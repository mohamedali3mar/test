<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$pwErrors = [];
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
            $errors['company_name'] = 'اسم الشركة مطلوب (120 حرفًا على الأكثر).';
        }
        if ($values['currency'] === '' || mb_strlen($values['currency']) > 40) {
            $errors['currency'] = 'اسم العملة مطلوب (40 حرفًا على الأكثر).';
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
        if (!$errors) {
            db_transaction($pdo, function (PDO $pdo) use ($values) {
                foreach ($values as $k => $v) {
                    save_setting($pdo, $k, $v);
                }
                data_version_bump($pdo);
            });
            flash('success', 'تم حفظ الإعدادات. تغيير العملة لا يغير الفواتير السابقة.');
            redirect('settings');
        }
    } elseif ($action === 'password') {
        $new = input($_POST, 'new_password');
        $problem = verify_current_password($pdo, input($_POST, 'current_password'));
        if ($problem !== null) {
            $pwErrors['current_password'] = $problem;
        } elseif ($problem = password_problem($new, input($_POST, 'confirm_password'), current_username())) {
            $pwErrors['new_password'] = $problem;
        } else {
            change_password($pdo, current_user_id(), $new);
            flash('success', 'تم تغيير كلمة المرور، وانتهت الجلسات المفتوحة على الأجهزة الأخرى.');
            redirect('settings');
        }
    } elseif ($action === 'migrate') {
        $applied = run_migrations($pdo);
        flash('success', $applied ? 'تم تحديث قاعدة البيانات.' : 'قاعدة البيانات محدثة بالفعل.');
        redirect('settings');
    } else {
        render_simple_error('إجراء غير معروف.', 400);
    }
}

$current = fn (string $k) => $errors ? input($_POST, $k) : app_setting($k);
$pending = pending_migrations($pdo);

render_header('الإعدادات', 'settings');
?>
<h1>الإعدادات</h1>

<?php if ($pending): ?>
<section class="section confirm-box" aria-labelledby="migrate-title">
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
  <form method="post" action="<?= h(url('settings')) ?>" class="form">
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
            <option value="<?= h($v) ?>"<?= $current('volume_decimals') === $v ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint" id="hint-volume">للعرض فقط. الحساب الداخلي والقيمة المالية من الحجم الدقيق دائمًا.</p>
      </div>
    </div>
    <div class="field field-check">
      <input type="checkbox" id="volume_pad" name="volume_pad" value="1"<?= $current('volume_pad') === '1' ? ' checked' : '' ?>>
      <label for="volume_pad">إظهار الأصفار في آخر الحجم عند اختيار عدد خانات ثابت (مثل 0.150)</label>
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
    </div>
  </form>
</section>

<section class="section">
  <h2>تغيير كلمة المرور</h2>
  <?= errors_summary($pwErrors) ?>
  <form method="post" action="<?= h(url('settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <input type="text" name="username" value="<?= h(current_username()) ?>" autocomplete="username" hidden>
    <div class="field">
      <label for="current_password">كلمة المرور الحالية</label>
      <input type="password" id="current_password" name="current_password" autocomplete="current-password" required<?= field_attrs($pwErrors, 'current_password') ?>>
      <?= field_error($pwErrors, 'current_password') ?>
    </div>
    <div class="field">
      <label for="new_password">كلمة المرور الجديدة</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="10" required<?= field_attrs($pwErrors, 'new_password', 'hint-newpw') ?>>
      <p class="hint" id="hint-newpw">10 أحرف على الأقل. ستنتهي الجلسات المفتوحة على الأجهزة الأخرى.</p>
      <?= field_error($pwErrors, 'new_password') ?>
    </div>
    <div class="field">
      <label for="confirm_password">تأكيد كلمة المرور الجديدة</label>
      <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="10" required>
    </div>
    <div class="actions">
      <button type="submit" class="btn">تغيير كلمة المرور</button>
    </div>
  </form>
</section>

<p class="muted">إصدار النظام <?= h(digits(APP_VERSION)) ?>، إصدار قاعدة البيانات <?= h(fmt_int(schema_version($pdo))) ?>.</p>
<?php
render_footer();
