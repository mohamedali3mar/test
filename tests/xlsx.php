<?php
declare(strict_types=1);

/*
 * اختبارات تصدير Excel وCSV ‏(app/lib/xlsx.php). لا تحتاج قاعدة بيانات.
 * التشغيل: php tests/xlsx.php            (ويفضل: php -d memory_limit=128M tests/xlsx.php لمحاكاة الاستضافة)
 *
 * - تحويل القيم (أرقام دقيقة، تواريخ Excel بدون إزاحة توقيت، تنظيف النصوص، أسماء الأوراق والملفات)
 * - CSV: BOM وRFC 4180 والحماية من حقن الصيغ
 * - xlsx: صحة الحزمة والأجزاء، XML سليم، RTL، تجميد الرأس، الفلتر، الدمج، التنسيقات، عدم وجود صيغ
 * - الرجوع إلى CSV بدون ext-zip (عملية PHP منفصلة بدون zip)، ومجلد مؤقت بديل
 * - ترويسات التحميل الفعلية عبر خادم PHP المدمج على منفذ محلي عشوائي
 * - الأداء: 20,000 صف (الوقت والذاكرة)
 * - قارئ مستقل: tests/xlsx_check.py ‏(openpyxl، وLibreOffice إن وجد)
 * - اختياري: XLSX_XSD_DIR=<مجلد مخططات ECMA-376> للتحقق من الأجزاء بالمخطط الرسمي (sml.xsd)
 */

require __DIR__ . '/lib.php';

$OUT = __DIR__ . '/output';
const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

$cache = &settings_cache();
$cache = ['company_name' => 'شركة <الأخشاب> & أولاده "المتحدة"', 'digits' => 'western'] + DEFAULT_SETTINGS;

function expect_exception(string $label, string $class, callable $fn): void
{
    try {
        $fn();
        check($label, false, 'no exception');
    } catch (Throwable $e) {
        check($label, $e instanceof $class, get_class($e) . ': ' . $e->getMessage());
    }
}

/** أجزاء الحزمة: [اسم => محتوى] مع ترتيبها */
function zip_parts(string $path, bool $withSheet = true): array
{
    $z = new ZipArchive();
    $rc = $z->open($path, ZipArchive::CHECKCONS);
    if ($rc !== true) {
        return ['__error' => (string) $rc];
    }
    $parts = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        $parts[$name] = ($withSheet || $name !== 'xl/worksheets/sheet1.xml') ? $z->getFromIndex($i) : '';
    }
    $z->close();
    return $parts;
}

function load_xml(string $xml): ?DOMDocument
{
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return ($ok && !$errors) ? $doc : null;
}

function xp(DOMDocument $doc): DOMXPath
{
    $x = new DOMXPath($doc);
    $x->registerNamespace('m', NS_MAIN);
    return $x;
}

/** خلايا الورقة: [ref => ['t' => نوع, 's' => رقم التنسيق, 'v' => قيمة <v> أو نص <is>, 'f' => هل بها صيغة]] */
function sheet_cells(DOMDocument $doc): array
{
    $x = xp($doc);
    $cells = [];
    foreach ($x->query('//m:sheetData/m:row/m:c') as $c) {
        /** @var DOMElement $c */
        $t = $c->getAttribute('t');
        $v = $t === 'inlineStr' ? $x->evaluate('string(m:is/m:t)', $c) : ($x->query('m:v', $c)->length ? $x->evaluate('string(m:v)', $c) : null);
        $cells[$c->getAttribute('r')] = ['t' => $t, 's' => (int) $c->getAttribute('s'), 'v' => $v, 'f' => $x->query('m:f', $c)->length > 0];
    }
    return $cells;
}

/** معلومات تنسيق كل xf: رمز الرقم، الخط العريض، الخلفية، الحدود، المحاذاة */
function style_info(DOMDocument $styles): array
{
    $x = xp($styles);
    $fmts = [0 => 'General', 3 => '#,##0', 4 => '#,##0.00'];
    foreach ($x->query('//m:numFmts/m:numFmt') as $f) {
        $fmts[(int) $f->getAttribute('numFmtId')] = $f->getAttribute('formatCode');
    }
    $fonts = [];
    foreach ($x->query('//m:fonts/m:font') as $f) {
        $fonts[] = ['b' => $x->query('m:b', $f)->length > 0, 'sz' => $x->evaluate('string(m:sz/@val)', $f)];
    }
    $fills = [];
    foreach ($x->query('//m:fills/m:fill') as $f) {
        $fills[] = $x->evaluate('string(m:patternFill/m:fgColor/@rgb)', $f);
    }
    $borders = [];
    foreach ($x->query('//m:borders/m:border') as $b) {
        $borders[] = ['top' => $x->evaluate('string(m:top/@style)', $b), 'bottom' => $x->evaluate('string(m:bottom/@style)', $b)];
    }
    $out = [];
    foreach ($x->query('//m:cellXfs/m:xf') as $xf) {
        $out[] = [
            'fmt' => $fmts[(int) $xf->getAttribute('numFmtId')] ?? '?' . $xf->getAttribute('numFmtId'),
            'font' => $fonts[(int) $xf->getAttribute('fontId')] ?? null,
            'fill' => $fills[(int) $xf->getAttribute('fillId')] ?? null,
            'border' => $borders[(int) $xf->getAttribute('borderId')] ?? null,
            'h' => $x->evaluate('string(m:alignment/@horizontal)', $xf),
            'wrap' => $x->evaluate('string(m:alignment/@wrapText)', $xf) === '1',
        ];
    }
    return $out;
}

/** يتحقق أن أبناء العنصر الجذر بالترتيب الذي يفرضه المخطط (Excel يرفض الترتيب الخاطئ) */
function children_in_order(DOMDocument $doc, array $order): bool
{
    $last = -1;
    foreach ($doc->documentElement->childNodes as $n) {
        if (!$n instanceof DOMElement) {
            continue;
        }
        $pos = array_search($n->localName, $order, true);
        if ($pos === false || $pos < $last) {
            return false;
        }
        $last = $pos;
    }
    return true;
}

function reset_peak(): void
{
    if (function_exists('memory_reset_peak_usage')) { // PHP 8.2+
        memory_reset_peak_usage();
    }
}

function temp_leftovers(): int
{
    return count(glob(sys_get_temp_dir() . '/wood-xlsx-*') ?: []) + count(glob(APP_ROOT . '/storage/wood-xlsx-*') ?: []);
}

/* ================================================================ */
section('تحويل القيم الرقمية');
check_eq('حجم 18 خانة يُقص إلى 0.15', '0.15', xlsx_number('0.150000000000000000', 'volume'));
check_eq('حجم يُقرب إلى 9 خانات (نصف لأعلى)', '0.000000001', xlsx_number('0.000000000500000000', 'volume'));
check_eq('حجم أصغر من 9 خانات يصبح 0', '0', xlsx_number('0.000000000000000001', 'volume'));
check_eq('حجم كبير', '1234.567891235', xlsx_number('1234.567891234567890000', 'volume'));
check_eq('حجم سالب', '-0.5', xlsx_number('-0.500000000000000000', 'volume'));
check_eq('سالب صفري يفقد الإشارة', '0', xlsx_number('-0.0000000001', 'volume'));
check_eq('مبلغ بدون تقريب', '3000', xlsx_number('3000.00', 'money'));
check_eq('مبلغ بكسور', '12345678.9', xlsx_number('12345678.90', 'money'));
check_eq('مبلغ 3 خانات لا يُقرب', '20000.505', xlsx_number('20000.505', 'money'));
check_eq('عدد صحيح int', '4000000000', xlsx_number(4000000000, 'int'));
check_eq('أصفار بادئة', '42', xlsx_number('00042', 'int'));
check_eq('مسافات حول الرقم', '12.5', xlsx_number(' 12.50 ', 'money'));
check_eq('عدد كبير جدًا كما هو (بدون float)', '123456789012345678901234567890', xlsx_number('123456789012345678901234567890', 'int'));
check_eq('float يُقبل', '0.3', xlsx_number(0.1 + 0.2, 'money'));
foreach (['abc', '1e3', '+5', '.5', '5.', '1,000', '', '١٢', '0x1A'] as $bad) {
    check('ليس رقمًا: ' . var_export($bad, true), xlsx_number($bad, 'int') === null);
}
check('NAN/INF ليست أرقامًا', xlsx_number(NAN, 'int') === null && xlsx_number(INF, 'int') === null);

section('تواريخ Excel (نظام 1900، نفس الوقت المحلي)');
$serial = static fn(string $s): ?string => ($p = xlsx_datetime_parts($s)) === null ? null : xlsx_serial($p);
check_eq('1900-03-01 = 61', '61', $serial('1900-03-01 00:00:00'));
check_eq('1970-01-01 = 25569', '25569', $serial('1970-01-01 00:00:00'));
check_eq('2000-01-01 = 36526', '36526', $serial('2000-01-01'));
check_eq('2026-10-07 15:30', '46302.645833333333', $serial('2026-10-07 15:30:00'));
check_eq('منتصف اليوم بالضبط', '46302.5', $serial('2026-10-07 12:00:00'));
check_eq('آخر ثانية في اليوم', '46302.999988425926', $serial('2026-10-07 23:59:59'));
check_eq('وقت غير موجود محليًا (بداية التوقيت الصيفي بالقاهرة) لا يُزاح', '46136.020833333333', $serial('2026-04-24 00:30:00'));
check_eq('صيغة T وبدون ثوانٍ', '46302.645833333333', $serial('2026-10-07T15:30'));
$tzBefore = date_default_timezone_get();
date_default_timezone_set('America/New_York');
check_eq('المنطقة الزمنية للخادم لا تغير القيمة', '46302.645833333333', $serial('2026-10-07 15:30:00'));
date_default_timezone_set('Pacific/Kiritimati');
check_eq('ولا منطقة +14', '46302.645833333333', $serial('2026-10-07 15:30:00'));
date_default_timezone_set($tzBefore);
foreach (['2026-02-30 10:00:00', '2026-10-07 24:00:00', '2026-10-07 10:60:00', '1899-12-31 00:00:00', '1900-02-28 00:00:00', 'أمس', '0000-00-00 00:00:00', '2026-10-07 15:30:00 extra'] as $bad) {
    check('تاريخ مرفوض يُكتب نصًا: ' . $bad, xlsx_datetime_parts($bad) === null);
}

section('تنظيف النصوص');
check_eq('رموز التحكم تُحذف', "خشب  موسكي", xlsx_text("خشب\x01\x02 \x1F موسكي\x7F"));
check_eq('C1 وU+FFFE/FFFF تُحذف', 'ab', xlsx_text("a\u{0085}\u{009F}\u{FFFE}\u{FFFF}b"));
check_eq('CRLF وCR تصبح LF، وTab يبقى', "a\nb\nc\td", xlsx_text("a\r\nb\rc\td"));
check_eq('UTF-8 التالف يُصلح', 'x??y', xlsx_text("x\xff\xfey"));
check_eq('عربي بتشكيل ورموز خارج BMP كما هي', 'خَشَب 🌲 م³', xlsx_text('خَشَب 🌲 م³'));
check_eq('int', '5', xlsx_text(5));
expect_exception('مصفوفة في خلية مرفوضة', InvalidArgumentException::class, fn() => xlsx_text(['x']));
check_eq('حد Excel لطول الخلية', XLSX_MAX_CELL_CHARS, mb_strlen(xlsx_cell_text(str_repeat('x', 40000))));
check('حد الطول بوحدات UTF-16 مع رموز خارج BMP', strlen(mb_convert_encoding(xlsx_cell_text(str_repeat('🌲', 20000)), 'UTF-16LE', 'UTF-8')) <= 2 * XLSX_MAX_CELL_CHARS);
check_eq('تهريب _xHHHH_ ليبقى النص حرفيًا', '<t>_x005F_x0041_ &amp; &lt;b&gt;</t>', xlsx_t('_x0041_ & <b>'));
check_eq('xml:space للمسافات الطرفية', '<t xml:space="preserve"> a</t>', xlsx_t(' a'));

section('أسماء الأعمدة والأوراق والملفات');
check('حروف الأعمدة', [xlsx_col(1), xlsx_col(26), xlsx_col(27), xlsx_col(702), xlsx_col(703), xlsx_col(16384)] === ['A', 'Z', 'AA', 'ZZ', 'AAA', 'XFD']);
check_eq('اسم ورقة بدون رموز ممنوعة', 'تقرير المخزون الكل الفروع', xlsx_sheet_name('تقرير [المخزون]: الكل/الفروع?*\\'));
check_eq('اسم ورقة طويل يُقص عند حد كلمة', 'تقرير المبيعات كل المخازن', xlsx_sheet_name('تقرير المبيعات: كل المخازن أكتوبر'));
check_eq('كلمات قصيرة: 8 كلمات كاملة = 31 حرفًا', trim(str_repeat('خشب ', 8)), xlsx_sheet_name(str_repeat('خشب ', 20)));
check_eq('لا تُقص كلمة من المنتصف', trim(str_repeat('خشب ', 7)), xlsx_sheet_name(str_repeat('خشب ', 7) . 'زانروماني'));
check_eq('كلمة واحدة طويلة تُقص عند 31', 31, mb_strlen(xlsx_sheet_name(str_repeat('خ', 50) . ' ب')));
check_eq('31 حرفًا بالضبط تبقى كما هي', str_repeat('خ', 31), xlsx_sheet_name(str_repeat('خ', 31)));
check_eq('الحد عند نهاية كلمة تمامًا', str_repeat('خ', 31), xlsx_sheet_name(str_repeat('خ', 31) . ' بقية'));
check_eq('بدون \' في الطرفين', "Tom's", xlsx_sheet_name("'Tom's'"));
check_eq('اسم فارغ', 'تقرير', xlsx_sheet_name(" \n "));
check_eq('History محجوز', 'تقرير', xlsx_sheet_name('history'));
check_eq('رموز خارج BMP تحسب وحدتين', 15, mb_strlen(xlsx_sheet_name(str_repeat('🌲', 20))));
check_eq('اسم ملف عربي وبديل ASCII', ['تقرير المخزون 2026-10-07.xlsx', 'export-2026-10-07.xlsx'], xlsx_download_names('تقرير المخزون 2026-10-07', 'xlsx'));
check_eq('امتداد قديم يُستبدل', ['Stock report.csv', 'Stock-report.csv'], xlsx_download_names('Stock report.xlsx', 'csv'));
check_eq('مسار يُحذف', ['etc passwd.xlsx', 'etc-passwd.xlsx'], xlsx_download_names('../../etc/passwd', 'xlsx'));
check_eq('رموز اتجاه النص (تزوير الامتداد) تُحذف', ['evil exe.xlsx', 'evil-exe.xlsx'], xlsx_download_names("evil\u{202E}exe", 'xlsx'));
check_eq('اسم فارغ', ['export.csv', 'export.csv'], xlsx_download_names('', 'csv'));
$cd = xlsx_content_disposition("تقرير\r\nSet-Cookie: x=1", 'xlsx');
check('لا حقن ترويسات في اسم الملف', !preg_match('/[\r\n]/', $cd) && str_contains($cd, 'filename="Set-Cookie-x-1.xlsx"'), $cd);
check('filename* بصيغة RFC 5987', preg_match("/; filename\\*=UTF-8''([A-Za-z0-9%._~!#$&+^`|-]+)\\z/", $cd, $m) === 1
    && !preg_match('/[^\x21-\x7E ]/', $cd)
    && rawurldecode($m[1]) === 'تقرير Set-Cookie x=1.xlsx', $cd);

section('مخالفة عقد البيانات');
$cols1 = [['key' => 'a', 'label' => 'أ', 'type' => 'text', 'align' => 'start']];
expect_exception('بدون أعمدة', InvalidArgumentException::class, fn() => csv_build(['columns' => [], 'rows' => []]));
expect_exception('نوع عمود غير معروف', InvalidArgumentException::class, fn() => csv_build(['columns' => [['key' => 'a', 'label' => 'a', 'type' => 'float']], 'rows' => []]));
expect_exception('محاذاة غير معروفة', InvalidArgumentException::class, fn() => csv_build(['columns' => [['key' => 'a', 'label' => 'a', 'align' => 'left']], 'rows' => []]));
expect_exception('مفتاح مكرر', InvalidArgumentException::class, fn() => csv_build(['columns' => [$cols1[0], $cols1[0]], 'rows' => []]));
expect_exception('صف ليس مصفوفة (CSV)', InvalidArgumentException::class, fn() => csv_build(['columns' => $cols1, 'rows' => ['x']]));
expect_exception('صف ليس مصفوفة (xlsx)', InvalidArgumentException::class, fn() => xlsx_build(['columns' => $cols1, 'rows' => [['a' => 1], 'x']]));
expect_exception('قيمة غير قياسية في خلية', InvalidArgumentException::class, fn() => xlsx_build(['columns' => $cols1, 'rows' => [['a' => new stdClass()]]]));

/* ================================================================ */
$long = str_repeat('ملاحظة طويلة عن شحنة الخشب الواردة من الميناء، ', 8);
$sample = [
    'title' => 'تقرير المبيعات: كل المخازن [أكتوبر]',
    'subtitle' => 'المخزن: الكل، النوع: زان، من 2026-10-01 إلى 2026-10-07',
    'columns' => [
        ['key' => 'name', 'label' => 'الصنف', 'type' => 'text', 'align' => 'start'],
        ['key' => 'size', 'label' => 'المقاس', 'type' => 'text', 'align' => 'start'],
        ['key' => 'qty', 'label' => 'عدد القطع', 'type' => 'int', 'align' => 'end'],
        ['key' => 'vol', 'label' => 'الحجم (م³)', 'type' => 'volume', 'align' => 'end'],
        ['key' => 'price', 'label' => 'سعر المتر', 'type' => 'money', 'align' => 'end'],
        ['key' => 'amount', 'label' => 'القيمة', 'type' => 'money', 'align' => 'end'],
        ['key' => 'at', 'label' => 'التاريخ', 'type' => 'date', 'align' => 'start'],
        ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text', 'align' => 'start'],
    ],
    'rows' => [
        ['name' => 'زان روماني', 'size' => 'عرض 10 سم × تخانة 5 سم × طول 3 متر', 'qty' => 10, 'vol' => '0.150000000000000000',
            'price' => '20000.00', 'amount' => '3000.00', 'at' => '2026-10-07 15:30:00', 'notes' => '=1+2'],
        ['name' => '<script>alert(1)</script> & "x", y', 'size' => null, 'qty' => '1234567', 'vol' => '0.000000000500000000',
            'price' => '0.00', 'amount' => '0.00', 'at' => '2026-04-24 00:30:00', 'notes' => '+SUM(A1:A2)'],
        ['name' => "خشب\x01\x02 \x1F موسكي\x7F", 'qty' => 0, 'vol' => '0.000000000000000001', 'price' => null, 'amount' => null,
            'at' => '2026-10-07', 'notes' => '-2+3'],
        ['name' => '  مسافات في الطرفين  ', 'qty' => '-5', 'vol' => '1234.567891234567890000', 'price' => '10000000.00',
            'amount' => '12345678.90', 'at' => '2026-02-30 10:00:00', 'notes' => '@SUM(1)'],
        ['name' => "سطر أول\r\nسطر ثانٍ\rسطر ثالث", 'qty' => 'abc', 'vol' => '98765.432100000000000000', 'price' => '1.5',
            'amount' => '0.05', 'at' => '1899-12-31 00:00:00', 'notes' => "\tTab أولًا"],
        ['name' => '_x0041_ literal', 'qty' => 4000000000, 'vol' => '0', 'price' => '999.99', 'amount' => '-150.25', 'at' => null, 'notes' => $long],
        ['name' => '🌲 emoji خشب', 'notes' => "invalid utf8 \xff\xfe end"],
        ['name' => str_repeat('x', 40000), 'qty' => '00042', 'vol' => '-0.500000000000000000', 'price' => '', 'amount' => ' 12.5 ',
            'at' => '2026-10-07 23:59:59', 'notes' => '=HYPERLINK("http://evil.example","x")'],
    ],
    'totals' => ['qty' => '4001234619', 'vol' => '99999.123456789123456789', 'amount' => '12348873.70'],
    'generated_at' => '2026-10-07 15:31:12',
];

section('CSV');
$csv = csv_build($sample);
check('يبدأ بـ BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
check('أسطر CRLF', str_starts_with(substr($csv, 3), "الصنف,المقاس,عدد القطع,الحجم (م³),سعر المتر,القيمة,التاريخ,ملاحظات\r\n"));
$fh = fopen('php://memory', 'w+b');
fwrite($fh, substr($csv, 3));
rewind($fh);
$parsed = [];
while (($line = fgetcsv($fh, null, ',', '"', '')) !== false) {
    $parsed[] = $line;
}
check_eq('عدد الأسطر: رأس + 8 + إجمالي', 10, count($parsed));
check('كل الأسطر 8 حقول', count(array_unique(array_map('count', $parsed))) === 1 && count($parsed[0]) === 8);
check_eq('صف 1', ['زان روماني', 'عرض 10 سم × تخانة 5 سم × طول 3 متر', '10', '0.15', '20000.00', '3000.00', '2026-10-07 15:30:00', "'=1+2"], $parsed[1]);
check_eq('صف 2: اقتباس الفاصلة والعلامات المزدوجة', ['<script>alert(1)</script> & "x", y', '', '1234567', '0.000000001', '0.00', '0.00', '2026-04-24 00:30:00', "'+SUM(A1:A2)"], $parsed[2]);
check_eq('صف 3', ['خشب  موسكي', '', '0', '0', '', '', '2026-10-07 00:00:00', "'-2+3"], $parsed[3]);
check_eq('صف 4: سالب رقمي بلا حماية، تاريخ غير صالح نص', ['  مسافات في الطرفين  ', '', '-5', '1234.567891235', '10000000.00', '12345678.90', '2026-02-30 10:00:00', "'@SUM(1)"], $parsed[4]);
check_eq('صف 5: أسطر متعددة وTab', ["سطر أول\nسطر ثانٍ\nسطر ثالث", '', 'abc', '98765.4321', '1.50', '0.05', '1899-12-31 00:00:00', "'\tTab أولًا"], $parsed[5]);
check_eq('صف 6', ['_x0041_ literal', '', '4000000000', '0', '999.99', '-150.25', '', $long], $parsed[6]);
check_eq('صف 7', ['🌲 emoji خشب', '', '', '', '', '', '', 'invalid utf8 ?? end'], $parsed[7]);
check_eq('صف 8: نص طويل كامل في CSV', 40000, strlen($parsed[8][0]));
check_eq('صف 8: الصيغة محمية', "'=HYPERLINK(\"http://evil.example\",\"x\")", $parsed[8][7]);
check_eq('صف الإجمالي', ['الإجمالي', '', '4001234619', '99999.123456789', '', '12348873.70', '', ''], $parsed[9]);
$risky = 0;
foreach ($parsed as $i => $row) {
    foreach ($row as $j => $v) {
        $numericCol = in_array($j, [2, 3, 4, 5], true) && preg_match('/^-?\d+(\.\d+)?\z/', $v);
        if (!$numericCol && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            $risky++;
        }
    }
}
check_eq('لا خلية نصية تبدأ برمز صيغة', 0, $risky);

/* ================================================================ */
section('xlsx: الحزمة والأجزاء');
$leftBefore = temp_leftovers();
$t = hrtime(true);
$bytes = xlsx_build($sample);
$sampleMs = (hrtime(true) - $t) / 1e6;
$samplePath = $OUT . '/xlsx_sample.xlsx';
file_put_contents($samplePath, $bytes);
file_put_contents($OUT . '/xlsx_sample.csv', $csv);
check('توقيع ZIP', str_starts_with($bytes, "PK\x03\x04"));
check_eq('لا ملفات مؤقتة متبقية', $leftBefore, temp_leftovers());
$parts = zip_parts($samplePath);
check('ZipArchive::CHECKCONS', !isset($parts['__error']), $parts['__error'] ?? '');
$required = ['[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml', 'xl/workbook.xml',
    'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'];
check_eq('الأجزاء المطلوبة فقط', $required, array_keys($parts));
$docs = [];
foreach ($parts as $name => $xml) {
    $docs[$name] = load_xml($xml);
    check("XML سليم: $name", $docs[$name] !== null);
}
$ct = xp($docs['[Content_Types].xml']);
$ct->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');
check('Content-Type للورقة والكتاب والتنسيقات والخصائص', $ct->query('//ct:Override')->length === 5
    && $ct->evaluate('string(//ct:Override[@PartName="/xl/workbook.xml"]/@ContentType)') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml');
foreach (['_rels/.rels' => ['xl/workbook.xml', 'docProps/core.xml', 'docProps/app.xml'], 'xl/_rels/workbook.xml.rels' => ['xl/worksheets/sheet1.xml', 'xl/styles.xml']] as $rels => $targets) {
    $base = $rels === '_rels/.rels' ? '' : 'xl/';
    $found = [];
    foreach ($docs[$rels]->getElementsByTagName('Relationship') as $r) {
        $found[] = $base . $r->getAttribute('Target');
    }
    check("كل العلاقات في $rels تشير لأجزاء موجودة", $found === $targets && !array_diff($found, array_keys($parts)));
}

section('xlsx: الورقة');
$sheet = $docs['xl/worksheets/sheet1.xml'];
$sx = xp($sheet);
check('ترتيب العناصر حسب المخطط', children_in_order($sheet, ['sheetPr', 'dimension', 'sheetViews', 'sheetFormatPr', 'cols', 'sheetData',
    'sheetCalcPr', 'sheetProtection', 'protectedRanges', 'scenarios', 'autoFilter', 'sortState', 'dataConsolidate', 'customSheetViews',
    'mergeCells', 'phoneticPr', 'conditionalFormatting', 'dataValidations', 'hyperlinks', 'printOptions', 'pageMargins', 'pageSetup', 'headerFooter']));
check_eq('rightToLeft', '1', $sx->evaluate('string(//m:sheetView/@rightToLeft)'));
check('تجميد الأسطر حتى الرأس', $sx->evaluate('string(//m:pane/@state)') === 'frozen' && $sx->evaluate('string(//m:pane/@ySplit)') === '4'
    && $sx->evaluate('string(//m:pane/@topLeftCell)') === 'A5' && $sx->evaluate('string(//m:pane/@xSplit)') === '');
check_eq('الفلتر على الرأس والبيانات دون الإجمالي', 'A4:H12', $sx->evaluate('string(//m:autoFilter/@ref)'));
check_eq('dimension', 'A1:H13', $sx->evaluate('string(//m:dimension/@ref)'));
$merges = [];
foreach ($sx->query('//m:mergeCell') as $mc) {
    $merges[] = $mc->getAttribute('ref');
}
check_eq('العنوان والسطر الوصفي مدمجان بعرض الجدول', ['A1:H1', 'A2:H2'], $merges);
$widths = [];
foreach ($sx->query('//m:cols/m:col') as $c) {
    $widths[] = (float) $c->getAttribute('width');
}
check('8 أعمدة بعرض بين 8 و60', count($widths) === 8 && min($widths) >= 8 && max($widths) <= 60, json_encode($widths));
check_eq('عمود النص الطويل يُحد بـ 60', 60.0, $widths[0]);
check('عمود التاريخ يتسع للتاريخ', $widths[6] >= 16);
check('لا توجد أي صيغة في الملف', $sx->query('//m:f')->length === 0);
check('كل النصوص inlineStr (لا shared strings ولا t=str)', $sx->query('//m:c[@t and @t!="inlineStr"]')->length === 0);

$cells = sheet_cells($sheet);
$st = style_info($docs['xl/styles.xml']);
$fmt = static fn(string $ref): string => $st[$cells[$ref]['s']]['fmt'] ?? '?';
check_eq('A1 العنوان', $sample['title'], $cells['A1']['v'] ?? null);
check('العنوان عريض 16', ($st[$cells['A1']['s']]['font'] ?? []) === ['b' => true, 'sz' => '16']);
check_eq('A2 الفلاتر وتاريخ التصدير', $sample['subtitle'] . "\nتاريخ التصدير: 2026-10-07 15:31", $cells['A2']['v'] ?? null);
check('الصف 3 فارغ', !array_filter(array_keys($cells), fn($r) => preg_match('/^[A-Z]+3\z/', $r)));
$heads = [];
$badHead = [];
foreach (range('A', 'H') as $col) {
    $heads[] = $cells[$col . '4']['v'] ?? null;
    $s = $st[$cells[$col . '4']['s'] ?? 0];
    if (!$s['font']['b'] || $s['fill'] !== 'FFF5F5F4' || $s['border']['bottom'] !== 'thin') {
        $badHead[] = $col . ' ' . json_encode($s);
    }
}
check_eq('صف الرأس', array_column($sample['columns'], 'label'), $heads);
check('الرأس عريض بخلفية رمادية #F5F5F4 وحد سفلي رفيع', $badHead === [], implode('; ', $badHead));

$expect = [
    // ref => [t (n|s|null), القيمة في الملف, رمز التنسيق]
    'A5' => ['s', 'زان روماني', 'General'], 'B5' => ['s', 'عرض 10 سم × تخانة 5 سم × طول 3 متر', 'General'],
    'C5' => ['n', '10', '#,##0'], 'D5' => ['n', '0.15', '#,##0.000######'], 'E5' => ['n', '20000', '#,##0.00'],
    'F5' => ['n', '3000', '#,##0.00'], 'G5' => ['n', '46302.645833333333', 'yyyy-mm-dd hh:mm'], 'H5' => ['s', '=1+2', 'General'],
    'A6' => ['s', '<script>alert(1)</script> & "x", y', 'General'], 'B6' => [null], 'C6' => ['n', '1234567', '#,##0'],
    'D6' => ['n', '0.000000001', '#,##0.000######'], 'E6' => ['n', '0', '#,##0.00'], 'G6' => ['n', '46136.020833333333', 'yyyy-mm-dd hh:mm'],
    'H6' => ['s', '+SUM(A1:A2)', 'General'],
    'A7' => ['s', 'خشب  موسكي', 'General'], 'C7' => ['n', '0', '#,##0'], 'D7' => ['n', '0', '#,##0.000######'], 'E7' => [null], 'F7' => [null],
    'G7' => ['n', '46302', 'yyyy-mm-dd hh:mm'], 'H7' => ['s', '-2+3', 'General'],
    'A8' => ['s', '  مسافات في الطرفين  ', 'General'], 'C8' => ['n', '-5', '#,##0'], 'D8' => ['n', '1234.567891235', '#,##0.000######'],
    'E8' => ['n', '10000000', '#,##0.00'], 'F8' => ['n', '12345678.9', '#,##0.00'], 'G8' => ['s', '2026-02-30 10:00:00', 'General'],
    'H8' => ['s', '@SUM(1)', 'General'],
    'A9' => ['s', "سطر أول\nسطر ثانٍ\nسطر ثالث", 'General'], 'C9' => ['s', 'abc', 'General'], 'D9' => ['n', '98765.4321', '#,##0.000######'],
    'E9' => ['n', '1.5', '#,##0.00'], 'F9' => ['n', '0.05', '#,##0.00'], 'G9' => ['s', '1899-12-31 00:00:00', 'General'], 'H9' => ['s', "\tTab أولًا", 'General'],
    'A10' => ['s', '_x005F_x0041_ literal', 'General'], 'C10' => ['n', '4000000000', '#,##0'], 'D10' => ['n', '0', '#,##0.000######'],
    'E10' => ['n', '999.99', '#,##0.00'], 'F10' => ['n', '-150.25', '#,##0.00'], 'G10' => [null], 'H10' => ['s', $long, 'General'],
    'A11' => ['s', '🌲 emoji خشب', 'General'], 'C11' => [null], 'D11' => [null], 'H11' => ['s', 'invalid utf8 ?? end', 'General'],
    'A12' => ['s', str_repeat('x', 32767), 'General'], 'C12' => ['n', '42', '#,##0'], 'D12' => ['n', '-0.5', '#,##0.000######'], 'E12' => [null],
    'F12' => ['n', '12.5', '#,##0.00'], 'G12' => ['n', '46302.999988425926', 'yyyy-mm-dd hh:mm'], 'H12' => ['s', '=HYPERLINK("http://evil.example","x")', 'General'],
    'A13' => ['s', 'الإجمالي', 'General'], 'C13' => ['n', '4001234619', '#,##0'], 'D13' => ['n', '99999.123456789', '#,##0.000######'],
    'F13' => ['n', '12348873.7', '#,##0.00'],
];
$bad = [];
foreach ($expect as $ref => $e) {
    $c = $cells[$ref] ?? null;
    if ($e[0] === null) {
        if ($c !== null && $c['v'] !== null) {
            $bad[] = "$ref should be empty";
        }
        continue;
    }
    $t = $c === null ? 'missing' : ($c['t'] === 'inlineStr' ? 's' : ($c['t'] === '' ? 'n' : $c['t']));
    if ($t !== $e[0] || $c['v'] !== $e[1] || $fmt($ref) !== $e[2] || $c['f']) {
        $bad[] = "$ref: got [$t, " . mb_substr((string) ($c['v'] ?? 'null'), 0, 60) . ', ' . ($c === null ? '' : $fmt($ref)) . ']';
    }
}
check('قيم وأنواع وتنسيقات ' . count($expect) . ' خلية', $bad === [], implode('; ', $bad));
check('الأرقام خلايا رقمية بلا t', ($cells['D5']['t'] ?? 'x') === '' && ($cells['F13']['t'] ?? 'x') === '');
check('النص المشبوه نص وليس صيغة', ($cells['H5']['t'] ?? '') === 'inlineStr' && ($cells['H12']['t'] ?? '') === 'inlineStr');
check('المحاذاة: البداية يمين، النهاية يسار', $st[$cells['A5']['s']]['h'] === 'right' && $st[$cells['D5']['s']]['h'] === 'left' && $st[$cells['G5']['s']]['h'] === 'right');
check('النص الطويل ومتعدد الأسطر ملتف، والقصير لا', $st[$cells['H10']['s']]['wrap'] && $st[$cells['A9']['s']]['wrap'] && !$st[$cells['A5']['s']]['wrap']);
$tot = $st[$cells['C13']['s']];
check('صف الإجمالي عريض بحد علوي', $tot['font']['b'] && $tot['border']['top'] === 'thin' && $st[$cells['A13']['s']]['font']['b']);
check('الحد العلوي على كل خلايا صف الإجمالي', count(array_filter(range('A', 'H'), fn($c) => isset($cells[$c . '13']) && $st[$cells[$c . '13']['s']]['border']['top'] === 'thin')) === 8);

section('xlsx: الكتاب والخصائص');
$wx = xp($docs['xl/workbook.xml']);
check_eq('اسم الورقة', 'تقرير المبيعات كل المخازن', $wx->evaluate('string(//m:sheet/@name)'));
check_eq('_FilterDatabase', "'تقرير المبيعات كل المخازن'!\$A\$4:\$H\$12", $wx->evaluate('string(//m:definedName[@name="_xlnm._FilterDatabase"])'));
check_eq('تكرار صف الرأس في الطباعة', "'تقرير المبيعات كل المخازن'!\$4:\$4", $wx->evaluate('string(//m:definedName[@name="_xlnm.Print_Titles"])'));
$core = $docs['docProps/core.xml'];
check_eq('core: العنوان', $sample['title'], $core->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title')->item(0)?->textContent);
check_eq('core: المنشئ = اسم الشركة', 'شركة <الأخشاب> & أولاده "المتحدة"', $core->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator')->item(0)?->textContent);
check_eq('core: وقت الإنشاء UTC (القاهرة +3 صيفًا)', '2026-10-07T12:31:12Z', $core->getElementsByTagNameNS('http://purl.org/dc/terms/', 'created')->item(0)?->textContent);
check_eq('app: الشركة', 'شركة <الأخشاب> & أولاده "المتحدة"', $docs['docProps/app.xml']->getElementsByTagName('Company')->item(0)?->textContent);

section('xlsx: حالات حدية');
$one = xlsx_build(['title' => 'عمود واحد', 'subtitle' => '', 'columns' => $cols1, 'rows' => [['a' => 'س']], 'totals' => ['a' => 'المجموع'], 'generated_at' => '2026-10-07 10:00:00']);
file_put_contents($OUT . '/xlsx_one.xlsx', $one);
$p1 = zip_parts($OUT . '/xlsx_one.xlsx');
$d1 = load_xml($p1['xl/worksheets/sheet1.xml'] ?? '');
check('عمود واحد: بدون دمج، والإجمالي النصي من التقرير بدل «الإجمالي»', $d1 !== null && xp($d1)->query('//m:mergeCells')->length === 0
    && (sheet_cells($d1)['A6']['v'] ?? '') === 'المجموع' && xp($d1)->evaluate('string(//m:autoFilter/@ref)') === 'A4:A5');
file_put_contents($OUT . '/xlsx_narrow.xlsx', xlsx_build(['columns' => [['key' => 'c', 'label' => 'ك'], ['key' => 'q', 'label' => 'ع', 'type' => 'int']],
    'rows' => [['c' => 'A', 'q' => 1]], 'totals' => ['q' => 1]]));
$dn = load_xml(zip_parts($OUT . '/xlsx_narrow.xlsx')['xl/worksheets/sheet1.xml'] ?? '');
check('عمود ضيق يتسع لكلمة «الإجمالي»', $dn !== null && (float) xp($dn)->evaluate('string(//m:col[@min="1"]/@width)') >= 12
    && (sheet_cells($dn)['A6']['v'] ?? '') === 'الإجمالي');
$empty = xlsx_build(['title' => '', 'columns' => $sample['columns'], 'rows' => [], 'totals' => []]);
file_put_contents($OUT . '/xlsx_empty.xlsx', $empty);
$pe = zip_parts($OUT . '/xlsx_empty.xlsx');
$de = load_xml($pe['xl/worksheets/sheet1.xml'] ?? '');
check('بدون صفوف وعنوان وإجمالي: فلتر على الرأس فقط', $de !== null && xp($de)->evaluate('string(//m:autoFilter/@ref)') === 'A4:H4'
    && xp($de)->evaluate('string(//m:dimension/@ref)') === 'A1:H4' && !isset(sheet_cells($de)['A1']));
$gen = (function (): Generator {
    for ($i = 1; $i <= 3; $i++) {
        yield ['name' => "صنف $i", 'qty' => $i];
    }
})();
file_put_contents($OUT . '/xlsx_gen.xlsx', xlsx_build(['title' => 'مولد', 'columns' => array_slice($sample['columns'], 0, 3), 'rows' => $gen]));
$dg = load_xml(zip_parts($OUT . '/xlsx_gen.xlsx')['xl/worksheets/sheet1.xml'] ?? '');
check('الصفوف من Generator', $dg !== null && (sheet_cells($dg)['C7']['v'] ?? '') === '3');
check_eq('لا ملفات مؤقتة متبقية بعد كل البناءات', $leftBefore, temp_leftovers());

$xsdDir = getenv('XLSX_XSD_DIR') ?: '';
if ($xsdDir !== '' && is_file($xsdDir . '/sml.xsd')) {
    section('xlsx: التحقق بمخطط ECMA-376 الرسمي');
    // معروف: المخطط لا يذكر xml:space على <t> رغم أن Excel نفسه يكتبه؛ يُحذف قبل التحقق
    foreach ([$samplePath, $OUT . '/xlsx_one.xlsx', $OUT . '/xlsx_empty.xlsx'] as $file) {
        $pp = zip_parts($file);
        foreach (['xl/worksheets/sheet1.xml' => 'sml.xsd', 'xl/styles.xml' => 'sml.xsd', 'xl/workbook.xml' => 'sml.xsd',
            'docProps/app.xml' => 'shared-documentPropertiesExtended.xsd'] as $part => $xsd) {
            $doc = load_xml(str_replace(' xml:space="preserve"', '', $pp[$part]));
            libxml_use_internal_errors(true);
            $ok = $doc !== null && $doc->schemaValidate($xsdDir . '/' . $xsd);
            $err = implode(' | ', array_map(fn($e) => trim($e->message), array_slice(libxml_get_errors(), 0, 3)));
            libxml_clear_errors();
            check(basename($file) . ": $part يطابق $xsd", $ok, $err);
        }
    }
}

/* ================================================================ */
section('الرجوع إلى CSV بدون ext-zip');
$scanned = php_ini_scanned_files();
$zipIni = $scanned ? array_values(array_filter(array_map('trim', explode(',', $scanned)), fn($f) => preg_match('/zip\.ini\z/', $f))) : [];
if (!$zipIni) {
    echo "  SKIP  ext-zip ليست ملف ini منفصلًا في هذه البيئة\n";
} else {
    $iniDir = $OUT . '/php-ini-nozip';
    @mkdir($iniDir, 0775, true);
    array_map('unlink', glob($iniDir . '/*.ini') ?: []);
    foreach (array_map('trim', explode(',', $scanned)) as $f) {
        if ($f !== '' && !preg_match('/zip\.ini\z/', $f)) {
            copy($f, $iniDir . '/' . basename($f));
        }
    }
    $script = $OUT . '/xlsx_nozip.php';
    file_put_contents($script, '<?php
require ' . var_export(__DIR__ . '/lib.php', true) . ';
$c = &settings_cache(); $c = DEFAULT_SETTINGS;
$ds = ["title" => "ت", "columns" => [["key" => "a", "label" => "أ", "type" => "int"]], "rows" => [["a" => "5"], ["a" => "=1"]]];
fwrite(STDERR, class_exists("ZipArchive") ? "ZIP-PRESENT\n" : "ZIP-ABSENT\n");
try { xlsx_build($ds); fwrite(STDERR, "BUILT\n"); } catch (XlsxUnavailableException $e) { fwrite(STDERR, "UNAVAILABLE\n"); }
spreadsheet_send($ds, "تقرير", "xlsx");
');
    $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PHP_INI_SCAN_DIR' => $iniDir, 'WOOD_TEST_DB' => test_db_name()] + getenv());
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    check('ZipArchive غير موجودة في العملية الفرعية', str_starts_with($stderr, "ZIP-ABSENT\n"), $stderr);
    check('xlsx_build يرمي XlsxUnavailableException', str_contains($stderr, "UNAVAILABLE\n"), $stderr);
    check('spreadsheet_send يرسل CSV بدلًا منه', $stdout === "\xEF\xBB\xBFأ\r\n5\r\n'=1\r\n" && $code === 0, json_encode($stdout) . ' ' . $stderr);
}

section('مجلد مؤقت بديل عند تعذر مجلد النظام');
$script = $OUT . '/xlsx_tmpdir.php';
file_put_contents($script, '<?php
require ' . var_export(__DIR__ . '/lib.php', true) . ';
$c = &settings_cache(); $c = DEFAULT_SETTINGS;
$b = xlsx_build(["title" => "ت", "columns" => [["key" => "a", "label" => "أ"]], "rows" => [["a" => "س"]]]);
echo sys_get_temp_dir(), "|", strlen($b) > 0 && str_starts_with($b, "PK") ? "OK" : "BAD", "|", count(glob(APP_ROOT . "/storage/wood-xlsx-*") ?: []);
');
$proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => '/nonexistent-wood-tmp'] + getenv());
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
proc_close($proc);
check_eq('يُبنى في app/storage ثم يُحذف', '/nonexistent-wood-tmp|OK|0', $stdout . $stderr);

/* ================================================================ */
section('ترويسات التحميل (خادم PHP المدمج على منفذ محلي)');
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$router = $OUT . '/xlsx_router.php';
file_put_contents($router, '<?php
define("APP_ROOT", ' . var_export(APP_ROOT, true) . ');
foreach (["core", "Num", "format", "xlsx"] as $l) { require APP_ROOT . "/lib/$l.php"; }
$c = &settings_cache(); $c = DEFAULT_SETTINGS;
ob_start();
echo "JUNK-BEFORE-DOWNLOAD";
ob_start();
echo "MORE-JUNK";
$m = $_GET["m"] ?? "";
if ($m === "xlsx") { xlsx_send(file_get_contents(' . var_export($samplePath, true) . '), "تقرير المخزون \"الرئيسي\" 2026-10-07"); }
if ($m === "csv") { csv_send(file_get_contents(' . var_export($OUT . '/xlsx_sample.csv', true) . '), "تقرير المخزون.xlsx"); }
if ($m === "auto") { spreadsheet_send(["title" => "ت", "columns" => [["key" => "a", "label" => "أ", "type" => "int"]], "rows" => [["a" => "5"]]], "Stock report"); }
');
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100000);
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) {
        $up = true;
        fclose($fp);
    }
}
check('بدأ الخادم المحلي', $up);
$fetch = static function (string $q) use ($port): array {
    $body = @file_get_contents("http://127.0.0.1:$port/?m=$q", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $headers[strtolower(trim($k))] = trim($v);
        } else {
            $headers['status'] = $h;
        }
    }
    return [$headers, $body === false ? '' : $body];
};
if ($up) {
    [$h, $body] = $fetch('xlsx');
    check('xlsx: 200', str_contains($h['status'] ?? '', ' 200'), $h['status'] ?? '');
    check_eq('xlsx: Content-Type', XLSX_MIME, $h['content-type'] ?? null);
    check_eq('xlsx: Content-Disposition', 'attachment; filename="export-2026-10-07.xlsx"; filename*=UTF-8\'\''
        . rawurlencode('تقرير المخزون الرئيسي 2026-10-07.xlsx'), $h['content-disposition'] ?? null);
    check_eq('xlsx: Content-Length', (string) filesize($samplePath), $h['content-length'] ?? null);
    check('xlsx: Cache-Control no-store', str_contains($h['cache-control'] ?? '', 'no-store'));
    check_eq('xlsx: nosniff', 'nosniff', $h['x-content-type-options'] ?? null);
    check('xlsx: المحتوى مطابق تمامًا (المخازن المؤقتة نُظفت)', $body === file_get_contents($samplePath));
    [$h, $body] = $fetch('csv');
    check_eq('csv: Content-Type', 'text/csv; charset=utf-8', $h['content-type'] ?? null);
    check('csv: الاسم بامتداد csv', str_contains($h['content-disposition'] ?? '', 'filename="export.csv"; filename*=UTF-8\'\'' . rawurlencode('تقرير المخزون.csv')), $h['content-disposition'] ?? '');
    check('csv: المحتوى مطابق', $body === $csv);
    [$h, $body] = $fetch('auto');
    check('spreadsheet_send: xlsx افتراضيًا', ($h['content-type'] ?? '') === XLSX_MIME && str_starts_with($body, "PK\x03\x04")
        && str_contains($h['content-disposition'] ?? '', 'filename="Stock-report.xlsx"'));
}
proc_terminate($server);
proc_close($server);

/* ================================================================ */
section('الأداء: 20,000 صف');
$N = 20000;
$base = gmmktime(0, 0, 0, 1, 1, 2026);
$memBefore = memory_get_usage();
$rows = [];
$sumQty = '0';
$sumVol = '0';
$sumAmt = '0';
for ($i = 1; $i <= $N; $i++) {
    $volUm3 = Num::add(Num::mul((string) $i, '15000000000000000'), '123');
    $amt = (string) (300000 + $i * 7);
    $rows[] = [
        'name' => 'زان روماني ' . ($i % 50),
        'size' => 'عرض ' . (10 + $i % 7) . ' سم × تخانة ' . (2 + $i % 5) . ' سم × طول ' . (3 + $i % 3) . ' متر',
        'qty' => $i % 1000 + 1,
        'vol' => Num::toDecimal($volUm3, 18),
        'price' => Num::toDecimal((string) (2000000 + $i), 2),
        'amount' => Num::toDecimal($amt, 2),
        'at' => gmdate('Y-m-d H:i:s', $base + $i * 600),
        'notes' => $i % 10 === 0 ? 'ملاحظة طويلة نسبيًا للصف رقم ' . $i : null,
    ];
    $sumQty = Num::add($sumQty, (string) ($i % 1000 + 1));
    $sumVol = Num::add($sumVol, $volUm3);
    $sumAmt = Num::add($sumAmt, $amt);
}
$big = ['title' => 'تقرير كبير', 'subtitle' => '20,000 صف', 'columns' => $sample['columns'], 'rows' => $rows,
    'totals' => ['qty' => $sumQty, 'vol' => Num::toDecimal($sumVol, 18), 'amount' => Num::toDecimal($sumAmt, 2)], 'generated_at' => '2026-10-07 15:31:12'];
$datasetMb = (memory_get_usage() - $memBefore) / 1048576;

gc_collect_cycles();
reset_peak();
$m0 = memory_get_usage();
$t = hrtime(true);
$bigBytes = xlsx_build($big);
$xlsxSec = (hrtime(true) - $t) / 1e9;
$xlsxPeakMb = (memory_get_peak_usage() - $m0) / 1048576;
$bigPath = $OUT . '/xlsx_20k.xlsx';
file_put_contents($bigPath, $bigBytes);
$z = new ZipArchive();
$z->open($bigPath);
$sheetSize = $z->statName('xl/worksheets/sheet1.xml')['size'];
$z->close();
unset($bigBytes);

reset_peak();
$m0 = memory_get_usage();
$t = hrtime(true);
$bigCsv = csv_build($big);
$csvSec = (hrtime(true) - $t) / 1e9;
$csvPeakMb = (memory_get_peak_usage() - $m0) / 1048576;
$csvSize = strlen($bigCsv);
unset($bigCsv);

$gen = (function () use ($rows): Generator {
    foreach ($rows as $r) {
        yield $r;
    }
})();
reset_peak();
$m0 = memory_get_usage();
$t = hrtime(true);
$genLen = strlen(xlsx_build(['rows' => $gen] + $big));
$genSec = (hrtime(true) - $t) / 1e9;
$genPeakMb = (memory_get_peak_usage() - $m0) / 1048576;

printf("  INFO  dataset in memory: %.1f MB (%d rows x %d columns)\n", $datasetMb, $N, count($sample['columns']));
printf("  INFO  xlsx_build: %.2f s, peak +%.1f MB, file %.2f MB (sheet XML %.1f MB uncompressed)\n", $xlsxSec, $xlsxPeakMb, filesize($bigPath) / 1048576, $sheetSize / 1048576);
printf("  INFO  xlsx_build from a Generator: %.2f s, peak +%.1f MB, file %.2f MB\n", $genSec, $genPeakMb, $genLen / 1048576);
printf("  INFO  csv_build: %.2f s, peak +%.1f MB, file %.2f MB\n", $csvSec, $csvPeakMb, $csvSize / 1048576);
printf("  INFO  memory_limit=%s, peak total %.1f MB\n", ini_get('memory_limit'), memory_get_peak_usage() / 1048576);
check('xlsx 20,000 صف في أقل من 10 ثوانٍ', $xlsxSec < 10, sprintf('%.2f s', $xlsxSec));
check('ذاكرة xlsx الإضافية أقل من 32MB (الورقة تُكتب على القرص)', $xlsxPeakMb < 32, sprintf('%.1f MB', $xlsxPeakMb));

$reader = new XMLReader();
$reader->open('zip://' . $bigPath . '#xl/worksheets/sheet1.xml', null, LIBXML_NONET | LIBXML_PARSEHUGE);
$rowCount = 0;
$lastR = '';
$ok = true;
libxml_use_internal_errors(true);
while (true) {
    $r = @$reader->read();
    if ($r === false) {
        break;
    }
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
        $rowCount++;
        $lastR = $reader->getAttribute('r');
    }
}
$ok = libxml_get_errors() === [];
libxml_clear_errors();
$reader->close();
check('ورقة 20,000 صف XML سليم (قراءة متدفقة)', $ok);
check_eq('عدد الصفوف: عنوان + وصف + رأس + 20,000 + إجمالي', $N + 4, $rowCount);
check_eq('رقم آخر صف', (string) ($N + 5), $lastR);
$bp = zip_parts($bigPath, false);
check('أجزاء الملف الكبير سليمة', !isset($bp['__error']) && load_xml($bp['xl/styles.xml']) !== null && load_xml($bp['xl/workbook.xml']) !== null);

/* ================================================================ */
section('قارئ مستقل: openpyxl (tests/xlsx_check.py)');
$expectJson = [
    'sample' => [
        'path' => $samplePath,
        'sheet' => 'تقرير المبيعات كل المخازن',
        'title' => $sample['title'],
        'creator' => 'شركة <الأخشاب> & أولاده "المتحدة"',
        'autofilter' => 'A4:H12',
        'freeze' => 'A5',
        'merged' => ['A1:H1', 'A2:H2'],
        'header' => array_column($sample['columns'], 'label'),
        'cells' => [
            'A5' => ['s', 'زان روماني', 'General'], 'C5' => ['n', '10', '#,##0'], 'D5' => ['n', '0.15', '#,##0.000######'],
            'E5' => ['n', '20000', '#,##0.00'], 'F5' => ['n', '3000', '#,##0.00'], 'G5' => ['d', '2026-10-07T15:30:00', 'yyyy-mm-dd hh:mm'],
            'H5' => ['s', '=1+2', 'General'],
            'A6' => ['s', '<script>alert(1)</script> & "x", y', 'General'], 'B6' => [null], 'C6' => ['n', '1234567', '#,##0'],
            'D6' => ['n', '0.000000001', '#,##0.000######'], 'G6' => ['d', '2026-04-24T00:30:00', 'yyyy-mm-dd hh:mm'], 'H6' => ['s', '+SUM(A1:A2)', 'General'],
            'A7' => ['s', 'خشب  موسكي', 'General'], 'C7' => ['n', '0', '#,##0'], 'E7' => [null], 'G7' => ['d', '2026-10-07T00:00:00', 'yyyy-mm-dd hh:mm'],
            'H7' => ['s', '-2+3', 'General'],
            'A8' => ['s', '  مسافات في الطرفين  ', 'General'], 'C8' => ['n', '-5', '#,##0'], 'D8' => ['n', '1234.567891235', '#,##0.000######'],
            'F8' => ['n', '12345678.9', '#,##0.00'], 'G8' => ['s', '2026-02-30 10:00:00', 'General'], 'H8' => ['s', '@SUM(1)', 'General'],
            'A9' => ['s', "سطر أول\nسطر ثانٍ\nسطر ثالث", 'General'], 'C9' => ['s', 'abc', 'General'], 'D9' => ['n', '98765.4321', '#,##0.000######'],
            'G9' => ['s', '1899-12-31 00:00:00', 'General'], 'H9' => ['s', "\tTab أولًا", 'General'],
            'A10' => ['s', '_x0041_ literal', 'General'], 'C10' => ['n', '4000000000', '#,##0'], 'F10' => ['n', '-150.25', '#,##0.00'], 'H10' => ['s', $long, 'General'],
            'A11' => ['s', '🌲 emoji خشب', 'General'], 'H11' => ['s', 'invalid utf8 ?? end', 'General'],
            'A12' => ['s', str_repeat('x', 32767), 'General'], 'C12' => ['n', '42', '#,##0'], 'D12' => ['n', '-0.5', '#,##0.000######'],
            'G12' => ['d', '2026-10-07T23:59:59', 'yyyy-mm-dd hh:mm'], 'H12' => ['s', '=HYPERLINK("http://evil.example","x")', 'General'],
            'A13' => ['s', 'الإجمالي', 'General'], 'C13' => ['n', '4001234619', '#,##0'], 'D13' => ['n', '99999.123456789', '#,##0.000######'],
            'F13' => ['n', '12348873.7', '#,##0.00'],
        ],
        'wrapped' => ['A9', 'H10'],
        'header_row' => 4,
        'total_row' => 13,
        // كما يعرضها LibreOffice (إن وجد) بإعدادات en-US
        'shown' => [
            'C5' => '10', 'D5' => '0.150', 'E5' => '20,000.00', 'F5' => '3,000.00', 'G5' => '2026-10-07 15:30', 'H5' => '=1+2',
            'D6' => '0.000000001', 'G6' => '2026-04-24 00:30', 'C8' => '-5', 'D8' => '1,234.567891235', 'F8' => '12,345,678.90',
            'G8' => '2026-02-30 10:00:00', 'C9' => 'abc', 'D9' => '98,765.4321', 'C10' => '4,000,000,000', 'D10' => '0.000',
            'F10' => '-150.25', 'A10' => '_x0041_ literal', 'D12' => '-0.500', 'G12' => '2026-10-07 23:59', 'A13' => 'الإجمالي',
            'D13' => '99,999.123456789', 'H12' => '=HYPERLINK("http://evil.example","x")',
        ],
    ],
    'big' => [
        'path' => $bigPath,
        'rows' => $N,
        'first' => ['زان روماني 1', 'عرض 11 سم × تخانة 3 سم × طول 4 متر', 2, '0.015', '20000.01', '3000.07', '2026-01-01T00:10:00', null],
        'last' => ['زان روماني 0', 'عرض 11 سم × تخانة 2 سم × طول 5 متر', 1, '300', '20200', '4400', '2026-05-19T21:20:00', 'ملاحظة طويلة نسبيًا للصف رقم 20000'],
        'types' => ['s', 's', 'n', 'n', 'n', 'n', 'd', 's?'],
        'totals' => ['الإجمالي', null, $sumQty, xlsx_number(Num::toDecimal($sumVol, 18), 'volume'), null, xlsx_number(Num::toDecimal($sumAmt, 2), 'money'), null, null],
    ],
];
$expectPath = $OUT . '/xlsx_expect.json';
file_put_contents($expectPath, json_encode($expectJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$py = trim((string) shell_exec('command -v python3'));
if ($py === '') {
    echo "  SKIP  python3 غير موجود\n";
} else {
    $proc = proc_open(['python3', '-I', __DIR__ . '/xlsx_check.py', $expectPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outPy = stream_get_contents($pipes[1]);
    $errPy = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    echo preg_replace('/^/m', '    ', rtrim($outPy . $errPy)) . "\n";
    if ($code === 3) {
        echo "  SKIP  openpyxl غير مثبت (pip install openpyxl على مستوى النظام؛ الفحص يعمل بـ python3 -I)\n";
    } else {
        check('xlsx_check.py نجح', $code === 0, "exit $code");
    }
}

finish();
