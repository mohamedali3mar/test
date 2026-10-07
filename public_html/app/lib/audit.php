<?php
defined('APP_ROOT') || exit;

/*
 * سجل المراقبة: من فعل ماذا، ومتى، ومن أين.
 *
 * قواعد ثابتة:
 *  - audit_record() تُستدعى داخل نفس معاملة العملية، مباشرة قبل data_version_bump()،
 *    فالعملية وسطر السجل يُحفظان معًا أو يتراجعان معًا. لا توجد عملية محفوظة بلا سطر في السجل.
 *  - الملخص نص عربي عادي يُبنى من البيانات (يُهرَّب عند العرض فقط)، والتفاصيل JSON بالقيم القديمة والجديدة.
 *  - دوال الملخص لا تقرأ من قاعدة البيانات داخل المعاملة: تعتمد على البيانات الممررة لها،
 *    وإعداد شكل الأرقام يُحمَّل قبل المعاملة (require_login في طلبات الحفظ).
 *  - السجل دائم ولا يُحذف منه شيء.
 *  - لا تُسجل كلمات المرور أو تجزئاتها أو أي قيمة سرية.
 */

const AUDIT_GROUPS = [
    'documents' => 'المستندات',
    'cancel'    => 'الإلغاء',
    'master'    => 'البيانات الأساسية',
    'settings'  => 'الإعدادات',
    'users'     => 'المستخدمون',
    'accounts'  => 'الحسابات',
    'export'    => 'التصدير',
];

/** العملية => [الاسم المعروض، المجموعة] */
const AUDIT_ACTIONS = [
    'doc.receipt'         => ['وارد', 'documents'],
    'doc.sale'            => ['فاتورة بيع', 'documents'],
    'doc.transfer'        => ['تحويل', 'documents'],
    'doc.cancel'          => ['إلغاء مستند', 'cancel'],
    'voucher.cancel'      => ['إلغاء سند', 'cancel'],
    'type.create'         => ['إضافة نوع خشب', 'master'],
    'type.rename'         => ['تعديل اسم نوع', 'master'],
    'type.delete'         => ['حذف نوع خشب', 'master'],
    'warehouse.create'    => ['إضافة مخزن', 'master'],
    'warehouse.rename'    => ['تعديل اسم مخزن', 'master'],
    'warehouse.delete'    => ['حذف مخزن', 'master'],
    'warehouse.move'      => ['نقل مخزن إلى فرع', 'master'],
    'branch.create'       => ['إضافة فرع', 'master'],
    'branch.update'       => ['تعديل فرع', 'master'],
    'branch.delete'       => ['حذف فرع', 'master'],
    'settings.update'     => ['تعديل الإعدادات', 'settings'],
    'db.migrate'          => ['تحديث قاعدة البيانات', 'settings'],
    'account.password'    => ['تغيير كلمة المرور', 'users'],
    'user.create'         => ['إضافة مستخدم', 'users'],
    'user.update'         => ['تعديل مستخدم', 'users'],
    'user.role'           => ['تغيير الدور', 'users'],
    'user.disable'        => ['تعطيل مستخدم', 'users'],
    'user.enable'         => ['تفعيل مستخدم', 'users'],
    'user.password_reset' => ['تعيين كلمة مرور', 'users'],
    'user.branch'         => ['تحديد فرع المستخدم', 'users'],
    'voucher.create'      => ['سند', 'accounts'],
    'party.create'        => ['إضافة عميل أو مورد', 'accounts'],
    'party.update'        => ['تعديل عميل أو مورد', 'accounts'],
    'party.activate'      => ['تفعيل عميل أو مورد', 'accounts'],
    'party.deactivate'    => ['إيقاف عميل أو مورد', 'accounts'],
    'party.delete'        => ['حذف عميل أو مورد', 'accounts'],
    'cash_box.create'     => ['إضافة خزنة', 'accounts'],
    'cash_box.rename'     => ['تعديل اسم خزنة', 'accounts'],
    'cash_box.activate'   => ['تفعيل خزنة', 'accounts'],
    'cash_box.deactivate' => ['إيقاف خزنة', 'accounts'],
    'expense_category.create'     => ['إضافة تصنيف مصروفات', 'accounts'],
    'expense_category.rename'     => ['تعديل تصنيف مصروفات', 'accounts'],
    'expense_category.activate'   => ['تفعيل تصنيف مصروفات', 'accounts'],
    'expense_category.deactivate' => ['إيقاف تصنيف مصروفات', 'accounts'],
    'opening_valuation'   => ['تقييم افتتاحي للمخزون', 'accounts'],
    'data.export'         => ['تصدير بيانات', 'export'],
];

/** الإعدادات التي تُسجل تغييراتها، بأسمائها المعروضة. أي مفتاح غيرها (مثل dummy_hash) لا يُسجل أبدًا */
const AUDIT_SETTING_LABELS = [
    'company_name'    => 'اسم الشركة',
    'currency'        => 'العملة',
    'unit_width'      => 'وحدة العرض',
    'unit_thickness'  => 'وحدة التخانة',
    'unit_length'     => 'وحدة الطول',
    'digits'          => 'شكل الأرقام',
    'volume_decimals' => 'دقة عرض الحجم',
    'volume_pad'      => 'إظهار الأصفار في الحجم',
    'closing_date'    => 'تاريخ الإقفال',
];

/** أحداث جدول auth_events (سجل الدخول) بنفس أسمائها في قسم الإعدادات */
const MONITOR_AUTH_EVENT_LABELS = [
    'login_ok'         => 'دخول ناجح',
    'login_fail'       => 'محاولة دخول فاشلة',
    'login_locked'     => 'دخول محظور مؤقتًا',
    'logout'           => 'تسجيل خروج',
    'password_changed' => 'تغيير كلمة المرور',
    'password_reset'   => 'استعادة كلمة المرور',
    'session_expired'  => 'انتهاء الجلسة',
];

const AUDIT_PAGE_SIZE = 50;

/* ===================== التسجيل ===================== */

/** رقم خطأ قاعدة البيانات (مثل 1146 جدول غير موجود، 1054 عمود غير موجود) */
function audit_db_error_code(Throwable $e): ?int
{
    return $e instanceof PDOException && isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
}

/**
 * يضيف سطرًا إلى سجل المراقبة باسم المستخدم الحالي (من الجلسة) وعنوانه وجهازه.
 * بدون جلسة (سطر الأوامر) يُسجل كعملية «النظام».
 * إذا لم يُنشأ الجدول بعد (ترقية قاعدة البيانات معلقة) يُتجاهل هذا الخطأ وحده حتى يعمل النظام
 * إلى أن يطبق المدير الترقية؛ أي خطأ آخر يُرفع فتتراجع العملية كلها.
 */
function audit_record(PDO $pdo, string $action, string $summary, string $entity = '', ?int $entityId = null, ?array $details = null): void
{
    $userId = current_user_id();
    $json = $details === null ? null
        : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    try {
        $pdo->prepare(
            'INSERT INTO audit_log (created_at, user_id, username, action, entity, entity_id, summary, details, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            now(),
            $userId > 0 ? $userId : null,
            mb_substr(current_username(), 0, 60),
            mb_substr($action, 0, 40),
            mb_substr($entity, 0, 20),
            $entityId,
            mb_substr($summary, 0, 500),
            $json,
            audit_client_ip(),
            audit_user_agent(),
        ]);
    } catch (PDOException $e) {
        if (audit_db_error_code($e) !== 1146) {
            throw $e;
        }
        error_log('[wood] audit_log table missing (database update pending), not recorded: ' . $action);
    }
}

/** عنوان IP الكامل للعرض (وليس مفتاح /64 المستخدم في حدود الدخول). لا نثق في X-Forwarded-For. */
function audit_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return mb_substr(clean_text(mb_scrub($ip, 'UTF-8')), 0, 45);
    }
    return (string) inet_ntop($bin);
}

/** وصف المتصفح كما أرسله، بعد التنظيف والقص إلى 255 حرفًا */
function audit_user_agent(): string
{
    return mb_substr(clean_text(mb_scrub((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'UTF-8')), 0, 255);
}

/* ===================== ملخصات العمليات ===================== */

/** مثل: فاتورة بيع رقم ١٢، وارد رقم ٥، تحويل رقم ٤ */
function audit_doc_name(string $kind, int $docNo): string
{
    $noun = match ($kind) {
        'sale' => 'فاتورة بيع',
        'transfer' => 'تحويل',
        default => 'وارد',
    };
    return $noun . ' رقم ' . fmt_doc_no($docNo);
}

/** عدد الأصناف بصيغة عربية صحيحة: صنف واحد، صنفان، ٣ أصناف، ١١ صنفًا */
function audit_items_phrase(int $n): string
{
    return match (true) {
        $n === 1 => 'صنف واحد',
        $n === 2 => 'صنفان',
        $n >= 3 && $n <= 10 => fmt_int($n) . ' أصناف',
        default => fmt_int($n) . ' صنفًا',
    };
}

/** أسطر البيع أو التحويل للتفاصيل: النوع والمقاس والكمية (والسعر والقيمة في البيع) */
function audit_lines_details(array $lines, bool $withPrice): array
{
    $out = [];
    foreach ($lines as $l) {
        $row = [
            'item_id' => (int) $l['item']['id'],
            'wood_type' => $l['item']['wood_type_name'],
            'size' => fmt_size($l['item']),
            'quantity' => $l['quantity'],
        ];
        if ($withPrice) {
            $row['price_per_m3'] = $l['price'];
            $row['amount'] = $l['amount'];
        }
        $out[] = $row;
    }
    return $out;
}

/** $v من validate_receipt() */
function audit_receipt(PDO $pdo, int $docId, int $docNo, array $v): void
{
    $d = $v['dims'];
    $size = fmt_size([
        'width_um' => $d['width']['um'], 'thickness_um' => $d['thickness']['um'], 'length_um' => $d['length']['um'],
        'width_unit' => $d['width']['unit'], 'thickness_unit' => $d['thickness']['unit'], 'length_unit' => $d['length']['unit'],
    ]);
    $summary = sprintf('%s إلى %s: %s، %s، %s قطعة', audit_doc_name('in', $docNo), $v['warehouse']['name'], $v['type']['name'], $size, fmt_int($v['quantity']));
    if ($v['party_name'] !== '') {
        $summary .= '، المورد: ' . $v['party_name'];
    }
    audit_record($pdo, 'doc.receipt', $summary, 'document', $docId, [
        'kind' => 'in', 'doc_no' => $docNo,
        'warehouse_id' => (int) $v['warehouse']['id'], 'warehouse' => $v['warehouse']['name'],
        'wood_type' => $v['type']['name'], 'size' => $size,
        'quantity' => $v['quantity'], 'volume_m3' => Num::trimDecimal($v['total_m3']),
        'supplier' => $v['party_name'], 'reference' => $v['reference'],
    ]);
}

/** $v من validate_sale() */
function audit_sale(PDO $pdo, int $docId, int $docNo, array $v, string $currency): void
{
    $summary = sprintf(
        '%s من %s: %s بإجمالي %s',
        audit_doc_name('sale', $docNo),
        $v['warehouse']['name'],
        audit_items_phrase(count($v['lines'])),
        fmt_money_currency($v['total_amount'], $currency)
    );
    if ($v['party_name'] !== '') {
        $summary .= '، العميل: ' . $v['party_name'];
    }
    audit_record($pdo, 'doc.sale', $summary, 'document', $docId, [
        'kind' => 'sale', 'doc_no' => $docNo,
        'warehouse_id' => (int) $v['warehouse']['id'], 'warehouse' => $v['warehouse']['name'],
        'customer' => $v['party_name'],
        'line_count' => count($v['lines']), 'total_qty' => $v['total_qty'], 'total_volume_m3' => Num::trimDecimal($v['total_m3']),
        'total_amount' => $v['total_amount'], 'currency' => $currency,
        'lines' => audit_lines_details($v['lines'], true),
    ]);
}

/** $v من validate_transfer() */
function audit_transfer(PDO $pdo, int $docId, int $docNo, array $v): void
{
    $summary = sprintf(
        '%s من %s إلى %s: %s، %s قطعة',
        audit_doc_name('transfer', $docNo),
        $v['from']['name'],
        $v['to']['name'],
        audit_items_phrase(count($v['lines'])),
        fmt_int($v['total_qty'])
    );
    audit_record($pdo, 'doc.transfer', $summary, 'document', $docId, [
        'kind' => 'transfer', 'doc_no' => $docNo,
        'from_warehouse_id' => (int) $v['from']['id'], 'from_warehouse' => $v['from']['name'],
        'to_warehouse_id' => (int) $v['to']['id'], 'to_warehouse' => $v['to']['name'],
        'line_count' => count($v['lines']), 'total_qty' => $v['total_qty'], 'total_volume_m3' => Num::trimDecimal($v['total_m3']),
        'lines' => audit_lines_details($v['lines'], false),
    ]);
}

/** $doc صف المستند المقفل قبل الإلغاء */
function audit_cancel(PDO $pdo, array $doc, string $reason): void
{
    $summary = 'إلغاء ' . audit_doc_name($doc['kind'], (int) $doc['doc_no'])
        . ($reason !== '' ? '، السبب: ' . $reason : ' بدون سبب مكتوب');
    $details = ['kind' => $doc['kind'], 'doc_no' => (int) $doc['doc_no'], 'reason' => $reason,
        'warehouse' => $doc['warehouse_name'], 'total_qty' => (int) $doc['total_qty']];
    if ($doc['kind'] === 'sale') {
        $details['total_amount'] = (string) $doc['total_amount'];
        $details['currency'] = (string) $doc['currency'];
    }
    audit_record($pdo, 'doc.cancel', $summary, 'document', (int) $doc['id'], $details);
}

/** $op: create | rename | delete. $oldName لتعديل الاسم فقط */
function audit_catalog(PDO $pdo, string $kind, string $op, int $id, string $name, ?string $oldName = null): void
{
    $noun = $kind === 'warehouse' ? 'مخزن' : 'نوع خشب';
    $summary = match ($op) {
        'create' => "إضافة {$noun} «{$name}»",
        'rename' => "تعديل اسم {$noun} من «{$oldName}» إلى «{$name}»",
        default => "حذف {$noun} «{$name}»",
    };
    $details = $op === 'rename' ? ['name' => ['old' => $oldName, 'new' => $name]] : ['name' => $name];
    audit_record($pdo, ($kind === 'warehouse' ? 'warehouse.' : 'type.') . $op, $summary,
        $kind === 'warehouse' ? 'warehouse' : 'wood_type', $id, $details);
}

/** قيمة إعداد كما تُعرض في الملخص */
function audit_setting_display(string $key, string $value): string
{
    return match ($key) {
        'digits' => ['arabic' => 'عربية', 'western' => 'إنجليزية'][$value] ?? $value,
        'unit_width', 'unit_thickness', 'unit_length' => is_unit($value) ? unit_label($value) : $value,
        'volume_decimals' => $value === 'full' ? 'الدقة الكاملة' : digits($value) . ' خانات',
        'volume_pad' => $value === '1' ? 'نعم' : 'لا',
        'closing_date' => $value === '' ? 'بدون' : digits($value),
        default => $value,
    };
}

/**
 * يحفظ الإعدادات العامة ويسجل المفاتيح التي تغيرت فقط (القديم والجديد) في نفس المعاملة.
 * القيم القديمة تُقرأ مقفلة داخل المعاملة، فحفظان متزامنان لا يسجلان قيمًا قديمة خاطئة.
 * @param array<string,string> $values
 * @return array<string,array{old:string,new:string}> المفاتيح التي تغيرت
 */
function settings_save_audited(PDO $pdo, array $values): array
{
    return db_transaction($pdo, function (PDO $pdo) use ($values) {
        $names = array_keys($values);
        $old = DEFAULT_SETTINGS;
        if ($names) {
            $stmt = $pdo->prepare('SELECT name, value FROM settings WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY name FOR UPDATE');
            $stmt->execute($names);
            foreach ($stmt as $row) {
                $old[$row['name']] = $row['value'];
            }
        }
        $changed = [];
        foreach ($values as $k => $v) {
            if ((string) ($old[$k] ?? '') !== $v) {
                save_setting($pdo, $k, $v);
                if (isset(AUDIT_SETTING_LABELS[$k])) {
                    $changed[$k] = ['old' => (string) ($old[$k] ?? ''), 'new' => $v];
                }
            }
        }
        // ترتيب ثابت في الملخص (ترتيب الإعدادات في الصفحة) مهما كان ترتيب المدخلات
        $changed = array_intersect_key(array_replace(AUDIT_SETTING_LABELS, $changed), $changed);
        if ($changed) {
            $parts = [];
            foreach ($changed as $k => $c) {
                $parts[] = sprintf('%s من «%s» إلى «%s»', AUDIT_SETTING_LABELS[$k], audit_setting_display($k, $c['old']), audit_setting_display($k, $c['new']));
            }
            audit_record($pdo, 'settings.update', 'تعديل الإعدادات: ' . implode('، ', $parts), 'settings', null, $changed);
        }
        data_version_bump($pdo);
        return $changed;
    });
}

/** يسجل ترقيات قاعدة البيانات بعد انتهاء run_migrations (الترقيات نفسها لا تعمل داخل معاملة) */
function audit_migrations(PDO $pdo, array $applied): void
{
    if (!$applied) {
        return;
    }
    $last = max($applied);
    db_transaction($pdo, function (PDO $pdo) use ($applied, $last) {
        audit_record($pdo, 'db.migrate', 'تحديث قاعدة البيانات إلى الإصدار ' . fmt_int($last), 'schema', $last,
            ['applied' => array_values(array_map('intval', $applied))]);
        data_version_bump($pdo);
    });
}

/* ===================== العرض ===================== */

function audit_action_label(string $action): string
{
    return AUDIT_ACTIONS[$action][0] ?? $action;
}

/** @return string[] عمليات المجموعة */
function audit_group_actions(string $group): array
{
    return array_keys(array_filter(AUDIT_ACTIONS, fn ($a) => $a[1] === $group));
}

/**
 * اسم قصير للمتصفح ونظام التشغيل من User-Agent، مثل «كروم على أندرويد».
 * الترتيب مهم: إيدج وأوبرا وسامسونج تذكر Chrome أيضًا، وكروم يذكر Safari.
 */
function audit_device_label(string $ua): string
{
    if ($ua === '') {
        return '';
    }
    $browsers = [
        '/\bEdg(?:e|A|iOS)?\//' => 'إيدج',
        '/\bOPR\/|\bOpera\b/' => 'أوبرا',
        '/\bSamsungBrowser\//' => 'سامسونج إنترنت',
        '/\b(?:Firefox|FxiOS)\//' => 'فايرفوكس',
        '/\b(?:Chrome|CriOS|Chromium)\//' => 'كروم',
        '/\bSafari\//' => 'سفاري',
    ];
    $systems = [
        '/\biPhone\b/' => 'آيفون',
        '/\biPad\b/' => 'آيباد',
        '/\bAndroid\b/' => 'أندرويد',
        '/\bWindows\b/' => 'ويندوز',
        '/\bCrOS\b/' => 'كروم أو إس',
        '/\bMacintosh\b|\bMac OS X\b/' => 'ماك',
        '/\bLinux\b/' => 'لينكس',
    ];
    $browser = '';
    foreach ($browsers as $re => $label) {
        if (preg_match($re, $ua)) {
            $browser = $label;
            break;
        }
    }
    $os = '';
    foreach ($systems as $re => $label) {
        if (preg_match($re, $ua)) {
            $os = $label;
            break;
        }
    }
    if ($browser !== '' && $os !== '') {
        return $browser . ' على ' . $os;
    }
    if ($browser !== '' || $os !== '') {
        return $browser !== '' ? $browser : 'متصفح على ' . $os;
    }
    return mb_strlen($ua) > 60 ? mb_substr($ua, 0, 60) . '…' : $ua;
}

/* ===================== استعلامات صفحة المراقبة ===================== */

/** تاريخ بصيغة YYYY-MM-DD من حقل التصفية. @return array{0:?DateTimeImmutable,1:bool} [التاريخ، غير صالح] */
function audit_parse_date(string $raw): array
{
    $raw = normalize_number_input($raw);
    if ($raw === '') {
        return [null, false];
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return [null, true];
    }
    return [$dt, false];
}

/**
 * يقرأ مرشحات صفحة المراقبة من الطلب ويتحقق منها. القيم غير الصالحة تُتجاهل وتُذكر في ignored.
 * @param array<int,string> $userIds معرفات المستخدمين الموجودين
 */
function audit_filters(array $get, array $userIds): array
{
    $f = ['user' => '', 'group' => '', 'from' => null, 'to' => null, 'q' => '', 'doc' => 0, 'ignored' => []];
    $user = input($get, 'user');
    if ($user === 'system' || ($user !== '' && ctype_digit($user) && in_array((int) $user, $userIds, true))) {
        $f['user'] = $user;
    } elseif ($user !== '') {
        $f['ignored'][] = 'المستخدم';
    }
    $group = input($get, 'group');
    if (isset(AUDIT_GROUPS[$group])) {
        $f['group'] = $group;
    } elseif ($group !== '') {
        $f['ignored'][] = 'نوع العملية';
    }
    [$f['from'], $badFrom] = audit_parse_date(input($get, 'from'));
    [$f['to'], $badTo] = audit_parse_date(input($get, 'to'));
    if ($badFrom || $badTo) {
        $f['ignored'][] = 'التاريخ (استخدم الصيغة 2026-01-31)';
    }
    $q = clean_text(input($get, 'q'));
    if (mb_strlen($q) > 100) {
        $f['ignored'][] = 'نص البحث (100 حرف على الأكثر)';
    } else {
        $f['q'] = $q;
    }
    $doc = normalize_number_input(input($get, 'doc'));
    if (preg_match('/^[1-9]\d{0,8}\z/', $doc)) {
        $f['doc'] = (int) $doc;
    } elseif ($doc !== '') {
        $f['ignored'][] = 'رقم المستند';
    }
    return $f;
}

/** المرشحات كمعاملات رابط (لروابط الصفحات) */
function audit_filter_query(array $f): array
{
    return array_filter([
        'r' => 'monitor',
        'user' => $f['user'],
        'group' => $f['group'],
        'from' => $f['from'] ? $f['from']->format('Y-m-d') : '',
        'to' => $f['to'] ? $f['to']->format('Y-m-d') : '',
        'q' => $f['q'],
        'doc' => $f['doc'] ?: '',
    ], fn ($v) => $v !== '');
}

/** @return array{0:string,1:array} [شرط WHERE، القيم] */
function audit_where(array $f): array
{
    $where = [];
    $params = [];
    if ($f['user'] === 'system') {
        $where[] = 'a.user_id IS NULL';
    } elseif ($f['user'] !== '') {
        $where[] = 'a.user_id = ?';
        $params[] = (int) $f['user'];
    }
    if ($f['group'] !== '') {
        $actions = audit_group_actions($f['group']);
        $where[] = 'a.action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
        array_push($params, ...$actions);
    }
    if ($f['from']) {
        $where[] = 'a.created_at >= ?';
        $params[] = $f['from']->format('Y-m-d 00:00:00');
    }
    if ($f['to']) {
        $where[] = 'a.created_at < ?';
        $params[] = $f['to']->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    if ($f['q'] !== '') {
        $where[] = 'a.summary LIKE ?';
        $params[] = '%' . addcslashes($f['q'], '%_\\') . '%';
    }
    if ($f['doc'] > 0) {
        $where[] = "a.entity = 'document' AND a.entity_id IN (SELECT id FROM documents WHERE doc_no = ?)";
        $params[] = $f['doc'];
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** @return array{rows:array,total:int,page:int,pages:int} الأحدث أولًا */
function audit_search(PDO $pdo, array $f, int $page, int $perPage = AUDIT_PAGE_SIZE): array
{
    [$whereSql, $params] = audit_where($f);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log a' . $whereSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $stmt = $pdo->prepare(
        'SELECT a.*, u.display_name AS user_display_name
         FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . $whereSql
        . ' ORDER BY a.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (($page - 1) * (int) $perPage)
    );
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** اسم من نفذ العملية: الاسم المعروض الحالي، أو اسم المستخدم وقتها، أو «النظام» */
function audit_actor_name(array $row): string
{
    if ($row['user_id'] === null) {
        return 'النظام';
    }
    $display = (string) ($row['user_display_name'] ?? '');
    return $display !== '' ? $display : (string) $row['username'];
}

/**
 * ملخص اليوم وآخر ٢٤ ساعة لأعلى صفحة المراقبة.
 * failed_logins = null إذا لم يكن جدول سجل الدخول auth_events موجودًا.
 */
function monitor_summary(PDO $pdo): array
{
    $today = date('Y-m-d 00:00:00');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE created_at >= ?');
    $stmt->execute([$today]);
    $operations = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT currency, COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total FROM documents
         WHERE kind = 'sale' AND status = 'active' AND created_at >= ? GROUP BY currency ORDER BY currency"
    );
    $stmt->execute([$today]);
    $sales = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE status = 'cancelled' AND cancelled_at >= ?");
    $stmt->execute([$today]);
    $cancellations = (int) $stmt->fetchColumn();

    $failed = null;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM auth_events WHERE event IN ('login_fail', 'login_locked') AND created_at >= ?");
        $stmt->execute([date('Y-m-d H:i:s', time() - 86400)]);
        $failed = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        if (audit_db_error_code($e) !== 1146) {
            throw $e;
        }
    }

    return [
        'operations' => $operations,
        'sales' => $sales,
        'sales_count' => array_sum(array_map(fn ($r) => (int) $r['n'], $sales)),
        'cancellations' => $cancellations,
        'failed_logins' => $failed,
        'online' => users_online($pdo),
    ];
}

/** آخر أحداث الدخول، أو null إذا لم يكن جدول auth_events موجودًا */
function monitor_auth_events(PDO $pdo, int $limit = 50): ?array
{
    try {
        return $pdo->query('SELECT id, event, username, ip, user_agent, created_at FROM auth_events ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
    } catch (PDOException $e) {
        if (audit_db_error_code($e) !== 1146) {
            throw $e;
        }
        return null;
    }
}
