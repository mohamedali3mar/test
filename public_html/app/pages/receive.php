<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$fields = ['warehouse_id', 'wood_type_id', 'width', 'width_unit', 'thickness', 'thickness_unit', 'length', 'length_unit',
    'quantity', 'party_name', 'reference', 'notes', 'request_token', 'cost_per_m3', ...PAYMENT_FORM_FIELDS];
$boxes = cash_boxes_for_user($pdo);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

if (input($_GET, 'clear') === '1') {
    unset($_SESSION['receive_sticky']);
    redirect('receive');
}

// الحقول الثابتة من آخر وارد (المخزن والنوع والوحدات والمورد والمرجع) لتقليل الإدخال المتكرر
$sticky = is_array($_SESSION['receive_sticky'] ?? null) ? $_SESSION['receive_sticky'] : [];
$defaults = [
    'warehouse_id' => input($_GET, 'warehouse', (string) ($sticky['warehouse_id'] ?? '')),
    'wood_type_id' => input($_GET, 'type', (string) ($sticky['wood_type_id'] ?? '')),
    'width' => '', 'thickness' => '', 'length' => '', 'quantity' => '', 'notes' => '',
    'width_unit' => (string) ($sticky['width_unit'] ?? app_setting('unit_width')),
    'thickness_unit' => (string) ($sticky['thickness_unit'] ?? app_setting('unit_thickness')),
    'length_unit' => (string) ($sticky['length_unit'] ?? app_setting('unit_length')),
    'party_name' => (string) ($sticky['party_name'] ?? ''),
    'reference' => (string) ($sticky['reference'] ?? ''),
    'request_token' => new_request_token(),
    'cost_per_m3' => '',
] + payment_form_defaults($boxes);
$form = $posted ? string_inputs($_POST, $fields) : $defaults;

if ($posted) {
    verify_csrf();
    try {
        $result = record_receipt($pdo, current_user_id(), $form);
        $_SESSION['receive_sticky'] = [
            'warehouse_id' => $form['warehouse_id'], 'wood_type_id' => $form['wood_type_id'],
            'width_unit' => $form['width_unit'], 'thickness_unit' => $form['thickness_unit'], 'length_unit' => $form['length_unit'],
            'party_name' => clean_text($form['party_name']), 'reference' => clean_text($form['reference']),
        ];
        flash($result['duplicate'] ? 'warning' : 'success', $result['duplicate']
            ? sprintf('هذا الوارد سُجل من قبل (وارد رقم %s) ولم يُضف مرة ثانية.', fmt_int($result['doc_no']))
            : sprintf('تم تسجيل وارد رقم %s وإضافته إلى المخزون.', fmt_int($result['doc_no'])));
        redirect('receive', ['done' => $result['id']]);
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
    $form['request_token'] = refresh_token_if_needed($form, $errors);
}

$types = catalog_all($pdo, 'type');
$scope = allowed_branch_id($pdo);
$warehouses = scoped_warehouses($pdo, $scope);
$done = (int) input($_GET, 'done') > 0 ? find_document_scoped($pdo, (int) input($_GET, 'done')) : null;
if ($done && $done['kind'] !== 'in') {
    $done = null;
}
$doneLine = $done ? (document_lines($pdo, (int) $done['id'])[0] ?? null) : null;
$unitsPosted = $posted || $sticky !== [];

render_header('إضافة وارد', 'receive');
?>
<div class="page-head">
  <h1>إضافة وارد</h1>
  <?php if ($sticky): ?><div class="page-actions"><a class="btn btn-quiet" href="<?= h(url('receive', ['clear' => '1'])) ?>">نموذج جديد فارغ</a></div><?php endif; ?>
</div>

<?php if ($done && $doneLine): ?>
  <section class="confirm-box" aria-labelledby="done-title">
    <h2 id="done-title">تم الحفظ: <?= h(doc_label($done)) ?></h2>
    <dl class="facts">
      <div><dt>المخزن</dt><dd><?= h($done['warehouse_name']) ?></dd></div>
      <div><dt>النوع</dt><dd><?= h($doneLine['wood_type_name']) ?></dd></div>
      <div><dt>المقاس</dt><dd><?= h(fmt_size($doneLine)) ?></dd></div>
      <div><dt>العدد</dt><dd><?= h(fmt_int((int) $doneLine['quantity'])) ?> قطعة</dd></div>
      <div><dt>الحجم</dt><dd><?= h(fmt_volume($doneLine['total_volume_m3'])) ?> م³</dd></div>
      <div><dt>الرصيد بعد الإضافة</dt><dd><?= h(fmt_int((int) $doneLine['balance_after'])) ?> قطعة</dd></div>
      <?php if ($done['payment_type'] !== null): $donePaid = money_to_piasters((string) $done['paid_amount']); ?>
        <div><dt>قيمة الشراء</dt><dd><?= h(fmt_money_currency((string) $done['total_cost'])) ?></dd></div>
        <div><dt>طريقة الدفع</dt><dd><?= h(PAYMENT_TYPE_LABELS[$done['payment_type']] ?? '') ?></dd></div>
        <div><dt>المدفوع</dt><dd><?= h(fmt_piasters($donePaid)) ?></dd></div>
        <div><dt>المتبقي</dt><dd><?= h(fmt_piasters(money_to_piasters((string) $done['total_cost']) - $donePaid)) ?></dd></div>
      <?php endif; ?>
    </dl>
    <p class="row-actions">
      <a href="<?= h(url('document', ['id' => (int) $done['id']])) ?>">تفاصيل المستند</a>
      <a href="<?= h(url('print', ['id' => (int) $done['id']])) ?>">طباعة إذن الوارد</a>
    </p>
  </section>
<?php endif; ?>

<?php if (!$types || !$warehouses): ?>
  <p class="empty">
    <?php if (!$warehouses && $scope !== null): ?>لا توجد مخازن في فرعك. اطلب من المدير إضافة مخزن للفرع.<?php elseif (!$warehouses): ?>لا توجد مخازن. <a href="<?= h(url('warehouses')) ?>">أضف مخزنًا أولًا</a>.<?php endif; ?>
    <?php if (!$types): ?>لا توجد أنواع خشب بعد. <a href="<?= h(url('types')) ?>">أضف نوعًا أولًا</a>.<?php endif; ?>
  </p>
<?php else: ?>

<?= errors_summary($errors) ?>

<form method="post" action="<?= h(url('receive')) ?>" class="form" novalidate data-calc="receive">
  <?= csrf_field() ?>
  <input type="hidden" name="request_token" value="<?= h($form['request_token']) ?>">

  <div class="field-row">
    <div class="field">
      <label for="warehouse_id">المخزن</label>
      <?= warehouse_select('warehouse_id', 'warehouse_id', $warehouses, $form['warehouse_id'], $errors, 'اختر المخزن') ?>
      <?= field_error($errors, 'warehouse_id') ?>
    </div>
    <div class="field">
      <label for="wood_type_id">نوع الخشب</label>
      <select id="wood_type_id" name="wood_type_id" required<?= field_attrs($errors, 'wood_type_id', 'hint-type') ?>>
        <?= options_html($types, $form['wood_type_id'], 'اختر النوع') ?>
      </select>
      <p class="hint" id="hint-type"><a href="<?= h(url('types')) ?>">إضافة نوع جديد</a></p>
      <?= field_error($errors, 'wood_type_id') ?>
    </div>
  </div>

  <fieldset class="dims">
    <legend>المقاس</legend>
    <?php foreach (DIMENSIONS as $key => $label): ?>
      <div class="field">
        <label for="<?= h($key) ?>"><?= h($label) ?></label>
        <div class="input-unit">
          <input type="text" inputmode="decimal" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($form[$key]) ?>" autocomplete="off" required maxlength="20"<?= field_attrs($errors, $key) ?>>
          <?= unit_select($key . '_unit', $form[$key . '_unit'], 'وحدة ' . $label, $unitsPosted) ?>
        </div>
        <?= field_error($errors, $key) ?>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <div class="field field-narrow">
    <label for="quantity">عدد القطع</label>
    <input type="text" inputmode="numeric" id="quantity" name="quantity" value="<?= h($form['quantity']) ?>" autocomplete="off" required maxlength="12"<?= field_attrs($errors, 'quantity') ?>>
    <?= field_error($errors, 'quantity') ?>
  </div>

  <div class="field field-narrow">
    <label for="cost_per_m3">تكلفة المتر المكعب <span class="optional">(اختياري)</span></label>
    <input type="text" inputmode="decimal" id="cost_per_m3" name="cost_per_m3" value="<?= h($form['cost_per_m3']) ?>" autocomplete="off" maxlength="20"<?= field_attrs($errors, 'cost_per_m3', 'hint-cost') ?>>
    <p class="hint" id="hint-cost">بدون تكلفة يُقيَّم الوارد بمتوسط تكلفة الصنف الحالي. قيمة الشراء = حجم الكمية × تكلفة المتر، وتظهر في المراجعة على الخادم بعد الحفظ.</p>
    <?= field_error($errors, 'cost_per_m3') ?>
  </div>

  <section class="calc-panel" aria-labelledby="calc-title">
    <h2 id="calc-title">الحساب</h2>
    <dl class="facts">
      <div><dt>الأبعاد بالمتر</dt><dd data-out="meters">-</dd></div>
      <div><dt>حجم القطعة</dt><dd><span data-out="piece">-</span> م³</dd></div>
      <div><dt>حجم الكمية</dt><dd><span data-out="total">-</span> م³</dd></div>
      <div><dt>رصيد هذا المقاس في المخزن المختار</dt><dd data-out="existing">-</dd></div>
    </dl>
    <p class="calc-error" data-out="error" role="status" hidden></p>
    <p class="hint">يُعاد الحساب على الخادم عند الحفظ.</p>
  </section>

  <div class="field-row">
    <div class="field">
      <label for="party_name">اسم المورد بدون حساب <span class="optional">(اختياري)</span></label>
      <input type="text" id="party_name" name="party_name" value="<?= h($form['party_name']) ?>" maxlength="120"<?= field_attrs($errors, 'party_name') ?>>
      <?= field_error($errors, 'party_name') ?>
    </div>
    <div class="field">
      <label for="reference">مرجع التوريد <span class="optional">(اختياري)</span></label>
      <input type="text" id="reference" name="reference" value="<?= h($form['reference']) ?>" maxlength="120"<?= field_attrs($errors, 'reference') ?>>
      <?= field_error($errors, 'reference') ?>
    </div>
  </div>
  <?= render_payment_fields($form, $errors, 'supplier', parties_for_select($pdo, 'supplier'), $boxes, 'cost_per_m3') ?>
  <div class="field">
    <label for="notes">ملاحظات <span class="optional">(اختياري)</span></label>
    <textarea id="notes" name="notes" rows="3" maxlength="1000"<?= field_attrs($errors, 'notes', 'hint-notes') ?>><?= h($form['notes']) ?></textarea>
    <p class="hint" id="hint-notes">لتسجيل الرصيد الافتتاحي اكتب في الملاحظات: رصيد افتتاحي.</p>
    <?= field_error($errors, 'notes') ?>
  </div>

  <div class="actions">
    <button type="submit" class="btn btn-primary" data-busy-text="جارٍ الحفظ">حفظ الوارد</button>
  </div>
</form>
<?= stock_data_script(stock_payload($pdo, $scope)) ?>
<?php endif; ?>
<?php
render_footer();
