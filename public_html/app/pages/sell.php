<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$boxes = cash_boxes_for_user($pdo);
$form = $posted
    ? string_inputs($_POST, ['warehouse_id', 'party_name', 'notes', 'request_token', ...PAYMENT_FORM_FIELDS])
    : ['warehouse_id' => input($_GET, 'warehouse'), 'party_name' => '', 'notes' => '', 'request_token' => new_request_token()]
        + payment_form_defaults($boxes);
$form['lines'] = $posted ? form_lines($_POST['lines'] ?? [], LINE_FIELDS_SALE) : [];
$extraLines = 0;
$review = null;

if ($posted) {
    verify_csrf();
    $action = input($_POST, 'action');
    try {
        if ($action === 'review' || $action === 'confirm') {
            // إذا حُفظت هذه الفاتورة بالفعل (رجوع ثم إعادة إرسال) نعرض المحفوظة بدل مراجعة جديدة
            $saved = find_document_by_token($pdo, $form['request_token']);
            if ($saved && $action === 'review') {
                flash('warning', sprintf('هذه الفاتورة محفوظة بالفعل (%s).', doc_label($saved)));
                redirect('document', ['id' => (int) $saved['id']]);
            }
        }
        if ($action === 'review') {
            $review = validate_sale($pdo, $form, true);
        } elseif ($action === 'confirm') {
            $result = record_sale($pdo, current_user_id(), $form);
            flash($result['duplicate'] ? 'warning' : 'success', $result['duplicate']
                ? sprintf('هذه الفاتورة سُجلت من قبل (بيع رقم %s) ولم تُخصم مرة ثانية.', fmt_int($result['doc_no']))
                : sprintf('تم حفظ فاتورة بيع رقم %s وخصم الكميات من المخزون.', fmt_int($result['doc_no'])));
            redirect('sell', ['done' => $result['id']]);
        } elseif ($action === 'more_lines') {
            $extraLines = 3;
        } elseif ($action !== 'edit') {
            render_simple_error('إجراء غير معروف.', 400);
        }
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
    $form['request_token'] = refresh_token_if_needed($form, $errors);
}

/* ---------- صفحة المراجعة ---------- */
if ($review) {
    render_header('مراجعة فاتورة البيع', 'sell');
    $avail = current_stock($pdo, array_map(fn ($l) => (int) $l['item']['id'], $review['lines']), (int) $review['warehouse']['id']);
    $rp = $review['pay'];
    ?>
<h1>مراجعة فاتورة البيع</h1>
<p>راجع البيانات ثم اضغط «تأكيد البيع». لم يُحفظ شيء حتى الآن، والرصيد يُتحقق منه مرة أخرى لحظة التأكيد.</p>
<dl class="facts facts-wide">
  <div><dt>المخزن</dt><dd><?= h($review['warehouse']['name']) ?></dd></div>
  <div><dt>العميل</dt><dd><?= $review['party_name'] !== '' ? h($review['party_name']) : '<span class="muted">لم يُحدد</span>' ?><?= $rp['party'] ? '' : ' <span class="muted">(عميل نقدي بدون حساب)</span>' ?></dd></div>
  <div><dt>طريقة الدفع</dt><dd><?= h(PAYMENT_TYPE_LABELS[$rp['payment_type']]) ?></dd></div>
  <div><dt>المدفوع</dt><dd><?= h(fmt_piasters($rp['paid'])) ?><?= $rp['cash_box'] ? ' (' . h($rp['cash_box']['name']) . ')' : '' ?></dd></div>
  <div><dt>المتبقي</dt><dd><?= h(fmt_piasters($rp['remaining'])) ?></dd></div>
  <?php if ($rp['party']): $oldBal = money_to_piasters((string) $rp['party']['balance']); ?>
    <div><dt>رصيد العميل الحالي</dt><dd><?= h(fmt_party_balance($oldBal, 'customer')) ?></dd></div>
    <div class="fact-strong"><dt>رصيد العميل بعد الفاتورة</dt><dd><?= h(fmt_party_balance($oldBal + $rp['remaining'], 'customer')) ?></dd></div>
  <?php endif; ?>
  <?php if (substr($rp['doc_date'], 0, 10) !== date('Y-m-d')): ?><div><dt>تاريخ الفاتورة</dt><dd><?= h(fmt_datetime($rp['doc_date'])) ?></dd></div><?php endif; ?>
  <?php if ($review['notes'] !== ''): ?><div><dt>ملاحظات</dt><dd class="pre"><?= h($review['notes']) ?></dd></div><?php endif; ?>
</dl>
<div class="table-wrap table-stack">
  <table>
    <caption class="visually-hidden">أسطر الفاتورة</caption>
    <thead>
      <tr>
        <th scope="col" class="num">#</th>
        <th scope="col">النوع</th>
        <th scope="col">المقاس</th>
        <th scope="col" class="num">العدد</th>
        <th scope="col" class="num">الحجم (م³)</th>
        <th scope="col" class="num">سعر المتر (<?= h(app_setting('currency')) ?>)</th>
        <th scope="col" class="num">القيمة</th>
        <th scope="col" class="num">المتاح الآن</th>
        <th scope="col" class="num">بعد البيع</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($review['lines'] as $l): $have = (int) ($avail[(int) $l['item']['id']] ?? 0); ?>
      <tr>
        <td class="num" data-label="<?= h('السطر') ?>"><?= h(fmt_int($l['line_no'])) ?></td>
        <td data-label="<?= h('النوع') ?>"><?= h($l['item']['wood_type_name']) ?></td>
        <td data-label="<?= h('المقاس') ?>"><?= h(fmt_size($l['item'])) ?></td>
        <td class="num" data-label="<?= h('العدد') ?>"><?= h(fmt_int($l['quantity'])) ?></td>
        <td class="num" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume($l['total_m3'])) ?></td>
        <td class="num" data-label="<?= h('سعر المتر (' . app_setting('currency') . ')') ?>"><?= h(fmt_money($l['price'])) ?></td>
        <td class="num" data-label="<?= h('القيمة') ?>"><?= h(fmt_money($l['amount'])) ?></td>
        <td class="num" data-label="<?= h('المتاح الآن') ?>"><?= h(fmt_int($have)) ?></td>
        <td class="num" data-label="<?= h('بعد البيع') ?>"><?= h(fmt_int($have - $l['quantity'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="row-total">
        <th scope="row" colspan="3">الإجمالي</th>
        <td class="num" data-label="<?= h('العدد') ?>"><?= h(fmt_int($review['total_qty'])) ?></td>
        <td class="num" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume($review['total_m3'])) ?></td>
        <td></td>
        <td class="num" data-label="<?= h('القيمة') ?>"><?= h(fmt_money_currency($review['total_amount'])) ?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
</div>
<p class="muted">لا تُضاف ضرائب أو شحن أو خصومات. القيمة = حجم الكمية × سعر المتر المكعب، مقربة لأقرب قرش.</p>
<form method="post" action="<?= h(url('sell')) ?>" class="actions">
  <?= csrf_field() ?>
  <?php foreach (['warehouse_id', 'party_name', 'notes', 'request_token'] as $f): ?>
    <input type="hidden" name="<?= h($f) ?>" value="<?= h($form[$f]) ?>">
  <?php endforeach; ?>
  <?= payment_hidden_fields($form) ?>
  <?php foreach (array_values($form['lines']) as $i => $line): ?>
    <?php foreach (LINE_FIELDS_SALE as $f): ?>
      <input type="hidden" name="lines[<?= (int) $i ?>][<?= h($f) ?>]" value="<?= h($line[$f]) ?>">
    <?php endforeach; ?>
  <?php endforeach; ?>
  <button type="submit" name="action" value="confirm" class="btn btn-primary" data-busy-text="جارٍ الحفظ">تأكيد البيع</button>
  <button type="submit" name="action" value="edit" class="btn">تعديل</button>
</form>
<?php
    render_footer();
    exit;
}

/* ---------- نموذج الفاتورة ---------- */
$warehouses = catalog_all($pdo, 'warehouse');
$types = catalog_all($pdo, 'type');
$stockData = stock_payload($pdo);
$hasStock = (bool) array_filter($stockData, fn ($it) => array_sum((array) $it['stock']) > 0);
$done = (int) input($_GET, 'done') > 0 ? find_document($pdo, (int) input($_GET, 'done')) : null;
if ($done && $done['kind'] !== 'sale') {
    $done = null;
}

render_header('فاتورة بيع', 'sell');
?>
<h1>فاتورة بيع</h1>

<?php if ($done): ?>
  <section class="confirm-box" aria-labelledby="done-title">
    <h2 id="done-title">تم الحفظ: <?= h(doc_label($done)) ?></h2>
    <dl class="facts">
      <div><dt>المخزن</dt><dd><?= h($done['warehouse_name']) ?></dd></div>
      <div><dt>العميل</dt><dd><?= $done['party_name'] !== null ? h($done['party_name']) : '<span class="muted">لم يُحدد</span>' ?></dd></div>
      <div><dt>الأسطر</dt><dd><?= h(fmt_int((int) $done['line_count'])) ?></dd></div>
      <div><dt>القطع</dt><dd><?= h(fmt_int((int) $done['total_qty'])) ?></dd></div>
      <div><dt>الحجم</dt><dd><?= h(fmt_volume($done['total_volume_m3'])) ?> م³</dd></div>
      <div class="fact-strong"><dt>الإجمالي</dt><dd><?= h(fmt_money_currency((string) $done['total_amount'], $done['currency'])) ?></dd></div>
      <?php if ($done['payment_type'] !== null): $donePaid = money_to_piasters((string) $done['paid_amount']); ?>
        <div><dt>طريقة الدفع</dt><dd><?= h(PAYMENT_TYPE_LABELS[$done['payment_type']] ?? '') ?></dd></div>
        <div><dt>المدفوع</dt><dd><?= h(fmt_piasters($donePaid)) ?></dd></div>
        <div><dt>المتبقي</dt><dd><?= h(fmt_piasters(money_to_piasters((string) $done['total_amount']) - $donePaid)) ?></dd></div>
      <?php endif; ?>
    </dl>
    <p class="row-actions">
      <a class="btn btn-primary" href="<?= h(url('print', ['id' => (int) $done['id']])) ?>">طباعة الفاتورة</a>
      <a href="<?= h(url('document', ['id' => (int) $done['id']])) ?>">تفاصيل الفاتورة</a>
    </p>
  </section>
<?php endif; ?>

<?php if (!$hasStock && !$errors && !$posted): ?>
  <p class="empty">لا توجد أصناف متاحة للبيع. <a href="<?= h(url('receive')) ?>">أضف وارد</a> أولًا.</p>
<?php else: ?>

<?= errors_summary($errors) ?>

<form method="post" action="<?= h(url('sell')) ?>" class="form form-wide" novalidate data-doc-form="sale">
  <?= csrf_field() ?>
  <input type="hidden" name="request_token" value="<?= h($form['request_token']) ?>">
  <?php /* زر افتراضي أول في النموذج: الضغط على Enter يفتح المراجعة وليس «أسطر إضافية» */ ?>
  <button type="submit" name="action" value="review" class="default-submit" tabindex="-1" aria-hidden="true">مراجعة</button>

  <div class="field-row">
    <div class="field">
      <label for="warehouse_id">المخزن</label>
      <?= warehouse_select('warehouse_id', 'warehouse_id', $warehouses, $form['warehouse_id'], $errors, 'اختر المخزن') ?>
      <?= field_error($errors, 'warehouse_id') ?>
    </div>
    <div class="field">
      <label for="party_name">اسم العميل النقدي <span class="optional">(اختياري)</span></label>
      <input type="text" id="party_name" name="party_name" value="<?= h($form['party_name']) ?>" maxlength="120"<?= field_attrs($errors, 'party_name') ?>>
      <?= field_error($errors, 'party_name') ?>
    </div>
  </div>

  <?= render_line_editor(editor_lines($form['lines'], LINE_FIELDS_SALE, 3, $extraLines), $errors, true, $types, $stockData) ?>

  <?= render_payment_fields($form, $errors, 'customer', parties_for_select($pdo, 'customer'), $boxes) ?>

  <div class="field">
    <label for="notes">ملاحظات <span class="optional">(اختياري)</span></label>
    <textarea id="notes" name="notes" rows="2" maxlength="1000"<?= field_attrs($errors, 'notes') ?>><?= h($form['notes']) ?></textarea>
    <?= field_error($errors, 'notes') ?>
  </div>

  <p class="hint">السعر هو سعر المتر المكعب وليس سعر القطعة. لا تُضاف ضرائب أو شحن أو خصومات.</p>
  <div class="actions">
    <button type="submit" name="action" value="review" class="btn btn-primary">مراجعة الفاتورة</button>
  </div>
</form>
<?= stock_data_script($stockData) ?>
<?php endif; ?>
<?php
render_footer();
