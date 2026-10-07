<?php
defined('APP_ROOT') || exit;

if (current_user_id() === 0) {
    redirect('login');
}
require_post();
logout();
start_secure_session();
flash('success', 'تم تسجيل الخروج.');
redirect('login');
