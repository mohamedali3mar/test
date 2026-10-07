<?php
defined('APP_ROOT') || exit;

$pdo = db();
$id = (int) input($_GET, 'id');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input($_POST, 'action') !== 'cancel') {
        render_simple_error('إجراء غير معروف.', 400);
    }
    if (input($_POST, 'confirm') !== '1') {
        $errors['confirm'] = 'ضع علامة على خانة التأكيد لإلغاء المستند.';
    } else {
        try {
            $doc = cancel_document($pdo, current_user_id(), $id, input($_POST, 'reason'));
            flash('success', sprintf('تم إلغاء %s وتعديل الأرصدة. المستند باقٍ في السجل بحالة «ملغى».', doc_label($doc)));
            redirect('document', ['id' => $id]);
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
    }
}

$d = find_document($pdo, $id);
if (!$d) {
    render_simple_error('المستند غير موجود.', 404);
}
$lines = document_lines($pdo, $id);
$kind = $d['kind'];
$cancelled = $d['status'] === 'cancelled';

render_header(doc_label($d), 'documents');
?>
<div class="page-head">
  <h1><?= h(doc_label($d)) ?></h1>
  <div class="page-actions">
    <a class="btn" href="<?= h(url('print', ['id' => $id])) ?>">طباعة</a>
    <a class="btn btn-quiet" href="<?= h(url('documents')) ?>">السجل</a>
  </div>
</div>

<div id="live-document" data-live>
<?php if ($cancelled): ?>
  <div class="alert alert-warning" role="status">
    هذا المستند ملغى منذ <?= h(fmt_datetime($d['cancelled_at'])) ?> بواسطة <?= h((string) $d['cancelled_by_name']) ?>.
    <?php if ($d['cancel_reason'] !== null): ?>السبب: <?= h($d['cancel_reason']) ?><?php endif; ?>
  </div>
<?php endif; ?>

<dl class="facts facts-wide">
  <div><dt>التاريخ</dt><dd><?= h(fmt_datetime($d['doc_date'])) ?></dd></div>
  <?php if (substr((string) $d['doc_date'], 0, 10) !== substr((string) $d['created_at'], 0, 10)): ?>
    <div><dt>وقت التسجيل</dt><dd><?= h(fmt_datetime($d['created_at'])) ?></dd></div>
  <?php endif; ?>
  <div><dt>سجله</dt><dd><?= h($d['created_by_name']) ?></dd></div>
  <?php if ($kind === 'transfer'): ?>
    <div><dt>من مخزن</dt><dd><?= h($d['warehouse_name']) ?></dd></div>
    <div><dt>إلى مخزن</dt><dd><?= h((string) $d['to_warehouse_name']) ?></dd></div>
  <?php else: ?>
    <div><dt>المخزن</dt><dd><?= h($d['warehouse_name']) ?></dd></div>
    <div><dt><?= $kind === 'sale' ? 'العميل' : 'المورد' ?></dt><dd><?= $d['party_name'] !== null ? h($d['party_name']) : '<span class="muted">لم يُسجل</span>' ?></dd></div>
  <?php endif; ?>
  <?php if ($kind === 'in'): ?>
    <div><dt>مرجع التوريد</dt><dd><?= $d['reference'] !== null ? h($d['reference']) : '<span class="muted">لم يُسجل</span>' ?></dd></div>
  <?php endif; ?>
  <div><dt>القطع</dt><dd><?= h(fmt_int((int) $d['total_qty'])) ?></dd></div>
  <div><dt>الحجم</dt><dd><?= h(fmt_volume($d['total_volume_m3'])) ?> م³</dd></div>
  <?php if ($kind === 'sale'): ?>
    <div class="fact-strong"><dt>إجمالي القيمة</dt><dd><?= h(fmt_money_currency((string) $d['total_amount'], $d['currency'])) ?></dd></div>
  <?php endif; ?>
  <?php if ($kind !== 'transfer' && $d['payment_type'] !== null):
      $payTotal = money_to_piasters((string) ($kind === 'sale' ? $d['total_amount'] : $d['total_cost']));
      $payPaid = money_to_piasters((string) $d['paid_amount']); ?>
    <div><dt>طريقة الدفع</dt><dd><?= h(PAYMENT_TYPE_LABELS[$d['payment_type']] ?? $d['payment_type']) ?></dd></div>
    <?php if ($kind === 'in'): ?><div><dt>قيمة الشراء</dt><dd><?= h(fmt_money_currency(piasters_to_money($payTotal))) ?></dd></div><?php endif; ?>
    <div><dt>المدفوع</dt><dd><?= h(fmt_piasters($payPaid)) ?></dd></div>
    <div><dt>المتبقي</dt><dd><?= h(fmt_piasters($payTotal - $payPaid)) ?></dd></div>
    <?php if ($d['cash_box_name'] !== null): ?><div><dt>الخزنة</dt><dd><?= h($d['cash_box_name']) ?></dd></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($kind !== 'transfer' && $d['total_cost'] !== null && acct_can('reports.profit')): $cost = money_to_piasters((string) $d['total_cost']); ?>
    <div><dt><?= $kind === 'sale' ? 'تكلفة البضاعة المباعة' : 'قيمة الوارد بالتكلفة' ?></dt><dd><?= h(fmt_piasters($cost)) ?></dd></div>
    <?php if ($kind === 'sale'): $profit = money_to_piasters((string) $d['total_amount']) - $cost; ?>
      <div><dt>مجمل الربح</dt><dd><?= h(fmt_piasters($profit)) ?></dd></div>
    <?php endif; ?>
  <?php endif; ?>
  <div><dt>ملاحظات</dt><dd class="pre"><?= $d['notes'] !== null ? h($d['notes']) : '<span class="muted">لا توجد</span>' ?></dd></div>
</dl>

<div class="table-wrap">
  <table>
    <caption class="visually-hidden">أسطر المستند مع الأرصدة وقت الحركة</caption>
    <thead>
      <tr>
        <th scope="col" class="num">#</th>
        <th scope="col">النوع</th>
        <th scope="col">المقاس</th>
        <th scope="col">بالمتر</th>
        <th scope="col" class="num">العدد</th>
        <th scope="col" class="num">حجم القطعة</th>
        <th scope="col" class="num">الحجم (م³)</th>
        <?php if ($kind === 'sale'): ?>
          <th scope="col" class="num">سعر المتر</th>
          <th scope="col" class="num">القيمة</th>
        <?php endif; ?>
        <th scope="col" class="num"><?= $kind === 'transfer' ? 'رصيد المصدر قبل/بعد' : 'الرصيد قبل/بعد' ?></th>
        <?php if ($kind === 'transfer'): ?><th scope="col" class="num">رصيد المستلم قبل/بعد</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr>
        <td class="num"><?= h(fmt_int((int) $l['line_no'])) ?></td>
        <td><?= h($l['wood_type_name']) ?></td>
        <td><?= h(fmt_size($l)) ?></td>
        <td class="muted"><?= h(fmt_meters($l)) ?></td>
        <td class="num"><?= h(fmt_int((int) $l['quantity'])) ?></td>
        <td class="num"><?= h(fmt_volume($l['piece_volume_m3'])) ?></td>
        <td class="num"><?= h(fmt_volume($l['total_volume_m3'])) ?></td>
        <?php if ($kind === 'sale'): ?>
          <td class="num"><?= h(fmt_money((string) $l['price_per_m3'])) ?></td>
          <td class="num"><?= h(fmt_money((string) $l['amount'])) ?></td>
        <?php endif; ?>
        <td class="num"><?= h(fmt_int((int) $l['balance_before']) . ' / ' . fmt_int((int) $l['balance_after'])) ?></td>
        <?php if ($kind === 'transfer'): ?>
          <td class="num"><?= h(fmt_int((int) $l['to_balance_before']) . ' / ' . fmt_int((int) $l['to_balance_after'])) ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($cancelled): ?>
  <p class="muted">المستند ملغى، ولا يمكن إلغاؤه مرة أخرى.</p>
<?php endif; ?>
</div>

<?php if (!$cancelled): ?>
<section class="section danger-zone" id="live-cancel" data-live aria-labelledby="cancel-title">
  <h2 id="cancel-title">إلغاء المستند</h2>
  <p>
    <?php if ($kind === 'sale'): ?>
      إلغاء الفاتورة يعيد كل كمياتها (<?= h(fmt_int((int) $d['total_qty'])) ?> قطعة) إلى <?= h($d['warehouse_name']) ?>.
    <?php elseif ($kind === 'in'): ?>
      إلغاء الوارد يخصم كميته (<?= h(fmt_int((int) $d['total_qty'])) ?> قطعة) من <?= h($d['warehouse_name']) ?>، ويُرفض إذا كان الرصيد الحالي أقل من ذلك.
    <?php else: ?>
      إلغاء التحويل يعيد الكميات من <?= h((string) $d['to_warehouse_name']) ?> إلى <?= h($d['warehouse_name']) ?>، ويُرفض إذا كان رصيد المخزن المستلم أقل من الكمية.
    <?php endif; ?>
    يبقى المستند في السجل بحالة «ملغى».
  </p>
  <?= errors_summary($errors) ?>
  <form method="post" action="<?= h(url('document', ['id' => $id])) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel">
    <div class="field">
      <label for="reason">سبب الإلغاء <span class="optional">(اختياري)</span></label>
      <input type="text" id="reason" name="reason" maxlength="255" value="<?= h(input($_POST, 'reason')) ?>">
    </div>
    <div class="field field-check">
      <input type="checkbox" id="confirm" name="confirm" value="1" required<?= field_attrs($errors, 'confirm') ?>>
      <label for="confirm">أؤكد إلغاء <?= h(doc_label($d)) ?></label>
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-danger" data-busy-text="جارٍ الإلغاء">إلغاء المستند</button>
    </div>
  </form>
</section>
<?php endif; ?>
<?php
render_footer();
