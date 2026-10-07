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
    'inventory'  => ['file' => 'inventory',  'public' => false],
    'receive'    => ['file' => 'receive',    'public' => false],
    'sell'       => ['file' => 'sell',       'public' => false],
    'transfer'   => ['file' => 'transfer',   'public' => false],
    'documents'  => ['file' => 'documents',  'public' => false],
    'document'   => ['file' => 'document',   'public' => false],
    'print'      => ['file' => 'print',      'public' => false],
    'types'      => ['file' => 'catalog',    'public' => false, 'catalog' => 'type'],
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
];

$route = input($_GET, 'r');
if ($route === '') {
    $route = 'inventory';
}
if (!isset(ROUTES[$route])) {
    start_secure_session();
    render_simple_error('الصفحة المطلوبة غير موجودة.', 404);
}

// طلبات التحديث التلقائي لا تمد عمر الجلسة
start_secure_session(!is_live_request());

if (!ROUTES[$route]['public']) {
    require_login();
}

$catalogKind = ROUTES[$route]['catalog'] ?? null;
$voucherKind = ROUTES[$route]['voucher'] ?? null;
require APP_ROOT . '/pages/' . ROUTES[$route]['file'] . '.php';
