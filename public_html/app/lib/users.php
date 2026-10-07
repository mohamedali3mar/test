<?php
defined('APP_ROOT') || exit;

/*
 * المستخدمون والأدوار والصلاحيات.
 *
 *  - الصلاحية تُفحص على الخادم دائمًا (require_permission)، وإخفاء الروابط في الواجهة إضافة فقط.
 *  - الدور وحالة التفعيل يُقرآن من قاعدة البيانات في كل طلب داخل require_login (نفس استعلام
 *    auth_version)، والجلسة تحتفظ بالدور كنسخة مؤقتة تتحدث مع كل طلب.
 *  - خدمات إدارة المستخدمين تعمل داخل معاملة تقفل كل صفوف المستخدمين بترتيب ثابت قبل أي فحص،
 *    فعمليتان متزامنتان لا تتركان النظام بلا مدير نشط. كل عملية تُسجل في سجل المراقبة.
 */

const ROLE_LABELS = ['admin' => 'مدير', 'staff' => 'موظف'];

/** الصلاحية => الأدوار المسموح لها */
const PERMISSIONS = [
    'stock.view'       => ['admin', 'staff'], // المخزون والتحديث التلقائي
    'documents.create' => ['admin', 'staff'], // إضافة وارد، فاتورة بيع، تحويل
    'documents.view'   => ['admin', 'staff'], // السجل وتفاصيل المستند والطباعة
    'documents.cancel' => ['admin'],
    'catalog.view'     => ['admin', 'staff'], // عرض أنواع الخشب والمخازن
    'catalog.manage'   => ['admin'],          // إضافة وتعديل وحذف الأنواع والمخازن
    'account.manage'   => ['admin', 'staff'], // «حسابي»: كلمة المرور الخاصة بالمستخدم
    'settings.manage'  => ['admin'],
    'migrate.run'      => ['admin'],
    'users.manage'     => ['admin'],
    'monitor.view'     => ['admin'],
    'app.use'          => ['admin', 'staff'], // ملفات الواجهة المحمية (r=asset) وصفحة التقارير العامة
    // الحسابات (ACCOUNTING_SPEC.md §6)
    'manual_date'            => ['admin'],          // تاريخ يدوي للمستند أو السند
    'parties.view'           => ['admin', 'staff'],
    'parties.create'         => ['admin', 'staff'],
    'parties.manage'         => ['admin'],          // تعديل، رصيد افتتاحي، إيقاف، حذف
    'vouchers.view'          => ['admin', 'staff'],
    'vouchers.collect'       => ['admin', 'staff'],
    'vouchers.expense'       => ['admin', 'staff'],
    'vouchers.pay'           => ['admin'],
    'vouchers.cash_transfer' => ['admin'],
    'vouchers.cancel'        => ['admin'],
    'cash.manage'            => ['admin'],          // الخزائن وتصنيفات المصروفات وكشف الخزنة
    'statements.view'        => ['admin', 'staff'], // كشوف حساب العملاء والموردين وأرصدتهم
    'reports.sales'          => ['admin'],
    'reports.profit'         => ['admin'],          // ويُظهر التكلفة والربح في تفاصيل المستند
    'reports.valuation'      => ['admin'],          // وتقييم المخزون الافتتاحي
    'dashboard'              => ['admin'],
    'admin.only'       => ['admin'],
];

/**
 * صلاحية فتح كل صفحة. أي صفحة غير مسجلة هنا (مثل صفحة جديدة تُضاف لاحقًا) تكون للمدير فقط
 * حتى تُضاف صراحة، فلا تُفتح صفحة إدارية للموظفين بالخطأ.
 */
const ROUTE_PERMISSIONS = [
    'inventory'  => 'stock.view',
    'api'        => 'stock.view',
    'receive'    => 'documents.create',
    'sell'       => 'documents.create',
    'transfer'   => 'documents.create',
    'documents'  => 'documents.view',
    'document'   => 'documents.view',   // الإلغاء داخلها يتطلب documents.cancel
    'print'      => 'documents.view',
    'types'      => 'catalog.view',     // التعديل داخلها يتطلب catalog.manage
    'warehouses' => 'catalog.view',
    'account'    => 'account.manage',
    'settings'   => 'settings.manage',
    'users'      => 'users.manage',
    'monitor'    => 'monitor.view',
    'asset'      => 'app.use',
    'branches'   => 'catalog.view',
    'export'     => 'documents.view',   // كل تقرير يفحص صلاحيته الخاصة أيضًا
    'pdf'        => 'documents.view',
    // الحسابات
    'opening_valuation'  => 'reports.valuation',
    'reports'            => 'app.use',  // الصفحة تعرض فقط التقارير المسموحة للدور
    'dashboard'          => 'dashboard',
    'parties'            => 'parties.view',
    'party_statement'    => 'statements.view',
    'cash_boxes'         => 'cash.manage',
    'cash_statement'     => 'cash.manage',
    'expense_categories' => 'cash.manage',
    'vouchers'           => 'vouchers.view',
    'voucher'            => 'vouchers.view',
    'voucher_print'      => 'vouchers.view',
    'collect'            => 'vouchers.collect',
    'pay'                => 'vouchers.pay',
    'expense'            => 'vouchers.expense',
    'cash_transfer'      => 'vouchers.cash_transfer',
];

const USER_ONLINE_SECONDS = 300;    // «متصل الآن»: ظهر خلال آخر 5 دقائق
const USER_SEEN_INTERVAL = 60;      // تحديث last_seen_at مرة كل دقيقة على الأكثر لكل جلسة

/* ===================== الصلاحيات ===================== */

function current_role(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

function can(string $perm): bool
{
    return current_user_id() > 0 && in_array(current_role(), PERMISSIONS[$perm] ?? [], true);
}

/** يوقف الطلب برسالة 403 عربية (أو JSON للتحديث التلقائي) إذا لم تكن للمستخدم الصلاحية */
function require_permission(string $perm): void
{
    if (can($perm)) {
        return;
    }
    if (is_live_request()) {
        json_response(['error' => 'forbidden'], 403);
    }
    render_simple_error('ليست لديك صلاحية لهذه الصفحة أو لهذا الإجراء. إذا كنت تحتاجها راجع مدير النظام.', 403);
}

function route_permission(string $route): string
{
    return ROUTE_PERMISSIONS[$route] ?? 'admin.only';
}

/** لإظهار روابط القائمة المسموحة فقط */
function can_open(string $route): bool
{
    return can(route_permission($route));
}

/* ===================== جلسة المستخدم ===================== */

/**
 * صف المستخدم لفحص الجلسة. SELECT * حتى يعمل الاستعلام نفسه قبل تطبيق الترقية 004 وبعدها
 * (قبلها لا توجد أعمدة الدور والتفعيل، وكل الحسابات تُعامل كمديرين نشطين).
 */
function session_user_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function user_role(array $u): string
{
    $role = (string) ($u['role'] ?? 'admin');
    return isset(ROLE_LABELS[$role]) ? $role : 'staff';
}

function user_is_active(array $u): bool
{
    return (int) ($u['is_active'] ?? 1) === 1;
}

function user_display_name(array $u): string
{
    $name = (string) ($u['display_name'] ?? '');
    return $name !== '' ? $name : (string) $u['username'];
}

/**
 * بعد التحقق من الجلسة في كل طلب: يحدّث نسخة الدور والاسم في الجلسة، ويسجل آخر ظهور.
 * في طلبات الحفظ يحمّل الإعدادات قبل أي معاملة، حتى تنسق ملخصات سجل المراقبة الأرقام
 * دون قراءة غير مقفلة داخل المعاملة.
 */
function user_session_refresh(PDO $pdo, array $user): void
{
    $_SESSION['role'] = user_role($user);
    $_SESSION['display_name'] = user_display_name($user);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        app_setting('digits');
    }
    user_touch_last_seen($pdo);
}

/** يحدّث users.last_seen_at مرة كل دقيقة على الأكثر لكل جلسة، ولا يحدّثه التحديث التلقائي أبدًا */
function user_touch_last_seen(PDO $pdo): void
{
    if (is_live_request() || current_user_id() <= 0 || time() - (int) ($_SESSION['seen_at'] ?? 0) < USER_SEEN_INTERVAL) {
        return;
    }
    try {
        $pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([now(), current_user_id()]);
    } catch (PDOException $e) {
        if (audit_db_error_code($e) !== 1054) { // العمود غير موجود قبل تطبيق الترقية 004
            throw $e;
        }
    }
    $_SESSION['seen_at'] = time();
}

/* ===================== قراءة المستخدمين ===================== */

/** هل طُبقت الترقية 004 (الأدوار وسجل المراقبة)؟ صفحتا المستخدمين والمراقبة تحتاجانها */
function users_audit_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT role, is_active, display_name, last_seen_at FROM users LIMIT 0');
        $pdo->query('SELECT id FROM audit_log LIMIT 0');
        return true;
    } catch (PDOException $e) {
        if (!in_array(audit_db_error_code($e), [1054, 1146], true)) {
            throw $e;
        }
        return false;
    }
}

/** تنبيه صفحات المستخدمين والمراقبة قبل تطبيق الترقية */
function render_migration_needed(string $title, string $active): never
{
    render_header($title, $active);
    echo '<h1>' . h($title) . '</h1>';
    echo '<div class="alert alert-warning" role="status">هذه الصفحة تحتاج تحديث قاعدة البيانات. افتح <a href="'
        . h(url('settings')) . '">الإعدادات</a> واضغط «تحديث قاعدة البيانات» بعد أخذ نسخة احتياطية.</div>';
    render_footer();
    exit;
}

function user_find(PDO $pdo, int $id): ?array
{
    return $id > 0 ? session_user_row($pdo, $id) : null;
}

/** كل المستخدمين: النشطون أولًا ثم حسب الاسم */
function users_all(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM users ORDER BY is_active DESC, role, username')->fetchAll();
}

/** @return array<int,string> معرف المستخدم => الاسم المعروض (يعمل قبل الترقية 004 أيضًا) */
function users_name_map(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT * FROM users ORDER BY id') as $u) {
        $out[(int) $u['id']] = user_display_name($u);
    }
    return $out;
}

function user_is_online(array $u): bool
{
    return user_is_active($u) && !empty($u['last_seen_at'])
        && strtotime((string) $u['last_seen_at']) >= time() - USER_ONLINE_SECONDS;
}

/** المستخدمون النشطون الذين ظهروا خلال آخر 5 دقائق، الأحدث أولًا */
function users_online(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE is_active = 1 AND last_seen_at >= ? ORDER BY last_seen_at DESC');
    $stmt->execute([date('Y-m-d H:i:s', time() - USER_ONLINE_SECONDS)]);
    return $stmt->fetchAll();
}

/* ===================== التحقق من المدخلات ===================== */

/** @return array{0:?string,1:?string} [الاسم، رسالة الخطأ] */
function user_validate_display_name(string $raw): array
{
    $name = clean_text($raw);
    if ($name === '' || mb_strlen($name) > 100) {
        return [null, 'الاسم المعروض مطلوب (' . fmt_int(100) . ' حرف على الأكثر)، مثل: أحمد محمود.'];
    }
    return [$name, null];
}

function user_validate_role(string $role): ?string
{
    return isset(ROLE_LABELS[$role]) ? null : 'اختر الدور: مدير أو موظف.';
}

/* ===================== خدمات إدارة المستخدمين ===================== */

/**
 * يقفل كل صفوف المستخدمين بترتيب المعرف (الجدول صغير)، فلا تتداخل عمليتا إدارة متزامنتان.
 * @return array<int,array> المعرف => الصف
 */
function users_lock_all(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT * FROM users ORDER BY id FOR UPDATE')->fetchAll() as $u) {
        $out[(int) $u['id']] = $u;
    }
    return $out;
}

/** المنفذ يجب أن يكون مديرًا نشطًا لحظة التنفيذ (وليس فقط عند فتح الصفحة) */
function users_assert_actor(array $rows, int $actorId): void
{
    $actor = $rows[$actorId] ?? null;
    if (!$actor || user_role($actor) !== 'admin' || !user_is_active($actor)) {
        throw new ValidationException(['user' => 'ليست لديك صلاحية إدارة المستخدمين.']);
    }
}

/** عدد المديرين النشطين بعد استبعاد مستخدم معين */
function users_other_active_admins(array $rows, int $excludeId): int
{
    $n = 0;
    foreach ($rows as $id => $u) {
        if ($id !== $excludeId && user_role($u) === 'admin' && user_is_active($u)) {
            $n++;
        }
    }
    return $n;
}

function users_target(array $rows, int $id): array
{
    if (!isset($rows[$id])) {
        throw new ValidationException(['user' => 'المستخدم غير موجود.']);
    }
    return $rows[$id];
}

/**
 * يضيف مستخدمًا. $in: display_name, username, role, password, password_confirm
 * @return int معرف المستخدم الجديد
 */
function user_create(PDO $pdo, int $actorId, array $in): int
{
    $errors = [];
    [$name, $err] = user_validate_display_name(input($in, 'display_name'));
    if ($err !== null) {
        $errors['display_name'] = $err;
    }
    [$username, $err] = validate_username(input($in, 'username'));
    if ($err !== null) {
        $errors['username'] = $err;
    }
    $role = input($in, 'role');
    if ($err = user_validate_role($role)) {
        $errors['role'] = $err;
    }
    $password = input($in, 'password');
    if ($username !== null && ($err = password_problem($password, input($in, 'password_confirm'), $username))) {
        $errors['password'] = $err;
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($actorId, $name, $username, $role, $hash) {
            users_assert_actor(users_lock_all($pdo), $actorId);
            $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, is_active, display_name, created_at) VALUES (?, ?, ?, 1, ?, ?)'
            )->execute([$username, $hash, $role, $name, now()]);
            $id = (int) $pdo->lastInsertId();
            audit_record($pdo, 'user.create', sprintf('إضافة المستخدم %s (%s) بدور %s', $username, $name, ROLE_LABELS[$role]),
                'user', $id, ['username' => $username, 'display_name' => $name, 'role' => $role]);
            data_version_bump($pdo);
            return $id;
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_users_username')) {
            throw new ValidationException(['username' => 'اسم المستخدم مستخدم بالفعل. اختر اسمًا آخر.']);
        }
        throw $e;
    }
}

/**
 * يعدل الاسم المعروض والدور. لا يغير المدير دوره هو، ولا يُخفَّض آخر مدير نشط.
 * @return array<string,array{old:string,new:string}> ما تغير فعلًا
 */
function user_update(PDO $pdo, int $actorId, int $id, array $in): array
{
    $errors = [];
    [$name, $err] = user_validate_display_name(input($in, 'display_name'));
    if ($err !== null) {
        $errors['display_name'] = $err;
    }
    $role = input($in, 'role');
    if ($err = user_validate_role($role)) {
        $errors['role'] = $err;
    }
    // الفرع: '' = كل الفروع، أو رقم فرع موجود (يُتحقق منه داخل المعاملة). غياب الحقل = بلا تغيير
    $branchRaw = array_key_exists('branch_id', $in) ? input($in, 'branch_id') : null;
    if ($branchRaw !== null && $branchRaw !== '' && !preg_match('/^[1-9]\d{0,9}\z/', $branchRaw)) {
        $errors['branch_id'] = 'اختر الفرع من القائمة.';
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    return db_transaction($pdo, function (PDO $pdo) use ($actorId, $id, $name, $role, $branchRaw) {
        $rows = users_lock_all($pdo);
        $u = users_target($rows, $id);
        $changes = [];
        if ($branchRaw !== null && array_key_exists('branch_id', $u)) {
            $newBranch = $branchRaw === '' ? null : (int) $branchRaw;
            $branchName = 'كل الفروع';
            if ($newBranch !== null) {
                $b = $pdo->prepare('SELECT name FROM branches WHERE id = ? LOCK IN SHARE MODE');
                $b->execute([$newBranch]);
                $branchName = $b->fetchColumn();
                if ($branchName === false) {
                    throw new ValidationException(['branch_id' => 'الفرع غير موجود.']);
                }
            }
            if ($newBranch !== null && $id === $actorId) {
                throw new ValidationException(['branch_id' => 'لا يمكنك تقييد حسابك أنت بفرع. يفعلها مدير آخر إذا لزم.']);
            }
            $oldBranch = $u['branch_id'] === null ? null : (int) $u['branch_id'];
            if ($oldBranch !== $newBranch) {
                $pdo->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$newBranch, $id]);
                audit_record($pdo, 'user.branch', sprintf('تحديد فرع المستخدم %s: %s', $u['username'], (string) $branchName), 'user', $id,
                    ['branch_id' => ['old' => $oldBranch, 'new' => $newBranch]]);
                $changes['branch_id'] = ['old' => $oldBranch, 'new' => $newBranch];
            }
        }
        if ((string) $u['display_name'] !== $name) {
            $changes['display_name'] = ['old' => (string) $u['display_name'], 'new' => $name];
        }
        if (user_role($u) !== $role) {
            if ($id === $actorId) {
                throw new ValidationException(['role' => 'لا يمكنك تغيير دورك أنت. يغيره مدير آخر إذا لزم.']);
            }
            if (user_role($u) === 'admin' && user_is_active($u) && users_other_active_admins($rows, $id) < 1) {
                throw new ValidationException(['role' => 'يجب أن يبقى مدير واحد نشط على الأقل.']);
            }
            $changes['role'] = ['old' => user_role($u), 'new' => $role];
        }
        users_assert_actor($rows, $actorId);
        if (!$changes) {
            return [];
        }
        if (isset($changes['branch_id']) && count($changes) === 1) {
            data_version_bump($pdo);
            return $changes;
        }
        $pdo->prepare('UPDATE users SET display_name = ?, role = ? WHERE id = ?')->execute([$name, $role, $id]);
        if (isset($changes['display_name'])) {
            audit_record($pdo, 'user.update', sprintf('تعديل الاسم المعروض للمستخدم %s من «%s» إلى «%s»',
                $u['username'], $changes['display_name']['old'], $name), 'user', $id, ['display_name' => $changes['display_name']]);
        }
        if (isset($changes['role'])) {
            audit_record($pdo, 'user.role', sprintf('تغيير دور المستخدم %s من %s إلى %s',
                $u['username'], ROLE_LABELS[$changes['role']['old']], ROLE_LABELS[$role]), 'user', $id, ['role' => $changes['role']]);
        }
        data_version_bump($pdo);
        return $changes;
    });
}

/**
 * يعطل الحساب أو يفعّله. التعطيل ينهي كل جلسات المستخدم فورًا (رفع auth_version)،
 * ولا يعطل المدير حسابه، ولا يُعطَّل آخر مدير نشط.
 * @return bool false إذا كان الحساب على الحالة المطلوبة بالفعل
 */
function user_set_active(PDO $pdo, int $actorId, int $id, bool $active): bool
{
    return db_transaction($pdo, function (PDO $pdo) use ($actorId, $id, $active) {
        $rows = users_lock_all($pdo);
        $u = users_target($rows, $id);
        if (user_is_active($u) === $active) {
            users_assert_actor($rows, $actorId);
            return false;
        }
        if (!$active) {
            if ($id === $actorId) {
                throw new ValidationException(['user' => 'لا يمكنك تعطيل حسابك أنت.']);
            }
            if (user_role($u) === 'admin' && users_other_active_admins($rows, $id) < 1) {
                throw new ValidationException(['user' => 'يجب أن يبقى مدير واحد نشط على الأقل.']);
            }
        }
        users_assert_actor($rows, $actorId);
        $pdo->prepare('UPDATE users SET is_active = ?' . ($active ? '' : ', auth_version = auth_version + 1') . ' WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
        audit_record($pdo, $active ? 'user.enable' : 'user.disable',
            sprintf('%s المستخدم %s', $active ? 'تفعيل' : 'تعطيل', $u['username']),
            'user', $id, ['is_active' => ['old' => $active ? 0 : 1, 'new' => $active ? 1 : 0]]);
        data_version_bump($pdo);
        return true;
    });
}

/**
 * المدير يعين كلمة مرور جديدة لمستخدم آخر (مثلًا عند نسيانها). تنتهي كل جلسات المستخدم،
 * وتُمسح محاولات دخوله الفاشلة. المدير يغير كلمة مروره هو من صفحة «حسابي».
 */
function user_reset_password(PDO $pdo, int $actorId, int $id, string $password, string $confirm): void
{
    if ($id === $actorId) {
        throw new ValidationException(['password' => 'لتغيير كلمة مرورك استخدم صفحة «حسابي».']);
    }
    $target = user_find($pdo, $id);
    if (!$target) {
        throw new ValidationException(['user' => 'المستخدم غير موجود.']);
    }
    if ($err = password_problem($password, $confirm, (string) $target['username'])) {
        throw new ValidationException(['password' => $err]);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db_transaction($pdo, function (PDO $pdo) use ($actorId, $id, $hash) {
        $rows = users_lock_all($pdo);
        $u = users_target($rows, $id);
        users_assert_actor($rows, $actorId);
        $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$hash, $id]);
        $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$u['username']]);
        audit_record($pdo, 'user.password_reset', sprintf('تعيين كلمة مرور جديدة للمستخدم %s', $u['username']), 'user', $id);
        data_version_bump($pdo);
    });
}
