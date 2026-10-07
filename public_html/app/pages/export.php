<?php
defined('APP_ROOT') || exit;

/*
 * تحميل جدول كملف Excel أو CSV أو تقرير PDF (التفاصيل في lib/exports.php).
 *   t=inventory   المخزون (stock.view)
 *   t=documents   الفواتير والحركات (documents.view)
 *   t=report      تقرير محاسبي: report=<المفتاح>، و part=<رقم القسم> للجداول الإضافية (صلاحية التقرير نفسه)
 */

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    render_simple_error('طريقة الطلب غير مسموحة.', 405);
}
// التصدير يُسجل في المراقبة ويُحسب في الحد: لا يُقبل طلب صادر من موقع آخر
if (is_cross_site_request()) {
    render_simple_error('افتح التصدير من صفحات النظام نفسها.', 403);
}
$table = input($_GET, 't');
$format = input($_GET, 'format');
if (!isset(EXPORT_FORMATS[$format])) {
    render_simple_error('صيغة التصدير غير معروفة. اختر Excel أو CSV أو تقرير PDF.', 400);
}
$display = $format === 'pdf';
$visible = export_visible_columns(input($_GET, 'cols'));

if ($table === 'inventory') {
    require_permission('stock.view');
} elseif ($table === 'documents') {
    require_permission('documents.view');
} elseif ($table === 'report') {
    $key = input($_GET, 'report');
    if (!isset(ACCT_REPORTS[$key])) {
        render_simple_error('التقرير المطلوب غير موجود.', 404);
    }
    acct_require(ACCT_REPORTS[$key]['permission']);
} else {
    render_simple_error('الجدول المطلوب غير موجود.', 404);
}

if (export_rate_exceeded()) {
    render_simple_error(sprintf('وصلت إلى حد التصدير (%s ملفًا كل %s دقائق). انتظر قليلًا ثم حاول مرة أخرى.',
        fmt_int(EXPORT_RATE_MAX), fmt_int(intdiv(EXPORT_RATE_WINDOW, 60))), 429);
}

if ($table === 'inventory') {
    $ds = inventory_dataset(inventory_request($pdo, $_GET), $display);
    $rows = count($ds['rows']);
} elseif ($table === 'documents') {
    $req = documents_request($pdo, $_GET);
    $rows = documents_count($pdo, $req);
    // العدد قبل القراءة: لا تُقرأ الصفوف إذا تجاوزت الحد
    if (($err = export_limit_error($rows, $format)) !== null) {
        render_simple_error($err, 422);
    }
    $ds = [
        'title' => 'الفواتير والحركات',
        'subtitle' => $req['subtitle'],
        'columns' => documents_export_columns($req['show_branch']),
        'rows' => documents_export_rows($pdo, $req),
        'totals' => null,
        'generated_at' => now(),
    ];
} else {
    $ds = acct_report($pdo, $key, $_GET);
    $part = (int) input($_GET, 'part');
    if ($part > 0) {
        $section = $ds['sections'][$part - 1] ?? null;
        if ($section === null) {
            render_simple_error('الجدول المطلوب غير موجود في هذا التقرير.', 404);
        }
        $ds = ['title' => $ds['title'] . ': ' . $section['title'], 'subtitle' => $ds['subtitle'], 'columns' => $section['columns'],
            'rows' => $section['rows'], 'totals' => $section['totals'] ?: null, 'generated_at' => $ds['generated_at']];
    } else {
        $ds = dataset_sorted($ds, table_sort($_GET, report_sortable($ds)));
    }
    $rows = count($ds['rows']);
}
if (($err = export_limit_error($rows, $format)) !== null) {
    render_simple_error($err, 422);
}
$ds = dataset_visible_columns($ds, $visible);

audit_export($pdo, $table === 'report' ? 'report.' . $key : $table, (string) $ds['title'], $format, $rows, (string) $ds['subtitle']);
export_rate_note();
// الجلسة لم تعد لازمة؛ إغلاقها لا يحجز باقي صفحات المستخدم أثناء إنشاء ملف كبير
session_write_close();
export_send($ds, $format);
