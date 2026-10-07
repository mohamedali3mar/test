-- الإصدار 2 من قاعدة البيانات: سجل الدخول والأمان
-- يسجل كل محاولة دخول (ناجحة أو فاشلة أو محظورة) والخروج وتغيير كلمة المرور واستعادتها وانتهاء الجلسة،
-- حتى تظهر أي محاولة للوصول إلى البيانات في صفحة الإعدادات. السجلات الأقدم من 180 يومًا تُحذف تلقائيًا.
-- تُطبق من زر «تحديث قاعدة البيانات» في الإعدادات، أو تلقائيًا عند التثبيت الجديد.

CREATE TABLE IF NOT EXISTS auth_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event ENUM('login_ok','login_fail','login_locked','logout','password_changed','password_reset','session_expired') NOT NULL,
    -- الاسم كما كُتب في محاولة الدخول (قد لا يكون مستخدمًا موجودًا)
    username VARCHAR(60) NOT NULL,
    -- عنوان IPv4 كاملًا، أو شبكة IPv6 بطول /64 كما في تحديد محاولات الدخول
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_auth_events_created (created_at),
    KEY idx_auth_events_event_created (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
