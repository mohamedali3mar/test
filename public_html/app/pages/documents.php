<?php
defined('APP_ROOT') || exit;

$pdo = db();
$page = max(1, (int) input($_GET, 'page', '1'));
$perPage = 50;

// التصفية والترتيب ونطاق الفرع في documents_request (lib/tables.php)، ومنها ملف التصدير أيضًا
$req = documents_request($pdo, $_GET);
['kind' => $kind, 'status' => $status, 'warehouse' => $warehouseId, 'type' => $typeId, 'q' => $q, 'from' => $from, 'to' => $to,
    'date_invalid' => $dateInvalid, 'scope' => $scope, 'branches' => $branches, 'branch_names' => $branchNames, 'branch' => $branchId,
    'warehouses' => $warehouses, 'types' => $types, 'where_sql' => $whereSql, 'params' => $params, 'sort' => $sort,
    'filters' => $filters, 'filtered' => $filtered, 'show_branch' => $showBranch] = $req;

$total = documents_count($pdo, $req);
// إجماليات المبيعات لكل عملة على حدة: لا تُجمع مبالغ عملات مختلفة
$salesTotals = sales_by_currency($pdo, $whereSql, $params, $typeId);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare('SELECT d.* FROM documents d' . $whereSql . ' ORDER BY ' . $req['order'] . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$userNames = users_name_map($pdo);
$columns = documents_columns($showBranch);
$th = fn (string $k, string $class = '') => sort_th($k, $columns[$k], $sort, $filters, isset(DOCUMENTS_SORT_SQL[$k]), $class);
// روابط الصفحات تحمل الترتيب
$pageQuery = $filters + ($sort !== null ? ['sort' => table_sort_param($sort)] : []);

render_header('الفواتير والحركات', 'documents');
?>
<h1>الفواتير والحركات</h1>

<form method="get" action="index.php" class="filters no-print" role="search" aria-label="تصفية السجل">
  <input type="hidden" name="r" value="documents">
  <?php if ($sort !== null): ?><input type="hidden" name="sort" value="<?= h(table_sort_param($sort)) ?>"><?php endif; ?>
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
  <?php if ($scope === null && $showBranch): ?>
  <div class="field">
    <label for="branch">الفرع</label>
    <select id="branch" name="branch"><?= options_html($branches, $branchId ? (string) $branchId : '', 'كل الفروع') ?></select>
  </div>
  <?php endif; ?>
  <div class="field">
    <label for="warehouse">المخزن</label>
    <select id="warehouse" name="warehouse"><?= warehouse_options($warehouses, $warehouseId ? (string) $warehouseId : '', 'كل المخازن') ?></select>
  </div>
  <div class="field">
    <label for="type">نوع الخشب</label>
    <select id="type" name="type"><?= options_html($types, $typeId ? (string) $typeId : '', 'كل الأنواع') ?></select>
  </div>
  <div class="field">
    <label for="from">من تاريخ</label>
    <input type="date" id="from" name="from" value="<?= h($from ? $from->format('Y-m-d') : '') ?>" aria-describedby="hint-from">
    <p class="hint" id="hint-from">الترتيب: سنة-شهر-يوم</p>
  </div>
  <div class="field">
    <label for="to">إلى تاريخ</label>
    <input type="date" id="to" name="to" value="<?= h($to ? $to->format('Y-m-d') : '') ?>" aria-describedby="hint-to">
    <p class="hint" id="hint-to">الترتيب: سنة-شهر-يوم</p>
  </div>
  <div class="field field-wide">
    <label for="q">بحث</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="رقم، عميل، مورد، مرجع، نوع">
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <?php if ($filtered): ?><a class="btn btn-quiet" href="<?= h(url('documents')) ?>">مسح التصفية</a><?php endif; ?>
  </div>
</form>

<?php if ($dateInvalid): ?><div class="alert alert-warning" role="status">صيغة التاريخ غير صحيحة وتم تجاهلها. استخدم الصيغة <?= h(digits('2026-01-31')) ?>.</div><?php endif; ?>

<?php render_print_heading('الفواتير والحركات', $req['subtitle']); ?>
<div id="live-documents" data-live>
<?php if (!$rows): ?>
  <p class="empty">
    <?php if ($filtered): ?>
      لا توجد مستندات مطابقة للتصفية. <a href="<?= h(url('documents')) ?>">عرض كل المستندات</a>
    <?php else: ?>
      لا توجد مستندات بعد. تظهر هنا فواتير البيع والوارد والتحويلات بعد حفظها. <a href="<?= h(url('receive')) ?>">إضافة وارد</a>
    <?php endif; ?>
  </p>
<?php else: ?>
  <p class="summary">
    عدد المستندات<?= $branchId > 0 ? h(' في الفرع «' . $branchNames[$branchId] . '»') : '' ?>: <?= h(fmt_int($total)) ?>
  </p>
  <?php foreach ($salesTotals as $st): ?>
    <p class="summary">
      مبيعات سارية في النتائج<?= $typeId > 0 ? h(' (أسطر النوع المختار فقط)') : '' ?><?= count($salesTotals) > 1 ? h(' بعملة ' . $st['currency']) : '' ?>: عدد الفواتير: <?= h(fmt_int($st['count'])) ?>،
      الحجم: <strong><?= h(fmt_volume($st['volume'])) ?> م³</strong>،
      الإجمالي: <strong><?= h(fmt_money_currency($st['amount'], $st['currency'])) ?></strong>
    </p>
  <?php endforeach; ?>
  <?php render_table_tools([
      'key' => 'documents', 'table' => 'documents-table', 'columns' => $columns, 'required' => ['doc'], 'print' => true,
      'export' => ['t' => 'documents', 'sort' => table_sort_param($sort)] + array_diff_key($filters, ['r' => true]),
      'sortable' => array_intersect_key($columns, DOCUMENTS_SORT_SQL), 'sort' => $sort, 'query' => $filters,
  ]); ?>
  <div class="table-wrap table-stack">
    <table class="documents-table" id="documents-table">
      <caption class="visually-hidden">المستندات من الأحدث إلى الأقدم</caption>
      <thead>
        <tr>
          <?= $th('doc') ?>
          <?= $th('date') ?>
          <?= $th('user') ?>
          <?php if ($showBranch): ?><?= $th('branch') ?><?php endif; ?>
          <?= $th('warehouse') ?>
          <?= $th('party') ?>
          <?= $th('lines', 'num') ?>
          <?= $th('qty', 'num') ?>
          <?= $th('volume', 'num') ?>
          <?= $th('amount', 'num') ?>
          <?= $th('status') ?>
          <th scope="col" class="no-print"><span class="visually-hidden">إجراءات</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $d): $cancelled = $d['status'] === 'cancelled'; ?>
        <tr class="<?= $cancelled ? 'row-cancelled' : '' ?>">
          <td class="nowrap" data-col="doc" data-label="<?= h('المستند') ?>"><a href="<?= h(url('document', ['id' => (int) $d['id']])) ?>"><?= h(doc_label($d)) ?></a></td>
          <td class="nowrap" data-col="date" data-label="<?= h('التاريخ') ?>"><?= h(fmt_datetime($d['doc_date'])) ?></td>
          <td data-col="user" data-label="<?= h('المستخدم') ?>"><?= h($userNames[(int) $d['created_by']] ?? '') ?></td>
          <?php if ($showBranch): ?>
            <td data-col="branch" data-label="الفرع"><?= h($d['kind'] === 'transfer' && (int) $d['to_branch_id'] !== (int) $d['branch_id']
                ? 'من ' . $d['branch_name'] . ' إلى ' . $d['to_branch_name'] : (string) $d['branch_name']) ?></td>
          <?php endif; ?>
          <td data-col="warehouse" data-label="<?= h('المخزن') ?>"><?= h($d['kind'] === 'transfer' ? 'من ' . $d['warehouse_name'] . ' إلى ' . $d['to_warehouse_name'] : $d['warehouse_name']) ?></td>
          <td data-col="party" data-label="<?= h($d['kind'] === 'sale' ? 'العميل' : ($d['kind'] === 'in' ? 'المورد' : 'العميل / المورد')) ?>"><?= h((string) $d['party_name']) ?></td>
          <td class="num" data-col="lines" data-label="<?= h('الأسطر') ?>"><?= h(fmt_int((int) $d['line_count'])) ?></td>
          <td class="num" data-col="qty" data-label="<?= h('القطع') ?>"><?= h(fmt_int((int) $d['total_qty'])) ?></td>
          <td class="num" data-col="volume" data-label="<?= h('الحجم (م³)') ?>"><?= h(fmt_volume($d['total_volume_m3'])) ?></td>
          <td class="num" data-col="amount" data-label="<?= h('القيمة') ?>"><?= $d['kind'] === 'sale' ? h(fmt_money_currency((string) $d['total_amount'], $d['currency'])) : '' ?></td>
          <td data-col="status" data-label="<?= h('الحالة') ?>"><?= $cancelled ? '<span class="status status-cancelled">ملغاة ' . h(fmt_datetime($d['cancelled_at'])) . '</span>' : 'سارية' ?></td>
          <td class="cell-actions no-print">
            <div class="row-actions">
              <a href="<?= h(url('document', ['id' => (int) $d['id']])) ?>">تفاصيل</a>
              <a href="<?= h(url('print', ['id' => (int) $d['id']])) ?>">طباعة</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pager no-print" aria-label="الصفحات">
    <?php if ($page > 1): ?><a class="btn" href="<?= h('index.php?' . http_build_query($pageQuery + ['page' => $page - 1])) ?>">السابق</a><?php endif; ?>
    <span>صفحة <?= h(fmt_int($page)) ?> من <?= h(fmt_int($pages)) ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= h('index.php?' . http_build_query($pageQuery + ['page' => $page + 1])) ?>">التالي</a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php
render_footer();
