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
$f2['quantity'] = '6';
$errors = expect_validation('وارد بيع منه جزء لا يُعدل إذا أصبح الرصيد سالبًا بالإلغاء', fn () => document_replace($pdo, $admin, $nr['id'], $f2, ''));
check('  رسالة الرصيد', str_contains($errors['document'] ?? '', 'لا يمكن إلغاء'), json_encode($errors, JSON_UNESCAPED_UNICODE));
check('  ولم يتغير شيء', $doc($nr['id'])['status'] === 'active' && $stock($right, $wh2) === 2);

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

section('الفترة المقفلة');
$as($admin);
save_setting($pdo, 'closing_date', date('Y-m-d', strtotime('-2 days')));
reset_settings_cache();
check('مستند في فترة مقفلة لا يُعدل', str_contains((string) document_edit_problem($pdo, $doc($nd['id'])), 'مقفلة'));
save_setting($pdo, 'closing_date', '');
reset_settings_cache();
check_eq('لا اختلاف في الأرصدة في النهاية', [], acct_verify_balances($pdo));

finish();
