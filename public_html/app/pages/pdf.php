<?php
defined('APP_ROOT') || exit;

/*
 * ملف PDF لمستند (فاتورة بيع أو إذن وارد أو إذن تحويل): index.php?r=pdf&id=<رقم المستند>.
 * نفس بيانات صفحة الطباعة، والمستند خارج فرع المستخدم يُعامل كأنه غير موجود.
 */

$pdo = db();
$id = (int) input($_GET, 'id');
$d = find_document_scoped($pdo, $id);
if (!$d) {
    render_simple_error('المستند غير موجود.', 404);
}
// لا حاجة للجلسة بعد التحقق؛ إغلاقها لا يحجز باقي صفحات المستخدم أثناء إنشاء الملف
session_write_close();
try {
    $bytes = pdf_document($d, document_lines($pdo, $id));
} catch (Throwable $e) {
    error_log('[wood] pdf document ' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
    render_simple_error('تعذر إنشاء ملف PDF. استخدم زر «طباعة» واختر «حفظ كملف PDF» مؤقتًا، وأبلغ مسؤول الموقع.', 500);
}
pdf_send($bytes, pdf_document_filename($d), false);
