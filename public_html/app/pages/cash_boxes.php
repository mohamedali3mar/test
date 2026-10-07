<?php
defined('APP_ROOT') || exit;

/* الخزائن: القائمة بالأرصدة، والإضافة وتغيير الاسم والإيقاف لمن يملك صلاحية cash.manage */

acct_require('statements.view');
$pdo = db();
$errors = [];
$newName = '';
$newOpening = '';
$canManage = acct_can('cash.manage');
$editId = (int) input($_GET, 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    acct_require('cash.manage');
    $action = input($_POST, 'action');
    $id = (int) input($_POST, 'id');
    try {
        if ($action === 'create') {
            $newName = input($_POST, 'name');
            $newOpening = input($_POST, 'opening_balance');
            cash_box_create($pdo, current_user_id(), $newName, $newOpening);
            flash('success', 'تمت إضافة الخزنة.');
        } elseif ($action === 'rename') {
            cash_box_rename($pdo, current_user_id(), $id, input($_POST, 'name'));
            flash('success', 'تم تعديل اسم الخزنة. السندات السابقة تحتفظ بالاسم القديم.');
        } elseif ($action === 'activate' || $action === 'deactivate') {
            cash_box_set_active($pdo, current_user_id(), $id, $action === 'activate');
            flash('success', $action === 'activate' ? 'تم تفعيل الخزنة.' : 'تم إيقاف الخزنة. تبقى في الكشوف والتقارير.');
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
        redirect('cash_boxes');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$rows = cash_boxes_all($pdo);
$total = 0;
foreach ($rows as $r) {
    $total += money_to_piasters($r['balance']);
}
$creating = input($_POST, 'action') === 'create';

render_header('الخزائن', 'cash_boxes');
?>
<div class="page-head">
  <h1>الخزائن</h1>
  <div class="page-actions">
    <?php if (acct_can('vouchers.cash_transfer')): ?><a class="btn" href="<?= h(url('cash_transfer')) ?>">تحويل نقدية</a><?php endif; ?>
    <a class="btn btn-quiet" href="<?= h(url('vouchers')) ?>">سجل السندات</a>
  </div>
</div>

<?php if ($errors && !$creating): ?>
  <div class="alert alert-error" role="alert"><?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="section" aria-labelledby="add-title">
  <h2 id="add-title">إضافة خزنة</h2>
  <?= $creating ? errors_summary($errors) : '' ?>
  <form method="post" action="<?= h(url('cash_boxes')) ?>" class="form-inline" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="new-name">الاسم</label>
      <input type="text" id="new-name" name="name" value="<?= h($newName) ?>" maxlength="100" required<?= $creating ? field_attrs($errors, 'name') : '' ?>>
      <?= $creating ? field_error($errors, 'name') : '' ?>
    </div>
    <div class="field">
      <label for="new-opening">الرصيد الافتتاحي <span class="optional">(اختياري)</span></label>
      <input type="text" inputmode="decimal" autocomplete="off" id="new-opening" name="opening_balance" value="<?= h($newOpening) ?>" maxlength="20"<?= $creating ? field_attrs($errors, 'opening_balance') : '' ?>>
      <?= $creating ? field_error($errors, 'opening_balance') : '' ?>
    </div>
    <button type="submit" class="btn btn-primary">إضافة</button>
  </form>
  <p class="muted">الرصيد الافتتاحي يُحدد عند الإضافة فقط. بعدها يتغير الرصيد بالسندات والفواتير.</p>
</section>
<?php endif; ?>

<section class="section" id="live-cash-boxes" data-live aria-labelledby="list-title">
  <h2 id="list-title">الخزائن المسجلة</h2>
  <p class="summary">إجمالي النقدية: <strong><?= h(fmt_piasters($total)) ?> <?= h(app_setting('currency')) ?></strong></p>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">الخزائن وأرصدتها</caption>
      <thead>
        <tr>
          <th scope="col">الاسم</th>
          <th scope="col" class="num">الرصيد</th>
          <th scope="col">الحالة</th>
          <th scope="col">إجراءات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; $active = (int) $r['is_active'] === 1; ?>
        <tr class="<?= $active ? '' : 'row-cancelled' ?>">
          <td data-label="الاسم">
            <?php if ($canManage && $editId === $rid): ?>
              <form method="post" action="<?= h(url('cash_boxes')) ?>" class="form-inline compact" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= $rid ?>">
                <label class="visually-hidden" for="rename-<?= $rid ?>">الاسم الجديد</label>
                <input type="text" id="rename-<?= $rid ?>" name="name" value="<?= h($r['name']) ?>" maxlength="100" required>
                <button type="submit" class="btn">حفظ</button>
                <a class="btn btn-quiet" href="<?= h(url('cash_boxes')) ?>">تراجع</a>
              </form>
            <?php else: ?>
              <a href="<?= h(url('cash_statement', ['id' => $rid])) ?>"><?= h($r['name']) ?></a>
            <?php endif; ?>
          </td>
          <td data-label="الرصيد" class="num"><?= h(fmt_piasters(money_to_piasters($r['balance']))) ?></td>
          <td data-label="الحالة"><?= $active ? 'نشطة' : '<span class="status status-cancelled">موقوفة</span>' ?></td>
          <td data-label="إجراءات" class="row-actions">
            <a href="<?= h(url('cash_statement', ['id' => $rid])) ?>">كشف الحركة</a>
            <?php if ($canManage): ?>
              <?php if ($editId !== $rid): ?><a href="<?= h(url('cash_boxes', ['edit' => $rid])) ?>">تعديل الاسم</a><?php endif; ?>
              <form method="post" action="<?= h(url('cash_boxes')) ?>" class="form-inline compact">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $rid ?>">
                <button type="submit" class="btn btn-quiet" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>"><?= $active ? 'إيقاف' : 'تفعيل' ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php
render_footer();
