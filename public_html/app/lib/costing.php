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
 *   إلغاء بيع:             V += تكلفة السطر المحفوظة، معدلة بفروق التكلفة المسجلة بعد البيع (تُعكس بفرق مقابل)،
 *                          فلا تختلف النتيجة باختلاف ترتيب إلغاء البيع والوارد.
 *   إلغاء وارد:            V -= تكلفة السطر؛ إذا صارت V سالبة أو نفدت الكمية مع بقاء قيمة تُضبط V = 0
 *                          ويُسجل الفرق في cost_adjustments (الموجب خسارة تُخصم من الربح، والسالب ربح).
 *   التحويل لا يغير V.
 *
 * رصيد بلا قيمة (Q > 0 و V = 0) نوعان:
 *   - لم يُقيَّم (سُجل بدون تكلفة): يظهر في التقييم الافتتاحي، ولا يُقبل له وارد بتكلفة ولا إلغاء بيع يعيد قيمة
 *     حتى يقيّمه المدير، حتى لا تختلط القطع بلا قيمة بالقطع المقيّمة.
 *   - خُصمت تكلفته (items.cost_written_off = 1): صارت V صفرًا بإلغاء وارد أو بيع والقطع باقية، وتكلفتها
 *     محسوبة في الأرباح بالفعل. لا يظهر في التقييم الافتتاحي ولا يُقيَّم (حتى لا تُحسب تكلفته مرتين).
 * التقييم الافتتاحي يحفظ تكلفة المتر ووقته (opening_cost_per_m3، opening_valued_at): إلغاء وارد بلا تكلفة أو بيع
 * بتكلفة صفر سُجل قبل التقييم يُعكس بهذه التكلفة، فيخرج من V ما أضافه التقييم لقطعه أو يعود إليها.
 *
 * الترتيب يتبع ترتيب التسجيل وليس التاريخ المحاسبي: المستند بتاريخ سابق يؤثر على المتوسط وقت حفظه.
 * كل الدوال التي تقرأ أو تكتب تُستدعى داخل معاملة، بعد قفل الطرف والخزائن وقبل قفل صفوف stock.
 */

/**
 * يقفل صفوف الأصناف FOR UPDATE بترتيب تصاعدي (الخطوة 4 في ترتيب الأقفال) ويقرأ Q لكل صنف بقراءة مقفلة.
 * opening_c: تكلفة المتر بالقروش في آخر تقييم افتتاحي (أو null)، opening_at: وقته.
 * @param int[] $itemIds
 * @return array<int, array{value:int, qty:int, piece_um3:string, opening_c:?string, opening_at:?string, written_off:bool}>
 */
function costing_lock_items(PDO $pdo, array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn ($v) => $v > 0)));
    sort($ids);
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, inventory_value, piece_volume_m3, opening_cost_per_m3, opening_valued_at, cost_written_off
         FROM items WHERE id IN ($in) ORDER BY id FOR UPDATE"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $opening = $r['opening_cost_per_m3'] !== null && $r['opening_valued_at'] !== null;
        $out[(int) $r['id']] = [
            'value' => money_to_piasters((string) $r['inventory_value']),
            'qty' => 0,
            'piece_um3' => m3_to_um3((string) $r['piece_volume_m3']),
            'opening_c' => $opening ? (string) money_to_piasters((string) $r['opening_cost_per_m3']) : null,
            'opening_at' => $opening ? (string) $r['opening_valued_at'] : null,
            'written_off' => (int) $r['cost_written_off'] === 1,
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

/**
 * يحفظ V الجديدة لصنف مقفل.
 * $writtenOff: true = رصيد خُصمت تكلفته، false = ليس كذلك (رصيد جديد أو لم يُقيَّم)، null = بلا تغيير.
 */
function costing_set_value(PDO $pdo, int $itemId, int $value, ?bool $writtenOff = null): void
{
    if ($value < 0) {
        throw new RuntimeException('Negative inventory value');
    }
    if ($writtenOff === null) {
        $pdo->prepare('UPDATE items SET inventory_value = ? WHERE id = ?')->execute([piasters_to_money($value), $itemId]);
        return;
    }
    $pdo->prepare('UPDATE items SET inventory_value = ?, cost_written_off = ? WHERE id = ?')
        ->execute([piasters_to_money($value), $writtenOff ? 1 : 0, $itemId]);
}

/** رصيد لم يُقيَّم بعد: له قطع وقيمته صفر، وليست قيمة خُصمت تكلفتها من الأرباح */
function costing_unvalued(array $c): bool
{
    return $c['qty'] > 0 && $c['value'] === 0 && !$c['written_off'];
}

/** رسالة رفض عملية تعيد قيمة لمقاس رصيده لم يُقيَّم: التقييم للمدير، فالموظف يطلبه منه */
function costing_unvalued_message(string $action): string
{
    return sprintf(
        'لهذا المقاس رصيد بدون تكلفة لم يُقيَّم بعد، فلا يُقبل %s الآن حتى لا تختلط القطع بلا قيمة بالقطع المقيّمة. '
        . 'اطلب من المدير تقييم رصيده أولًا من صفحة «التقييم الافتتاحي للمخزون»، ثم أعد المحاولة.',
        $action
    );
}

/**
 * قيمة سطر بتكلفة صفر (وارد بدون تكلفة أو بيع بتكلفة صفر) سُجل قبل آخر تقييم افتتاحي للصنف: حجمه × تكلفة التقييم.
 * أي سطر آخر يبقى بتكلفته المحفوظة.
 */
function costing_line_cost(array $c, array $line, string $docCreatedAt): int
{
    $cost = money_to_piasters((string) ($line['cost_amount'] ?? '0'));
    if ($cost !== 0 || ($line['cost_per_m3'] ?? null) !== null || $c['opening_c'] === null || $docCreatedAt > $c['opening_at']) {
        return $cost;
    }
    return (int) sale_amount_piasters(m3_to_um3((string) $line['total_volume_m3']), $c['opening_c']);
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

/** الأصناف التي لها رصيد لم يُقيَّم (Q > 0 و V = 0، وليست تكلفته محسوبة في الأرباح)، للعرض */
function unvalued_items(PDO $pdo): array
{
    return $pdo->query(
        'SELECT i.id, i.width_um, i.thickness_um, i.length_um, i.width_unit, i.thickness_unit, i.length_unit,
                i.piece_volume_m3, t.name AS wood_type_name, SUM(s.qty_on_hand) AS qty
         FROM items i JOIN wood_types t ON t.id = i.wood_type_id JOIN stock s ON s.item_id = i.id
         WHERE i.inventory_value = 0 AND i.cost_written_off = 0
         GROUP BY i.id, i.width_um, i.thickness_um, i.length_um, i.width_unit, i.thickness_unit, i.length_unit, i.piece_volume_m3, t.name
         HAVING SUM(s.qty_on_hand) > 0
         ORDER BY t.name, i.thickness_um, i.width_um, i.length_um, i.id'
    )->fetchAll();
}

/**
 * يحدد قيمة افتتاحية لصنف له رصيد بلا قيمة: V = sale_amount_piasters(Q × حجم القطعة, c).
 * مسموح فقط والقيمة صفر وليست تكلفة الرصيد محسوبة في الأرباح، بعد قفل الصنف. يحفظ c ووقت التقييم
 * لإلغاء المستندات التي سُجلت قبله بتكلفة صفر. يُسجل في سجل المراقبة.
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
        if ($it['written_off']) {
            throw new ValidationException(['form' => 'قيمة هذا الصنف صفر لأن تكلفة رصيده خُصمت بالفعل من الأرباح عند إلغاء وارد بعد بيع جزء منه '
                . '(تظهر في «فروق تكلفة المخزون» بتقرير الأرباح)، فلا يُقيَّم مرة أخرى حتى لا تُحسب تكلفته مرتين.']);
        }
        $value = (int) sale_amount_piasters(Num::mul($it['piece_um3'], (string) $it['qty']), $cost);
        costing_set_value($pdo, $itemId, $value, false);
        // تكلفة المتر ووقت التقييم: إلغاء وارد بلا تكلفة أو بيع بتكلفة صفر سُجل قبل الآن يُعكس بها
        $pdo->prepare('UPDATE items SET opening_cost_per_m3 = ?, opening_valued_at = ? WHERE id = ?')
            ->execute([Num::toDecimal($cost, 2), now(), $itemId]);
        acct_audit($pdo, 'opening_valuation', sprintf('تقييم افتتاحي لصنف رقم %d: %s قطعة بقيمة %s', $itemId, $it['qty'], piasters_to_money($value)),
            'item', $itemId, ['qty' => $it['qty'], 'cost_per_m3' => Num::toDecimal($cost, 2), 'value' => piasters_to_money($value)]);
        data_version_bump($pdo);
        return ['item_id' => $itemId, 'qty' => $it['qty'], 'value' => $value];
    });
    return $result;
}
