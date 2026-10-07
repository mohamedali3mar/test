<?php
defined('APP_ROOT') || exit;

/* تفاصيل السند، وإلغاؤه (للمدير) بقيود عكسية مع رفض الفترات المقفلة */

acct_require('statements.view');
$pdo = db();
$id = (int) input($_GET, 'id');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    acct_require('vouchers.cancel');
    if (input($_POST, 'action') !== 'cancel') {
        render_simple_error('إجراء غير معروف.', 400);
    }
    if (input($_POST, 'confirm') !== '1') {
        $errors['confirm'] = 'ضع علامة على خانة التأكيد لإلغاء السند.';
    } else {
        try {
            $v = cancel_voucher($pdo, current_user_id(), $id, input($_POST, 'reason'));
            flash('success', sprintf('تم إلغاء %s وعكس قيوده. السند باقٍ في السجل بحالة «ملغى».', voucher_label($v)));
            redirect('voucher', ['id' => $id]);
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
    }
}

$v = find_voucher($pdo, $id);
if (!$v) {
    render_simple_error('السند غير موجود.', 404);
}
$kind = $v['kind'];
$cancelled = $v['status'] === 'cancelled';
$amount = money_to_piasters($v['amount']);
$closed = !period_is_open($v['voucher_date']);

render_header(voucher_label($v), 'vouchers');
?>
<div class="page-head">
  <h1><?= h(voucher_label($v)) ?></h1>
  <div class="page-actions">
    <a class="btn" href="<?= h(url('voucher_print', ['id' => $id])) ?>">طباعة</a>
    <a class="btn btn-quiet" href="<?= h(url('vouchers')) ?>">السجل</a>
  </div>
</div>

<div id="live-voucher" data-live>
<?php if ($cancelled): ?>
  <div class="alert alert-warning" role="status">
    هذا السند ملغى منذ <?= h(fmt_datetime($v['cancelled_at'])) ?> بواسطة <?= h((string) $v['cancelled_by_name']) ?>.
    <?php if ($v['cancel_reason'] !== null): ?>السبب: <?= h($v['cancel_reason']) ?><?php endif; ?>
  </div>
<?php endif; ?>

<dl class="facts facts-wide">
  <div><dt>التاريخ</dt><dd><?= h(fmt_datetime($v['voucher_date'])) ?></dd></div>
  <div><dt>سجله</dt><dd><?= h($v['created_by_name']) ?> في <?= h(fmt_datetime($v['created_at'])) ?></dd></div>
  <?php if ($v['party_id'] !== null): ?>
    <div><dt><?= $kind === 'collect' ? 'العميل' : 'المورد' ?></dt><dd><a href="<?= h(url('party_statement', ['id' => (int) $v['party_id']])) ?>"><?= h((string) $v['party_name']) ?></a></dd></div>
  <?php endif; ?>
  <?php if ($v['category_name'] !== null): ?>
    <div><dt>التصنيف</dt><dd><?= h($v['category_name']) ?></dd></div>
  <?php endif; ?>
  <?php if ($kind === 'cash_transfer'): ?>
    <div><dt>من خزنة</dt><dd><a href="<?= h(url('cash_statement', ['id' => (int) $v['cash_box_id']])) ?>"><?= h($v['cash_box_name']) ?></a></dd></div>
    <div><dt>إلى خزنة</dt><dd><a href="<?= h(url('cash_statement', ['id' => (int) $v['to_cash_box_id']])) ?>"><?= h((string) $v['to_cash_box_name']) ?></a></dd></div>
  <?php else: ?>
    <div><dt>الخزنة</dt><dd><a href="<?= h(url('cash_statement', ['id' => (int) $v['cash_box_id']])) ?>"><?= h($v['cash_box_name']) ?></a></dd></div>
  <?php endif; ?>
  <div class="fact-strong"><dt>المبلغ</dt><dd><?= h(fmt_money_currency((string) $v['amount'], $v['currency'])) ?></dd></div>
  <div><dt>بالحروف</dt><dd><?= h(amount_in_words_ar($amount)) ?></dd></div>
  <div><dt>المرجع</dt><dd><?= $v['reference'] !== null ? h($v['reference']) : '<span class="muted">لا يوجد</span>' ?></dd></div>
  <div><dt>البيان</dt><dd class="pre"><?= $v['notes'] !== null ? h($v['notes']) : '<span class="muted">لا يوجد</span>' ?></dd></div>
</dl>
</div>

<?php if (!$cancelled && acct_can('vouchers.cancel')): ?>
<section class="section danger-zone" id="live-voucher-cancel" data-live aria-labelledby="cancel-title">
  <h2 id="cancel-title">إلغاء السند</h2>
  <?php if ($closed): ?>
    <p>تاريخ هذا السند في فترة مقفلة (حتى <?= h(digits(closing_date())) ?>)، فلا يمكن إلغاؤه إلا بعد تغيير تاريخ الإقفال من الإعدادات.</p>
  <?php else: ?>
  <p>
    الإلغاء يضيف قيودًا عكسية بتاريخ اليوم ولا يحذف القيود الأصلية.
    <?php if ($kind === 'collect'): ?>يخرج المبلغ من الخزنة ويعود على العميل، ويُرفض إذا كان رصيد الخزنة لا يكفي.
    <?php elseif ($kind === 'pay'): ?>يعود المبلغ إلى الخزنة ويعود مستحقًا للمورد.
    <?php elseif ($kind === 'expense'): ?>يعود المبلغ إلى الخزنة.
    <?php else: ?>يعود المبلغ من الخزنة المحوَّل إليها، ويُرفض إذا كان رصيدها لا يكفي.
    <?php endif; ?>
  </p>
  <?= errors_summary($errors) ?>
  <form method="post" action="<?= h(url('voucher', ['id' => $id])) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel">
    <div class="field">
      <label for="reason">سبب الإلغاء <span class="optional">(اختياري)</span></label>
      <input type="text" id="reason" name="reason" maxlength="255" value="<?= h(input($_POST, 'reason')) ?>"<?= field_attrs($errors, 'reason') ?>>
      <?= field_error($errors, 'reason') ?>
    </div>
    <div class="field field-check">
      <input type="checkbox" id="confirm" name="confirm" value="1" required<?= field_attrs($errors, 'confirm') ?>>
      <label for="confirm">أؤكد إلغاء <?= h(voucher_label($v)) ?></label>
    </div>
    <?= field_error($errors, 'confirm') ?>
    <div class="actions">
      <button type="submit" class="btn btn-danger" data-busy-text="جارٍ الإلغاء">إلغاء السند</button>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
render_footer();
