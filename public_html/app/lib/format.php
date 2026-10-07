<?php
defined('APP_ROOT') || exit;

/*
 * دوال التنسيق fmt_* تعيد نصًا عاديًا دائمًا. التهريب يتم عند الإخراج بـ h().
 */

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** يطبق شكل الأرقام المختار في الإعدادات (عربية أو إنجليزية) */
function digits(string $s): string
{
    if (app_setting('digits') !== 'arabic') {
        return $s;
    }
    return strtr($s, [
        '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
        '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        '.' => '٫', ',' => '٬',
    ]);
}

/** يضيف فواصل الآلاف لنص عشري موجب */
function group_thousands(string $dec): string
{
    $parts = explode('.', $dec, 2);
    $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
    return isset($parts[1]) ? $int . '.' . $parts[1] : $int;
}

function fmt_int(int|string $n): string
{
    return digits(group_thousands((string) $n));
}

/**
 * حجم بالمتر المكعب حسب إعداد «دقة عرض الحجم»:
 *  - full: القيمة الدقيقة بدون أصفار زائدة (حتى 9 خانات، وإلا تقريب مع ≈)
 *  - 2..6: عدد خانات ثابت، مع إظهار الأصفار أو حذفها حسب volume_pad
 */
function fmt_volume(string $m3): string
{
    $isZero = trim(str_replace('.', '', $m3), '0') === '';
    $mode = app_setting('volume_decimals');
    if (!preg_match('/^[2-6]\z/', $mode)) {
        $exact = Num::trimDecimal($m3);
        $dec = str_contains($exact, '.') ? strlen(explode('.', $exact, 2)[1]) : 0;
        if ($dec <= 9) {
            return digits(group_thousands($exact));
        }
        $r = Num::trimDecimal(Num::roundDecimal($m3, 9));
        return $r === '0' ? 'أقل من ' . digits('0.000000001') : '≈ ' . digits(group_thousands($r));
    }
    $n = (int) $mode;
    $r = Num::roundDecimal($m3, $n);
    if (!$isZero && trim(str_replace('.', '', $r), '0') === '') {
        return 'أقل من ' . digits('0.' . str_repeat('0', $n - 1) . '1');
    }
    if (app_setting('volume_pad') !== '1') {
        $r = Num::trimDecimal($r);
    }
    return digits(group_thousands($r));
}

/** هل يختلف الحجم المعروض عن القيمة الدقيقة؟ (لإظهار ملاحظة التقريب في الفاتورة) */
function volume_display_rounded(string $m3): bool
{
    $mode = app_setting('volume_decimals');
    $exact = Num::trimDecimal($m3);
    $dec = str_contains($exact, '.') ? strlen(explode('.', $exact, 2)[1]) : 0;
    $limit = preg_match('/^[2-6]\z/', $mode) ? (int) $mode : 9;
    return $dec > $limit;
}

/** مبلغ مالي بخانتين عشريتين */
function fmt_money(string $amount): string
{
    return digits(group_thousands(Num::roundDecimal($amount, 2)));
}

function fmt_money_currency(string $amount, ?string $currency = null): string
{
    return fmt_money($amount) . ' ' . ($currency ?? app_setting('currency'));
}

/** بعد واحد مع وحدته، مثل: 10 سم */
function fmt_dim(int $um, string $unit): string
{
    return digits(group_thousands(um_in_unit($um, $unit))) . ' ' . unit_label($unit);
}

/**
 * وصف كامل للمقاس بترتيب ثابت ومُسمّى، حتى لا يلتبس الترتيب في اتجاه RTL.
 * $units يسمح بعرض المقاس بوحدات حركة معينة بدل وحدات الصنف.
 */
function fmt_size(array $item, ?array $units = null): string
{
    $u = $units ?? $item;
    return 'عرض ' . fmt_dim((int) $item['width_um'], $u['width_unit'])
        . ' × تخانة ' . fmt_dim((int) $item['thickness_um'], $u['thickness_unit'])
        . ' × طول ' . fmt_dim((int) $item['length_um'], $u['length_unit']);
}

function fmt_meters(array $item): string
{
    return 'عرض ' . digits(um_in_meters((int) $item['width_um']))
        . ' × تخانة ' . digits(um_in_meters((int) $item['thickness_um']))
        . ' × طول ' . digits(um_in_meters((int) $item['length_um'])) . ' متر';
}

function fmt_datetime(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $ts = strtotime($dt);
    return $ts === false ? $dt : digits(date('Y-m-d H:i', $ts));
}

const DOC_KIND_LABELS = ['in' => 'وارد', 'sale' => 'بيع', 'transfer' => 'تحويل'];

function kind_label(string $kind): string
{
    return DOC_KIND_LABELS[$kind] ?? $kind;
}

/** مثل: بيع رقم ١٥ */
function doc_label(array $doc): string
{
    return kind_label($doc['kind']) . ' رقم ' . fmt_int((int) $doc['doc_no']);
}

/** عنوان المستند المطبوع */
function doc_print_title(string $kind): string
{
    return match ($kind) {
        'sale' => 'فاتورة بيع',
        'transfer' => 'إذن تحويل بين المخازن',
        default => 'إذن وارد',
    };
}
