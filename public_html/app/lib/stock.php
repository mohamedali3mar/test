<?php
defined('APP_ROOT') || exit;

/*
 * استعلامات الأرصدة للعرض. كل المجاميع تُحسب بدقة كاملة كأعداد صحيحة بالميكرومتر المكعب.
 */

/** ملخص لكل مخزن: عدد المقاسات المتاحة، والقطع، والحجم. $branchId يقصره على مخازن فرع واحد */
function warehouse_summary(PDO $pdo, ?int $branchId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT w.id, w.name, w.branch_id,
                COALESCE(SUM(CASE WHEN s.qty_on_hand > 0 THEN 1 ELSE 0 END), 0) AS sizes,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty,
                COALESCE(SUM(s.qty_on_hand * i.piece_volume_m3), 0) AS volume
         FROM warehouses w
         LEFT JOIN stock s ON s.warehouse_id = w.id
         LEFT JOIN items i ON i.id = s.item_id'
        . ($branchId !== null ? ' WHERE w.branch_id = ?' : '') . '
         GROUP BY w.id, w.name, w.branch_id
         ORDER BY w.name, w.id'
    );
    $stmt->execute($branchId !== null ? [$branchId] : []);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['volume'] = Num::trimDecimal((string) $r['volume']);
    }
    return $rows;
}

/**
 * جدول المخزون مجمعًا حسب النوع.
 * $warehouseId = 0 يعني كل المخازن (الكمية = مجموع المخازن، مع التوزيع).
 * $branchId يقصر الأرصدة والإجماليات على مخازن فرع واحد (null = كل الفروع).
 */
function inventory_view(PDO $pdo, string $q, int $typeId, int $warehouseId, bool $hideEmpty, ?int $branchId = null): array
{
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = 't.name LIKE ?';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    if ($typeId > 0) {
        $where[] = 't.id = ?';
        $params[] = $typeId;
    }
    if ($warehouseId > 0) {
        $where[] = 's.warehouse_id = ?';
        $params[] = $warehouseId;
    }
    if ($branchId !== null) {
        $where[] = 's.warehouse_id IN (SELECT id FROM warehouses WHERE branch_id = ?)';
        $params[] = $branchId;
    }
    $stmt = $pdo->prepare(
        'SELECT i.id, i.wood_type_id, t.name AS wood_type_name, i.width_um, i.thickness_um, i.length_um,
                i.width_unit, i.thickness_unit, i.length_unit, i.piece_volume_m3,
                s.warehouse_id, s.qty_on_hand
         FROM items i
         JOIN wood_types t ON t.id = i.wood_type_id
         JOIN stock s ON s.item_id = i.id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.name, t.id, i.thickness_um, i.width_um, i.length_um, s.warehouse_id'
    );
    $stmt->execute($params);

    $items = [];
    foreach ($stmt as $r) {
        $id = (int) $r['id'];
        if (!isset($items[$id])) {
            $items[$id] = $r + ['qty' => 0, 'by_warehouse' => []];
        }
        $items[$id]['qty'] += (int) $r['qty_on_hand'];
        $items[$id]['by_warehouse'][(int) $r['warehouse_id']] = (int) $r['qty_on_hand'];
    }

    $groups = [];
    $grandQty = '0';
    $grandUm3 = '0';
    $emptyCount = 0;
    foreach ($items as $it) {
        if ($hideEmpty && $it['qty'] === 0) {
            continue;
        }
        $pieceUm3 = m3_to_um3((string) $it['piece_volume_m3']);
        $volUm3 = Num::mul($pieceUm3, (string) $it['qty']);
        $it['volume'] = um3_to_m3($volUm3);
        $tid = (int) $it['wood_type_id'];
        if (!isset($groups[$tid])) {
            $groups[$tid] = ['name' => $it['wood_type_name'], 'rows' => [], 'qty' => '0', 'um3' => '0', 'empty' => 0];
        }
        $groups[$tid]['rows'][] = $it;
        $groups[$tid]['qty'] = Num::add($groups[$tid]['qty'], (string) $it['qty']);
        $groups[$tid]['um3'] = Num::add($groups[$tid]['um3'], $volUm3);
        if ($it['qty'] === 0) {
            $groups[$tid]['empty']++;
            $emptyCount++;
        }
        $grandQty = Num::add($grandQty, (string) $it['qty']);
        $grandUm3 = Num::add($grandUm3, $volUm3);
    }
    foreach ($groups as &$g) {
        $g['volume'] = um3_to_m3($g['um3']);
    }
    return [
        'groups' => $groups,
        'qty' => $grandQty,
        'volume' => um3_to_m3($grandUm3),
        'empty' => $emptyCount,
        'count' => array_sum(array_map(fn ($g) => count($g['rows']), $groups)),
    ];
}

/**
 * بيانات الأصناف وأرصدتها في كل المخازن، لنماذج الوارد والبيع والتحويل وللتحديث التلقائي.
 * النصوص منسقة على الخادم (حسب إعداد الأرقام) وتُعرض في المتصفح كنص فقط.
 * $branchId (نطاق المستخدم) يقصر الأرصدة على مخازن فرعه؛ قائمة المقاسات نفسها مشتركة بين الفروع.
 */
function stock_payload(PDO $pdo, ?int $branchId = null): array
{
    $stock = [];
    $stmt = $pdo->prepare('SELECT item_id, warehouse_id, qty_on_hand FROM stock'
        . ($branchId !== null ? ' WHERE warehouse_id IN (SELECT id FROM warehouses WHERE branch_id = ?)' : ''));
    $stmt->execute($branchId !== null ? [$branchId] : []);
    foreach ($stmt as $s) {
        $stock[(int) $s['item_id']][(string) $s['warehouse_id']] = (int) $s['qty_on_hand'];
    }
    $out = [];
    $rows = $pdo->query(
        'SELECT i.*, t.name AS wood_type_name FROM items i JOIN wood_types t ON t.id = i.wood_type_id
         ORDER BY t.name, t.id, i.thickness_um, i.width_um, i.length_um'
    );
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'type' => (int) $r['wood_type_id'],
            'typeName' => $r['wood_type_name'],
            'key' => $r['wood_type_id'] . ':' . $r['width_um'] . ':' . $r['thickness_um'] . ':' . $r['length_um'],
            'size' => fmt_size($r),
            'piece' => m3_to_um3((string) $r['piece_volume_m3']),
            'stock' => (object) ($stock[(int) $r['id']] ?? []),
        ];
    }
    return $out;
}

function json_for_script(mixed $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}
