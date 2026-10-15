<?php
declare(strict_types=1);

/*
 * تكلفة المخزون (ACCOUNTING_SPEC.md البند 2): المخزون بدون تكلفة والتقييم الافتتاحي، وإلغاء الوارد المقيّم
 * افتتاحيًا، والأصناف التي خُصمت تكلفتها، والترقية 010.
 * التشغيل: WOOD_TEST_DB=wood_costing php tests/integration_costing.php
 */

require __DIR__ . '/lib.php';

$pdo = fresh_database();
$admin = seed_user($pdo);
$staff = seed_user($pdo, 'staff');
$pdo->exec("UPDATE users SET role = 'admin' WHERE id = $admin");
$pdo->exec("UPDATE users SET role = 'staff' WHERE id = $staff");
$as = function (int $id) use ($pdo): void {
    $u = session_user_row($pdo, $id);
    $_SESSION = ['user_id' => $id, 'username' => (string) $u['username'], 'role' => user_role($u), 'perm_overrides' => user_permission_overrides($u)];
    reset_branch_scope_cache();
};
$as($admin);

$q = fn (string $sql) => $pdo->query($sql)->fetchColumn();
$wh1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$wh2 = catalog_create($pdo, 'warehouse', 'مخزن الفرع');
$types = [];
foreach (['موسكي', 'زان', 'سويد', 'بلوط', 'ماهوجني', 'أرو', 'كونتر', 'عزيزي', 'بياض', 'جوز'] as $name) {
    $types[] = catalog_create($pdo, 'type', $name);
}
$sup = party_create($pdo, $admin, 'supplier', ['name' => 'مورد التكلفة']);

/** قيمة المخزون V بالقروش، وعدد القطع Q، ومجموع فروق التكلفة للصنف بالقروش */
$V = fn (int $item): int => money_to_piasters((string) $q("SELECT inventory_value FROM items WHERE id = $item"));
$Q = fn (int $item): int => (int) $q("SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock WHERE item_id = $item");
$adj = fn (int $item): int => money_to_piasters((string) $q("SELECT COALESCE(SUM(amount), 0) FROM cost_adjustments WHERE item_id = $item"));
$adjRows = fn (int $item): int => (int) $q("SELECT COUNT(*) FROM cost_adjustments WHERE item_id = $item");
$net = fn (): int => money_to_piasters(acct_report($pdo, 'profit', [])['totals']['amount']);
$listed = fn (int $item): bool => in_array($item, array_map(fn ($r) => (int) $r['id'], unvalued_items($pdo)), true);
$dash = fn (): int => acct_dashboard($pdo)['unvalued_items'];
$status = function (int $item) use ($pdo): ?string {
    $it = $pdo->query("SELECT i.*, t.name AS type_name FROM items i JOIN wood_types t ON t.id = i.wood_type_id WHERE i.id = $item")->fetch();
    foreach (acct_report($pdo, 'valuation', [])['rows'] as $r) {
        if ($r['type_name'] === $it['type_name'] && $r['size'] === fmt_size($it)) {
            return $r['status'];
        }
    }
    return null;
};
$doc = fn (int $id): array => $pdo->query("SELECT * FROM documents WHERE id = $id")->fetch();
/** وارد: [معرف المستند، معرف الصنف]. الأبعاد بالسنتيمتر والطول بالمتر */
$receive = function (int $type, string $w, string $t, string $l, int $n, array $extra = [], ?int $wh = null) use ($pdo): array {
    $r = record_receipt($pdo, current_user_id(), receipt_input($wh ?? $GLOBALS['wh1'], $type, $w, 'cm', $t, 'cm', $l, 'm', (string) $n, $extra));
    $line = document_lines($pdo, $r['id'])[0];
    return [$r['id'], (int) $line['item_id']];
};
$costed = fn (string $cost): array => ['cost_per_m3' => $cost, 'party_id' => (string) $sup, 'payment_type' => 'credit'];
$sell = fn (int $item, int $n, string $price, ?int $wh = null): int => record_sale($pdo, current_user_id(), sale_input($wh ?? $wh1, [[$item, $n, $price]]))['id'];
$cancel = fn (int $id) => cancel_document($pdo, current_user_id(), $id, 'تجربة');
/** خطأ غير متوقع في قسم: يُسجل فشلًا ويكمل باقي الأقسام */
function unexpected(Throwable $ex): void
{
    check('بلا خطأ غير متوقع', false, get_class($ex) . ': ' . ($ex instanceof ValidationException ? json_encode($ex->errors, JSON_UNESCAPED_UNICODE) : $ex->getMessage()));
}

/* ---------------------------------------------------------------- */
section('مخزون افتتاحي بدون تكلفة: لا يُقبل وارد بتكلفة قبل تقييمه');
try {
    // القطعة 10 × 5 × 3 = 0.015 م³: 100 قطعة = 1.5 م³
    $dash0 = $dash();
    [$open1, $a] = $receive($types[0], '10', '5', '3', 100, ['notes' => 'رصيد افتتاحي']);
    check_eq('الوارد الافتتاحي: V = 0 و Q = 100', [0, 100], [$V($a), $Q($a)]);
    check('  يظهر في التقييم الافتتاحي وتحذير لوحة التحكم', $listed($a) && $dash() === $dash0 + 1 && $status($a) === 'غير مقيّم');
    $as($staff);
    $docsBefore = (int) $q('SELECT COUNT(*) FROM documents');
    $e = expect_validation('الموظف: شراء بتكلفة لنفس المقاس قبل التقييم مرفوض', fn () => $receive($types[0], '10', '5', '3', 10, $costed('20000')));
    $msg = $e['cost_per_m3'] ?? '';
    check('  الرسالة تطلب تقييم الرصيد من المدير من «التقييم الافتتاحي للمخزون»', str_contains($msg, 'المدير') && str_contains($msg, 'التقييم الافتتاحي للمخزون'), $msg);
    check_eq('  لم يُسجل شيء: المستندات والقيمة والكمية ورصيد المورد', [$docsBefore, 0, 100, 0],
        [(int) $q('SELECT COUNT(*) FROM documents'), $V($a), $Q($a), party_balance($pdo, $sup)]);
    check('  وما زال يظهر في التقييم الافتتاحي والتحذير', $listed($a) && $dash() === $dash0 + 1);
    $as($admin);
    expect_validation('والمدير أيضًا (يقيّم أولًا)', fn () => $receive($types[0], '10', '5', '3', 10, $costed('20000')));
    [, $same] = $receive($types[0], '10', '5', '3', 5);
    check_eq('وارد بدون تكلفة لنفس المقاس مقبول ويبقى بلا قيمة (105 قطعة)', [$a, 0, 105], [$same, $V($a), $Q($a)]);
    $as($staff);
    expect_validation('الموظف لا يقيّم بنفسه', fn () => set_opening_valuation($pdo, $staff, $a, '20000'));
    $as($admin);
    $res = set_opening_valuation($pdo, $admin, $a, '20000');
    check_eq('المدير يقيّم: 105 × 0.015 = 1.575 م³ × 20000 = 31500.00', ['item_id' => $a, 'qty' => 105, 'value' => 3150000], $res);
    check('  لم يعد في التقييم الافتتاحي ولا التحذير', !$listed($a) && $dash() === $dash0 && $status($a) === 'مقيّم');
    check_eq('  تكلفة التقييم محفوظة', '20000.00', (string) $q("SELECT opening_cost_per_m3 FROM items WHERE id = $a"));
    $as($staff);
    [$buy, ] = $receive($types[0], '10', '5', '3', 10, $costed('20000'));
    check_eq('بعد التقييم: شراء الموظف 10 قطع × 0.015 × 20000 = 3000.00 مقبول', [3450000, 115], [$V($a), $Q($a)]);
    $as($admin);
    $s = $sell($a, 50, '25000');
    check_eq('بيع 50: تكلفة 34500 × 50 ÷ 115 = 15000.00 (بالتكلفة الحقيقية 300 للقطعة)', '15000.00', (string) document_lines($pdo, $s)[0]['cost_amount']);
} catch (Throwable $ex) {
    unexpected($ex);
}

/* ---------------------------------------------------------------- */
section('إلغاء بيع بتكلفة صفر قبل التقييم يعيد القطع بتكلفة التقييم');
try {
    // القطعة 10 × 5 × 4 = 0.02 م³
    [, $b] = $receive($types[1], '10', '5', '4', 100);
    $s0 = $sell($b, 50, '30000');
    check_eq('بيع اليوم الأول قبل التقييم: تكلفة صفر', '0.00', (string) document_lines($pdo, $s0)[0]['cost_amount']);
    set_opening_valuation($pdo, $admin, $b, '15000');
    check_eq('تقييم الباقي 50 قطعة = 1 م³ × 15000 = 15000.00', [1500000, 50], [$V($b), $Q($b)]);
    $cancel($s0);
    check_eq('إلغاء بيع اليوم الأول: V = 30000.00 و Q = 100 (300 للقطعة)', [3000000, 100], [$V($b), $Q($b)]);
    check('  الصنف مقيّم ولا يظهر في التقييم الافتتاحي', !$listed($b) && $status($b) === 'مقيّم');
    $sAll = $sell($b, 100, '30000');
    check_eq('بيع كل الـ 100: تكلفة 30000.00', '30000.00', (string) document_lines($pdo, $sAll)[0]['cost_amount']);
} catch (Throwable $ex) {
    unexpected($ex);
}

/* ---------------------------------------------------------------- */
section('إلغاء وارد افتتاحي مقيّم يخرج قيمة التقييم');
try {
    // القطعة 10 × 5 × 5 = 0.025 م³: 100 قطعة = 2.5 م³ × 12000 = 30000.00، و 10 قطع = 3000.00
    [$o3, $c] = $receive($types[2], '10', '5', '5', 100);
    set_opening_valuation($pdo, $admin, $c, '12000');
    $receive($types[2], '10', '5', '5', 10, $costed('12000'));
    check_eq('V = 33000.00 و Q = 110', [3300000, 110], [$V($c), $Q($c)]);
    $net0 = $net();
    $cancel($o3);
    check_eq('إلغاء الوارد الافتتاحي (مخزن خاطئ): V = 3000.00 و Q = 10 (300 للقطعة)', [300000, 10], [$V($c), $Q($c)]);
    check_eq('  بلا فرق تكلفة ولا تغيير في الربح', [0, $net0], [$adjRows($c), $net()]);
    [$o3b, ] = $receive($types[2], '10', '5', '5', 100, [], $wh2);
    check_eq('إعادة إدخاله في المخزن الصحيح بدون تكلفة: بالمتوسط 3000 × 100 ÷ 10، فـ V = 33000.00 و Q = 110', [3300000, 110], [$V($c), $Q($c)]);
    check_eq('  تكلفة سطره 30000.00', '30000.00', (string) document_lines($pdo, $o3b)[0]['cost_amount']);

    // القطعة 10 × 5 × 6 = 0.03 م³: 100 قطعة = 3 م³ × 10000 = 30000.00
    [$o4, $d] = $receive($types[3], '10', '5', '6', 100);
    set_opening_valuation($pdo, $admin, $d, '10000');
    $net0 = $net();
    $cancel($o4);
    check_eq('إلغاء وارد افتتاحي مقيّم وحده: V = 0 و Q = 0', [0, 0], [$V($d), $Q($d)]);
    check_eq('  بلا فرق تكلفة (لا خسارة وهمية) والربح كما هو', [0, $net0], [$adjRows($d), $net()]);
} catch (Throwable $ex) {
    unexpected($ex);
}

/* ---------------------------------------------------------------- */
section('تعديل وارد افتتاحي مقيّم (نقله لمخزن آخر)');
try {
    // نفس أرقام الحالة السابقة على صنف جديد: القطعة 0.015 م³ (10 × 5 × 3)، النوع الخامس
    [$o5, $e5] = $receive($types[4], '10', '5', '3', 100);
    set_opening_valuation($pdo, $admin, $e5, '20000');
    $receive($types[4], '10', '5', '3', 10, $costed('20000'));
    $form = document_edit_form($pdo, $doc($o5));
    $form['warehouse_id'] = (string) $wh2;
    $net0 = $net();
    $new = document_replace($pdo, $admin, $o5, $form, 'مخزن خاطئ');
    check('التعديل تم: الأصلي ملغى والبديل في المخزن الثاني', $doc($o5)['status'] === 'cancelled' && (int) $doc($new['id'])['warehouse_id'] === $wh2);
    check_eq('  V = 33000.00 و Q = 110 كما كانت (300 للقطعة)', [3300000, 110], [$V($e5), $Q($e5)]);
    check_eq('  بلا فرق تكلفة والربح كما هو', [0, $net0], [$adjRows($e5), $net()]);
    // صنف بلا رصيد آخر: التعديل يعيده بلا قيمة (يُقيَّم من جديد) بدون خسارة وهمية
    [$o6, $f6] = $receive($types[5], '10', '5', '3', 100);
    set_opening_valuation($pdo, $admin, $f6, '20000');
    $form = document_edit_form($pdo, $doc($o6));
    $form['notes'] = 'رصيد افتتاحي بعد التصحيح';
    $net0 = $net();
    document_replace($pdo, $admin, $o6, $form, '');
    check_eq('تعديل الوارد الافتتاحي الوحيد: V = 0 و Q = 100 ويظهر للتقييم من جديد', [0, 100, true], [$V($f6), $Q($f6), $listed($f6)]);
    check_eq('  بلا فرق تكلفة والربح كما هو', [0, $net0], [$adjRows($f6), $net()]);
} catch (Throwable $ex) {
    unexpected($ex);
}

/* ---------------------------------------------------------------- */
section('صنف صُفّرت قيمته بإلغاء وارد بعد بيع جزء منه');
try {
    // القطعة 0.0001 م³: 50 قطعة × 100000 = 500.00، وارد خاطئ 50 قطعة × 10000000 = 50000.00
    $typeH = catalog_create($pdo, 'type', 'صنف الوارد الخاطئ');
    [, $h] = $receive($typeH, '1', '1', '1', 50, $costed('100000'));
    [$typo, ] = $receive($typeH, '1', '1', '1', 50, $costed('10000000'));
    $net0 = $net();
    $sh = $sell($h, 20, '200000'); // 400.00، تكلفة 50500 × 20 ÷ 100 = 10100.00
    check_eq('بيع 20: تكلفة 10100.00', '10100.00', (string) document_lines($pdo, $sh)[0]['cost_amount']);
    $dash0 = $dash();
    $cancel($typo);
    check_eq('إلغاء الوارد الخاطئ: V = 0 و Q = 30 وفرق −9600.00', [0, 30, -960000], [$V($h), $Q($h), $adj($h)]);
    check('  لا يظهر كرصيد بدون تكلفة: التقييم الافتتاحي ولوحة التحكم', !$listed($h) && $dash() === $dash0);
    check_eq('  حالته في تقييم المخزون: تكلفته محسوبة في الأرباح', 'تكلفته محسوبة في الأرباح', $status($h));
    $e = expect_validation('  التقييم الافتتاحي له مرفوض (لا تُحسب تكلفته مرتين)', fn () => set_opening_valuation($pdo, $admin, $h, '100000'));
    check('  والرسالة تذكر أن تكلفته محسوبة في الأرباح', str_contains($e['form'] ?? '', 'خُصمت بالفعل من الأرباح'), $e['form'] ?? '');
    $as($staff);
    $receive($typeH, '1', '1', '1', 10, $costed('100000')); // 100.00
    $as($admin);
    check_eq('وارد بتكلفة لهذا الصنف مقبول: V = 100.00 و Q = 40', [10000, 40], [$V($h), $Q($h)]);
    $sh2 = $sell($h, 40, '200000'); // 800.00
    check_eq('بيع الـ 40: تكلفة 100.00', '100.00', (string) document_lines($pdo, $sh2)[0]['cost_amount']);
    // الحقيقة: شراء 60 قطعة بـ 600.00 وبيعها بـ 1200.00
    check_eq('صافي الربح 600.00 (الإيراد 1200.00 − التكلفة 10200.00 + فرق 9600.00)', 60000, $net() - $net0);
    $receive($typeH, '1', '1', '1', 5);
    check_eq('وارد جديد بدون تكلفة بعد نفاد الكمية: رصيد بدون تكلفة يظهر للتقييم', [0, 5, true, 'غير مقيّم'], [$V($h), $Q($h), $listed($h), $status($h)]);

    // قيمة صارت صفرًا بالتقريب في البيع: القطعة 0.0001 م³، قطعتان × 50 = 0.01
    $typeR = catalog_create($pdo, 'type', 'صنف التقريب');
    [, $rr] = $receive($typeR, '1', '1', '1', 2, $costed('50'));
    check_eq('V = 0.01 و Q = 2', [1, 2], [$V($rr), $Q($rr)]);
    $sell($rr, 1, '100000');
    check_eq('بيع قطعة: تكلفة 0.01 بالتقريب فتبقى V = 0 و Q = 1', [0, 1], [$V($rr), $Q($rr)]);
    check('  تكلفته في الأرباح، فلا يظهر للتقييم', !$listed($rr) && $status($rr) === 'تكلفته محسوبة في الأرباح');
} catch (Throwable $ex) {
    unexpected($ex);
}

/* ---------------------------------------------------------------- */
section('إلغاء بيع يعيد قيمة لصنف رصيده بدون تكلفة');
try {
    // القطعة 1 × 1 × 400 سم = 0.0004 م³: 10 قطع × 100000 = 400.00
    $typeI = catalog_create($pdo, 'type', 'صنف الإلغاء');
    [, $i] = $receive($typeI, '1', '1', '4', 10, $costed('100000'));
    $si = $sell($i, 10, '200000');
    $receive($typeI, '1', '1', '4', 5); // رصيد جديد بدون تكلفة
    check_eq('V = 0 و Q = 5 ويظهر للتقييم', [0, 5, true], [$V($i), $Q($i), $listed($i)]);
    $as($staff);
    $e = expect_validation('الموظف: إلغاء البيع القديم (يعيد قطعًا بتكلفة 400.00) مرفوض قبل التقييم', fn () => $cancel($si));
    check('  الرسالة تذكر المقاس وتطلب التقييم من المدير', str_contains($e['document'] ?? '', 'صنف الإلغاء') && str_contains($e['document'] ?? '', 'المدير')
        && str_contains($e['document'] ?? '', 'التقييم الافتتاحي للمخزون'), $e['document'] ?? '');
    check_eq('  لم يتغير شيء', ['active', 0, 5], [$doc($si)['status'], $V($i), $Q($i)]);
    $as($admin);
    $form = document_edit_form($pdo, $doc($si));
    $form['lines'][0]['price'] = '210000';
    expect_validation('تعديل الفاتورة مرفوض أيضًا قبل التقييم', fn () => document_replace($pdo, $admin, $si, $form, ''));
    check_eq('  لم يتغير شيء', ['active', 0, 5], [$doc($si)['status'], $V($i), $Q($i)]);
    set_opening_valuation($pdo, $admin, $i, '100000'); // 5 × 0.0004 = 0.002 م³ = 200.00
    $form['request_token'] = new_request_token();
    $ni = document_replace($pdo, $admin, $si, $form, '');
    check_eq('بعد التقييم: التعديل تم، وتكلفة البديل 600 × 10 ÷ 15 = 400.00', '400.00', (string) document_lines($pdo, $ni['id'])[0]['cost_amount']);
    check_eq('  V = 200.00 و Q = 5', [20000, 5], [$V($i), $Q($i)]);
} catch (Throwable $ex) {
    unexpected($ex);
}

/* ---------------------------------------------------------------- */
section('الترقية 010: آمنة لإعادة التنفيذ، وتقرأ الحالة السابقة');
try {
    // صنف صُفّرت قيمته بعد تقييم افتتاحي وشراء ثم إلغاء الشراء بعد بيع
    // (الترقية تقارن أوقات المستندات بالثانية، فيُنتظر ثانية بين الوارد الافتتاحي وإلغاء الشراء كما في البيانات الحقيقية)
    $typeM = catalog_create($pdo, 'type', 'صنف الترقية');
    [, $m] = $receive($typeM, '1', '1', '1', 10);
    set_opening_valuation($pdo, $admin, $m, '100000');                     // 100.00
    [$pm, ] = $receive($typeM, '1', '1', '1', 10, $costed('1000000'));      // 1000.00
    $sell($m, 5, '200000');                                                // تكلفة 1100 × 5 ÷ 20 = 275.00
    sleep(1);
    $cancel($pm);                                                          // V = 825 − 1000 < 0
    check_eq('صنف الترقية: V = 0 و Q = 5 وتكلفته في الأرباح', [0, 5, false], [$V($m), $Q($m), $listed($m)]);
    // صنف بدون تكلفة له فرق «أقل من صفر» من إلغاء وارد قديم: يبقى بدون تكلفة
    $typeN = catalog_create($pdo, 'type', 'صنف بدون تكلفة');
    [$rn, $n] = $receive($typeN, '1', '1', '1', 10, $costed('100000'));
    $sell($n, 10, '200000');
    $receive($typeN, '1', '1', '1', 20);
    $cancel($rn);
    check_eq('صنف بدون تكلفة بعد إلغاء وارد قديم: V = 0 و Q = 10 ويظهر للتقييم', [0, 10, true], [$V($n), $Q($n), $listed($n)]);
    $before = $pdo->query('SELECT id, opening_cost_per_m3, opening_valued_at, cost_written_off FROM items ORDER BY id')->fetchAll();
    // قاعدة من قبل الترقية: الأعمدة الجديدة فارغة
    $pdo->exec('UPDATE items SET opening_cost_per_m3 = NULL, opening_valued_at = NULL, cost_written_off = 0');
    $ok = true;
    foreach ([1, 2] as $run) {
        foreach (split_sql((string) file_get_contents(APP_ROOT . '/migrations/010_costing.sql')) as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $ex) {
                $ok = false;
                echo '    ' . $ex->getMessage() . "\n";
            }
        }
    }
    check('إعادة تنفيذ أوامرها مرتين بلا أخطاء', $ok);
    $after = $pdo->query('SELECT id, opening_cost_per_m3, opening_valued_at, cost_written_off FROM items ORDER BY id')->fetchAll();
    $diff = [];
    foreach ($before as $k => $row) {
        $x = $after[$k];
        $timeOk = ($row['opening_valued_at'] === null && $x['opening_valued_at'] === null)
            || ($row['opening_valued_at'] !== null && $x['opening_valued_at'] !== null && abs(strtotime($row['opening_valued_at']) - strtotime($x['opening_valued_at'])) <= 2);
        if ($row['opening_cost_per_m3'] !== $x['opening_cost_per_m3'] || !$timeOk) {
            $diff[] = [$row, $x];
        }
    }
    check_eq('تكلفة التقييم الافتتاحي ووقته من سجل المراقبة كما كانت', [], $diff);
    $flag = fn (int $item): int => (int) $q("SELECT cost_written_off FROM items WHERE id = $item");
    check_eq('  الصنف الذي صُفّرت قيمته بإلغاء وارد مميز، والأصناف بدون تكلفة لا', [1, 0, 0, 0], [$flag($m), $flag($n), $flag($h ?? 0), $flag($f6 ?? 0)]);
    check('  فيظهر في التقييم الافتتاحي ما كان يظهر فيه فقط', !$listed($m) && $listed($n) && $listed($h ?? 0) && $listed($f6 ?? 0));
} catch (Throwable $ex) {
    unexpected($ex);
}

section('سلامة الأرصدة');
check_eq('acct_verify_balances() = []', [], acct_verify_balances($pdo));
check_eq('لا قيمة مخزون سالبة', 0, (int) $q('SELECT COUNT(*) FROM items WHERE inventory_value < 0'));

finish();
