<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$form = $posted
    ? string_inputs($_POST, ['warehouse_id', 'party_name', 'notes', 'request_token'])
    : ['warehouse_id' => input($_GET, 'warehouse'), 'party_name' => '', 'notes' => '', 'request_token' => new_request_token()];
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
    ?>
<h1>مراجعة فاتورة البيع</h1>
<p>راجع البيانات ثم اضغط «تأكيد البيع». لم يُحفظ شيء حتى الآن، والرصيد يُتحقق منه مرة أخرى لحظة التأكيد.</p>
<dl class="facts facts-wide">
  <div><dt>المخزن</dt><dd><?= h($review['warehouse']['name']) ?></dd></div>
  <div><dt>العميل</dt><dd><?= $review['party_name'] !== '' ? h($review['party_name']) : '<span class="muted">لم يُحدد</span>' ?></dd></div>
  <?php if ($review['notes'] !== ''): ?><div><dt>ملاحظات</dt><dd class="pre"><?= h($review['notes']) ?></dd></div><?php endif; ?>
</dl>
<div class="table-wrap">
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
        <td class="num"><?= h(fmt_int($l['line_no'])) ?></td>
        <td><?= h($l['item']['wood_type_name']) ?></td>
        <td><?= h(fmt_size($l['item'])) ?></td>
        <td class="num"><?= h(fmt_int($l['quantity'])) ?></td>
        <td class="num"><?= h(fmt_volume($l['total_m3'])) ?></td>
        <td class="num"><?= h(fmt_money($l['price'])) ?></td>
        <td class="num"><?= h(fmt_money($l['amount'])) ?></td>
        <td class="num"><?= h(fmt_int($have)) ?></td>
        <td class="num"><?= h(fmt_int($have - $l['quantity'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="row-total">
        <th scope="row" colspan="3">الإجمالي</th>
        <td class="num"><?= h(fmt_int($review['total_qty'])) ?></td>
        <td class="num"><?= h(fmt_volume($review['total_m3'])) ?></td>
        <td></td>
        <td class="num"><?= h(fmt_money_currency($review['total_amount'])) ?></td>
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
$scope = allowed_branch_id($pdo);
$warehouses = scoped_warehouses($pdo, $scope);
$types = catalog_all($pdo, 'type');
$stockData = stock_payload($pdo, $scope);
$hasStock = (bool) array_filter($stockData, fn ($it) => array_sum((array) $it['stock']) > 0);
$done = (int) input($_GET, 'done') > 0 ? find_document_scoped($pdo, (int) input($_GET, 'done')) : null;
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
      <label for="party_name">اسم العميل <span class="optional">(اختياري)</span></label>
      <input type="text" id="party_name" name="party_name" value="<?= h($form['party_name']) ?>" maxlength="120"<?= field_attrs($errors, 'party_name') ?>>
      <?= field_error($errors, 'party_name') ?>
    </div>
  </div>

  <?= render_line_editor(editor_lines($form['lines'], LINE_FIELDS_SALE, 3, $extraLines), $errors, true, $types, $stockData) ?>

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
