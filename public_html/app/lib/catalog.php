<?php
defined('APP_ROOT') || exit;

/*
 * البيانات الأساسية: أنواع الخشب والمخازن. نفس القواعد للاثنين:
 * أسماء بلا تكرار (حسب name_key)، وتعديل الاسم، وحذف فقط عند عدم الاستخدام.
 */

const CATALOGS = [
    'type' => [
        'table' => 'wood_types',
        'noun' => 'النوع',
        'exists' => 'هذا النوع موجود بالفعل.',
        'missing' => 'النوع غير موجود.',
    ],
    'warehouse' => [
        'table' => 'warehouses',
        'noun' => 'المخزن',
        'exists' => 'يوجد مخزن بنفس الاسم.',
        'missing' => 'المخزن غير موجود.',
    ],
];

function catalog_def(string $kind): array
{
    if (!isset(CATALOGS[$kind])) {
        throw new InvalidArgumentException('Unknown catalog ' . $kind);
    }
    return CATALOGS[$kind];
}

/** @return array{0:string,1:string} [الاسم المعروض, مفتاح المقارنة] */
function catalog_validate_name(string $kind, string $raw): array
{
    $noun = catalog_def($kind)['noun'];
    $name = clean_text($raw);
    if ($name === '') {
        throw new ValidationException(['name' => "أدخل اسم {$noun}."]);
    }
    $key = name_key($name);
    if (mb_strlen($name) > 100 || mb_strlen($key) > 100) {
        throw new ValidationException(['name' => "اسم {$noun} طويل جدًا (100 حرف على الأكثر)."]);
    }
    if ($key === '') {
        throw new ValidationException(['name' => "أدخل اسم {$noun}."]);
    }
    return [$name, $key];
}

function catalog_find(PDO $pdo, string $kind, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $table = catalog_def($kind)['table'];
    $stmt = $pdo->prepare("SELECT id, name FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function catalog_all(PDO $pdo, string $kind): array
{
    $table = catalog_def($kind)['table'];
    return $pdo->query("SELECT id, name FROM {$table} ORDER BY name, id")->fetchAll();
}

function catalog_create(PDO $pdo, string $kind, string $raw): int
{
    $def = catalog_def($kind);
    [$name, $key] = catalog_validate_name($kind, $raw);
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($kind, $def, $name, $key) {
            $pdo->prepare("INSERT INTO {$def['table']} (name, name_key, created_at) VALUES (?, ?, ?)")
                ->execute([$name, $key, now()]);
            $id = (int) $pdo->lastInsertId();
            audit_catalog($pdo, $kind, 'create', $id, $name);
            data_version_bump($pdo);
            return $id;
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => $def['exists']]);
        }
        throw $e;
    }
}

function catalog_rename(PDO $pdo, string $kind, int $id, string $raw): void
{
    $def = catalog_def($kind);
    [$name, $key] = catalog_validate_name($kind, $raw);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($kind, $def, $id, $name, $key) {
            $stmt = $pdo->prepare("SELECT id, name FROM {$def['table']} WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new ValidationException(['name' => $def['missing']]);
            }
            $pdo->prepare("UPDATE {$def['table']} SET name = ?, name_key = ? WHERE id = ?")->execute([$name, $key, $id]);
            if ($old['name'] !== $name) {
                audit_catalog($pdo, $kind, 'rename', $id, $name, $old['name']);
            }
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => $def['exists']]);
        }
        throw $e;
    }
}

/** الحذف مسموح فقط إذا لم يُستخدم الاسم في أي صنف أو رصيد أو مستند */
function catalog_delete(PDO $pdo, string $kind, int $id): void
{
    $def = catalog_def($kind);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($kind, $def, $id) {
            $stmt = $pdo->prepare("SELECT id, name FROM {$def['table']} WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new ValidationException(['name' => $def['missing']]);
            }
            if ($kind === 'type') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE wood_type_id = ?');
                $stmt->execute([$id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new ValidationException(['name' => 'لا يمكن حذف نوع سُجل له وارد. يمكنك تعديل اسمه.']);
                }
            } else {
                // قفل كل صفوف المخازن قبل العد: حذفان متزامنان لمخزنين لا يتركان النظام بلا مخازن
                $count = count($pdo->query('SELECT id FROM warehouses ORDER BY id FOR UPDATE')->fetchAll());
                if ($count <= 1) {
                    throw new ValidationException(['name' => 'يجب أن يبقى مخزن واحد على الأقل.']);
                }
                $stmt = $pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM stock WHERE warehouse_id = ?)
                          + (SELECT COUNT(*) FROM documents WHERE warehouse_id = ? OR to_warehouse_id = ?)'
                );
                $stmt->execute([$id, $id, $id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new ValidationException(['name' => 'لا يمكن حذف مخزن له أرصدة أو مستندات. يمكنك تعديل اسمه.']);
                }
            }
            $pdo->prepare("DELETE FROM {$def['table']} WHERE id = ?")->execute([$id]);
            audit_catalog($pdo, $kind, 'delete', $id, $row['name']);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_fk_error($e)) {
            throw new ValidationException(['name' => "لا يمكن حذف {$def['noun']} لأنه مستخدم."]);
        }
        throw $e;
    }
}
