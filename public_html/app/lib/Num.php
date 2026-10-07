<?php
defined('APP_ROOT') || exit;

/**
 * حساب دقيق على أعداد صحيحة غير سالبة ممثلة كنصوص.
 * يُستخدم لحساب الأحجام والمبالغ دون أخطاء الفاصلة العائمة ودون الاعتماد على bcmath.
 */
final class Num
{
    public static function isInt(string $a): bool
    {
        return preg_match('/^\d+\z/', $a) === 1;
    }

    public static function norm(string $a): string
    {
        if (!self::isInt($a)) {
            throw new InvalidArgumentException('Not a non-negative integer: ' . $a);
        }
        $a = ltrim($a, '0');
        return $a === '' ? '0' : $a;
    }

    public static function cmp(string $a, string $b): int
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    public static function add(string $a, string $b): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $s = $carry + ($i >= 0 ? ord($a[$i]) - 48 : 0) + ($j >= 0 ? ord($b[$j]) - 48 : 0);
            $out .= chr(48 + $s % 10);
            $carry = intdiv($s, 10);
            $i--;
            $j--;
        }
        return self::norm(strrev($out));
    }

    public static function mul(string $a, string $b): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $A = self::chunks($a);
        $B = self::chunks($b);
        $res = array_fill(0, count($A) + count($B) + 1, 0);
        foreach ($A as $i => $x) {
            if ($x === 0) {
                continue;
            }
            foreach ($B as $j => $y) {
                $res[$i + $j] += $x * $y;
            }
        }
        $n = count($res);
        for ($k = 0; $k < $n - 1; $k++) {
            $res[$k + 1] += intdiv($res[$k], 10000);
            $res[$k] %= 10000;
        }
        while ($n > 1 && $res[$n - 1] === 0) {
            $n--;
        }
        $out = (string) $res[$n - 1];
        for ($k = $n - 2; $k >= 0; $k--) {
            $out .= str_pad((string) $res[$k], 4, '0', STR_PAD_LEFT);
        }
        return self::norm($out);
    }

    /** round(a / 10^n) بتقريب النصف لأعلى */
    public static function divPow10HalfUp(string $a, int $n): string
    {
        $a = self::norm($a);
        if ($n <= 0) {
            return $a;
        }
        $len = strlen($a);
        if ($len < $n) {
            return '0';
        }
        $int = $len === $n ? '0' : substr($a, 0, $len - $n);
        return $a[$len - $n] >= '5' ? self::add($int, '1') : self::norm($int);
    }

    /** يحول عددًا صحيحًا مضروبًا في 10^scale إلى نص عشري كامل الدقة */
    public static function toDecimal(string $a, int $scale): string
    {
        $a = self::norm($a);
        if ($scale <= 0) {
            return $a;
        }
        $a = str_pad($a, $scale + 1, '0', STR_PAD_LEFT);
        return substr($a, 0, -$scale) . '.' . substr($a, -$scale);
    }

    /** يحول نصًا عشريًا (مثل قيمة DECIMAL من قاعدة البيانات) إلى عدد صحيح مضروب في 10^scale */
    public static function fromDecimal(string $dec, int $scale): string
    {
        if (!preg_match('/^(\d+)(?:\.(\d+))?\z/', $dec, $m)) {
            throw new InvalidArgumentException('Bad decimal: ' . $dec);
        }
        $frac = $m[2] ?? '';
        if (strlen($frac) > $scale) {
            $extra = substr($frac, $scale);
            if (trim($extra, '0') !== '') {
                throw new InvalidArgumentException('Precision loss: ' . $dec);
            }
            $frac = substr($frac, 0, $scale);
        }
        return self::norm($m[1] . str_pad($frac, $scale, '0'));
    }

    /** يحذف الأصفار الزائدة بعد العلامة العشرية */
    public static function trimDecimal(string $dec): string
    {
        if (strpos($dec, '.') === false) {
            return $dec;
        }
        $dec = rtrim(rtrim($dec, '0'), '.');
        return $dec === '' ? '0' : $dec;
    }

    /** يقرب نصًا عشريًا إلى عدد محدد من الخانات العشرية (النصف لأعلى) ويعيده بعدد الخانات كاملًا */
    public static function roundDecimal(string $dec, int $places): string
    {
        $parts = explode('.', $dec, 2);
        $scale = isset($parts[1]) ? strlen($parts[1]) : 0;
        $scale = max($scale, $places);
        $scaled = self::fromDecimal($dec, $scale);
        return self::toDecimal(self::divPow10HalfUp($scaled, $scale - $places), $places);
    }

    /** @return int[] أجزاء أساس 10000 تبدأ من الخانات الأقل */
    private static function chunks(string $a): array
    {
        $out = [];
        for ($i = strlen($a); $i > 0; $i -= 4) {
            $start = max(0, $i - 4);
            $out[] = (int) substr($a, $start, $i - $start);
        }
        return $out;
    }
}
