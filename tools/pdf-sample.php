<?php
declare(strict_types=1);

/*
 * ينشئ ملفات PDF تجريبية للفحص اليدوي من قاعدة اختبار مؤقتة (لا يُرفع للاستضافة).
 *
 *   WOOD_TEST_DB=wood_pdf_sample php tools/pdf-sample.php [مجلد الإخراج] [--western] [--rows=5000] [--keep]
 *
 *   مجلد الإخراج الافتراضي: tests/output/pdf-sample
 *   --western  أرقام إنجليزية بدل العربية
 *   --rows=N   عدد صفوف تقرير الحركات (الافتراضي 300)
 *   --keep     لا تحذف قاعدة الاختبار في النهاية
 *
 * يتطلب MariaDB محليًا بنفس إعدادات tests/lib.php. القاعدة تُحذف وتُنشأ من جديد، واسمها يجب أن يبدأ بـ wood_.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('WOOD_TEST_DB') === false) {
    putenv('WOOD_TEST_DB=wood_pdf_sample');
}
require dirname(__DIR__) . '/tests/lib.php';
require dirname(__DIR__) . '/tests/pdf_fixtures.php';

$args = array_slice($argv, 1);
$out = dirname(__DIR__) . '/tests/output/pdf-sample';
$rows = 300;
$western = false;
$keep = false;
foreach ($args as $a) {
    if ($a === '--western') {
        $western = true;
    } elseif ($a === '--keep') {
        $keep = true;
    } elseif (preg_match('/^--rows=(\d+)\z/', $a, $m)) {
        $rows = (int) $m[1];
    } elseif (!str_starts_with($a, '--')) {
        $out = $a;
    } else {
        fwrite(STDERR, "unknown option {$a}\n");
        exit(2);
    }
}
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}
$GLOBALS['APP_CONFIG']['pdf_temp_dir'] = dirname(__DIR__) . '/tests/output/pdf-cache';

$pdo = fresh_database();
$ids = pdf_seed_fixtures($pdo);
if ($western) {
    save_setting($pdo, 'digits', 'western');
    reset_settings_cache();
}

$files = [];
foreach (['sale', 'receipt', 'transfer', 'cancelled', 'rounded'] as $key) {
    $doc = find_document($pdo, $ids[$key]);
    $path = $out . '/' . $key . '.pdf';
    file_put_contents($path, pdf_document($doc, document_lines($pdo, $ids[$key])));
    $files[] = $path;
}
$t = microtime(true);
$path = $out . '/report-' . $rows . '.pdf';
file_put_contents($path, pdf_report(pdf_sample_report($rows)));
$files[] = $path;
$reportSeconds = microtime(true) - $t;

if (!$keep) {
    $name = test_db_name();
    (new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
        ->exec("DROP DATABASE IF EXISTS `$name`");
}

foreach ($files as $f) {
    printf("%s  (%s KB)\n", $f, number_format(filesize($f) / 1024, 1));
}
printf("report with %d rows: %.2f s, peak memory %.1f MB\n", $rows, $reportSeconds, memory_get_peak_usage(true) / 1048576);
