<?php
declare(strict_types=1);

/*
 * اختبارات الوحدة للتحقق بخطوتين: متجهات RFC 6238 (SHA1)، و base32 حسب RFC 4648،
 * وفرق الساعة المسموح (±1 خطوة فقط)، ورفض إعادة استخدام الرمز.
 * لا تحتاج قاعدة بيانات. التشغيل: php tests/unit_totp.php
 */

require __DIR__ . '/lib.php';

/* ---------------------------------------------------------------- */
section('RFC 6238: متجهات الاختبار الرسمية (SHA1، السر "12345678901234567890")');
$secret = base32_encode('12345678901234567890');
check_eq('السر بترميز base32', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
$vectors = [
    59 => '94287082',
    1111111109 => '07081804',
    1111111111 => '14050471',
    1234567890 => '89005924',
    2000000000 => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $t => $code8) {
    $step = totp_step($t);
    check_eq("T=$t: 8 أرقام كما في RFC", $code8, totp_code($secret, $step, 8));
    check_eq("T=$t: 6 أرقام (آخر 6 من رمز RFC)", substr($code8, -6), totp_code($secret, $step));
    $ok = totp_verify($secret, substr($code8, -6), $used, null, $t);
    check("T=$t: totp_verify يقبل الرمز ويعيد رقم الخطوة", $ok && $used === $step, var_export([$ok, $used], true));
}
check_eq('رقم الخطوة = الوقت ÷ 30', 37037037, totp_step(1111111111));

/* ---------------------------------------------------------------- */
section('base32 (RFC 4648)');
foreach (['f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $plain => $enc) {
    check_eq("ترميز " . $plain, $enc, base32_encode($plain));
    check_eq("فك " . $enc, $plain, base32_decode($enc));
}
check_eq('ترميز نص فارغ', '', base32_encode(''));
$roundTrips = 0;
for ($i = 0; $i < 300; $i++) {
    $bin = random_bytes(1 + $i % 40);
    $roundTrips += base32_decode(base32_encode($bin)) === $bin ? 1 : 0;
}
check_eq('300 رحلة ذهاب وعودة لبيانات عشوائية (1 إلى 40 بايت)', 300, $roundTrips);
check_eq('الفك يقبل الأحرف الصغيرة والمسافات والحشو', 'foobar', base32_decode('mzxw 6ytb oi======'));
foreach (['', 'MZXW1', 'MZXW8', 'MZ!W6', "MZXW6\n", 'مرحبا'] as $bad) {
    check('نص base32 غير صالح يعيد null: ' . json_encode($bad, JSON_UNESCAPED_UNICODE), base32_decode($bad) === null);
}
$s1 = totp_new_secret();
$s2 = totp_new_secret();
check('السر الجديد 32 حرفًا base32 = 20 بايت عشوائية', strlen($s1) === 32 && strlen((string) base32_decode($s1)) === 20, $s1);
check('سران جديدان مختلفان', $s1 !== $s2);
check_eq('السر في مجموعات من 4 أحرف', 'GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', totp_secret_groups($secret));

/* ---------------------------------------------------------------- */
section('فرق الساعة: يُقبل ±1 خطوة فقط');
$t = 1111111111;
$step = totp_step($t);
foreach ([-1, 0, 1] as $d) {
    $ok = totp_verify($secret, totp_code($secret, $step + $d), $used, null, $t);
    check("رمز الخطوة {$d}+ مقبول", $ok && $used === $step + $d, var_export([$ok, $used], true));
}
foreach ([-3, -2, 2, 3] as $d) {
    check("رمز الخطوة {$d}+ مرفوض", !totp_verify($secret, totp_code($secret, $step + $d), $used, null, $t) && $used === null);
}
check('بعد 30 ثانية يبقى الرمز السابق مقبولًا', totp_verify($secret, totp_code($secret, $step), $used, null, $t + 30) && $used === $step);
check('بعد 60 ثانية يُرفض', !totp_verify($secret, totp_code($secret, $step), $used, null, $t + 60));

/* ---------------------------------------------------------------- */
section('رفض إعادة استخدام الرمز (آخر خطوة مستخدمة)');
$code = totp_code($secret, $step);
check('أول استخدام مقبول', totp_verify($secret, $code, $used, null, $t) && $used === $step);
check('نفس الرمز مرة أخرى مرفوض (الخطوة = آخر خطوة)', !totp_verify($secret, $code, $used, $step, $t));
check('رمز خطوة أقدم من آخر خطوة مرفوض', !totp_verify($secret, totp_code($secret, $step - 1), $used, $step, $t));
check('رمز الخطوة التالية مقبول بعدها', totp_verify($secret, totp_code($secret, $step + 1), $used, $step, $t) && $used === $step + 1);
check('آخر خطوة أقدم: الرمز الحالي مقبول', totp_verify($secret, $code, $used, $step - 1, $t) && $used === $step);

/* ---------------------------------------------------------------- */
section('صيغة الرمز المدخل');
$digits = totp_code($secret, $step);
$arabic = strtr($digits, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
check('أرقام عربية مقبولة', totp_verify($secret, $arabic, $used, null, $t));
check('مسافة في منتصف الرمز مقبولة', totp_verify($secret, substr($digits, 0, 3) . ' ' . substr($digits, 3), $used, null, $t));
foreach (['', '12345', '1234567', 'abcdef', $digits . 'x', ' '] as $bad) {
    check('رمز بصيغة خاطئة مرفوض: ' . json_encode($bad), !totp_verify($secret, $bad, $used, null, $t));
}
check('سر غير صالح يعيد false بدون استثناء', !totp_verify('not-base32!', $digits, $used, null, $t));

/* ---------------------------------------------------------------- */
section('رابط otpauth');
$uri = totp_uri($secret, 'شركة النيل: للأخشاب', 'admin');
check('يبدأ بـ otpauth://totp/ ويحتوي السر والجهة', str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret=' . $secret) && str_contains($uri, '&issuer=%D8%B4'), $uri);
check('الرابط كله ASCII (اسم الشركة العربي مُرمّز)', preg_match('/^[\x21-\x7e]+$/', $uri) === 1, $uri);
check('النقطتان في اسم الشركة لا تختلطان بفاصل الحساب', substr_count(rawurldecode(explode('?', $uri)[0]), ':') === 2, $uri);
check_eq('صيغة الرابط كاملة (SHA1 و 6 أرقام و 30 ثانية هي القيم الافتراضية)', 'otpauth://totp/Acme%20Wood:admin?secret=' . $secret . '&issuer=Acme%20Wood',
    totp_uri($secret, 'Acme Wood', 'admin'));
check('اسم الحساب = اسم المستخدم', str_contains(explode('?', $uri)[0], ':admin'));

finish();
