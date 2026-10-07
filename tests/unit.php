<?php
declare(strict_types=1);

/*
 * اختبارات الوحدة: الحساب الدقيق (Num) مقارنة بـ Python، وقواعد الإدخال، والتنسيق.
 * وتُخرج ملف tests/output/parity.json لاختبار تطابق JavaScript (tests/parity.cjs).
 * التشغيل: php tests/unit.php
 */

require __DIR__ . '/lib.php';

function set_settings(array $s): void
{
    $cache = &settings_cache();
    $cache = $s + DEFAULT_SETTINGS;
}

/* ---------------------------------------------------------------- */
section('Num: مقارنة عشوائية مع أعداد Python الصحيحة الدقيقة');
mt_srand(20261007);
$cases = [];
$rand = function (int $maxLen): string {
    $len = mt_rand(1, $maxLen);
    $s = (string) mt_rand(1, 9);
    for ($i = 1; $i < $len; $i++) {
        $s .= (string) mt_rand(0, 9);
    }
    return mt_rand(0, 20) === 0 ? '0' : $s;
};
for ($i = 0; $i < 3000; $i++) {
    $a = $rand(30);
    $b = $rand(30);
    $n = mt_rand(0, 20);
    $cases[] = [$a, $b, $n, Num::add($a, $b), Num::mul($a, $b), Num::divPow10HalfUp($a . $b, $n), Num::cmp($a, $b)];
}
$tmp = __DIR__ . '/output/num_cases.json';
file_put_contents($tmp, json_encode($cases));
$py = <<<'PY'
import json, sys
bad = 0
for a, b, n, add, mul, rnd, cmp in json.load(open(sys.argv[1])):
    A, B = int(a), int(b)
    ab = int(a + b)
    p = 10 ** n
    exp_rnd = (ab + p // 2) // p if n > 0 else ab
    exp_cmp = (A > B) - (A < B)
    if str(A + B) != add or str(A * B) != mul or str(exp_rnd) != rnd or exp_cmp != cmp:
        bad += 1
print(bad)
PY;
file_put_contents(__DIR__ . '/output/num_check.py', $py);
$bad = trim((string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/output/num_check.py') . ' ' . escapeshellarg($tmp)));
check_eq('3000 حالة جمع وضرب وتقريب ومقارنة مطابقة', '0', $bad);
check_eq('toDecimal', '0.015000000000000000', Num::toDecimal('15000000000000000', 18));
check_eq('fromDecimal', '15000000000000000', Num::fromDecimal('0.015', 18));
check_eq('trimDecimal', '0.15', Num::trimDecimal('0.150000'));
check_eq('roundDecimal نصف لأعلى', '3.13', Num::roundDecimal('3.125', 2));
check('رفض الأعداد غير الصحيحة (سطر جديد في النهاية)', (function () {
    try {
        Num::norm("12\n");
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
})());

/* ---------------------------------------------------------------- */
section('الحساب: أمثلة المتطلبات');
$piece = piece_volume_um3(100000, 50000, 3000000);
check_eq('10 سم × 50 مللي × 3 متر = 0.015', '0.015', Num::trimDecimal(um3_to_m3($piece)));
check_eq('× 10 قطع = 0.15', '0.15', Num::trimDecimal(um3_to_m3(Num::mul($piece, '10'))));
check_eq('0.15 × 20000 = 3000.00', '3000.00', Num::toDecimal(sale_amount_piasters(Num::mul($piece, '10'), '2000000'), 2));
check_eq('100 قطعة = 1.5', '1.5', Num::trimDecimal(um3_to_m3(Num::mul($piece, '100'))));
check_eq('90 قطعة = 1.35', '1.35', Num::trimDecimal(um3_to_m3(Num::mul($piece, '90'))));

/* ---------------------------------------------------------------- */
section('قواعد الإدخال');
set_settings(['digits' => 'western']);
$dimCases = [
    ['10', 'cm', 100000], ['١٠', 'cm', 100000], ['۱۰', 'cm', 100000], ['50', 'mm', 50000], ['0.05', 'm', 50000],
    ['2.5', 'cm', 25000], ['٢٫٥', 'cm', 25000], ['.5', 'cm', 5000], ['5.', 'cm', 50000], ['2.50000', 'cm', 25000],
    [" 10\u{200F} ", 'cm', 100000], ["10\u{200B}", 'cm', 100000], ["\u{FEFF}10", 'cm', 100000], ['0.001', 'mm', 1],
    ['50', 'm', 50000000], ['0010', 'cm', 100000],
];
foreach ($dimCases as [$raw, $unit, $um]) {
    [$v, $err] = parse_dimension($raw, $unit, 'العرض');
    check_eq('بُعد مقبول: ' . json_encode($raw, JSON_UNESCAPED_UNICODE) . " $unit", $um, $v);
}
$dimBad = [
    ['', 'cm', 'أدخل العرض.'], ['-5', 'cm', 'لا يقبل قيمًا سالبة'], ['0', 'cm', 'أكبر من صفر'], ['0.000', 'cm', 'أكبر من صفر'],
    ['1,5', 'cm', 'الفاصلة'], ['1،5', 'cm', 'الفاصلة'], ['1/2', 'cm', 'بالنقطة العشرية'], ['abc', 'cm', 'أدخل رقمًا موجبًا'],
    ['1e3', 'cm', 'أدخل رقمًا موجبًا'], ['+5', 'cm', 'أدخل رقمًا موجبًا'], ['1.2.3', 'cm', 'أدخل رقمًا موجبًا'], ['.', 'cm', 'أدخل رقمًا موجبًا'],
    ['0.0001', 'mm', 'يقبل 3 أرقام عشرية'], ['0.00001', 'cm', 'يقبل 4 أرقام عشرية'], ['0.0000001', 'm', 'يقبل 6 أرقام عشرية'],
    ['50.000001', 'm', 'أكبر من الحد'], ['99999999999999999', 'mm', 'أكبر من الحد'], ['10', 'inch', 'اختر وحدة'],
    ["10\n", 'cm', null], ["1 0", 'cm', 'أدخل رقمًا موجبًا'],
];
foreach ($dimBad as [$raw, $unit, $needle]) {
    [$v, $err] = parse_dimension($raw, $unit, 'العرض');
    if ($needle === null) {
        check('مسافات وسطر جديد على الأطراف تُقص: ' . json_encode($raw), $v === 100000, (string) $err);
        continue;
    }
    check('بُعد مرفوض: ' . json_encode($raw, JSON_UNESCAPED_UNICODE) . " $unit", $v === null && str_contains((string) $err, $needle), (string) $err);
}
foreach ([['10', 10], ['١٠٠', 100], ['0007', 7], ['1000000', 1000000]] as [$raw, $q]) {
    check_eq("عدد مقبول: $raw", $q, parse_quantity($raw)[0]);
}
foreach ([['', 'أدخل عدد'], ['0', 'أكبر من صفر'], ['2.5', 'بدون كسور'], ['10.0', 'بدون كسور'], ['-1', 'سالبة'], ['+5', 'بدون كسور'],
    ['1,000', 'بدون فواصل'], ['1000001', 'أكبر من الحد'], ['abc', 'بدون كسور']] as [$raw, $needle]) {
    [$q, $err] = parse_quantity($raw);
    check("عدد مرفوض: $raw", $q === null && str_contains((string) $err, $needle), (string) $err);
}
foreach ([['20000', '2000000'], ['20000.5', '2000050'], ['٢٠٠٠٠', '2000000'], ['0.01', '1'], ['10000000', '1000000000']] as [$raw, $p]) {
    check_eq("سعر مقبول: $raw", $p, parse_price($raw)[0]);
}
foreach ([['0', 'أكبر من صفر'], ['20,000', 'بدون فواصل الآلاف'], ['1.005', 'رقمين عشريين'], ['10000000.01', 'أكبر من الحد'], ['-1', 'سالبة']] as [$raw, $needle]) {
    [$p, $err] = parse_price($raw);
    check("سعر مرفوض: $raw", $p === null && str_contains((string) $err, $needle), (string) $err);
}

/* ---------------------------------------------------------------- */
section('توحيد الأسماء ومنع التكرار');
check_eq('مسافات زائدة', 'زان أحمر', clean_text("  زان \t\n أحمر  "));
check_eq('سطر جديد لا يلصق الكلمات', 'a b', clean_text("a\nb"));
check_eq('Tab لا يلصق الكلمات', 'a b', clean_text("a\tb"));
check_eq('متعدد الأسطر يحفظ السطر', "سطر1\nسطر2", clean_text("سطر1\r\n\tسطر2", true));
check_eq('رموز التحكم تُحذف', 'ab', clean_text("a\u{0007}b"));
check_eq('أشكال الحروف المنسوخة تتوحد', name_key('زان'), name_key('ﺯﺍﻥ'));
check_eq('التطويل يُحذف', name_key('موسكي'), name_key('موسـكي'));
check('أ و ا لا تُدمجان', name_key('أرو') !== name_key('ارو'));
check('ة و ه لا تُدمجان', name_key('عزة') !== name_key('عزه'));
check_eq('حالة الأحرف اللاتينية', name_key('pine'), name_key('PINE'));

/* ---------------------------------------------------------------- */
section('التنسيق');
set_settings(['digits' => 'arabic', 'volume_decimals' => 'full', 'volume_pad' => '0']);
check_eq('أرقام عربية: فاصل الآلاف مسافة ضيقة والعلامة العشرية ٫', "٣\u{202F}٠٠٠٫٠٠", fmt_money('3000.00'));
check_eq('أرقام كبيرة بالعربية', "١٢\u{202F}٣٤٥\u{202F}٦٧٨٫٥٠", fmt_money('12345678.50'));
check_eq('حجم 0.15', '٠٫١٥', fmt_volume('0.150000000000000000'));
set_settings(['digits' => 'western', 'volume_decimals' => 'full']);
check_eq('دقة كاملة 0.00015625', '0.00015625', fmt_volume('0.000156250000000000'));
check_eq('أكثر من 9 خانات تُقرب مع ≈', '≈ 0.000000001', fmt_volume('0.000000000500000000'));
check_eq('قيمة صغيرة جدًا', 'أقل من 0.000000001', fmt_volume('0.000000000000000001'));
set_settings(['digits' => 'western', 'volume_decimals' => '3', 'volume_pad' => '1']);
check_eq('3 خانات مع الأصفار', '0.150', fmt_volume('0.15'));
check_eq('أقل من 0.001 لا تظهر صفرًا', 'أقل من 0.001', fmt_volume('0.0002'));
check_eq('الصفر يبقى صفرًا', '0.000', fmt_volume('0'));
set_settings(['digits' => 'western', 'volume_decimals' => '3', 'volume_pad' => '0']);
check_eq('3 خانات بدون أصفار', '0.15', fmt_volume('0.15'));
check_eq('فواصل الآلاف للحجم', '1,234.5', fmt_volume('1234.5'));
check('ملاحظة التقريب تظهر عند الحاجة', volume_display_rounded('0.00015625') && !volume_display_rounded('0.15'));
set_settings(['digits' => 'western']);
check_eq('عرض المقاس بترتيب ثابت', 'عرض 10 سم × تخانة 50 مللي × طول 3 متر',
    fmt_size(['width_um' => 100000, 'width_unit' => 'cm', 'thickness_um' => 50000, 'thickness_unit' => 'mm', 'length_um' => 3000000, 'length_unit' => 'm']));

/* ---------------------------------------------------------------- */
section('ملف حالات التطابق مع JavaScript');
$parity = [];
$configs = [
    ['digits' => 'western', 'volume_decimals' => 'full', 'volume_pad' => '0'],
    ['digits' => 'arabic', 'volume_decimals' => 'full', 'volume_pad' => '0'],
    ['digits' => 'arabic', 'volume_decimals' => '3', 'volume_pad' => '1'],
    ['digits' => 'western', 'volume_decimals' => '2', 'volume_pad' => '0'],
    ['digits' => 'western', 'volume_decimals' => '6', 'volume_pad' => '1'],
];
$raws = ['10', '١٠', '2.5', '٢٫٥', '-5', '0', '1,5', '1/2', 'abc', '0.0001', '0.00001', '51', '', ' 7 ', '10.0', '+5', '1e3',
    '20000', '20000.505', '1000001', '99999999999999999', '.5', '5.', '2.50000', "10\u{200F}", '0.000', '1،5', '10000000.01'];
$volumes = ['0', '0.015', '0.15', '1.35', '0.00015625', '0.0002', '1234.5678', '0.000000000500000000', '0.000000000000000001', '98765.4321'];
foreach ($configs as $c) {
    set_settings($c);
    $entry = ['config' => $c, 'dims' => [], 'qty' => [], 'price' => [], 'volume' => [], 'money' => []];
    foreach ($raws as $raw) {
        foreach (array_merge(array_keys(UNITS), ['inch']) as $unit) {
            [$v, $err] = parse_dimension($raw, $unit, 'الطول');
            $entry['dims'][] = [$raw, $unit, $v === null ? null : (string) $v, $err];
        }
        [$q, $err] = parse_quantity($raw);
        $entry['qty'][] = [$raw, $q, $err];
        [$p, $err] = parse_price($raw);
        $entry['price'][] = [$raw, $p, $err];
    }
    foreach ($volumes as $vol) {
        $entry['volume'][] = [m3_to_um3($vol), fmt_volume($vol)];
    }
    foreach (['0', '1', '300000', '123456789012'] as $pi) {
        $entry['money'][] = [$pi, fmt_money(Num::toDecimal($pi, 2))];
    }
    $parity[] = $entry;
}
file_put_contents(__DIR__ . '/output/parity.json', json_encode($parity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
check('أُنشئ ملف حالات التطابق', is_file(__DIR__ . '/output/parity.json'));

finish();
