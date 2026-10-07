<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

const VOLUME_DECIMAL_CHOICES = [
    'full' => 'الدقة الكاملة (القيمة الدقيقة بدون أصفار زائدة)',
    '2' => 'خانتان عشريتان',
    '3' => '3 خانات عشرية',
    '4' => '4 خانات عشرية',
    '5' => '5 خانات عشرية',
    '6' => '6 خانات عشرية',
];

if ($posted) {
    verify_csrf();
    $action = input($_POST, 'action');

    if ($action === 'general') {
        $values = [
            'company_name' => clean_text(input($_POST, 'company_name')),
            'currency' => clean_text(input($_POST, 'currency')),
            'digits' => input($_POST, 'digits'),
            'volume_decimals' => input($_POST, 'volume_decimals'),
            'volume_pad' => input($_POST, 'volume_pad') === '1' ? '1' : '0',
        ];
        if ($values['company_name'] === '' || mb_strlen($values['company_name']) > 120) {
            $errors['company_name'] = 'اسم الشركة مطلوب (' . fmt_int(120) . ' حرفًا على الأكثر).';
        }
        if ($values['currency'] === '' || mb_strlen($values['currency']) > 40) {
            $errors['currency'] = 'اسم العملة مطلوب (' . fmt_int(40) . ' حرفًا على الأكثر).';
        }
        foreach (DIMENSIONS as $key => $label) {
            $u = input($_POST, 'unit_' . $key);
            if (!is_unit($u)) {
                $errors['unit_' . $key] = 'اختر وحدة صحيحة لـ' . $label . '.';
            }
            $values['unit_' . $key] = $u;
        }
        if (!in_array($values['digits'], ['western', 'arabic'], true)) {
            $errors['digits'] = 'اختر شكل الأرقام.';
        }
        if (!isset(VOLUME_DECIMAL_CHOICES[$values['volume_decimals']])) {
            $errors['volume_decimals'] = 'اختر دقة عرض الحجم.';
        }
        // تاريخ الإقفال: فارغ = لا إقفال. لا يُقبل تاريخ في المستقبل حتى لا يُمنع العمل اليومي بالخطأ
        $closing = normalize_number_input(trim(input($_POST, 'closing_date')));
        if ($closing !== '') {
            $cd = DateTimeImmutable::createFromFormat('!Y-m-d', $closing);
            if (!$cd || $cd->format('Y-m-d') !== $closing) {
                $errors['closing_date'] = 'تاريخ الإقفال غير صحيح. استخدم الصيغة سنة-شهر-يوم.';
            } elseif ($closing >= date('Y-m-d')) {
                $errors['closing_date'] = 'تاريخ الإقفال يجب أن يكون قبل اليوم.';
            }
        }
        $values['closing_date'] = $closing;
        if (!$errors) {
            // يحفظ ويسجل في سجل المراقبة الإعدادات التي تغيرت فقط (القديم والجديد)
            settings_save_audited($pdo, $values);
            flash('success', 'تم حفظ الإعدادات. تغيير العملة لا يغير الفواتير السابقة.');
            redirect('settings');
        }
    } elseif ($action === 'migrate') {
        require_permission('migrate.run');
        $applied = run_migrations($pdo);
        audit_migrations($pdo, $applied);
        flash('success', $applied ? 'تم تحديث قاعدة البيانات.' : 'قاعدة البيانات محدثة بالفعل.');
        redirect('settings');
    } else {
        render_simple_error('إجراء غير معروف.', 400);
    }
}

$current = fn (string $k) => $errors ? input($_POST, $k) : app_setting($k);
$pending = pending_migrations($pdo);
$authLog = auth_events_overview($pdo, 50);

render_header('الإعدادات', 'settings');
?>
<h1>الإعدادات</h1>

<?php if ($pending): ?>
<section class="section confirm-box warning" aria-labelledby="migrate-title">
  <h2 id="migrate-title">تحديث قاعدة البيانات مطلوب</h2>
  <p>يوجد تحديث لقاعدة البيانات مع هذا الإصدار من النظام. خذ نسخة احتياطية من قاعدة البيانات أولًا (من phpMyAdmin أو Backups في لوحة Hostinger) ثم اضغط التحديث.</p>
  <form method="post" action="<?= h(url('settings')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="migrate">
    <button type="submit" class="btn btn-primary" data-busy-text="جارٍ التحديث">تحديث قاعدة البيانات</button>
  </form>
</section>
<?php endif; ?>

<section class="section">
  <h2>بيانات عامة</h2>
  <?= errors_summary($errors) ?>
  <form method="post" action="<?= h(url('settings')) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="general">
    <div class="field">
      <label for="company_name">اسم الشركة (يظهر في الفواتير)</label>
      <input type="text" id="company_name" name="company_name" value="<?= h($current('company_name')) ?>" maxlength="120" required<?= field_attrs($errors, 'company_name') ?>>
      <?= field_error($errors, 'company_name') ?>
    </div>
    <div class="field">
      <label for="currency">العملة</label>
      <input type="text" id="currency" name="currency" value="<?= h($current('currency')) ?>" maxlength="40" required<?= field_attrs($errors, 'currency', 'hint-currency') ?>>
      <p class="hint" id="hint-currency">تُحفظ العملة مع كل فاتورة، فلا تتغير الفواتير القديمة عند تعديلها.</p>
      <?= field_error($errors, 'currency') ?>
    </div>
    <fieldset>
      <legend>الوحدات الافتراضية في نموذج الوارد</legend>
      <p class="hint">إذا غيّر المستخدم الوحدة أثناء الإدخال، يتذكر المتصفح آخر اختيار له.</p>
      <div class="field-row">
        <?php foreach (DIMENSIONS as $key => $label): $sel = $current('unit_' . $key); ?>
          <div class="field">
            <label for="unit_<?= h($key) ?>"><?= h($label) ?></label>
            <select id="unit_<?= h($key) ?>" name="unit_<?= h($key) ?>">
              <?php foreach (UNITS as $u => $info): ?>
                <option value="<?= h($u) ?>"<?= $u === $sel ? ' selected' : '' ?>><?= h($info['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="field-row">
      <div class="field">
        <label for="digits">شكل الأرقام في العرض والطباعة</label>
        <select id="digits" name="digits" aria-describedby="hint-digits">
          <option value="arabic"<?= $current('digits') === 'arabic' ? ' selected' : '' ?>>عربية ٠١٢٣٤٥٦٧٨٩</option>
          <option value="western"<?= $current('digits') === 'western' ? ' selected' : '' ?>>إنجليزية 0123456789</option>
        </select>
        <p class="hint" id="hint-digits">الإدخال يقبل الشكلين دائمًا.</p>
      </div>
      <div class="field">
        <label for="volume_decimals">دقة عرض الحجم (م³)</label>
        <select id="volume_decimals" name="volume_decimals" aria-describedby="hint-volume"<?= field_attrs($errors, 'volume_decimals') ?>>
          <?php foreach (VOLUME_DECIMAL_CHOICES as $v => $label): ?>
            <option value="<?= h($v) ?>"<?= $current('volume_decimals') === $v ? ' selected' : '' ?>><?= h(digits($label)) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint" id="hint-volume">للعرض فقط. الحساب الداخلي والقيمة المالية من الحجم الدقيق دائمًا.</p>
      </div>
    </div>
    <div class="field field-check">
      <input type="checkbox" id="volume_pad" name="volume_pad" value="1"<?= $current('volume_pad') === '1' ? ' checked' : '' ?>>
      <label for="volume_pad">إظهار الأصفار في آخر الحجم عند اختيار عدد خانات ثابت (مثل <?= h(digits('0.150')) ?>)</label>
    </div>
    <div class="field field-narrow">
      <label for="closing_date">تاريخ الإقفال (اختياري)</label>
      <input type="date" id="closing_date" name="closing_date" value="<?= h($current('closing_date')) ?>"<?= field_attrs($errors, 'closing_date', 'hint-closing') ?>>
      <p class="hint" id="hint-closing">بعد مراجعة حسابات فترة: لا يُسجل ولا يُلغى أي مستند أو سند بتاريخ في هذا اليوم أو قبله. اتركه فارغًا لعدم الإقفال.</p>
      <?= field_error($errors, 'closing_date') ?>
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
    </div>
  </form>
</section>

<p>تغيير كلمة المرور من صفحة <a href="<?= h(url('account')) ?>">حسابي</a>، وإدارة حسابات الموظفين من صفحة <a href="<?= h(url('users')) ?>">المستخدمون</a>.</p>

<section class="section" aria-labelledby="auth-log-title">
  <h2 id="auth-log-title">سجل الدخول والأمان</h2>
  <div id="live-auth-events" data-live>
  <?php if ($authLog === null): ?>
    <p class="empty">يبدأ السجل بعد تحديث قاعدة البيانات من الزر في أعلى الصفحة.</p>
  <?php elseif (!$authLog['rows']): ?>
    <p class="empty">لا توجد أحداث مسجلة بعد.</p>
  <?php else: ?>
    <p class="summary">
      خلال آخر <?= h(fmt_int(24)) ?> ساعة: محاولات الدخول الفاشلة <strong><?= h(fmt_int($authLog['failed'])) ?></strong>،
      والمحاولات المحظورة مؤقتًا <strong><?= h(fmt_int($authLog['locked'])) ?></strong>.
    </p>
    <div class="table-wrap">
      <table>
        <caption class="visually-hidden">آخر <?= h(fmt_int(50)) ?> حدثًا في سجل الدخول والأمان من الأحدث إلى الأقدم</caption>
        <thead>
          <tr>
            <th scope="col">الحدث</th>
            <th scope="col">اسم المستخدم</th>
            <th scope="col">العنوان (IP)</th>
            <th scope="col">المتصفح</th>
            <th scope="col">الوقت</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($authLog['rows'] as $ev):
            $failure = in_array($ev['event'], ['login_fail', 'login_locked'], true);
            $agent = fmt_user_agent($ev['user_agent']);
            // «Chrome على Windows» يُقرأ صحيحًا داخل RTL، أما نص الترويسة الخام فيُعزل باتجاه LTR حتى لا تنقلب أقواسه
            $agentLtr = !preg_match('/\p{Arabic}/u', $agent); ?>
          <tr>
            <td class="nowrap"><?php if ($failure): ?><span class="status status-empty"><?= h(auth_event_label($ev['event'])) ?></span><?php else: ?><?= h(auth_event_label($ev['event'])) ?><?php endif; ?></td>
            <td><bdi><?= h($ev['username']) ?></bdi></td>
            <td class="num" dir="ltr"><?= h($ev['ip']) ?></td>
            <td class="nowrap"<?= $ev['user_agent'] !== '' ? ' title="' . h($ev['user_agent']) . '"' : '' ?>><?= $agentLtr ? '<bdi dir="ltr">' . h($agent) . '</bdi>' : h($agent) ?></td>
            <td class="nowrap"><?= h(fmt_datetime($ev['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">يُحتفظ بالسجل <?= h(fmt_int(AUTH_EVENTS_KEEP_DAYS)) ?> يومًا. المحاولات المتكررة أثناء الحظر من نفس العنوان تُسجل مرة واحدة كل دقيقة.</p>
  <?php endif; ?>
  </div>
</section>

<p class="muted">إصدار النظام <?= h(digits(APP_VERSION)) ?>، إصدار قاعدة البيانات <?= h(fmt_int(schema_version($pdo))) ?>.</p>
<?php
render_footer();
