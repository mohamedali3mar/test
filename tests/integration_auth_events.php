<?php
declare(strict_types=1);

/*
 * اختبارات سجل الدخول والأمان على قاعدة بيانات MariaDB حقيقية: الترقية 002 على قاعدة جديدة
 * وعلى قاعدة من الإصدار 1، وحقول الأحداث، وعدم توقف الدخول عند غياب الجدول، والحظر،
 * وانتهاء الجلسة، والتنظيف، وعرض السجل في صفحة الإعدادات مع التهريب.
 * التشغيل: php tests/integration_auth_events.php
 */

require __DIR__ . '/lib.php';

// عملية فرعية: تعرض صفحة الإعدادات وتطبع HTML (الصفحة تعرّف ثابتًا فلا تُحمّل مرتين في عملية واحدة)
if (($argv[1] ?? '') === 'render-settings') {
    set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
        throw new ErrorException($msg, 0, $no, $file, $line); // أي تحذير في الصفحة يُفشل الاختبار
    });
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = ['user_id' => (int) ($argv[2] ?? 1), 'username' => 'admin', 'auth_version' => 1, 'csrf' => str_repeat('a', 64)];
    require APP_ROOT . '/pages/settings.php';
    exit(0);
}

// الجلسات في CLI لا تبدأ بعد ظهور أي مخرجات، فتُحجز المخرجات حتى نهاية الاختبار
ob_start();

const SESSION_DIR = APP_ROOT . '/storage/sessions';
$sessionFilesBefore = glob(SESSION_DIR . '/sess_*') ?: [];

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
}

/** قاعدة مؤقتة منفصلة باتصال مستخدم النظام (وليس root) وبنفس وضع SQL الصارم في db() */
function scratch_database(string $suffix): PDO
{
    $name = test_db_name() . '_' . $suffix;
    if (!preg_match('/^wood_[a-z0-9_]+\z/', $name)) {
        throw new RuntimeException('Refusing to use non-test database');
    }
    $root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$name`");
    $root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $c = app_config()['db'];
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $name), $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function drop_scratch_database(string $suffix): void
{
    $name = test_db_name() . '_' . $suffix;
    if (preg_match('/^wood_[a-z0-9_]+\z/', $name)) {
        (new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', ''))->exec("DROP DATABASE IF EXISTS `$name`");
    }
}

/** @return list<array<string,mixed>> أحداث السجل بعد رقم معين بترتيب الإضافة */
function events_after(PDO $pdo, int $afterId): array
{
    $stmt = $pdo->prepare('SELECT * FROM auth_events WHERE id > ? ORDER BY id');
    $stmt->execute([$afterId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function last_event_id(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM auth_events')->fetchColumn();
}

function count_events(PDO $pdo, string $event): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_events WHERE event = ?');
    $stmt->execute([$event]);
    return (int) $stmt->fetchColumn();
}

/** ملف جلسة جاهز في مجلد جلسات النظام، ثم يُرسل معرفه ككوكي كما يفعل المتصفح */
function plant_session(array $data): string
{
    $sid = bin2hex(random_bytes(16));
    $raw = '';
    foreach ($data as $k => $v) {
        $raw .= $k . '|' . serialize($v);
    }
    file_put_contents(SESSION_DIR . '/sess_' . $sid, $raw);
    $_COOKIE['WOODSESSID'] = $sid;
    // بعد session_write_close يحتفظ PHP بالمعرف السابق ويتجاهل الكوكي، فيُضبط المعرف صراحة أيضًا
    session_id($sid);
    return $sid;
}

function render_settings(int $uid): array
{
    $proc = proc_open([PHP_BINARY, __FILE__, 'render-settings', (string) $uid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $html = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($proc), $html, $err];
}

function error_log_since(int $offset): string
{
    $file = ini_get('error_log');
    clearstatcache();
    return is_file($file) ? (string) file_get_contents($file, false, null, $offset) : '';
}

function error_log_size(): int
{
    $file = ini_get('error_log');
    clearstatcache();
    return is_file($file) ? (int) filesize($file) : 0;
}

$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

/* ---------------------------------------------------------------- */
section('الترقية 002 على قاعدة جديدة');
$pdo = fresh_database();
$uid = seed_user($pdo);
$files = migration_files();
check('ملف الترقية 002_auth_events.sql مسجل', basename($files[2] ?? '') === '002_auth_events.sql');
check('جدول auth_events أُنشئ', table_exists($pdo, 'auth_events'));
check_eq('رقم إصدار القاعدة = آخر ترقية', max(array_keys($files)), schema_version($pdo));
check('رقم إصدار القاعدة لا يقل عن 2', schema_version($pdo) >= 2);
$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM auth_events') as $c) {
    $cols[$c['Field']] = $c;
}
check_eq('الأعمدة', ['id', 'event', 'username', 'ip', 'user_agent', 'created_at'], array_keys($cols));
check_eq('قيم الحدث', "enum('login_ok','login_fail','login_locked','logout','password_changed','password_reset','session_expired')", $cols['event']['Type'] ?? null);
check('id رقم كبير موجب وتلقائي', ($cols['id']['Type'] ?? '') === 'bigint(20) unsigned' && ($cols['id']['Extra'] ?? '') === 'auto_increment');
check('user_agent افتراضيًا نص فارغ', ($cols['user_agent']['Default'] ?? null) === "''" || ($cols['user_agent']['Default'] ?? null) === '');
$idx = [];
foreach ($pdo->query('SHOW INDEX FROM auth_events') as $r) {
    $idx[$r['Key_name']][(int) $r['Seq_in_index']] = $r['Column_name'];
}
check('فهرس على created_at', in_array([1 => 'created_at'], $idx, true));
check('فهرس على (event, created_at)', in_array([1 => 'event', 2 => 'created_at'], $idx, true));
$meta = $pdo->query("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_events'")->fetch();
check_eq('InnoDB و utf8mb4', ['InnoDB', 'utf8mb4_unicode_ci'], [$meta['ENGINE'], $meta['TABLE_COLLATION']]);
check_eq('لا ترقيات معلقة', [], pending_migrations($pdo));

/* ---------------------------------------------------------------- */
section('auth_event: الحقول والتنظيف والقص');
$v0 = (int) data_version($pdo);
$mark = last_event_id($pdo);
auth_event($pdo, 'logout', 'admin');
$e = events_after($pdo, $mark);
check_eq('أُضيف صف واحد', 1, count($e));
check_eq('  الحدث', 'logout', $e[0]['event'] ?? null);
check_eq('  اسم المستخدم', 'admin', $e[0]['username'] ?? null);
check_eq('  العنوان من client_ip_key', '198.51.100.23', $e[0]['ip'] ?? null);
check_eq('  المتصفح من الترويسة', $_SERVER['HTTP_USER_AGENT'], $e[0]['user_agent'] ?? null);
check('  الوقت الآن', abs(strtotime((string) ($e[0]['created_at'] ?? '')) - time()) <= 5, (string) ($e[0]['created_at'] ?? ''));
check_eq('الحدث لا يرفع رقم إصدار البيانات (حتى لا تعيد الصفحات المفتوحة التحميل أثناء هجوم)', $v0, (int) data_version($pdo));

$mark = last_event_id($pdo);
$_SERVER['HTTP_USER_AGENT'] = str_repeat('x', 300);
auth_event($pdo, 'login_ok', 'admin');
$_SERVER['HTTP_USER_AGENT'] = str_repeat('م', 300);
auth_event($pdo, 'login_ok', 'admin');
$_SERVER['HTTP_USER_AGENT'] = "Agent\twith\nnew\u{202E}lines\x07";
auth_event($pdo, 'login_ok', 'admin');
$_SERVER['HTTP_USER_AGENT'] = "bad\xC3\x28utf8";
auth_event($pdo, 'login_ok', 'admin');
unset($_SERVER['HTTP_USER_AGENT']);
auth_event($pdo, 'login_ok', 'admin');
$_SERVER['HTTP_USER_AGENT'] = ['not', 'a', 'string'];
auth_event($pdo, 'login_ok', 'admin');
$ua = array_column(events_after($pdo, $mark), 'user_agent');
check_eq('متصفح طويل يُقص إلى 255 حرفًا', 255, mb_strlen($ua[0] ?? ''));
check_eq('القص بالحروف وليس بالبايت (عربي)', str_repeat('م', 255), $ua[1] ?? null);
check_eq('رموز التحكم والاتجاه تُحذف والمسافات تتوحد', 'Agent with newlines', $ua[2] ?? null);
check_eq('ترميز غير صالح يصبح نصًا فارغًا', '', $ua[3] ?? null);
check_eq('بدون ترويسة: نص فارغ', '', $ua[4] ?? null);
check_eq('ترويسة ليست نصًا: نص فارغ', '', $ua[5] ?? null);
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

$mark = last_event_id($pdo);
auth_event($pdo, 'login_fail', str_repeat('ب', 80));
auth_event($pdo, 'login_fail', "  evil\u{202E}user\x00  ");
$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12:1:2:3:4';
auth_event($pdo, 'login_fail', 'v6');
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
$rows = events_after($pdo, $mark);
check_eq('اسم أطول من 60 حرفًا يُقص', str_repeat('ب', 60), $rows[0]['username'] ?? null);
check_eq('الاسم يُنظف من رموز التحكم والمسافات', 'eviluser', $rows[1]['username'] ?? null);
check_eq('IPv6 يُسجل كشبكة /64 مثل تحديد المحاولات', '2001:db8:abcd:12::/64', $rows[2]['ip'] ?? null);

$mark = last_event_id($pdo);
$vBefore = data_version($pdo);
$logBefore = error_log_size();
$threw = false;
try {
    auth_event($pdo, 'hacked', 'admin');
} catch (Throwable $ex) {
    $threw = true;
}
check('حدث غير معروف لا يرمي استثناء', !$threw);
check_eq('  ولا يُسجل', [], events_after($pdo, $mark));
check_eq('  ولا يرفع رقم الإصدار', $vBefore, data_version($pdo));
check('  ويُكتب في سجل الأخطاء', str_contains(error_log_since($logBefore), 'auth_event(hacked)'));

/* ---------------------------------------------------------------- */
section('وصف المتصفح المختصر');
$agents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' => 'Chrome على Windows',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0' => 'Edge على Windows',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1' => 'Safari على iPhone',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15' => 'Safari على Mac',
    'Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0' => 'Firefox على Android',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36' => 'Samsung Internet على Android',
    'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0' => 'Firefox على Linux',
    'curl/8.5.0' => 'curl',
    'Python-urllib/3.13' => 'Python',
    '' => 'غير معروف',
    str_repeat('z', 50) => str_repeat('z', 40) . '…',
];
foreach ($agents as $raw => $expected) {
    check_eq('وصف: ' . $expected, $expected, fmt_user_agent((string) $raw));
}

/* ---------------------------------------------------------------- */
section('انتهاء الجلسة عند الخمول');
check('مجلد جلسات النظام قابل للكتابة', is_dir(SESSION_DIR) && is_writable(SESSION_DIR));
$expiredBefore = count_events($pdo, 'session_expired');
$old = time() - 3 * 3600; // مدة الخمول الافتراضية ساعتان
plant_session(['flash' => [], 'last_activity' => $old]);
start_secure_session();
check_eq('جلسة زائر قديمة (بدون دخول) لا تُسجل', $expiredBefore, count_events($pdo, 'session_expired'));
session_write_close();
plant_session(['user_id' => $uid, 'username' => 'admin', 'auth_version' => 1, 'last_activity' => time() - 60] + session_binding_values());
start_secure_session();
check('جلسة نشطة حديثة تبقى', current_user_id() === $uid);
check_eq('  ولا تُسجل', $expiredBefore, count_events($pdo, 'session_expired'));
session_write_close();
$mark = last_event_id($pdo);
$sid = plant_session(['user_id' => $uid, 'username' => 'admin', 'auth_version' => 1, 'csrf' => str_repeat('b', 64), 'last_activity' => $old]);
start_secure_session();
check('الجلسة الخاملة انتهت', current_user_id() === 0 && session_id() !== $sid && !is_file(SESSION_DIR . '/sess_' . $sid));
$rows = events_after($pdo, $mark);
check_eq('سُجل حدث انتهاء الجلسة مرة واحدة', ['session_expired'], array_column($rows, 'event'));
check_eq('  باسم مستخدم الجلسة', 'admin', $rows[0]['username'] ?? null);
check_eq('  ورسالة التنبيه باقية للمستخدم', 'warning', $_SESSION['flash'][0]['type'] ?? null);
// الجلسة الآن نشطة (فارغة)، وتُستخدم في اختبارات الدخول التالية

/* ---------------------------------------------------------------- */
section('محاولات الدخول: فشل ثم حظر ثم نجاح');
$pdo->exec('DELETE FROM login_attempts');
$mark = last_event_id($pdo);
$msgs = [];
for ($i = 0; $i < 5; $i++) {
    $msgs[] = attempt_login($pdo, 'admin', 'wrong-password-' . $i);
}
check('5 محاولات خاطئة ترفض برسالة عامة', count(array_filter($msgs, fn ($m) => $m === 'اسم المستخدم أو كلمة المرور غير صحيحة.')) === 5);
$rows = events_after($pdo, $mark);
check_eq('سُجلت 5 محاولات فاشلة', array_fill(0, 5, 'login_fail'), array_column($rows, 'event'));
check('  باسم المستخدم والعنوان', count(array_filter($rows, fn ($r) => $r['username'] === 'admin' && $r['ip'] === '198.51.100.23')) === 5);

$mark = last_event_id($pdo);
$v1 = (int) data_version($pdo);
$m6 = attempt_login($pdo, 'admin', 'wrong-password-6');
check('المحاولة السادسة محظورة', str_contains((string) $m6, 'محاولات دخول كثيرة'), (string) $m6);
check_eq('سُجل حدث الحظر', ['login_locked'], array_column(events_after($pdo, $mark), 'event'));
$mark = last_event_id($pdo);
$m7 = attempt_login($pdo, 'admin', 'Correct-Horse-9');
check('كلمة المرور الصحيحة محظورة أيضًا أثناء الحظر', str_contains((string) $m7, 'محاولات دخول كثيرة') && current_user_id() === 0);
check_eq('تكرار المحاولة أثناء الحظر خلال دقيقة لا يضيف صفًا (حماية من إغراق السجل)', [], events_after($pdo, $mark));
check_eq('  ولا يرفع رقم الإصدار', $v1, (int) data_version($pdo));
check_eq('لا دخول ناجح أثناء الحظر', 0, (int) $pdo->query("SELECT COUNT(*) FROM auth_events WHERE event = 'login_ok' AND id > $mark")->fetchColumn());
$pdo->prepare("UPDATE auth_events SET created_at = ? WHERE event = 'login_locked'")->execute([date('Y-m-d H:i:s', time() - 61)]);
attempt_login($pdo, 'admin', 'wrong-again');
check_eq('بعد دقيقة يُسجل الحظر مرة أخرى', ['login_locked'], array_column(events_after($pdo, $mark), 'event'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$mark = last_event_id($pdo);
attempt_login($pdo, 'admin', 'wrong-from-other-ip');
check_eq('عنوان آخر غير محظور: محاولة فاشلة عادية', ['login_fail'], array_column(events_after($pdo, $mark), 'event'));

// عنوان محظور بحد العنوان (20) يغير الاسم في كل طلب: يجب ألا يتجاوز صف حظر واحد في الدقيقة
$pdo->exec('DELETE FROM login_attempts');
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$mark = last_event_id($pdo);
for ($i = 0; $i < 20; $i++) {
    attempt_login($pdo, 'spray' . $i, 'x');
}
$sprayLocked = attempt_login($pdo, 'spray20', 'x');
for ($i = 21; $i < 40; $i++) {
    attempt_login($pdo, 'spray' . $i, 'x');
}
$counts = array_count_values(array_column(events_after($pdo, $mark), 'event'));
check_eq('أول 20 محاولة بأسماء مختلفة من نفس العنوان فاشلة عادية', 20, $counts['login_fail'] ?? 0);
check('ثم الحظر بحد العنوان', str_contains((string) $sprayLocked, 'محاولات دخول كثيرة'));
check_eq('تغيير الاسم في كل طلب لا يضيف صفوف حظر خلال الدقيقة', 1, $counts['login_locked'] ?? 0);
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';

$pdo->exec('DELETE FROM login_attempts');
$mark = last_event_id($pdo);
attempt_login($pdo, 'ghost_user', 'whatever-123');
attempt_login($pdo, '<script>alert(1)</script>', 'x');
attempt_login($pdo, str_repeat('ج', 75), 'x');
attempt_login($pdo, '', 'x');
attempt_login($pdo, 'admin', '');
$rows = events_after($pdo, $mark);
check_eq('اسم غير موجود يُسجل كما كُتب', 'ghost_user', $rows[0]['username'] ?? null);
check_eq('نص خبيث يُحفظ كما هو (التهريب عند العرض)', '<script>alert(1)</script>', $rows[1]['username'] ?? null);
check_eq('الاسم الطويل يُسجل بعد قصه إلى 60', str_repeat('ج', 60), $rows[2]['username'] ?? null);
check_eq('الحقول الفارغة لا تُعد محاولة', 3, count($rows));

$mark = last_event_id($pdo);
$ok = attempt_login($pdo, 'ADMIN', 'Correct-Horse-9');
check('الدخول الصحيح نجح', $ok === null && current_user_id() === $uid, (string) $ok);
$rows = events_after($pdo, $mark);
check_eq('سُجل دخول ناجح', ['login_ok'], array_column($rows, 'event'));
check_eq('  بالاسم المحفوظ وليس كما كُتب', 'admin', $rows[0]['username'] ?? null);

/* ---------------------------------------------------------------- */
section('تغيير كلمة المرور والخروج');
$mark = last_event_id($pdo);
change_password($pdo, $uid, 'Another-Horse-10');
$rows = events_after($pdo, $mark);
check_eq('سُجل تغيير كلمة المرور', ['password_changed'], array_column($rows, 'event'));
check_eq('  باسم صاحب الحساب', 'admin', $rows[0]['username'] ?? null);
check_eq('  والجلسة الحالية مستمرة بالإصدار الجديد', 2, (int) ($_SESSION['auth_version'] ?? 0));

/* ---------------------------------------------------------------- */
section('غياب الجدول (قبل تحديث قاعدة البيانات) لا يوقف الدخول');
$pdo->exec('RENAME TABLE auth_events TO auth_events_hidden');
$logBefore = error_log_size();
$vBefore = data_version($pdo);
$pdo->exec('DELETE FROM login_attempts');
$threw = null;
$failMsg = $okMsg = 'not run';
$lockMsgs = [];
try {
    auth_event($pdo, 'logout', 'admin');
    $failMsg = attempt_login($pdo, 'admin', 'wrong-password');
    for ($i = 0; $i < 5; $i++) {
        $lockMsgs[] = attempt_login($pdo, 'admin', 'wrong-password');
    }
    $pdo->exec('DELETE FROM login_attempts');
    $okMsg = attempt_login($pdo, 'admin', 'Another-Horse-10');
    change_password($pdo, $uid, 'Third-Horse-111');
} catch (Throwable $ex) {
    $threw = $ex;
}
check('لا استثناء من auth_event أو الدخول أو تغيير كلمة المرور', $threw === null, $threw ? $threw->getMessage() : '');
check('الدخول الخاطئ برسالته المعتادة', $failMsg === 'اسم المستخدم أو كلمة المرور غير صحيحة.', (string) $failMsg);
check('الحظر يعمل', str_contains((string) end($lockMsgs), 'محاولات دخول كثيرة'));
check('الدخول الصحيح يعمل', $okMsg === null && current_user_id() === $uid, (string) $okMsg);
check_eq('فشل تسجيل الحدث لا يرفع الإصدار (الزيادة الوحيدة من تغيير كلمة المرور المسجل في المراقبة)', (string) ((int) $vBefore + 1), data_version($pdo));
$log = error_log_since($logBefore);
check('الفشل يُكتب في سجل الأخطاء', str_contains($log, 'auth_event(login_ok)') && str_contains($log, "doesn't exist"));
check('سجل الأخطاء لا يحتوي كلمات المرور', !str_contains($log, 'Third-Horse') && !str_contains($log, 'wrong-password'));
check_eq('صفحة الإعدادات تعرف أن الجدول غير موجود', null, auth_events_overview($pdo));
[$code, $html, $err] = render_settings($uid);
check('صفحة الإعدادات تعمل بدون الجدول', $code === 0 && str_contains($html, 'يبدأ السجل بعد تحديث قاعدة البيانات'), trim($err));
check('  منطقة التحديث التلقائي موجودة', str_contains($html, 'id="live-auth-events" data-live'));
$pdo->exec('RENAME TABLE auth_events_hidden TO auth_events');

/* ---------------------------------------------------------------- */
section('زر «تحديث قاعدة البيانات» على قاعدة من الإصدار 1');
$v1db = scratch_database('v1');
foreach (split_sql((string) file_get_contents($files[1])) as $stmt) {
    $v1db->exec($stmt);
}
save_setting($v1db, 'schema_version', '1');
check_eq('الترقية 2 هي التالية المعلقة', 2, array_key_first(pending_migrations($v1db)));
check('الجدول غير موجود قبل التحديث', !table_exists($v1db, 'auth_events'));
check_eq('auth_events_overview تعيد null', null, auth_events_overview($v1db));
$threw = false;
try {
    auth_event($v1db, 'login_fail', 'admin', 1);
} catch (Throwable $ex) {
    $threw = true;
}
check('auth_event لا يرمي استثناء في القاعدة القديمة (مع التنظيف)', !$threw);
seed_user($v1db, 'oldadmin', 'Old-Horse-1234');
$v1db->exec('DELETE FROM login_attempts');
db_column_cache_reset(); // قاعدة أخرى في نفس العملية: أعمدة الترقيات تُفحص من جديد
check_eq('الدخول يعمل في القاعدة القديمة', null, attempt_login($v1db, 'oldadmin', 'Old-Horse-1234'));
$applied = run_migrations($v1db);
check_eq('التحديث طبق الترقية 2 أولًا', 2, $applied[0] ?? null);
check('الجدول أُنشئ', table_exists($v1db, 'auth_events'));
check_eq('رقم إصدار القاعدة بعد التحديث', max(array_keys($files)), schema_version($v1db));
check_eq('تكرار التحديث لا يفعل شيئًا', [], run_migrations($v1db));
$v1db->exec('DELETE FROM login_attempts');
attempt_login($v1db, 'oldadmin', 'wrong-pass');
check_eq('بعد التحديث تُسجل الأحداث', 'login_fail', $v1db->query('SELECT event FROM auth_events ORDER BY id DESC LIMIT 1')->fetchColumn());
$v1db = null;
drop_scratch_database('v1');

/* ---------------------------------------------------------------- */
section('حذف السجلات الأقدم من 180 يومًا');
$pdo->exec('DELETE FROM auth_events');
$ins = $pdo->prepare('INSERT INTO auth_events (event, username, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute(['login_fail', 'old181', '1.1.1.1', '', date('Y-m-d H:i:s', time() - 181 * 86400)]);
$ins->execute(['login_fail', 'old200', '1.1.1.1', '', date('Y-m-d H:i:s', time() - 200 * 86400)]);
$ins->execute(['login_ok', 'keep179', '1.1.1.1', '', date('Y-m-d H:i:s', time() - 179 * 86400)]);
auth_event($pdo, 'logout', 'never-prune', PHP_INT_MAX);
check_eq('بدون اختيار التنظيف تبقى السجلات القديمة', 4, (int) $pdo->query('SELECT COUNT(*) FROM auth_events')->fetchColumn());
auth_event($pdo, 'logout', 'admin', 1);
$names = $pdo->query('SELECT username FROM auth_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check_eq('عند اختيار التنظيف تُحذف الأقدم من 180 يومًا فقط', ['keep179', 'never-prune', 'admin'], $names);
$ins->execute(['login_fail', 'old190', '1.1.1.1', '', date('Y-m-d H:i:s', time() - 190 * 86400)]);
check_eq('auth_events_prune تعيد عدد المحذوف', 1, auth_events_prune($pdo));
check_eq('  ولا شيء بعدها', 0, auth_events_prune($pdo));
check_eq('مدة احتفاظ مخصصة', 1, auth_events_prune($pdo, 100));

/* ---------------------------------------------------------------- */
section('ملخص الصفحة وآخر الأحداث');
$pdo->exec('DELETE FROM auth_events');
$ins->execute(['login_fail', 'yesterday', '1.1.1.1', '', date('Y-m-d H:i:s', time() - 25 * 3600)]);
$ins->execute(['login_locked', 'yesterday', '1.1.1.1', '', date('Y-m-d H:i:s', time() - 25 * 3600)]);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 "><script>alert(7)</script>';
foreach (['login_fail', 'login_fail', 'login_fail', 'login_locked', 'login_ok', 'logout', 'password_changed', 'password_reset', 'session_expired'] as $ev) {
    auth_event($pdo, $ev, $ev === 'login_fail' ? '<script>alert(1)</script>' : 'admin');
}
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
$ov = auth_events_overview($pdo);
check_eq('الفاشلة خلال 24 ساعة (لا تُعد الأقدم)', 3, $ov['failed'] ?? null);
check_eq('المحظورة خلال 24 ساعة', 1, $ov['locked'] ?? null);
check_eq('الأحدث أولًا', 'session_expired', $ov['rows'][0]['event'] ?? null);
check_eq('الأقدم آخرًا', 'yesterday', end($ov['rows'])['username'] ?? null);
for ($i = 0; $i < 60; $i++) {
    $ins->execute(['logout', 'bulk' . $i, '1.1.1.1', '', date('Y-m-d H:i:s', time() - 7200 - $i)]);
}
check_eq('آخر 50 حدثًا فقط', 50, count(auth_events_overview($pdo, 50)['rows'] ?? []));

[$code, $html, $err] = render_settings($uid);
check('صفحة الإعدادات تعمل بدون تحذيرات', $code === 0 && $err === '', trim($err));
check('عنوان القسم', str_contains($html, '<h2 id="auth-log-title">سجل الدخول والأمان</h2>'));
check('منطقة التحديث التلقائي', str_contains($html, '<div id="live-auth-events" data-live>'));
foreach (AUTH_EVENT_LABELS as $label) {
    check('التسمية: ' . $label, str_contains($html, $label));
}
check('الملخص بعدد الفاشلة والمحظورة', str_contains($html, 'محاولات الدخول الفاشلة <strong>' . h(fmt_int(3)) . '</strong>')
    && str_contains($html, 'المحظورة مؤقتًا <strong>' . h(fmt_int(1)) . '</strong>'));
check('الفشل مميز بلون الخطأ الموجود', str_contains($html, '<span class="status status-empty">محاولة دخول فاشلة</span>')
    && str_contains($html, '<span class="status status-empty">دخول محظور مؤقتًا</span>'));
check('الأحداث العادية بدون تمييز', !str_contains($html, '<span class="status status-empty">دخول ناجح</span>'));
check('اسم المستخدم الخبيث مهرّب', str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)'));
check('المتصفح الخبيث مهرّب في النص والخاصية', !str_contains($html, '<script>alert(7)')
    && str_contains($html, 'title="Mozilla/5.0 &quot;&gt;&lt;script&gt;alert(7)&lt;/script&gt;"'));
check('الجدول داخل table-wrap مع caption مخفي', (bool) preg_match('#<div class="table-wrap">\s*<table>\s*<caption class="visually-hidden">#u', $html));
check('عناوين الأعمدة', str_contains($html, '<th scope="col">الحدث</th>') && str_contains($html, '<th scope="col">العنوان (IP)</th>')
    && str_contains($html, '<th scope="col">المتصفح</th>') && str_contains($html, '<th scope="col">الوقت</th>'));
check('الوقت بتنسيق fmt_datetime', str_contains($html, h(fmt_datetime(now()))) || str_contains($html, h(fmt_datetime(date('Y-m-d H:i:s', time() - 60)))));
check('لا شرطة طويلة في الصفحة', !preg_match('/[\x{2013}\x{2014}]/u', $html));
$section = (string) strstr((string) strstr($html, 'id="auth-log-title"'), '</section>', true);
check('لا ألوان أو أنماط مضمنة في القسم', !str_contains($section, 'style=') && !str_contains($section, 'color'));

/* ---------------------------------------------------------------- */
section('الخروج');
$pdo->exec('DELETE FROM login_attempts');
check_eq('دخول المدير قبل الخروج', null, attempt_login($pdo, 'admin', 'Third-Horse-111'));
$mark = last_event_id($pdo);
auth_event_db('logout', current_username());
logout();
$rows = events_after($pdo, $mark);
check_eq('سُجل الخروج باسم المستخدم قبل إنهاء الجلسة', [['logout', 'admin']], array_map(fn ($r) => [$r['event'], $r['username']], $rows));
check('الجلسة انتهت', session_status() !== PHP_SESSION_ACTIVE);

// تنظيف ملفات الجلسات التي أنشأها الاختبار فقط
foreach (array_diff(glob(SESSION_DIR . '/sess_*') ?: [], $sessionFilesBefore) as $f) {
    @unlink($f);
}
check_eq('لم تبق ملفات جلسات من الاختبار', $sessionFilesBefore, glob(SESSION_DIR . '/sess_*') ?: []);

finish();
