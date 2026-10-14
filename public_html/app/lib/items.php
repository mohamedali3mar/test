<?php
defined('APP_ROOT') || exit;

/*
 * إدارة المقاسات (الأصناف) وإخفاء الأنواع (الترقية 009).
 *
 *  - المقاس = نوع الخشب + العرض + التخانة + الطول، ويُنشأ تلقائيًا مع أول وارد له. لا يُحذف ولا تُعدل أبعاده:
 *    المستندات السابقة (حتى الملغاة) تقرأ أبعادها منه، فتعديل البعد يغير أحجام وقيم فواتير مطبوعة.
 *  - الإخفاء بدل الحذف: مقاس رصيده صفر في كل المخازن يُخفى من المخزون والقوائم، ويظهر من جديد تلقائيًا
 *    مع أول وارد له (record_receipt). النوع يُخفى من نموذج الوارد إذا كانت أرصدة كل مقاساته صفرًا.
 *  - وحدات العرض (مللي أو سم أو متر لكل بعد) تُعدل بحرية: للعرض فقط، ولا تغير الهوية ولا الحجم، والمستندات
 *    السابقة تحتفظ بوحداتها كما سُجلت.
 *  - الصلاحية: items.manage (المدير، ويمنحها لموظف من صفحة المستخدمين). الإخفاء يخص الشركة كلها، فهو لمستخدم
 *    يرى كل الفروع. كل عملية في معاملة مع سطرها في المراقبة ثم رفع رقم إصدار البيانات.
 *  - ترتيب الأقفال: صف المقاس (الخطوة 4) ثم أرصدته بقفل مشترك (الخطوة 5)، مثل المستندات.
 */

const ITEMS_DENIED = 'ليست لديك صلاحية إدارة المقاسات. راجع مدير النظام.';

function items_require_manager(PDO $pdo): void
{
    if (!acct_can('items.manage')) {
        throw new ValidationException(['item' => ITEMS_DENIED]);
    }
    if (acct_branch_scope($pdo) !== null) {
        throw new ValidationException(['item' => 'إدارة المقاسات تخص الشركة كلها، فهي متاحة لمستخدم يرى كل الفروع.']);
    }
}

/**
 * قائمة المقاسات مع رصيدها في كل المخازن. $hidden: null = الكل، true = المخفية، false = الظاهرة.
 * @return array<int,array<string,mixed>>
 */
function items_list(PDO $pdo, int $typeId = 0, ?bool $hidden = null): array
{
    $where = [];
    $params = [];
    if ($typeId > 0) {
        $where[] = 'i.wood_type_id = ?';
        $params[] = $typeId;
    }
    if ($hidden !== null) {
        $where[] = 'i.is_hidden = ?';
        $params[] = $hidden ? 1 : 0;
    }
    $stmt = $pdo->prepare(
        'SELECT i.*, t.name AS wood_type_name, t.is_hidden AS type_hidden,
                COALESCE((SELECT SUM(s.qty_on_hand) FROM stock s WHERE s.item_id = i.id), 0) AS qty,
                (SELECT COUNT(*) FROM document_lines l WHERE l.item_id = i.id) AS line_count
         FROM items i JOIN wood_types t ON t.id = i.wood_type_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.name, t.id, i.thickness_um, i.width_um, i.length_um, i.id'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** يقفل صف المقاس ويقرأ رصيده الكلي بقراءة مقفلة. @return array{row:array,qty:int} */
function item_lock(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare('SELECT i.*, t.name AS wood_type_name FROM items i JOIN wood_types t ON t.id = i.wood_type_id WHERE i.id = ? FOR UPDATE');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ValidationException(['item' => 'المقاس غير موجود.']);
    }
    $q = $pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM (SELECT qty_on_hand FROM stock WHERE item_id = ? LOCK IN SHARE MODE) x');
    $q->execute([$itemId]);
    return ['row' => $row, 'qty' => (int) $q->fetchColumn()];
}

/** وصف المقاس للرسائل والسجل: «موسكي، عرض ١٠ سم × تخانة ٥٠ مللي × طول ٣ متر» */
function item_label(array $item): string
{
    return $item['wood_type_name'] . '، ' . fmt_size($item);
}

/**
 * يخفي مقاسًا رصيده صفر في كل المخازن، أو يظهره. @return bool true إذا تغيرت الحالة
 */
function item_set_hidden(PDO $pdo, int $itemId, bool $hidden): bool
{
    items_require_manager($pdo);
    return db_transaction($pdo, function (PDO $pdo) use ($itemId, $hidden) {
        ['row' => $it, 'qty' => $qty] = item_lock($pdo, $itemId);
        if ((int) $it['is_hidden'] === ($hidden ? 1 : 0)) {
            return false;
        }
        if ($hidden && $qty !== 0) {
            throw new ValidationException(['item' => sprintf(
                'لا يمكن إخفاء مقاس له رصيد (%s قطعة). يُخفى المقاس بعد أن يصبح رصيده صفرًا في كل المخازن.', fmt_int($qty))]);
        }
        $pdo->prepare('UPDATE items SET is_hidden = ? WHERE id = ?')->execute([$hidden ? 1 : 0, $itemId]);
        audit_record($pdo, $hidden ? 'item.hide' : 'item.show', ($hidden ? 'إخفاء المقاس: ' : 'إظهار المقاس: ') . item_label($it), 'item', $itemId);
        data_version_bump($pdo);
        return true;
    });
}

/**
 * وحدات عرض المقاس (للعرض فقط). $units: [width_unit, thickness_unit, length_unit] من mm|cm|m.
 * @return bool true إذا تغيرت
 */
function item_set_units(PDO $pdo, int $itemId, array $units): bool
{
    items_require_manager($pdo);
    $errors = [];
    foreach (['width_unit', 'thickness_unit', 'length_unit'] as $k) {
        if (!is_unit((string) ($units[$k] ?? ''))) {
            $errors[$k] = 'اختر الوحدة من القائمة.';
        }
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    return db_transaction($pdo, function (PDO $pdo) use ($itemId, $units) {
        ['row' => $it] = item_lock($pdo, $itemId);
        $old = ['width_unit' => $it['width_unit'], 'thickness_unit' => $it['thickness_unit'], 'length_unit' => $it['length_unit']];
        $new = ['width_unit' => (string) $units['width_unit'], 'thickness_unit' => (string) $units['thickness_unit'], 'length_unit' => (string) $units['length_unit']];
        if ($old === $new) {
            return false;
        }
        $pdo->prepare('UPDATE items SET width_unit = ?, thickness_unit = ?, length_unit = ? WHERE id = ?')
            ->execute([$new['width_unit'], $new['thickness_unit'], $new['length_unit'], $itemId]);
        audit_record($pdo, 'item.units', sprintf('وحدات عرض المقاس %s: من «%s» إلى «%s»', $it['wood_type_name'], fmt_size($it), fmt_size($it, $new)),
            'item', $itemId, ['old' => $old, 'new' => $new]);
        data_version_bump($pdo);
        return true;
    });
}

/**
 * يخفي نوع خشب من نموذج الوارد (إذا كانت أرصدة كل مقاساته صفرًا)، أو يظهره. يخفي مقاساته الظاهرة معه.
 * @return bool true إذا تغيرت الحالة
 */
function type_set_hidden(PDO $pdo, int $typeId, bool $hidden): bool
{
    items_require_manager($pdo);
    return db_transaction($pdo, function (PDO $pdo) use ($typeId, $hidden) {
        $stmt = $pdo->prepare('SELECT id, name, is_hidden FROM wood_types WHERE id = ? FOR UPDATE');
        $stmt->execute([$typeId]);
        $t = $stmt->fetch();
        if (!$t) {
            throw new ValidationException(['item' => 'نوع الخشب غير موجود.']);
        }
        if ((int) $t['is_hidden'] === ($hidden ? 1 : 0)) {
            return false;
        }
        if ($hidden) {
            $ids = $pdo->prepare('SELECT id FROM items WHERE wood_type_id = ? ORDER BY id FOR UPDATE');
            $ids->execute([$typeId]);
            $ids = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $q = $pdo->prepare("SELECT COALESCE(SUM(qty_on_hand), 0) FROM (SELECT qty_on_hand FROM stock WHERE item_id IN ($in) LOCK IN SHARE MODE) x");
                $q->execute($ids);
                $qty = (int) $q->fetchColumn();
                if ($qty !== 0) {
                    throw new ValidationException(['item' => sprintf(
                        'لا يمكن إخفاء «%s» ولمقاساته رصيد (%s قطعة). يُخفى النوع بعد أن تصبح أرصدة كل مقاساته صفرًا.', $t['name'], fmt_int($qty))]);
                }
                $pdo->prepare("UPDATE items SET is_hidden = 1 WHERE id IN ($in)")->execute($ids);
            }
        }
        $pdo->prepare('UPDATE wood_types SET is_hidden = ? WHERE id = ?')->execute([$hidden ? 1 : 0, $typeId]);
        audit_record($pdo, $hidden ? 'type.hide' : 'type.show', ($hidden ? 'إخفاء نوع الخشب: ' : 'إظهار نوع الخشب: ') . $t['name'], 'type', $typeId);
        data_version_bump($pdo);
        return true;
    });
}

/** هل النوع مخفي؟ (قبل الترقية 009 لا يوجد العمود، فلا شيء مخفي) */
function type_is_hidden(PDO $pdo, int $typeId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT is_hidden FROM wood_types WHERE id = ?');
        $stmt->execute([$typeId]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        if (audit_db_error_code($e) === 1054) {
            return false;
        }
        throw $e;
    }
}
