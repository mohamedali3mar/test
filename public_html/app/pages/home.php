<?php
defined('APP_ROOT') || exit;

/* الصفحة الرئيسية لكل الأدوار: أزرار سريعة، وحركة اليوم، والمخزون، وآخر المستندات، ضمن نطاق فرع المستخدم */

$pdo = db();
$scope = allowed_branch_id($pdo);
$o = home_overview($pdo, $scope);
$branchName = $scope !== null ? (string) (branch_find($pdo, $scope)['name'] ?? '') : '';
$names = users_name_map($pdo);
$allBranches = $scope === null;
$money = fn (array $rows) => $rows
    ? implode('، ', array_map(fn ($r) => fmt_money_currency($r['amount'], $r['currency']), $rows))
    : fmt_money_currency('0');
$count = fn (array $rows) => array_sum(array_map(fn ($r) => $r['count'], $rows));

render_header('الرئيسية', 'home', 'page-home');
?>
<div class="page-head">
  <div>
    <h1>الرئيسية</h1>
    <p class="home-sub">
      <?= h((string) ($_SESSION['display_name'] ?? current_username())) ?>،
      <?= h(home_day_label($o['day'])) ?><?= $branchName !== '' ? '، الفرع: ' . h($branchName) : ($allBranches ? '، كل الفروع' : '') ?>
    </p>
  </div>
</div>

<?php $actions = home_actions(); if ($actions): ?>
<nav class="home-actions" aria-label="إجراءات سريعة">
  <?php foreach ($actions as [$route, $label, $primary]): ?>
    <a class="btn<?= $primary ? ' btn-primary' : '' ?>" href="<?= h(url($route)) ?>" data-icon="<?= h($route) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="section home-today" id="live-home-today" data-live aria-labelledby="home-today-title">
  <h2 id="home-today-title">حركة اليوم</h2>
  <dl class="facts home-facts">
    <div class="fact-strong">
      <dt>المبيعات</dt>
      <dd><?= h($money($o['sales'])) ?></dd>
      <dd class="fact-note"><?= h(fmt_int($count($o['sales']))) ?> فاتورة سارية</dd>
    </div>
    <div>
      <dt>الوارد</dt>
      <dd><?= h(fmt_int($o['moves']['in']['n'])) ?> إذن</dd>
      <dd class="fact-note"><?= h(fmt_int($o['moves']['in']['qty'])) ?> قطعة، <?= h(fmt_volume($o['moves']['in']['volume'])) ?> م³</dd>
    </div>
    <div>
      <dt>التحويلات</dt>
      <dd><?= h(fmt_int($o['moves']['transfer']['n'])) ?> إذن</dd>
      <dd class="fact-note"><?= h(fmt_int($o['moves']['transfer']['qty'])) ?> قطعة</dd>
    </div>
    <?php if ($o['collect'] !== null): ?>
    <div>
      <dt>التحصيلات</dt>
      <dd><?= h($money($o['collect'])) ?></dd>
      <dd class="fact-note"><?= h(fmt_int($count($o['collect']))) ?> سند قبض</dd>
    </div>
    <?php endif; ?>
    <div>
      <dt>الإلغاءات</dt>
      <dd><?= h(fmt_int($o['cancelled'])) ?></dd>
      <?php if ($o['cancelled'] > 0 && can_open('documents')): ?>
      <dd class="fact-note"><a href="<?= h(url('documents', ['status' => 'cancelled'])) ?>">عرض الملغاة</a></dd>
      <?php endif; ?>
    </div>
  </dl>
</section>

<section class="section home-stock" id="live-home-stock" data-live aria-labelledby="home-stock-title">
  <div class="section-head">
    <h2 id="home-stock-title">المخزون الآن</h2>
    <?php if (can_open('inventory')): ?><a href="<?= h(url('inventory')) ?>">عرض المخزون</a><?php endif; ?>
  </div>
  <dl class="facts home-facts">
    <div><dt>مقاسات متاحة</dt><dd><?= h(fmt_int($o['stock']['sizes'])) ?></dd></div>
    <div><dt>القطع</dt><dd><?= h(fmt_int($o['stock']['qty'])) ?></dd></div>
    <div><dt>الحجم</dt><dd><?= h(fmt_volume($o['stock']['volume'])) ?> م³</dd></div>
    <div>
      <dt>مقاسات نافدة</dt>
      <dd><?= h(fmt_int($o['stock']['empty'])) ?></dd>
      <?php if ($o['stock']['empty'] > 0 && can_open('inventory')): ?>
      <dd class="fact-note"><a href="<?= h(url('inventory')) ?>">تظهر بعلامة «نفد» في المخزون</a></dd>
      <?php endif; ?>
    </div>
  </dl>
</section>

<?php if (can_open('documents')): ?>
<section class="section" id="live-home-recent" data-live aria-labelledby="home-recent-title">
  <div class="section-head">
    <h2 id="home-recent-title">آخر الحركات</h2>
    <a href="<?= h(url('documents')) ?>">كل الفواتير والحركات</a>
  </div>
  <?php if (!$o['recent']): ?>
    <p class="empty">لا توجد مستندات بعد.<?php if (can_open('receive')): ?> ابدأ <a href="<?= h(url('receive')) ?>">بإضافة وارد</a>.<?php endif; ?></p>
  <?php else: ?>
  <div class="table-wrap table-stack">
    <table>
      <caption class="visually-hidden">آخر المستندات من الأحدث إلى الأقدم</caption>
      <thead>
        <tr>
          <th scope="col">المستند</th>
          <th scope="col">التاريخ</th>
          <th scope="col">المستخدم</th>
          <th scope="col">المخزن</th>
          <th scope="col">العميل / المورد</th>
          <th scope="col" class="num">القطع</th>
          <th scope="col" class="num">القيمة</th>
          <th scope="col">الحالة</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($o['recent'] as $d): $cancelled = $d['status'] === 'cancelled'; ?>
        <tr class="<?= $cancelled ? 'row-cancelled' : '' ?>">
          <td class="nowrap" data-label="المستند"><a href="<?= h(url('document', ['id' => (int) $d['id']])) ?>"><?= h(doc_label($d)) ?></a></td>
          <td class="nowrap" data-label="التاريخ"><?= h(fmt_datetime($d['doc_date'])) ?></td>
          <td data-label="المستخدم"><?= h($names[(int) $d['created_by']] ?? '') ?></td>
          <td data-label="المخزن"><?= h($d['kind'] === 'transfer' ? 'من ' . $d['warehouse_name'] . ' إلى ' . $d['to_warehouse_name'] : (string) $d['warehouse_name']) ?></td>
          <td data-label="العميل / المورد"><?= h((string) $d['party_name']) ?></td>
          <td class="num" data-label="القطع"><?= h(fmt_int((int) $d['total_qty'])) ?></td>
          <td class="num" data-label="القيمة"><?= $d['kind'] === 'sale' ? h(fmt_money_currency((string) $d['total_amount'], $d['currency'])) : '' ?></td>
          <td data-label="الحالة"><?= $cancelled ? '<span class="status status-cancelled">ملغاة</span>' : 'سارية' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($allBranches && (acct_can('dashboard') || can('monitor.view'))): ?>
<section class="section" aria-labelledby="home-admin-title">
  <h2 id="home-admin-title">للمدير</h2>
  <nav class="home-actions" aria-label="صفحات المدير">
    <?php if (acct_can('dashboard')): ?><a class="btn" href="<?= h(url('dashboard')) ?>" data-icon="dashboard">لوحة التحكم والأرباح</a><?php endif; ?>
    <?php if (can_open('reports')): ?><a class="btn" href="<?= h(url('reports')) ?>" data-icon="reports">التقارير</a><?php endif; ?>
    <?php if (can('monitor.view')): ?><a class="btn" href="<?= h(url('monitor')) ?>" data-icon="monitor">المراقبة</a><?php endif; ?>
  </nav>
</section>
<?php endif; ?>
<?php
render_footer();
