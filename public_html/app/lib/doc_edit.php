<?php
defined('APP_ROOT') || exit;

/*
 * تعديل المستندات (فاتورة البيع وإذن الوارد وإذن التحويل) وإلغاؤها بصلاحيات يحددها المدير.
 *
 *  - لا يُحذف مستند ولا يُعدل في مكانه (ACCOUNTING_SPEC.md): التعديل = إلغاء الأصلي بقيود عكسية + تسجيل
 *    مستند بديل برقم جديد، في معاملة واحدة. إما أن يتم الاثنان معًا أو لا شيء. البديل يحتفظ بتاريخ الأصلي
 *    (بدون صلاحية التاريخ اليدوي)، والأصلي يُعرض «عُدّل بالمستند رقم ...» والبديل «بدل المستند رقم ...».
 *  - الصلاحيات (يمنحها المدير لكل موظف من صفحة المستخدمين):
 *      documents.edit / documents.cancel           أي مستند في نطاق فرع المستخدم وفي فترة مفتوحة
 *      documents.edit_own / documents.cancel_own   مستند سجّله المستخدم نفسه اليوم فقط
 *  - كل الفحوص على الخادم: نطاق الفرع، والفترة المقفلة، والرصيد بعد الإلغاء، وحد الائتمان، والخزنة.
 *  - ترتيب الأقفال: الإلغاء أولًا (صف المستند ثم الطرف ثم الخزائن ثم الأصناف ثم الأرصدة) ثم التسجيل.
 *    التسجيل قد يطلب قفلًا مشتركًا على المخازن بعد أقفال الأصناف؛ الجمود النادر يُعاد تلقائيًا (db_transaction).
 */

/** تاريخ المستند الأصلي (Y-m-d) المسموح به في تعديل جارٍ، أو null */
function doc_edit_inherited_date(?string $set = null, bool $clear = false): ?string
{
    static $date = null;
    if ($clear) {
        $date = null;
    } elseif ($set !== null) {
        $date = $set;
    }
    return $date;
}

/** هل سجّل المستخدم الحالي هذا المستند اليوم؟ */
function document_owned_today(array $doc): bool
{
    return (int) $doc['created_by'] === current_user_id() && substr((string) $doc['created_at'], 0, 10) === date('Y-m-d');
}

/** هل يستطيع المستخدم الحالي إلغاء هذا المستند (الصلاحية فقط؛ باقي الشروط يفحصها cancel_document)؟ */
function document_cancel_permitted(array $doc): bool
{
    return can('documents.cancel') || (can('documents.cancel_own') && document_owned_today($doc));
}

/** هل يملك المستخدم الحالي صلاحية تعديل هذا المستند؟ */
function document_edit_permitted(array $doc): bool
{
    return can('documents.create') && (can('documents.edit') || (can('documents.edit_own') && document_owned_today($doc)));
}

/**
 * سبب منع تعديل المستند الآن، أو null إذا كان مسموحًا. للعرض قبل فتح نموذج التعديل؛ نفس الشروط تُفحص
 * داخل معاملة الحفظ.
 */
function document_edit_problem(PDO $pdo, array $doc): ?string
{
    if (!in_array($doc['kind'], ['in', 'sale', 'transfer'], true)) {
        return 'هذا المستند لا يُعدل.';
    }
    if ($doc['status'] !== 'active') {
        return 'هذا المستند ملغى، فلا يُعدل.';
    }
    if (!document_edit_permitted($doc)) {
        return can('documents.edit_own') ? 'تعدّل فقط المستندات التي سجلتها أنت اليوم. لتعديل غيرها راجع مدير النظام.'
            : 'ليست لديك صلاحية تعديل المستندات. راجع مدير النظام.';
    }
    $scope = allowed_branch_id($pdo);
    if (!document_cancellable($doc, $scope)) {
        return 'لا يمكنك تعديل هذا المستند لأن أحد طرفيه في فرع آخر.';
    }
    if (($moved = document_moved_warehouse($pdo, $doc, $scope)) !== null) {
        return sprintf('لا يمكنك تعديل هذا المستند لأن المخزن «%s» نُقل إلى فرع آخر بعد حفظه. اطلب تعديله من المدير (مستخدم يرى كل الفروع).', $moved);
    }
    if (!period_is_open((string) $doc['doc_date'])) {
        return sprintf('لا يمكن تعديل هذا المستند: تاريخه %s يقع في فترة مقفلة (حتى %s).',
            digits(substr((string) $doc['doc_date'], 0, 10)), digits(closing_date()));
    }
    return null;
}

/** صفحة النموذج لكل نوع مستند */
function document_edit_route(array $doc): string
{
    return ['in' => 'receive', 'sale' => 'sell', 'transfer' => 'transfer'][$doc['kind']];
}

/** رقم صحيح أو عشري من قاعدة البيانات كما يكتبه المستخدم (بلا أصفار زائدة بعد الفاصلة) */
function doc_edit_number(?string $v): string
{
    return $v === null ? '' : Num::trimDecimal((string) $v);
}

/**
 * قيم النموذج من المستند الأصلي (تُعرض للتعديل). البيع والتحويل: الأسطر بصيغة النموذج. الوارد: سطر واحد.
 * @return array<string,mixed>
 */
function document_edit_form(PDO $pdo, array $doc): array
{
    $lines = document_lines($pdo, (int) $doc['id']);
    $date = substr((string) $doc['doc_date'], 0, 10);
    $form = [
        'warehouse_id' => (string) $doc['warehouse_id'],
        'notes' => (string) ($doc['notes'] ?? ''),
        'request_token' => new_request_token(),
        'edit_id' => (string) $doc['id'],
        'edit_reason' => '',
        'doc_date' => $date === date('Y-m-d') ? '' : $date,
    ];
    if ($doc['kind'] !== 'transfer') {
        $form += [
            'party_name' => (string) ($doc['party_name'] ?? ''),
            'party_id' => $doc['party_id'] !== null ? (string) $doc['party_id'] : '',
            'payment_type' => (string) ($doc['payment_type'] ?? 'cash'),
            'paid_amount' => $doc['payment_type'] === 'partial' ? doc_edit_number($doc['paid_amount']) : '',
            'cash_box_id' => $doc['cash_box_id'] !== null ? (string) $doc['cash_box_id'] : '',
        ];
    }
    if ($doc['kind'] === 'transfer') {
        $form['to_warehouse_id'] = (string) $doc['to_warehouse_id'];
    }
    if ($doc['kind'] === 'in') {
        $l = $lines[0];
        $form += [
            'wood_type_id' => (string) $l['wood_type_id'],
            'width' => um_in_unit((int) $l['width_um'], (string) $l['width_unit']), 'width_unit' => (string) $l['width_unit'],
            'thickness' => um_in_unit((int) $l['thickness_um'], (string) $l['thickness_unit']), 'thickness_unit' => (string) $l['thickness_unit'],
            'length' => um_in_unit((int) $l['length_um'], (string) $l['length_unit']), 'length_unit' => (string) $l['length_unit'],
            'quantity' => (string) $l['quantity'],
            'reference' => (string) ($doc['reference'] ?? ''),
            'cost_per_m3' => doc_edit_number($l['cost_per_m3'] ?? null),
        ];
        foreach (['width', 'thickness', 'length'] as $k) {
            $form[$k] = Num::trimDecimal($form[$k]);
        }
    } else {
        $form['lines'] = array_map(fn ($l) => [
            'type_id' => (string) $l['wood_type_id'],
            'item_id' => (string) $l['item_id'],
            'quantity' => (string) $l['quantity'],
            'price' => doc_edit_number($l['price_per_m3'] ?? null),
        ], $lines);
    }
    return $form;
}

/**
 * يعدّل مستندًا: يلغي الأصلي ويسجل البديل في معاملة واحدة، ويربط بينهما ويسجل التعديل في المراقبة.
 * $in: بيانات النموذج كما تُرسل لـ record_receipt أو record_sale أو record_transfer.
 * @return array{id:int, kind:string, doc_no:int, duplicate:bool, replaced:array}
 */
function document_replace(PDO $pdo, int $userId, int $oldId, array $in, string $reasonRaw): array
{
    $reason = clean_text($reasonRaw);
    if (mb_strlen($reason) > 200) {
        throw new ValidationException(['edit_reason' => 'سبب التعديل أطول من المسموح (200 حرف).']);
    }
    $old = find_document_scoped($pdo, $oldId);
    if (!$old) {
        throw new ValidationException(['form' => 'المستند الأصلي غير موجود.']);
    }
    // إعادة إرسال تعديل سبق حفظه: يُعاد البديل المحفوظ ولا يتكرر شيء
    $token = (string) ($in['request_token'] ?? '');
    if (is_request_token($token)) {
        $stmt = $pdo->prepare('SELECT id, kind, doc_no, replaces_id FROM documents WHERE request_token = ?');
        $stmt->execute([$token]);
        $saved = $stmt->fetch();
        if ($saved && (int) $saved['replaces_id'] === $oldId) {
            return ['id' => (int) $saved['id'], 'kind' => (string) $saved['kind'], 'doc_no' => (int) $saved['doc_no'], 'duplicate' => true, 'replaced' => $old];
        }
    }
    if ($problem = document_edit_problem($pdo, $old)) {
        throw new ValidationException(['form' => $problem]);
    }
    $label = doc_label($old);
    // البديل يحتفظ بتاريخ الأصلي بلا صلاحية التاريخ اليدوي (وأي تاريخ آخر يتطلبها كالمعتاد)
    doc_edit_inherited_date(substr((string) $old['doc_date'], 0, 10));
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($userId, $oldId, $old, $in, $reason, $label) {
            // القيود العكسية بتاريخ الأصلي (والبديل بنفس التاريخ): كشف ذلك اليوم يبقى متوازنًا، وفحص الخزنة بتاريخ سابق يمر.
            // البديل يُسجل في منتصف الإلغاء: بعد ما يرد للمخازن والخزائن وقبل ما يخرج منها، فوارد بيع جزء منه
            // وبيع نُقلت نقديته من الخزنة يُعدلان إذا كانت النتيجة النهائية صحيحة.
            $new = null;
            cancel_document($pdo, $userId, $oldId, 'تعديل' . ($reason !== '' ? ': ' . $reason : ''), (string) $old['doc_date'],
                function (PDO $pdo) use ($userId, $old, $in, &$new) {
                    $new = match ($old['kind']) {
                        'in' => record_receipt($pdo, $userId, $in),
                        'sale' => record_sale($pdo, $userId, $in),
                        'transfer' => record_transfer($pdo, $userId, $in),
                    };
                    if ($new['duplicate']) {
                        // رمز الطلب استُخدم لمستند آخر: لا يُلغى الأصلي
                        throw new ValidationException(['form' => 'هذا النموذج سُجل من قبل. افتح المستند من جديد ثم عدّله.']);
                    }
                    return $new;
                });
            $pdo->prepare('UPDATE documents SET replaces_id = ? WHERE id = ?')->execute([$oldId, $new['id']]);
            $pdo->prepare('UPDATE documents SET replaced_by_id = ?, cancel_reason = ? WHERE id = ?')
                ->execute([$new['id'], mb_substr('عُدّل بـ ' . doc_label(['kind' => $new['kind'], 'doc_no' => $new['doc_no']])
                    . ($reason !== '' ? ': ' . $reason : ''), 0, 255), $oldId]);
            audit_record($pdo, 'doc.edit', sprintf('تعديل %s: أُلغي وسُجل بدلًا منه %s%s', $label,
                doc_label(['kind' => $new['kind'], 'doc_no' => $new['doc_no']]), $reason !== '' ? '. السبب: ' . $reason : ''),
                'document', (int) $new['id'], ['replaces_id' => $oldId, 'reason' => $reason]);
            data_version_bump($pdo);
            return $new + ['replaced' => $old];
        });
    } finally {
        doc_edit_inherited_date(null, true);
    }
}

/**
 * المستند الجاري تعديله من طلب الصفحة (?edit= أو الحقل المخفي edit_id)، بعد فحص النوع والصلاحية.
 * غير موجود أو من نوع آخر: 404. ممنوع تعديله: رسالة ثم العودة لصفحة المستند.
 */
function document_edit_target(PDO $pdo, string $kind): ?array
{
    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $id = (int) ($posted ? input($_POST, 'edit_id') : input($_GET, 'edit'));
    if ($id <= 0) {
        return null;
    }
    $doc = find_document_scoped($pdo, $id);
    if (!$doc || $doc['kind'] !== $kind) {
        render_simple_error('المستند المطلوب تعديله غير موجود.', 404);
    }
    if (!$posted && ($problem = document_edit_problem($pdo, $doc))) {
        flash('error', $problem);
        redirect('document', ['id' => $id]);
    }
    return $doc;
}

/** تنبيه التعديل أعلى النموذج: ماذا سيحدث عند الحفظ */
function document_edit_notice(array $doc): string
{
    return '<div class="alert alert-warning" role="status">تعديل ' . h(doc_label($doc))
        . ': عند الحفظ يُلغى المستند الأصلي بقيود عكسية ويُسجل هذا بدلًا منه برقم جديد، في خطوة واحدة. '
        . 'الأصلي يبقى في السجل بحالة «ملغى» ومربوطًا بالجديد. <a href="' . h(url('document', ['id' => (int) $doc['id']])) . '">تراجع عن التعديل</a></div>';
}

/**
 * حقول التعديل داخل النموذج: المعرف، وسبب التعديل، وتاريخ الأصلي (حقل مخفي لمن لا يملك تغيير التاريخ،
 * لأن البديل يحتفظ بتاريخ الأصلي).
 */
function document_edit_fields(array $doc, array $form, array $errors): string
{
    $html = '<input type="hidden" name="edit_id" value="' . (int) $doc['id'] . '">';
    if (!acct_can('manual_date')) {
        $html .= '<input type="hidden" name="doc_date" value="' . h((string) ($form['doc_date'] ?? '')) . '">';
    }
    $html .= '<div class="field"><label for="edit_reason">سبب التعديل <span class="optional">(اختياري، يظهر في المراقبة)</span></label>'
        . '<input type="text" id="edit_reason" name="edit_reason" maxlength="200" value="' . h((string) ($form['edit_reason'] ?? '')) . '"'
        . field_attrs($errors, 'edit_reason') . '>' . field_error($errors, 'edit_reason') . '</div>';
    return $html;
}

/**
 * مراجعة فاتورة بيع معدلة: نفس فحوص المراجعة، لكن الرصيد المتاح ورصيد العميل بعد رد الفاتورة الأصلية
 * (لم يُحفظ شيء بعد؛ الفحص الملزم داخل معاملة الحفظ). يضيف 'avail' و 'party_balance' للعرض.
 */
function document_edit_review_sale(PDO $pdo, array $old, array $in): array
{
    doc_edit_inherited_date(substr((string) $old['doc_date'], 0, 10));
    try {
        $v = validate_sale($pdo, $in, false);
    } finally {
        doc_edit_inherited_date(null, true);
    }
    $wid = (int) $v['warehouse']['id'];
    $avail = current_stock($pdo, array_map(fn ($l) => (int) $l['item']['id'], $v['lines']), $wid);
    if ((int) $old['warehouse_id'] === $wid) {
        foreach (document_lines($pdo, (int) $old['id']) as $l) {
            $iid = (int) $l['item_id'];
            if (array_key_exists($iid, $avail)) {
                $avail[$iid] = (int) $avail[$iid] + (int) $l['quantity'];
            }
        }
    }
    if ($errors = stock_errors($v['lines'], $avail)) {
        throw new ValidationException($errors);
    }
    $balance = null;
    if ($v['pay']['party']) {
        $balance = money_to_piasters((string) $v['pay']['party']['balance']);
        if ((int) ($old['party_id'] ?? 0) === (int) $v['pay']['party']['id']) {
            $balance -= money_to_piasters((string) $old['total_amount']) - money_to_piasters((string) ($old['paid_amount'] ?? '0'));
        }
        if ($err = credit_limit_error($v['pay']['party'], $balance, $v['pay']['remaining'])) {
            throw new ValidationException(['party_id' => $err]);
        }
    }
    return $v + ['avail' => $avail, 'party_balance' => $balance];
}

/**
 * أثناء تعديل بيع أو تحويل: كميات المستند الأصلي تعود لمخزنه المصدر عند الحفظ، فتُضاف للمتاح في شاشة
 * الإدخال (وإلا لا يستطيع المستخدم حتى إبقاء نفس الكمية). JSON تقرؤه app.js وتطبقه بعد كل تحديث للأرصدة.
 * للعرض فقط؛ الخادم يفحص الرصيد بعد الإلغاء داخل معاملة الحفظ.
 */
function document_edit_stock_script(PDO $pdo, array $old): string
{
    $back = [];
    foreach (document_lines($pdo, (int) $old['id']) as $l) {
        $back[(string) $l['item_id']] = ($back[(string) $l['item_id']] ?? 0) + (int) $l['quantity'];
    }
    return '<script type="application/json" id="edit-stock-back">'
        . json_for_script(['wh' => (string) $old['warehouse_id'], 'items' => (object) $back]) . '</script>';
}

/* ===================== تعديل السندات ===================== */

/** هل يملك المستخدم الحالي صلاحية تعديل هذا السند (وتسجيل نوعه)؟ */
function voucher_edit_permitted(array $v): bool
{
    return acct_can(VOUCHER_PERMISSIONS[$v['kind']])
        && (acct_can('vouchers.edit') || (acct_can('vouchers.edit_own') && voucher_owned_today($v)));
}

/** سبب منع تعديل السند الآن، أو null */
function voucher_edit_problem(array $v): ?string
{
    if ($v['status'] !== 'active') {
        return 'هذا السند ملغى، فلا يُعدل.';
    }
    if (!voucher_edit_permitted($v)) {
        return acct_can('vouchers.edit_own') ? 'تعدّل فقط السندات التي سجلتها أنت اليوم. لتعديل غيرها راجع مدير النظام.'
            : 'ليست لديك صلاحية تعديل السندات. راجع مدير النظام.';
    }
    if (!period_is_open((string) $v['voucher_date'])) {
        return sprintf('لا يمكن تعديل هذا السند: تاريخه %s يقع في فترة مقفلة (حتى %s).',
            digits(substr((string) $v['voucher_date'], 0, 10)), digits(closing_date()));
    }
    return null;
}

/** قيم نموذج السند من السند الأصلي */
function voucher_edit_form(array $v): array
{
    $date = substr((string) $v['voucher_date'], 0, 10);
    return [
        'party_id' => $v['party_id'] !== null ? (string) $v['party_id'] : '',
        'cash_box_id' => (string) $v['cash_box_id'],
        'to_cash_box_id' => $v['to_cash_box_id'] !== null ? (string) $v['to_cash_box_id'] : '',
        'category_id' => $v['category_id'] !== null ? (string) $v['category_id'] : '',
        'amount' => doc_edit_number((string) $v['amount']),
        'reference' => (string) ($v['reference'] ?? ''),
        'notes' => (string) ($v['notes'] ?? ''),
        'voucher_date' => $date === date('Y-m-d') ? '' : $date,
        'request_token' => new_request_token(),
        'confirm_advance' => '',
        'edit_id' => (string) $v['id'],
        'edit_reason' => '',
    ];
}

/**
 * يعدّل سندًا: يلغي الأصلي (قيود عكسية بتاريخه) ويسجل البديل بنفس النوع في معاملة واحدة، ويربط بينهما.
 * @return array{id:int, kind:string, doc_no:int, duplicate:bool}
 */
function voucher_replace(PDO $pdo, int $userId, int $oldId, array $in, string $reasonRaw): array
{
    $reason = clean_text($reasonRaw);
    if (mb_strlen($reason) > 200) {
        throw new ValidationException(['edit_reason' => 'سبب التعديل أطول من المسموح (200 حرف).']);
    }
    $old = find_voucher($pdo, $oldId);
    if (!$old) {
        throw new ValidationException(['form' => 'السند الأصلي غير موجود.']);
    }
    $token = (string) ($in['request_token'] ?? '');
    if (is_request_token($token)) {
        $stmt = $pdo->prepare('SELECT id, kind, doc_no, replaces_id FROM vouchers WHERE request_token = ?');
        $stmt->execute([$token]);
        $saved = $stmt->fetch();
        if ($saved && (int) $saved['replaces_id'] === $oldId) {
            return ['id' => (int) $saved['id'], 'kind' => (string) $saved['kind'], 'doc_no' => (int) $saved['doc_no'], 'duplicate' => true];
        }
    }
    if ($problem = voucher_edit_problem($old)) {
        throw new ValidationException(['form' => $problem]);
    }
    $label = VOUCHER_KIND_LABELS[$old['kind']] . ' رقم ' . $old['doc_no'];
    doc_edit_inherited_date(substr((string) $old['voucher_date'], 0, 10));
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($userId, $oldId, $old, $in, $reason, $label) {
            // البديل يُسجل بين عكس ما يرد للخزنة وعكس ما يخرج منها: نقدية سند قبض نُقلت من الخزنة لا تمنع تعديله
            $new = null;
            cancel_voucher($pdo, $userId, $oldId, 'تعديل' . ($reason !== '' ? ': ' . $reason : ''), (string) $old['voucher_date'], true,
                function (PDO $pdo) use ($userId, $old, $in, &$new) {
                    $new = record_voucher($pdo, $userId, (string) $old['kind'], $in);
                    if ($new['duplicate']) {
                        throw new ValidationException(['form' => 'هذا النموذج سُجل من قبل. افتح السند من جديد ثم عدّله.']);
                    }
                });
            $newLabel = VOUCHER_KIND_LABELS[$new['kind']] . ' رقم ' . $new['doc_no'];
            $pdo->prepare('UPDATE vouchers SET replaces_id = ? WHERE id = ?')->execute([$oldId, $new['id']]);
            $pdo->prepare('UPDATE vouchers SET replaced_by_id = ?, cancel_reason = ? WHERE id = ?')
                ->execute([$new['id'], mb_substr('عُدّل بـ ' . $newLabel . ($reason !== '' ? ': ' . $reason : ''), 0, 255), $oldId]);
            audit_record($pdo, 'voucher.edit', sprintf('تعديل %s: أُلغي وسُجل بدلًا منه %s%s', $label, $newLabel,
                $reason !== '' ? '. السبب: ' . $reason : ''), 'voucher', (int) $new['id'], ['replaces_id' => $oldId, 'reason' => $reason]);
            data_version_bump($pdo);
            return $new;
        });
    } finally {
        doc_edit_inherited_date(null, true);
    }
}
