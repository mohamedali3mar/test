<?php
declare(strict_types=1);

/*
 * يولد دليل الرفع المختصر (PDF عربي بخط Cairo) لحزمة Hostinger، بمحرك PDF الموجود في النظام نفسه.
 * الاستخدام: php tools/hostinger-guide.php <ملف الإخراج.pdf> [--prefilled] [--site=<النطاق>]
 *   --prefilled: ملف config.php في الحزمة فيه بيانات القاعدة بالفعل، فتصبح خطوته «ارفعه كما هو».
 *   --site: نطاق الموقع (مثل wood.example.com) ليظهر في الروابط بدل example.com.
 * يستدعيه tools/hostinger-bundle.sh. النص هنا مختصر الخطوات؛ التفاصيل الكاملة في INSTALL_HOSTINGER_AR.md.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php tools/hostinger-guide.php <out.pdf>\n");
    exit(2);
}
require dirname(__DIR__) . '/public_html/app/bootstrap.php';
$GLOBALS['APP_CONFIG']['pdf_temp_dir'] = sys_get_temp_dir() . '/wood-guide-pdf-cache';

$zip = 'wood-inventory-' . APP_VERSION . '-upload.zip';
$prefilled = in_array('--prefilled', $argv, true);
$site = 'example.com';
foreach ($argv as $a) {
    if (preg_match('/^--site=([A-Za-z0-9.\-]+)\z/', $a, $m)) {
        $site = $m[1];
    }
}
$custom = $site !== 'example.com';

/** خطوة مرقمة: عنوان ونقاط */
function guide_step(int $n, string $title, array $items): string
{
    $html = '<h2>' . h(digits((string) $n)) . '. ' . h($title) . '</h2><ul>';
    foreach ($items as $item) {
        $html .= '<li>' . $item . '</li>';
    }
    return $html . '</ul>';
}

/** نص لاتيني (اسم ملف أو قائمة في hPanel أو رابط) معزول باتجاهه داخل السطر العربي */
function ltr(string $s): string
{
    return '<span class="ltr" dir="ltr">' . h($s) . '</span>';
}

$css = pdf_css() . <<<CSS
h1 { font-size: 20pt; margin-bottom: 1mm; }
.lead { font-size: 11pt; color: #44403c; margin-bottom: 4mm; }
h2 { font-size: 13pt; font-weight: bold; margin: 5mm 0 1.5mm 0; color: #8a4510; }
ul { margin: 0 0 0 0; padding-right: 6mm; }
li { margin-bottom: 1.2mm; font-size: 10.5pt; }
.files td { border: 0.5pt solid #d6d3d1; padding: 1.6mm 2mm; font-size: 10.5pt; vertical-align: top; }
.files th { background-color: #f5f5f4; border: 0.5pt solid #d6d3d1; padding: 1.6mm 2mm; font-size: 9.5pt; text-align: right; }
.box { border: 0.75pt solid #93370d; background-color: #fffaeb; padding: 2.5mm 3mm; margin: 3mm 0; font-size: 10.5pt; }
.ltr { font-family: dejavusans; font-size: 9.5pt; }
.small { font-size: 9pt; color: #6b6560; }
CSS;

$html = '<h1>رفع نظام مخزون وحسابات الأخشاب على Hostinger</h1>'
    . '<p class="lead">' . ($custom ? 'الموقع: ' . ltr($site) . '. ' : '') . 'الإصدار ' . ltr(APP_VERSION) . '. التثبيت كله من لوحة Hostinger ‏(hPanel) والمتصفح، بدون أي أوامر أو برامج. الوقت المتوقع 20 دقيقة.</p>'
    . '<table class="files" width="100%"><thead><tr><th width="38%">الملف</th><th>ماذا تفعل به</th></tr></thead><tbody>'
    . '<tr><td>' . ltr($zip) . '</td><td>ملف النظام كاملًا. يُرفع ويُفك داخل ' . ltr('public_html') . ' (الخطوة 4).</td></tr>'
    . '<tr><td>' . ltr('config.php') . '</td><td>' . ($prefilled
        ? 'ملف الإعدادات، جاهز وفيه بيانات قاعدة البيانات ومفتاح تثبيت عشوائي. يُرفع كما هو داخل المجلد ' . ltr('app') . ' (الخطوة 5).'
        : 'ملف الإعدادات، جاهز وفيه مفتاح تثبيت عشوائي. تكتب فيه بيانات قاعدة البيانات فقط، ثم يُرفع داخل المجلد ' . ltr('app') . ' (الخطوة 5).') . '</td></tr>'
    . '<tr><td>' . ltr('SHA256SUMS') . '</td><td>بصمات الملفات للتأكد أنها لم تتغير أثناء النقل (اختياري).</td></tr>'
    . '</tbody></table>'
    . '<div class="box"><strong>مهم:</strong> ملف ' . ltr('config.php') . ' فيه كلمة مرور قاعدة البيانات ومفتاح التثبيت. لا ترسله لأحد، واحتفظ بنسخة منه في مكان آمن خارج الاستضافة. مفتاح التثبيت هو الطريق الوحيد لاستعادة حساب المدير إذا نسيت كلمة المرور.</div>';

$html .= guide_step(1, 'تفعيل HTTPS', [
    'من hPanel اختر ' . ltr('Security') . ' ثم ' . ltr('SSL') . '، وثبّت الشهادة المجانية للنطاق حتى تصبح ' . ltr('Active') . '.',
    'فعّل ' . ltr('Force HTTPS') . ' إن وُجد، وافتح ' . ltr('https://' . $site) . ' وتأكد من ظهور القفل في المتصفح.',
    'إذا كان النطاق على Cloudflare: اجعل وضع SSL هناك ' . ltr('Full') . ' وليس ' . ltr('Flexible') . '.',
]);
$html .= guide_step(2, 'إنشاء قاعدة البيانات', [
    ($prefilled ? '<strong>إذا أنشأتها بالفعل وكتبنا بياناتها في ' . ltr('config.php') . ' فتخطَّ هذه الخطوة.</strong> ' : '')
        . 'من hPanel اختر ' . ltr('Databases') . ' ثم ' . ltr('Management') . '.',
    'أنشئ قاعدة جديدة ومستخدمًا لها، واستخدم زر توليد كلمة مرور قوية.',
    'اكتب القيم الثلاث كاملة كما تظهر بالبادئة: اسم القاعدة (مثل ' . ltr('u123456789_wood') . ')، واسم المستخدم (مثل ' . ltr('u123456789_wooduser') . ')، وكلمة المرور.',
]);
$html .= guide_step(3, 'إعداد PHP', [
    'من ' . ltr('Advanced') . ' ثم ' . ltr('PHP Configuration') . ' اختر PHP ' . ltr('8.2') . ' أو ' . ltr('8.3') . ' واحفظ.',
    'في تبويب ' . ltr('PHP Extensions') . ' تأكد أن هذه مفعلة: ' . ltr('pdo_mysql, mbstring, zip, gd, intl') . '.',
]);
$html .= guide_step(4, 'رفع ملف النظام وفك ضغطه', [
    ($custom ? 'من hPanel اختر الموقع ' . ltr($site) . ' ثم ' : 'من ') . ltr('Files') . ' ثم ' . ltr('File Manager') . ' وادخل ' . ltr('public_html') . ' الخاص به. احذف منه ' . ltr('default.php') . ' وأي ملفات قديمة لا تحتاجها.',
    'اضغط ' . ltr('Upload') . ' وارفع ' . ltr($zip) . '، ثم اضغط عليه بالزر الأيمن واختر ' . ltr('Extract') . ' في نفس المجلد.',
    'احذف ملف ZIP بعد فك الضغط.',
    'من إعدادات File Manager فعّل ' . ltr('Show hidden files') . '، وتأكد من وجود ' . ltr('.htaccess') . ' بجوار ' . ltr('index.php') . '. لا تحذفه: هو الذي يحمي الإعدادات.',
    ...($custom ? [] : ['<span class="small">لتشغيل النظام في مجلد فرعي (مثل ' . ltr('example.com/wood') . '): أنشئ المجلد ' . ltr('wood') . ' داخل ' . ltr('public_html') . ' وارفع وفك الضغط بداخله.</span>']),
]);
$html .= guide_step(5, 'ملف الإعدادات config.php', $prefilled ? [
    'ملف ' . ltr('config.php') . ' المرفق فيه بيانات قاعدة البيانات بالفعل. لا تعدّل فيه شيئًا.',
    'في File Manager ادخل المجلد ' . ltr('app') . ' (ستجد فيه ' . ltr('config.sample.php') . ') وارفع ' . ltr('config.php') . ' بداخله.',
    'إذا غيرت كلمة مرور قاعدة البيانات لاحقًا من hPanel: افتح ' . ltr('app/config.php') . ' في File Manager ثم ' . ltr('Edit') . '، وغيّر القيمة بين علامتي التنصيص في سطر ' . ltr("'password'") . ' فقط، واحفظ.',
] : [
    'افتح ملف ' . ltr('config.php') . ' المرفق بالمفكرة (' . ltr('Notepad') . ') على جهازك، واكتب بين علامتي التنصيص في السطور الثلاثة: ' . ltr("'name'") . ' و' . ltr("'user'") . ' و' . ltr("'password'") . ' القيم من الخطوة 2.',
    'في سطر ' . ltr("'base_url'") . ' اكتب عنوان موقعك، مثل ' . ltr('https://example.com') . ' بدون ' . ltr('/') . ' في النهاية (في المجلد الفرعي اكتب النطاق فقط). هذا السطر اختياري.',
    'لا تغير باقي السطور، ولا تحذف أي فاصلة أو علامة تنصيص. احفظ الملف.',
    'في File Manager ادخل المجلد ' . ltr('app') . ' (ستجد فيه ' . ltr('config.sample.php') . ') وارفع ' . ltr('config.php') . ' بداخله.',
]);
$html .= guide_step(6, 'التثبيت وإنشاء حساب المدير', [
    'افتح ' . ltr('https://' . $site . '/install.php') . ($custom ? '.' : ' (بنطاقك).'),
    'اكتب مفتاح التثبيت: انسخه من سطر ' . ltr("'install_key'") . ' في ' . ltr('config.php') . ' بدون علامتي التنصيص.',
    'اكتب اسم الشركة، واسم أول فرع، واسم أول مخزن، واسم مستخدم المدير وكلمة مرور من 10 أحرف على الأقل.',
    'اضغط «تثبيت». النظام ينشئ الجداول والحساب ثم يحذف ' . ltr('install.php') . ' تلقائيًا. إذا ظهرت رسالة خطأ فهي تقول ما الناقص في ' . ltr('config.php') . '.',
    'سجّل الدخول، ثم من «حسابي» فعّل التحقق بخطوتين لحساب المدير.',
]);
$html .= guide_step(7, 'التأكد من الحماية', [
    'افتح ' . ltr('https://' . $site . '/app/config.php') . ': يجب أن تظهر رسالة رفض (403)، وليس محتوى الملف.',
    'تأكد أن ' . ltr('install.php') . ' لم يعد موجودًا في File Manager، وإلا احذفه يدويًا.',
    'دورة تجريبية: وارد، ثم فاتورة بيع، ثم طباعتها وتحميلها PDF، ثم تصدير المخزون إلى Excel. ثم ألغِ الفاتورة والوارد.',
]);
$html .= guide_step(8, 'النسخ الاحتياطي', [
    'من ' . ltr('Databases') . ' ثم ' . ltr('phpMyAdmin') . '، اختر القاعدة، ثم ' . ltr('Export') . ' بصيغة ' . ltr('SQL') . '. احفظ الملف خارج الاستضافة.',
    'خذ نسخة يوميًا أو أسبوعيًا على الأقل، وقبل أي تحديث. واحتفظ بنسخة من ' . ltr('config.php') . '.',
]);
$html .= '<h2>إذا ظهرت مشكلة</h2><ul>'
    . '<li><strong>«إعدادات النظام غير مكتملة»:</strong> ' . ltr('config.php') . ' غير موجود داخل ' . ltr('app') . '، أو فيه علامة محذوفة. ارفع النسخة المرفقة من جديد واكتب القيم بعناية.</li>'
    . '<li><strong>«تعذر الاتصال بقاعدة البيانات»:</strong> اسم القاعدة أو المستخدم أو كلمة المرور غير صحيح. انسخها كاملة بالبادئة.</li>'
    . '<li><strong>الصفحة تعيد التحميل بلا توقف (تحويل متكرر):</strong> شهادة SSL لم تتفعل بعد (الخطوة 1)، أو ' . ltr('Cloudflare') . ' على ' . ltr('Flexible') . '.</li>'
    . '<li><strong>صفحة خطأ عامة:</strong> السبب مسجل في ' . ltr('app/storage/logs/php-error.log') . '. تأكد من إصدار PHP في الخطوة 3.</li>'
    . '</ul>'
    . '<p class="small">الدليل الكامل (التحديث من إصدار سابق، واستعادة كلمة المرور، وملاحظات الحماية): ' . ltr('INSTALL_HOSTINGER_AR.md') . ' في ملف الوثائق. ودليل الاستخدام: ' . ltr('HANDOVER_AR.md') . '.</p>';

$mpdf = pdf_engine(['margin_top' => 18]);
$mpdf->SetTitle('رفع نظام الأخشاب على Hostinger');
$mpdf->SetAuthor('نظام مخزون وحسابات الأخشاب');
$mpdf->SetHTMLFooter('<table class="run-foot"><tr><td>صفحة {PAGENO} من {nbpg}</td><td class="end">دليل الرفع، الإصدار ' . ltr(APP_VERSION) . '</td></tr></table>');
$mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
pdf_write($mpdf, $html);
file_put_contents($argv[1], $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN));
echo "guide written: {$argv[1]}\n";
