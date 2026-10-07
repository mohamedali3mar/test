<?php
declare(strict_types=1);

/*
 * عملية منفصلة تنفذ عملية واحدة (بيع/تحويل/إلغاء) باتصال قاعدة بيانات خاص بها،
 * لاختبارات التزامن. تطبع النتيجة JSON.
 * الاستخدام: php worker.php sale <warehouse> <item> <qty> <price> [token]
 *            php worker.php cancel <document_id>
 */

require __DIR__ . '/lib.php';

$op = $argv[1] ?? '';
$pdo = db();
$uid = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
try {
    if ($op === 'sale') {
        $in = sale_input((int) $argv[2], [[(int) $argv[3], (int) $argv[4], $argv[5]]]);
        if (!empty($argv[6])) {
            $in['request_token'] = $argv[6];
        }
        $r = record_sale($pdo, $uid, $in);
    } elseif ($op === 'cancel') {
        $doc = cancel_document($pdo, $uid, (int) $argv[2], '');
        $r = ['id' => (int) $doc['id'], 'duplicate' => false];
    } else {
        throw new RuntimeException('unknown op');
    }
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE), "\n";
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'errors' => $e->errors], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
}
