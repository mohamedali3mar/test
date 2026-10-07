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
    $username = input($_POST, 'username');
    $error = attempt_login($pdo, $username, input($_POST, 'password'));
    if ($error === null) {
        redirect('inventory');
    }
}

render_header('تسجيل الدخول', '', 'page-login');
?>
<section class="login-box">
  <h1><?= h(app_setting('company_name')) ?></h1>
  <p class="muted">سجّل الدخول للمتابعة</p>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= h(url('login')) ?>" class="form">
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
