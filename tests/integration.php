<?php
declare(strict_types=1);

/*
 * اختبارات القبول على قاعدة بيانات MariaDB حقيقية (البنود أ إلى ك في المتطلبات + المخازن والتحويل).
 * التشغيل: php tests/integration.php
 */

require __DIR__ . '/lib.php';

$pdo = fresh_database();
$uid = seed_user($pdo);
$wh1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$wh2 = catalog_create($pdo, 'warehouse', 'مخزن الفرع');
$mosky = catalog_create($pdo, 'type', 'موسكي');
$zan = catalog_create($pdo, 'type', 'زان');
$um = fn (string $cm) => (int) round((float) $cm * 10000); // لكتابة الأبعاد في الاختبار فقط

/* ---------------------------------------------------------------- */
section('أ. حساب الحجم: 10 سم × 50 مللي × 3 متر × 10 قطع = 0.15 م³');
$r = record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '10'));
$doc = find_document($pdo, $r['id']);
$lines = document_lines($pdo, $r['id']);
check_eq('حجم القطعة 0.015', '0.015', Num::trimDecimal($lines[0]['piece_volume_m3']));
check_eq('حجم الكمية 0.15', '0.15', Num::trimDecimal($doc['total_volume_m3']));
check_eq('رقم الوارد الأول = 1', 1, (int) $doc['doc_no']);
$item = item_id_for($pdo, $mosky, 100000, 50000, 3000000);
check('الصنف أُنشئ بالأبعاد الموحدة بالميكرومتر', $item !== null);
check_eq('الرصيد 10', 10, stock_qty($pdo, $item, $wh1));

section('ب. حساب البيع: 0.15 م³ × 20,000 = 3,000');
$s = record_sale($pdo, $uid, sale_input($wh1, [[$item, 10, '20000']], ['party_name' => 'عميل تجريبي']));
$sd = find_document($pdo, $s['id']);
$sl = document_lines($pdo, $s['id']);
check_eq('إجمالي الفاتورة 3000.00', '3000.00', (string) $sd['total_amount']);
check_eq('قيمة السطر 3000.00', '3000.00', (string) $sl[0]['amount']);
check_eq('سعر المتر محفوظ 20000.00', '20000.00', (string) $sl[0]['price_per_m3']);
check_eq('العملة محفوظة مع الفاتورة', 'جنيه مصري', $sd['currency']);
check_eq('رقم فاتورة البيع الأولى = 1', 1, (int) $sd['doc_no']);
check_eq('الرصيد بعد البيع 0', 0, stock_qty($pdo, $item, $wh1));

section('ج. الرصيد: وارد 100 = 1.5 م³، بيع 10 يترك 90 = 1.35 م³');
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '100'));
$view = inventory_view($pdo, '', 0, $wh1, false);
check_eq('الحجم المتاح 1.5', '1.5', Num::trimDecimal($view['volume']));
record_sale($pdo, $uid, sale_input($wh1, [[$item, 10, '20000']]));
check_eq('الرصيد 90 قطعة', 90, stock_qty($pdo, $item, $wh1));
$view = inventory_view($pdo, '', 0, $wh1, false);
check_eq('الحجم المتاح 1.35', '1.35', Num::trimDecimal($view['volume']));
check_eq('أرقام البيع متتالية (الثانية = 2)', 2, (int) $pdo->query("SELECT MAX(doc_no) FROM documents WHERE kind='sale'")->fetchColumn());

section('د. تساوي الوحدات: نفس المقاس بالمللي والسنتيمتر والمتر يحدّث نفس الصنف');
$countBefore = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '100', 'mm', '5', 'cm', '300', 'cm', '1'));
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '0.1', 'm', '0.05', 'm', '3000', 'mm', '1'));
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '10.000', 'cm', '50.0', 'mm', '3', 'm', '1'));
check_eq('لم يُنشأ صنف جديد', $countBefore, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
check_eq('الكمية أضيفت لنفس الصنف (90 + 3)', 93, stock_qty($pdo, $item, $wh1));
$lastLines = document_lines($pdo, (int) $pdo->query("SELECT MAX(id) FROM documents WHERE kind='in'")->fetchColumn());
check_eq('الوارد يحتفظ بوحداته كما أُدخلت', 'cm', $lastLines[0]['width_unit']);

section('هـ. فصل الأصناف');
record_receipt($pdo, $uid, receipt_input($wh1, $zan, '10', 'cm', '50', 'mm', '3', 'm', '5'));
$zanItem = item_id_for($pdo, $zan, 100000, 50000, 3000000);
check('نوعان مختلفان بنفس الأبعاد = صنفان', $zanItem !== null && $zanItem !== $item);
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '4', 'm', '5'));
$longItem = item_id_for($pdo, $mosky, 100000, 50000, 4000000);
check('نفس النوع بطول مختلف = صنف مستقل', $longItem !== null && $longItem !== $item);
check_eq('رصيد الطول الأصلي لم يتغير', 93, stock_qty($pdo, $item, $wh1));
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '5', 'cm', '100', 'mm', '3', 'm', '2'));
$swapped = item_id_for($pdo, $mosky, 50000, 100000, 3000000);
check('تبديل العرض والتخانة لا يُدمج تلقائيًا', $swapped !== null && $swapped !== $item);
expect_validation('اسم نوع مكرر بمسافات زائدة يُرفض', fn () => catalog_create($pdo, 'type', '  موسكي  '));
expect_validation('اسم مكرر بأشكال الحروف المنسوخة من PDF يُرفض (ﻣﻮﺳﻜﻲ)', fn () => catalog_create($pdo, 'type', 'ﻣﻮﺳﻜﻲ'));
expect_validation('اسم مكرر بالتطويل يُرفض (موسـكي)', fn () => catalog_create($pdo, 'type', 'موسـكي'));
$t1 = catalog_create($pdo, 'type', 'Pine');
expect_validation('اسم لاتيني مكرر باختلاف حالة الأحرف يُرفض', fn () => catalog_create($pdo, 'type', 'PINE'));
$red = catalog_create($pdo, 'type', 'زان أحمر');
check('«زان» و«زان أحمر» نوعان مختلفان', $red > 0 && $red !== $zan);
$aro1 = catalog_create($pdo, 'type', 'أرو');
$aro2 = catalog_create($pdo, 'type', 'ارو');
check('لا دمج عشوائي بين أ و ا', $aro1 !== $aro2);
check_eq('الاسم يُحفظ بعد إزالة المسافات الزائدة', 'سويد أبيض', catalog_find($pdo, 'type', catalog_create($pdo, 'type', "  سويد \t  أبيض "))['name']);

section('و. المدخلات');
$ar = record_receipt($pdo, $uid, receipt_input($wh2, $zan, '١٠', 'cm', '٥٠', 'mm', '٣', 'm', '١٠'));
check_eq('أرقام عربية مقبولة وتحسب 0.15', '0.15', Num::trimDecimal(find_document($pdo, $ar['id'])['total_volume_m3']));
$fr = record_receipt($pdo, $uid, receipt_input($wh2, $zan, '2.5', 'cm', '٢٫٥', 'cm', '1.25', 'm', '4'));
check_eq('الكسور العشرية (2.5 و٢٫٥ و1.25): 0.003125', '0.003125', Num::trimDecimal(find_document($pdo, $fr['id'])['total_volume_m3']));
$cases = [
    'عرض سالب' => ['-5', 'cm', '50', 'mm', '3', 'm', '10', 'width', 'لا يقبل قيمًا سالبة'],
    'عرض صفر' => ['0', 'cm', '50', 'mm', '3', 'm', '10', 'width', 'أكبر من صفر'],
    'عدد بكسر' => ['10', 'cm', '50', 'mm', '3', 'm', '2.5', 'quantity', 'بدون كسور'],
    'عدد صفر' => ['10', 'cm', '50', 'mm', '3', 'm', '0', 'quantity', 'أكبر من صفر'],
    'عدد سالب' => ['10', 'cm', '50', 'mm', '3', 'm', '-3', 'quantity', 'سالبة'],
    'نص تالف' => ['abc', 'cm', '50', 'mm', '3', 'm', '10', 'width', 'أدخل رقمًا موجبًا'],
    'فاصلة' => ['1,5', 'cm', '50', 'mm', '3', 'm', '10', 'width', 'الفاصلة'],
    'كسر بالشرطة' => ['1/2', 'cm', '50', 'mm', '3', 'm', '10', 'width', 'بالنقطة العشرية'],
    'خانات زائدة بالمللي' => ['10.0001', 'mm', '50', 'mm', '3', 'm', '10', 'width', 'أرقام عشرية على الأكثر'],
    'أكبر من 50 متر' => ['51', 'm', '50', 'mm', '3', 'm', '10', 'width', 'أكبر من الحد المسموح'],
    'وحدة غير صحيحة' => ['10', 'inch', '50', 'mm', '3', 'm', '10', 'width', 'اختر وحدة'],
];
foreach ($cases as $label => [$w, $wu, $t, $tu, $l, $lu, $q, $field, $needle]) {
    $errors = expect_validation("يُرفض: {$label}", fn () => record_receipt($pdo, $uid, receipt_input($wh1, $mosky, $w, $wu, $t, $tu, $l, $lu, $q)));
    check("  رسالة عربية واضحة ({$label})", str_contains($errors[$field] ?? '', $needle), $errors[$field] ?? 'no message');
}
$errors = expect_validation('سعر صفر مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$item, 1, '0']])));
check('  رسالة السعر', str_contains(implode(' ', $errors), 'أكبر من صفر'));
expect_validation('سعر بثلاث خانات عشرية مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$item, 1, '10.005']])));
$p = record_sale($pdo, $uid, sale_input($wh1, [[$item, 1, '20000.500']]));
check_eq('الأصفار الزائدة في السعر مقبولة (20000.500)', '20000.50', (string) document_lines($pdo, $p['id'])[0]['price_per_m3']);
check_eq('الرصيد بعد بيع قطعة', 92, stock_qty($pdo, $item, $wh1));

section('تقريب القيمة لأقرب قرش (النصف لأعلى)');
record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '12.5', 'mm', '12.5', 'mm', '1', 'm', '1'));
$small = item_id_for($pdo, $mosky, 12500, 12500, 1000000);
$sr = record_sale($pdo, $uid, sale_input($wh1, [[$small, 1, '20000']]));
check_eq('0.00015625 × 20000 = 3.125 ← 3.13', '3.13', (string) find_document($pdo, $sr['id'])['total_amount']);

section('ز. منع تجاوز المخزون من الخادم');
record_receipt($pdo, $uid, receipt_input($wh1, $zan, '20', 'cm', '5', 'cm', '6', 'm', '10'));
$z10 = item_id_for($pdo, $zan, 200000, 50000, 6000000);
$errors = expect_validation('بيع 11 والمتاح 10 مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$z10, 11, '15000']])));
check('  الرسالة تذكر المتاح', str_contains(implode(' ', $errors), 'المتاح'), implode(' ', $errors));
check_eq('  الرصيد لم يتغير (10)', 10, stock_qty($pdo, $z10, $wh1));
$salesBefore = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE kind='sale'")->fetchColumn();
expect_validation('فاتورة بسطرين أحدهما يتجاوز الرصيد تُرفض كلها', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$item, 5, '20000'], [$z10, 11, '15000']])));
check_eq('  لم تُسجل فاتورة', $salesBefore, (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE kind='sale'")->fetchColumn());
check_eq('  رصيد السطر الأول لم يتغير', 92, stock_qty($pdo, $item, $wh1));
expect_validation('مقاس مكرر في نفس الفاتورة مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$z10, 1, '100'], [$z10, 1, '100']])));
expect_validation('البيع من مخزن لا يوجد فيه المقاس مرفوض', fn () => record_sale($pdo, $uid, sale_input($wh2, [[$z10, 1, '100']])));

section('فاتورة متعددة الأسطر');
$multi = record_sale($pdo, $uid, sale_input($wh1, [[$item, 2, '20000'], [$z10, 3, '15000']], ['party_name' => 'شركة النجار']));
$md = find_document($pdo, $multi['id']);
// موسكي: 0.015 × 2 = 0.03 م³ × 20000 = 600 ؛ زان: 0.2×0.05×6 = 0.06 × 3 = 0.18 × 15000 = 2700
check_eq('إجمالي القيمة 3300.00', '3300.00', (string) $md['total_amount']);
check_eq('إجمالي الحجم 0.21', '0.21', Num::trimDecimal($md['total_volume_m3']));
check_eq('عدد الأسطر 2', 2, (int) $md['line_count']);
check_eq('رصيد موسكي 90', 90, stock_qty($pdo, $item, $wh1));
check_eq('رصيد زان 7', 7, stock_qty($pdo, $z10, $wh1));

section('المخازن والتحويل');
$tr = record_transfer($pdo, $uid, transfer_input($wh1, $wh2, [[$item, 30], [$z10, 2]]));
check_eq('المصدر بعد التحويل (موسكي) 60', 60, stock_qty($pdo, $item, $wh1));
check_eq('المستلم بعد التحويل (موسكي) 30', 30, stock_qty($pdo, $item, $wh2));
check_eq('زان في الفرع 2', 2, stock_qty($pdo, $z10, $wh2));
check_eq('رقم التحويل الأول = 1', 1, (int) find_document($pdo, $tr['id'])['doc_no']);
expect_validation('التحويل لنفس المخزن مرفوض', fn () => record_transfer($pdo, $uid, transfer_input($wh1, $wh1, [[$item, 1]])));
expect_validation('تحويل كمية أكبر من رصيد المصدر مرفوض', fn () => record_transfer($pdo, $uid, transfer_input($wh2, $wh1, [[$item, 31]])));
$fromBranch = record_sale($pdo, $uid, sale_input($wh2, [[$item, 5, '21000']]));
check_eq('البيع من الفرع يخصم من الفرع فقط', 25, stock_qty($pdo, $item, $wh2));
check_eq('المخزن الرئيسي لم يتأثر', 60, stock_qty($pdo, $item, $wh1));
$all = inventory_view($pdo, '', 0, 0, false);
$row = null;
foreach ($all['groups'] as $g) {
    foreach ($g['rows'] as $rr) {
        if ((int) $rr['id'] === $item) {
            $row = $rr;
        }
    }
}
check_eq('عرض كل المخازن يجمع الرصيد (60 + 25)', 85, $row['qty'] ?? null);
check_eq('توزيع الرصيد على المخازن', [$wh1 => 60, $wh2 => 25], $row['by_warehouse'] ?? null);
expect_validation('حذف مخزن له أرصدة مرفوض', fn () => catalog_delete($pdo, 'warehouse', $wh2));
expect_validation('حذف نوع له وارد مرفوض', fn () => catalog_delete($pdo, 'type', $mosky));
$emptyWh = catalog_create($pdo, 'warehouse', 'مخزن مؤقت');
catalog_delete($pdo, 'warehouse', $emptyWh);
check('حذف مخزن غير مستخدم مسموح', catalog_find($pdo, 'warehouse', $emptyWh) === null);

section('ط. تكرار الطلب');
$in = sale_input($wh1, [[$item, 1, '20000']]);
$first = record_sale($pdo, $uid, $in);
$second = record_sale($pdo, $uid, $in);
check('إعادة إرسال نفس الطلب تعيد نفس الفاتورة', $second['duplicate'] === true && $second['id'] === $first['id']);
check_eq('الخصم حدث مرة واحدة (60 - 1)', 59, stock_qty($pdo, $item, $wh1));
$changed = $in;
$changed['lines'][0]['quantity'] = '2';
$errors = expect_validation('نفس الرمز مع بيانات مختلفة يُرفض بوضوح', fn () => record_sale($pdo, $uid, $changed));
check('  الرسالة تذكر رقم الفاتورة المحفوظة', str_contains($errors['request_token'] ?? '', 'بيع رقم'), $errors['request_token'] ?? '');
check_eq('  الرصيد لم يتغير', 59, stock_qty($pdo, $item, $wh1));
$rin = receipt_input($wh1, $zan, '1', 'm', '1', 'm', '1', 'm', '1');
$a = record_receipt($pdo, $uid, $rin);
$b = record_receipt($pdo, $uid, $rin);
check('إعادة إرسال نفس الوارد لا تضيف مرتين', $b['duplicate'] && $a['id'] === $b['id']
    && stock_qty($pdo, item_id_for($pdo, $zan, 1000000, 1000000, 1000000), $wh1) === 1);

section('ي. فشل الحفظ لا يترك تغييرًا جزئيًا');
$root = root_pdo();
$root->exec('DROP TRIGGER IF EXISTS t_fail_version');
$root->exec("CREATE TRIGGER t_fail_version BEFORE UPDATE ON counters FOR EACH ROW BEGIN
    IF NEW.name = 'data_version' AND @wood_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF; END");
$snapshot = fn () => $pdo->query("SELECT
    (SELECT COUNT(*) FROM items), (SELECT COUNT(*) FROM documents), (SELECT COUNT(*) FROM document_lines),
    (SELECT COALESCE(SUM(qty_on_hand),0) FROM stock), (SELECT GROUP_CONCAT(name, '=', value ORDER BY name) FROM counters)")->fetch(PDO::FETCH_NUM);
$pdo->exec('SET @wood_fail = 1');
$before = $snapshot();
foreach ([
    'وارد لمقاس جديد' => fn () => record_receipt($pdo, $uid, receipt_input($wh1, $zan, '7', 'cm', '7', 'cm', '7', 'm', '7')),
    'وارد لمقاس موجود' => fn () => record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '7')),
    'فاتورة بيع بسطرين' => fn () => record_sale($pdo, $uid, sale_input($wh1, [[$item, 1, '20000'], [$z10, 1, '100']])),
    'تحويل' => fn () => record_transfer($pdo, $uid, transfer_input($wh1, $wh2, [[$item, 1]])),
    'إلغاء فاتورة' => fn () => cancel_document($pdo, $uid, $first['id'], ''),
] as $label => $fn) {
    $threw = false;
    try {
        $fn();
    } catch (PDOException $e) {
        $threw = str_contains($e->getMessage(), 'injected failure');
    }
    check("فشل مفتعل أثناء: {$label}", $threw);
    check_eq("  لا تغيير جزئي بعد: {$label}", $before, $snapshot());
}
$pdo->exec('SET @wood_fail = 0');
$root->exec('DROP TRIGGER t_fail_version');
$next = record_sale($pdo, $uid, sale_input($wh1, [[$item, 1, '20000']]));
check_eq('الترقيم بلا فجوات بعد الفشل', (int) $pdo->query("SELECT MAX(doc_no) FROM documents WHERE kind='sale' AND id <> " . (int) $next['id'])->fetchColumn() + 1, $next['doc_no']);

section('ك. الإلغاء');
$before = stock_qty($pdo, $item, $wh1);
cancel_document($pdo, $uid, $next['id'], 'خطأ في الإدخال');
check_eq('إلغاء البيع يعيد الكمية مرة واحدة', $before + 1, stock_qty($pdo, $item, $wh1));
$errors = expect_validation('الإلغاء مرتين مرفوض', fn () => cancel_document($pdo, $uid, $next['id'], ''));
check('  الرسالة: ملغى بالفعل', str_contains($errors['document'] ?? '', 'ملغى بالفعل'));
check_eq('  الرصيد لم يتغير بالإلغاء الثاني', $before + 1, stock_qty($pdo, $item, $wh1));
$cd = find_document($pdo, $next['id']);
check('الحركة الملغاة باقية في السجل مع السبب والوقت', $cd['status'] === 'cancelled' && $cd['cancel_reason'] === 'خطأ في الإدخال' && $cd['cancelled_at'] !== null);
// وارد ثم بيع جزء منه ثم محاولة إلغاء الوارد
$rcv = record_receipt($pdo, $uid, receipt_input($wh2, $mosky, '9', 'cm', '9', 'cm', '9', 'm', '10'));
$i9 = item_id_for($pdo, $mosky, 90000, 90000, 9000000);
record_sale($pdo, $uid, sale_input($wh2, [[$i9, 4, '100']]));
$errors = expect_validation('إلغاء وارد يجعل الرصيد سالبًا مرفوض', fn () => cancel_document($pdo, $uid, $rcv['id'], ''));
check('  الرسالة توضح الرصيد الحالي', str_contains($errors['document'] ?? '', 'الآن'), $errors['document'] ?? '');
check_eq('  الرصيد لم يتغير (6)', 6, stock_qty($pdo, $i9, $wh2));
$rcv2 = record_receipt($pdo, $uid, receipt_input($wh2, $mosky, '9', 'cm', '9', 'cm', '9', 'm', '3'));
cancel_document($pdo, $uid, $rcv2['id'], '');
check_eq('إلغاء وارد مسموح عندما يكفي الرصيد', 6, stock_qty($pdo, $i9, $wh2));
// إلغاء تحويل: التحويل الأول نقل 30 للفرع ثم بيع منها 5، فإلغاؤه يجعل رصيد الفرع سالبًا
check_eq('رصيد الرئيسي قبل اختبار إلغاء التحويل', 59, stock_qty($pdo, $item, $wh1));
check_eq('رصيد الفرع قبل اختبار إلغاء التحويل', 25, stock_qty($pdo, $item, $wh2));
$errors = expect_validation('إلغاء تحويل بِيع جزء منه في المخزن المستلم مرفوض', fn () => cancel_document($pdo, $uid, $tr['id'], ''));
check('  الرسالة تذكر المخزن المستلم ورصيده', str_contains($errors['document'] ?? '', 'مخزن الفرع'), $errors['document'] ?? '');
check_eq('  رصيد المصدر لم يتغير', 59, stock_qty($pdo, $item, $wh1));
check_eq('  رصيد المستلم لم يتغير', 25, stock_qty($pdo, $item, $wh2));
$tr2 = record_transfer($pdo, $uid, transfer_input($wh1, $wh2, [[$item, 4]]));
check_eq('رقم التحويل الثاني = 2', 2, $tr2['doc_no']);
check_eq('بعد التحويل الثاني: الرئيسي 55', 55, stock_qty($pdo, $item, $wh1));
cancel_document($pdo, $uid, $tr2['id'], '');
check_eq('إلغاء التحويل يعيد الكمية للمصدر (59)', 59, stock_qty($pdo, $item, $wh1));
check_eq('إلغاء التحويل يخصمها من المستلم (25)', 25, stock_qty($pdo, $item, $wh2));

section('ح. التزامن: جلستان تبيع كل منهما 7 والمتاح 10');
$rx = record_receipt($pdo, $uid, receipt_input($wh1, $zan, '3', 'cm', '3', 'cm', '3', 'm', '10'));
$c10 = item_id_for($pdo, $zan, 30000, 30000, 3000000);
for ($round = 1; $round <= 3; $round++) {
    if ($round > 1) {
        $pdo->prepare('UPDATE stock SET qty_on_hand = 10 WHERE item_id = ? AND warehouse_id = ?')->execute([$c10, $wh1]);
    }
    $root = root_pdo();
    $root->beginTransaction();
    $root->prepare('SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE')->execute([$c10, $wh1]);
    $procs = [];
    for ($k = 0; $k < 2; $k++) {
        $cmd = [PHP_BINARY, __DIR__ . '/worker.php', 'sale', (string) $wh1, (string) $c10, '7', '100'];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    $waiting = 0;
    for ($t = 0; $t < 100 && $waiting < 2; $t++) {
        usleep(100000);
        $waiting = (int) $root->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
    }
    check("الجولة {$round}: العمليتان تنتظران نفس القفل فعلًا", $waiting === 2, "waiting={$waiting}");
    $root->commit();
    $results = [];
    foreach ($procs as $k => $p) {
        $results[] = json_decode(trim(stream_get_contents($pipes[$k][1])), true);
        proc_close($p);
    }
    $ok = count(array_filter($results, fn ($r) => $r['ok'] ?? false));
    check_eq("الجولة {$round}: نجحت عملية واحدة فقط", 1, $ok);
    check_eq("الجولة {$round}: الرصيد النهائي 3 (ليس سالبًا)", 3, stock_qty($pdo, $c10, $wh1));
}

section('ح2. ضغط: 20 عملية متوازية تبيع كل منها قطعة من 10');
$pdo->prepare('UPDATE stock SET qty_on_hand = 10 WHERE item_id = ? AND warehouse_id = ?')->execute([$c10, $wh1]);
$procs = [];
$pipes = [];
for ($k = 0; $k < 20; $k++) {
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', 'sale', (string) $wh1, (string) $c10, '1', '100'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
}
$ok = 0;
foreach ($procs as $k => $p) {
    $r = json_decode(trim(stream_get_contents($pipes[$k][1])), true);
    $ok += ($r['ok'] ?? false) ? 1 : 0;
    proc_close($p);
}
check_eq('نجحت 10 عمليات بالضبط', 10, $ok);
check_eq('الرصيد النهائي صفر', 0, stock_qty($pdo, $c10, $wh1));

section('ط2. نفس الطلب يُرسل مرتين في نفس اللحظة');
$pdo->prepare('UPDATE stock SET qty_on_hand = 10 WHERE item_id = ? AND warehouse_id = ?')->execute([$c10, $wh1]);
$token = new_request_token();
$root = root_pdo();
$root->beginTransaction();
$root->prepare('SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE')->execute([$c10, $wh1]);
$procs = [];
$pipes = [];
for ($k = 0; $k < 2; $k++) {
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', 'sale', (string) $wh1, (string) $c10, '3', '100', $token], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
}
for ($t = 0; $t < 100; $t++) {
    usleep(100000);
    if ((int) $root->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn() === 2) {
        break;
    }
}
$root->commit();
$res = [];
foreach ($procs as $k => $p) {
    $res[] = json_decode(trim(stream_get_contents($pipes[$k][1])), true);
    proc_close($p);
}
check('الطلبان نجحا شكليًا (الثاني يعيد نفس الفاتورة)', ($res[0]['ok'] ?? false) && ($res[1]['ok'] ?? false) && $res[0]['id'] === $res[1]['id'], json_encode($res, JSON_UNESCAPED_UNICODE));
check_eq('الخصم حدث مرة واحدة (10 - 3)', 7, stock_qty($pdo, $c10, $wh1));

section('إلغاء متزامن لنفس الفاتورة');
$sale = record_sale($pdo, $uid, sale_input($wh1, [[$c10, 2, '100']]));
$procs = [];
$pipes = [];
for ($k = 0; $k < 2; $k++) {
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', 'cancel', (string) $sale['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
}
$ok = 0;
foreach ($procs as $k => $p) {
    $r = json_decode(trim(stream_get_contents($pipes[$k][1])), true);
    $ok += ($r['ok'] ?? false) ? 1 : 0;
    proc_close($p);
}
check_eq('إلغاء واحد فقط نجح', 1, $ok);
check_eq('الكمية عادت مرة واحدة', 7, stock_qty($pdo, $c10, $wh1));

section('التحديث التلقائي: رقم إصدار البيانات');
$v1 = data_version($pdo);
record_receipt($pdo, $uid, receipt_input($wh1, $zan, '3', 'cm', '3', 'cm', '3', 'm', '1'));
$v2 = data_version($pdo);
check('كل عملية حفظ ترفع رقم الإصدار', (int) $v2 === (int) $v1 + 1, "$v1 -> $v2");
catalog_rename($pdo, 'warehouse', $wh2, 'مخزن الفرع الجديد');
check('تعديل اسم مخزن يرفع رقم الإصدار', (int) data_version($pdo) === (int) $v2 + 1);
check_eq('المستندات القديمة تحتفظ باسم المخزن وقت الحركة', 'مخزن الفرع', find_document($pdo, $fromBranch['id'])['warehouse_name']);
expect_validation('عملية فاشلة لا ترفع رقم الإصدار', fn () => record_sale($pdo, $uid, sale_input($wh1, [[$c10, 999, '1']])));
check_eq('  رقم الإصدار ثابت', (int) $v2 + 1, (int) data_version($pdo));

finish();
