<?php
declare(strict_types=1);

/*
 * صفحة التثبيت: تنشئ الجداول وحساب المدير وأول مخزن مرة واحدة فقط، ثم تُغلق.
 * بعد التثبيت تحاول حذف نفسها. لاستعادة كلمة مرور المدير: ارفع هذا الملف مرة أخرى
 * وأنشئ ملفًا فارغًا باسم app/storage/reset.allow ثم افتح الصفحة (يُطلب مفتاح التثبيت).
 */

if (!is_file(__DIR__ . '/app/config.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>تثبيت النظام</title>'
        . '<p>انسخ الملف app/config.sample.php باسم app/config.php واكتب فيه بيانات قاعدة البيانات ومفتاح التثبيت، ثم أعد فتح هذه الصفحة. التفاصيل في دليل التثبيت.</p></html>';
    exit;
}

ob_start();
require __DIR__ . '/app/bootstrap.php';
header_remove('X-Powered-By');
block_automated_clients();
enforce_https();
start_secure_session();

const INSTALL_LOCK_FILE = APP_ROOT . '/storage/installed.lock';
const RESET_ALLOW_FILE = APP_ROOT . '/storage/reset.allow';

function install_end(): never
{
    render_footer();
    exit;
}

/** مفتاح التثبيت يجب أن يكون طويلًا ومتنوعًا حتى لا يُخمَّن */
function install_key_problem(string $key): ?string
{
    if (strlen($key) < 16) {
        return 'مفتاح التثبيت قصير. اكتب في install_key داخل ملف app/config.php نصًا عشوائيًا من 16 حرفًا على الأقل.';
    }
    if (count(array_unique(str_split($key))) < 8) {
        return 'مفتاح التثبيت ضعيف (حروف مكررة). استخدم نصًا عشوائيًا متنوعًا.';
    }
    return null;
}

function check_install_key(string $sent): bool
{
    $key = (string) (app_config()['install_key'] ?? '');
    if ($key === '' || install_key_problem($key) !== null || !hash_equals($key, $sent)) {
        sleep(2); // إبطاء محاولات التخمين
        return false;
    }
    return true;
}

render_header('تثبيت النظام', '', 'page-install');
echo '<h1>تثبيت النظام</h1>';

// 1) هل النظام مثبت؟ (يُفحص قبل أي شيء آخر، ولا يكشف أي معلومات للزائر)
$locked = is_file(INSTALL_LOCK_FILE);
$dbError = null;
$pdo = null;
try {
    $pdo = db();
    if (!$locked) {
        try {
            $locked = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1146) { // الجدول غير موجود = لم يُثبت بعد
                throw $e;
            }
        }
    }
} catch (AppConfigException $e) {
    $dbError = 'أكمل بيانات قاعدة البيانات في ملف app/config.php.';
} catch (PDOException $e) {
    error_log('[wood-install] DB: ' . $e->getMessage());
    $dbError = 'تعذر الاتصال بقاعدة البيانات. تأكد من اسم القاعدة واسم المستخدم وكلمة المرور في app/config.php.';
}

/* ---------- وضع استعادة كلمة المرور ---------- */
if ($locked) {
    if (!is_file(RESET_ALLOW_FILE) || $pdo === null) {
        http_response_code(403);
        echo '<div class="alert alert-warning" role="status">النظام مثبت بالفعل، وصفحة التثبيت مغلقة. احذف الملف install.php من الاستضافة.</div>';
        echo '<p><a class="btn" href="index.php">تسجيل الدخول</a></p>';
        install_end();
    }
    $errors = [];
    $username = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $username = clean_text(input($_POST, 'username'));
        $password = input($_POST, 'password');
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'username' => ''];
        $userId = (int) $found['id'];
        if (!check_install_key(input($_POST, 'install_key'))) {
            $errors['install_key'] = 'مفتاح التثبيت غير صحيح.';
        } elseif ($userId === 0) {
            $errors['username'] = 'اسم المستخدم غير موجود.';
        } elseif ($problem = password_problem($password, input($_POST, 'password_confirm'), $username)) {
            $errors['password'] = $problem;
        }
        if (!$errors) {
            $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            // الاستعادة توقف التحقق بخطوتين أيضًا (مثلًا عند ضياع الهاتف)
            two_factor_disable($pdo, $userId);
            $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            // قبل تحديث قاعدة البيانات لا يوجد جدول السجل، وauth_event لا يوقف الاستعادة عندها
            auth_event($pdo, 'password_reset', (string) $found['username']);
            @unlink(RESET_ALLOW_FILE);
            $selfDeleted = @unlink(__FILE__);
            echo '<div class="alert alert-success" role="status">تم تعيين كلمة المرور الجديدة، وأُوقف التحقق بخطوتين لهذا الحساب إن كان مفعلًا.</div>';
            echo '<p>' . ($selfDeleted ? 'تم حذف ملف install.php تلقائيًا.' : '<strong>مهم:</strong> احذف الملف install.php من الاستضافة الآن.')
                . (is_file(RESET_ALLOW_FILE) ? ' احذف أيضًا الملف app/storage/reset.allow.' : '') . '</p>';
            echo '<p><a class="btn btn-primary" href="index.php">تسجيل الدخول</a></p>';
            install_end();
        }
    }
    ?>
<h2>استعادة كلمة مرور المدير</h2>
<p>هذا الوضع يعمل لأن الملف app/storage/reset.allow موجود. يُطلب مفتاح التثبيت من ملف الإعدادات. الاستعادة توقف أيضًا التحقق بخطوتين للحساب، ويمكن تفعيله من جديد من الإعدادات.</p>
<?= errors_summary($errors) ?>
<form method="post" action="install.php" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <div class="field">
    <label for="install_key">مفتاح التثبيت</label>
    <input type="password" id="install_key" name="install_key" required<?= field_attrs($errors, 'install_key') ?>>
  </div>
  <div class="field">
    <label for="username">اسم المستخدم</label>
    <input type="text" id="username" name="username" value="<?= h($username) ?>" maxlength="60" required autocomplete="username"<?= field_attrs($errors, 'username') ?>>
  </div>
  <div class="field">
    <label for="password">كلمة المرور الجديدة</label>
    <input type="password" id="password" name="password" minlength="10" required autocomplete="new-password"<?= field_attrs($errors, 'password') ?>>
  </div>
  <div class="field">
    <label for="password_confirm">تأكيد كلمة المرور</label>
    <input type="password" id="password_confirm" name="password_confirm" minlength="10" required autocomplete="new-password">
  </div>
  <div class="actions"><button type="submit" class="btn btn-primary" data-busy-text="جارٍ الحفظ">تعيين كلمة المرور</button></div>
</form>
<?php
    install_end();
}

/* ---------- التثبيت لأول مرة ---------- */
$problems = [];
if ($dbError !== null) {
    $problems[] = $dbError;
}
$keyProblem = install_key_problem((string) (app_config()['install_key'] ?? ''));
if ($keyProblem !== null) {
    $problems[] = $keyProblem . ' مثال لمفتاح عشوائي يمكنك استخدامه: ' . bin2hex(random_bytes(16));
}
if (!is_writable(APP_ROOT . '/storage')) {
    $problems[] = 'المجلد app/storage غير قابل للكتابة. اضبط صلاحياته إلى 755.';
}
if ($problems) {
    echo '<div class="alert alert-error" role="alert"><p>لا يمكن متابعة التثبيت:</p><ul>';
    foreach ($problems as $p) {
        echo '<li>' . h($p) . '</li>';
    }
    echo '</ul></div>';
    install_end();
}
if (!class_exists('Normalizer')) {
    echo '<div class="alert alert-warning" role="status">إضافة intl غير مفعلة في PHP. النظام يعمل، لكن يُفضل تفعيلها من إعدادات PHP في لوحة Hostinger لتوحيد كتابة الأسماء.</div>';
}

$errors = [];
$form = ['username' => '', 'company_name' => DEFAULT_SETTINGS['company_name'], 'warehouse_name' => 'المخزن الرئيسي'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $form = string_inputs($_POST, ['username', 'company_name', 'warehouse_name']);
    if (!check_install_key(input($_POST, 'install_key'))) {
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
    try {
        [$whName, $whKey] = catalog_validate_name('warehouse', $form['warehouse_name']);
    } catch (ValidationException $e) {
        $errors['warehouse_name'] = $e->errors['name'];
    }

    if (!$errors) {
        if ((int) $pdo->query("SELECT GET_LOCK('wood_install', 10)")->fetchColumn() !== 1) {
            render_simple_error('هناك عملية تثبيت أخرى جارية. أعد المحاولة بعد قليل.', 409);
        }
        try {
            run_migrations($pdo);
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                render_simple_error('النظام مثبت بالفعل.', 403);
            }
            db_transaction($pdo, function (PDO $pdo) use ($username, $password, $company, $whName, $whKey) {
                $now = now();
                foreach (DEFAULT_SETTINGS as $name => $value) {
                    $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
                }
                save_setting($pdo, 'company_name', $company);
                save_setting($pdo, 'dummy_hash', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
                $pdo->prepare('INSERT INTO warehouses (name, name_key, created_at) VALUES (?, ?, ?)')->execute([$whName, $whKey, $now]);
                $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $now]);
                data_version_bump($pdo);
            });
            @file_put_contents(INSTALL_LOCK_FILE, 'installed ' . now() . "\n", LOCK_EX);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('wood_install')");
        }
        reset_settings_cache();
        session_regenerate_id(true);
        $_SESSION = [];
        $selfDeleted = @unlink(__FILE__);
        echo '<div class="alert alert-success" role="status">تم التثبيت وإنشاء حساب المدير و«' . h($whName) . '». صفحة التثبيت أُغلقت.</div>';
        echo '<p>' . ($selfDeleted
            ? 'تم حذف ملف install.php تلقائيًا.'
            : '<strong>مهم:</strong> احذف الملف install.php من الاستضافة الآن.') . ' يمكنك أيضًا مسح قيمة install_key من ملف الإعدادات، أو تركها لاستعادة كلمة المرور مستقبلًا.</p>';
        echo '<p><a class="btn btn-primary" href="index.php">تسجيل الدخول</a></p>';
        install_end();
    }
}
?>
<p>تنشئ هذه الصفحة جداول قاعدة البيانات وحساب المدير وأول مخزن. تعمل مرة واحدة فقط، ولا توجد كلمة مرور افتراضية.</p>
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
    <label for="warehouse_name">اسم أول مخزن</label>
    <input type="text" id="warehouse_name" name="warehouse_name" value="<?= h($form['warehouse_name']) ?>" maxlength="100" required<?= field_attrs($errors, 'warehouse_name', 'hint-wh') ?>>
    <p class="hint" id="hint-wh">يمكن إضافة مخازن أخرى بعد التثبيت.</p>
    <?= field_error($errors, 'warehouse_name') ?>
  </div>
  <div class="field">
    <label for="username">اسم مستخدم المدير</label>
    <input type="text" id="username" name="username" value="<?= h($form['username']) ?>" maxlength="60" required autocomplete="username"<?= field_attrs($errors, 'username') ?>>
    <?= field_error($errors, 'username') ?>
  </div>
  <div class="field">
    <label for="password">كلمة المرور</label>
    <input type="password" id="password" name="password" minlength="10" required autocomplete="new-password"<?= field_attrs($errors, 'password', 'hint-pw') ?>>
    <p class="hint" id="hint-pw">10 أحرف على الأقل.</p>
    <?= field_error($errors, 'password') ?>
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
install_end();
