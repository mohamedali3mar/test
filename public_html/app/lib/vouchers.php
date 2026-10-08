<?php
defined('APP_ROOT') || exit;

/*
 * السندات المالية (المرحلة الثانية، الوحدة ب): قبض من عميل، صرف لمورد، مصروف، تحويل نقدية.
 *
 *  - كل سند معاملة واحدة: السند وقيوده ورقمه إما تُحفظ معًا أو لا يُحفظ شيء، فالترقيم متتالٍ بلا فجوات
 *    لكل نوع (counter_next على doc_collect / doc_pay / doc_expense / doc_cash_transfer).
 *  - ترتيب الأقفال (من رأس accounting.php): (الإلغاء) صف السند ← صف الطرف ← الخزائن تصاعديًا
 *    ← العداد ← إدراج السند ← القيود ← acct_audit ← data_version_bump آخر شيء.
 *  - الخزنة لا تصبح سالبة أبدًا: ledger_cash يرفض داخل المعاملة بعد القفل.
 *  - منع التكرار: request_token فريد ومعه request_hash؛ نفس الرمز بنفس المحتوى يعيد السند المسجل،
 *    وبمحتوى مختلف يرفض برسالة واضحة.
 *  - الإلغاء لا يحذف ولا يعدل القيود: reverse_ledgers تضيف قيودًا عكسية بتاريخ الإلغاء،
 *    ويُرفض إذا كان تاريخ السند في فترة مقفلة.
 */

const VOUCHER_COUNTERS = [
    'collect' => 'doc_collect',
    'pay' => 'doc_pay',
    'expense' => 'doc_expense',
    'cash_transfer' => 'doc_cash_transfer',
];

const VOUCHER_PERMISSIONS = [
    'collect' => 'vouchers.collect',
    'pay' => 'vouchers.pay',
    'expense' => 'vouchers.expense',
    'cash_transfer' => 'vouchers.cash_transfer',
];

/** عناوين صفحات النماذج والطباعة */
const VOUCHER_TITLES = [
    'collect' => 'سند قبض نقدية',
    'pay' => 'سند صرف نقدية',
    'expense' => 'سند مصروف',
    'cash_transfer' => 'إذن تحويل نقدية',
];

function voucher_label(array $v): string
{
    return (VOUCHER_KIND_LABELS[$v['kind']] ?? (string) $v['kind']) . ' رقم ' . fmt_doc_no((int) $v['doc_no']);
}

/** نوع الطرف المطلوب لنوع السند (null = بلا طرف) */
function voucher_party_kind(string $kind): ?string
{
    return match ($kind) {
        'collect' => 'customer',
        'pay' => 'supplier',
        default => null,
    };
}

function existing_voucher_request(PDO $pdo, string $token, string $kind, string $hash): ?array
{
    $stmt = $pdo->prepare('SELECT id, kind, doc_no, request_hash FROM vouchers WHERE request_token = ?');
    $stmt->execute([$token]);
    $v = $stmt->fetch();
    if (!$v) {
        return null;
    }
    if ($v['kind'] !== $kind || !hash_equals($v['request_hash'], $hash)) {
        throw new ValidationException([
            'request_token' => sprintf(
                'هذا النموذج حُفظ سابقًا (%s)، ولم تُحفظ التعديلات الجديدة. راجع البيانات ثم احفظها كسند جديد.',
                voucher_label($v)
            ),
        ]);
    }
    return ['id' => (int) $v['id'], 'kind' => $v['kind'], 'doc_no' => (int) $v['doc_no'], 'duplicate' => true];
}

/**
 * يتحقق من مدخلات السند ويقرأ البيانات الثابتة (الطرف والخزائن والتصنيف) قبل المعاملة.
 * الأرصدة لا تُقرأ هنا: الرصيد الملزم يُقرأ بعد القفل داخل المعاملة.
 */
function validate_voucher(PDO $pdo, string $kind, array $in): array
{
    $errors = [];
    $partyKind = voucher_party_kind($kind);
    $branch = acct_branch_scope($pdo);

    $party = null;
    if ($partyKind !== null) {
        $party = party_find($pdo, (int) ($in['party_id'] ?? 0));
        if (!$party || $party['kind'] !== $partyKind) {
            $errors['party_id'] = $partyKind === 'customer' ? 'اختر العميل.' : 'اختر المورد.';
            $party = null;
        }
    }

    $box = cash_box_find($pdo, (int) ($in['cash_box_id'] ?? 0));
    if (!$box || !(int) $box['is_active'] || !cash_box_in_scope($box, $branch)) {
        $errors['cash_box_id'] = $kind === 'cash_transfer' ? 'اختر الخزنة المحوَّل منها.' : 'اختر الخزنة.';
        $box = null;
    }

    $toBox = null;
    if ($kind === 'cash_transfer') {
        $toBox = cash_box_find($pdo, (int) ($in['to_cash_box_id'] ?? 0));
        if (!$toBox || !(int) $toBox['is_active']) {
            $errors['to_cash_box_id'] = 'اختر الخزنة المحوَّل إليها.';
            $toBox = null;
        } elseif ($box && (int) $box['id'] === (int) $toBox['id']) {
            $errors['to_cash_box_id'] = 'اختر خزنة مختلفة عن الخزنة المحوَّل منها.';
        }
    }

    $category = null;
    if ($kind === 'expense') {
        $stmt = $pdo->prepare('SELECT id, name, is_active FROM expense_categories WHERE id = ?');
        $stmt->execute([(int) ($in['category_id'] ?? 0)]);
        $category = $stmt->fetch() ?: null;
        if (!$category || !(int) $category['is_active']) {
            $errors['category_id'] = 'اختر تصنيف المصروف.';
            $category = null;
        }
    }

    [$amount, $err] = parse_money_input(is_string($in['amount'] ?? null) ? $in['amount'] : '', 'المبلغ');
    if ($err !== null) {
        $errors['amount'] = $err;
    }

    $text = clean_text_fields($in, [
        'reference' => ['المرجع', 120, false],
        'notes' => ['البيان', 1000, true],
    ], $errors);
    if ($kind === 'expense' && $text['notes'] === '' && !isset($errors['notes'])) {
        $errors['notes'] = 'اكتب بيان المصروف (مثل: إيجار شهر يناير).';
    }

    $rawDate = normalize_number_input(trim(is_string($in['voucher_date'] ?? null) ? $in['voucher_date'] : ''));
    $date = acct_doc_date($in, $errors, 'voucher_date');
    $token = check_token($in, $errors);
    if ($errors) {
        throw new ValidationException($errors);
    }
    return $text + [
        'kind' => $kind,
        'party' => $party,
        'box' => $box,
        'to_box' => $toBox,
        'category' => $category,
        'amount' => (int) $amount,
        'date' => $date,
        'request_token' => $token,
        'hash' => request_hash([
            'voucher', $kind, $party ? (int) $party['id'] : null, (int) $box['id'], $toBox ? (int) $toBox['id'] : null,
            $category ? (int) $category['id'] : null, (int) $amount, $text['reference'], $text['notes'],
            $rawDate === substr(now(), 0, 10) ? '' : $rawDate,
        ]),
    ];
}

/**
 * يسجل سندًا. $kind: collect | pay | expense | cash_transfer.
 * @return array{id:int, kind:string, doc_no:int, duplicate:bool}
 */
function record_voucher(PDO $pdo, int $userId, string $kind, array $in): array
{
    if (!isset(VOUCHER_COUNTERS[$kind])) {
        throw new InvalidArgumentException('Unknown voucher kind ' . $kind);
    }
    if (!acct_can(VOUCHER_PERMISSIONS[$kind])) {
        throw acct_denied('تسجيل ' . VOUCHER_KIND_LABELS[$kind]);
    }
    $v = validate_voucher($pdo, $kind, $in);
    if ($dup = existing_voucher_request($pdo, $v['request_token'], $kind, $v['hash'])) {
        return $dup;
    }
    // تُقرأ قبل المعاملة حتى لا تُفتح قراءة غير مقفلة داخلها
    $currency = app_setting('currency');
    // فرع السند: فرع المستخدم المقيد بفرع (تحصيلات الفرع في الرئيسية)، وNULL لمن يرى كل الفروع (سند عام)
    $branchId = acct_branch_scope($pdo);
    $branchName = $branchId !== null ? (string) (branch_find($pdo, $branchId)['name'] ?? '') : null;
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($v, $kind, $userId, $currency, $branchId, $branchName) {
            $party = null;
            if ($v['party'] !== null) {
                // التحصيل والصرف مسموحان لحساب موقوف (تسوية رصيده)
                $party = lock_party($pdo, (int) $v['party']['id'], $v['party']['kind'], false);
            }
            $boxId = (int) $v['box']['id'];
            $toId = $v['to_box'] ? (int) $v['to_box']['id'] : null;
            $boxes = lock_cash_boxes($pdo, $toId ? [$boxId, $toId] : [$boxId], true);

            $docNo = counter_next($pdo, VOUCHER_COUNTERS[$kind]);
            $amount = $v['amount'];
            $pdo->prepare('INSERT INTO vouchers (kind, doc_no, voucher_date, party_id, party_name, cash_box_id, cash_box_name,
                    to_cash_box_id, to_cash_box_name, category_id, category_name, amount, currency, reference, notes,
                    branch_id, branch_name, request_token, request_hash, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $kind, $docNo, $v['date'],
                    $party ? (int) $party['id'] : null, $party ? $party['name'] : null,
                    $boxId, $boxes[$boxId]['name'],
                    $toId, $toId ? $boxes[$toId]['name'] : null,
                    $v['category'] ? (int) $v['category']['id'] : null, $v['category'] ? $v['category']['name'] : null,
                    piasters_to_money($amount), $currency,
                    $v['reference'] !== '' ? $v['reference'] : null, $v['notes'] !== '' ? $v['notes'] : null,
                    $branchId, $branchName, $v['request_token'], $v['hash'], now(), $userId,
                ]);
            $id = (int) $pdo->lastInsertId();
            $label = VOUCHER_KIND_LABELS[$kind] . ' رقم ' . $docNo;
            $date = $v['date'];
            switch ($kind) {
                case 'collect':
                    ledger_party($pdo, (int) $party['id'], $date, null, $id, 'collect', -$amount, $label);
                    ledger_cash($pdo, $boxId, $date, null, $id, 'collect', $amount, $label . ' من ' . $party['name']);
                    break;
                case 'pay':
                    ledger_party($pdo, (int) $party['id'], $date, null, $id, 'pay', -$amount, $label);
                    ledger_cash($pdo, $boxId, $date, null, $id, 'pay', -$amount, $label . ' إلى ' . $party['name']);
                    break;
                case 'expense':
                    ledger_cash($pdo, $boxId, $date, null, $id, 'expense', -$amount, $label . ': ' . $v['category']['name']);
                    break;
                case 'cash_transfer':
                    ledger_cash($pdo, $boxId, $date, null, $id, 'transfer_out', -$amount, $label . ' إلى ' . $boxes[$toId]['name']);
                    ledger_cash($pdo, $toId, $date, null, $id, 'transfer_in', $amount, $label . ' من ' . $boxes[$boxId]['name']);
                    break;
            }
            $summary = sprintf('%s بمبلغ %s', $label, piasters_to_money($amount));
            if ($date !== null && substr($date, 0, 10) !== substr(now(), 0, 10)) {
                $summary .= ' بتاريخ يدوي ' . substr($date, 0, 10);
            }
            acct_audit($pdo, 'voucher.create', $summary, 'voucher', $id, [
                'kind' => $kind, 'doc_no' => $docNo, 'amount' => piasters_to_money($amount), 'voucher_date' => $date,
            ]);
            data_version_bump($pdo);
            return ['id' => $id, 'kind' => $kind, 'doc_no' => $docNo, 'duplicate' => false];
        });
    } catch (ValidationException $e) {
        // طلب مكرر وصل متزامنًا ووجد الرصيد قد نقص بسبب الطلب الأول نفسه
        if ($dup = existing_voucher_request($pdo, $v['request_token'], $kind, $v['hash'])) {
            return $dup;
        }
        throw $e;
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_vouchers_request_token')) {
            if ($dup = existing_voucher_request($pdo, $v['request_token'], $kind, $v['hash'])) {
                return $dup;
            }
        }
        if (is_fk_error($e)) {
            throw new ValidationException(['form' => 'أحد البيانات المختارة (حساب أو خزنة أو تصنيف) لم يعد موجودًا. حدّث الصفحة وأعد المحاولة.']);
        }
        throw $e;
    }
}

/**
 * يلغي السند بقيود عكسية بتاريخ الإلغاء، ويبقى السند في السجل بحالة «ملغى».
 * يُرفض إذا كان تاريخ السند في فترة مقفلة، أو إذا لم يكفِ رصيد الخزنة لعكس مبلغ دخلها.
 */
function cancel_voucher(PDO $pdo, int $userId, int $id, string $reasonRaw): array
{
    if (!acct_can('vouchers.cancel')) {
        throw acct_denied('إلغاء السندات');
    }
    $reason = clean_text($reasonRaw);
    if (mb_strlen($reason) > 255) {
        throw new ValidationException(['reason' => 'سبب الإلغاء أطول من المسموح (' . fmt_int(255) . ' حرفًا).']);
    }
    return db_transaction($pdo, function (PDO $pdo) use ($userId, $id, $reason) {
        $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        if (!$v) {
            throw new ValidationException(['voucher' => 'السند غير موجود.']);
        }
        if ($v['status'] !== 'active') {
            throw new ValidationException(['voucher' => 'هذا السند ملغى بالفعل.']);
        }
        if (!period_is_open($v['voucher_date'])) {
            throw new ValidationException(['voucher' => sprintf(
                'لا يمكن إلغاء سند تاريخه في فترة مقفلة (حتى %s). غيّر تاريخ الإقفال من الإعدادات أولًا.',
                digits(closing_date())
            )]);
        }
        $now = now();
        if (!period_is_open($now)) {
            throw new ValidationException(['voucher' => 'الفترة الحالية مقفلة. غيّر تاريخ الإقفال من الإعدادات.']);
        }
        $src = ledger_sources_of($pdo, 'voucher', $id);
        $partyKind = voucher_party_kind($v['kind']);
        foreach ($src['parties'] as $pid) {
            lock_party($pdo, $pid, (string) $partyKind, false);
        }
        lock_cash_boxes($pdo, $src['cash_boxes'], false);
        $label = VOUCHER_KIND_LABELS[$v['kind']] . ' رقم ' . $v['doc_no'];
        try {
            reverse_ledgers($pdo, 'voucher', $id, $now, 'إلغاء ' . $label);
        } catch (ValidationException $e) {
            throw new ValidationException(['voucher' => 'لا يمكن إلغاء السند: ' . implode(' ', $e->errors)]);
        }
        $upd = $pdo->prepare("UPDATE vouchers SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ?
            WHERE id = ? AND status = 'active'");
        $upd->execute([$now, $userId, $reason !== '' ? $reason : null, $id]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('Cancel update affected no rows');
        }
        acct_audit($pdo, 'voucher.cancel', 'إلغاء ' . $label . ($reason !== '' ? ': ' . $reason : ''), 'voucher', $id, [
            'kind' => $v['kind'], 'doc_no' => (int) $v['doc_no'], 'amount' => (string) $v['amount'],
        ]);
        data_version_bump($pdo);
        $v['status'] = 'cancelled';
        $v['cancelled_at'] = $now;
        return $v;
    });
}

function find_voucher(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT v.*, cu.username AS created_by_name, xu.username AS cancelled_by_name, p.kind AS party_kind
         FROM vouchers v
         JOIN users cu ON cu.id = v.created_by
         LEFT JOIN users xu ON xu.id = v.cancelled_by
         LEFT JOIN parties p ON p.id = v.party_id
         WHERE v.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/* ===================== المبلغ بالحروف (تفقيط) ===================== */

const TAFQEET_ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة'];
const TAFQEET_TENS = ['', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
const TAFQEET_HUNDREDS = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
/** [مفرد, مثنى, جمع (3-10), تمييز منصوب (11-99)] */
const TAFQEET_SCALES = [
    1000000000 => ['مليار', 'ملياران', 'مليارات', 'مليارًا'],
    1000000 => ['مليون', 'مليونان', 'ملايين', 'مليونًا'],
    1000 => ['ألف', 'ألفان', 'آلاف', 'ألفًا'],
];

/** عدد من 1 إلى 999 بالحروف (للمعدود المذكر). $construct: «مائتا» بدل «مائتان» إذا تبعها المعدود مباشرة */
function tafqeet_999(int $n, bool $construct = false): string
{
    $h = intdiv($n, 100);
    $r = $n % 100;
    $parts = [];
    if ($h > 0) {
        $parts[] = ($h === 2 && $r === 0 && $construct) ? 'مائتا' : TAFQEET_HUNDREDS[$h];
    }
    if ($r > 0) {
        if ($r <= 10) {
            $parts[] = TAFQEET_ONES[$r];
        } elseif ($r === 11) {
            $parts[] = 'أحد عشر';
        } elseif ($r === 12) {
            $parts[] = 'اثنا عشر';
        } elseif ($r < 20) {
            $parts[] = TAFQEET_ONES[$r - 10] . ' عشر';
        } else {
            $u = $r % 10;
            $parts[] = $u > 0 ? TAFQEET_ONES[$u] . ' و' . TAFQEET_TENS[intdiv($r, 10)] : TAFQEET_TENS[intdiv($r, 10)];
        }
    }
    return implode(' و', $parts);
}

/** العدد مع معدوده حسب قواعد التمييز. $forms: [مفرد, مثنى, جمع, منصوب] */
function tafqeet_counted(int $n, array $forms, bool $oneAfter = false): string
{
    $r = $n % 100;
    if ($n === 1) {
        return $oneAfter ? $forms[0] . ' واحد' : $forms[0];
    }
    if ($n === 2) {
        return $forms[1];
    }
    if ($r >= 3 && $r <= 10) {
        return tafqeet_999_or_more($n) . ' ' . $forms[2];
    }
    if ($r >= 11) {
        return tafqeet_999_or_more($n) . ' ' . $forms[3];
    }
    // 0 أو 1 أو 2 في آخر العدد: المعدود مفرد مجرور (مائة جنيه، ألف جنيه، مائتا جنيه)
    return tafqeet_999_or_more($n, true) . ' ' . $forms[0];
}

/** عدد صحيح موجب بالحروف بدون معدود. $construct: الإضافة في آخر العدد (مائتا، ألفا، مليونا) */
function tafqeet_999_or_more(int $n, bool $construct = false): string
{
    $parts = [];
    $rest = $n;
    foreach (TAFQEET_SCALES as $scale => $forms) {
        $g = intdiv($rest, $scale);
        $rest %= $scale;
        if ($g === 0) {
            continue;
        }
        $last = $rest === 0;
        if ($g === 1) {
            $parts[] = $forms[0];
        } elseif ($g === 2) {
            $parts[] = ($last && $construct) ? mb_substr($forms[1], 0, -1) : $forms[1];
        } else {
            $gr = $g % 100;
            if ($gr >= 3 && $gr <= 10) {
                $parts[] = tafqeet_999($g) . ' ' . $forms[2];
            } elseif ($gr >= 11) {
                // «خمسة وأربعون ألفًا وستمائة» لكن «أحد عشر ألف جنيه» إذا تبعها المعدود مباشرة
                $parts[] = tafqeet_999($g) . ' ' . (($last && $construct) ? $forms[0] : $forms[3]);
            } else {
                $parts[] = tafqeet_999($g, true) . ' ' . $forms[0];
            }
        }
    }
    if ($rest > 0) {
        $parts[] = tafqeet_999($rest, $construct);
    }
    return implode(' و', $parts);
}

/**
 * المبلغ بالحروف للسندات المطبوعة، مثل: «فقط ألف وخمسمائة جنيه وخمسون قرشًا لا غير».
 */
function amount_in_words_ar(int $piasters): string
{
    if ($piasters < 0) {
        throw new InvalidArgumentException('amount_in_words_ar expects a non-negative amount');
    }
    $pounds = intdiv($piasters, 100);
    $qirsh = $piasters % 100;
    $parts = [];
    if ($pounds > 0) {
        $parts[] = tafqeet_counted($pounds, ['جنيه', 'جنيهان', 'جنيهات', 'جنيهًا'], true);
    }
    if ($qirsh > 0) {
        $parts[] = tafqeet_counted($qirsh, ['قرش', 'قرشان', 'قروش', 'قرشًا'], true);
    }
    if (!$parts) {
        $parts[] = 'صفر جنيه';
    }
    return 'فقط ' . implode(' و', $parts) . ' لا غير';
}
