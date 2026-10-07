<?php
defined('APP_ROOT') || exit;

/*
 * سكربتات الواجهة للمستخدمين المسجلين فقط (index.php?r=asset&f=app.js).
 * الوصول المباشر إلى assets/js/app.js و twofactor.js ممنوع في .htaccess.
 */

// لا حاجة للجلسة بعد التحقق من الدخول؛ إغلاقها يمنع حجزها أثناء تحميل الصفحة
session_write_close();
serve_protected_script(input($_GET, 'f'));
