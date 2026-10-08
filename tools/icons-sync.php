<?php
/*
 * يولد مجموعة ICONS في public_html/app/lib/icons.php من design/identity/icons.json
 * (المصدر: ملف الأيقونات المعتمد من مرحلة الهوية). التشغيل: php tools/icons-sync.php
 * يقبل كل أيقونة كعناصر SVG داخلية أو كـ <svg> كامل (يُنزع الغلاف).
 * الفحص بقائمة سماح وليس قائمة منع: الأيقونة تُحلل كـ XML، وتُقبل فقط عناصر الرسم البسيطة وخصائص الهندسة
 * والخط، وقيم fill وstroke إما none أو currentColor. أي شيء آخر (style، script، روابط، url()، رموز HTML
 * داخل SVG، نص) يوقف الأداة، لأن الرموز تُطبع كما هي في كل صفحة ومنها صفحة الدخول.
 */
const ICON_ELEMENTS = ['path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'g'];
const ICON_ATTRIBUTES = [
    'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'transform', 'opacity',
    'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule',
];

/** سبب الرفض، أو null إذا كانت الأيقونة آمنة */
function icon_problem(string $body): ?string
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML('<svg xmlns="http://www.w3.org/2000/svg">' . $body . '</svg>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || $doc->documentElement === null) {
        return 'not well-formed SVG';
    }
    $walk = static function (DOMNode $node) use (&$walk): ?string {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->nodeValue ?? '') !== '') {
                    return 'text content';
                }
                continue;
            }
            if (!$child instanceof DOMElement) {
                return 'node type ' . $child->nodeType;
            }
            if ($child->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($child->localName, ICON_ELEMENTS, true)) {
                return 'element <' . $child->nodeName . '>';
            }
            foreach ($child->attributes as $attr) {
                if ($attr->namespaceURI !== null || !in_array($attr->name, ICON_ATTRIBUTES, true)) {
                    return 'attribute ' . $attr->nodeName;
                }
                $value = trim($attr->value);
                if (in_array($attr->name, ['fill', 'stroke'], true) && !in_array($value, ['none', 'currentColor'], true)) {
                    return $attr->name . '="' . $value . '"';
                }
                if (!preg_match('/^[A-Za-z0-9 .,()+\-#%]*\z/', $value) || stripos($value, 'url') !== false) {
                    return 'value of ' . $attr->name;
                }
            }
            if (($bad = $walk($child)) !== null) {
                return $bad;
            }
        }
        return null;
    };
    return $walk($doc->documentElement);
}

$root = dirname(__DIR__);
$json = json_decode((string) file_get_contents($root . '/design/identity/icons.json'), true, 512, JSON_THROW_ON_ERROR);
$lines = [];
foreach ($json as $name => $svg) {
    if (!is_string($name) || !preg_match('/^[a-z_]+$/', $name)) {
        fwrite(STDERR, "bad icon name: $name\n");
        exit(1);
    }
    $body = trim(preg_replace('~^\s*<svg\b[^>]*>|</svg>\s*$~', '', trim((string) $svg)));
    $body = preg_replace('/\s+/', ' ', $body);
    if (($problem = icon_problem($body)) !== null) {
        fwrite(STDERR, "unsafe markup in icon $name: $problem\n");
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
