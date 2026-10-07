<?php
defined('APP_ROOT') || exit;

/*
 * ترقيات قاعدة البيانات: ملفات app/migrations/NNN_name.sql تُطبق بالترتيب مرة واحدة،
 * ورقم آخر ترقية مطبقة يُحفظ في settings.schema_version.
 * تحديثات النظام لاحقًا (مثل الحسابات) تضيف ملفات جديدة دون المساس ببيانات العميل.
 */

/** @return array<int,string> رقم الترقية => مسار الملف */
function migration_files(): array
{
    $out = [];
    foreach (glob(APP_ROOT . '/migrations/*.sql') ?: [] as $file) {
        if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql\z/', basename($file), $m)) {
            $out[(int) $m[1]] = $file;
        }
    }
    ksort($out);
    return $out;
}

function schema_version(PDO $pdo): int
{
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn();
        return $v === false ? 0 : (int) $v;
    } catch (PDOException $e) {
        return 0;
    }
}

function pending_migrations(PDO $pdo): array
{
    $current = schema_version($pdo);
    return array_filter(migration_files(), fn ($v) => $v > $current, ARRAY_FILTER_USE_KEY);
}

/** يقسم ملف SQL إلى أوامر (الملفات مكتوبة بحيث ينتهي كل أمر بفاصلة منقوطة آخر السطر) */
function split_sql(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    $out = [];
    foreach (preg_split('/;\s*(?:\n|\z)/', $sql) ?: [] as $stmt) {
        if (trim($stmt) !== '') {
            $out[] = trim($stmt);
        }
    }
    return $out;
}

/** يطبق الترقيات المعلقة بالترتيب. @return int[] أرقام الترقيات المطبقة */
function run_migrations(PDO $pdo): array
{
    if ((int) $pdo->query("SELECT GET_LOCK('wood_migrate', 15)")->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire migration lock');
    }
    $applied = [];
    try {
        foreach (pending_migrations($pdo) as $version => $file) {
            foreach (split_sql((string) file_get_contents($file)) as $stmt) {
                $pdo->exec($stmt);
            }
            save_setting($pdo, 'schema_version', (string) $version);
            $applied[] = $version;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('wood_migrate')");
    }
    reset_settings_cache();
    return $applied;
}
