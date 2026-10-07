<?php
defined('APP_ROOT') || exit;

/*
 * الفروع ونطاق المستخدم.
 *
 *  - كل مخزن يتبع فرعًا واحدًا (warehouses.branch_id). نقل المخزن إلى فرع آخر يغير التجميع فقط،
 *    والمستندات السابقة تحتفظ باسم الفرع كما سُجل وقتها (documents.branch_name و to_branch_name).
 *  - نطاق المستخدم users.branch_id: NULL = كل الفروع. المستخدم المقيد بفرع يرى مخازن فرعه وأرصدتها
 *    ومستنداتها فقط، ويسجل الوارد والبيع والتحويل من مخازن فرعه فقط (التحويل إلى مخزن في أي فرع مسموح)،
 *    ولا يدير الفروع ولا المخازن. التحقق يتم في الخدمات نفسها على الخادم، وليس في الواجهة فقط.
 *  - ترتيب الأقفال: صفوف warehouses قبل صفوف branches دائمًا (كلٌّ مرتب تصاعديًا).
 *      المستندات: warehouses ثم branches (مشترك) ← ثم ترتيب المستندات المعتاد (انظر documents.php).
 *      نقل مخزن: صف المخزن (حصري) ثم صف الفرع الجديد (مشترك).
 *      إضافة مخزن: صف الفرع (مشترك) ثم إدراج المخزن الجديد (لا يقفل صفوف مخازن موجودة).
 *      تعديل فرع: صفه (حصري). حذف فرع: كل صفوف الفروع (حصري، مرتبة) ثم العد.
 */

const BRANCH_NO_ACCESS = 'لا تملك صلاحية على هذا المخزن.';
const BRANCH_ADMIN_ONLY = 'إضافة الفروع والمخازن وتعديلها وحذفها متاحة فقط لمستخدم يرى كل الفروع.';
const BRANCH_DEFAULT_NAME = 'الفرع الرئيسي';

/* ===================== نطاق المستخدم ===================== */

/** @return array<int,?int> معرف المستخدم => فرعه (ذاكرة مؤقتة لكل طلب) */
function &branch_scope_cache(): array
{
    static $cache = [];
    return $cache;
}

function reset_branch_scope_cache(): void
{
    $cache = &branch_scope_cache();
    $cache = [];
}

/**
 * فرع المستخدم المسجل، أو null إذا كان يرى كل الفروع.
 * null أيضًا بدون جلسة مستخدم (سطر الأوامر والاختبارات)، أو قبل تطبيق ترقية الفروع (العمود غير موجود).
 */
function allowed_branch_id(PDO $pdo): ?int
{
    $uid = current_user_id();
    if ($uid <= 0) {
        return null;
    }
    $cache = &branch_scope_cache();
    if (!array_key_exists($uid, $cache)) {
        try {
            $stmt = $pdo->prepare('SELECT branch_id FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $v = $stmt->fetchColumn();
            $cache[$uid] = ($v === false || $v === null) ? null : (int) $v;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1054) { // 1054 = عمود غير موجود (الترقية لم تُطبق بعد)
                throw $e;
            }
            $cache[$uid] = null;
        }
    }
    return $cache[$uid];
}

/** هل الفرع ضمن النطاق؟ (النطاق null = كل الفروع، والفرع غير المعروف خارج أي نطاق محدد) */
function branch_in_scope(?int $scope, mixed $branchId): bool
{
    return $scope === null || ($branchId !== null && (int) $branchId === $scope);
}

/** المستند ظاهر للمستخدم إذا كان فرعه المصدر أو الفرع المستلم (في التحويل) ضمن نطاقه */
function document_visible(array $doc, ?int $scope): bool
{
    return branch_in_scope($scope, $doc['branch_id'] ?? null)
        || ($doc['kind'] === 'transfer' && branch_in_scope($scope, $doc['to_branch_id'] ?? null));
}

/** إلغاء المستند يغير أرصدة كل أطرافه، فيتطلب أن يكون كل طرف فيه ضمن النطاق */
function document_cancellable(array $doc, ?int $scope): bool
{
    return branch_in_scope($scope, $doc['branch_id'] ?? null)
        && ($doc['kind'] !== 'transfer' || branch_in_scope($scope, $doc['to_branch_id'] ?? null));
}

/** المستند إذا كان ظاهرًا للمستخدم الحالي، وإلا null كأنه غير موجود (لا يُكشف وجوده) */
function find_document_scoped(PDO $pdo, int $id): ?array
{
    $d = find_document($pdo, $id);
    return $d !== null && document_visible($d, allowed_branch_id($pdo)) ? $d : null;
}

/** يرفض إدارة الفروع والمخازن للمستخدم المقيد بفرع */
function require_all_branches(PDO $pdo, string $field = 'name'): void
{
    if (allowed_branch_id($pdo) !== null) {
        throw new ValidationException([$field => BRANCH_ADMIN_ONLY]);
    }
}

/** رسالة خطأ إذا كان المخزن خارج نطاق المستخدم الحالي (فحص مبدئي قبل المعاملة، بدون قفل) */
function warehouse_scope_error(PDO $pdo, int $warehouseId): ?string
{
    $scope = allowed_branch_id($pdo);
    if ($scope === null) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT branch_id FROM warehouses WHERE id = ?');
    $stmt->execute([$warehouseId]);
    return branch_in_scope($scope, $stmt->fetchColumn() ?: null) ? null : BRANCH_NO_ACCESS;
}

/* ===================== قوائم المخازن ===================== */

/**
 * المخازن مع أسماء فروعها، مرتبة حسب الفرع ثم الاسم. $branchId = null يعني كل الفروع.
 * @return array<int,array{id:int,name:string,branch_id:int,branch_name:string}>
 */
function scoped_warehouses(PDO $pdo, ?int $branchId): array
{
    $stmt = $pdo->prepare(
        'SELECT w.id, w.name, w.branch_id, b.name AS branch_name
         FROM warehouses w JOIN branches b ON b.id = w.branch_id'
        . ($branchId !== null ? ' WHERE w.branch_id = ?' : '')
        . ' ORDER BY b.name, b.id, w.name, w.id'
    );
    $stmt->execute($branchId !== null ? [$branchId] : []);
    return $stmt->fetchAll();
}

/** خيارات المخازن، مجمعة حسب الفرع (optgroup) إذا كانت من أكثر من فرع */
function warehouse_options(array $warehouses, string $selected, string $placeholder = ''): string
{
    $branchIds = array_unique(array_map(fn ($w) => (int) ($w['branch_id'] ?? 0), $warehouses));
    if (count($branchIds) <= 1) {
        return options_html($warehouses, $selected, $placeholder);
    }
    $html = $placeholder !== '' ? '<option value="">' . h($placeholder) . '</option>' : '';
    $group = null;
    foreach ($warehouses as $w) {
        if ($group !== (int) $w['branch_id']) {
            $html .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . h($w['branch_name']) . '">';
            $group = (int) $w['branch_id'];
        }
        $html .= '<option value="' . (int) $w['id'] . '"' . ((string) $w['id'] === $selected ? ' selected' : '') . '>' . h($w['name']) . '</option>';
    }
    return $html . '</optgroup>';
}

/* ===================== أقفال المستندات ===================== */

/**
 * أول خطوة في معاملة الوارد والبيع والتحويل: قفل مشترك على صفوف المخازن ثم صفوف فروعها،
 * كلٌّ مرتب تصاعديًا. القيم المعادة (اسم المخزن وفرعه) من صفوف مقفلة لا تتغير حتى نهاية المعاملة،
 * فاسم الفرع المحفوظ في المستند وفحص نطاق المستخدم صحيحان حتى مع نقل مخزن أو تعديل اسم في نفس اللحظة.
 * @return array<int,array{id:int,name:string,branch_id:int,branch_name:string}>
 */
function lock_doc_warehouses(PDO $pdo, array $warehouseIds): array
{
    $ids = array_values(array_unique(array_map('intval', $warehouseIds)));
    sort($ids);
    $stmt = $pdo->prepare('SELECT id, name, branch_id FROM warehouses WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id LOCK IN SHARE MODE');
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $w) {
        $out[(int) $w['id']] = ['id' => (int) $w['id'], 'name' => (string) $w['name'], 'branch_id' => (int) $w['branch_id']];
    }
    if (count($out) !== count($ids)) {
        throw new ValidationException(['form' => 'أحد المخازن المختارة لم يعد موجودًا. حدّث الصفحة وأعد المحاولة.']);
    }
    $branchIds = array_values(array_unique(array_column($out, 'branch_id')));
    sort($branchIds);
    $stmt = $pdo->prepare('SELECT id, name FROM branches WHERE id IN ('
        . implode(',', array_fill(0, count($branchIds), '?')) . ') ORDER BY id LOCK IN SHARE MODE');
    $stmt->execute($branchIds);
    $names = array_column($stmt->fetchAll(), 'name', 'id');
    foreach ($out as &$w) {
        if (!isset($names[$w['branch_id']])) {
            throw new RuntimeException('Warehouse without branch');
        }
        $w['branch_name'] = (string) $names[$w['branch_id']];
    }
    unset($w);
    return $out;
}

/** فرع المخزن الجديد مع قفل مشترك حتى نهاية المعاملة. null = أول فرع (للاستدعاءات القديمة) */
function lock_branch_for_new_warehouse(PDO $pdo, ?int $branchId): int
{
    if ($branchId === null) {
        $id = $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1 LOCK IN SHARE MODE')->fetchColumn();
    } else {
        $stmt = $pdo->prepare('SELECT id FROM branches WHERE id = ? LOCK IN SHARE MODE');
        $stmt->execute([$branchId]);
        $id = $stmt->fetchColumn();
    }
    if ($id === false) {
        throw new ValidationException(['branch_id' => 'اختر فرع المخزن.']);
    }
    return (int) $id;
}

/* ===================== إدارة الفروع ===================== */

function branch_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, address, phone FROM branches WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * يتحقق من بيانات الفرع. الهاتف يقبل الأرقام العربية والإنجليزية ويُحفظ بالأرقام الإنجليزية.
 * @return array{0:string,1:string,2:string,3:string} [الاسم, مفتاح المقارنة, العنوان, الهاتف]
 */
function branch_validate(array $in): array
{
    $errors = [];
    $name = $key = '';
    try {
        [$name, $key] = catalog_validate_name('branch', input($in, 'name'));
    } catch (ValidationException $e) {
        $errors += $e->errors;
    }
    $address = clean_text(input($in, 'address'));
    if (mb_strlen($address) > 200) {
        $errors['address'] = 'العنوان أطول من المسموح (' . fmt_int(200) . ' حرف على الأكثر).';
    }
    $phone = normalize_number_input(clean_text(input($in, 'phone')));
    if (mb_strlen($phone) > 40) {
        $errors['phone'] = 'رقم الهاتف أطول من المسموح (' . fmt_int(40) . ' حرفًا على الأكثر).';
    } elseif (!preg_match('/^[0-9+\- ]*\z/', $phone)) {
        $errors['phone'] = 'رقم الهاتف يقبل الأرقام والمسافات وعلامتي + و- فقط.';
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    return [$name, $key, $address, $phone];
}

/** @param array{name?:string,address?:string,phone?:string} $in */
function branch_create(PDO $pdo, array $in): int
{
    require_all_branches($pdo);
    [$name, $key, $address, $phone] = branch_validate($in);
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($name, $key, $address, $phone) {
            $pdo->prepare('INSERT INTO branches (name, name_key, address, phone, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $key, $address, $phone, now()]);
            $id = (int) $pdo->lastInsertId();
            acct_audit($pdo, 'branch.create', 'إضافة فرع: ' . $name, 'branch', $id);
            data_version_bump($pdo);
            return $id;
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => CATALOGS['branch']['exists']]);
        }
        throw $e;
    }
}

/** تعديل الاسم والعنوان والهاتف. المستندات السابقة تحتفظ باسم الفرع وقت الحركة. */
function branch_update(PDO $pdo, int $id, array $in): void
{
    require_all_branches($pdo);
    [$name, $key, $address, $phone] = branch_validate($in);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($id, $name, $key, $address, $phone) {
            $stmt = $pdo->prepare('SELECT id FROM branches WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                throw new ValidationException(['name' => CATALOGS['branch']['missing']]);
            }
            $pdo->prepare('UPDATE branches SET name = ?, name_key = ?, address = ?, phone = ? WHERE id = ?')
                ->execute([$name, $key, $address, $phone, $id]);
            acct_audit($pdo, 'branch.update', 'تعديل بيانات الفرع: ' . $name, 'branch', $id);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => CATALOGS['branch']['exists']]);
        }
        throw $e;
    }
}

/** الحذف مسموح فقط لفرع بلا مخازن ولا مستندات ولا مستخدمين، ويبقى فرع واحد على الأقل */
function branch_delete(PDO $pdo, int $id): void
{
    require_all_branches($pdo);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($id) {
            // قفل كل صفوف الفروع بترتيب ثابت قبل العد: حذفان متزامنان لفرعين لا يتركان النظام بلا فروع
            $ids = array_map('intval', $pdo->query('SELECT id FROM branches ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($id, $ids, true)) {
                throw new ValidationException(['name' => CATALOGS['branch']['missing']]);
            }
            if (count($ids) <= 1) {
                throw new ValidationException(['name' => 'يجب أن يبقى فرع واحد على الأقل.']);
            }
            $stmt = $pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM warehouses WHERE branch_id = ?),
                        (SELECT COUNT(*) FROM documents WHERE branch_id = ? OR to_branch_id = ?),
                        (SELECT COUNT(*) FROM users WHERE branch_id = ?)'
            );
            $stmt->execute([$id, $id, $id, $id]);
            [$warehouses, $documents, $users] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
            if ($warehouses > 0) {
                throw new ValidationException(['name' => 'لا يمكن حذف فرع تتبعه مخازن. انقل مخازنه إلى فرع آخر أولًا.']);
            }
            if ($documents > 0) {
                throw new ValidationException(['name' => 'لا يمكن حذف فرع له مستندات مسجلة. يمكنك تعديل اسمه.']);
            }
            if ($users > 0) {
                throw new ValidationException(['name' => 'لا يمكن حذف فرع مرتبط بمستخدمين. غيّر فرع هؤلاء المستخدمين أولًا.']);
            }
            $pdo->prepare('DELETE FROM branches WHERE id = ?')->execute([$id]);
            acct_audit($pdo, 'branch.delete', 'حذف فرع رقم ' . $id, 'branch', $id);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_fk_error($e)) {
            throw new ValidationException(['name' => 'لا يمكن حذف الفرع لأنه مستخدم.']);
        }
        throw $e;
    }
}

/**
 * ينقل المخزن إلى فرع آخر، حتى لو كان عليه رصيد: يتغير التجميع فقط، والمستندات السابقة تحتفظ بفرعها.
 * @return string اسم الفرع الجديد
 */
function warehouse_move(PDO $pdo, int $warehouseId, int $branchId): string
{
    require_all_branches($pdo, 'branch_id');
    return db_transaction($pdo, function (PDO $pdo) use ($warehouseId, $branchId) {
        $stmt = $pdo->prepare('SELECT id, name, branch_id FROM warehouses WHERE id = ? FOR UPDATE');
        $stmt->execute([$warehouseId]);
        $wh = $stmt->fetch();
        if (!$wh) {
            throw new ValidationException(['branch_id' => CATALOGS['warehouse']['missing']]);
        }
        $stmt = $pdo->prepare('SELECT id, name FROM branches WHERE id = ? LOCK IN SHARE MODE');
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch();
        if (!$branch) {
            throw new ValidationException(['branch_id' => 'اختر الفرع الجديد للمخزن.']);
        }
        if ((int) $wh['branch_id'] === (int) $branch['id']) {
            throw new ValidationException(['branch_id' => 'المخزن يتبع هذا الفرع بالفعل. اختر فرعًا آخر.']);
        }
        $pdo->prepare('UPDATE warehouses SET branch_id = ? WHERE id = ?')->execute([(int) $branch['id'], $warehouseId]);
        acct_audit($pdo, 'warehouse.move', 'نقل المخزن «' . $wh['name'] . '» إلى فرع ' . $branch['name'], 'warehouse', $warehouseId);
        data_version_bump($pdo);
        return (string) $branch['name'];
    });
}

/* ===================== التقارير ===================== */

/** بداية الشهر الحالي وبداية الشهر التالي بتوقيت النظام (نفس توقيت created_at في المستندات) */
function current_month_range(): array
{
    $start = new DateTimeImmutable('first day of this month 00:00:00');
    return [$start->format('Y-m-d H:i:s'), $start->modify('+1 month')->format('Y-m-d H:i:s')];
}

/** شرط سجل المستندات لفرع: المستندات الصادرة منه والتحويلات الواردة إليه */
function document_branch_condition(int $branchId): array
{
    return ['(d.branch_id = ? OR d.to_branch_id = ?)', [$branchId, $branchId]];
}

/**
 * إجماليات فواتير البيع السارية لكل عملة على حدة (العملة محفوظة مع كل فاتورة وقد تتغير في الإعدادات،
 * فلا تُجمع مبالغ عملات مختلفة أبدًا). العملة الحالية في الإعدادات أولًا.
 * $whereSql: '' أو ' WHERE ...' على الجدول documents باسم d، و $params قيمه.
 * @return array<int,array{currency:string,count:int,volume:string,amount:string}>
 */
function sales_by_currency(PDO $pdo, string $whereSql, array $params): array
{
    $cond = "d.kind = 'sale' AND d.status = 'active'";
    $stmt = $pdo->prepare(
        'SELECT d.currency, COUNT(*) AS n, COALESCE(SUM(d.total_volume_m3), 0) AS volume, COALESCE(SUM(d.total_amount), 0) AS amount
         FROM documents d' . ($whereSql !== '' ? $whereSql . ' AND ' . $cond : ' WHERE ' . $cond) . '
         GROUP BY d.currency ORDER BY d.currency'
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt as $r) {
        $out[] = ['currency' => (string) $r['currency'], 'count' => (int) $r['n'], 'volume' => (string) $r['volume'], 'amount' => (string) $r['amount']];
    }
    $current = app_setting('currency');
    usort($out, fn ($a, $b) => [$a['currency'] !== $current, $a['currency']] <=> [$b['currency'] !== $current, $b['currency']]);
    return $out;
}

/**
 * ملخص لكل فرع: عدد المخازن، والمقاسات المتاحة، والقطع، والحجم، وعدد المستندات والمستخدمين المرتبطين،
 * ومبيعات الشهر الحالي لكل عملة. $branchId = null يعني كل الفروع.
 */
function branch_summary(PDO $pdo, ?int $branchId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT b.id, b.name, b.address, b.phone,
                COUNT(DISTINCT w.id) AS warehouses,
                COUNT(DISTINCT CASE WHEN s.qty_on_hand > 0 THEN s.item_id END) AS sizes,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty,
                COALESCE(SUM(s.qty_on_hand * i.piece_volume_m3), 0) AS volume,
                (SELECT COUNT(*) FROM documents d WHERE d.branch_id = b.id OR d.to_branch_id = b.id) AS documents,
                (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS users
         FROM branches b
         LEFT JOIN warehouses w ON w.branch_id = b.id
         LEFT JOIN stock s ON s.warehouse_id = w.id
         LEFT JOIN items i ON i.id = s.item_id'
        . ($branchId !== null ? ' WHERE b.id = ?' : '')
        . ' GROUP BY b.id, b.name, b.address, b.phone ORDER BY b.name, b.id'
    );
    $stmt->execute($branchId !== null ? [$branchId] : []);
    $rows = [];
    foreach ($stmt as $r) {
        $r['volume'] = Num::trimDecimal((string) $r['volume']);
        $r['sales'] = [];
        $rows[(int) $r['id']] = $r;
    }
    [$from, $to] = current_month_range();
    $stmt = $pdo->prepare(
        "SELECT branch_id, currency, COUNT(*) AS n, SUM(total_amount) AS amount FROM documents
         WHERE kind = 'sale' AND status = 'active' AND created_at >= ? AND created_at < ?"
        . ($branchId !== null ? ' AND branch_id = ?' : '')
        . ' GROUP BY branch_id, currency ORDER BY branch_id, currency'
    );
    $stmt->execute($branchId !== null ? [$from, $to, $branchId] : [$from, $to]);
    foreach ($stmt as $s) {
        if (isset($rows[(int) $s['branch_id']])) {
            $rows[(int) $s['branch_id']]['sales'][] = ['currency' => (string) $s['currency'], 'count' => (int) $s['n'], 'amount' => (string) $s['amount']];
        }
    }
    return array_values($rows);
}

/** إجمالي ملخص الفروع: الأرقام تُجمع بدقة كاملة، والمبيعات لكل عملة على حدة */
function branch_summary_totals(array $rows): array
{
    $t = ['warehouses' => 0, 'qty' => '0', 'um3' => '0', 'sales' => []];
    foreach ($rows as $r) {
        $t['warehouses'] += (int) $r['warehouses'];
        $t['qty'] = Num::add($t['qty'], (string) $r['qty']);
        $t['um3'] = Num::add($t['um3'], m3_to_um3((string) $r['volume']));
        foreach ($r['sales'] as $s) {
            $c = $s['currency'];
            $t['sales'][$c] ??= ['currency' => $c, 'count' => 0, 'piasters' => '0'];
            $t['sales'][$c]['count'] += $s['count'];
            $t['sales'][$c]['piasters'] = Num::add($t['sales'][$c]['piasters'], Num::fromDecimal($s['amount'], 2));
        }
    }
    $t['volume'] = um3_to_m3($t['um3']);
    $t['sales'] = array_values(array_map(fn ($s) => [
        'currency' => $s['currency'], 'count' => $s['count'], 'amount' => Num::toDecimal($s['piasters'], 2),
    ], $t['sales']));
    return $t;
}

/** سطر مبيعات لكل عملة، مثل: ٣ فاتورة، ٣ ٠٠٠٫٠٠ جنيه مصري */
function fmt_sales_lines(array $sales): array
{
    return array_map(fn ($s) => 'عدد الفواتير: ' . fmt_int($s['count']) . '، ' . fmt_money_currency($s['amount'], $s['currency']), $sales);
}

/**
 * رقم الهاتف بشكل الأرقام المختار في الإعدادات. يُعرض داخل <bdo dir="ltr">: الأرقام العربية (٠١٢)
 * تأخذ اتجاه النص العربي مع الفواصل بينها، فبدون فرض الاتجاه ينقلب ترتيب أجزاء الرقم.
 */
function fmt_phone(string $phone): string
{
    return digits($phone);
}
