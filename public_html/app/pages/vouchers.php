<?php
defined('APP_ROOT') || exit;

/* سجل السندات: تصفية بالنوع والتاريخ والخزنة والحساب والحالة والرقم، مع الإجماليات */

acct_require('statements.view');
$pdo = db();
$kind = input($_GET, 'kind');
$status = input($_GET, 'status');
$boxId = (int) input($_GET, 'box');
$partyId = (int) input($_GET, 'party');
$no = normalize_number_input(trim(input($_GET, 'no')));
$page = max(1, (int) input($_GET, 'page', '1'));
$perPage = 50;
$invalid = false;
$from = acct_date_filter(input($_GET, 'from'), $invalid);
$to = acct_date_filter(input($_GET, 'to'), $invalid);

$where = [];
$params = [];
if (isset(VOUCHER_KIND_LABELS[$kind])) {
    $where[] = 'v.kind = ?';
    $params[] = $kind;
} else {
    $kind = '';
}
if ($status === 'active' || $status === 'cancelled') {
    $where[] = 'v.status = ?';
    $params[] = $status;
} else {
    $status = '';
}
if ($boxId > 0) {
    $where[] = '(v.cash_box_id = ? OR v.to_cash_box_id = ?)';
    array_push($params, $boxId, $boxId);
}
if ($partyId > 0) {
    $where[] = 'v.party_id = ?';
    $params[] = $partyId;
}
if (preg_match('/^\d{1,9}\z/', $no)) {
    $where[] = 'v.doc_no = ?';
    $params[] = (int) $no;
} else {
    $no = '';
}
if ($from !== null) {
    $where[] = 'v.voucher_date >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== null) {
    $where[] = 'v.voucher_date < ?';
    $params[] = acct_next_day($to);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT v.kind, COUNT(*) AS n, COALESCE(SUM(v.amount), 0) AS total
    FROM vouchers v{$whereSql} " . ($where ? ' AND ' : ' WHERE ') . "v.status = 'active' GROUP BY v.kind");
$stmt->execute($params);
$totals = [];
foreach ($stmt->fetchAll() as $t) {
    $totals[$t['kind']] = $t;
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM vouchers v' . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$stmt = $pdo->prepare('SELECT v.* FROM vouchers v' . $whereSql . ' ORDER BY v.voucher_date DESC, v.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$boxes = cash_boxes_for_user($pdo, false);
$parties = $pdo->query("SELECT id, CONCAT(name, ' (', IF(kind = 'customer', 'عميل', 'مورد'), ')') AS name FROM parties ORDER BY kind, name, id")->fetchAll();
$filters = array_filter(['r' => 'vouchers', 'kind' => $kind, 'status' => $status, 'box' => $boxId ?: '', 'party' => $partyId ?: '',
    'no' => $no, 'from' => $from ?? '', 'to' => $to ?? ''], fn ($v) => $v !== '');
$filtered = count($filters) > 1;

render_header('السندات', 'vouchers');
?>
<div class="page-head">
  <h1>السندات</h1>
  <div class="page-actions">
    <?php foreach (VOUCHER_PERMISSIONS as $k => $perm): ?>
      <?php if (acct_can($perm)): ?><a class="btn" href="<?= h(url($k)) ?>"><?= h(VOUCHER_KIND_LABELS[$k]) ?></a><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<form method="get" action="index.php" class="filters" role="search" aria-label="تصفية السندات">
  <input type="hidden" name="r" value="vouchers">
  <div class="field">
    <label for="kind">النوع</label>
    <select id="kind" name="kind">
      <option value="">الكل</option>
      <?php foreach (VOUCHER_KIND_LABELS as $k => $label): ?>
        <option value="<?= h($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="status">الحالة</label>
    <select id="status" name="status">
      <option value="">الكل</option>
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>سارية</option>
      <option value="cancelled"<?= $status === 'cancelled' ? ' selected' : '' ?>>ملغاة</option>
    </select>
  </div>
  <div class="field">
    <label for="box">الخزنة</label>
    <select id="box" name="box"><?= options_html($boxes, $boxId ? (string) $boxId : '', 'كل الخزائن') ?></select>
  </div>
  <div class="field">
    <label for="party">الحساب</label>
    <select id="party" name="party"><?= options_html($parties, $partyId ? (string) $partyId : '', 'كل الحسابات') ?></select>
  </div>
  <div class="field">
    <label for="no">الرقم</label>
    <input type="text" inputmode="numeric" id="no" name="no" value="<?= h($no) ?>" maxlength="9">
  </div>
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
    <?php if ($filtered): ?><a class="btn btn-quiet" href="<?= h(url('vouchers')) ?>">مسح التصفية</a><?php endif; ?>
  </div>
</form>
<?php if ($invalid): ?><div class="alert alert-warning" role="status">صيغة التاريخ غير صحيحة وتم تجاهلها. استخدم الصيغة 2026-01-31.</div><?php endif; ?>

<div id="live-vouchers" data-live>
<?php if (!$rows): ?>
  <p class="empty"><?= $filtered ? 'لا توجد سندات مطابقة.' : 'لا توجد سندات بعد.' ?></p>
<?php else: ?>
  <p class="summary">
    <?= h(fmt_int($total)) ?> سند.
    <?php foreach (VOUCHER_KIND_LABELS as $k => $label): if (!isset($totals[$k])) { continue; } ?>
      <?= h($label) ?>: <?= h(fmt_int((int) $totals[$k]['n'])) ?> بإجمالي <strong><?= h(fmt_money((string) $totals[$k]['total'])) ?></strong>.
    <?php endforeach; ?>
    <span class="muted">(الإجماليات للسندات السارية فقط)</span>
  </p>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">السندات من الأحدث إلى الأقدم</caption>
      <thead>
        <tr>
          <th scope="col">السند</th>
          <th scope="col">التاريخ</th>
          <th scope="col">الحساب / التصنيف</th>
          <th scope="col">الخزنة</th>
          <th scope="col" class="num">المبلغ</th>
          <th scope="col">الحالة</th>
          <th scope="col"><span class="visually-hidden">إجراءات</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $v): $cancelled = $v['status'] === 'cancelled'; ?>
        <tr class="<?= $cancelled ? 'row-cancelled' : '' ?>">
          <td data-label="السند" class="nowrap"><a href="<?= h(url('voucher', ['id' => (int) $v['id']])) ?>"><?= h(voucher_label($v)) ?></a></td>
          <td data-label="التاريخ" class="nowrap"><?= h(fmt_datetime($v['voucher_date'])) ?></td>
          <td data-label="الحساب / التصنيف"><?= h((string) ($v['party_name'] ?? $v['category_name'] ?? '')) ?></td>
          <td data-label="الخزنة"><?= h($v['kind'] === 'cash_transfer' ? 'من ' . $v['cash_box_name'] . ' إلى ' . $v['to_cash_box_name'] : $v['cash_box_name']) ?></td>
          <td data-label="المبلغ" class="num nowrap"><?= h(fmt_money((string) $v['amount'])) ?></td>
          <td data-label="الحالة"><?= $cancelled ? '<span class="status status-cancelled">ملغى ' . h(fmt_datetime($v['cancelled_at'])) . '</span>' : 'ساري' ?></td>
          <td data-label="إجراءات" class="row-actions">
            <a href="<?= h(url('voucher', ['id' => (int) $v['id']])) ?>">تفاصيل</a>
            <a href="<?= h(url('voucher_print', ['id' => (int) $v['id']])) ?>">طباعة</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="الصفحات">
    <?php if ($page > 1): ?><a class="btn" href="<?= h('index.php?' . http_build_query($filters + ['page' => $page - 1])) ?>">السابق</a><?php endif; ?>
    <span>صفحة <?= h(fmt_int($page)) ?> من <?= h(fmt_int($pages)) ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= h('index.php?' . http_build_query($filters + ['page' => $page + 1])) ?>">التالي</a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php
render_footer();
