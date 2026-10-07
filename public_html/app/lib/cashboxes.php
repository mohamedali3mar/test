<?php
defined('APP_ROOT') || exit;

/*
 * الخزائن وتصنيفات المصروفات (المرحلة الثانية، الوحدة ب).
 *  - الرصيد الافتتاحي للخزنة ≥ 0 ويُحدد عند الإنشاء فقط، ويصبح رصيدها المخزن.
 *    بعد ذلك يتغير الرصيد فقط عبر ledger_cash (السندات والفواتير).
 *  - الإيقاف بدل الحذف، فالخزنة الموقوفة تبقى في الكشوف والتقارير.
 */

function cash_box_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM cash_boxes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** كل الخزائن في نطاق المستخدم (النشطة أولًا) */
function cash_boxes_all(PDO $pdo): array
{
    $rows = cash_boxes_for_user($pdo, false);
    usort($rows, fn ($a, $b) => [(int) $b['is_active'], $a['name'], (int) $a['id']] <=> [(int) $a['is_active'], $b['name'], (int) $b['id']]);
    return $rows;
}

function cash_box_create(PDO $pdo, int $userId, string $rawName, string $rawOpening): int
{
    if (!acct_can('cash.manage')) {
        throw acct_denied('إدارة الخزائن');
    }
    $errors = [];
    $name = $key = '';
    try {
        [$name, $key] = acct_validate_name($rawName, 'name', 'الخزنة', 100);
    } catch (ValidationException $e) {
        $errors += $e->errors;
    }
    $opening = 0;
    if (trim($rawOpening) !== '') {
        [$p, $err] = parse_money_input($rawOpening, 'الرصيد الافتتاحي', true);
        if ($err !== null) {
            $errors['opening_balance'] = $err;
        } else {
            $opening = (int) $p;
        }
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($name, $key, $opening) {
            $pdo->prepare('INSERT INTO cash_boxes (name, name_key, opening_balance, balance, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $key, piasters_to_money($opening), piasters_to_money($opening), now()]);
            $id = (int) $pdo->lastInsertId();
            acct_audit($pdo, 'cash_box.create', sprintf('إضافة خزنة «%s» برصيد افتتاحي %s', $name, fmt_piasters($opening)),
                'cash_box', $id, ['opening_balance' => piasters_to_money($opening)]);
            data_version_bump($pdo);
            return $id;
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => 'توجد خزنة بنفس الاسم.']);
        }
        throw $e;
    }
}

function cash_box_rename(PDO $pdo, int $userId, int $id, string $rawName): void
{
    if (!acct_can('cash.manage')) {
        throw acct_denied('إدارة الخزائن');
    }
    [$name, $key] = acct_validate_name($rawName, 'name', 'الخزنة', 100);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($id, $name, $key) {
            $stmt = $pdo->prepare('SELECT id, name FROM cash_boxes WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new ValidationException(['name' => 'الخزنة غير موجودة.']);
            }
            $pdo->prepare('UPDATE cash_boxes SET name = ?, name_key = ? WHERE id = ?')->execute([$name, $key, $id]);
            acct_audit($pdo, 'cash_box.rename', sprintf('تغيير اسم الخزنة «%s» إلى «%s»', $old['name'], $name), 'cash_box', $id);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => 'توجد خزنة بنفس الاسم.']);
        }
        throw $e;
    }
}

function cash_box_set_active(PDO $pdo, int $userId, int $id, bool $active): void
{
    if (!acct_can('cash.manage')) {
        throw acct_denied('إدارة الخزائن');
    }
    db_transaction($pdo, function (PDO $pdo) use ($id, $active) {
        // كل صفوف الخزائن بترتيب تصاعدي (نفس ترتيب أقفال السندات): إيقافان متزامنان لا يتركان النظام بلا خزنة نشطة
        $all = $pdo->query('SELECT id, name, is_active FROM cash_boxes ORDER BY id FOR UPDATE')->fetchAll();
        $box = null;
        $activeCount = 0;
        foreach ($all as $b) {
            $activeCount += (int) $b['is_active'];
            if ((int) $b['id'] === $id) {
                $box = $b;
            }
        }
        if (!$box) {
            throw new ValidationException(['form' => 'الخزنة غير موجودة.']);
        }
        if (!$active && (int) $box['is_active'] && $activeCount <= 1) {
            throw new ValidationException(['form' => 'يجب أن تبقى خزنة نشطة واحدة على الأقل.']);
        }
        $pdo->prepare('UPDATE cash_boxes SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        acct_audit($pdo, $active ? 'cash_box.activate' : 'cash_box.deactivate',
            sprintf('%s الخزنة «%s»', $active ? 'تفعيل' : 'إيقاف', $box['name']), 'cash_box', $id);
        data_version_bump($pdo);
    });
}

/**
 * كشف حركة خزنة لفترة: رصيد أول المدة، الداخل والخارج والرصيد بعد كل حركة،
 * وملخص «حركة اليوم» لكل يوم (داخل، خارج، رصيد آخر اليوم).
 */
function cash_statement(PDO $pdo, int $boxId, ?string $from, ?string $to): array
{
    $box = cash_box_find($pdo, $boxId);
    if (!$box) {
        throw new ValidationException(['cash_box_id' => 'الخزنة غير موجودة.']);
    }
    $opening = money_to_piasters($box['opening_balance']);
    if ($from !== null) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM cash_ledger WHERE cash_box_id = ? AND entry_date < ?');
        $stmt->execute([$boxId, $from . ' 00:00:00']);
        $opening += money_to_piasters((string) $stmt->fetchColumn());
    }
    $sql = 'SELECT l.id, l.entry_date, l.entry_type, l.amount, l.description, l.document_id, l.voucher_id, l.reversal_of,
                   d.kind AS doc_kind, d.doc_no AS doc_no, v.kind AS voucher_kind, v.doc_no AS voucher_no
            FROM cash_ledger l
            LEFT JOIN documents d ON d.id = l.document_id
            LEFT JOIN vouchers v ON v.id = l.voucher_id
            WHERE l.cash_box_id = ?';
    $args = [$boxId];
    if ($from !== null) {
        $sql .= ' AND l.entry_date >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $sql .= ' AND l.entry_date < ?';
        $args[] = acct_next_day($to);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY l.entry_date, l.id');
    $stmt->execute($args);
    $running = $opening;
    $totIn = 0;
    $totOut = 0;
    $rows = [];
    $days = [];
    foreach ($stmt->fetchAll() as $r) {
        $amount = money_to_piasters($r['amount']);
        $running += $amount;
        $in = max($amount, 0);
        $out = max(-$amount, 0);
        $totIn += $in;
        $totOut += $out;
        $day = substr($r['entry_date'], 0, 10);
        $days[$day] ??= ['date' => $day, 'in' => 0, 'out' => 0, 'count' => 0, 'closing' => 0];
        $days[$day]['in'] += $in;
        $days[$day]['out'] += $out;
        $days[$day]['count']++;
        $days[$day]['closing'] = $running;
        if ($r['document_id'] !== null) {
            $link = ['route' => 'document', 'id' => (int) $r['document_id'],
                'label' => doc_label(['kind' => $r['doc_kind'], 'doc_no' => $r['doc_no']])];
        } else {
            $link = ['route' => 'voucher', 'id' => (int) $r['voucher_id'],
                'label' => voucher_label(['kind' => $r['voucher_kind'], 'doc_no' => $r['voucher_no']])];
        }
        $rows[] = [
            'id' => (int) $r['id'], 'date' => $r['entry_date'], 'day' => $day, 'type' => $r['entry_type'],
            'description' => $r['description'], 'amount' => $amount, 'in' => $in, 'out' => $out,
            'balance' => $running, 'link' => $link, 'is_reversal' => $r['reversal_of'] !== null,
        ];
    }
    return [
        'box' => $box, 'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows,
        'days' => array_values($days), 'total_in' => $totIn, 'total_out' => $totOut, 'closing' => $running,
    ];
}

/* ===================== تصنيفات المصروفات ===================== */

function expense_categories_all(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT c.id, c.name, c.is_active, (SELECT COUNT(*) FROM vouchers v WHERE v.category_id = c.id) AS used
            FROM expense_categories c' . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.is_active DESC, c.name, c.id';
    return $pdo->query($sql)->fetchAll();
}

function expense_category_create(PDO $pdo, int $userId, string $rawName): int
{
    if (!acct_can('cash.manage')) {
        throw acct_denied('إدارة تصنيفات المصروفات');
    }
    [$name, $key] = acct_validate_name($rawName, 'name', 'التصنيف', 100);
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($name, $key) {
            $pdo->prepare('INSERT INTO expense_categories (name, name_key, created_at) VALUES (?, ?, ?)')->execute([$name, $key, now()]);
            $id = (int) $pdo->lastInsertId();
            acct_audit($pdo, 'expense_category.create', sprintf('إضافة تصنيف مصروفات «%s»', $name), 'expense_category', $id);
            data_version_bump($pdo);
            return $id;
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => 'يوجد تصنيف بنفس الاسم.']);
        }
        throw $e;
    }
}

function expense_category_rename(PDO $pdo, int $userId, int $id, string $rawName): void
{
    if (!acct_can('cash.manage')) {
        throw acct_denied('إدارة تصنيفات المصروفات');
    }
    [$name, $key] = acct_validate_name($rawName, 'name', 'التصنيف', 100);
    try {
        db_transaction($pdo, function (PDO $pdo) use ($id, $name, $key) {
            $stmt = $pdo->prepare('SELECT id, name FROM expense_categories WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new ValidationException(['name' => 'التصنيف غير موجود.']);
            }
            $pdo->prepare('UPDATE expense_categories SET name = ?, name_key = ? WHERE id = ?')->execute([$name, $key, $id]);
            acct_audit($pdo, 'expense_category.rename', sprintf('تغيير اسم التصنيف «%s» إلى «%s»', $old['name'], $name), 'expense_category', $id);
            data_version_bump($pdo);
        });
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            throw new ValidationException(['name' => 'يوجد تصنيف بنفس الاسم.']);
        }
        throw $e;
    }
}

function expense_category_set_active(PDO $pdo, int $userId, int $id, bool $active): void
{
    if (!acct_can('cash.manage')) {
        throw acct_denied('إدارة تصنيفات المصروفات');
    }
    db_transaction($pdo, function (PDO $pdo) use ($id, $active) {
        $stmt = $pdo->prepare('SELECT id, name FROM expense_categories WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) {
            throw new ValidationException(['form' => 'التصنيف غير موجود.']);
        }
        $pdo->prepare('UPDATE expense_categories SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        acct_audit($pdo, $active ? 'expense_category.activate' : 'expense_category.deactivate',
            sprintf('%s تصنيف المصروفات «%s»', $active ? 'تفعيل' : 'إيقاف', $c['name']), 'expense_category', $id);
        data_version_bump($pdo);
    });
}
