<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$fields = ['wood_type_id', 'width', 'width_unit', 'thickness', 'thickness_unit', 'length', 'length_unit',
    'quantity', 'party_name', 'reference', 'notes', 'request_token'];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$form = $posted ? string_inputs($_POST, $fields) : [
    'wood_type_id' => input($_GET, 'type'),
    'width' => '', 'width_unit' => app_setting('unit_width'),
    'thickness' => '', 'thickness_unit' => app_setting('unit_thickness'),
    'length' => '', 'length_unit' => app_setting('unit_length'),
    'quantity' => '', 'party_name' => '', 'reference' => '', 'notes' => '',
    'request_token' => new_request_token(),
];

if ($posted) {
    verify_csrf();
    try {
        $result = record_receipt($pdo, current_user_id(), $form);
        if ($result['duplicate']) {
            flash('warning', sprintf('هذه العملية سُجلت من قبل برقم %s ولم تُضف مرة ثانية.', fmt_int($result['id'])));
        } else {
            flash('success', sprintf('تم تسجيل الوارد رقم %s وإضافته إلى المخزون.', fmt_int($result['id'])));
        }
        redirect('receive', ['done' => $result['id']]);
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
    if (!is_request_token($form['request_token'])) {
        $form['request_token'] = new_request_token();
    }
}

$types = all_wood_types($pdo);
$done = (int) input($_GET, 'done') > 0 ? find_movement($pdo, (int) input($_GET, 'done')) : null;
if ($done && $done['kind'] !== 'in') {
    $done = null;
}

// الأصناف الحالية لعرض رصيد الصنف الذي ستضاف إليه الكمية أثناء الإدخال
$known = [];
foreach ($pdo->query('SELECT wood_type_id, width_um, thickness_um, length_um, qty_on_hand FROM items') as $r) {
    $known[$r['wood_type_id'] . ':' . $r['width_um'] . ':' . $r['thickness_um'] . ':' . $r['length_um']] = (int) $r['qty_on_hand'];
}

render_header('إضافة وارد', 'receive');
?>
<h1>إضافة وارد</h1>

<?php if ($done): ?>
  <section class="confirm-box" aria-labelledby="done-title">
    <h2 id="done-title">آخر وارد مسجل: رقم <?= fmt_int((int) $done['id']) ?></h2>
    <dl class="facts">
      <div><dt>النوع</dt><dd><?= h($done['wood_type_name']) ?></dd></div>
      <div><dt>المقاس</dt><dd><?= fmt_size(movement_size_item($done)) ?></dd></div>
      <div><dt>العدد</dt><dd><?= fmt_int((int) $done['quantity']) ?> قطعة</dd></div>
      <div><dt>الحجم</dt><dd><?= fmt_volume($done['total_volume_m3']) ?> م³</dd></div>
      <div><dt>الرصيد بعد الإضافة</dt><dd><?= fmt_int((int) $done['balance_after']) ?> قطعة</dd></div>
      <div><dt>التاريخ</dt><dd><?= fmt_datetime($done['created_at']) ?></dd></div>
    </dl>
    <p><a href="<?= h(url('movement', ['id' => (int) $done['id']])) ?>">تفاصيل الحركة</a></p>
  </section>
<?php endif; ?>

<?php if (!$types): ?>
  <p class="empty">لا توجد أنواع خشب بعد. <a href="<?= h(url('types')) ?>">أضف نوعًا أولًا</a>.</p>
<?php else: ?>

<?= errors_summary($errors) ?>

<form method="post" action="<?= h(url('receive')) ?>" class="form" id="receive-form" novalidate data-calc="receive">
  <?= csrf_field() ?>
  <input type="hidden" name="request_token" value="<?= h($form['request_token']) ?>">

  <div class="field">
    <label for="wood_type_id">نوع الخشب</label>
    <select id="wood_type_id" name="wood_type_id" required<?= field_attrs($errors, 'wood_type_id') ?>>
      <option value="">اختر النوع</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= (int) $t['id'] ?>"<?= (string) $t['id'] === $form['wood_type_id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'wood_type_id') ?>
    <p class="hint"><a href="<?= h(url('types')) ?>">إضافة نوع جديد</a></p>
  </div>

  <fieldset class="dims">
    <legend>المقاس</legend>
    <?php foreach (DIMENSIONS as $key => $label): ?>
      <div class="field">
        <label for="<?= h($key) ?>"><?= h($label) ?></label>
        <div class="input-unit">
          <input type="text" inputmode="decimal" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($form[$key]) ?>" autocomplete="off" required maxlength="20"<?= field_attrs($errors, $key) ?>>
          <?= unit_select($key . '_unit', $form[$key . '_unit'], 'وحدة ' . $label, $posted) ?>
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

  <section class="calc-panel" aria-live="polite" aria-labelledby="calc-title">
    <h2 id="calc-title">الحساب</h2>
    <dl class="facts">
      <div><dt>الأبعاد بالمتر</dt><dd data-out="meters">-</dd></div>
      <div><dt>حجم القطعة</dt><dd><span data-out="piece">-</span> م³</dd></div>
      <div><dt>حجم الكمية</dt><dd><span data-out="total">-</span> م³</dd></div>
      <div><dt>الرصيد الحالي لهذا المقاس</dt><dd data-out="existing">-</dd></div>
    </dl>
    <p class="calc-error" data-out="error" hidden></p>
    <p class="hint">الحساب النهائي يُعاد على الخادم عند الحفظ.</p>
  </section>

  <div class="field">
    <label for="party_name">اسم المورد <span class="optional">(اختياري)</span></label>
    <input type="text" id="party_name" name="party_name" value="<?= h($form['party_name']) ?>" maxlength="120"<?= field_attrs($errors, 'party_name') ?>>
    <?= field_error($errors, 'party_name') ?>
  </div>
  <div class="field">
    <label for="reference">مرجع التوريد <span class="optional">(اختياري)</span></label>
    <input type="text" id="reference" name="reference" value="<?= h($form['reference']) ?>" maxlength="120"<?= field_attrs($errors, 'reference') ?>>
    <?= field_error($errors, 'reference') ?>
  </div>
  <div class="field">
    <label for="notes">ملاحظات <span class="optional">(اختياري)</span></label>
    <textarea id="notes" name="notes" rows="3" maxlength="1000"<?= field_attrs($errors, 'notes') ?>><?= h($form['notes']) ?></textarea>
    <?= field_error($errors, 'notes') ?>
    <p class="hint">لتسجيل الرصيد الافتتاحي اكتب في الملاحظات: رصيد افتتاحي.</p>
  </div>

  <div class="actions">
    <button type="submit" class="btn btn-primary" data-busy-text="جارٍ الحفظ">حفظ الوارد</button>
  </div>
</form>
<script type="application/json" id="known-items"><?= json_encode($known, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<?php
render_footer();
