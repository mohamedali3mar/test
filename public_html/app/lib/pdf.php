<?php
defined('APP_ROOT') || exit;

/*
 * ملفات PDF من الخادم بمكتبة mPDF: الفاتورة والإذن، والتقارير الجدولية.
 *
 *  - المكتبة في app/vendor وتُحمَّل عند أول طلب PDF فقط (pdf_engine)، فلا تتأثر باقي الصفحات.
 *  - الخط Cairo (ملفات TTF ثابتة في app/fonts) مع تشكيل الحروف العربية (OTL) واتجاه RTL،
 *    والنص في الملف نص حقيقي قابل للتحديد والبحث.
 *  - كل بيانات المستخدم تُهرَّب بـ h() قبل وضعها في HTML المرسل للمكتبة (صفوف التقارير تُكتب نصًا
 *    مباشرًا بـ WriteCell فلا تُفسَّر كـ HTML أصلًا)، ولا تُحمَّل أي صور أو ملفات CSS أو موارد
 *    خارجية أو محلية (خدمات الجلب في المكتبة مستبدلة بخدمات ترفض كل طلب).
 *  - الأرقام بنفس إعداد شكل الأرقام في النظام (دوال fmt_*)، وكذلك أرقام الصفحات.
 *
 * Rebuilding the vendored library and fonts: tools/pdf-vendor/README.md
 */

const PDF_COLORS = [
    'primary' => '#8a4510',
    'text' => '#1c1917',
    'text2' => '#44403c',
    'muted' => '#6b6560',
    'border' => '#d6d3d1',
    'surface2' => '#f5f5f4',
    'error' => '#b42318',
    'error_bg' => '#fef3f2',
    'watermark' => '#e7e5e4',
];

/** أعمدة التقرير أكثر من هذا العدد = صفحة بالعرض */
const PDF_LANDSCAPE_COLUMNS = 7;

/** خط جداول التقارير: الحجم بالنقطة، وارتفاع السطر، والحشو بالمليمتر */
const PDF_REPORT_FONT_PT = 9.0;
const PDF_REPORT_LINE = 1.3;
const PDF_REPORT_PAD_X = 1.5;
const PDF_REPORT_PAD_Y = 0.9;

/* ===================== المحرك ===================== */

/** يحمّل المكتبة مرة واحدة عند الحاجة فقط */
function pdf_load_library(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PDF library missing: app/vendor/autoload.php (see tools/pdf-vendor/README.md)');
    }
    require_once $autoload;
    $loaded = true;
}

/** مجلد ملفات المكتبة المؤقتة (بيانات الخطوط المحسوبة مسبقًا). داخل storage المحمي من HTTP. */
function pdf_temp_dir(): string
{
    $dir = (string) (app_config()['pdf_temp_dir'] ?? APP_ROOT . '/storage/pdf-cache');
    if ((is_dir($dir) && is_writable($dir)) || (!file_exists($dir) && is_writable(dirname($dir)))) {
        return $dir;
    }
    return rtrim(sys_get_temp_dir(), '/\\') . '/wood-pdf-cache';
}

/**
 * نسخة جديدة من mPDF بإعدادات النظام: A4، خط Cairo، اتجاه RTL، وبدون أي جلب لموارد خارجية أو محلية.
 * @param array<string,mixed> $config إعدادات إضافية تُدمج فوق الافتراضية (مثل orientation)
 */
function pdf_engine(array $config = []): \Mpdf\Mpdf
{
    pdf_load_library();

    $deny = static function (): never {
        throw new \Mpdf\Exception\AssetFetchingException('External and local resources are disabled');
    };
    $services = new \Mpdf\Container\SimpleContainer([
        // لا اتصال بالشبكة لأي رابط
        'httpClient' => new class ($deny) implements \Mpdf\Http\ClientInterface {
            public function __construct(private Closure $deny)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request)
            {
                ($this->deny)();
            }
        },
        // لا قراءة لأي ملف محلي عبر HTML (صور أو CSS)
        'localContentLoader' => new class ($deny) implements \Mpdf\File\LocalContentLoaderInterface {
            public function __construct(private Closure $deny)
            {
            }

            public function load($path)
            {
                ($this->deny)();
            }
        },
    ]);

    $arabicDigits = app_setting('digits') === 'arabic';
    $defaults = [
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'P',
        'margin_left' => 14,
        'margin_right' => 14,
        'margin_top' => 26,
        'margin_bottom' => 20,
        'margin_header' => 9,
        'margin_footer' => 9,
        'tempDir' => pdf_temp_dir(),
        'fontDir' => [APP_ROOT . '/fonts', APP_ROOT . '/vendor/mpdf/mpdf/ttfonts'],
        // قائمة الخطوط تستبدل قائمة المكتبة كلها: Cairo فقط، وDejaVu Sans احتياطيًا للرموز غير الموجودة في Cairo
        'fontdata' => [
            'cairo' => ['R' => 'Cairo-Regular.ttf', 'B' => 'Cairo-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            'dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
        ],
        'default_font' => 'cairo',
        'default_font_size' => 10.5,
        'useSubstitutions' => true,
        'backupSubsFont' => ['dejavusans'],
        'backupSIPFont' => 'dejavusans',
        'autoScriptToLang' => false,
        'autoLangToFont' => false,
        'defaultPageNumStyle' => $arabicDigits ? 'arabic-indic' : '1',
        // أمان: لا روابط ولا ملفات خارجية
        'curlAllowUnsafeSslRequests' => false,
        'curlFollowLocation' => false,
        'whitelistStreamWrappers' => [],
        'allowAnnotationFiles' => false,
        'enableImports' => false,
        'showImageErrors' => false,
        'debug' => false,
        'exposeVersion' => false,
        // جداول المستندات صغيرة: حدود لكل خلية، وتصغير الخط عند الضرورة بدل خروج الجدول عن الصفحة
        'packTableData' => false,
        'simpleTables' => false,
        'shrink_tables_to_fit' => 1.4,
        'use_kwt' => false,
        'useDictionaryLBR' => false,
    ];
    /*
     * العلامة المائية (مثل «ملغاة») تُرسم فوق المحتوى. بالشفافية العادية تظهر بقع داكنة حيث تتداخل
     * أطراف الحروف المتصلة. لذلك تُرسم بلون فاتح معتم بنمط الدمج Darken: التداخل لا يغمق اللون،
     * والنص الداكن تحتها يبقى ظاهرًا كما هو. باقي الرسم بالنمط العادي.
     */
    $mpdf = new class (array_replace($defaults, $config), $services) extends \Mpdf\Mpdf {
        private bool $drawingWatermark = false;

        public function watermark($texte, $angle = 45, $fontsize = 96, $alpha = 0.2)
        {
            $this->drawingWatermark = true;
            try {
                parent::watermark($texte, $angle, $fontsize, $alpha);
            } finally {
                $this->drawingWatermark = false;
            }
        }

        public function SetAlpha($alpha, $bm = 'Normal', $return = false, $mode = 'B')
        {
            if ($this->drawingWatermark && $alpha < 1) {
                return parent::SetAlpha(1, 'Darken', $return, $mode);
            }
            return parent::SetAlpha($alpha, $bm, $return, $mode);
        }
    };
    // الاتجاه ليس من إعدادات المُنشئ في mPDF 8: يُضبط هنا (ومعه direction في CSS الصفحة)
    $mpdf->SetDirectionality('rtl');
    $mpdf->SetCreator(app_setting('company_name'));
    $mpdf->SetDisplayMode('fullpage');
    return $mpdf;
}

/* ===================== أجزاء مشتركة ===================== */

function pdf_css(): string
{
    $c = PDF_COLORS;
    return <<<CSS
body { font-family: cairo; font-size: 10.5pt; color: {$c['text']}; line-height: 1.5; direction: rtl; }
p { margin: 0; }
.run-head { width: 100%; border-bottom: 0.75pt solid {$c['primary']}; }
.run-head td { padding: 0 0 2mm 0; vertical-align: bottom; }
.run-company { font-size: 12pt; font-weight: bold; color: {$c['text']}; }
.run-title { font-size: 9pt; color: {$c['text2']}; text-align: left; }
.run-foot { width: 100%; border-top: 0.75pt solid {$c['border']}; }
.run-foot td { padding: 1.5mm 0 0 0; font-size: 9pt; color: {$c['muted']}; }
.run-foot .end { text-align: left; }
h1 { font-size: 18pt; font-weight: bold; margin: 0 0 1mm 0; color: {$c['text']}; }
.branch { font-size: 10.5pt; font-weight: bold; color: {$c['text2']}; margin: 0 0 0.5mm 0; }
.branch-contact { font-size: 9pt; color: {$c['text2']}; margin: 0 0 2mm 0; }
.subtitle { font-size: 10.5pt; color: {$c['text2']}; margin: 0 0 1mm 0; }
.generated { font-size: 9pt; color: {$c['muted']}; margin: 0 0 4mm 0; }
.cancelled { border: 0.75pt solid {$c['error']}; background-color: {$c['error_bg']}; color: {$c['error']};
  font-weight: bold; font-size: 12pt; padding: 2mm 3mm; margin: 2mm 0 3mm 0; border-radius: 1.5mm; }
.cancel-reason { font-weight: normal; font-size: 10.5pt; }
.meta { width: 100%; border-collapse: collapse; margin: 2mm 0 5mm 0; }
.meta td { padding: 1.2mm 0; vertical-align: top; border-bottom: 0.5pt solid {$c['border']}; }
.meta .k { color: {$c['muted']}; font-size: 9pt; width: 17%; padding-left: 2mm; }
.meta .v { font-size: 10.5pt; width: 33%; padding-left: 6mm; }
.grid { width: 100%; border-collapse: collapse; }
.grid th { background-color: {$c['surface2']}; color: {$c['text2']}; font-weight: bold; font-size: 9pt;
  border: 0.5pt solid {$c['border']}; padding: 1.6mm 1.8mm; text-align: right; vertical-align: bottom; }
.grid td { border: 0.5pt solid {$c['border']}; padding: 1.4mm 1.8mm; text-align: right; vertical-align: top; }
.grid .num { text-align: left; }
.grid .nowrap { white-space: nowrap; }
.grid .seq { color: {$c['muted']}; text-align: center; }
.grid tr.total td { font-weight: bold; font-size: 12pt; border-top: 1pt solid {$c['text2']}; }
.report-title { padding-top: 5mm; }
.grand { width: 100%; margin-top: 4mm; border-collapse: collapse; }
.grand-space { padding: 0; }
.grand-box { border: 0.75pt solid {$c['border']}; padding: 2.5mm 4mm; }
.grand-label { font-size: 10.5pt; color: {$c['text2']}; }
.grand-amount { font-size: 15pt; font-weight: bold; color: {$c['text']}; }
.note { font-size: 9pt; color: {$c['muted']}; margin-top: 3mm; }
.notes { margin-top: 5mm; border-top: 0.5pt solid {$c['border']}; padding-top: 2mm; }
.notes .label { font-size: 9pt; color: {$c['muted']}; margin-bottom: 1mm; }
CSS;
}

/** ترويسة كل صفحة: اسم الشركة يمينًا وعنوان الملف يسارًا */
function pdf_running_header(string $title): string
{
    return '<table class="run-head"><tr>'
        . '<td class="run-company">' . pdf_bdi(app_setting('company_name')) . '</td>'
        . '<td class="run-title">' . h($title) . '</td>'
        . '</tr></table>';
}

/** تذييل كل صفحة: رقم الصفحة من إجمالي الصفحات، ووقت إنشاء الملف */
function pdf_running_footer(string $generatedAt): string
{
    return '<table class="run-foot"><tr>'
        . '<td>صفحة {PAGENO} من {nbpg}</td>'
        . '<td class="end">تاريخ الإنشاء: ' . h(fmt_datetime($generatedAt)) . '</td>'
        . '</tr></table>';
}

/** يبدأ المستند: CSS والترويسة والتذييل وبيانات الملف */
function pdf_begin(\Mpdf\Mpdf $mpdf, string $title, string $generatedAt): void
{
    $mpdf->SetTitle($title);
    $mpdf->SetAuthor(app_setting('company_name'));
    $mpdf->SetHTMLHeader(pdf_running_header($title));
    $mpdf->SetHTMLFooter(pdf_running_footer($generatedAt));
    $mpdf->WriteHTML(pdf_css(), \Mpdf\HTMLParserMode::HEADER_CSS);
}

/** يكتب HTML مع رفع حد pcre.backtrack_limit عند الحاجة (المكتبة ترفض النص الأطول منه) */
function pdf_write(\Mpdf\Mpdf $mpdf, string $html): void
{
    $need = strlen($html) + 1024;
    if ((int) ini_get('pcre.backtrack_limit') < $need) {
        ini_set('pcre.backtrack_limit', (string) $need);
    }
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
}

/**
 * نص أدخله المستخدم (اسم نوع أو مخزن أو عميل أو مرجع...) معزولًا باتجاهه الخاص: <bdi> يأخذ الاتجاه
 * من أول حرف قوي، فيظهر اسم لاتيني مثل «Pine (Finland)» بأقواسه الصحيحة داخل سطر عربي.
 * لا يُستخدم للأرقام والتواريخ المنسقة حتى تبقى كما تظهر في صفحات النظام.
 */
function pdf_bdi(?string $s): string
{
    return '<bdi>' . h($s) . '</bdi>';
}

/** اتجاه فقرة نصية من أول حرف قوي فيها؛ النص بلا حروف (أرقام وتواريخ) يتبع اتجاه الصفحة */
function pdf_text_dir(string $s): string
{
    if (preg_match('/\p{L}/u', $s, $m)) {
        return preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $m[0]) ? 'rtl' : 'ltr';
    }
    return 'rtl';
}

/* ===================== الفاتورة والإذن ===================== */

/**
 * ملف PDF لمستند (فاتورة بيع أو إذن وارد أو إذن تحويل) بنفس بيانات صفحة الطباعة.
 * @param array $doc   صف المستند من find_document()
 * @param array $lines أسطره من document_lines()
 * @return string محتوى ملف PDF
 */
function pdf_document(array $doc, array $lines): string
{
    $kind = (string) $doc['kind'];
    $isSale = $kind === 'sale';
    $cancelled = ($doc['status'] ?? 'active') === 'cancelled';
    $title = doc_print_title($kind);
    $fullTitle = $title . ' رقم ' . fmt_doc_no((int) $doc['doc_no']);
    $rounded = (bool) array_filter($lines, fn ($l) => volume_display_rounded((string) $l['total_volume_m3']));

    $mpdf = pdf_engine();
    pdf_begin($mpdf, $fullTitle . ($cancelled ? ' (ملغاة)' : ''), now());
    if ($cancelled) {
        // لون فاتح معتم (انظر pdf_engine)؛ قيمة الشفافية أقل من 1 فقط لتفعيل نمط Darken
        $mpdf->SetWatermarkText(new \Mpdf\WatermarkText('ملغاة', 120, 45, PDF_COLORS['watermark'], 0.5, 'cairo'));
        $mpdf->showWatermarkText = true;
    }

    // الفرع كما سُجل مع المستند، والعنوان والهاتف الحاليان للفرع (مثل صفحة الطباعة)
    $html = '';
    if (($doc['branch_name'] ?? null) !== null && $doc['branch_name'] !== '') {
        $html .= '<p class="branch">فرع: ' . pdf_bdi((string) $doc['branch_name']) . '</p>';
        $branch = ($doc['branch_id'] ?? null) !== null && function_exists('branch_find') ? branch_find(db(), (int) $doc['branch_id']) : null;
        $contact = [];
        if ($branch && (string) $branch['address'] !== '') {
            $contact[] = 'العنوان: ' . pdf_bdi((string) $branch['address']);
        }
        if ($branch && (string) $branch['phone'] !== '') {
            $contact[] = 'الهاتف: <bdo dir="ltr">' . h(fmt_phone((string) $branch['phone'])) . '</bdo>';
        }
        if ($contact) {
            $html .= '<p class="branch-contact">' . implode('&nbsp;&nbsp;&nbsp;', $contact) . '</p>';
        }
    }
    $html .= '<h1>' . h($title) . '</h1>';
    if ($cancelled) {
        $html .= '<div class="cancelled">ملغاة بتاريخ ' . h(fmt_datetime($doc['cancelled_at'] ?? null));
        if (($doc['cancel_reason'] ?? null) !== null && $doc['cancel_reason'] !== '') {
            $html .= '<br><span class="cancel-reason">سبب الإلغاء: ' . pdf_bdi((string) $doc['cancel_reason']) . '</span>';
        }
        $html .= '</div>';
    }

    // بيانات المستند في عمودين: [العنوان، HTML القيمة]
    // تاريخ المستند (اليدوي إن حدده المدير)، وليس وقت التسجيل
    $meta = [
        ['رقم ' . ($isSale ? 'الفاتورة' : 'الإذن'), h(fmt_doc_no((int) $doc['doc_no']))],
        ['التاريخ', h(fmt_datetime($doc['doc_date'] ?? $doc['created_at']))],
    ];
    if ($kind === 'transfer') {
        $meta[] = ['من مخزن', pdf_bdi((string) $doc['warehouse_name'])];
        $meta[] = ['إلى مخزن', pdf_bdi((string) $doc['to_warehouse_name'])];
        $toBranch = $doc['to_branch_id'] ?? null;
        if ($toBranch !== null && (int) $toBranch !== (int) ($doc['branch_id'] ?? 0) && ($doc['to_branch_name'] ?? null) !== null) {
            $meta[] = ['إلى فرع', pdf_bdi((string) $doc['to_branch_name'])];
        }
    } else {
        $meta[] = ['المخزن', pdf_bdi((string) $doc['warehouse_name'])];
    }
    if (($doc['party_name'] ?? null) !== null) {
        $meta[] = [$isSale ? 'العميل' : 'المورد', pdf_bdi((string) $doc['party_name'])];
    }
    // طريقة الدفع فقط؛ لا تُطبع التكلفة أو الربح أبدًا
    if ($isSale && ($doc['payment_type'] ?? null) !== null) {
        $meta[] = ['طريقة الدفع', h(PAYMENT_TYPE_LABELS[$doc['payment_type']] ?? (string) $doc['payment_type'])];
    }
    if (($doc['reference'] ?? null) !== null) {
        $meta[] = ['المرجع', pdf_bdi((string) $doc['reference'])];
    }
    $html .= '<table class="meta">';
    foreach (array_chunk($meta, 2) as $pair) {
        $html .= '<tr>';
        foreach ($pair as [$k, $v]) {
            $html .= '<td class="k">' . h($k) . '</td><td class="v">' . $v . '</td>';
        }
        if (count($pair) === 1) {
            $html .= '<td class="k"></td><td class="v"></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    // جدول الأسطر: الأعمدة بترتيبها المنطقي، والمكتبة تعرض أولها يمينًا (RTL)
    $currency = (string) ($doc['currency'] ?? app_setting('currency'));
    $html .= '<table class="grid" autosize="1"><thead><tr>'
        . '<th class="seq" width="5%">م</th>'
        . '<th>نوع الخشب</th>'
        . '<th>العرض</th><th>التخانة</th><th>الطول</th>'
        . '<th class="num">العدد</th>'
        . '<th class="num">الحجم (م³)</th>';
    if ($isSale) {
        $html .= '<th class="num">سعر المتر المكعب (' . h($currency) . ')</th><th class="num">القيمة</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($lines as $l) {
        $html .= '<tr>'
            . '<td class="seq">' . h(fmt_int((int) $l['line_no'])) . '</td>'
            . '<td>' . pdf_bdi((string) $l['wood_type_name']) . '</td>'
            . '<td class="nowrap">' . h(fmt_dim((int) $l['width_um'], (string) $l['width_unit'])) . '</td>'
            . '<td class="nowrap">' . h(fmt_dim((int) $l['thickness_um'], (string) $l['thickness_unit'])) . '</td>'
            . '<td class="nowrap">' . h(fmt_dim((int) $l['length_um'], (string) $l['length_unit'])) . '</td>'
            . '<td class="num nowrap">' . h(fmt_int((int) $l['quantity'])) . '</td>'
            . '<td class="num nowrap">' . h(fmt_volume((string) $l['total_volume_m3'])) . '</td>';
        if ($isSale) {
            $html .= '<td class="num nowrap">' . h(fmt_money((string) $l['price_per_m3'])) . '</td>'
                . '<td class="num nowrap">' . h(fmt_money((string) $l['amount'])) . '</td>';
        }
        $html .= '</tr>';
    }
    // صف الإجمالي آخر صف في الجدول (وليس tfoot حتى لا يتكرر في كل صفحة)
    $html .= '<tr class="total"><td colspan="5">الإجمالي</td>'
        . '<td class="num nowrap">' . h(fmt_int((int) $doc['total_qty'])) . '</td>'
        . '<td class="num nowrap">' . h(fmt_volume((string) $doc['total_volume_m3'])) . '</td>';
    if ($isSale) {
        $html .= '<td></td><td class="num nowrap">' . h(fmt_money((string) $doc['total_amount'])) . '</td>';
    }
    $html .= '</tr></tbody></table>';

    if ($isSale) {
        $paid = '';
        if (($doc['payment_type'] ?? null) !== null) {
            $paidP = money_to_piasters((string) $doc['paid_amount']);
            $paid = '<br><span class="grand-label">المدفوع: ' . h(fmt_piasters($paidP))
                . '، المتبقي: ' . h(fmt_piasters(money_to_piasters((string) $doc['total_amount']) - $paidP)) . '</span>';
        }
        $html .= '<table class="grand"><tr><td class="grand-space" width="55%"></td><td class="grand-box">'
            . '<span class="grand-label">إجمالي القيمة</span><br>'
            . '<span class="grand-amount">' . h(fmt_money_currency((string) $doc['total_amount'], $currency)) . '</span>'
            . $paid . '</td></tr></table>';
    }
    if ($rounded) {
        $html .= '<p class="note">' . ($isSale
            ? 'الأحجام معروضة مقربة، والقيمة محسوبة من الحجم الدقيق.'
            : 'الأحجام معروضة مقربة.') . '</p>';
    }
    if (($doc['notes'] ?? null) !== null && $doc['notes'] !== '') {
        // كل سطر في الملاحظات معزول باتجاهه
        $noteLines = array_map('pdf_bdi', explode("\n", (string) $doc['notes']));
        $html .= '<div class="notes"><p class="label">ملاحظات</p><p>' . implode('<br>', $noteLines) . '</p></div>';
    }

    pdf_write($mpdf, $html);
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/** اسم ملف مقترح للمستند، مثل: فاتورة بيع 15.pdf */
function pdf_document_filename(array $doc): string
{
    return doc_print_title((string) $doc['kind']) . ' ' . (int) $doc['doc_no'] . '.pdf';
}

/* ===================== التقارير ===================== */

/** قيمة خلية تقرير كنص منسق (بدون تهريب) */
function pdf_report_value(string $type, mixed $raw): string
{
    if ($raw === null || $raw === '' || !is_scalar($raw)) {
        return '';
    }
    $s = is_float($raw) ? rtrim(rtrim(sprintf('%.10F', $raw), '0'), '.') : trim((string) $raw);
    if ($type === 'date') {
        // تاريخ بلا وقت يبقى بلا وقت (لا «00:00» مضللة)
        return preg_match('/^\d{4}-\d{2}-\d{2}\z/', $s) ? digits($s) : fmt_datetime($s);
    }
    if (!in_array($type, ['int', 'volume', 'money'], true)) {
        // نص من أرقام فقط (مثل الفترة 2026-01) بشكل الأرقام في الإعدادات، كما في صفحات التقارير
        return preg_match('/^[\d\-]+\z/', $s) ? digits($s) : $s;
    }
    // دوال fmt_* تقبل القيم الموجبة فقط؛ الإشارة السالبة تُضاف بعد التنسيق
    if (!preg_match('/^(-?)(\d+(?:\.\d+)?)\z/', $s, $m)) {
        return $s;
    }
    $formatted = match ($type) {
        'int' => fmt_int($m[2]),
        'volume' => fmt_volume($m[2]),
        default => fmt_money($m[2]),
    };
    $negative = $m[1] === '-' && trim(str_replace('.', '', $m[2]), '0') !== '';
    return ($negative ? '-' : '') . $formatted;
}

/**
 * ملف PDF لتقرير جدولي، بذاكرة ثابتة تقريبًا مهما زاد عدد الصفوف.
 *
 * جداول HTML في mPDF تحتفظ بالجدول كله في الذاكرة حتى نهايته، وتكلف قرابة 0.7 ملي ثانية للخلية،
 * فجدول بخمسة آلاف صف يتجاوز 256 ميجابايت. لذلك:
 *  - تُحسب عروض الأعمدة مرة واحدة بالمليمتر من مقاسات الخط الفعلية (pdf_report_widths).
 *  - سطر الشركة والعنوان والوصف ترويسة HTML للصفحة (العنوان والوصف في الصفحة الأولى فقط).
 *  - صف عناوين الأعمدة والصفوف تُرسم مباشرة بـ WriteCell (نفس تشكيل الحروف واتجاه النص في المكتبة)
 *    مع فواصل الصفحات والحدود (pdf_report_draw_rows): ذاكرة ثابتة تقريبًا، وأسرع بنحو ثلاث مرات.
 *
 * @param array{title:string, subtitle?:string, columns:array<int,array{key:string,label:string,type?:string,align?:string}>,
 *              rows:iterable<array<string,mixed>>, totals?:?array<string,mixed>, generated_at?:string} $dataset
 * @return string محتوى ملف PDF
 */
function pdf_report(array $dataset): string
{
    $title = (string) ($dataset['title'] ?? '');
    $subtitle = (string) ($dataset['subtitle'] ?? '');
    $generatedAt = (string) ($dataset['generated_at'] ?? now());
    $columns = [];
    foreach ((array) ($dataset['columns'] ?? []) as $c) {
        if (!is_array($c) || !isset($c['key']) || !is_scalar($c['key'])) {
            throw new InvalidArgumentException('pdf_report: every column needs a key');
        }
        $type = in_array($c['type'] ?? 'text', ['text', 'int', 'volume', 'money', 'date'], true) ? ($c['type'] ?? 'text') : 'text';
        $align = $c['align'] ?? (in_array($type, ['int', 'volume', 'money'], true) ? 'end' : 'start');
        $columns[] = [
            'key' => (string) $c['key'],
            'label' => (string) ($c['label'] ?? $c['key']),
            'type' => $type,
            'end' => $align === 'end',
            'nowrap' => $type !== 'text',
        ];
    }
    if (!$columns) {
        throw new InvalidArgumentException('pdf_report: no columns');
    }
    $rows = $dataset['rows'] ?? [];
    if (!is_array($rows)) {
        $rows = iterator_to_array($rows, false); // تمريرتان: القياس ثم الكتابة
    }
    // التقارير الكبيرة: مهلة تنفيذ أطول إن سمحت الاستضافة (قرابة 400 صف في الثانية في بيئة الاختبار)
    $limit = (int) ini_get('max_execution_time');
    if ($limit > 0 && count($rows) > 1000 && function_exists('set_time_limit')) {
        @set_time_limit(max($limit, 60 + intdiv(count($rows), 100)));
    }
    $totals = is_array($dataset['totals'] ?? null) && $rows ? $dataset['totals'] : null;
    $totalCells = null;
    if ($totals !== null) {
        $totalCells = [];
        foreach ($columns as $i => $c) {
            $v = pdf_report_value($c['type'], $totals[$c['key']] ?? null);
            $totalCells[] = ($i === 0 && $v === '') ? 'الإجمالي' : $v;
        }
    }

    $mpdf = pdf_engine([
        'orientation' => count($columns) > PDF_LANDSCAPE_COLUMNS ? 'L' : 'P',
        'default_font_size' => 9,
        'margin_top' => 10,
        // الهامش العلوي = ارتفاع الترويسة في كل صفحة (أطول في الصفحة الأولى بسبب العنوان)
        'setAutoTopMargin' => 'stretch',
        'autoMarginPadding' => 4,
    ]);
    $fontFor = pdf_font_chooser($mpdf, PDF_REPORT_FONT_PT);
    $measure = pdf_text_measurer($mpdf, PDF_REPORT_FONT_PT, $fontFor); // ذاكرة قياس واحدة للعروض والالتفاف
    [$widths, $nowrapOk] = pdf_report_widths($mpdf, $measure, $fontFor, $columns, $rows, $totalCells);

    $titleBlock = '<h1>' . h($title) . '</h1>';
    if ($subtitle !== '') {
        $titleBlock .= '<p class="subtitle">' . h($subtitle) . '</p>';
    }
    $titleBlock .= '<p class="generated">تاريخ الإنشاء: ' . h(fmt_datetime($generatedAt)) . '</p>';

    $mpdf->SetTitle($title);
    $mpdf->SetAuthor(app_setting('company_name'));
    $mpdf->WriteHTML(pdf_css(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->SetHTMLFooter(pdf_running_footer($generatedAt));
    // الصفحة الأولى: سطر الشركة ثم العنوان والوصف. الصفحات التالية: سطر الشركة فقط.
    // صف عناوين الأعمدة يُرسم أعلى كل صفحة مع الصفوف بنفس هندستها (pdf_report_draw_rows).
    $mpdf->SetHTMLHeader(pdf_running_header($title) . '<div class="report-title">' . $titleBlock . '</div>');
    $mpdf->AddPage();
    $mpdf->SetHTMLHeader(pdf_running_header($title));

    pdf_report_draw_rows($mpdf, $measure, $fontFor, $columns, $widths, $nowrapOk, $rows, $totalCells);
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * دالة تختار خط النص: Cairo، وإن كان فيه حرف لا يحتويه Cairo فـ DejaVu Sans (مثل الحروف السيريلية).
 * يُختار الخط للخلية كلها حتى يتطابق القياس والرسم.
 */
function pdf_font_chooser(\Mpdf\Mpdf $mpdf, float $sizePt): Closure
{
    $mpdf->SetFont('cairo', '', $sizePt, false);
    $cw = $mpdf->CurrentFont['cw'];
    $asciiOk = true;
    for ($u = 0x20; $u <= 0x7E; $u++) {
        $asciiOk = $asciiOk && $mpdf->_charDefined($cw, $u);
    }
    $cache = [];
    return static function (string $s) use ($mpdf, $cw, $asciiOk, &$cache): string {
        if ($s === '' || ($asciiOk && preg_match('/^[\x20-\x7E]*\z/', $s))) {
            return 'cairo';
        }
        if (!isset($cache[$s])) {
            if (count($cache) > 20000) {
                $cache = [];
            }
            $font = 'cairo';
            foreach (mb_str_split($s) as $ch) {
                $u = mb_ord($ch);
                // رموز الاتجاه والوصل غير المرئية لا تحتاج رسمًا
                if (($u >= 0x200B && $u <= 0x200F) || ($u >= 0x202A && $u <= 0x202E) || ($u >= 0x2066 && $u <= 0x2069)) {
                    continue;
                }
                if (!$mpdf->_charDefined($cw, $u)) {
                    $font = 'dejavusans';
                    break;
                }
            }
            $cache[$s] = $font;
        }
        return $cache[$s];
    };
}

/**
 * دالة تقيس عرض نص بالمليمتر بعد تشكيل الحروف، أي كما سيُرسم فعلًا (مع ذاكرة مؤقتة).
 * العرض بدون تشكيل (حروف منفصلة) أكبر بنحو 1.7 مرة في Cairo، فيلتف النص بلا داع.
 * محرك التشكيل في mPDF خاصية خاصة (otl)؛ تُقرأ بربط دالة بنطاق الصنف، والمكتبة مثبتة الإصدار في
 * app/vendor. إن تعذرت القراءة يُستخدم العرض بدون تشكيل (أكبر، فلا يخرج النص عن الخلية).
 * تنبيه: القياس يغيّر الخط الحالي في المكتبة دون كتابته في الملف؛ أول SetFont بعده يُكتب إجباريًا.
 */
function pdf_text_measurer(\Mpdf\Mpdf $mpdf, float $sizePt, Closure $fontFor): Closure
{
    $otl = null;
    try {
        $otl = Closure::bind(fn () => $this->otl ?? null, $mpdf, \Mpdf\Mpdf::class)();
    } catch (Throwable $e) {
        $otl = null;
    }
    $otl = $otl instanceof \Mpdf\Otl ? $otl : null;
    $cache = [];
    // $font: خط الخلية كلها إن كانت الكلمة جزءًا منها؛ وإلا يُختار للنص نفسه
    return static function (string $s, string $style = '', ?string $font = null) use ($mpdf, $otl, $sizePt, $fontFor, &$cache): float {
        $font ??= $fontFor($s);
        $key = $font . $style . "\x00" . $s;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (count($cache) > 50000) {
            $cache = [];
        }
        $mpdf->SetFont($font, $style, $sizePt, false);
        $text = $s;
        $otlData = false;
        if ($otl !== null && !empty($mpdf->CurrentFont['useOTL']) && preg_match('/[\x{0600}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $s)) {
            $saveTags = $mpdf->OTLtags;
            $mpdf->OTLtags = [];
            $text = $otl->applyOTL($s, $mpdf->CurrentFont['useOTL']);
            $otlData = $otl->OTLdata;
            $mpdf->OTLtags = $saveTags;
        }
        return $cache[$key] = (float) $mpdf->GetStringWidth($text, false, $otlData);
    };
}

/**
 * عروض أعمدة التقرير بالمليمتر من مقاسات الخط الفعلية لكل القيم (مع ذاكرة مؤقتة للنصوص المكررة).
 * الأعمدة الرقمية لا تلتف، والأعمدة النصية تأخذ المساحة المتبقية بنسبة طول محتواها.
 * @return array{0: array<int,float>, 1: bool} [العروض, هل يتسع منع الالتفاف للأعمدة الرقمية]
 */
function pdf_report_widths(\Mpdf\Mpdf $mpdf, Closure $measure, Closure $fontFor, array $columns, array $rows, ?array $totalCells): array
{
    $available = $mpdf->w - $mpdf->lMargin - $mpdf->rMargin;
    $pad = 2 * PDF_REPORT_PAD_X + 0.8; // الحشو الأفقي للخلية + الحدود + هامش أمان
    $longestWord = static function (string $s, string $style) use ($measure, $fontFor): float {
        $w = 0.0;
        $font = $fontFor($s);
        foreach (preg_split('/ +/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $w = max($w, $measure($word, $style, $font));
        }
        return $w;
    };

    $min = [];
    $want = [];
    foreach ($columns as $j => $c) {
        $min[$j] = $longestWord($c['label'], 'B');
        $want[$j] = $measure($c['label'], 'B');
    }
    $consider = static function (int $j, string $text, string $style) use ($columns, $measure, $longestWord, &$min, &$want): void {
        if ($text === '') {
            return;
        }
        $full = $measure($text, $style);
        $want[$j] = max($want[$j], $full);
        $min[$j] = max($min[$j], $columns[$j]['nowrap'] ? $full : $longestWord($text, $style));
    };
    foreach ($rows as $row) {
        foreach ($columns as $j => $c) {
            $consider($j, pdf_report_value($c['type'], $row[$c['key']] ?? null), '');
        }
    }
    foreach ($totalCells ?? [] as $j => $text) {
        $consider($j, $text, 'B');
    }
    $mpdf->SetFont('cairo', '', PDF_REPORT_FONT_PT, false);

    foreach ($columns as $j => $c) {
        $min[$j] += $pad;
        $want[$j] = max($want[$j] + $pad, $min[$j]);
    }
    $sumMin = array_sum($min);
    $sumWant = array_sum($want);
    if ($sumWant <= $available) {
        // يتسع كل شيء في سطر واحد: الزيادة للأعمدة النصية (أو لكل الأعمدة إن لم يوجد نص)
        $flex = array_keys(array_filter($columns, fn ($c) => !$c['nowrap'])) ?: array_keys($columns);
        $flexSum = array_sum(array_intersect_key($want, array_flip($flex)));
        $widths = $want;
        foreach ($flex as $j) {
            $widths[$j] += ($available - $sumWant) * ($want[$j] / $flexSum);
        }
        return [$widths, true];
    }
    if ($sumMin <= $available) {
        // كل عمود يبدأ بحده الأدنى، ثم تُرفع الأعمدة الناقصة بالتساوي حتى يكتمل الأقصر محتوى منها،
        // فتبقى الأعمدة القصيرة في سطر واحد ويلتف الأطول فقط
        $widths = $min;
        $remaining = $available - $sumMin;
        $active = array_keys(array_filter($columns, fn ($c, $j) => $want[$j] > $min[$j], ARRAY_FILTER_USE_BOTH));
        while ($remaining > 0.01 && $active) {
            $share = $remaining / count($active);
            foreach ($active as $k => $j) {
                $inc = min($share, $want[$j] - $widths[$j]);
                $widths[$j] += $inc;
                $remaining -= $inc;
                if ($want[$j] - $widths[$j] <= 0.001) {
                    unset($active[$k]);
                }
            }
        }
        return [$widths, true];
    }
    // أعمدة كثيرة جدًا: تصغير نسبي، ويُسمح بالتفاف الأرقام حتى لا يخرج الجدول عن الصفحة
    return [array_map(fn ($w) => $w * $available / $sumMin, $min), false];
}

/**
 * يرسم صف عناوين الأعمدة والصفوف وصف الإجمالي مباشرة: الأعمدة من اليمين لليسار، والنص يلتف داخل
 * الأعمدة النصية، وحدود 0.5 نقطة، وصفحة جديدة عند امتلاء الصفحة يتكرر أعلاها صف العناوين.
 * الخلية التي فيها رموز لا يحتويها Cairo تُكتب بخط DejaVu Sans الاحتياطي.
 */
function pdf_report_draw_rows(\Mpdf\Mpdf $mpdf, Closure $measure, Closure $fontFor, array $columns, array $widths, bool $nowrapOk, array $rows, ?array $totalCells): void
{
    $lineH = PDF_REPORT_FONT_PT * 25.4 / 72 * PDF_REPORT_LINE;
    $right = $mpdf->w - $mpdf->rMargin;
    $edges = []; // [يسار, يمين] لكل عمود؛ العمود الأول في أقصى اليمين
    $x = $right;
    foreach ($columns as $j => $c) {
        $edges[$j] = [$x - $widths[$j], $x];
        $x -= $widths[$j];
    }
    $left = $x;
    $hex = static fn (string $h): array => array_map('hexdec', str_split(ltrim($h, '#'), 2));
    $borderRgb = $hex(PDF_COLORS['border']);
    $strongRgb = $hex(PDF_COLORS['text2']);
    $textRgb = $hex(PDF_COLORS['text']);
    $mutedRgb = $hex(PDF_COLORS['muted']);
    $surfaceRgb = $hex(PDF_COLORS['surface2']);

    // التفاف النص بالكلمات حسب عرضها بعد التشكيل (المسافة تفصل التشكيل بين الكلمات، فالمجموع دقيق)
    // القياس (في تقسيم الأسطر) يغيّر الخط الحالي دون كتابته في الملف؛ أول تغيير خط بعده يُكتب إجباريًا
    $fontDirty = true;
    $wrap = static function (string $text, float $width, string $style) use ($measure, $fontFor): array {
        $font = $fontFor($text);
        if ($text === '' || $measure($text, $style, $font) <= $width) {
            return [$text];
        }
        $space = $measure(' ', $style, $font);
        $lines = [];
        $cur = '';
        $curW = 0.0;
        foreach (explode(' ', $text) as $word) {
            if ($word === '') {
                continue;
            }
            $w = $measure($word, $style, $font);
            if ($w > $width) {
                // كلمة أعرض من العمود: تُقسم على حروف حتى لا يخرج النص عن الخلية
                if ($cur !== '') {
                    $lines[] = $cur;
                }
                $cur = '';
                $curW = 0.0;
                foreach (mb_str_split($word) as $ch) {
                    $cw2 = $measure($ch, $style, $font);
                    if ($cur !== '' && $curW + $cw2 > $width) {
                        $lines[] = $cur;
                        $cur = '';
                        $curW = 0.0;
                    }
                    $cur .= $ch;
                    $curW += $cw2;
                }
                continue;
            }
            if ($cur !== '' && $curW + $space + $w > $width) {
                $lines[] = $cur;
                $cur = $word;
                $curW = $w;
            } else {
                $curW += ($cur === '' ? 0 : $space) + $w;
                $cur = $cur === '' ? $word : $cur . ' ' . $word;
            }
        }
        $lines[] = $cur;
        return $lines;
    };

    $saveAuto = $mpdf->autoPageBreak;
    $mpdf->autoPageBreak = false; // فواصل الصفحات هنا، قبل كل صف
    $y = $mpdf->y;
    $penReady = false;
    $strongY = null;
    // أقصى عدد أسطر للخلية حتى يتسع الصف في صفحة واحدة مع صف العناوين
    $maxLines = max(1, (int) floor(($mpdf->h - $mpdf->tMargin - $mpdf->bMargin) / $lineH) - 6);

    // تقسيم نصوص الصف إلى أسطر، وحساب ارتفاعه. kind: head | row | total | empty
    $layout = static function (array $texts, string $kind) use ($columns, $edges, $left, $right, $nowrapOk, $lineH, $maxLines, $wrap): array {
        $style = in_array($kind, ['head', 'total'], true) ? 'B' : '';
        $cellLines = [];
        $n = 1;
        foreach ($texts as $j => $text) {
            if ($kind === 'empty') {
                $lines = [$text];
            } else {
                $inner = $edges[$j][1] - $edges[$j][0] - 2 * PDF_REPORT_PAD_X;
                $keep = $kind !== 'head' && $columns[$j]['nowrap'] && $nowrapOk;
                $lines = $keep ? [$text] : $wrap($text, $inner, $style);
            }
            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, 0, $maxLines);
                $lines[$maxLines - 1] .= ' …';
            }
            $cellLines[$j] = $lines;
            $n = max($n, count($lines));
        }
        return [$cellLines, $n * $lineH + 2 * PDF_REPORT_PAD_Y, $style];
    };

    $paint = static function (array $cellLines, float $h, string $style, string $kind) use (
        $mpdf, $columns, $edges, $left, $right, $lineH, $fontFor, &$fontDirty,
        $borderRgb, $strongRgb, $textRgb, $mutedRgb, $surfaceRgb, &$y, &$penReady, &$strongY
    ): void {
        if ($kind === 'head') {
            $mpdf->SetFillColor($surfaceRgb[0], $surfaceRgb[1], $surfaceRgb[2]);
            $mpdf->Rect($left, $y, $right - $left, $h, 'F');
        }
        if (!$penReady) {
            $mpdf->SetDrawColor($borderRgb[0], $borderRgb[1], $borderRgb[2]);
            $mpdf->SetLineWidth(0.18);
            $penReady = true;
        }
        $rgb = match ($kind) {
            'head' => $strongRgb,
            'empty' => $mutedRgb,
            default => $textRgb,
        };
        $mpdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        foreach ($cellLines as $j => $lines) {
            [$x0, $x1] = $kind === 'empty' ? [$left, $right] : $edges[$j];
            $align = $kind === 'empty' ? 'C' : ($columns[$j]['end'] ? 'L' : 'R');
            // النص الحر يأخذ اتجاهه من أول حرف قوي (مثل <bdi>)، والأرقام والتواريخ تبقى في سياق الصفحة
            $isolate = $kind === 'row' && !$columns[$j]['nowrap'];
            $cellText = implode(' ', $lines);
            $mpdf->directionality = $isolate ? pdf_text_dir($cellText) : 'rtl';
            $font = $fontFor($cellText);
            foreach ($lines as $k => $line) {
                if ($line === '') {
                    continue;
                }
                $mpdf->SetFont($font, $style, PDF_REPORT_FONT_PT, true, $fontDirty);
                $fontDirty = false;
                $mpdf->SetXY($x0 + PDF_REPORT_PAD_X, $y + PDF_REPORT_PAD_Y + $k * $lineH);
                $mpdf->WriteCell($x1 - $x0 - 2 * PDF_REPORT_PAD_X, $lineH, $line, 0, 0, $align);
            }
            $mpdf->directionality = 'rtl';
        }
        // الحدود: الرأسية كلها، والسفلية، والعلوية لصف العناوين فقط (الصفوف التالية تشترك في حد واحد)
        // الخطوط الرأسية تُرسم مرة واحدة لكل صفحة ($closeSegment)، إلا صف «لا توجد بيانات» الممتد
        if ($kind === 'empty') {
            $mpdf->Line($left, $y, $left, $y + $h);
            $mpdf->Line($right, $y, $right, $y + $h);
        }
        $mpdf->Line($left, $y + $h, $right, $y + $h);
        if ($kind === 'head') {
            $mpdf->Line($left, $y, $right, $y);
        }
        if ($kind === 'total') {
            $strongY = $y; // خط أغمق فوق صف الإجمالي، يُرسم بعد الخطوط الرأسية
        }
        $y += $h;
    };

    $segTop = $y;
    $closeSegment = static function () use ($mpdf, $edges, $left, $borderRgb, &$segTop, &$y, &$penReady): void {
        if ($y > $segTop) {
            $mpdf->SetDrawColor($borderRgb[0], $borderRgb[1], $borderRgb[2]);
            $mpdf->SetLineWidth(0.18);
            $penReady = true;
            $mpdf->Line($left, $segTop, $left, $y);
            foreach ($edges as [$x0, $x1]) {
                $mpdf->Line($x1, $segTop, $x1, $y);
            }
        }
        $segTop = $y;
    };

    $labels = array_map(fn ($c) => $c['label'], $columns);
    $heading = $layout($labels, 'head');
    $drawHeading = static function () use ($heading, $paint, &$segTop, &$y): void {
        $segTop = $y;
        $paint($heading[0], $heading[1], $heading[2], 'head');
    };
    $freshPage = true;
    $drawRow = static function (array $texts, string $kind) use ($mpdf, $layout, $paint, $drawHeading, $closeSegment, &$y, &$penReady, &$freshPage, &$fontDirty): void {
        [$cellLines, $h, $style] = $layout($texts, $kind);
        $fontDirty = true;
        if (!$freshPage && $y + $h > $mpdf->PageBreakTrigger) {
            $closeSegment();
            $mpdf->AddPage();
            $y = $mpdf->y;
            $penReady = false;
            $fontDirty = true;
            $drawHeading();
        }
        $paint($cellLines, $h, $style, $kind);
        $freshPage = false;
    };

    $drawHeading();
    foreach ($rows as $row) {
        $texts = [];
        foreach ($columns as $j => $c) {
            $texts[$j] = pdf_report_value($c['type'], $row[$c['key']] ?? null);
        }
        $drawRow($texts, 'row');
    }
    if (!$rows) {
        $closeSegment();
        $drawRow([0 => 'لا توجد بيانات.'], 'empty');
        $segTop = $y;
    }
    if ($totalCells !== null) {
        $drawRow($totalCells, 'total');
    }
    $closeSegment();
    if ($strongY !== null) {
        $mpdf->SetDrawColor($strongRgb[0], $strongRgb[1], $strongRgb[2]);
        $mpdf->SetLineWidth(0.35);
        $mpdf->Line($left, $strongY, $right, $strongY);
    }
    $mpdf->SetTextColor($textRgb[0], $textRgb[1], $textRgb[2]);
    $mpdf->SetFont('cairo', '', PDF_REPORT_FONT_PT);
    $mpdf->autoPageBreak = $saveAuto;
    $mpdf->SetY($y + 2);
}

/* ===================== الإرسال ===================== */

/**
 * يرسل ملف PDF للمتصفح وينهي التنفيذ.
 * $inline = true يعرضه في المتصفح، و false يحمّله كملف.
 * اسم الملف بالعربية عبر filename* (RFC 5987/6266) مع اسم بديل بحروف ASCII للمتصفحات القديمة.
 */
function pdf_send(string $bytes, string $filename, bool $inline): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (headers_sent($file, $line)) {
        throw new RuntimeException("pdf_send: headers already sent ({$file}:{$line})");
    }
    [$utf8, $ascii] = pdf_filenames($filename);
    http_response_code(200);
    header('Content-Type: application/pdf');
    header(sprintf(
        'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
        $inline ? 'inline' : 'attachment',
        $ascii,
        rawurlencode($utf8)
    ));
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    echo $bytes;
    exit;
}

/**
 * اسم ملف آمن: [الاسم بالعربية UTF-8, بديل ASCII]. يحذف رموز التحكم والفواصل والعلامات الخطرة في الترويسة.
 * @return array{0:string,1:string}
 */
function pdf_filenames(string $filename): array
{
    $name = clean_text($filename);
    $name = preg_replace('/[\\\\\/:*?"<>|;]+/u', ' ', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', ' .');
    $name = preg_replace('/\.pdf\z/i', '', $name) ?? '';
    if ($name === '') {
        $name = 'document';
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }
    $ascii = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '', '-._');
    if (!preg_match('/[A-Za-z]/', $ascii)) {
        $ascii = 'document' . ($ascii !== '' ? '-' . $ascii : '');
    }
    return [$name . '.pdf', $ascii . '.pdf'];
}
