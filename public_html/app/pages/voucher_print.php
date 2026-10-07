<?php
defined('APP_ROOT') || exit;

/* طباعة السند (A4 أو A5) مع المبلغ بالحروف وخانات التوقيع */

acct_require('statements.view');
$pdo = db();
$id = (int) input($_GET, 'id');
$v = find_voucher($pdo, $id);
if (!$v) {
    render_simple_error('السند غير موجود.', 404);
}
$kind = $v['kind'];
$size = input($_GET, 'size') === 'a5' ? 'a5' : 'a4';
$title = VOUCHER_TITLES[$kind];
$amount = money_to_piasters($v['amount']);

render_header($title . ' ' . fmt_doc_no((int) $v['doc_no']), 'vouchers', 'page-print print-' . $size);
?>
<div class="print-toolbar no-print">
  <button type="button" class="btn btn-primary" data-action="print">طباعة</button>
  <?php if ($size === 'a4'): ?>
    <a class="btn" href="<?= h(url('voucher_print', ['id' => $id, 'size' => 'a5'])) ?>">مقاس A5</a>
  <?php else: ?>
    <a class="btn" href="<?= h(url('voucher_print', ['id' => $id])) ?>">مقاس A4</a>
  <?php endif; ?>
  <a class="btn btn-quiet" href="<?= h(url('voucher', ['id' => $id])) ?>">تفاصيل السند</a>
</div>

<article class="print-doc" id="live-voucher-print" data-live aria-label="<?= h($title) ?>">
  <header class="print-head">
    <p class="print-company"><?= h(app_setting('company_name')) ?></p>
    <h1><?= h($title) ?></h1>
    <?php if ($v['status'] === 'cancelled'): ?>
      <p class="print-cancelled">ملغى بتاريخ <?= h(fmt_datetime($v['cancelled_at'])) ?></p>
    <?php endif; ?>
  </header>

  <dl class="print-meta">
    <div><dt>رقم السند</dt><dd><?= h(fmt_doc_no((int) $v['doc_no'])) ?></dd></div>
    <div><dt>التاريخ</dt><dd><?= h(fmt_datetime($v['voucher_date'])) ?></dd></div>
    <?php if ($kind === 'collect'): ?>
      <div><dt>استلمنا من</dt><dd><?= h((string) $v['party_name']) ?></dd></div>
    <?php elseif ($kind === 'pay'): ?>
      <div><dt>صرفنا إلى</dt><dd><?= h((string) $v['party_name']) ?></dd></div>
    <?php elseif ($kind === 'expense'): ?>
      <div><dt>التصنيف</dt><dd><?= h((string) $v['category_name']) ?></dd></div>
    <?php endif; ?>
    <?php if ($kind === 'cash_transfer'): ?>
      <div><dt>من خزنة</dt><dd><?= h($v['cash_box_name']) ?></dd></div>
      <div><dt>إلى خزنة</dt><dd><?= h((string) $v['to_cash_box_name']) ?></dd></div>
    <?php else: ?>
      <div><dt>الخزنة</dt><dd><?= h($v['cash_box_name']) ?></dd></div>
    <?php endif; ?>
    <?php if ($v['reference'] !== null): ?>
      <div><dt>المرجع</dt><dd><?= h($v['reference']) ?></dd></div>
    <?php endif; ?>
  </dl>

  <p class="print-total">
    <span>المبلغ</span>
    <strong><?= h(fmt_money_currency((string) $v['amount'], $v['currency'])) ?></strong>
  </p>
  <p class="print-words"><?= h(amount_in_words_ar($amount)) ?></p>

  <?php if ($v['notes'] !== null): ?>
    <div class="print-notes">
      <p class="label">وذلك عن</p>
      <p class="pre"><?= h($v['notes']) ?></p>
    </div>
  <?php endif; ?>

  <div class="print-signatures">
    <div><p>المستلم</p><p class="sign-line">الاسم والتوقيع</p></div>
    <div><p>المحاسب</p><p class="sign-line">الاسم والتوقيع</p></div>
  </div>
</article>
<?php
render_footer();
