<?php
defined('APP_ROOT') || exit;

/*
 * تصدير الجداول: index.php?r=export&t=<الجدول>&format=xlsx|csv|pdf مع نفس معاملات التصفية والترتيب في الصفحة.
 *
 *  - الملف يحتوي كل الصفوف المطابقة للتصفية (وليس الصفحة الحالية فقط)، بالأعمدة الظاهرة على الشاشة (cols)،
 *    وبنفس الترتيب، مع عنوان يصف التصفية. الصلاحية ونطاق الفرع نفس الصفحة تمامًا لأن البيانات من نفس الدوال.
 *  - حد للصفوف: 50 ألف لملف Excel أو CSV و5 آلاف لتقرير PDF، وعند التجاوز تُطلب تصفية أضيق.
 *  - حد لعدد مرات التصدير لكل جلسة في دقائق قليلة لإبطاء سحب البيانات بالجملة.
 *  - كل تصدير يُسجل في المراقبة (من، ماذا، بأي صيغة، كم صفًا، وبأي تصفية). التصدير لا يغير البيانات،
 *    فلا يرفع رقم إصدار البيانات ولا يسبب تحديثًا تلقائيًا للصفحات المفتوحة.
 */

const EXPORT_FORMATS = ['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'تقرير PDF'];
const EXPORT_MAX_ROWS_SHEET = 50000;
const EXPORT_MAX_ROWS_PDF = 5000;
const EXPORT_RATE_MAX = 20;          // ملفات لكل جلسة
const EXPORT_RATE_WINDOW = 300;      // خلال 5 دقائق
/** تقارير بنود (قائمة دخل) لا معنى لترتيب صفوفها */
const EXPORT_UNSORTABLE_REPORTS = ['profit'];

/** هل تجاوزت الجلسة حد التصدير؟ يحذف الأوقات الأقدم من النافذة */
function export_rate_exceeded(): bool
{
    $now = time();
    $times = array_values(array_filter((array) ($_SESSION['export_times'] ?? []), fn ($t) => is_int($t) && $t > $now - EXPORT_RATE_WINDOW));
    $_SESSION['export_times'] = $times;
    return count($times) >= EXPORT_RATE_MAX;
}

function export_rate_note(): void
{
    $_SESSION['export_times'][] = time();
}

/** مفاتيح الأعمدة الظاهرة من المتصفح (cols=a,b,c)، أو null = كل الأعمدة */
function export_visible_columns(string $raw): ?array
{
    if ($raw === '' || strlen($raw) > 1000) {
        return null;
    }
    $keys = array_values(array_filter(explode(',', $raw), fn ($k) => preg_match('/^[a-z_]{1,40}\z/', $k) === 1));
    return $keys ?: null;
}

/**
 * يبقي أعمدة مجموعة البيانات الظاهرة فقط. عمود التصدير يتبع عمود الشاشة col إن وُجد، وإلا مفتاحه.
 * إذا لم يبق أي عمود (قائمة غير صالحة) تبقى كل الأعمدة.
 */
function dataset_visible_columns(array $ds, ?array $visible): array
{
    if ($visible !== null) {
        $kept = array_values(array_filter($ds['columns'], fn ($c) => in_array($c['col'] ?? $c['key'], $visible, true)));
        if ($kept) {
            $ds['columns'] = $kept;
        }
    }
    $ds['columns'] = array_map(fn ($c) => array_diff_key($c, ['col' => true]), $ds['columns']);
    return $ds;
}

/** ترتيب صفوف مجموعة بيانات تقرير حسب sort في الرابط (الإجمالي يبقى في الآخر) */
function dataset_sorted(array $ds, ?array $sort): array
{
    if ($sort === null || !is_array($ds['rows'])) {
        return $ds;
    }
    foreach ($ds['columns'] as $c) {
        if ($c['key'] === $sort['key']) {
            $ds['rows'] = rows_sorted($ds['rows'], $c['key'], $c['type'] ?? 'text', $sort['dir']);
            break;
        }
    }
    return $ds;
}

/** الأعمدة القابلة للترتيب في تقرير: [المفتاح => العنوان] */
function report_sortable(array $ds): array
{
    if (in_array($ds['key'] ?? '', EXPORT_UNSORTABLE_REPORTS, true)) {
        return [];
    }
    return array_column($ds['columns'], 'label', 'key');
}

/** عدد الصفوف أكبر من حد الصيغة: رسالة الخطأ، وإلا null */
function export_limit_error(int $rows, string $format): ?string
{
    $max = $format === 'pdf' ? EXPORT_MAX_ROWS_PDF : EXPORT_MAX_ROWS_SHEET;
    if ($rows <= $max) {
        return null;
    }
    return sprintf(
        'عدد الصفوف (%s) أكبر من حد التصدير (%s صف %s). ضيّق التصفية، مثل فترة أقصر أو مخزن واحد، ثم صدّر مرة أخرى.',
        fmt_int($rows), fmt_int($max), $format === 'pdf' ? 'لتقرير PDF' : 'لملف Excel أو CSV'
    );
}

/** اسم الملف: العنوان والتاريخ، مثل «المخزون 2026-10-07» */
function export_filename(string $title): string
{
    return trim($title) . ' ' . date('Y-m-d');
}

/** يسجل التصدير في المراقبة (لا يغير البيانات، فلا يرفع رقم الإصدار) */
function audit_export(PDO $pdo, string $table, string $title, string $format, int $rows, string $subtitle): void
{
    audit_record($pdo, 'data.export', sprintf('تصدير «%s» إلى %s (%s صف)%s', $title, EXPORT_FORMATS[$format], fmt_int($rows),
        $subtitle !== '' ? ': ' . $subtitle : ''), 'export', null, ['table' => $table, 'format' => $format, 'rows' => $rows]);
}

/** يرسل مجموعة البيانات بالصيغة المطلوبة وينهي التنفيذ */
function export_send(array $ds, string $format): never
{
    $name = export_filename((string) $ds['title']);
    if ($format === 'pdf') {
        pdf_send(pdf_report($ds), $name . '.pdf', false);
    }
    if ($format === 'csv') {
        csv_send(csv_build($ds), $name);
    }
    spreadsheet_send($ds, $name, 'xlsx');
}
