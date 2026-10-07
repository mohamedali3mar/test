<?php
defined('APP_ROOT') || exit;

/*
 * صفحة أنواع الخشب وصفحة المخازن: نفس التصميم والقواعد. $catalogKind يحدده index.php
 * المخازن فقط: عمود الفرع، واختيار الفرع عند الإضافة، ونقل المخزن إلى فرع آخر.
 */

$kind = $catalogKind === 'warehouse' ? 'warehouse' : 'type';
$route = $kind === 'warehouse' ? 'warehouses' : 'types';
$title = $kind === 'warehouse' ? 'المخازن' : 'أنواع الخشب';
$noun = catalog_def($kind)['noun'];
$pdo = db();
$errors = [];
$newName = '';
$manage = can('catalog.manage'); // الموظف يرى القائمة فقط
// المخازن: المستخدم المقيد بفرع يرى مخازن فرعه فقط ولا يديرها. الأنواع مشتركة بين الفروع.
$scope = allowed_branch_id($pdo);
$canManage = $manage && ($kind === 'type' || $scope === null);
$branches = $kind === 'warehouse' ? catalog_all($pdo, 'branch') : [];
$newBranch = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('catalog.manage');
    verify_csrf();
    $action = input($_POST, 'action');
    try {
        if ($action === 'create') {
            $newName = input($_POST, 'name');
            $newBranch = input($_POST, 'branch_id');
            catalog_create($pdo, $kind, $newName, $kind === 'warehouse' ? (int) $newBranch : null);
            flash('success', $kind === 'warehouse' ? 'تمت إضافة المخزن.' : 'تمت إضافة النوع.');
        } elseif ($action === 'move' && $kind === 'warehouse') {
            $branchName = warehouse_move($pdo, (int) input($_POST, 'id'), (int) input($_POST, 'branch_id'));
            flash('success', sprintf('تم نقل المخزن إلى «%s». المستندات السابقة تحتفظ باسم الفرع كما سُجل وقتها.', $branchName));
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
    $stmt = $pdo->prepare(
        'SELECT w.id, w.name, w.branch_id, b.name AS branch_name,
                COALESCE(SUM(CASE WHEN s.qty_on_hand > 0 THEN 1 ELSE 0 END), 0) AS sizes,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty,
                COALESCE(SUM(s.qty_on_hand * i.piece_volume_m3), 0) AS volume,
                (SELECT COUNT(*) FROM stock s2 WHERE s2.warehouse_id = w.id)
                  + (SELECT COUNT(*) FROM documents d WHERE d.warehouse_id = w.id OR d.to_warehouse_id = w.id) AS used
         FROM warehouses w
         JOIN branches b ON b.id = w.branch_id
         LEFT JOIN stock s ON s.warehouse_id = w.id
         LEFT JOIN items i ON i.id = s.item_id'
        . ($scope !== null ? ' WHERE w.branch_id = ?' : '') . '
         GROUP BY w.id, w.name, w.branch_id, b.name ORDER BY b.name, b.id, w.name, w.id'
    );
    $stmt->execute($scope !== null ? [$scope] : []);
    $rows = $stmt->fetchAll();
} else {
    // الأرصدة من مخازن فرع المستخدم فقط، وعدد الأصناف (لمنع حذف نوع مستخدم) من كل الفروع
    $stmt = $pdo->prepare(
        'SELECT t.id, t.name,
                COUNT(DISTINCT CASE WHEN s.qty_on_hand > 0 THEN i.id END) AS sizes,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty,
                COALESCE(SUM(s.qty_on_hand * i.piece_volume_m3), 0) AS volume,
                COUNT(DISTINCT i.id) AS used
         FROM wood_types t
         LEFT JOIN items i ON i.wood_type_id = t.id
         LEFT JOIN stock s ON s.item_id = i.id'
        . ($scope !== null ? ' AND s.warehouse_id IN (SELECT id FROM warehouses WHERE branch_id = ?)' : '') . '
         GROUP BY t.id, t.name ORDER BY t.name, t.id'
    );
    $stmt->execute($scope !== null ? [$scope] : []);
    $rows = $stmt->fetchAll();
}
$editId = $canManage ? (int) input($_GET, 'edit') : 0;
$deleteId = $canManage ? (int) input($_GET, 'delete') : 0;
$canMove = $kind === 'warehouse' && $canManage && count($branches) > 1;
$moveId = $canMove ? (int) input($_GET, 'move') : 0;
$deleteRow = null;
$moveRow = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $deleteId && (int) $r['used'] === 0) {
        $deleteRow = $r;
    }
    if ((int) $r['id'] === $moveId) {
        $moveRow = $r;
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

<?php if ($moveRow): ?>
  <section class="panel" aria-labelledby="move-title">
    <h2 id="move-title">نقل المخزن «<?= h($moveRow['name']) ?>» إلى فرع آخر</h2>
    <p>الفرع الحالي: <?= h($moveRow['branch_name']) ?>. النقل مسموح حتى لو كان على المخزن رصيد: تظهر أرصدته تحت الفرع الجديد، والمستندات السابقة تحتفظ باسم الفرع كما سُجل وقتها.</p>
    <form method="post" action="<?= h(url($route, ['move' => (int) $moveRow['id']])) ?>" class="form-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="move">
      <input type="hidden" name="id" value="<?= (int) $moveRow['id'] ?>">
      <div class="field">
        <label for="move-branch">الفرع الجديد</label>
        <select id="move-branch" name="branch_id" required>
          <?= options_html(array_values(array_filter($branches, fn ($b) => (int) $b['id'] !== (int) $moveRow['branch_id'])), '', 'اختر الفرع') ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">نقل المخزن</button>
      <a class="btn btn-quiet" href="<?= h(url($route)) ?>">تراجع</a>
    </form>
  </section>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="section">
  <h2>إضافة <?= h($kind === 'warehouse' ? 'مخزن' : 'نوع') ?></h2>
  <form method="post" action="<?= h(url($route)) ?>" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="new-name">الاسم</label>
      <input type="text" id="new-name" name="name" value="<?= h($newName) ?>" maxlength="100" required>
    </div>
    <?php if ($kind === 'warehouse'): ?>
    <div class="field">
      <label for="new-branch">الفرع</label>
      <select id="new-branch" name="branch_id" required>
        <?= options_html($branches, $newBranch !== '' ? $newBranch : (string) ($branches[0]['id'] ?? '')) ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">إضافة</button>
  </form>
</section>
<?php endif; ?>

<section class="section" id="live-catalog" data-live aria-labelledby="list-title">
  <h2 id="list-title"><?= h($kind === 'warehouse' ? 'المخازن المسجلة' : 'الأنواع المسجلة') ?></h2>
  <?php if (!$rows): ?>
    <p class="empty"><?= $canManage
        ? h('لا توجد بيانات بعد. أضف أول ' . ($kind === 'warehouse' ? 'مخزن' : 'نوع') . ' من النموذج أعلاه.')
        : 'لا توجد مخازن في فرعك.' ?></p>
  <?php else: ?>
  <div class="table-wrap table-stack">
    <table>
      <caption class="visually-hidden"><?= h($title) ?></caption>
      <thead>
        <tr>
          <th scope="col">الاسم</th>
          <?php if ($kind === 'warehouse'): ?><th scope="col">الفرع</th><?php endif; ?>
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
          <?php if ($kind === 'warehouse'): ?><td data-label="الفرع"><?= h($r['branch_name']) ?></td><?php endif; ?>
          <td class="num" data-label="مقاسات متاحة"><?= h(fmt_int((string) $r['sizes'])) ?></td>
          <td class="num" data-label="القطع المتاحة"><?= h(fmt_int((string) $r['qty'])) ?></td>
          <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume((string) $r['volume'])) ?></td>
          <td class="cell-actions" data-label="إجراءات">
            <div class="row-actions">
            <a href="<?= h(url('inventory', [$kind === 'warehouse' ? 'warehouse' : 'type' => $rid])) ?>">الأرصدة</a>
            <?php if ($canManage && $editId !== $rid): ?><a href="<?= h(url($route, ['edit' => $rid])) ?>">تعديل الاسم</a><?php endif; ?>
            <?php if ($canMove && $moveId !== $rid): ?><a href="<?= h(url($route, ['move' => $rid])) ?>">تغيير الفرع</a><?php endif; ?>
            <?php if ($canManage && (int) $r['used'] === 0): ?><a class="danger-link" href="<?= h(url($route, ['delete' => $rid])) ?>">حذف</a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$canManage): ?>
  <p class="muted">تعرض الصفحة مخازن فرعك فقط. إضافة المخازن وتعديلها متاحة لمستخدم يرى كل الفروع.</p>
  <?php else: ?>
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
