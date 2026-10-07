<?php
declare(strict_types=1);

/*
 * اختبارات تعدد المستخدمين والأدوار وسجل المراقبة (الترقية 004) على قاعدة بيانات MariaDB حقيقية.
 * التشغيل: WOOD_TEST_DB=wood_xxx php tests/integration_users_audit.php
 */

require __DIR__ . '/lib.php';

// المخرجات تُكتب مباشرة إلى STDOUT عبر المخزن المؤقت، فلا تُعتبر الترويسات «مرسلة»
// ويعمل session_regenerate_id() في الدخول وتغيير كلمة المرور كما في طلب حقيقي
ob_start(fn (string $buf) => fwrite(STDOUT, $buf) === false ? '' : '', 1);

// جلسة PHP حقيقية بدون ملفات تعريف، لدوال الدخول وتغيير كلمة المرور (session_regenerate_id)
@mkdir(__DIR__ . '/output/sessions', 0775, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(__DIR__ . '/output/sessions');
session_start();
$logFile = __DIR__ . '/output/php-error.log';
clearstatcache();
$logStart = is_file($logFile) ? filesize($logFile) : 0;

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
$_SERVER['REQUEST_METHOD'] = 'GET';

/** يحاكي جلسة مستخدم مسجل الدخول */
function act_as(PDO $pdo, int $id): void
{
    $u = session_user_row($pdo, $id);
    $_SESSION['user_id'] = $id;
    $_SESSION['username'] = (string) $u['username'];
    $_SESSION['auth_version'] = (int) $u['auth_version'];
    $_SESSION['role'] = user_role($u);
}

function act_as_nobody(): void
{
    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['auth_version']);
}

function last_audit_id(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
}

function audit_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
}

/** أسطر السجل بعد معرف معين */
function audit_after(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM audit_log WHERE id > ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/** يتحقق من سطر واحد جديد بالعملية والمستخدم والكيان المتوقع، ويعيده */
function expect_one_audit(PDO $pdo, string $label, int $after, string $action, ?int $userId, string $entity, ?int $entityId): array
{
    $rows = audit_after($pdo, $after);
    $row = $rows[0] ?? [];
    check("{$label}: سطر واحد في السجل", count($rows) === 1, 'rows=' . count($rows));
    check_eq("{$label}: العملية", $action, $row['action'] ?? null);
    check_eq("{$label}: المستخدم", $userId, isset($row['user_id']) ? (int) $row['user_id'] : null);
    check_eq("{$label}: الكيان", $entity, $row['entity'] ?? null);
    check_eq("{$label}: معرف الكيان", $entityId, isset($row['entity_id']) ? (int) $row['entity_id'] : null);
    return $row;
}

function details_of(array $row): ?array
{
    return $row['details'] === null ? null : json_decode((string) $row['details'], true, 512, JSON_THROW_ON_ERROR);
}

/** قاعدة بيانات بالإصدار 1 فقط (قبل الترقية 004)، كما عند عميل حالي قبل التحديث */
function database_v1(): PDO
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
    foreach (split_sql((string) file_get_contents(APP_ROOT . '/migrations/001_initial.sql')) as $stmt) {
        $pdo->exec($stmt);
    }
    save_setting($pdo, 'schema_version', '1');
    foreach (DEFAULT_SETTINGS as $k => $v) {
        save_setting($pdo, $k, $v);
    }
    reset_settings_cache();
    return $pdo;
}

function wait_lock_waits(PDO $root, int $n): int
{
    $waiting = 0;
    for ($t = 0; $t < 100 && $waiting < $n; $t++) {
        usleep(100000);
        $waiting = (int) $root->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
    }
    return $waiting;
}

function active_admins(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}

/* ---------------------------------------------------------------- */
section('قبل الترقية 004: النظام يعمل والسجل يُتجاهل بأمان');
$pdo = database_v1();
$owner = seed_user($pdo, 'owner', 'Owner-Pass-0001');
check('الترقية 004 معلقة', isset(pending_migrations($pdo)[4]));
check('صفحتا المستخدمين والمراقبة تعرفان أن الترقية مطلوبة', users_audit_ready($pdo) === false);
act_as($pdo, $owner);
check_eq('دور الحساب قبل الترقية = مدير', 'admin', current_role());
$wh0 = catalog_create($pdo, 'warehouse', 'مخزن قبل الترقية');
check('عملية حفظ تنجح رغم عدم وجود جدول السجل (1146 يُتجاهل)', $wh0 > 0 && catalog_find($pdo, 'warehouse', $wh0) !== null);
clearstatcache();
check('تُكتب ملاحظة في سجل الأخطاء', str_contains((string) file_get_contents($logFile, false, null, $logStart), 'audit_log table missing'));
$_SESSION['seen_at'] = 0;
user_touch_last_seen($pdo);
check('آخر ظهور قبل الترقية لا يسبب خطأ (1054 يُتجاهل)', true);
$err = attempt_login($pdo, 'owner', 'Owner-Pass-0001');
check('الدخول يعمل قبل الترقية', $err === null, (string) $err);
check_eq('  الدور في الجلسة: مدير', 'admin', $_SESSION['role'] ?? null);

section('تطبيق الترقية 004 (مع الإكمال بعد توقف في منتصفها)');
$stmts = split_sql((string) file_get_contents(APP_ROOT . '/migrations/004_users_audit.sql'));
check_eq('الترقية أمران: تعديل users وإنشاء audit_log', 2, count($stmts));
// الترقيات الأقدم من 004 (مثل 002 و 003) تُطبق أولًا كما يفعل زر التحديث، ثم يُحاكى توقف 004 في منتصفها
foreach (pending_migrations($pdo) as $ver => $file) {
    if ($ver < 4) {
        foreach (split_sql((string) file_get_contents($file)) as $stmt) {
            $pdo->exec($stmt);
        }
        save_setting($pdo, 'schema_version', (string) $ver);
    }
}
$pdo->exec($stmts[0]);
save_setting($pdo, 'schema_progress', '4:1');
$applied = run_migrations($pdo);
check_eq('الترقية أكملت من الأمر الثاني (ثم الترقيات الأحدث)', 4, $applied[0] ?? null);
check_eq('إصدار القاعدة = آخر ترقية', max(array_keys(migration_files())), schema_version($pdo));
$o = session_user_row($pdo, $owner);
check('الحساب الموجود أصبح مديرًا نشطًا', $o['role'] === 'admin' && (int) $o['is_active'] === 1, json_encode($o));
check('الاسم المعروض فارغ ويُعرض اسم الدخول بدلًا منه', $o['display_name'] === '' && user_display_name($o) === 'owner');
check('created_at موجود منذ 001 وآخر ظهور فارغ', $o['created_at'] !== null && $o['last_seen_at'] === null);
check('users_audit_ready بعد الترقية', users_audit_ready($pdo));
$idx = [];
foreach ($pdo->query('SHOW INDEX FROM audit_log')->fetchAll() as $ix) {
    $idx[$ix['Key_name']][(int) $ix['Seq_in_index']] = $ix['Column_name'];
}
check_eq('فهارس audit_log', [
    'PRIMARY' => [1 => 'id'],
    'idx_audit_log_action' => [1 => 'action', 2 => 'created_at'],
    'idx_audit_log_created' => [1 => 'created_at'],
    'idx_audit_log_entity' => [1 => 'entity', 2 => 'entity_id'],
    'idx_audit_log_user' => [1 => 'user_id', 2 => 'created_at'],
], (function () use ($idx) {
    ksort($idx);
    return $idx;
})());
$fk = $pdo->query("SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'")->fetchAll();
check('مفتاح أجنبي إلى users بدون حذف متتالٍ', count($fk) === 1 && $fk[0]['REFERENCED_TABLE_NAME'] === 'users' && $fk[0]['DELETE_RULE'] === 'RESTRICT', json_encode($fk));
$cols = array_column($pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll(), 'Type', 'Field');
check('أنواع الأعمدة', $cols['id'] === 'bigint(20) unsigned' && $cols['user_id'] === 'int(10) unsigned' && $cols['summary'] === 'varchar(500)'
    && $cols['details'] === 'longtext' && $cols['ip'] === 'varchar(45)' && $cols['user_agent'] === 'varchar(255)', json_encode($cols));
try {
    root_pdo()->exec("INSERT INTO audit_log (created_at, action, summary, details) VALUES (NOW(), 'x', 'x', 'not json')");
    check('فحص JSON_VALID يرفض تفاصيل غير صالحة', false);
} catch (PDOException $e) {
    check('فحص JSON_VALID يرفض تفاصيل غير صالحة', true);
}
$a0 = last_audit_id($pdo);
audit_migrations($pdo, $applied);
$row = expect_one_audit($pdo, 'تسجيل الترقية', $a0, 'db.migrate', $owner, 'schema', max($applied));
check_eq('  تفاصيل الترقية', ['applied' => $applied], details_of($row));

/* ---------------------------------------------------------------- */
section('تثبيت جديد: أول مدير بالقيم الافتراضية للأعمدة');
$pdo = fresh_database();
check_eq('كل الترقيات مطبقة (آخرها 4)', max(array_keys(migration_files())), schema_version($pdo));
$admin = seed_user($pdo, 'admin', 'Correct-Horse-9');
$a = session_user_row($pdo, $admin);
check('أول حساب يُنشأ مديرًا نشطًا (DEFAULT في الأعمدة كما في install.php)', $a['role'] === 'admin' && (int) $a['is_active'] === 1);
act_as($pdo, $admin);
$wh1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$wh2 = catalog_create($pdo, 'warehouse', 'مخزن الفرع');
$mosky = catalog_create($pdo, 'type', 'موسكي');

/* ---------------------------------------------------------------- */
section('إضافة المستخدمين');
$a0 = last_audit_id($pdo);
$staff = user_create($pdo, $admin, ['display_name' => '  أحمد   محمود ', 'username' => 'ahmed', 'role' => 'staff',
    'password' => 'Staff-Pass-001', 'password_confirm' => 'Staff-Pass-001']);
$s = user_find($pdo, $staff);
check('موظف جديد بدور موظف ونشط', $s['role'] === 'staff' && (int) $s['is_active'] === 1);
check_eq('  الاسم المعروض بعد التنظيف', 'أحمد محمود', $s['display_name']);
check('  كلمة المرور محفوظة كتجزئة', password_verify('Staff-Pass-001', $s['password_hash']));
$row = expect_one_audit($pdo, 'إضافة مستخدم', $a0, 'user.create', $admin, 'user', $staff);
check_eq('  اسم المنفذ لقطة في السجل', 'admin', $row['username']);
check_eq('  التفاصيل', ['username' => 'ahmed', 'display_name' => 'أحمد محمود', 'role' => 'staff'], details_of($row));
check('  لا كلمة مرور ولا تجزئة في السجل', !preg_match('/Staff-Pass|\$2y\$|password/i', $row['summary'] . $row['details']));
check_eq('  عنوان IP', '203.0.113.7', $row['ip']);
check('  وصف المتصفح', str_starts_with($row['user_agent'], 'Mozilla/5.0 (Windows NT 10.0'));
$n = audit_count($pdo);
$errors = expect_validation('اسم مستخدم مكرر (باختلاف حالة الأحرف) مرفوض', fn () => user_create($pdo, $admin,
    ['display_name' => 'آخر', 'username' => 'AHMED', 'role' => 'staff', 'password' => 'Other-Pass-001', 'password_confirm' => 'Other-Pass-001']));
check('  رسالة عربية واضحة', str_contains($errors['username'] ?? '', 'مستخدم بالفعل'), $errors['username'] ?? '');
$errors = expect_validation('مدخلات غير صالحة كلها مرفوضة معًا', fn () => user_create($pdo, $admin,
    ['display_name' => '', 'username' => 'a b', 'role' => 'root', 'password' => 'short', 'password_confirm' => 'short']));
check('  رسالة لكل حقل', isset($errors['display_name'], $errors['username'], $errors['role']), json_encode($errors, JSON_UNESCAPED_UNICODE));
$errors = expect_validation('كلمة مرور قصيرة مرفوضة', fn () => user_create($pdo, $admin,
    ['display_name' => 'سعيد', 'username' => 'saeed', 'role' => 'staff', 'password' => 'short', 'password_confirm' => 'short']));
check('  رسالة الطول', str_contains($errors['password'] ?? '', '10 أحرف'));
expect_validation('تأكيد كلمة مرور مختلف مرفوض', fn () => user_create($pdo, $admin,
    ['display_name' => 'سعيد', 'username' => 'saeed', 'role' => 'staff', 'password' => 'Saeed-Pass-001', 'password_confirm' => 'Saeed-Pass-002']));
check_eq('لا أسطر سجل للمحاولات المرفوضة', $n, audit_count($pdo));
$admin2 = user_create($pdo, $admin, ['display_name' => 'منى', 'username' => 'mona', 'role' => 'admin',
    'password' => 'Mona-Pass-0001', 'password_confirm' => 'Mona-Pass-0001']);
check_eq('مدير ثانٍ', 'admin', user_find($pdo, $admin2)['role']);
$errors = expect_validation('موظف لا يستطيع إضافة مستخدم (فحص داخل المعاملة)', fn () => user_create($pdo, $staff,
    ['display_name' => 'سعيد', 'username' => 'saeed', 'role' => 'admin', 'password' => 'Saeed-Pass-001', 'password_confirm' => 'Saeed-Pass-001']));
check('  رسالة الصلاحية', str_contains($errors['user'] ?? '', 'صلاحية'));
check('  لم يُنشأ الحساب', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'saeed'")->fetchColumn() === 0);

section('تعديل الاسم والدور');
$a0 = last_audit_id($pdo);
$changes = user_update($pdo, $admin, $staff, ['display_name' => 'أحمد علي', 'role' => 'staff']);
check_eq('تغيير الاسم فقط', ['display_name' => ['old' => 'أحمد محمود', 'new' => 'أحمد علي']], $changes);
$row = expect_one_audit($pdo, 'تعديل الاسم', $a0, 'user.update', $admin, 'user', $staff);
check('  الملخص يذكر القديم والجديد', str_contains($row['summary'], '«أحمد محمود»') && str_contains($row['summary'], '«أحمد علي»'), $row['summary']);
$a0 = last_audit_id($pdo);
user_update($pdo, $admin, $staff, ['display_name' => 'أحمد علي', 'role' => 'admin']);
$row = expect_one_audit($pdo, 'تغيير الدور', $a0, 'user.role', $admin, 'user', $staff);
check_eq('  تفاصيل الدور', ['role' => ['old' => 'staff', 'new' => 'admin']], details_of($row));
check('  الملخص بالعربية', str_contains($row['summary'], 'من موظف إلى مدير'), $row['summary']);
user_update($pdo, $admin, $staff, ['display_name' => 'أحمد علي', 'role' => 'staff']);
$a0 = last_audit_id($pdo);
check_eq('حفظ بلا تغيير لا يعدل شيئًا', [], user_update($pdo, $admin, $staff, ['display_name' => 'أحمد علي', 'role' => 'staff']));
check_eq('  ولا يكتب سطرًا في السجل', $a0, last_audit_id($pdo));
$errors = expect_validation('لا يمكن للمدير تخفيض نفسه', fn () => user_update($pdo, $admin, $admin, ['display_name' => 'المدير', 'role' => 'staff']));
check('  رسالة واضحة', str_contains($errors['role'] ?? '', 'دورك'), $errors['role'] ?? '');
check_eq('  الدور لم يتغير', 'admin', user_find($pdo, $admin)['role']);
$errors = expect_validation('مستخدم غير موجود', fn () => user_update($pdo, $admin, 999999, ['display_name' => 'س', 'role' => 'staff']));
check('  رسالة', str_contains($errors['user'] ?? '', 'غير موجود'));
user_update($pdo, $admin, $admin, ['display_name' => 'صاحب الشركة', 'role' => 'admin']);
check_eq('المدير يعدل اسمه المعروض', 'صاحب الشركة', user_find($pdo, $admin)['display_name']);
$rows = [];
foreach ($pdo->query('SELECT * FROM users ORDER BY id') as $u) {
    $rows[(int) $u['id']] = $u;
}
check_eq('عد المديرين النشطين الآخرين (أساس قاعدة آخر مدير)', 1, users_other_active_admins($rows, $admin));

section('التعطيل والتفعيل وتعيين كلمة المرور');
$errors = expect_validation('لا يمكن للمدير تعطيل نفسه', fn () => user_set_active($pdo, $admin, $admin, false));
check('  رسالة واضحة', str_contains($errors['user'] ?? '', 'حسابك'));
$v0 = (int) user_find($pdo, $staff)['auth_version'];
$a0 = last_audit_id($pdo);
check('تعطيل الموظف', user_set_active($pdo, $admin, $staff, false) === true);
$s = user_find($pdo, $staff);
check('  الحساب معطل وانتهت جلساته (auth_version زاد)', (int) $s['is_active'] === 0 && (int) $s['auth_version'] === $v0 + 1);
expect_one_audit($pdo, 'تعطيل', $a0, 'user.disable', $admin, 'user', $staff);
$a0 = last_audit_id($pdo);
check('تعطيل حساب معطل بالفعل لا يفعل شيئًا', user_set_active($pdo, $admin, $staff, false) === false && last_audit_id($pdo) === $a0);
check('تفعيل الموظف', user_set_active($pdo, $admin, $staff, true) === true && (int) user_find($pdo, $staff)['is_active'] === 1);
expect_one_audit($pdo, 'تفعيل', $a0, 'user.enable', $admin, 'user', $staff);
$errors = expect_validation('موظف لا يستطيع تعطيل مدير', fn () => user_set_active($pdo, $staff, $admin2, false));
check('  رسالة الصلاحية', str_contains($errors['user'] ?? '', 'صلاحية'));
$pdo->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')->execute(['198.51.100.1', 'ahmed', now()]);
$v0 = (int) user_find($pdo, $staff)['auth_version'];
$a0 = last_audit_id($pdo);
user_reset_password($pdo, $admin, $staff, 'New-Staff-Pass-2', 'New-Staff-Pass-2');
$s = user_find($pdo, $staff);
check('تعيين كلمة مرور جديدة للموظف', password_verify('New-Staff-Pass-2', $s['password_hash']) && (int) $s['auth_version'] === $v0 + 1);
check('  محاولات دخوله الفاشلة مُسحت', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'ahmed'")->fetchColumn() === 0);
$row = expect_one_audit($pdo, 'تعيين كلمة مرور', $a0, 'user.password_reset', $admin, 'user', $staff);
check('  لا تجزئة ولا كلمة مرور في السجل', $row['details'] === null && !str_contains($row['summary'], 'New-Staff'));
$errors = expect_validation('المدير لا يعيد تعيين كلمة مروره من إدارة المستخدمين', fn () => user_reset_password($pdo, $admin, $admin, 'Another-Pass-9', 'Another-Pass-9'));
check('  يُوجَّه إلى «حسابي»', str_contains($errors['password'] ?? '', 'حسابي'));
expect_validation('كلمة مرور ضعيفة في التعيين مرفوضة', fn () => user_reset_password($pdo, $admin, $staff, 'ahmed', 'ahmed'));

/* ---------------------------------------------------------------- */
section('آخر مدير نشط: مديران يخفّض أو يعطل كل منهما الآخر في نفس اللحظة');
foreach (['demote' => 'user.role', 'disable' => 'user.disable'] as $op => $action) {
    for ($round = 1; $round <= 2; $round++) {
        $pdo->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id IN (?, ?)")->execute([$admin, $admin2]);
        $pdo->prepare("UPDATE users SET role = 'staff' WHERE id NOT IN (?, ?)")->execute([$admin, $admin2]);
        check_eq("{$op} {$round}: مديران نشطان قبل البدء", 2, active_admins($pdo));
        $a0 = last_audit_id($pdo);
        $root = root_pdo();
        $root->beginTransaction();
        $root->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $procs = [];
        $pipes = [];
        foreach ([[$admin, $admin2], [$admin2, $admin]] as $k => [$actor, $target]) {
            $procs[$k] = proc_open([PHP_BINARY, __DIR__ . '/worker_users.php', $op, (string) $actor, (string) $target], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
        }
        $waiting = wait_lock_waits($root, 2);
        check("{$op} {$round}: العمليتان تنتظران نفس القفل فعلًا", $waiting === 2, "waiting={$waiting}");
        // الانتظار على قراءة القفل التي تسبق كل الفحوص (وليس على UPDATE بعد فحص بيانات قديمة)
        $queries = $root->query("SELECT trx_query FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchAll(PDO::FETCH_COLUMN);
        check("{$op} {$round}: الانتظار على قفل كل صفوف المستخدمين قبل الفحص", count($queries) === 2
            && !array_filter($queries, fn ($q) => !str_contains((string) $q, 'FROM users ORDER BY id FOR UPDATE')), json_encode($queries));
        $root->commit();
        $results = [];
        foreach ($procs as $k => $p) {
            $results[] = json_decode(trim((string) stream_get_contents($pipes[$k][1])), true);
            proc_close($p);
        }
        $ok = array_values(array_filter($results, fn ($r) => $r['ok'] ?? false));
        $failed = array_values(array_filter($results, fn ($r) => !($r['ok'] ?? false)));
        check_eq("{$op} {$round}: نجحت عملية واحدة فقط", 1, count($ok));
        check_eq("{$op} {$round}: بقي مدير نشط واحد", 1, active_admins($pdo));
        check("{$op} {$round}: الأخرى رُفضت بقاعدة آخر مدير", str_contains(implode(' ', $failed[0]['errors'] ?? []), 'مدير واحد نشط'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $new = audit_after($pdo, $a0);
        check("{$op} {$round}: سطر سجل واحد للعملية الناجحة فقط", count($new) === 1 && $new[0]['action'] === $action, json_encode(array_column($new, 'action')));
    }
}
$pdo->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id IN (?, ?)")->execute([$admin, $admin2]);
$pdo->prepare("UPDATE users SET role = 'staff', is_active = 1 WHERE id = ?")->execute([$staff]);

/* ---------------------------------------------------------------- */
section('الدخول: الحساب المعطل يأخذ نفس رسالة كلمة المرور الخاطئة ويُحسب في الحد');
$pdo->exec('DELETE FROM login_attempts');
act_as_nobody();
check('دخول الموظف النشط', attempt_login($pdo, 'ahmed', 'New-Staff-Pass-2') === null);
check_eq('  الدور في الجلسة: موظف', 'staff', $_SESSION['role'] ?? null);
check_eq('  الاسم المعروض في الجلسة', 'أحمد علي', $_SESSION['display_name'] ?? null);
act_as($pdo, $admin);
user_set_active($pdo, $admin, $staff, false);
act_as_nobody();
$generic = attempt_login($pdo, 'nobody-here', 'Whatever-Pass-1');
$msgs = [];
for ($i = 0; $i < 6; $i++) {
    $msgs[] = attempt_login($pdo, 'ahmed', 'New-Staff-Pass-2');
}
check_eq('كلمة المرور الصحيحة لحساب معطل: نفس الرسالة العامة', $generic, $msgs[0]);
check_eq('  الرسالة العامة', 'اسم المستخدم أو كلمة المرور غير صحيحة.', $generic);
check('  لم تُنشأ جلسة', empty($_SESSION['user_id']));
check('  المحاولات محسوبة: الحظر بعد 5 محاولات', $msgs[4] === $generic && str_contains((string) $msgs[5], 'محاولات دخول كثيرة'), json_encode($msgs, JSON_UNESCAPED_UNICODE));
check_eq('  المحاولات لم تُمسح رغم صحة كلمة المرور', 6, (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'ahmed'")->fetchColumn());
$pdo->exec('DELETE FROM login_attempts');
act_as($pdo, $admin);
user_set_active($pdo, $admin, $staff, true);

/* ---------------------------------------------------------------- */
section('الصلاحيات');
act_as($pdo, $staff);
$staffCan = [];
foreach (array_keys(PERMISSIONS) as $p) {
    $staffCan[$p] = can($p);
}
$expectedStaff = [];
foreach (PERMISSIONS as $p => $roles) {
    $expectedStaff[$p] = in_array('staff', $roles, true);
}
check_eq('صلاحيات الموظف تطابق خريطة الصلاحيات', $expectedStaff, $staffCan);
$staffBasic = [
    'stock.view' => true, 'documents.create' => true, 'documents.view' => true, 'documents.cancel' => false,
    'catalog.view' => true, 'catalog.manage' => false, 'account.manage' => true, 'settings.manage' => false,
    'migrate.run' => false, 'users.manage' => false, 'monitor.view' => false, 'admin.only' => false,
    'vouchers.collect' => true, 'vouchers.pay' => false, 'vouchers.cancel' => false, 'reports.profit' => false, 'manual_date' => false,
];
ksort($staffBasic);
$staffActual = array_intersect_key($staffCan, array_flip(['stock.view', 'documents.create', 'documents.view', 'documents.cancel', 'catalog.view',
    'catalog.manage', 'account.manage', 'settings.manage', 'migrate.run', 'users.manage', 'monitor.view', 'admin.only',
    'vouchers.collect', 'vouchers.pay', 'vouchers.cancel', 'reports.profit', 'manual_date']));
ksort($staffActual);
check_eq('صلاحيات الموظف الأساسية', $staffBasic, $staffActual);
$open = [];
foreach (['inventory', 'receive', 'sell', 'transfer', 'documents', 'document', 'print', 'types', 'warehouses', 'account', 'api', 'settings', 'users', 'monitor', 'not_registered_route'] as $r) {
    $open[$r] = can_open($r);
}
check_eq('الصفحات المسموحة للموظف (والصفحة غير المسجلة للمدير فقط)', ['inventory' => true, 'receive' => true, 'sell' => true, 'transfer' => true,
    'documents' => true, 'document' => true, 'print' => true, 'types' => true, 'warehouses' => true, 'account' => true, 'api' => true,
    'settings' => false, 'users' => false, 'monitor' => false, 'not_registered_route' => false], $open);
act_as($pdo, $admin);
check('المدير يملك كل الصلاحيات', !in_array(false, array_map('can', array_keys(PERMISSIONS)), true));
check('المدير يفتح الصفحة غير المسجلة', can_open('not_registered_route'));
act_as_nobody();
check('بدون دخول لا صلاحية', !can('stock.view'));

/* ---------------------------------------------------------------- */
section('آخر ظهور والمتصلون الآن');
act_as($pdo, $admin);
$_SESSION['seen_at'] = 0;
unset($_SERVER['HTTP_X_LIVE']);
user_touch_last_seen($pdo);
$seen = fn () => (string) user_find($pdo, $admin)['last_seen_at'];
check('طلب عادي يسجل آخر ظهور', abs(strtotime($seen()) - time()) <= 2, $seen());
$pdo->prepare("UPDATE users SET last_seen_at = '2000-01-01 00:00:00' WHERE id = ?")->execute([$admin]);
user_touch_last_seen($pdo);
check_eq('خلال الدقيقة نفسها لا تحديث (مرة كل 60 ثانية)', '2000-01-01 00:00:00', $seen());
$_SESSION['seen_at'] = 0;
$_SERVER['HTTP_X_LIVE'] = '1';
user_touch_last_seen($pdo);
check_eq('طلبات التحديث التلقائي لا تسجل ظهورًا أبدًا', '2000-01-01 00:00:00', $seen());
unset($_SERVER['HTTP_X_LIVE']);
user_touch_last_seen($pdo);
check('بعد الدقيقة يُسجل مرة أخرى', abs(strtotime($seen()) - time()) <= 2);
$pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 600), $staff]);
$online = array_map(fn ($u) => (int) $u['id'], users_online($pdo));
check('المتصلون الآن: المدير فقط (الموظف ظهر قبل 10 دقائق)', $online === [$admin], json_encode($online));
check('شارة «متصل الآن»', user_is_online(user_find($pdo, $admin)) && !user_is_online(user_find($pdo, $staff)));

/* ---------------------------------------------------------------- */
section('كل عملية تكتب سطرها: المستخدم والعملية والكيان والتفاصيل والعنوان والجهاز');
act_as($pdo, $staff);
$_SERVER['REMOTE_ADDR'] = '2001:db8:0:0:0:0:0:1';
$_SERVER['HTTP_USER_AGENT'] = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1\x01";
$a0 = last_audit_id($pdo);
$r = record_receipt($pdo, $staff, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '10', ['party_name' => 'مورد الشرق']));
$row = expect_one_audit($pdo, 'وارد', $a0, 'doc.receipt', $staff, 'document', $r['id']);
check_eq('  اسم الموظف لقطة', 'ahmed', $row['username']);
check_eq('  عنوان IPv6 كاملًا وموحدًا (ليس /64)', '2001:db8::1', $row['ip']);
check('  وصف الجهاز منظف من رموز التحكم', !preg_match('/\p{C}/u', $row['user_agent']) && str_contains($row['user_agent'], 'iPhone'));
check_eq('  الجهاز بالعربية', 'سفاري على آيفون', audit_device_label($row['user_agent']));
check_eq('  الملخص', 'وارد رقم ١ إلى المخزن الرئيسي: موسكي، عرض ١٠ سم × تخانة ٥٠ مللي × طول ٣ متر، ١٠ قطعة، المورد: مورد الشرق', $row['summary']);
$d = details_of($row);
check('  التفاصيل', $d['quantity'] === 10 && $d['warehouse'] === 'المخزن الرئيسي' && $d['volume_m3'] === '0.15' && $d['supplier'] === 'مورد الشرق', json_encode($d, JSON_UNESCAPED_UNICODE));
$item = item_id_for($pdo, $mosky, 100000, 50000, 3000000);

$a0 = last_audit_id($pdo);
$sale = record_sale($pdo, $staff, sale_input($wh1, [[$item, 4, '20000']], ['party_name' => '<script>alert(1)</script> & شركاه']));
$row = expect_one_audit($pdo, 'فاتورة بيع', $a0, 'doc.sale', $staff, 'document', $sale['id']);
check_eq('  الملخص (نص عادي يُهرَّب عند العرض)', "فاتورة بيع رقم ١ من المخزن الرئيسي: صنف واحد بإجمالي ١\u{202F}٢٠٠٫٠٠ جنيه مصري، العميل: <script>alert(1)</script> & شركاه", $row['summary']);
$d = details_of($row);
check('  التفاصيل: الإجمالي والعملة والأسطر', $d['total_amount'] === '1200.00' && $d['currency'] === 'جنيه مصري' && count($d['lines']) === 1
    && $d['lines'][0]['quantity'] === 4 && $d['lines'][0]['amount'] === '1200.00', json_encode($d, JSON_UNESCAPED_UNICODE));

$a0 = last_audit_id($pdo);
$tr = record_transfer($pdo, $staff, transfer_input($wh1, $wh2, [[$item, 2]]));
$row = expect_one_audit($pdo, 'تحويل', $a0, 'doc.transfer', $staff, 'document', $tr['id']);
check_eq('  الملخص', 'تحويل رقم ١ من المخزن الرئيسي إلى مخزن الفرع: صنف واحد، ٢ قطعة', $row['summary']);

$a0 = last_audit_id($pdo);
$dup = record_sale($pdo, $staff, ($dupIn = sale_input($wh1, [[$item, 1, '100']])));
$again = record_sale($pdo, $staff, $dupIn);
check('إعادة إرسال نفس الطلب لا تكرر سطر السجل', $again['duplicate'] && count(audit_after($pdo, $a0)) === 1);

act_as($pdo, $admin);
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
$a0 = last_audit_id($pdo);
cancel_document($pdo, $admin, $sale['id'], '  خطأ   في السعر ');
$row = expect_one_audit($pdo, 'إلغاء', $a0, 'doc.cancel', $admin, 'document', $sale['id']);
check_eq('  الملخص مع السبب', 'إلغاء فاتورة بيع رقم ١، السبب: خطأ في السعر', $row['summary']);
check_eq('  السبب في التفاصيل', 'خطأ في السعر', details_of($row)['reason']);
check_eq('  الجهاز', 'كروم على أندرويد', audit_device_label($row['user_agent']));

$a0 = last_audit_id($pdo);
$zan = catalog_create($pdo, 'type', 'زان');
expect_one_audit($pdo, 'إضافة نوع', $a0, 'type.create', $admin, 'wood_type', $zan);
$a0 = last_audit_id($pdo);
catalog_rename($pdo, 'type', $zan, 'زان أحمر');
$row = expect_one_audit($pdo, 'تعديل اسم نوع', $a0, 'type.rename', $admin, 'wood_type', $zan);
check_eq('  القديم والجديد', ['name' => ['old' => 'زان', 'new' => 'زان أحمر']], details_of($row));
check_eq('  الملخص', 'تعديل اسم نوع خشب من «زان» إلى «زان أحمر»', $row['summary']);
$a0 = last_audit_id($pdo);
catalog_rename($pdo, 'type', $zan, 'زان أحمر');
check('إعادة حفظ نفس الاسم لا تكتب سطرًا', last_audit_id($pdo) === $a0);
catalog_delete($pdo, 'type', $zan);
expect_one_audit($pdo, 'حذف نوع', $a0, 'type.delete', $admin, 'wood_type', $zan);
$a0 = last_audit_id($pdo);
$wh3 = catalog_create($pdo, 'warehouse', 'مخزن مؤقت');
catalog_rename($pdo, 'warehouse', $wh3, 'مخزن مؤقت 2');
catalog_delete($pdo, 'warehouse', $wh3);
check_eq('المخازن: إضافة وتعديل وحذف', ['warehouse.create', 'warehouse.rename', 'warehouse.delete'], array_column(audit_after($pdo, $a0), 'action'));

section('الإعدادات: المفاتيح التي تغيرت فقط، ولا أسرار');
$current = [];
foreach (array_keys(AUDIT_SETTING_LABELS) as $k) {
    $current[$k] = app_setting($k);
}
$hashBefore = (string) $pdo->query("SELECT value FROM settings WHERE name = 'dummy_hash'")->fetchColumn();
$a0 = last_audit_id($pdo);
$changed = settings_save_audited($pdo, ['currency' => 'دولار أمريكي', 'dummy_hash' => '$2y$10$secretsecretsecretsecretsecretsecretsecretsecret12'] + $current);
reset_settings_cache();
check_eq('المفاتيح المتغيرة المسجلة: العملة فقط', ['currency' => ['old' => 'جنيه مصري', 'new' => 'دولار أمريكي']], $changed);
$row = expect_one_audit($pdo, 'تعديل الإعدادات', $a0, 'settings.update', $admin, 'settings', null);
check_eq('  التفاصيل: العملة فقط', ['currency' => ['old' => 'جنيه مصري', 'new' => 'دولار أمريكي']], details_of($row));
check_eq('  الملخص', 'تعديل الإعدادات: العملة من «جنيه مصري» إلى «دولار أمريكي»', $row['summary']);
check('  لا قيمة سرية في السجل', !str_contains((string) $row['details'] . $row['summary'], 'secret') && !str_contains((string) $row['details'], 'dummy_hash'));
$pdo->prepare("UPDATE settings SET value = ? WHERE name = 'dummy_hash'")->execute([$hashBefore]);
$a0 = last_audit_id($pdo);
check_eq('حفظ بلا تغيير', [], settings_save_audited($pdo, ['currency' => 'دولار أمريكي'] + $current));
check_eq('  لا سطر في السجل', $a0, last_audit_id($pdo));
settings_save_audited($pdo, ['digits' => 'western', 'currency' => 'جنيه مصري', 'volume_pad' => '1'] + $current);
reset_settings_cache();
$row = audit_after($pdo, $a0)[0] ?? ['summary' => ''];
check_eq('  ملخص قيم مفهومة بالعربية', 'تعديل الإعدادات: العملة من «دولار أمريكي» إلى «جنيه مصري»، شكل الأرقام من «عربية» إلى «إنجليزية»، إظهار الأصفار في الحجم من «لا» إلى «نعم»', $row['summary']);
$a0 = last_audit_id($pdo);
$rw = record_receipt($pdo, $admin, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '12'));
check('الأرقام في الملخص تتبع الإعداد (إنجليزية)', str_contains(audit_after($pdo, $a0)[0]['summary'] ?? '', 'وارد رقم 2 '), audit_after($pdo, $a0)[0]['summary'] ?? '');
settings_save_audited($pdo, $current);
reset_settings_cache();

section('تغيير كلمة المرور من «حسابي»');
$v0 = (int) user_find($pdo, $admin)['auth_version'];
$a0 = last_audit_id($pdo);
change_password($pdo, $admin, 'Changed-Pass-55');
$u = user_find($pdo, $admin);
check('كلمة المرور تغيرت والجلسة تحمل الإصدار الجديد', password_verify('Changed-Pass-55', $u['password_hash']) && (int) $u['auth_version'] === $v0 + 1
    && $_SESSION['auth_version'] === $v0 + 1);
$row = expect_one_audit($pdo, 'تغيير كلمة المرور', $a0, 'account.password', $admin, 'user', $admin);
check('  لا تجزئة في السجل', $row['details'] === null && !str_contains($row['summary'], '$2y$'));

/* ---------------------------------------------------------------- */
section('عملية فاشلة لا تترك سطرًا في السجل');
$n = audit_count($pdo);
expect_validation('بيع أكبر من الرصيد', fn () => record_sale($pdo, $admin, sale_input($wh1, [[$item, 99999, '100']])));
check_eq('  لا سطر جديد', $n, audit_count($pdo));
$root = root_pdo();
$root->exec('DROP TRIGGER IF EXISTS t_fail_version');
$root->exec("CREATE TRIGGER t_fail_version BEFORE UPDATE ON counters FOR EACH ROW BEGIN
    IF NEW.name = 'data_version' AND @wood_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF; END");
$snapshot = fn () => $pdo->query("SELECT (SELECT COUNT(*) FROM audit_log), (SELECT COUNT(*) FROM documents), (SELECT COALESCE(SUM(qty_on_hand),0) FROM stock),
    (SELECT COUNT(*) FROM users), (SELECT GROUP_CONCAT(name, '=', value ORDER BY name) FROM settings), (SELECT COUNT(*) FROM wood_types),
    (SELECT GROUP_CONCAT(id, ':', role, ':', is_active, ':', auth_version ORDER BY id) FROM users)")->fetch(PDO::FETCH_NUM);
$open = record_sale($pdo, $admin, sale_input($wh1, [[$item, 1, '100']])); // فاتورة سارية لاختبار الإلغاء الفاشل
$before = $snapshot();
$pdo->exec('SET @wood_fail = 1');
foreach ([
    'وارد' => fn () => record_receipt($pdo, $admin, receipt_input($wh1, $mosky, '7', 'cm', '7', 'cm', '7', 'm', '7')),
    'فاتورة بيع' => fn () => record_sale($pdo, $admin, sale_input($wh1, [[$item, 1, '100']])),
    'تحويل' => fn () => record_transfer($pdo, $admin, transfer_input($wh1, $wh2, [[$item, 1]])),
    'إلغاء' => fn () => cancel_document($pdo, $admin, $open['id'], 'x'),
    'إضافة نوع' => fn () => catalog_create($pdo, 'type', 'نوع فاشل'),
    'إضافة مستخدم' => fn () => user_create($pdo, $admin, ['display_name' => 'فاشل', 'username' => 'failing', 'role' => 'staff', 'password' => 'Failing-Pass-1', 'password_confirm' => 'Failing-Pass-1']),
    'تخفيض مستخدم' => fn () => user_update($pdo, $admin, $admin2, ['display_name' => 'منى', 'role' => 'staff']),
    'تعطيل مستخدم' => fn () => user_set_active($pdo, $admin, $staff, false),
    'تعيين كلمة مرور' => fn () => user_reset_password($pdo, $admin, $staff, 'Failing-Pass-2', 'Failing-Pass-2'),
    'حفظ الإعدادات' => fn () => settings_save_audited($pdo, ['company_name' => 'اسم فاشل'] + $current),
    'تغيير كلمة المرور' => fn () => change_password($pdo, $admin, 'Failing-Pass-3'),
] as $label => $fn) {
    $threw = false;
    try {
        $fn();
    } catch (PDOException $e) {
        $threw = str_contains($e->getMessage(), 'injected failure');
    }
    check("فشل مفتعل بعد كتابة سطر السجل: {$label}", $threw);
    check_eq("  لا عملية ولا سطر سجل بعد: {$label}", $before, $snapshot());
}
$pdo->exec('SET @wood_fail = 0');
$root->exec('DROP TRIGGER t_fail_version');
check('كلمة مرور المدير لم تتغير بعد الفشل', password_verify('Changed-Pass-55', user_find($pdo, $admin)['password_hash']));

section('audit_record: يتحمل غياب الجدول (1146) فقط، وأي خطأ آخر يُلغي العملية');
$root->exec('RENAME TABLE audit_log TO audit_log_hidden');
clearstatcache();
$logPos = filesize($logFile);
try {
    $rr = record_receipt($pdo, $admin, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '1'));
    check('الوارد حُفظ رغم غياب جدول السجل', $rr['id'] > 0 && find_document($pdo, $rr['id']) !== null);
    audit_record($pdo, 'test.direct', 'استدعاء مباشر');
    check('الاستدعاء المباشر بدون معاملة لا يرمي استثناء', true);
} catch (Throwable $e) {
    check('غياب جدول السجل لا يوقف العمل', false, $e->getMessage());
} finally {
    $root->exec('RENAME TABLE audit_log_hidden TO audit_log');
}
clearstatcache();
check('  وكُتبت ملاحظة في سجل الأخطاء', str_contains((string) file_get_contents($logFile, false, null, $logPos), 'audit_log table missing'));
$root->exec("CREATE TRIGGER t_fail_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN
    IF @audit_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'; END IF; END");
$pdo->exec('SET @audit_fail = 1');
$docsBefore = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$qtyBefore = stock_qty($pdo, $item, $wh1);
$vBefore = data_version($pdo);
$threw = false;
try {
    record_sale($pdo, $admin, sale_input($wh1, [[$item, 1, '100']]));
} catch (PDOException $e) {
    $threw = str_contains($e->getMessage(), 'audit failure');
}
$pdo->exec('SET @audit_fail = 0');
$root->exec('DROP TRIGGER t_fail_audit');
check('خطأ آخر في السجل يرفع استثناء', $threw);
check('  ويُلغي البيع كله (لا مستند ولا خصم ولا رفع للإصدار)', $docsBefore === (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn()
    && $qtyBefore === stock_qty($pdo, $item, $wh1) && $vBefore === data_version($pdo));

act_as_nobody();
$a0 = last_audit_id($pdo);
catalog_create($pdo, 'type', 'نوع من سطر الأوامر');
$row = audit_after($pdo, $a0)[0] ?? [];
check('بدون جلسة: العملية تُنسب إلى «النظام»', array_key_exists('user_id', $row) && $row['user_id'] === null && $row['username'] === ''
    && audit_actor_name($row + ['user_display_name' => null]) === 'النظام', json_encode($row, JSON_UNESCAPED_UNICODE));
act_as($pdo, $admin);

/* ---------------------------------------------------------------- */
section('صفحة المراقبة: التصفية والبحث والصفحات');
$ids = array_map(fn ($u) => (int) $u['id'], users_all($pdo));
$f = audit_filters(['user' => 'abc', 'group' => 'hack', 'from' => '2026-13-45', 'to' => '2026-02-30', 'q' => str_repeat('س', 101), 'doc' => '12a'], $ids);
check_eq('قيم غير صالحة تُتجاهل وتُذكر', ['المستخدم', 'نوع العملية', 'التاريخ (استخدم الصيغة 2026-01-31)', 'نص البحث (100 حرف على الأكثر)', 'رقم المستند'], $f['ignored']);
check('  ولا تُطبق', $f['user'] === '' && $f['group'] === '' && $f['from'] === null && $f['to'] === null && $f['q'] === '' && $f['doc'] === 0);
check('مستخدم غير موجود يُتجاهل', audit_filters(['user' => '999999'], $ids)['user'] === '');
$all = audit_search($pdo, audit_filters([], $ids), 1, 1000);
check('بدون تصفية: كل الأسطر، الأحدث أولًا', $all['total'] === audit_count($pdo) && (int) $all['rows'][0]['id'] === last_audit_id($pdo));
$byStaff = audit_search($pdo, audit_filters(['user' => (string) $staff], $ids), 1, 1000);
check('تصفية بالمستخدم', $byStaff['total'] >= 4 && !array_filter($byStaff['rows'], fn ($r) => (int) $r['user_id'] !== $staff));
check_eq('  اسم المنفذ المعروض حاليًا', 'أحمد علي', audit_actor_name($byStaff['rows'][0]));
$sys = audit_search($pdo, audit_filters(['user' => 'system'], $ids), 1, 1000);
check('تصفية «النظام»', $sys['total'] >= 1 && !array_filter($sys['rows'], fn ($r) => $r['user_id'] !== null));
$cancel = audit_search($pdo, audit_filters(['group' => 'cancel'], $ids), 1, 1000);
check('مجموعة الإلغاء', $cancel['total'] === 1 && $cancel['rows'][0]['action'] === 'doc.cancel');
$usersGroup = audit_search($pdo, audit_filters(['group' => 'users'], $ids), 1, 1000);
check('مجموعة المستخدمين تشمل تغيير كلمة المرور', in_array('account.password', array_column($usersGroup['rows'], 'action'), true)
    && !array_diff(array_column($usersGroup['rows'], 'action'), audit_group_actions('users')));
check('مجموعة البيانات الأساسية', !array_diff(array_column(audit_search($pdo, audit_filters(['group' => 'master'], $ids), 1, 1000)['rows'], 'action'), audit_group_actions('master')));
$q = audit_search($pdo, audit_filters(['q' => '<script>'], $ids), 1, 1000);
check('بحث نصي في التفاصيل', $q['total'] === 1 && $q['rows'][0]['action'] === 'doc.sale');
check_eq('% و _ تُعامل كحروف عادية', 0, audit_search($pdo, audit_filters(['q' => '100%'], $ids), 1, 10)['total']);
check_eq('_ لا تطابق أي حرف', 0, audit_search($pdo, audit_filters(['q' => 'وارد_رقم'], $ids), 1, 10)['total']);
$byDoc = audit_search($pdo, audit_filters(['doc' => '١'], $ids), 1, 1000);
check('رقم المستند (يقبل الأرقام العربية): يجد كل حركات المستندات رقم 1', $byDoc['total'] >= 4
    && !array_filter($byDoc['rows'], fn ($r) => $r['entity'] !== 'document'), (string) $byDoc['total']);
$today = date('Y-m-d');
check_eq('تاريخ اليوم يشمل الكل', audit_count($pdo), audit_search($pdo, audit_filters(['from' => $today, 'to' => $today], $ids), 1, 10)['total']);
check_eq('من الغد: لا شيء', 0, audit_search($pdo, audit_filters(['from' => date('Y-m-d', time() + 86400)], $ids), 1, 10)['total']);
check_eq('حتى الأمس: لا شيء', 0, audit_search($pdo, audit_filters(['to' => date('Y-m-d', time() - 86400)], $ids), 1, 10)['total']);
$p = audit_search($pdo, audit_filters([], $ids), 999, 5);
check('الصفحات: 5 في الصفحة، ورقم صفحة كبير يُقصر على آخر صفحة', $p['pages'] === (int) ceil(audit_count($pdo) / 5) && $p['page'] === $p['pages'] && count($p['rows']) >= 1);
check_eq('روابط الصفحات تحفظ التصفية', ['r' => 'monitor', 'user' => (string) $staff, 'group' => 'documents', 'from' => '2026-01-01', 'q' => 'زان', 'doc' => 7],
    audit_filter_query(audit_filters(['user' => (string) $staff, 'group' => 'documents', 'from' => '2026-01-01', 'q' => 'زان', 'doc' => '7', 'x' => 'y'], $ids)));

section('ملخص اليوم');
$m = monitor_summary($pdo);
check_eq('عمليات اليوم = كل الأسطر', audit_count($pdo), $m['operations']);
$salesToday = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE kind = 'sale' AND status = 'active'")->fetchColumn();
check_eq('فواتير البيع السارية اليوم', $salesToday, $m['sales_count']);
check('الإجمالي حسب العملة', count($m['sales']) === 1 && $m['sales'][0]['currency'] === 'جنيه مصري');
check_eq('الإلغاءات اليوم', 1, $m['cancellations']);
$root->exec('DROP TABLE IF EXISTS auth_events'); // الترقية 002 أنشأته؛ يُحذف لاختبار غيابه ثم يُنشأ بأحداث محددة
check('محاولات الدخول الفاشلة غير متاحة بدون جدول auth_events', monitor_summary($pdo)['failed_logins'] === null && monitor_auth_events($pdo) === null);
$root->exec("CREATE TABLE auth_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event ENUM('login_ok','login_fail','login_locked','logout','password_changed','password_reset','session_expired') NOT NULL,
    username VARCHAR(60) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '', user_agent VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL) ENGINE=InnoDB");
$ins = $root->prepare('INSERT INTO auth_events (event, username, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute(['login_fail', 'admin', '203.0.113.9', 'curl/8', date('Y-m-d H:i:s', time() - 3600)]);
$ins->execute(['login_locked', 'admin', '203.0.113.9', 'curl/8', date('Y-m-d H:i:s', time() - 1800)]);
$ins->execute(['login_fail', 'admin', '203.0.113.9', 'curl/8', date('Y-m-d H:i:s', time() - 90000)]);
$ins->execute(['login_ok', 'admin', '203.0.113.9', 'curl/8', date('Y-m-d H:i:s')]);
check_eq('محاولات فاشلة خلال 24 ساعة من auth_events', 2, monitor_summary($pdo)['failed_logins']);
$ev = monitor_auth_events($pdo, 50);
check('سجل الدخول: الأحدث أولًا', count($ev) === 4 && $ev[0]['event'] === 'login_ok');
check('كل أحداث الدخول لها اسم عربي', !array_diff(['login_ok', 'login_fail', 'login_locked', 'logout', 'password_changed', 'password_reset', 'session_expired'], array_keys(MONITOR_AUTH_EVENT_LABELS)));
$root->exec('DROP TABLE auth_events');

section('أسماء الأجهزة');
$devices = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0' => 'إيدج على ويندوز',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' => 'كروم على ويندوز',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36' => 'كروم على أندرويد',
    'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36' => 'سامسونج إنترنت على أندرويد',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' => 'سفاري على آيفون',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0 Mobile/15E148 Safari/604.1' => 'كروم على آيفون',
    'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' => 'سفاري على آيباد',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15' => 'سفاري على ماك',
    'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0' => 'فايرفوكس على لينكس',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0' => 'أوبرا على ويندوز',
    'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' => 'كروم على كروم أو إس',
    'Python-urllib/3.12' => 'Python-urllib/3.12',
    'Mozilla/5.0 (Windows NT 10.0)' => 'متصفح على ويندوز',
    '' => '',
];
$got = [];
foreach ($devices as $ua => $expected) {
    $got[$ua] = audit_device_label($ua);
}
check_eq('اسم قصير للمتصفح ونظام التشغيل', $devices, $got);
check('وصف طويل غير معروف يُقص', mb_strlen(audit_device_label(str_repeat('x', 300))) === 61);

/* ---------------------------------------------------------------- */
section('أسماء المستخدمين في المستندات');
$names = users_name_map($pdo);
check_eq('الاسم المعروض للموظف', 'أحمد علي', $names[$staff] ?? null);
check_eq('المستخدم بدون اسم معروض يظهر باسم الدخول', 'mona', (function () use ($pdo, $admin, $admin2) {
    $pdo->prepare("UPDATE users SET display_name = '' WHERE id = ?")->execute([$admin2]);
    return users_name_map($pdo)[$admin2] ?? null;
})());

section('لا تحذيرات PHP أثناء الاختبار');
clearstatcache();
$newLog = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $logStart) : '';
check('سجل الأخطاء خالٍ من التحذيرات والأخطاء', !preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $newLog), substr($newLog, 0, 300));

finish();
