<?php
declare(strict_types=1);

/*
 * المتوسط المرجح وفواتير البيع والوارد مع طريقة الدفع (ACCOUNTING_SPEC.md البندان 2 و3).
 * التشغيل: WOOD_TEST_DB=wood_acct_a php tests/integration_sales_purchases.php
 */

require __DIR__ . '/lib.php';

$pdo = fresh_database();
$uid = seed_user($pdo);
$wh1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$wh2 = catalog_create($pdo, 'warehouse', 'مخزن الفرع');
$type = catalog_create($pdo, 'type', 'موسكي');
$box = (int) $pdo->query('SELECT id FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn();

function make_party(PDO $pdo, string $kind, string $name, ?string $limit = null): int
{
    $pdo->prepare('INSERT INTO parties (kind, name, name_key, credit_limit, created_at) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$kind, $name, name_key($name), $limit]);
    return (int) $pdo->lastInsertId();
}

function item_v(PDO $pdo, int $item): string
{
    $s = $pdo->prepare('SELECT inventory_value FROM items WHERE id = ?');
    $s->execute([$item]);
    return (string) $s->fetchColumn();
}

function item_q(PDO $pdo, int $item): int
{
    $s = $pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock WHERE item_id = ?');
    $s->execute([$item]);
    return (int) $s->fetchColumn();
}

function doc_row(PDO $pdo, int $id): array
{
    return find_document($pdo, $id);
}

function line_row(PDO $pdo, int $id): array
{
    return document_lines($pdo, $id)[0];
}

function money(int $p): string
{
    return piasters_to_money($p);
}

$s1 = make_party($pdo, 'supplier', 'مورد أول');
$s2 = make_party($pdo, 'supplier', 'مورد ثان');
$c1 = make_party($pdo, 'customer', 'عميل أول');
$c2 = make_party($pdo, 'customer', 'عميل بحد ائتمان', '1000.00');

/* ---------------------------------------------------------------- */
section('المتوسط المرجح: وارد بتكلفة ثم بيع');
$r1 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '3', 'm', '10',
    ['cost_per_m3' => '20000', 'party_id' => (string) $s1, 'payment_type' => 'credit']));
$a = item_id_for($pdo, $type, 100000, 50000, 3000000);
$d = doc_row($pdo, $r1['id']);
check_eq('وارد 0.15 م³ × 20000: قيمة السطر 3000.00', '3000.00', (string) line_row($pdo, $r1['id'])['cost_amount']);
check_eq('  تكلفة المتر محفوظة', '20000.00', (string) line_row($pdo, $r1['id'])['cost_per_m3']);
check_eq('  total_cost للمستند 3000.00', '3000.00', (string) $d['total_cost']);
check_eq('  V = 3000.00', '3000.00', item_v($pdo, $a));
check_eq('  آجل: المدفوع صفر وبلا خزنة', ['credit', '0.00', null], [$d['payment_type'], (string) $d['paid_amount'], $d['cash_box_id']]);
check_eq('  المورد +3000.00', 300000, party_balance($pdo, $s1));
check_eq('  اسم المورد محفوظ مع المستند', 'مورد أول', $d['party_name']);

$r2 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '3', 'm', '10',
    ['cost_per_m3' => '30000', 'party_id' => (string) $s1, 'payment_type' => 'credit']));
check_eq('وارد ثان 10 قطع × 30000: +4500.00', '4500.00', (string) line_row($pdo, $r2['id'])['cost_amount']);
check_eq('  V = 7500.00 و Q = 20', ['7500.00', 20], [item_v($pdo, $a), item_q($pdo, $a)]);

$sa = record_sale($pdo, $uid, sale_input($wh1, [[$a, 5, '40000']]));
$sd = doc_row($pdo, $sa['id']);
check_eq('بيع 5 قطع: COGS = 7500 × 5 ÷ 20 = 1875.00', '1875.00', (string) line_row($pdo, $sa['id'])['cost_amount']);
check_eq('  total_cost للفاتورة', '1875.00', (string) $sd['total_cost']);
check_eq('  V = 5625.00', '5625.00', item_v($pdo, $a));
check_eq('  نقدي بدون عميل: الخزنة +3000.00', 300000, cash_balance($pdo, $box));
check_eq('  الفاتورة: نقدي، المدفوع = الإجمالي، والخزنة محفوظة', ['cash', '3000.00', $box, 'الخزنة الرئيسية', null],
    [$sd['payment_type'], (string) $sd['paid_amount'], (int) $sd['cash_box_id'], $sd['cash_box_name'], $sd['party_id']]);
$sb = record_sale($pdo, $uid, sale_input($wh1, [[$a, 15, '40000']]));
check_eq('بيع كل الباقي 15: COGS = 5625.00 بالضبط', '5625.00', (string) line_row($pdo, $sb['id'])['cost_amount']);
check_eq('  V = 0.00 تمامًا و Q = 0', ['0.00', 0], [item_v($pdo, $a), item_q($pdo, $a)]);
check_eq('  الخزنة 12000.00', 1200000, cash_balance($pdo, $box));

section('وارد بدون تكلفة');
$r3 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '3', 'm', '10',
    ['cost_per_m3' => '20000', 'party_id' => (string) $s1, 'payment_type' => 'credit']));
$r4 = record_receipt($pdo, $uid, receipt_input($wh2, $type, '10', 'cm', '50', 'mm', '3', 'm', '5'));
$l4 = line_row($pdo, $r4['id']);
check_eq('Q > 0: الوارد يُقيَّم بالمتوسط 3000 × 5 ÷ 10 = 1500.00', '1500.00', (string) $l4['cost_amount']);
check_eq('  تكلفة المتر NULL', null, $l4['cost_per_m3']);
check_eq('  بدون تكلفة: لا طريقة دفع', [null, null, null], [doc_row($pdo, $r4['id'])['payment_type'], doc_row($pdo, $r4['id'])['paid_amount'], doc_row($pdo, $r4['id'])['cash_box_id']]);
check_eq('  V = 4500.00 و Q = 15 (في مخزنين)', ['4500.00', 15], [item_v($pdo, $a), item_q($pdo, $a)]);
$r5 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '9', 'cm', '9', 'cm', '9', 'm', '4'));
$i9 = item_id_for($pdo, $type, 90000, 90000, 9000000);
check_eq('Q = 0: مخزون بلا قيمة (0.00)', ['0.00', '0.00'], [(string) line_row($pdo, $r5['id'])['cost_amount'], item_v($pdo, $i9)]);
check('  يظهر في التقييم الافتتاحي', in_array($i9, array_map(fn ($r) => (int) $r['id'], unvalued_items($pdo)), true));

section('إلغاء البيع يعيد القيمة');
$sc = record_sale($pdo, $uid, sale_input($wh1, [[$a, 3, '40000']]));
check_eq('بيع 3 من 15: COGS = 900.00', '900.00', (string) line_row($pdo, $sc['id'])['cost_amount']);
check_eq('  V = 3600.00', '3600.00', item_v($pdo, $a));
cancel_document($pdo, $uid, $sc['id'], 'تجربة');
check_eq('الإلغاء يعيد V = 4500.00 و Q = 15', ['4500.00', 15], [item_v($pdo, $a), item_q($pdo, $a)]);
check_eq('  والخزنة رجعت 12000.00', 1200000, cash_balance($pdo, $box));

section('إلغاء وارد بعد بيع جزء: فرق التكلفة');
// صنف ب: 100 قطعة × 0.02 = 2 م³ × 500 = 1000.00، ثم 10 قطع = 0.2 م³ × 45000 = 9000.00
$rb1 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '5', 'cm', '4', 'm', '100',
    ['cost_per_m3' => '500', 'party_id' => (string) $s1, 'payment_type' => 'credit']));
$b = item_id_for($pdo, $type, 100000, 50000, 4000000);
$rb2 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '5', 'cm', '4', 'm', '10',
    ['cost_per_m3' => '45000', 'party_id' => (string) $s1, 'payment_type' => 'credit']));
check_eq('V = 10000.00 و Q = 110', ['10000.00', 110], [item_v($pdo, $b), item_q($pdo, $b)]);
$sbb = record_sale($pdo, $uid, sale_input($wh1, [[$b, 50, '1000']]));
check_eq('بيع 50: COGS = 1000000 × 50 ÷ 110 = 4545.45', '4545.45', (string) line_row($pdo, $sbb['id'])['cost_amount']);
check_eq('  V = 5454.55', '5454.55', item_v($pdo, $b));
$s1Before = party_balance($pdo, $s1);
cancel_document($pdo, $uid, $rb2['id'], '');
check_eq('إلغاء الوارد الثاني: V = 0.00 (لا تصبح سالبة) و Q = 50', ['0.00', 50], [item_v($pdo, $b), item_q($pdo, $b)]);
$adj = $pdo->query('SELECT item_id, document_id, amount, reason FROM cost_adjustments ORDER BY id DESC LIMIT 1')->fetch();
check_eq('  فرق التكلفة مسجل: 5454.55 − 9000.00 = −3545.45', [$b, (int) $rb2['id'], '-3545.45'], [(int) $adj['item_id'], (int) $adj['document_id'], (string) $adj['amount']]);
check('  سبب الفرق بالعربية', str_contains($adj['reason'], 'وارد رقم'), $adj['reason']);
check_eq('  قيد المورد عُكس (−9000.00)', $s1Before - 900000, party_balance($pdo, $s1));

// صنف ج: نفاد الكمية مع بقاء قيمة
$rc1 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '5', 'm', '10',
    ['cost_per_m3' => '20000', 'party_id' => (string) $s1, 'payment_type' => 'credit'])); // 0.25 م³ = 5000.00
$c = item_id_for($pdo, $type, 100000, 50000, 5000000);
record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '5', 'm', '10',
    ['cost_per_m3' => '60000', 'party_id' => (string) $s1, 'payment_type' => 'credit'])); // 15000.00
record_sale($pdo, $uid, sale_input($wh1, [[$c, 10, '100000']])); // COGS 10000.00
check_eq('صنف ج: V = 10000.00 و Q = 10', ['10000.00', 10], [item_v($pdo, $c), item_q($pdo, $c)]);
cancel_document($pdo, $uid, $rc1['id'], '');
check_eq('إلغاء الوارد الأول: Q = 0 فتُضبط V = 0.00', ['0.00', 0], [item_v($pdo, $c), item_q($pdo, $c)]);
check_eq('  فرق التكلفة +5000.00 (خسارة)', '5000.00', (string) $pdo->query('SELECT amount FROM cost_adjustments ORDER BY id DESC LIMIT 1')->fetchColumn());

section('فواتير البيع: نقدي وآجل وجزئي');
record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '2', 'm', '200'));
$dd = item_id_for($pdo, $type, 100000, 50000, 2000000); // القطعة 0.01 م³
$boxBefore = cash_balance($pdo, $box);
$cash = record_sale($pdo, $uid, sale_input($wh1, [[$dd, 10, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'cash']));
check_eq('نقدي لعميل: رصيده 0.00 بعد قيدين', 0, party_balance($pdo, $c1));
check_eq('  قيود العميل: +1000.00 ثم −1000.00', ['1000.00', '-1000.00'],
    $pdo->query('SELECT amount FROM party_ledger WHERE document_id = ' . (int) $cash['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check_eq('  الخزنة +1000.00', $boxBefore + 100000, cash_balance($pdo, $box));
check_eq('  اسم العميل من الحساب', 'عميل أول', doc_row($pdo, $cash['id'])['party_name']);
$credit = record_sale($pdo, $uid, sale_input($wh1, [[$dd, 5, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'credit']));
check_eq('آجل: العميل 500.00 عليه والخزنة لم تتغير', [50000, $boxBefore + 100000], [party_balance($pdo, $c1), cash_balance($pdo, $box)]);
check_eq('  الفاتورة: مدفوع 0.00 بلا خزنة', ['0.00', null], [(string) doc_row($pdo, $credit['id'])['paid_amount'], doc_row($pdo, $credit['id'])['cash_box_id']]);
$partial = record_sale($pdo, $uid, sale_input($wh1, [[$dd, 20, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'partial', 'paid_amount' => '750.50']));
check_eq('جزئي 2000.00 دُفع منه 750.50: العميل 1749.50', 174950, party_balance($pdo, $c1));
check_eq('  الخزنة +750.50', $boxBefore + 100000 + 75050, cash_balance($pdo, $box));
check_eq('  الفاتورة: مدفوع 750.50', '750.50', (string) doc_row($pdo, $partial['id'])['paid_amount']);
$e = expect_validation('جزئي والمدفوع = الإجمالي مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 20, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'partial', 'paid_amount' => '2000'])));
check('  الخطأ على المبلغ المدفوع', isset($e['paid_amount']), json_encode($e, JSON_UNESCAPED_UNICODE));
expect_validation('جزئي والمدفوع أكبر من الإجمالي مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 20, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'partial', 'paid_amount' => '2500'])));
expect_validation('جزئي بدون مبلغ مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 20, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'partial', 'paid_amount' => ''])));
$e = expect_validation('آجل بدون عميل مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['payment_type' => 'credit'])));
check('  الخطأ على العميل', isset($e['party_id']));
expect_validation('جزئي بدون عميل مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 2, '10000']], ['payment_type' => 'partial', 'paid_amount' => '50'])));
expect_validation('طريقة دفع غير معروفة مرفوضة', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['payment_type' => 'gift'])));
expect_validation('عميل من نوع مورد مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['party_id' => (string) $s1, 'payment_type' => 'credit'])));

section('حد الائتمان');
record_sale($pdo, $uid, sale_input($wh1, [[$dd, 10, '10000']], ['party_id' => (string) $c2, 'payment_type' => 'credit']));
check_eq('آجل حتى الحد بالضبط مقبول (1000.00)', 100000, party_balance($pdo, $c2));
$stockBefore = stock_qty($pdo, $dd, $wh1);
$e = expect_validation('تجاوز حد الائتمان مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['party_id' => (string) $c2, 'payment_type' => 'credit'])));
check('  الرسالة تذكر المتاح', str_contains($e['party_id'] ?? '', 'المتاح'), $e['party_id'] ?? '');
check_eq('  لا تغيير في الرصيد أو المخزون', [100000, $stockBefore], [party_balance($pdo, $c2), stock_qty($pdo, $dd, $wh1)]);
record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['party_id' => (string) $c2, 'payment_type' => 'cash']));
check_eq('البيع النقدي لنفس العميل مسموح', 100000, party_balance($pdo, $c2));

section('الوارد: شراء نقدي وآجل وجزئي');
$boxBefore = cash_balance($pdo, $box);
$p1 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '20',
    ['cost_per_m3' => '10000', 'party_id' => (string) $s2, 'payment_type' => 'cash'])); // 0.1 م³ = 1000.00
check_eq('شراء نقدي من مورد: رصيده 0.00 والخزنة −1000.00', [0, $boxBefore - 100000], [party_balance($pdo, $s2), cash_balance($pdo, $box)]);
check_eq('  قيود المورد: +1000.00 ثم −1000.00', ['1000.00', '-1000.00'],
    $pdo->query('SELECT amount FROM party_ledger WHERE document_id = ' . (int) $p1['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check_eq('  قيد الخزنة −1000.00', ['-1000.00'], $pdo->query('SELECT amount FROM cash_ledger WHERE document_id = ' . (int) $p1['id'])->fetchAll(PDO::FETCH_COLUMN));
record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '10',
    ['cost_per_m3' => '10000', 'party_id' => (string) $s2, 'payment_type' => 'credit']));
check_eq('شراء آجل: المورد 500.00 له', 50000, party_balance($pdo, $s2));
$p3 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '20',
    ['cost_per_m3' => '10000', 'party_id' => (string) $s2, 'payment_type' => 'partial', 'paid_amount' => '400']));
check_eq('شراء جزئي 1000.00 دُفع 400.00: المورد 1100.00 والخزنة −400.00', [110000, $boxBefore - 140000], [party_balance($pdo, $s2), cash_balance($pdo, $box)]);
check_eq('  المستند: مدفوع 400.00', '400.00', (string) doc_row($pdo, $p3['id'])['paid_amount']);
$p4 = record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '10', ['cost_per_m3' => '10000']));
check_eq('شراء نقدي بدون حساب مورد: الخزنة −500.00 فقط', $boxBefore - 190000, cash_balance($pdo, $box));
check_eq('  بلا قيود مورد', 0, (int) $pdo->query('SELECT COUNT(*) FROM party_ledger WHERE document_id = ' . (int) $p4['id'])->fetchColumn());
expect_validation('شراء آجل بدون مورد مرفوض', fn () => record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '10', ['cost_per_m3' => '10000', 'payment_type' => 'credit'])));
$e5 = item_id_for($pdo, $type, 100000, 50000, 1000000);
$q5 = item_q($pdo, $e5);
$docsBefore = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$e = expect_validation('شراء نقدي أكبر من رصيد الخزنة مرفوض', fn () => record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '1000', ['cost_per_m3' => '1000000'])));
check('  الخطأ على الخزنة', isset($e['cash_box_id']), json_encode($e, JSON_UNESCAPED_UNICODE));
check_eq('  لا مستند ولا مخزون ولا تغيير في الخزنة', [$docsBefore, $q5, $boxBefore - 190000],
    [(int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(), item_q($pdo, $e5), cash_balance($pdo, $box)]);

section('إلغاء بيع نقدي والخزنة لا تكفي للرد');
$pdo->prepare('INSERT INTO cash_boxes (name, name_key, created_at) VALUES (?, ?, NOW())')->execute(['خزنة فرعية', name_key('خزنة فرعية')]);
$box2 = (int) $pdo->lastInsertId();
$small = record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['cash_box_id' => (string) $box2])); // 100.00
check_eq('البيع دخل الخزنة الفرعية 100.00', 10000, cash_balance($pdo, $box2));
record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '2', ['cost_per_m3' => '10000', 'cash_box_id' => (string) $box2])); // 100.00
check_eq('شراء نقدي صرف كل رصيدها', 0, cash_balance($pdo, $box2));
$vBefore = item_v($pdo, $dd);
$qBefore = item_q($pdo, $dd);
$e = expect_validation('إلغاء البيع مرفوض', fn () => cancel_document($pdo, $uid, $small['id'], ''));
check('  الرسالة: رصيد الخزنة لا يكفي لرد المبلغ', str_contains($e['document'] ?? '', 'رصيد الخزنة لا يكفي لرد المبلغ'), $e['document'] ?? '');
check_eq('  لا تغيير: الحالة والمخزون والقيمة', ['active', $qBefore, $vBefore], [doc_row($pdo, $small['id'])['status'], item_q($pdo, $dd), item_v($pdo, $dd)]);
cancel_document($pdo, $uid, $credit['id'], '');
check_eq('إلغاء بيع آجل يخفض رصيد العميل (1749.50 − 500.00)', 124950, party_balance($pdo, $c1));

section('تكرار الطلب مع الحقول الجديدة');
$in = sale_input($wh1, [[$dd, 2, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'partial', 'paid_amount' => '50']);
$first = record_sale($pdo, $uid, $in);
$again = record_sale($pdo, $uid, $in);
check('نفس الرمز ونفس المحتوى يعيد نفس الفاتورة', $again['duplicate'] && $again['id'] === $first['id']);
$changed = $in;
$changed['paid_amount'] = '60';
$e = expect_validation('نفس الرمز بمبلغ مدفوع مختلف يُرفض', fn () => record_sale($pdo, $uid, $changed));
check('  الخطأ على رمز الطلب', isset($e['request_token']));
$changed = $in;
$changed['payment_type'] = 'credit';
expect_validation('نفس الرمز بطريقة دفع مختلفة يُرفض', fn () => record_sale($pdo, $uid, $changed));
$rin = receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '1', ['cost_per_m3' => '10000', 'party_id' => (string) $s2, 'payment_type' => 'credit']);
$ra = record_receipt($pdo, $uid, $rin);
$rb = record_receipt($pdo, $uid, $rin);
check('الوارد: نفس الرمز ونفس المحتوى لا يتكرر', $rb['duplicate'] && $rb['id'] === $ra['id']);
$rin['cost_per_m3'] = '11000';
expect_validation('الوارد: نفس الرمز بتكلفة مختلفة يُرفض', fn () => record_receipt($pdo, $uid, $rin));

section('التاريخ اليدوي');
$past = date('Y-m-d', time() - 86400 * 3);
$md = record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['party_id' => (string) $c1, 'payment_type' => 'credit', 'doc_date' => $past]));
$mdd = doc_row($pdo, $md['id']);
check_eq('تاريخ سابق للمدير مقبول', $past, substr($mdd['doc_date'], 0, 10));
check_eq('  وقت الإنشاء هو الآن', date('Y-m-d'), substr($mdd['created_at'], 0, 10));
check_eq('  تاريخ القيد = تاريخ المستند', $mdd['doc_date'], $pdo->query('SELECT entry_date FROM party_ledger WHERE document_id = ' . (int) $md['id'])->fetchColumn());
$e = expect_validation('تاريخ في المستقبل مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['doc_date' => date('Y-m-d', time() + 86400 * 2)])));
check('  الخطأ على التاريخ', isset($e['doc_date']));
expect_validation('الوارد: تاريخ في المستقبل مرفوض', fn () => record_receipt($pdo, $uid, receipt_input($wh1, $type, '10', 'cm', '50', 'mm', '1', 'm', '1', ['doc_date' => date('Y-m-d', time() + 86400)])));
save_setting($pdo, 'closing_date', date('Y-m-d', time() - 86400));
reset_settings_cache();
expect_validation('تاريخ في فترة مقفلة مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']], ['doc_date' => $past])));
$e = expect_validation('إلغاء مستند تاريخه في فترة مقفلة مرفوض', fn () => cancel_document($pdo, $uid, $md['id'], ''));
check('  الرسالة تذكر الفترة المقفلة', str_contains($e['document'] ?? '', 'فترة مقفلة'), $e['document'] ?? '');
$today = record_sale($pdo, $uid, sale_input($wh1, [[$dd, 1, '10000']]));
check('التسجيل بتاريخ اليوم مسموح بعد إقفال الأمس', $today['id'] > 0);
save_setting($pdo, 'closing_date', '');
reset_settings_cache();

section('التزامن: بيعان متزامنان لنفس الصنف');
// 7 سم × 3 سم × 3 م = 0.0063 م³ للقطعة؛ 30 قطعة = 0.189 م³ × 12345.67 = 2333.33
record_receipt($pdo, $uid, receipt_input($wh1, $type, '7', 'cm', '3', 'cm', '3', 'm', '30',
    ['cost_per_m3' => '12345.67', 'party_id' => (string) $s1, 'payment_type' => 'credit']));
$f = item_id_for($pdo, $type, 70000, 30000, 3000000);
check_eq('V0 = 2333.33 و Q = 30', ['2333.33', 30], [item_v($pdo, $f), item_q($pdo, $f)]);
$v0 = money_to_piasters(item_v($pdo, $f));
$root = root_pdo();
$root->beginTransaction();
$root->prepare('SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE')->execute([$f, $wh1]);
$procs = [];
$pipes = [];
foreach ([7, 11] as $k => $qty) {
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', 'sale', (string) $wh1, (string) $f, (string) $qty, '20000'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
}
$waiting = 0;
for ($t = 0; $t < 100 && $waiting < 2; $t++) {
    usleep(100000);
    $waiting = (int) $root->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
}
check('العمليتان تنتظران القفل فعلًا', $waiting === 2, "waiting={$waiting}");
$root->commit();
$ids = [];
foreach ($procs as $k => $p) {
    $r = json_decode(trim(stream_get_contents($pipes[$k][1])), true);
    proc_close($p);
    check('  البيع نجح', $r['ok'] ?? false, json_encode($r, JSON_UNESCAPED_UNICODE));
    $ids[] = (int) ($r['id'] ?? 0);
}
$vf = money_to_piasters(item_v($pdo, $f));
check_eq('Q = 30 − 7 − 11 = 12', 12, item_q($pdo, $f));
$cogs = array_sum(array_map(fn ($id) => money_to_piasters((string) line_row($pdo, $id)['cost_amount']), $ids));
check_eq('مجموع COGS = نقص V', $v0 - $vf, $cogs);
// الترتيب الفعلي (بالمعرّف) يحدد الأرقام: أول بيع من 30 ثم الثاني من الباقي
sort($ids);
$q1 = (int) line_row($pdo, $ids[0])['quantity'];
$exp1 = muldiv_half_up($v0, $q1, 30);
$exp2 = muldiv_half_up($v0 - $exp1, 18 - $q1, 30 - $q1);
check_eq('COGS لكل فاتورة بالمتوسط المتحرك بالترتيب الفعلي', [$exp1, $exp2],
    [money_to_piasters((string) line_row($pdo, $ids[0])['cost_amount']), money_to_piasters((string) line_row($pdo, $ids[1])['cost_amount'])]);
check_eq('V النهائية', $v0 - $exp1 - $exp2, $vf);

section('التقييم الافتتاحي');
$res = set_opening_valuation($pdo, $uid, $i9, '10000'); // 4 قطع × 0.0729 = 0.2916 م³ × 10000 = 2916.00
check_eq('V = 2916.00', '2916.00', item_v($pdo, $i9));
check_eq('  النتيجة', ['item_id' => $i9, 'qty' => 4, 'value' => 291600], $res);
expect_validation('التقييم مرة ثانية مرفوض (القيمة ليست صفرًا)', fn () => set_opening_valuation($pdo, $uid, $i9, '5000'));
expect_validation('تكلفة صفر مرفوضة', fn () => set_opening_valuation($pdo, $uid, $dd, '0'));

section('سلامة الأرصدة');
check_eq('acct_verify_balances() = []', [], acct_verify_balances($pdo));
$neg = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE inventory_value < 0')->fetchColumn();
check_eq('لا قيمة مخزون سالبة', 0, $neg);

finish();
