<?php
defined('APP_ROOT') || exit;

/*
 * العملاء والموردون (المرحلة الثانية، الوحدة ب).
 *  - الاسم فريد لكل نوع حسب name_key، والطرف الذي يكون عميلًا ومورّدًا يُسجل مرتين.
 *  - الرصيد الافتتاحي موجب دائمًا في الإدخال ومعه الاتجاه: «عليه» (مستحق لنا) أو «له» (مستحق علينا).
 *    يُخزن بإشارة الدفتر: للعميل «عليه» موجب، وللمورد «له» موجب.
 *  - تعديل الافتتاحي يحرك الرصيد بنفس الفرق تحت قفل صف الطرف، فيبقى الرصيد = الافتتاحي + مجموع القيود.
 *  - الإيقاف بدل الحذف. الحذف فقط لطرف بلا قيود ولا مستندات ولا سندات.
 */

const PARTY_NOUNS = ['customer' => 'العميل', 'supplier' => 'المورد'];
const PARTY_PLURALS = ['customer' => 'العملاء', 'supplier' => 'الموردون'];
/** اتجاه الرصيد الافتتاحي كما يختاره المستخدم */
const PARTY_DIRECTIONS = ['owes_us' => 'عليه', 'we_owe' => 'له'];

function party_kind(string $kind): string
{
    if (!isset(PARTY_NOUNS[$kind])) {
        throw new InvalidArgumentException('Unknown party kind ' . $kind);
    }
    return $kind;
}

/** الرصيد بإشارة الدفتر من المبلغ والاتجاه */
function party_signed_amount(string $kind, int $amount, string $direction): int
{
    $owesUs = $direction === 'owes_us';
    return ($kind === 'customer') === $owesUs ? $amount : -$amount;
}

/** عكس party_signed_amount: [المبلغ الموجب, الاتجاه] للعرض في النموذج */
function party_split_amount(string $kind, int $signed): array
{
    if ($signed === 0) {
        return [0, $kind === 'customer' ? 'owes_us' : 'we_owe'];
    }
    $owesUs = $kind === 'customer' ? $signed > 0 : $signed < 0;
    return [abs($signed), $owesUs ? 'owes_us' : 'we_owe'];
}

/** يتحقق من اسم بمفتاح مقارنة. @return array{0:string,1:string} */
function acct_validate_name(string $raw, string $field, string $noun, int $max): array
{
    $name = clean_text($raw);
    $key = name_key($name);
    if ($name === '' || $key === '') {
        throw new ValidationException([$field => "أدخل اسم {$noun}."]);
    }
    if (mb_strlen($name) > $max || mb_strlen($key) > $max) {
        throw new ValidationException([$field => sprintf('اسم %s طويل جدًا (%s حرفًا على الأكثر).', $noun, fmt_int($max))]);
    }
    return [$name, $key];
}

/** تاريخ تصفية Y-m-d أو null إذا كان فارغًا. القيمة غير الصالحة تضبط $invalid وتُتجاهل. */
function acct_date_filter(string $raw, bool &$invalid): ?string
{
    $raw = normalize_number_input(trim($raw));
    if ($raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        $invalid = true;
        return null;
    }
    return $raw;
}

function acct_next_day(string $ymd): string
{
    return (new DateTimeImmutable($ymd))->modify('+1 day')->format('Y-m-d 00:00:00');
}

function acct_denied(string $what): ValidationException
{
    return new ValidationException(['form' => "لا تملك صلاحية {$what}."]);
}

/**
 * يتحقق من بيانات نموذج الطرف.
 * @return array{name:string,name_key:string,phone:string,address:string,notes:string,opening:int,credit_limit:?int}
 */
function party_validate(string $kind, array $in): array
{
    $errors = [];
    $noun = PARTY_NOUNS[party_kind($kind)];
    $name = '';
    $key = '';
    try {
        [$name, $key] = acct_validate_name(is_string($in['name'] ?? null) ? $in['name'] : '', 'name', $noun, 120);
    } catch (ValidationException $e) {
        $errors += $e->errors;
    }
    $text = clean_text_fields($in, [
        'phone' => ['الهاتف', 40, false],
        'address' => ['العنوان', 200, false],
        'notes' => ['الملاحظات', 500, true],
    ], $errors);

    $opening = 0;
    $rawOpening = trim(is_string($in['opening_balance'] ?? null) ? $in['opening_balance'] : '');
    if ($rawOpening !== '') {
        [$p, $err] = parse_money_input($rawOpening, 'الرصيد الافتتاحي', true);
        if ($err !== null) {
            $errors['opening_balance'] = $err;
        } else {
            $dir = is_string($in['opening_direction'] ?? null) ? $in['opening_direction'] : '';
            if (!isset(PARTY_DIRECTIONS[$dir])) {
                $errors['opening_direction'] = 'اختر اتجاه الرصيد الافتتاحي: عليه أو له.';
            } else {
                $opening = party_signed_amount($kind, (int) $p, $dir);
            }
        }
    }

    $limit = null;
    $rawLimit = trim(is_string($in['credit_limit'] ?? null) ? $in['credit_limit'] : '');
    if ($rawLimit !== '') {
        [$p, $err] = parse_money_input($rawLimit, 'حد الائتمان', true);
        if ($err !== null) {
            $errors['credit_limit'] = $err;
        } else {
            $limit = (int) $p;
        }
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    return $text + ['name' => $name, 'name_key' => $key, 'opening' => $opening, 'credit_limit' => $limit];
}

function party_duplicate_error(string $kind): ValidationException
{
    return new ValidationException(['name' => $kind === 'customer' ? 'يوجد عميل بنفس الاسم.' : 'يوجد مورد بنفس الاسم.']);
}

/** إضافة عميل أو مورد. @return int المعرّف */
function party_create(PDO $pdo, int $userId, string $kind, array $in): int
{
    party_kind($kind);
    if (!acct_can('parties.create')) {
        throw acct_denied('إضافة ' . PARTY_PLURALS[$kind]);
    }
    $v = party_validate($kind, $in);
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($kind, $v, $userId) {
            $pdo->prepare('INSERT INTO parties (kind, name, name_key, phone, address, notes, opening_balance, balance, credit_limit, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$kind, $v['name'], $v['name_key'], $v['phone'], $v['address'], $v['notes'],
                    piasters_to_money($v['opening']), piasters_to_money($v['opening']),
                    $v['credit_limit'] === null ? null : piasters_to_money($v['credit_limit']), now(), $userId ?: null]);
            $id = (int) $pdo->lastInsertId();
            acct_audit($pdo, 'party.create', sprintf('إضافة %s «%s» برصيد افتتاحي %s', PARTY_NOUNS[$kind], $v['name'], fmt_party_balance($v['opening'], $kind)),
                'party', $id, ['opening_balance' => piasters_to_money($v['opening'])]);
            data_version_bump($pdo);
            return $id;
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw party_duplicate_error($kind);
        }
        throw $e;
    }
}

/** تعديل بيانات الطرف (للمدير). تغيير الافتتاحي يحرك الرصيد بنفس الفرق. */
function party_update(PDO $pdo, int $userId, int $id, array $in): void
{
    if (!acct_can('parties.manage')) {
        throw acct_denied('تعديل الحسابات');
    }
    $row = party_find($pdo, $id);
    if (!$row) {
        throw new ValidationException(['form' => 'الحساب غير موجود.']);
    }
    $kind = $row['kind'];
    $v = party_validate($kind, $in);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($id, $kind, $v) {
            $stmt = $pdo->prepare('SELECT id, kind, name, opening_balance, balance FROM parties WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old || $old['kind'] !== $kind) {
                throw new ValidationException(['form' => 'الحساب غير موجود.']);
            }
            $delta = $v['opening'] - money_to_piasters($old['opening_balance']);
            $pdo->prepare('UPDATE parties SET name = ?, name_key = ?, phone = ?, address = ?, notes = ?,
                    opening_balance = ?, balance = balance + ?, credit_limit = ? WHERE id = ?')
                ->execute([$v['name'], $v['name_key'], $v['phone'], $v['address'], $v['notes'],
                    piasters_to_money($v['opening']), piasters_to_money($delta),
                    $v['credit_limit'] === null ? null : piasters_to_money($v['credit_limit']), $id]);
            $summary = sprintf('تعديل %s «%s»', PARTY_NOUNS[$kind], $v['name']);
            if ($delta !== 0) {
                $summary .= sprintf('، الرصيد الافتتاحي من %s إلى %s',
                    fmt_party_balance(money_to_piasters($old['opening_balance']), $kind), fmt_party_balance($v['opening'], $kind));
            }
            acct_audit($pdo, 'party.update', $summary, 'party', $id, [
                'old_name' => $old['name'], 'old_opening_balance' => (string) $old['opening_balance'],
                'new_opening_balance' => piasters_to_money($v['opening']), 'delta' => piasters_to_money($delta),
            ]);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw party_duplicate_error($kind);
        }
        throw $e;
    }
}

function party_set_active(PDO $pdo, int $userId, int $id, bool $active): void
{
    if (!acct_can('parties.manage')) {
        throw acct_denied('إيقاف الحسابات أو تفعيلها');
    }
    db_transaction($pdo, function (PDO $pdo) use ($id, $active) {
        $stmt = $pdo->prepare('SELECT id, kind, name FROM parties WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new ValidationException(['form' => 'الحساب غير موجود.']);
        }
        $pdo->prepare('UPDATE parties SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        acct_audit($pdo, $active ? 'party.activate' : 'party.deactivate',
            sprintf('%s %s «%s»', $active ? 'تفعيل' : 'إيقاف', PARTY_NOUNS[$p['kind']], $p['name']), 'party', $id);
        data_version_bump($pdo);
    });
}

/** هل للطرف أي حركة؟ (قيود أو مستندات أو سندات) */
function party_is_used(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM party_ledger WHERE party_id = ?)
        + (SELECT COUNT(*) FROM documents WHERE party_id = ?) + (SELECT COUNT(*) FROM vouchers WHERE party_id = ?)');
    $stmt->execute([$id, $id, $id]);
    return (int) $stmt->fetchColumn() > 0;
}

function party_delete(PDO $pdo, int $userId, int $id): void
{
    if (!acct_can('parties.manage')) {
        throw acct_denied('حذف الحسابات');
    }
    try {
        db_transaction($pdo, function (PDO $pdo) use ($id) {
            $stmt = $pdo->prepare('SELECT id, kind, name FROM parties WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) {
                throw new ValidationException(['form' => 'الحساب غير موجود.']);
            }
            if (party_is_used($pdo, $id)) {
                throw new ValidationException(['form' => 'لا يمكن حذف حساب له حركات. يمكنك إيقافه بدلًا من الحذف.']);
            }
            $pdo->prepare('DELETE FROM parties WHERE id = ?')->execute([$id]);
            acct_audit($pdo, 'party.delete', sprintf('حذف %s «%s»', PARTY_NOUNS[$p['kind']], $p['name']), 'party', $id);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_fk_error($e)) {
            throw new ValidationException(['form' => 'لا يمكن حذف حساب له حركات. يمكنك إيقافه بدلًا من الحذف.']);
        }
        throw $e;
    }
}

function party_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM parties WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** قائمة العملاء أو الموردين مع بحث بالاسم أو الهاتف */
function parties_list(PDO $pdo, string $kind, string $q = '', string $status = ''): array
{
    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM party_ledger l WHERE l.party_id = p.id)
              + (SELECT COUNT(*) FROM documents d WHERE d.party_id = p.id) AS used
            FROM parties p WHERE p.kind = ?';
    $args = [party_kind($kind)];
    if ($status === 'active' || $status === 'inactive') {
        $sql .= ' AND p.is_active = ?';
        $args[] = $status === 'active' ? 1 : 0;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= ' AND (p.name LIKE ? OR p.phone LIKE ? OR p.name_key LIKE ?)';
        array_push($args, $like, $like, '%' . addcslashes(name_key($q), '%_\\') . '%');
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY p.is_active DESC, p.name, p.id');
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/**
 * كشف حساب عميل أو مورد لفترة.
 * الرصيد الافتتاحي للكشف = الافتتاحي + مجموع القيود قبل $from. الصفوف بترتيب (التاريخ، المعرّف).
 * عمودا «عليه» و«له» بالقروش الموجبة: «عليه» يزيد ما يستحق لنا عليه، و«له» يزيد ما يستحق له علينا.
 * @param ?string $from Y-m-d  @param ?string $to Y-m-d (شامل)
 */
function party_statement(PDO $pdo, int $id, ?string $from, ?string $to): array
{
    $party = party_find($pdo, $id);
    if (!$party) {
        throw new ValidationException(['party' => 'الحساب غير موجود.']);
    }
    $kind = $party['kind'];
    $opening = money_to_piasters($party['opening_balance']);
    if ($from !== null) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM party_ledger WHERE party_id = ? AND entry_date < ?');
        $stmt->execute([$id, $from . ' 00:00:00']);
        $opening += money_to_piasters((string) $stmt->fetchColumn());
    }
    $sql = 'SELECT l.id, l.entry_date, l.entry_type, l.amount, l.description, l.document_id, l.voucher_id, l.reversal_of,
                   d.kind AS doc_kind, d.doc_no AS doc_no, v.kind AS voucher_kind, v.doc_no AS voucher_no
            FROM party_ledger l
            LEFT JOIN documents d ON d.id = l.document_id
            LEFT JOIN vouchers v ON v.id = l.voucher_id
            WHERE l.party_id = ?';
    $args = [$id];
    if ($from !== null) {
        $sql .= ' AND l.entry_date >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $sql .= ' AND l.entry_date < ?';
        $args[] = acct_next_day($to);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY l.entry_date, l.id');
    $stmt->execute($args);
    $running = $opening;
    $totAlayh = 0;
    $totLah = 0;
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $amount = money_to_piasters($r['amount']);
        $running += $amount;
        $owesUsSide = $kind === 'customer' ? $amount > 0 : $amount < 0;
        $alayh = $owesUsSide ? abs($amount) : 0;
        $lah = $owesUsSide ? 0 : abs($amount);
        $totAlayh += $alayh;
        $totLah += $lah;
        if ($r['document_id'] !== null) {
            $link = ['route' => 'document', 'id' => (int) $r['document_id'],
                'label' => doc_label(['kind' => $r['doc_kind'], 'doc_no' => $r['doc_no']])];
        } else {
            $link = ['route' => 'voucher', 'id' => (int) $r['voucher_id'],
                'label' => voucher_label(['kind' => $r['voucher_kind'], 'doc_no' => $r['voucher_no']])];
        }
        $rows[] = [
            'id' => (int) $r['id'], 'date' => $r['entry_date'], 'type' => $r['entry_type'],
            'description' => $r['description'], 'amount' => $amount,
            'alayh' => $alayh, 'lah' => $lah, 'balance' => $running, 'link' => $link,
            'is_reversal' => $r['reversal_of'] !== null,
        ];
    }
    return [
        'party' => $party, 'kind' => $kind, 'from' => $from, 'to' => $to,
        'opening' => $opening, 'rows' => $rows,
        'total_alayh' => $totAlayh, 'total_lah' => $totLah, 'closing' => $running,
    ];
}
