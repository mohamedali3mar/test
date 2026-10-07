<?php
defined('APP_ROOT') || exit;

/*
 * المستندات: الوارد وفاتورة البيع والتحويل والإلغاء.
 *
 * قواعد ثابتة في كل العمليات:
 *  - كل عملية معاملة واحدة: الرصيد والمستند وأسطره والترقيم إما تُحفظ معًا أو لا يُحفظ شيء.
 *  - ترتيب الأقفال واحد دائمًا لمنع الجمود:
 *      (الإلغاء فقط) صف المستند ← صفوف items مرتبة تصاعديًا (حصري في الوارد، مشترك في غيره)
 *      ← صفوف stock مرتبة تصاعديًا (item_id, warehouse_id) ← صف عداد الترقيم ← إدراج المستند
 *      ← رفع data_version آخر شيء.
 *  - لا قراءات غير مقفلة داخل المعاملات (تُقرأ البيانات الثابتة قبلها)، توافقًا مع MariaDB 11.6+.
 *  - الرصيد الملزم يُقرأ بعد القفل (SELECT ... FOR UPDATE)، وليس من القيمة المعروضة في الواجهة.
 *  - رمز الطلب request_token فريد، ومعه بصمة المحتوى request_hash، فإعادة الإرسال لا تكرر العملية.
 */

const DOC_COUNTERS = ['in' => 'doc_in', 'sale' => 'doc_sale', 'transfer' => 'doc_transfer'];
const MAX_LINES = 50;
const FORM_EXPIRED = 'انتهت صلاحية النموذج. أعد فتح الصفحة وحاول مرة أخرى.';

/* ===================== أدوات عامة ===================== */

function request_hash(array $canonical): string
{
    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/**
 * إذا كان رمز الطلب مستخدمًا: يعيد المستند المسجل (نفس المحتوى)،
 * أو يرفض بوضوح إذا تغير المحتوى بعد الحفظ (مثلًا رجوع ثم تعديل ثم إرسال).
 */
function existing_request(PDO $pdo, string $token, string $kind, string $hash): ?array
{
    $stmt = $pdo->prepare('SELECT id, kind, doc_no, request_hash FROM documents WHERE request_token = ?');
    $stmt->execute([$token]);
    $d = $stmt->fetch();
    if (!$d) {
        return null;
    }
    if ($d['kind'] !== $kind || !hash_equals($d['request_hash'], $hash)) {
        throw new ValidationException([
            'request_token' => sprintf(
                'هذا النموذج حُفظ سابقًا (%s)، ولم تُحفظ التعديلات الجديدة. راجع البيانات ثم احفظها كمستند جديد.',
                doc_label($d)
            ),
        ]);
    }
    return ['id' => (int) $d['id'], 'kind' => $d['kind'], 'doc_no' => (int) $d['doc_no'], 'duplicate' => true];
}

/** نصوص اختيارية: تنظيف وحد أقصى للطول */
function clean_text_fields(array $in, array $spec, array &$errors): array
{
    $out = [];
    foreach ($spec as $field => [$label, $max, $multiline]) {
        $value = clean_text(is_string($in[$field] ?? null) ? $in[$field] : '', $multiline);
        if (mb_strlen($value) > $max) {
            $errors[$field] = sprintf('%s: النص أطول من المسموح (%s حرفًا على الأكثر).', $label, fmt_int($max));
        }
        $out[$field] = $value;
    }
    return $out;
}

function check_token(array $in, array &$errors): string
{
    $token = is_string($in['request_token'] ?? null) ? $in['request_token'] : '';
    if (!is_request_token($token)) {
        $errors['request_token'] = FORM_EXPIRED;
    }
    return $token;
}

/** الأصناف بمعرفاتها مع اسم النوع */
function load_items(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
    if (!$ids) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT i.*, t.name AS wood_type_name FROM items i JOIN wood_types t ON t.id = i.wood_type_id
         WHERE i.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt as $row) {
        $out[(int) $row['id']] = $row;
    }
    return $out;
}

/** الرصيد الحالي (بدون قفل) لعدة أصناف في مخزن. null = المقاس غير مسجل في هذا المخزن */
function current_stock(PDO $pdo, array $itemIds, int $warehouseId): array
{
    $out = [];
    foreach ($itemIds as $id) {
        $out[(int) $id] = null;
    }
    if (!$out) {
        return [];
    }
    $ids = array_keys($out);
    $stmt = $pdo->prepare(
        'SELECT item_id, qty_on_hand FROM stock WHERE warehouse_id = ? AND item_id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $stmt->execute([$warehouseId, ...$ids]);
    foreach ($stmt as $row) {
        $out[(int) $row['item_id']] = (int) $row['qty_on_hand'];
    }
    return $out;
}

/**
 * قفل مشترك على صفوف الأصناف بترتيب تصاعدي قبل أي قفل على الأرصدة.
 * الوارد يقفل صف الصنف (قفل حصري) ثم رصيده، وإدراج الأسطر يحتاج قفلًا مشتركًا على الصنف عبر المفتاح
 * الأجنبي؛ أخذ هذا القفل أولًا يجعل الترتيب واحدًا في كل العمليات: الأصناف ثم الأرصدة، فلا يحدث جمود.
 */
function lock_items_shared(PDO $pdo, array $itemIds): void
{
    $ids = array_values(array_unique(array_map('intval', $itemIds)));
    if (!$ids) {
        return;
    }
    sort($ids);
    $stmt = $pdo->prepare('SELECT id FROM items WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id LOCK IN SHARE MODE');
    $stmt->execute($ids);
    $stmt->fetchAll();
}

/**
 * يقفل صفوف الأرصدة بترتيب ثابت ويعيد الكميات. مع $create تُنشأ الصفوف الناقصة برصيد صفر.
 * @param array<int,array{0:int,1:int}> $pairs [item_id, warehouse_id]
 * @return array<string,?int> "item:warehouse" => الكمية
 */
function lock_stock_rows(PDO $pdo, array $pairs, bool $create): array
{
    $keys = [];
    foreach ($pairs as [$item, $wh]) {
        $keys[$item . ':' . $wh] = [(int) $item, (int) $wh];
    }
    uasort($keys, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $insert = $pdo->prepare(
        'INSERT INTO stock (item_id, warehouse_id, qty_on_hand, updated_at) VALUES (?, ?, 0, ?)
         ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand'
    );
    $select = $pdo->prepare('SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE');
    $now = now();
    $out = [];
    foreach ($keys as $k => [$item, $wh]) {
        if ($create) {
            $insert->execute([$item, $wh, $now]);
        }
        $select->execute([$item, $wh]);
        $q = $select->fetchColumn();
        $select->closeCursor();
        $out[$k] = $q === false ? null : (int) $q;
    }
    return $out;
}

function set_stock(PDO $pdo, int $itemId, int $warehouseId, int $qty, string $now): void
{
    if ($qty < 0 || $qty > MAX_STOCK) {
        throw new RuntimeException('Stock out of range');
    }
    $pdo->prepare('UPDATE stock SET qty_on_hand = ?, updated_at = ? WHERE item_id = ? AND warehouse_id = ?')
        ->execute([$qty, $now, $itemId, $warehouseId]);
}

function insert_document(PDO $pdo, array $d): int
{
    $cols = ['kind', 'doc_no', 'warehouse_id', 'warehouse_name', 'to_warehouse_id', 'to_warehouse_name',
        'party_name', 'reference', 'notes', 'line_count', 'total_qty', 'total_volume_m3', 'total_amount',
        'currency', 'request_token', 'request_hash', 'created_at', 'created_by',
        // الحسابات (المرحلة الثانية): التاريخ المحاسبي افتراضيًا هو وقت الإنشاء
        'doc_date', 'party_id', 'payment_type', 'paid_amount', 'cash_box_id', 'cash_box_name', 'total_cost'];
    $d['doc_date'] ??= $d['created_at'] ?? null;
    $values = [];
    foreach ($cols as $c) {
        $values[] = $d[$c] ?? null;
    }
    $pdo->prepare('INSERT INTO documents (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute($values);
    return (int) $pdo->lastInsertId();
}

function insert_line(PDO $pdo, int $docId, array $l): void
{
    $cols = ['line_no', 'item_id', 'wood_type_name', 'width_unit', 'thickness_unit', 'length_unit', 'quantity',
        'piece_volume_m3', 'total_volume_m3', 'price_per_m3', 'amount', 'balance_before', 'balance_after',
        'to_balance_before', 'to_balance_after', 'cost_per_m3', 'cost_amount'];
    $values = [$docId];
    foreach ($cols as $c) {
        $values[] = $l[$c] ?? null;
    }
    $pdo->prepare('INSERT INTO document_lines (document_id, ' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols) + 1, '?')) . ')')->execute($values);
}

/** حجم السطر وقيمته بدقة كاملة */
function line_figures(array $item, int $qty, ?string $pricePiasters): array
{
    $pieceUm3 = m3_to_um3((string) $item['piece_volume_m3']);
    $totalUm3 = Num::mul($pieceUm3, (string) $qty);
    $f = [
        'piece_um3' => $pieceUm3,
        'total_um3' => $totalUm3,
        'piece_m3' => um3_to_m3($pieceUm3),
        'total_m3' => um3_to_m3($totalUm3),
        'price' => null,
        'amount' => null,
        'amount_piasters' => '0',
    ];
    if ($pricePiasters !== null) {
        $f['amount_piasters'] = sale_amount_piasters($totalUm3, $pricePiasters);
        $f['price'] = Num::toDecimal($pricePiasters, 2);
        $f['amount'] = Num::toDecimal($f['amount_piasters'], 2);
    }
    return $f;
}

/** يحوّل أخطاء قاعدة البيانات المتوقعة إلى رسائل مفهومة */
function translate_db_error(PDOException $e, PDO $pdo, string $token, string $kind, string $hash): array
{
    if (is_duplicate_key($e, 'uq_documents_request_token')) {
        $dup = existing_request($pdo, $token, $kind, $hash);
        if ($dup) {
            return $dup;
        }
    }
    if (is_fk_error($e)) {
        throw new ValidationException(['form' => 'أحد البيانات المختارة (مخزن أو نوع أو مقاس) لم يعد موجودًا. حدّث الصفحة وأعد المحاولة.']);
    }
    throw $e;
}

/* ===================== الوارد ===================== */

function validate_receipt(PDO $pdo, array $in): array
{
    $errors = [];
    $wh = catalog_find($pdo, 'warehouse', (int) ($in['warehouse_id'] ?? 0));
    if (!$wh) {
        $errors['warehouse_id'] = 'اختر المخزن.';
    }
    $type = catalog_find($pdo, 'type', (int) ($in['wood_type_id'] ?? 0));
    if (!$type) {
        $errors['wood_type_id'] = 'اختر نوع الخشب.';
    }
    $dims = [];
    foreach (DIMENSIONS as $key => $label) {
        $unit = is_string($in[$key . '_unit'] ?? null) ? $in[$key . '_unit'] : '';
        [$um, $err] = parse_dimension(is_string($in[$key] ?? null) ? $in[$key] : '', $unit, $label);
        if ($err !== null) {
            $errors[$key] = $err;
        } else {
            $dims[$key] = ['um' => $um, 'unit' => $unit];
        }
    }
    [$qty, $err] = parse_quantity(is_string($in['quantity'] ?? null) ? $in['quantity'] : '');
    if ($err !== null) {
        $errors['quantity'] = $err;
    }
    $text = clean_text_fields($in, [
        'party_name' => ['اسم المورد', 120, false],
        'reference' => ['مرجع التوريد', 120, false],
        'notes' => ['الملاحظات', 1000, true],
    ], $errors);
    $token = check_token($in, $errors);
    if ($errors) {
        throw new ValidationException($errors);
    }

    $pieceUm3 = piece_volume_um3($dims['width']['um'], $dims['thickness']['um'], $dims['length']['um']);
    $totalUm3 = Num::mul($pieceUm3, (string) $qty);
    return $text + [
        'warehouse' => $wh,
        'type' => $type,
        'dims' => $dims,
        'quantity' => $qty,
        'piece_m3' => um3_to_m3($pieceUm3),
        'total_m3' => um3_to_m3($totalUm3),
        'request_token' => $token,
        'hash' => request_hash([
            'in', (int) $wh['id'], (int) $type['id'],
            $dims['width']['um'], $dims['width']['unit'],
            $dims['thickness']['um'], $dims['thickness']['unit'],
            $dims['length']['um'], $dims['length']['unit'],
            $qty, $text['party_name'], $text['reference'], $text['notes'],
        ]),
    ];
}

/** @return array{id:int, kind:string, doc_no:int, duplicate:bool} */
function record_receipt(PDO $pdo, int $userId, array $in): array
{
    $v = validate_receipt($pdo, $in);
    if ($dup = existing_request($pdo, $v['request_token'], 'in', $v['hash'])) {
        return $dup;
    }
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($v, $userId) {
            $now = now();
            $d = $v['dims'];
            $wid = (int) $v['warehouse']['id'];
            $identity = [(int) $v['type']['id'], $d['width']['um'], $d['thickness']['um'], $d['length']['um']];

            // الصنف: يُنشأ إن لم يوجد، ثم يُقرأ بمفتاحه الفريد مع القفل
            $pdo->prepare(
                'INSERT INTO items (wood_type_id, width_um, thickness_um, length_um, width_unit, thickness_unit, length_unit, piece_volume_m3, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id'
            )->execute([...$identity, $d['width']['unit'], $d['thickness']['unit'], $d['length']['unit'], $v['piece_m3'], $now]);
            $stmt = $pdo->prepare('SELECT id FROM items WHERE wood_type_id = ? AND width_um = ? AND thickness_um = ? AND length_um = ? FOR UPDATE');
            $stmt->execute($identity);
            $itemId = (int) $stmt->fetchColumn();
            if ($itemId <= 0) {
                throw new RuntimeException('Item upsert failed');
            }

            $bal = lock_stock_rows($pdo, [[$itemId, $wid]], true);
            $before = (int) $bal[$itemId . ':' . $wid];
            $after = $before + $v['quantity'];
            if ($after > MAX_STOCK) {
                throw new ValidationException(['quantity' => 'الرصيد الناتج أكبر من الحد المسموح للصنف.']);
            }

            $docNo = counter_next($pdo, DOC_COUNTERS['in']);
            $docId = insert_document($pdo, [
                'kind' => 'in', 'doc_no' => $docNo,
                'warehouse_id' => $wid, 'warehouse_name' => $v['warehouse']['name'],
                'party_name' => $v['party_name'] ?: null, 'reference' => $v['reference'] ?: null, 'notes' => $v['notes'] ?: null,
                'line_count' => 1, 'total_qty' => $v['quantity'], 'total_volume_m3' => $v['total_m3'],
                'request_token' => $v['request_token'], 'request_hash' => $v['hash'],
                'created_at' => $now, 'created_by' => $userId,
            ]);
            insert_line($pdo, $docId, [
                'line_no' => 1, 'item_id' => $itemId, 'wood_type_name' => $v['type']['name'],
                'width_unit' => $d['width']['unit'], 'thickness_unit' => $d['thickness']['unit'], 'length_unit' => $d['length']['unit'],
                'quantity' => $v['quantity'], 'piece_volume_m3' => $v['piece_m3'], 'total_volume_m3' => $v['total_m3'],
                'balance_before' => $before, 'balance_after' => $after,
            ]);
            set_stock($pdo, $itemId, $wid, $after, $now);
            data_version_bump($pdo);
            return ['id' => $docId, 'kind' => 'in', 'doc_no' => $docNo, 'duplicate' => false];
        });
    } catch (PDOException $e) {
        return translate_db_error($e, $pdo, $v['request_token'], 'in', $v['hash']);
    }
}

/* ===================== أسطر البيع والتحويل ===================== */

/**
 * يقرأ أسطر النموذج من $_POST كما هي (نصوص فقط)، مع حد أقصى لعدد الأسطر المقروءة.
 * @return array<int,array<string,string>>
 */
function form_lines(mixed $raw, array $fields): array
{
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    $read = 0;
    foreach ($raw as $row) {
        // حد أقصى للأسطر المقروءة من الطلب (حماية)، ثم تُستبعد الأسطر الفارغة تمامًا قبل حد الخمسين،
        // ويُحتفظ بسطر زائد واحد فقط ليظهر خطأ «الحد الأقصى» بدل حذف أسطر مملوءة بصمت
        if (++$read > 1000 || count($out) > MAX_LINES) {
            break;
        }
        $line = [];
        foreach ($fields as $f) {
            $line[$f] = is_array($row) && is_string($row[$f] ?? null) ? $row[$f] : '';
        }
        $blank = trim($line['item_id'] ?? '') === '' && trim($line['quantity'] ?? '') === '' && trim($line['price'] ?? '') === '';
        if (!$blank) {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * يتحقق من الأسطر: يتجاهل الأسطر الفارغة تمامًا، ويمنع تكرار المقاس، ويربط كل خطأ برقم السطر في النموذج.
 * مفاتيح الأخطاء: lines.{index}.{field}
 */
function validate_lines(PDO $pdo, array $lines, bool $withPrice, array &$errors): array
{
    $filled = [];
    foreach (array_values($lines) as $idx => $l) {
        $blank = trim($l['item_id'] ?? '') === '' && trim($l['quantity'] ?? '') === ''
            && (!$withPrice || trim($l['price'] ?? '') === '');
        if (!$blank) {
            $filled[$idx] = $l;
        }
    }
    if (!$filled) {
        $errors['lines'] = 'أضف سطرًا واحدًا على الأقل.';
        return [];
    }
    if (count($filled) > MAX_LINES) {
        $errors['lines'] = sprintf('الحد الأقصى %s سطرًا في المستند الواحد.', fmt_int(MAX_LINES));
        return [];
    }
    $items = load_items($pdo, array_map(fn ($l) => ctype_digit($l['item_id'] ?? '') ? (int) $l['item_id'] : 0, $filled));
    $seen = [];
    $out = [];
    $n = 0;
    foreach ($filled as $idx => $l) {
        $n++;
        $p = 'lines.' . $idx . '.';
        $itemId = ctype_digit($l['item_id'] ?? '') ? (int) $l['item_id'] : 0;
        $item = $items[$itemId] ?? null;
        if (!$item) {
            $errors[$p . 'item_id'] = 'اختر المقاس.';
        } elseif (isset($seen[$itemId])) {
            $errors[$p . 'item_id'] = sprintf('هذا المقاس مكرر في السطر %s. اجمع الكمية في سطر واحد.', fmt_int($seen[$itemId]));
        } else {
            $seen[$itemId] = $idx + 1;
        }
        [$qty, $err] = parse_quantity($l['quantity'] ?? '');
        if ($err !== null) {
            $errors[$p . 'quantity'] = $err;
        }
        $price = null;
        if ($withPrice) {
            [$price, $err] = parse_price($l['price'] ?? '');
            if ($err !== null) {
                $errors[$p . 'price'] = $err;
            }
        }
        $out[$idx] = ['line_no' => $n, 'item' => $item, 'quantity' => $qty, 'price_piasters' => $price];
    }
    return $out;
}

/** يجمع أرقام الأسطر والإجماليات */
function lines_with_figures(array $lines): array
{
    $totQty = 0;
    $totUm3 = '0';
    $totAmount = '0';
    foreach ($lines as $idx => $l) {
        $f = line_figures($l['item'], $l['quantity'], $l['price_piasters']);
        $lines[$idx] = $l + $f;
        $totQty += $l['quantity'];
        $totUm3 = Num::add($totUm3, $f['total_um3']);
        $totAmount = Num::add($totAmount, $f['amount_piasters']);
    }
    return [$lines, $totQty, um3_to_m3($totUm3), Num::toDecimal($totAmount, 2)];
}

/** أخطاء الكمية مقابل الرصيد (للمراجعة قبل الحفظ، أو داخل المعاملة بعد القفل) */
function stock_errors(array $lines, array $available, string $suffix = ''): array
{
    $errors = [];
    foreach ($lines as $idx => $l) {
        $have = $available[(int) $l['item']['id']] ?? null;
        if ($have === null) {
            $errors['lines.' . $idx . '.quantity'] = 'هذا المقاس غير موجود في المخزن المختار.';
        } elseif ($l['quantity'] > $have) {
            $errors['lines.' . $idx . '.quantity'] = sprintf('الكمية أكبر من المتاح%s في المخزن (%s قطعة).', $suffix, fmt_int($have));
        }
    }
    return $errors;
}

/* ===================== فاتورة البيع ===================== */

/** يتحقق من الفاتورة ويحسبها. $checkStock يقارن بالرصيد الحالي (بدون قفل) لصفحة المراجعة. */
function validate_sale(PDO $pdo, array $in, bool $checkStock = true): array
{
    $errors = [];
    $wh = catalog_find($pdo, 'warehouse', (int) ($in['warehouse_id'] ?? 0));
    if (!$wh) {
        $errors['warehouse_id'] = 'اختر المخزن.';
    }
    $lines = validate_lines($pdo, $in['lines'] ?? [], true, $errors);
    $text = clean_text_fields($in, [
        'party_name' => ['اسم العميل', 120, false],
        'notes' => ['الملاحظات', 1000, true],
    ], $errors);
    $token = check_token($in, $errors);
    if ($errors) {
        throw new ValidationException($errors);
    }
    if ($checkStock) {
        $avail = current_stock($pdo, array_map(fn ($l) => (int) $l['item']['id'], $lines), (int) $wh['id']);
        if ($stockErrors = stock_errors($lines, $avail)) {
            throw new ValidationException($stockErrors);
        }
    }
    [$lines, $totQty, $totM3, $totAmount] = lines_with_figures($lines);
    return $text + [
        'warehouse' => $wh,
        'lines' => $lines,
        'total_qty' => $totQty,
        'total_m3' => $totM3,
        'total_amount' => $totAmount,
        'request_token' => $token,
        'hash' => request_hash([
            'sale', (int) $wh['id'],
            array_values(array_map(fn ($l) => [(int) $l['item']['id'], $l['quantity'], $l['price_piasters']], $lines)),
            $text['party_name'], $text['notes'],
        ]),
    ];
}

function record_sale(PDO $pdo, int $userId, array $in): array
{
    // التحقق من المدخلات أولًا بدون الرصيد، ثم فحص التكرار: إعادة إرسال فاتورة محفوظة تعيدها
    // حتى لو صار الرصيد الآن أقل (بدل رسالة «الكمية أكبر من المتاح» المضللة)
    $v = validate_sale($pdo, $in, false);
    if ($dup = existing_request($pdo, $v['request_token'], 'sale', $v['hash'])) {
        return $dup;
    }
    $wid = (int) $v['warehouse']['id'];
    $itemIds = array_map(fn ($l) => (int) $l['item']['id'], $v['lines']);
    // فحص مبدئي بدون قفل: يرفض المقاسات غير الموجودة في المخزن قبل أي قفل (لا أقفال فجوات)
    if ($stockErrors = stock_errors($v['lines'], current_stock($pdo, $itemIds, $wid))) {
        throw new ValidationException($stockErrors);
    }
    // تُقرأ قبل المعاملة حتى لا تُفتح قراءة غير مقفلة داخلها (توافق MariaDB 11.6+)
    $currency = app_setting('currency');
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($v, $wid, $userId, $itemIds, $currency) {
            lock_items_shared($pdo, $itemIds);
            $pairs = array_map(fn ($id) => [$id, $wid], $itemIds);
            $bal = lock_stock_rows($pdo, $pairs, false);
            $available = [];
            foreach ($v['lines'] as $l) {
                $available[(int) $l['item']['id']] = $bal[$l['item']['id'] . ':' . $wid];
            }
            if ($errors = stock_errors($v['lines'], $available, ' الآن')) {
                throw new ValidationException($errors);
            }

            $now = now();
            $docNo = counter_next($pdo, DOC_COUNTERS['sale']);
            $docId = insert_document($pdo, [
                'kind' => 'sale', 'doc_no' => $docNo,
                'warehouse_id' => $wid, 'warehouse_name' => $v['warehouse']['name'],
                'party_name' => $v['party_name'] ?: null, 'notes' => $v['notes'] ?: null,
                'line_count' => count($v['lines']), 'total_qty' => $v['total_qty'], 'total_volume_m3' => $v['total_m3'],
                'total_amount' => $v['total_amount'], 'currency' => $currency,
                'request_token' => $v['request_token'], 'request_hash' => $v['hash'],
                'created_at' => $now, 'created_by' => $userId,
            ]);
            foreach ($v['lines'] as $l) {
                $item = $l['item'];
                $before = $available[(int) $item['id']];
                $after = $before - $l['quantity'];
                insert_line($pdo, $docId, [
                    'line_no' => $l['line_no'], 'item_id' => $item['id'], 'wood_type_name' => $item['wood_type_name'],
                    'width_unit' => $item['width_unit'], 'thickness_unit' => $item['thickness_unit'], 'length_unit' => $item['length_unit'],
                    'quantity' => $l['quantity'], 'piece_volume_m3' => $l['piece_m3'], 'total_volume_m3' => $l['total_m3'],
                    'price_per_m3' => $l['price'], 'amount' => $l['amount'],
                    'balance_before' => $before, 'balance_after' => $after,
                ]);
                set_stock($pdo, (int) $item['id'], $wid, $after, $now);
            }
            data_version_bump($pdo);
            return ['id' => $docId, 'kind' => 'sale', 'doc_no' => $docNo, 'duplicate' => false];
        });
    } catch (ValidationException $e) {
        // طلب مكرر وصل متزامنًا ووجد الرصيد قد نفد بسبب الطلب الأول نفسه
        if ($dup = existing_request($pdo, $v['request_token'], 'sale', $v['hash'])) {
            return $dup;
        }
        throw $e;
    } catch (PDOException $e) {
        return translate_db_error($e, $pdo, $v['request_token'], 'sale', $v['hash']);
    }
}

/* ===================== التحويل بين المخازن ===================== */

function validate_transfer(PDO $pdo, array $in, bool $checkStock = true): array
{
    $errors = [];
    $from = catalog_find($pdo, 'warehouse', (int) ($in['warehouse_id'] ?? 0));
    if (!$from) {
        $errors['warehouse_id'] = 'اختر المخزن المحوَّل منه.';
    }
    $to = catalog_find($pdo, 'warehouse', (int) ($in['to_warehouse_id'] ?? 0));
    if (!$to) {
        $errors['to_warehouse_id'] = 'اختر المخزن المحوَّل إليه.';
    } elseif ($from && (int) $from['id'] === (int) $to['id']) {
        $errors['to_warehouse_id'] = 'اختر مخزنًا مختلفًا عن المخزن المحوَّل منه.';
    }
    $lines = validate_lines($pdo, $in['lines'] ?? [], false, $errors);
    $text = clean_text_fields($in, ['notes' => ['الملاحظات', 1000, true]], $errors);
    $token = check_token($in, $errors);
    if ($errors) {
        throw new ValidationException($errors);
    }
    if ($checkStock) {
        $avail = current_stock($pdo, array_map(fn ($l) => (int) $l['item']['id'], $lines), (int) $from['id']);
        if ($stockErrors = stock_errors($lines, $avail)) {
            throw new ValidationException($stockErrors);
        }
    }
    [$lines, $totQty, $totM3] = lines_with_figures($lines);
    return $text + [
        'from' => $from,
        'to' => $to,
        'lines' => $lines,
        'total_qty' => $totQty,
        'total_m3' => $totM3,
        'request_token' => $token,
        'hash' => request_hash([
            'transfer', (int) $from['id'], (int) $to['id'],
            array_values(array_map(fn ($l) => [(int) $l['item']['id'], $l['quantity']], $lines)),
            $text['notes'],
        ]),
    ];
}

function record_transfer(PDO $pdo, int $userId, array $in): array
{
    $v = validate_transfer($pdo, $in, false);
    if ($dup = existing_request($pdo, $v['request_token'], 'transfer', $v['hash'])) {
        return $dup;
    }
    $fromId = (int) $v['from']['id'];
    $toId = (int) $v['to']['id'];
    $itemIds = array_map(fn ($l) => (int) $l['item']['id'], $v['lines']);
    if ($stockErrors = stock_errors($v['lines'], current_stock($pdo, $itemIds, $fromId))) {
        throw new ValidationException($stockErrors);
    }
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($v, $fromId, $toId, $userId, $itemIds) {
            lock_items_shared($pdo, $itemIds);
            $pairs = [];
            foreach ($v['lines'] as $l) {
                $pairs[] = [(int) $l['item']['id'], $fromId];
                $pairs[] = [(int) $l['item']['id'], $toId];
            }
            $bal = lock_stock_rows($pdo, $pairs, true);
            $available = [];
            foreach ($v['lines'] as $l) {
                $available[(int) $l['item']['id']] = $bal[$l['item']['id'] . ':' . $fromId];
            }
            if ($errors = stock_errors($v['lines'], $available, ' الآن')) {
                throw new ValidationException($errors);
            }

            $now = now();
            $docNo = counter_next($pdo, DOC_COUNTERS['transfer']);
            $docId = insert_document($pdo, [
                'kind' => 'transfer', 'doc_no' => $docNo,
                'warehouse_id' => $fromId, 'warehouse_name' => $v['from']['name'],
                'to_warehouse_id' => $toId, 'to_warehouse_name' => $v['to']['name'],
                'notes' => $v['notes'] ?: null,
                'line_count' => count($v['lines']), 'total_qty' => $v['total_qty'], 'total_volume_m3' => $v['total_m3'],
                'request_token' => $v['request_token'], 'request_hash' => $v['hash'],
                'created_at' => $now, 'created_by' => $userId,
            ]);
            foreach ($v['lines'] as $l) {
                $item = $l['item'];
                $id = (int) $item['id'];
                $fromBefore = (int) $bal[$id . ':' . $fromId];
                $toBefore = (int) $bal[$id . ':' . $toId];
                $fromAfter = $fromBefore - $l['quantity'];
                $toAfter = $toBefore + $l['quantity'];
                if ($toAfter > MAX_STOCK) {
                    throw new ValidationException(['lines' => 'الرصيد الناتج في المخزن المحوَّل إليه أكبر من الحد المسموح.']);
                }
                insert_line($pdo, $docId, [
                    'line_no' => $l['line_no'], 'item_id' => $id, 'wood_type_name' => $item['wood_type_name'],
                    'width_unit' => $item['width_unit'], 'thickness_unit' => $item['thickness_unit'], 'length_unit' => $item['length_unit'],
                    'quantity' => $l['quantity'], 'piece_volume_m3' => $l['piece_m3'], 'total_volume_m3' => $l['total_m3'],
                    'balance_before' => $fromBefore, 'balance_after' => $fromAfter,
                    'to_balance_before' => $toBefore, 'to_balance_after' => $toAfter,
                ]);
                set_stock($pdo, $id, $fromId, $fromAfter, $now);
                set_stock($pdo, $id, $toId, $toAfter, $now);
            }
            data_version_bump($pdo);
            return ['id' => $docId, 'kind' => 'transfer', 'doc_no' => $docNo, 'duplicate' => false];
        });
    } catch (ValidationException $e) {
        if ($dup = existing_request($pdo, $v['request_token'], 'transfer', $v['hash'])) {
            return $dup;
        }
        throw $e;
    } catch (PDOException $e) {
        return translate_db_error($e, $pdo, $v['request_token'], 'transfer', $v['hash']);
    }
}

/* ===================== الإلغاء ===================== */

/**
 * يلغي المستند بالكامل مع الاحتفاظ به في السجل.
 * البيع يعيد الكميات، والوارد يخصمها، والتحويل يعكسها، بشرط ألا يصبح أي رصيد سالبًا.
 */
function cancel_document(PDO $pdo, int $userId, int $docId, string $reasonRaw): array
{
    $reason = clean_text($reasonRaw);
    if (mb_strlen($reason) > 255) {
        throw new ValidationException(['reason' => 'سبب الإلغاء أطول من المسموح (255 حرفًا).']);
    }
    // أسطر المستند لا تتغير أبدًا بعد الحفظ، فتُقرأ قبل المعاملة (لا قراءة غير مقفلة داخلها)
    $lines = document_lines($pdo, $docId);
    return db_transaction($pdo, function (PDO $pdo) use ($userId, $docId, $reason, $lines) {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? FOR UPDATE');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) {
            throw new ValidationException(['document' => 'المستند غير موجود.']);
        }
        if ($doc['status'] !== 'active') {
            throw new ValidationException(['document' => 'هذا المستند ملغى بالفعل.']);
        }
        lock_items_shared($pdo, array_map(fn ($l) => (int) $l['item_id'], $lines));

        $deltas = [];
        $add = function (array $l, int $wh, string $whName, int $delta) use (&$deltas) {
            $k = $l['item_id'] . ':' . $wh;
            $deltas[$k] ??= ['item' => (int) $l['item_id'], 'wh' => $wh, 'wh_name' => $whName, 'delta' => 0, 'line' => $l];
            $deltas[$k]['delta'] += $delta;
        };
        foreach ($lines as $l) {
            $q = (int) $l['quantity'];
            if ($doc['kind'] === 'in') {
                $add($l, (int) $doc['warehouse_id'], $doc['warehouse_name'], -$q);
            } elseif ($doc['kind'] === 'sale') {
                $add($l, (int) $doc['warehouse_id'], $doc['warehouse_name'], $q);
            } else {
                $add($l, (int) $doc['warehouse_id'], $doc['warehouse_name'], $q);
                $add($l, (int) $doc['to_warehouse_id'], (string) $doc['to_warehouse_name'], -$q);
            }
        }
        $bal = lock_stock_rows($pdo, array_map(fn ($d) => [$d['item'], $d['wh']], $deltas), true);

        $problems = [];
        foreach ($deltas as $k => $d) {
            $new = (int) $bal[$k] + $d['delta'];
            if ($new < 0) {
                $problems[] = sprintf(
                    'رصيد %s (%s) في %s الآن %s قطعة، والإلغاء يحتاج خصم %s قطعة.',
                    $d['line']['wood_type_name'],
                    fmt_size($d['line']),
                    $d['wh_name'],
                    fmt_int((int) $bal[$k]),
                    fmt_int(-$d['delta'])
                );
            } elseif ($new > MAX_STOCK) {
                $problems[] = 'الرصيد الناتج أكبر من الحد المسموح للصنف.';
            }
        }
        if ($problems) {
            throw new ValidationException(['document' => 'لا يمكن إلغاء هذا المستند: ' . implode(' ', $problems)]);
        }

        $now = now();
        foreach ($deltas as $k => $d) {
            set_stock($pdo, $d['item'], $d['wh'], (int) $bal[$k] + $d['delta'], $now);
        }
        $stmt = $pdo->prepare(
            "UPDATE documents SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ?
             WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$now, $userId, $reason !== '' ? $reason : null, $docId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Cancel update affected no rows');
        }
        data_version_bump($pdo);
        return $doc;
    });
}

/* ===================== استعلامات ===================== */

function find_document(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT d.*, cu.username AS created_by_name, xu.username AS cancelled_by_name
         FROM documents d
         JOIN users cu ON cu.id = d.created_by
         LEFT JOIN users xu ON xu.id = d.cancelled_by
         WHERE d.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** أسطر المستند مع أبعاد الصنف بالميكرومتر */
function document_lines(PDO $pdo, int $docId): array
{
    $stmt = $pdo->prepare(
        'SELECT l.*, i.width_um, i.thickness_um, i.length_um, i.wood_type_id
         FROM document_lines l JOIN items i ON i.id = l.item_id
         WHERE l.document_id = ? ORDER BY l.line_no'
    );
    $stmt->execute([$docId]);
    return $stmt->fetchAll();
}

function find_document_by_token(PDO $pdo, string $token): ?array
{
    if (!is_request_token($token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, kind, doc_no FROM documents WHERE request_token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}
