<?php
declare(strict_types=1);

/*
 * أدوات الاختبار: تحميل مكتبات النظام بإعدادات اختبار، وقاعدة بيانات اختبار جديدة لكل مجموعة،
 * ودوال تحقق بسيطة تطبع النتيجة. لا يُرفع هذا المجلد إلى الاستضافة.
 */

require dirname(__DIR__) . '/public_html/app/bootstrap.php';

const TEST_DB_USER = 'wood_app';
const TEST_DB_PASS = 'Test-Pass-2026';

$GLOBALS['APP_CONFIG'] = [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => getenv('WOOD_TEST_DB') ?: 'wood_test',
        'user' => TEST_DB_USER,
        'password' => TEST_DB_PASS,
    ],
    'install_key' => 'test-install-key-123456',
    'force_https' => false,
    'timezone' => 'Africa/Cairo',
];
@mkdir(__DIR__ . '/output', 0775, true);
ini_set('error_log', __DIR__ . '/output/php-error.log');

$GLOBALS['T_PASS'] = 0;
$GLOBALS['T_FAIL'] = 0;
$GLOBALS['T_FAILS'] = [];

function test_db_name(): string
{
    return app_config()['db']['name'];
}

/** قاعدة بيانات جديدة فارغة مع تطبيق الترقيات (تتطلب root عبر unix socket لإنشاء القاعدة) */
function fresh_database(): PDO
{
    $name = test_db_name();
    if (!preg_match('/^wood_[a-z0-9_]+\z/', $name)) {
        throw new RuntimeException('Refusing to drop non-test database');
    }
    $root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$name`");
    $root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = db();
    $pdo->exec("USE `$name`");
    run_migrations($pdo);
    foreach (DEFAULT_SETTINGS as $k => $v) {
        save_setting($pdo, $k, $v);
    }
    reset_settings_cache();
    return $pdo;
}

function root_pdo(): PDO
{
    $name = test_db_name();
    return new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$name;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function seed_user(PDO $pdo, string $username = 'admin', string $password = 'Correct-Horse-9'): int
{
    $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), now()]);
    return (int) $pdo->lastInsertId();
}

function section(string $title): void
{
    echo "\n== {$title}\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['T_PASS']++;
        echo "  PASS  {$label}\n";
    } else {
        $GLOBALS['T_FAIL']++;
        $GLOBALS['T_FAILS'][] = $label . ($detail !== '' ? " ({$detail})" : '');
        echo "  FAIL  {$label}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
    }
}

function check_eq(string $label, mixed $expected, mixed $actual): void
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** يتوقع ValidationException ويعيد أخطاءها */
function expect_validation(string $label, callable $fn): array
{
    try {
        $fn();
        check($label, false, 'no ValidationException thrown');
        return [];
    } catch (ValidationException $e) {
        check($label, true);
        return $e->errors;
    }
}

function finish(): never
{
    echo "\nResult: {$GLOBALS['T_PASS']} passed, {$GLOBALS['T_FAIL']} failed\n";
    foreach ($GLOBALS['T_FAILS'] as $f) {
        echo "  - {$f}\n";
    }
    exit($GLOBALS['T_FAIL'] > 0 ? 1 : 0);
}

function stock_qty(PDO $pdo, int $itemId, int $whId): ?int
{
    $stmt = $pdo->prepare('SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?');
    $stmt->execute([$itemId, $whId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int) $v;
}

function item_id_for(PDO $pdo, int $typeId, int $w, int $t, int $l): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM items WHERE wood_type_id = ? AND width_um = ? AND thickness_um = ? AND length_um = ?');
    $stmt->execute([$typeId, $w, $t, $l]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int) $v;
}

function receipt_input(int $wh, int $type, string $w, string $wu, string $t, string $tu, string $l, string $lu, string $qty, array $extra = []): array
{
    return $extra + [
        'warehouse_id' => (string) $wh, 'wood_type_id' => (string) $type,
        'width' => $w, 'width_unit' => $wu, 'thickness' => $t, 'thickness_unit' => $tu,
        'length' => $l, 'length_unit' => $lu, 'quantity' => $qty,
        'party_name' => '', 'reference' => '', 'notes' => '',
        'request_token' => new_request_token(),
    ];
}

function sale_input(int $wh, array $lines, array $extra = []): array
{
    $l = [];
    foreach ($lines as [$item, $qty, $price]) {
        $l[] = ['type_id' => '', 'item_id' => (string) $item, 'quantity' => (string) $qty, 'price' => (string) $price];
    }
    return $extra + [
        'warehouse_id' => (string) $wh, 'lines' => $l, 'party_name' => '', 'notes' => '',
        'request_token' => new_request_token(),
    ];
}

function transfer_input(int $from, int $to, array $lines, array $extra = []): array
{
    $l = [];
    foreach ($lines as [$item, $qty]) {
        $l[] = ['type_id' => '', 'item_id' => (string) $item, 'quantity' => (string) $qty];
    }
    return $extra + [
        'warehouse_id' => (string) $from, 'to_warehouse_id' => (string) $to, 'lines' => $l, 'notes' => '',
        'request_token' => new_request_token(),
    ];
}
