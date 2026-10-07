<?php
defined('APP_ROOT') || exit;

/*
 * تكلفة المخزون بالمتوسط المرجح المتحرك (ACCOUNTING_SPEC.md البند 2).
 *
 *   V = items.inventory_value: قيمة كل قطع الصنف في كل المخازن (بالجنيه في القاعدة، بالقروش هنا).
 *   Q = مجموع stock.qty_on_hand للصنف في كل المخازن، يُقرأ بقراءة مقفلة بعد قفل صف الصنف.
 *
 *   وارد بتكلفة c للمتر:   A = sale_amount_piasters(حجم الكمية, c)، و V += A.
 *   وارد بدون تكلفة:       A = V × q ÷ Q (إذا Q > 0) وإلا A = 0 (مخزون بلا قيمة)، و V += A.
 *   بيع q قطعة:            COGS = V × q ÷ Q مقربًا النصف لأعلى (q = Q تعطي V بالضبط)، و V -= COGS.
 *   إلغاء بيع:             V += تكلفة السطر المحفوظة.
 *   إلغاء وارد:            V -= تكلفة السطر؛ إذا صارت V سالبة أو نفدت الكمية مع بقاء قيمة تُضبط V = 0
 *                          ويُسجل الفرق في cost_adjustments (الموجب خسارة تُخصم من الربح، والسالب ربح).
 *   التحويل لا يغير V.
 *
 * الترتيب يتبع ترتيب التسجيل وليس التاريخ المحاسبي: المستند بتاريخ سابق يؤثر على المتوسط وقت حفظه.
 * كل الدوال التي تقرأ أو تكتب تُستدعى داخل معاملة، بعد قفل الطرف والخزائن وقبل قفل صفوف stock.
 */

/**
 * يقفل صفوف الأصناف FOR UPDATE بترتيب تصاعدي (الخطوة 4 في ترتيب الأقفال) ويقرأ Q لكل صنف بقراءة مقفلة.
 * @param int[] $itemIds
 * @return array<int, array{value:int, qty:int, piece_um3:string}>
 */
function costing_lock_items(PDO $pdo, array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn ($v) => $v > 0)));
    sort($ids);
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, inventory_value, piece_volume_m3 FROM items WHERE id IN ($in) ORDER BY id FOR UPDATE");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['id']] = [
            'value' => money_to_piasters((string) $r['inventory_value']),
            'qty' => 0,
            'piece_um3' => m3_to_um3((string) $r['piece_volume_m3']),
        ];
    }
    foreach ($ids as $id) {
        if (!isset($out[$id])) {
            throw new ValidationException(['form' => 'أحد المقاسات المختارة لم يعد موجودًا. حدّث الصفحة وأعد المحاولة.']);
        }
    }
    // Q بقراءة مقفلة (قفل مشترك) بترتيب (item_id, warehouse_id): لا تتأثر بلقطة قراءة قديمة
    $q = $pdo->prepare("SELECT item_id, qty_on_hand FROM stock WHERE item_id IN ($in) ORDER BY item_id, warehouse_id LOCK IN SHARE MODE");
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $out[(int) $r['item_id']]['qty'] += (int) $r['qty_on_hand'];
    }
    return $out;
}

/** يحفظ V الجديدة لصنف مقفل */
function costing_set_value(PDO $pdo, int $itemId, int $value): void
{
    if ($value < 0) {
        throw new RuntimeException('Negative inventory value');
    }
    $pdo->prepare('UPDATE items SET inventory_value = ? WHERE id = ?')->execute([piasters_to_money($value), $itemId]);
}

/**
 * قيمة سطر وارد بالقروش.
 * @param ?string $costPiasters تكلفة المتر بالقروش، أو null إذا لم تُدخل
 */
function costing_receipt_value(int $value, int $qty, int $q, string $totalUm3, ?string $costPiasters): int
{
    if ($costPiasters !== null) {
        return (int) sale_amount_piasters($totalUm3, $costPiasters);
    }
    if ($qty > 0 && $value > 0) {
        return muldiv_half_up($value, $q, $qty);
    }
    return 0;
}

/** تكلفة البضاعة المباعة لسطر بيع q قطعة من Q */
function costing_sale_cogs(int $value, int $qty, int $q): int
{
    if ($qty <= 0 || $q <= 0 || $value <= 0) {
        return 0;
    }
    if ($q >= $qty) {
        return $value;
    }
    return muldiv_half_up($value, $q, $qty);
}

/**
 * V بعد إلغاء سطر وارد.
 * @return array{0:int, 1:int} [V النهائية, فرق التكلفة = المحسوبة − النهائية]
 */
function costing_cancel_receipt_value(int $value, int $qtyAfter, int $lineCost): array
{
    $computed = $value - $lineCost;
    $final = ($qtyAfter <= 0 || $computed < 0) ? 0 : $computed;
    return [$final, $computed - $final];
}

function costing_record_adjustment(PDO $pdo, int $itemId, ?int $documentId, int $amount, string $reason, ?int $userId): void
{
    if ($amount === 0) {
        return;
    }
    $pdo->prepare('INSERT INTO cost_adjustments (item_id, document_id, amount, reason, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$itemId, $documentId, piasters_to_money($amount), mb_substr($reason, 0, 255), now(), $userId]);
}

/* ===================== التقييم الافتتاحي ===================== */

/** الأصناف التي لها رصيد بلا قيمة (Q > 0 و V = 0)، للعرض */
function unvalued_items(PDO $pdo): array
{
    return $pdo->query(
        'SELECT i.id, i.width_um, i.thickness_um, i.length_um, i.width_unit, i.thickness_unit, i.length_unit,
                i.piece_volume_m3, t.name AS wood_type_name, SUM(s.qty_on_hand) AS qty
         FROM items i JOIN wood_types t ON t.id = i.wood_type_id JOIN stock s ON s.item_id = i.id
         WHERE i.inventory_value = 0
         GROUP BY i.id, i.width_um, i.thickness_um, i.length_um, i.width_unit, i.thickness_unit, i.length_unit, i.piece_volume_m3, t.name
         HAVING SUM(s.qty_on_hand) > 0
         ORDER BY t.name, i.thickness_um, i.width_um, i.length_um, i.id'
    )->fetchAll();
}

/**
 * يحدد قيمة افتتاحية لصنف له رصيد بلا قيمة: V = sale_amount_piasters(Q × حجم القطعة, c).
 * مسموح فقط والقيمة صفر، بعد قفل الصنف. يُسجل في سجل المراقبة.
 * @return array{item_id:int, qty:int, value:int}
 */
function set_opening_valuation(PDO $pdo, int $userId, int $itemId, string $costRaw): array
{
    if (!acct_can('reports.valuation')) {
        throw new ValidationException(['form' => 'لا تملك صلاحية تقييم المخزون.']);
    }
    [$cost, $err] = parse_price($costRaw);
    if ($err !== null) {
        throw new ValidationException(['cost_per_m3' => str_replace('السعر', 'تكلفة المتر', $err)]);
    }
    $result = db_transaction($pdo, function (PDO $pdo) use ($itemId, $cost) {
        $items = costing_lock_items($pdo, [$itemId]);
        $it = $items[$itemId];
        if ($it['value'] !== 0) {
            throw new ValidationException(['form' => 'هذا الصنف له قيمة مخزون بالفعل، والتقييم الافتتاحي متاح فقط للأصناف بلا قيمة.']);
        }
        if ($it['qty'] <= 0) {
            throw new ValidationException(['form' => 'لا يوجد رصيد لهذا الصنف الآن.']);
        }
        $value = (int) sale_amount_piasters(Num::mul($it['piece_um3'], (string) $it['qty']), $cost);
        costing_set_value($pdo, $itemId, $value);
        acct_audit($pdo, 'opening_valuation', sprintf('تقييم افتتاحي لصنف رقم %d: %s قطعة بقيمة %s', $itemId, $it['qty'], piasters_to_money($value)),
            'item', $itemId, ['qty' => $it['qty'], 'cost_per_m3' => Num::toDecimal($cost, 2), 'value' => piasters_to_money($value)]);
        data_version_bump($pdo);
        return ['item_id' => $itemId, 'qty' => $it['qty'], 'value' => $value];
    });
    return $result;
}
