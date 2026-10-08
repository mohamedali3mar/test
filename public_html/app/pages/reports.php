<?php
defined('APP_ROOT') || exit;

/*
 * التقارير: index.php?r=reports يعرض قائمة التقارير المتاحة للمستخدم،
 * و index.php?r=reports&report=<key> يعرض نموذج التصفية (GET) والجدول مع الإجماليات وزر الطباعة.
 */

$pdo = db();
$key = input($_GET, 'report');

if ($key === '') {
    $visible = acct_reports_visible();
    if (!$visible) {
        acct_require('reports.sales');
    }
    render_header('التقارير', 'reports');
    ?>
<div class="page-head">
  <h1>التقارير</h1>
  <?php if (acct_can('dashboard') && acct_branch_scope($pdo) === null): ?><div class="page-actions"><a class="btn" href="<?= h(url('dashboard')) ?>">لوحة التحكم</a></div><?php endif; ?>
</div>
<?php if (!$visible): ?>
  <p class="empty">لا توجد تقارير متاحة لحسابك.</p>
<?php else: ?>
  <ul class="report-list">
    <?php foreach ($visible as $k => $r): ?>
    <li>
      <a href="<?= h(url('reports', ['report' => $k])) ?>"><?= h($r['title']) ?></a>
      <p class="hint"><?= h($r['description']) ?></p>
    </li>
    <?php endforeach; ?>
  </ul>
<?php endif;
    render_footer();
    return;
}

if (!isset(ACCT_REPORTS[$key])) {
    render_simple_error('التقرير المطلوب غير موجود.', 404);
}
$def = ACCT_REPORTS[$key];
acct_require($def['permission']);
if (in_array($key, ACCT_ALL_BRANCH_REPORTS, true)) {
    acct_require_all_branches($pdo);
}

$ds = acct_report($pdo, $key, $_GET);
$reportSortable = report_sortable($ds);
$sort = table_sort($_GET, $reportSortable);
$ds = dataset_sorted($ds, $sort);
$f = $ds['filters'];
$spec = $def['filters'];
$has = fn (string $k) => in_array($k, $spec, true);
$sel = fn (string $k) => isset($f[$k]) ? (string) $f[$k] : '';

$opts = [];
if ($has('warehouse')) {
    $opts['warehouse'] = catalog_all($pdo, 'warehouse');
}
if ($has('type')) {
    $opts['type'] = catalog_all($pdo, 'type');
}
if ($has('customer')) {
    $opts['customer'] = $pdo->query("SELECT id, name FROM parties WHERE kind = 'customer' ORDER BY name, id")->fetchAll();
}
if ($has('cash_box')) {
    $opts['cash_box'] = $pdo->query('SELECT id, name FROM cash_boxes ORDER BY name, id')->fetchAll();
}
if ($has('category')) {
    $opts['category'] = $pdo->query('SELECT id, name FROM expense_categories ORDER BY name, id')->fetchAll();
}
$selectLabels = [
    'warehouse' => ['المخزن', 'كل المخازن'],
    'type' => ['نوع الخشب', 'كل الأنواع'],
    'customer' => ['العميل', 'كل العملاء'],
    'cash_box' => ['الخزنة', 'كل الخزائن'],
    'category' => ['التصنيف', 'كل التصنيفات'],
];
$enumFields = [];
if ($has('group')) {
    $enumFields['group'] = ['التجميع', ACCT_GROUP_LABELS];
}
if ($has('group_expenses')) {
    $enumFields['group'] = ['التجميع', ACCT_EXPENSE_GROUP_LABELS];
}
if ($has('dimension')) {
    $dims = ACCT_DIMENSION_LABELS;
    if (!acct_has_column($pdo, 'documents', 'branch_id')) {
        unset($dims['branch']);
    }
    $enumFields['dimension'] = ['حسب', $dims];
}
if ($has('show')) {
    $enumFields['show'] = ['الحسابات', ACCT_SHOW_LABELS];
}
// معاملات الصفحة الحالية (التصفية المقبولة فقط) لروابط الترتيب والتصدير
$reportQuery = ['r' => 'reports', 'report' => $key];
foreach (['from', 'to', 'group', 'dimension', 'show', 'warehouse', 'type', 'customer', 'cash_box', 'category'] as $k) {
    if (isset($f[$k]) && $f[$k] !== '') {
        $reportQuery[$k] = (string) $f[$k];
    }
}
$exportBase = ['t' => 'report'] + array_diff_key($reportQuery, ['r' => true]);
$fieldNames = ['from' => 'من تاريخ', 'to' => 'إلى تاريخ'] + array_map(fn ($l) => $l[0], $selectLabels) + array_map(fn ($e) => $e[0], $enumFields);

render_header($def['title'], 'reports', 'page-report');
?>
<div class="page-head">
  <h1><?= h($def['title']) ?></h1>
  <div class="page-actions no-print">
    <button type="button" class="btn btn-primary" data-action="print"><?= icon('print') ?>طباعة</button>
    <a class="btn btn-quiet" href="<?= h(url('reports')) ?>">كل التقارير</a>
  </div>
</div>

<?php if ($spec): ?>
<form method="get" action="index.php" class="filters no-print" role="search" aria-label="تصفية التقرير">
  <input type="hidden" name="r" value="reports">
  <input type="hidden" name="report" value="<?= h($key) ?>">
  <?php if ($sort !== null): ?><input type="hidden" name="sort" value="<?= h(table_sort_param($sort)) ?>"><?php endif; ?>
  <?php if ($has('from')): ?>
  <div class="field">
    <label for="from">من تاريخ</label>
    <input type="date" id="from" name="from" value="<?= h($sel('from')) ?>">
  </div>
  <div class="field">
    <label for="to">إلى تاريخ</label>
    <input type="date" id="to" name="to" value="<?= h($sel('to')) ?>">
  </div>
  <?php endif; ?>
  <?php foreach ($enumFields as $name => [$label, $choices]): ?>
  <div class="field">
    <label for="<?= h($name) ?>"><?= h($label) ?></label>
    <select id="<?= h($name) ?>" name="<?= h($name) ?>">
      <?php foreach ($choices as $v => $text): ?>
      <option value="<?= h((string) $v) ?>"<?= $sel($name) === (string) $v ? ' selected' : '' ?>><?= h($text) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endforeach; ?>
  <?php foreach ($opts as $name => $rows): ?>
  <div class="field">
    <label for="<?= h($name) ?>"><?= h($selectLabels[$name][0]) ?></label>
    <select id="<?= h($name) ?>" name="<?= h($name) ?>"><?= options_html($rows, $sel($name), $selectLabels[$name][1]) ?></select>
  </div>
  <?php endforeach; ?>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <a class="btn btn-quiet" href="<?= h(url('reports', ['report' => $key])) ?>">مسح التصفية</a>
  </div>
</form>
<?php endif; ?>

<?php if ($ds['ignored']): ?>
  <div class="alert alert-warning" role="status">
    تم تجاهل قيم غير صحيحة في: <?= h(implode('، ', array_map(fn ($k) => $fieldNames[$k] ?? $k, $ds['ignored']))) ?>.
    التاريخ بالصيغة <?= h(digits('2026-01-31')) ?>.
  </div>
<?php endif; ?>

<div id="live-report" data-live>
  <p class="print-only report-company"><?= h(app_setting('company_name')) ?></p>
  <p class="report-subtitle"><?= h($ds['subtitle']) ?></p>
  <?php if ($key === 'valuation' && ($ds['notes']['unvalued'] ?? 0) > 0): ?>
    <div class="alert alert-warning" role="status">
      يوجد <?= h(fmt_int($ds['notes']['unvalued'])) ?> صنف له رصيد بدون تكلفة (غير مقيّم)، فقيمة المخزون أقل من الحقيقة.
    </div>
  <?php endif; ?>
  <?php render_table_tools([
      'key' => 'report.' . $key, 'table' => 'report-table', 'columns' => array_column($ds['columns'], 'label', 'key'),
      'required' => [$ds['columns'][0]['key']],
      'export' => $exportBase + ['sort' => table_sort_param($sort)],
      'sortable' => $reportSortable, 'sort' => $sort, 'query' => $reportQuery,
  ]); ?>
  <?php render_dataset_table($ds, ['id' => 'report-table', 'sort' => $sort, 'query' => $reportQuery, 'sortable' => $reportSortable]); ?>
  <?php foreach ($ds['sections'] ?? [] as $i => $section): ?>
  <section class="section" aria-labelledby="report-section-<?= (int) $i ?>">
    <h2 id="report-section-<?= (int) $i ?>"><?= h($section['title']) ?></h2>
    <?php render_table_tools([
        'key' => 'report.' . $key . '.' . ($i + 1), 'table' => 'report-table-' . ($i + 1),
        'columns' => array_column($section['columns'], 'label', 'key'), 'required' => [$section['columns'][0]['key']],
        'export' => $exportBase + ['part' => (string) ($i + 1)],
    ]); ?>
    <?php render_dataset_table($section, ['id' => 'report-table-' . ($i + 1)]); ?>
  </section>
  <?php endforeach; ?>
  <p class="hint">أُعد التقرير في <?= h(fmt_datetime($ds['generated_at'])) ?></p>
</div>
<?php
render_footer();
