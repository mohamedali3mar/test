<?php
/* اختبار تقارير الحسابات ولوحة التحكم (app/lib/acct_reports.php) على بيانات محسوبة يدويًا */
declare(strict_types=1);
require __DIR__ . '/lib.php';
$pdo = fresh_database();
$uid = seed_user($pdo);

/* ---------------- البيانات ---------------- */
$w1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$w2 = catalog_create($pdo, 'warehouse', 'مخزن الفرع');
$w3 = catalog_create($pdo, 'warehouse', 'مخزن ثالث');
$t1 = catalog_create($pdo, 'type', 'زان');
$t2 = catalog_create($pdo, 'type', 'موسكي');

function mk_party(PDO $pdo, string $kind, string $name, string $opening = '0.00'): int
{
    $pdo->prepare('INSERT INTO parties (kind, name, name_key, phone, opening_balance, balance, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$kind, $name, name_key($name), '0100', $opening, $opening]);
    return (int) $pdo->lastInsertId();
}
$c1 = mk_party($pdo, 'customer', 'عميل أ');
$c2 = mk_party($pdo, 'customer', 'عميل ب', '50.00');
$c3 = mk_party($pdo, 'customer', 'عميل صفري');
$s1 = mk_party($pdo, 'supplier', 'مورد أ');
$s2 = mk_party($pdo, 'supplier', 'مورد ب');

$b1 = (int) $pdo->query('SELECT id FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('UPDATE cash_boxes SET opening_balance = 5000.00, balance = 5000.00 WHERE id = ?')->execute([$b1]);
$pdo->prepare('INSERT INTO cash_boxes (name, name_key, created_at) VALUES (?, ?, NOW())')->execute(['خزنة الفرع', name_key('خزنة الفرع')]);
$b2 = (int) $pdo->lastInsertId();
$catRent = (int) $pdo->query("SELECT id FROM expense_categories WHERE name = 'إيجار'")->fetchColumn();
$catPower = (int) $pdo->query("SELECT id FROM expense_categories WHERE name = 'كهرباء ومياه'")->fetchColumn();

// الأصناف: أ (زان 0.015 م³)، ب (موسكي 0.016)، ج (زان 0.01: قطعة في كل مخزن)، د (موسكي 0.005 بدون تكلفة)
$rA = (int) record_receipt($pdo, $uid, receipt_input($w1, $t1, '10', 'cm', '50', 'mm', '3', 'm', '100'))['id'];
$rB = (int) record_receipt($pdo, $uid, receipt_input($w2, $t2, '20', 'cm', '2', 'cm', '4', 'm', '50'))['id'];
foreach ([$w1, $w2, $w3] as $w) {
    record_receipt($pdo, $uid, receipt_input($w, $t1, '10', 'cm', '10', 'cm', '1', 'm', '1'));
}
record_receipt($pdo, $uid, receipt_input($w3, $t2, '5', 'cm', '5', 'cm', '2', 'm', '5'));
$itemA = item_id_for($pdo, $t1, 100000, 50000, 3000000);
$itemB = item_id_for($pdo, $t2, 200000, 20000, 4000000);
$itemC = item_id_for($pdo, $t1, 100000, 100000, 1000000);
$itemD = item_id_for($pdo, $t2, 50000, 50000, 2000000);
record_transfer($pdo, $uid, transfer_input($w1, $w2, [[$itemA, 10]]));

$sale = fn (int $wh, array $lines) => (int) record_sale($pdo, $uid, sale_input($wh, $lines))['id'];
$d1 = $sale($w1, [[$itemA, 10, '1000']]);
$d2 = $sale($w1, [[$itemA, 20, '1200']]);
$d3 = $sale($w2, [[$itemB, 5, '2000']]);
$d4 = $sale($w2, [[$itemA, 4, '1000'], [$itemB, 5, '1500']]);
$d5 = $sale($w2, [[$itemB, 10, '1000']]);
cancel_document($pdo, $uid, $d5, 'خطأ في الفاتورة');
check_eq('إجماليات فواتير البيع كما حُسبت يدويًا', ['150.00', '360.00', '160.00', '180.00', '160.00'],
    $pdo->query("SELECT total_amount FROM documents WHERE kind = 'sale' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

// خدمات البيع تسجل الآن قيود الدفع والتكلفة تلقائيًا (بيع نقدي افتراضي). هذا الاختبار يبني الدفاتر
// والتكاليف يدويًا بأرقام محسوبة مسبقًا، فتُمسح آثار الخدمات المحاسبية أولًا (المخزون والمستندات تبقى)
$pdo->exec('DELETE FROM cash_ledger');
$pdo->exec('DELETE FROM party_ledger');
$pdo->exec('DELETE FROM cost_adjustments');
$pdo->exec('UPDATE cash_boxes SET balance = opening_balance');
$pdo->exec('UPDATE parties SET balance = opening_balance');
$pdo->exec('UPDATE documents SET payment_type = NULL, paid_amount = NULL, cash_box_id = NULL, cash_box_name = NULL, total_cost = NULL, party_id = NULL');
$pdo->exec('UPDATE document_lines SET cost_amount = NULL, cost_per_m3 = NULL');
$pdo->exec('UPDATE items SET inventory_value = 0');

function upd_doc(PDO $pdo, int $id, array $cols): void
{
    $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($cols)));
    $pdo->prepare("UPDATE documents SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
}
function line_cost(PDO $pdo, int $doc, int $item, string $cost, ?string $perM3 = null): void
{
    $pdo->prepare('UPDATE document_lines SET cost_amount = ?, cost_per_m3 = ? WHERE document_id = ? AND item_id = ?')->execute([$cost, $perM3, $doc, $item]);
}
upd_doc($pdo, $rA, ['doc_date' => '2026-08-01 08:00:00', 'party_id' => $s1, 'payment_type' => 'credit', 'paid_amount' => '0.00', 'total_cost' => '1200.00']);
line_cost($pdo, $rA, $itemA, '1200.00', '800.00');
upd_doc($pdo, $rB, ['doc_date' => '2026-08-02 08:00:00', 'party_id' => $s2, 'payment_type' => 'partial', 'paid_amount' => '400.00', 'cash_box_id' => $b1, 'cash_box_name' => 'الخزنة الرئيسية', 'total_cost' => '1000.00']);
line_cost($pdo, $rB, $itemB, '1000.00', '1250.00');
upd_doc($pdo, $d1, ['doc_date' => '2026-08-15 10:00:00', 'party_id' => $c1, 'payment_type' => 'credit', 'paid_amount' => '0.00', 'total_cost' => '120.00']);
line_cost($pdo, $d1, $itemA, '120.00');
upd_doc($pdo, $d2, ['doc_date' => '2026-09-01 09:00:00', 'party_id' => $c2, 'payment_type' => 'partial', 'paid_amount' => '100.00', 'cash_box_id' => $b1, 'cash_box_name' => 'الخزنة الرئيسية', 'total_cost' => '240.00']);
line_cost($pdo, $d2, $itemA, '240.00');
upd_doc($pdo, $d3, ['doc_date' => '2026-09-01 15:00:00', 'payment_type' => 'cash', 'paid_amount' => '160.00', 'cash_box_id' => $b1, 'cash_box_name' => 'الخزنة الرئيسية', 'total_cost' => '100.00']);
line_cost($pdo, $d3, $itemB, '100.00');
upd_doc($pdo, $d4, ['doc_date' => '2026-09-20 11:00:00', 'party_id' => $c1, 'payment_type' => 'credit', 'paid_amount' => '0.00', 'total_cost' => '148.00']);
line_cost($pdo, $d4, $itemA, '48.00');
line_cost($pdo, $d4, $itemB, '100.00');
upd_doc($pdo, $d5, ['doc_date' => '2026-09-10 12:00:00', 'party_id' => $c2, 'payment_type' => 'credit', 'paid_amount' => '0.00', 'total_cost' => '200.00']);
line_cost($pdo, $d5, $itemB, '200.00');
foreach ([$itemA => '792.00', $itemB => '800.00', $itemC => '100.00', $itemD => '0.00'] as $it => $v) {
    $pdo->prepare('UPDATE items SET inventory_value = ? WHERE id = ?')->execute([$v, $it]);
}

function mk_voucher(PDO $pdo, int $uid, string $kind, int $no, string $date, ?int $party, int $box, ?int $cat, string $amount): int
{
    $pdo->prepare('INSERT INTO vouchers (kind, doc_no, voucher_date, party_id, cash_box_id, cash_box_name, category_id, category_name, amount, currency,
            request_token, request_hash, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$kind, $no, $date, $party, $box, 'خزنة', $cat, $cat ? 'تصنيف' : null, $amount, 'جنيه مصري',
            new_request_token(), hash('sha256', (string) mt_rand()), $date, $uid]);
    return (int) $pdo->lastInsertId();
}
/** قيد في دفتر (عبر ledger_party / ledger_cash حتى تبقى الأرصدة المخزنة سليمة) */
function led(PDO $pdo, string $which, int $owner, string $date, ?int $doc, ?int $v, string $type, int $amount): void
{
    db_transaction($pdo, function (PDO $pdo) use ($which, $owner, $date, $doc, $v, $type, $amount) {
        $which === 'party'
            ? ledger_party($pdo, $owner, $date, $doc, $v, $type, $amount, 'اختبار')
            : ledger_cash($pdo, $owner, $date, $doc, $v, $type, $amount, 'اختبار');
    });
}
led($pdo, 'party', $s1, '2026-08-01 08:00:00', $rA, null, 'purchase', 120000);
led($pdo, 'party', $s2, '2026-08-02 08:00:00', $rB, null, 'purchase', 100000);
led($pdo, 'party', $s2, '2026-08-02 08:00:00', $rB, null, 'purchase_payment', -40000);
led($pdo, 'cash', $b1, '2026-08-02 08:00:00', $rB, null, 'purchase', -40000);
led($pdo, 'party', $c1, '2026-08-15 10:00:00', $d1, null, 'sale', 15000);
$e1 = mk_voucher($pdo, $uid, 'expense', 1, '2026-08-20 09:00:00', null, $b1, $catRent, '500.00');
led($pdo, 'cash', $b1, '2026-08-20 09:00:00', null, $e1, 'expense', -50000);
led($pdo, 'party', $c2, '2026-09-01 09:00:00', $d2, null, 'sale', 36000);
led($pdo, 'party', $c2, '2026-09-01 09:00:00', $d2, null, 'sale_payment', -10000);
led($pdo, 'cash', $b1, '2026-09-01 09:00:00', $d2, null, 'sale', 10000);
led($pdo, 'cash', $b1, '2026-09-01 15:00:00', $d3, null, 'sale', 16000);
$v1 = mk_voucher($pdo, $uid, 'collect', 1, '2026-09-04 10:00:00', $c1, $b2, null, '100.00');
led($pdo, 'party', $c1, '2026-09-04 10:00:00', null, $v1, 'collect', -10000);
led($pdo, 'cash', $b2, '2026-09-04 10:00:00', null, $v1, 'collect', 10000);
$e2 = mk_voucher($pdo, $uid, 'expense', 2, '2026-09-05 09:00:00', null, $b1, $catRent, '500.00');
led($pdo, 'cash', $b1, '2026-09-05 09:00:00', null, $e2, 'expense', -50000);
$e3 = mk_voucher($pdo, $uid, 'expense', 3, '2026-09-06 09:00:00', null, $b2, $catPower, '75.50');
led($pdo, 'cash', $b2, '2026-09-06 09:00:00', null, $e3, 'expense', -7550);
$e4 = mk_voucher($pdo, $uid, 'expense', 4, '2026-09-07 09:00:00', null, $b1, $catPower, '30.00');
led($pdo, 'cash', $b1, '2026-09-07 09:00:00', null, $e4, 'expense', -3000);
db_transaction($pdo, fn (PDO $pdo) => reverse_ledgers($pdo, 'voucher', $e4, '2026-09-08 10:00:00', 'إلغاء'));
$pdo->prepare("UPDATE vouchers SET status = 'cancelled', cancelled_at = '2026-09-08 10:00:00', cancelled_by = ? WHERE id = ?")->execute([$uid, $e4]);
led($pdo, 'party', $c2, '2026-09-10 12:00:00', $d5, null, 'sale', 16000);
db_transaction($pdo, fn (PDO $pdo) => reverse_ledgers($pdo, 'document', $d5, '2026-09-12 10:00:00', 'إلغاء'));
$p1 = mk_voucher($pdo, $uid, 'pay', 1, '2026-09-15 10:00:00', $s1, $b1, null, '300.00');
led($pdo, 'party', $s1, '2026-09-15 10:00:00', null, $p1, 'pay', -30000);
led($pdo, 'cash', $b1, '2026-09-15 10:00:00', null, $p1, 'pay', -30000);
led($pdo, 'party', $c1, '2026-09-20 11:00:00', $d4, null, 'sale', 18000);
$pdo->prepare('INSERT INTO cost_adjustments (item_id, document_id, amount, reason, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$itemA, $rA, '12.00', 'إلغاء وارد', '2026-09-25 12:00:00', $uid]);
check_eq('البيانات: الأرصدة المخزنة سليمة', [], acct_verify_balances($pdo));

$R = fn (string $key, array $f = []) => acct_report($pdo, $key, $f);
$cols = fn (array $ds) => array_column($ds['columns'], 'key');
$pick = fn (array $rows, array $keys) => array_map(fn ($r) => array_intersect_key($r, array_flip($keys)), $rows);
$sp = fn ($p, $n, $v, $a, $c, $g, $m) => ['period' => $p, 'invoices' => $n, 'volume' => $v, 'amount' => $a, 'cost' => $c, 'profit' => $g, 'margin' => $m];

/* ---------------- الحساب الدقيق ---------------- */
section('أدوات الحساب');
check_eq('القسمة الكبيرة مع التقريب', ['3', '7', '3', '14285714285714285714286', '0'],
    [acct_big_div_half_up('10', '3'), acct_big_div_half_up('20', '3'), acct_big_div_half_up('5', '2'), acct_big_div_half_up('100000000000000000000000', '7'), acct_big_div_half_up('0', '9')]);
check_eq('توزيع 100.00 على ثلاثة مخازن 1/1/1: الفرق للأول', [1 => 3334, 2 => 3333, 3 => 3333], acct_split_value(10000, [1 => 1, 2 => 1, 3 => 1]));
check_eq('توزيع 2.00 على 1/1/1: الفرق السالب للأول', [1 => 66, 2 => 67, 3 => 67], acct_split_value(200, [1 => 1, 2 => 1, 3 => 1]));
check_eq('توزيع 10.00 على 1/5/1: الفرق لأكبر كمية', [1 => 143, 2 => 714, 3 => 143], acct_split_value(1000, [1 => 1, 2 => 5, 3 => 1]));
check_eq('توزيع 10.01 على 1/1', [7 => 500, 9 => 501], acct_split_value(1001, [7 => 1, 9 => 1]));
check_eq('هامش الربح', ['34.62', '-50.00', null], [acct_margin(18000, 52000), acct_margin(-5000, 10000), acct_margin(100, 0)]);

/* ---------------- المبيعات حسب الفترة ---------------- */
section('المبيعات حسب الفترة');
$ds = $R('sales_period');
check_eq('الأعمدة', ['period', 'invoices', 'volume', 'amount', 'cost', 'profit', 'margin'], $cols($ds));
check_eq('يومي: الصفوف (الملغاة مستبعدة)', [
    $sp('2026-08-15', 1, '0.15', '150.00', '120.00', '30.00', '20.00'),
    $sp('2026-09-01', 2, '0.38', '520.00', '340.00', '180.00', '34.62'),
    $sp('2026-09-20', 1, '0.14', '180.00', '148.00', '32.00', '17.78'),
], $ds['rows']);
check_eq('يومي: الإجمالي', $sp('الإجمالي', 4, '0.67', '850.00', '608.00', '242.00', '28.47'), $ds['totals']);
check_eq('الوصف بدون تصفية', 'كل الفترات، التجميع: يومي', $ds['subtitle']);
check('generated_at بصيغة التاريخ', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $ds['generated_at']));
$ds = $R('sales_period', ['group' => 'month']);
check_eq('شهري', [
    $sp('2026-08', 1, '0.15', '150.00', '120.00', '30.00', '20.00'),
    $sp('2026-09', 3, '0.52', '700.00', '488.00', '212.00', '30.29'),
], $ds['rows']);
$ds = $R('sales_period', ['from' => '2026-09-01', 'to' => '2026-09-01']);
check_eq('فترة يوم واحد تشمل آخر اليوم', [$sp('2026-09-01', 2, '0.38', '520.00', '340.00', '180.00', '34.62')], $ds['rows']);
$ds = $R('sales_period', ['from' => '٢٠٢٦-٠٩-٠٢']);
check_eq('تاريخ بالأرقام العربية', ['2026-09-20'], array_column($ds['rows'], 'period'));
$ds = $R('sales_period', ['from' => '2026-09-30', 'to' => '2026-09-01', 'group' => 'month', 'warehouse' => (string) $w2]);
check_eq('مخزن الفرع شهريًا (والتاريخان معكوسان يُصححان)', [$sp('2026-09', 2, '0.22', '340.00', '248.00', '92.00', '27.06')], $ds['rows']);
check_eq('الوصف', 'من ' . digits('2026-09-01') . ' إلى ' . digits('2026-09-30') . '، التجميع: شهري، المخزن: مخزن الفرع', $ds['subtitle']);
$ds = $R('sales_period', ['type' => (string) $t2]);
check_eq('نوع موسكي: من الأسطر فقط', [
    $sp('2026-09-01', 1, '0.08', '160.00', '100.00', '60.00', '37.50'),
    $sp('2026-09-20', 1, '0.08', '120.00', '100.00', '20.00', '16.67'),
], $ds['rows']);
$ds = $R('sales_period', ['customer' => (string) $c1]);
check_eq('العميل أ', ['150.00', '180.00'], array_column($ds['rows'], 'amount'));
check_eq('  الوصف', 'كل الفترات، التجميع: يومي، العميل: عميل أ', $ds['subtitle']);

/* ---------------- تحليل المبيعات ---------------- */
section('تحليل المبيعات');
$sb = fn ($l, $n, $q, $v, $a, $c, $g, $m) => ['label' => $l, 'invoices' => $n, 'qty' => $q, 'volume' => $v, 'amount' => $a, 'cost' => $c, 'profit' => $g, 'margin' => $m];
$ds = $R('sales_by');
check_eq('الافتراضي حسب النوع', [
    $sb('زان', 3, 34, '0.51', '570.00', '408.00', '162.00', '28.42'),
    $sb('موسكي', 2, 10, '0.16', '280.00', '200.00', '80.00', '28.57'),
], $ds['rows']);
check_eq('  الإجمالي يعد الفاتورة مرة واحدة', $sb('الإجمالي', 4, 44, '0.67', '850.00', '608.00', '242.00', '28.47'), $ds['totals']);
check_eq('  عنوان العمود', 'نوع الخشب', $ds['columns'][0]['label']);
$ds = $R('sales_by', ['dimension' => 'warehouse']);
check_eq('حسب المخزن', [
    $sb('المخزن الرئيسي', 2, 30, '0.45', '510.00', '360.00', '150.00', '29.41'),
    $sb('مخزن الفرع', 2, 14, '0.22', '340.00', '248.00', '92.00', '27.06'),
], $ds['rows']);
$ds = $R('sales_by', ['dimension' => 'customer']);
check_eq('حسب العميل (والنقدي بدون حساب)', [
    $sb('عميل ب', 1, 20, '0.3', '360.00', '240.00', '120.00', '33.33'),
    $sb('عميل أ', 2, 19, '0.29', '330.00', '268.00', '62.00', '18.79'),
    $sb(ACCT_CASH_CUSTOMER_LABEL, 1, 5, '0.08', '160.00', '100.00', '60.00', '37.50'),
], $ds['rows']);
check_eq('  عنوان العمود', 'العميل', $ds['columns'][0]['label']);
$ds = $R('sales_by', ['dimension' => 'customer', 'type' => (string) $t1, 'from' => '2026-09-01']);
check_eq('حسب العميل لنوع زان من سبتمبر', [['عميل ب', '360.00'], ['عميل أ', '60.00']], array_map(fn ($r) => [$r['label'], $r['amount']], $ds['rows']));
check_eq('  الوصف', 'من ' . digits('2026-09-01') . '، حسب العميل، نوع الخشب: زان', $ds['subtitle']);
$ds = $R('sales_by', ['dimension' => 'branch']);
check_eq('حسب الفرع متاح بعد ترقية الفروع (005)', [], $ds['ignored']);
check_eq('  مجموع الفروع = كل المبيعات السارية', '850.00', piasters_to_money(array_sum(array_map(fn ($r) => money_to_piasters($r['amount']), $ds['rows']))));

/* ---------------- الأرباح ---------------- */
section('الأرباح');
$pr = fn (array $ds) => array_map(fn ($r) => [$r['label'], $r['amount']], $ds['rows']);
$ds = $R('profit');
check_eq('كل الفترات', [
    ['إيرادات المبيعات', '850.00'], ['تكلفة البضاعة المباعة', '-608.00'], ['مجمل الربح', '242.00'],
    ['مصروف: إيجار', '-1000.00'], ['مصروف: كهرباء ومياه', '-75.50'], ['إجمالي المصروفات', '-1075.50'],
    ['فروق تكلفة المخزون', '-12.00'],
], $pr($ds));
check_eq('  صافي الربح', ['label' => 'صافي الربح', 'amount' => '-845.50'], $ds['totals']);
$ds = $R('profit', ['from' => '2026-09-01', 'to' => '2026-09-30']);
check_eq('سبتمبر', [
    ['إيرادات المبيعات', '700.00'], ['تكلفة البضاعة المباعة', '-488.00'], ['مجمل الربح', '212.00'],
    ['مصروف: إيجار', '-500.00'], ['مصروف: كهرباء ومياه', '-75.50'], ['إجمالي المصروفات', '-575.50'],
    ['فروق تكلفة المخزون', '-12.00'],
], $pr($ds));
check_eq('  صافي الربح', '-375.50', $ds['totals']['amount']);
$ds = $R('profit', ['from' => '2026-08-01', 'to' => '2026-08-31']);
check_eq('أغسطس: بدون فروق تكلفة', [['مجمل الربح', '30.00'], ['إجمالي المصروفات', '-500.00'], ['فروق تكلفة المخزون', '0.00']],
    array_values(array_filter($pr($ds), fn ($r) => in_array($r[0], ['مجمل الربح', 'إجمالي المصروفات', 'فروق تكلفة المخزون'], true))));
check_eq('  صافي الربح', '-470.00', $ds['totals']['amount']);

/* ---------------- تقييم المخزون ---------------- */
section('تقييم المخزون');
$vk = ['type_name', 'qty', 'volume', 'avg_cost', 'value', 'status'];
$vr = fn ($t, $q, $v, $a, $val, $s) => ['type_name' => $t, 'qty' => $q, 'volume' => $v, 'avg_cost' => $a, 'value' => $val, 'status' => $s];
$ds = $R('valuation');
check_eq('لكل صنف: الكمية والحجم ومتوسط تكلفة المتر والقيمة', [
    $vr('زان', 66, '0.99', '800.00', '792.00', 'مقيّم'),
    $vr('زان', 3, '0.03', '3333.33', '100.00', 'مقيّم'),
    $vr('موسكي', 40, '0.64', '1250.00', '800.00', 'مقيّم'),
    $vr('موسكي', 5, '0.025', null, '0.00', 'غير مقيّم'),
], $pick($ds['rows'], $vk));
check_eq('  المقاس نص مقروء', fmt_size(['width_um' => 100000, 'thickness_um' => 50000, 'length_um' => 3000000, 'width_unit' => 'cm', 'thickness_unit' => 'mm', 'length_unit' => 'm']), $ds['rows'][0]['size']);
check_eq('  الإجمالي', ['qty' => 114, 'volume' => '1.685', 'value' => '1692.00', 'status' => 'أصناف غير مقيّمة: ' . fmt_int(1)],
    array_intersect_key($ds['totals'], array_flip(['qty', 'volume', 'value', 'status'])));
check_eq('  عدد غير المقيّم', 1, $ds['notes']['unvalued']);
check_eq('  الوصف', 'المخزون الحالي', $ds['subtitle']);
$byWh = array_column($ds['sections'][0]['rows'], null, 'warehouse');
check_eq('القيمة حسب المخزن (الفرق لأكبر صف)', [
    'المخزن الرئيسي' => ['warehouse' => 'المخزن الرئيسي', 'qty' => 61, 'volume' => '0.91', 'value' => '753.34'],
    'مخزن الفرع' => ['warehouse' => 'مخزن الفرع', 'qty' => 47, 'volume' => '0.74', 'value' => '905.33'],
    'مخزن ثالث' => ['warehouse' => 'مخزن ثالث', 'qty' => 6, 'volume' => '0.035', 'value' => '33.33'],
], [
    'المخزن الرئيسي' => $byWh['المخزن الرئيسي'], 'مخزن الفرع' => $byWh['مخزن الفرع'], 'مخزن ثالث' => $byWh['مخزن ثالث'],
]);
check_eq('  مجموع المخازن = القيمة بالضبط', ['qty' => 114, 'volume' => '1.685', 'value' => '1692.00'],
    array_intersect_key($ds['sections'][0]['totals'], array_flip(['qty', 'volume', 'value'])));
$cRows = array_values(array_filter($ds['sections'][1]['rows'], fn ($r) => $r['volume'] === '0.01'));
check_eq('صنف ج: 100.00 على ثلاثة مخازن = 33.34 / 33.33 / 33.33', [['المخزن الرئيسي', '33.34'], ['مخزن الفرع', '33.33'], ['مخزن ثالث', '33.33']],
    array_map(fn ($r) => [$r['warehouse'], $r['value']], $cRows));
$ds = $R('valuation', ['warehouse' => (string) $w3]);
check_eq('مخزن ثالث فقط', [$vr('زان', 1, '0.01', '3333.33', '33.33', 'مقيّم'), $vr('موسكي', 5, '0.025', null, '0.00', 'غير مقيّم')], $pick($ds['rows'], $vk));
check_eq('  الإجمالي', '33.33', $ds['totals']['value']);
check_eq('  الوصف', 'المخزون الحالي، المخزن: مخزن ثالث', $ds['subtitle']);
$ds = $R('valuation', ['type' => (string) $t2]);
check_eq('نوع موسكي فقط', ['800.00', '0.00'], array_column($ds['rows'], 'value'));

/* ---------------- الأرصدة ---------------- */
section('أرصدة العملاء والموردين');
$bk = ['name', 'balance', 'direction', 'last_entry', 'status'];
$ds = $R('customer_balances');
check_eq('العملاء غير الصفريين', [
    ['name' => 'عميل ب', 'balance' => '310.00', 'direction' => 'عليه', 'last_entry' => '2026-09-12 10:00:00', 'status' => 'نشط'],
    ['name' => 'عميل أ', 'balance' => '230.00', 'direction' => 'عليه', 'last_entry' => '2026-09-20 11:00:00', 'status' => 'نشط'],
], $pick($ds['rows'], $bk));
check_eq('  الإجمالي', ['balance' => '540.00', 'direction' => 'عليه'], array_intersect_key($ds['totals'], array_flip(['balance', 'direction'])));
check_eq('  الوصف', 'الأرصدة الحالية، الأرصدة غير الصفرية فقط', $ds['subtitle']);
$ds = $R('customer_balances', ['show' => 'all']);
check_eq('كل العملاء', [['عميل ب', '310.00'], ['عميل أ', '230.00'], ['عميل صفري', '0.00']], array_map(fn ($r) => [$r['name'], $r['balance']], $ds['rows']));
check_eq('  الإجمالي', '540.00', $ds['totals']['balance']);
$ds = $R('supplier_balances');
check_eq('الموردون', [['مورد أ', '900.00', 'له'], ['مورد ب', '600.00', 'له']], array_map(fn ($r) => [$r['name'], $r['balance'], $r['direction']], $ds['rows']));
check_eq('  الإجمالي', '1500.00', $ds['totals']['balance']);

/* ---------------- الخزائن ---------------- */
section('ملخص الخزائن');
$ds = $R('cash_summary', ['from' => '2026-09-01', 'to' => '2026-09-30']);
check_eq('رصيد كل خزنة وحركة سبتمبر', [
    ['name' => 'الخزنة الرئيسية', 'opening' => '4100.00', 'in' => '290.00', 'out' => '830.00', 'closing' => '3560.00', 'balance' => '3560.00'],
    ['name' => 'خزنة الفرع', 'opening' => '0.00', 'in' => '100.00', 'out' => '75.50', 'closing' => '24.50', 'balance' => '24.50'],
], $ds['rows']);
check_eq('  الإجمالي', ['name' => 'الإجمالي', 'opening' => '4100.00', 'in' => '390.00', 'out' => '905.50', 'closing' => '3584.50', 'balance' => '3584.50'], $ds['totals']);
check_eq('الحركة حسب النوع (قيد الإلغاء من الدفتر مرة واحدة)', [
    ['type' => 'مبيعات', 'in' => '260.00', 'out' => '0.00', 'net' => '260.00'],
    ['type' => 'تحصيل من العملاء', 'in' => '100.00', 'out' => '0.00', 'net' => '100.00'],
    ['type' => 'سداد للموردين', 'in' => '0.00', 'out' => '300.00', 'net' => '-300.00'],
    ['type' => 'مصروفات', 'in' => '0.00', 'out' => '605.50', 'net' => '-605.50'],
    ['type' => 'قيود إلغاء', 'in' => '30.00', 'out' => '0.00', 'net' => '30.00'],
], $ds['sections'][0]['rows']);
check_eq('  الإجمالي', ['type' => 'الإجمالي', 'in' => '390.00', 'out' => '905.50', 'net' => '-515.50'], $ds['sections'][0]['totals']);
$dr = fn ($d, $o, $i, $x, $c) => ['day' => $d, 'opening' => $o, 'in' => $i, 'out' => $x, 'closing' => $c];
check_eq('الرصيد اليومي لكل الخزائن', [
    $dr('2026-09-01', '4100.00', '260.00', '0.00', '4360.00'),
    $dr('2026-09-04', '4360.00', '100.00', '0.00', '4460.00'),
    $dr('2026-09-05', '4460.00', '0.00', '500.00', '3960.00'),
    $dr('2026-09-06', '3960.00', '0.00', '75.50', '3884.50'),
    $dr('2026-09-07', '3884.50', '0.00', '30.00', '3854.50'),
    $dr('2026-09-08', '3854.50', '30.00', '0.00', '3884.50'),
    $dr('2026-09-15', '3884.50', '0.00', '300.00', '3584.50'),
], $ds['sections'][1]['rows']);
$ds = $R('cash_summary', ['from' => '2026-09-01', 'to' => '2026-09-30', 'cash_box' => (string) $b2]);
check_eq('خزنة الفرع فقط', [['خزنة الفرع', '0.00', '24.50']], array_map(fn ($r) => [$r['name'], $r['opening'], $r['closing']], $ds['rows']));
check_eq('  رصيدها اليومي', [$dr('2026-09-04', '0.00', '100.00', '0.00', '100.00'), $dr('2026-09-06', '100.00', '0.00', '75.50', '24.50')], $ds['sections'][1]['rows']);
check_eq('  الوصف', 'من ' . digits('2026-09-01') . ' إلى ' . digits('2026-09-30') . '، الخزنة: خزنة الفرع', $ds['subtitle']);
$ds = $R('cash_summary');
check_eq('كل الفترات: من الافتتاحي إلى الرصيد الحالي', ['opening' => '5000.00', 'in' => '390.00', 'out' => '1805.50', 'closing' => '3584.50', 'balance' => '3584.50'],
    array_intersect_key($ds['totals'], array_flip(['opening', 'in', 'out', 'closing', 'balance'])));

/* ---------------- المصروفات ---------------- */
section('المصروفات');
$ds = $R('expenses');
check_eq('حسب التصنيف (الملغى مستبعد)', [
    ['category' => 'إيجار', 'count' => 2, 'amount' => '1000.00'],
    ['category' => 'كهرباء ومياه', 'count' => 1, 'amount' => '75.50'],
], $ds['rows']);
check_eq('  الإجمالي', ['category' => 'الإجمالي', 'count' => 3, 'amount' => '1075.50'], $ds['totals']);
check_eq('  الأعمدة بدون الفترة', ['category', 'count', 'amount'], $cols($ds));
$ds = $R('expenses', ['group' => 'month']);
check_eq('شهري', [
    ['period' => '2026-08', 'category' => 'إيجار', 'count' => 1, 'amount' => '500.00'],
    ['period' => '2026-09', 'category' => 'إيجار', 'count' => 1, 'amount' => '500.00'],
    ['period' => '2026-09', 'category' => 'كهرباء ومياه', 'count' => 1, 'amount' => '75.50'],
], $ds['rows']);
$ds = $R('expenses', ['from' => '2026-09-01', 'category' => (string) $catPower]);
check_eq('تصنيف كهرباء من سبتمبر', [['category' => 'كهرباء ومياه', 'count' => 1, 'amount' => '75.50']], $ds['rows']);
check_eq('  الوصف', 'من ' . digits('2026-09-01') . '، التجميع: حسب التصنيف، التصنيف: كهرباء ومياه', $ds['subtitle']);
$ds = $R('expenses', ['cash_box' => (string) $b1, 'group' => 'day']);
check_eq('الخزنة الرئيسية يوميًا', [['2026-08-20', '500.00'], ['2026-09-05', '500.00']], array_map(fn ($r) => [$r['period'], $r['amount']], $ds['rows']));

/* ---------------- التصفية غير الصالحة ---------------- */
section('التصفية غير الصالحة تُتجاهل بأمان');
$base = $R('sales_period');
$ds = $R('sales_period', ['from' => "2026-09-01' OR '1'='1", 'to' => '2026-13-45', 'group' => 'month; DROP TABLE documents',
    'warehouse' => '1 OR 1=1', 'type' => ['x'], 'customer' => '-1', 'evil' => "'; DELETE FROM documents; --"]);
check_eq('النتيجة كأن لا تصفية', $base['rows'], $ds['rows']);
check_eq('الحقول المتجاهلة', ['from', 'to', 'group', 'warehouse', 'type', 'customer'], $ds['ignored']);
check_eq('الوصف لا يحتوي المدخلات', 'كل الفترات، التجميع: يومي', $ds['subtitle']);
$ds = $R('sales_by', ['dimension' => "customer' UNION SELECT 1 --", 'warehouse' => '999999']);
check_eq('بُعد غير معروف ومخزن غير موجود', [['dimension', 'warehouse'], 'نوع الخشب'], [$ds['ignored'], $ds['columns'][0]['label']]);
$ds = $R('customer_balances', ['show' => '1; DROP TABLE parties', 'from' => '2026-01-01']);
check_eq('حقل لا يخص التقرير لا يؤثر', [['show'], 2], [$ds['ignored'], count($ds['rows'])]);
$ds = $R('expenses', ['category' => (string) $c1 . 'x', 'cash_box' => '0']);
check_eq('معرّفات غير صالحة', ['cash_box', 'category'], $ds['ignored']);
check_eq('الجداول سليمة', [5, 5], [(int) $pdo->query("SELECT COUNT(*) FROM documents WHERE kind = 'sale'")->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM parties')->fetchColumn()]);
try {
    $R('nope');
    check('تقرير غير معروف مرفوض', false);
} catch (InvalidArgumentException $e) {
    check('تقرير غير معروف مرفوض', true);
}
$ds = $R('sales_period', ['customer' => (string) $s1]);
check_eq('معرّف مورد في حقل العميل يُتجاهل', ['customer'], $ds['ignored']);

/* ---------------- لوحة التحكم ---------------- */
section('لوحة التحكم');
$dash = acct_dashboard($pdo, '2026-09-20');
check_eq('اليوم', ['sales_count' => 1, 'sales_amount' => '180.00', 'gross_profit' => '32.00', 'collections' => '0.00', 'expenses' => '0.00'], $dash['day']);
check_eq('هذا الشهر', ['sales_count' => 3, 'sales_amount' => '700.00', 'gross_profit' => '212.00', 'collections' => '100.00', 'expenses' => '575.50'], $dash['month']);
check_eq('الأرصدة', ['3584.50', '540.00', '1500.00', 1], [$dash['cash'], $dash['receivables'], $dash['payables'], $dash['unvalued_items']]);
check_eq('أعلى العملاء', [['عميل ب', '310.00'], ['عميل أ', '230.00']], array_map(fn ($c) => [$c['name'], $c['balance']], $dash['top_customers']));
check_eq('بداية الشهر', '2026-09-01', $dash['month_start']);

/* ---------------- الصفحات ---------------- */
section('عرض الصفحات');
$_SESSION = ['user_id' => $uid, 'username' => 'admin', 'auth_version' => 1, 'role' => 'admin'];
function render_page(string $page, array $get): array
{
    $warnings = [];
    set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) {
        $warnings[] = "$msg @ " . basename($file) . ":$line";
        return true;
    });
    $_GET = $get;
    ob_start();
    try {
        include APP_ROOT . '/pages/' . $page . '.php';
    } finally {
        $html = (string) ob_get_clean();
        restore_error_handler();
    }
    return [$html, $warnings];
}
function check_html(string $label, string $html, array $warnings): void
{
    check_eq("$label: بلا تحذيرات PHP", [], $warnings);
    check("$label: كل خلية td لها data-label", preg_match_all('/<td(?![^>]*data-label=)[^>]*>/', $html) === 0);
    check("$label: لا CSS أو JS مضمن", !preg_match('/\sstyle=|\son[a-z]+=|<script(?![^>]*\ssrc=)/i', $html));
    check("$label: الصفحة مكتملة", str_contains($html, '</html>'));
}
[$html, $w] = render_page('reports', []);
check_html('قائمة التقارير', $html, $w);
foreach (ACCT_REPORTS as $k => $r) {
    check("القائمة تربط تقرير $k", str_contains($html, h(url('reports', ['report' => $k]))));
}
foreach (array_keys(ACCT_REPORTS) as $k) {
    [$html, $w] = render_page('reports', ['report' => $k, 'from' => '2026-09-01', 'to' => '2026-09-30', 'group' => 'month', 'dimension' => 'customer']);
    check_html("تقرير $k", $html, $w);
    check("تقرير $k: العنوان والوصف وزر الطباعة", str_contains($html, '<h1>' . h(ACCT_REPORTS[$k]['title']) . '</h1>')
        && str_contains($html, 'class="report-subtitle"') && str_contains($html, 'data-action="print"') && str_contains($html, 'id="live-report" data-live'));
}
[$html, $w] = render_page('reports', ['report' => 'sales_period', 'from' => '2026-09-01', 'to' => '2026-09-30']);
check('المبيعات: الإجمالي منسق في الصفحة', str_contains($html, h(fmt_money('700.00'))) && str_contains($html, h(fmt_money('212.00'))) && str_contains($html, 'class="row-total"'));
check('المبيعات: الوصف في الصفحة', str_contains($html, h('من ' . digits('2026-09-01') . ' إلى ' . digits('2026-09-30'))));
[$html, $w] = render_page('reports', ['report' => 'profit']);
check('الأرباح: السالب بعلامة ناقص', str_contains($html, h('-' . fmt_money('845.50'))));
[$html, $w] = render_page('reports', ['report' => 'valuation']);
check('التقييم: تنبيه الأصناف غير المقيّمة', str_contains($html, 'غير مقيّم') && str_contains($html, h(fmt_money('753.34'))));
[$html, $w] = render_page('reports', ['report' => 'sales_period', 'from' => '<script>alert(1)</script>', 'warehouse' => '"><b>x']);
check_html('مدخلات خبيثة', $html, $w);
check('مدخلات خبيثة: لا تظهر في الصفحة', !str_contains($html, '<script>alert') && !str_contains($html, '<b>x') && str_contains($html, 'تم تجاهل'));
[$html, $w] = render_page('dashboard', []);
check_html('لوحة التحكم', $html, $w);
foreach (['today', 'month', 'balances', 'customers', 'stock'] as $id) {
    check("لوحة التحكم: منطقة live-dashboard-$id", (bool) preg_match('/id="live-dashboard-' . $id . '"[^>]*data-live/', $html));
}
check('لوحة التحكم: النقدية والمستحقات وأعلى العملاء', str_contains($html, h(fmt_money('3584.50'))) && str_contains($html, h(fmt_money('1500.00')))
    && str_contains($html, h(fmt_party_balance(31000, 'customer'))));
check('لوحة التحكم: تنبيه المخزون غير المقيّم ورابط التقييم', str_contains($html, h(url('reports', ['report' => 'valuation']))) && str_contains($html, 'بدون تكلفة'));
foreach (array_keys(ACCT_REPORTS) as $k) {
    check("لوحة التحكم تربط تقرير $k", str_contains($html, h(url('reports', ['report' => $k]))));
}

check_eq('الأرصدة المخزنة سليمة في النهاية', [], acct_verify_balances($pdo));
finish();
