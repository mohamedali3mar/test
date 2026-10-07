<?php
defined('APP_ROOT') || exit;

/**
 * ترويسات مشتركة بين صفحات HTML وردود JSON. تُرسل من PHP فقط (وليس من .htaccess)
 * حتى تظهر كل ترويسة مرة واحدة، و header() يستبدل أي قيمة سابقة بنفس الاسم.
 */
function send_common_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cache-Control: no-store, private');
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $csp = "default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";
    // على http فقط تكسر هذه التعليمة تحميل الملفات، لذلك تُضاف عند https فقط
    if (is_https()) {
        $csp .= '; upgrade-insecure-requests';
    }
    header('Content-Security-Policy: ' . $csp);
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    send_common_headers();
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function url(string $route, array $params = []): string
{
    $q = $route === '' ? $params : ['r' => $route] + $params;
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
}

function redirect(string $route, array $params = []): never
{
    header('Location: ' . url($route, $params), true, 303);
    exit;
}

function json_response(array $data, int $code = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    send_common_headers();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

function flash(string $type, string $text): void
{
    $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
}

/** طلبات التحديث التلقائي لا تستهلك رسائل التنبيه المنتظرة */
function take_flashes(): array
{
    if (is_live_request()) {
        return [];
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

function asset(string $path): string
{
    return 'assets/' . $path . '?v=' . rawurlencode(APP_VERSION);
}

const NAV = [
    'inventory'  => 'المخزون',
    'receive'    => 'إضافة وارد',
    'sell'       => 'فاتورة بيع',
    'transfer'   => 'تحويل',
    'documents'  => 'الفواتير والحركات',
    'types'      => 'أنواع الخشب',
    'warehouses' => 'المخازن',
    'monitor'    => 'المراقبة',
    'users'      => 'المستخدمون',
    'settings'   => 'الإعدادات',
];

function render_header(string $title, string $active = '', string $bodyClass = ''): void
{
    send_security_headers();
    $company = app_setting('company_name');
    $loggedIn = current_user_id() > 0;
    $version = '';
    if ($loggedIn) {
        try {
            $version = data_version(db());
        } catch (Throwable $e) {
            $version = '';
        }
    }
    ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | <?= h($company) ?></title>
<link rel="preload" href="assets/fonts/cairo-arabic-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
<script src="<?= h(asset('js/app.js')) ?>" defer></script>
</head>
<body class="<?= h($bodyClass) ?>"
  data-digits="<?= h(app_setting('digits')) ?>"
  data-vol-decimals="<?= h(app_setting('volume_decimals')) ?>"
  data-vol-pad="<?= h(app_setting('volume_pad')) ?>"
  data-currency="<?= h(app_setting('currency')) ?>"
  <?= $loggedIn ? 'data-live-version="' . h($version) . '" data-api="' . h(url('api')) . '" data-login="' . h(url('login')) . '"' : '' ?>>
<a class="skip-link" href="#main">تخطي إلى المحتوى</a>
<?php if ($loggedIn): ?>
<header class="site-header no-print">
  <div class="container header-row">
    <a class="brand" href="<?= h(url('inventory')) ?>"><?= h($company) ?></a>
    <nav class="main-nav" aria-label="القائمة الرئيسية">
      <?php foreach (NAV as $route => $label): if (!can_open($route)) { continue; } /* المسموحة للدور فقط */ ?>
        <a href="<?= h(url($route)) ?>"<?= $route === $active ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="btn btn-quiet header-account" href="<?= h(url('account')) ?>"<?= $active === 'account' ? ' aria-current="page"' : '' ?>><span class="visually-hidden">حسابي: </span><span class="header-account-name"><?= h((string) ($_SESSION['display_name'] ?? current_username())) ?></span></a>
    <form method="post" action="<?= h(url('logout')) ?>" class="logout-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-quiet">خروج</button>
    </form>
  </div>
</header>
<div class="container no-print">
  <div class="alert alert-warning live-status" role="status" id="live-status" hidden></div>
</div>
<?php endif; ?>
<main id="main" class="container">
<?php foreach (take_flashes() as $f): ?>
  <div class="alert alert-<?= h((string) $f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= h((string) $f['text']) ?></div>
<?php endforeach;
}

function render_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/** يعرض صفحة خطأ عامة دون تفاصيل تقنية وينهي التنفيذ */
function render_simple_error(string $message, int $code = 500): never
{
    if (is_live_request()) {
        json_response(['error' => 'request', 'message' => $message], $code);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
    }
    render_header('خطأ');
    echo '<h1>تعذر تنفيذ الطلب</h1>';
    echo '<div class="alert alert-error" role="alert">' . h($message) . '</div>';
    echo '<p><a class="btn" href="' . h(url('inventory')) . '">العودة إلى المخزون</a></p>';
    render_footer();
    exit;
}

/* ===================== أدوات النماذج ===================== */

/** يقرأ قيمة نصية من مصفوفة طلب بأمان (أي قيمة غير نصية تصبح القيمة الافتراضية) */
function input(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

/** يأخذ الحقول المطلوبة فقط من الطلب كنصوص */
function string_inputs(array $src, array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = input($src, $k);
    }
    return $out;
}

function field_error(array $errors, string $field): string
{
    if (!isset($errors[$field])) {
        return '';
    }
    return '<p class="field-error" id="' . h(err_id($field)) . '">' . h($errors[$field]) . '</p>';
}

function err_id(string $field): string
{
    return 'err-' . preg_replace('/[^a-z0-9_-]/i', '-', $field);
}

/** خصائص الحقل: حالة الخطأ وربط رسالة الخطأ والتلميح به */
function field_attrs(array $errors, string $field, string $hintId = ''): string
{
    $ids = [];
    if ($hintId !== '') {
        $ids[] = $hintId;
    }
    if (isset($errors[$field])) {
        $ids[] = err_id($field);
    }
    $out = isset($errors[$field]) ? ' aria-invalid="true"' : '';
    return $out . ($ids ? ' aria-describedby="' . h(implode(' ', $ids)) . '"' : '');
}

function unit_select(string $name, string $selected, string $label, bool $posted): string
{
    $html = '<select name="' . h($name) . '" id="' . h($name) . '" aria-label="' . h($label) . '" data-remember="' . h($name) . '"'
        . ($posted ? ' data-posted="1"' : '') . '>';
    foreach (UNITS as $key => $u) {
        $html .= '<option value="' . h($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . h($u['label']) . '</option>';
    }
    return $html . '</select>';
}

/** قائمة اختيار من صفوف [id, name] */
function options_html(array $rows, string $selected, string $placeholder = ''): string
{
    $html = $placeholder !== '' ? '<option value="">' . h($placeholder) . '</option>' : '';
    foreach ($rows as $r) {
        $html .= '<option value="' . (int) $r['id'] . '"' . ((string) $r['id'] === $selected ? ' selected' : '') . '>' . h($r['name']) . '</option>';
    }
    return $html;
}

function errors_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $html = '<div class="alert alert-error" role="alert"><p>تعذر الحفظ. راجع ما يلي:</p><ul>';
    foreach (array_unique($errors) as $field => $e) {
        $html .= '<li>' . h(line_prefix((string) $field) . $e) . '</li>';
    }
    return $html . '</ul></div>';
}

/** بادئة «السطر N:» لأخطاء أسطر الفاتورة في ملخص الأخطاء */
function line_prefix(string $field): string
{
    if (preg_match('/^lines\.(\d+)\./', $field, $m)) {
        return 'السطر ' . fmt_int((int) $m[1] + 1) . ': ';
    }
    return '';
}

/** يعيد رمز طلب جديدًا إذا كان الرمز الحالي غير صالح أو استُخدم لمحتوى آخر */
function refresh_token_if_needed(array $form, array $errors): string
{
    $t = $form['request_token'] ?? '';
    return (!is_request_token($t) || isset($errors['request_token'])) ? new_request_token() : $t;
}
