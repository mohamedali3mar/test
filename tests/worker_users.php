<?php
declare(strict_types=1);

/*
 * عملية منفصلة تنفذ عملية إدارة مستخدمين واحدة باتصال قاعدة بيانات خاص بها، لاختبارات التزامن.
 * تطبع النتيجة JSON.
 * الاستخدام: php worker_users.php demote <actor_id> <target_id>
 *            php worker_users.php disable <actor_id> <target_id>
 */

require __DIR__ . '/lib.php';

$op = $argv[1] ?? '';
$actor = (int) ($argv[2] ?? 0);
$target = (int) ($argv[3] ?? 0);
$pdo = db();
$me = user_find($pdo, $actor);
// جلسة المنفذ كما في الطلب الحقيقي، حتى يُنسب سطر سجل المراقبة إليه
$_SESSION = ['user_id' => $actor, 'username' => (string) ($me['username'] ?? ''), 'role' => $me ? user_role($me) : ''];
try {
    if ($op === 'demote') {
        $u = user_find($pdo, $target);
        user_update($pdo, $actor, $target, ['display_name' => (string) $u['display_name'], 'role' => 'staff']);
    } elseif ($op === 'disable') {
        user_set_active($pdo, $actor, $target, false);
    } else {
        throw new RuntimeException('unknown op');
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE), "\n";
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'errors' => $e->errors], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
}
