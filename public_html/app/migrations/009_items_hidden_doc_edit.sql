-- الترقية 009: إخفاء المقاسات والأنواع، وربط المستند المعدَّل بالمستند الأصلي.
-- آمنة لإعادة التنفيذ إذا انقطعت (IF NOT EXISTS).

-- مقاس مخفي: رصيده صفر في كل المخازن ولا يظهر في المخزون والقوائم. يظهر من جديد تلقائيًا مع أول وارد له.
ALTER TABLE items ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0;

-- نوع مخفي: لا يظهر في نموذج الوارد. يُخفى فقط إذا كانت أرصدة كل مقاساته صفرًا.
ALTER TABLE wood_types ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0;

-- تعديل مستند = إلغاء الأصلي وتسجيل البديل في معاملة واحدة. replaces_id في البديل يشير إلى الأصلي،
-- و replaced_by_id في الأصلي يشير إلى البديل (للعرض والطباعة).
ALTER TABLE documents ADD COLUMN IF NOT EXISTS replaces_id INT UNSIGNED NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS replaced_by_id INT UNSIGNED NULL;
ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS replaces_id INT UNSIGNED NULL;
ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS replaced_by_id INT UNSIGNED NULL;
