<?php
declare(strict_types=1);

/*
 * تعديل المستندات (lib/doc_edit.php): إلغاء الأصلي وتسجيل البديل في معاملة واحدة، والربط بينهما،
 * والأرصدة والحسابات بعد التعديل، والتراجع الكامل عند أي خطأ، والصلاحيات (الكل أو مستنداته اليوم فقط).
 * التشغيل: WOOD_TEST_DB=wood_doc_edit php tests/integration_doc_edit.php
 */

require __DIR__ . '/lib.php';

$pdo = fresh_database();
$admin = seed_user($pdo);
$pdo->exec("UPDATE users SET role = 'admin'");
$as = function (int $id) use ($pdo): void {
    $u = session_user_row($pdo, $id);
    $_SESSION = ['user_id' => $id, 'username' => (string) $u['username'], 'role' => user_role($u), 'perm_overrides' => user_permission_overrides($u)];
    reset_branch_scope_cache();
};
$as($admin);
$q = fn (string $sql) => $pdo->query($sql)->fetchColumn();
$stock = fn (int $item, int $wh): int => (int) $q("SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock WHERE item_id = $item AND warehouse_id = $wh");
$doc = fn (int $id): array => $pdo->query("SELECT * FROM documents WHERE id = $id")->fetch();
$box = (int) $q('SELECT id FROM cash_boxes ORDER BY id LIMIT 1');
$boxBal = fn (): int => money_to_piasters((string) $q("SELECT balance FROM cash_boxes WHERE id = $box"));

$wh1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$wh2 = catalog_create($pdo, 'warehouse', 'مخزن الفرع');
$mosky = catalog_create($pdo, 'type', 'موسكي');
record_receipt($pdo, $admin, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '100'));
$item = item_id_for($pdo, $mosky, 100000, 50000, 3000000); // القطعة 0.015 م³
$cust = party_create($pdo, $admin, 'customer', ['name' => 'عميل التعديل', 'credit_limit' => '100000']);

section('تعديل فاتورة بيع: الكمية والسعر وطريقة الدفع');
$sale = record_sale($pdo, $admin, sale_input($wh1, [[$item, 10, '20000']], ['party_id' => (string) $cust, 'party_name' => 'عميل التعديل', 'payment_type' => 'credit']));
check_eq('قبل التعديل: الرصيد ٩٠ والعميل عليه 3000.00', [90, 300000], [$stock($item, $wh1), party_balance($pdo, $cust)]);
$counter = (int) $q("SELECT value FROM counters WHERE name = 'doc_sale'");
$form = document_edit_form($pdo, $doc($sale['id']));
check_eq('نموذج التعديل يحمل بيانات الفاتورة', [(string) $wh1, (string) $cust, 'credit', '10', '20000'],
    [$form['warehouse_id'], $form['party_id'], $form['payment_type'], $form['lines'][0]['quantity'], $form['lines'][0]['price']]);
$form['lines'][0]['quantity'] = '12';
$form['lines'][0]['price'] = '21000';
$form['payment_type'] = 'partial';
$form['paid_amount'] = '1000';
$form['cash_box_id'] = (string) $box;
$boxBefore = $boxBal();
$new = document_replace($pdo, $admin, $sale['id'], $form, 'خطأ في الكمية');
$o = $doc($sale['id']);
$n = $doc($new['id']);
check('الأصلية ملغاة ومربوطة بالبديلة', $o['status'] === 'cancelled' && (int) $o['replaced_by_id'] === $new['id'] && str_contains((string) $o['cancel_reason'], 'عُدّل بـ'));
check('البديلة سارية برقم جديد ومربوطة بالأصلية', $n['status'] === 'active' && (int) $n['replaces_id'] === $sale['id'] && (int) $n['doc_no'] === $counter + 1);
check_eq('الرصيد بعد التعديل ٨٨ (١٠٠ - ١٢)', 88, $stock($item, $wh1));
// 12 × 0.015 × 21000 = 3780.00، مدفوع 1000.00
check_eq('العميل عليه 2780.00 فقط (القيود العكسية ثم الجديدة)', 278000, party_balance($pdo, $cust));
check_eq('الخزنة زادت 1000.00', $boxBefore + 100000, $boxBal());
check_eq('لا اختلاف في الأرصدة', [], acct_verify_balances($pdo));
check('التعديل في المراقبة بسببه', (int) $q("SELECT COUNT(*) FROM audit_log WHERE action = 'doc.edit' AND summary LIKE '%خطأ في الكمية%'") === 1);
$dup = document_replace($pdo, $admin, $sale['id'], $form, 'خطأ في الكمية');
check('إعادة إرسال نفس التعديل تعيد البديلة ولا يتكرر شيء', $dup['duplicate'] === true && $dup['id'] === $new['id'] && $stock($item, $wh1) === 88);

section('التعديل يتم كله أو لا شيء');
$form2 = document_edit_form($pdo, $doc($new['id']));
$form2['lines'][0]['quantity'] = '101';
$errors = expect_validation('كمية أكبر من المتاح بعد رد الأصلية مرفوضة', fn () => document_replace($pdo, $admin, $new['id'], $form2, ''));
check('  ولم يُلغ الأصلي ولم يتغير الرصيد', $doc($new['id'])['status'] === 'active' && $stock($item, $wh1) === 88 && party_balance($pdo, $cust) === 278000);
$form2['lines'][0]['quantity'] = '100';
$form2['payment_type'] = 'credit';
$form2['paid_amount'] = '';
$r = document_replace($pdo, $admin, $new['id'], $form2, '');
check_eq('كل الرصيد بعد رد الأصلية (١٠٠ قطعة) مسموح', 0, $stock($item, $wh1));
check_eq('  والعميل عليه 31500.00', 3150000, party_balance($pdo, $cust));
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));

section('تعديل إذن وارد: مقاس خاطئ');
$rc = record_receipt($pdo, $admin, receipt_input($wh2, $mosky, '20', 'cm', '5', 'cm', '4', 'm', '7'));
$wrong = item_id_for($pdo, $mosky, 200000, 50000, 4000000);
$f = document_edit_form($pdo, $doc($rc['id']));
check_eq('نموذج الوارد يحمل المقاس بوحداته', ['20', 'cm', '5', 'cm', '4', 'm', '7'], [$f['width'], $f['width_unit'], $f['thickness'], $f['thickness_unit'], $f['length'], $f['length_unit'], $f['quantity']]);
$f['width'] = '25';
$nr = document_replace($pdo, $admin, $rc['id'], $f, 'مقاس خاطئ');
$right = item_id_for($pdo, $mosky, 250000, 50000, 4000000);
check_eq('المقاس الخاطئ رصيده صفر والصحيح ٧', [0, 7], [$stock($wrong, $wh2), $stock($right, $wh2)]);
check('  والبديل مربوط', (int) $doc($nr['id'])['replaces_id'] === $rc['id']);
$sold = record_sale($pdo, $admin, sale_input($wh2, [[$right, 5, '1000']]));
$f2 = document_edit_form($pdo, $doc($nr['id']));
$f2['quantity'] = '4';
$errors = expect_validation('وارد بيع منه ٥ لا يُعدل إلى ٤ قطع (أقل من المباع)', fn () => document_replace($pdo, $admin, $nr['id'], $f2, ''));
check('  رسالة الرصيد', str_contains($errors['document'] ?? '', 'لا يمكن حفظ التعديل'), json_encode($errors, JSON_UNESCAPED_UNICODE));
check('  ولم يتغير شيء', $doc($nr['id'])['status'] === 'active' && $stock($right, $wh2) === 2);
$f2['quantity'] = '6';
document_replace($pdo, $admin, $nr['id'], $f2, 'وصل ٦ فقط');
check_eq('  وإلى ٦ قطع يُحفظ: الباقي ١ (بيع منها ٥)', 1, $stock($right, $wh2));
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));

section('تعديل إذن تحويل');
record_receipt($pdo, $admin, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '20'));
$tr = record_transfer($pdo, $admin, transfer_input($wh1, $wh2, [[$item, 8]]));
$ft = document_edit_form($pdo, $doc($tr['id']));
$ft['lines'][0]['quantity'] = '5';
document_replace($pdo, $admin, $tr['id'], $ft, '');
check_eq('بعد تعديل التحويل من ٨ إلى ٥: ١٥ و٥', [15, 5], [$stock($item, $wh1), $stock($item, $wh2)]);

section('التاريخ: البديل يحتفظ بتاريخ الأصلي');
$back = date('Y-m-d', strtotime('-3 days'));
$old = record_sale($pdo, $admin, sale_input($wh1, [[$item, 1, '1000']], ['doc_date' => $back]));
$staff = user_create($pdo, $admin, ['display_name' => 'موظف', 'username' => 'edit_staff', 'role' => 'staff', 'password' => 'Staff-Pass-001', 'password_confirm' => 'Staff-Pass-001']);
user_set_permissions($pdo, $admin, $staff, ['stock.view', 'documents.create', 'documents.view', 'catalog.view', 'documents.edit']);
$as($staff);
check('الموظف بلا صلاحية التاريخ اليدوي', !can('manual_date'));
$fd = document_edit_form($pdo, $doc($old['id']));
check_eq('  النموذج يحمل التاريخ الأصلي', $back, $fd['doc_date']);
$fd['lines'][0]['quantity'] = '2';
$nd = document_replace($pdo, $staff, $old['id'], $fd, '');
check_eq('  والبديل بنفس التاريخ', $back, substr((string) $doc($nd['id'])['doc_date'], 0, 10));
$fd2 = document_edit_form($pdo, $doc($nd['id']));
$fd2['doc_date'] = date('Y-m-d', strtotime('-5 days'));
expect_validation('  وتاريخ آخر يتطلب صلاحية التاريخ اليدوي', fn () => document_replace($pdo, $staff, $nd['id'], $fd2, ''));
check('  ولم يتغير المستند', $doc($nd['id'])['status'] === 'active');

section('الصلاحيات: الكل أو مستنداته اليوم فقط');
$as($admin);
user_set_permissions($pdo, $admin, $staff, ['stock.view', 'documents.create', 'documents.view', 'catalog.view', 'documents.edit_own', 'documents.cancel_own']);
$adminSale = record_sale($pdo, $admin, sale_input($wh1, [[$item, 1, '1000']]));
$as($staff);
$mine = record_sale($pdo, $staff, sale_input($wh1, [[$item, 1, '1000']]));
check('الموظف يعدل ويلغي فاتورته اليوم', document_edit_problem($pdo, $doc($mine['id'])) === null && document_cancel_permitted($doc($mine['id'])));
check('  ولا يعدل ولا يلغي فاتورة غيره', document_edit_problem($pdo, $doc($adminSale['id'])) !== null && !document_cancel_permitted($doc($adminSale['id'])));
expect_validation('  وطلب تعديل مباشر لفاتورة غيره مرفوض', fn () => document_replace($pdo, $staff, $adminSale['id'], document_edit_form($pdo, $doc($adminSale['id'])), ''));
check('  وفاتورة غيره ما زالت سارية', $doc($adminSale['id'])['status'] === 'active');
$pdo->exec("UPDATE documents SET created_at = created_at - INTERVAL 1 DAY WHERE id = {$mine['id']}");
check('  ولا فاتورته من أمس', document_edit_problem($pdo, $doc($mine['id'])) !== null && !document_cancel_permitted($doc($mine['id'])));
$as($admin);
user_set_permissions($pdo, $admin, $staff, ['stock.view', 'documents.create', 'documents.view', 'catalog.view']);
$as($staff);
check('بلا صلاحيات التعديل والإلغاء: لا شيء', document_edit_problem($pdo, $doc($adminSale['id'])) !== null && !document_cancel_permitted($doc($adminSale['id'])));

section('تعديل شراء نقدي بتاريخ سابق: القيود العكسية بتاريخ الأصلي');
$as($admin);
$box2 = cash_box_create($pdo, $admin, 'خزنة المشتريات', '300');
$backBuy = date('Y-m-d', strtotime('-4 days'));
$pdo->exec("UPDATE cash_ledger SET entry_date = '" . date('Y-m-d', strtotime('-10 days')) . " 08:00:00' WHERE cash_box_id = $box2");
$buy = record_receipt($pdo, $admin, receipt_input($wh1, $mosky, '30', 'cm', '5', 'cm', '2', 'm', '10',
    ['cost_per_m3' => '1000', 'payment_type' => 'cash', 'cash_box_id' => (string) $box2, 'doc_date' => $backBuy]));
check_eq('الشراء استهلك رصيد الخزنة (300.00 = 10 × 0.03 × 1000)', 0, money_to_piasters((string) $q("SELECT balance FROM cash_boxes WHERE id = $box2")));
$fb = document_edit_form($pdo, $doc($buy['id']));
$fb['quantity'] = '9';
$nb = document_replace($pdo, $admin, $buy['id'], $fb, 'كمية');
check('التعديل مسموح (لم يُرفض بسبب رصيد الخزنة في ذلك اليوم)', $doc($nb['id'])['status'] === 'active');
check_eq('  القيود العكسية بتاريخ الشراء الأصلي، والبديل بنفس اليوم', [$backBuy, $backBuy],
    [substr((string) $q("SELECT entry_date FROM cash_ledger WHERE cash_box_id = $box2 AND entry_type = 'reversal'"), 0, 10),
     substr((string) $q("SELECT MAX(entry_date) FROM cash_ledger WHERE cash_box_id = $box2 AND document_id = {$nb['id']}"), 0, 10)]);
check_eq('  والخزنة بقي فيها 30.00', 3000, money_to_piasters((string) $q("SELECT balance FROM cash_boxes WHERE id = $box2")));
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));

section('تعديل السندات: قبض ومصروف');
$as($admin);
$cashBox = cash_box_create($pdo, $admin, 'خزنة السندات', '1000');
$custV = party_create($pdo, $admin, 'customer', ['name' => 'عميل السندات', 'opening_balance' => '500', 'opening_direction' => 'owes_us']);
$vin = fn (array $f) => $f + ['reference' => '', 'notes' => '', 'request_token' => new_request_token()];
$col = record_voucher($pdo, $admin, 'collect', $vin(['party_id' => (string) $custV, 'cash_box_id' => (string) $cashBox, 'amount' => '200']));
$vrow = fn (int $id) => $pdo->query("SELECT * FROM vouchers WHERE id = $id")->fetch();
check_eq('قبل التعديل: العميل عليه 300.00 والخزنة 1200.00', [30000, 120000], [party_balance($pdo, $custV), money_to_piasters((string) $q("SELECT balance FROM cash_boxes WHERE id = $cashBox"))]);
$vf = voucher_edit_form(find_voucher($pdo, $col['id']));
check_eq('نموذج تعديل السند يحمل بياناته', [(string) $custV, (string) $cashBox, '200'], [$vf['party_id'], $vf['cash_box_id'], $vf['amount']]);
$vf['amount'] = '250';
$nv = voucher_replace($pdo, $admin, $col['id'], $vf, 'خطأ في المبلغ');
check('السند الأصلي ملغى ومربوط بالبديل', $vrow($col['id'])['status'] === 'cancelled' && (int) $vrow($col['id'])['replaced_by_id'] === $nv['id']
    && (int) $vrow($nv['id'])['replaces_id'] === $col['id']);
check_eq('بعد التعديل: العميل عليه 250.00 والخزنة 1250.00', [25000, 125000], [party_balance($pdo, $custV), money_to_piasters((string) $q("SELECT balance FROM cash_boxes WHERE id = $cashBox"))]);
check('  والتعديل في المراقبة', (int) $q("SELECT COUNT(*) FROM audit_log WHERE action = 'voucher.edit'") === 1);
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));
$dupV = voucher_replace($pdo, $admin, $col['id'], $vf, '');
check('إعادة إرسال تعديل السند لا تكرر شيئًا', $dupV['duplicate'] === true && party_balance($pdo, $custV) === 25000);
$vf2 = voucher_edit_form(find_voucher($pdo, $nv['id']));
$vf2['cash_box_id'] = '999999';
expect_validation('خزنة غير صالحة: لا يتغير شيء', fn () => voucher_replace($pdo, $admin, $nv['id'], $vf2, ''));
check('  والسند البديل ما زال ساريًا', $vrow($nv['id'])['status'] === 'active' && party_balance($pdo, $custV) === 25000);

$staffV = user_create($pdo, $admin, ['display_name' => 'محصل', 'username' => 'staff_vouch', 'role' => 'staff', 'password' => 'Staff-Pass-002', 'password_confirm' => 'Staff-Pass-002']);
user_set_permissions($pdo, $admin, $staffV, ['vouchers.view', 'vouchers.collect', 'parties.view', 'statements.view', 'vouchers.edit_own', 'vouchers.cancel_own']);
$as($staffV);
$own = record_voucher($pdo, $staffV, 'collect', $vin(['party_id' => (string) $custV, 'cash_box_id' => (string) $cashBox, 'amount' => '10']));
check('الموظف يعدل ويلغي سنده اليوم', voucher_edit_problem(find_voucher($pdo, $own['id'])) === null && voucher_cancel_permitted(find_voucher($pdo, $own['id'])));
check('  ولا يعدل ولا يلغي سند غيره', voucher_edit_problem(find_voucher($pdo, $nv['id'])) !== null && !voucher_cancel_permitted(find_voucher($pdo, $nv['id'])));
expect_validation('  وطلب إلغاء مباشر لسند غيره مرفوض', fn () => cancel_voucher($pdo, $staffV, $nv['id'], ''));
cancel_voucher($pdo, $staffV, $own['id'], 'خطأ');
check('  وإلغاء سنده نجح', $vrow($own['id'])['status'] === 'cancelled');
$as($admin);

section('تعديل سند قبض نُقلت نقديته من الخزنة');
$as($admin);
$boxB = cash_box_create($pdo, $admin, 'خزنة المحصل', '0');
$boxC = cash_box_create($pdo, $admin, 'خزنة الإدارة', '0');
$balOf = fn (int $id): int => money_to_piasters((string) $q("SELECT balance FROM cash_boxes WHERE id = $id"));
$colB = record_voucher($pdo, $admin, 'collect', $vin(['party_id' => (string) $custV, 'cash_box_id' => (string) $boxB, 'amount' => '100']));
record_voucher($pdo, $admin, 'cash_transfer', $vin(['cash_box_id' => (string) $boxB, 'to_cash_box_id' => (string) $boxC, 'amount' => '100']));
check_eq('النقدية نُقلت: خزنة المحصل صفر', 0, $balOf($boxB));
$fb = voucher_edit_form(find_voucher($pdo, $colB['id']));
$fb['amount'] = '120';
$nb = voucher_replace($pdo, $admin, $colB['id'], $fb, 'المبلغ 120');
check_eq('زيادة المبلغ تُحفظ: البديل يدخل 120.00 قبل رد 100.00، فالخزنة 20.00', 2000, $balOf($boxB));
check_eq('  والعميل عليه 130.00', 13000, party_balance($pdo, $custV));
$fb2 = voucher_edit_form(find_voucher($pdo, $nb['id']));
$fb2['amount'] = '80';
$errors = expect_validation('إنقاص المبلغ إلى 80.00 يحتاج خصم 40.00 والخزنة فيها 20.00: مرفوض', fn () => voucher_replace($pdo, $admin, $nb['id'], $fb2, ''));
check('  برسالة حفظ التعديل ورصيد الخزنة', str_contains($errors['voucher'] ?? '', 'لا يمكن حفظ التعديل') && str_contains($errors['voucher'] ?? '', 'خزنة المحصل'),
    json_encode($errors, JSON_UNESCAPED_UNICODE));
check('  ولم يتغير شيء', $vrow($nb['id'])['status'] === 'active' && $balOf($boxB) === 2000 && party_balance($pdo, $custV) === 13000);
$fb2['amount'] = '100';
voucher_replace($pdo, $admin, $nb['id'], $fb2, '');
check_eq('إنقاصه إلى 100.00 (يكفي رصيد الخزنة) يُحفظ: الخزنة صفر', 0, $balOf($boxB));
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));

section('تعديل وارد بيع جزء منه: تصحيح التكلفة والكمية');
$as($admin);
$zan = catalog_create($pdo, 'type', 'زان');
$sup = party_create($pdo, $admin, 'supplier', ['name' => 'مورد التعديل']);
$val = fn (int $id): int => money_to_piasters((string) $q("SELECT inventory_value FROM items WHERE id = $id"));
$adjOf = fn (int $id): int => money_to_piasters((string) $q("SELECT COALESCE(SUM(amount), 0) FROM cost_adjustments WHERE item_id = $id"));
$rz = record_receipt($pdo, $admin, receipt_input($wh1, $zan, '10', 'cm', '50', 'mm', '3', 'm', '10',
    ['cost_per_m3' => '10000', 'party_id' => (string) $sup, 'payment_type' => 'credit']));
$z = item_id_for($pdo, $zan, 100000, 50000, 3000000); // القطعة 0.015 م³
check_eq('الوارد: ١٠ قطع بقيمة 1500.00 والمورد له 1500.00', [10, 150000, 150000], [$stock($z, $wh1), $val($z), party_balance($pdo, $sup)]);
$sz = record_sale($pdo, $admin, sale_input($wh1, [[$z, 4, '20000']]));
check_eq('بيع ٤: الباقي ٦ بقيمة 900.00', [6, 90000], [$stock($z, $wh1), $val($z)]);
$fz = document_edit_form($pdo, $doc($rz['id']));
check_eq('نموذج الوارد يحمل التكلفة', '10000', $fz['cost_per_m3']);
$fz['cost_per_m3'] = '12000';
$nz = document_replace($pdo, $admin, $rz['id'], $fz, 'التكلفة 12000');
check_eq('تصحيح التكلفة بعد البيع يُحفظ: الباقي ٦ بقيمة 1080.00 بالتكلفة الجديدة', [6, 108000], [$stock($z, $wh1), $val($z)]);
check_eq('  وفرق تكلفة القطع الأربع المباعة 120.00 في فروق التكلفة', 12000, $adjOf($z));
check_eq('  والمورد له 1800.00', 180000, party_balance($pdo, $sup));
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));
$fz2 = document_edit_form($pdo, $doc($nz['id']));
$fz2['quantity'] = '8';
$nz2 = document_replace($pdo, $admin, $nz['id'], $fz2, 'الكمية ٨');
check_eq('إنقاص الكمية إلى ٨ وقد بيع منها ٤: الباقي ٤ بقيمة 720.00', [4, 72000], [$stock($z, $wh1), $val($z)]);
check_eq('  وفروق التكلفة كما هي (نفس التكلفة)', 12000, $adjOf($z));
check_eq('  والمورد له 1440.00', 144000, party_balance($pdo, $sup));
$fz3 = document_edit_form($pdo, $doc($nz2['id']));
$fz3['quantity'] = '3';
$errors = expect_validation('كمية ٣ أقل من المباع (٤): مرفوض', fn () => document_replace($pdo, $admin, $nz2['id'], $fz3, ''));
check('  برسالة حفظ التعديل والرصيد', str_contains($errors['document'] ?? '', 'لا يمكن حفظ التعديل'), json_encode($errors, JSON_UNESCAPED_UNICODE));
check('  ولم يتغير شيء', $doc($nz2['id'])['status'] === 'active' && $stock($z, $wh1) === 4 && $val($z) === 72000 && party_balance($pdo, $sup) === 144000);
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));
cancel_document($pdo, $admin, $sz['id'], 'مرتجع');
check_eq('إلغاء البيع القديم بعد التعديل: ٨ قطع بقيمة 1440.00 (كلها بالتكلفة المصححة)', [8, 144000], [$stock($z, $wh1), $val($z)]);
check_eq('  وفرق التكلفة عُكس (المجموع صفر)', 0, $adjOf($z));

section('تعديل وارد بلا تكلفة لم يُبع منه شيء: إضافة التكلفة');
$rb = record_receipt($pdo, $admin, receipt_input($wh1, $zan, '20', 'cm', '50', 'mm', '3', 'm', '5'));
$zb = item_id_for($pdo, $zan, 200000, 50000, 3000000); // 0.03 م³
check_eq('بلا تكلفة: القيمة صفر', [5, 0], [$stock($zb, $wh1), $val($zb)]);
$fb = document_edit_form($pdo, $doc($rb['id']));
$fb['cost_per_m3'] = '10000';
$fb['party_id'] = (string) $sup;
$fb['payment_type'] = 'credit';
document_replace($pdo, $admin, $rb['id'], $fb, 'نسيت التكلفة');
check_eq('بعد إضافة التكلفة: ٥ قطع بقيمة 1500.00', [5, 150000], [$stock($zb, $wh1), $val($zb)]);
check_eq('  ولا فروق تكلفة', 0, $adjOf($zb));

section('تعديل بيع نقدي نُقلت نقديته من الخزنة');
$boxS = cash_box_create($pdo, $admin, 'خزنة البيع', '0');
$boxT = cash_box_create($pdo, $admin, 'خزنة الإدارة ٢', '0');
$cs = record_sale($pdo, $admin, sale_input($wh1, [[$zb, 1, '20000']], ['payment_type' => 'cash', 'cash_box_id' => (string) $boxS]));
check_eq('بيع نقدي 600.00 في خزنة البيع', 60000, $balOf($boxS));
record_voucher($pdo, $admin, 'cash_transfer', $vin(['cash_box_id' => (string) $boxS, 'to_cash_box_id' => (string) $boxT, 'amount' => '600']));
$fs = document_edit_form($pdo, $doc($cs['id']));
$fs['lines'][0]['price'] = '22000';
$ns = document_replace($pdo, $admin, $cs['id'], $fs, 'السعر');
check_eq('رفع السعر يُحفظ: البديل يدخل 660.00 قبل رد 600.00، فالخزنة 60.00', 6000, $balOf($boxS));
check_eq('  والرصيد ٤ (البيع ١ كما هو)', 4, $stock($zb, $wh1));
$fs2 = document_edit_form($pdo, $doc($ns['id']));
$fs2['lines'][0]['price'] = '18000';
$errors = expect_validation('خفض السعر إلى 540.00 يحتاج رد 120.00 والخزنة فيها 60.00: مرفوض', fn () => document_replace($pdo, $admin, $ns['id'], $fs2, ''));
check('  برسالة حفظ التعديل والخزنة', str_contains($errors['document'] ?? '', 'لا يمكن حفظ التعديل') && str_contains($errors['document'] ?? '', 'خزنة البيع'),
    json_encode($errors, JSON_UNESCAPED_UNICODE));
check('  ولم يتغير شيء', $doc($ns['id'])['status'] === 'active' && $balOf($boxS) === 6000 && $stock($zb, $wh1) === 4);
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));

section('تعديل تحويل باع المخزن المستلم جزءًا منه');
$rt = record_receipt($pdo, $admin, receipt_input($wh1, $zan, '30', 'cm', '50', 'mm', '3', 'm', '10',
    ['cost_per_m3' => '10000', 'party_id' => (string) $sup, 'payment_type' => 'credit']));
$zt = item_id_for($pdo, $zan, 300000, 50000, 3000000);
$tt = record_transfer($pdo, $admin, transfer_input($wh1, $wh2, [[$zt, 10]]));
record_sale($pdo, $admin, sale_input($wh2, [[$zt, 6, '20000']]));
$ftt = document_edit_form($pdo, $doc($tt['id']));
$ftt['lines'][0]['quantity'] = '8';
document_replace($pdo, $admin, $tt['id'], $ftt, 'حُوّل ٨ فقط');
check_eq('التحويل ٨ بدل ١٠ وقد بيع ٦: المصدر ٢ والمستلم ٢', [2, 2], [$stock($zt, $wh1), $stock($zt, $wh2)]);
$ftt2 = document_edit_form($pdo, $doc((int) $q("SELECT replaced_by_id FROM documents WHERE id = {$tt['id']}")));
$ftt2['lines'][0]['quantity'] = '5';
$errors = expect_validation('تحويل ٥ أقل من المباع في المستلم (٦): مرفوض', fn () => document_replace($pdo, $admin, (int) $ftt2['edit_id'], $ftt2, ''));
check('  ولم يتغير شيء', $stock($zt, $wh1) === 2 && $stock($zt, $wh2) === 2);
check_eq('  ولا اختلاف في الأرصدة', [], acct_verify_balances($pdo));

section('الفترة المقفلة');
$as($admin);
save_setting($pdo, 'closing_date', date('Y-m-d', strtotime('-2 days')));
reset_settings_cache();
check('مستند في فترة مقفلة لا يُعدل', str_contains((string) document_edit_problem($pdo, $doc($nd['id'])), 'مقفلة'));
save_setting($pdo, 'closing_date', '');
reset_settings_cache();
check_eq('لا اختلاف في الأرصدة في النهاية', [], acct_verify_balances($pdo));

finish();
