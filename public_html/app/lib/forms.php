<?php
defined('APP_ROOT') || exit;

/*
 * محرر أسطر فاتورة البيع والتحويل.
 * بدون JavaScript: الخادم يعرض أسطرًا فارغة وزر «أسطر إضافية».
 * مع JavaScript: تحل محلها شاشة الإدخال السريع (render_pos_panel): اختيار بالترتيب وإضافة مباشرة إلى جدول المستند.
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
    // نص المقاس المختار كاملًا تحت القائمة، لأن القائمة تقص النص على الموبايل
    $sizeText = '';
    foreach ($stockData as $it) {
        if (($line['item_id'] ?? '') !== '' && (string) $it['id'] === (string) $line['item_id']) {
            $sizeText = $it['typeName'] . ': ' . $it['size'];
        }
    }

    ob_start(); ?>
<fieldset class="line" data-line>
  <legend>السطر <span data-line-no><?= h($no) ?></span></legend>
  <div class="line-grid<?= $withPrice ? ' with-price' : '' ?>">
    <div class="field field-type">
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
      <p class="hint" data-line-size<?= $sizeText === '' ? ' hidden' : '' ?>><?= h($sizeText) ?></p>
      <p class="hint" data-line-available></p>
      <?= $err('item_id') ?>
    </div>
    <div class="field field-qty">
      <label for="<?= h($id('qty')) ?>">عدد القطع</label>
      <input type="text" inputmode="numeric" autocomplete="off" maxlength="12" id="<?= h($id('qty')) ?>" name="<?= h($name('quantity')) ?>" value="<?= h($line['quantity'] ?? '') ?>" data-line-qty<?= $attrs('quantity') ?>>
      <?= $err('quantity') ?>
    </div>
    <?php if ($withPrice): ?>
    <div class="field field-price">
      <label for="<?= h($id('price')) ?>">سعر المتر المكعب</label>
      <input type="text" inputmode="decimal" autocomplete="off" maxlength="20" id="<?= h($id('price')) ?>" name="<?= h($name('price')) ?>" value="<?= h($line['price'] ?? '') ?>" data-line-price<?= $attrs('price') ?>>
      <?= $err('price') ?>
    </div>
    <?php endif; ?>
    <div class="line-figures" aria-live="off">
      <span class="nowrap">الحجم: <strong data-line-volume>-</strong> م³</span>
      <?php if ($withPrice): ?><span class="nowrap">القيمة: <strong data-line-amount>-</strong></span><?php endif; ?>
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

/**
 * شاشة الإدخال السريع (مثل الكاشير) التي يضعها app.js مكان الأسطر عند توفر JavaScript:
 * لوحة اختيار النوع ثم المقاس (عرض × تخانة) ثم الطول ثم العدد والسعر، وزر «إضافة» يضع السطر في جدول المستند.
 * حقول اللوحة بلا name فلا تُرسل؛ app.js يكتب أسطر الجدول في حقول مخفية بنفس صيغة الخادم lines[i][...].
 */
function render_pos_panel(bool $withPrice): string
{
    ob_start(); ?>
<div class="pos" data-pos>
  <div class="pos-entry" role="group" aria-labelledby="pos-entry-title">
    <h3 id="pos-entry-title" data-pos-title>إضافة صنف</h3>
    <div class="pos-grid<?= $withPrice ? ' with-price' : '' ?>">
      <div class="field">
        <label for="pos-type">النوع</label>
        <select id="pos-type" data-pos-type aria-describedby="pos-available pos-error"></select>
      </div>
      <div class="field">
        <label for="pos-size">المقاس (عرض × تخانة)</label>
        <select id="pos-size" data-pos-size aria-describedby="pos-available pos-error"></select>
      </div>
      <div class="field">
        <label for="pos-length">الطول</label>
        <select id="pos-length" data-pos-length aria-describedby="pos-available pos-error"></select>
      </div>
      <div class="field">
        <label for="pos-qty">عدد القطع</label>
        <input type="text" id="pos-qty" inputmode="numeric" autocomplete="off" maxlength="12" data-pos-qty aria-describedby="pos-available pos-error">
      </div>
      <?php if ($withPrice): ?>
      <div class="field">
        <label for="pos-price">سعر المتر المكعب</label>
        <input type="text" id="pos-price" inputmode="decimal" autocomplete="off" maxlength="20" data-pos-price aria-describedby="pos-error">
      </div>
      <?php endif; ?>
      <div class="pos-buttons">
        <button type="button" class="btn btn-primary" data-pos-add><?= icon('add') ?><span data-pos-add-label>إضافة</span></button>
        <button type="button" class="btn btn-quiet" data-pos-cancel hidden>إلغاء التعديل</button>
      </div>
    </div>
    <p class="hint" id="pos-available" data-pos-available></p>
    <p class="pos-preview">
      <span class="nowrap">الحجم: <strong data-pos-volume>-</strong> م³</span>
      <?php if ($withPrice): ?><span class="nowrap">القيمة: <strong data-pos-amount>-</strong></span><?php endif; ?>
    </p>
    <p class="calc-error" id="pos-error" data-pos-error hidden></p>
  </div>
  <p class="visually-hidden" role="status" data-pos-status></p>
  <div class="table-wrap table-stack pos-cart-wrap">
    <table class="pos-cart" data-pos-cart>
      <caption class="visually-hidden">أصناف المستند</caption>
      <thead>
        <tr>
          <th scope="col" class="num">م</th>
          <th scope="col">النوع</th>
          <th scope="col">المقاس</th>
          <th scope="col">الطول</th>
          <th scope="col" class="num">العدد</th>
          <th scope="col" class="num">الحجم (م³)</th>
          <?php if ($withPrice): ?>
          <th scope="col" class="num">سعر المتر المكعب</th>
          <th scope="col" class="num">القيمة</th>
          <?php endif; ?>
          <th scope="col"><span class="visually-hidden">إجراءات</span></th>
        </tr>
      </thead>
      <tbody data-pos-rows></tbody>
    </table>
  </div>
  <p class="empty pos-empty" data-pos-empty>لم تُضف أصناف بعد. اختر النوع ثم المقاس ثم الطول، واكتب العدد<?= $withPrice ? ' والسعر' : '' ?>، ثم اضغط «إضافة».</p>
  <div data-pos-hidden hidden></div>
</div>
<?php
    return (string) ob_get_clean();
}

/** المحرر كاملًا: الأسطر، وقالب شاشة الإدخال السريع، وأزرار الإضافة، والإجماليات */
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
  <template data-pos-template><?= render_pos_panel($withPrice) ?></template>
  <div class="line-actions">
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
        . warehouse_options($warehouses, $selected, $placeholder) . '</select>';
}

/* ===================== حقول الحساب: الطرف وطريقة الدفع والخزنة والتاريخ ===================== */

const PAYMENT_FORM_FIELDS = ['party_id', 'payment_type', 'paid_amount', 'cash_box_id', 'doc_date'];

/** القيم الافتراضية لحقول الحساب في نموذج جديد */
function payment_form_defaults(array $boxes): array
{
    return [
        'party_id' => '', 'payment_type' => 'cash', 'paid_amount' => '',
        'cash_box_id' => $boxes ? (string) $boxes[0]['id'] : '', 'doc_date' => '',
    ];
}

/**
 * حقول العميل/المورد وطريقة الدفع والمبلغ المدفوع والخزنة، والتاريخ للمدير فقط.
 * تعمل بدون JavaScript؛ payment.js يخفي المبلغ المدفوع إلا في الدفع الجزئي،
 * وفي الوارد ($costField) يخفي تفاصيل الدفع حتى تُدخل التكلفة.
 */
function render_payment_fields(array $form, array $errors, string $partyKind, array $parties, array $boxes, string $costField = ''): string
{
    $isSale = $partyKind === 'customer';
    ob_start(); ?>
<fieldset class="payment" data-payment<?= $costField !== '' ? ' data-payment-cost="' . h($costField) . '"' : '' ?>>
  <legend><?= $isSale ? 'العميل والدفع' : 'المورد والدفع' ?></legend>
  <div class="field-row">
    <div class="field">
      <label for="party_id"><?= $isSale ? 'حساب العميل' : 'حساب المورد' ?></label>
      <select id="party_id" name="party_id"<?= field_attrs($errors, 'party_id', 'hint-party') ?>>
        <option value=""><?= $isSale ? 'عميل نقدي (بدون حساب)' : 'بدون حساب مورد' ?></option>
        <?php foreach ($parties as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= (string) $p['id'] === (string) $form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint" id="hint-party"><?= $isSale
          ? 'الدفع الآجل والجزئي يحتاجان حساب عميل.'
          : 'طريقة الدفع تُطبق عند إدخال التكلفة. الشراء الآجل والجزئي يحتاجان حساب مورد، والشراء بتكلفة بدون مورد يُصرف نقدًا من الخزنة.' ?></p>
      <?= field_error($errors, 'party_id') ?>
    </div>
    <?php if (acct_can('manual_date')): ?>
      <div class="field">
        <label for="doc_date">تاريخ المستند <span class="optional">(فارغ = اليوم)</span></label>
        <input type="date" id="doc_date" name="doc_date" value="<?= h($form['doc_date']) ?>" max="<?= h(date('Y-m-d')) ?>"<?= field_attrs($errors, 'doc_date') ?>>
        <?= field_error($errors, 'doc_date') ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if (!acct_can('manual_date')): ?><?= field_error($errors, 'doc_date') ?><?php endif; ?>
  <div data-payment-details>
    <fieldset class="payment-type">
      <legend>طريقة الدفع</legend>
      <?php foreach (PAYMENT_TYPE_LABELS as $value => $label): ?>
        <label class="radio"><input type="radio" name="payment_type" value="<?= h($value) ?>" data-payment-type<?= $form['payment_type'] === $value ? ' checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach; ?>
      <?= field_error($errors, 'payment_type') ?>
    </fieldset>
    <div class="field-row">
      <div class="field" data-paid-field>
        <label for="paid_amount">المبلغ المدفوع <span class="optional">(للدفع الجزئي)</span></label>
        <input type="text" inputmode="decimal" id="paid_amount" name="paid_amount" value="<?= h($form['paid_amount']) ?>" autocomplete="off" maxlength="20"<?= field_attrs($errors, 'paid_amount') ?>>
        <?= field_error($errors, 'paid_amount') ?>
      </div>
      <div class="field">
        <label for="cash_box_id">الخزنة</label>
        <select id="cash_box_id" name="cash_box_id"<?= field_attrs($errors, 'cash_box_id') ?>>
          <?php foreach ($boxes as $b): ?>
            <option value="<?= (int) $b['id'] ?>"<?= (string) $b['id'] === (string) $form['cash_box_id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'cash_box_id') ?>
      </div>
    </div>
  </div>
</fieldset>
<script src="<?= h(url('asset', ['f' => 'payment.js', 'v' => asset_version('js/payment.js')])) ?>" defer></script>
<?php
    return (string) ob_get_clean();
}

/** حقول الحساب كحقول مخفية (صفحة المراجعة) */
function payment_hidden_fields(array $form): string
{
    $html = '';
    foreach (PAYMENT_FORM_FIELDS as $f) {
        $html .= '<input type="hidden" name="' . h($f) . '" value="' . h((string) ($form[$f] ?? '')) . '">';
    }
    return $html;
}
