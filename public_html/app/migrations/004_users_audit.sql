-- الترقية 004: تعدد المستخدمين بأدوار، وسجل المراقبة (من فعل ماذا ومتى ومن أين)
-- MySQL 5.7+ / MariaDB 10.3+ ، محرك InnoDB وترميز utf8mb4
-- يُطبق من صفحة الإعدادات («تحديث قاعدة البيانات») أو تلقائيًا عند التثبيت الجديد.
-- كل أمر ينتهي بفاصلة منقوطة آخر السطر، والمنفذ يسجل التقدم بعد كل أمر فيكمل من حيث توقف.
-- users.created_at موجود منذ الترقية 001، فلا يضاف هنا.

-- الأدوار: admin مدير (كل الصلاحيات)، staff موظف. الحسابات الموجودة قبل الترقية تصبح مديرين.
-- أمر واحد لكل الأعمدة: تغيير الجدول يُطبق كاملًا أو لا يُطبق، فإعادة التنفيذ بعد فشله آمنة.
ALTER TABLE users
    ADD COLUMN role ENUM('admin','staff') NOT NULL DEFAULT 'admin' AFTER auth_version,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN display_name VARCHAR(100) NOT NULL DEFAULT '' AFTER is_active,
    ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at;

-- سجل المراقبة: صف لكل عملية، يُكتب داخل نفس معاملة العملية فيُحفظ معها أو يتراجع معها.
-- السجل دائم (سجل محاسبي) ولا يُحذف منه شيء. username و summary لقطة نصية وقت العملية.
-- details نص JSON بالقيم القديمة والجديدة (LONGTEXT مع فحص JSON_VALID ليعمل على MySQL و MariaDB).
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    user_id INT UNSIGNED NULL,
    username VARCHAR(60) NOT NULL DEFAULT '',
    action VARCHAR(40) NOT NULL,
    entity VARCHAR(20) NOT NULL DEFAULT '',
    entity_id BIGINT UNSIGNED NULL,
    summary VARCHAR(500) NOT NULL,
    details LONGTEXT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_audit_log_created (created_at),
    KEY idx_audit_log_user (user_id, created_at),
    KEY idx_audit_log_action (action, created_at),
    KEY idx_audit_log_entity (entity, entity_id),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT chk_audit_log_details CHECK (details IS NULL OR JSON_VALID(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
