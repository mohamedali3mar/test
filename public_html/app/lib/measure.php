<?php
defined('APP_ROOT') || exit;

/*
 * وحدات القياس والتحويل والتحقق من الأرقام.
 *
 * كل بُعد يُخزن كعدد صحيح بالميكرومتر (جزء من مليون من المتر).
 * حدود الدقة المقبولة لكل وحدة تجعل أي قيمة مقبولة عددًا صحيحًا من الميكرومترات بالضبط:
 *   مللي: حتى 3 أرقام عشرية، سم: حتى 4، متر: حتى 6.
 * لذلك التحويل دقيق تمامًا ولا يحدث أي تقريب للأبعاد أو حجم القطعة.
 *
 * ملاحظة: assets/js/app.js يطبق نفس القواعد ونفس الرسائل حرفيًا. أي تعديل هنا يُنقل إلى هناك،
 * واختبار التطابق في tests/ يكشف أي اختلاف.
 */

const UNITS = [
    'mm' => ['label' => 'مللي', 'decimals' => 3],
    'cm' => ['label' => 'سم',   'decimals' => 4],
    'm'  => ['label' => 'متر',  'decimals' => 6],
];

const DIMENSIONS = [
    'width'     => 'العرض',
    'thickness' => 'التخانة',
    'length'    => 'الطول',
];

const MAX_DIM_UM = 50000000;              // 50 متر لأي بُعد
const MAX_QTY = 1000000;                  // أقصى عدد قطع في السطر الواحد
const MAX_STOCK = 4000000000;             // أقصى رصيد لصنف في مخزن
const MAX_PRICE_PIASTERS = '1000000000';  // 10,000,000.00 للمتر المكعب
const VOLUME_SCALE = 18;                  // م³ = ميكرومتر³ / 10^18

function unit_label(string $unit): string
{
    return UNITS[$unit]['label'] ?? $unit;
}

function is_unit(string $unit): bool
{
    return isset(UNITS[$unit]);
}

/** يحول الأرقام العربية والفارسية والفاصلة العشرية العربية إلى صيغة موحدة ويحذف الرموز غير المرئية */
function normalize_number_input(string $s): string
{
    if (!mb_check_encoding($s, 'UTF-8')) {
        return '?';
    }
    $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}\x{FEFF}]/u', '', $s) ?? '?';
    $s = strtr($s, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.',
    ]);
    return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+\z/u', '', $s) ?? '?';
}

/**
 * يقرأ رقمًا عشريًا موجبًا ويعيده عددًا صحيحًا مضروبًا في 10^maxDecimals.
 * الأصفار الزائدة بعد العلامة العشرية مقبولة (2.50 = 2.5).
 * @return array{0: ?string, 1: ?string} [القيمة, رمز الخطأ]
 */
function parse_decimal_input(string $raw, int $maxDecimals): array
{
    $s = normalize_number_input($raw);
    if ($s === '') {
        return [null, 'empty'];
    }
    if (preg_match('/[,\x{060C}\x{066C}]/u', $s)) {
        return [null, 'comma'];
    }
    if (preg_match('/^[-\x{2212}\x{2013}]/u', $s)) {
        return [null, 'negative'];
    }
    if (str_contains($s, '/')) {
        return [null, 'fraction'];
    }
    if (!preg_match('/^(\d*)(?:\.(\d*))?\z/', $s, $m) || ($m[1] === '' && ($m[2] ?? '') === '')) {
        return [null, 'invalid'];
    }
    $int = ltrim($m[1], '0');
    $frac = rtrim($m[2] ?? '', '0');
    if (strlen($frac) > $maxDecimals) {
        return [null, 'decimals'];
    }
    if (strlen($int) > 15) {
        return [null, 'too_large'];
    }
    $scaled = Num::norm(($int === '' ? '0' : $int) . str_pad($frac, $maxDecimals, '0'));
    if ($scaled === '0') {
        return [null, 'zero'];
    }
    return [$scaled, null];
}

/**
 * يتحقق من بُعد واحد ويعيده بالميكرومتر.
 * @return array{0: ?int, 1: ?string} [ميكرومتر, رسالة خطأ عربية]
 */
function parse_dimension(string $raw, string $unit, string $label): array
{
    if (!is_unit($unit)) {
        return [null, "اختر وحدة {$label}."];
    }
    $decimals = UNITS[$unit]['decimals'];
    [$um, $err] = parse_decimal_input($raw, $decimals);
    if ($err === null && Num::cmp($um, (string) MAX_DIM_UM) > 0) {
        $err = 'too_large';
    }
    if ($err === null) {
        return [(int) $um, null];
    }
    $example = digits('2.5');
    $msg = match ($err) {
        'empty' => "أدخل {$label}.",
        'comma' => "{$label}: لا تستخدم الفاصلة. استخدم النقطة للكسور، مثل {$example}",
        'negative' => "{$label} لا يقبل قيمًا سالبة.",
        'fraction' => "{$label}: اكتب الكسر بالنقطة العشرية، مثل {$example}",
        'zero' => "{$label} يجب أن يكون أكبر من صفر.",
        'decimals' => sprintf('%s بوحدة %s يقبل %s أرقام عشرية على الأكثر.', $label, unit_label($unit), digits((string) $decimals)),
        'too_large' => sprintf('%s أكبر من الحد المسموح (%s متر).', $label, digits('50')),
        default => sprintf('%s: أدخل رقمًا موجبًا، مثل %s أو %s', $label, digits('10'), $example),
    };
    return [null, $msg];
}

/** @return array{0: ?int, 1: ?string} */
function parse_quantity(string $raw): array
{
    $s = normalize_number_input($raw);
    if ($s === '') {
        return [null, 'أدخل عدد القطع.'];
    }
    if (preg_match('/^[-\x{2212}\x{2013}]/u', $s)) {
        return [null, 'عدد القطع لا يقبل قيمًا سالبة.'];
    }
    if (preg_match('/[,\x{060C}\x{066C}]/u', $s)) {
        return [null, 'اكتب عدد القطع بدون فواصل.'];
    }
    if (!preg_match('/^\d+\z/', $s)) {
        return [null, 'عدد القطع يجب أن يكون عددًا صحيحًا بدون كسور.'];
    }
    $s = ltrim($s, '0');
    if ($s === '') {
        return [null, 'عدد القطع يجب أن يكون أكبر من صفر.'];
    }
    if (strlen($s) > 7 || (int) $s > MAX_QTY) {
        return [null, sprintf('عدد القطع أكبر من الحد المسموح في السطر الواحد (%s قطعة).', fmt_int(MAX_QTY))];
    }
    return [(int) $s, null];
}

/** @return array{0: ?string, 1: ?string} السعر بالقروش */
function parse_price(string $raw): array
{
    [$p, $err] = parse_decimal_input($raw, 2);
    if ($err === null && Num::cmp($p, MAX_PRICE_PIASTERS) > 0) {
        $err = 'too_large';
    }
    if ($err === null) {
        return [$p, null];
    }
    $msg = match ($err) {
        'empty' => 'أدخل سعر المتر المكعب.',
        'comma' => sprintf('السعر: اكتب الرقم بدون فواصل الآلاف، واستخدم النقطة للكسور، مثل %s', digits('20000.50')),
        'negative' => 'السعر لا يقبل قيمًا سالبة.',
        'fraction' => sprintf('السعر: اكتب الكسر بالنقطة العشرية، مثل %s', digits('20000.50')),
        'zero' => 'سعر المتر المكعب يجب أن يكون أكبر من صفر.',
        'decimals' => 'السعر يقبل رقمين عشريين على الأكثر (القروش).',
        'too_large' => sprintf('السعر أكبر من الحد المسموح (%s للمتر المكعب).', fmt_int(10000000)),
        default => sprintf('السعر: أدخل رقمًا موجبًا، مثل %s', digits('20000')),
    };
    return [null, $msg];
}

/** حجم القطعة بالميكرومتر المكعب (دقيق) */
function piece_volume_um3(int $w, int $t, int $l): string
{
    return Num::mul(Num::mul((string) $w, (string) $t), (string) $l);
}

/** م³ كنص عشري بدقة كاملة (18 خانة) */
function um3_to_m3(string $um3): string
{
    return Num::toDecimal($um3, VOLUME_SCALE);
}

function m3_to_um3(string $m3): string
{
    return Num::fromDecimal($m3, VOLUME_SCALE);
}

/** قيمة السطر بالقروش = حجم الكمية × سعر المتر، مقربة لأقرب قرش (النصف لأعلى) */
function sale_amount_piasters(string $totalUm3, string $pricePiasters): string
{
    return Num::divPow10HalfUp(Num::mul($totalUm3, $pricePiasters), VOLUME_SCALE);
}

/** قيمة البعد بوحدته كنص دقيق، مثل 50 أو 2.5 */
function um_in_unit(int $um, string $unit): string
{
    return Num::trimDecimal(Num::toDecimal((string) $um, UNITS[$unit]['decimals']));
}

/** البعد بالمتر كنص دقيق */
function um_in_meters(int $um): string
{
    return Num::trimDecimal(Num::toDecimal((string) $um, 6));
}
