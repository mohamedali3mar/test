<?php
defined('APP_ROOT') || exit;

/*
 * أدوات الجداول وبيانات صفحتي المخزون والسجل.
 *
 *  - الترتيب على الخادم بمعامل الرابط sort=<العمود>:<asc|desc>، فيعمل بدون JavaScript، ويشمل كل صفحات
 *    السجل (وليس الخمسين صفًا المعروضة فقط)، ويبقى مع التحديث التلقائي، ويُطبق نفسه على ملف التصدير.
 *  - شريط الأدوات فوق كل جدول (render_table_tools): أزرار التصدير تعمل بدون JavaScript،
 *    وtables.js يضيف البحث السريع واختيار الأعمدة والطباعة، ويرسل الأعمدة الظاهرة مع التصدير.
 *  - inventory_request و documents_request تقرآن التصفية مرة واحدة للصفحة ولملف التصدير،
 *    فيحتوي الملف بالضبط ما تعرضه الصفحة (بنفس الصلاحيات ونطاق الفرع).
 */

/* ===================== الترتيب ===================== */

/**
 * الترتيب المطلوب من الرابط إن كان العمود قابلًا للترتيب: ['key' => .., 'dir' => asc|desc] أو null.
 * @param array<string,mixed> $sortable مفاتيح الأعمدة القابلة للترتيب
 */
function table_sort(array $get, array $sortable): ?array
{
    $raw = input($get, 'sort');
    if (!preg_match('/^([a-z_]{1,40}):(asc|desc)\z/', $raw, $m) || !array_key_exists($m[1], $sortable)) {
        return null;
    }
    return ['key' => $m[1], 'dir' => $m[2]];
}

function table_sort_param(?array $sort): string
{
    return $sort === null ? '' : $sort['key'] . ':' . $sort['dir'];
}

/**
 * خانة عنوان عمود: رابط ترتيب للعمود القابل للترتيب (تصاعدي، ثم تنازلي، ثم الترتيب الافتراضي)،
 * و aria-sort للعمود المرتب. data-col يربطها بأداة اختيار الأعمدة.
 * @param array<string,string> $query معاملات الصفحة الحالية (التصفية) بدون sort وpage
 */
function sort_th(string $key, string $label, ?array $sort, array $query, bool $sortable, string $class = ''): string
{
    $attrs = ' scope="col" data-col="' . h($key) . '"' . ($class !== '' ? ' class="' . h($class) . '"' : '');
    if (!$sortable) {
        return '<th' . $attrs . '>' . h($label) . '</th>';
    }
    $current = $sort !== null && $sort['key'] === $key ? $sort['dir'] : '';
    $next = match ($current) {
        '' => $key . ':asc',
        'asc' => $key . ':desc',
        default => '',
    };
    $href = 'index.php?' . http_build_query($query + ($next !== '' ? ['sort' => $next] : []));
    $state = match ($current) {
        'asc' => ' <span class="sort-state">تصاعدي</span>',
        'desc' => ' <span class="sort-state">تنازلي</span>',
        default => '',
    };
    $aria = $current === '' ? '' : ' aria-sort="' . ($current === 'asc' ? 'ascending' : 'descending') . '"';
    return '<th' . $attrs . $aria . '><a class="sort-link" href="' . h($href) . '">' . h($label) . '</a>' . $state . '</th>';
}

/** مقارنة قيمتين خامتين حسب نوع العمود: الأرقام بدقة كاملة كنصوص عشرية، والتواريخ نصيًا، والنصوص بالترتيب العربي */
function sort_compare(mixed $a, mixed $b, string $type): int
{
    $emptyA = $a === null || $a === '';
    $emptyB = $b === null || $b === '';
    if ($emptyA || $emptyB) {
        return $emptyA <=> $emptyB; // الفارغ في الآخر دائمًا عند الترتيب التصاعدي
    }
    if (in_array($type, ['int', 'volume', 'money'], true)) {
        $x = decimal_parts((string) $a);
        $y = decimal_parts((string) $b);
        if ($x !== null && $y !== null) {
            return decimal_compare($x, $y);
        }
    }
    if ($type === 'date') {
        return strcmp((string) $a, (string) $b);
    }
    static $collator = false;
    if ($collator === false) {
        $collator = class_exists('Collator') ? new Collator('ar') : null;
        $collator?->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
    }
    return $collator !== null ? (int) $collator->compare((string) $a, (string) $b) : strnatcasecmp((string) $a, (string) $b);
}

/** [الإشارة، الجزء الصحيح بلا أصفار بادئة، الكسر بلا أصفار زائدة] أو null إن لم يكن رقمًا عشريًا */
function decimal_parts(string $s): ?array
{
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?\z/', trim($s), $m)) {
        return null;
    }
    $int = ltrim($m[2], '0');
    $frac = rtrim($m[3] ?? '', '0');
    $neg = $m[1] === '-' && ($int !== '' || $frac !== '');
    return [$neg ? -1 : 1, $int, $frac];
}

function decimal_compare(array $x, array $y): int
{
    if ($x[0] !== $y[0]) {
        return $x[0] <=> $y[0];
    }
    $c = (strlen($x[1]) <=> strlen($y[1])) ?: strcmp($x[1], $y[1]);
    if ($c === 0) {
        $len = max(strlen($x[2]), strlen($y[2]));
        $c = strcmp(str_pad($x[2], $len, '0'), str_pad($y[2], $len, '0'));
    }
    return $x[0] * ($c <=> 0);
}

/** يرتب صفوف مجموعة بيانات (مصفوفات) بعمود، ترتيبًا ثابتًا يحافظ على الترتيب الأصلي عند التساوي */
function rows_sorted(array $rows, string $key, string $type, string $dir): array
{
    $indexed = [];
    foreach (array_values($rows) as $i => $r) {
        $indexed[] = [$i, $r];
    }
    usort($indexed, function ($p, $q) use ($key, $type, $dir) {
        $a = $p[1][$key] ?? null;
        $b = $q[1][$key] ?? null;
        $c = sort_compare($a, $b, $type);
        // الفارغ يبقى في الآخر في الاتجاهين
        if ($c !== 0 && $dir === 'desc' && $a !== null && $a !== '' && $b !== null && $b !== '') {
            $c = -$c;
        }
        return $c ?: $p[0] <=> $q[0];
    });
    return array_column($indexed, 1);
}

/* ===================== شريط أدوات الجدول ===================== */

/**
 * شريط الأدوات فوق الجدول. $o:
 *   key       مفتاح ثابت للجدول (لتذكر الأعمدة في المتصفح)
 *   table     معرّف عنصر الجدول (id)
 *   columns   [المفتاح => العنوان] أعمدة الجدول الظاهرة بالترتيب
 *   required  مفاتيح لا يمكن إخفاؤها (العمود الأول عادة)
 *   export    معاملات رابط التصدير (t والتصفية والترتيب)، أو null بلا تصدير
 *   sortable  [المفتاح => العنوان] للترتيب على الشاشات الصغيرة (رؤوس الأعمدة مخفية فيها)، و sort الحالي
 *   query     معاملات الصفحة الحالية لنموذج الترتيب
 *   print     زر طباعة (يضيفه tables.js)
 */
function render_table_tools(array $o): void
{
    $GLOBALS['PAGE_USES_TABLES'] = true;
    $key = (string) $o['key'];
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $key);
    $cols = [];
    foreach ($o['columns'] as $k => $label) {
        $cols[] = ['key' => (string) $k, 'label' => (string) $label, 'required' => in_array($k, $o['required'] ?? [], true)];
    }
    ?>
<div class="table-tools no-print" data-table-tools="<?= h($key) ?>" data-table="<?= h((string) $o['table']) ?>"
  data-columns="<?= h(json_encode($cols, JSON_UNESCAPED_UNICODE)) ?>"<?= !empty($o['print']) ? ' data-print="1"' : '' ?>>
  <div class="table-tools-js" data-tools-slot></div>
  <?php if (!empty($o['sortable'])): $sortValue = table_sort_param($o['sort'] ?? null); ?>
  <form method="get" action="index.php" class="table-sort" aria-label="ترتيب الجدول">
    <?php foreach ($o['query'] as $qk => $qv): ?><input type="hidden" name="<?= h((string) $qk) ?>" value="<?= h((string) $qv) ?>"><?php endforeach; ?>
    <div class="field">
      <label for="sort-<?= h($slug) ?>">ترتيب حسب</label>
      <select id="sort-<?= h($slug) ?>" name="sort">
        <option value="">الترتيب الافتراضي</option>
        <?php foreach ($o['sortable'] as $sk => $label): ?>
          <?php foreach (['asc' => 'تصاعدي', 'desc' => 'تنازلي'] as $dir => $dirLabel): $v = $sk . ':' . $dir; ?>
          <option value="<?= h($v) ?>"<?= $sortValue === $v ? ' selected' : '' ?>><?= h($label . '، ' . $dirLabel) ?></option>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">ترتيب</button>
  </form>
  <?php endif; ?>
  <?php if (!empty($o['export'])): ?>
  <form method="get" action="index.php" class="table-export" data-export-form>
    <?php foreach (['r' => 'export'] + $o['export'] as $ek => $ev): ?>
      <?php if ((string) $ev !== ''): ?><input type="hidden" name="<?= h((string) $ek) ?>" value="<?= h((string) $ev) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <input type="hidden" name="cols" value="" data-export-cols>
    <span class="table-export-label" id="export-label-<?= h($slug) ?>">تصدير</span>
    <div class="table-export-buttons" role="group" aria-labelledby="export-label-<?= h($slug) ?>">
      <?php foreach (EXPORT_FORMATS as $fmt => $label): ?>
      <button type="submit" class="btn" name="format" value="<?= h($fmt) ?>"><?= h($label) ?></button>
      <?php endforeach; ?>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php
}

/** سطر وصف للطباعة فقط: اسم الشركة والعنوان والتصفية (الشاشة تعرض التصفية في النموذج) */
function render_print_heading(string $title, string $subtitle): void
{
    ?>
<div class="print-only print-heading">
  <p class="report-company"><?= h(app_setting('company_name')) ?></p>
  <p><strong><?= h($title) ?></strong><?= $subtitle !== '' ? '، ' . h($subtitle) : '' ?></p>
</div>
<?php
}

/** شكل الأرقام في نص التصدير: كما في الشاشة لملف PDF، وأرقام إنجليزية لملفات Excel وCSV */
function export_digits(string $s, bool $display): string
{
    return $display ? digits($s) : $s;
}

function export_dim(int $um, string $unit, bool $display): string
{
    return $display ? fmt_dim($um, $unit) : um_in_unit($um, $unit) . ' ' . unit_label($unit);
}

/* ===================== المخزون ===================== */

/** أعمدة جدول المخزون: [المفتاح => [العنوان، النوع]]. التوزيع يظهر فقط عند عرض أكثر من مخزن */
function inventory_columns(bool $showSplit): array
{
    $cols = [
        'type' => ['النوع', 'text'],
        'width' => ['العرض', 'text'],
        'thickness' => ['التخانة', 'text'],
        'length' => ['الطول', 'text'],
        'qty' => ['العدد المتاح', 'int'],
        'piece_volume' => ['حجم القطعة (م³)', 'volume'],
        'volume' => ['الحجم المتاح (م³)', 'volume'],
        'split' => ['التوزيع على المخازن', 'text'],
        'status' => ['الحالة', 'text'],
    ];
    if (!$showSplit) {
        unset($cols['split']);
    }
    return $cols;
}

/** قيمة الترتيب لكل عمود قابل للترتيب في المخزون: [القيمة, النوع] */
const INVENTORY_SORT = [
    'type' => 'النوع', 'width' => 'العرض', 'thickness' => 'التخانة', 'length' => 'الطول',
    'qty' => 'العدد المتاح', 'piece_volume' => 'حجم القطعة', 'volume' => 'الحجم المتاح', 'status' => 'الحالة',
];

function inventory_sort_value(array $r, string $key): array
{
    return match ($key) {
        'width' => [(int) $r['width_um'], 'int'],
        'thickness' => [(int) $r['thickness_um'], 'int'],
        'length' => [(int) $r['length_um'], 'int'],
        'qty' => [(int) $r['qty'], 'int'],
        'piece_volume' => [(string) $r['piece_volume_m3'], 'volume'],
        'volume' => [(string) $r['volume'], 'volume'],
        'status' => [$r['qty'] === 0 ? 0 : 1, 'int'],
        default => [(string) $r['wood_type_name'], 'text'],
    };
}

/**
 * تصفية صفحة المخزون ونتيجتها، للصفحة ولملف التصدير.
 * الفرع: المستخدم المقيد بفرع يرى فرعه فقط، وغيره يختار فرعًا أو كل الفروع.
 */
function inventory_request(PDO $pdo, array $get): array
{
    $q = clean_text(input($get, 'q'));
    if (mb_strlen($q) > 100) {
        $q = mb_substr($q, 0, 100);
    }
    $typeId = (int) input($get, 'type');
    $warehouseId = (int) input($get, 'warehouse');
    $hideEmpty = input($get, 'hide_empty') === '1';

    $types = catalog_all($pdo, 'type');
    $typeNames = array_column($types, 'name', 'id');
    if ($typeId > 0 && !isset($typeNames[$typeId])) {
        $typeId = 0;
    }
    $scope = allowed_branch_id($pdo);
    $branches = catalog_all($pdo, 'branch');
    $branchNames = array_column($branches, 'name', 'id');
    $branchId = $scope ?? (int) input($get, 'branch');
    if (!isset($branchNames[$branchId])) {
        $branchId = $scope ?? 0;
    }
    $branchFilter = $branchId > 0 ? $branchId : null;
    $warehouses = scoped_warehouses($pdo, $branchFilter);
    $whNames = array_column($warehouses, 'name', 'id');
    if ($warehouseId > 0 && !isset($whNames[$warehouseId])) {
        $warehouseId = 0;
    }
    $view = inventory_view($pdo, $q, $typeId, $warehouseId, $hideEmpty, $branchFilter);
    $sort = table_sort($get, INVENTORY_SORT);
    if ($sort !== null) {
        $view['groups'] = inventory_sorted_groups($view['groups'], $sort);
    }
    $filtered = $q !== '' || $typeId > 0 || $warehouseId > 0 || $hideEmpty || ($scope === null && $branchFilter !== null);
    $query = array_filter([
        'r' => 'inventory', 'q' => $q, 'type' => $typeId ?: '', 'branch' => $scope === null && $branchFilter !== null ? $branchFilter : '',
        'warehouse' => $warehouseId ?: '', 'hide_empty' => $hideEmpty ? '1' : '',
    ], fn ($v) => $v !== '');

    $parts = [];
    if ($warehouseId) {
        $parts[] = 'المخزن: ' . $whNames[$warehouseId];
    } elseif ($branchFilter !== null) {
        $parts[] = 'الفرع: ' . $branchNames[$branchFilter];
    } else {
        $parts[] = 'كل المخازن';
    }
    if ($typeId) {
        $parts[] = 'النوع: ' . $typeNames[$typeId];
    }
    if ($q !== '') {
        $parts[] = 'بحث: ' . $q;
    }
    if ($hideEmpty) {
        $parts[] = 'بدون المقاسات النافدة';
    }

    return [
        'q' => $q, 'type' => $typeId, 'warehouse' => $warehouseId, 'hide_empty' => $hideEmpty,
        'types' => $types, 'scope' => $scope, 'branches' => $branches, 'branch_names' => $branchNames,
        'branch' => $branchId, 'branch_filter' => $branchFilter, 'warehouses' => $warehouses, 'wh_names' => $whNames,
        'view' => $view, 'sort' => $sort, 'filtered' => $filtered, 'query' => $query,
        'show_split' => $warehouseId === 0 && count($warehouses) > 1,
        'subtitle' => implode('، ', $parts),
    ];
}

/** ترتيب المخزون: النوع يرتب المجموعات، وباقي الأعمدة ترتب المقاسات داخل كل نوع */
function inventory_sorted_groups(array $groups, array $sort): array
{
    if ($sort['key'] === 'type') {
        uasort($groups, function ($a, $b) use ($sort) {
            $c = sort_compare($a['name'], $b['name'], 'text');
            return $sort['dir'] === 'desc' ? -$c : $c;
        });
        return $groups;
    }
    foreach ($groups as &$g) {
        $rows = array_map(fn ($r) => ['_v' => inventory_sort_value($r, $sort['key'])[0], '_r' => $r], $g['rows']);
        $type = $g['rows'] ? inventory_sort_value($g['rows'][0], $sort['key'])[1] : 'text';
        $g['rows'] = array_column(rows_sorted($rows, '_v', $type, $sort['dir']), '_r');
    }
    return $groups;
}

/** نص التوزيع على المخازن لصنف */
function inventory_split_text(array $byWarehouse, array $whNames, bool $display): string
{
    $parts = [];
    foreach ($byWarehouse as $wid => $qty) {
        if ($qty > 0) {
            $parts[] = ($whNames[$wid] ?? '') . ': ' . ($display ? fmt_int($qty) : (string) $qty);
        }
    }
    return $parts ? implode('، ', $parts) : 'لا يوجد';
}

/** مجموعة بيانات التصدير للمخزون (بلا الإجماليات الفرعية: صف لكل مقاس ثم الإجمالي) */
function inventory_dataset(array $req, bool $display): array
{
    $cols = [];
    foreach (inventory_columns($req['show_split']) as $k => [$label, $type]) {
        $cols[] = ['key' => $k, 'label' => $label, 'type' => $type, 'align' => in_array($type, ['int', 'volume'], true) ? 'end' : 'start'];
    }
    $rows = [];
    foreach ($req['view']['groups'] as $g) {
        foreach ($g['rows'] as $r) {
            $rows[] = [
                'type' => (string) $g['name'],
                'width' => export_dim((int) $r['width_um'], (string) $r['width_unit'], $display),
                'thickness' => export_dim((int) $r['thickness_um'], (string) $r['thickness_unit'], $display),
                'length' => export_dim((int) $r['length_um'], (string) $r['length_unit'], $display),
                'qty' => (int) $r['qty'],
                'piece_volume' => (string) $r['piece_volume_m3'],
                'volume' => (string) $r['volume'],
                'split' => inventory_split_text($r['by_warehouse'], $req['wh_names'], $display),
                'status' => $r['qty'] === 0 ? 'نفد' : 'متاح',
            ];
        }
    }
    return [
        'title' => 'المخزون',
        'subtitle' => $req['subtitle'],
        'columns' => $cols,
        'rows' => $rows,
        'totals' => $rows ? ['qty' => $req['view']['qty'], 'volume' => $req['view']['volume']] : null,
        'generated_at' => now(),
    ];
}

/* ===================== السجل (الفواتير والحركات) ===================== */

/** تاريخ من حقل التصفية بصيغة YYYY-MM-DD، أو null إذا كان فارغًا أو غير صالح */
function parse_date_filter(string $raw, bool &$invalid): ?DateTimeImmutable
{
    $raw = normalize_number_input($raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        $invalid = true;
        return null;
    }
    return $dt;
}

/** أعمدة جدول السجل: [المفتاح => العنوان]. الفرع يظهر فقط مع أكثر من فرع */
function documents_columns(bool $showBranch): array
{
    $cols = [
        'doc' => 'المستند', 'date' => 'التاريخ', 'user' => 'المستخدم', 'branch' => 'الفرع', 'warehouse' => 'المخزن',
        'party' => 'العميل / المورد', 'lines' => 'الأسطر', 'qty' => 'القطع', 'volume' => 'الحجم (م³)', 'amount' => 'القيمة', 'status' => 'الحالة',
    ];
    if (!$showBranch) {
        unset($cols['branch']);
    }
    return $cols;
}

/** ترتيب السجل في قاعدة البيانات: [العمود => ORDER BY]. المستخدم غير قابل للترتيب (الاسم المعروض من جدول آخر) */
const DOCUMENTS_SORT_SQL = [
    'doc' => 'd.kind %1$s, d.doc_no %1$s',
    'date' => 'd.doc_date %1$s, d.id %1$s',
    'branch' => 'd.branch_name %1$s, d.id DESC',
    'warehouse' => 'd.warehouse_name %1$s, d.id DESC',
    'party' => 'd.party_name IS NULL, d.party_name %1$s, d.id DESC',
    'lines' => 'd.line_count %1$s, d.id DESC',
    'qty' => 'd.total_qty %1$s, d.id DESC',
    'volume' => 'd.total_volume_m3 %1$s, d.id DESC',
    'amount' => "d.kind <> 'sale', d.total_amount %1\$s, d.id DESC",
    'status' => 'd.status %1$s, d.id DESC',
];

/** تصفية السجل وشرطها، للصفحة ولملف التصدير */
function documents_request(PDO $pdo, array $get): array
{
    $kind = input($get, 'kind');
    $status = input($get, 'status');
    $warehouseId = (int) input($get, 'warehouse');
    $typeId = (int) input($get, 'type');
    $q = clean_text(input($get, 'q'));
    if (mb_strlen($q) > 100) {
        $q = mb_substr($q, 0, 100);
    }
    $dateInvalid = false;
    $from = parse_date_filter(input($get, 'from'), $dateInvalid);
    $to = parse_date_filter(input($get, 'to'), $dateInvalid);

    // الفرع: المستخدم المقيد بفرع يرى مستندات فرعه فقط (الصادرة منه والتحويلات الواردة إليه)
    $scope = allowed_branch_id($pdo);
    $branches = catalog_all($pdo, 'branch');
    $branchNames = array_column($branches, 'name', 'id');
    $branchId = $scope ?? (int) input($get, 'branch');
    if (!isset($branchNames[$branchId])) {
        $branchId = $scope ?? 0;
    }
    $warehouses = scoped_warehouses($pdo, $branchId > 0 ? $branchId : null);
    $whNames = array_column($warehouses, 'name', 'id');
    if ($warehouseId > 0 && !isset($whNames[$warehouseId])) {
        $warehouseId = 0;
    }
    $types = catalog_all($pdo, 'type');
    $typeNames = array_column($types, 'name', 'id');
    if ($typeId > 0 && !isset($typeNames[$typeId])) {
        $typeId = 0;
    }

    $where = [];
    $params = [];
    if ($branchId > 0) {
        [$cond, $condParams] = document_branch_condition($branchId);
        $where[] = $cond;
        array_push($params, ...$condParams);
    }
    if (isset(DOC_KIND_LABELS[$kind])) {
        $where[] = 'd.kind = ?';
        $params[] = $kind;
    } else {
        $kind = '';
    }
    if ($status === 'active' || $status === 'cancelled') {
        $where[] = 'd.status = ?';
        $params[] = $status;
    } else {
        $status = '';
    }
    if ($warehouseId > 0) {
        $where[] = '(d.warehouse_id = ? OR d.to_warehouse_id = ?)';
        array_push($params, $warehouseId, $warehouseId);
    }
    if ($typeId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM document_lines l JOIN items i ON i.id = l.item_id WHERE l.document_id = d.id AND i.wood_type_id = ?)';
        $params[] = $typeId;
    }
    if ($from) {
        $where[] = 'd.doc_date >= ?';
        $params[] = $from->format('Y-m-d 00:00:00');
    }
    if ($to) {
        $where[] = 'd.doc_date < ?';
        $params[] = $to->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $cond = '(d.party_name LIKE ? OR d.reference LIKE ? OR d.notes LIKE ?
                 OR EXISTS (SELECT 1 FROM document_lines l2 WHERE l2.document_id = d.id AND l2.wood_type_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
        $num = normalize_number_input($q);
        if (preg_match('/^\d{1,9}\z/', $num)) {
            $cond .= ' OR d.doc_no = ?';
            $params[] = (int) $num;
        }
        $where[] = $cond . ')';
    }
    $sort = table_sort($get, DOCUMENTS_SORT_SQL);
    $order = $sort !== null ? sprintf(DOCUMENTS_SORT_SQL[$sort['key']], strtoupper($sort['dir'])) : 'd.id DESC';

    $filters = array_filter([
        'r' => 'documents', 'kind' => $kind, 'status' => $status, 'branch' => $scope === null && $branchId ? $branchId : '',
        'warehouse' => $warehouseId ?: '', 'type' => $typeId ?: '',
        'from' => $from ? $from->format('Y-m-d') : '', 'to' => $to ? $to->format('Y-m-d') : '', 'q' => $q,
    ], fn ($v) => $v !== '');

    $parts = [];
    $parts[] = $kind !== '' ? ($kind === 'sale' ? 'فواتير البيع' : DOC_KIND_LABELS[$kind]) : 'كل المستندات';
    if ($status !== '') {
        $parts[] = $status === 'active' ? 'السارية' : 'الملغاة';
    }
    if ($from && $to) {
        $parts[] = 'من ' . digits($from->format('Y-m-d')) . ' إلى ' . digits($to->format('Y-m-d'));
    } elseif ($from) {
        $parts[] = 'من ' . digits($from->format('Y-m-d'));
    } elseif ($to) {
        $parts[] = 'حتى ' . digits($to->format('Y-m-d'));
    }
    if ($branchId > 0) {
        $parts[] = 'الفرع: ' . $branchNames[$branchId];
    }
    if ($warehouseId > 0) {
        $parts[] = 'المخزن: ' . $whNames[$warehouseId];
    }
    if ($typeId > 0) {
        $parts[] = 'النوع: ' . $typeNames[$typeId];
    }
    if ($q !== '') {
        $parts[] = 'بحث: ' . $q;
    }

    return [
        'kind' => $kind, 'status' => $status, 'warehouse' => $warehouseId, 'type' => $typeId, 'q' => $q,
        'from' => $from, 'to' => $to, 'date_invalid' => $dateInvalid,
        'scope' => $scope, 'branches' => $branches, 'branch_names' => $branchNames, 'branch' => $branchId,
        'warehouses' => $warehouses, 'types' => $types,
        'where_sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '', 'params' => $params,
        'sort' => $sort, 'order' => $order, 'filters' => $filters, 'filtered' => count($filters) > 1,
        'show_branch' => count($branchNames) > 1,
        'subtitle' => implode('، ', $parts),
    ];
}

function documents_count(PDO $pdo, array $req): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents d' . $req['where_sql']);
    $stmt->execute($req['params']);
    return (int) $stmt->fetchColumn();
}

/** أعمدة ملف التصدير للسجل: كل عمود في الشاشة يقابله عمود أو أكثر (col) */
function documents_export_columns(bool $showBranch): array
{
    $cols = [
        ['key' => 'kind', 'label' => 'نوع المستند', 'type' => 'text', 'col' => 'doc'],
        ['key' => 'doc_no', 'label' => 'الرقم', 'type' => 'int', 'col' => 'doc'],
        ['key' => 'date', 'label' => 'التاريخ', 'type' => 'date', 'col' => 'date'],
        ['key' => 'user', 'label' => 'المستخدم', 'type' => 'text', 'col' => 'user'],
        ['key' => 'branch', 'label' => 'الفرع', 'type' => 'text', 'col' => 'branch'],
        ['key' => 'warehouse', 'label' => 'المخزن', 'type' => 'text', 'col' => 'warehouse'],
        ['key' => 'party', 'label' => 'العميل / المورد', 'type' => 'text', 'col' => 'party'],
        ['key' => 'lines', 'label' => 'الأسطر', 'type' => 'int', 'col' => 'lines'],
        ['key' => 'qty', 'label' => 'القطع', 'type' => 'int', 'col' => 'qty'],
        ['key' => 'volume', 'label' => 'الحجم (م³)', 'type' => 'volume', 'col' => 'volume'],
        ['key' => 'amount', 'label' => 'القيمة', 'type' => 'money', 'col' => 'amount'],
        ['key' => 'currency', 'label' => 'العملة', 'type' => 'text', 'col' => 'amount'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'text', 'col' => 'status'],
        ['key' => 'cancelled_at', 'label' => 'تاريخ الإلغاء', 'type' => 'date', 'col' => 'status'],
    ];
    return $showBranch ? $cols : array_values(array_filter($cols, fn ($c) => $c['col'] !== 'branch'));
}

/** صفوف ملف التصدير للسجل، تُقرأ من قاعدة البيانات صفًا صفًا (حتى 50 ألف صف بذاكرة ثابتة تقريبًا) */
function documents_export_rows(PDO $pdo, array $req): Generator
{
    $names = users_name_map($pdo);
    $stmt = $pdo->prepare('SELECT d.* FROM documents d' . $req['where_sql'] . ' ORDER BY ' . $req['order']);
    $stmt->execute($req['params']);
    foreach ($stmt as $d) {
        $transfer = $d['kind'] === 'transfer';
        $interBranch = $transfer && $d['to_branch_id'] !== null && (int) $d['to_branch_id'] !== (int) $d['branch_id'];
        yield [
            'kind' => $d['kind'] === 'sale' ? 'فاتورة بيع' : kind_label((string) $d['kind']),
            'doc_no' => (int) $d['doc_no'],
            'date' => (string) $d['doc_date'],
            'user' => $names[(int) $d['created_by']] ?? '',
            'branch' => $interBranch ? 'من ' . $d['branch_name'] . ' إلى ' . $d['to_branch_name'] : (string) $d['branch_name'],
            'warehouse' => $transfer ? 'من ' . $d['warehouse_name'] . ' إلى ' . $d['to_warehouse_name'] : (string) $d['warehouse_name'],
            'party' => (string) $d['party_name'],
            'lines' => (int) $d['line_count'],
            'qty' => (int) $d['total_qty'],
            'volume' => (string) $d['total_volume_m3'],
            'amount' => $d['kind'] === 'sale' ? (string) $d['total_amount'] : null,
            'currency' => $d['kind'] === 'sale' ? (string) $d['currency'] : '',
            'status' => $d['status'] === 'cancelled' ? 'ملغاة' : 'سارية',
            'cancelled_at' => $d['status'] === 'cancelled' ? (string) $d['cancelled_at'] : null,
        ];
    }
}
