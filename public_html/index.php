<?php
declare(strict_types=1);

// تخزين المخرجات مؤقتًا حتى تستطيع صفحة الخطأ استبدال أي محتوى جزئي بالكامل
ob_start();

require __DIR__ . '/app/bootstrap.php';

header_remove('X-Powered-By');

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
    'branches'   => ['file' => 'branches',   'public' => false],
    'warehouses' => ['file' => 'catalog',    'public' => false, 'catalog' => 'warehouse'],
    'settings'   => ['file' => 'settings',   'public' => false],
    'api'        => ['file' => 'api',        'public' => false],
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
require APP_ROOT . '/pages/' . ROUTES[$route]['file'] . '.php';
