<?php
defined('APP_ROOT') || exit;

const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_PER_USER_IP = 5;    // نفس اسم المستخدم من نفس العنوان
const LOGIN_MAX_PER_IP = 20;        // أي أسماء من نفس العنوان
const LOGIN_MAX_PER_USER = 100;     // نفس الاسم من كل العناوين (هجوم موزع)، حد مرتفع حتى لا يُحبس المدير بسهولة
// Lax وليس Strict: نموذج الدخول يرسل للصفحة نفسها، والروابط المفتوحة من خارج الموقع يجب أن تبقى داخل الجلسة
const SESSION_COOKIE_SAMESITE = 'Lax';

const AUTH_EVENTS_KEEP_DAYS = 180;      // مدة الاحتفاظ بسجل الدخول والأمان
const AUTH_EVENTS_PRUNE_ONE_IN = 50;    // حذف السجلات القديمة مرة كل 50 حدثًا تقريبًا (اختيار عشوائي)
const AUTH_EVENTS_PRUNE_BATCH = 5000;   // أقصى عدد صفوف يُحذف في المرة الواحدة حتى لا يطول الطلب
const AUTH_LOCKED_REPEAT_SECONDS = 60;  // المحاولات أثناء الحظر تُسجل مرة واحدة في الدقيقة لكل عنوان

/** أحداث سجل الدخول والأمان (نفس قيم ENUM في جدول auth_events) وتسمياتها العربية */
const AUTH_EVENT_LABELS = [
    'login_ok'         => 'دخول ناجح',
    'login_fail'       => 'محاولة دخول فاشلة',
    'login_locked'     => 'دخول محظور مؤقتًا',
    'logout'           => 'تسجيل خروج',
    'password_changed' => 'تغيير كلمة المرور',
    'password_reset'   => 'استعادة كلمة المرور',
    'session_expired'  => 'انتهاء الجلسة',
];

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** يحوّل إلى https عند تفعيل force_https في الإعدادات */
function enforce_https(): void
{
    if (PHP_SAPI === 'cli' || empty(app_config()['force_https']) || is_https()) {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//')) {
        $uri = '/';
    }
    // يُفضّل النطاق المضبوط في الإعدادات بدل ترويسة Host القادمة من المتصفح
    $base = (string) (app_config()['base_url'] ?? '');
    if (preg_match('#^https://([A-Za-z0-9.\-]+(:\d{1,5})?)/?\z#', $base, $m)) {
        $host = $m[1];
    } else {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?\z/', $host)) {
            http_response_code(400);
            exit;
        }
    }
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

/** طلبات التحديث التلقائي (لا تعتبر نشاطًا من المستخدم ولا تستهلك رسائل التنبيه) */
function is_live_request(): bool
{
    return ($_SERVER['HTTP_X_LIVE'] ?? '') === '1';
}

/**
 * اسم ملف تعريف الجلسة. على https تُستخدم البادئة __Host- فيرفض المتصفح الملف إلا إذا كان Secure
 * ومساره / وبدون Domain، فلا يستطيع نطاق فرعي أو اتصال http غير مشفر زرع معرف جلسة.
 */
function session_cookie_name(): string
{
    return is_https() ? '__Host-WOODSESSID' : 'WOODSESSID';
}

function start_secure_session(bool $touch = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);
    $idle = max(5, (int) (app_config()['session_idle_minutes'] ?? 120)) * 60;
    // مجلد جلسات خاص بالنظام، حتى لا تحذف الاستضافة الجلسات قبل انتهاء مدة الخمول
    $dir = APP_ROOT . '/storage/sessions';
    if (is_dir($dir) && is_writable($dir)) {
        ini_set('session.save_path', $dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string) ($idle + 600));
    session_name(session_cookie_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // صريحًا، حتى لا يضيف إعداد session.cookie_domain في الاستضافة Domain فيرفض المتصفح ملف __Host-
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => SESSION_COOKIE_SAMESITE,
    ]);
    session_start();

    $last = (int) ($_SESSION['last_activity'] ?? 0);
    if (!empty($_SESSION['user_id']) && $last > 0 && time() - $last > $idle) {
        $expiredUser = (string) ($_SESSION['username'] ?? '');
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'انتهت الجلسة بسبب عدم النشاط. سجّل الدخول مرة أخرى.'];
        // التسجيل هنا فقط وليس في كل طلب، فلا تحتاج الطلبات العادية اتصالًا إضافيًا بقاعدة البيانات
        auth_event_db('session_expired', $expiredUser);
    }
    // الجلسة مربوطة بمتصفحها ولها مدة قصوى من وقت الدخول (security.php)
    enforce_session_binding();
    // التحديث التلقائي لا يمد عمر الجلسة، حتى يعمل الخروج عند الخمول مع بقاء الصفحة مفتوحة
    if ($touch || empty($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    }
}

function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function current_username(): string
{
    return (string) ($_SESSION['username'] ?? '');
}

function require_login(): void
{
    if (current_user_id() > 0) {
        // تأكد أن الحساب ما زال موجودًا ومفعلًا وأن كلمة المرور لم تتغير بعد بدء هذه الجلسة.
        // نفس الاستعلام يقرأ الدور، فتتحدث الصلاحيات من قاعدة البيانات في كل طلب.
        $user = session_user_row(db(), current_user_id());
        $version = $user['auth_version'] ?? false;
        if ($version !== false && (int) $version === (int) ($_SESSION['auth_version'] ?? -1) && user_is_active($user)) {
            user_session_refresh(db(), $user);
            return;
        }
        $_SESSION = [];
        session_regenerate_id(true);
        if ($user !== null && !user_is_active($user)) {
            flash('warning', 'تم إيقاف هذا الحساب. راجع مدير النظام.');
        } elseif ($version !== false) {
            flash('warning', 'تغيرت بيانات الدخول (كلمة المرور أو التحقق بخطوتين). سجّل الدخول مرة أخرى.');
        }
    }
    if (is_live_request()) {
        json_response(['error' => 'auth'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        render_simple_error('انتهت الجلسة. سجّل الدخول مرة أخرى ثم أعد المحاولة.', 403);
    }
    redirect('login');
}

/* ===================== CSRF ===================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * الرمز كما يُكتب في الصفحة: قناع عشوائي جديد مع كل عرض + (الرمز XOR القناع).
 * الصفحات مضغوطة، فلو ظهر الرمز الثابت نفسه بجوار نص يتحكم فيه المهاجم (مثل البحث)
 * أمكن استنتاجه من حجم الرد المضغوط (هجوم BREACH). القناع يجعل النص مختلفًا في كل مرة.
 */
function csrf_masked_token(): string
{
    $token = hex2bin(csrf_token());
    $mask = random_bytes(strlen($token));
    return bin2hex($mask) . bin2hex($mask ^ $token);
}

/** يعيد الرمز الأصلي من الرمز المقنّع (أو الرمز الخام كما هو)، أو '' إذا كانت الصيغة غير صحيحة */
function csrf_unmask(string $sent): string
{
    if (preg_match('/^[0-9a-f]{64}\z/', $sent)) {
        return $sent;
    }
    if (!preg_match('/^[0-9a-f]{128}\z/', $sent)) {
        return '';
    }
    $mask = hex2bin(substr($sent, 0, 64));
    $masked = hex2bin(substr($sent, 64));
    return bin2hex($mask ^ $masked);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_masked_token()) . '">';
}

/** يرفض أي طلب POST بدون رمز CSRF صحيح، أو صادر من موقع آخر حسب ترويسة Sec-Fetch-Site */
function verify_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    $sent = is_string($sent) ? csrf_unmask($sent) : '';
    if (is_cross_site_request() || $sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        render_simple_error('انتهت صلاحية النموذج أو الطلب غير صالح. ارجع للصفحة وأعد المحاولة.', 400);
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        render_simple_error('هذا الإجراء يتطلب إرسال النموذج.', 405);
    }
    verify_csrf();
}

/* ===================== تسجيل الدخول ===================== */

/** مفتاح العنوان لتحديد المحاولات. عناوين IPv6 تُجمع حسب الشبكة /64 لأن الجهاز الواحد يملك آلافها. */
function client_ip_key(): string
{
    // لا نثق في ترويسات X-Forwarded-For لأنها قابلة للتزوير
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return substr($ip, 0, 45);
    }
    // عنوان IPv4 بصيغة IPv6 (::ffff:1.2.3.4) يُعامل كعنوان IPv4 نفسه، لا كشبكة ::/64 مشتركة
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        return (string) inet_ntop(substr($bin, 12));
    }
    if (strlen($bin) === 16) {
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return (string) inet_ntop($bin);
}

/**
 * يسجل محاولة تحقق من كلمة المرور أولًا ثم يعد المحاولات (ومنها هذه)، فلا تتجاوز الطلبات
 * المتوازية الحد. يعيد عدد الدقائق المتبقية على رفع الحظر، أو 0 إذا كانت المحاولة مسموحة.
 *
 * المحاولة المرفوضة بسبب الحد تُعلَّم blocked = 1 ولا تُحسب في أي عدّ (بعد الترقية 003):
 * لم يُفحص فيها شيء، فلا داعي لعدها. بذلك لا يمد المهاجم الحظر بتكرار الطلبات، ولا يستطيع
 * عنوان واحد أن يصل وحده إلى حد اسم المستخدم من كل العناوين فيحبس المدير بلا نهاية.
 * حد الاسم يبقى عددًا للمحاولات الفعلية (وليس لعدد العناوين) لأنه يقيس ما يُخمَّن فعلًا.
 */
function throttle_register(PDO $pdo, string $username): int
{
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $pdo->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')
        ->execute([client_ip_key(), $username, now()]);
    $attemptId = (int) $pdo->lastInsertId();
    $hasBlocked = db_has_column($pdo, 'login_attempts', 'blocked');
    $counted = $hasBlocked ? ' AND blocked = 0' : '';

    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60);
    $ip = client_ip_key();
    $checks = [
        ['username = ? AND ip = ?', [$username, $ip], LOGIN_MAX_PER_USER_IP],
        ['ip = ?', [$ip], LOGIN_MAX_PER_IP],
        ['username = ?', [$username], LOGIN_MAX_PER_USER],
    ];
    $wait = 0;
    foreach ($checks as [$cond, $values, $max]) {
        $stmt = $pdo->prepare("SELECT COUNT(*), MIN(attempted_at) FROM login_attempts WHERE $cond$counted AND attempted_at > ?");
        $stmt->execute([...$values, $since]);
        [$count, $oldest] = $stmt->fetch(PDO::FETCH_NUM);
        if ((int) $count > $max && $oldest) {
            $unlockAt = strtotime((string) $oldest) + LOGIN_WINDOW_MINUTES * 60;
            $wait = max($wait, (int) ceil(($unlockAt - time()) / 60), 1);
        }
    }
    if ($wait > 0 && $hasBlocked) {
        $pdo->prepare('UPDATE login_attempts SET blocked = 1 WHERE id = ?')->execute([$attemptId]);
    }
    return $wait;
}

/** بعد نجاح التحقق: يمسح محاولات هذا الاسم من هذا العنوان فقط */
function throttle_clear(PDO $pdo, string $username): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ?')->execute([$username, client_ip_key()]);
}

/** تجزئة وهمية بنفس تكلفة التجزئات الحقيقية، تُستخدم عند عدم وجود المستخدم حتى لا يكشف الوقت ذلك */
function dummy_password_hash(): string
{
    $h = app_setting('dummy_hash');
    if (!str_starts_with($h, '$')) {
        $h = '$2y$10$X0IOxjV11qpT4H7qAHHm.uE0ZLTKFGHrOKuqOvQMsYGcop5Uk57vW';
    }
    if (!password_needs_rehash($h, PASSWORD_DEFAULT)) {
        return $h;
    }
    // ارتفعت التكلفة الافتراضية (مثل PHP 8.4): تجزئة وهمية جديدة بنفس تكلفة التجزئات الحقيقية الجديدة
    $h = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    try {
        save_setting(db(), 'dummy_hash', $h);
        $cache = &settings_cache();
        if ($cache !== null) {
            $cache['dummy_hash'] = $h;
        }
    } catch (Throwable $e) {
        error_log('[wood] dummy_hash not saved: ' . $e->getMessage());
    }
    return $h;
}

/**
 * @return string|null رسالة خطأ، أو null عند صحة كلمة المرور. إذا كان التحقق بخطوتين مفعلًا للحساب
 *   لا يكتمل الدخول بعد (current_user_id() يبقى 0) حتى يُدخل الرمز (two_factor_login).
 */
function attempt_login(PDO $pdo, string $username, string $password): ?string
{
    $username = mb_substr(clean_text($username), 0, 60);
    if ($username === '' || $password === '') {
        return 'أدخل اسم المستخدم وكلمة المرور.';
    }

    $lock = throttle_register($pdo, $username);
    if ($lock > 0) {
        auth_event($pdo, 'login_locked', $username);
        return sprintf('محاولات دخول كثيرة. حاول مرة أخرى بعد %s دقيقة.', fmt_int($lock));
    }

    // SELECT * يعمل قبل تطبيق الترقية 004 وبعدها (عمود is_active)
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // الحساب المعطل يأخذ نفس رسالة كلمة المرور الخاطئة (لا يكشف وجود الحساب)، والمحاولة محسوبة في الحد
    $ok = strlen($password) <= 1000
        && password_verify($password, $user['password_hash'] ?? dummy_password_hash())
        && $user
        && user_is_active($user);
    if (!$ok) {
        // يُسجل الاسم كما كُتب، سواء كان مستخدمًا موجودًا أم لا
        auth_event($pdo, 'login_fail', $username);
        return 'اسم المستخدم أو كلمة المرور غير صحيحة.';
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    // التحقق بخطوتين: لا يكتمل الدخول قبل الرمز، ولا تُمسح المحاولات حتى لا يُخمَّن الرمز بلا حد
    if (two_factor_enabled($pdo, (int) $user['id'])) {
        two_factor_begin($user, $username);
        return null;
    }
    complete_login($pdo, $user, $username);
    return null;
}

/** يكمل الدخول بعد التحقق الكامل (كلمة المرور، ثم رمز التحقق إن كان مفعلًا للحساب) */
function complete_login(PDO $pdo, array $user, string $username): void
{
    throttle_clear($pdo, $username);
    $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now(), $user['id']]);

    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'username' => $user['username'],
        'auth_version' => (int) $user['auth_version'],
        'role' => user_role($user),
        'display_name' => user_display_name($user),
        'csrf' => bin2hex(random_bytes(32)),
        'last_activity' => time(),
    ] + session_binding_values();
    auth_event($pdo, 'login_ok', (string) $user['username']);
}

/** يتحقق من كلمة المرور الحالية للمستخدم المسجل (مثلًا قبل تغييرها) مع نفس حدود المحاولات */
function verify_current_password(PDO $pdo, string $password): ?string
{
    $lock = throttle_register($pdo, current_username());
    if ($lock > 0) {
        return sprintf('محاولات كثيرة. حاول مرة أخرى بعد %s دقيقة.', fmt_int($lock));
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([current_user_id()]);
    $hash = (string) $stmt->fetchColumn();
    if (strlen($password) > 1000 || !password_verify($password, $hash ?: dummy_password_hash())) {
        return 'كلمة المرور الحالية غير صحيحة.';
    }
    throttle_clear($pdo, current_username());
    return null;
}

/**
 * يغير كلمة المرور ويرفع رقم الإصدار فتنتهي كل الجلسات الأخرى.
 * التغيير وسطره في سجل المراقبة في معاملة واحدة، والجلسة تتحدث بعد نجاحها فقط.
 */
function change_password(PDO $pdo, int $userId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $user = db_transaction($pdo, function (PDO $pdo) use ($userId, $hash) {
        $stmt = $pdo->prepare('SELECT username, auth_version FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['username' => '', 'auth_version' => 0];
        $user['auth_version'] = (int) $user['auth_version'] + 1;
        $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = ? WHERE id = ?')->execute([$hash, $user['auth_version'], $userId]);
        audit_record($pdo, 'account.password', 'تغيير كلمة المرور من صفحة «حسابي»', 'user', $userId);
        data_version_bump($pdo);
        return $user;
    });
    session_regenerate_id(true);
    $_SESSION['auth_version'] = $user['auth_version'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    auth_event($pdo, 'password_changed', (string) ($user['username'] ?? ''));
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        // نفس الاسم والخصائص التي ضبطتها start_secure_session، وإلا يبقى الملف القديم في المتصفح
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => SESSION_COOKIE_SAMESITE,
        ]);
    }
    session_destroy();
}

/** قواعد كلمة المرور عند الإنشاء والتغيير */
function password_problem(string $password, string $confirm, string $username): ?string
{
    if (mb_strlen($password) < 10) {
        return 'كلمة المرور يجب ألا تقل عن 10 أحرف.';
    }
    if (strlen($password) > 72) {
        return 'كلمة المرور طويلة جدًا (72 بايت على الأكثر).';
    }
    if ($password !== $confirm) {
        return 'كلمتا المرور غير متطابقتين.';
    }
    if (mb_strtolower($password) === mb_strtolower($username)) {
        return 'كلمة المرور لا يجب أن تطابق اسم المستخدم.';
    }
    return null;
}

function validate_username(string $raw): array
{
    $u = clean_text($raw);
    if (!preg_match('/^[\p{L}\p{N}_.\-]{3,60}$/u', $u)) {
        return [null, 'اسم المستخدم من 3 إلى 60 حرفًا، حروف وأرقام و _ . - فقط، بدون مسافات.'];
    }
    return [$u, null];
}

/* ===================== سجل الدخول والأمان ===================== */

function auth_event_label(string $event): string
{
    return AUTH_EVENT_LABELS[$event] ?? $event;
}

/**
 * يسجل حدثًا في سجل الدخول والأمان مع العنوان والمتصفح. لا يرفع رقم إصدار البيانات عمدًا:
 * محاولات الدخول الفاشلة يرسلها أي شخص من الخارج، ورفع الإصدار معها يجعل كل صفحة مفتوحة
 * تعيد التحميل باستمرار أثناء هجوم. يظهر الحدث مع أول تحديث تالٍ للبيانات أو عند فتح الصفحة.
 * لا يوقف العملية الأصلية أبدًا: أي فشل (مثل غياب الجدول قبل تحديث قاعدة البيانات) يُكتب في سجل الأخطاء فقط.
 * $pruneOneIn: حذف السجلات القديمة باحتمال 1 من N بعد التسجيل (القيمة 1 تحذف دائمًا).
 */
function auth_event(PDO $pdo, string $event, string $username, int $pruneOneIn = AUTH_EVENTS_PRUNE_ONE_IN): void
{
    try {
        if (!isset(AUTH_EVENT_LABELS[$event])) {
            throw new InvalidArgumentException('unknown event');
        }
        $username = mb_substr(clean_text($username), 0, 60);
        $ip = client_ip_key();
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $agent = mb_substr(clean_text(is_string($agent) ? $agent : ''), 0, 255);

        // المهاجم قد يكرر الطلب آلاف المرات أثناء الحظر؛ تسجيل كل طلب يضخم الجدول ويخفي باقي الأحداث.
        // المفتاح هو العنوان وحده (وليس الاسم) حتى لا يتجاوز المهاجم الحد بتغيير الاسم في كل طلب
        if ($event === 'login_locked') {
            $stmt = $pdo->prepare('SELECT 1 FROM auth_events WHERE event = ? AND created_at > ? AND ip = ? LIMIT 1');
            $stmt->execute([$event, date('Y-m-d H:i:s', time() - AUTH_LOCKED_REPEAT_SECONDS), $ip]);
            if ($stmt->fetchColumn() !== false) {
                return;
            }
        }

        $pdo->prepare('INSERT INTO auth_events (event, username, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$event, $username, $ip, $agent, now()]);

        if ($pruneOneIn <= 1 || random_int(1, $pruneOneIn) === 1) {
            auth_events_prune($pdo);
        }
    } catch (Throwable $e) {
        error_log('[wood] auth_event(' . $event . ') ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * مثل auth_event لكنه يفتح اتصال النظام بنفسه، فلا يمنع تعذرُ الاتصال بقاعدة البيانات
 * تسجيلَ الخروج أو إنهاء الجلسة المنتهية.
 */
function auth_event_db(string $event, string $username): void
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        error_log('[wood] auth_event(' . $event . ') ' . get_class($e) . ': ' . $e->getMessage());
        return;
    }
    auth_event($pdo, $event, $username);
}

/** يحذف أحداث السجل الأقدم من مدة الاحتفاظ (دفعة واحدة محدودة). يعيد عدد الصفوف المحذوفة. */
function auth_events_prune(PDO $pdo, int $keepDays = AUTH_EVENTS_KEEP_DAYS): int
{
    $stmt = $pdo->prepare('DELETE FROM auth_events WHERE created_at < ? LIMIT ' . AUTH_EVENTS_PRUNE_BATCH);
    $stmt->execute([date('Y-m-d H:i:s', time() - $keepDays * 86400)]);
    return $stmt->rowCount();
}

/**
 * آخر أحداث السجل (الأحدث أولًا) وعدد المحاولات الفاشلة والمحظورة خلال آخر 24 ساعة.
 * يعيد null إذا لم يُنشأ الجدول بعد (تحديث قاعدة البيانات لم يُطبق).
 * @return array{rows: list<array<string,mixed>>, failed: int, locked: int}|null
 */
function auth_events_overview(PDO $pdo, int $limit = 50): ?array
{
    try {
        $rows = $pdo->query('SELECT event, username, ip, user_agent, created_at FROM auth_events
            ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT event, COUNT(*) FROM auth_events
            WHERE event IN ('login_fail', 'login_locked') AND created_at > ? GROUP BY event");
        $stmt->execute([date('Y-m-d H:i:s', time() - 86400)]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1146) { // الجدول غير موجود
            return null;
        }
        throw $e;
    }
    return [
        'rows' => $rows,
        'failed' => (int) ($counts['login_fail'] ?? 0),
        'locked' => (int) ($counts['login_locked'] ?? 0),
    ];
}
