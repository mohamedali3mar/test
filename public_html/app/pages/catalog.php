<?php
defined('APP_ROOT') || exit;

/* صفحة أنواع الخشب وصفحة المخازن: نفس التصميم والقواعد. $catalogKind يحدده index.php */

$kind = $catalogKind === 'warehouse' ? 'warehouse' : 'type';
$route = $kind === 'warehouse' ? 'warehouses' : 'types';
$title = $kind === 'warehouse' ? 'المخازن' : 'أنواع الخشب';
$noun = catalog_def($kind)['noun'];
$pdo = db();
$errors = [];
$newName = '';
$manage = can('catalog.manage'); // الموظف يرى القائمة فقط

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('catalog.manage');
    verify_csrf();
    $action = input($_POST, 'action');
    try {
        if ($action === 'create') {
            $newName = input($_POST, 'name');
            catalog_create($pdo, $kind, $newName);
            flash('success', $kind === 'warehouse' ? 'تمت إضافة المخزن.' : 'تمت إضافة النوع.');
        } elseif ($action === 'rename') {
            catalog_rename($pdo, $kind, (int) input($_POST, 'id'), input($_POST, 'name'));
            flash('success', 'تم تعديل الاسم. المستندات السابقة تحتفظ بالاسم القديم كما سُجل وقتها.');
        } elseif ($action === 'delete') {
            catalog_delete($pdo, $kind, (int) input($_POST, 'id'));
            flash('success', $kind === 'warehouse' ? 'تم حذف المخزن.' : 'تم حذف النوع.');
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
        redirect($route);
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

if ($kind === 'warehouse') {
    $rows = $pdo->query(
        'SELECT w.id, w.name,
                COALESCE(SUM(CASE WHEN s.qty_on_hand > 0 THEN 1 ELSE 0 END), 0) AS sizes,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty,
                COALESCE(SUM(s.qty_on_hand * i.piece_volume_m3), 0) AS volume,
                (SELECT COUNT(*) FROM stock s2 WHERE s2.warehouse_id = w.id)
                  + (SELECT COUNT(*) FROM documents d WHERE d.warehouse_id = w.id OR d.to_warehouse_id = w.id) AS used
         FROM warehouses w
         LEFT JOIN stock s ON s.warehouse_id = w.id
         LEFT JOIN items i ON i.id = s.item_id
         GROUP BY w.id, w.name ORDER BY w.name, w.id'
    )->fetchAll();
} else {
    $rows = $pdo->query(
        'SELECT t.id, t.name,
                COUNT(DISTINCT CASE WHEN s.qty_on_hand > 0 THEN i.id END) AS sizes,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty,
                COALESCE(SUM(s.qty_on_hand * i.piece_volume_m3), 0) AS volume,
                COUNT(DISTINCT i.id) AS used
         FROM wood_types t
         LEFT JOIN items i ON i.wood_type_id = t.id
         LEFT JOIN stock s ON s.item_id = i.id
         GROUP BY t.id, t.name ORDER BY t.name, t.id'
    )->fetchAll();
}
$editId = $manage ? (int) input($_GET, 'edit') : 0;
$deleteId = $manage ? (int) input($_GET, 'delete') : 0;
$deleteRow = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $deleteId && (int) $r['used'] === 0) {
        $deleteRow = $r;
    }
}

render_header($title, $route);
?>
<h1><?= h($title) ?></h1>

<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<?php if ($deleteRow): ?>
  <section class="confirm-box danger" aria-labelledby="del-title">
    <h2 id="del-title">تأكيد الحذف</h2>
    <p>حذف <?= h($noun) ?> «<?= h($deleteRow['name']) ?>» نهائيًا؟ لا توجد عليه أي حركات.</p>
    <form method="post" action="<?= h(url($route)) ?>" class="actions">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $deleteRow['id'] ?>">
      <button type="submit" class="btn btn-danger">حذف</button>
      <a class="btn" href="<?= h(url($route)) ?>">تراجع</a>
    </form>
  </section>
<?php endif; ?>

<?php if (!$manage): ?>
  <p class="muted">إضافة <?= h($kind === 'warehouse' ? 'المخازن' : 'الأنواع') ?> وتعديلها وحذفها من صلاحية المدير.</p>
<?php else: ?>
<section class="section">
  <h2>إضافة <?= h($kind === 'warehouse' ? 'مخزن' : 'نوع') ?></h2>
  <form method="post" action="<?= h(url($route)) ?>" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="new-name">الاسم</label>
      <input type="text" id="new-name" name="name" value="<?= h($newName) ?>" maxlength="100" required>
    </div>
    <button type="submit" class="btn btn-primary">إضافة</button>
  </form>
</section>
<?php endif; ?>

<section class="section" id="live-catalog" data-live aria-labelledby="list-title">
  <h2 id="list-title"><?= h($kind === 'warehouse' ? 'المخازن المسجلة' : 'الأنواع المسجلة') ?></h2>
  <?php if (!$rows): ?>
    <p class="empty">لا توجد بيانات بعد.<?= $manage ? ' أضف أول ' . h($kind === 'warehouse' ? 'مخزن' : 'نوع') . ' من النموذج أعلاه.' : '' ?></p>
  <?php else: ?>
  <div class="table-wrap table-stack">
    <table>
      <caption class="visually-hidden"><?= h($title) ?></caption>
      <thead>
        <tr>
          <th scope="col">الاسم</th>
          <th scope="col" class="num">مقاسات متاحة</th>
          <th scope="col" class="num">القطع المتاحة</th>
          <th scope="col" class="num">الحجم المتاح (م³)</th>
          <th scope="col">إجراءات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
        <tr>
          <td data-label="<?= h('الاسم') ?>">
            <?php if ($editId === $rid): ?>
              <form method="post" action="<?= h(url($route)) ?>" class="form-inline compact">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= $rid ?>">
                <label class="visually-hidden" for="rename-<?= $rid ?>">الاسم الجديد</label>
                <input type="text" id="rename-<?= $rid ?>" name="name" value="<?= h($r['name']) ?>" maxlength="100" required>
                <button type="submit" class="btn">حفظ</button>
                <a class="btn btn-quiet" href="<?= h(url($route)) ?>">تراجع</a>
              </form>
            <?php else: ?>
              <?= h($r['name']) ?>
            <?php endif; ?>
          </td>
          <td class="num" data-label="<?= h('مقاسات متاحة') ?>"><?= h(fmt_int((string) $r['sizes'])) ?></td>
          <td class="num" data-label="<?= h('القطع المتاحة') ?>"><?= h(fmt_int((string) $r['qty'])) ?></td>
          <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume((string) $r['volume'])) ?></td>
          <td class="cell-actions">
            <div class="row-actions">
              <a href="<?= h(url('inventory', [$kind === 'warehouse' ? 'warehouse' : 'type' => $rid])) ?>">الأرصدة</a>
              <?php if ($manage && $editId !== $rid): ?><a href="<?= h(url($route, ['edit' => $rid])) ?>">تعديل الاسم</a><?php endif; ?>
              <?php if ($manage && (int) $r['used'] === 0): ?><a class="danger-link" href="<?= h(url($route, ['delete' => $rid])) ?>">حذف</a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($manage): ?>
  <p class="muted">
    <?= $kind === 'warehouse'
        ? 'يمكن حذف المخزن فقط إذا لم تُسجل عليه أي حركة، ويجب أن يبقى مخزن واحد على الأقل.'
        : 'يمكن حذف النوع فقط إذا لم يُسجل له أي وارد.' ?>
    تعديل الاسم لا يغير الأسماء في المستندات السابقة.
  </p>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php
render_footer();
