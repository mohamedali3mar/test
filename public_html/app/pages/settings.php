<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$pwErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');

    if ($action === 'general') {
        $company = clean_text(input($_POST, 'company_name'));
        $currency = clean_text(input($_POST, 'currency'));
        if ($company === '' || mb_strlen($company) > 120) {
            $errors['company_name'] = 'اسم الشركة مطلوب (120 حرفًا على الأكثر).';
        }
        if ($currency === '' || mb_strlen($currency) > 40) {
            $errors['currency'] = 'اسم العملة مطلوب (40 حرفًا على الأكثر).';
        }
        $units = [];
        foreach (DIMENSIONS as $key => $label) {
            $u = input($_POST, 'unit_' . $key);
            if (!is_unit($u)) {
                $errors['unit_' . $key] = 'اختر وحدة صحيحة لـ' . $label . '.';
            }
            $units['unit_' . $key] = $u;
        }
        $digits = input($_POST, 'digits');
        if (!in_array($digits, ['western', 'arabic'], true)) {
            $errors['digits'] = 'اختر شكل الأرقام.';
        }
        if (!$errors) {
            db_transaction($pdo, function (PDO $pdo) use ($company, $currency, $units, $digits) {
                save_setting($pdo, 'company_name', $company);
                save_setting($pdo, 'currency', $currency);
                foreach ($units as $k => $u) {
                    save_setting($pdo, $k, $u);
                }
                save_setting($pdo, 'digits', $digits);
            });
            flash('success', 'تم حفظ الإعدادات. تغيير العملة لا يغير المبيعات السابقة.');
            redirect('settings');
        }
    } elseif ($action === 'password') {
        $current = input($_POST, 'current_password');
        $new = input($_POST, 'new_password');
        $confirm = input($_POST, 'confirm_password');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $hash = (string) $stmt->fetchColumn();
        if (!password_verify($current, $hash)) {
            $pwErrors['current_password'] = 'كلمة المرور الحالية غير صحيحة.';
        } elseif ($problem = password_problem($new, $confirm, current_username())) {
            $pwErrors['new_password'] = $problem;
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
            session_regenerate_id(true);
            flash('success', 'تم تغيير كلمة المرور.');
            redirect('settings');
        }
    } else {
        render_simple_error('إجراء غير معروف.', 400);
    }
}

$values = [
    'company_name' => $errors ? input($_POST, 'company_name') : app_setting('company_name'),
    'currency' => $errors ? input($_POST, 'currency') : app_setting('currency'),
    'digits' => app_setting('digits'),
];

render_header('الإعدادات', 'settings');
?>
<h1>الإعدادات</h1>

<section class="section">
  <h2>بيانات عامة</h2>
  <?= errors_summary($errors) ?>
  <form method="post" action="<?= h(url('settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="general">
    <div class="field">
      <label for="company_name">اسم الشركة (يظهر في الإيصال)</label>
      <input type="text" id="company_name" name="company_name" value="<?= h($values['company_name']) ?>" maxlength="120" required<?= field_attrs($errors, 'company_name') ?>>
      <?= field_error($errors, 'company_name') ?>
    </div>
    <div class="field">
      <label for="currency">العملة</label>
      <input type="text" id="currency" name="currency" value="<?= h($values['currency']) ?>" maxlength="40" required<?= field_attrs($errors, 'currency') ?>>
      <?= field_error($errors, 'currency') ?>
      <p class="hint">تُحفظ العملة مع كل عملية بيع، فلا تتغير الإيصالات القديمة عند تعديلها.</p>
    </div>
    <fieldset>
      <legend>الوحدات الافتراضية في نموذج الوارد</legend>
      <p class="hint">إذا غيّر المستخدم الوحدة أثناء الإدخال، يتذكر المتصفح آخر اختيار له.</p>
      <div class="field-row">
        <?php foreach (DIMENSIONS as $key => $label): $sel = app_setting('unit_' . $key); ?>
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
    <div class="field">
      <label for="digits">شكل الأرقام في العرض</label>
      <select id="digits" name="digits">
        <option value="western"<?= $values['digits'] === 'western' ? ' selected' : '' ?>>0123456789</option>
        <option value="arabic"<?= $values['digits'] === 'arabic' ? ' selected' : '' ?>>٠١٢٣٤٥٦٧٨٩</option>
      </select>
      <p class="hint">الإدخال يقبل الشكلين دائمًا.</p>
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
      <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    </div>
    <div class="field">
      <label for="new_password">كلمة المرور الجديدة</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="10" required>
      <p class="hint">10 أحرف على الأقل.</p>
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
<?php
render_footer();
