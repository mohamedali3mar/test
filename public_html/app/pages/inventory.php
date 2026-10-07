<?php
defined('APP_ROOT') || exit;

$pdo = db();
$q = clean_text(input($_GET, 'q'));
$typeId = (int) input($_GET, 'type');
$warehouseId = (int) input($_GET, 'warehouse');
$hideEmpty = input($_GET, 'hide_empty') === '1';

$types = catalog_all($pdo, 'type');
$warehouses = catalog_all($pdo, 'warehouse');
$whNames = array_column($warehouses, 'name', 'id');
if ($warehouseId > 0 && !isset($whNames[$warehouseId])) {
    $warehouseId = 0;
}
$view = inventory_view($pdo, $q, $typeId, $warehouseId, $hideEmpty);
$summary = warehouse_summary($pdo);
$filtered = $q !== '' || $typeId > 0 || $warehouseId > 0 || $hideEmpty;
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
  <div class="field">
    <label for="q">بحث باسم النوع</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100">
  </div>
  <div class="field">
    <label for="type">نوع الخشب</label>
    <select id="type" name="type"><?= options_html($types, $typeId ? (string) $typeId : '', 'كل الأنواع') ?></select>
  </div>
  <div class="field">
    <label for="warehouse">المخزن</label>
    <select id="warehouse" name="warehouse"><?= options_html($warehouses, $warehouseId ? (string) $warehouseId : '', 'كل المخازن') ?></select>
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
<?php if (count($summary) > 1): ?>
  <section class="section" aria-labelledby="wh-summary-title">
    <h2 id="wh-summary-title">ملخص المخازن</h2>
    <div class="table-wrap">
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
            <th scope="row"><a href="<?= h(url('inventory', ['warehouse' => (int) $s['id']])) ?>"><?= h($s['name']) ?></a></th>
            <td class="num"><?= h(fmt_int((string) $s['sizes'])) ?></td>
            <td class="num"><?= h(fmt_int((string) $s['qty'])) ?></td>
            <td class="num"><?= h(fmt_volume((string) $s['volume'])) ?></td>
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
      لا توجد نتائج مطابقة.
    <?php else: ?>
      المخزون فارغ. ابدأ <a href="<?= h(url('receive')) ?>">بإضافة وارد</a>.
    <?php endif; ?>
  </p>
<?php else: ?>
  <section aria-labelledby="stock-title">
    <h2 id="stock-title"><?= $warehouseId ? h('أرصدة ' . $whNames[$warehouseId]) : 'أرصدة كل المخازن' ?></h2>
    <p class="summary">
      <?= $filtered ? 'إجمالي النتائج المعروضة' : 'إجمالي المخزون' ?>:
      <strong><?= h(fmt_volume($view['volume'])) ?> م³</strong>
      في <?= h(fmt_int($view['qty'])) ?> قطعة
      <?php if ($view['empty']): ?><span class="muted">، مقاسات نافدة: <?= h(fmt_int($view['empty'])) ?></span><?php endif; ?>
    </p>
    <div class="table-wrap">
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
            <td><?= h($g['name']) ?></td>
            <td class="num"><?= h(fmt_dim((int) $r['width_um'], $r['width_unit'])) ?></td>
            <td class="num"><?= h(fmt_dim((int) $r['thickness_um'], $r['thickness_unit'])) ?></td>
            <td class="num"><?= h(fmt_dim((int) $r['length_um'], $r['length_unit'])) ?></td>
            <td class="num"><?= h(fmt_int($r['qty'])) ?></td>
            <td class="num"><?= h(fmt_volume((string) $r['piece_volume_m3'])) ?></td>
            <td class="num"><?= h(fmt_volume($r['volume'])) ?></td>
            <?php if ($showSplit): ?>
              <td class="split">
                <?php $parts = [];
                foreach ($r['by_warehouse'] as $wid => $qty) {
                    if ($qty > 0) {
                        $parts[] = ($whNames[$wid] ?? '') . ': ' . fmt_int($qty);
                    }
                }
                echo $parts ? h(implode('، ', $parts)) : '<span class="muted">لا يوجد</span>'; ?>
              </td>
            <?php endif; ?>
            <td><?= $empty ? '<span class="status status-empty">نفد</span>' : 'متاح' ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="row-subtotal">
            <th scope="row" colspan="4">إجمالي <?= h($g['name']) ?></th>
            <td class="num"><?= h(fmt_int($g['qty'])) ?></td>
            <td></td>
            <td class="num"><?= h(fmt_volume($g['volume'])) ?></td>
            <?php if ($showSplit): ?><td></td><?php endif; ?>
            <td><?= $g['empty'] ? h('مقاسات نافدة: ' . fmt_int($g['empty'])) : '' ?></td>
          </tr>
        </tbody>
        <?php endforeach; ?>
        <tfoot>
          <tr class="row-total">
            <th scope="row" colspan="4"><?= $filtered ? 'إجمالي النتائج' : 'إجمالي المخزون' ?></th>
            <td class="num"><?= h(fmt_int($view['qty'])) ?></td>
            <td></td>
            <td class="num"><?= h(fmt_volume($view['volume'])) ?></td>
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
