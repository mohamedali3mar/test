<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (PHP_VERSION_ID < 80100 || !extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>متطلبات التشغيل</title>'
        . '<p>يتطلب النظام PHP 8.1 أو أحدث مع الإضافات pdo_mysql و mbstring. '
        . 'غيّر إصدار PHP من لوحة Hostinger (Advanced &gt; PHP Configuration).</p></html>';
    exit;
}

// سجل أخطاء خاص بالنظام داخل مجلد محمي، مع حد أقصى للحجم
$logDir = APP_ROOT . '/storage/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    $logFile = $logDir . '/php-error.log';
    if (is_file($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        @rename($logFile, $logFile . '.1');
    }
    ini_set('error_log', $logFile);
}
mb_internal_encoding('UTF-8');

require APP_ROOT . '/lib/core.php';
require APP_ROOT . '/lib/Num.php';
require APP_ROOT . '/lib/measure.php';
require APP_ROOT . '/lib/format.php';
require APP_ROOT . '/lib/xlsx.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/view.php';
require APP_ROOT . '/lib/catalog.php';
require APP_ROOT . '/lib/branches.php';
require APP_ROOT . '/lib/documents.php';
require APP_ROOT . '/lib/stock.php';
require APP_ROOT . '/lib/migrate.php';
require APP_ROOT . '/lib/forms.php';
require APP_ROOT . '/lib/security.php';
require APP_ROOT . '/lib/totp.php';
require APP_ROOT . '/lib/audit.php';
require APP_ROOT . '/lib/users.php';
require APP_ROOT . '/lib/accounting.php';
require APP_ROOT . '/lib/costing.php';
require APP_ROOT . '/lib/acct_reports.php';
require APP_ROOT . '/lib/parties.php';
require APP_ROOT . '/lib/cashboxes.php';
require APP_ROOT . '/lib/vouchers.php';

set_exception_handler(function (Throwable $e): void {
    error_log('[wood] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, (string) $e . "\n");
        exit(1);
    }
    $msg = $e instanceof AppConfigException
        ? 'إعدادات النظام غير مكتملة. راجع ملف app/config.php ودليل التثبيت.'
        : 'حدث خطأ غير متوقع ولم تُحفظ العملية. حاول مرة أخرى، وإذا تكرر الخطأ راجع مسؤول الموقع.';
    render_simple_error($msg, 500);
});

// الأخطاء الفادحة (مثل نفاد الذاكرة) لا تمر على معالج الاستثناءات: تُستبدل الصفحة الجزئية برسالة عامة
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) || PHP_SAPI === 'cli') {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ</title>'
        . '<p>حدث خطأ غير متوقع ولم تُحفظ العملية. حاول مرة أخرى، وإذا تكرر الخطأ راجع مسؤول الموقع.</p></html>';
});

$configFile = APP_ROOT . '/config.php';
$GLOBALS['APP_CONFIG'] = [];
if (is_file($configFile)) {
    $config = require $configFile;
    if (!is_array($config)) {
        throw new AppConfigException('config.php must return an array');
    }
    $GLOBALS['APP_CONFIG'] = $config;
} elseif (PHP_SAPI !== 'cli') {
    throw new AppConfigException('config.php missing');
}

$tz = (string) (app_config()['timezone'] ?? 'Africa/Cairo');
date_default_timezone_set(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Cairo');
