<?php
defined('APP_ROOT') || exit;

/*
 * أرقام الصفحة الرئيسية (لكل الأدوار)، كلها ضمن نطاق فرع المستخدم:
 * حركة اليوم (حسب تاريخ المستند)، وإجمالي المخزون، وآخر المستندات، والتحصيلات لمن يرى السندات.
 * قراءة فقط. أرقام الشركة كلها (الأرباح والتكلفة) تبقى في لوحة التحكم للمدير.
 */

const HOME_RECENT = 8;

/** @return array{0:string,1:array} شرط المستندات في نطاق الفرع (فارغ = كل الفروع) */
function home_document_scope(?int $scope): array
{
    return $scope !== null ? document_branch_condition($scope) : ['1 = 1', []];
}

function home_overview(PDO $pdo, ?int $scope, ?string $today = null): array
{
    $day = $today ?? date('Y-m-d');
    $from = $day . ' 00:00:00';
    $to = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d 00:00:00');
    [$cond, $condParams] = home_document_scope($scope);

    // مبيعات اليوم السارية لكل عملة على حدة
    $sales = sales_by_currency($pdo, ' WHERE ' . $cond . ' AND d.doc_date >= ? AND d.doc_date < ?', [...$condParams, $from, $to]);

    $stmt = $pdo->prepare(
        "SELECT d.kind, COUNT(*) AS n, COALESCE(SUM(d.total_qty), 0) AS qty, COALESCE(SUM(d.total_volume_m3), 0) AS volume
         FROM documents d WHERE {$cond} AND d.status = 'active' AND d.kind IN ('in', 'transfer') AND d.doc_date >= ? AND d.doc_date < ?
         GROUP BY d.kind"
    );
    $stmt->execute([...$condParams, $from, $to]);
    $moves = ['in' => ['n' => 0, 'qty' => '0', 'volume' => '0'], 'transfer' => ['n' => 0, 'qty' => '0', 'volume' => '0']];
    foreach ($stmt as $r) {
        $moves[$r['kind']] = ['n' => (int) $r['n'], 'qty' => (string) $r['qty'], 'volume' => Num::trimDecimal((string) $r['volume'])];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE {$cond} AND d.status = 'cancelled' AND d.cancelled_at >= ? AND d.cancelled_at < ?");
    $stmt->execute([...$condParams, $from, $to]);
    $cancelled = (int) $stmt->fetchColumn();

    // المخزون في مخازن النطاق: مقاسات متاحة ونافدة والقطع والحجم
    $whCond = $scope !== null ? ' WHERE s.warehouse_id IN (SELECT id FROM warehouses WHERE branch_id = ?)' : '';
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS items, COALESCE(SUM(CASE WHEN q > 0 THEN 1 ELSE 0 END), 0) AS sizes,
                COALESCE(SUM(CASE WHEN q = 0 THEN 1 ELSE 0 END), 0) AS empty, COALESCE(SUM(q), 0) AS qty, COALESCE(SUM(vol), 0) AS volume
         FROM (SELECT s.item_id, SUM(s.qty_on_hand) AS q, SUM(s.qty_on_hand * i.piece_volume_m3) AS vol
               FROM stock s JOIN items i ON i.id = s.item_id' . $whCond . ' GROUP BY s.item_id) x'
    );
    $stmt->execute($scope !== null ? [$scope] : []);
    $st = $stmt->fetch() ?: [];
    $stock = ['sizes' => (int) ($st['sizes'] ?? 0), 'empty' => (int) ($st['empty'] ?? 0), 'qty' => (string) ($st['qty'] ?? '0'),
        'volume' => Num::trimDecimal((string) ($st['volume'] ?? '0'))];

    $stmt = $pdo->prepare("SELECT d.* FROM documents d WHERE {$cond} ORDER BY d.id DESC LIMIT " . HOME_RECENT);
    $stmt->execute($condParams);
    $recent = $stmt->fetchAll();

    // التحصيلات السارية اليوم (سندات القبض) في فرع المستخدم أو كل الفروع
    $collect = null;
    if (can('vouchers.view')) {
        $stmt = $pdo->prepare(
            "SELECT currency, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM vouchers
             WHERE kind = 'collect' AND status = 'active' AND voucher_date >= ? AND voucher_date < ?"
            . ($scope !== null ? ' AND branch_id = ?' : '') . ' GROUP BY currency ORDER BY currency'
        );
        $stmt->execute($scope !== null ? [$from, $to, $scope] : [$from, $to]);
        $collect = array_map(fn ($r) => ['currency' => (string) $r['currency'], 'count' => (int) $r['n'], 'amount' => (string) $r['amount']], $stmt->fetchAll());
    }

    return ['day' => $day, 'sales' => $sales, 'moves' => $moves, 'cancelled' => $cancelled, 'stock' => $stock, 'recent' => $recent, 'collect' => $collect];
}

/** تاريخ اليوم بالكلمات، مثل «الخميس ٨ أكتوبر ٢٠٢٦» (أسماء الشهور المستخدمة في مصر) */
function home_day_label(string $ymd): string
{
    $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    $d = new DateTimeImmutable($ymd);
    return $days[(int) $d->format('w')] . ' ' . digits($d->format('j')) . ' ' . $months[(int) $d->format('n') - 1] . ' ' . digits($d->format('Y'));
}

/**
 * الأزرار السريعة المسموحة للمستخدم: [المسار، التسمية، فئة الزر، الأيقونة].
 * أزرار الإجراءات بلون نوعها (البيع والوارد والتحويل والنقدية)، وأزرار التصفح محايدة.
 */
function home_actions(): array
{
    $all = [
        ['sell', 'فاتورة بيع', 'sale'],
        ['receive', 'إضافة وارد', 'receive'],
        ['transfer', 'تحويل بين المخازن', 'transfer'],
        ['collect', 'سند قبض', 'collect'],
        ['expense', 'مصروف', 'expense'],
        ['inventory', 'المخزون', 'inventory'],
        ['documents', 'الفواتير والحركات', 'documents'],
    ];
    $out = [];
    foreach ($all as [$route, $label, $icon]) {
        if (can_open($route)) {
            $out[] = [$route, $label, isset(ACTION_KINDS[$route]) ? action_btn_class($route) : '', $icon];
        }
    }
    return $out;
}
