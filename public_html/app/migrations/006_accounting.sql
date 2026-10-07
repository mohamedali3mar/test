-- المرحلة الثانية: الحسابات. العملاء والموردون، الخزائن، المصروفات، السندات،
-- تكلفة المخزون بالمتوسط المرجح، وطريقة الدفع في فواتير البيع والشراء.
-- كل المبالغ بالجنيه بخانتين عشريتين (القروش)، وتُحسب في PHP كأعداد صحيحة بالقروش.
-- الأرصدة المخزنة (parties.balance و cash_boxes.balance) تتغير فقط مع إضافة قيد في دفترها
-- داخل نفس المعاملة (app/lib/accounting.php)، فيبقى: الرصيد = الافتتاحي + مجموع القيود.

-- العملاء والموردون. الطرف الذي يكون عميلًا ومورّدًا معًا يُسجل مرتين (حسابان مستقلان).
-- balance موجب = مستحق: على العميل لنا، أو علينا للمورد. سالب = رصيد دائن (دفعة مقدمة).
CREATE TABLE IF NOT EXISTS parties (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('customer','supplier') NOT NULL,
    name VARCHAR(120) NOT NULL,
    name_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    address VARCHAR(200) NOT NULL DEFAULT '',
    notes VARCHAR(500) NOT NULL DEFAULT '',
    opening_balance DECIMAL(24,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(24,2) NOT NULL DEFAULT 0.00,
    credit_limit DECIMAL(24,2) NULL,
    branch_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    created_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parties_kind_name (kind, name_key),
    KEY idx_parties_kind_active (kind, is_active, name),
    CONSTRAINT fk_parties_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_parties_credit_limit CHECK (credit_limit IS NULL OR credit_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الخزائن. branch_id NULL = خزنة عامة لكل الفروع. الرصيد لا يصبح سالبًا أبدًا.
CREATE TABLE IF NOT EXISTS cash_boxes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    branch_id INT UNSIGNED NULL,
    opening_balance DECIMAL(24,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(24,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cash_boxes_name (name_key),
    CONSTRAINT chk_cash_boxes_balance CHECK (balance >= 0 AND opening_balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cash_boxes (name, name_key, created_at)
    SELECT 'الخزنة الرئيسية', 'الخزنة الرئيسية', NOW() FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM cash_boxes);

-- تصنيفات المصروفات
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expense_categories_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO expense_categories (name, name_key, created_at) VALUES
    ('إيجار', 'إيجار', NOW()),
    ('رواتب وأجور', 'رواتب وأجور', NOW()),
    ('كهرباء ومياه', 'كهرباء ومياه', NOW()),
    ('نقل ومشال', 'نقل ومشال', NOW()),
    ('صيانة', 'صيانة', NOW()),
    ('مصروفات أخرى', 'مصروفات أخرى', NOW());

-- السندات المالية: قبض من عميل، صرف لمورد، مصروف، تحويل نقدية بين خزنتين.
-- لكل نوع ترقيم مستقل متتالٍ، ونفس قواعد الإلغاء ومنع التكرار المستخدمة في المستندات.
CREATE TABLE IF NOT EXISTS vouchers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('collect','pay','expense','cash_transfer') NOT NULL,
    doc_no INT UNSIGNED NOT NULL,
    voucher_date DATETIME NOT NULL,
    party_id INT UNSIGNED NULL,
    party_name VARCHAR(120) NULL,
    cash_box_id INT UNSIGNED NOT NULL,
    cash_box_name VARCHAR(100) NOT NULL,
    to_cash_box_id INT UNSIGNED NULL,
    to_cash_box_name VARCHAR(100) NULL,
    category_id INT UNSIGNED NULL,
    category_name VARCHAR(100) NULL,
    amount DECIMAL(24,2) NOT NULL,
    currency VARCHAR(40) NOT NULL,
    reference VARCHAR(120) NULL,
    notes VARCHAR(1000) NULL,
    branch_id INT UNSIGNED NULL,
    branch_name VARCHAR(100) NULL,
    request_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vouchers_kind_no (kind, doc_no),
    UNIQUE KEY uq_vouchers_request_token (request_token),
    KEY idx_vouchers_date (voucher_date),
    KEY idx_vouchers_kind_date (kind, voucher_date),
    KEY idx_vouchers_party (party_id),
    KEY idx_vouchers_cash_box (cash_box_id),
    KEY idx_vouchers_to_cash_box (to_cash_box_id),
    KEY idx_vouchers_category (category_id),
    CONSTRAINT fk_vouchers_party FOREIGN KEY (party_id) REFERENCES parties (id),
    CONSTRAINT fk_vouchers_cash_box FOREIGN KEY (cash_box_id) REFERENCES cash_boxes (id),
    CONSTRAINT fk_vouchers_to_cash_box FOREIGN KEY (to_cash_box_id) REFERENCES cash_boxes (id),
    CONSTRAINT fk_vouchers_category FOREIGN KEY (category_id) REFERENCES expense_categories (id),
    CONSTRAINT fk_vouchers_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_vouchers_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id),
    CONSTRAINT chk_vouchers_amount CHECK (amount > 0 AND doc_no > 0),
    CONSTRAINT chk_vouchers_kind CHECK (
        (kind IN ('collect','pay') AND party_id IS NOT NULL AND category_id IS NULL AND to_cash_box_id IS NULL)
        OR (kind = 'expense' AND party_id IS NULL AND category_id IS NOT NULL AND to_cash_box_id IS NULL)
        OR (kind = 'cash_transfer' AND party_id IS NULL AND category_id IS NULL
            AND to_cash_box_id IS NOT NULL AND to_cash_box_id <> cash_box_id)
    ),
    CONSTRAINT chk_vouchers_cancel CHECK (
        (status = 'active' AND cancelled_at IS NULL AND cancelled_by IS NULL)
        OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- دفتر حسابات العملاء والموردين. كل قيد مرتبط بمستند أو بسند واحد بالضبط.
-- الإلغاء لا يحذف القيود، بل يضيف قيدًا عكسيًا (entry_type = reversal) يشير للأصل.
CREATE TABLE IF NOT EXISTS party_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    party_id INT UNSIGNED NOT NULL,
    entry_date DATETIME NOT NULL,
    document_id INT UNSIGNED NULL,
    voucher_id INT UNSIGNED NULL,
    entry_type ENUM('sale','sale_payment','purchase','purchase_payment','collect','pay','reversal') NOT NULL,
    amount DECIMAL(24,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reversal_of BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_party_ledger_party_date (party_id, entry_date, id),
    KEY idx_party_ledger_document (document_id),
    KEY idx_party_ledger_voucher (voucher_id),
    KEY idx_party_ledger_reversal (reversal_of),
    CONSTRAINT fk_party_ledger_party FOREIGN KEY (party_id) REFERENCES parties (id),
    CONSTRAINT fk_party_ledger_document FOREIGN KEY (document_id) REFERENCES documents (id),
    CONSTRAINT fk_party_ledger_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id),
    CONSTRAINT chk_party_ledger_source CHECK ((document_id IS NULL) <> (voucher_id IS NULL)),
    CONSTRAINT chk_party_ledger_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- دفتر الخزائن: موجب = داخل للخزنة، سالب = خارج منها
CREATE TABLE IF NOT EXISTS cash_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cash_box_id INT UNSIGNED NOT NULL,
    entry_date DATETIME NOT NULL,
    document_id INT UNSIGNED NULL,
    voucher_id INT UNSIGNED NULL,
    entry_type ENUM('sale','purchase','collect','pay','expense','transfer_out','transfer_in','reversal') NOT NULL,
    amount DECIMAL(24,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reversal_of BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cash_ledger_box_date (cash_box_id, entry_date, id),
    KEY idx_cash_ledger_document (document_id),
    KEY idx_cash_ledger_voucher (voucher_id),
    KEY idx_cash_ledger_reversal (reversal_of),
    CONSTRAINT fk_cash_ledger_box FOREIGN KEY (cash_box_id) REFERENCES cash_boxes (id),
    CONSTRAINT fk_cash_ledger_document FOREIGN KEY (document_id) REFERENCES documents (id),
    CONSTRAINT fk_cash_ledger_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id),
    CONSTRAINT chk_cash_ledger_source CHECK ((document_id IS NULL) <> (voucher_id IS NULL)),
    CONSTRAINT chk_cash_ledger_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- قيمة مخزون الصنف (كل المخازن معًا) لحساب المتوسط المرجح:
-- متوسط تكلفة القطعة = inventory_value ÷ مجموع القطع في كل المخازن
ALTER TABLE items ADD COLUMN inventory_value DECIMAL(24,2) NOT NULL DEFAULT 0.00;

-- فروق التكلفة: عند إلغاء وارد بعد بيع جزء من الصنف، أو بقاء قيمة بعد نفاد الكمية
CREATE TABLE IF NOT EXISTS cost_adjustments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED NULL,
    amount DECIMAL(24,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    created_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_cost_adjustments_created (created_at),
    KEY idx_cost_adjustments_item (item_id),
    CONSTRAINT fk_cost_adjustments_item FOREIGN KEY (item_id) REFERENCES items (id),
    CONSTRAINT fk_cost_adjustments_document FOREIGN KEY (document_id) REFERENCES documents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- المستندات: التاريخ المحاسبي، العميل أو المورد، طريقة الدفع والخزنة، والتكلفة
ALTER TABLE documents
    ADD COLUMN doc_date DATETIME NULL,
    ADD COLUMN party_id INT UNSIGNED NULL,
    ADD COLUMN payment_type ENUM('cash','credit','partial') NULL,
    ADD COLUMN paid_amount DECIMAL(24,2) NULL,
    ADD COLUMN cash_box_id INT UNSIGNED NULL,
    ADD COLUMN cash_box_name VARCHAR(100) NULL,
    ADD COLUMN total_cost DECIMAL(24,2) NULL;

UPDATE documents SET doc_date = created_at WHERE doc_date IS NULL;

ALTER TABLE documents
    MODIFY doc_date DATETIME NOT NULL,
    ADD KEY idx_documents_doc_date (doc_date),
    ADD KEY idx_documents_kind_doc_date (kind, doc_date),
    ADD KEY idx_documents_party (party_id),
    ADD KEY idx_documents_cash_box (cash_box_id),
    ADD CONSTRAINT fk_documents_party FOREIGN KEY (party_id) REFERENCES parties (id),
    ADD CONSTRAINT fk_documents_cash_box FOREIGN KEY (cash_box_id) REFERENCES cash_boxes (id),
    ADD CONSTRAINT chk_documents_payment CHECK (
        (payment_type IS NULL AND paid_amount IS NULL AND cash_box_id IS NULL)
        OR (kind IN ('in','sale') AND payment_type IS NOT NULL AND paid_amount IS NOT NULL AND paid_amount >= 0
            AND (paid_amount = 0 OR cash_box_id IS NOT NULL)
            AND (payment_type = 'cash' OR party_id IS NOT NULL))
    );

-- أسطر المستند: تكلفة المتر في الوارد (كما أُدخلت)، وقيمة التكلفة للسطر:
-- في الوارد = قيمة ما أُضيف للمخزون، وفي البيع = تكلفة البضاعة المباعة بالمتوسط المرجح وقت البيع
ALTER TABLE document_lines
    ADD COLUMN cost_per_m3 DECIMAL(12,2) NULL,
    ADD COLUMN cost_amount DECIMAL(24,2) NULL,
    ADD CONSTRAINT chk_document_lines_cost CHECK (
        (cost_per_m3 IS NULL OR cost_per_m3 > 0) AND (cost_amount IS NULL OR cost_amount >= 0)
    );

INSERT IGNORE INTO counters (name, value) VALUES
    ('doc_collect', 0), ('doc_pay', 0), ('doc_expense', 0), ('doc_cash_transfer', 0);
