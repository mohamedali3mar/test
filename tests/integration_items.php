<?php
declare(strict_types=1);

/*
 * المقاسات: الإخفاء بدل الحذف، والإظهار، ووحدات العرض، وإخفاء نوع الخشب، والصلاحيات (lib/items.php).
 * التشغيل: WOOD_TEST_DB=wood_items php tests/integration_items.php
 */

require __DIR__ . '/lib.php';

$pdo = fresh_database();
$uid = seed_user($pdo);
$pdo->exec("UPDATE users SET role = 'admin'");
$as = function (int $id) use ($pdo): void {
    $u = session_user_row($pdo, $id);
    $_SESSION = ['user_id' => $id, 'username' => (string) $u['username'], 'role' => user_role($u), 'perm_overrides' => user_permission_overrides($u)];
    reset_branch_scope_cache();
};
$as($uid);
$audit = fn (string $action): int => (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = " . $pdo->quote($action))->fetchColumn();
$dv = fn (): int => (int) data_version($pdo);

$wh = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
$mosky = catalog_create($pdo, 'type', 'موسكي');
$zan = catalog_create($pdo, 'type', 'زان');
$r1 = record_receipt($pdo, $uid, receipt_input($wh, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '10'));
$item = item_id_for($pdo, $mosky, 100000, 50000, 3000000);
record_receipt($pdo, $uid, receipt_input($wh, $zan, '20', 'cm', '5', 'cm', '4', 'm', '3'));
$zanItem = item_id_for($pdo, $zan, 200000, 50000, 4000000);

section('الإخفاء بدل الحذف');
$errors = expect_validation('مقاس له رصيد لا يُخفى', fn () => item_set_hidden($pdo, $item, true));
check('  رسالة بالرصيد', str_contains($errors['item'] ?? '', '١٠ قطعة') || str_contains($errors['item'] ?? '', '10 قطعة'), $errors['item'] ?? '');
cancel_document($pdo, $uid, $r1['id'], 'مقاس خاطئ');
check('بعد إلغاء الوارد: الرصيد صفر والمقاس باقٍ (المستند الملغى يقرأ أبعاده منه)', (int) $pdo->query("SELECT COALESCE(SUM(qty_on_hand),0) FROM stock WHERE item_id = $item")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM items WHERE id = $item")->fetchColumn() === 1);
$v0 = $dv();
check('إخفاء مقاس رصيده صفر', item_set_hidden($pdo, $item, true) === true);
check('  محفوظ', (int) $pdo->query("SELECT is_hidden FROM items WHERE id = $item")->fetchColumn() === 1);
check('  في المراقبة ورقم الإصدار ارتفع', $audit('item.hide') === 1 && $dv() === $v0 + 1);
check('  ومرة أخرى بلا تغيير', item_set_hidden($pdo, $item, true) === false && $audit('item.hide') === 1);
$ids = fn (array $view): array => array_map(fn ($r) => (int) $r['id'], array_merge(...array_map(fn ($g) => $g['rows'], array_values($view['groups']))));
check('لا يظهر في المخزون', !in_array($item, $ids(inventory_view($pdo, '', 0, 0, false)), true) && in_array($zanItem, $ids(inventory_view($pdo, '', 0, 0, false)), true));
check('  ويظهر عند طلب المخفية', in_array($item, $ids(inventory_view($pdo, '', 0, 0, false, null, true)), true));
check('  ولا يُحسب ضمن المقاسات النافدة في الرئيسية', home_overview($pdo, null)['stock']['empty'] === 0);
check('قائمة المقاسات: المخفية وحدها', array_map(fn ($r) => (int) $r['id'], items_list($pdo, 0, true)) === [$item]);
check('إظهاره', item_set_hidden($pdo, $item, false) === true && (int) $pdo->query("SELECT is_hidden FROM items WHERE id = $item")->fetchColumn() === 0 && $audit('item.show') === 1);
item_set_hidden($pdo, $item, true);

section('وحدات العرض: للعرض فقط');
$before = fmt_size(items_list($pdo, $mosky)[0]);
check('تغيير وحدة العرض إلى مللي', item_set_units($pdo, $item, ['width_unit' => 'mm', 'thickness_unit' => 'mm', 'length_unit' => 'cm']) === true);
$it = $pdo->query("SELECT * FROM items WHERE id = $item")->fetch();
check('  الأبعاد والحجم كما هي', (int) $it['width_um'] === 100000 && (int) $it['length_um'] === 3000000 && $it['piece_volume_m3'] === '0.015000000000000000');
check('  والعرض بالوحدات الجديدة', str_contains(fmt_size($it), 'عرض' . "\u{00A0}" . digits('100') . "\u{00A0}مللي") && fmt_size($it) !== $before, fmt_size($it));
check('  والمستند السابق يحتفظ بوحداته', $pdo->query("SELECT CONCAT(width_unit, thickness_unit, length_unit) FROM document_lines WHERE document_id = {$r1['id']}")->fetchColumn() === 'cmmmm');
check('  في المراقبة بالقديم والجديد', $audit('item.units') === 1);
$errors = expect_validation('وحدة غير صالحة مرفوضة', fn () => item_set_units($pdo, $item, ['width_unit' => 'km', 'thickness_unit' => 'mm', 'length_unit' => 'cm']));
check('  رسالة الحقل', isset($errors['width_unit']));

section('إخفاء نوع الخشب');
$errors = expect_validation('نوع لمقاساته رصيد لا يُخفى', fn () => type_set_hidden($pdo, $zan, true));
check('  رسالة واضحة', str_contains($errors['item'] ?? '', 'زان'), $errors['item'] ?? '');
check('إخفاء نوع كل مقاساته رصيدها صفر', type_set_hidden($pdo, $mosky, true) === true && type_is_hidden($pdo, $mosky) && $audit('type.hide') === 1);
check('  وإظهاره', type_set_hidden($pdo, $mosky, false) === true && !type_is_hidden($pdo, $mosky) && $audit('type.show') === 1);

section('الصلاحيات');
$staff = user_create($pdo, $uid, ['display_name' => 'موظف', 'username' => 'staff_items', 'role' => 'staff', 'password' => 'Staff-Pass-001', 'password_confirm' => 'Staff-Pass-001']);
$as($staff);
$errors = expect_validation('الموظف لا يخفي المقاسات افتراضيًا', fn () => item_set_hidden($pdo, $item, false));
check('  رسالة الصلاحية', str_contains($errors['item'] ?? '', 'صلاحية'));
check('  ويفتح الصفحة للعرض فقط', can_open('items') && !can('items.manage'));
$as($uid);
user_set_permissions($pdo, $uid, $staff, ['stock.view', 'documents.create', 'documents.view', 'catalog.view', 'items.manage']);
$as($staff);
check('بعد أن يمنحه المدير الصلاحية: يظهر المقاس', item_set_hidden($pdo, $item, false) === true);
$as($uid);
$branch = branch_create($pdo, ['name' => 'فرع آخر']);
$pdo->exec("UPDATE users SET branch_id = $branch WHERE id = $staff");
$as($staff);
expect_validation('المقيد بفرع لا يخفي (الإخفاء للشركة كلها)', fn () => item_set_hidden($pdo, $item, true));
$as($uid);

section('الوارد يظهر المقاس المخفي، والنوع المخفي لا يُستقبل');
$as($uid);
item_set_hidden($pdo, $item, true);
record_receipt($pdo, $uid, receipt_input($wh, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '4'));
check('أول وارد للمقاس المخفي يظهره', (int) $pdo->query("SELECT is_hidden FROM items WHERE id = $item")->fetchColumn() === 0);
$errors = expect_validation('لا يُخفى بعد أن صار له رصيد', fn () => item_set_hidden($pdo, $item, true));
$third = catalog_create($pdo, 'type', 'سويد');
type_set_hidden($pdo, $third, true);
$errors = expect_validation('وارد لنوع مخفي مرفوض على الخادم', fn () => record_receipt($pdo, $uid, receipt_input($wh, $third, '10', 'cm', '5', 'cm', '2', 'm', '1')));
check('  رسالة تدل على صفحة المقاسات', str_contains($errors['wood_type_id'] ?? '', 'المقاسات'), json_encode($errors, JSON_UNESCAPED_UNICODE));

section('الترقية 009 آمنة لإعادة التنفيذ');
$ok = true;
foreach (split_sql((string) file_get_contents(APP_ROOT . '/migrations/009_items_hidden_doc_edit.sql')) as $stmt) {
    try {
        $pdo->exec($stmt);
    } catch (PDOException $e) {
        $ok = false;
    }
}
check('إعادة تنفيذ كل أوامرها بلا أخطاء ولا تغيير', $ok && (int) $pdo->query("SELECT is_hidden FROM items WHERE id = $item")->fetchColumn() === 0);

finish();
