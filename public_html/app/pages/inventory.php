<?php
defined('APP_ROOT') || exit;

$pdo = db();
$q = clean_text(input($_GET, 'q'));
$typeId = (int) input($_GET, 'type');
$hideEmpty = input($_GET, 'hide_empty') === '1';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = 't.name LIKE ?';
    $params[] = '%' . addcslashes($q, '%_\\') . '%';
}
if ($typeId > 0) {
    $where[] = 't.id = ?';
    $params[] = $typeId;
}
if ($hideEmpty) {
    $where[] = 'i.qty_on_hand > 0';
}
$sql = 'SELECT i.*, t.name AS wood_type_name, i.qty_on_hand * i.piece_volume_m3 AS volume
        FROM items i JOIN wood_types t ON t.id = i.wood_type_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY t.name, t.id, i.thickness_um, i.width_um, i.length_um';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// التجميع حسب النوع. الأحجام تُجمع بدقة كاملة كأعداد صحيحة بالميكرومتر المكعب
$groups = [];
$grandQty = '0';
$grandUm3 = '0';
foreach ($rows as $r) {
    $tid = (int) $r['wood_type_id'];
    if (!isset($groups[$tid])) {
        $groups[$tid] = ['name' => $r['wood_type_name'], 'rows' => [], 'qty' => '0', 'um3' => '0', 'empty' => 0];
    }
    $groups[$tid]['rows'][] = $r;
    $groups[$tid]['qty'] = Num::add($groups[$tid]['qty'], (string) $r['qty_on_hand']);
    $groups[$tid]['um3'] = Num::add($groups[$tid]['um3'], m3_to_um3((string) $r['volume']));
    if ((int) $r['qty_on_hand'] === 0) {
        $groups[$tid]['empty']++;
    }
    $grandQty = Num::add($grandQty, (string) $r['qty_on_hand']);
    $grandUm3 = Num::add($grandUm3, m3_to_um3((string) $r['volume']));
}
$types = all_wood_types($pdo);
$filtered = $q !== '' || $typeId > 0 || $hideEmpty;

render_header('المخزون', 'inventory');
?>
<div class="page-head">
  <h1>المخزون</h1>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= h(url('receive')) ?>">إضافة وارد</a>
    <a class="btn" href="<?= h(url('sell')) ?>">تسجيل بيع</a>
  </div>
</div>

<form method="get" action="index.php" class="filters" role="search">
  <input type="hidden" name="r" value="inventory">
  <div class="field">
    <label for="q">بحث باسم النوع</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100">
  </div>
  <div class="field">
    <label for="type">نوع الخشب</label>
    <select id="type" name="type">
      <option value="">كل الأنواع</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === $typeId ? ' selected' : '' ?>><?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
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

<?php if (!$rows): ?>
  <p class="empty">
    <?php if ($filtered): ?>
      لا توجد نتائج مطابقة للبحث.
    <?php else: ?>
      المخزون فارغ. ابدأ <a href="<?= h(url('receive')) ?>">بإضافة وارد</a>.
    <?php endif; ?>
  </p>
<?php else: ?>
  <p class="summary">
    <?= $filtered ? 'إجمالي النتائج المعروضة' : 'إجمالي المخزون' ?>:
    <strong><?= fmt_volume(um3_to_m3($grandUm3)) ?> م³</strong>
    في <?= fmt_int($grandQty) ?> قطعة
  </p>

  <div class="table-wrap">
    <table class="inventory-table">
      <thead>
        <tr>
          <th scope="col">النوع</th>
          <th scope="col" class="num">العرض</th>
          <th scope="col" class="num">التخانة</th>
          <th scope="col" class="num">الطول</th>
          <th scope="col" class="num">العدد المتاح</th>
          <th scope="col" class="num">حجم القطعة (م³)</th>
          <th scope="col" class="num">الحجم المتاح (م³)</th>
          <th scope="col">الحالة</th>
        </tr>
      </thead>
      <?php foreach ($groups as $tid => $g): ?>
      <tbody>
        <?php foreach ($g['rows'] as $r): $empty = (int) $r['qty_on_hand'] === 0; ?>
        <tr class="<?= $empty ? 'row-empty' : '' ?>">
          <td><?= h($g['name']) ?></td>
          <td class="num"><?= fmt_dim((int) $r['width_um'], $r['width_unit']) ?></td>
          <td class="num"><?= fmt_dim((int) $r['thickness_um'], $r['thickness_unit']) ?></td>
          <td class="num"><?= fmt_dim((int) $r['length_um'], $r['length_unit']) ?></td>
          <td class="num"><?= fmt_int((int) $r['qty_on_hand']) ?></td>
          <td class="num"><?= fmt_volume((string) $r['piece_volume_m3']) ?></td>
          <td class="num"><?= fmt_volume((string) $r['volume']) ?></td>
          <td><?php if ($empty): ?><span class="status status-empty">نفد</span><?php else: ?>متاح<?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="row-subtotal">
          <th scope="row" colspan="4">إجمالي <?= h($g['name']) ?></th>
          <td class="num"><?= fmt_int($g['qty']) ?></td>
          <td></td>
          <td class="num"><?= fmt_volume(um3_to_m3($g['um3'])) ?></td>
          <td><?= $g['empty'] ? fmt_int($g['empty']) . ' نافد' : '' ?></td>
        </tr>
      </tbody>
      <?php endforeach; ?>
      <tfoot>
        <tr class="row-total">
          <th scope="row" colspan="4"><?= $filtered ? 'إجمالي النتائج' : 'إجمالي المخزون' ?></th>
          <td class="num"><?= fmt_int($grandQty) ?></td>
          <td></td>
          <td class="num"><?= fmt_volume(um3_to_m3($grandUm3)) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
<?php endif; ?>
<?php
render_footer();
