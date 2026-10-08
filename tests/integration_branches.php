<?php
declare(strict_types=1);

/*
 * اختبارات الفروع على قاعدة بيانات MariaDB حقيقية: إدارة الفروع، ونقل المخازن، واسم الفرع في المستندات،
 * ونطاق المستخدم المقيد بفرع، والإجماليات لكل عملة، والتزامن، وترقية قاعدة بيانات قديمة (001) إلى 005.
 * التشغيل: WOOD_TEST_DB=wood_branches_test php tests/integration_branches.php
 * ترقية القاعدة القديمة تستخدم قاعدة إضافية باسم <WOOD_TEST_DB>_mig وتحذفها في النهاية.
 */

require __DIR__ . '/lib.php';

/** يشغل عمليات worker_branches.php، ويطلق القفل الحاجز بعد أن تنتظر كلها عليه، ويعيد نتائجها */
function run_workers_behind_barrier(PDO $root, array $commands, int $expectWaiting): array
{
    $procs = [];
    $pipes = [];
    foreach ($commands as $k => $args) {
        $procs[$k] = proc_open([PHP_BINARY, __DIR__ . '/worker_branches.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    // انتظار القفل في قاعدة الاختبار فقط: اختبارات أخرى قد تعمل على نفس الخادم في نفس الوقت
    $count = $root->prepare(
        "SELECT COUNT(*) FROM information_schema.INNODB_TRX t
         JOIN information_schema.PROCESSLIST p ON p.ID = t.trx_mysql_thread_id
         WHERE t.trx_state = 'LOCK WAIT' AND p.DB = ?"
    );
    $waiting = 0;
    for ($t = 0; $t < 100 && $waiting < $expectWaiting; $t++) {
        usleep(100000);
        $count->execute([test_db_name()]);
        $waiting = (int) $count->fetchColumn();
    }
    $root->commit();
    $results = [];
    foreach ($procs as $k => $p) {
        $out = trim((string) stream_get_contents($pipes[$k][1]));
        $results[$k] = json_decode($out, true) ?? ['ok' => false, 'raw' => $out . stream_get_contents($pipes[$k][2])];
        proc_close($p);
    }
    return [$waiting, $results];
}

/** يحاكي جلسة مستخدم مسجل في سطر الأوامر (0 = بدون جلسة) */
function act_as(int $userId): void
{
    $_SESSION = $userId > 0 ? ['user_id' => $userId] : [];
    reset_branch_scope_cache();
}

function dv(PDO $pdo): int
{
    return (int) data_version($pdo);
}

$pdo = fresh_database();
$uid = seed_user($pdo);

/* ---------------------------------------------------------------- */
section('الفرع الافتراضي بعد الترقية');
$branches = $pdo->query('SELECT id, name, name_key FROM branches')->fetchAll();
check_eq('فرع واحد افتراضي', 1, count($branches));
check_eq('  اسمه «الفرع الرئيسي»', BRANCH_DEFAULT_NAME, $branches[0]['name'] ?? null);
check_eq('  مفتاحه يطابق name_key() في PHP', name_key(BRANCH_DEFAULT_NAME), $branches[0]['name_key'] ?? null);
$main = (int) $branches[0]['id'];

/* ---------------------------------------------------------------- */
section('حذفان متزامنان لفرعين لا يتركان النظام بلا فروع (حاجز قفل حتمي)');
$second = branch_create($pdo, ['name' => 'فرع مؤقت']);
$root = root_pdo();
$root->beginTransaction();
$root->query('SELECT id FROM branches ORDER BY id FOR UPDATE')->fetchAll();
[$waiting, $res] = run_workers_behind_barrier($root, [['delete_branch', (string) $main], ['delete_branch', (string) $second]], 2);
check('العمليتان انتظرتا نفس القفل فعلًا', $waiting === 2, "waiting={$waiting}");
$ok = array_values(array_filter($res, fn ($r) => $r['ok'] ?? false));
$failed = array_values(array_filter($res, fn ($r) => !($r['ok'] ?? false)));
check_eq('نجح حذف واحد فقط', 1, count($ok));
check_eq('بقي فرع واحد', 1, (int) $pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn());
check('  الحذف الثاني رُفض برسالة واضحة', str_contains($failed[0]['errors']['name'] ?? '', 'يجب أن يبقى فرع واحد'), json_encode($res, JSON_UNESCAPED_UNICODE));
$main = (int) $pdo->query('SELECT id FROM branches')->fetchColumn();
$errors = expect_validation('حذف آخر فرع مرفوض', fn () => branch_delete($pdo, $main));
check('  الرسالة', str_contains($errors['name'] ?? '', 'يجب أن يبقى فرع واحد'));
branch_update($pdo, $main, ['name' => BRANCH_DEFAULT_NAME, 'address' => '', 'phone' => '']);

/* ---------------------------------------------------------------- */
section('إضافة الفروع وتعديلها');
$v = dv($pdo);
$maadi = branch_create($pdo, ['name' => 'فرع المعادي', 'address' => '  شارع 9،   المعادي ', 'phone' => '٠١٠ ١٢٣-٤٥٦٧']);
check_eq('إضافة فرع ترفع رقم الإصدار', $v + 1, dv($pdo));
$b = branch_find($pdo, $maadi);
check_eq('العنوان يُحفظ بعد تنظيف المسافات', 'شارع 9، المعادي', $b['address']);
check_eq('الهاتف بالأرقام العربية يُحفظ بالإنجليزية', '010 123-4567', $b['phone']);
expect_validation('اسم مكرر بمسافات زائدة مرفوض', fn () => branch_create($pdo, ['name' => '  فرع   المعادي ']));
expect_validation('اسم مكرر بالتطويل مرفوض (فرع المعـادي)', fn () => branch_create($pdo, ['name' => 'فرع المعـادي']));
$pres = "\u{FED3}\u{FEAE}\u{FEC9} المعادي"; // «فرع» بأشكال الحروف المنسوخة من PDF
check('  (أشكال الحروف تعطي نفس المفتاح)', name_key($pres) === name_key('فرع المعادي'));
expect_validation('اسم مكرر بأشكال الحروف المنسوخة من PDF مرفوض', fn () => branch_create($pdo, ['name' => $pres]));
$latin = branch_create($pdo, ['name' => 'Branch East']);
$errors = expect_validation('اسم لاتيني مكرر باختلاف حالة الأحرف مرفوض', fn () => branch_create($pdo, ['name' => 'BRANCH east']));
check_eq('  الرسالة', 'يوجد فرع بنفس الاسم.', $errors['name'] ?? null);
$errors = expect_validation('اسم فارغ مرفوض', fn () => branch_create($pdo, ['name' => '   ']));
check_eq('  الرسالة', 'أدخل اسم الفرع.', $errors['name'] ?? null);
$errors = expect_validation('هاتف بحروف مرفوض', fn () => branch_create($pdo, ['name' => 'فرع جديد', 'phone' => '010abc']));
check('  رسالة الهاتف توضح المسموح', str_contains($errors['phone'] ?? '', 'الأرقام والمسافات'), $errors['phone'] ?? '');
$errors = expect_validation('عنوان أطول من 200 حرف مرفوض', fn () => branch_create($pdo, ['name' => 'فرع جديد', 'address' => str_repeat('ع', 201)]));
check('  رسالة العنوان', isset($errors['address']));
check_eq('  لم يُنشأ الفرع المرفوض', 0, (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE name = 'فرع جديد'")->fetchColumn());
$v = dv($pdo);
branch_update($pdo, $latin, ['name' => 'فرع الشروق', 'address' => 'الشروق', 'phone' => '+20 2 1234']);
check_eq('تعديل الفرع (الاسم والعنوان والهاتف)', ['فرع الشروق', 'الشروق', '+20 2 1234'], array_values(array_slice(branch_find($pdo, $latin), 1)));
check_eq('  ويرفع رقم الإصدار', $v + 1, dv($pdo));
expect_validation('تعديل الاسم إلى اسم فرع آخر مرفوض', fn () => branch_update($pdo, $latin, ['name' => 'فرع المعادي']));
expect_validation('تعديل فرع غير موجود مرفوض', fn () => branch_update($pdo, 999999, ['name' => 'أي اسم']));
$v = dv($pdo);
branch_delete($pdo, $latin);
check('حذف فرع بلا مخازن ولا مستندات مسموح', branch_find($pdo, $latin) === null);
check_eq('  ويرفع رقم الإصدار', $v + 1, dv($pdo));

/* ---------------------------------------------------------------- */
section('المخازن داخل الفروع');
$whMain = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي'); // استدعاء قديم بدون فرع = أول فرع
check_eq('مخزن بدون فرع محدد يتبع أول فرع', $main, (int) $pdo->query("SELECT branch_id FROM warehouses WHERE id = $whMain")->fetchColumn());
$whMaadi = catalog_create($pdo, 'warehouse', 'مخزن المعادي', $maadi);
$whMaadi2 = catalog_create($pdo, 'warehouse', 'مخزن المعادي الثاني', $maadi);
check_eq('مخزن في فرع محدد', $maadi, (int) $pdo->query("SELECT branch_id FROM warehouses WHERE id = $whMaadi")->fetchColumn());
$errors = expect_validation('مخزن في فرع غير موجود مرفوض', fn () => catalog_create($pdo, 'warehouse', 'مخزن تائه', 999999));
check_eq('  الرسالة على حقل الفرع', 'اختر فرع المخزن.', $errors['branch_id'] ?? null);
$errors = expect_validation('حذف فرع تتبعه مخازن مرفوض', fn () => branch_delete($pdo, $maadi));
check('  الرسالة تطلب نقل المخازن', str_contains($errors['name'] ?? '', 'انقل مخازنه'), $errors['name'] ?? '');
$sorted = array_column(scoped_warehouses($pdo, null), 'name');
check_eq('قائمة المخازن مرتبة حسب الفرع ثم الاسم', ['المخزن الرئيسي', 'مخزن المعادي', 'مخزن المعادي الثاني'], $sorted);
check_eq('قائمة مخازن فرع واحد', [$whMaadi, $whMaadi2], array_map('intval', array_column(scoped_warehouses($pdo, $maadi), 'id')));
$opts = warehouse_options(scoped_warehouses($pdo, null), (string) $whMaadi, 'اختر المخزن');
check('خيارات المخازن مجمعة حسب الفرع (optgroup)', substr_count($opts, '<optgroup') === 2 && str_contains($opts, 'label="فرع المعادي"') && str_contains($opts, 'value="' . $whMaadi . '" selected'));
check('  وبدون تجميع لفرع واحد', !str_contains(warehouse_options(scoped_warehouses($pdo, $maadi), '', ''), 'optgroup'));

$mosky = catalog_create($pdo, 'type', 'موسكي');
$r1 = record_receipt($pdo, $uid, receipt_input($whMain, $mosky, '10', 'cm', '5', 'cm', '3', 'm', '100'));
$item = item_id_for($pdo, $mosky, 100000, 50000, 3000000);
$r2 = record_receipt($pdo, $uid, receipt_input($whMaadi, $mosky, '10', 'cm', '5', 'cm', '3', 'm', '40'));
$r3 = record_receipt($pdo, $uid, receipt_input($whMaadi2, $mosky, '10', 'cm', '5', 'cm', '3', 'm', '7'));

/* ---------------------------------------------------------------- */
section('اسم الفرع محفوظ في المستندات');
$d1 = find_document($pdo, $r1['id']);
check_eq('الوارد يحفظ فرع المخزن', [$main, BRANCH_DEFAULT_NAME], [(int) $d1['branch_id'], $d1['branch_name']]);
check('  ولا فرع مستلم في غير التحويل', $d1['to_branch_id'] === null && $d1['to_branch_name'] === null);
$s1 = record_sale($pdo, $uid, sale_input($whMaadi, [[$item, 4, '1000']]));
$ds1 = find_document($pdo, $s1['id']);
check_eq('البيع يحفظ فرع المخزن', [$maadi, 'فرع المعادي'], [(int) $ds1['branch_id'], $ds1['branch_name']]);
$t1 = record_transfer($pdo, $uid, transfer_input($whMain, $whMaadi, [[$item, 10]]));
$dt1 = find_document($pdo, $t1['id']);
check_eq('التحويل بين فرعين يحفظ الفرعين', [$main, BRANCH_DEFAULT_NAME, $maadi, 'فرع المعادي'],
    [(int) $dt1['branch_id'], $dt1['branch_name'], (int) $dt1['to_branch_id'], $dt1['to_branch_name']]);
$t2 = record_transfer($pdo, $uid, transfer_input($whMaadi, $whMaadi2, [[$item, 1]]));
$dt2 = find_document($pdo, $t2['id']);
check_eq('التحويل داخل نفس الفرع', [$maadi, $maadi], [(int) $dt2['branch_id'], (int) $dt2['to_branch_id']]);
$errors = expect_validation('حذف فرع له مستندات مرفوض حتى بعد نقل مخازنه', function () use ($pdo, $maadi, $main, $whMaadi, $whMaadi2) {
    try {
        $pdo->prepare('UPDATE warehouses SET branch_id = ? WHERE id IN (?, ?)')->execute([$main, $whMaadi, $whMaadi2]);
        branch_delete($pdo, $maadi);
    } finally {
        $pdo->prepare('UPDATE warehouses SET branch_id = ? WHERE id IN (?, ?)')->execute([$maadi, $whMaadi, $whMaadi2]);
    }
});
check('  الرسالة تذكر المستندات', str_contains($errors['name'] ?? '', 'مستندات'), $errors['name'] ?? '');

branch_update($pdo, $maadi, ['name' => 'فرع المعادي الجديد', 'address' => 'شارع 9، المعادي', 'phone' => '010 123-4567']);
check_eq('تعديل اسم الفرع لا يغير المستندات السابقة', 'فرع المعادي', find_document($pdo, $s1['id'])['branch_name']);
check_eq('  ولا الفرع المستلم في التحويل السابق', 'فرع المعادي', find_document($pdo, $t1['id'])['to_branch_name']);
$s2 = record_sale($pdo, $uid, sale_input($whMaadi, [[$item, 1, '1000']]));
check_eq('  المستند الجديد يحفظ الاسم الجديد', 'فرع المعادي الجديد', find_document($pdo, $s2['id'])['branch_name']);

/* ---------------------------------------------------------------- */
section('نقل مخزن إلى فرع آخر');
$north = branch_create($pdo, ['name' => 'فرع الشمال']);
$beforeQty = stock_qty($pdo, $item, $whMaadi2);
$v = dv($pdo);
$newName = warehouse_move($pdo, $whMaadi2, $north);
check_eq('النقل يعيد اسم الفرع الجديد', 'فرع الشمال', $newName);
check_eq('  المخزن صار في الفرع الجديد', $north, (int) $pdo->query("SELECT branch_id FROM warehouses WHERE id = $whMaadi2")->fetchColumn());
check_eq('  النقل مسموح مع وجود رصيد، والرصيد لم يتغير', $beforeQty, stock_qty($pdo, $item, $whMaadi2));
check_eq('  ويرفع رقم الإصدار', $v + 1, dv($pdo));
check_eq('  المستند السابق يحتفظ بفرعه وقت الحركة', [$maadi, $maadi], [(int) find_document($pdo, $t2['id'])['branch_id'], (int) find_document($pdo, $t2['id'])['to_branch_id']]);
$errors = expect_validation('النقل لنفس الفرع مرفوض', fn () => warehouse_move($pdo, $whMaadi2, $north));
check('  الرسالة', str_contains($errors['branch_id'] ?? '', 'بالفعل'));
expect_validation('النقل لفرع غير موجود مرفوض', fn () => warehouse_move($pdo, $whMaadi2, 999999));
expect_validation('نقل مخزن غير موجود مرفوض', fn () => warehouse_move($pdo, 999999, $north));
$sumNorth = warehouse_summary($pdo, $north);
check_eq('ملخص مخازن الفرع الجديد يضم المخزن المنقول', [$whMaadi2], array_map('intval', array_column($sumNorth, 'id')));
$bs = array_column(branch_summary($pdo), null, 'id');
check_eq('ملخص الفروع: مخازن الفرع الجديد', 1, (int) $bs[$north]['warehouses']);
check_eq('  وأرصدته', (string) $beforeQty, (string) $bs[$north]['qty']);
check_eq('  وفرع المعادي بقي له مخزن واحد', 1, (int) $bs[$maadi]['warehouses']);
check_eq('  وحجم الفرع بدقة كاملة (0.015 × الرصيد)', Num::trimDecimal(um3_to_m3(Num::mul(m3_to_um3('0.015'), (string) $beforeQty))), $bs[$north]['volume']);

/* ---------------------------------------------------------------- */
section('اسم الفرع يُقرأ من صف المخزن المقفل داخل المعاملة (حاجز قفل حتمي)');
$root = root_pdo();
$root->beginTransaction();
// الجلسة الأخرى تنقل المخزن إلى فرع الشمال وتحتفظ بالقفل، والبيع ينتظر
$root->prepare('UPDATE warehouses SET branch_id = ? WHERE id = ?')->execute([$north, $whMaadi]);
[$waiting, $res] = run_workers_behind_barrier($root, [['sale', '0', (string) $whMaadi, (string) $item, '1', '1000']], 1);
check('البيع انتظر قفل صف المخزن', $waiting === 1, "waiting={$waiting}");
check('  البيع نجح', $res[0]['ok'] ?? false, json_encode($res, JSON_UNESCAPED_UNICODE));
$moved = find_document($pdo, (int) ($res[0]['id'] ?? 0));
check_eq('  وحفظ الفرع بعد النقل وليس قبله', [$north, 'فرع الشمال'], [(int) ($moved['branch_id'] ?? 0), $moved['branch_name'] ?? null]);
warehouse_move($pdo, $whMaadi, $maadi);

/* ---------------------------------------------------------------- */
section('نطاق المستخدم المقيد بفرع');
$staff = seed_user($pdo, 'staff_maadi');
$pdo->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$maadi, $staff]);
// مستند في فرع الشمال وتحويل من الشمال إلى المعادي قبل تقييد الجلسة
$sNorth = record_sale($pdo, $uid, sale_input($whMaadi2, [[$item, 1, '1000']]));
$tNorthIn = record_transfer($pdo, $uid, transfer_input($whMaadi2, $whMaadi, [[$item, 1]]));
act_as(0);
check('بدون جلسة: كل الفروع', allowed_branch_id($pdo) === null);
act_as($uid);
check('المدير بدون فرع: كل الفروع', allowed_branch_id($pdo) === null);
act_as($staff);
check_eq('المستخدم المقيد: فرعه فقط', $maadi, allowed_branch_id($pdo));

$errors = expect_validation('وارد إلى مخزن فرع آخر مرفوض', fn () => record_receipt($pdo, $staff, receipt_input($whMain, $mosky, '1', 'm', '1', 'm', '1', 'm', '1')));
check_eq('  الرسالة', BRANCH_NO_ACCESS, $errors['warehouse_id'] ?? null);
$ok = record_receipt($pdo, $staff, receipt_input($whMaadi, $mosky, '10', 'cm', '5', 'cm', '3', 'm', '5'));
check('وارد إلى مخزن فرعه مسموح', !$ok['duplicate'] && (int) find_document($pdo, $ok['id'])['branch_id'] === $maadi);
$mainBefore = stock_qty($pdo, $item, $whMain);
$errors = expect_validation('بيع من مخزن فرع آخر مرفوض', fn () => record_sale($pdo, $staff, sale_input($whMain, [[$item, 1, '100']])));
check_eq('  الرسالة', BRANCH_NO_ACCESS, $errors['warehouse_id'] ?? null);
expect_validation('  ومراجعة الفاتورة أيضًا ترفضه', fn () => validate_sale($pdo, sale_input($whMain, [[$item, 1, '100']]), true));
check_eq('  الرصيد لم يتغير', $mainBefore, stock_qty($pdo, $item, $whMain));
$staffSale = record_sale($pdo, $staff, sale_input($whMaadi, [[$item, 2, '1000']]));
check('بيع من مخزن فرعه مسموح', $staffSale['id'] > 0);
$errors = expect_validation('تحويل من مخزن فرع آخر مرفوض', fn () => record_transfer($pdo, $staff, transfer_input($whMain, $whMaadi, [[$item, 1]])));
check_eq('  الرسالة على حقل المصدر', BRANCH_NO_ACCESS, $errors['warehouse_id'] ?? null);
$out = record_transfer($pdo, $staff, transfer_input($whMaadi, $whMain, [[$item, 2]]));
check('تحويل من فرعه إلى مخزن فرع آخر مسموح', (int) find_document($pdo, $out['id'])['to_branch_id'] === $main);

$errors = expect_validation('إلغاء مستند فرع آخر مرفوض', fn () => cancel_document($pdo, $staff, $sNorth['id'], ''));
check_eq('  ولا يكشف وجوده', 'المستند غير موجود.', $errors['document'] ?? null);
$errors = expect_validation('إلغاء تحويل بين فرعه وفرع آخر مرفوض', fn () => cancel_document($pdo, $staff, $out['id'], ''));
check('  الرسالة توضح السبب', str_contains($errors['document'] ?? '', 'فرع آخر'), $errors['document'] ?? '');
check_eq('  المستندان ما زالا ساريين', ['active', 'active'], [find_document($pdo, $sNorth['id'])['status'], find_document($pdo, $out['id'])['status']]);
cancel_document($pdo, $staff, $staffSale['id'], 'اختبار');
check_eq('إلغاء مستند فرعه مسموح', 'cancelled', find_document($pdo, $staffSale['id'])['status']);

check('عرض مستند فرع آخر: غير موجود', find_document_scoped($pdo, $sNorth['id']) === null);
check('عرض مستند فرعه: ظاهر', find_document_scoped($pdo, $ok['id']) !== null);
check('عرض تحويل وارد إلى فرعه من فرع آخر: ظاهر', find_document_scoped($pdo, $tNorthIn['id']) !== null);
check('  لكن لا يمكن إلغاؤه', !document_cancellable(find_document($pdo, $tNorthIn['id']), allowed_branch_id($pdo)));
check('عرض تحويل صادر من فرعه إلى فرع آخر: ظاهر', find_document_scoped($pdo, $out['id']) !== null);

$maadiWh = [$whMaadi];
$view = inventory_view($pdo, '', 0, 0, false, allowed_branch_id($pdo));
$expectedQty = 0;
foreach (scoped_warehouses($pdo, $maadi) as $w) {
    $expectedQty += (int) $pdo->query('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock WHERE warehouse_id = ' . (int) $w['id'])->fetchColumn();
}
$seenWh = [];
foreach ($view['groups'] as $g) {
    foreach ($g['rows'] as $row) {
        $seenWh += array_flip(array_keys($row['by_warehouse']));
    }
}
check_eq('المخزون: أرصدة مخازن فرعه فقط', $maadiWh, array_keys($seenWh));
check_eq('  والإجمالي = مجموع مخازن فرعه', (string) $expectedQty, $view['qty']);
$allView = inventory_view($pdo, '', 0, 0, false, null);
check('  إجمالي كل الفروع أكبر (للتأكد أن التصفية فعلية)', Num::cmp($allView['qty'], $view['qty']) > 0, $allView['qty'] . ' vs ' . $view['qty']);
check_eq('ملخص المخازن: مخازن فرعه فقط', $maadiWh, array_map('intval', array_column(warehouse_summary($pdo, allowed_branch_id($pdo)), 'id')));
check_eq('ملخص الفروع: فرعه فقط', [$maadi], array_map('intval', array_column(branch_summary($pdo, allowed_branch_id($pdo)), 'id')));

[$cond, $condParams] = document_branch_condition(allowed_branch_id($pdo));
$stmt = $pdo->prepare('SELECT d.id, d.branch_id, d.to_branch_id FROM documents d WHERE ' . $cond);
$stmt->execute($condParams);
$docs = $stmt->fetchAll();
check('سجل المستندات: كل الصفوف تخص فرعه (مصدرًا أو مستلمًا)',
    $docs !== [] && !array_filter($docs, fn ($d) => (int) $d['branch_id'] !== $maadi && (int) $d['to_branch_id'] !== $maadi));
$ids = array_map('intval', array_column($docs, 'id'));
check('  ويشمل التحويل الوارد من فرع آخر', in_array($tNorthIn['id'], $ids, true));
check('  ولا يشمل بيع فرع آخر', !in_array($sNorth['id'], $ids, true));
$expectedAmount = $pdo->query("SELECT SUM(total_amount) FROM documents WHERE kind = 'sale' AND status = 'active' AND branch_id = $maadi")->fetchColumn();
$scopedTotals = sales_by_currency($pdo, ' WHERE ' . $cond, $condParams);
check_eq('  إجمالي مبيعات فرعه فقط', [(string) $expectedAmount], array_column($scopedTotals, 'amount'));

$payload = stock_payload($pdo, allowed_branch_id($pdo));
$keys = [];
foreach ($payload as $it) {
    $keys += array_flip(array_map('intval', array_keys((array) $it['stock'])));
}
check_eq('بيانات الأرصدة (JSON) لمخازن فرعه فقط', $maadiWh, array_keys($keys));
check('  وقائمة المقاسات مشتركة', count($payload) === count(stock_payload($pdo, null)));
$fullKeys = [];
foreach (stock_payload($pdo, null) as $it) {
    $fullKeys += array_flip(array_keys((array) $it['stock']));
}
check('  وبدون نطاق تشمل كل المخازن', count($fullKeys) === 3);

foreach ([
    'إضافة فرع' => fn () => branch_create($pdo, ['name' => 'فرع ممنوع']),
    'تعديل فرع' => fn () => branch_update($pdo, $maadi, ['name' => 'اسم ممنوع']),
    'حذف فرع' => fn () => branch_delete($pdo, $north),
    'إضافة مخزن' => fn () => catalog_create($pdo, 'warehouse', 'مخزن ممنوع', $maadi),
    'تعديل اسم مخزن' => fn () => catalog_rename($pdo, 'warehouse', $whMaadi, 'اسم ممنوع'),
    'حذف مخزن' => fn () => catalog_delete($pdo, 'warehouse', $whMaadi),
    'نقل مخزن' => fn () => warehouse_move($pdo, $whMaadi, $north),
    'تعديل فرع عبر catalog_rename' => fn () => catalog_rename($pdo, 'branch', $maadi, 'اسم ممنوع'),
] as $label => $fn) {
    $errors = expect_validation("المستخدم المقيد لا يستطيع: {$label}", $fn);
    check("  الرسالة ({$label})", in_array(BRANCH_ADMIN_ONLY, $errors, true), json_encode($errors, JSON_UNESCAPED_UNICODE));
}
check('  لم يتغير شيء', branch_find($pdo, $maadi)['name'] === 'فرع المعادي الجديد'
    && (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE name = 'مخزن ممنوع'")->fetchColumn() === 0);
$typeOk = catalog_create($pdo, 'type', 'زان');
check('أنواع الخشب مشتركة: المستخدم المقيد يضيفها', $typeOk > 0);

act_as(0);
section('نطاق الفرع يُفحص من صف المخزن المقفل داخل المعاملة (حاجز قفل حتمي)');
$qtyBefore = stock_qty($pdo, $item, $whMaadi);
$root = root_pdo();
$root->beginTransaction();
// المدير ينقل المخزن إلى فرع آخر بينما المستخدم المقيد يحفظ فاتورة من نفس المخزن
$root->prepare('UPDATE warehouses SET branch_id = ? WHERE id = ?')->execute([$north, $whMaadi]);
[$waiting, $res] = run_workers_behind_barrier($root, [['sale', (string) $staff, (string) $whMaadi, (string) $item, '1', '1000']], 1);
check('البيع انتظر قفل صف المخزن', $waiting === 1, "waiting={$waiting}");
check_eq('  ورُفض لأن المخزن خرج من فرع المستخدم', BRANCH_NO_ACCESS, $res[0]['errors']['warehouse_id'] ?? json_encode($res, JSON_UNESCAPED_UNICODE));
check_eq('  الرصيد لم يتغير', $qtyBefore, stock_qty($pdo, $item, $whMaadi));
warehouse_move($pdo, $whMaadi, $maadi);

/* ---------------------------------------------------------------- */
section('إجماليات المبيعات لكل عملة على حدة');
save_setting($pdo, 'currency', 'دولار');
reset_settings_cache();
$usd = record_sale($pdo, $uid, sale_input($whMaadi, [[$item, 1, '100']]));
check_eq('الفاتورة الجديدة بالعملة الجديدة', 'دولار', find_document($pdo, $usd['id'])['currency']);
$all = sales_by_currency($pdo, '', []);
check_eq('عملتان منفصلتان في الإجمالي', 2, count($all));
check_eq('  العملة الحالية أولًا', 'دولار', $all[0]['currency'] ?? null);
$byCur = array_column($all, null, 'currency');
$egpExpected = $pdo->query("SELECT SUM(total_amount) FROM documents WHERE kind = 'sale' AND status = 'active' AND currency = 'جنيه مصري'")->fetchColumn();
check_eq('  الجنيه وحده', (string) $egpExpected, $byCur['جنيه مصري']['amount'] ?? null);
check_eq('  الدولار وحده (0.015 × 100 = 1.50)', '1.50', $byCur['دولار']['amount'] ?? null);
check_eq('  عدد فواتير الدولار', 1, $byCur['دولار']['count'] ?? null);
[$cond, $condParams] = document_branch_condition($maadi);
$maadiTotals = array_column(sales_by_currency($pdo, ' WHERE ' . $cond, $condParams), null, 'currency');
check('  تصفية الفرع تحتفظ بالفصل بين العملات', isset($maadiTotals['دولار'], $maadiTotals['جنيه مصري']) && $maadiTotals['دولار']['amount'] === '1.50');
$bs = array_column(branch_summary($pdo), null, 'id');
$maadiSales = array_column($bs[$maadi]['sales'], null, 'currency');
check('ملخص الفروع: مبيعات الشهر لكل عملة على حدة', count($maadiSales) === 2 && $maadiSales['دولار']['amount'] === '1.50');
$totals = branch_summary_totals(array_values($bs));
$totalByCur = array_column($totals['sales'], null, 'currency');
check_eq('  الإجمالي العام لا يجمع العملات معًا', 2, count($totals['sales']));
check_eq('  إجمالي الدولار', '1.50', $totalByCur['دولار']['amount'] ?? null);
$egpMonth = $pdo->prepare("SELECT SUM(total_amount) FROM documents WHERE kind = 'sale' AND status = 'active' AND currency = 'جنيه مصري' AND created_at >= ?");
$egpMonth->execute([current_month_range()[0]]);
check_eq('  إجمالي الجنيه للشهر', (string) $egpMonth->fetchColumn(), $totalByCur['جنيه مصري']['amount'] ?? null);
check('  سطر العرض لكل عملة', fmt_sales_lines([['count' => 1, 'amount' => '1.50', 'currency' => 'دولار']]) === ['عدد الفواتير: ' . fmt_int(1) . '، ' . fmt_money('1.50') . ' دولار']);
$old = record_sale($pdo, $uid, sale_input($whMaadi, [[$item, 1, '100']]));
$pdo->prepare("UPDATE documents SET created_at = DATE_SUB(?, INTERVAL 1 DAY) WHERE id = ?")->execute([current_month_range()[0], $old['id']]);
$bs2 = array_column(branch_summary($pdo, $maadi)[0]['sales'], null, 'currency');
check_eq('مبيعات الشهر لا تشمل الشهر السابق', 1, $bs2['دولار']['count'] ?? null);
save_setting($pdo, 'currency', DEFAULT_SETTINGS['currency']);
reset_settings_cache();

/* ---------------------------------------------------------------- */
section('حذف فرع مرتبط بمستخدم مرفوض');
$empty = branch_create($pdo, ['name' => 'فرع فارغ']);
$pdo->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$empty, $staff]);
$errors = expect_validation('حذف فرع مرتبط بمستخدم مرفوض', fn () => branch_delete($pdo, $empty));
check('  الرسالة تذكر المستخدمين', str_contains($errors['name'] ?? '', 'مستخدمين'), $errors['name'] ?? '');
$pdo->prepare('UPDATE users SET branch_id = NULL WHERE id = ?')->execute([$staff]);
branch_delete($pdo, $empty);
check('  وبعد فك الارتباط يُحذف', branch_find($pdo, $empty) === null);

/* ---------------------------------------------------------------- */
section('ترقية قاعدة بيانات قديمة (001 بالكود السابق) إلى الفروع (005)');
$repo = dirname(__DIR__);
$migDb = test_db_name() . '_mig';
$git = fn (string $args) => trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ' . $args . ' 2>/dev/null'));
// الكود السابق للفروع: أب أول commit أضاف ملف الترقية 005، أو HEAD قبل أن يُحفظ الملف في git
$added = $git('log --diff-filter=A --format=%H -- public_html/app/migrations/005_branches.sql');
$added = $added !== '' ? (string) array_slice(explode("\n", $added), -1)[0] : '';
$oldRev = $added !== '' ? $added . '^' : 'HEAD';
$oldTree = __DIR__ . '/output/old_tree';
shell_exec('rm -rf ' . escapeshellarg($oldTree));
@mkdir($oldTree, 0775, true);
shell_exec('git -C ' . escapeshellarg($repo) . ' archive --format=tar ' . escapeshellarg($oldRev) . ' public_html tests/lib.php | tar -x -C ' . escapeshellarg($oldTree));
$hasOld = is_file($oldTree . '/tests/lib.php') && !is_file($oldTree . '/public_html/app/migrations/005_branches.sql');
check("الكود السابق مستخرج من git ({$oldRev})", $hasOld);
if ($hasOld) {
    file_put_contents($oldTree . '/tests/seed_old.php', <<<'PHP'
<?php
declare(strict_types=1);
// يعمل بالكود السابق للفروع: قاعدة جديدة بترقية 001 فقط، ثم بيانات عبر الخدمات القديمة نفسها
require __DIR__ . '/lib.php';
$pdo = fresh_database();
$uid = seed_user($pdo);
$w1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$w2 = catalog_create($pdo, 'warehouse', 'مخزن قديم');
$t = catalog_create($pdo, 'type', 'زان');
$in = record_receipt($pdo, $uid, receipt_input($w1, $t, '10', 'cm', '5', 'cm', '3', 'm', '20'));
$item = item_id_for($pdo, $t, 100000, 50000, 3000000);
$sale = record_sale($pdo, $uid, sale_input($w1, [[$item, 3, '1000']]));
$tr = record_transfer($pdo, $uid, transfer_input($w1, $w2, [[$item, 5]]));
$c = record_sale($pdo, $uid, sale_input($w2, [[$item, 1, '1000']]));
cancel_document($pdo, $uid, $c['id'], 'قديم');
echo json_encode(['uid' => $uid, 'w1' => $w1, 'w2' => $w2, 'type' => $t, 'item' => $item, 'transfer' => $tr['id'],
    'schema' => schema_version($pdo), 'has_branches' => (bool) $pdo->query("SHOW TABLES LIKE 'branches'")->fetch()]), "\n";
PHP);
    $env = getenv();
    $env['WOOD_TEST_DB'] = $migDb;
    $p = proc_open([PHP_BINARY, $oldTree . '/tests/seed_old.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $oldTree, $env);
    $seedOut = trim((string) stream_get_contents($pipes[1]));
    $seedErr = trim((string) stream_get_contents($pipes[2]));
    proc_close($p);
    $seed = json_decode($seedOut, true);
    check('بيانات قديمة أُنشئت بالخدمات القديمة على ترقية 001 فقط', is_array($seed) && $seed['schema'] === 1 && $seed['has_branches'] === false, $seedOut . $seedErr);

    $mig = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $migDb . ';charset=utf8mb4', TEST_DB_USER, TEST_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $mig->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $stockBefore = $mig->query('SELECT item_id, warehouse_id, qty_on_hand FROM stock ORDER BY item_id, warehouse_id')->fetchAll();
    $docsBefore = $mig->query('SELECT id, kind, doc_no, status, total_amount FROM documents ORDER BY id')->fetchAll();

    // ترقية توقفت في منتصفها: نُفذت أربعة أوامر وسُجل اثنان فقط، فيُعاد تنفيذ الثالث والرابع
    $stmts = split_sql((string) file_get_contents(APP_ROOT . '/migrations/005_branches.sql'));
    foreach (array_slice($stmts, 0, 4) as $s) {
        $mig->exec($s);
    }
    save_setting($mig, 'schema_progress', '5:2');
    $applied = run_migrations($mig);
    check('الترقية أكملت 005 بعد توقف في منتصفها', in_array(5, $applied, true), json_encode($applied));
    check('  رقم إصدار القاعدة ≥ 5', schema_version($mig) >= 5);
    $br = $mig->query('SELECT id, name, name_key FROM branches')->fetchAll();
    check_eq('  فرع افتراضي واحد', 1, count($br));
    check_eq('  اسمه ومفتاحه', [BRANCH_DEFAULT_NAME, name_key(BRANCH_DEFAULT_NAME)], [$br[0]['name'] ?? null, $br[0]['name_key'] ?? null]);
    $defId = (int) ($br[0]['id'] ?? 0);
    check_eq('  كل المخازن في الفرع الافتراضي', [[$defId], 2], [array_map('intval', $mig->query('SELECT DISTINCT branch_id FROM warehouses')->fetchAll(PDO::FETCH_COLUMN)), (int) $mig->query('SELECT COUNT(*) FROM warehouses')->fetchColumn()]);
    check_eq('  كل المستندات القديمة أُكملت بالفرع', 0, (int) $mig->query('SELECT COUNT(*) FROM documents WHERE branch_id IS NULL OR branch_name IS NULL OR branch_id <> ' . $defId)->fetchColumn());
    check_eq('  التحويل القديم أُكمل بالفرع المستلم', [$defId, BRANCH_DEFAULT_NAME], array_values($mig->query('SELECT to_branch_id, to_branch_name FROM documents WHERE id = ' . (int) $seed['transfer'])->fetch(PDO::FETCH_NUM)));
    check_eq('  غير التحويل بلا فرع مستلم', 0, (int) $mig->query("SELECT COUNT(*) FROM documents WHERE kind <> 'transfer' AND (to_branch_id IS NOT NULL OR to_branch_name IS NOT NULL)")->fetchColumn());
    check_eq('  المستخدمون بلا تقييد (كل الفروع)', 0, (int) $mig->query('SELECT COUNT(*) FROM users WHERE branch_id IS NOT NULL')->fetchColumn());
    check_eq('  الأرصدة لم تتغير', $stockBefore, $mig->query('SELECT item_id, warehouse_id, qty_on_hand FROM stock ORDER BY item_id, warehouse_id')->fetchAll());
    check_eq('  المستندات لم تتغير', $docsBefore, $mig->query('SELECT id, kind, doc_no, status, total_amount FROM documents ORDER BY id')->fetchAll());

    $rejects = function (string $sql) use ($mig): bool {
        try {
            $mig->exec($sql);
            return false;
        } catch (PDOException $e) {
            return true;
        }
    };
    check('NOT NULL: مخزن بدون فرع مرفوض', $rejects("INSERT INTO warehouses (name, name_key, created_at) VALUES ('x', 'x', NOW())"));
    check('NOT NULL: إزالة فرع مخزن مرفوضة', $rejects('UPDATE warehouses SET branch_id = NULL'));
    check('FK: فرع غير موجود مرفوض', $rejects('UPDATE warehouses SET branch_id = 999999'));
    check('FK: حذف فرع له مخازن مرفوض على مستوى القاعدة', $rejects('DELETE FROM branches'));

    $checksum = fn () => $mig->query('CHECKSUM TABLE branches, warehouses, documents, users, stock')->fetchAll(PDO::FETCH_KEY_PAIR);
    $before = $checksum();
    $createBefore = array_map(fn ($t) => $mig->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1], ['branches', 'warehouses', 'documents', 'users']);
    $rerunOk = true;
    $rerunErr = '';
    foreach ($stmts as $s) {
        try {
            $mig->exec($s);
        } catch (PDOException $e) {
            $rerunOk = false;
            $rerunErr = $e->getMessage();
        }
    }
    check('إعادة تنفيذ كل أوامر 005 بلا أخطاء', $rerunOk, $rerunErr);
    check_eq('  ولا تغير البيانات', $before, $checksum());
    check_eq('  ولا بنية الجداول', $createBefore, array_map(fn ($t) => $mig->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1], ['branches', 'warehouses', 'documents', 'users']));
    check_eq('  ولا تضيف فرعًا ثانيًا', 1, (int) $mig->query('SELECT COUNT(*) FROM branches')->fetchColumn());

    $newDoc = record_sale($mig, (int) $seed['uid'], sale_input((int) $seed['w2'], [[(int) $seed['item'], 1, '1000']]));
    check_eq('الخدمات الجديدة تعمل على القاعدة المرقاة وتحفظ الفرع', BRANCH_DEFAULT_NAME,
        $mig->query('SELECT branch_name FROM documents WHERE id = ' . (int) $newDoc['id'])->fetchColumn());
    $mig = null;
}
root_pdo()->exec('DROP DATABASE IF EXISTS `' . $migDb . '`');
shell_exec('rm -rf ' . escapeshellarg($oldTree));

section('تحديد فرع المستخدم من صفحة المستخدمين (user_update)');
$pdoU = fresh_database();
$adminU = seed_user($pdoU, 'boss', 'Boss-Pass-12345');
$pdoU->exec("UPDATE users SET role = 'admin'");
$staffU = user_create($pdoU, $adminU, ['display_name' => 'موظف الفرع', 'username' => 'branchstaff', 'role' => 'staff',
    'password' => 'Staff-Pass-12345', 'password_confirm' => 'Staff-Pass-12345']);
$brU = (int) $pdoU->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
$ch = user_update($pdoU, $adminU, $staffU, ['display_name' => 'موظف الفرع', 'role' => 'staff', 'branch_id' => (string) $brU]);
check_eq('تحديد الفرع يُحفظ', $brU, (int) $pdoU->query("SELECT branch_id FROM users WHERE id = $staffU")->fetchColumn());
check('  ويُسجل في المراقبة', (int) $pdoU->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user.branch' AND entity_id = $staffU")->fetchColumn() === 1);
user_update($pdoU, $adminU, $staffU, ['display_name' => 'موظف الفرع', 'role' => 'staff', 'branch_id' => '']);
check_eq('«كل الفروع» يعيد NULL', null, $pdoU->query("SELECT branch_id FROM users WHERE id = $staffU")->fetchColumn());
expect_validation('فرع غير موجود مرفوض', fn () => user_update($pdoU, $adminU, $staffU, ['display_name' => 'موظف الفرع', 'role' => 'staff', 'branch_id' => '999']));
expect_validation('قيمة غير رقمية مرفوضة', fn () => user_update($pdoU, $adminU, $staffU, ['display_name' => 'موظف الفرع', 'role' => 'staff', 'branch_id' => '1 OR 1=1']));
expect_validation('المدير لا يقيد نفسه بفرع', fn () => user_update($pdoU, $adminU, $adminU, ['display_name' => 'boss', 'role' => 'admin', 'branch_id' => (string) $brU]));

section('تحصيلات اليوم في الرئيسية: فرع من سجّل سند القبض');
$pdoH = fresh_database();
$bossH = seed_user($pdoH, 'boss_h', 'Boss-Pass-12345');
$pdoH->exec("UPDATE users SET role = 'admin'");
$mainH = (int) $pdoH->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
$alexH = branch_create($pdoH, ['name' => 'فرع الإسكندرية']);
$staffH = user_create($pdoH, $bossH, ['display_name' => 'محصل الإسكندرية', 'username' => 'alex_cashier', 'role' => 'staff',
    'password' => 'Staff-Pass-12345', 'password_confirm' => 'Staff-Pass-12345']);
$pdoH->exec("UPDATE users SET branch_id = $alexH WHERE id = $staffH");
$custH = party_create($pdoH, $bossH, 'customer', ['name' => 'عميل التحصيل', 'opening_balance' => '1000', 'opening_direction' => 'owes_us']);
$boxH = (int) $pdoH->query('SELECT id FROM cash_boxes ORDER BY id LIMIT 1')->fetchColumn();
$collectIn = fn (string $amount) => ['party_id' => (string) $custH, 'cash_box_id' => (string) $boxH, 'amount' => $amount,
    'reference' => '', 'notes' => '', 'request_token' => new_request_token()];

$_SESSION = ['user_id' => $staffH, 'role' => 'staff'];
reset_branch_scope_cache();
$vStaff = record_voucher($pdoH, $staffH, 'collect', $collectIn('250'));
$_SESSION = ['user_id' => $bossH, 'role' => 'admin'];
reset_branch_scope_cache();
$vBoss = record_voucher($pdoH, $bossH, 'collect', $collectIn('100'));
$row = fn (int $id) => $pdoH->query("SELECT branch_id, branch_name FROM vouchers WHERE id = $id")->fetch();
check_eq('سند الموظف المقيد يحفظ فرعه', [(string) $alexH, 'فرع الإسكندرية'], array_values(array_map('strval', $row($vStaff['id']))));
check_eq('سند من يرى كل الفروع عام (بلا فرع)', [null, null], array_values($row($vBoss['id'])));

$homeAll = home_overview($pdoH, null);
check_eq('الرئيسية لكل الفروع: كل التحصيلات (350.00 في سندين)', [['currency' => app_setting('currency'), 'count' => 2, 'amount' => '350.00']], $homeAll['collect']);
$_SESSION = ['user_id' => $staffH, 'role' => 'staff'];
reset_branch_scope_cache();
$homeAlex = home_overview($pdoH, $alexH);
check_eq('الرئيسية للموظف المقيد: تحصيلات فرعه فقط (250.00)', [['currency' => app_setting('currency'), 'count' => 1, 'amount' => '250.00']], $homeAlex['collect']);
check_eq('الرئيسية لفرع آخر: لا تحصيلات', [], home_overview($pdoH, $mainH)['collect']);
$_SESSION = [];
reset_branch_scope_cache();

finish();
