<?php
declare(strict_types=1);

/*
 * اختبارات الوحدة ب: العملاء والموردون، الخزائن، السندات، الكشوف، والمبلغ بالحروف.
 * التشغيل: WOOD_TEST_DB=wood_acct_b php tests/integration_vouchers.php
 */

require __DIR__ . '/lib.php';

$pdo = fresh_database();
$uid = seed_user($pdo);
$box = (int) $pdo->query('SELECT id FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn();
$rent = (int) $pdo->query("SELECT id FROM expense_categories WHERE name = 'إيجار'")->fetchColumn();

function vin(array $fields): array
{
    return $fields + ['amount' => '', 'reference' => '', 'notes' => '', 'voucher_date' => '', 'request_token' => new_request_token()];
}

function count_vouchers(PDO $pdo, string $kind): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM vouchers WHERE kind = ?');
    $stmt->execute([$kind]);
    return (int) $stmt->fetchColumn();
}

function day_offset(int $days): string
{
    return date('Y-m-d', time() + 86400 * $days);
}

/** يشغل عمليتين متوازيتين بعد قفل صف بواسطة root حتى تنتظرا معًا، ثم يحرر القفل ويعيد النتيجتين */
function run_barrier(string $lockSql, array $lockArgs, array $cmds): array
{
    $root = root_pdo();
    $root->beginTransaction();
    $root->prepare($lockSql)->execute($lockArgs);
    $procs = [];
    $pipes = [];
    foreach ($cmds as $k => $cmd) {
        $procs[$k] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    $waiting = 0;
    for ($t = 0; $t < 100 && $waiting < count($cmds); $t++) {
        usleep(100000);
        $waiting = (int) $root->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
    }
    $root->commit();
    $out = [];
    foreach ($procs as $k => $p) {
        $out[] = json_decode(trim((string) stream_get_contents($pipes[$k][1])), true) ?? ['ok' => false, 'raw' => stream_get_contents($pipes[$k][2])];
        proc_close($p);
    }
    return ['waiting' => $waiting, 'results' => $out];
}

/* ---------------------------------------------------------------- */
section('المبلغ بالحروف');
$words = [
    50 => 'فقط خمسون قرشًا لا غير',
    100 => 'فقط جنيه واحد لا غير',
    200 => 'فقط جنيهان لا غير',
    300 => 'فقط ثلاثة جنيهات لا غير',
    1000 => 'فقط عشرة جنيهات لا غير',
    1100 => 'فقط أحد عشر جنيهًا لا غير',
    10000 => 'فقط مائة جنيه لا غير',
    10100 => 'فقط مائة وواحد جنيه لا غير',
    100000 => 'فقط ألف جنيه لا غير',
    200000 => 'فقط ألفا جنيه لا غير',
    150050 => 'فقط ألف وخمسمائة جنيه وخمسون قرشًا لا غير',
    100000000 => 'فقط مليون جنيه لا غير',
    234567899 => 'فقط مليونان وثلاثمائة وخمسة وأربعون ألفًا وستمائة وثمانية وسبعون جنيهًا وتسعة وتسعون قرشًا لا غير',
    0 => 'فقط صفر جنيه لا غير',
    1 => 'فقط قرش واحد لا غير',
    1200 => 'فقط اثنا عشر جنيهًا لا غير',
    2000 => 'فقط عشرون جنيهًا لا غير',
    2100 => 'فقط واحد وعشرون جنيهًا لا غير',
    20000 => 'فقط مائتا جنيه لا غير',
    300000 => 'فقط ثلاثة آلاف جنيه لا غير',
    1100000 => 'فقط أحد عشر ألف جنيه لا غير',
    15000000 => 'فقط مائة وخمسون ألف جنيه لا غير',
    20000000 => 'فقط مائتا ألف جنيه لا غير',
    100000000000 => 'فقط مليار جنيه لا غير',
];
foreach ($words as $p => $expected) {
    check_eq('بالحروف ' . piasters_to_money($p), $expected, amount_in_words_ar($p));
}

/* ---------------------------------------------------------------- */
section('العملاء والموردون');
$cust = party_create($pdo, $uid, 'customer', ['name' => 'شركة النجار', 'phone' => '0100', 'opening_balance' => '1000.00', 'opening_direction' => 'owes_us']);
check_eq('رصيد العميل الافتتاحي 1000.00 عليه', 100000, party_balance($pdo, $cust));
$supp = party_create($pdo, $uid, 'supplier', ['name' => 'شركة النجار', 'opening_balance' => '500', 'opening_direction' => 'we_owe']);
check('نفس الاسم مسموح كعميل وكمورد', $supp > 0 && $supp !== $cust);
check_eq('رصيد المورد 500.00 له (موجب)', 50000, party_balance($pdo, $supp));
check_eq('عرض رصيد المورد', fmt_money('500') . ' له', fmt_party_balance(50000, 'supplier'));
$errs = expect_validation('اسم عميل مكرر بمسافات زائدة مرفوض', fn () => party_create($pdo, $uid, 'customer', ['name' => '  شركة   النجار ']));
check('  رسالة واضحة', str_contains($errs['name'] ?? '', 'بنفس الاسم'));
expect_validation('رصيد افتتاحي سالب مرفوض', fn () => party_create($pdo, $uid, 'customer', ['name' => 'س', 'opening_balance' => '-5', 'opening_direction' => 'owes_us']));
expect_validation('اتجاه غير صحيح مرفوض', fn () => party_create($pdo, $uid, 'customer', ['name' => 'س', 'opening_balance' => '5', 'opening_direction' => 'x']));
$adv = party_create($pdo, $uid, 'customer', ['name' => 'عميل مقدم', 'opening_balance' => '20', 'opening_direction' => 'we_owe', 'credit_limit' => '5000']);
check_eq('عميل له رصيد دائن (له) = سالب', -2000, party_balance($pdo, $adv));
check_eq('حد الائتمان محفوظ', '5000.00', (string) party_find($pdo, $adv)['credit_limit']);

/* ---------------------------------------------------------------- */
section('سند قبض: افتتاحي 1000.00 ثم قبض 400.00');
$c1 = record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '400.00']));
check_eq('رقم سند القبض الأول = 1', 1, $c1['doc_no']);
check_eq('رصيد العميل 600.00', 60000, party_balance($pdo, $cust));
check_eq('الخزنة +400.00', 40000, cash_balance($pdo, $box));
$vr = find_voucher($pdo, $c1['id']);
check_eq('المبلغ في السند', '400.00', (string) $vr['amount']);
check_eq('اسم العميل محفوظ في السند', 'شركة النجار', $vr['party_name']);
check_eq('قيد العميل -400.00 (collect)', ['collect', '-400.00'], array_values($pdo->query("SELECT entry_type, amount FROM party_ledger WHERE voucher_id = {$c1['id']}")->fetch()));
check_eq('قيد الخزنة +400.00 (collect)', ['collect', '400.00'], array_values($pdo->query("SELECT entry_type, amount FROM cash_ledger WHERE voucher_id = {$c1['id']}")->fetch()));
expect_validation('قبض بمبلغ صفر مرفوض', fn () => record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '0'])));
expect_validation('قبض من مورد (نوع خطأ) مرفوض', fn () => record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $supp, 'cash_box_id' => (string) $box, 'amount' => '1'])));
expect_validation('قبض بثلاث خانات عشرية مرفوض', fn () => record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '1.005'])));

section('سند صرف: يُرفض إذا لم تكفِ الخزنة، ويُقبل بعد قبض');
$errs = expect_validation('صرف 500.00 والخزنة 400.00 مرفوض', fn () => record_voucher($pdo, $uid, 'pay', vin(['party_id' => (string) $supp, 'cash_box_id' => (string) $box, 'amount' => '500'])));
check('  الرسالة تذكر رصيد الخزنة', str_contains($errs['cash_box_id'] ?? '', 'لا يكفي'), json_encode($errs, JSON_UNESCAPED_UNICODE));
check_eq('  لم يُسجل سند صرف', 0, count_vouchers($pdo, 'pay'));
check_eq('  الخزنة لم تتغير', 40000, cash_balance($pdo, $box));
check_eq('  رصيد المورد لم يتغير', 50000, party_balance($pdo, $supp));
$c2 = record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '300']));
check_eq('قبض ثانٍ رقمه 2', 2, $c2['doc_no']);
check_eq('الخزنة 700.00', 70000, cash_balance($pdo, $box));
$p1 = record_voucher($pdo, $uid, 'pay', vin(['party_id' => (string) $supp, 'cash_box_id' => (string) $box, 'amount' => '500']));
check_eq('سند الصرف الأول رقمه 1 رغم فشل المحاولة السابقة (بلا فجوات)', 1, $p1['doc_no']);
check_eq('رصيد المورد 0.00', 0, party_balance($pdo, $supp));
check_eq('الخزنة 200.00', 20000, cash_balance($pdo, $box));

section('مصروف');
$errs = expect_validation('مصروف بلا بيان مرفوض', fn () => record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $box, 'amount' => '50'])));
check('  رسالة البيان', isset($errs['notes']));
expect_validation('مصروف بلا تصنيف مرفوض', fn () => record_voucher($pdo, $uid, 'expense', vin(['category_id' => '0', 'cash_box_id' => (string) $box, 'amount' => '50', 'notes' => 'x'])));
$e1 = record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $box, 'amount' => '50', 'notes' => 'إيجار الشهر']));
check_eq('رقم المصروف الأول 1', 1, $e1['doc_no']);
check_eq('الخزنة 150.00 بعد المصروف', 15000, cash_balance($pdo, $box));
check_eq('اسم التصنيف محفوظ', 'إيجار', find_voucher($pdo, $e1['id'])['category_name']);
expect_validation('مصروف أكبر من الخزنة مرفوض', fn () => record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $box, 'amount' => '150.01', 'notes' => 'x'])));
check_eq('  رقم المصروف التالي ما زال 2 (العداد لم يُستهلك)', 1, (int) $pdo->query("SELECT value FROM counters WHERE name = 'doc_expense'")->fetchColumn());

section('تحويل نقدية بين خزنتين');
$box2 = cash_box_create($pdo, $uid, 'خزنة الفرع', '25.50');
check_eq('رصيد افتتاحي للخزنة الجديدة 25.50', 2550, cash_balance($pdo, $box2));
expect_validation('رصيد افتتاحي سالب للخزنة مرفوض', fn () => cash_box_create($pdo, $uid, 'خزنة س', '-1'));
expect_validation('اسم خزنة مكرر مرفوض', fn () => cash_box_create($pdo, $uid, 'خزنة الفرع', ''));
$t1 = record_voucher($pdo, $uid, 'cash_transfer', vin(['cash_box_id' => (string) $box, 'to_cash_box_id' => (string) $box2, 'amount' => '100']));
check_eq('رقم التحويل الأول 1', 1, $t1['doc_no']);
check_eq('الخزنة الرئيسية 50.00', 5000, cash_balance($pdo, $box));
check_eq('خزنة الفرع 125.50', 12550, cash_balance($pdo, $box2));
expect_validation('تحويل 50.01 والمتاح 50.00 مرفوض', fn () => record_voucher($pdo, $uid, 'cash_transfer', vin(['cash_box_id' => (string) $box, 'to_cash_box_id' => (string) $box2, 'amount' => '50.01'])));
check_eq('  الخزنتان لم تتغيرا', [5000, 12550], [cash_balance($pdo, $box), cash_balance($pdo, $box2)]);
expect_validation('تحويل لنفس الخزنة مرفوض', fn () => record_voucher($pdo, $uid, 'cash_transfer', vin(['cash_box_id' => (string) $box, 'to_cash_box_id' => (string) $box, 'amount' => '1'])));
$t2 = record_voucher($pdo, $uid, 'cash_transfer', vin(['cash_box_id' => (string) $box, 'to_cash_box_id' => (string) $box2, 'amount' => '50.00']));
check_eq('تحويل كل الرصيد بالضبط مقبول (الرئيسية 0.00)', 0, cash_balance($pdo, $box));
check_eq('  رقمه 2', 2, $t2['doc_no']);

/* ---------------------------------------------------------------- */
section('الإلغاء يعكس القيود بالضبط');
$origRows = $pdo->query("SELECT id, amount FROM party_ledger WHERE voucher_id = {$p1['id']} UNION ALL SELECT id, amount FROM cash_ledger WHERE voucher_id = {$p1['id']}")->fetchAll();
$errs = expect_validation('إلغاء قبض والخزنة لا تكفي للرد مرفوض', fn () => cancel_voucher($pdo, $uid, $c2['id'], ''));
check('  الرسالة تذكر الخزنة', str_contains($errs['voucher'] ?? '', 'لا يكفي'), json_encode($errs, JSON_UNESCAPED_UNICODE));
check_eq('  السند ما زال ساريًا', 'active', find_voucher($pdo, $c2['id'])['status']);
cancel_voucher($pdo, $uid, $p1['id'], 'خطأ في المبلغ');
check_eq('إلغاء الصرف يعيد رصيد المورد 500.00', 50000, party_balance($pdo, $supp));
check_eq('  ويعيد 500.00 للخزنة', 50000, cash_balance($pdo, $box));
$rev = $pdo->query("SELECT COUNT(*) FROM party_ledger WHERE voucher_id = {$p1['id']} AND entry_type = 'reversal' AND amount = 500.00 AND reversal_of IS NOT NULL")->fetchColumn()
    + $pdo->query("SELECT COUNT(*) FROM cash_ledger WHERE voucher_id = {$p1['id']} AND entry_type = 'reversal' AND amount = 500.00 AND reversal_of IS NOT NULL")->fetchColumn();
check_eq('  قيدان عكسيان', 2, (int) $rev);
$after = $pdo->query("SELECT id, amount FROM party_ledger WHERE voucher_id = {$p1['id']} AND entry_type <> 'reversal' UNION ALL SELECT id, amount FROM cash_ledger WHERE voucher_id = {$p1['id']} AND entry_type <> 'reversal'")->fetchAll();
check_eq('  القيود الأصلية لم تتغير', $origRows, $after);
$pv = find_voucher($pdo, $p1['id']);
check_eq('  السند ملغى مع السبب', ['cancelled', 'خطأ في المبلغ'], [$pv['status'], $pv['cancel_reason']]);
expect_validation('إلغاء مرة ثانية مرفوض', fn () => cancel_voucher($pdo, $uid, $p1['id'], ''));
cancel_voucher($pdo, $uid, $c1['id'], '');
check_eq('إلغاء القبض الأول: العميل 700.00', 70000, party_balance($pdo, $cust));
check_eq('  الخزنة 100.00', 10000, cash_balance($pdo, $box));
cancel_voucher($pdo, $uid, $t1['id'], '');
check_eq('إلغاء التحويل الأول: الرئيسية 200.00', 20000, cash_balance($pdo, $box));
check_eq('  الفرع 75.50', 7550, cash_balance($pdo, $box2));
cancel_voucher($pdo, $uid, $e1['id'], '');
check_eq('إلغاء المصروف يعيد 50.00 (الرئيسية 250.00)', 25000, cash_balance($pdo, $box));
check_eq('فحص السلامة بعد الإلغاءات', [], acct_verify_balances($pdo));

section('الإلغاء في فترة مقفلة');
$old = record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $box, 'amount' => '10', 'notes' => 'قديم', 'voucher_date' => day_offset(-3)]));
check_eq('سند بتاريخ يدوي سابق', day_offset(-3), substr(find_voucher($pdo, $old['id'])['voucher_date'], 0, 10));
check_eq('  رقمه 2 (الترقيم متتالٍ بعد الإلغاء)', 2, $old['doc_no']);
save_setting($pdo, 'closing_date', day_offset(-2));
reset_settings_cache();
$errs = expect_validation('إلغاء سند تاريخه في فترة مقفلة مرفوض', fn () => cancel_voucher($pdo, $uid, $old['id'], ''));
check('  رسالة الفترة المقفلة', str_contains($errs['voucher'] ?? '', 'مقفلة'));
check_eq('  السند ما زال ساريًا', 'active', find_voucher($pdo, $old['id'])['status']);
expect_validation('تسجيل سند بتاريخ في فترة مقفلة مرفوض', fn () => record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $box, 'amount' => '1', 'notes' => 'x', 'voucher_date' => day_offset(-3)])));
save_setting($pdo, 'closing_date', '');
reset_settings_cache();

section('الترقيم المتتالي لكل نوع');
foreach (array_keys(VOUCHER_COUNTERS) as $k) {
    $nos = $pdo->query("SELECT doc_no FROM vouchers WHERE kind = '$k' ORDER BY doc_no")->fetchAll(PDO::FETCH_COLUMN);
    check_eq("أرقام {$k} متتالية بلا فجوات", $nos ? range(1, count($nos)) : [], array_map('intval', $nos));
}

/* ---------------------------------------------------------------- */
section('منع التكرار');
$in = vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '10']);
$a = record_voucher($pdo, $uid, 'collect', $in);
$b = record_voucher($pdo, $uid, 'collect', $in);
check('نفس الرمز ونفس المحتوى يعيد نفس السند', $b['duplicate'] === true && $b['id'] === $a['id']);
check_eq('  القبض حدث مرة واحدة (690.00)', 69000, party_balance($pdo, $cust));
$errs = expect_validation('نفس الرمز بمبلغ مختلف مرفوض', fn () => record_voucher($pdo, $uid, 'collect', ['amount' => '11'] + $in));
check('  رسالة واضحة تذكر السند المحفوظ', str_contains($errs['request_token'] ?? '', 'حُفظ سابقًا'));
expect_validation('نفس الرمز بنوع سند آخر مرفوض', fn () => record_voucher($pdo, $uid, 'pay', ['party_id' => (string) $supp] + $in));

section('التزامن: نفس الطلب يُرسل مرتين في نفس اللحظة');
$tok = vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '5']);
$cmd = [PHP_BINARY, __DIR__ . '/worker_vouchers.php', 'record', 'collect', json_encode($tok, JSON_UNESCAPED_UNICODE)];
$bar = run_barrier('SELECT id FROM parties WHERE id = ? FOR UPDATE', [$cust], [$cmd, $cmd]);
check('العمليتان تنتظران نفس القفل فعلًا', $bar['waiting'] === 2, 'waiting=' . $bar['waiting']);
[$r1, $r2] = $bar['results'];
check('الاثنان يعيدان نفس السند', ($r1['ok'] ?? false) && ($r2['ok'] ?? false) && $r1['id'] === $r2['id'], json_encode($bar['results'], JSON_UNESCAPED_UNICODE));
check_eq('  سند واحد بهذا الرمز', 1, (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE request_token = '{$tok['request_token']}'")->fetchColumn());
check_eq('  القبض مرة واحدة (685.00)', 68500, party_balance($pdo, $cust));

section('التزامن: سندا صرف والخزنة تكفي واحدًا فقط');
$supp2 = party_create($pdo, $uid, 'supplier', ['name' => 'مورد التزامن', 'opening_balance' => '1000', 'opening_direction' => 'we_owe']);
$box3 = cash_box_create($pdo, $uid, 'خزنة التزامن', '100.00');
for ($round = 1; $round <= 2; $round++) {
    $cmds = [];
    for ($k = 0; $k < 2; $k++) {
        $cmds[] = [PHP_BINARY, __DIR__ . '/worker_vouchers.php', 'record', 'pay',
            json_encode(vin(['party_id' => (string) $supp2, 'cash_box_id' => (string) $box3, 'amount' => '60.00']))];
    }
    $before = cash_balance($pdo, $box3);
    $bar = run_barrier('SELECT id FROM cash_boxes WHERE id = ? FOR UPDATE', [$box3], $cmds);
    check("الجولة {$round}: العمليتان تنتظران فعلًا", $bar['waiting'] === 2, 'waiting=' . $bar['waiting']);
    $ok = count(array_filter($bar['results'], fn ($r) => $r['ok'] ?? false));
    check_eq("الجولة {$round}: نجح سند واحد فقط", 1, $ok);
    check_eq("الجولة {$round}: الخزنة نقصت 60.00 فقط", $before - 6000, cash_balance($pdo, $box3));
    check("الجولة {$round}: الخزنة ليست سالبة", cash_balance($pdo, $box3) >= 0);
    // تجهيز الجولة التالية: قبض يعيد الخزنة إلى 100.00
    if ($round === 1) {
        record_voucher($pdo, $uid, 'cash_transfer', vin(['cash_box_id' => (string) $box, 'to_cash_box_id' => (string) $box3, 'amount' => '60']));
    }
}
check_eq('رصيد مورد التزامن 1000 - 2×60 = 880.00', 88000, party_balance($pdo, $supp2));
$nos = $pdo->query("SELECT doc_no FROM vouchers WHERE kind = 'pay' ORDER BY doc_no")->fetchAll(PDO::FETCH_COLUMN);
check_eq('أرقام الصرف متتالية بعد المحاولات المرفوضة المتزامنة', range(1, count($nos)), array_map('intval', $nos));

section('إلغاء متزامن لنفس السند');
$cx = record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $cust, 'cash_box_id' => (string) $box, 'amount' => '1']));
$cmd = [PHP_BINARY, __DIR__ . '/worker_vouchers.php', 'cancel', (string) $cx['id']];
$bar = run_barrier('SELECT id FROM vouchers WHERE id = ? FOR UPDATE', [$cx['id']], [$cmd, $cmd]);
check_eq('إلغاء واحد فقط نجح', 1, count(array_filter($bar['results'], fn ($r) => $r['ok'] ?? false)));
check_eq('  القيود العكسية مرة واحدة', 2, (int) $pdo->query("SELECT (SELECT COUNT(*) FROM party_ledger WHERE voucher_id = {$cx['id']} AND entry_type = 'reversal') + (SELECT COUNT(*) FROM cash_ledger WHERE voucher_id = {$cx['id']} AND entry_type = 'reversal')")->fetchColumn());

/* ---------------------------------------------------------------- */
section('كشف حساب عميل لفترة');
$st = party_create($pdo, $uid, 'customer', ['name' => 'عميل الكشف', 'opening_balance' => '1000', 'opening_direction' => 'owes_us']);
record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $st, 'cash_box_id' => (string) $box, 'amount' => '100', 'voucher_date' => day_offset(-5)]));
$s2 = record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $st, 'cash_box_id' => (string) $box, 'amount' => '200', 'voucher_date' => day_offset(-3)]));
record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $st, 'cash_box_id' => (string) $box, 'amount' => '300.25']));
$s = party_statement($pdo, $st, day_offset(-4), day_offset(0));
check_eq('الرصيد أول الفترة 900.00 (1000 - 100 قبلها)', 90000, $s['opening']);
check_eq('حركتان في الفترة', 2, count($s['rows']));
check_eq('الرصيد الجاري بعد كل حركة', [70000, 39975], array_column($s['rows'], 'balance'));
check_eq('عمود «له» للعميل (القبض)', [20000, 30025], array_column($s['rows'], 'lah'));
check_eq('إجمالي «له» 500.25 و«عليه» 0', [50025, 0], [$s['total_lah'], $s['total_alayh']]);
check_eq('الرصيد آخر الفترة 399.75', 39975, $s['closing']);
check_eq('رابط الحركة للسند', ['voucher', $s2['id']], [$s['rows'][0]['link']['route'], $s['rows'][0]['link']['id']]);
$s = party_statement($pdo, $st, null, day_offset(-4));
check_eq('كشف حتى قبل 4 أيام: افتتاحي 1000 وحركة واحدة وختامي 900.00', [100000, 1, 90000], [$s['opening'], count($s['rows']), $s['closing']]);
cancel_voucher($pdo, $uid, $s2['id'], '');
$s = party_statement($pdo, $st, null, null);
check_eq('الكشف الكامل بعد إلغاء: ختامي = الرصيد المخزن', party_balance($pdo, $st), $s['closing']);
check_eq('  4 حركات (3 قبض + قيد عكسي)', 4, count($s['rows']));
check_eq('  القيد العكسي في عمود «عليه» 200.00', 20000, $s['total_alayh']);
$ss = party_statement($pdo, $supp, null, null);
check_eq('كشف المورد: الصرف في «عليه» والعكس في «له»', [50000, 50000, 50000], [$ss['total_alayh'], $ss['total_lah'], $ss['closing']]);

section('كشف حركة خزنة');
$bx = cash_box_create($pdo, $uid, 'خزنة الكشف', '10');
record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $st, 'cash_box_id' => (string) $bx, 'amount' => '40', 'voucher_date' => day_offset(-2)]));
record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $bx, 'amount' => '15', 'notes' => 'x', 'voucher_date' => day_offset(-2)]));
record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $st, 'cash_box_id' => (string) $bx, 'amount' => '5']));
$cs = cash_statement($pdo, $bx, null, null);
check_eq('افتتاحي 10.00', 1000, $cs['opening']);
check_eq('داخل 45.00 وخارج 15.00 وختامي 40.00', [4500, 1500, 4000], [$cs['total_in'], $cs['total_out'], $cs['closing']]);
check_eq('الرصيد الجاري', [5000, 3500, 4000], array_column($cs['rows'], 'balance'));
check_eq('حركة يومين', [[day_offset(-2), 4000, 1500, 3500], [day_offset(0), 500, 0, 4000]],
    array_map(fn ($d) => [$d['date'], $d['in'], $d['out'], $d['closing']], $cs['days']));
$cs = cash_statement($pdo, $bx, day_offset(-1), null);
check_eq('كشف من أمس: افتتاحي 35.00 وحركة واحدة', [3500, 1, 4000], [$cs['opening'], count($cs['rows']), $cs['closing']]);
check_eq('ختامي الكشف = رصيد الخزنة', cash_balance($pdo, $bx), $cs['closing']);

/* ---------------------------------------------------------------- */
section('تعديل الرصيد الافتتاحي يحرك الرصيد بالفرق');
$ed = party_create($pdo, $uid, 'customer', ['name' => 'عميل التعديل', 'opening_balance' => '100', 'opening_direction' => 'owes_us']);
record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $ed, 'cash_box_id' => (string) $box, 'amount' => '30']));
check_eq('الرصيد 70.00', 7000, party_balance($pdo, $ed));
party_update($pdo, $uid, $ed, ['name' => 'عميل التعديل', 'opening_balance' => '150', 'opening_direction' => 'owes_us']);
check_eq('افتتاحي 150: الرصيد 120.00', 12000, party_balance($pdo, $ed));
party_update($pdo, $uid, $ed, ['name' => 'عميل التعديل', 'opening_balance' => '20', 'opening_direction' => 'we_owe']);
check_eq('افتتاحي 20 له: الرصيد -50.00 (له)', -5000, party_balance($pdo, $ed));
check_eq('  العرض «له»', fmt_money('50') . ' له', fmt_party_balance(party_balance($pdo, $ed), 'customer'));
expect_validation('تعديل الاسم إلى اسم موجود مرفوض', fn () => party_update($pdo, $uid, $ed, ['name' => 'شركة النجار']));
party_set_active($pdo, $uid, $ed, false);
check_eq('الحساب موقوف', 0, (int) party_find($pdo, $ed)['is_active']);
check('الموقوف لا يظهر في القوائم', !in_array($ed, array_map(fn ($r) => (int) $r['id'], parties_for_select($pdo, 'customer')), true));
$cz = record_voucher($pdo, $uid, 'collect', vin(['party_id' => (string) $ed, 'cash_box_id' => (string) $box, 'amount' => '1']));
check('التحصيل من حساب موقوف مسموح (تسوية)', $cz['id'] > 0);
expect_validation('حذف حساب له حركات مرفوض', fn () => party_delete($pdo, $uid, $ed));
$tmp = party_create($pdo, $uid, 'supplier', ['name' => 'مورد مؤقت']);
party_delete($pdo, $uid, $tmp);
check('حذف حساب بلا حركات', party_find($pdo, $tmp) === null);

section('الخزائن والتصنيفات');
cash_box_rename($pdo, $uid, $box2, 'خزنة الفرع الجديدة');
check_eq('تغيير اسم الخزنة', 'خزنة الفرع الجديدة', cash_box_find($pdo, $box2)['name']);
cash_box_set_active($pdo, $uid, $box2, false);
expect_validation('السند على خزنة موقوفة مرفوض', fn () => record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $rent, 'cash_box_id' => (string) $box2, 'amount' => '1', 'notes' => 'x'])));
cash_box_set_active($pdo, $uid, $box2, true);
$cat = expense_category_create($pdo, $uid, 'ضيافة');
expect_validation('تصنيف مكرر مرفوض', fn () => expense_category_create($pdo, $uid, ' ضيافة '));
expense_category_set_active($pdo, $uid, $cat, false);
expect_validation('مصروف على تصنيف موقوف مرفوض', fn () => record_voucher($pdo, $uid, 'expense', vin(['category_id' => (string) $cat, 'cash_box_id' => (string) $box, 'amount' => '1', 'notes' => 'x'])));

section('السلامة');
check_eq('acct_verify_balances() سليم', [], acct_verify_balances($pdo));
check_eq('لا رصيد خزنة سالب', 0, (int) $pdo->query('SELECT COUNT(*) FROM cash_boxes WHERE balance < 0')->fetchColumn());

finish();
