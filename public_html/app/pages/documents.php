<?php
defined('APP_ROOT') || exit;

$pdo = db();
$kind = input($_GET, 'kind');
$status = input($_GET, 'status');
$warehouseId = (int) input($_GET, 'warehouse');
$typeId = (int) input($_GET, 'type');
$q = clean_text(input($_GET, 'q'));
$page = max(1, (int) input($_GET, 'page', '1'));
$perPage = 50;

/** تاريخ من حقل التصفية بصيغة YYYY-MM-DD، أو null إذا كان فارغًا أو غير صالح */
function parse_date_filter(string $raw, bool &$invalid): ?DateTimeImmutable
{
    $raw = normalize_number_input($raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        $invalid = true;
        return null;
    }
    return $dt;
}

$dateInvalid = false;
$from = parse_date_filter(input($_GET, 'from'), $dateInvalid);
$to = parse_date_filter(input($_GET, 'to'), $dateInvalid);

$where = [];
$params = [];
if (isset(DOC_KIND_LABELS[$kind])) {
    $where[] = 'd.kind = ?';
    $params[] = $kind;
} else {
    $kind = '';
}
if ($status === 'active' || $status === 'cancelled') {
    $where[] = 'd.status = ?';
    $params[] = $status;
} else {
    $status = '';
}
if ($warehouseId > 0) {
    $where[] = '(d.warehouse_id = ? OR d.to_warehouse_id = ?)';
    array_push($params, $warehouseId, $warehouseId);
}
if ($typeId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM document_lines l JOIN items i ON i.id = l.item_id WHERE l.document_id = d.id AND i.wood_type_id = ?)';
    $params[] = $typeId;
}
if ($from) {
    $where[] = 'd.created_at >= ?';
    $params[] = $from->format('Y-m-d 00:00:00');
}
if ($to) {
    $where[] = 'd.created_at < ?';
    $params[] = $to->modify('+1 day')->format('Y-m-d 00:00:00');
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $cond = '(d.party_name LIKE ? OR d.reference LIKE ? OR d.notes LIKE ?
             OR EXISTS (SELECT 1 FROM document_lines l2 WHERE l2.document_id = d.id AND l2.wood_type_name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
    $num = normalize_number_input($q);
    if (preg_match('/^\d{1,9}\z/', $num)) {
        $cond .= ' OR d.doc_no = ?';
        $params[] = (int) $num;
    }
    $where[] = $cond . ')';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN d.kind = 'sale' AND d.status = 'active' THEN 1 ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN d.kind = 'sale' AND d.status = 'active' THEN d.total_volume_m3 ELSE 0 END), 0) AS sales_volume,
            COALESCE(SUM(CASE WHEN d.kind = 'sale' AND d.status = 'active' THEN d.total_amount ELSE 0 END), 0) AS sales_amount
     FROM documents d" . $whereSql
);
$stmt->execute($params);
$totals = $stmt->fetch();
$total = (int) $totals['n'];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare('SELECT d.* FROM documents d' . $whereSql . ' ORDER BY d.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$userNames = users_name_map($pdo);

$warehouses = catalog_all($pdo, 'warehouse');
$types = catalog_all($pdo, 'type');
$filters = ['r' => 'documents', 'kind' => $kind, 'status' => $status, 'warehouse' => $warehouseId ?: '', 'type' => $typeId ?: '',
    'from' => $from ? $from->format('Y-m-d') : '', 'to' => $to ? $to->format('Y-m-d') : '', 'q' => $q];
$filters = array_filter($filters, fn ($v) => $v !== '');
$filtered = count($filters) > 1;

render_header('الفواتير والحركات', 'documents');
?>
<h1>الفواتير والحركات</h1>

<form method="get" action="index.php" class="filters" role="search" aria-label="تصفية السجل">
  <input type="hidden" name="r" value="documents">
  <div class="field">
    <label for="kind">نوع المستند</label>
    <select id="kind" name="kind">
      <option value="">الكل</option>
      <?php foreach (DOC_KIND_LABELS as $k => $label): ?>
        <option value="<?= h($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= h($k === 'sale' ? 'فواتير البيع' : $label) ?></option>
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
    <label for="warehouse">المخزن</label>
    <select id="warehouse" name="warehouse"><?= options_html($warehouses, $warehouseId ? (string) $warehouseId : '', 'كل المخازن') ?></select>
  </div>
  <div class="field">
    <label for="type">نوع الخشب</label>
    <select id="type" name="type"><?= options_html($types, $typeId ? (string) $typeId : '', 'كل الأنواع') ?></select>
  </div>
  <div class="field">
    <label for="from">من تاريخ</label>
    <input type="date" id="from" name="from" value="<?= h($from ? $from->format('Y-m-d') : '') ?>">
  </div>
  <div class="field">
    <label for="to">إلى تاريخ</label>
    <input type="date" id="to" name="to" value="<?= h($to ? $to->format('Y-m-d') : '') ?>">
  </div>
  <div class="field">
    <label for="q">بحث</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="رقم، عميل، مورد، مرجع، نوع">
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <?php if ($filtered): ?><a class="btn btn-quiet" href="<?= h(url('documents')) ?>">مسح التصفية</a><?php endif; ?>
  </div>
</form>

<?php if ($dateInvalid): ?><div class="alert alert-warning" role="status">صيغة التاريخ غير صحيحة وتم تجاهلها. استخدم الصيغة 2026-01-31.</div><?php endif; ?>

<div id="live-documents" data-live>
<?php if (!$rows): ?>
  <p class="empty"><?= $filtered ? 'لا توجد مستندات مطابقة.' : 'لا توجد مستندات بعد.' ?></p>
<?php else: ?>
  <p class="summary">
    <?= h(fmt_int($total)) ?> مستند.
    <?php if ((int) $totals['sales'] > 0): ?>
      مبيعات سارية في النتائج: <?= h(fmt_int((string) $totals['sales'])) ?> فاتورة،
      <strong><?= h(fmt_volume((string) $totals['sales_volume'])) ?> م³</strong>،
      بقيمة <strong><?= h(fmt_money_currency((string) $totals['sales_amount'])) ?></strong>
    <?php endif; ?>
  </p>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">المستندات من الأحدث إلى الأقدم</caption>
      <thead>
        <tr>
          <th scope="col">المستند</th>
          <th scope="col">التاريخ</th>
          <th scope="col">المستخدم</th>
          <th scope="col">المخزن</th>
          <th scope="col">العميل / المورد</th>
          <th scope="col" class="num">الأسطر</th>
          <th scope="col" class="num">القطع</th>
          <th scope="col" class="num">الحجم (م³)</th>
          <th scope="col" class="num">القيمة</th>
          <th scope="col">الحالة</th>
          <th scope="col"><span class="visually-hidden">إجراءات</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $d): $cancelled = $d['status'] === 'cancelled'; ?>
        <tr class="<?= $cancelled ? 'row-cancelled' : '' ?>">
          <td class="nowrap"><a href="<?= h(url('document', ['id' => (int) $d['id']])) ?>"><?= h(doc_label($d)) ?></a></td>
          <td class="nowrap"><?= h(fmt_datetime($d['created_at'])) ?></td>
          <td data-label="<?= h('المستخدم') ?>"><?= h($userNames[(int) $d['created_by']] ?? '') ?></td>
          <td><?= h($d['kind'] === 'transfer' ? 'من ' . $d['warehouse_name'] . ' إلى ' . $d['to_warehouse_name'] : $d['warehouse_name']) ?></td>
          <td><?= h((string) $d['party_name']) ?></td>
          <td class="num"><?= h(fmt_int((int) $d['line_count'])) ?></td>
          <td class="num"><?= h(fmt_int((int) $d['total_qty'])) ?></td>
          <td class="num"><?= h(fmt_volume($d['total_volume_m3'])) ?></td>
          <td class="num"><?= $d['kind'] === 'sale' ? h(fmt_money_currency((string) $d['total_amount'], $d['currency'])) : '' ?></td>
          <td><?= $cancelled ? '<span class="status status-cancelled">ملغاة ' . h(fmt_datetime($d['cancelled_at'])) . '</span>' : 'سارية' ?></td>
          <td class="row-actions">
            <a href="<?= h(url('document', ['id' => (int) $d['id']])) ?>">تفاصيل</a>
            <a href="<?= h(url('print', ['id' => (int) $d['id']])) ?>">طباعة</a>
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
