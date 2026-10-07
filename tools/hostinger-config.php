<?php
declare(strict_types=1);

/*
 * يولد app/config.php لحزمة Hostinger من config.sample.php نفسه:
 *  - مفتاح تثبيت عشوائي جديد (32 حرفًا) في كل مرة.
 *  - بيانات القاعدة وعنوان الموقع من متغيرات البيئة إن وُجدت (WOOD_DB_NAME، WOOD_DB_USER، WOOD_DB_PASS، WOOD_BASE_URL)،
 *    وإلا تبقى فارغة ليكتبها صاحب الموقع.
 * القيم تُكتب بـ var_export فتبقى صحيحة مهما كانت الرموز فيها (مثل & أو ' أو \).
 * الاستخدام: php tools/hostinger-config.php <النموذج> <ملف الإخراج>
 * الملف الناتج سري: لا يُرفع إلى git (يُكتب في dist/ المستبعد في .gitignore).
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php tools/hostinger-config.php <config.sample.php> <out config.php>\n");
    exit(2);
}
$s = (string) file_get_contents($argv[1]);
$set = function (string $pattern, string $value) use (&$s): void {
    $n = 0;
    $s = preg_replace_callback($pattern, fn ($m) => $m[1] . var_export($value, true) . ',', $s, 1, $n);
    if ($n !== 1) {
        fwrite(STDERR, "config template changed, pattern not found: {$pattern}\n");
        exit(1);
    }
};
$set("/('install_key' => )'',/", bin2hex(random_bytes(16)));
$fields = ['name' => 'WOOD_DB_NAME', 'user' => 'WOOD_DB_USER', 'password' => 'WOOD_DB_PASS'];
foreach ($fields as $key => $env) {
    $v = getenv($env);
    if (is_string($v) && $v !== '') {
        $set("/('{$key}'\\s*=> )'',/", $v);
    }
}
$base = getenv('WOOD_BASE_URL');
if (is_string($base) && $base !== '') {
    $set("/('base_url' => )'',/", rtrim($base, '/'));
}
$s = str_replace(
    'انسخ هذا الملف باسم config.php في نفس المجلد (app/config.php) ثم عدّل القيم.',
    'هذا الملف جاهز لهذا الموقع: ارفعه كما هو داخل المجلد app (بجوار config.sample.php). راجع القيم قبل الرفع.',
    $s
);
file_put_contents($argv[2], $s);
@chmod($argv[2], 0600);
