<?php
defined('APP_ROOT') || exit;

/*
 * نماذج السندات: قبض (collect) وصرف (pay) ومصروف (expense) وتحويل نقدية (cash_transfer).
 * $voucherKind يحدده index.php. الحفظ بنمط POST ثم تحويل إلى صفحة السند.
 */

$kind = isset(VOUCHER_COUNTERS[(string) $voucherKind]) ? (string) $voucherKind : 'collect';
acct_require(VOUCHER_PERMISSIONS[$kind]);
$pdo = db();
$title = VOUCHER_TITLES[$kind];
$partyKind = voucher_party_kind($kind);
$fields = ['party_id', 'cash_box_id', 'to_cash_box_id', 'category_id', 'amount', 'reference', 'notes', 'voucher_date', 'request_token', 'confirm_advance'];
$errors = [];
$warning = '';
$form = array_fill_keys($fields, '');
$form['party_id'] = input($_GET, 'party');
$form['request_token'] = new_request_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    acct_require(VOUCHER_PERMISSIONS[$kind]);
    $form = string_inputs($_POST, $fields);
    // القبض أكبر من رصيد العميل مسموح (دفعة مقدمة)، لكن بعد تنبيه وتأكيد صريح
    if ($kind === 'collect' && $form['confirm_advance'] !== '1') {
        $p = party_find($pdo, (int) $form['party_id']);
        [$amt] = parse_money_input($form['amount'], 'المبلغ');
        if ($p && $p['kind'] === 'customer' && $amt !== null && $amt > money_to_piasters($p['balance'])) {
            $warning = sprintf(
                'المبلغ %s أكبر من رصيد العميل «%s» (%s). الزيادة تُسجل دفعة مقدمة ويصبح له رصيد. ضع علامة التأكيد ثم احفظ.',
                fmt_piasters($amt), $p['name'], fmt_party_balance(money_to_piasters($p['balance']), 'customer')
            );
        }
    }
    if ($warning === '') {
        try {
            $r = record_voucher($pdo, current_user_id(), $kind, $form);
            flash('success', $r['duplicate']
                ? sprintf('هذا السند محفوظ سابقًا: %s. لم يُسجل مرة أخرى.', voucher_label($r))
                : sprintf('تم حفظ %s.', voucher_label($r)));
            redirect('voucher', ['id' => $r['id']]);
        } catch (ValidationException $e) {
            $errors = $e->errors;
            $form['request_token'] = refresh_token_if_needed($form, $errors);
        }
    }
}

$boxes = cash_boxes_for_user($pdo);
$boxOptions = array_map(fn ($b) => ['id' => $b['id'], 'name' => $b['name'] . ' (الرصيد ' . fmt_piasters(money_to_piasters($b['balance'])) . ')'], $boxes);
if ($form['cash_box_id'] === '' && $boxes) {
    $form['cash_box_id'] = (string) $boxes[0]['id'];
}
$parties = $partyKind ? parties_for_select($pdo, $partyKind) : [];
$partyOptions = array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'] . ' (' . fmt_party_balance(money_to_piasters($p['balance']), $partyKind) . ')'], $parties);
$categories = $kind === 'expense' ? expense_categories_all($pdo, true) : [];
$selectedParty = null;
foreach ($parties as $p) {
    if ((string) $p['id'] === $form['party_id']) {
        $selectedParty = $p;
    }
}

render_header($title, $kind);
?>
<div class="page-head">
  <h1><?= h($title) ?></h1>
  <div class="page-actions">
    <a class="btn btn-quiet" href="<?= h(url('vouchers', ['kind' => $kind])) ?>">سجل السندات</a>
  </div>
</div>

<?= errors_summary($errors) ?>
<?php if ($warning !== ''): ?>
  <div class="alert alert-warning" role="alert"><?= h($warning) ?></div>
<?php endif; ?>

<?php if (!$boxes): ?>
  <p class="empty">لا توجد خزنة نشطة. <a href="<?= h(url('cash_boxes')) ?>">أضف خزنة أولًا</a>.</p>
<?php elseif ($partyKind && !$parties): ?>
  <p class="empty">لا يوجد <?= $partyKind === 'customer' ? 'عملاء' : 'موردون' ?> بعد. <a href="<?= h(url('parties', ['kind' => $partyKind])) ?>">أضف حسابًا أولًا</a>.</p>
<?php else: ?>
<form method="post" action="<?= h(url($kind)) ?>" class="form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="request_token" value="<?= h($form['request_token']) ?>">

  <?php if ($partyKind): ?>
  <div class="field">
    <label for="party_id"><?= $partyKind === 'customer' ? 'العميل' : 'المورد' ?></label>
    <select id="party_id" name="party_id" required<?= field_attrs($errors, 'party_id', 'party-hint') ?>>
      <?= options_html($partyOptions, $form['party_id'], $partyKind === 'customer' ? 'اختر العميل' : 'اختر المورد') ?>
    </select>
    <p class="hint" id="party-hint">
      <?php if ($selectedParty): ?>
        الرصيد الحالي: <?= h(fmt_party_balance(money_to_piasters($selectedParty['balance']), $partyKind)) ?>.
      <?php endif; ?>
      الرصيد بجوار كل اسم. <?= $kind === 'collect' ? 'القبض ينقص ما على العميل.' : 'الصرف ينقص ما للمورد علينا.' ?>
    </p>
    <?= field_error($errors, 'party_id') ?>
  </div>
  <?php endif; ?>

  <?php if ($kind === 'expense'): ?>
  <div class="field">
    <label for="category_id">التصنيف</label>
    <select id="category_id" name="category_id" required<?= field_attrs($errors, 'category_id') ?>>
      <?= options_html($categories, $form['category_id'], 'اختر التصنيف') ?>
    </select>
    <?= field_error($errors, 'category_id') ?>
  </div>
  <?php endif; ?>

  <div class="field">
    <label for="cash_box_id"><?= $kind === 'cash_transfer' ? 'من خزنة' : 'الخزنة' ?></label>
    <select id="cash_box_id" name="cash_box_id" required<?= field_attrs($errors, 'cash_box_id') ?>>
      <?= options_html($boxOptions, $form['cash_box_id']) ?>
    </select>
    <?= field_error($errors, 'cash_box_id') ?>
  </div>

  <?php if ($kind === 'cash_transfer'): ?>
  <div class="field">
    <label for="to_cash_box_id">إلى خزنة</label>
    <select id="to_cash_box_id" name="to_cash_box_id" required<?= field_attrs($errors, 'to_cash_box_id') ?>>
      <?= options_html($boxOptions, $form['to_cash_box_id'], 'اختر الخزنة') ?>
    </select>
    <?= field_error($errors, 'to_cash_box_id') ?>
  </div>
  <?php endif; ?>

  <div class="field">
    <label for="amount">المبلغ (<?= h(app_setting('currency')) ?>)</label>
    <input type="text" inputmode="decimal" autocomplete="off" id="amount" name="amount" maxlength="20" required value="<?= h($form['amount']) ?>"<?= field_attrs($errors, 'amount', 'amount-hint') ?>>
    <p class="hint" id="amount-hint">بالجنيه، وللقروش استخدم النقطة، مثل <?= h(digits('1500.50')) ?></p>
    <?= field_error($errors, 'amount') ?>
  </div>

  <?php if ($kind === 'collect' && $warning !== ''): ?>
  <div class="field field-check">
    <input type="checkbox" id="confirm_advance" name="confirm_advance" value="1">
    <label for="confirm_advance">أؤكد تسجيل الزيادة دفعة مقدمة للعميل</label>
  </div>
  <?php endif; ?>

  <?php if (acct_can('manual_date')): ?>
  <div class="field">
    <label for="voucher_date">التاريخ <span class="optional">(اتركه فارغًا لتاريخ اليوم)</span></label>
    <input type="date" id="voucher_date" name="voucher_date" max="<?= h(date('Y-m-d')) ?>" value="<?= h($form['voucher_date']) ?>"<?= field_attrs($errors, 'voucher_date') ?>>
    <?= field_error($errors, 'voucher_date') ?>
  </div>
  <?php endif; ?>

  <div class="field">
    <label for="reference">المرجع <span class="optional">(اختياري، مثل رقم شيك أو إيصال)</span></label>
    <input type="text" id="reference" name="reference" maxlength="120" value="<?= h($form['reference']) ?>"<?= field_attrs($errors, 'reference') ?>>
    <?= field_error($errors, 'reference') ?>
  </div>

  <div class="field">
    <label for="notes">البيان<?= $kind === 'expense' ? '' : ' <span class="optional">(اختياري)</span>' ?></label>
    <textarea id="notes" name="notes" maxlength="1000" rows="2"<?= $kind === 'expense' ? ' required' : '' ?><?= field_attrs($errors, 'notes') ?>><?= h($form['notes']) ?></textarea>
    <?= field_error($errors, 'notes') ?>
  </div>
  <?= field_error($errors, 'request_token') ?>
  <?= field_error($errors, 'form') ?>

  <div class="actions">
    <button type="submit" class="btn btn-primary" data-busy-text="جارٍ الحفظ">حفظ <?= h(VOUCHER_KIND_LABELS[$kind]) ?></button>
  </div>
</form>
<?php endif; ?>
<?php
render_footer();
