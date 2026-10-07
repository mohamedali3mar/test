<?php
defined('APP_ROOT') || exit;

/*
 * صفحة الفروع: الإضافة والتعديل (الاسم والعنوان والهاتف) والحذف، وملخص كل فرع:
 * عدد المخازن والأرصدة الحالية ومبيعات الشهر الحالي لكل عملة.
 * المستخدم المقيد بفرع يرى ملخص فرعه فقط بدون إدارة.
 */

$pdo = db();
$scope = allowed_branch_id($pdo);
$canManage = $scope === null;
$addErrors = [];
$editErrors = [];
$topErrors = []; // أخطاء الحذف، وأي خطأ لا يظهر نموذجه في الصفحة (مثل محاولة إدارة من مستخدم مقيد بفرع)
$addForm = ['name' => '', 'address' => '', 'phone' => ''];
$editForm = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');
    $posted = string_inputs($_POST, ['name', 'address', 'phone']);
    try {
        if ($action === 'create') {
            $addForm = $posted;
            branch_create($pdo, $posted);
            flash('success', 'تمت إضافة الفرع. أضف مخازنه من صفحة المخازن.');
        } elseif ($action === 'update') {
            $editForm = $posted;
            branch_update($pdo, (int) input($_POST, 'id'), $posted);
            flash('success', 'تم حفظ بيانات الفرع. المستندات السابقة تحتفظ باسم الفرع كما سُجل وقتها.');
        } elseif ($action === 'delete') {
            branch_delete($pdo, (int) input($_POST, 'id'));
            flash('success', 'تم حذف الفرع.');
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
        redirect('branches');
    } catch (ValidationException $e) {
        if ($action === 'create' && $canManage) {
            $addErrors = $e->errors;
        } elseif ($action === 'update' && $canManage) {
            $editErrors = $e->errors;
        } else {
            $topErrors = $e->errors;
        }
    }
}

$rows = branch_summary($pdo, $scope);
$totals = branch_summary_totals($rows);
$deletable = fn (array $r) => count($rows) > 1 && (int) $r['warehouses'] === 0 && (int) $r['documents'] === 0 && (int) $r['users'] === 0;
$editId = $canManage ? (int) input($_GET, 'edit') : 0;
$deleteId = $canManage ? (int) input($_GET, 'delete') : 0;
$editRow = null;
$deleteRow = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $editId) {
        $editRow = $r;
    }
    if ((int) $r['id'] === $deleteId && $deletable($r)) {
        $deleteRow = $r;
    }
}
$editForm ??= $editRow ? ['name' => $editRow['name'], 'address' => $editRow['address'], 'phone' => $editRow['phone']] : null;
[$monthStart] = current_month_range();

/** حقول نموذج الفرع (الإضافة والتعديل) */
$branchFields = function (string $prefix, array $form, array $errors): string {
    ob_start(); ?>
    <div class="field">
      <label for="<?= h($prefix) ?>-name">اسم الفرع</label>
      <input type="text" id="<?= h($prefix) ?>-name" name="name" value="<?= h($form['name']) ?>" maxlength="100" required<?= field_attrs($errors, 'name') ?>>
      <?= field_error($errors, 'name') ?>
    </div>
    <div class="field">
      <label for="<?= h($prefix) ?>-address">العنوان <span class="optional">(اختياري)</span></label>
      <input type="text" id="<?= h($prefix) ?>-address" name="address" value="<?= h($form['address']) ?>" maxlength="200"<?= field_attrs($errors, 'address') ?>>
      <?= field_error($errors, 'address') ?>
    </div>
    <div class="field">
      <label for="<?= h($prefix) ?>-phone">الهاتف <span class="optional">(اختياري)</span></label>
      <input type="text" id="<?= h($prefix) ?>-phone" name="phone" value="<?= h($form['phone']) ?>" maxlength="40" inputmode="tel" class="input-ltr"<?= field_attrs($errors, 'phone') ?>>
      <?= field_error($errors, 'phone') ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

render_header('الفروع', 'branches');
?>
<h1>الفروع</h1>

<?php if ($topErrors): ?>
  <div class="alert alert-error" role="alert"><?= h(implode(' ', $topErrors)) ?></div>
<?php endif; ?>

<?php if ($deleteRow): ?>
  <section class="confirm-box danger" aria-labelledby="del-title">
    <h2 id="del-title">تأكيد الحذف</h2>
    <p>حذف الفرع «<?= h($deleteRow['name']) ?>» نهائيًا؟ لا تتبعه مخازن ولا توجد له مستندات.</p>
    <form method="post" action="<?= h(url('branches')) ?>" class="actions">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $deleteRow['id'] ?>">
      <button type="submit" class="btn btn-danger">حذف</button>
      <a class="btn" href="<?= h(url('branches')) ?>">تراجع</a>
    </form>
  </section>
<?php endif; ?>

<?php if ($editRow && $editForm !== null): ?>
  <section class="panel" aria-labelledby="edit-title">
    <h2 id="edit-title">تعديل الفرع «<?= h($editRow['name']) ?>»</h2>
    <?= errors_summary($editErrors) ?>
    <form method="post" action="<?= h(url('branches', ['edit' => (int) $editRow['id']])) ?>" class="form-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
      <?= $branchFields('edit', $editForm, $editErrors) ?>
      <button type="submit" class="btn btn-primary">حفظ</button>
      <a class="btn btn-quiet" href="<?= h(url('branches')) ?>">تراجع</a>
    </form>
    <p class="hint">تعديل الاسم لا يغير اسم الفرع في المستندات السابقة. العنوان والهاتف يظهران في الفواتير المطبوعة.</p>
  </section>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="section" aria-labelledby="add-title">
  <h2 id="add-title">إضافة فرع</h2>
  <?= errors_summary($addErrors) ?>
  <form method="post" action="<?= h(url('branches')) ?>" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <?= $branchFields('new', $addForm, $addErrors) ?>
    <button type="submit" class="btn btn-primary">إضافة</button>
  </form>
</section>
<?php endif; ?>

<section class="section" id="live-branches" data-live aria-labelledby="list-title">
  <h2 id="list-title">ملخص الفروع</h2>
  <p class="muted">الأرصدة الحالية، ومبيعات الشهر الحالي (الفواتير السارية منذ <?= h(digits(substr($monthStart, 0, 10))) ?>) لكل عملة على حدة.</p>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">ملخص الفروع</caption>
      <thead>
        <tr>
          <th scope="col">الفرع</th>
          <th scope="col">العنوان</th>
          <th scope="col">الهاتف</th>
          <th scope="col" class="num">المخازن</th>
          <th scope="col" class="num">القطع المتاحة</th>
          <th scope="col" class="num">الحجم المتاح (م³)</th>
          <th scope="col">مبيعات الشهر</th>
          <th scope="col">إجراءات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
        <tr>
          <td data-label="الفرع"><?= h($r['name']) ?></td>
          <td data-label="العنوان"><?= h($r['address']) ?></td>
          <td data-label="الهاتف"><?php if ($r['phone'] !== ''): ?><bdo dir="ltr" class="nowrap"><?= h(fmt_phone($r['phone'])) ?></bdo><?php endif; ?></td>
          <td class="num" data-label="المخازن"><?= h(fmt_int((string) $r['warehouses'])) ?></td>
          <td class="num" data-label="القطع المتاحة"><?= h(fmt_int((string) $r['qty'])) ?></td>
          <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume((string) $r['volume'])) ?></td>
          <td data-label="مبيعات الشهر">
            <?php if (!$r['sales']): ?><span class="muted">لا توجد</span><?php endif; ?>
            <?php foreach (fmt_sales_lines($r['sales']) as $line): ?><div class="nowrap"><?= h($line) ?></div><?php endforeach; ?>
          </td>
          <td data-label="إجراءات">
            <div class="row-actions actions-nowrap">
              <a href="<?= h(url('inventory', ['branch' => $rid])) ?>">الأرصدة</a>
              <a href="<?= h(url('documents', ['branch' => $rid])) ?>">الحركات</a>
              <?php if ($canManage && $editId !== $rid): ?><a href="<?= h(url('branches', ['edit' => $rid])) ?>">تعديل</a><?php endif; ?>
              <?php if ($canManage && $deletable($r)): ?><a class="danger-link" href="<?= h(url('branches', ['delete' => $rid])) ?>">حذف</a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if (count($rows) > 1): ?>
      <tfoot>
        <tr class="row-total">
          <th scope="row" colspan="3">إجمالي كل الفروع</th>
          <td class="num" data-label="المخازن"><?= h(fmt_int($totals['warehouses'])) ?></td>
          <td class="num" data-label="القطع المتاحة"><?= h(fmt_int($totals['qty'])) ?></td>
          <td class="num" data-label="<?= h('الحجم المتاح (م³)') ?>"><?= h(fmt_volume($totals['volume'])) ?></td>
          <td data-label="مبيعات الشهر">
            <?php if (!$totals['sales']): ?><span class="muted">لا توجد</span><?php endif; ?>
            <?php foreach (fmt_sales_lines($totals['sales']) as $line): ?><div class="nowrap"><?= h($line) ?></div><?php endforeach; ?>
          </td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($canManage): ?>
  <p class="muted">
    يتبع كل مخزن فرعًا واحدًا، ويمكن نقل المخزن إلى فرع آخر من صفحة <a href="<?= h(url('warehouses')) ?>">المخازن</a>.
    يمكن حذف الفرع فقط إذا لم تتبعه مخازن ولا مستندات ولا مستخدمون، ويجب أن يبقى فرع واحد على الأقل.
  </p>
  <?php endif; ?>
</section>
<?php
render_footer();
