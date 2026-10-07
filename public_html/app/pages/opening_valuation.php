<?php
defined('APP_ROOT') || exit;

/* التقييم الافتتاحي: الأصناف التي لها رصيد بلا قيمة مخزون (Q > 0 و V = 0) */
acct_require('reports.valuation');

$pdo = db();
// التقييم يغير قيمة الصنف في كل المخازن: لمستخدم يرى كل الفروع فقط
acct_require_all_branches($pdo);
$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$postedItem = 0;
$postedCost = '';

if ($posted) {
    verify_csrf();
    $postedItem = (int) input($_POST, 'item_id');
    $postedCost = input($_POST, 'cost_per_m3');
    try {
        $r = set_opening_valuation($pdo, current_user_id(), $postedItem, $postedCost);
        flash('success', sprintf('تم تقييم الصنف: %s قطعة بقيمة %s.', fmt_int($r['qty']), fmt_money_currency(piasters_to_money($r['value']))));
        redirect('opening_valuation');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$rows = unvalued_items($pdo);

render_header('التقييم الافتتاحي للمخزون', 'opening_valuation');
?>
<h1>التقييم الافتتاحي للمخزون</h1>
<p>هذه الأصناف لها رصيد بدون قيمة مخزون (سُجلت بدون تكلفة). أدخل تكلفة المتر المكعب لكل صنف لتصبح قيمته = حجم كل القطع × التكلفة. يُتاح التقييم مرة واحدة فقط لكل صنف قيمته صفر، ويُسجل في سجل المراقبة.</p>

<?= errors_summary($errors) ?>

<section id="live-opening-valuation" data-live aria-live="polite">
<?php if (!$rows): ?>
  <p class="empty">لا توجد أصناف بلا قيمة. كل المخزون مُقيَّم.</p>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">أصناف لها رصيد بلا قيمة</caption>
      <thead>
        <tr>
          <th scope="col">النوع</th>
          <th scope="col">المقاس</th>
          <th scope="col" class="num">القطع</th>
          <th scope="col" class="num">الحجم (م³)</th>
          <th scope="col">تكلفة المتر المكعب</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $id = (int) $r['id'];
            $vol = um3_to_m3(Num::mul(m3_to_um3((string) $r['piece_volume_m3']), (string) (int) $r['qty']));
            $fieldId = 'cost-' . $id; ?>
        <tr>
          <td data-label="النوع"><?= h($r['wood_type_name']) ?></td>
          <td data-label="المقاس"><?= h(fmt_size($r)) ?></td>
          <td data-label="القطع" class="num"><?= h(fmt_int((int) $r['qty'])) ?></td>
          <td data-label="الحجم (م³)" class="num"><?= h(fmt_volume($vol)) ?></td>
          <td data-label="تكلفة المتر المكعب">
            <form method="post" action="<?= h(url('opening_valuation')) ?>" class="inline-form" novalidate>
              <?= csrf_field() ?>
              <input type="hidden" name="item_id" value="<?= $id ?>">
              <label for="<?= h($fieldId) ?>" class="visually-hidden">تكلفة المتر المكعب لـ <?= h($r['wood_type_name'] . ' ' . fmt_size($r)) ?></label>
              <input type="text" inputmode="decimal" id="<?= h($fieldId) ?>" name="cost_per_m3" maxlength="20" autocomplete="off"
                value="<?= $postedItem === $id ? h($postedCost) : '' ?>"<?= $postedItem === $id && isset($errors['cost_per_m3']) ? ' aria-invalid="true"' : '' ?>>
              <button type="submit" class="btn" data-busy-text="جارٍ الحفظ">حفظ القيمة</button>
              <?php if ($postedItem === $id): ?><?= field_error($errors, 'cost_per_m3') ?><?php endif; ?>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
<?php
render_footer();
