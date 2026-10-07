<?php
defined('APP_ROOT') || exit;

if (current_user_id() > 0) {
    redirect('inventory');
}

$pdo = db();
try {
    $installed = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (PDOException $e) {
    $installed = false;
}
if (!$installed) {
    render_header('تسجيل الدخول');
    echo '<h1>النظام غير مثبت بعد</h1>';
    echo '<p>افتح صفحة <a href="install.php">التثبيت</a> لإنشاء الجداول وحساب المدير.</p>';
    render_footer();
    exit;
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $step = input($_POST, 'step');
    if ($step === 'code') {
        // الخطوة الثانية: رمز تطبيق المصادقة
        $error = two_factor_login($pdo, input($_POST, 'code'));
        if ($error === null) {
            redirect('inventory');
        }
    } elseif ($step === 'cancel') {
        two_factor_cancel();
        redirect('login');
    } else {
        $username = input($_POST, 'username');
        $error = attempt_login($pdo, $username, input($_POST, 'password'));
        if ($error === null) {
            // مع التحقق بخطوتين لم يكتمل الدخول بعد: صفحة الدخول تعرض خانة الرمز
            redirect(current_user_id() > 0 ? 'inventory' : 'login');
        }
    }
}
$pending = two_factor_pending($pendingExpired);
if ($pendingExpired && $error === null) {
    $error = 'انتهت مهلة إدخال رمز التحقق (' . fmt_int(5) . ' دقائق). أدخل اسم المستخدم وكلمة المرور مرة أخرى.';
}

render_header('تسجيل الدخول', '', 'page-login');
if ($pending !== null):
?>
<section class="login-box">
  <h1><?= h(app_setting('company_name')) ?></h1>
  <p class="muted">التحقق بخطوتين</p>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= h(url('login')) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="code">
    <div class="field">
      <label for="code">رمز التحقق من تطبيق المصادقة</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="12" autofocus aria-describedby="hint-code"<?= $error ? ' aria-invalid="true"' : '' ?>>
      <p class="hint" id="hint-code">افتح تطبيق المصادقة على هاتفك واكتب الرمز المكون من <?= h(fmt_int(6)) ?> أرقام الظاهر لهذا الحساب.</p>
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-primary">تحقق</button>
    </div>
  </form>
  <form method="post" action="<?= h(url('login')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="cancel">
    <button type="submit" class="btn btn-quiet">الرجوع إلى كلمة المرور</button>
  </form>
</section>
<?php
    render_footer();
    exit;
endif;
?>
<section class="login-box">
  <h1><?= h(app_setting('company_name')) ?></h1>
  <p class="muted">سجّل الدخول للمتابعة</p>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= h(url('login')) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">اسم المستخدم</label>
      <input type="text" id="username" name="username" value="<?= h($username) ?>" autocomplete="username" required maxlength="60" autofocus>
    </div>
    <div class="field">
      <label for="password">كلمة المرور</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required maxlength="200">
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-primary">دخول</button>
    </div>
  </form>
</section>
<?php
render_footer();
