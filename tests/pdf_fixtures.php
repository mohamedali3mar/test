<?php
declare(strict_types=1);

/*
 * بيانات تجريبية لملفات PDF، يستخدمها tests/pdf.php و tools/pdf-sample.php.
 * تُنشأ في قاعدة اختبار جديدة فقط (fresh_database).
 */

const PDF_FIXTURE_LONG_TYPE = 'خشب زان أحمر روماني معالج حراريًا مستورد درجة أولى ممتازة للأثاث الفاخر';
const PDF_FIXTURE_CUSTOMER = 'شركة النجار الحديثة للأثاث';
const PDF_FIXTURE_COMPANY = 'شركة الأمل لتجارة الأخشاب';
const PDF_FIXTURE_NOTES = "يسلم في المخزن الرئيسي صباح السبت.\n<script>alert(1)</script> & \"اقتباس\" 'مفرد' <img src=\"/etc/passwd\">";

/**
 * ينشئ أنواعًا ومخازن وواردًا وفاتورة بيع بثلاثة أسطر وتحويلًا وفاتورة ملغاة.
 * @return array{uid:int, receipt:int, sale:int, transfer:int, cancelled:int, rounded:int}
 */
function pdf_seed_fixtures(PDO $pdo): array
{
    $uid = seed_user($pdo, 'pdf_admin');
    save_setting($pdo, 'company_name', PDF_FIXTURE_COMPANY);
    reset_settings_cache();

    $wh1 = catalog_create($pdo, 'warehouse', 'المخزن الرئيسي');
    $wh2 = catalog_create($pdo, 'warehouse', 'مخزن فرع العاشر من رمضان');
    $mosky = catalog_create($pdo, 'type', 'موسكي');
    $long = catalog_create($pdo, 'type', PDF_FIXTURE_LONG_TYPE);
    $pine = catalog_create($pdo, 'type', 'Pine (Finland)');

    $receipt = record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '10', 'cm', '50', 'mm', '3', 'm', '120', [
        'party_name' => 'مؤسسة الخشب الفنلندي', 'reference' => 'BL-2026/0457', 'notes' => 'وارد شحنة أكتوبر',
    ]));
    record_receipt($pdo, $uid, receipt_input($wh1, $long, '20', 'cm', '5', 'cm', '6', 'm', '40'));
    record_receipt($pdo, $uid, receipt_input($wh1, $pine, '2.5', 'cm', '12.5', 'mm', '1.25', 'm', '1500'));
    // مقاس بحجم يحتاج أكثر من 9 خانات عشرية (لظهور ملاحظة التقريب)
    record_receipt($pdo, $uid, receipt_input($wh1, $mosky, '1.234', 'mm', '1.111', 'mm', '1.111', 'mm', '7'));

    $iMosky = item_id_for($pdo, $mosky, 100000, 50000, 3000000);
    $iLong = item_id_for($pdo, $long, 200000, 50000, 6000000);
    $iPine = item_id_for($pdo, $pine, 25000, 12500, 1250000);
    $iTiny = item_id_for($pdo, $mosky, 1234, 1111, 1111);

    $sale = record_sale($pdo, $uid, sale_input($wh1, [
        [$iMosky, 10, '20000'],
        [$iLong, 12, '18500.50'],
        [$iPine, 1250, '9999.99'],
    ], ['party_name' => PDF_FIXTURE_CUSTOMER, 'notes' => PDF_FIXTURE_NOTES]));

    $transfer = record_transfer($pdo, $uid, transfer_input($wh1, $wh2, [[$iMosky, 25], [$iLong, 3]], [
        'notes' => 'تحويل لتغطية طلبات الفرع',
    ]));

    $cancelled = record_sale($pdo, $uid, sale_input($wh1, [[$iMosky, 5, '21000']], ['party_name' => 'عميل نقدي']));
    cancel_document($pdo, $uid, $cancelled['id'], 'خطأ في السعر');

    $rounded = record_sale($pdo, $uid, sale_input($wh1, [[$iTiny, 7, '1500000']], ['party_name' => 'ورشة حسن للنجارة']));

    return [
        'uid' => $uid,
        'receipt' => $receipt['id'],
        'sale' => $sale['id'],
        'transfer' => $transfer['id'],
        'cancelled' => $cancelled['id'],
        'rounded' => $rounded['id'],
    ];
}

/**
 * تقرير حركات تجريبي بعدد صفوف محدد (9 أعمدة، فيُعرض بالعرض).
 * القيم الخام بنفس عقد app/lib/reports.php: أرقام عشرية نصية.
 */
function pdf_sample_report(int $rows): array
{
    $types = ['موسكي', 'زان أحمر', 'Pine (Finland)', PDF_FIXTURE_LONG_TYPE, 'سويد أبيض'];
    $whs = ['المخزن الرئيسي', 'مخزن فرع العاشر من رمضان'];
    $kinds = ['بيع', 'وارد', 'تحويل'];
    $out = [];
    $totQty = 0;
    $totUm3 = '0';
    $totAmount = '0';
    $base = strtotime('2026-01-01 08:00:00');
    for ($i = 1; $i <= $rows; $i++) {
        $qty = ($i * 7) % 300 + 1;
        $volUm3 = Num::mul((string) $qty, '15000000000000000'); // 0.015 م³ للقطعة
        $vol = um3_to_m3($volUm3);
        $amountP = sale_amount_piasters($volUm3, (string) (1500000 + ($i % 50) * 1000));
        $amount = Num::toDecimal($amountP, 2);
        $totQty += $qty;
        $totUm3 = Num::add($totUm3, $volUm3);
        $totAmount = Num::add($totAmount, $amountP);
        $out[] = [
            'created_at' => date('Y-m-d H:i:s', $base + $i * 3700),
            'doc' => $kinds[$i % 3] . ' ' . $i,
            'warehouse' => $whs[$i % 2],
            'type' => $types[$i % 5],
            'size' => '10 سم × 50 مللي × 3 متر',
            'party' => $i % 4 === 0 ? null : 'عميل رقم ' . $i,
            'qty' => (string) $qty,
            'volume' => $vol,
            'amount' => $amount,
        ];
    }
    return [
        'title' => 'تقرير الحركات',
        'subtitle' => 'من 2026-01-01 إلى 2026-12-31، كل المخازن',
        'columns' => [
            ['key' => 'created_at', 'label' => 'التاريخ', 'type' => 'date', 'align' => 'start'],
            ['key' => 'doc', 'label' => 'المستند', 'type' => 'text', 'align' => 'start'],
            ['key' => 'warehouse', 'label' => 'المخزن', 'type' => 'text', 'align' => 'start'],
            ['key' => 'type', 'label' => 'نوع الخشب', 'type' => 'text', 'align' => 'start'],
            ['key' => 'size', 'label' => 'المقاس', 'type' => 'text', 'align' => 'start'],
            ['key' => 'party', 'label' => 'الطرف', 'type' => 'text', 'align' => 'start'],
            ['key' => 'qty', 'label' => 'العدد', 'type' => 'int', 'align' => 'end'],
            ['key' => 'volume', 'label' => 'الحجم (م³)', 'type' => 'volume', 'align' => 'end'],
            ['key' => 'amount', 'label' => 'القيمة', 'type' => 'money', 'align' => 'end'],
        ],
        'rows' => $out,
        'totals' => ['qty' => (string) $totQty, 'volume' => um3_to_m3($totUm3), 'amount' => Num::toDecimal($totAmount, 2)],
        'generated_at' => '2026-10-07 14:35:00',
    ];
}

/**
 * تقرير رصيد صغير بخمسة أعمدة (يُعرض بالطول): فيه اسم بحروف لا يحتويها Cairo (خط احتياطي)،
 * وقيمة سالبة، وقيمة فارغة.
 */
function pdf_sample_inventory_report(): array
{
    return [
        'title' => 'تقرير الرصيد',
        'subtitle' => 'المخزن الرئيسي',
        'columns' => [
            ['key' => 'type', 'label' => 'نوع الخشب', 'type' => 'text', 'align' => 'start'],
            ['key' => 'size', 'label' => 'المقاس', 'type' => 'text', 'align' => 'start'],
            ['key' => 'qty', 'label' => 'العدد', 'type' => 'int', 'align' => 'end'],
            ['key' => 'volume', 'label' => 'الحجم (م³)', 'type' => 'volume', 'align' => 'end'],
            ['key' => 'value', 'label' => 'فرق القيمة', 'type' => 'money', 'align' => 'end'],
        ],
        'rows' => [
            ['type' => 'موسكي', 'size' => '10 سم × 50 مللي × 3 متر', 'qty' => '120', 'volume' => '1.800000000000000000', 'value' => '-12.50'],
            ['type' => 'Дуб (oak)', 'size' => '20 سم × 5 سم × 6 متر', 'qty' => 40, 'volume' => '2.4', 'value' => null],
            ['type' => PDF_FIXTURE_LONG_TYPE, 'size' => '2.5 سم × 12.5 مللي × 1.25 متر', 'qty' => '1500', 'volume' => '0.5859375', 'value' => '3000.00'],
        ],
        'totals' => ['qty' => '1660', 'volume' => '4.7859375', 'value' => '2987.50'],
        'generated_at' => '2026-10-07 09:05:00',
    ];
}
