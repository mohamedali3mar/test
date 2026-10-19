-- الترقية 003: التحقق بخطوتين (TOTP) الاختياري لكل مستخدم، وتحسين حساب محاولات الدخول.
-- تُطبق من صفحة الإعدادات (زر «تحديث قاعدة البيانات») أو تلقائيًا عند التثبيت.
-- النظام يعمل قبل تطبيقها: يُعامل التحقق بخطوتين كأنه غير مفعل.
-- كل أمر آمن لإعادة التنفيذ (IF NOT EXISTS في MariaDB): إذا انقطعت الترقية بعد تنفيذ أمر وقبل تسجيل تقدمه
-- يُعاد تنفيذه عند الضغط على الزر مرة أخرى دون خطأ ودون تغيير.

-- totp_secret: السر بترميز base32 (يُحفظ فقط بعد أن يؤكد المستخدم أول رمز)
-- totp_last_step: رقم آخر خطوة زمنية (30 ثانية) استُخدم رمزها، فلا يُقبل نفس الرمز مرتين
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS totp_last_step BIGINT UNSIGNED NULL;

-- المحاولات المرفوضة بسبب الحد (blocked = 1) لا تُحسب، فلا يمد المهاجم الحظر ولا يحبس الحساب من كل العناوين
ALTER TABLE login_attempts
    ADD COLUMN IF NOT EXISTS blocked TINYINT(1) NOT NULL DEFAULT 0;
