<?php
declare(strict_types=1);

/*
 * عملية منفصلة لاختبارات تزامن الفروع (tests/integration_branches.php)، باتصال قاعدة بيانات خاص بها.
 * تطبع النتيجة JSON.
 * الاستخدام: php worker_branches.php delete_branch <branch_id>
 *            php worker_branches.php sale <user_id أو 0> <warehouse> <item> <qty> <price>
 * user_id > 0 يحاكي جلسة مستخدم مسجل (لاختبار نطاق الفرع داخل المعاملة).
 */

require __DIR__ . '/lib.php';

$op = $argv[1] ?? '';
$pdo = db();
try {
    if ($op === 'delete_branch') {
        branch_delete($pdo, (int) $argv[2]);
        $r = ['id' => (int) $argv[2]];
    } elseif ($op === 'sale') {
        $userId = (int) $argv[2];
        if ($userId > 0) {
            $_SESSION = ['user_id' => $userId];
        } else {
            $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        }
        $r = record_sale($pdo, $userId, sale_input((int) $argv[3], [[(int) $argv[4], (int) $argv[5], $argv[6]]]));
    } else {
        throw new RuntimeException('unknown op');
    }
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE), "\n";
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'errors' => $e->errors], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
}
