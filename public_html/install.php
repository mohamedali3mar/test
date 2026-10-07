<?php
declare(strict_types=1);

/*
 * صفحة التثبيت: تنشئ الجداول وحساب المدير مرة واحدة فقط.
 * تُغلق تلقائيًا بعد إنشاء أول حساب، ويُنصح بحذف هذا الملف بعد التثبيت.
 */

require __DIR__ . '/app/bootstrap.php';

enforce_https();
start_secure_session();

const INSTALL_LOCK_FILE = APP_ROOT . '/storage/installed.lock';

function install_is_locked(): bool
{
    if (is_file(INSTALL_LOCK_FILE)) {
        return true;
    }
    try {
        return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (PDOException $e) {
        // الجدول غير موجود بعد: لم يتم التثبيت
        if (($e->errorInfo[1] ?? null) === 1146) {
            return false;
        }
        throw $e;
    }
}

function install_page_end(): never
{
    render_footer();
    exit;
}

ob_start(); // حتى تستطيع صفحة الخطأ استبدال المحتوى بالكامل
render_header('تثبيت النظام', '', 'page-install');
echo '<h1>تثبيت النظام</h1>';

// 1) متطلبات أساسية
$problems = [];
$key = (string) (app_config()['install_key'] ?? '');
if (strlen($key) < 16) {
    $problems[] = 'اكتب مفتاح تثبيت عشوائيًا من 16 حرفًا على الأقل في install_key داخل ملف app/config.php.';
}
if (!is_writable(APP_ROOT . '/storage')) {
    $problems[] = 'المجلد app/storage غير قابل للكتابة. اضبط صلاحياته إلى 755.';
}
try {
    db();
} catch (AppConfigException $e) {
    $problems[] = 'أكمل بيانات قاعدة البيانات في ملف app/config.php.';
} catch (PDOException $e) {
    error_log('[wood-install] DB connect failed: ' . $e->getMessage());
    $problems[] = 'تعذر الاتصال بقاعدة البيانات. تأكد من اسم القاعدة واسم المستخدم وكلمة المرور في app/config.php.';
}
if ($problems) {
    echo '<div class="alert alert-error" role="alert"><p>لا يمكن متابعة التثبيت:</p><ul>';
    foreach ($problems as $p) {
        echo '<li>' . h($p) . '</li>';
    }
    echo '</ul></div>';
    install_page_end();
}

// 2) منع إعادة التثبيت أو الاستيلاء على الموقع
if (install_is_locked()) {
    http_response_code(403);
    echo '<div class="alert alert-warning" role="status">النظام مثبت بالفعل، وصفحة التثبيت مغلقة. احذف الملف install.php من الاستضافة.</div>';
    echo '<p><a class="btn" href="index.php">تسجيل الدخول</a></p>';
    install_page_end();
}

$errors = [];
$form = ['username' => '', 'company_name' => DEFAULT_SETTINGS['company_name']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $form['username'] = input($_POST, 'username');
    $form['company_name'] = input($_POST, 'company_name');

    if (!hash_equals($key, input($_POST, 'install_key'))) {
        sleep(2);
        $errors['install_key'] = 'مفتاح التثبيت غير صحيح.';
    }
    [$username, $err] = validate_username($form['username']);
    if ($err) {
        $errors['username'] = $err;
    }
    $password = input($_POST, 'password');
    if ($username !== null && ($problem = password_problem($password, input($_POST, 'password_confirm'), $username))) {
        $errors['password'] = $problem;
    }
    $company = clean_text($form['company_name']);
    if ($company === '' || mb_strlen($company) > 120) {
        $errors['company_name'] = 'اسم الشركة مطلوب (120 حرفًا على الأكثر).';
    }

    if (!$errors) {
        $pdo = db();
        $lock = (int) $pdo->query("SELECT GET_LOCK('wood_install', 10)")->fetchColumn();
        if ($lock !== 1) {
            render_simple_error('هناك عملية تثبيت أخرى جارية. أعد المحاولة بعد قليل.', 409);
        }
        try {
            // أوامر إنشاء الجداول (تنفذ خارج المعاملة لأن MySQL ينهي المعاملة عند أوامر DDL)
            $sql = (string) file_get_contents(APP_ROOT . '/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (preg_split('/;\s*(?:\n|$)/', $sql) as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
            if (install_is_locked()) {
                render_simple_error('النظام مثبت بالفعل.', 403);
            }
            db_transaction($pdo, function (PDO $pdo) use ($username, $password, $company) {
                foreach (DEFAULT_SETTINGS as $name => $value) {
                    $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
                }
                save_setting($pdo, 'company_name', $company);
                $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), now()]);
            });
            @file_put_contents(INSTALL_LOCK_FILE, 'installed ' . now() . "\n", LOCK_EX);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('wood_install')");
        }
        session_regenerate_id(true);
        $_SESSION = [];
        echo '<div class="alert alert-success" role="status">تم التثبيت وإنشاء حساب المدير. صفحة التثبيت أُغلقت.</div>';
        echo '<p><strong>مهم:</strong> احذف الملف install.php من الاستضافة الآن، ويمكنك أيضًا مسح قيمة install_key من ملف الإعدادات.</p>';
        echo '<p><a class="btn btn-primary" href="index.php">تسجيل الدخول</a></p>';
        install_page_end();
    }
}
?>
<p>تنشئ هذه الصفحة جداول قاعدة البيانات وحساب المدير. تعمل مرة واحدة فقط.</p>
<?= errors_summary($errors) ?>
<form method="post" action="install.php" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <div class="field">
    <label for="install_key">مفتاح التثبيت (من ملف app/config.php)</label>
    <input type="password" id="install_key" name="install_key" required<?= field_attrs($errors, 'install_key') ?>>
    <?= field_error($errors, 'install_key') ?>
  </div>
  <div class="field">
    <label for="company_name">اسم الشركة</label>
    <input type="text" id="company_name" name="company_name" value="<?= h($form['company_name']) ?>" maxlength="120" required<?= field_attrs($errors, 'company_name') ?>>
    <?= field_error($errors, 'company_name') ?>
  </div>
  <div class="field">
    <label for="username">اسم مستخدم المدير</label>
    <input type="text" id="username" name="username" value="<?= h($form['username']) ?>" maxlength="60" required autocomplete="username"<?= field_attrs($errors, 'username') ?>>
    <?= field_error($errors, 'username') ?>
  </div>
  <div class="field">
    <label for="password">كلمة المرور</label>
    <input type="password" id="password" name="password" minlength="10" required autocomplete="new-password"<?= field_attrs($errors, 'password') ?>>
    <?= field_error($errors, 'password') ?>
    <p class="hint">10 أحرف على الأقل. لا توجد كلمة مرور افتراضية.</p>
  </div>
  <div class="field">
    <label for="password_confirm">تأكيد كلمة المرور</label>
    <input type="password" id="password_confirm" name="password_confirm" minlength="10" required autocomplete="new-password">
  </div>
  <div class="actions">
    <button type="submit" class="btn btn-primary" data-busy-text="جارٍ التثبيت">تثبيت</button>
  </div>
</form>
<?php
install_page_end();
