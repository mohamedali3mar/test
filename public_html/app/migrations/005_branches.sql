-- الفروع: كل مخزن يتبع فرعًا واحدًا، والمستندات تحفظ اسم الفرع وقت الحركة كما تحفظ اسم المخزن.
-- أوامر الترقية آمنة لإعادة التنفيذ: إذا توقفت الترقية في منتصفها تكمل من الأمر التالي،
-- وإعادة تنفيذ أي أمر سبق تنفيذه لا تغير شيئًا (IF NOT EXISTS في MariaDB، وشروط WHERE في البيانات).
-- صيغة ADD COLUMN/KEY/FOREIGN KEY IF NOT EXISTS خاصة بـ MariaDB 10.3+ (قاعدة بيانات Hostinger).

CREATE TABLE IF NOT EXISTS branches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    -- مفتاح منع التكرار بنفس توحيد أسماء المخازن (name_key في PHP)
    name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    address VARCHAR(200) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branches_name_key (name_key),
    KEY idx_branches_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الفرع الافتراضي، فقط إذا لم يوجد أي فرع. مفتاحه يساوي name_key('الفرع الرئيسي') في PHP.
-- صفحة التثبيت تعيد تسميته بالاسم الذي يختاره المدير بدل إنشاء فرع ثانٍ.
INSERT INTO branches (name, name_key, address, phone, created_at) SELECT 'الفرع الرئيسي', 'الفرع الرئيسي', '', '', NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branches);

-- المخازن الحالية كلها تتبع الفرع الافتراضي، ثم يصبح الفرع إلزاميًا لكل مخزن
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER name_key;

UPDATE warehouses SET branch_id = (SELECT MIN(id) FROM branches) WHERE branch_id IS NULL;

ALTER TABLE warehouses
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD KEY IF NOT EXISTS idx_warehouses_branch (branch_id),
    ADD CONSTRAINT fk_warehouses_branch FOREIGN KEY IF NOT EXISTS (branch_id) REFERENCES branches (id);

-- المستند يحفظ فرع المخزن وقت الحركة (والفرع المستلم في التحويل). تبقى الأعمدة قابلة لـ NULL
-- احتياطًا، لكن كل مستند جديد يملؤها دائمًا.
ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER warehouse_name,
    ADD COLUMN IF NOT EXISTS branch_name VARCHAR(100) NULL AFTER branch_id,
    ADD COLUMN IF NOT EXISTS to_branch_id INT UNSIGNED NULL AFTER to_warehouse_name,
    ADD COLUMN IF NOT EXISTS to_branch_name VARCHAR(100) NULL AFTER to_branch_id;

UPDATE documents d JOIN warehouses w ON w.id = d.warehouse_id JOIN branches b ON b.id = w.branch_id SET d.branch_id = b.id, d.branch_name = b.name WHERE d.branch_id IS NULL;

UPDATE documents d JOIN warehouses w ON w.id = d.to_warehouse_id JOIN branches b ON b.id = w.branch_id SET d.to_branch_id = b.id, d.to_branch_name = b.name WHERE d.to_warehouse_id IS NOT NULL AND d.to_branch_id IS NULL;

ALTER TABLE documents
    ADD KEY IF NOT EXISTS idx_documents_branch_created (branch_id, created_at),
    ADD KEY IF NOT EXISTS idx_documents_to_branch (to_branch_id),
    ADD CONSTRAINT fk_documents_branch FOREIGN KEY IF NOT EXISTS (branch_id) REFERENCES branches (id),
    ADD CONSTRAINT fk_documents_to_branch FOREIGN KEY IF NOT EXISTS (to_branch_id) REFERENCES branches (id);

-- نطاق المستخدم: NULL = كل الفروع، أو فرع واحد يرى مخازنه ومستنداته فقط
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL,
    ADD KEY IF NOT EXISTS idx_users_branch (branch_id),
    ADD CONSTRAINT fk_users_branch FOREIGN KEY IF NOT EXISTS (branch_id) REFERENCES branches (id);
