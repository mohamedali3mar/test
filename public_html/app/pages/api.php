<?php
defined('APP_ROOT') || exit;

/*
 * واجهة القراءة للتحديث التلقائي (GET فقط، لا تغير أي بيانات):
 *  op=version  رقم إصدار البيانات الحالي (طلب خفيف كل بضع ثوانٍ)
 *  op=stock    أرصدة كل الأصناف في كل المخازن (للنماذج المفتوحة)
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'method'], 405);
}
// لا حاجة للجلسة بعد التحقق من الدخول؛ إغلاقها يمنع حجز الجلسة أثناء الطلبات المتوازية
session_write_close();

$pdo = db();
switch (input($_GET, 'op')) {
    case 'version':
        json_response(['v' => data_version($pdo)]);
    case 'stock':
        json_response(['v' => data_version($pdo), 'items' => stock_payload($pdo)]);
    default:
        json_response(['error' => 'op'], 400);
}
