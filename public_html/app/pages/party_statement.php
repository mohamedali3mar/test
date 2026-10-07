<?php
defined('APP_ROOT') || exit;

/* كشف حساب عميل أو مورد لفترة (قابل للطباعة) */

acct_require('statements.view');
$pdo = db();
$id = (int) input($_GET, 'id');
$party = party_find($pdo, $id);
if (!$party) {
    render_simple_error('الحساب غير موجود.', 404);
}
$invalid = false;
$from = acct_date_filter(input($_GET, 'from'), $invalid);
$to = acct_date_filter(input($_GET, 'to'), $invalid);
if ($from !== null && $to !== null && $from > $to) {
    [$from, $to] = [$to, $from];
}
$s = party_statement($pdo, $id, $from, $to);
$kind = $party['kind'];
$title = 'كشف حساب ' . PARTY_NOUNS[$kind] . ' ' . $party['name'];
$period = ($from !== null ? 'من ' . digits($from) : 'من البداية') . ' ' . ($to !== null ? 'إلى ' . digits($to) : 'حتى اليوم');

render_header($title, 'parties', 'page-print');
?>
<div class="page-head no-print">
  <h1><?= h($title) ?></h1>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" data-action="print">طباعة</button>
    <a class="btn btn-quiet" href="<?= h(url('parties', ['kind' => $kind])) ?>"><?= h(PARTY_PLURALS[$kind]) ?></a>
  </div>
</div>

<form method="get" action="index.php" class="filters no-print" aria-label="فترة الكشف">
  <input type="hidden" name="r" value="party_statement">
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
    <?php if ($from !== null || $to !== null): ?><a class="btn btn-quiet" href="<?= h(url('party_statement', ['id' => $id])) ?>">كل الفترات</a><?php endif; ?>
  </div>
</form>
<?php if ($invalid): ?><div class="alert alert-warning" role="status">صيغة التاريخ غير صحيحة وتم تجاهلها. استخدم الصيغة <?= h(digits('2026-01-31')) ?>.</div><?php endif; ?>

<article class="print-doc" id="live-party-statement" data-live aria-label="<?= h($title) ?>">
  <header class="print-head">
    <p class="print-company"><?= h(app_setting('company_name')) ?></p>
    <h2>كشف حساب <?= h(PARTY_NOUNS[$kind]) ?></h2>
  </header>
  <dl class="print-meta">
    <div><dt><?= h(PARTY_NOUNS[$kind]) ?></dt><dd><?= h($party['name']) ?><?= (int) $party['is_active'] ? '' : ' (موقوف)' ?></dd></div>
    <?php if ($party['phone'] !== ''): ?><div><dt>الهاتف</dt><dd><?= h(digits($party['phone'])) ?></dd></div><?php endif; ?>
    <div><dt>الفترة</dt><dd><?= h($period) ?></dd></div>
    <div><dt>الرصيد الحالي</dt><dd><?= h(fmt_party_balance(money_to_piasters($party['balance']), $kind)) ?></dd></div>
  </dl>

  <div class="table-wrap print-table-wrap">
    <table class="print-table">
      <caption class="visually-hidden">حركات الحساب مرتبة بالتاريخ مع الرصيد بعد كل حركة</caption>
      <thead>
        <tr>
          <th scope="col">التاريخ</th>
          <th scope="col">البيان</th>
          <th scope="col">المستند</th>
          <th scope="col" class="num">عليه</th>
          <th scope="col" class="num">له</th>
          <th scope="col" class="num">الرصيد</th>
        </tr>
      </thead>
      <tbody>
        <tr class="row-subtotal">
          <td data-label="التاريخ" class="nowrap"><?= h($from !== null ? digits($from) : '') ?></td>
          <td data-label="البيان"><?= $from !== null ? 'رصيد أول الفترة' : 'الرصيد الافتتاحي' ?></td>
          <td data-label="المستند"></td>
          <td data-label="عليه" class="num"></td>
          <td data-label="له" class="num"></td>
          <td data-label="الرصيد" class="num nowrap"><?= h(fmt_party_balance($s['opening'], $kind)) ?></td>
        </tr>
        <?php foreach ($s['rows'] as $r): ?>
        <tr class="<?= $r['is_reversal'] ? 'row-cancelled' : '' ?>">
          <td data-label="التاريخ" class="nowrap"><?= h(fmt_datetime($r['date'])) ?></td>
          <td data-label="البيان"><?= h(digits($r['description'])) ?></td>
          <td data-label="المستند" class="nowrap"><a href="<?= h(url($r['link']['route'], ['id' => $r['link']['id']])) ?>"><?= h($r['link']['label']) ?></a></td>
          <td data-label="عليه" class="num"><?= $r['alayh'] ? h(fmt_piasters($r['alayh'])) : '' ?></td>
          <td data-label="له" class="num"><?= $r['lah'] ? h(fmt_piasters($r['lah'])) : '' ?></td>
          <td data-label="الرصيد" class="num nowrap"><?= h(fmt_party_balance($r['balance'], $kind)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$s['rows']): ?>
        <tr class="row-empty"><td colspan="6">لا توجد حركات في هذه الفترة.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr class="row-total">
          <th scope="row" colspan="3">الإجمالي والرصيد آخر الفترة</th>
          <td data-label="عليه" class="num"><?= h(fmt_piasters($s['total_alayh'])) ?></td>
          <td data-label="له" class="num"><?= h(fmt_piasters($s['total_lah'])) ?></td>
          <td data-label="الرصيد" class="num nowrap"><?= h(fmt_party_balance($s['closing'], $kind)) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <p class="print-note">«عليه» = مستحق لنا على الحساب، «له» = مستحق علينا للحساب. القيود العكسية للمستندات والسندات الملغاة تظهر بخط باهت.</p>
</article>
<?php
render_footer();
