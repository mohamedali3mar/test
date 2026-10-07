<?php
defined('APP_ROOT') || exit;

/*
 * محرر أسطر فاتورة البيع والتحويل.
 * يعمل بدون JavaScript (الخادم يعرض أسطرًا فارغة وزر «أسطر إضافية»)،
 * ومع JavaScript تُضاف الأسطر وتُحذف فورًا وتُحسب الأحجام والقيم وتُفلتر المقاسات حسب المخزن والنوع.
 */

const LINE_FIELDS_SALE = ['type_id', 'item_id', 'quantity', 'price'];
const LINE_FIELDS_TRANSFER = ['type_id', 'item_id', 'quantity'];

/** أسطر النموذج للعرض: الأسطر المرسلة، ثم أسطر فارغة حتى الحد الأدنى */
function editor_lines(array $lines, array $fields, int $min, int $extra = 0): array
{
    $blank = array_fill_keys($fields, '');
    $lines = array_values($lines);
    // حذف الأسطر الفارغة من آخر القائمة فقط، ثم إكمال العدد
    while ($lines && implode('', array_map('trim', end($lines))) === '') {
        array_pop($lines);
    }
    $target = max($min, count($lines) + $extra);
    while (count($lines) < $target) {
        $lines[] = $blank;
    }
    return array_slice($lines, 0, MAX_LINES);
}

/** خيارات المقاسات: كل صنف له رصيد في أي مخزن، مع الإبقاء على المختار حتى لو نفد */
function item_options(array $stockData, string $selected): string
{
    $html = '<option value="">اختر المقاس</option>';
    foreach ($stockData as $it) {
        $total = array_sum((array) $it['stock']);
        if ($total <= 0 && (string) $it['id'] !== $selected) {
            continue;
        }
        $html .= '<option value="' . (int) $it['id'] . '" data-type="' . (int) $it['type'] . '"'
            . ((string) $it['id'] === $selected ? ' selected' : '') . '>'
            . h($it['typeName'] . ': ' . $it['size']) . '</option>';
    }
    return $html;
}

function render_line(int|string $i, array $line, array $errors, bool $withPrice, array $types, array $stockData): string
{
    $id = fn (string $f) => 'l' . $i . '-' . $f;
    $name = fn (string $f) => 'lines[' . $i . '][' . $f . ']';
    $err = fn (string $f) => is_int($i) ? field_error($errors, "lines.$i.$f") : '';
    $attrs = fn (string $f) => is_int($i) ? field_attrs($errors, "lines.$i.$f") : '';
    $no = is_int($i) ? fmt_int($i + 1) : '';

    ob_start(); ?>
<fieldset class="line" data-line>
  <legend>السطر <span data-line-no><?= h($no) ?></span></legend>
  <div class="line-grid<?= $withPrice ? ' with-price' : '' ?>">
    <div class="field">
      <label for="<?= h($id('type')) ?>">النوع</label>
      <select id="<?= h($id('type')) ?>" name="<?= h($name('type_id')) ?>" data-line-type>
        <?= options_html($types, $line['type_id'] ?? '', 'كل الأنواع') ?>
      </select>
    </div>
    <div class="field field-item">
      <label for="<?= h($id('item')) ?>">المقاس</label>
      <select id="<?= h($id('item')) ?>" name="<?= h($name('item_id')) ?>" data-line-item<?= $attrs('item_id') ?>>
        <?= item_options($stockData, $line['item_id'] ?? '') ?>
      </select>
      <p class="hint" data-line-available></p>
      <?= $err('item_id') ?>
    </div>
    <div class="field">
      <label for="<?= h($id('qty')) ?>">عدد القطع</label>
      <input type="text" inputmode="numeric" autocomplete="off" maxlength="12" id="<?= h($id('qty')) ?>" name="<?= h($name('quantity')) ?>" value="<?= h($line['quantity'] ?? '') ?>" data-line-qty<?= $attrs('quantity') ?>>
      <?= $err('quantity') ?>
    </div>
    <?php if ($withPrice): ?>
    <div class="field">
      <label for="<?= h($id('price')) ?>">سعر المتر المكعب</label>
      <input type="text" inputmode="decimal" autocomplete="off" maxlength="20" id="<?= h($id('price')) ?>" name="<?= h($name('price')) ?>" value="<?= h($line['price'] ?? '') ?>" data-line-price<?= $attrs('price') ?>>
      <?= $err('price') ?>
    </div>
    <?php endif; ?>
    <div class="line-figures" aria-live="off">
      <span>الحجم: <strong data-line-volume>-</strong> م³</span>
      <?php if ($withPrice): ?><span>القيمة: <strong data-line-amount>-</strong></span><?php endif; ?>
    </div>
    <div class="line-remove">
      <button type="button" class="btn btn-quiet" data-line-remove hidden>حذف السطر</button>
    </div>
  </div>
  <p class="calc-error" data-line-error hidden></p>
</fieldset>
<?php
    return (string) ob_get_clean();
}

/** المحرر كاملًا: الأسطر، وقالب سطر جديد، وأزرار الإضافة، والإجماليات */
function render_line_editor(array $lines, array $errors, bool $withPrice, array $types, array $stockData): string
{
    ob_start(); ?>
<section class="lines" data-lines data-with-price="<?= $withPrice ? '1' : '0' ?>" aria-labelledby="lines-title">
  <h2 id="lines-title">الأصناف</h2>
  <?= field_error($errors, 'lines') ?>
  <div class="line-list" data-line-list>
    <?php foreach ($lines as $i => $line): ?>
      <?= render_line($i, $line, $errors, $withPrice, $types, $stockData) ?>
    <?php endforeach; ?>
  </div>
  <template data-line-template><?= render_line('__i__', [], [], $withPrice, $types, $stockData) ?></template>
  <div class="line-actions">
    <button type="button" class="btn" data-line-add hidden>إضافة سطر</button>
    <button type="submit" name="action" value="more_lines" class="btn" data-nojs-only formnovalidate>أسطر إضافية</button>
  </div>
  <dl class="facts totals" aria-live="polite">
    <div><dt>إجمالي القطع</dt><dd data-total-qty>-</dd></div>
    <div><dt>إجمالي الحجم</dt><dd><span data-total-volume>-</span> م³</dd></div>
    <?php if ($withPrice): ?>
      <div class="fact-strong"><dt>إجمالي القيمة</dt><dd><span data-total-amount>-</span> <?= h(app_setting('currency')) ?></dd></div>
    <?php endif; ?>
  </dl>
</section>
<?php
    return (string) ob_get_clean();
}

/** بيانات الأرصدة للمتصفح (تُحدَّث تلقائيًا بعد ذلك من api) */
function stock_data_script(array $stockData): string
{
    return '<script type="application/json" id="stock-data">' . json_for_script($stockData) . '</script>';
}

/** قائمة المخازن مع الاختيار الافتراضي (إذا كان هناك مخزن واحد يُختار تلقائيًا) */
function warehouse_select(string $name, string $id, array $warehouses, string $selected, array $errors, string $placeholder, bool $remember = true): string
{
    if ($selected === '' && count($warehouses) === 1) {
        $selected = (string) $warehouses[0]['id'];
    }
    return '<select id="' . h($id) . '" name="' . h($name) . '" required' . field_attrs($errors, $name)
        . ($remember ? ' data-remember="' . h($name) . '"' . ($selected !== '' ? ' data-posted="1"' : '') : '') . '>'
        . options_html($warehouses, $selected, $placeholder) . '</select>';
}
