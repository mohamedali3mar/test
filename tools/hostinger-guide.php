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
$ready = 'wood-inventory-' . APP_VERSION . '-ready.zip';
$prefilled = in_array('--prefilled', $argv, true);
$site = 'example.com';
foreach ($argv as $a) {
    if (preg_match('/^--site=([A-Za-z0-9.\-]+)\z/', $a, $m)) {
        $site = $m[1];
    }
}
$custom = $site !== 'example.com';
// اسم القاعدة والمستخدم (ليسا سرًا) ليطابقهما صاحب الموقع في hPanel؛ كلمة المرور لا تُطبع أبدًا
$dbName = (string) (getenv('WOOD_DB_NAME') ?: '');
$dbUser = (string) (getenv('WOOD_DB_USER') ?: '');

/** خطوة مرقمة: عنوان ونقاط */
function guide_step(int $n, string $title, array $items): string
{
    $html = '<h2>' . h(digits((string) $n)) . '. ' . h($title) . '</h2><ul>';
    foreach ($items as $item) {
        $html .= '<li>' . $item . '</li>';
    }
    return $html . '</ul>';
}

/**
 * اسم ملف يبدأ بنقطة: mPDF ينقل النقطة إلى آخر الاسم داخل السطر العربي مهما عُزل، فيُكتب الاسم بلا نقطة
 * مع توضيح أنه يبدأ بنقطة، حتى يتعرف عليه صاحب الموقع في File Manager.
 */
function dotfile(string $name): string
{
    return 'الملف ' . ltr(ltrim($name, '.')) . ' (اسمه يبدأ بنقطة)';
}

/** نص لاتيني (اسم ملف أو قائمة في hPanel أو رابط) معزول باتجاهه داخل السطر العربي */
function ltr(string $s): string
{
    // bdo يفرض ترتيب الحروف من اليسار: تبقى النقطة في بداية اسم مثل .htaccess (span بـ dir لا يكفي في mPDF)
    return '<bdo class="ltr" dir="ltr">' . h($s) . '</bdo>';
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
    . '<p class="lead">' . ($custom ? 'الموقع: ' . ltr($site) . '. ' : '') . 'الإصدار ' . ltr(APP_VERSION) . '. التثبيت كله من لوحة Hostinger ‏(hPanel) والمتصفح، بدون أي أوامر أو برامج. الوقت المتوقع 20 دقيقة. أسماء القوائم هنا بالإنجليزية كما في hPanel؛ إذا ظهرت لك بالعربية أو لم تجدها فاكتب الاسم في شريط البحث أعلى hPanel.</p>'
    . '<table class="files" width="100%"><thead><tr><th width="38%">الملف</th><th>ماذا تفعل به</th></tr></thead><tbody>'
    . ($prefilled
        ? '<tr><td>' . ltr($ready) . '</td><td><strong>الملف الذي ترفعه.</strong> النظام كاملًا، ومعه ملف الإعدادات ببيانات قاعدة البيانات في مكانه (' . ltr('app/config.php') . '). يُرفع مرة واحدة (الخطوة 4).</td></tr>'
            . '<tr><td>' . ltr('config.php') . '</td><td>نسخة من ملف الإعدادات للاحتفاظ بها خارج الاستضافة. موجود بالفعل داخل الملف الجاهز، فلا ترفعه.</td></tr>'
            . '<tr><td>' . ltr($zip) . '</td><td>نفس النظام بدون ملف الإعدادات. احتفظ به للتحديثات أو لاستعادة الملفات لاحقًا.</td></tr>'
            . '<tr><td>' . ltr('wood-inventory-' . APP_VERSION . '-docs.zip') . '</td><td>الوثائق: دليل الاستخدام والدليل الكامل للتثبيت. لا يُرفع للاستضافة.</td></tr>'
        : '<tr><td>' . ltr($zip) . '</td><td>ملف النظام كاملًا. يُرفع ويُفك داخل ' . ltr('public_html') . ' (الخطوة 4).</td></tr>'
            . '<tr><td>' . ltr('config.php') . '</td><td>ملف الإعدادات، جاهز وفيه مفتاح تثبيت عشوائي. تكتب فيه بيانات قاعدة البيانات فقط، ثم يُرفع داخل المجلد ' . ltr('app') . ' (الخطوة 5).</td></tr>')
    . '<tr><td>' . ltr('SHA256SUMS') . '</td><td>بصمات الملفات للتأكد أنها لم تتغير أثناء النقل (اختياري).</td></tr>'
    . '</tbody></table>'
    . '<div class="box"><strong>مهم:</strong> ملف ' . ltr('config.php') . ($prefilled ? ' والملف الجاهز فيهما' : ' فيه') . ' كلمة مرور قاعدة البيانات ومفتاح التثبيت. لا ترسله لأحد، واحتفظ بنسخة منه في مكان آمن خارج الاستضافة. مفتاح التثبيت هو الطريق الوحيد لاستعادة حساب المدير إذا نسيت كلمة المرور.</div>';

$html .= guide_step(1, 'تفعيل HTTPS', [
    'من hPanel اختر ' . ltr('Security') . ' ثم ' . ltr('SSL') . '، وثبّت الشهادة المجانية للنطاق حتى تصبح ' . ltr('Active') . '.',
    'فعّل ' . ltr('Force HTTPS') . ' إن وُجد، وافتح ' . ltr('https://' . $site) . ' وتأكد من ظهور القفل في المتصفح.',
    'إذا كان النطاق على Cloudflare: اجعل وضع SSL هناك ' . ltr('Full') . ' وليس ' . ltr('Flexible') . '.',
]);
$html .= guide_step(2, 'إنشاء قاعدة البيانات', [
    ($prefilled && $dbName !== ''
        ? 'ملف الإعدادات مكتوب لقاعدة اسمها ' . ltr($dbName) . ' ومستخدم اسمه ' . ltr($dbUser) . '. من hPanel اختر ' . ltr('Databases') . ' ثم ' . ltr('Management') . ' وتأكد أنهما ظاهران في الجدول بنفس الاسمين، ثم تخطَّ باقي هذه الخطوة. إذا غيرت كلمة مرور هذا المستخدم بعد تجهيز الملف فانظر الخطوة 5.'
        : 'من hPanel اختر ' . ltr('Databases') . ' ثم ' . ltr('Management') . '.'),
    'أنشئ قاعدة جديدة ومستخدمًا لها، واستخدم زر توليد كلمة مرور قوية.',
    'اكتب القيم الثلاث كاملة كما تظهر بالبادئة: اسم القاعدة (مثل ' . ltr('u123456789_wood') . ')، واسم المستخدم (مثل ' . ltr('u123456789_wooduser') . ')، وكلمة المرور.',
    '<strong>القاعدة يجب أن تكون جديدة وفارغة وخاصة بهذا النظام.</strong> لا تستخدم قاعدة موقع أو برنامج آخر: جداول النظام بأسماء عامة (مثل ' . ltr('users') . ') وقد تتداخل معه.',
]);
$html .= guide_step(3, 'إعداد PHP', [
    'من ' . ltr('Advanced') . ' ثم ' . ltr('PHP Configuration') . ' اختر PHP ' . ltr('8.2') . ' أو ' . ltr('8.3') . ' واحفظ.',
    'في تبويب ' . ltr('PHP Extensions') . ' تأكد أن هذه مفعلة: ' . ltr('pdo_mysql, mbstring, zip, gd, intl') . '.',
]);
$html .= $prefilled ? guide_step(4, 'رفع الملف الجاهز', [
    '<strong>أولًا تأكد من المجلد الصحيح.</strong> ' . ltr($site) . ' نطاق فرعي: من hPanel افتح ' . ltr('Domains') . ' ثم ' . ltr('Subdomains') . ' (النطاقات الفرعية)، وانظر المجلد المكتوب بجواره، مثل ' . ltr('public_html/wood') . '. النظام يُرفع داخل هذا المجلد فقط.',
    '<strong>لا تحذف ولا ترفع أي شيء في ' . ltr('public_html') . ' نفسه</strong> إذا كان مجلد النطاق الفرعي بداخله: هذا مجلد موقعك الآخر، وأي حذف فيه يوقفه.',
    'الطريقة الأسهل: من hPanel افتح لوحة الموقع ' . ltr($site) . ' نفسه، ثم «رفع ملفات الموقع» (' . ltr('Upload website files') . ')، واسحب الملف ' . ltr($ready) . ' وأفلته. بعد الرفع افتح File Manager وتأكد أن الملفات نزلت في مجلد النطاق الفرعي وليس في مجلد الموقع الآخر.',
    'الطريقة البديلة (الأضمن): من ' . ltr('File Manager') . ' ادخل مجلد النطاق الفرعي نفسه، ثم ' . ltr('Upload') . ' للملف الجاهز، ثم اضغط عليه بالزر الأيمن واختر ' . ltr('Extract') . ' داخل نفس المجلد، ثم احذف ملف ZIP.',
    'لا ترفع ملف قاعدة بيانات ' . ltr('SQL') . ': النظام ينشئ جداول قاعدة البيانات بنفسه في الخطوة 6. إذا طلبت صفحة الرفع ملف قاعدة بيانات فتخطَّ هذا الجزء.',
    'للتأكد: في File Manager فعّل ' . ltr('Show hidden files') . '. يجب أن ترى داخل مجلد النطاق الفرعي: ' . ltr('index.php') . '، و' . dotfile('.htaccess') . '، والمجلد ' . ltr('app') . ' وبداخله ' . ltr('config.php') . '. لا تحذف ' . dotfile('.htaccess') . ': هو الذي يحمي الإعدادات.',
]) : guide_step(4, 'رفع ملف النظام وفك ضغطه', [
    ($custom ? 'من hPanel اختر الموقع ' . ltr($site) . ' ثم ' : 'من ') . ltr('Files') . ' ثم ' . ltr('File Manager') . ' وادخل ' . ltr('public_html') . ' الخاص به. احذف منه ' . ltr('default.php') . ' وأي ملفات قديمة لا تحتاجها.',
    'اضغط ' . ltr('Upload') . ' وارفع ' . ltr($zip) . '، ثم اضغط عليه بالزر الأيمن واختر ' . ltr('Extract') . ' في نفس المجلد.',
    'احذف ملف ZIP بعد فك الضغط.',
    'من إعدادات File Manager فعّل ' . ltr('Show hidden files') . '، وتأكد من وجود ' . dotfile('.htaccess') . ' بجوار ' . ltr('index.php') . '. لا تحذفه: هو الذي يحمي الإعدادات.',
    ...($custom ? [] : ['<span class="small">لتشغيل النظام في مجلد فرعي (مثل ' . ltr('example.com/wood') . '): أنشئ المجلد ' . ltr('wood') . ' داخل ' . ltr('public_html') . ' وارفع وفك الضغط بداخله.</span>']),
]);
$html .= guide_step(5, 'ملف الإعدادات config.php', $prefilled ? [
    'موجود بالفعل داخل الملف الجاهز في ' . ltr('app/config.php') . '، وفيه بيانات قاعدة البيانات. لا تحتاج لرفعه أو تعديله.',
    'إذا غيرت كلمة مرور قاعدة البيانات لاحقًا من hPanel: افتح ' . ltr('app/config.php') . ' في File Manager ثم ' . ltr('Edit') . '، وغيّر القيمة بين علامتي التنصيص في سطر ' . ltr("'password'") . ' فقط، واحفظ.',
] : [
    'افتح ملف ' . ltr('config.php') . ' المرفق بالمفكرة (' . ltr('Notepad') . ') على جهازك، واكتب بين علامتي التنصيص في السطور الثلاثة: ' . ltr("'name'") . ' و' . ltr("'user'") . ' و' . ltr("'password'") . ' القيم من الخطوة 2.',
    'في سطر ' . ltr("'base_url'") . ' اكتب عنوان موقعك، مثل ' . ltr('https://example.com') . ' بدون ' . ltr('/') . ' في النهاية (في المجلد الفرعي اكتب النطاق فقط). هذا السطر اختياري.',
    'لا تغير باقي السطور، ولا تحذف أي فاصلة أو علامة تنصيص. احفظ الملف.',
    'في File Manager ادخل المجلد ' . ltr('app') . ' (ستجد فيه ' . ltr('config.sample.php') . ') وارفع ' . ltr('config.php') . ' بداخله.',
]);
$html .= guide_step(6, 'التثبيت وإنشاء حساب المدير', [
    'افتح ' . ltr('https://' . $site . '/install.php') . ($custom ? '.' : ' (بنطاقك).'),
    'اكتب مفتاح التثبيت: افتح ' . ltr('config.php') . ' المرفق بالمفكرة (' . ltr('Notepad') . ': زر الفأرة الأيمن على الملف ثم ' . ltr('Open with') . ')، أو من File Manager افتح ' . ltr('app/config.php') . ' ثم ' . ltr('Edit') . '. انسخ القيمة المكتوبة في سطر ' . ltr("'install_key'") . ' بين علامتي التنصيص فقط.',
    'اكتب اسم الشركة، واسم أول فرع، واسم أول مخزن، واسم مستخدم المدير وكلمة مرور من 10 أحرف على الأقل.',
    'اضغط «تثبيت». النظام ينشئ الجداول والحساب ثم يحذف ' . ltr('install.php') . ' تلقائيًا. إذا ظهرت رسالة خطأ فهي تقول ما الناقص في ' . ltr('config.php') . '.',
    'سجّل الدخول، ثم اضغط اسمك أعلى الصفحة بجوار «خروج» (صفحة «حسابي»)، وفي قسم «التحقق بخطوتين» فعّله لحساب المدير بتطبيق مصادقة على الهاتف.',
]);
$html .= guide_step(7, 'التأكد من الحماية', [
    'افتح ' . ltr('https://' . $site . '/app/fonts/OFL.txt') . ': يجب أن تظهر رسالة رفض (403). إذا ظهر نص ترخيص إنجليزي فالحماية لا تعمل: تأكد من وجود ' . dotfile('.htaccess') . ' داخل المجلد ' . ltr('app') . '.',
    'افتح ' . ltr('https://' . $site . '/assets/js/app.js') . ': يجب أن تظهر رسالة رفض (403). إذا ظهر كود ف' . dotfile('.htaccess') . ' الرئيسي غير موجود أو تغير: ارفع الملف الجاهز من جديد.',
    'تأكد أن ' . ltr('install.php') . ' لم يعد موجودًا في File Manager، وإلا احذفه يدويًا.',
    'دورة تجريبية: وارد، ثم فاتورة بيع، ثم طباعتها وتحميلها PDF، ثم تصدير المخزون إلى Excel، ثم ألغِ الفاتورة والوارد. انتبه: المستند الملغى يبقى في السجل بحالة «ملغاة» ويُستهلك رقمه، ونوع الخشب المستخدم لا يُحذف، فاستخدم نوعًا حقيقيًا وكمية صغيرة واكتب «تجربة» في الملاحظات.',
]);
$html .= guide_step(8, 'النسخ الاحتياطي', [
    'من ' . ltr('Databases') . ' ثم ' . ltr('phpMyAdmin') . '، اختر القاعدة، ثم ' . ltr('Export') . ' بصيغة ' . ltr('SQL') . '. احفظ الملف خارج الاستضافة.',
    'خذ نسخة يوميًا أو أسبوعيًا على الأقل، وقبل أي تحديث. واحتفظ بنسخة من ' . ltr('config.php') . '.',
]);
$html .= '<h2>إذا ظهرت مشكلة</h2><ul>'
    . ($prefilled
        ? '<li><strong>رسالة «انسخ الملف app/config.sample.php باسم app/config.php…» أو «إعدادات النظام غير مكتملة»:</strong> ملف الإعدادات غير موجود في مكانه، غالبًا لأن الملف المرفوع هو ' . ltr($zip) . ' بدل ' . ltr($ready) . '. من File Manager ادخل المجلد ' . ltr('app') . ' وارفع ' . ltr('config.php') . ' المرفق كما هو.</li>'
        : '<li><strong>رسالة «انسخ الملف app/config.sample.php باسم app/config.php…»:</strong> ملف ' . ltr('config.php') . ' غير موجود داخل ' . ltr('app') . '. راجع الخطوة 5.</li>')
    . '<li><strong>«حدث خطأ غير متوقع» بعد تعديل ' . ltr('config.php') . ':</strong> غالبًا حُذفت علامة تنصيص أو فاصلة. ارفع النسخة المرفقة من جديد.</li>'
    . '<li><strong>«تعذر الاتصال بقاعدة البيانات»:</strong> اسم القاعدة أو المستخدم أو كلمة المرور غير صحيح. انسخها كاملة بالبادئة.</li>'
    . '<li><strong>الصفحة تعيد التحميل بلا توقف (تحويل متكرر):</strong> شهادة SSL لم تتفعل بعد (الخطوة 1)، أو ' . ltr('Cloudflare') . ' على ' . ltr('Flexible') . '.</li>'
    . '<li><strong>صفحة خطأ عامة:</strong> السبب مسجل في ' . ltr('app/storage/logs/php-error.log') . '. تأكد من إصدار PHP في الخطوة 3.</li>'
    . '</ul>'
    . '<p class="small">في ملف الوثائق ' . ltr('wood-inventory-' . APP_VERSION . '-docs.zip') . ' (لا يُرفع للاستضافة): الدليل الكامل ' . ltr('INSTALL_HOSTINGER_AR.md') . ' (التحديث من إصدار سابق، واستعادة كلمة المرور، وملاحظات الحماية)، ودليل الاستخدام ' . ltr('HANDOVER_AR.md') . '. تُفتح بأي محرر نصوص.</p>';

$mpdf = pdf_engine(['margin_top' => 18]);
$mpdf->SetTitle('رفع نظام الأخشاب على Hostinger');
$mpdf->SetAuthor('نظام مخزون وحسابات الأخشاب');
$mpdf->SetHTMLFooter('<table class="run-foot"><tr><td>صفحة {PAGENO} من {nbpg}</td><td class="end">دليل الرفع، الإصدار ' . ltr(APP_VERSION) . '</td></tr></table>');
$mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
pdf_write($mpdf, $html);
file_put_contents($argv[1], $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN));
echo "guide written: {$argv[1]}\n";
