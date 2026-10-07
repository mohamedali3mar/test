<?php
defined('APP_ROOT') || exit;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = app_config()['db'] ?? [];
    if (!is_array($c) || empty($c['name']) || empty($c['user'])) {
        throw new AppConfigException('بيانات قاعدة البيانات غير مكتملة في ملف الإعدادات.');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? 'localhost',
        (int) ($c['port'] ?? 3306),
        $c['name']
    );
    $pdo = new PDO($dsn, (string) $c['user'], (string) ($c['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    // MariaDB 11.6.2+ يفعّل innodb_snapshot_isolation افتراضيًا، فيرفض تعديل صف تغير بعد بداية قراءة
    // المعاملة (خطأ 1020). النظام يعتمد على الأقفال الصريحة، فيُعطَّل لهذه الجلسة إن كان موجودًا.
    try {
        $pdo->exec('SET SESSION innodb_snapshot_isolation = OFF');
    } catch (PDOException $e) {
        // المتغير غير موجود في MySQL وإصدارات MariaDB الأقدم: لا شيء مطلوب
    }
    return $pdo;
}

/**
 * ينفذ الدالة داخل معاملة واحدة. إما أن تنجح كل التغييرات أو لا يُحفظ أي منها.
 * يعيد المحاولة تلقائيًا عند الجمود (deadlock) أو انتهاء مهلة انتظار القفل.
 */
function db_transaction(PDO $pdo, callable $fn)
{
    $attempts = 0;
    while (true) {
        $attempts++;
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null;
            // 1213 جمود، 1205 انتهاء مهلة القفل، 1020 تعارض لقطة القراءة (MariaDB 11.6+)
            if (in_array($code, [1213, 1205, 1020], true) && $attempts < 3) {
                usleep(50000 * $attempts);
                continue;
            }
            throw $e;
        }
    }
}

function is_duplicate_key(Throwable $e, ?string $keyName = null): bool
{
    if (!$e instanceof PDOException || ($e->errorInfo[1] ?? null) !== 1062) {
        return false;
    }
    return $keyName === null || str_contains((string) ($e->errorInfo[2] ?? ''), $keyName);
}

/** خطأ مفتاح أجنبي: سجل مرتبط حُذف أثناء العملية */
function is_fk_error(Throwable $e): bool
{
    return $e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1451, 1452], true);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * الرقم التالي في عداد (مثل أرقام فواتير البيع). يُستدعى داخل معاملة فقط:
 * القفل على صف العداد يبقى حتى نهاية المعاملة، فالتسلسل متتالٍ بلا فجوات حتى عند التراجع.
 */
function counter_next(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('UPDATE counters SET value = LAST_INSERT_ID(value + 1) WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Missing counter: ' . $name);
    }
    return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

/** يرفع رقم إصدار البيانات ليعرف كل متصفح مفتوح أن عليه التحديث. آخر خطوة قبل تأكيد المعاملة. */
function data_version_bump(PDO $pdo): void
{
    $pdo->exec("UPDATE counters SET value = value + 1 WHERE name = 'data_version'");
}

function data_version(PDO $pdo): string
{
    $v = $pdo->query("SELECT value FROM counters WHERE name = 'data_version'")->fetchColumn();
    return $v === false ? '0' : (string) $v;
}
