<?php
defined('APP_ROOT') || exit;

/*
 * تقارير الحسابات ولوحة التحكم (قراءة فقط).
 *
 * كل تقرير يعيد «مجموعة بيانات» بنفس الشكل حتى تعرضها صفحة HTML أو طبقة التصدير:
 *   title, subtitle (وصف عربي للتصفية), columns [key, label, type, align], rows, totals, generated_at
 *   وأيضًا filters (قيم التصفية بعد التحقق) و ignored (أسماء حقول تصفية غير صالحة تم تجاهلها)
 *   و sections (اختياري): جداول إضافية بنفس الشكل {title, columns, rows, totals}.
 * القيم في rows خام: المبالغ نص عشري مثل "1234.50"، الأحجام نص عشري دقيق، الأعداد int، التواريخ 'Y-m-d H:i:s'.
 * أنواع الأعمدة: text | int | volume | money | date. نسبة هامش الربح نوعها money (رقمان عشريان) وعنوانها يذكر ٪.
 *
 * المستندات: فواتير البيع السارية فقط (status = active) حسب doc_date. السندات: السارية فقط حسب voucher_date.
 * حركة الخزائن تُقرأ من دفتر الخزائن نفسه (القيود العكسية للإلغاء موجودة فيه بتاريخ الإلغاء)، فلا تُحسب مرتين.
 * كل الحسابات المالية بالقروش كأعداد صحيحة، والأحجام بالميكرومتر المكعب كنصوص دقيقة.
 */

const ACCT_REPORT_TYPES = ['text', 'int', 'volume', 'money', 'date'];

const ACCT_SALES_COLUMNS = [
    ['key' => 'invoices', 'label' => 'عدد الفواتير', 'type' => 'int', 'align' => 'end'],
    ['key' => 'volume', 'label' => 'الحجم (م³)', 'type' => 'volume', 'align' => 'end'],
    ['key' => 'amount', 'label' => 'المبيعات', 'type' => 'money', 'align' => 'end'],
    ['key' => 'cost', 'label' => 'التكلفة', 'type' => 'money', 'align' => 'end'],
    ['key' => 'profit', 'label' => 'مجمل الربح', 'type' => 'money', 'align' => 'end'],
    ['key' => 'margin', 'label' => 'هامش الربح ٪', 'type' => 'money', 'align' => 'end'],
];

const ACCT_REPORTS = [
    'sales_period' => [
        'title' => 'المبيعات حسب الفترة',
        'description' => 'عدد الفواتير والحجم والمبيعات والتكلفة ومجمل الربح لكل يوم أو شهر.',
        'permission' => 'reports.sales',
        'filters' => ['from', 'to', 'group', 'warehouse', 'type', 'customer'],
        'columns' => [['key' => 'period', 'label' => 'الفترة', 'type' => 'text', 'align' => 'start'], ...ACCT_SALES_COLUMNS],
    ],
    'sales_by' => [
        'title' => 'تحليل المبيعات',
        'description' => 'المبيعات وربحها حسب نوع الخشب أو المخزن أو العميل.',
        'permission' => 'reports.sales',
        'filters' => ['from', 'to', 'dimension', 'warehouse', 'type', 'customer'],
        'columns' => [
            ['key' => 'label', 'label' => 'البند', 'type' => 'text', 'align' => 'start'],
            ACCT_SALES_COLUMNS[0],
            ['key' => 'qty', 'label' => 'القطع', 'type' => 'int', 'align' => 'end'],
            ACCT_SALES_COLUMNS[1], ACCT_SALES_COLUMNS[2], ACCT_SALES_COLUMNS[3], ACCT_SALES_COLUMNS[4], ACCT_SALES_COLUMNS[5],
        ],
    ],
    'profit' => [
        'title' => 'الأرباح',
        'description' => 'الإيرادات وتكلفة المبيعات ومجمل الربح، ثم المصروفات وفروق التكلفة وصافي الربح.',
        'permission' => 'reports.profit',
        'filters' => ['from', 'to'],
        'columns' => [
            ['key' => 'label', 'label' => 'البند', 'type' => 'text', 'align' => 'start'],
            ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'money', 'align' => 'end'],
        ],
    ],
    'valuation' => [
        'title' => 'تقييم المخزون',
        'description' => 'قيمة المخزون الحالي لكل صنف بالمتوسط المرجح، وتوزيعها على المخازن.',
        'permission' => 'reports.valuation',
        'filters' => ['warehouse', 'type'],
        'columns' => [
            ['key' => 'type_name', 'label' => 'النوع', 'type' => 'text', 'align' => 'start'],
            ['key' => 'size', 'label' => 'المقاس', 'type' => 'text', 'align' => 'start'],
            ['key' => 'qty', 'label' => 'القطع', 'type' => 'int', 'align' => 'end'],
            ['key' => 'volume', 'label' => 'الحجم (م³)', 'type' => 'volume', 'align' => 'end'],
            ['key' => 'avg_cost', 'label' => 'متوسط تكلفة المتر', 'type' => 'money', 'align' => 'end'],
            ['key' => 'value', 'label' => 'القيمة', 'type' => 'money', 'align' => 'end'],
            ['key' => 'status', 'label' => 'الحالة', 'type' => 'text', 'align' => 'start'],
        ],
    ],
    'customer_balances' => [
        'title' => 'أرصدة العملاء',
        'description' => 'المستحق على كل عميل الآن.',
        'permission' => 'statements.view',
        'filters' => ['show'],
        'columns' => [
            ['key' => 'name', 'label' => 'العميل', 'type' => 'text', 'align' => 'start'],
            ['key' => 'phone', 'label' => 'الهاتف', 'type' => 'text', 'align' => 'start'],
            ['key' => 'balance', 'label' => 'الرصيد', 'type' => 'money', 'align' => 'end'],
            ['key' => 'direction', 'label' => 'الاتجاه', 'type' => 'text', 'align' => 'start'],
            ['key' => 'credit_limit', 'label' => 'حد الائتمان', 'type' => 'money', 'align' => 'end'],
            ['key' => 'last_entry', 'label' => 'آخر حركة', 'type' => 'date', 'align' => 'start'],
            ['key' => 'status', 'label' => 'الحالة', 'type' => 'text', 'align' => 'start'],
        ],
    ],
    'supplier_balances' => [
        'title' => 'أرصدة الموردين',
        'description' => 'المستحق لكل مورد الآن.',
        'permission' => 'statements.view',
        'filters' => ['show'],
        'columns' => [
            ['key' => 'name', 'label' => 'المورد', 'type' => 'text', 'align' => 'start'],
            ['key' => 'phone', 'label' => 'الهاتف', 'type' => 'text', 'align' => 'start'],
            ['key' => 'balance', 'label' => 'الرصيد', 'type' => 'money', 'align' => 'end'],
            ['key' => 'direction', 'label' => 'الاتجاه', 'type' => 'text', 'align' => 'start'],
            ['key' => 'credit_limit', 'label' => 'حد الائتمان', 'type' => 'money', 'align' => 'end'],
            ['key' => 'last_entry', 'label' => 'آخر حركة', 'type' => 'date', 'align' => 'start'],
            ['key' => 'status', 'label' => 'الحالة', 'type' => 'text', 'align' => 'start'],
        ],
    ],
    'cash_summary' => [
        'title' => 'ملخص الخزائن',
        'description' => 'رصيد كل خزنة، والحركة خلال الفترة حسب النوع، والرصيد اليومي.',
        'permission' => 'reports.profit',
        'filters' => ['from', 'to', 'cash_box'],
        'columns' => [
            ['key' => 'name', 'label' => 'الخزنة', 'type' => 'text', 'align' => 'start'],
            ['key' => 'opening', 'label' => 'رصيد أول الفترة', 'type' => 'money', 'align' => 'end'],
            ['key' => 'in', 'label' => 'داخل', 'type' => 'money', 'align' => 'end'],
            ['key' => 'out', 'label' => 'خارج', 'type' => 'money', 'align' => 'end'],
            ['key' => 'closing', 'label' => 'رصيد آخر الفترة', 'type' => 'money', 'align' => 'end'],
            ['key' => 'balance', 'label' => 'الرصيد الحالي', 'type' => 'money', 'align' => 'end'],
        ],
    ],
    'expenses' => [
        'title' => 'المصروفات',
        'description' => 'المصروفات حسب التصنيف أو حسب اليوم أو الشهر.',
        'permission' => 'reports.profit',
        'filters' => ['from', 'to', 'group_expenses', 'category', 'cash_box'],
        'columns' => [
            ['key' => 'period', 'label' => 'الفترة', 'type' => 'text', 'align' => 'start'],
            ['key' => 'category', 'label' => 'التصنيف', 'type' => 'text', 'align' => 'start'],
            ['key' => 'count', 'label' => 'عدد السندات', 'type' => 'int', 'align' => 'end'],
            ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'money', 'align' => 'end'],
        ],
    ],
];

const ACCT_GROUP_LABELS = ['day' => 'يومي', 'month' => 'شهري'];
const ACCT_EXPENSE_GROUP_LABELS = ['category' => 'حسب التصنيف', 'month' => 'شهري', 'day' => 'يومي'];
const ACCT_DIMENSION_LABELS = ['type' => 'نوع الخشب', 'warehouse' => 'المخزن', 'customer' => 'العميل', 'branch' => 'الفرع'];
const ACCT_SHOW_LABELS = ['nonzero' => 'الأرصدة غير الصفرية فقط', 'all' => 'كل الحسابات'];
const ACCT_CASH_ENTRY_LABELS = [
    'sale' => 'مبيعات',
    'purchase' => 'مشتريات',
    'collect' => 'تحصيل من العملاء',
    'pay' => 'سداد للموردين',
    'expense' => 'مصروفات',
    'transfer_in' => 'تحويل وارد',
    'transfer_out' => 'تحويل صادر',
    'reversal' => 'قيود إلغاء',
];
const ACCT_CASH_CUSTOMER_LABEL = 'عميل نقدي (بدون حساب)';

/* ---------------- حساب دقيق مساعد ---------------- */

/** a - b لعددين صحيحين غير سالبين كنصوص، بشرط a >= b */
function acct_big_sub(string $a, string $b): string
{
    $a = Num::norm($a);
    $b = Num::norm($b);
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;
    $borrow = 0;
    $out = '';
    while ($i >= 0) {
        $d = (ord($a[$i]) - 48) - $borrow - ($j >= 0 ? ord($b[$j]) - 48 : 0);
        $borrow = $d < 0 ? 1 : 0;
        $out .= chr(48 + ($d + 10) % 10);
        $i--;
        $j--;
    }
    return Num::norm(strrev($out));
}

/** قسمة عددين صحيحين كبيرين غير سالبين كنصوص، مقربة النصف لأعلى (المقام موجب) */
function acct_big_div_half_up(string $num, string $den): string
{
    $num = Num::norm($num);
    $den = Num::norm($den);
    if ($den === '0') {
        throw new InvalidArgumentException('Division by zero');
    }
    $q = '';
    $r = '0';
    $len = strlen($num);
    for ($i = 0; $i < $len; $i++) {
        $r = Num::norm($r . $num[$i]);
        $digit = 0;
        while (Num::cmp($r, $den) >= 0) {
            $r = acct_big_sub($r, $den);
            $digit++;
        }
        $q .= (string) $digit;
    }
    $q = Num::norm($q);
    return Num::cmp(Num::add($r, $r), $den) >= 0 ? Num::add($q, '1') : $q;
}

/** حجم بالميكرومتر المكعب إلى نص م³ دقيق بدون أصفار زائدة */
function acct_um3_out(string $um3): string
{
    return Num::trimDecimal(um3_to_m3($um3));
}

/** هامش الربح ٪ بخانتين (النصف لأعلى) كنص، أو null إذا لم توجد مبيعات */
function acct_margin(int $profit, int $amount): ?string
{
    if ($amount <= 0) {
        return null;
    }
    $bp = muldiv_half_up(abs($profit), 10000, $amount);
    return piasters_to_money($profit < 0 ? -$bp : $bp);
}

/* ---------------- التصفية ---------------- */

/** يتحقق من تاريخ Y-m-d (يقبل الأرقام العربية) ويعيده، أو null */
function acct_valid_date(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $raw = normalize_number_input(trim($raw));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : null;
}

/** يتحقق من معرّف رقمي موجب */
function acct_valid_id(mixed $raw): ?int
{
    if (is_int($raw)) {
        return $raw > 0 ? $raw : null;
    }
    if (!is_string($raw)) {
        return null;
    }
    $raw = normalize_number_input(trim($raw));
    return preg_match('/^[1-9]\d{0,9}\z/', $raw) && (int) $raw <= 4294967295 ? (int) $raw : null;
}

/** هل يوجد عمود في جدول؟ (من information_schema مرة واحدة لكل اتصال) */
function acct_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $k = spl_object_id($pdo) . ':' . $table . '.' . $column;
    if (!isset($cache[$k])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $cache[$k] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$k];
}

/** اسم من جدول بالمعرّف (الجداول ثابتة في الكود وليست من المستخدم) */
function acct_name_of(PDO $pdo, string $table, int $id, string $where = ''): ?string
{
    $stmt = $pdo->prepare("SELECT name FROM {$table} WHERE id = ?" . $where);
    $stmt->execute([$id]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * يقرأ حقول التصفية المسموحة للتقرير فقط ويتحقق منها. أي قيمة غير صالحة تُتجاهل وتُذكر في ignored.
 * @return array{0: array, 1: list<string>, 2: array} [القيم, الحقول المتجاهلة, أسماء العناصر المختارة للوصف]
 */
function acct_report_filters(PDO $pdo, string $key, array $raw): array
{
    $spec = ACCT_REPORTS[$key]['filters'];
    $f = ['from' => null, 'to' => null, 'group' => null, 'dimension' => null, 'warehouse' => null, 'type' => null,
        'customer' => null, 'cash_box' => null, 'category' => null, 'show' => null];
    $ignored = [];
    $names = [];
    $present = fn (string $k) => isset($raw[$k]) && $raw[$k] !== '';
    foreach (['from', 'to'] as $k) {
        if (in_array($k, $spec, true) && $present($k)) {
            $f[$k] = acct_valid_date($raw[$k]);
            if ($f[$k] === null) {
                $ignored[] = $k;
            }
        }
    }
    if ($f['from'] !== null && $f['to'] !== null && $f['from'] > $f['to']) {
        [$f['from'], $f['to']] = [$f['to'], $f['from']];
    }
    $enum = function (string $field, array $allowed, string $default) use ($raw, $present, &$ignored): string {
        if (!$present($field)) {
            return $default;
        }
        $v = $raw[$field];
        if (is_string($v) && isset($allowed[$v])) {
            return $v;
        }
        $ignored[] = $field;
        return $default;
    };
    if (in_array('group', $spec, true)) {
        $f['group'] = $enum('group', ACCT_GROUP_LABELS, 'day');
    }
    if (in_array('group_expenses', $spec, true)) {
        $f['group'] = $enum('group', ACCT_EXPENSE_GROUP_LABELS, 'category');
    }
    if (in_array('dimension', $spec, true)) {
        $dims = ACCT_DIMENSION_LABELS;
        if (!acct_has_column($pdo, 'documents', 'branch_id')) {
            unset($dims['branch']);
        }
        $f['dimension'] = $enum('dimension', $dims, 'type');
    }
    if (in_array('show', $spec, true)) {
        $f['show'] = $enum('show', ACCT_SHOW_LABELS, 'nonzero');
    }
    $lookups = [
        'warehouse' => ['warehouses', ''],
        'type' => ['wood_types', ''],
        'customer' => ['parties', " AND kind = 'customer'"],
        'cash_box' => ['cash_boxes', ''],
        'category' => ['expense_categories', ''],
    ];
    foreach ($lookups as $k => [$table, $where]) {
        if (!in_array($k, $spec, true) || !$present($k)) {
            continue;
        }
        $id = acct_valid_id($raw[$k]);
        $name = $id !== null ? acct_name_of($pdo, $table, $id, $where) : null;
        if ($name === null) {
            $ignored[] = $k;
            continue;
        }
        $f[$k] = $id;
        $names[$k] = $name;
    }
    return [$f, $ignored, $names];
}

/** الوصف العربي للتصفية المطبقة */
function acct_report_subtitle(string $key, array $f, array $names): string
{
    $parts = [];
    $spec = ACCT_REPORTS[$key]['filters'];
    if (in_array('from', $spec, true)) {
        if ($f['from'] !== null && $f['to'] !== null) {
            $parts[] = 'من ' . digits($f['from']) . ' إلى ' . digits($f['to']);
        } elseif ($f['from'] !== null) {
            $parts[] = 'من ' . digits($f['from']);
        } elseif ($f['to'] !== null) {
            $parts[] = 'حتى ' . digits($f['to']);
        } else {
            $parts[] = 'كل الفترات';
        }
    } elseif ($key === 'valuation') {
        $parts[] = 'المخزون الحالي';
    } else {
        $parts[] = 'الأرصدة الحالية';
    }
    if ($key === 'sales_period') {
        $parts[] = 'التجميع: ' . ACCT_GROUP_LABELS[$f['group']];
    } elseif ($key === 'expenses') {
        $parts[] = 'التجميع: ' . ACCT_EXPENSE_GROUP_LABELS[$f['group']];
    }
    if ($f['dimension'] !== null) {
        $parts[] = 'حسب ' . ACCT_DIMENSION_LABELS[$f['dimension']];
    }
    $labels = ['warehouse' => 'المخزن', 'type' => 'نوع الخشب', 'customer' => 'العميل', 'cash_box' => 'الخزنة', 'category' => 'التصنيف'];
    foreach ($labels as $k => $label) {
        if (isset($names[$k])) {
            $parts[] = $label . ': ' . $names[$k];
        }
    }
    if ($f['show'] !== null) {
        $parts[] = ACCT_SHOW_LABELS[$f['show']];
    }
    return implode('، ', $parts);
}

/** شرط الفترة على عمود تاريخ: [أجزاء SQL, المعاملات] */
function acct_date_where(string $col, ?string $from, ?string $to): array
{
    $w = [];
    $p = [];
    if ($from !== null) {
        $w[] = "{$col} >= ?";
        $p[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $w[] = "{$col} < ?";
        $p[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    return [$w, $p];
}

/* ---------------- الواجهة العامة ---------------- */

/**
 * يبني مجموعة بيانات تقرير. لا يفحص الصلاحية (الصفحة وطبقة التصدير تستدعيان acct_require بصلاحية التقرير).
 * @param array $filters قيم خام من الطلب (مثل $_GET)، وأي قيمة غير صالحة تُتجاهل بأمان
 */
function acct_report(PDO $pdo, string $key, array $filters): array
{
    if (!isset(ACCT_REPORTS[$key])) {
        throw new InvalidArgumentException('Unknown report: ' . $key);
    }
    [$f, $ignored, $names] = acct_report_filters($pdo, $key, $filters);
    $def = ACCT_REPORTS[$key];
    $ds = match ($key) {
        'sales_period' => acct_rep_sales_period($pdo, $f),
        'sales_by' => acct_rep_sales_by($pdo, $f),
        'profit' => acct_rep_profit($pdo, $f),
        'valuation' => acct_rep_valuation($pdo, $f),
        'customer_balances' => acct_rep_balances($pdo, $f, 'customer'),
        'supplier_balances' => acct_rep_balances($pdo, $f, 'supplier'),
        'cash_summary' => acct_rep_cash($pdo, $f),
        'expenses' => acct_rep_expenses($pdo, $f),
    };
    $columns = $ds['columns'] ?? $def['columns'];
    $out = [
        'key' => $key,
        'title' => $def['title'],
        'subtitle' => acct_report_subtitle($key, $f, $names),
        'columns' => $columns,
        'rows' => $ds['rows'],
        'totals' => $ds['totals'],
        'generated_at' => now(),
        'filters' => array_filter($f, fn ($v) => $v !== null),
        'ignored' => array_values(array_unique($ignored)),
    ];
    if (!empty($ds['sections'])) {
        $out['sections'] = $ds['sections'];
    }
    if (isset($ds['notes'])) {
        $out['notes'] = $ds['notes'];
    }
    return $out;
}

/* ---------------- المبيعات ---------------- */

/** أسطر فواتير البيع السارية حسب التصفية */
function acct_sales_lines(PDO $pdo, array $f): array
{
    [$where, $params] = acct_date_where('d.doc_date', $f['from'], $f['to']);
    array_unshift($where, "d.kind = 'sale'", "d.status = 'active'");
    foreach (['warehouse' => 'd.warehouse_id', 'type' => 'i.wood_type_id', 'customer' => 'd.party_id'] as $k => $col) {
        if (($f[$k] ?? null) !== null) {
            $where[] = "{$col} = ?";
            $params[] = $f[$k];
        }
    }
    $branch = '';
    if (($f['dimension'] ?? null) === 'branch') {
        $branch = ', d.branch_id' . (acct_has_column($pdo, 'documents', 'branch_name') ? ', d.branch_name' : ', NULL AS branch_name');
    }
    $stmt = $pdo->prepare(
        "SELECT d.id AS doc_id, d.doc_date, d.warehouse_id, w.name AS warehouse_name, d.party_id, p.name AS party_name,
                i.wood_type_id, t.name AS type_name, l.quantity, l.total_volume_m3, l.amount, l.cost_amount{$branch}
         FROM documents d
         JOIN document_lines l ON l.document_id = d.id
         JOIN items i ON i.id = l.item_id
         JOIN wood_types t ON t.id = i.wood_type_id
         JOIN warehouses w ON w.id = d.warehouse_id
         LEFT JOIN parties p ON p.id = d.party_id
         WHERE " . implode(' AND ', $where) . '
         ORDER BY d.doc_date, d.id, l.line_no'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** يجمع أسطر البيع في مجموعات حسب مفتاح، ويعيد [المجموعات, الإجمالي] */
function acct_aggregate_sales(array $lines, callable $keyOf): array
{
    $new = fn () => ['docs' => [], 'qty' => 0, 'um3' => '0', 'amount' => 0, 'cost' => 0];
    $groups = [];
    $all = $new();
    foreach ($lines as $l) {
        [$k, $label] = $keyOf($l);
        $groups[$k] ??= $new() + ['label' => $label];
        foreach ([&$groups[$k], &$all] as &$g) {
            $g['docs'][(int) $l['doc_id']] = true;
            $g['qty'] += (int) $l['quantity'];
            $g['um3'] = Num::add($g['um3'], m3_to_um3((string) $l['total_volume_m3']));
            $g['amount'] += money_to_piasters($l['amount'] ?? '0');
            $g['cost'] += money_to_piasters($l['cost_amount'] ?? '0');
        }
        unset($g);
    }
    return [$groups, $all];
}

function acct_sales_figures(array $g): array
{
    $profit = $g['amount'] - $g['cost'];
    return [
        'invoices' => count($g['docs']),
        'qty' => $g['qty'],
        'volume' => acct_um3_out($g['um3']),
        'amount' => piasters_to_money($g['amount']),
        'cost' => piasters_to_money($g['cost']),
        'profit' => piasters_to_money($profit),
        'margin' => acct_margin($profit, $g['amount']),
    ];
}

function acct_rep_sales_period(PDO $pdo, array $f): array
{
    $len = $f['group'] === 'month' ? 7 : 10;
    [$groups, $all] = acct_aggregate_sales(acct_sales_lines($pdo, $f), function (array $l) use ($len) {
        $p = substr((string) $l['doc_date'], 0, $len);
        return [$p, $p];
    });
    ksort($groups, SORT_STRING);
    $rows = [];
    foreach ($groups as $g) {
        $fig = acct_sales_figures($g);
        unset($fig['qty']);
        $rows[] = ['period' => $g['label']] + $fig;
    }
    $tot = acct_sales_figures($all);
    unset($tot['qty']);
    return ['rows' => $rows, 'totals' => ['period' => 'الإجمالي'] + $tot];
}

function acct_rep_sales_by(PDO $pdo, array $f): array
{
    $dim = $f['dimension'];
    [$groups, $all] = acct_aggregate_sales(acct_sales_lines($pdo, $f), function (array $l) use ($dim) {
        return match ($dim) {
            'warehouse' => ['w' . $l['warehouse_id'], (string) $l['warehouse_name']],
            'customer' => $l['party_id'] === null ? ['c0', ACCT_CASH_CUSTOMER_LABEL] : ['c' . $l['party_id'], (string) $l['party_name']],
            'branch' => $l['branch_id'] === null ? ['b0', 'بدون فرع'] : ['b' . $l['branch_id'], (string) ($l['branch_name'] ?? ('فرع رقم ' . $l['branch_id']))],
            default => ['t' . $l['wood_type_id'], (string) $l['type_name']],
        };
    });
    $rows = [];
    foreach ($groups as $g) {
        $rows[] = ['label' => $g['label']] + acct_sales_figures($g) + ['_amount' => $g['amount']];
    }
    usort($rows, fn ($a, $b) => [$b['_amount'], $a['label']] <=> [$a['_amount'], $b['label']]);
    foreach ($rows as &$r) {
        unset($r['_amount']);
    }
    unset($r);
    $columns = ACCT_REPORTS['sales_by']['columns'];
    $columns[0]['label'] = ACCT_DIMENSION_LABELS[$dim];
    return ['columns' => $columns, 'rows' => $rows, 'totals' => ['label' => 'الإجمالي'] + acct_sales_figures($all)];
}

/* ---------------- الأرباح والمصروفات ---------------- */

/** سندات المصروفات السارية حسب التصفية */
function acct_expense_rows(PDO $pdo, array $f): array
{
    [$where, $params] = acct_date_where('v.voucher_date', $f['from'], $f['to']);
    array_unshift($where, "v.kind = 'expense'", "v.status = 'active'");
    foreach (['category' => 'v.category_id', 'cash_box' => 'v.cash_box_id'] as $k => $col) {
        if (($f[$k] ?? null) !== null) {
            $where[] = "{$col} = ?";
            $params[] = $f[$k];
        }
    }
    $stmt = $pdo->prepare(
        'SELECT v.id, v.voucher_date, v.category_id, COALESCE(c.name, v.category_name) AS category_name, v.amount
         FROM vouchers v LEFT JOIN expense_categories c ON c.id = v.category_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY v.voucher_date, v.id'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function acct_rep_profit(PDO $pdo, array $f): array
{
    [, $sales] = acct_aggregate_sales(acct_sales_lines($pdo, $f), fn () => ['all', '']);
    $revenue = $sales['amount'];
    $cogs = $sales['cost'];
    $gross = $revenue - $cogs;

    $byCat = [];
    foreach (acct_expense_rows($pdo, $f) as $e) {
        $k = (int) $e['category_id'];
        $byCat[$k] ??= ['name' => (string) $e['category_name'], 'amount' => 0];
        $byCat[$k]['amount'] += money_to_piasters($e['amount']);
    }
    uasort($byCat, fn ($a, $b) => [$b['amount'], $a['name']] <=> [$a['amount'], $b['name']]);
    $expenses = array_sum(array_column($byCat, 'amount'));

    [$where, $params] = acct_date_where('created_at', $f['from'], $f['to']);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM cost_adjustments' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
    $stmt->execute($params);
    $adjust = money_to_piasters((string) $stmt->fetchColumn());

    $rows = [
        ['label' => 'إيرادات المبيعات', 'amount' => piasters_to_money($revenue)],
        ['label' => 'تكلفة البضاعة المباعة', 'amount' => piasters_to_money(-$cogs)],
        ['label' => 'مجمل الربح', 'amount' => piasters_to_money($gross)],
    ];
    foreach ($byCat as $c) {
        $rows[] = ['label' => 'مصروف: ' . $c['name'], 'amount' => piasters_to_money(-$c['amount'])];
    }
    $rows[] = ['label' => 'إجمالي المصروفات', 'amount' => piasters_to_money(-$expenses)];
    $rows[] = ['label' => 'فروق تكلفة المخزون', 'amount' => piasters_to_money(-$adjust)];
    return ['rows' => $rows, 'totals' => ['label' => 'صافي الربح', 'amount' => piasters_to_money($gross - $expenses - $adjust)]];
}

function acct_rep_expenses(PDO $pdo, array $f): array
{
    $group = $f['group'];
    $len = $group === 'month' ? 7 : 10;
    $groups = [];
    $count = 0;
    $total = 0;
    foreach (acct_expense_rows($pdo, $f) as $e) {
        $period = $group === 'category' ? '' : substr((string) $e['voucher_date'], 0, $len);
        $k = $period . '|' . (int) $e['category_id'];
        $groups[$k] ??= ['period' => $period, 'category' => (string) $e['category_name'], 'count' => 0, 'amount' => 0];
        $groups[$k]['count']++;
        $groups[$k]['amount'] += money_to_piasters($e['amount']);
        $count++;
        $total += money_to_piasters($e['amount']);
    }
    $rows = array_values($groups);
    usort($rows, fn ($a, $b) => [$a['period'], $b['amount'], $a['category']] <=> [$b['period'], $a['amount'], $b['category']]);
    $columns = ACCT_REPORTS['expenses']['columns'];
    if ($group === 'category') {
        array_shift($columns);
    }
    $out = [];
    foreach ($rows as $r) {
        $row = ['period' => $r['period'], 'category' => $r['category'], 'count' => $r['count'], 'amount' => piasters_to_money($r['amount'])];
        if ($group === 'category') {
            unset($row['period']);
        }
        $out[] = $row;
    }
    $totals = ['period' => 'الإجمالي', 'category' => $group === 'category' ? 'الإجمالي' : '', 'count' => $count, 'amount' => piasters_to_money($total)];
    if ($group === 'category') {
        unset($totals['period']);
    }
    return ['columns' => $columns, 'rows' => $out, 'totals' => $totals];
}

/* ---------------- تقييم المخزون ---------------- */

/**
 * يوزع قيمة صنف على المخازن: القيمة × كمية المخزن ÷ كل الكمية، مقربة النصف لأعلى،
 * والفرق الناتج عن التقريب يُضاف لأكبر صف كمية (الأول عند التساوي) فيصبح المجموع = القيمة بالضبط.
 * @param array<int,int> $qtyByWarehouse [warehouse_id => qty] بالترتيب المطلوب
 * @return array<int,int> [warehouse_id => قروش]
 */
function acct_split_value(int $value, array $qtyByWarehouse): array
{
    $total = array_sum($qtyByWarehouse);
    $out = [];
    if ($total <= 0) {
        return array_map(fn () => 0, $qtyByWarehouse);
    }
    $largest = null;
    foreach ($qtyByWarehouse as $wid => $q) {
        $share = muldiv_half_up(abs($value), $q, $total);
        $out[$wid] = $value < 0 ? -$share : $share;
        if ($largest === null || $q > $qtyByWarehouse[$largest]) {
            $largest = $wid;
        }
    }
    $out[$largest] += $value - array_sum($out);
    return $out;
}

function acct_rep_valuation(PDO $pdo, array $f): array
{
    $where = '';
    $params = [];
    if ($f['type'] !== null) {
        $where = ' WHERE i.wood_type_id = ?';
        $params[] = $f['type'];
    }
    $stmt = $pdo->prepare(
        'SELECT i.id, i.wood_type_id, t.name AS type_name, i.width_um, i.thickness_um, i.length_um,
                i.width_unit, i.thickness_unit, i.length_unit, i.piece_volume_m3, i.inventory_value
         FROM items i JOIN wood_types t ON t.id = i.wood_type_id' . $where . '
         ORDER BY t.name, t.id, i.thickness_um, i.width_um, i.length_um, i.id'
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    $stock = [];
    foreach ($pdo->query('SELECT s.item_id, s.warehouse_id, s.qty_on_hand FROM stock s ORDER BY s.warehouse_id') as $s) {
        if ((int) $s['qty_on_hand'] > 0) {
            $stock[(int) $s['item_id']][(int) $s['warehouse_id']] = (int) $s['qty_on_hand'];
        }
    }
    $whNames = array_column(catalog_all($pdo, 'warehouse'), 'name', 'id');
    $onlyWh = $f['warehouse'];

    $rows = [];
    $detail = [];
    $byWh = [];
    $tot = ['qty' => 0, 'um3' => '0', 'value' => 0, 'unvalued' => 0];
    foreach ($items as $it) {
        $id = (int) $it['id'];
        $qtys = $stock[$id] ?? [];
        $q = array_sum($qtys);
        $v = money_to_piasters($it['inventory_value']);
        if ($q === 0 && $v === 0) {
            continue;
        }
        $pieceUm3 = m3_to_um3((string) $it['piece_volume_m3']);
        $split = acct_split_value($v, $qtys);
        $avg = ($q > 0 && $v > 0) ? piasters_to_money((int) acct_big_div_half_up(Num::mul((string) $v, '1' . str_repeat('0', VOLUME_SCALE)), Num::mul($pieceUm3, (string) $q))) : null;
        $status = $q > 0 && $v === 0 ? 'غير مقيّم' : ($q === 0 ? 'قيمة بدون رصيد' : ($v < 0 ? 'قيمة سالبة' : 'مقيّم'));
        foreach ($qtys as $wid => $wq) {
            $byWh[$wid] ??= ['qty' => 0, 'um3' => '0', 'value' => 0];
            $byWh[$wid]['qty'] += $wq;
            $byWh[$wid]['um3'] = Num::add($byWh[$wid]['um3'], Num::mul($pieceUm3, (string) $wq));
            $byWh[$wid]['value'] += $split[$wid];
            if ($onlyWh === null || $onlyWh === $wid) {
                $detail[] = [
                    'warehouse' => (string) ($whNames[$wid] ?? ''), 'type_name' => $it['type_name'], 'size' => fmt_size($it),
                    'qty' => $wq, 'volume' => acct_um3_out(Num::mul($pieceUm3, (string) $wq)), 'value' => piasters_to_money($split[$wid]),
                ];
            }
        }
        if ($onlyWh !== null) {
            if (!isset($qtys[$onlyWh])) {
                continue;
            }
            $q = $qtys[$onlyWh];
            $v = $split[$onlyWh];
        }
        $um3 = Num::mul($pieceUm3, (string) $q);
        $rows[] = [
            'type_name' => $it['type_name'], 'size' => fmt_size($it), 'qty' => $q, 'volume' => acct_um3_out($um3),
            'avg_cost' => $avg, 'value' => piasters_to_money($v), 'status' => $status,
        ];
        $tot['qty'] += $q;
        $tot['um3'] = Num::add($tot['um3'], $um3);
        $tot['value'] += $v;
        $tot['unvalued'] += $status === 'غير مقيّم' ? 1 : 0;
    }

    $whRows = [];
    $whTot = ['qty' => 0, 'um3' => '0', 'value' => 0];
    foreach ($whNames as $wid => $name) {
        if (!isset($byWh[$wid]) || ($onlyWh !== null && $onlyWh !== (int) $wid)) {
            continue;
        }
        $w = $byWh[$wid];
        $whRows[] = ['warehouse' => (string) $name, 'qty' => $w['qty'], 'volume' => acct_um3_out($w['um3']), 'value' => piasters_to_money($w['value'])];
        $whTot['qty'] += $w['qty'];
        $whTot['um3'] = Num::add($whTot['um3'], $w['um3']);
        $whTot['value'] += $w['value'];
    }
    $whCol = ['key' => 'warehouse', 'label' => 'المخزن', 'type' => 'text', 'align' => 'start'];
    $qtyCol = ['key' => 'qty', 'label' => 'القطع', 'type' => 'int', 'align' => 'end'];
    $volCol = ['key' => 'volume', 'label' => 'الحجم (م³)', 'type' => 'volume', 'align' => 'end'];
    $valCol = ['key' => 'value', 'label' => 'القيمة', 'type' => 'money', 'align' => 'end'];
    return [
        'rows' => $rows,
        'totals' => [
            'type_name' => 'الإجمالي', 'size' => '', 'qty' => $tot['qty'], 'volume' => acct_um3_out($tot['um3']), 'avg_cost' => null,
            'value' => piasters_to_money($tot['value']),
            'status' => $tot['unvalued'] > 0 ? 'أصناف غير مقيّمة: ' . fmt_int($tot['unvalued']) : '',
        ],
        'notes' => ['unvalued' => $tot['unvalued']],
        'sections' => [
            [
                'title' => 'القيمة حسب المخزن',
                'columns' => [$whCol, $qtyCol, $volCol, $valCol],
                'rows' => $whRows,
                'totals' => ['warehouse' => 'الإجمالي', 'qty' => $whTot['qty'], 'volume' => acct_um3_out($whTot['um3']), 'value' => piasters_to_money($whTot['value'])],
            ],
            [
                'title' => 'توزيع قيمة الأصناف على المخازن',
                'columns' => [$whCol, ['key' => 'type_name', 'label' => 'النوع', 'type' => 'text', 'align' => 'start'],
                    ['key' => 'size', 'label' => 'المقاس', 'type' => 'text', 'align' => 'start'], $qtyCol, $volCol, $valCol],
                'rows' => $detail,
                'totals' => [],
            ],
        ],
    ];
}

/* ---------------- الأرصدة ---------------- */

function acct_rep_balances(PDO $pdo, array $f, string $kind): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.phone, p.balance, p.credit_limit, p.is_active,
                (SELECT MAX(l.entry_date) FROM party_ledger l WHERE l.party_id = p.id) AS last_entry
         FROM parties p WHERE p.kind = ?' . ($f['show'] === 'all' ? '' : ' AND p.balance <> 0') . '
         ORDER BY p.balance DESC, p.name, p.id'
    );
    $stmt->execute([$kind]);
    $rows = [];
    $total = 0;
    $owed = 0;
    foreach ($stmt->fetchAll() as $p) {
        $b = money_to_piasters($p['balance']);
        $total += $b;
        $owed += max(0, $b);
        $text = fmt_party_balance($b, $kind);
        $rows[] = [
            'name' => $p['name'], 'phone' => $p['phone'], 'balance' => piasters_to_money($b),
            'direction' => $b === 0 ? '' : (str_ends_with($text, 'عليه') ? 'عليه' : 'له'),
            'credit_limit' => $p['credit_limit'] === null ? null : piasters_to_money(money_to_piasters($p['credit_limit'])),
            'last_entry' => $p['last_entry'], 'status' => (int) $p['is_active'] ? 'نشط' : 'موقوف',
        ];
    }
    $text = fmt_party_balance($total, $kind);
    return [
        'rows' => $rows,
        'totals' => ['name' => 'الإجمالي', 'phone' => '', 'balance' => piasters_to_money($total),
            'direction' => $total === 0 ? '' : (str_ends_with($text, 'عليه') ? 'عليه' : 'له'),
            'credit_limit' => null, 'last_entry' => null, 'status' => ''],
        'notes' => ['positive_total' => piasters_to_money($owed)],
    ];
}

/* ---------------- الخزائن ---------------- */

function acct_rep_cash(PDO $pdo, array $f): array
{
    $boxWhere = '';
    $boxParams = [];
    if ($f['cash_box'] !== null) {
        $boxWhere = ' WHERE b.id = ?';
        $boxParams[] = $f['cash_box'];
    }
    $stmt = $pdo->prepare('SELECT b.id, b.name, b.opening_balance, b.balance, b.is_active FROM cash_boxes b' . $boxWhere . ' ORDER BY b.name, b.id');
    $stmt->execute($boxParams);
    $boxes = $stmt->fetchAll();

    // قبل الفترة: الافتتاحي + مجموع القيود قبل تاريخ البداية
    $before = [];
    if ($f['from'] !== null) {
        $stmt = $pdo->prepare('SELECT cash_box_id, SUM(amount) AS s FROM cash_ledger WHERE entry_date < ? GROUP BY cash_box_id');
        $stmt->execute([$f['from'] . ' 00:00:00']);
        foreach ($stmt->fetchAll() as $r) {
            $before[(int) $r['cash_box_id']] = money_to_piasters((string) $r['s']);
        }
    }
    [$where, $params] = acct_date_where('entry_date', $f['from'], $f['to']);
    if ($f['cash_box'] !== null) {
        $where[] = 'cash_box_id = ?';
        $params[] = $f['cash_box'];
    }
    $stmt = $pdo->prepare('SELECT cash_box_id, entry_date, entry_type, amount FROM cash_ledger'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY entry_date, id');
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    $mov = [];
    $byType = [];
    $byDay = [];
    foreach ($entries as $e) {
        $a = money_to_piasters($e['amount']);
        $bid = (int) $e['cash_box_id'];
        $mov[$bid] ??= ['in' => 0, 'out' => 0];
        $byType[$e['entry_type']] ??= ['in' => 0, 'out' => 0];
        $day = substr((string) $e['entry_date'], 0, 10);
        $byDay[$day] ??= ['in' => 0, 'out' => 0];
        $side = $a > 0 ? 'in' : 'out';
        $mov[$bid][$side] += abs($a);
        $byType[$e['entry_type']][$side] += abs($a);
        $byDay[$day][$side] += abs($a);
    }

    $rows = [];
    $t = ['opening' => 0, 'in' => 0, 'out' => 0, 'closing' => 0, 'balance' => 0];
    foreach ($boxes as $b) {
        $id = (int) $b['id'];
        $opening = money_to_piasters($b['opening_balance']) + ($before[$id] ?? 0);
        $in = $mov[$id]['in'] ?? 0;
        $out = $mov[$id]['out'] ?? 0;
        $r = ['opening' => $opening, 'in' => $in, 'out' => $out, 'closing' => $opening + $in - $out, 'balance' => money_to_piasters($b['balance'])];
        foreach ($r as $k => $v) {
            $t[$k] += $v;
        }
        $rows[] = ['name' => $b['name'] . ((int) $b['is_active'] ? '' : ' (موقوفة)')] + array_map('piasters_to_money', $r);
    }

    $typeRows = [];
    $tt = ['in' => 0, 'out' => 0];
    foreach (ACCT_CASH_ENTRY_LABELS as $type => $label) {
        if (!isset($byType[$type])) {
            continue;
        }
        $x = $byType[$type];
        $typeRows[] = ['type' => $label, 'in' => piasters_to_money($x['in']), 'out' => piasters_to_money($x['out']), 'net' => piasters_to_money($x['in'] - $x['out'])];
        $tt['in'] += $x['in'];
        $tt['out'] += $x['out'];
    }

    ksort($byDay, SORT_STRING);
    $dayRows = [];
    $running = $t['opening'];
    foreach ($byDay as $day => $x) {
        $open = $running;
        $running += $x['in'] - $x['out'];
        $dayRows[] = ['day' => $day, 'opening' => piasters_to_money($open), 'in' => piasters_to_money($x['in']), 'out' => piasters_to_money($x['out']), 'closing' => piasters_to_money($running)];
    }

    $m = fn (string $k, string $l) => ['key' => $k, 'label' => $l, 'type' => 'money', 'align' => 'end'];
    return [
        'rows' => $rows,
        'totals' => ['name' => 'الإجمالي'] + array_map('piasters_to_money', $t),
        'sections' => [
            [
                'title' => 'الحركة حسب النوع',
                'columns' => [['key' => 'type', 'label' => 'نوع الحركة', 'type' => 'text', 'align' => 'start'], $m('in', 'داخل'), $m('out', 'خارج'), $m('net', 'الصافي')],
                'rows' => $typeRows,
                'totals' => ['type' => 'الإجمالي', 'in' => piasters_to_money($tt['in']), 'out' => piasters_to_money($tt['out']), 'net' => piasters_to_money($tt['in'] - $tt['out'])],
            ],
            [
                'title' => 'الرصيد اليومي' . ($f['cash_box'] === null ? ' (كل الخزائن)' : ''),
                'columns' => [['key' => 'day', 'label' => 'اليوم', 'type' => 'text', 'align' => 'start'], $m('opening', 'رصيد أول اليوم'), $m('in', 'داخل'), $m('out', 'خارج'), $m('closing', 'رصيد آخر اليوم')],
                'rows' => $dayRows,
                'totals' => [],
            ],
        ],
    ];
}

/* ---------------- لوحة التحكم ---------------- */

/** أرقام لوحة التحكم ليوم $today (Y-m-d) والشهر الذي يحتويه */
function acct_dashboard(PDO $pdo, ?string $today = null): array
{
    $today = acct_valid_date($today ?? '') ?? date('Y-m-d');
    $monthStart = substr($today, 0, 8) . '01';
    $period = function (string $from) use ($pdo, $today): array {
        $f = ['from' => $from, 'to' => $today, 'warehouse' => null, 'type' => null, 'customer' => null];
        [, $s] = acct_aggregate_sales(acct_sales_lines($pdo, $f), fn () => ['all', '']);
        [$where, $params] = acct_date_where('voucher_date', $from, $today);
        $stmt = $pdo->prepare("SELECT kind, SUM(amount) AS s FROM vouchers WHERE status = 'active' AND kind IN ('collect','expense') AND "
            . implode(' AND ', $where) . ' GROUP BY kind');
        $stmt->execute($params);
        $v = ['collect' => 0, 'expense' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $v[$r['kind']] = money_to_piasters((string) $r['s']);
        }
        return [
            'sales_count' => count($s['docs']),
            'sales_amount' => piasters_to_money($s['amount']),
            'gross_profit' => piasters_to_money($s['amount'] - $s['cost']),
            'collections' => piasters_to_money($v['collect']),
            'expenses' => piasters_to_money($v['expense']),
        ];
    };
    $sum = function (string $sql) use ($pdo): string {
        return piasters_to_money(money_to_piasters((string) $pdo->query($sql)->fetchColumn()));
    };
    $top = $pdo->query("SELECT id, name, balance FROM parties WHERE kind = 'customer' AND balance > 0 ORDER BY balance DESC, name, id LIMIT 5")->fetchAll();
    $unvalued = (int) $pdo->query('SELECT COUNT(*) FROM items i WHERE i.inventory_value = 0
        AND (SELECT COALESCE(SUM(s.qty_on_hand), 0) FROM stock s WHERE s.item_id = i.id) > 0')->fetchColumn();
    return [
        'today' => $today,
        'month_start' => $monthStart,
        'day' => $period($today),
        'month' => $period($monthStart),
        'cash' => $sum('SELECT COALESCE(SUM(balance), 0) FROM cash_boxes'),
        'receivables' => $sum("SELECT COALESCE(SUM(balance), 0) FROM parties WHERE kind = 'customer' AND balance > 0"),
        'payables' => $sum("SELECT COALESCE(SUM(balance), 0) FROM parties WHERE kind = 'supplier' AND balance > 0"),
        'top_customers' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'balance' => piasters_to_money(money_to_piasters($r['balance']))], $top),
        'unvalued_items' => $unvalued,
    ];
}

/** التقارير التي يملك المستخدم صلاحيتها */
function acct_reports_visible(): array
{
    return array_filter(ACCT_REPORTS, fn ($r) => acct_can($r['permission']));
}

/* ---------------- العرض ---------------- */

/** قيمة خلية كنص للعرض حسب نوع العمود (يُهرب عند الإخراج) */
function acct_fmt_cell(mixed $value, string $type): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $s = (string) $value;
    switch ($type) {
        case 'money':
            return str_starts_with($s, '-') ? '-' . fmt_money(substr($s, 1)) : fmt_money($s);
        case 'volume':
            return fmt_volume($s);
        case 'int':
            return fmt_int($s);
        case 'date':
            return strlen($s) > 10 ? fmt_datetime($s) : digits($s);
        default:
            return preg_match('/^[\d\-]+\z/', $s) ? digits($s) : $s;
    }
}

/** يعرض مجموعة بيانات (أو قسمًا منها) كجدول: محاذاة حسب النوع، وتنسيق القيم، وصف الإجمالي */
function render_dataset_table(array $dataset): void
{
    $cols = $dataset['columns'];
    $cls = fn (array $c) => ($c['align'] ?? 'start') === 'end' ? ' class="num align-end"' : '';
    ?>
<div class="table-wrap">
  <table class="report-table">
    <caption class="visually-hidden"><?= h((string) ($dataset['title'] ?? '')) ?></caption>
    <thead>
      <tr>
        <?php foreach ($cols as $c): ?><th scope="col"<?= $cls($c) ?>><?= h($c['label']) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (!$dataset['rows']): ?>
      <tr><td colspan="<?= count($cols) ?>" data-label="<?= h($cols[0]['label']) ?>" class="muted">لا توجد بيانات مطابقة.</td></tr>
      <?php endif; ?>
      <?php foreach ($dataset['rows'] as $r): ?>
      <tr>
        <?php foreach ($cols as $c): ?><td data-label="<?= h($c['label']) ?>"<?= $cls($c) ?>><?= h(acct_fmt_cell($r[$c['key']] ?? null, $c['type'])) ?></td><?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if (!empty($dataset['totals']) && $dataset['rows']): ?>
    <tfoot>
      <tr class="row-total">
        <?php foreach ($cols as $i => $c): ?>
          <?php if ($i === 0): ?><th scope="row"<?= $cls($c) ?>><?= h(acct_fmt_cell($dataset['totals'][$c['key']] ?? 'الإجمالي', $c['type'])) ?></th>
          <?php else: ?><td data-label="<?= h($c['label']) ?>"<?= $cls($c) ?>><?= h(acct_fmt_cell($dataset['totals'][$c['key']] ?? null, $c['type'])) ?></td><?php endif; ?>
        <?php endforeach; ?>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>
<?php
}
