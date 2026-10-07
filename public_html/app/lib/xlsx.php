<?php
defined('APP_ROOT') || exit;

/*
 * تصدير التقارير إلى Excel ‏(.xlsx) أو CSV بدون أي مكتبة خارجية.
 *
 * عقد البيانات (dataset) الذي تبنيه التقارير:
 *   'title'        => عنوان التقرير
 *   'subtitle'     => وصف الفلاتر المطبقة (قد يكون فارغًا)
 *   'columns'      => [['key' => .., 'label' => .., 'type' => text|int|volume|money|date, 'align' => start|end], ...]
 *   'rows'         => [[key => قيمة خام], ...]   (مصفوفة أو أي iterable، تُقرأ مرة واحدة)
 *   'totals'       => [key => قيمة خام] أو null
 *   'generated_at' => 'Y-m-d H:i:s' بالتوقيت المحلي
 *
 * القيم الخام نصوص عشرية دقيقة كما تأتي من قاعدة البيانات (مثل "0.150000000000000000" و"3000.00")
 * أو أعداد صحيحة أو null. تُكتب الأرقام خلايا رقمية حقيقية كما هي نصيًا بدون المرور بـ float،
 * والحجم يُقرب إلى 9 خانات عشرية على الأكثر (نفس حد العرض في النظام). التواريخ تصبح تواريخ Excel
 * حقيقية بنفس الوقت المحلي دون أي إزاحة للتوقيت.
 *
 * الحماية من حقن الصيغ: النصوص في xlsx خلايا نصية (inlineStr) ولا تُكتب أي صيغة أبدًا،
 * وفي CSV تُسبق النصوص التي تبدأ بـ = + - @ أو Tab أو CR بعلامة '.
 *
 * ملف xlsx يُبنى بـ ZipArchive في مجلد مؤقت فريد يُحذف بعد القراءة. إن لم تتوفر ext-zip
 * أو تعذرت الكتابة المؤقتة يُرمى XlsxUnavailableException ليرجع المستدعي إلى CSV
 * (spreadsheet_send تفعل ذلك تلقائيًا).
 */

/** لا يمكن بناء xlsx في هذه البيئة (لا توجد ext-zip أو تعذرت الكتابة المؤقتة). البديل: CSV */
final class XlsxUnavailableException extends RuntimeException
{
}

const XLSX_TYPES = ['text', 'int', 'volume', 'money', 'date'];
const XLSX_FONT = 'Arial';               // متوفر على كل الأجهزة ويدعم العربية، بخلاف Cairo غير المثبت عادة
const XLSX_HEADER_ROW = 4;
const XLSX_MAX_WIDTH = 60;
const XLSX_MAX_CELL_CHARS = 32767;       // حد Excel لطول الخلية (وحدات UTF-16)
const XLSX_MAX_ROWS = 1048576;
const XLSX_MAX_COLS = 16384;
const XLSX_VOLUME_DECIMALS = 9;
const XLSX_TOTAL_LABEL = 'الإجمالي';
const XLSX_GENERATED_LABEL = 'تاريخ التصدير: ';
const XLSX_FMT_VOLUME = '#,##0.000######';
const XLSX_FMT_DATE = 'yyyy-mm-dd hh:mm';
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function xlsx_available(): bool
{
    return class_exists('ZipArchive', false);
}

/* ------------------------------------------------------------------ */
/* واجهة الاستخدام                                                     */
/* ------------------------------------------------------------------ */

/**
 * يبني ملف xlsx ويعيد محتواه.
 * @throws XlsxUnavailableException عند غياب ZipArchive أو تعذر الكتابة المؤقتة
 * @throws InvalidArgumentException عند مخالفة عقد البيانات
 */
function xlsx_build(array $dataset): string
{
    if (!xlsx_available()) {
        throw new XlsxUnavailableException('ZipArchive (ext-zip) is not available');
    }
    $d = xlsx_normalize_dataset($dataset);
    $dir = xlsx_temp_dir();
    register_shutdown_function('xlsx_remove_dir', $dir); // تنظيف حتى عند خطأ فادح
    try {
        $sheet = xlsx_write_sheet($d, $dir);
        $company = xlsx_text(app_setting('company_name'));

        $zipPath = $dir . '/book.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new XlsxUnavailableException('Cannot create the temporary zip file');
        }
        $parts = [
            '[Content_Types].xml' => xlsx_content_types_xml(),
            '_rels/.rels' => xlsx_root_rels_xml(),
            'docProps/core.xml' => xlsx_core_xml($d, $company),
            'docProps/app.xml' => xlsx_app_xml($company),
            'xl/workbook.xml' => xlsx_workbook_xml($d, $sheet),
            'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
            'xl/styles.xml' => $sheet['styles']->xml(),
        ];
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->addFile($sheet['path'], 'xl/worksheets/sheet1.xml');
        // المستوى الافتراضي في بعض نسخ libzip هو الأقصى: أبطأ 6 مرات تقريبًا لتوفير 10% فقط من الحجم
        $zip->setCompressionName('xl/worksheets/sheet1.xml', ZipArchive::CM_DEFLATE, 6);
        if (!$zip->close()) {
            throw new XlsxUnavailableException('Cannot write the temporary zip file');
        }
        $bytes = file_get_contents($zipPath);
        if ($bytes === false || $bytes === '') {
            throw new XlsxUnavailableException('Cannot read the temporary zip file');
        }
        return $bytes;
    } finally {
        xlsx_remove_dir($dir);
    }
}

/**
 * CSV بترميز UTF-8 مع BOM (ليفتحه Excel بالعربية صحيحًا)، فاصلة، اقتباس RFC 4180، أسطر CRLF.
 * الصف الأول عناوين الأعمدة، ثم البيانات، ثم صف الإجمالي إن وجد. الأرقام عشرية عادية بدون فواصل آلاف.
 */
function csv_build(array $dataset): string
{
    $d = xlsx_normalize_dataset($dataset);
    $cols = $d['columns'];
    $out = "\xEF\xBB\xBF" . csv_line(array_map(static fn(array $c): string => csv_text_field($c['label']), $cols));
    foreach ($d['rows'] as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Each export row must be an array');
        }
        $fields = [];
        foreach ($cols as $col) {
            $fields[] = csv_field($row[$col['key']] ?? null, $col['type']);
        }
        $out .= csv_line($fields);
    }
    if ($d['totals'] !== null) {
        $labelAt = xlsx_total_label_index($cols, $d['totals']);
        $fields = [];
        foreach ($cols as $i => $col) {
            $fields[] = $i === $labelAt ? csv_text_field(XLSX_TOTAL_LABEL) : csv_field($d['totals'][$col['key']] ?? null, $col['type']);
        }
        $out .= csv_line($fields);
    }
    return $out;
}

function xlsx_send(string $bytes, string $filename): never
{
    xlsx_send_download($bytes, $filename, 'xlsx', XLSX_MIME);
}

function csv_send(string $bytes, string $filename): never
{
    xlsx_send_download($bytes, $filename, 'csv', 'text/csv; charset=utf-8');
}

/**
 * يبني التقرير ويرسله للتحميل: xlsx افتراضيًا، ويرجع تلقائيًا إلى CSV إن تعذر xlsx.
 * $filename بدون امتداد أو بأي منهما؛ يُضبط الامتداد حسب الصيغة المرسلة فعلًا.
 */
function spreadsheet_send(array $dataset, string $filename, string $format = 'xlsx'): never
{
    if ($format !== 'csv') {
        try {
            xlsx_send(xlsx_build($dataset), $filename);
        } catch (XlsxUnavailableException $e) {
            error_log('[wood] xlsx export unavailable, sending CSV instead: ' . $e->getMessage());
        }
    }
    csv_send(csv_build($dataset), $filename);
}

/* ------------------------------------------------------------------ */
/* تحويل القيم                                                          */
/* ------------------------------------------------------------------ */

/**
 * يتحقق من عقد البيانات ويوحده. الأعمدة: النوع الافتراضي text، والمحاذاة الافتراضية
 * end للأرقام وstart لغيرها.
 */
function xlsx_normalize_dataset(array $d): array
{
    $cols = $d['columns'] ?? null;
    if (!is_array($cols) || $cols === []) {
        throw new InvalidArgumentException('Export dataset needs at least one column');
    }
    if (count($cols) > XLSX_MAX_COLS) {
        throw new InvalidArgumentException('Too many columns for one worksheet');
    }
    $out = [];
    $seen = [];
    foreach (array_values($cols) as $c) {
        if (!is_array($c) || !isset($c['key']) || !is_scalar($c['key']) || (string) $c['key'] === '') {
            throw new InvalidArgumentException('Each export column needs a key');
        }
        $key = (string) $c['key'];
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('Duplicate export column key: ' . $key);
        }
        $seen[$key] = true;
        $type = $c['type'] ?? 'text';
        if (!in_array($type, XLSX_TYPES, true)) {
            throw new InvalidArgumentException('Unknown export column type: ' . (is_scalar($type) ? (string) $type : gettype($type)));
        }
        $align = $c['align'] ?? (in_array($type, ['int', 'volume', 'money'], true) ? 'end' : 'start');
        if ($align !== 'start' && $align !== 'end') {
            throw new InvalidArgumentException('Export column align must be start or end');
        }
        $out[] = ['key' => $key, 'label' => xlsx_text($c['label'] ?? $key), 'type' => $type, 'align' => $align];
    }
    $rows = $d['rows'] ?? [];
    if (!is_iterable($rows)) {
        throw new InvalidArgumentException('Export rows must be iterable');
    }
    $totals = $d['totals'] ?? null;
    if ($totals !== null && !is_array($totals)) {
        throw new InvalidArgumentException('Export totals must be an array or null');
    }
    $gen = xlsx_datetime_parts($d['generated_at'] ?? null) ?? xlsx_datetime_parts(date('Y-m-d H:i:s'));
    return [
        'title' => xlsx_text($d['title'] ?? ''),
        'subtitle' => xlsx_text($d['subtitle'] ?? ''),
        'columns' => $out,
        'rows' => $rows,
        'totals' => $totals === [] ? null : $totals,
        'generated_at' => $gen,
    ];
}

/**
 * نص صالح لـ XML 1.0: يصلح UTF-8 التالف، ويوحد نهايات الأسطر إلى \n، ويحذف رموز التحكم
 * (C0 عدا Tab وLF، وDEL، وC1) والرمزين U+FFFE وU+FFFF. Tab والسطر الجديد يبقيان.
 */
function xlsx_text(mixed $raw): string
{
    if ($raw === null) {
        return '';
    }
    if (!is_scalar($raw)) {
        throw new InvalidArgumentException('Export cell values must be scalars or null, got ' . gettype($raw));
    }
    $s = is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw;
    if ($s === '') {
        return '';
    }
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_scrub($s, 'UTF-8');
    }
    if (str_contains($s, "\r")) {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
    }
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}\x{FFFE}\x{FFFF}]+/u', '', $s) ?? '';
}

/**
 * قيمة رقمية كنص عشري لعنصر <v> (و CSV)، أو null إن لم تكن رقمًا.
 * يقبل: عدد صحيح، أو نص مثل -12 و 0.150000000000000000. الحجم يُقرب إلى 9 خانات (النصف لأعلى).
 * الأصفار الزائدة تُحذف. float خارج العقد لكنه يُقبل بدقة 12 خانة عشرية.
 */
function xlsx_number(mixed $raw, string $type): ?string
{
    if (is_int($raw)) {
        $s = (string) $raw;
    } elseif (is_string($raw)) {
        $s = trim($raw);
    } elseif (is_float($raw) && is_finite($raw)) {
        $s = sprintf('%.12F', $raw);
    } else {
        return null;
    }
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?\z/', $s, $m)) {
        return null;
    }
    $int = ltrim($m[2], '0');
    $abs = ($int === '' ? '0' : $int) . (isset($m[3]) ? '.' . $m[3] : '');
    if ($type === 'volume') {
        $abs = Num::roundDecimal($abs, XLSX_VOLUME_DECIMALS);
    }
    $abs = Num::trimDecimal($abs);
    return ($m[1] === '-' && $abs !== '0') ? '-' . $abs : $abs;
}

/**
 * يقرأ 'Y-m-d H:i:s' (أو 'Y-m-d H:i' أو 'Y-m-d') ويعيد [y, m, d, h, i, s] أو null.
 * التواريخ قبل 1900-03-01 ترفض لأن ترقيم Excel قبلها غير متصل (خطأ 1900 الكبيسة).
 */
function xlsx_datetime_parts(mixed $raw): ?array
{
    if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?\z/', trim($raw), $m)) {
        return null;
    }
    $p = [(int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
    if (!checkdate($p[1], $p[2], $p[0]) || $p[3] > 23 || $p[4] > 59 || $p[5] > 59 || $p[0] < 1900 || ($p[0] === 1900 && $p[1] < 3)) {
        return null;
    }
    return $p;
}

/**
 * الرقم التسلسلي لتاريخ Excel (نظام 1900) لنفس الوقت المحلي: الأيام منذ 1899-12-30 + كسر اليوم.
 * يُحسب بـ gmmktime فلا تؤثر المنطقة الزمنية أو التوقيت الصيفي، والكسر بقسمة صحيحة لـ 12 خانة.
 */
function xlsx_serial(array $p): string
{
    $days = intdiv(gmmktime(0, 0, 0, $p[1], $p[2], $p[0]), 86400) + 25569;
    $sec = $p[3] * 3600 + $p[4] * 60 + $p[5];
    if ($sec === 0) {
        return (string) $days;
    }
    $frac = intdiv($sec * 2000000000000 + 86400, 172800); // round(sec / 86400 * 10^12)
    return rtrim($days . '.' . str_pad((string) $frac, 12, '0', STR_PAD_LEFT), '0');
}

/* ------------------------------------------------------------------ */
/* الورقة                                                               */
/* ------------------------------------------------------------------ */

/**
 * يكتب xl/worksheets/sheet1.xml في المجلد المؤقت على دفعات (لا يُحمل الملف كاملًا في الذاكرة).
 * الصفوف: 1 العنوان، 2 الفلاتر وتاريخ التصدير، 4 رؤوس الأعمدة، البيانات من 5، ثم الإجمالي.
 * @return array{path: string, styles: XlsxStyles, last_row: int, last_data_row: int, last_col: string}
 */
function xlsx_write_sheet(array $d, string $dir): array
{
    $cols = $d['columns'];
    $n = count($cols);
    $st = new XlsxStyles();
    $letters = [];
    $widths = [];
    $cellStyles = [];
    foreach ($cols as $i => $col) {
        $letters[$i] = xlsx_col($i + 1);
        $h = $col['align'] === 'start' ? 'right' : 'left'; // الورقة من اليمين لليسار: البداية يمين
        $fmt = XlsxStyles::numFmtFor($col['type']);
        // إزاحة 1 في خلايا الجدول: بدونها يلتصق عمود محاذاته للبداية بجاره المحاذى للنهاية عند الطباعة
        $cellStyles[$i] = [
            'num' => $st->xf($fmt, 0, 0, 0, $h, 'top', false, 1),
            'text' => $st->xf(0, 0, 0, 0, $h, 'top', false, 1),
            'wrap' => $st->xf(0, 0, 0, 0, $h, 'top', true, 1),
            'head' => $st->xf(0, XlsxStyles::FONT_BOLD, XlsxStyles::FILL_HEADER, XlsxStyles::BORDER_BOTTOM, $h, 'center', true, 1),
            'total' => $st->xf($fmt, XlsxStyles::FONT_BOLD, 0, XlsxStyles::BORDER_TOP, $h, 'top', false, 1),
        ];
        // الرأس عريض ومعه زر الفلتر
        $widths[$i] = xlsx_text_width($col['label']) * 1.15 + 3;
    }
    $last = $letters[$n - 1];

    $rowsPath = $dir . '/rows.xml';
    $fh = fopen($rowsPath, 'wb');
    if ($fh === false) {
        throw new XlsxUnavailableException('Cannot create a temporary file');
    }
    $r = XLSX_HEADER_ROW;
    $limit = XLSX_MAX_ROWS - 1;
    $buf = '';
    foreach ($d['rows'] as $row) {
        if (!is_array($row)) {
            fclose($fh);
            throw new InvalidArgumentException('Each export row must be an array');
        }
        if (++$r > $limit) {
            fclose($fh);
            throw new InvalidArgumentException('Too many rows for one worksheet');
        }
        $buf .= '<row r="' . $r . '">';
        foreach ($cols as $i => $col) {
            $buf .= xlsx_cell($letters[$i] . $r, $row[$col['key']] ?? null, $col['type'], $cellStyles[$i], $widths[$i]);
        }
        $buf .= '</row>';
        if (strlen($buf) > 262144) {
            xlsx_fwrite($fh, $buf);
            $buf = '';
        }
    }
    xlsx_fwrite($fh, $buf);
    fclose($fh);
    $lastDataRow = $r;

    $tail = '';
    if ($d['totals'] !== null) {
        $r++;
        $labelAt = xlsx_total_label_index($cols, $d['totals']);
        if ($labelAt >= 0) {
            $widths[$labelAt] = max($widths[$labelAt], xlsx_text_width(XLSX_TOTAL_LABEL) * 1.15);
        }
        $tail .= '<row r="' . $r . '">';
        foreach ($cols as $i => $col) {
            $ref = $letters[$i] . $r;
            $style = $cellStyles[$i]['total'];
            $cell = $i === $labelAt
                ? '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is>' . xlsx_t(XLSX_TOTAL_LABEL) . '</is></c>'
                : xlsx_cell($ref, $d['totals'][$col['key']] ?? null, $col['type'], ['num' => $style, 'text' => $style, 'wrap' => $style], $widths[$i]);
            $tail .= $cell !== '' ? $cell : '<c r="' . $ref . '" s="' . $style . '"/>'; // الحد العلوي على كامل الصف
        }
        $tail .= '</row>';
    }
    $lastRow = $r;

    $colsXml = '<cols>';
    $total = 0.0;
    foreach ($widths as $i => $w) {
        $w = min(XLSX_MAX_WIDTH, max(8, (int) ceil($w + 3))); // هامش + الإزاحة
        $total += $w;
        $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    // العنوان والسطر الوصفي مدمجان بعرض الجدول مع التفاف؛ الارتفاع يُحسب لأن Excel لا يضبط الخلايا المدمجة
    $g = $d['generated_at'];
    $meta = ($d['subtitle'] !== '' ? $d['subtitle'] . "\n" : '')
        . XLSX_GENERATED_LABEL . sprintf('%04d-%02d-%02d %02d:%02d', $g[0], $g[1], $g[2], $g[3], $g[4]);
    $head = '';
    if ($d['title'] !== '') {
        $head .= '<row r="1" ht="' . (26 * xlsx_line_count($d['title'], $total, 1.6)) . '" customHeight="1">'
            . '<c r="A1" s="' . $st->xf(0, XlsxStyles::FONT_TITLE, 0, 0, 'right', 'center', true) . '" t="inlineStr"><is>' . xlsx_t($d['title']) . '</is></c></row>';
    }
    $head .= '<row r="2" ht="' . (15 * xlsx_line_count($meta, $total, 0.95)) . '" customHeight="1">'
        . '<c r="A2" s="' . $st->xf(0, XlsxStyles::FONT_META, 0, 0, 'right', 'top', true) . '" t="inlineStr"><is>' . xlsx_t($meta) . '</is></c></row>';
    $head .= '<row r="' . XLSX_HEADER_ROW . '">';
    foreach ($cols as $i => $col) {
        $head .= '<c r="' . $letters[$i] . XLSX_HEADER_ROW . '" s="' . $cellStyles[$i]['head'] . '" t="inlineStr"><is>' . xlsx_t($col['label']) . '</is></c>';
    }
    $head .= '</row>';

    $merges = [];
    if ($n > 1) {
        if ($d['title'] !== '') {
            $merges[] = 'A1:' . $last . '1';
        }
        $merges[] = 'A2:' . $last . '2';
    }
    $first = XLSX_HEADER_ROW + 1;
    $wide = $n > 6;

    $path = $dir . '/sheet1.xml';
    $out = fopen($path, 'wb');
    $in = fopen($rowsPath, 'rb');
    if ($out === false || $in === false) {
        throw new XlsxUnavailableException('Cannot create a temporary file');
    }
    xlsx_fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $last . $lastRow . '"/>'
        . '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="' . XLSX_HEADER_ROW . '" topLeftCell="A' . $first . '" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A' . $first . '" sqref="A' . $first . '"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $colsXml
        . '<sheetData>' . $head);
    if (stream_copy_to_stream($in, $out) === false) {
        throw new XlsxUnavailableException('Cannot write a temporary file');
    }
    fclose($in);
    xlsx_fwrite($out, $tail . '</sheetData>'
        . '<autoFilter ref="A' . XLSX_HEADER_ROW . ':' . $last . $lastDataRow . '"/>'
        . ($merges ? '<mergeCells count="' . count($merges) . '"><mergeCell ref="' . implode('"/><mergeCell ref="', $merges) . '"/></mergeCells>' : '')
        . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup paperSize="9" orientation="' . ($wide ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>'
        // رقم الصفحة بأرقام فقط: الكلمات العربية حول الحقول ينعكس ترتيبها في تذييل LibreOffice
        . '<headerFooter><oddFooter>' . xlsx_esc('&C&P / &N') . '</oddFooter></headerFooter>'
        . '</worksheet>');
    if (!fclose($out)) {
        throw new XlsxUnavailableException('Cannot write a temporary file');
    }
    @unlink($rowsPath);
    return ['path' => $path, 'styles' => $st, 'last_row' => $lastRow, 'last_data_row' => $lastDataRow, 'last_col' => $last];
}

/**
 * خلية واحدة، أو '' للخلية الفارغة. القيمة غير الرقمية في عمود رقمي تُكتب نصًا ولا تضيع.
 * $width يُحدَّث بعرض المحتوى المعروض لتقدير عرض العمود.
 */
function xlsx_cell(string $ref, mixed $raw, string $type, array $styles, float &$width): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    if ($type === 'date') {
        $p = xlsx_datetime_parts($raw);
        if ($p !== null) {
            $width = max($width, 16);
            return '<c r="' . $ref . '" s="' . $styles['num'] . '"><v>' . xlsx_serial($p) . '</v></c>';
        }
    } elseif ($type !== 'text') {
        $v = xlsx_number($raw, $type);
        if ($v !== null) {
            $width = max($width, xlsx_number_width($v, $type));
            return '<c r="' . $ref . '" s="' . $styles['num'] . '"><v>' . $v . '</v></c>';
        }
    }
    $s = xlsx_cell_text($raw);
    if ($s === '') {
        return '';
    }
    $w = xlsx_text_width($s);
    $width = max($width, $w);
    $style = ($w > XLSX_MAX_WIDTH - 3 || str_contains($s, "\n")) ? $styles['wrap'] : $styles['text'];
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is>' . xlsx_t($s) . '</is></c>';
}

/** نص الخلية مقصوصًا عند حد Excel (32767 وحدة UTF-16) */
function xlsx_cell_text(mixed $raw): string
{
    $s = xlsx_text($raw);
    if (strlen($s) > XLSX_MAX_CELL_CHARS) {
        $s = mb_substr($s, 0, XLSX_MAX_CELL_CHARS);
        while (strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) > 2 * XLSX_MAX_CELL_CHARS) {
            $s = mb_substr($s, 0, -1);
        }
    }
    return $s;
}

/**
 * عنصر <t> لنص مهرب. Excel يفسر _xHHHH_ كرمز مهرب، فتُهرب الشرطة السفلية بـ _x005F_
 * ليبقى النص كما هو حرفيًا.
 */
function xlsx_t(string $s): string
{
    if (str_contains($s, '_x')) {
        $s = preg_replace('/_(x[0-9A-Fa-f]{4}_)/', '_x005F_$1', $s) ?? $s;
    }
    $preserve = $s !== '' && ($s[0] === ' ' || $s[-1] === ' ' || strpbrk($s, "\t\n") !== false || str_contains($s, '  '));
    return ($preserve ? '<t xml:space="preserve">' : '<t>') . xlsx_esc($s) . '</t>';
}

function xlsx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** حرف العمود من رقمه (1 = A) */
function xlsx_col(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + $n % 26) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** العرض الظاهر للرقم بعد التنسيق (فواصل الآلاف والخانات العشرية) */
function xlsx_number_width(string $v, string $type): int
{
    $neg = $v[0] === '-' ? 1 : 0;
    $dot = strpos($v, '.');
    $intLen = ($dot === false ? strlen($v) : $dot) - $neg;
    $dec = $dot === false ? 0 : strlen($v) - $dot - 1;
    $len = $neg + $intLen + intdiv($intLen - 1, 3);
    return $len + match ($type) {
        'money' => 3,
        'volume' => 1 + max(3, $dec),
        default => 0,
    };
}

/** عرض أطول سطر في النص بوحدة عرض الحرف */
function xlsx_text_width(string $s): int
{
    if (!str_contains($s, "\n")) {
        return mb_strwidth($s, 'UTF-8');
    }
    $max = 0;
    foreach (explode("\n", $s) as $line) {
        $max = max($max, mb_strwidth($line, 'UTF-8'));
    }
    return $max;
}

/** عدد الأسطر التقريبي لنص ملتف في مساحة عرضها $width، مع معامل لحجم الخط */
function xlsx_line_count(string $s, float $width, float $factor): int
{
    $lines = 0;
    foreach (explode("\n", $s) as $line) {
        $lines += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') * $factor / max(1.0, $width)));
    }
    return $lines;
}

/**
 * موضع كلمة «الإجمالي» في صف الإجمالي: أول عمود بلا قيمة إجمالي، إلا إذا وفّر التقرير
 * نصًا بنفسه في أحد الأعمدة النصية. -1 = بلا كلمة.
 */
function xlsx_total_label_index(array $cols, array $totals): int
{
    foreach ($cols as $col) {
        if ($col['type'] === 'text' && ($totals[$col['key']] ?? '') !== '') {
            return -1;
        }
    }
    foreach ($cols as $i => $col) {
        if (($totals[$col['key']] ?? '') === '') {
            return $i;
        }
    }
    return -1;
}

/** اسم ورقة صالح: 31 حرفًا على الأكثر (يُقص عند حد كلمة إن أمكن)، بدون []:*?/\ ولا علامة ' في الطرفين */
function xlsx_sheet_name(string $title): string
{
    $s = preg_replace('/[\[\]:*?\/\\\\]+|\p{C}+/u', ' ', xlsx_text($title)) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '', " '");
    $full = $s;
    $s = mb_substr($s, 0, 31);
    while (strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) > 62) {
        $s = mb_substr($s, 0, -1);
    }
    $space = mb_strrpos($s, ' ');
    if ($s !== $full && mb_substr($full, mb_strlen($s), 1) !== ' ' && $space !== false && $space >= 12) {
        $s = mb_substr($s, 0, $space);
    }
    $s = trim($s, " '");
    return ($s === '' || strcasecmp($s, 'History') === 0) ? 'تقرير' : $s;
}

/* ------------------------------------------------------------------ */
/* أجزاء الحزمة                                                          */
/* ------------------------------------------------------------------ */

const XLSX_XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

function xlsx_content_types_xml(): string
{
    return XLSX_XML_HEAD
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';
}

function xlsx_root_rels_xml(): string
{
    return XLSX_XML_HEAD
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function xlsx_workbook_rels_xml(): string
{
    return XLSX_XML_HEAD
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

function xlsx_core_xml(array $d, string $company): string
{
    $g = $d['generated_at'];
    try {
        $local = new DateTimeImmutable(vsprintf('%04d-%02d-%02d %02d:%02d:%02d', $g), new DateTimeZone(date_default_timezone_get()));
        $utc = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        $utc = gmdate('Y-m-d\TH:i:s\Z');
    }
    return XLSX_XML_HEAD
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xlsx_esc($d['title']) . '</dc:title>'
        . ($d['subtitle'] !== '' ? '<dc:description>' . xlsx_esc($d['subtitle']) . '</dc:description>' : '')
        . '<dc:creator>' . xlsx_esc($company) . '</dc:creator>'
        . '<cp:lastModifiedBy>' . xlsx_esc($company) . '</cp:lastModifiedBy>'
        . '<dc:language>ar-EG</dc:language>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $utc . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $utc . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function xlsx_app_xml(string $company): string
{
    return XLSX_XML_HEAD
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
        . '<Company>' . xlsx_esc($company) . '</Company>'
        . '<LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged>'
        . '</Properties>';
}

function xlsx_workbook_xml(array $d, array $sheet): string
{
    $name = xlsx_sheet_name($d['title']);
    $q = "'" . str_replace("'", "''", $name) . "'!";
    $h = XLSX_HEADER_ROW;
    return XLSX_XML_HEAD
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr/>'
        . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="15000" activeTab="0"/></bookViews>'
        . '<sheets><sheet name="' . xlsx_esc($name) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames>'
        . '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
        . xlsx_esc($q . '$A$' . $h . ':$' . $sheet['last_col'] . '$' . $sheet['last_data_row']) . '</definedName>'
        . '<definedName name="_xlnm.Print_Titles" localSheetId="0">' . xlsx_esc($q . '$' . $h . ':$' . $h) . '</definedName>'
        . '</definedNames>'
        . '</workbook>';
}

/**
 * سجل تنسيقات الخلايا (cellXfs): كل تركيبة تُضاف مرة واحدة ويعاد رقمها.
 * الألوان من قواعد التصميم: النص #1C1917، الثانوي #44403C، خلفية الرأس #F5F5F4.
 */
final class XlsxStyles
{
    public const FONT_BOLD = 1;
    public const FONT_TITLE = 2;
    public const FONT_META = 3;
    public const FILL_HEADER = 2;
    public const BORDER_BOTTOM = 1;
    public const BORDER_TOP = 2;
    private const FMT_VOLUME = 164;
    private const FMT_DATE = 165;

    /** @var array<string,int> */
    private array $index = ['0|0|0|0|||0' => 0];
    /** @var string[] */
    private array $xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];

    public static function numFmtFor(string $type): int
    {
        return match ($type) {
            'int' => 3,      // #,##0 (مدمج)
            'money' => 4,    // #,##0.00 (مدمج)
            'volume' => self::FMT_VOLUME,
            'date' => self::FMT_DATE,
            default => 0,
        };
    }

    /** $indent: إزاحة النص عن حافة محاذاته (left/right فقط) */
    public function xf(int $fmt, int $font, int $fill, int $border, string $h = '', string $v = '', bool $wrap = false, int $indent = 0): int
    {
        $key = "$fmt|$font|$fill|$border|$h|$v|" . (int) $wrap . ($indent ? "|$indent" : '');
        if (isset($this->index[$key])) {
            return $this->index[$key];
        }
        $x = '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ($fmt ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . ($fill ? ' applyFill="1"' : '')
            . ($border ? ' applyBorder="1"' : '');
        if ($h !== '' || $v !== '' || $wrap) {
            $x .= ' applyAlignment="1"><alignment' . ($h !== '' ? ' horizontal="' . $h . '"' : '')
                . ($v !== '' ? ' vertical="' . $v . '"' : '') . ($wrap ? ' wrapText="1"' : '')
                . ($indent && ($h === 'left' || $h === 'right') ? ' indent="' . $indent . '"' : '') . '/></xf>';
        } else {
            $x .= '/>';
        }
        $this->xfs[] = $x;
        return $this->index[$key] = count($this->xfs) - 1;
    }

    public function xml(): string
    {
        $font = static fn(string $extra, int $size, string $color): string =>
            '<font>' . $extra . '<sz val="' . $size . '"/><color rgb="FF' . $color . '"/><name val="' . XLSX_FONT . '"/><family val="2"/></font>';
        $border = static fn(string $side): string => '<border>'
            . implode('', array_map(static fn(string $s): string => $s === $side
                ? "<$s style=\"thin\"><color rgb=\"FF78716C\"/></$s>" : "<$s/>", ['left', 'right', 'top', 'bottom']))
            . '<diagonal/></border>';
        return XLSX_XML_HEAD
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="' . self::FMT_VOLUME . '" formatCode="' . xlsx_esc(XLSX_FMT_VOLUME) . '"/>'
            . '<numFmt numFmtId="' . self::FMT_DATE . '" formatCode="' . xlsx_esc(XLSX_FMT_DATE) . '"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . $font('', 11, '1C1917') . $font('<b/>', 11, '1C1917') . $font('<b/>', 16, '1C1917') . $font('', 10, '44403C')
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F4"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border('bottom') . $border('top') . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium9" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }
}

/* ------------------------------------------------------------------ */
/* ملفات مؤقتة                                                          */
/* ------------------------------------------------------------------ */

/** مجلد مؤقت فريد بصلاحيات 0700: مجلد النظام المؤقت، وإلا app/storage (محمي من الويب) */
function xlsx_temp_dir(): string
{
    foreach ([sys_get_temp_dir(), APP_ROOT . '/storage'] as $base) {
        if ($base === '' || !@is_dir($base) || !@is_writable($base)) {
            continue;
        }
        $dir = rtrim($base, '/\\') . '/wood-xlsx-' . bin2hex(random_bytes(12));
        if (@mkdir($dir, 0700)) {
            return $dir;
        }
    }
    throw new XlsxUnavailableException('No writable temporary directory');
}

function xlsx_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

function xlsx_fwrite($fh, string $data): void
{
    if ($data !== '' && fwrite($fh, $data) !== strlen($data)) {
        throw new XlsxUnavailableException('Cannot write a temporary file (disk full?)');
    }
}

/* ------------------------------------------------------------------ */
/* CSV                                                                  */
/* ------------------------------------------------------------------ */

function csv_line(array $fields): string
{
    return implode(',', $fields) . "\r\n";
}

/** حقل CSV من قيمة خام حسب نوع العمود؛ القيمة غير الصالحة لنوعها تُكتب نصًا محميًا */
function csv_field(mixed $raw, string $type): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    if ($type === 'date') {
        $p = xlsx_datetime_parts($raw);
        if ($p !== null) {
            return vsprintf('%04d-%02d-%02d %02d:%02d:%02d', $p);
        }
    } elseif ($type !== 'text') {
        $v = xlsx_number($raw, $type);
        if ($v !== null) {
            if ($type === 'money') {
                $dot = strpos($v, '.');
                $v .= $dot === false ? '.00' : str_repeat('0', max(0, 2 - (strlen($v) - $dot - 1)));
            }
            return $v;
        }
    }
    return csv_text_field(xlsx_text($raw));
}

/** نص CSV: حماية من حقن الصيغ ثم اقتباس RFC 4180 عند الحاجة */
function csv_text_field(string $s): string
{
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
        $s = "'" . $s;
    }
    if (strpbrk($s, ",\"\r\n") !== false || ($s !== '' && ($s[0] === ' ' || $s[-1] === ' '))) {
        return '"' . str_replace('"', '""', $s) . '"';
    }
    return $s;
}

/* ------------------------------------------------------------------ */
/* الإرسال                                                              */
/* ------------------------------------------------------------------ */

/**
 * اسم الملف بصيغتين: UTF-8 (لـ filename*) وبديل ASCII (لـ filename).
 * يحذف رموز التحكم واتجاه النص (لمنع تزوير الامتداد) والرموز الممنوعة في أسماء الملفات.
 * @return array{0: string, 1: string}
 */
function xlsx_download_names(string $filename, string $ext): array
{
    $name = preg_replace('/\p{C}+|[<>:"\/\\\\|?*]+/u', ' ', xlsx_text($filename)) ?? '';
    $name = preg_replace('/\.(xlsx|csv)\s*\z/iu', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', ' .');
    $name = trim(mb_substr($name, 0, 120), ' .');
    if ($name === '') {
        $name = 'export';
    }
    $ascii = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '', '-._');
    $ascii = preg_replace('/-{2,}/', '-', $ascii) ?? '';
    if (!preg_match('/[A-Za-z]/', $ascii)) {
        $ascii = trim('export-' . $ascii, '-');
    }
    return [$name . '.' . $ext, $ascii . '.' . $ext];
}

/** ترويسة Content-Disposition مع اسم عربي حسب RFC 5987/6266 وبديل ASCII للمتصفحات القديمة */
function xlsx_content_disposition(string $filename, string $ext): string
{
    [$utf8, $ascii] = xlsx_download_names($filename, $ext);
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($utf8);
}

function xlsx_send_download(string $bytes, string $filename, string $ext, string $contentType): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (headers_sent($file, $line)) {
        throw new RuntimeException("Cannot send the export: output already started at {$file}:{$line}");
    }
    if (ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', '0'); // وإلا لا يطابق Content-Length المحتوى المضغوط
    }
    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: ' . xlsx_content_disposition($filename, $ext));
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}
