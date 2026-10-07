<?php
defined('APP_ROOT') || exit;

$pdo = db();
$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$form = $posted
    ? string_inputs($_POST, ['warehouse_id', 'to_warehouse_id', 'notes', 'request_token'])
    : ['warehouse_id' => input($_GET, 'from'), 'to_warehouse_id' => '', 'notes' => '', 'request_token' => new_request_token()];
$form['lines'] = $posted ? form_lines($_POST['lines'] ?? [], LINE_FIELDS_TRANSFER) : [];
$extraLines = 0;

if ($posted) {
    verify_csrf();
    $action = input($_POST, 'action');
    try {
        if ($action === 'save') {
            $result = record_transfer($pdo, current_user_id(), $form);
            flash($result['duplicate'] ? 'warning' : 'success', $result['duplicate']
                ? sprintf('هذا التحويل سُجل من قبل (تحويل رقم %s) ولم يتكرر.', fmt_doc_no((int) $result['doc_no']))
                : sprintf('تم حفظ تحويل رقم %s ونقل الكميات.', fmt_doc_no((int) $result['doc_no'])));
            redirect('transfer', ['done' => $result['id']]);
        } elseif ($action === 'more_lines') {
            $extraLines = 3;
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
    $form['request_token'] = refresh_token_if_needed($form, $errors);
}

// المصدر من مخازن فرع المستخدم فقط، والمستلم أي مخزن في أي فرع
$scope = allowed_branch_id($pdo);
$warehouses = scoped_warehouses($pdo, $scope);
$destinations = scoped_warehouses($pdo, null);
$types = catalog_all($pdo, 'type');
$stockData = stock_payload($pdo, $scope);
$done = (int) input($_GET, 'done') > 0 ? find_document_scoped($pdo, (int) input($_GET, 'done')) : null;
if ($done && $done['kind'] !== 'transfer') {
    $done = null;
}

render_header('تحويل بين المخازن', 'transfer');
?>
<h1>تحويل بين المخازن</h1>

<?php if ($done): ?>
  <section class="confirm-box" aria-labelledby="done-title">
    <h2 id="done-title">تم الحفظ: <?= h(doc_label($done)) ?></h2>
    <dl class="facts">
      <div><dt>من</dt><dd><?= h($done['warehouse_name']) ?></dd></div>
      <div><dt>إلى</dt><dd><?= h((string) $done['to_warehouse_name']) ?></dd></div>
      <div><dt>الأسطر</dt><dd><?= h(fmt_int((int) $done['line_count'])) ?></dd></div>
      <div><dt>القطع</dt><dd><?= h(fmt_int((int) $done['total_qty'])) ?></dd></div>
      <div><dt>الحجم</dt><dd><?= h(fmt_volume($done['total_volume_m3'])) ?> م³</dd></div>
    </dl>
    <p class="row-actions">
      <a href="<?= h(url('document', ['id' => (int) $done['id']])) ?>">تفاصيل التحويل</a>
      <a href="<?= h(url('print', ['id' => (int) $done['id']])) ?>">طباعة إذن التحويل</a>
    </p>
  </section>
<?php endif; ?>

<?php if (!$warehouses && $scope !== null): ?>
  <p class="empty">لا توجد مخازن في فرعك للتحويل منها. اطلب من المدير إضافة مخزن للفرع.</p>
<?php elseif (count($destinations) < 2): ?>
  <p class="empty">التحويل يحتاج مخزنين على الأقل. <a href="<?= h(url('warehouses')) ?>">أضف مخزنًا</a>.</p>
<?php else: ?>

<?= errors_summary($errors) ?>

<form method="post" action="<?= h(url('transfer')) ?>" class="form form-wide" novalidate data-doc-form="transfer">
  <?= csrf_field() ?>
  <input type="hidden" name="request_token" value="<?= h($form['request_token']) ?>">
  <button type="submit" name="action" value="save" class="default-submit" tabindex="-1" aria-hidden="true">حفظ</button>

  <div class="field-row">
    <div class="field">
      <label for="warehouse_id">من مخزن</label>
      <?= warehouse_select('warehouse_id', 'warehouse_id', $warehouses, $form['warehouse_id'], $errors, 'اختر المخزن', false) ?>
      <?= field_error($errors, 'warehouse_id') ?>
    </div>
    <div class="field">
      <label for="to_warehouse_id">إلى مخزن</label>
      <select id="to_warehouse_id" name="to_warehouse_id" required<?= field_attrs($errors, 'to_warehouse_id') ?>>
        <?= warehouse_options($destinations, $form['to_warehouse_id'], 'اختر المخزن') ?>
      </select>
      <?= field_error($errors, 'to_warehouse_id') ?>
    </div>
  </div>

  <?= render_line_editor(editor_lines($form['lines'], LINE_FIELDS_TRANSFER, 3, $extraLines), $errors, false, $types, $stockData) ?>

  <div class="field">
    <label for="notes">ملاحظات <span class="optional">(اختياري)</span></label>
    <textarea id="notes" name="notes" rows="2" maxlength="1000"<?= field_attrs($errors, 'notes') ?>><?= h($form['notes']) ?></textarea>
    <?= field_error($errors, 'notes') ?>
  </div>

  <div class="actions">
    <button type="submit" name="action" value="save" class="btn btn-primary" data-busy-text="جارٍ الحفظ">حفظ التحويل</button>
  </div>
</form>
<?= stock_data_script($stockData) ?>
<?php endif; ?>
<?php
render_footer();
