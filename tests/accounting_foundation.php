<?php
/* اختبار أساس الحسابات: ترقية 006 والدوال المشتركة في app/lib/accounting.php */
declare(strict_types=1);
require __DIR__ . '/lib.php';
$pdo = fresh_database();
$uid = seed_user($pdo);
$wh = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$type = catalog_create($pdo, 'type', 'زان');

section('ترقية 006');
check('جداول الحسابات موجودة', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('parties','cash_boxes','expense_categories','vouchers','party_ledger','cash_ledger','cost_adjustments')")->fetchColumn() === 7);
check_eq('الخزنة الرئيسية أُنشئت', 'الخزنة الرئيسية', $pdo->query('SELECT name FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn());
check_eq('مفتاح اسم الخزنة يطابق name_key في PHP', name_key('الخزنة الرئيسية'), $pdo->query('SELECT name_key FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn());
foreach ($pdo->query('SELECT name, name_key FROM expense_categories')->fetchAll() as $c) {
    check_eq('مفتاح التصنيف ' . $c['name'], name_key($c['name']), $c['name_key']);
}

section('المبالغ');
check_eq('money_to_piasters', [12345, -12345, 1000, 5, 50, 0], [money_to_piasters('123.45'), money_to_piasters('-123.45'), money_to_piasters('10'), money_to_piasters('0.05'), money_to_piasters('0.5'), money_to_piasters('0.00')]);
check_eq('piasters_to_money', ['123.45', '-123.45', '0.05', '0.00', '-0.05'], [piasters_to_money(12345), piasters_to_money(-12345), piasters_to_money(5), piasters_to_money(0), piasters_to_money(-5)]);
check_eq('muldiv_half_up يقرب النصف لأعلى', [3, 2, 0, 1000], [muldiv_half_up(5, 1, 2), muldiv_half_up(10, 1, 6), muldiv_half_up(0, 7, 3), muldiv_half_up(1000, 7, 7)]);
check_eq('muldiv_half_up بلا فيضان', 4611686018427387904, muldiv_half_up(PHP_INT_MAX, 2, 4));
check_eq('parse_money_input', [150050, null], parse_money_input('1500.50', 'المبلغ'));
check_eq('parse_money_input بالأرقام العربية', [150050, null], parse_money_input('١٥٠٠٫٥٠', 'المبلغ'));
check('parse_money_input يرفض الصفر', parse_money_input('0', 'المبلغ')[0] === null);
check_eq('parse_money_input يقبل الصفر عند السماح', [0, null], parse_money_input('0', 'المبلغ', true));
check('parse_money_input يرفض السالب', parse_money_input('-5', 'المبلغ')[0] === null);

section('الدفاتر والأرصدة');
$pdo->prepare("INSERT INTO parties (kind, name, name_key, opening_balance, balance, created_at) VALUES ('customer', 'عميل ١', ?, 100.00, 100.00, NOW())")->execute([name_key('عميل ١')]);
$cid = (int) $pdo->lastInsertId();
$box = (int) $pdo->query('SELECT id FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn();
$r = record_receipt($pdo, $uid, receipt_input($wh, $type, '10', 'cm', '50', 'mm', '3', 'm', '10'));
$docId = (int) $r['id'];
db_transaction($pdo, function (PDO $pdo) use ($cid, $box, $docId) {
    lock_party($pdo, $cid, 'customer');
    lock_cash_boxes($pdo, [$box]);
    ledger_party($pdo, $cid, now(), $docId, null, 'sale', 50000, 'اختبار');
    ledger_cash($pdo, $box, now(), $docId, null, 'sale', 20000, 'اختبار');
    ledger_party($pdo, $cid, now(), $docId, null, 'sale_payment', -20000, 'اختبار');
});
check_eq('رصيد العميل = افتتاحي + قيود', 100 * 100 + 50000 - 20000, party_balance($pdo, $cid));
check_eq('رصيد الخزنة', 20000, cash_balance($pdo, $box));
check_eq('فحص السلامة سليم', [], acct_verify_balances($pdo));
expect_validation('الخروج من الخزنة أكثر من رصيدها مرفوض', fn () => db_transaction($pdo, function (PDO $pdo) use ($box, $docId) {
    lock_cash_boxes($pdo, [$box]);
    ledger_cash($pdo, $box, now(), $docId, null, 'purchase', -20001, 'اختبار');
}));
check_eq('  والرصيد لم يتغير', 20000, cash_balance($pdo, $box));
db_transaction($pdo, function (PDO $pdo) use ($cid, $box, $docId) {
    $src = ledger_sources_of($pdo, 'document', $docId);
    lock_party($pdo, $src['parties'][0], 'customer', false);
    lock_cash_boxes($pdo, $src['cash_boxes'], false);
    reverse_ledgers($pdo, 'document', $docId, now(), 'إلغاء');
});
check_eq('القيود العكسية تعيد رصيد العميل', 10000, party_balance($pdo, $cid));
check_eq('  وتعيد رصيد الخزنة', 0, cash_balance($pdo, $box));
check_eq('  ثلاثة قيود عكسية + ثلاثة أصلية', 6, (int) $pdo->query('SELECT (SELECT COUNT(*) FROM party_ledger) + (SELECT COUNT(*) FROM cash_ledger)')->fetchColumn());
check_eq('فحص السلامة سليم بعد العكس', [], acct_verify_balances($pdo));
$pdo->exec("UPDATE parties SET balance = balance + 1 WHERE id = $cid");
check('فحص السلامة يكتشف الاختلاف', count(acct_verify_balances($pdo)) === 1);

section('التاريخ المحاسبي');
$e = [];
check_eq('فارغ = الآن', substr(now(), 0, 16), substr(acct_doc_date([], $e), 0, 16));
check_eq('  بلا أخطاء', [], $e);
$e = [];
$d = acct_doc_date(['doc_date' => date('Y-m-d', time() - 86400 * 3)], $e);
check_eq('تاريخ يدوي سابق مقبول (بدون طبقة صلاحيات = مسموح)', date('Y-m-d', time() - 86400 * 3), substr($d, 0, 10));
$e = [];
acct_doc_date(['doc_date' => date('Y-m-d', time() + 86400 * 2)], $e);
check('تاريخ في المستقبل مرفوض', isset($e['doc_date']));
$e = [];
acct_doc_date(['doc_date' => '2026-02-30'], $e);
check('تاريخ غير صحيح مرفوض', isset($e['doc_date']));
save_setting($pdo, 'closing_date', date('Y-m-d', time() - 86400));
reset_settings_cache();
$e = [];
acct_doc_date(['doc_date' => date('Y-m-d', time() - 86400 * 3)], $e);
check('تاريخ في فترة مقفلة مرفوض', isset($e['doc_date']));
check('اليوم مفتوح بعد إقفال الأمس', period_is_open(now()));
save_setting($pdo, 'closing_date', '');
reset_settings_cache();

section('المستندات القديمة');
check_eq('doc_date = created_at للمستند المسجل', $pdo->query("SELECT created_at FROM documents WHERE id = $docId")->fetchColumn(), $pdo->query("SELECT doc_date FROM documents WHERE id = $docId")->fetchColumn());

finish();
