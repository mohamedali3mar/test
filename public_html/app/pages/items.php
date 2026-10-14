<?php
defined('APP_ROOT') || exit;

/*
 * المقاسات: كل مقاس (نوع + عرض × تخانة × طول) برصيده في كل المخازن. لمن يملك items.manage:
 * إخفاء المقاس الذي رصيده صفر (بدل الحذف)، وإظهاره، وتعديل وحدات عرضه، وإخفاء نوع كل مقاساته رصيدها صفر.
 * القواعد في lib/items.php. الأبعاد لا تُعدل: المستندات السابقة تقرأ أبعادها من المقاس.
 */

$pdo = db();
$scope = allowed_branch_id($pdo);
$canManage = can('items.manage') && $scope === null;
$errors = [];
$unitsId = (int) input($_GET, 'units');
$unitsForm = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('items.manage');
    verify_csrf();
    $action = input($_POST, 'action');
    $id = (int) input($_POST, 'id');
    try {
        if ($action === 'hide' || $action === 'show') {
            $changed = item_set_hidden($pdo, $id, $action === 'hide');
            flash($changed ? 'success' : 'warning', !$changed ? 'المقاس على هذه الحالة بالفعل.'
                : ($action === 'hide' ? 'تم إخفاء المقاس. يظهر من جديد تلقائيًا مع أول وارد له.' : 'تم إظهار المقاس.'));
        } elseif ($action === 'units') {
            $unitsId = $id;
            $unitsForm = string_inputs($_POST, ['width_unit', 'thickness_unit', 'length_unit']);
            $changed = item_set_units($pdo, $id, $unitsForm);
            flash($changed ? 'success' : 'warning', $changed ? 'تم حفظ وحدات عرض المقاس. المستندات السابقة تحتفظ بوحداتها.' : 'لم يتغير شيء.');
        } elseif ($action === 'type_hide' || $action === 'type_show') {
            $changed = type_set_hidden($pdo, $id, $action === 'type_hide');
            flash($changed ? 'success' : 'warning', !$changed ? 'النوع على هذه الحالة بالفعل.'
                : ($action === 'type_hide' ? 'تم إخفاء النوع ومقاساته من نموذج الوارد والمخزون.' : 'تم إظهار النوع في نموذج الوارد.'));
        } else {
            render_simple_error('إجراء غير معروف.', 400);
        }
        redirect('items', array_filter(['type' => input($_GET, 'type'), 'show' => input($_GET, 'show')]));
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$types = $pdo->query('SELECT id, name, is_hidden FROM wood_types ORDER BY name, id')->fetchAll();
$typeNames = array_column($types, 'name', 'id');
$typeId = (int) input($_GET, 'type');
if (!isset($typeNames[$typeId])) {
    $typeId = 0;
}
$show = input($_GET, 'show');
$show = in_array($show, ['hidden', 'all'], true) ? $show : 'visible';
$rows = items_list($pdo, $typeId, $show === 'all' ? null : $show === 'hidden');
$unitsRow = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $unitsId) {
        $unitsRow = $r;
    }
}
if (!$canManage) {
    $unitsRow = null;
}
$unitsForm ??= $unitsRow ? ['width_unit' => $unitsRow['width_unit'], 'thickness_unit' => $unitsRow['thickness_unit'], 'length_unit' => $unitsRow['length_unit']] : null;
$hiddenTypes = $canManage ? array_values(array_filter($types, fn ($t) => (int) ($t['is_hidden'] ?? 0) === 1)) : [];
$query = array_filter(['type' => $typeId ?: '', 'show' => $show !== 'visible' ? $show : ''], fn ($v) => $v !== '');
/** خيارات قائمة بقيم نصية (options_html للمعرفات الرقمية فقط) */
$textOptions = function (array $options, string $selected): string {
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . h($value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html;
};
$unitOptions = array_map(fn ($u) => $u['label'], UNITS);

render_header('المقاسات', 'items');
?>
<div class="page-head">
  <h1>المقاسات</h1>
</div>
<p class="muted">كل مقاس يُسجل تلقائيًا مع أول وارد له. لا يُحذف المقاس ولا تُعدل أبعاده لأن الفواتير السابقة تقرأ أبعادها منه، لكن يمكن إخفاء المقاس الذي رصيده صفر من المخزون والقوائم، ويظهر من جديد تلقائيًا مع أول وارد له.</p>
<?= errors_summary($errors) ?>

<?php if ($unitsRow): $uid = (int) $unitsRow['id']; ?>
<section class="section edit-panel" aria-labelledby="units-title">
  <h2 id="units-title">وحدات عرض المقاس: <?= h(item_label($unitsRow)) ?></h2>
  <p class="hint">للعرض فقط: لا تغير المقاس ولا حجمه. المستندات السابقة تحتفظ بالوحدات التي سُجلت بها.</p>
  <form method="post" action="<?= h(url('items', $query)) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="units">
    <input type="hidden" name="id" value="<?= $uid ?>">
    <div class="field-row">
      <?php foreach (['width_unit' => 'وحدة العرض', 'thickness_unit' => 'وحدة التخانة', 'length_unit' => 'وحدة الطول'] as $k => $label): ?>
      <div class="field">
        <label for="u-<?= h($k) ?>"><?= h($label) ?></label>
        <select id="u-<?= h($k) ?>" name="<?= h($k) ?>"<?= field_attrs($errors, $k) ?>>
          <?= $textOptions($unitOptions, (string) $unitsForm[$k]) ?>
        </select>
        <?= field_error($errors, $k) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-primary">حفظ الوحدات</button>
      <a class="btn btn-quiet" href="<?= h(url('items', $query)) ?>">إغلاق</a>
    </div>
  </form>
</section>
<?php endif; ?>

<form method="get" action="index.php" class="filters no-print" role="search" aria-label="تصفية المقاسات">
  <input type="hidden" name="r" value="items">
  <div class="field">
    <label for="f-type">النوع</label>
    <select id="f-type" name="type">
      <?= options_html($types, $typeId ? (string) $typeId : '', 'كل الأنواع') ?>
    </select>
  </div>
  <div class="field">
    <label for="f-show">الحالة</label>
    <select id="f-show" name="show">
      <?= $textOptions(['visible' => 'الظاهرة', 'hidden' => 'المخفية', 'all' => 'الكل'], $show) ?>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
  </div>
</form>

<section class="section" id="live-items" data-live aria-labelledby="items-title">
  <h2 id="items-title"><?= h($show === 'hidden' ? 'المقاسات المخفية' : ($show === 'all' ? 'كل المقاسات' : 'المقاسات الظاهرة')) ?></h2>
  <?php if (!$rows): ?>
    <p class="empty"><?= $show === 'hidden' ? 'لا توجد مقاسات مخفية.' : 'لا توجد مقاسات بعد. تُسجل المقاسات تلقائيًا مع الوارد.' ?></p>
  <?php else: ?>
  <div class="table-wrap table-stack">
    <table>
      <caption class="visually-hidden">المقاسات</caption>
      <thead>
        <tr>
          <th scope="col">النوع</th>
          <th scope="col">المقاس</th>
          <th scope="col" class="num">الرصيد في كل المخازن</th>
          <th scope="col" class="num">أسطر المستندات</th>
          <th scope="col">الحالة</th>
          <?php if ($canManage): ?><th scope="col">إجراءات</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; $hidden = (int) $r['is_hidden'] === 1; $qty = (int) $r['qty']; ?>
        <tr<?= $hidden ? ' class="row-cancelled"' : '' ?>>
          <td data-label="النوع"><?= h($r['wood_type_name']) ?></td>
          <td data-label="المقاس"><?= h(fmt_size($r)) ?></td>
          <td class="num" data-label="الرصيد في كل المخازن"><?= h(fmt_int($qty)) ?></td>
          <td class="num" data-label="أسطر المستندات"><?= h(fmt_int((int) $r['line_count'])) ?></td>
          <td data-label="الحالة"><?= $hidden ? 'مخفي' : ($qty === 0 ? 'نفد' : 'متاح') ?></td>
          <?php if ($canManage): ?>
          <td class="cell-actions" data-label="إجراءات">
            <div class="row-actions">
              <?php if ($hidden): ?>
                <form method="post" action="<?= h(url('items', $query)) ?>" class="form-inline compact">
                  <?= csrf_field() ?><input type="hidden" name="action" value="show"><input type="hidden" name="id" value="<?= $rid ?>">
                  <button type="submit" class="btn btn-quiet">إظهار</button>
                </form>
              <?php elseif ($qty === 0): ?>
                <form method="post" action="<?= h(url('items', $query)) ?>" class="form-inline compact">
                  <?= csrf_field() ?><input type="hidden" name="action" value="hide"><input type="hidden" name="id" value="<?= $rid ?>">
                  <button type="submit" class="btn btn-quiet">إخفاء</button>
                </form>
              <?php endif; ?>
              <a href="<?= h(url('items', $query + ['units' => $rid])) ?>">وحدات العرض</a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="section" aria-labelledby="types-hide-title">
  <h2 id="types-hide-title">إخفاء نوع خشب</h2>
  <p class="hint">النوع المخفي لا يظهر في نموذج الوارد، ومقاساته تُخفى معه. يُخفى النوع فقط إذا كانت أرصدة كل مقاساته صفرًا، ويمكن إظهاره في أي وقت.</p>
  <form method="post" action="<?= h(url('items', $query)) ?>" class="form-inline" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="type_hide">
    <div class="field">
      <label for="hide-type">النوع</label>
      <select id="hide-type" name="id" required>
        <?= options_html(array_values(array_filter($types, fn ($t) => (int) ($t['is_hidden'] ?? 0) === 0)), '', 'اختر النوع') ?>
      </select>
    </div>
    <button type="submit" class="btn">إخفاء النوع</button>
  </form>
  <?php if ($hiddenTypes): ?>
    <h3>الأنواع المخفية</h3>
    <ul>
      <?php foreach ($hiddenTypes as $t): ?>
        <li>
          <?= h($t['name']) ?>
          <form method="post" action="<?= h(url('items', $query)) ?>" class="form-inline compact">
            <?= csrf_field() ?><input type="hidden" name="action" value="type_show"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="btn btn-quiet">إظهار</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
render_footer();
