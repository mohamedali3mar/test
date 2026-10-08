<?php
/*
 * يولد مجموعة ICONS في public_html/app/lib/icons.php من design/identity/icons.json
 * (المصدر: ملف الأيقونات المعتمد من مرحلة الهوية). التشغيل: php tools/icons-sync.php
 * يقبل كل أيقونة كعناصر SVG داخلية أو كـ <svg> كامل (يُنزع الغلاف). يرفض style وid وscript وon* وروابط خارجية.
 */
$root = dirname(__DIR__);
$json = json_decode((string) file_get_contents($root . '/design/identity/icons.json'), true, 512, JSON_THROW_ON_ERROR);
$lines = [];
foreach ($json as $name => $svg) {
    if (!preg_match('/^[a-z_]+$/', $name)) {
        fwrite(STDERR, "bad icon name: $name\n");
        exit(1);
    }
    $body = trim(preg_replace('~^\s*<svg\b[^>]*>|</svg>\s*$~', '', trim((string) $svg)));
    $body = preg_replace('/\s+/', ' ', $body);
    if (preg_match('/\b(style|id|on[a-z]+|href|xlink:href)\s*=|<script|<foreignObject|<use\b/i', $body)) {
        fwrite(STDERR, "unsafe markup in icon: $name\n");
        exit(1);
    }
    $lines[] = '    ' . var_export($name, true) . ' => ' . var_export($body, true) . ',';
}
$file = $root . '/public_html/app/lib/icons.php';
$src = (string) file_get_contents($file);
$block = "// ICONS:BEGIN (يولده tools/icons-sync.php، لا تعدله يدويًا)\nconst ICONS = [\n" . implode("\n", $lines) . "\n];\n// ICONS:END";
$new = preg_replace('~// ICONS:BEGIN.*?// ICONS:END~s', str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $src, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "markers not found in icons.php\n");
    exit(1);
}
file_put_contents($file, $new);
echo count($lines) . " icons written to app/lib/icons.php\n";
