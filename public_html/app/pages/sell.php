<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$fields = ['wood_type_id', 'item_id', 'quantity', 'price', 'party_name', 'notes', 'request_token'];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$form = $posted ? string_inputs($_POST, $fields) : [
    'wood_type_id' => input($_GET, 'type'),
    'item_id' => input($_GET, 'item'),
    'quantity' => '', 'price' => '', 'party_name' => '', 'notes' => '',
    'request_token' => new_request_token(),
];
$review = null;

if ($posted) {
    verify_csrf();
    $action = input($_POST, 'action');
    try {
        if ($action === 'review') {
            $review = validate_sale_input($pdo, $form);
        } elseif ($action === 'confirm') {
            $result = record_sale($pdo, current_user_id(), $form);
            if ($result['duplicate']) {
                flash('warning', sprintf('عملية البيع هذه سُجلت من قبل برقم %s ولم تُخصم مرة ثانية.', fmt_int($result['id'])));
            } else {
                flash('success', sprintf('تم تسجيل البيع رقم %s وخصم الكمية من المخزون.', fmt_int($result['id'])));
            }
            redirect('sell', ['done' => $result['id']]);
        } elseif ($action !== 'edit') {
            render_simple_error('إجراء غير معروف.', 400);
        }
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
    if (!is_request_token($form['request_token'])) {
        $form['request_token'] = new_request_token();
    }
}

/* ---------- صفحة المراجعة قبل التأكيد ---------- */
if ($review) {
    $item = $review['item'];
    $after = (int) $item['qty_on_hand'] - $review['quantity'];
    render_header('مراجعة البيع', 'sell');
    ?>
<h1>مراجعة البيع</h1>
<p>راجع البيانات ثم اضغط «تأكيد البيع». لم يُحفظ شيء حتى الآن.</p>
<section class="review" aria-label="ملخص البيع">
  <dl class="facts facts-wide">
    <div><dt>نوع الخشب</dt><dd><?= h($item['wood_type_name']) ?></dd></div>
    <div><dt>المقاس</dt><dd><?= fmt_size($item) ?></dd></div>
    <div><dt>العدد المتاح الآن</dt><dd><?= fmt_int((int) $item['qty_on_hand']) ?> قطعة</dd></div>
    <div><dt>عدد القطع المباعة</dt><dd><?= fmt_int($review['quantity']) ?> قطعة</dd></div>
    <div><dt>حجم القطعة</dt><dd><?= fmt_volume($review['piece_m3']) ?> م³</dd></div>
    <div><dt>حجم الكمية المباعة</dt><dd><?= fmt_volume($review['total_m3']) ?> م³</dd></div>
    <div><dt>سعر المتر المكعب</dt><dd><?= fmt_money_currency($review['price']) ?></dd></div>
    <div class="fact-strong"><dt>إجمالي القيمة</dt><dd><?= fmt_money_currency($review['amount']) ?></dd></div>
    <div><dt>الرصيد بعد البيع</dt><dd><?= fmt_int($after) ?> قطعة</dd></div>
    <?php if ($review['party_name'] !== ''): ?><div><dt>العميل</dt><dd><?= h($review['party_name']) ?></dd></div><?php endif; ?>
    <?php if ($review['notes'] !== ''): ?><div><dt>ملاحظات</dt><dd class="pre"><?= h($review['notes']) ?></dd></div><?php endif; ?>
  </dl>
  <p class="muted">لا تُضاف ضرائب أو شحن أو خصومات. الرصيد يُتحقق منه مرة أخرى لحظة التأكيد.</p>
  <form method="post" action="<?= h(url('sell')) ?>" class="actions">
    <?= csrf_field() ?>
    <?php foreach ($fields as $f): ?>
      <input type="hidden" name="<?= h($f) ?>" value="<?= h($form[$f]) ?>">
    <?php endforeach; ?>
    <button type="submit" name="action" value="confirm" class="btn btn-primary" data-busy-text="جارٍ الحفظ">تأكيد البيع</button>
    <button type="submit" name="action" value="edit" class="btn">تعديل</button>
  </form>
</section>
<?php
    render_footer();
    exit;
}

/* ---------- نموذج البيع ---------- */
$types = $pdo->query(
    'SELECT DISTINCT t.id, t.name FROM wood_types t JOIN items i ON i.wood_type_id = t.id
     WHERE i.qty_on_hand > 0 ORDER BY t.name, t.id'
)->fetchAll();
$items = $pdo->query(
    'SELECT i.*, t.name AS wood_type_name FROM items i JOIN wood_types t ON t.id = i.wood_type_id
     WHERE i.qty_on_hand > 0 ORDER BY t.name, t.id, i.thickness_um, i.width_um, i.length_um'
)->fetchAll();

$done = (int) input($_GET, 'done') > 0 ? find_movement($pdo, (int) input($_GET, 'done')) : null;
if ($done && $done['kind'] !== 'sale') {
    $done = null;
}

render_header('تسجيل بيع', 'sell');
?>
<h1>تسجيل بيع</h1>

<?php if ($done): ?>
  <section class="confirm-box" aria-labelledby="done-title">
    <h2 id="done-title">آخر بيع مسجل: رقم <?= fmt_int((int) $done['id']) ?></h2>
    <dl class="facts">
      <div><dt>النوع</dt><dd><?= h($done['wood_type_name']) ?></dd></div>
      <div><dt>المقاس</dt><dd><?= fmt_size(movement_size_item($done)) ?></dd></div>
      <div><dt>العدد</dt><dd><?= fmt_int((int) $done['quantity']) ?> قطعة</dd></div>
      <div><dt>الحجم</dt><dd><?= fmt_volume($done['total_volume_m3']) ?> م³</dd></div>
      <div><dt>الإجمالي</dt><dd><?= fmt_money_currency($done['total_amount'], $done['currency']) ?></dd></div>
      <div><dt>الرصيد بعد البيع</dt><dd><?= fmt_int((int) $done['balance_after']) ?> قطعة</dd></div>
    </dl>
    <p class="row-actions">
      <a class="btn" href="<?= h(url('receipt', ['id' => (int) $done['id']])) ?>">عرض الإيصال وطباعته</a>
      <a href="<?= h(url('movement', ['id' => (int) $done['id']])) ?>">تفاصيل الحركة</a>
    </p>
  </section>
<?php endif; ?>

<?php if (!$items && !$errors): ?>
  <p class="empty">لا توجد أصناف متاحة للبيع. <a href="<?= h(url('receive')) ?>">أضف وارد</a> أولًا.</p>
<?php else: ?>

<?= errors_summary($errors) ?>

<form method="post" action="<?= h(url('sell')) ?>" class="form" id="sell-form" novalidate data-calc="sell">
  <?= csrf_field() ?>
  <input type="hidden" name="request_token" value="<?= h($form['request_token']) ?>">

  <div class="field">
    <label for="sale_type">١. نوع الخشب</label>
    <select id="sale_type" name="wood_type_id">
      <option value="">كل الأنواع</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= (int) $t['id'] ?>"<?= (string) $t['id'] === $form['wood_type_id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="item_id">٢. المقاس</label>
    <select id="item_id" name="item_id" required<?= field_attrs($errors, 'item_id') ?>>
      <option value="">اختر المقاس</option>
      <?php foreach ($items as $it): ?>
        <option value="<?= (int) $it['id'] ?>"
          data-type="<?= (int) $it['wood_type_id'] ?>"
          data-qty="<?= (int) $it['qty_on_hand'] ?>"
          data-piece-um3="<?= h(m3_to_um3($it['piece_volume_m3'])) ?>"
          <?= (string) $it['id'] === $form['item_id'] ? ' selected' : '' ?>><?= h($it['wood_type_name']) ?>: <?= fmt_size($it) ?></option>
      <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'item_id') ?>
  </div>

  <section class="calc-panel" aria-live="polite" aria-labelledby="stock-title">
    <h2 id="stock-title">٣. المتاح من هذا المقاس</h2>
    <dl class="facts">
      <div><dt>العدد المتاح</dt><dd><span data-out="available">-</span> قطعة</dd></div>
      <div><dt>الحجم المتاح</dt><dd><span data-out="available_volume">-</span> م³</dd></div>
      <div><dt>حجم القطعة</dt><dd><span data-out="piece">-</span> م³</dd></div>
    </dl>
  </section>

  <div class="field-row">
    <div class="field field-narrow">
      <label for="quantity">٤. عدد القطع</label>
      <input type="text" inputmode="numeric" id="quantity" name="quantity" value="<?= h($form['quantity']) ?>" autocomplete="off" required maxlength="12"<?= field_attrs($errors, 'quantity') ?>>
      <?= field_error($errors, 'quantity') ?>
    </div>
    <div class="field field-narrow">
      <label for="price">٥. سعر المتر المكعب (<?= h(app_setting('currency')) ?>)</label>
      <input type="text" inputmode="decimal" id="price" name="price" value="<?= h($form['price']) ?>" autocomplete="off" required maxlength="20"<?= field_attrs($errors, 'price') ?>>
      <?= field_error($errors, 'price') ?>
    </div>
  </div>

  <section class="calc-panel" aria-live="polite" aria-labelledby="calc-title">
    <h2 id="calc-title">٦. الحساب</h2>
    <dl class="facts">
      <div><dt>حجم الكمية</dt><dd><span data-out="total">-</span> م³</dd></div>
      <div class="fact-strong"><dt>القيمة</dt><dd><span data-out="amount">-</span> <?= h(app_setting('currency')) ?></dd></div>
      <div><dt>الرصيد المتوقع بعد البيع</dt><dd><span data-out="after">-</span> قطعة</dd></div>
    </dl>
    <p class="calc-error" data-out="error" hidden></p>
  </section>

  <div class="field">
    <label for="party_name">اسم العميل <span class="optional">(اختياري)</span></label>
    <input type="text" id="party_name" name="party_name" value="<?= h($form['party_name']) ?>" maxlength="120"<?= field_attrs($errors, 'party_name') ?>>
    <?= field_error($errors, 'party_name') ?>
  </div>
  <div class="field">
    <label for="notes">ملاحظات <span class="optional">(اختياري)</span></label>
    <textarea id="notes" name="notes" rows="3" maxlength="1000"<?= field_attrs($errors, 'notes') ?>><?= h($form['notes']) ?></textarea>
    <?= field_error($errors, 'notes') ?>
  </div>

  <div class="actions">
    <button type="submit" name="action" value="review" class="btn btn-primary">٧. مراجعة وتأكيد</button>
  </div>
</form>
<?php endif; ?>
<?php
render_footer();
