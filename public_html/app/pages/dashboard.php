<?php
defined('APP_ROOT') || exit;

acct_require('dashboard');
$pdo = db();
acct_require_all_branches($pdo);
$dash = acct_dashboard($pdo);
$money = fn (string $m) => fmt_money_currency($m);
$visible = acct_reports_visible();

render_header('لوحة التحكم', 'dashboard');
?>
<div class="page-head">
  <h1>لوحة التحكم</h1>
  <div class="page-actions"><a class="btn" href="<?= h(url('reports')) ?>">كل التقارير</a></div>
</div>

<?php foreach (['day' => ['live-dashboard-today', 'اليوم ' . digits($dash['today'])], 'month' => ['live-dashboard-month', 'هذا الشهر من ' . digits($dash['month_start'])]] as $p => [$id, $title]): $d = $dash[$p]; ?>
<section class="section" id="<?= h($id) ?>" data-live aria-labelledby="<?= h($id) ?>-title">
  <h2 id="<?= h($id) ?>-title"><?= h($title) ?></h2>
  <dl class="facts facts-wide">
    <div class="fact-strong"><dt>المبيعات</dt><dd><?= h($money($d['sales_amount'])) ?></dd></div>
    <div><dt>عدد فواتير البيع</dt><dd><?= h(fmt_int($d['sales_count'])) ?></dd></div>
    <div><dt>التحصيلات</dt><dd><?= h($money($d['collections'])) ?></dd></div>
    <div><dt>المصروفات</dt><dd><?= h($money($d['expenses'])) ?></dd></div>
    <?php if ($p === 'month' && acct_can('reports.profit')): ?>
    <div><dt>مجمل الربح</dt><dd><?= h(acct_fmt_cell($d['gross_profit'], 'money') . ' ' . app_setting('currency')) ?></dd></div>
    <?php endif; ?>
  </dl>
</section>
<?php endforeach; ?>

<section class="section" id="live-dashboard-balances" data-live aria-labelledby="dash-balances-title">
  <h2 id="dash-balances-title">الأرصدة الآن</h2>
  <dl class="facts facts-wide">
    <div class="fact-strong"><dt>النقدية في الخزائن</dt><dd><?= h($money($dash['cash'])) ?></dd></div>
    <div><dt>مستحق على العملاء</dt><dd><?= h($money($dash['receivables'])) ?></dd></div>
    <div><dt>مستحق للموردين</dt><dd><?= h($money($dash['payables'])) ?></dd></div>
  </dl>
</section>

<section class="section" id="live-dashboard-customers" data-live aria-labelledby="dash-top-title">
  <h2 id="dash-top-title">أعلى العملاء رصيدًا</h2>
  <?php if (!$dash['top_customers']): ?>
    <p class="empty">لا يوجد عميل عليه رصيد مستحق.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">أعلى خمسة عملاء في الرصيد المستحق</caption>
      <thead><tr><th scope="col">العميل</th><th scope="col" class="num align-end">الرصيد</th></tr></thead>
      <tbody>
        <?php foreach ($dash['top_customers'] as $c): ?>
        <tr>
          <td data-label="العميل"><?= h($c['name']) ?></td>
          <td data-label="الرصيد" class="num align-end"><?= h(fmt_party_balance(money_to_piasters($c['balance']), 'customer')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="section" id="live-dashboard-stock" data-live aria-labelledby="dash-stock-title">
  <h2 id="dash-stock-title">تقييم المخزون</h2>
  <?php if ($dash['unvalued_items'] > 0): ?>
    <div class="alert alert-warning" role="status">
      يوجد <?= h(fmt_int($dash['unvalued_items'])) ?> صنف له رصيد بدون تكلفة، فلا تدخل قيمته في الأرباح وتقييم المخزون.
      <?php if (isset($visible['valuation'])): ?><a href="<?= h(url('reports', ['report' => 'valuation'])) ?>">عرض تقييم المخزون</a><?php endif; ?>
    </div>
  <?php else: ?>
    <p>كل الأصناف التي لها رصيد لها تكلفة.</p>
  <?php endif; ?>
</section>

<section class="section" aria-labelledby="dash-reports-title">
  <h2 id="dash-reports-title">التقارير</h2>
  <ul class="report-list">
    <?php foreach ($visible as $k => $r): ?>
    <li><a href="<?= h(url('reports', ['report' => $k])) ?>"><?= h($r['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php
render_footer();
