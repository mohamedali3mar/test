<?php
defined('APP_ROOT') || exit;

$pdo = db();
$id = (int) input($_GET, 'id');
$d = find_document($pdo, $id);
if (!$d) {
    render_simple_error('المستند غير موجود.', 404);
}
$lines = document_lines($pdo, $id);
$kind = $d['kind'];
$cancelled = $d['status'] === 'cancelled';
$rounded = (bool) array_filter($lines, fn ($l) => volume_display_rounded($l['total_volume_m3']));

render_header(doc_print_title($kind) . ' ' . fmt_int((int) $d['doc_no']), 'documents', 'page-print');
?>
<div class="print-toolbar no-print">
  <button type="button" class="btn btn-primary" data-action="print">طباعة</button>
  <a class="btn" href="<?= h(url('document', ['id' => $id])) ?>">تفاصيل المستند</a>
  <?php if ($kind === 'sale'): ?><a class="btn btn-quiet" href="<?= h(url('sell')) ?>">فاتورة جديدة</a><?php endif; ?>
</div>

<article class="print-doc" id="live-print" data-live aria-label="<?= h(doc_print_title($kind)) ?>">
  <header class="print-head">
    <p class="print-company"><?= h(app_setting('company_name')) ?></p>
    <h1><?= h(doc_print_title($kind)) ?></h1>
    <?php if ($cancelled): ?>
      <p class="print-cancelled">ملغاة بتاريخ <?= h(fmt_datetime($d['cancelled_at'])) ?></p>
    <?php endif; ?>
  </header>

  <dl class="print-meta">
    <div><dt>رقم <?= $kind === 'sale' ? 'الفاتورة' : 'الإذن' ?></dt><dd><?= h(fmt_int((int) $d['doc_no'])) ?></dd></div>
    <div><dt>التاريخ</dt><dd><?= h(fmt_datetime($d['created_at'])) ?></dd></div>
    <?php if ($kind === 'transfer'): ?>
      <div><dt>من مخزن</dt><dd><?= h($d['warehouse_name']) ?></dd></div>
      <div><dt>إلى مخزن</dt><dd><?= h((string) $d['to_warehouse_name']) ?></dd></div>
    <?php else: ?>
      <div><dt>المخزن</dt><dd><?= h($d['warehouse_name']) ?></dd></div>
    <?php endif; ?>
    <?php if ($d['party_name'] !== null): ?>
      <div><dt><?= $kind === 'sale' ? 'العميل' : 'المورد' ?></dt><dd><?= h($d['party_name']) ?></dd></div>
    <?php endif; ?>
    <?php if ($d['reference'] !== null): ?>
      <div><dt>المرجع</dt><dd><?= h($d['reference']) ?></dd></div>
    <?php endif; ?>
  </dl>

  <p class="hint table-scroll-hint no-print">اسحب الجدول أفقيًا لعرض كل الأعمدة.</p>
  <div class="table-wrap print-table-wrap">
    <table class="print-table">
      <caption class="visually-hidden">الأصناف</caption>
      <thead>
        <tr>
          <th scope="col">نوع الخشب</th>
          <th scope="col">العرض</th>
          <th scope="col">التخانة</th>
          <th scope="col">الطول</th>
          <th scope="col" class="num">العدد</th>
          <th scope="col" class="num">الحجم (م³)</th>
          <?php if ($kind === 'sale'): ?>
            <th scope="col" class="num">سعر المتر المكعب (<?= h((string) $d['currency']) ?>)</th>
            <th scope="col" class="num">القيمة</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $l): ?>
        <tr>
          <td><?= h($l['wood_type_name']) ?></td>
          <td class="nowrap"><?= h(fmt_dim((int) $l['width_um'], $l['width_unit'])) ?></td>
          <td class="nowrap"><?= h(fmt_dim((int) $l['thickness_um'], $l['thickness_unit'])) ?></td>
          <td class="nowrap"><?= h(fmt_dim((int) $l['length_um'], $l['length_unit'])) ?></td>
          <td class="num"><?= h(fmt_int((int) $l['quantity'])) ?></td>
          <td class="num"><?= h(fmt_volume($l['total_volume_m3'])) ?></td>
          <?php if ($kind === 'sale'): ?>
            <td class="num"><?= h(fmt_money((string) $l['price_per_m3'])) ?></td>
            <td class="num"><?= h(fmt_money((string) $l['amount'])) ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="row-total">
          <th scope="row" colspan="4">الإجمالي</th>
          <td class="num"><?= h(fmt_int((int) $d['total_qty'])) ?></td>
          <td class="num"><?= h(fmt_volume($d['total_volume_m3'])) ?></td>
          <?php if ($kind === 'sale'): ?><td></td><td class="num"><?= h(fmt_money((string) $d['total_amount'])) ?></td><?php endif; ?>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if ($kind === 'sale'): ?>
    <p class="print-total">
      <span>إجمالي القيمة</span>
      <strong><?= h(fmt_money_currency((string) $d['total_amount'], $d['currency'])) ?></strong>
    </p>
  <?php endif; ?>
  <?php if ($rounded && $kind === 'sale'): ?>
    <p class="print-note">الأحجام معروضة مقربة، والقيمة محسوبة من الحجم الدقيق.</p>
  <?php endif; ?>

  <?php if ($d['notes'] !== null): ?>
    <div class="print-notes">
      <p class="label">ملاحظات</p>
      <p class="pre"><?= h($d['notes']) ?></p>
    </div>
  <?php endif; ?>
</article>
<?php
render_footer();
