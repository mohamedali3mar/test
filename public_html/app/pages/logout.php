<?php
defined('APP_ROOT') || exit;

if (current_user_id() === 0) {
    redirect('login');
}
require_post();
// يُسجل قبل إنهاء الجلسة لأن اسم المستخدم يُقرأ منها
auth_event_db('logout', current_username());
logout();
start_secure_session();
flash('success', 'تم تسجيل الخروج.');
redirect('login');
