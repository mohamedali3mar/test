<?php
declare(strict_types=1);

/*
 * اختبارات ملفات PDF (mPDF): الفاتورة والإذن والتحويل والملغاة، وتقرير بخمسة آلاف صف،
 * واستخراج النص بالترتيب المنطقي، والأمان (التهريب ومنع جلب الموارد)، وترويسات الإرسال.
 *
 * التشغيل: WOOD_TEST_DB=wood_pdf_test php tests/pdf.php
 * يتطلب poppler-utils (pdftotext و pdfinfo و pdffonts و pdftoppm).
 * الملفات وصور الصفحات (110 نقطة/بوصة) في tests/output/pdf/ للفحص البصري.
 */

require __DIR__ . '/lib.php';
require __DIR__ . '/pdf_fixtures.php';

ini_set('memory_limit', '256M');
const PDF_OUT = __DIR__ . '/output/pdf';
@mkdir(PDF_OUT, 0775, true);
foreach (glob(PDF_OUT . '/*.{pdf,png}', GLOB_BRACE) ?: [] as $old) {
    unlink($old);
}
$GLOBALS['APP_CONFIG']['pdf_temp_dir'] = __DIR__ . '/output/pdf-cache';
foreach (['pdftotext', 'pdfinfo', 'pdffonts', 'pdftoppm'] as $tool) {
    if (trim((string) shell_exec('command -v ' . $tool)) === '') {
        fwrite(STDERR, "missing {$tool}: apt-get install poppler-utils\n");
        exit(2);
    }
}

// أي تحذير PHP أثناء إنشاء الملفات (من المكتبة أو من الكود) يُسجَّل ويفشل الاختبار في النهاية
$GLOBALS['PDF_WARNINGS'] = [];
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // مكتوم عمدًا بـ @
    }
    $GLOBALS['PDF_WARNINGS'][] = "{$msg} ({$file}:{$line})";
    return true;
});

/** نص للمقارنة: NFKC (أشكال الحروف المتصلة إلى الحروف الأصلية)، بدون رموز الاتجاه، ومسافات موحدة */
function pdf_norm(string $s): string
{
    $n = Normalizer::normalize($s, Normalizer::FORM_KC);
    $s = is_string($n) ? $n : $s;
    $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s) ?? $s;
    return preg_replace('/[^\S\f]+/u', ' ', $s) ?? $s;
}

function pdf_save(string $name, string $bytes): string
{
    $path = PDF_OUT . '/' . $name . '.pdf';
    file_put_contents($path, $bytes);
    return $path;
}

function pdf_text_of(string $path): string
{
    return pdf_norm((string) shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null'));
}

/** @return array<string,string> */
function pdf_info(string $path): array
{
    $out = [];
    foreach (explode("\n", (string) shell_exec('pdfinfo -enc UTF-8 ' . escapeshellarg($path) . ' 2>/dev/null')) as $line) {
        if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
            $out[trim($m[1])] = trim($m[2]);
        }
    }
    return $out;
}

function pdf_png(string $path, string $prefix, int $first = 1, int $last = 1): void
{
    shell_exec(sprintf('pdftoppm -r 110 -png -f %d -l %d %s %s', $first, $last, escapeshellarg($path), escapeshellarg(PDF_OUT . '/' . $prefix)));
}

function check_pdf_file(string $label, string $bytes): void
{
    check("{$label}: يبدأ بـ %PDF-1.", str_starts_with($bytes, '%PDF-1.'));
    check("{$label}: ينتهي بـ %%EOF", str_ends_with(rtrim($bytes), '%%EOF'));
    check("{$label}: بلا JavaScript أو تشغيل ملفات", !preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|URI)\b#', $bytes));
}

/**
 * النص موجود في الملف. pdftotext يعيد ترتيب الأجزاء مختلطة الاتجاه (أرقام أو لاتيني داخل سطر عربي)
 * بطريقته ويقلب الأقواس، لذلك إن لم يوجد النص حرفيًا يُقارن بعد حذف المسافات والأقواس.
 * الترتيب المرئي الصحيح يُفحص بالنظر إلى صور الصفحات.
 */
function check_has(string $label, string $text, string $needle): void
{
    $n = pdf_norm($needle);
    $ok = str_contains($text, $n);
    if (!$ok) {
        $strip = fn (string $s): string => preg_replace('/[\s()\[\]{}<>]+/u', '', $s) ?? $s;
        $ok = str_contains($strip($text), $strip($n));
    }
    check($label, $ok, 'missing: ' . $n);
}

/**
 * كلمات صفحة بمواقعها (pdftotext -bbox): [t => النص بالترتيب المنطقي، x, x2, y].
 * pdftotext يعطي الكلمة العربية بترتيبها المرئي فتُعكس، والأرقام واللاتيني كما هي.
 * @return array<int,array{t:string,x:float,x2:float,y:float}>
 */
function pdf_words(string $path, int $page = 1): array
{
    $xml = (string) shell_exec(sprintf('pdftotext -bbox -f %d -l %d %s - 2>/dev/null', $page, $page, escapeshellarg($path)));
    preg_match_all('#<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="[\d.]+">(.*?)</word>#u', $xml, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $w) {
        $t = pdf_norm(html_entity_decode($w[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/[\x{0621}-\x{064A}]/u', $t)) {
            $t = implode('', array_reverse(mb_str_split($t)));
        }
        $out[] = ['t' => $t, 'x' => (float) $w[1], 'x2' => (float) $w[3], 'y' => (float) $w[2]];
    }
    return $out;
}

/**
 * هل الكلمة هي النص المطلوب؟ داخل الكلمة العربية يرتب pdftotext الحروف بموضعها الأفقي، فقد يتبادل
 * حرفان متداخلان (مثل «شر»)، لذلك تُقارن الكلمة العربية بحروفها دون ترتيبها. ترتيب الكلمات يُفحص بالمواضع.
 */
function pdf_word_is(array $w, string $t): bool
{
    $t = pdf_norm($t);
    if ($w['t'] === $t) {
        return true;
    }
    if (!preg_match('/[\x{0621}-\x{064A}]/u', $t)) {
        return false;
    }
    $a = mb_str_split($w['t']);
    $b = mb_str_split($t);
    sort($a);
    sort($b);
    return $a === $b;
}

/** أول كلمة بنص معين */
function pdf_word(array $words, string $t): ?array
{
    foreach ($words as $w) {
        if (pdf_word_is($w, $t)) {
            return $w;
        }
    }
    return null;
}

/**
 * ترتيب الكلمات مرئيًا: $rtl = كل كلمة يسار سابقتها (العربية)، وإلا يمينها (اللاتينية).
 * $sameLine يشترط أن تكون كلها في سطر واحد. تُجرّب كل مواضع الكلمة الأولى.
 */
function check_visual_order(string $label, array $words, array $sequence, bool $rtl, bool $sameLine = true): void
{
    $detail = 'not found: ' . $sequence[0];
    foreach ($words as $first) {
        if (!pdf_word_is($first, $sequence[0])) {
            continue;
        }
        $prev = $first;
        foreach (array_slice($sequence, 1) as $t) {
            $next = null;
            foreach ($words as $w) {
                $dirOk = $rtl ? $w['x'] < $prev['x'] : $w['x'] > $prev['x'];
                if ($dirOk && (!$sameLine || abs($w['y'] - $prev['y']) < 3) && pdf_word_is($w, $t)) {
                    $next = $w;
                    break;
                }
            }
            if ($next === null) {
                $detail = sprintf('after «%s» (x=%.1f) no «%s» in order', $prev['t'], $prev['x'], $t);
                continue 2;
            }
            $prev = $next;
        }
        check($label, true);
        return;
    }
    check($label, false, $detail);
}

/** النص موجود حرفيًا كما هو (بلا تساهل) */
function check_exact(string $label, string $text, string $needle): void
{
    $n = pdf_norm($needle);
    check($label, str_contains($text, $n), 'missing: ' . $n);
}

function check_no_pua(string $label, string $text): void
{
    preg_match_all('/[\x{E000}-\x{F8FF}]/u', $text, $m);
    check($label, !$m[0], count($m[0]) . ' PUA characters');
}

$timings = [];
$timed = function (string $name, callable $fn) use (&$timings): string {
    $t = microtime(true);
    $bytes = $fn();
    $timings[$name] = microtime(true) - $t;
    return $bytes;
};

/* ---------------------------------------------------------------- */
section('تحميل المكتبة عند الحاجة فقط');
check('mPDF غير محمّل بعد bootstrap', !class_exists('Mpdf\Mpdf', false));
check('دوال PDF معرّفة من bootstrap', function_exists('pdf_document') && function_exists('pdf_report') && function_exists('pdf_send'));

$pdo = fresh_database();
$ids = pdf_seed_fixtures($pdo);
$company = PDF_FIXTURE_COMPANY;

/* ---------------------------------------------------------------- */
section('فاتورة بيع بثلاثة أسطر (أرقام عربية)');
$doc = find_document($pdo, $ids['sale']);
$lines = document_lines($pdo, $ids['sale']);
$bytes = $timed('فاتورة بيع (أول ملف، يشمل تجهيز بيانات الخط)', fn () => pdf_document($doc, $lines));
check('المكتبة حُمّلت عند أول ملف', class_exists('Mpdf\Mpdf', false));
check_pdf_file('الفاتورة', $bytes);
$salePath = pdf_save('sale', $bytes);
$text = pdf_text_of($salePath);
$info = pdf_info($salePath);
check_eq('صفحة واحدة', '1', $info['Pages'] ?? '');
check('مقاس A4 بالطول', str_contains($info['Page size'] ?? '', '595.') && str_contains($info['Page size'] ?? '', '841.'), $info['Page size'] ?? '');
check_exact('اسم الشركة', $text, $company);
check_exact('العنوان «فاتورة بيع» بالترتيب المنطقي', $text, 'فاتورة بيع');
check_exact('اسم العميل', $text, PDF_FIXTURE_CUSTOMER);
check_has('المخزن', $text, 'المخزن الرئيسي');
check_has('رقم الفاتورة في الترويسة', $text, 'فاتورة بيع رقم ' . fmt_int((int) $doc['doc_no']));
foreach (['نوع الخشب', 'العرض', 'التخانة', 'الطول', 'العدد', 'الحجم (م³)', 'القيمة', 'الإجمالي', 'إجمالي القيمة', 'ملاحظات'] as $label) {
    check_has("عنوان «{$label}»", $text, $label);
}
check_has('عنوان السعر مع العملة', $text, 'سعر المتر المكعب (' . $doc['currency'] . ')');
check_has('اسم النوع الطويل (بداية السطر)', $text, 'خشب زان أحمر روماني');
$words = pdf_words($salePath);
check_visual_order('اسم الشركة من اليمين لليسار', $words, ['شركة', 'الأمل', 'لتجارة', 'الأخشاب'], true);
check_visual_order('«فاتورة بيع» من اليمين لليسار', $words, ['فاتورة', 'بيع', 'رقم'], true);
check_visual_order('اسم العميل من اليمين لليسار', $words, ['شركة', 'النجار', 'الحديثة', 'للأثاث'], true);
check_visual_order('ترتيب الأعمدة: الأول في أقصى اليمين', $words, ['م', 'نوع', 'العرض', 'التخانة', 'الطول', 'العدد', 'الحجم', 'سعر', 'القيمة'], true, false);
check_visual_order('صنف لاتيني معزول باتجاهه: «Pine (Finland)» بأقواس صحيحة', $words, ['Pine', '(Finland)'], false);
check('«(Finland)» بلا قوس معكوس', pdf_word($words, '(Finland)') !== null && pdf_word($words, ')Finland(') === null);
foreach ($lines as $l) {
    check_has('قيمة السطر ' . $l['line_no'], $text, fmt_money((string) $l['amount']));
    check_has('سعر السطر ' . $l['line_no'], $text, fmt_money((string) $l['price_per_m3']));
    check_has('حجم السطر ' . $l['line_no'], $text, fmt_volume((string) $l['total_volume_m3']));
    check_has('عدد السطر ' . $l['line_no'], $text, fmt_int((int) $l['quantity']));
    check_has('عرض السطر ' . $l['line_no'] . ' بوحدته', $text, fmt_dim((int) $l['width_um'], $l['width_unit']));
}
check_exact('إجمالي القيمة بالأرقام العربية', $text, fmt_money((string) $doc['total_amount']));
check_has('إجمالي القيمة بالعملة', $text, fmt_money_currency((string) $doc['total_amount'], $doc['currency']));
check_has('إجمالي العدد', $text, fmt_int((int) $doc['total_qty']));
check('الأرقام بالشكل العربي', preg_match('/[٠-٩]/u', $text) === 1 && str_contains($text, '٫'));
check_has('الملاحظات (عربي)', $text, 'يسلم في المخزن الرئيسي صباح السبت.');
check('«<script>alert(1)</script>» كلمة نصية حرفية', pdf_word($words, '<script>alert(1)</script>') !== null);
check('«&» كما هو (لا &amp;)', pdf_word($words, '&') !== null && !str_contains($text, '&amp;'));
check('«<img src=…>» من بيانات المستخدم نص لا وسم', pdf_word($words, '<img') !== null && pdf_word($words, 'src="/etc/passwd">') !== null);
check('لا صور داخل الملف', !str_contains($bytes, '/Subtype /Image'));
check('لا ملاحظة تقريب (الأحجام دقيقة)', !str_contains($text, 'معروضة مقربة'));
check_has('تذييل: صفحة ١ من ١', $text, 'صفحة ١ من ١');
check_has('تذييل: تاريخ الإنشاء', $text, 'تاريخ الإنشاء');
check_exact('تاريخ المستند (اليوم والوقت)', $text, digits(date('H:i', strtotime((string) $doc['created_at']))));
check_no_pua('كل الحروف قابلة للنسخ والبحث (لا رموز PUA)', $text);
check('عنوان الملف في بياناته', str_contains($info['Title'] ?? '', 'فاتورة بيع'), $info['Title'] ?? '');
$fonts = (string) shell_exec('pdffonts ' . escapeshellarg($salePath));
check('خط Cairo مضمن (Regular و Bold)', substr_count($fonts, 'Cairo') >= 2, $fonts);
check('لا خط آخر (كل الحروف من Cairo)', !str_contains($fonts, 'DejaVu'), $fonts);
pdf_png($salePath, 'sale');

/* ---------------------------------------------------------------- */
section('إذن وارد');
$doc = find_document($pdo, $ids['receipt']);
$bytes = $timed('إذن وارد', fn () => pdf_document($doc, document_lines($pdo, $ids['receipt'])));
check_pdf_file('الوارد', $bytes);
$path = pdf_save('receipt', $bytes);
$text = pdf_text_of($path);
check_has('العنوان «إذن وارد»', $text, 'إذن وارد');
check_has('رقم الإذن', $text, 'رقم الإذن');
check_has('المورد', $text, 'مؤسسة الخشب الفنلندي');
check_has('المرجع اللاتيني', $text, 'BL-2026/0457');
check('لا عمود سعر في الوارد', !str_contains($text, 'سعر المتر المكعب'));
check_has('الحجم ١٫٨', $text, fmt_volume((string) $doc['total_volume_m3']));
check_no_pua('لا رموز PUA', $text);
pdf_png($path, 'receipt');

/* ---------------------------------------------------------------- */
section('إذن تحويل');
$doc = find_document($pdo, $ids['transfer']);
$bytes = $timed('إذن تحويل', fn () => pdf_document($doc, document_lines($pdo, $ids['transfer'])));
check_pdf_file('التحويل', $bytes);
$path = pdf_save('transfer', $bytes);
$text = pdf_text_of($path);
check_has('العنوان', $text, 'إذن تحويل بين المخازن');
check_has('من مخزن', $text, 'من مخزن');
check_has('إلى مخزن', $text, 'إلى مخزن');
check_has('المخزن المحوَّل إليه', $text, 'مخزن فرع العاشر من رمضان');
check_has('الملاحظات', $text, 'تحويل لتغطية طلبات الفرع');
check_has('إجمالي العدد ٢٨', $text, fmt_int(28));
pdf_png($path, 'transfer');

/* ---------------------------------------------------------------- */
section('فاتورة ملغاة');
$doc = find_document($pdo, $ids['cancelled']);
$bytes = $timed('فاتورة ملغاة', fn () => pdf_document($doc, document_lines($pdo, $ids['cancelled'])));
check_pdf_file('الملغاة', $bytes);
$path = pdf_save('cancelled', $bytes);
$text = pdf_text_of($path);
check_exact('تنبيه «ملغاة بتاريخ»', $text, 'ملغاة بتاريخ');
check_exact('وقت الإلغاء', $text, digits(date('H:i', strtotime((string) $doc['cancelled_at']))));
check_has('سبب الإلغاء', $text, 'سبب الإلغاء: خطأ في السعر');
check_has('الترويسة تذكر الإلغاء', $text, 'فاتورة بيع رقم ' . fmt_int((int) $doc['doc_no']) . ' (ملغاة)');
check('«ملغاة» في الترويسة والتنبيه', substr_count($text, 'ملغاة') >= 2, (string) substr_count($text, 'ملغاة'));
check('العلامة المائية معتمة بنمط Darken (بلا بقع تداخل)', str_contains($bytes, '/BM /Darken'));
pdf_png($path, 'cancelled');

/* ---------------------------------------------------------------- */
section('حجم معروض مقربًا');
$doc = find_document($pdo, $ids['rounded']);
$bytes = $timed('فاتورة بحجم مقرب', fn () => pdf_document($doc, document_lines($pdo, $ids['rounded'])));
$path = pdf_save('rounded', $bytes);
$text = pdf_text_of($path);
check_has('ملاحظة التقريب', $text, 'الأحجام معروضة مقربة، والقيمة محسوبة من الحجم الدقيق.');
check_has('علامة ≈ مع الحجم', $text, '≈');
check_exact('اسم فيه «سن» (حروف متصلة بلا ربط خاص)', $text, 'ورشة حسن للنجارة');
check_no_pua('لا رموز PUA', $text);
pdf_png($path, 'rounded');

/* ---------------------------------------------------------------- */
section('الأرقام الإنجليزية من الإعدادات');
save_setting($pdo, 'digits', 'western');
reset_settings_cache();
$doc = find_document($pdo, $ids['sale']);
$bytes = $timed('فاتورة بيع (أرقام إنجليزية)', fn () => pdf_document($doc, document_lines($pdo, $ids['sale'])));
$path = pdf_save('sale-western', $bytes);
$text = pdf_text_of($path);
check_has('الإجمالي بالأرقام الإنجليزية', $text, fmt_money_currency((string) $doc['total_amount'], $doc['currency']));
check('القيمة 21,203.17', str_contains($text, '21,203.17'), 'expected 21,203.17');
check('لا أرقام عربية', preg_match('/[٠-٩]/u', $text) === 0);
check_has('رقم الصفحة بالإنجليزية', $text, 'صفحة 1 من 1');
pdf_png($path, 'sale-western');
save_setting($pdo, 'digits', 'arabic');
reset_settings_cache();

/* ---------------------------------------------------------------- */
section('تقرير بخمسة آلاف صف (حد الذاكرة 256M)');
$ds = pdf_sample_report(5000);
gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$memBefore = memory_get_usage(true);
$bytes = $timed('تقرير 5000 صف', fn () => pdf_report($ds));
$peak = memory_get_peak_usage(true);
check_pdf_file('التقرير', $bytes);
$repPath = pdf_save('report-5000', $bytes);
$info = pdf_info($repPath);
$pages = (int) ($info['Pages'] ?? 0);
check('عدد صفحات معقول (150 إلى 400)', $pages >= 150 && $pages <= 400, (string) $pages);
check('بالعرض لأن الأعمدة 9', str_contains($info['Page size'] ?? '', '841.') && str_starts_with($info['Page size'] ?? '', '841'), $info['Page size'] ?? '');
check('الذاكرة القصوى أقل من 200MB', $peak < 200 * 1048576, round($peak / 1048576, 1) . ' MB');
check('الوقت أقل من 60 ثانية', $timings['تقرير 5000 صف'] < 60, round($timings['تقرير 5000 صف'], 1) . ' s');
$text = pdf_text_of($repPath);
$pageTexts = explode("\f", $text);
check_has('العنوان', $pageTexts[0], 'تقرير الحركات');
check_exact('الوصف (الفلاتر)', $pageTexts[0], 'كل المخازن');
$words = pdf_words($repPath, 1);
// تاريخ بأرقام إنجليزية بعد حرف عربي يُعرض «01-01-2026» حسب قاعدة Unicode W2 (مثل المتصفح تمامًا)
check('تواريخ الوصف', str_contains($pageTexts[0], '01-01-2026') && str_contains($pageTexts[0], '31-12-2026'));
check_visual_order('ترتيب أعمدة التقرير: الأول في أقصى اليمين', $words, ['التاريخ', 'المستند', 'المخزن', 'نوع', 'المقاس', 'الطرف', 'العدد', 'الحجم', 'القيمة'], true);
// الأرقام بمحاذاة الطرف الأيسر لعمودها: بداية كل قيمة في عمود «القيمة» عند نفس الموضع
$words2 = pdf_words($repPath, 2);
$col = pdf_word($words2, 'القيمة');
$starts = [];
foreach ($words2 as $w) {
    // صفوف الجدول فقط: تحت عنوان العمود وفوق تذييل الصفحة
    if ($col !== null && $w['y'] > $col['y'] + 5 && $w['y'] < 530 && $w['x'] < $col['x2'] + 2 && preg_match('/^[٠-٩]/u', $w['t'])) {
        $starts[(string) round($w['y'])] = min($starts[(string) round($w['y'])] ?? INF, $w['x']);
    }
}
check('أرقام عمود القيمة محاذاة لليسار (صفحة ٢)', count($starts) > 15 && max($starts) - min($starts) < 0.5, count($starts) . ' rows, spread ' . (count($starts) ? round(max($starts) - min($starts), 2) : 0));
check_exact('وقت الإنشاء', $pageTexts[0], 'تاريخ الإنشاء');
check_exact('وقت الإنشاء بالأرقام العربية', $pageTexts[0], '١٤:٣٥');
foreach ($ds['columns'] as $c) {
    check_has('عنوان العمود ' . $c['label'], $pageTexts[0], $c['label']);
}
$withHeading = 0;
$withCompany = 0;
for ($p = 0; $p < $pages; $p++) {
    $withHeading += (int) (str_contains($pageTexts[$p] ?? '', 'نوع الخشب') && str_contains($pageTexts[$p] ?? '', 'المستند'));
    $withCompany += (int) str_contains($pageTexts[$p] ?? '', $company);
}
check_eq('صف عناوين الأعمدة يتكرر في كل صفحة', $pages, $withHeading);
check_eq('اسم الشركة في كل صفحة', $pages, $withCompany);
preg_match_all('/(?:بيع|وارد|تحويل) (\d+)/u', $text, $m);
$nums = array_unique(array_map('intval', $m[1]));
check_eq('كل الصفوف الخمسة آلاف موجودة مرة واحدة', 5000, count($nums));
check_eq('أولها 1 وآخرها 5000', [1, 5000], [min($nums), max($nums)]);
$last = $pageTexts[$pages - 1] ?? '';
check_has('صف الإجمالي في الصفحة الأخيرة', $last, 'الإجمالي');
check_has('إجمالي العدد', $last, fmt_int($ds['totals']['qty']));
check_has('إجمالي الحجم', $last, fmt_volume($ds['totals']['volume']));
check_has('إجمالي القيمة', $last, fmt_money($ds['totals']['amount']));
check_has('تذييل الصفحة الأخيرة', $last, 'صفحة ' . fmt_int($pages) . ' من ' . fmt_int($pages));
check_exact('وقت أول صف بالأرقام العربية', $text, digits(date('H:i', strtotime($ds['rows'][0]['created_at']))));
check_exact('صف بطرف فارغ موجود (وارد 4000)', $text, 'وارد 4000');
check_no_pua('لا رموز PUA', $text);
pdf_png($repPath, 'report-5000', 1, 2);
pdf_png($repPath, 'report-5000-last', $pages, $pages);
printf("  INFO  5000 صف: %.2f ث، %d صفحة، %.1f KB، ذاكرة قصوى %.1f MB (قبل التقرير %.1f MB)\n",
    $timings['تقرير 5000 صف'], $pages, strlen($bytes) / 1024, $peak / 1048576, $memBefore / 1048576);

/* ---------------------------------------------------------------- */
section('تقرير صغير بالطول مع خط احتياطي وقيم سالبة وفارغة');
$ds = pdf_sample_inventory_report();
$bytes = $timed('تقرير الرصيد', fn () => pdf_report($ds));
check_pdf_file('تقرير الرصيد', $bytes);
$path = pdf_save('report-inventory', $bytes);
$info = pdf_info($path);
check('بالطول لأن الأعمدة 5', str_starts_with($info['Page size'] ?? '', '595'), $info['Page size'] ?? '');
$text = pdf_text_of($path);
$words = pdf_words($path);
check_visual_order('اسم بحروف سيريلية عبر الخط الاحتياطي، معزول باتجاهه', $words, ['Дуб', '(oak)'], false);
check('DejaVu Sans مضمن للحروف غير الموجودة في Cairo', str_contains((string) shell_exec('pdffonts ' . escapeshellarg($path)), 'DejaVuSans'));
check_exact('قيمة سالبة', $text, fmt_money('12.50'));
check('علامة السالب ملاصقة للقيمة', pdf_word($words, '-' . fmt_money('12.50')) !== null || pdf_word($words, fmt_money('12.50') . '-') !== null);
check_has('حجم دقيق ١٫٨', $text, fmt_volume('1.8'));
check_has('الإجمالي', $text, fmt_money('2987.50'));
pdf_png($path, 'report-inventory');

/* ---------------------------------------------------------------- */
section('تقرير بلا صفوف، وتقرير بأعمدة كثيرة');
$bytes = pdf_report(['title' => 'تقرير فارغ', 'subtitle' => '', 'columns' => $ds['columns'], 'rows' => [], 'totals' => ['qty' => '0'], 'generated_at' => '2026-10-07 10:00:00']);
$path = pdf_save('report-empty', $bytes);
$text = pdf_text_of($path);
check_has('رسالة «لا توجد بيانات.»', $text, 'لا توجد بيانات.');
check('لا صف إجمالي بلا صفوف', !str_contains($text, 'الإجمالي'));
check_eq('صفحة واحدة', '1', pdf_info($path)['Pages'] ?? '');
pdf_png($path, 'report-empty');

$wideCols = [];
$wideRows = [];
for ($c = 1; $c <= 14; $c++) {
    $wideCols[] = ['key' => 'c' . $c, 'label' => 'عمود رقم ' . $c, 'type' => $c % 2 ? 'text' : 'money', 'align' => $c % 2 ? 'start' : 'end'];
}
for ($r = 1; $r <= 60; $r++) {
    $row = [];
    for ($c = 1; $c <= 14; $c++) {
        $row['c' . $c] = $c % 2 ? 'قيمة نصية طويلة نسبيا ' . $r : (string) (123456.75 * $r);
    }
    $wideRows[] = $row;
}
$bytes = pdf_report(['title' => 'تقرير عريض', 'subtitle' => '14 عمودًا', 'columns' => $wideCols, 'rows' => $wideRows, 'totals' => null, 'generated_at' => '2026-10-07 10:00:00']);
$path = pdf_save('report-wide', $bytes);
$text = pdf_text_of($path);
check_has('كل الأعمدة الأربعة عشر', $text, 'عمود رقم 14');
check_has('آخر صف', $text, 'قيمة نصية طويلة نسبيا 60');
pdf_png($path, 'report-wide');
$rejected = false;
try {
    pdf_report(['title' => 'x', 'columns' => [], 'rows' => []]);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('تقرير بلا أعمدة يُرفض بوضوح', $rejected);

/* ---------------------------------------------------------------- */
section('الأمان: لا جلب لأي مورد محلي أو خارجي');
$png = PDF_OUT . '/probe.png';
$im = imagecreatetruecolor(8, 8);
imagepng($im, $png);
$mpdf = pdf_engine();
$t = microtime(true);
$mpdf->WriteHTML('<p>اختبار</p><img src="' . $png . '"><img src="file://' . $png . '"><img src="https://example.invalid/x.png">'
    . '<link rel="stylesheet" href="https://example.invalid/x.css"><div style="background-image: url(' . $png . ')">x</div>');
$bytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
unlink($png);
check('لا صورة محلية أو خارجية داخل الملف', !str_contains($bytes, '/Subtype /Image'));
check('بلا انتظار شبكة', microtime(true) - $t < 3, round(microtime(true) - $t, 2) . ' s');

/* ---------------------------------------------------------------- */
section('أسماء الملفات');
[$u, $a] = pdf_filenames('فاتورة بيع 15.pdf');
check_eq('الاسم العربي', 'فاتورة بيع 15.pdf', $u);
check_eq('البديل ASCII', 'document-15.pdf', $a);
[$u, $a] = pdf_filenames("Report \"x\"\r\n;../a/b.PDF");
check('لا علامات خطرة في الاسم', !preg_match('/["\r\n;\/\\\\]/', $u . $a), $u . ' | ' . $a);
check('ينتهي بـ .pdf', str_ends_with($u, '.pdf') && str_ends_with($a, '.pdf'));
check_eq('اسم فارغ', ['document.pdf', 'document.pdf'], pdf_filenames(''));
check_eq('اسم مقترح للمستند', 'فاتورة بيع 1.pdf', pdf_document_filename(['kind' => 'sale', 'doc_no' => 1]));

/* ---------------------------------------------------------------- */
section('pdf_send عبر خادم PHP محلي مؤقت');
$router = __DIR__ . '/output/pdf-send-router.php';
file_put_contents($router, "<?php\ndeclare(strict_types=1);\ndefine('APP_ROOT', " . var_export(dirname(__DIR__) . '/public_html/app', true) . ");\n"
    . "require APP_ROOT . '/lib/core.php';\nrequire APP_ROOT . '/lib/pdf.php';\n"
    . "ob_start();\necho 'stray output before the PDF';\n"
    . "pdf_send(\"%PDF-1.4\\n%test\\n%%EOF\\n\", 'فاتورة بيع 15.pdf', (\$_GET['inline'] ?? '') === '1');\n");
$srv = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr((string) stream_socket_get_name($srv, false), ':'), 1);
fclose($srv);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$fetch = function (string $query) use ($port): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $body = (string) @file_get_contents('http://127.0.0.1:' . $port . '/' . $query, false, $ctx);
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $headers[strtolower(trim($k))] = trim($v);
        } else {
            $headers['status'] = $h;
        }
    }
    return [$headers, $body];
};
[$hd, $body] = $fetch('?inline=1');
check('الحالة 200', str_contains($hd['status'] ?? '', '200'), $hd['status'] ?? 'no response');
check_eq('Content-Type', 'application/pdf', $hd['content-type'] ?? '');
check_eq('Content-Disposition (عرض)', "inline; filename=\"document-15.pdf\"; filename*=UTF-8''" . rawurlencode('فاتورة بيع 15.pdf'), $hd['content-disposition'] ?? '');
check('Cache-Control: no-store', str_contains($hd['cache-control'] ?? '', 'no-store'), $hd['cache-control'] ?? '');
check_eq('X-Content-Type-Options', 'nosniff', $hd['x-content-type-options'] ?? '');
check_eq('المحتوى هو الملف فقط (المخرجات السابقة حُذفت)', "%PDF-1.4\n%test\n%%EOF\n", $body);
check_eq('Content-Length', (string) strlen($body), $hd['content-length'] ?? '');
[$hd] = $fetch('?inline=0');
check('Content-Disposition (تحميل)', str_starts_with($hd['content-disposition'] ?? '', 'attachment; filename="document-15.pdf"'), $hd['content-disposition'] ?? '');
proc_terminate($proc);
proc_close($proc);
unlink($router);

/* ---------------------------------------------------------------- */
section('تحذيرات PHP');
restore_error_handler();
check('لا تحذيرات أو ملاحظات PHP أثناء إنشاء الملفات', !$GLOBALS['PDF_WARNINGS'], implode(' | ', array_slice($GLOBALS['PDF_WARNINGS'], 0, 3)));

echo "\nالأوقات:\n";
foreach ($timings as $name => $sec) {
    printf("  %-48s %6.2f s\n", $name, $sec);
}
echo "\nصور الصفحات للفحص البصري:\n";
foreach (glob(PDF_OUT . '/*.png') ?: [] as $f) {
    echo '  ' . $f . "\n";
}
finish();
