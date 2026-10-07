<?php
defined('APP_ROOT') || exit;

/*
 * التحقق بخطوتين (اختياري لكل مستخدم): رموز TOTP حسب RFC 6238
 * (HMAC-SHA1، خطوة 30 ثانية، 6 أرقام، سر عشوائي 20 بايت بترميز base32 حسب RFC 4648).
 * يعمل مع Google Authenticator و Microsoft Authenticator و Authy وأمثالها.
 *
 * يُقبل رمز الخطوة الحالية أو السابقة أو التالية فقط (فرق ساعة الهاتف حتى 30 ثانية)،
 * ورقم آخر خطوة مستخدمة يُحفظ في users.totp_last_step فلا يُقبل نفس الرمز مرتين.
 * قبل تطبيق الترقية 003 لا توجد الأعمدة، فيُعامل التحقق بخطوتين كأنه غير مفعل.
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_SECRET_BYTES = 20;
const TOTP_DRIFT_STEPS = 1;
const TFA_PENDING_SECONDS = 300;   // مهلة إدخال الرمز بعد كلمة المرور الصحيحة
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/* ===================== base32 و TOTP ===================== */

/** ترميز base32 بدون حشو «=» (الشكل المعتاد في روابط otpauth) */
function base32_encode(string $bin): string
{
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $buffer = (($buffer << 8) | ord($bin[$i])) & 0xFFFF;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= BASE32_ALPHABET[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
    }
    return $out;
}

/** فك base32 (يتجاهل المسافات والحشو وحالة الأحرف). يعيد null لأي نص غير صالح. */
function base32_decode(string $text): ?string
{
    $text = strtoupper(str_replace([' ', '-'], '', rtrim($text, '=')));
    if ($text === '' || strspn($text, BASE32_ALPHABET) !== strlen($text)) {
        return null;
    }
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $buffer = (($buffer << 5) | strpos(BASE32_ALPHABET, $text[$i])) & 0xFFF;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(TOTP_SECRET_BYTES));
}

function totp_step(?int $time = null): int
{
    return intdiv($time ?? time(), TOTP_PERIOD);
}

/** رمز خطوة زمنية معينة. $secret بترميز base32. */
function totp_code(string $secret, int $step, int $digits = TOTP_DIGITS): string
{
    $key = base32_decode($secret);
    if ($key === null || $step < 0) {
        throw new InvalidArgumentException('Invalid TOTP secret or step');
    }
    $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hmac[19]) & 0x0F;
    $value = ((ord($hmac[$offset]) & 0x7F) << 24)
        | (ord($hmac[$offset + 1]) << 16)
        | (ord($hmac[$offset + 2]) << 8)
        | ord($hmac[$offset + 3]);
    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** يقبل الأرقام العربية والفارسية والمسافات التي قد يكتبها المستخدم أو ينسخها من التطبيق */
function totp_normalize_code(string $raw): string
{
    $map = [' ' => '', '-' => '', "\u{00A0}" => '', "\u{202F}" => ''];
    for ($d = 0; $d < 10; $d++) {
        $map[mb_chr(0x0660 + $d, 'UTF-8')] = (string) $d;
        $map[mb_chr(0x06F0 + $d, 'UTF-8')] = (string) $d;
    }
    return strtr(trim($raw), $map);
}

/**
 * يتحقق من الرمز في الخطوة الحالية ±1. $lastStep: آخر خطوة استُخدمت (أي خطوة مثلها أو أقدم مرفوضة).
 * يقارن كل الخطوات دائمًا بمقارنة ثابتة الزمن. عند النجاح يضع رقم الخطوة المطابقة في $usedStep.
 */
function totp_verify(string $secret, string $code, ?int &$usedStep = null, ?int $lastStep = null, ?int $time = null): bool
{
    $usedStep = null;
    $code = totp_normalize_code($code);
    if (preg_match('/^\d{' . TOTP_DIGITS . '}\z/', $code) !== 1 || base32_decode($secret) === null) {
        return false;
    }
    $current = totp_step($time);
    $match = null;
    for ($d = -TOTP_DRIFT_STEPS; $d <= TOTP_DRIFT_STEPS; $d++) {
        $step = $current + $d;
        if ($step < 0) {
            continue;
        }
        $equal = hash_equals(totp_code($secret, $step), $code);
        if ($equal && $match === null && ($lastStep === null || $step > $lastStep)) {
            $match = $step;
        }
    }
    if ($match === null) {
        return false;
    }
    $usedStep = $match;
    return true;
}

/**
 * رابط otpauth الذي يقرؤه تطبيق المصادقة (يظهر نصًا ورمز QR في صفحة الإعدادات).
 * SHA1 و 6 أرقام و 30 ثانية هي القيم الافتراضية في صيغة الرابط فلا تُكتب، حتى يبقى رمز QR أقل كثافة
 * (اسم الشركة العربي يُرمّز ويتكرر مرتين).
 */
function totp_uri(string $secret, string $issuer, string $account): string
{
    $issuer = str_replace(':', ' ', $issuer);
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
}

/** السر في مجموعات من 4 أحرف لتسهيل كتابته يدويًا */
function totp_secret_groups(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}

/* ===================== حالة المستخدم في قاعدة البيانات ===================== */

/** هل طُبقت الترقية 003؟ (بدونها يعمل الدخول كالمعتاد بدون تحقق بخطوتين) */
function two_factor_supported(PDO $pdo): bool
{
    return db_has_column($pdo, 'users', 'totp_enabled');
}

/** @return array{totp_secret: ?string, totp_enabled: int, totp_last_step: ?int}|null */
function two_factor_row(PDO $pdo, int $userId): ?array
{
    if (!two_factor_supported($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT totp_secret, totp_enabled, totp_last_step FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function two_factor_enabled(PDO $pdo, int $userId): bool
{
    $row = two_factor_row($pdo, $userId);
    return $row !== null && (int) $row['totp_enabled'] === 1;
}

/**
 * يتحقق من رمز المستخدم ويسجل خطوته كمستخدمة في نفس الأمر، فلو أُرسل نفس الرمز في طلبين
 * متزامنين ينجح أحدهما فقط.
 */
function two_factor_consume(PDO $pdo, int $userId, string $code): bool
{
    $row = two_factor_row($pdo, $userId);
    if ($row === null || (int) $row['totp_enabled'] !== 1) {
        return false;
    }
    $last = $row['totp_last_step'] === null ? null : (int) $row['totp_last_step'];
    if (!totp_verify((string) $row['totp_secret'], $code, $step, $last)) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_enabled = 1 AND (totp_last_step IS NULL OR totp_last_step < ?)');
    $stmt->execute([$step, $userId, $step]);
    return $stmt->rowCount() === 1;
}

/** يوقف التحقق بخطوتين (من الإعدادات، أو من وضع الاستعادة في install.php) */
function two_factor_disable(PDO $pdo, int $userId): void
{
    if (two_factor_supported($pdo)) {
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = ?')->execute([$userId]);
    }
}

/** بعد التفعيل أو الإيقاف: رقم الإصدار ارتفع فتنتهي الجلسات الأخرى، والجلسة الحالية تبقى (مثل change_password) */
function two_factor_keep_session(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT auth_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    session_regenerate_id(true);
    $_SESSION['auth_version'] = (int) $stmt->fetchColumn();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ===================== خطوة الرمز عند تسجيل الدخول ===================== */

/** بعد كلمة مرور صحيحة لحساب مفعل له التحقق: حالة مؤقتة في الجلسة، والدخول لم يكتمل بعد */
function two_factor_begin(array $user, string $username): void
{
    session_regenerate_id(true);
    $_SESSION['tfa_pending'] = [
        'user_id' => (int) $user['id'],
        'username' => $username,
        'auth_version' => (int) $user['auth_version'],
        'expires' => time() + TFA_PENDING_SECONDS,
        'ua_hash' => user_agent_hash(),
    ];
}

/** الحالة المؤقتة إن كانت صالحة. $expired = true إذا كانت موجودة وانتهت مهلتها (وتُحذف). */
function two_factor_pending(?bool &$expired = null): ?array
{
    $expired = false;
    $p = $_SESSION['tfa_pending'] ?? null;
    if (!is_array($p)) {
        return null;
    }
    if ((int) ($p['expires'] ?? 0) < time() || !hash_equals((string) ($p['ua_hash'] ?? ''), user_agent_hash())) {
        unset($_SESSION['tfa_pending']);
        $expired = true;
        return null;
    }
    return $p;
}

function two_factor_cancel(): void
{
    unset($_SESSION['tfa_pending']);
}

/**
 * خطوة الرمز: تُحسب ضمن نفس حدود محاولات الدخول. عند النجاح يكتمل الدخول بنفس طريقة attempt_login.
 * @return string|null رسالة خطأ، أو null عند النجاح. إذا لم تعد الحالة المؤقتة موجودة يرجع المستخدم لخطوة كلمة المرور.
 */
function two_factor_login(PDO $pdo, string $code): ?string
{
    $p = two_factor_pending();
    if ($p === null) {
        return 'انتهت مهلة إدخال رمز التحقق (5 دقائق). أدخل اسم المستخدم وكلمة المرور مرة أخرى.';
    }
    $lock = throttle_register($pdo, (string) $p['username']);
    if ($lock > 0) {
        auth_event($pdo, 'login_locked', (string) $p['username']);
        return sprintf('محاولات دخول كثيرة. حاول مرة أخرى بعد %s دقيقة.', fmt_int($lock));
    }
    $stmt = $pdo->prepare('SELECT id, username, auth_version FROM users WHERE id = ?');
    $stmt->execute([(int) $p['user_id']]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['auth_version'] !== (int) $p['auth_version'] || !two_factor_enabled($pdo, (int) $user['id'])) {
        two_factor_cancel();
        return 'تغيرت بيانات الدخول لهذا الحساب. أدخل اسم المستخدم وكلمة المرور مرة أخرى.';
    }
    if (!two_factor_consume($pdo, (int) $user['id'], $code)) {
        // كلمة المرور صحيحة لكن الرمز خطأ: يظهر في سجل الأمان كمحاولة فاشلة
        auth_event($pdo, 'login_fail', (string) $p['username']);
        return 'رمز التحقق غير صحيح أو استُخدم من قبل. اكتب الرمز الظاهر الآن في تطبيق المصادقة.';
    }
    two_factor_cancel();
    complete_login($pdo, $user, (string) $p['username']);
    return null;
}

/* ===================== قسم الإعدادات ===================== */

function two_factor_redirect(): never
{
    header('Location: ' . url('settings') . '#two-factor', true, 303);
    exit;
}

/**
 * إجراءات قسم «التحقق بخطوتين» في الإعدادات (الطلب تحقق من CSRF قبلها).
 * السر الجديد يبقى في الجلسة فقط حتى يؤكده المستخدم برمز صحيح.
 * @return array<string,string> أخطاء الحقول (عند النجاح يُعاد التوجيه ولا تعود الدالة)
 */
function two_factor_settings_post(PDO $pdo, string $action): array
{
    if (!two_factor_supported($pdo)) {
        flash('warning', 'حدّث قاعدة البيانات أولًا من أعلى صفحة الإعدادات، ثم فعّل التحقق بخطوتين.');
        two_factor_redirect();
    }
    $uid = current_user_id();
    $enabled = two_factor_enabled($pdo, $uid);

    if ($action === 'tfa_start') {
        if (!$enabled) {
            $_SESSION['tfa_setup'] = totp_new_secret();
        }
        two_factor_redirect();
    }
    if ($action === 'tfa_cancel') {
        unset($_SESSION['tfa_setup']);
        two_factor_redirect();
    }
    if ($action === 'tfa_confirm') {
        $secret = $_SESSION['tfa_setup'] ?? '';
        if ($enabled || !is_string($secret) || base32_decode($secret) === null) {
            unset($_SESSION['tfa_setup']);
            two_factor_redirect();
        }
        if (!totp_verify($secret, input($_POST, 'tfa_code'), $step)) {
            return ['tfa_code' => 'الرمز غير صحيح. اكتب الرمز الظاهر الآن في التطبيق (يتغير كل 30 ثانية)، وتأكد أن وقت الهاتف مضبوط تلقائيًا.'];
        }
        $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, auth_version = auth_version + 1 WHERE id = ?')
            ->execute([$secret, $step, $uid]);
        unset($_SESSION['tfa_setup']);
        two_factor_keep_session($pdo, $uid);
        flash('success', 'تم تفعيل التحقق بخطوتين، وانتهت الجلسات المفتوحة على الأجهزة الأخرى. سيُطلب الرمز عند كل تسجيل دخول.');
        two_factor_redirect();
    }
    if ($action === 'tfa_disable') {
        if (!$enabled) {
            two_factor_redirect();
        }
        // محاولة واحدة في حدود الدخول لكل طلب، ولا تُمسح إلا إذا صحت كلمة المرور والرمز معًا
        $lock = throttle_register($pdo, current_username());
        if ($lock > 0) {
            return ['tfa_password' => sprintf('محاولات كثيرة. حاول مرة أخرى بعد %s دقيقة.', fmt_int($lock))];
        }
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $hash = (string) $stmt->fetchColumn();
        $password = input($_POST, 'tfa_password');
        if (strlen($password) > 1000 || !password_verify($password, $hash ?: dummy_password_hash())) {
            return ['tfa_password' => 'كلمة المرور الحالية غير صحيحة.'];
        }
        if (!two_factor_consume($pdo, $uid, input($_POST, 'tfa_code'))) {
            return ['tfa_code' => 'رمز التحقق غير صحيح أو استُخدم من قبل. اكتب الرمز الظاهر الآن في التطبيق.'];
        }
        throttle_clear($pdo, current_username());
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL, auth_version = auth_version + 1 WHERE id = ?')
            ->execute([$uid]);
        two_factor_keep_session($pdo, $uid);
        flash('success', 'تم إيقاف التحقق بخطوتين، وانتهت الجلسات المفتوحة على الأجهزة الأخرى.');
        two_factor_redirect();
    }
    render_simple_error('إجراء غير معروف.', 400);
}
