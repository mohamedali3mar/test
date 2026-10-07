<?php
defined('APP_ROOT') || exit;

/* «حسابي»: متاحة لكل مستخدم (مدير أو موظف) لإدارة حسابه هو فقط */

$pdo = db();
$pwErrors = [];
$tfaErrors = [];

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
    } elseif (str_starts_with($action, 'tfa_')) {
        $tfaErrors = two_factor_settings_post($pdo, $action);
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
$tfaSupported = two_factor_supported($pdo);
$tfaEnabled = $tfaSupported && two_factor_enabled($pdo, current_user_id());
$tfaSecret = (!$tfaEnabled && is_string($_SESSION['tfa_setup'] ?? null)) ? $_SESSION['tfa_setup'] : '';
?>
<section class="section" id="two-factor" aria-labelledby="tfa-title">
  <h2 id="tfa-title">التحقق بخطوتين</h2>
  <?php if (!$tfaSupported): ?>
    <p>يتطلب هذا الخيار تحديث قاعدة البيانات أولًا من الزر أعلى هذه الصفحة.</p>
  <?php elseif ($tfaEnabled): ?>
    <p>التحقق بخطوتين <strong>مفعل</strong> لحسابك: بعد كلمة المرور يُطلب رمز من تطبيق المصادقة على هاتفك.</p>
    <?= errors_summary($tfaErrors) ?>
    <form method="post" action="<?= h(url('account')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tfa_disable">
      <input type="text" name="username" value="<?= h(current_username()) ?>" autocomplete="username" hidden>
      <div class="field">
        <label for="tfa_password">كلمة المرور الحالية</label>
        <input type="password" id="tfa_password" name="tfa_password" autocomplete="current-password" required<?= field_attrs($tfaErrors, 'tfa_password') ?>>
        <?= field_error($tfaErrors, 'tfa_password') ?>
      </div>
      <div class="field field-narrow">
        <label for="tfa_code">رمز التحقق من تطبيق المصادقة</label>
        <input type="text" id="tfa_code" name="tfa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required<?= field_attrs($tfaErrors, 'tfa_code', 'hint-tfa-off') ?>>
        <p class="hint" id="hint-tfa-off">الإيقاف ينهي الجلسات المفتوحة على الأجهزة الأخرى.</p>
        <?= field_error($tfaErrors, 'tfa_code') ?>
      </div>
      <div class="actions">
        <button type="submit" class="btn btn-danger">إيقاف التحقق بخطوتين</button>
      </div>
    </form>
  <?php elseif ($tfaSecret === ''): ?>
    <p>حماية إضافية اختيارية: بعد كلمة المرور يُطلب رمز من 6 أرقام يظهر في تطبيق مصادقة على هاتفك، مثل Google Authenticator أو Microsoft Authenticator.</p>
    <form method="post" action="<?= h(url('account')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tfa_start">
      <button type="submit" class="btn">بدء تفعيل التحقق بخطوتين</button>
    </form>
  <?php else: $tfaUri = totp_uri($tfaSecret, app_setting('company_name'), current_username()); ?>
    <div class="form">
      <p>افتح تطبيق المصادقة على هاتفك واختر إضافة حساب، ثم امسح الرمز التالي أو اكتب المفتاح يدويًا.</p>
      <div class="field" data-otpauth="<?= h($tfaUri) ?>" data-label="رمز QR لإضافة الحساب إلى تطبيق المصادقة" hidden></div>
      <div class="field">
        <label for="tfa_secret">المفتاح للإدخال اليدوي</label>
        <textarea id="tfa_secret" readonly dir="ltr" rows="2" aria-describedby="hint-tfa-secret"><?= h(totp_secret_groups($tfaSecret)) ?></textarea>
        <p class="hint" id="hint-tfa-secret">نوع المفتاح: حسب الوقت. لا تشارك هذا المفتاح مع أحد.</p>
      </div>
      <div class="field">
        <label for="tfa_uri">رابط الإعداد (otpauth)</label>
        <textarea id="tfa_uri" readonly dir="ltr" rows="3"><?= h($tfaUri) ?></textarea>
      </div>
    </div>
    <?= errors_summary($tfaErrors) ?>
    <form method="post" action="<?= h(url('account')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tfa_confirm">
      <div class="field field-narrow">
        <label for="tfa_code">رمز التحقق من تطبيق المصادقة</label>
        <input type="text" id="tfa_code" name="tfa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required<?= field_attrs($tfaErrors, 'tfa_code', 'hint-tfa-on') ?>>
        <p class="hint" id="hint-tfa-on">اكتب الرمز المكون من 6 أرقام الظاهر الآن في التطبيق لتأكيد التفعيل. ستنتهي الجلسات المفتوحة على الأجهزة الأخرى.</p>
        <?= field_error($tfaErrors, 'tfa_code') ?>
      </div>
      <div class="actions">
        <button type="submit" class="btn">تأكيد التفعيل</button>
      </div>
    </form>
    <form method="post" action="<?= h(url('account')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tfa_cancel">
      <button type="submit" class="btn btn-quiet">إلغاء الإعداد</button>
    </form>
    <script src="<?= h(asset('js/vendor/qrcode.js')) ?>" defer></script>
    <script src="<?= h(url('asset', ['f' => 'twofactor.js', 'v' => asset_version('js/twofactor.js')])) ?>" defer></script>
  <?php endif; ?>
</section>
<?php
render_footer();
