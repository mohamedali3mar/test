<?php
defined('APP_ROOT') || exit;

/* كشف حركة خزنة لفترة، مع ملخص «حركة اليوم» لكل يوم (قابل للطباعة) */

acct_require('statements.view');
$pdo = db();
$id = (int) input($_GET, 'id');
$box = cash_box_find($pdo, $id);
if (!$box || !cash_box_in_scope($box, acct_branch_scope($pdo))) {
    render_simple_error('الخزنة غير موجودة.', 404);
}
$invalid = false;
$from = acct_date_filter(input($_GET, 'from'), $invalid);
$to = acct_date_filter(input($_GET, 'to'), $invalid);
if ($from === null && $to === null && !$invalid && input($_GET, 'all') !== '1') {
    $from = date('Y-m-01'); // الافتراضي: الشهر الحالي
}
if ($from !== null && $to !== null && $from > $to) {
    [$from, $to] = [$to, $from];
}
$s = cash_statement($pdo, $id, $from, $to);
$title = 'كشف حركة ' . $box['name'];
$period = ($from !== null ? 'من ' . digits($from) : 'من البداية') . ' ' . ($to !== null ? 'إلى ' . digits($to) : 'حتى اليوم');

render_header($title, 'cash_boxes', 'page-print');
?>
<div class="page-head no-print">
  <h1><?= h($title) ?></h1>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" data-action="print">طباعة</button>
    <a class="btn btn-quiet" href="<?= h(url('cash_boxes')) ?>">الخزائن</a>
  </div>
</div>

<form method="get" action="index.php" class="filters no-print" aria-label="فترة الكشف">
  <input type="hidden" name="r" value="cash_statement">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="field">
    <label for="from">من تاريخ</label>
    <input type="date" id="from" name="from" value="<?= h($from ?? '') ?>">
  </div>
  <div class="field">
    <label for="to">إلى تاريخ</label>
    <input type="date" id="to" name="to" value="<?= h($to ?? '') ?>">
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <a class="btn btn-quiet" href="<?= h(url('cash_statement', ['id' => $id, 'all' => '1'])) ?>">كل الفترات</a>
  </div>
</form>
<?php if ($invalid): ?><div class="alert alert-warning" role="status">صيغة التاريخ غير صحيحة وتم تجاهلها. استخدم الصيغة <?= h(digits('2026-01-31')) ?>.</div><?php endif; ?>

<article class="print-doc" id="live-cash-statement" data-live aria-label="<?= h($title) ?>">
  <header class="print-head">
    <p class="print-company"><?= h(app_setting('company_name')) ?></p>
    <h2>كشف حركة خزنة</h2>
  </header>
  <dl class="print-meta">
    <div><dt>الخزنة</dt><dd><?= h($box['name']) ?><?= (int) $box['is_active'] ? '' : ' (موقوفة)' ?></dd></div>
    <div><dt>الفترة</dt><dd><?= h($period) ?></dd></div>
    <div><dt>الرصيد الحالي</dt><dd><?= h(fmt_piasters(money_to_piasters($box['balance']))) ?></dd></div>
  </dl>

  <h3>حركة اليوم</h3>
  <div class="table-wrap print-table-wrap">
    <table class="print-table">
      <caption class="visually-hidden">ملخص الحركة لكل يوم</caption>
      <thead>
        <tr>
          <th scope="col">اليوم</th>
          <th scope="col" class="num">عدد الحركات</th>
          <th scope="col" class="num">داخل</th>
          <th scope="col" class="num">خارج</th>
          <th scope="col" class="num">الرصيد آخر اليوم</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($s['days'] as $d): ?>
        <tr>
          <td data-label="اليوم" class="nowrap"><?= h(digits($d['date'])) ?></td>
          <td data-label="عدد الحركات" class="num"><?= h(fmt_int($d['count'])) ?></td>
          <td data-label="داخل" class="num"><?= h(fmt_piasters($d['in'])) ?></td>
          <td data-label="خارج" class="num"><?= h(fmt_piasters($d['out'])) ?></td>
          <td data-label="الرصيد آخر اليوم" class="num"><?= h(fmt_piasters($d['closing'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$s['days']): ?>
        <tr class="row-empty"><td colspan="5">لا توجد حركات في هذه الفترة.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <h3>الحركات</h3>
  <div class="table-wrap print-table-wrap">
    <table class="print-table">
      <caption class="visually-hidden">حركات الخزنة مع الرصيد بعد كل حركة</caption>
      <thead>
        <tr>
          <th scope="col">التاريخ</th>
          <th scope="col">البيان</th>
          <th scope="col">المستند</th>
          <th scope="col" class="num">داخل</th>
          <th scope="col" class="num">خارج</th>
          <th scope="col" class="num">الرصيد</th>
        </tr>
      </thead>
      <tbody>
        <tr class="row-subtotal">
          <td data-label="التاريخ" class="nowrap"><?= h($from !== null ? digits($from) : '') ?></td>
          <td data-label="البيان"><?= $from !== null ? 'رصيد أول الفترة' : 'الرصيد الافتتاحي' ?></td>
          <td data-label="المستند"></td>
          <td data-label="داخل" class="num"></td>
          <td data-label="خارج" class="num"></td>
          <td data-label="الرصيد" class="num"><?= h(fmt_piasters($s['opening'])) ?></td>
        </tr>
        <?php foreach ($s['rows'] as $r): ?>
        <tr class="<?= $r['is_reversal'] ? 'row-cancelled' : '' ?>">
          <td data-label="التاريخ" class="nowrap"><?= h(fmt_datetime($r['date'])) ?></td>
          <td data-label="البيان"><?= h(digits($r['description'])) ?></td>
          <td data-label="المستند" class="nowrap"><a href="<?= h(url($r['link']['route'], ['id' => $r['link']['id']])) ?>"><?= h($r['link']['label']) ?></a></td>
          <td data-label="داخل" class="num"><?= $r['in'] ? h(fmt_piasters($r['in'])) : '' ?></td>
          <td data-label="خارج" class="num"><?= $r['out'] ? h(fmt_piasters($r['out'])) : '' ?></td>
          <td data-label="الرصيد" class="num"><?= h(fmt_piasters($r['balance'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="row-total">
          <th scope="row" colspan="3">الإجمالي والرصيد آخر الفترة</th>
          <td data-label="داخل" class="num"><?= h(fmt_piasters($s['total_in'])) ?></td>
          <td data-label="خارج" class="num"><?= h(fmt_piasters($s['total_out'])) ?></td>
          <td data-label="الرصيد" class="num"><?= h(fmt_piasters($s['closing'])) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</article>
<?php
render_footer();
