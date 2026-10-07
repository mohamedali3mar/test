<?php
defined('APP_ROOT') || exit;

/*
 * طبقات حماية إضافية: فلتر برامج الأتمتة، ورفض طلبات POST القادمة من مواقع أخرى،
 * وربط الجلسة بالمتصفح مع مدة قصوى لها، وتقديم سكربتات الواجهة للمستخدمين المسجلين فقط.
 */

/* ===================== فلتر برامج الأتمتة ===================== */

/*
 * هذا فلتر وليس جدارًا: ترويسة User-Agent يكتبها العميل بنفسه، وأي برنامج يستطيع أن ينتحل اسم متصفح
 * (مثل curl -A، وبعض الماسحات مثل nuclei ترسل اسم متصفح عشوائيًا افتراضيًا).
 * فائدته أنه يصد الأدوات والماسحات الآلية بإعداداتها الافتراضية ويقلل الضجيج في السجلات.
 * الحماية الفعلية تبقى في تسجيل الدخول وحدود المحاولات ورموز CSRF وحماية الملفات.
 *
 * «HeadlessChrome» غير محظور عمدًا: اختبارات المتصفح الآلية للنظام (Playwright في tests/e2e.mjs)
 * تستخدمه، وهو متصفح Chromium حقيقي يطبق نفس قيود الأمان التي يطبقها أي متصفح.
 * المقارنة بدون اعتبار لحالة الأحرف.
 */
const BLOCKED_USER_AGENTS = [
    'curl', 'wget', 'python-requests', 'python-urllib', 'aiohttp', 'httpie', 'libwww-perl', 'go-http-client',
    'okhttp', 'java/', 'apache-httpclient', 'postmanruntime', 'insomnia', 'sqlmap', 'nikto', 'nmap', 'masscan',
    'zgrab', 'nuclei', 'wpscan', 'dirbuster', 'gobuster', 'ffuf', 'scrapy', 'httpx', 'node-fetch', 'axios',
    'fuzz faster u fool', // الاسم الذي يرسله ffuf افتراضيًا
];

function is_automated_client(string $userAgent): bool
{
    $ua = strtolower(trim($userAgent));
    if ($ua === '') {
        return true;
    }
    foreach (BLOCKED_USER_AGENTS as $needle) {
        if (str_contains($ua, $needle)) {
            return true;
        }
    }
    return false;
}

/** يُستدعى في أول index.php و install.php (قبل بدء الجلسة وقبل أي استعلام لقاعدة البيانات) */
function block_automated_clients(): void
{
    if (PHP_SAPI === 'cli' || !is_automated_client((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        return;
    }
    if (is_live_request()) {
        json_response(['error' => 'client'], 403);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(403);
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>غير مسموح</title><link rel="stylesheet" href="' . h(asset('css/app.css')) . '"></head>'
        . '<body><main class="container"><h1>غير مسموح</h1>'
        . '<p>لا يمكن فتح هذه الصفحة من هذا البرنامج. افتحها من متصفح الإنترنت.</p></main></body></html>';
    exit;
}

/* ===================== طلبات من مواقع أخرى ===================== */

/**
 * حماية إضافية مع رمز CSRF: المتصفحات الحديثة ترسل Sec-Fetch-Site، وأي قيمة غير same-origin أو none
 * تعني أن الطلب صادر من موقع آخر. بدون الترويسة (مثل Safari القديم) يُعتمد على رمز CSRF وحده.
 */
function is_cross_site_request(): bool
{
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    return is_string($site) && !in_array(strtolower(trim($site)), ['same-origin', 'none'], true);
}

/* ===================== ربط الجلسة ومدتها القصوى ===================== */

function user_agent_hash(): string
{
    return hash('sha256', 'wood-ua|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function session_absolute_seconds(): int
{
    return max(1, (int) (app_config()['session_absolute_hours'] ?? 12)) * 3600;
}

/** قيم تُحفظ في الجلسة عند اكتمال الدخول */
function session_binding_values(): array
{
    return ['ua_hash' => user_agent_hash(), 'login_at' => time()];
}

/**
 * يُستدعى من start_secure_session لكل طلب (ومنها طلبات التحديث التلقائي):
 * جلسة الدخول مربوطة ببصمة متصفحها وقت الدخول، ولها مدة قصوى من وقت الدخول حتى مع النشاط المستمر.
 * عند المخالفة تُحذف الجلسة ويُطلب الدخول من جديد.
 * User-Agent ليس سرًا: الربط يصعّب استخدام ملف جلسة مسروق من جهاز آخر ولا يمنعه تمامًا.
 */
function enforce_session_binding(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $message = null;
    if (!hash_equals((string) ($_SESSION['ua_hash'] ?? ''), user_agent_hash())) {
        $message = 'انتهت الجلسة لأنها استُخدمت من متصفح مختلف. سجّل الدخول مرة أخرى.';
    } elseif (time() - (int) ($_SESSION['login_at'] ?? 0) > session_absolute_seconds()) {
        $message = sprintf('انتهت الجلسة بعد %s ساعة من تسجيل الدخول. سجّل الدخول مرة أخرى.', fmt_int(intdiv(session_absolute_seconds(), 3600)));
    }
    if ($message !== null) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'][] = ['type' => 'warning', 'text' => $message];
    }
}

/* ===================== أعمدة الترقيات ===================== */

function &db_column_cache(): array
{
    static $cache = [];
    return $cache;
}

function db_column_cache_reset(): void
{
    $cache = &db_column_cache();
    $cache = [];
}

/** هل العمود موجود؟ يسمح للكود الجديد بالعمل قبل أن يطبق المدير الترقية من الإعدادات. */
function db_has_column(PDO $pdo, string $table, string $column): bool
{
    $cache = &db_column_cache();
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$key];
}

/* ===================== سكربتات الواجهة للمستخدمين المسجلين فقط ===================== */

/*
 * الوصول المباشر إلى هذه الملفات ممنوع في .htaccess، وتُقدم عبر index.php?r=asset بعد التحقق من الدخول.
 * الاسم المطلوب يُطابق مع هذه القائمة فقط، ولا يُبنى أي مسار من مدخلات المستخدم.
 * الملفات تبقى في مكانها على القرص (tests/parity.cjs يقرأ app.js مباشرة).
 */
const PROTECTED_SCRIPTS = [
    'app.js' => 'js/app.js',
    'twofactor.js' => 'js/twofactor.js',
    'payment.js' => 'js/payment.js',
    'tables.js' => 'js/tables.js',
];

function serve_protected_script(string $name): never
{
    $rel = PROTECTED_SCRIPTS[$name] ?? null;
    $file = $rel === null ? '' : dirname(APP_ROOT) . '/assets/' . $rel;
    if ($file === '' || !is_file($file)) {
        render_simple_error('الملف المطلوب غير موجود.', 404);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        render_simple_error('طريقة الطلب غير مسموحة.', 405);
    }
    clearstatcache(true, $file);
    $etag = sprintf('"%x-%x"', (int) filemtime($file), (int) filesize($file));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // بدء الجلسة أرسل ترويسات منع التخزين؛ تُستبدل هنا. Expires صريحة حتى لا تضيف mod_expires مدة أخرى.
    header_remove('Pragma');
    header('Cache-Control: private, max-age=86400');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if (etag_matches((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: application/javascript; charset=utf-8');
    readfile($file);
    exit;
}

function etag_matches(string $ifNoneMatch, string $etag): bool
{
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if (str_starts_with($candidate, 'W/')) {
            $candidate = substr($candidate, 2);
        }
        if ($candidate === '*' || $candidate === $etag) {
            return true;
        }
    }
    return false;
}
