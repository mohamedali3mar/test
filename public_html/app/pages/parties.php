<?php
defined('APP_ROOT') || exit;

/* العملاء والموردون: القائمة والبحث والإضافة، والتعديل والإيقاف والحذف للمدير */

acct_require('statements.view');
$pdo = db();
$kind = input($_GET, 'kind') === 'supplier' ? 'supplier' : 'customer';
$title = PARTY_PLURALS[$kind];
$noun = PARTY_NOUNS[$kind];
$self = ['kind' => $kind];
$errors = [];
$form = [];
$editId = (int) input($_GET, 'edit');
$canManage = acct_can('parties.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');
    $id = (int) input($_POST, 'id');
    $form = string_inputs($_POST, ['name', 'phone', 'address', 'notes', 'opening_balance', 'opening_direction', 'credit_limit']);
    try {
        if ($action === 'create') {
            acct_require('parties.create');
            party_create($pdo, current_user_id(), $kind, $form);
            flash('success', sprintf('تمت إضافة %s «%s».', $noun, clean_text($form['name'])));
        } elseif ($action === 'update') {
            acct_require('parties.manage');
            party_update($pdo, current_user_id(), $id, $form);
            flash('success', 'تم حفظ التعديلات.');
        } elseif ($action === 'activate' || $action === 'deactivate') {
            acct_require('parties.manage');
            party_set_active($pdo, current_user_id(), $id, $action === 'activate');
            flash('success', $action === 'activate' ? 'تم تفعيل الحساب.' : 'تم إيقاف الحساب. يبقى في الكشوف والتقارير.');
        } elseif ($action === 'delete') {
            acct_require('parties.manage');
            party_delete($pdo, current_user_id(), $id);
            flash('success', 'تم حذف الحساب.');
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
        redirect('parties', $self);
    } catch (ValidationException $e) {
        $errors = $e->errors;
        if ($action === 'update') {
            $editId = $id;
        }
    }
}

$q = clean_text(input($_GET, 'q'));
$status = input($_GET, 'status');
$rows = parties_list($pdo, $kind, $q, $status);
$totalBalance = 0;
foreach ($rows as $r) {
    $totalBalance += money_to_piasters($r['balance']);
}
$editRow = $editId > 0 && $canManage ? party_find($pdo, $editId) : null;
if ($editRow && $editRow['kind'] !== $kind) {
    $editRow = null;
}
$creating = input($_POST, 'action') === 'create';

/** حقول نموذج الطرف (الإضافة والتعديل) */
$partyFields = function (array $values, array $errors, string $prefix) use ($kind): void {
    $id = fn (string $f) => $prefix . '-' . $f;
    $dir = $values['opening_direction'] ?? ($kind === 'customer' ? 'owes_us' : 'we_owe');
    ?>
    <div class="field">
      <label for="<?= h($id('name')) ?>">الاسم</label>
      <input type="text" id="<?= h($id('name')) ?>" name="name" maxlength="120" required value="<?= h($values['name'] ?? '') ?>"<?= field_attrs($errors, 'name') ?>>
      <?= field_error($errors, 'name') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('phone')) ?>">الهاتف <span class="optional">(اختياري)</span></label>
      <input type="tel" id="<?= h($id('phone')) ?>" name="phone" maxlength="40" value="<?= h($values['phone'] ?? '') ?>"<?= field_attrs($errors, 'phone') ?>>
      <?= field_error($errors, 'phone') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('address')) ?>">العنوان <span class="optional">(اختياري)</span></label>
      <input type="text" id="<?= h($id('address')) ?>" name="address" maxlength="200" value="<?= h($values['address'] ?? '') ?>"<?= field_attrs($errors, 'address') ?>>
      <?= field_error($errors, 'address') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('opening')) ?>">الرصيد الافتتاحي <span class="optional">(اختياري)</span></label>
      <input type="text" inputmode="decimal" autocomplete="off" id="<?= h($id('opening')) ?>" name="opening_balance" maxlength="20" value="<?= h($values['opening_balance'] ?? '') ?>"<?= field_attrs($errors, 'opening_balance', $id('opening-hint')) ?>>
      <p class="hint" id="<?= h($id('opening-hint')) ?>">المبلغ المستحق عند بدء التعامل بالنظام.</p>
      <?= field_error($errors, 'opening_balance') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('dir')) ?>">اتجاه الرصيد</label>
      <select id="<?= h($id('dir')) ?>" name="opening_direction"<?= field_attrs($errors, 'opening_direction') ?>>
        <option value="owes_us"<?= $dir === 'owes_us' ? ' selected' : '' ?>>عليه (مستحق لنا)</option>
        <option value="we_owe"<?= $dir === 'we_owe' ? ' selected' : '' ?>>له (مستحق علينا)</option>
      </select>
      <?= field_error($errors, 'opening_direction') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('limit')) ?>">حد الائتمان <span class="optional">(اختياري)</span></label>
      <input type="text" inputmode="decimal" autocomplete="off" id="<?= h($id('limit')) ?>" name="credit_limit" maxlength="20" value="<?= h($values['credit_limit'] ?? '') ?>"<?= field_attrs($errors, 'credit_limit') ?>>
      <?= field_error($errors, 'credit_limit') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('notes')) ?>">ملاحظات <span class="optional">(اختياري)</span></label>
      <textarea id="<?= h($id('notes')) ?>" name="notes" maxlength="500" rows="2"<?= field_attrs($errors, 'notes') ?>><?= h($values['notes'] ?? '') ?></textarea>
      <?= field_error($errors, 'notes') ?>
    </div>
    <?php
};

render_header($title, 'parties');
?>
<div class="page-head">
  <h1><?= h($title) ?></h1>
  <div class="page-actions">
    <a class="btn btn-quiet" href="<?= h(url('parties', ['kind' => $kind === 'customer' ? 'supplier' : 'customer'])) ?>"><?= $kind === 'customer' ? 'الموردون' : 'العملاء' ?></a>
    <a class="btn" href="<?= h(url($kind === 'customer' ? 'collect' : 'pay')) ?>"><?= $kind === 'customer' ? 'سند قبض' : 'سند صرف' ?></a>
  </div>
</div>

<?php if ($errors && !$creating && !$editRow): ?>
  <div class="alert alert-error" role="alert"><?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<?php if ($editRow):
    [$amt, $dir] = party_split_amount($kind, money_to_piasters($editRow['opening_balance']));
    $values = $errors ? $form : [
        'name' => $editRow['name'], 'phone' => $editRow['phone'], 'address' => $editRow['address'], 'notes' => $editRow['notes'],
        'opening_balance' => $amt === 0 ? '' : piasters_to_money($amt), 'opening_direction' => $dir,
        'credit_limit' => $editRow['credit_limit'] === null ? '' : (string) $editRow['credit_limit'],
    ];
?>
<section class="section" aria-labelledby="edit-title">
  <h2 id="edit-title">تعديل <?= h($noun) ?> «<?= h($editRow['name']) ?>»</h2>
  <?= errors_summary($errors) ?>
  <p class="muted">تغيير الرصيد الافتتاحي يغير الرصيد الحالي بنفس الفرق، ويُسجل في سجل المراقبة.</p>
  <form method="post" action="<?= h(url('parties', $self + ['edit' => (int) $editRow['id']])) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
    <?php $partyFields($values, $errors, 'edit'); ?>
    <div class="actions">
      <button type="submit" class="btn btn-primary" data-busy-text="جارٍ الحفظ">حفظ التعديلات</button>
      <a class="btn btn-quiet" href="<?= h(url('parties', $self)) ?>">تراجع</a>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if (acct_can('parties.create') && !$editRow): ?>
<section class="section" aria-labelledby="add-title">
  <h2 id="add-title">إضافة <?= h($kind === 'customer' ? 'عميل' : 'مورد') ?></h2>
  <?= $creating ? errors_summary($errors) : '' ?>
  <form method="post" action="<?= h(url('parties', $self)) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <?php $partyFields($creating ? $form : [], $creating ? $errors : [], 'new'); ?>
    <div class="actions">
      <button type="submit" class="btn btn-primary" data-busy-text="جارٍ الحفظ">إضافة</button>
    </div>
  </form>
</section>
<?php endif; ?>

<form method="get" action="index.php" class="filters" role="search" aria-label="بحث في <?= h($title) ?>">
  <input type="hidden" name="r" value="parties">
  <input type="hidden" name="kind" value="<?= h($kind) ?>">
  <div class="field">
    <label for="q">بحث</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="الاسم أو الهاتف">
  </div>
  <div class="field">
    <label for="status">الحالة</label>
    <select id="status" name="status">
      <option value="">الكل</option>
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>نشط</option>
      <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>موقوف</option>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-quiet" href="<?= h(url('parties', $self)) ?>">مسح البحث</a><?php endif; ?>
  </div>
</form>

<section class="section" id="live-parties" data-live aria-labelledby="list-title">
  <h2 id="list-title"><?= h($title) ?> المسجلون</h2>
  <?php if (!$rows): ?>
    <p class="empty"><?= $q !== '' || $status !== '' ? 'لا توجد نتائج مطابقة.' : 'لا يوجد ' . h($kind === 'customer' ? 'عملاء' : 'موردون') . ' بعد. أضف أول حساب من النموذج أعلاه.' ?></p>
  <?php else: ?>
  <p class="summary"><?= h(fmt_int(count($rows))) ?> حساب. صافي الأرصدة: <strong><?= h(fmt_party_balance($totalBalance, $kind)) ?></strong></p>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden"><?= h($title) ?> وأرصدتهم</caption>
      <thead>
        <tr>
          <th scope="col">الاسم</th>
          <th scope="col">الهاتف</th>
          <th scope="col" class="num">الرصيد</th>
          <th scope="col" class="num">حد الائتمان</th>
          <th scope="col">الحالة</th>
          <th scope="col">إجراءات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; $active = (int) $r['is_active'] === 1; ?>
        <tr class="<?= $active ? '' : 'row-cancelled' ?>">
          <td data-label="الاسم"><a href="<?= h(url('party_statement', ['id' => $rid])) ?>"><?= h($r['name']) ?></a></td>
          <td data-label="الهاتف" class="nowrap"><?= h(digits($r['phone'])) ?></td>
          <td data-label="الرصيد" class="num nowrap"><?= h(fmt_party_balance(money_to_piasters($r['balance']), $kind)) ?></td>
          <td data-label="حد الائتمان" class="num"><?= $r['credit_limit'] === null ? '<span class="muted">بلا حد</span>' : h(fmt_money((string) $r['credit_limit'])) ?></td>
          <td data-label="الحالة"><?= $active ? 'نشط' : '<span class="status status-cancelled">موقوف</span>' ?></td>
          <td data-label="إجراءات" class="row-actions">
            <a href="<?= h(url('party_statement', ['id' => $rid])) ?>">كشف الحساب</a>
            <?php if ($canManage): ?>
              <a href="<?= h(url('parties', $self + ['edit' => $rid])) ?>">تعديل</a>
              <form method="post" action="<?= h(url('parties', $self)) ?>" class="form-inline compact">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $rid ?>">
                <button type="submit" class="btn btn-quiet" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>"><?= $active ? 'إيقاف' : 'تفعيل' ?></button>
                <?php if ((int) $r['used'] === 0): ?>
                  <button type="submit" class="btn btn-quiet danger-link" name="action" value="delete">حذف</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted">«عليه» = مستحق لنا على الحساب، «له» = مستحق علينا للحساب. الحساب الموقوف لا يظهر في الفواتير الجديدة ويبقى في الكشوف.</p>
  <?php endif; ?>
</section>
<?php
render_footer();
