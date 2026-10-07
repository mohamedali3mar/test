<?php
defined('APP_ROOT') || exit;

/* «حسابي»: متاحة لكل مستخدم (مدير أو موظف) لإدارة حسابه هو فقط */

$pdo = db();
$pwErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');
    if ($action === 'password') {
        $new = input($_POST, 'new_password');
        $problem = verify_current_password($pdo, input($_POST, 'current_password'));
        if ($problem !== null) {
            $pwErrors['current_password'] = $problem;
        } elseif ($problem = password_problem($new, input($_POST, 'confirm_password'), current_username())) {
            $pwErrors['new_password'] = $problem;
        } else {
            change_password($pdo, current_user_id(), $new);
            flash('success', 'تم تغيير كلمة المرور، وانتهت الجلسات المفتوحة على الأجهزة الأخرى.');
            redirect('account');
        }
    } else {
        render_simple_error('إجراء غير معروف.', 400);
    }
}

$me = session_user_row($pdo, current_user_id());

render_header('حسابي', 'account');
?>
<h1>حسابي</h1>

<dl class="facts facts-wide">
  <div><dt>الاسم المعروض</dt><dd><?= h(user_display_name($me)) ?></dd></div>
  <div><dt>اسم المستخدم</dt><dd><?= h((string) $me['username']) ?></dd></div>
  <div><dt>الدور</dt><dd><?= h(ROLE_LABELS[user_role($me)]) ?></dd></div>
  <div><dt>آخر دخول</dt><dd><?= $me['last_login_at'] ? h(fmt_datetime($me['last_login_at'])) : '<span class="muted">لم يسجل الدخول</span>' ?></dd></div>
</dl>

<section class="section" aria-labelledby="password-title">
  <h2 id="password-title">تغيير كلمة المرور</h2>
  <?= errors_summary($pwErrors) ?>
  <form method="post" action="<?= h(url('account')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <input type="text" name="username" value="<?= h(current_username()) ?>" autocomplete="username" hidden>
    <div class="field">
      <label for="current_password">كلمة المرور الحالية</label>
      <input type="password" id="current_password" name="current_password" autocomplete="current-password" required<?= field_attrs($pwErrors, 'current_password') ?>>
      <?= field_error($pwErrors, 'current_password') ?>
    </div>
    <div class="field">
      <label for="new_password">كلمة المرور الجديدة</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="10" required<?= field_attrs($pwErrors, 'new_password', 'hint-newpw') ?>>
      <p class="hint" id="hint-newpw">10 أحرف على الأقل. ستنتهي الجلسات المفتوحة على الأجهزة الأخرى.</p>
      <?= field_error($pwErrors, 'new_password') ?>
    </div>
    <div class="field">
      <label for="confirm_password">تأكيد كلمة المرور الجديدة</label>
      <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="10" required>
    </div>
    <div class="actions">
      <button type="submit" class="btn">تغيير كلمة المرور</button>
    </div>
  </form>
</section>

<?php
/*
 * مكان قسم «التحقق بخطوتين» (TOTP) الخاص بكل مستخدم: يُضاف هنا بعد قسم كلمة المرور،
 * بنفس النمط (section مع h2، والنموذج يرسل إلى url('account') مع action خاص به ويُعالج أعلى الصفحة).
 * الصفحة متاحة لكل الأدوار بصلاحية account.manage، فكل مستخدم يدير التحقق بخطوتين لحسابه هو.
 */
?>
<?php
render_footer();
