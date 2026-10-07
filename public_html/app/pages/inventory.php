<?php
defined('APP_ROOT') || exit;

$pdo = db();
$q = clean_text(input($_GET, 'q'));
$typeId = (int) input($_GET, 'type');
$warehouseId = (int) input($_GET, 'warehouse');
$hideEmpty = input($_GET, 'hide_empty') === '1';

$types = catalog_all($pdo, 'type');
// الفرع: المستخدم المقيد بفرع يرى فرعه فقط، وغيره يختار فرعًا أو كل الفروع
$scope = allowed_branch_id($pdo);
$branches = catalog_all($pdo, 'branch');
$branchNames = array_column($branches, 'name', 'id');
$branchId = $scope ?? (int) input($_GET, 'branch');
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
$summary = warehouse_summary($pdo, $branchFilter);
// عند عرض كل الفروع: ملخص لكل فرع مع مخازنه
$branchRows = $branchFilter === null && count($branches) > 1 ? branch_summary($pdo) : [];
$filtered = $q !== '' || $typeId > 0 || $warehouseId > 0 || $hideEmpty || ($scope === null && $branchFilter !== null);
$showSplit = $warehouseId === 0 && count($warehouses) > 1;

render_header('المخزون', 'inventory');
?>
<div class="page-head">
  <h1>المخزون</h1>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= h(url('receive')) ?>">إضافة وارد</a>
    <a class="btn" href="<?= h(url('sell')) ?>">فاتورة بيع</a>
    <a class="btn" href="<?= h(url('transfer')) ?>">تحويل</a>
  </div>
</div>

<form method="get" action="index.php" class="filters" role="search" aria-label="تصفية المخزون">
  <input type="hidden" name="r" value="inventory">
  <div class="field field-wide">
    <label for="q">بحث باسم النوع</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100">
  </div>
  <div class="field">
    <label for="type">نوع الخشب</label>
    <select id="type" name="type"><?= options_html($types, $typeId ? (string) $typeId : '', 'كل الأنواع') ?></select>
  </div>
  <?php if ($scope === null && count($branches) > 1): ?>
  <div class="field">
    <label for="branch">الفرع</label>
    <select id="branch" name="branch"><?= options_html($branches, $branchId ? (string) $branchId : '', 'كل الفروع') ?></select>
  </div>
  <?php endif; ?>
  <div class="field">
    <label for="warehouse">المخزن</label>
    <select id="warehouse" name="warehouse"><?= warehouse_options($warehouses, $warehouseId ? (string) $warehouseId : '', 'كل المخازن') ?></select>
  </div>
  <div class="field field-check">
    <input type="checkbox" id="hide_empty" name="hide_empty" value="1"<?= $hideEmpty ? ' checked' : '' ?>>
    <label for="hide_empty">إخفاء المقاسات النافدة</label>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <?php if ($filtered): ?><a class="btn btn-quiet" href="<?= h(url('inventory')) ?>">مسح التصفية</a><?php endif; ?>
  </div>
</form>

<div id="live-inventory" data-live>
<?php if ($branchRows): $branchTotals = branch_summary_totals($branchRows); ?>
  <section class="section" aria-labelledby="branch-summary-title">
    <h2 id="branch-summary-title">ملخص الفروع</h2>
    <div class="table-wrap">
      <table>
        <caption class="visually-hidden">إجمالي كل فرع ثم مخازنه</caption>
        <thead>
          <tr>
            <th scope="col">الفرع والمخزن</th>
            <th scope="col" class="num">مقاسات متاحة</th>
            <th scope="col" class="num">القطع</th>
            <th scope="col" class="num">الحجم (م³)</th>
          </tr>
        </thead>
        <?php foreach ($branchRows as $b): $bid = (int) $b['id']; ?>
        <tbody>
          <tr class="row-subtotal">
            <th scope="row"><a href="<?= h(url('inventory', ['branch' => $bid])) ?>"><?= h($b['name']) ?></a></th>
            <td class="num" data-label="مقاسات متاحة"><?= h(fmt_int((string) $b['sizes'])) ?></td>
            <td class="num" data-label="القطع"><?= h(fmt_int((string) $b['qty'])) ?></td>
            <td class="num" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume((string) $b['volume'])) ?></td>
          </tr>
          <?php $inBranch = array_filter($summary, fn ($s) => (int) $s['branch_id'] === $bid); ?>
          <?php foreach ($inBranch as $s): ?>
          <tr class="row-child">
            <th scope="row"><a href="<?= h(url('inventory', ['warehouse' => (int) $s['id']])) ?>"><?= h($s['name']) ?></a></th>
            <td class="num" data-label="مقاسات متاحة"><?= h(fmt_int((string) $s['sizes'])) ?></td>
            <td class="num" data-label="القطع"><?= h(fmt_int((string) $s['qty'])) ?></td>
            <td class="num" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume((string) $s['volume'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$inBranch): ?>
          <tr class="row-child"><td colspan="4" class="muted" data-label="المخزن">لا توجد مخازن في هذا الفرع.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php endforeach; ?>
        <tfoot>
          <tr class="row-total">
            <th scope="row">إجمالي كل الفروع</th>
            <td></td>
            <td class="num" data-label="القطع"><?= h(fmt_int($branchTotals['qty'])) ?></td>
            <td class="num" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume($branchTotals['volume'])) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
<?php elseif (count($summary) > 1): ?>
  <section class="section" aria-labelledby="wh-summary-title">
    <h2 id="wh-summary-title">ملخص المخازن</h2>
    <div class="table-wrap table-stack">
      <table>
        <caption class="visually-hidden">إجمالي كل مخزن</caption>
        <thead>
          <tr>
            <th scope="col">المخزن</th>
            <th scope="col" class="num">مقاسات متاحة</th>
            <th scope="col" class="num">القطع</th>
            <th scope="col" class="num">الحجم (م³)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary as $s): ?>
          <tr>
            <th scope="row" data-label="<?= h('المخزن') ?>"><a href="<?= h(url('inventory', ['warehouse' => (int) $s['id']])) ?>"><?= h($s['name']) ?></a></th>
            <td class="num" data-label="<?= h('مقاسات متاحة') ?>"><?= h(fmt_int((string) $s['sizes'])) ?></td>
            <td class="num" data-label="<?= h('القطع') ?>"><?= h(fmt_int((string) $s['qty'])) ?></td>
            <td class="num" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume((string) $s['volume'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if (!$view['groups']): ?>
  <p class="empty">
    <?php if ($filtered): ?>
      لا توجد نتائج مطابقة للتصفية. <a href="<?= h(url('inventory')) ?>">عرض كل المخزون</a>
    <?php else: ?>
      المخزون فارغ. ابدأ <a href="<?= h(url('receive')) ?>">بإضافة وارد</a>.
    <?php endif; ?>
  </p>
<?php else: ?>
  <section aria-labelledby="stock-title">
    <h2 id="stock-title"><?= h($warehouseId ? 'أرصدة ' . $whNames[$warehouseId]
        : ($branchFilter !== null ? 'أرصدة الفرع: ' . $branchNames[$branchFilter] : 'أرصدة كل المخازن')) ?></h2>
    <p class="summary">
      <?= $filtered ? 'إجمالي النتائج المعروضة' : 'إجمالي المخزون' ?>:
      الحجم <strong class="nowrap"><?= h(fmt_volume($view['volume'])) ?> م³</strong>،
      عدد القطع: <span class="nowrap"><?= h(fmt_int($view['qty'])) ?></span>
      <?php if ($view['empty']): ?><span class="muted">، مقاسات نافدة: <?= h(fmt_int($view['empty'])) ?></span><?php endif; ?>
    </p>
    <div class="table-wrap table-stack">
      <table class="inventory-table">
        <caption class="visually-hidden">أرصدة المقاسات مجمعة حسب النوع</caption>
        <thead>
          <tr>
            <th scope="col">النوع</th>
            <th scope="col" class="num">العرض</th>
            <th scope="col" class="num">التخانة</th>
            <th scope="col" class="num">الطول</th>
            <th scope="col" class="num">العدد المتاح</th>
            <th scope="col" class="num">حجم القطعة (م³)</th>
            <th scope="col" class="num">الحجم المتاح (م³)</th>
            <?php if ($showSplit): ?><th scope="col">التوزيع على المخازن</th><?php endif; ?>
            <th scope="col">الحالة</th>
          </tr>
        </thead>
        <?php foreach ($view['groups'] as $g): ?>
        <tbody>
          <?php foreach ($g['rows'] as $r): $empty = $r['qty'] === 0; ?>
          <tr class="<?= $empty ? 'row-empty' : '' ?>">
            <td data-label="<?= h('النوع') ?>"><?= h($g['name']) ?></td>
            <td class="num" data-label="<?= h('العرض') ?>"><?= h(fmt_dim((int) $r['width_um'], $r['width_unit'])) ?></td>
            <td class="num" data-label="<?= h('التخانة') ?>"><?= h(fmt_dim((int) $r['thickness_um'], $r['thickness_unit'])) ?></td>
            <td class="num" data-label="<?= h('الطول') ?>"><?= h(fmt_dim((int) $r['length_um'], $r['length_unit'])) ?></td>
            <td class="num" data-label="<?= h('العدد المتاح') ?>"><?= h(fmt_int($r['qty'])) ?></td>
            <td class="num" data-label="<?= h('حجم القطعة (م³)') ?>"><?= h(fmt_volume((string) $r['piece_volume_m3'])) ?></td>
            <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume($r['volume'])) ?></td>
            <?php if ($showSplit): ?>
              <td class="split" data-label="<?= h('التوزيع على المخازن') ?>">
                <?php $parts = [];
                foreach ($r['by_warehouse'] as $wid => $qty) {
                    if ($qty > 0) {
                        $parts[] = ($whNames[$wid] ?? '') . ': ' . fmt_int($qty);
                    }
                }
                echo $parts ? h(implode('، ', $parts)) : '<span class="muted">لا يوجد</span>'; ?>
              </td>
            <?php endif; ?>
            <td data-label="<?= h('الحالة') ?>"><?= $empty ? '<span class="status status-empty">نفد</span>' : 'متاح' ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="row-subtotal">
            <th scope="row" colspan="4">إجمالي <?= h($g['name']) ?></th>
            <td class="num" data-label="<?= h('العدد المتاح') ?>"><?= h(fmt_int($g['qty'])) ?></td>
            <td></td>
            <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume($g['volume'])) ?></td>
            <?php if ($showSplit): ?><td></td><?php endif; ?>
            <td data-label="<?= h('الحالة') ?>"><?= $g['empty'] ? h('مقاسات نافدة: ' . fmt_int($g['empty'])) : '' ?></td>
          </tr>
        </tbody>
        <?php endforeach; ?>
        <tfoot>
          <tr class="row-total">
            <th scope="row" colspan="4"><?= $filtered ? 'إجمالي النتائج' : 'إجمالي المخزون' ?></th>
            <td class="num" data-label="<?= h('العدد المتاح') ?>"><?= h(fmt_int($view['qty'])) ?></td>
            <td></td>
            <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume($view['volume'])) ?></td>
            <?php if ($showSplit): ?><td></td><?php endif; ?>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
<?php endif; ?>
</div>
<?php
render_footer();
