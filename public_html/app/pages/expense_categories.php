<?php
defined('APP_ROOT') || exit;

/* تصنيفات المصروفات: إضافة وتغيير اسم وإيقاف (للمدير) */

acct_require('cash.manage');
$pdo = db();
$errors = [];
$newName = '';
$editId = (int) input($_GET, 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    acct_require('cash.manage');
    $action = input($_POST, 'action');
    $id = (int) input($_POST, 'id');
    try {
        if ($action === 'create') {
            $newName = input($_POST, 'name');
            expense_category_create($pdo, current_user_id(), $newName);
            flash('success', 'تمت إضافة التصنيف.');
        } elseif ($action === 'rename') {
            expense_category_rename($pdo, current_user_id(), $id, input($_POST, 'name'));
            flash('success', 'تم تعديل اسم التصنيف. المصروفات السابقة تحتفظ بالاسم القديم.');
        } elseif ($action === 'activate' || $action === 'deactivate') {
            expense_category_set_active($pdo, current_user_id(), $id, $action === 'activate');
            flash('success', $action === 'activate' ? 'تم تفعيل التصنيف.' : 'تم إيقاف التصنيف.');
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
        redirect('expense_categories');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$rows = expense_categories_all($pdo);

render_header('تصنيفات المصروفات', 'expense_categories');
?>
<div class="page-head">
  <h1>تصنيفات المصروفات</h1>
  <div class="page-actions">
    <a class="btn" href="<?= h(url('expense')) ?>">تسجيل مصروف</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<section class="section">
  <h2>إضافة تصنيف</h2>
  <form method="post" action="<?= h(url('expense_categories')) ?>" class="form-inline" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="new-name">الاسم</label>
      <input type="text" id="new-name" name="name" value="<?= h($newName) ?>" maxlength="100" required>
    </div>
    <button type="submit" class="btn btn-primary">إضافة</button>
  </form>
</section>

<section class="section" id="live-expense-categories" data-live aria-labelledby="list-title">
  <h2 id="list-title">التصنيفات المسجلة</h2>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">تصنيفات المصروفات</caption>
      <thead>
        <tr>
          <th scope="col">الاسم</th>
          <th scope="col" class="num">عدد المصروفات</th>
          <th scope="col">الحالة</th>
          <th scope="col">إجراءات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; $active = (int) $r['is_active'] === 1; ?>
        <tr class="<?= $active ? '' : 'row-cancelled' ?>">
          <td data-label="الاسم">
            <?php if ($editId === $rid): ?>
              <form method="post" action="<?= h(url('expense_categories')) ?>" class="form-inline compact" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= $rid ?>">
                <label class="visually-hidden" for="rename-<?= $rid ?>">الاسم الجديد</label>
                <input type="text" id="rename-<?= $rid ?>" name="name" value="<?= h($r['name']) ?>" maxlength="100" required>
                <button type="submit" class="btn">حفظ</button>
                <a class="btn btn-quiet" href="<?= h(url('expense_categories')) ?>">تراجع</a>
              </form>
            <?php else: ?>
              <?= h($r['name']) ?>
            <?php endif; ?>
          </td>
          <td data-label="عدد المصروفات" class="num"><?= h(fmt_int((int) $r['used'])) ?></td>
          <td data-label="الحالة"><?= $active ? 'نشط' : '<span class="status status-cancelled">موقوف</span>' ?></td>
          <td data-label="إجراءات" class="row-actions">
            <?php if ($editId !== $rid): ?><a href="<?= h(url('expense_categories', ['edit' => $rid])) ?>">تعديل الاسم</a><?php endif; ?>
            <form method="post" action="<?= h(url('expense_categories')) ?>" class="form-inline compact">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $rid ?>">
              <button type="submit" class="btn btn-quiet" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>"><?= $active ? 'إيقاف' : 'تفعيل' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted">التصنيف الموقوف لا يظهر في نموذج المصروف، ويبقى في السجل والتقارير.</p>
</section>
<?php
render_footer();
