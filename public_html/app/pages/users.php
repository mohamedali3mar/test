<?php
defined('APP_ROOT') || exit;

/*
 * إدارة المستخدمين (للمدير فقط): الإضافة، والاسم المعروض والدور، والتعطيل والتفعيل، وتعيين كلمة مرور.
 * قواعد الأمان (لا تعطيل أو تخفيض للنفس، ومدير نشط واحد على الأقل) في lib/users.php داخل معاملات.
 */

$pdo = db();
if (!users_audit_ready($pdo)) {
    render_migration_needed('المستخدمون', 'users');
}

$createErrors = [];
$editErrors = [];
$pwErrors = [];
$createForm = ['display_name' => '', 'username' => '', 'role' => 'staff'];
$editForm = null;
$editId = (int) input($_GET, 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');
    $id = (int) input($_POST, 'id');
    try {
        if ($action === 'create') {
            $createForm = string_inputs($_POST, ['display_name', 'username', 'role']);
            user_create($pdo, current_user_id(), $_POST);
            flash('success', sprintf('تمت إضافة المستخدم %s. يدخل باسم المستخدم وكلمة المرور التي حددتها، ويمكنه تغييرها من «حسابي».', clean_text($createForm['username'])));
            redirect('users');
        } elseif ($action === 'update') {
            $editId = $id;
            $editForm = string_inputs($_POST, ['display_name', 'role', 'branch_id']);
            $changes = user_update($pdo, current_user_id(), $id, $_POST);
            flash($changes ? 'success' : 'warning', $changes ? 'تم حفظ تعديل المستخدم.' : 'لم يتغير شيء.');
            redirect('users');
        } elseif ($action === 'password') {
            $editId = $id;
            user_reset_password($pdo, current_user_id(), $id, input($_POST, 'password'), input($_POST, 'password_confirm'));
            flash('success', 'تم تعيين كلمة المرور الجديدة وانتهت جلسات المستخدم المفتوحة. أبلغه بها ليدخل ثم يغيرها من «حسابي».');
            redirect('users');
        } elseif ($action === 'disable' || $action === 'enable') {
            $editId = $id;
            $changed = user_set_active($pdo, current_user_id(), $id, $action === 'enable');
            flash($changed ? 'success' : 'warning', !$changed ? 'الحساب على هذه الحالة بالفعل.'
                : ($action === 'enable' ? 'تم تفعيل الحساب، ويمكن لصاحبه الدخول الآن.' : 'تم تعطيل الحساب وإنهاء جلساته المفتوحة. لا يمكن لصاحبه الدخول حتى يُفعَّل.'));
            redirect('users');
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
    } catch (ValidationException $e) {
        if ($action === 'create') {
            $createErrors = $e->errors;
        } elseif ($action === 'password') {
            $pwErrors = $e->errors;
        } else {
            $editErrors = $e->errors;
        }
    }
}

$users = users_all($pdo);
$editUser = null;
foreach ($users as $u) {
    if ((int) $u['id'] === $editId) {
        $editUser = $u;
    }
}
$me = current_user_id();
if ($editUser) {
    $editForm ??= ['display_name' => (string) $editUser['display_name'], 'role' => user_role($editUser),
        'branch_id' => isset($editUser['branch_id']) ? (string) $editUser['branch_id'] : ''];
    $editSelf = (int) $editUser['id'] === $me;
}

render_header('المستخدمون', 'users');
?>
<div class="page-head">
  <h1>المستخدمون</h1>
  <div class="page-actions"><a class="btn" href="#add-user-title">إضافة مستخدم</a></div>
</div>

<?php if ($editUser): $uid = (int) $editUser['id']; ?>
<section class="section edit-panel" aria-labelledby="edit-title">
  <h2 id="edit-title">تعديل المستخدم <?= h((string) $editUser['username']) ?></h2>
  <?= errors_summary($editErrors) ?>
  <form method="post" action="<?= h(url('users', ['edit' => $uid])) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= $uid ?>">
    <div class="field-row">
      <div class="field">
        <label for="edit-display-name">الاسم المعروض</label>
        <input type="text" id="edit-display-name" name="display_name" value="<?= h($editForm['display_name']) ?>" maxlength="100" required<?= field_attrs($editErrors, 'display_name') ?>>
        <?= field_error($editErrors, 'display_name') ?>
      </div>
      <div class="field">
        <label for="edit-role">الدور</label>
        <?php if ($editSelf): ?>
          <input type="hidden" name="role" value="<?= h($editForm['role']) ?>">
          <select id="edit-role" disabled aria-describedby="hint-edit-role"><option><?= h(ROLE_LABELS[$editForm['role']] ?? '') ?></option></select>
          <p class="hint" id="hint-edit-role">لا يمكنك تغيير دورك أنت. يغيره مدير آخر إذا لزم.</p>
        <?php else: ?>
          <select id="edit-role" name="role" required<?= field_attrs($editErrors, 'role', 'hint-edit-role') ?>>
            <?php foreach (ROLE_LABELS as $r => $label): ?>
              <option value="<?= h($r) ?>"<?= $editForm['role'] === $r ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint" id="hint-edit-role">المدير يملك كل الصلاحيات. الموظف يسجل الوارد والبيع والتحويل ويطبع، ولا يلغي المستندات ولا يفتح الإعدادات.</p>
          <?= field_error($editErrors, 'role') ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if (array_key_exists('branch_id', $editUser) && !$editSelf): $allBranches = catalog_all($pdo, 'branch'); ?>
      <div class="field">
        <label for="edit-branch">الفرع</label>
        <select id="edit-branch" name="branch_id"<?= field_attrs($editErrors, 'branch_id', 'hint-edit-branch') ?>>
          <option value="">كل الفروع</option>
          <?php foreach ($allBranches as $b): ?>
            <option value="<?= (int) $b['id'] ?>"<?= (string) ($editForm['branch_id'] ?? '') === (string) $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint" id="hint-edit-branch">المستخدم المقيد بفرع يرى مخازن فرعه ومستنداته فقط، ويسجل الوارد والبيع منها.</p>
        <?= field_error($editErrors, 'branch_id') ?>
      </div>
    <?php endif; ?>
    <div class="actions">
      <button type="submit" class="btn btn-primary">حفظ التعديل</button>
      <a class="btn btn-quiet" href="<?= h(url('users')) ?>">إغلاق</a>
    </div>
  </form>

  <?php if ($editSelf): ?>
    <p class="muted">لتغيير كلمة مرورك افتح <a href="<?= h(url('account')) ?>">حسابي</a>. لا يمكنك تعطيل حسابك أنت.</p>
  <?php else: ?>
    <h3>تعيين كلمة مرور جديدة</h3>
    <?= errors_summary($pwErrors) ?>
    <form method="post" action="<?= h(url('users', ['edit' => $uid])) ?>" class="form" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="id" value="<?= $uid ?>">
      <div class="field-row">
        <div class="field">
          <label for="reset-password">كلمة المرور الجديدة</label>
          <input type="password" id="reset-password" name="password" minlength="10" required autocomplete="new-password"<?= field_attrs($pwErrors, 'password', 'hint-reset') ?>>
          <p class="hint" id="hint-reset"><?= h(fmt_int(10)) ?> أحرف على الأقل. تنتهي جلسات المستخدم المفتوحة فورًا.</p>
          <?= field_error($pwErrors, 'password') ?>
        </div>
        <div class="field">
          <label for="reset-password-confirm">تأكيد كلمة المرور</label>
          <input type="password" id="reset-password-confirm" name="password_confirm" minlength="10" required autocomplete="new-password">
        </div>
      </div>
      <div class="actions">
        <button type="submit" class="btn">تعيين كلمة المرور</button>
      </div>
    </form>

    <h3><?= user_is_active($editUser) ? 'تعطيل الحساب' : 'تفعيل الحساب' ?></h3>
    <form method="post" action="<?= h(url('users', ['edit' => $uid])) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $uid ?>">
      <?php if (user_is_active($editUser)): ?>
        <p>الحساب المعطل لا يستطيع الدخول، وتنتهي جلساته المفتوحة فورًا. مستنداته وسجله يبقيان كما هما، ويمكن تفعيله لاحقًا.</p>
        <input type="hidden" name="action" value="disable">
        <button type="submit" class="btn btn-danger">تعطيل الحساب</button>
      <?php else: ?>
        <p>بعد التفعيل يستطيع صاحب الحساب الدخول بكلمة مروره الحالية.</p>
        <input type="hidden" name="action" value="enable">
        <button type="submit" class="btn">تفعيل الحساب</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="section" id="live-users" data-live aria-labelledby="users-title">
  <h2 id="users-title">الحسابات</h2>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">حسابات المستخدمين وأدوارهم وآخر ظهور</caption>
      <thead>
        <tr>
          <th scope="col">الاسم المعروض</th>
          <th scope="col">اسم المستخدم</th>
          <th scope="col">الدور</th>
          <th scope="col">الحالة</th>
          <th scope="col">آخر ظهور</th>
          <th scope="col"><span class="visually-hidden">إجراءات</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): $active = user_is_active($u); $seen = $u['last_seen_at'] ?? $u['last_login_at']; ?>
        <tr class="<?= $active ? '' : 'row-disabled' ?>">
          <td data-label="<?= h('الاسم المعروض') ?>"><?= h(user_display_name($u)) ?><?= (int) $u['id'] === $me ? ' <span class="muted">(أنت)</span>' : '' ?></td>
          <td data-label="<?= h('اسم المستخدم') ?>"><?= h((string) $u['username']) ?></td>
          <td data-label="<?= h('الدور') ?>"><?= h(ROLE_LABELS[user_role($u)]) ?></td>
          <td data-label="<?= h('الحالة') ?>"><?= $active ? 'نشط' : '<span class="status status-disabled">معطل</span>' ?></td>
          <td data-label="<?= h('آخر ظهور') ?>" class="nowrap">
            <?php if (user_is_online($u)): ?>
              <span class="status status-online">متصل الآن</span>
            <?php elseif ($seen): ?>
              <?= h(fmt_datetime((string) $seen)) ?>
            <?php else: ?>
              <span class="muted">لم يسجل الدخول</span>
            <?php endif; ?>
          </td>
          <td data-label="<?= h('إجراءات') ?>" class="row-actions">
            <a href="<?= h(url('users', ['edit' => (int) $u['id']])) ?>">تعديل</a>
            <a href="<?= h(url('monitor', ['user' => (int) $u['id']])) ?>">نشاطه</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted">لا يُحذف أي حساب حتى يبقى سجل عملياته واضحًا. الحساب الذي لم يعد مستخدمًا يُعطَّل.</p>
</section>

<section class="section" aria-labelledby="add-user-title">
  <h2 id="add-user-title">إضافة مستخدم</h2>
  <?= errors_summary($createErrors) ?>
  <form method="post" action="<?= h(url('users')) ?>" class="form" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field-row">
      <div class="field">
        <label for="display_name">الاسم المعروض</label>
        <input type="text" id="display_name" name="display_name" value="<?= h($createForm['display_name']) ?>" maxlength="100" required<?= field_attrs($createErrors, 'display_name', 'hint-display-name') ?>>
        <p class="hint" id="hint-display-name">يظهر في سجل المراقبة وفي المستندات، مثل: أحمد محمود.</p>
        <?= field_error($createErrors, 'display_name') ?>
      </div>
      <div class="field">
        <label for="username">اسم المستخدم للدخول</label>
        <input type="text" id="username" name="username" value="<?= h($createForm['username']) ?>" maxlength="60" required autocomplete="off"<?= field_attrs($createErrors, 'username', 'hint-username') ?>>
        <p class="hint" id="hint-username">من <?= h(fmt_int(3)) ?> إلى <?= h(fmt_int(60)) ?> حرفًا: حروف وأرقام و _ . - بدون مسافات.</p>
        <?= field_error($createErrors, 'username') ?>
      </div>
    </div>
    <div class="field field-narrow">
      <label for="role">الدور</label>
      <select id="role" name="role" required<?= field_attrs($createErrors, 'role', 'hint-role') ?>>
        <?php foreach (ROLE_LABELS as $r => $label): ?>
          <option value="<?= h($r) ?>"<?= $createForm['role'] === $r ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint" id="hint-role">الموظف يسجل الوارد والبيع والتحويل ويطبع. المدير يملك كل الصلاحيات.</p>
      <?= field_error($createErrors, 'role') ?>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="password">كلمة المرور</label>
        <input type="password" id="password" name="password" minlength="10" required autocomplete="new-password"<?= field_attrs($createErrors, 'password', 'hint-password') ?>>
        <p class="hint" id="hint-password"><?= h(fmt_int(10)) ?> أحرف على الأقل. يمكن للمستخدم تغييرها بعد الدخول.</p>
        <?= field_error($createErrors, 'password') ?>
      </div>
      <div class="field">
        <label for="password_confirm">تأكيد كلمة المرور</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="10" required autocomplete="new-password">
      </div>
    </div>
    <div class="actions">
      <button type="submit" class="btn<?= $editUser ? '' : ' btn-primary' ?>" data-busy-text="جارٍ الحفظ">إضافة المستخدم</button>
    </div>
  </form>
</section>
<?php
render_footer();
