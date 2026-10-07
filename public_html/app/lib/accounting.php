<?php
defined('APP_ROOT') || exit;

/*
 * أساس الحسابات (المرحلة الثانية). دوال مشتركة تستخدمها كل وحدات الحسابات:
 *   - المبالغ: أعداد صحيحة بالقروش في PHP (int)، و DECIMAL(24,2) في قاعدة البيانات.
 *   - الدفاتر: party_ledger و cash_ledger. القيد يُضاف فقط عبر ledger_party و ledger_cash،
 *     وهما يحدّثان الرصيد المخزن في نفس المعاملة، فيبقى دائمًا: الرصيد = الافتتاحي + مجموع القيود.
 *   - الإلغاء لا يحذف قيدًا: reverse_ledgers تضيف قيودًا عكسية بتاريخ الإلغاء.
 *
 * ترتيب الأقفال الكامل للنظام (كل العمليات تلتزم به لمنع الجمود):
 *   1. (الإلغاء فقط) صف المستند أو السند FOR UPDATE
 *   2. صف العميل أو المورد FOR UPDATE (طرف واحد على الأكثر في العملية)
 *   3. صفوف الخزائن FOR UPDATE بترتيب تصاعدي للمعرّف
 *   4. صفوف الأصناف بترتيب تصاعدي: FOR UPDATE إذا تغيرت قيمة المخزون (وارد، بيع، وإلغاؤهما)،
 *      و LOCK IN SHARE MODE إذا لم تتغير (تحويل وإلغاؤه)
 *   5. صفوف الأرصدة stock بترتيب (item_id, warehouse_id)
 *   6. عداد الترقيم
 *   7. إدراج المستند أو السند وأسطره، ثم قيود الدفاتر
 *   8. audit_record (سجل المراقبة) ثم data_version_bump آخر شيء
 */

const MONEY_MAX_PIASTERS = 100000000000; // مليار جنيه: حد أي مبلغ يُدخل في سند أو رصيد افتتاحي

/** مبلغ من قاعدة البيانات مثل "-123.45" أو "10" إلى قروش */
function money_to_piasters(string|int|float|null $dec): int
{
    $s = trim((string) ($dec ?? '0'));
    if (!preg_match('/^(-)?(\d+)(?:\.(\d{1,2})\d*)?\z/', $s, $m)) {
        throw new InvalidArgumentException('Bad money value');
    }
    $p = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
    return ($m[1] ?? '') === '-' ? -$p : $p;
}

/** قروش إلى نص عشري لقاعدة البيانات مثل "-123.45" */
function piasters_to_money(int $p): string
{
    $sign = $p < 0 ? '-' : '';
    $a = abs($p);
    return $sign . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
}

/** عرض مبلغ بالقروش بخانتين وبإعداد الأرقام، والسالب بعلامة ناقص في أوله */
function fmt_piasters(int $p): string
{
    return ($p < 0 ? '-' : '') . fmt_money(piasters_to_money(abs($p)));
}

/** رصيد عميل أو مورد كنص مفهوم: «١٠٠٫٠٠ عليه» أو «٥٠٫٠٠ له» أو «٠٫٠٠» */
function fmt_party_balance(int $p, string $kind): string
{
    if ($p === 0) {
        return fmt_money('0');
    }
    // للعميل: الموجب مستحق عليه لنا. للمورد: الموجب مستحق له علينا.
    $owesUs = $kind === 'customer' ? $p > 0 : $p < 0;
    return fmt_money(piasters_to_money(abs($p))) . ($owesUs ? ' عليه' : ' له');
}

/**
 * يقرأ مبلغًا يدخله المستخدم (بالجنيه وخانتين عشريتين على الأكثر).
 * @return array{0: ?int, 1: ?string} [القروش, رسالة الخطأ]
 */
function parse_money_input(string $raw, string $label, bool $allowZero = false): array
{
    [$p, $err] = parse_decimal_input($raw, 2);
    if ($err === 'zero' && $allowZero) {
        return [0, null];
    }
    if ($err === null && Num::cmp($p, (string) MONEY_MAX_PIASTERS) > 0) {
        $err = 'too_large';
    }
    if ($err === null) {
        return [(int) $p, null];
    }
    $msg = match ($err) {
        'empty' => "أدخل {$label}.",
        'comma' => sprintf('%s: اكتب الرقم بدون فواصل الآلاف، واستخدم النقطة للكسور، مثل %s', $label, digits('1500.50')),
        'negative' => "{$label} لا يقبل قيمًا سالبة.",
        'zero' => "{$label} يجب أن يكون أكبر من صفر.",
        'decimals' => "{$label} يقبل رقمين عشريين على الأكثر (القروش).",
        'too_large' => sprintf('%s أكبر من الحد المسموح (%s).', $label, fmt_int(intdiv(MONEY_MAX_PIASTERS, 100))),
        default => sprintf('%s: أدخل رقمًا صحيحًا، مثل %s', $label, digits('1500')),
    };
    return [null, $msg];
}

/**
 * (أ × ب ÷ ج) مقربًا النصف لأعلى، بحساب دقيق لا يفيض: أ و ب غير سالبين، و ج موجب.
 * يُستخدم لتكلفة البضاعة المباعة: قيمة المخزون × القطع المباعة ÷ كل القطع.
 */
function muldiv_half_up(int $a, int $b, int $c): int
{
    if ($a < 0 || $b < 0 || $c <= 0) {
        throw new InvalidArgumentException('muldiv_half_up expects a,b >= 0 and c > 0');
    }
    $num = Num::mul((string) $a, (string) $b);
    // قسمة طويلة لعدد نصي على عدد صحيح
    $q = '';
    $r = 0;
    $len = strlen($num);
    for ($i = 0; $i < $len; $i++) {
        $r = $r * 10 + (int) $num[$i];
        $q .= (string) intdiv($r, $c);
        $r %= $c;
    }
    $q = ltrim($q, '0');
    $q = $q === '' ? 0 : (int) $q;
    return ($r * 2 >= $c) ? $q + 1 : $q;
}

/* ---------------- الصلاحيات والمراقبة (تعمل قبل وبعد دمج طبقة الصلاحيات) ---------------- */

function acct_can(string $perm): bool
{
    if (!function_exists('can')) {
        return true;
    }
    // سطر الأوامر بلا مستخدم مسجل (الاختبارات والصيانة) يعمل كالنظام. على الويب لا يصل طلب
    // إلى الخدمات قبل require_login، فلا ينطبق هذا الاستثناء أبدًا على زائر.
    if (PHP_SAPI === 'cli' && current_user_id() === 0) {
        return true;
    }
    return (bool) can($perm);
}

function acct_require(string $perm): void
{
    if (PHP_SAPI === 'cli' && current_user_id() === 0) {
        return; // مثل acct_can: سطر الأوامر بلا مستخدم يعمل كالنظام
    }
    if (function_exists('require_permission')) {
        require_permission($perm);
    }
}

/** يسجل العملية في سجل المراقبة إن وُجد. يُستدعى داخل معاملة العملية قبل data_version_bump */
function acct_audit(PDO $pdo, string $action, string $summary, string $entity = '', ?int $entityId = null, ?array $details = null): void
{
    if (function_exists('audit_record')) {
        audit_record($pdo, $action, $summary, $entity, $entityId, $details);
    }
}

/* ---------------- التاريخ المحاسبي وتاريخ الإقفال ---------------- */

/** تاريخ الإقفال (Y-m-d) أو '' إذا لم يُحدد. لا يُسجل ولا يُلغى شيء بتاريخ في يوم الإقفال أو قبله. */
function closing_date(): string
{
    $d = app_setting('closing_date');
    return preg_match('/^\d{4}-\d{2}-\d{2}\z/', $d) ? $d : '';
}

function period_is_open(string $dateTime): bool
{
    $c = closing_date();
    return $c === '' || substr($dateTime, 0, 10) > $c;
}

/**
 * التاريخ المحاسبي للمستند أو السند من الحقل $field:
 *   فارغ = الآن. غير ذلك: للمدير فقط (صلاحية manual_date)، بصيغة Y-m-d، ليس في المستقبل،
 *   وبعد تاريخ الإقفال. يُضاف للتاريخ اليدوي الوقت الحالي حتى يبقى ترتيب العمليات في اليوم.
 */
function acct_doc_date(array $in, array &$errors, string $field = 'doc_date'): string
{
    $raw = trim((string) ($in[$field] ?? ''));
    $now = now();
    if ($raw === '' || $raw === substr($now, 0, 10)) {
        if (!period_is_open($now)) {
            $errors[$field] = 'الفترة مقفلة حتى ' . digits(closing_date()) . '. غيّر تاريخ الإقفال من الإعدادات.';
        }
        return $now;
    }
    if (!acct_can('manual_date')) {
        $errors[$field] = 'لا تملك صلاحية تغيير تاريخ المستند.';
        return $now;
    }
    $raw = normalize_number_input($raw);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$d || $d->format('Y-m-d') !== $raw) {
        $errors[$field] = 'التاريخ غير صحيح. استخدم الصيغة سنة-شهر-يوم.';
        return $now;
    }
    if ($raw > substr($now, 0, 10)) {
        $errors[$field] = 'لا يمكن تسجيل مستند بتاريخ في المستقبل.';
        return $now;
    }
    $date = $raw . substr($now, 10);
    if (!period_is_open($date)) {
        $errors[$field] = 'التاريخ يقع في فترة مقفلة (حتى ' . digits(closing_date()) . ').';
        return $now;
    }
    return $date;
}

/* ---------------- الأطراف والخزائن: القفل والأرصدة ---------------- */

/**
 * يقفل صف العميل أو المورد (الخطوة 2 في ترتيب الأقفال) ويعيده.
 * @param bool $requireActive العمليات الجديدة (فاتورة) تتطلب طرفًا نشطًا؛ التحصيل والإلغاء لا.
 */
function lock_party(PDO $pdo, int $id, string $kind, bool $requireActive = true): array
{
    $stmt = $pdo->prepare('SELECT id, kind, name, balance, credit_limit, branch_id, is_active FROM parties WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $noun = $kind === 'customer' ? 'العميل' : 'المورد';
    if (!$row || $row['kind'] !== $kind) {
        throw new ValidationException(['party_id' => "{$noun} غير موجود."]);
    }
    if ($requireActive && !(int) $row['is_active']) {
        throw new ValidationException(['party_id' => "حساب {$noun} «{$row['name']}» موقوف."]);
    }
    return $row;
}

/**
 * يقفل صفوف الخزائن بترتيب تصاعدي (الخطوة 3) ويعيدها [id => row].
 * @param int[] $ids
 */
function lock_cash_boxes(PDO $pdo, array $ids, bool $requireActive = true): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, branch_id, balance, is_active FROM cash_boxes WHERE id IN ($in) ORDER BY id FOR UPDATE");
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[(int) $r['id']] = $r;
    }
    foreach ($ids as $id) {
        if (!isset($rows[$id])) {
            throw new ValidationException(['cash_box_id' => 'الخزنة غير موجودة.']);
        }
        if ($requireActive && !(int) $rows[$id]['is_active']) {
            throw new ValidationException(['cash_box_id' => "الخزنة «{$rows[$id]['name']}» موقوفة."]);
        }
    }
    return $rows;
}

/**
 * يضيف قيدًا في دفتر عميل أو مورد ويحدّث رصيده المخزن. صف الطرف يجب أن يكون مقفلًا مسبقًا.
 * $amount موجب يزيد المستحق (على العميل / للمورد)، وسالب ينقصه.
 * @return int معرّف القيد
 */
function ledger_party(PDO $pdo, int $partyId, string $date, ?int $documentId, ?int $voucherId, string $type, int $amount, string $description, ?int $reversalOf = null): int
{
    if ($amount === 0) {
        return 0;
    }
    $pdo->prepare('INSERT INTO party_ledger (party_id, entry_date, document_id, voucher_id, entry_type, amount, description, reversal_of, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$partyId, $date, $documentId, $voucherId, $type, piasters_to_money($amount), mb_substr($description, 0, 255), $reversalOf, now()]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE parties SET balance = balance + ? WHERE id = ?')->execute([piasters_to_money($amount), $partyId]);
    return $id;
}

/**
 * يضيف قيدًا في دفتر خزنة ويحدّث رصيدها. صف الخزنة يجب أن يكون مقفلًا مسبقًا.
 * $amount موجب = داخل، سالب = خارج. يرفض أي خروج يجعل الرصيد سالبًا.
 */
function ledger_cash(PDO $pdo, int $boxId, string $date, ?int $documentId, ?int $voucherId, string $type, int $amount, string $description, ?int $reversalOf = null): int
{
    if ($amount === 0) {
        return 0;
    }
    if ($amount < 0) {
        // قراءة بقفل: ترجع آخر قيمة مؤكدة حتى لو بدأت لقطة المعاملة قبل الحصول على القفل
        $stmt = $pdo->prepare('SELECT name, balance FROM cash_boxes WHERE id = ? FOR UPDATE');
        $stmt->execute([$boxId]);
        $box = $stmt->fetch();
        $balance = money_to_piasters($box['balance'] ?? '0');
        if ($balance + $amount < 0) {
            throw new ValidationException(['cash_box_id' => sprintf(
                'رصيد الخزنة «%s» لا يكفي: المتاح %s والمطلوب %s.',
                $box['name'] ?? '',
                fmt_piasters($balance),
                fmt_piasters(-$amount)
            )]);
        }
    }
    $pdo->prepare('INSERT INTO cash_ledger (cash_box_id, entry_date, document_id, voucher_id, entry_type, amount, description, reversal_of, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$boxId, $date, $documentId, $voucherId, $type, piasters_to_money($amount), mb_substr($description, 0, 255), $reversalOf, now()]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE cash_boxes SET balance = balance + ? WHERE id = ?')->execute([piasters_to_money($amount), $boxId]);
    return $id;
}

/**
 * يعكس كل قيود مستند أو سند (عند إلغائه) بقيود عكسية بتاريخ $date.
 * يجب أن تكون صفوف الأطراف والخزائن المعنية مقفلة مسبقًا (استخدم ledger_sources_of أولًا لمعرفتها).
 * القيود الداخلة للخزنة تُعكس كخروج، فيُرفض الإلغاء إذا لم يكفِ رصيد الخزنة.
 */
function reverse_ledgers(PDO $pdo, string $source, int $id, string $date, string $description): void
{
    $col = $source === 'voucher' ? 'voucher_id' : 'document_id';
    foreach (['party' => 'party_ledger', 'cash' => 'cash_ledger'] as $kind => $table) {
        $stmt = $pdo->prepare("SELECT id, " . ($kind === 'party' ? 'party_id' : 'cash_box_id') . " AS owner, amount
            FROM {$table} WHERE {$col} = ? AND entry_type <> 'reversal' ORDER BY id FOR UPDATE");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $e) {
            $amount = -money_to_piasters($e['amount']);
            $docId = $source === 'voucher' ? null : $id;
            $vId = $source === 'voucher' ? $id : null;
            if ($kind === 'party') {
                ledger_party($pdo, (int) $e['owner'], $date, $docId, $vId, 'reversal', $amount, $description, (int) $e['id']);
            } else {
                ledger_cash($pdo, (int) $e['owner'], $date, $docId, $vId, 'reversal', $amount, $description, (int) $e['id']);
            }
        }
    }
}

/**
 * الأطراف والخزائن التي لها قيود في مستند أو سند، لقفلها بالترتيب قبل reverse_ledgers.
 * @return array{parties: int[], cash_boxes: int[]}
 */
function ledger_sources_of(PDO $pdo, string $source, int $id): array
{
    $col = $source === 'voucher' ? 'voucher_id' : 'document_id';
    $p = $pdo->prepare("SELECT DISTINCT party_id FROM party_ledger WHERE {$col} = ? ORDER BY party_id");
    $p->execute([$id]);
    $c = $pdo->prepare("SELECT DISTINCT cash_box_id FROM cash_ledger WHERE {$col} = ? ORDER BY cash_box_id");
    $c->execute([$id]);
    return [
        'parties' => array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN)),
        'cash_boxes' => array_map('intval', $c->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

/** رصيد طرف (قراءة عادية خارج المعاملات، للعرض) */
function party_balance(PDO $pdo, int $id): int
{
    $stmt = $pdo->prepare('SELECT balance FROM parties WHERE id = ?');
    $stmt->execute([$id]);
    return money_to_piasters($stmt->fetchColumn() ?: '0');
}

function cash_balance(PDO $pdo, int $id): int
{
    $stmt = $pdo->prepare('SELECT balance FROM cash_boxes WHERE id = ?');
    $stmt->execute([$id]);
    return money_to_piasters($stmt->fetchColumn() ?: '0');
}

/**
 * فحص سلامة الأرصدة: كل رصيد مخزن يساوي الافتتاحي + مجموع القيود.
 * @return list<string> وصف كل اختلاف (فارغة = سليم)
 */
function acct_verify_balances(PDO $pdo): array
{
    $out = [];
    $rows = $pdo->query('SELECT p.id, p.name, p.balance, p.opening_balance + COALESCE(SUM(l.amount), 0) AS expected
        FROM parties p LEFT JOIN party_ledger l ON l.party_id = p.id GROUP BY p.id, p.name, p.balance, p.opening_balance')->fetchAll();
    foreach ($rows as $r) {
        if (money_to_piasters($r['balance']) !== money_to_piasters($r['expected'])) {
            $out[] = "party {$r['id']} {$r['name']}: stored {$r['balance']} expected {$r['expected']}";
        }
    }
    $rows = $pdo->query('SELECT b.id, b.name, b.balance, b.opening_balance + COALESCE(SUM(l.amount), 0) AS expected
        FROM cash_boxes b LEFT JOIN cash_ledger l ON l.cash_box_id = b.id GROUP BY b.id, b.name, b.balance, b.opening_balance')->fetchAll();
    foreach ($rows as $r) {
        if (money_to_piasters($r['balance']) !== money_to_piasters($r['expected'])) {
            $out[] = "cash box {$r['id']} {$r['name']}: stored {$r['balance']} expected {$r['expected']}";
        }
    }
    return $out;
}

/* ---------------- قوائم للنماذج ---------------- */

/** فرع المستخدم الحالي إن كانت طبقة الفروع موجودة (null = كل الفروع) */
function acct_branch_scope(PDO $pdo): ?int
{
    return function_exists('allowed_branch_id') ? allowed_branch_id($pdo) : null;
}

/** الخزائن النشطة المتاحة للمستخدم: خزائن فرعه والخزائن العامة */
function cash_boxes_for_user(PDO $pdo, bool $activeOnly = true): array
{
    $sql = 'SELECT id, name, branch_id, balance, is_active FROM cash_boxes WHERE 1 = 1';
    $args = [];
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $branch = acct_branch_scope($pdo);
    if ($branch !== null) {
        $sql .= ' AND (branch_id IS NULL OR branch_id = ?)';
        $args[] = $branch;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY name, id');
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/** هل الخزنة ضمن نطاق المستخدم؟ (للتحقق في الخدمات قبل القفل) */
function cash_box_in_scope(array $box, ?int $branch): bool
{
    return $branch === null || $box['branch_id'] === null || (int) $box['branch_id'] === $branch;
}

/** العملاء أو الموردون النشطون للقوائم المنسدلة */
function parties_for_select(PDO $pdo, string $kind): array
{
    $stmt = $pdo->prepare('SELECT id, name, balance, credit_limit FROM parties WHERE kind = ? AND is_active = 1 ORDER BY name, id');
    $stmt->execute([$kind]);
    return $stmt->fetchAll();
}

const PAYMENT_TYPE_LABELS = [
    'cash' => 'نقدي',
    'credit' => 'آجل',
    'partial' => 'دفع جزئي',
];

const PARTY_KIND_LABELS = [
    'customer' => 'عميل',
    'supplier' => 'مورد',
];

const VOUCHER_KIND_LABELS = [
    'collect' => 'سند قبض',
    'pay' => 'سند صرف',
    'expense' => 'مصروف',
    'cash_transfer' => 'تحويل نقدية',
];
