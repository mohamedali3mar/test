-- الترقية 008: صلاحيات يحددها المدير لكل موظف.
-- permissions: كائن JSON بالصلاحيات التي تختلف عن صلاحيات الموظف الافتراضية، مثل {"vouchers.pay":true,"documents.cancel":false}.
-- NULL = الصلاحيات الافتراضية للدور. المدير يملك كل الصلاحيات دائمًا، فالعمود يُطبق على الموظفين فقط.
-- آمنة لإعادة التنفيذ إذا انقطعت (IF NOT EXISTS).
ALTER TABLE users ADD COLUMN IF NOT EXISTS permissions TEXT NULL;
