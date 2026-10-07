-- نظام مخزون ومبيعات الأخشاب: الإصدار الأول من قاعدة البيانات
-- MySQL 5.7+ / MariaDB 10.3+ ، محرك InnoDB وترميز utf8mb4
-- صفحة install.php تنفذ هذا الملف تلقائيًا. يمكن أيضًا استيراده يدويًا من phpMyAdmin،
-- ثم تشغيل install.php لإنشاء حساب المدير وأول مخزن.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(60) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_ip (ip, attempted_at),
    KEY idx_login_attempts_user (username, attempted_at),
    KEY idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(50) NOT NULL,
    value VARCHAR(255) NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- عدادات الترقيم المتتالي لكل نوع مستند، ورقم إصدار البيانات للتحديث التلقائي
CREATE TABLE IF NOT EXISTS counters (
    name VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB;

INSERT IGNORE INTO counters (name, value) VALUES ('doc_in', 0), ('doc_sale', 0), ('doc_transfer', 0), ('data_version', 0);

CREATE TABLE IF NOT EXISTS wood_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    -- مفتاح منع التكرار: الاسم بعد توحيد Unicode والمسافات وحالة الأحرف، بمقارنة ثنائية
    name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wood_types_name_key (name_key),
    KEY idx_wood_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouses_name_key (name_key),
    KEY idx_warehouses_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الصنف = نوع الخشب + العرض + التخانة + الطول (بالميكرومتر بعد التوحيد). الهوية واحدة لكل المخازن.
CREATE TABLE IF NOT EXISTS items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    wood_type_id INT UNSIGNED NOT NULL,
    width_um INT UNSIGNED NOT NULL,
    thickness_um INT UNSIGNED NOT NULL,
    length_um INT UNSIGNED NOT NULL,
    -- وحدات العرض المفضلة (من أول وارد) لعرض المقاس فقط، ولا تؤثر في الهوية
    width_unit ENUM('mm','cm','m') NOT NULL,
    thickness_unit ENUM('mm','cm','m') NOT NULL,
    length_unit ENUM('mm','cm','m') NOT NULL,
    piece_volume_m3 DECIMAL(36,18) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_items_identity (wood_type_id, width_um, thickness_um, length_um),
    CONSTRAINT fk_items_wood_type FOREIGN KEY (wood_type_id) REFERENCES wood_types (id),
    CONSTRAINT chk_items_dims CHECK (
        width_um BETWEEN 1 AND 50000000
        AND thickness_um BETWEEN 1 AND 50000000
        AND length_um BETWEEN 1 AND 50000000
        AND piece_volume_m3 > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رصيد كل صنف في كل مخزن
CREATE TABLE IF NOT EXISTS stock (
    item_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    qty_on_hand INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (item_id, warehouse_id),
    KEY idx_stock_warehouse (warehouse_id),
    CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES items (id),
    CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رأس المستند: وارد أو فاتورة بيع أو تحويل. صُمم ليستوعب مستندات الحسابات لاحقًا.
CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('in','sale','transfer') NOT NULL,
    doc_no INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    warehouse_name VARCHAR(100) NOT NULL,
    to_warehouse_id INT UNSIGNED NULL,
    to_warehouse_name VARCHAR(100) NULL,
    party_name VARCHAR(120) NULL,
    reference VARCHAR(120) NULL,
    notes VARCHAR(1000) NULL,
    line_count SMALLINT UNSIGNED NOT NULL,
    total_qty INT UNSIGNED NOT NULL,
    total_volume_m3 DECIMAL(42,18) NOT NULL,
    -- للبيع فقط: الإجمالي والعملة محفوظان تاريخيًا
    total_amount DECIMAL(24,2) NULL,
    currency VARCHAR(40) NULL,
    request_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_kind_no (kind, doc_no),
    UNIQUE KEY uq_documents_request_token (request_token),
    KEY idx_documents_created (created_at),
    KEY idx_documents_kind_created (kind, created_at),
    KEY idx_documents_warehouse (warehouse_id),
    KEY idx_documents_to_warehouse (to_warehouse_id),
    KEY idx_documents_status (status),
    CONSTRAINT fk_documents_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_documents_to_warehouse FOREIGN KEY (to_warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_documents_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_documents_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id),
    CONSTRAINT chk_documents_counts CHECK (line_count > 0 AND total_qty > 0 AND doc_no > 0),
    CONSTRAINT chk_documents_sale CHECK (
        (kind = 'sale' AND total_amount IS NOT NULL AND currency IS NOT NULL)
        OR (kind <> 'sale' AND total_amount IS NULL)
    ),
    CONSTRAINT chk_documents_transfer CHECK (
        (kind = 'transfer' AND to_warehouse_id IS NOT NULL AND to_warehouse_id <> warehouse_id)
        OR (kind <> 'transfer' AND to_warehouse_id IS NULL)
    ),
    CONSTRAINT chk_documents_cancel CHECK (
        (status = 'active' AND cancelled_at IS NULL AND cancelled_by IS NULL)
        OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أسطر المستند. الأرصدة قبل وبعد محفوظة للمراجعة. لا يتكرر نفس الصنف في المستند الواحد.
CREATE TABLE IF NOT EXISTS document_lines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    wood_type_name VARCHAR(100) NOT NULL,
    -- وحدات عرض المقاس كما أُدخلت أو عُرضت وقت المستند
    width_unit ENUM('mm','cm','m') NOT NULL,
    thickness_unit ENUM('mm','cm','m') NOT NULL,
    length_unit ENUM('mm','cm','m') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    piece_volume_m3 DECIMAL(36,18) NOT NULL,
    total_volume_m3 DECIMAL(42,18) NOT NULL,
    price_per_m3 DECIMAL(12,2) NULL,
    amount DECIMAL(24,2) NULL,
    balance_before INT UNSIGNED NOT NULL,
    balance_after INT UNSIGNED NOT NULL,
    to_balance_before INT UNSIGNED NULL,
    to_balance_after INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_document_lines_no (document_id, line_no),
    UNIQUE KEY uq_document_lines_item (document_id, item_id),
    KEY idx_document_lines_item (item_id),
    CONSTRAINT fk_document_lines_document FOREIGN KEY (document_id) REFERENCES documents (id),
    CONSTRAINT fk_document_lines_item FOREIGN KEY (item_id) REFERENCES items (id),
    CONSTRAINT chk_document_lines_qty CHECK (quantity > 0 AND line_no > 0),
    CONSTRAINT chk_document_lines_volume CHECK (total_volume_m3 = piece_volume_m3 * quantity),
    CONSTRAINT chk_document_lines_price CHECK (
        (price_per_m3 IS NULL AND amount IS NULL)
        OR (price_per_m3 > 0 AND amount IS NOT NULL AND amount >= 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
