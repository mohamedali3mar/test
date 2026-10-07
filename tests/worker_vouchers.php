<?php
declare(strict_types=1);

/*
 * عملية منفصلة تسجل سندًا أو تلغيه باتصال قاعدة بيانات خاص بها، لاختبارات التزامن في السندات.
 * الاستخدام: php worker_vouchers.php record <kind> '<json input>'
 *            php worker_vouchers.php cancel <voucher_id>
 */

require __DIR__ . '/lib.php';

$op = $argv[1] ?? '';
$pdo = db();
$uid = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
try {
    if ($op === 'record') {
        $in = json_decode($argv[3] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $r = record_voucher($pdo, $uid, (string) $argv[2], $in);
    } elseif ($op === 'cancel') {
        $v = cancel_voucher($pdo, $uid, (int) $argv[2], '');
        $r = ['id' => (int) $v['id'], 'duplicate' => false];
    } else {
        throw new RuntimeException('unknown op');
    }
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE), "\n";
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'errors' => $e->errors], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
}
