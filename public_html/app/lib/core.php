<?php
defined('APP_ROOT') || exit;

const APP_VERSION = '1.0.0';

final class AppConfigException extends RuntimeException
{
}

/** خطأ في المدخلات يعرض للمستخدم. errors: [اسم الحقل => رسالة] */
final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

const DEFAULT_SETTINGS = [
    'company_name'    => 'شركة الأخشاب',
    'currency'        => 'جنيه مصري',
    'unit_width'      => 'cm',
    'unit_thickness'  => 'mm',
    'unit_length'     => 'm',
    'digits'          => 'arabic',   // arabic | western
    'volume_decimals' => 'full',     // full | 2..6
    'volume_pad'      => '0',        // 1 = إظهار الأصفار في وضع الخانات الثابتة
];

function app_config(): array
{
    return $GLOBALS['APP_CONFIG'] ?? [];
}

/** يعيد قيمة إعداد من قاعدة البيانات (مع قيم افتراضية قبل التثبيت) */
function app_setting(string $name): string
{
    $cache = &settings_cache();
    if ($cache === null) {
        $cache = DEFAULT_SETTINGS;
        try {
            foreach (db()->query('SELECT name, value FROM settings') as $row) {
                $cache[$row['name']] = $row['value'];
            }
        } catch (Throwable $e) {
            // قبل التثبيت لا توجد جداول؛ تُستخدم القيم الافتراضية
        }
    }
    return $cache[$name] ?? '';
}

function &settings_cache(): ?array
{
    static $cache = null;
    return $cache;
}

function reset_settings_cache(): void
{
    $cache = &settings_cache();
    $cache = null;
}

function save_setting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?')
        ->execute([$name, $value, $value]);
}

/**
 * نص حر من المستخدم: يوحد Unicode ‏(NFC)، ويحوّل كل أنواع المسافات إلى مسافة عادية، ثم يحذف
 * رموز التحكم والتنسيق غير المرئية. في وضع السطر الواحد يصبح السطر الجديد مسافة.
 */
function clean_text(?string $s, bool $multiline = false): string
{
    $s = (string) $s;
    if ($s === '' || !mb_check_encoding($s, 'UTF-8')) {
        return '';
    }
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_C);
        if (is_string($n)) {
            $s = $n;
        }
    }
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $spaces = '\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}';
    if ($multiline) {
        $s = str_replace(["\u{2028}", "\u{2029}"], "\n", $s);
        $s = preg_replace('/[^\S\n]+|[' . $spaces . ']+/u', ' ', $s) ?? '';
        $s = preg_replace('/[^\P{C}\n]+/u', '', $s) ?? '';
        $s = preg_replace('/ *\n */', "\n", $s) ?? '';
        $s = preg_replace('/ {2,}/', ' ', $s) ?? '';
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? '';
    } else {
        $s = preg_replace('/[\s\x{2028}\x{2029}' . $spaces . ']+/u', ' ', $s) ?? '';
        $s = preg_replace('/\p{C}+/u', '', $s) ?? '';
        $s = preg_replace('/ {2,}/', ' ', $s) ?? '';
    }
    return trim($s, " \n");
}

/**
 * مفتاح مقارنة الأسماء لمنع التكرار: NFKC (يوحد أشكال الحروف المنسوخة من PDF مثل ﺯﺍﻥ)،
 * وتوحيد حالة الأحرف اللاتينية، وحذف التطويل «ـ»، وتوحيد المسافات.
 * لا يدمج أ/ا ولا ى/ي ولا ة/ه حتى لا تُدمج أسماء مختلفة فعلًا.
 */
function name_key(string $display): string
{
    $k = $display;
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($k, Normalizer::FORM_KC);
        if (is_string($n)) {
            $k = $n;
        }
    }
    $k = mb_convert_case($k, MB_CASE_FOLD, 'UTF-8');
    $k = str_replace("\u{0640}", '', $k);
    $k = preg_replace('/\s+/u', ' ', $k) ?? $k;
    return trim($k);
}

function new_request_token(): string
{
    return bin2hex(random_bytes(16));
}

function is_request_token(string $t): bool
{
    return preg_match('/^[a-f0-9]{32}\z/', $t) === 1;
}
