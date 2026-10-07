-- الترقية 003: التحقق بخطوتين (TOTP) الاختياري لكل مستخدم، وتحسين حساب محاولات الدخول.
-- تُطبق من صفحة الإعدادات (زر «تحديث قاعدة البيانات») أو تلقائيًا عند التثبيت.
-- النظام يعمل قبل تطبيقها: يُعامل التحقق بخطوتين كأنه غير مفعل.

-- totp_secret: السر بترميز base32 (يُحفظ فقط بعد أن يؤكد المستخدم أول رمز)
-- totp_last_step: رقم آخر خطوة زمنية (30 ثانية) استُخدم رمزها، فلا يُقبل نفس الرمز مرتين
ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(64) NULL,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL;

-- المحاولات المرفوضة بسبب الحد (blocked = 1) لا تُحسب، فلا يمد المهاجم الحظر ولا يحبس الحساب من كل العناوين
ALTER TABLE login_attempts
    ADD COLUMN blocked TINYINT(1) NOT NULL DEFAULT 0;
