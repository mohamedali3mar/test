<?php
declare(strict_types=1);

/*
 * اختبارات طبقات الحماية على قاعدة بيانات حقيقية: فلتر برامج الأتمتة، و Sec-Fetch-Site، ومفتاح العنوان،
 * وحدود محاولات الدخول (المحاولات المرفوضة لا تُحسب)، والتجزئة الوهمية، وربط الجلسة ومدتها القصوى،
 * وخطوة رمز التحقق عند الدخول ورفض إعادة استخدامه.
 * التشغيل: WOOD_TEST_DB=wood_xxx php tests/unit_security.php
 */

require __DIR__ . '/lib.php';

// جلسة PHP حقيقية في CLI (قبل أي مخرجات) حتى تعمل session_regenerate_id كما في الموقع
$sessDir = __DIR__ . '/output/sessions';
@mkdir($sessDir, 0700, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.save_path', $sessDir);
session_start();
// المخرجات تُجمع حتى النهاية: في CLI أي مخرجات تُعد «ترويسات أُرسلت» فتفشل session_regenerate_id
ob_start();

/** رمز من 6 أرقام لا يطابق أي خطوة مقبولة الآن */
function wrong_code(string $secret): string
{
    $valid = [];
    for ($d = -2; $d <= 2; $d++) {
        $valid[] = totp_code($secret, totp_step() + $d);
    }
    for ($n = 0; in_array(sprintf('%06d', $n), $valid, true); $n++) {
    }
    return sprintf('%06d', $n);
}

const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
$_SERVER['HTTP_USER_AGENT'] = BROWSER_UA;
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

/* ---------------------------------------------------------------- */
section('فلتر برامج الأتمتة (User-Agent)');
$blocked = [
    'curl/8.5.0', 'Wget/1.21.4', 'python-requests/2.31.0', 'Python-urllib/3.12', 'Python/3.12 aiohttp/3.9.1', 'HTTPie/3.2.2',
    'libwww-perl/6.72', 'Go-http-client/1.1', 'okhttp/4.12.0', 'Java/17.0.2', 'Apache-HttpClient/4.5.14 (Java/17)',
    'PostmanRuntime/7.36.0', 'insomnia/8.4.5', 'sqlmap/1.7.12#stable (https://sqlmap.org)', 'Mozilla/5.00 (Nikto/2.5.0)',
    'Mozilla/5.0 (compatible; Nmap Scripting Engine; https://nmap.org/book/nse.html)', 'masscan/1.3', 'Mozilla/5.0 zgrab/0.x',
    'Mozilla/5.0 (Windows NT 10.0) Nuclei - Open-source project (github.com/projectdiscovery/nuclei)', 'WPScan v3.8.25',
    'DirBuster-1.0-RC1', 'gobuster/3.6', 'Fuzz Faster U Fool v2.1.0', 'ffuf/2.1.0', 'Scrapy/2.11.0 (+https://scrapy.org)',
    'python-httpx/0.26.0', 'node-fetch/1.0 (+https://github.com/bitinn/node-fetch)', 'axios/1.6.2', 'CURL/7.0', '', '   ',
];
foreach ($blocked as $ua) {
    check('محظور: ' . ($ua === '' ? '(فارغ)' : $ua), is_automated_client($ua));
}
$browsers = [
    'Chrome' => BROWSER_UA,
    'Safari macOS' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Safari iOS' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Chrome iOS' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1',
    'Firefox' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
    'Firefox Android' => 'Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0',
    'Edge' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
    'Samsung Internet' => 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
    'Chrome Android' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
    'HeadlessChrome (Playwright)' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0.6668.29 Safari/537.36',
];
foreach ($browsers as $name => $ua) {
    check('مسموح: ' . $name, !is_automated_client($ua));
}

section('طلبات POST من مواقع أخرى (Sec-Fetch-Site)');
foreach ([['same-origin', false], ['none', false], ['Same-Origin', false], ['same-site', true], ['cross-site', true], ['', true]] as [$v, $expected]) {
    $_SERVER['HTTP_SEC_FETCH_SITE'] = $v;
    check('Sec-Fetch-Site=' . json_encode($v) . ($expected ? ' مرفوض' : ' مقبول'), is_cross_site_request() === $expected);
}
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
check('بدون الترويسة: يعتمد على رمز CSRF وحده', !is_cross_site_request());

/* ---------------------------------------------------------------- */
section('مفتاح العنوان لحدود المحاولات');
$ipKey = function (string $ip): string {
    $_SERVER['REMOTE_ADDR'] = $ip;
    return client_ip_key();
};
check_eq('IPv4 كما هو', '198.51.100.7', $ipKey('198.51.100.7'));
check_eq('IPv4 بصيغة IPv6 يُعامل كـ IPv4', '198.51.100.7', $ipKey('::ffff:198.51.100.7'));
check('عنوانا IPv4 مختلفان بصيغة IPv6 لا يشتركان في مفتاح واحد', $ipKey('::ffff:1.2.3.4') !== $ipKey('::ffff:5.6.7.8'));
check_eq('IPv6 يُجمع حسب الشبكة /64', '2001:db8:1:2::/64', $ipKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
check_eq('::1 ليس IPv4', '::/64', $ipKey('::1'));
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

/* ---------------------------------------------------------------- */
$pdo = fresh_database();
db_column_cache_reset();
$uid = seed_user($pdo);

section('الترقية 003');
check('أعمدة التحقق بخطوتين موجودة', two_factor_supported($pdo));
check('عمود blocked في login_attempts', db_has_column($pdo, 'login_attempts', 'blocked'));
check('إصدار قاعدة البيانات 3 أو أكثر', schema_version($pdo) >= 3);
check_eq('ملف الترقية = أمران', 2, count(split_sql((string) file_get_contents(APP_ROOT . '/migrations/003_two_factor.sql'))));
check('التحقق بخطوتين غير مفعل افتراضيًا', !two_factor_enabled($pdo, $uid));

section('حدود المحاولات: المرفوضة لا تُحسب ولا يحبس عنوان واحد الحساب');
$attempt = function (string $ip, string $user = 'admin') use ($pdo): int {
    $_SERVER['REMOTE_ADDR'] = $ip;
    return throttle_register($pdo, $user);
};
$results = [];
for ($i = 0; $i < 6; $i++) {
    $results[] = $attempt('198.51.100.1') > 0;
}
check('5 محاولات مسموحة ثم الحظر في السادسة (نفس الاسم والعنوان)', $results === [false, false, false, false, false, true], json_encode($results));
for ($i = 0; $i < 200; $i++) {
    $attempt('198.51.100.1');
}
check('200 محاولة مرفوضة بعدها: كلها مُعلَّمة blocked = 1', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip = '198.51.100.1' AND blocked = 1")->fetchColumn() === 201);
check('المحسوب من هذا العنوان 5 فقط', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip = '198.51.100.1' AND blocked = 0")->fetchColumn() === 5);
check('المدير يدخل من عنوان آخر رغم 206 محاولات من عنوان المهاجم', $attempt('192.0.2.50') === 0);
check('المهاجم ما زال محظورًا', $attempt('198.51.100.1') > 0);
// المحاولات المسموحة خرجت من النافذة، والمرفوضة الحديثة لا تمد الحظر
$pdo->prepare("UPDATE login_attempts SET attempted_at = ? WHERE ip = '198.51.100.1' AND blocked = 0")->execute([date('Y-m-d H:i:s', time() - 16 * 60)]);
check('الحظر لا يمتد بالمحاولات المرفوضة: ينتهي بعد 15 دقيقة من أول محاولة محسوبة', $attempt('198.51.100.1') === 0);

$pdo->exec('DELETE FROM login_attempts');
$perIp = [];
for ($i = 0; $i < 21; $i++) {
    $perIp[] = $attempt('198.51.100.2', 'user' . $i) > 0;
}
check('حد العنوان: 20 اسمًا مختلفًا ثم الحظر', array_slice($perIp, 0, 20) === array_fill(0, 20, false) && $perIp[20] === true, json_encode($perIp));
check('حد العنوان يبقى بعد محاولات مرفوضة أخرى', $attempt('198.51.100.2', 'another') > 0);

$pdo->exec('DELETE FROM login_attempts');
for ($n = 1; $n <= 20; $n++) {
    for ($k = 0; $k < 5; $k++) {
        $attempt("10.0.0.$n");
    }
}
check('حد الاسم من كل العناوين (هجوم موزع): 100 محاولة فعلية من 20 عنوانًا ثم الحظر', $attempt('10.0.1.1') > 0);
check('  المحاولة المرفوضة لم تُحسب', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE blocked = 0")->fetchColumn() === 100);

section('حدود المحاولات قبل تطبيق الترقية 003 (بدون عمود blocked)');
$pdo->exec('DELETE FROM login_attempts');
$pdo->exec('ALTER TABLE login_attempts DROP COLUMN blocked');
db_column_cache_reset();
$old = [];
for ($i = 0; $i < 6; $i++) {
    $old[] = $attempt('198.51.100.3') > 0;
}
check('تعمل بدون أخطاء بنفس الحدود', $old === [false, false, false, false, false, true], json_encode($old));
$pdo->exec('ALTER TABLE login_attempts ADD COLUMN blocked TINYINT(1) NOT NULL DEFAULT 0');
$pdo->exec('DELETE FROM login_attempts');
db_column_cache_reset();
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

/* ---------------------------------------------------------------- */
section('التجزئة الوهمية بنفس تكلفة التجزئات الحقيقية');
$weak = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
save_setting($pdo, 'dummy_hash', $weak);
reset_settings_cache();
$fresh = dummy_password_hash();
check('تكلفة قديمة: تُستبدل بتجزئة بالتكلفة الافتراضية الحالية', $fresh !== $weak && !password_needs_rehash($fresh, PASSWORD_DEFAULT));
reset_settings_cache();
check('  وتُحفظ في الإعدادات', app_setting('dummy_hash') === $fresh);
check('  الاستدعاء التالي يعيد نفس التجزئة', dummy_password_hash() === $fresh);
$pdo->exec("DELETE FROM settings WHERE name = 'dummy_hash'");
reset_settings_cache();
check('بدون إعداد: التجزئة الاحتياطية صالحة', str_starts_with(dummy_password_hash(), '$2y$'));

/* ---------------------------------------------------------------- */
section('الدخول بدون تحقق بخطوتين: نفس السلوك مع ربط الجلسة');
$_SESSION = [];
check('كلمة مرور خاطئة', attempt_login($pdo, 'admin', 'wrong-password') !== null);
check('كلمة مرور صحيحة: دخول مباشر', attempt_login($pdo, 'admin', 'Correct-Horse-9') === null && current_user_id() === $uid);
$normalKeys = array_keys($_SESSION);
sort($normalKeys);
check_eq('مفاتيح الجلسة', ['auth_version', 'csrf', 'last_activity', 'login_at', 'ua_hash', 'user_id', 'username'], $normalKeys);

section('ربط الجلسة بالمتصفح ومدتها القصوى');
$loginSession = $_SESSION;
enforce_session_binding();
check('نفس المتصفح: الجلسة مستمرة', current_user_id() === $uid);
$_SERVER['HTTP_USER_AGENT'] = $browsers['Firefox'];
enforce_session_binding();
check('متصفح مختلف: الجلسة حُذفت مع رسالة', current_user_id() === 0 && str_contains((string) ($_SESSION['flash'][0]['text'] ?? ''), 'متصفح مختلف'));
$_SERVER['HTTP_USER_AGENT'] = BROWSER_UA;
$_SESSION = $loginSession;
$_SESSION['login_at'] = time() - 11 * 3600;
enforce_session_binding();
check('بعد 11 ساعة (الحد الافتراضي 12): مستمرة', current_user_id() === $uid);
$_SESSION['login_at'] = time() - 12 * 3600 - 5;
enforce_session_binding();
check('بعد 12 ساعة: انتهت حتى مع النشاط', current_user_id() === 0 && str_contains((string) ($_SESSION['flash'][0]['text'] ?? ''), 'ساعة من تسجيل الدخول'));
$GLOBALS['APP_CONFIG']['session_absolute_hours'] = 1;
$_SESSION = $loginSession;
$_SESSION['login_at'] = time() - 3700;
enforce_session_binding();
check('session_absolute_hours = 1 من الإعدادات', current_user_id() === 0);
unset($GLOBALS['APP_CONFIG']['session_absolute_hours']);
$_SESSION = $loginSession;
unset($_SESSION['ua_hash'], $_SESSION['login_at']);
enforce_session_binding();
check('جلسة قديمة بدون بصمة (قبل التحديث): يُطلب الدخول مرة واحدة', current_user_id() === 0);

/* ---------------------------------------------------------------- */
section('الدخول مع التحقق بخطوتين');
$secret = totp_new_secret();
$pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = NULL WHERE id = ?')->execute([$secret, $uid]);
$pdo->exec('DELETE FROM login_attempts');
$_SESSION = [];
check('كلمة المرور الصحيحة لا تكمل الدخول', attempt_login($pdo, 'admin', 'Correct-Horse-9') === null && current_user_id() === 0);
$p = two_factor_pending();
check('حالة مؤقتة: المستخدم ورقم الإصدار والمهلة 5 دقائق', $p !== null && $p['user_id'] === $uid && $p['expires'] > time() + 290 && $p['expires'] <= time() + 300);
check('محاولة كلمة المرور لم تُمسح من حدود المحاولات', (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === 1);
$err = two_factor_login($pdo, wrong_code($secret));
check('رمز خاطئ مرفوض والحالة المؤقتة باقية', $err !== null && str_contains($err, 'غير صحيح') && two_factor_pending() !== null && current_user_id() === 0);
check('  الرمز الخاطئ حُسب ضمن حدود المحاولات', (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === 2);
$codeStep = totp_step();
$code = totp_code($secret, $codeStep);
check('الرمز الصحيح يكمل الدخول', two_factor_login($pdo, $code) === null && current_user_id() === $uid && two_factor_pending() === null);
$tfaKeys = array_keys($_SESSION);
sort($tfaKeys);
check_eq('نفس مفاتيح الجلسة كالدخول العادي', $normalKeys, $tfaKeys);
check('  مُسحت المحاولات بعد اكتمال الدخول', (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === 0);
check('  آخر خطوة مستخدمة حُفظت', (int) $pdo->query("SELECT totp_last_step FROM users WHERE id = $uid")->fetchColumn() === $codeStep);

$_SESSION = [];
attempt_login($pdo, 'admin', 'Correct-Horse-9');
$err = two_factor_login($pdo, $code);
check('إعادة استخدام نفس الرمز مرفوضة', $err !== null && current_user_id() === 0);
check('رمز الخطوة التالية مقبول', two_factor_login($pdo, totp_code($secret, totp_step() + 1)) === null && current_user_id() === $uid);
check('two_factor_consume: نفس الخطوة لا تُستخدم مرتين', !two_factor_consume($pdo, $uid, totp_code($secret, totp_step() + 1)));

$_SESSION = [];
attempt_login($pdo, 'admin', 'Correct-Horse-9');
$_SESSION['tfa_pending']['expires'] = time() - 1;
$err = two_factor_login($pdo, totp_code($secret, totp_step()));
check('بعد انتهاء المهلة: رجوع لخطوة كلمة المرور برسالة', $err !== null && str_contains($err, 'انتهت مهلة') && two_factor_pending() === null && current_user_id() === 0);
$err = two_factor_login($pdo, '123456');
check('بدون حالة مؤقتة: نفس الرسالة', $err !== null && str_contains($err, 'انتهت مهلة'));

attempt_login($pdo, 'admin', 'Correct-Horse-9');
$pdo->exec("UPDATE users SET auth_version = auth_version + 1 WHERE id = $uid");
$err = two_factor_login($pdo, totp_code($secret, totp_step() + 1));
check('تغير رقم الإصدار (مثل تغيير كلمة المرور) أثناء الانتظار: رجوع لكلمة المرور', $err !== null && two_factor_pending() === null && current_user_id() === 0);

attempt_login($pdo, 'admin', 'Correct-Horse-9');
$_SERVER['HTTP_USER_AGENT'] = $browsers['Firefox'];
check('الحالة المؤقتة مربوطة بنفس المتصفح', two_factor_pending() === null);
$_SERVER['HTTP_USER_AGENT'] = BROWSER_UA;

section('خطوة الرمز ضمن حدود المحاولات');
$pdo->exec('DELETE FROM login_attempts');
$_SESSION = [];
attempt_login($pdo, 'admin', 'Correct-Horse-9');
$msgs = [];
for ($i = 0; $i < 5; $i++) {
    $msgs[] = (string) two_factor_login($pdo, wrong_code($secret));
}
check('كلمة المرور + 4 رموز خاطئة ثم الحظر', str_contains($msgs[4], 'محاولات دخول كثيرة') && !str_contains($msgs[3], 'محاولات دخول كثيرة'), json_encode($msgs, JSON_UNESCAPED_UNICODE));
check('  حتى الرمز الصحيح محظور أثناء الحظر', str_contains((string) two_factor_login($pdo, totp_code($secret, totp_step() + 1)), 'محاولات دخول كثيرة'));
$pdo->exec('DELETE FROM login_attempts');

section('قبل تطبيق الترقية 003: الدخول يعمل والتحقق بخطوتين غير مفعل');
$pdo->exec('ALTER TABLE users DROP COLUMN totp_secret, DROP COLUMN totp_enabled, DROP COLUMN totp_last_step');
db_column_cache_reset();
$_SESSION = [];
check('two_factor_supported = false', !two_factor_supported($pdo) && !two_factor_enabled($pdo, $uid));
check('الدخول يكتمل بكلمة المرور فقط بدون أخطاء', attempt_login($pdo, 'admin', 'Correct-Horse-9') === null && current_user_id() === $uid);
two_factor_disable($pdo, $uid);
check('two_factor_disable لا يفشل بدون الأعمدة', true);

/* ---------------------------------------------------------------- */
section('أدوات السكربتات المحمية');
check('ETag مطابق', etag_matches('"abc-12"', '"abc-12"'));
check('ETag ضعيف W/ وقائمة', etag_matches('"x", W/"abc-12"', '"abc-12"'));
check('ETag *', etag_matches('*', '"abc-12"'));
check('ETag مختلف', !etag_matches('"abc-13"', '"abc-12"') && !etag_matches('', '"abc-12"'));
check('القائمة البيضاء: app.js و twofactor.js فقط', array_keys(PROTECTED_SCRIPTS) === ['app.js', 'twofactor.js']);
check('رقم النسخة يتضمن وقت تعديل الملف', asset_version('js/app.js') === APP_VERSION . '-' . filemtime(dirname(APP_ROOT) . '/assets/js/app.js'));
check('رابط CSS يتضمن رقم النسخة بوقت التعديل', asset('css/app.css') === 'assets/css/app.css?v=' . rawurlencode(APP_VERSION . '-' . filemtime(dirname(APP_ROOT) . '/assets/css/app.css')));

session_destroy();
finish();
