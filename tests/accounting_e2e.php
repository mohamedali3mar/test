<?php
declare(strict_types=1);

/*
 * اختبار ترابط الحسابات من أولها لآخرها بالخدمات الحقيقية (بدون كتابة مباشرة في الجداول):
 * مورد، عميل، خزنة، وارد بتكلفة آجل وجزئي، بيع نقدي وآجل وجزئي، تحصيل، صرف، مصروف، إلغاء،
 * ثم التحقق أن التقارير ولوحة التحكم تساوي الأرصدة والدفاتر بالضبط.
 */
require __DIR__ . '/lib.php';

$pdo = fresh_database();
$uid = seed_user($pdo);
$wh = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$type = catalog_create($pdo, 'type', 'زان');
$box = cash_box_create($pdo, $uid, 'خزنة المعرض', '10000');
$sup = party_create($pdo, $uid, 'supplier', ['name' => 'مورد الأخشاب', 'opening_balance' => '0']);
$cus = party_create($pdo, $uid, 'customer', ['name' => 'عميل آجل', 'opening_balance' => '0']);
$cat = (int) $pdo->query('SELECT id FROM expense_categories ORDER BY id LIMIT 1')->fetchColumn();

section('دورة كاملة بالخدمات');
// وارد 20 قطعة 10سم×50مم×3م (0.3 م³) بسعر 20000 للمتر: 6000.00، جزئي مدفوع 2000 من الخزنة
$r1 = record_receipt($pdo, $uid, receipt_input($wh, $type, '10', 'cm', '50', 'mm', '3', 'm', '20',
    ['cost_per_m3' => '20000', 'party_id' => (string) $sup, 'payment_type' => 'partial', 'paid_amount' => '2000', 'cash_box_id' => (string) $box]));
$item = item_id_for($pdo, $type, 100000, 50000, 3000000);
check_eq('المورد: 6000 - 2000 = 4000 له', 400000, party_balance($pdo, $sup));
check_eq('الخزنة: 10000 - 2000 = 8000', 800000, cash_balance($pdo, $box));

// بيع 5 قطع (0.075 م³) بسعر 40000: 3000.00 نقدي، تكلفة 6000 × 5 ÷ 20 = 1500
$s1 = record_sale($pdo, $uid, sale_input($wh, [[$item, 5, '40000']], ['payment_type' => 'cash', 'cash_box_id' => (string) $box]));
// بيع 5 قطع بسعر 40000: 3000.00 آجل للعميل
$s2 = record_sale($pdo, $uid, sale_input($wh, [[$item, 5, '40000']], ['payment_type' => 'credit', 'party_id' => (string) $cus]));
// بيع 4 قطع بسعر 50000: 0.06 م³ = 3000.00، جزئي مدفوع 1000
$s3 = record_sale($pdo, $uid, sale_input($wh, [[$item, 4, '50000']], ['payment_type' => 'partial', 'party_id' => (string) $cus, 'paid_amount' => '1000', 'cash_box_id' => (string) $box]));
check_eq('العميل: 3000 + 3000 - 1000 = 5000 عليه', 500000, party_balance($pdo, $cus));
check_eq('الخزنة: 8000 + 3000 + 1000 = 12000', 1200000, cash_balance($pdo, $box));
check_eq('قيمة المخزون: 6000 - 1500 - 1500 - 1200 = 1800 لـ 6 قطع', '1800.00', (string) $pdo->query("SELECT inventory_value FROM items WHERE id = $item")->fetchColumn());

$v1 = record_voucher($pdo, $uid, 'collect', ['party_id' => (string) $cus, 'cash_box_id' => (string) $box, 'amount' => '1500', 'request_token' => new_request_token()]);
$v2 = record_voucher($pdo, $uid, 'pay', ['party_id' => (string) $sup, 'cash_box_id' => (string) $box, 'amount' => '4000', 'request_token' => new_request_token()]);
$v3 = record_voucher($pdo, $uid, 'expense', ['category_id' => (string) $cat, 'cash_box_id' => (string) $box, 'amount' => '250.50', 'notes' => 'إيجار', 'request_token' => new_request_token()]);
check_eq('العميل بعد التحصيل: 3500', 350000, party_balance($pdo, $cus));
check_eq('المورد بعد الصرف: صفر', 0, party_balance($pdo, $sup));
check_eq('الخزنة: 12000 + 1500 - 4000 - 250.50 = 9249.50', 924950, cash_balance($pdo, $box));

// إلغاء البيع النقدي: يرد 3000 من الخزنة ويعيد التكلفة 1500 للمخزون
cancel_document($pdo, $uid, (int) $s1['id'], 'مرتجع');
check_eq('الخزنة بعد إلغاء البيع النقدي: 6249.50', 624950, cash_balance($pdo, $box));
check_eq('قيمة المخزون بعد الإلغاء: 3300 لـ 11 قطعة', '3300.00', (string) $pdo->query("SELECT inventory_value FROM items WHERE id = $item")->fetchColumn());
check_eq('سلامة الأرصدة', [], acct_verify_balances($pdo));

section('التقارير تساوي الدفاتر');
$today = date('Y-m-d');
$profit = acct_report($pdo, 'profit', ['from' => $today, 'to' => $today]);
$byLabel = [];
foreach ($profit['rows'] as $r) {
    $byLabel[$r['label']] = $r['amount'];
}
check('تقرير الأرباح فيه المبيعات والتكلفة', $byLabel !== [], json_encode($profit['rows'], JSON_UNESCAPED_UNICODE));
$sales = acct_report($pdo, 'sales_period', ['from' => $today, 'to' => $today, 'group' => 'day']);
check_eq('المبيعات السارية: 6000.00 (فاتورتان)', ['6000.00', 2], [(string) ($sales['totals']['amount'] ?? ''), (int) ($sales['totals']['invoices'] ?? 0)]);
check_eq('تكلفة المبيعات السارية: 1500 + 1200 = 2700.00', '2700.00', (string) ($sales['totals']['cost'] ?? ''));
check_eq('مجمل الربح: 3300.00', '3300.00', (string) ($sales['totals']['profit'] ?? ''));
$val = acct_report($pdo, 'valuation', []);
check_eq('تقييم المخزون = قيمة الصنف', '3300.00', (string) ($val['totals']['value'] ?? ''));
$cb = acct_report($pdo, 'customer_balances', []);
check_eq('أرصدة العملاء = رصيد العميل', '3500.00', (string) ($cb['totals']['balance'] ?? $cb['totals']['amount'] ?? ''));
$cash = acct_report($pdo, 'cash_summary', ['from' => $today, 'to' => $today]);
check('ملخص الخزائن يذكر رصيد الخزنة الحالي 6249.50', str_contains(json_encode($cash, JSON_UNESCAPED_UNICODE), '6249.50'));
$dash = acct_dashboard($pdo);
check('لوحة التحكم تذكر الأرصدة نفسها', str_contains(json_encode($dash, JSON_UNESCAPED_UNICODE), '6249.50') && str_contains(json_encode($dash, JSON_UNESCAPED_UNICODE), '3500.00'));
$st = party_statement($pdo, $cus, null, null);
check('كشف حساب العميل ينتهي بـ 3500', str_contains(json_encode($st, JSON_UNESCAPED_UNICODE), '3500'));
check_eq('سلامة الأرصدة في النهاية', [], acct_verify_balances($pdo));

finish();
