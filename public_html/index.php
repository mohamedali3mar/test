<?php
declare(strict_types=1);

// تخزين المخرجات مؤقتًا حتى تستطيع صفحة الخطأ استبدال أي محتوى جزئي بالكامل
ob_start();

require __DIR__ . '/app/bootstrap.php';

header_remove('X-Powered-By');
// فلتر برامج الأتمتة (curl وأمثاله): قبل الجلسة وقبل أي استعلام. فلتر وليس جدارًا، التفاصيل في security.php
block_automated_clients();

// روابط مثل index.php/x/y تجعل المسارات النسبية للملفات تنكسر؛ لا تُقبل
if (!empty($_SERVER['PATH_INFO'])) {
    render_simple_error('الصفحة المطلوبة غير موجودة.', 404);
}

enforce_https();

const ROUTES = [
    'login'      => ['file' => 'login',      'public' => true],
    'logout'     => ['file' => 'logout',     'public' => true],
    'home'       => ['file' => 'home',       'public' => false],
    'inventory'  => ['file' => 'inventory',  'public' => false],
    'receive'    => ['file' => 'receive',    'public' => false],
    'sell'       => ['file' => 'sell',       'public' => false],
    'transfer'   => ['file' => 'transfer',   'public' => false],
    'documents'  => ['file' => 'documents',  'public' => false],
    'document'   => ['file' => 'document',   'public' => false],
    'print'      => ['file' => 'print',      'public' => false],
    'pdf'        => ['file' => 'pdf',        'public' => false],
    'export'     => ['file' => 'export',     'public' => false],
    'types'      => ['file' => 'catalog',    'public' => false, 'catalog' => 'type'],
    'branches'   => ['file' => 'branches',   'public' => false],
    'warehouses' => ['file' => 'catalog',    'public' => false, 'catalog' => 'warehouse'],
    'settings'   => ['file' => 'settings',   'public' => false],
    'api'        => ['file' => 'api',        'public' => false],
    'asset'      => ['file' => 'asset',      'public' => false],
    'opening_valuation' => ['file' => 'opening_valuation', 'public' => false],
    'reports'    => ['file' => 'reports',    'public' => false],
    'dashboard'  => ['file' => 'dashboard',  'public' => false],
    // الحسابات (الوحدة ب): العملاء والموردون، الخزائن، المصروفات، السندات والكشوف
    'parties'            => ['file' => 'parties',            'public' => false],
    'party_statement'    => ['file' => 'party_statement',    'public' => false],
    'cash_boxes'         => ['file' => 'cash_boxes',         'public' => false],
    'cash_statement'     => ['file' => 'cash_statement',     'public' => false],
    'expense_categories' => ['file' => 'expense_categories', 'public' => false],
    'vouchers'           => ['file' => 'vouchers',           'public' => false],
    'voucher'            => ['file' => 'voucher',            'public' => false],
    'voucher_print'      => ['file' => 'voucher_print',      'public' => false],
    'collect'            => ['file' => 'voucher_form',       'public' => false, 'voucher' => 'collect'],
    'pay'                => ['file' => 'voucher_form',       'public' => false, 'voucher' => 'pay'],
    'expense'            => ['file' => 'voucher_form',       'public' => false, 'voucher' => 'expense'],
    'cash_transfer'      => ['file' => 'voucher_form',       'public' => false, 'voucher' => 'cash_transfer'],
    'account'    => ['file' => 'account',    'public' => false],
    'users'      => ['file' => 'users',      'public' => false],
    'monitor'    => ['file' => 'monitor',    'public' => false],
];

$route = input($_GET, 'r');
if ($route === '') {
    $route = 'home';
}
if (!isset(ROUTES[$route])) {
    start_secure_session();
    render_simple_error('الصفحة المطلوبة غير موجودة.', 404);
}

// طلبات التحديث التلقائي لا تمد عمر الجلسة
start_secure_session(!is_live_request());

if (!ROUTES[$route]['public']) {
    require_login();
    // صلاحية الصفحة حسب الدور (ROUTE_PERMISSIONS في lib/users.php). الصفحة غير المسجلة هناك للمدير فقط.
    require_permission(route_permission($route));
    // بعد رفع إصدار جديد وقبل تحديث قاعدة البيانات: الصفحات تحتاج الجداول الجديدة، فيُوجَّه المدير
    // إلى زر التحديث في الإعدادات، ويرى الموظف رسالة واضحة بدل خطأ عام
    if (!in_array($route, ['settings', 'logout', 'api', 'asset', 'account'], true) && pending_migrations(db())) {
        if (can('migrate.run')) {
            flash('warning', 'يوجد تحديث لقاعدة البيانات لم يُطبق بعد. خذ نسخة احتياطية ثم اضغط «تحديث قاعدة البيانات».');
            redirect('settings');
        }
        render_simple_error('النظام قيد التحديث الآن. حاول مرة أخرى بعد قليل، أو راجع مدير النظام.', 503);
    }
    // رقم إصدار البيانات يُقرأ قبل أي استعلام للصفحة: لو حُفظت عملية أثناء تجهيز الصفحة
    // يرى المتصفح إصدارًا أحدث في أول فحص فيحدّث الصفحة، بدل أن تبقى قديمة بإصدار جديد
    $GLOBALS['PAGE_DATA_VERSION'] = data_version(db());
}

$catalogKind = ROUTES[$route]['catalog'] ?? null;
$voucherKind = ROUTES[$route]['voucher'] ?? null;
require APP_ROOT . '/pages/' . ROUTES[$route]['file'] . '.php';
