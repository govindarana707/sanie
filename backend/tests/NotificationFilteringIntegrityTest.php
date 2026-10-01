<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/NotificationService.php';

function nfAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$db = (new Database())->getConnection();
$userId = null;
try {
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Notification Filter')")
        ->execute(['notification-filter-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $service = new NotificationService();
    $model = new Notification();
    $warningId = (int)$service->create($userId, 'budget_warning', 'Food warning', 'Food reached its threshold', 'budget', 1);
    $systemId = (int)$service->create($userId, 'system', 'System unread', 'General message');
    $oldId = (int)$service->create($userId, 'budget_warning', 'Old warning', 'Historical warning', 'budget', 2);
    $readId = (int)$service->create($userId, 'system', 'System read', 'Already handled');
    $service->markAsRead($readId, $userId);
    $db->prepare("UPDATE notifications SET created_at='2026-06-01 12:00:00' WHERE id=? AND user_id=?")->execute([$oldId,$userId]);

    nfAssert(count($model->findAll($userId, ['is_read'=>0])) === 3, 'is_read=0 returns unread notifications only');
    nfAssert($model->countAll($userId, ['is_read'=>0]) === 3, 'is_read=0 count matches unread list');
    nfAssert(count($model->findAll($userId, ['is_read'=>1])) === 1, 'is_read=1 returns read notifications only');
    nfAssert($model->countAll($userId, ['is_read'=>1]) === 1, 'is_read=1 count matches read list');
    nfAssert(count($model->findAll($userId)) === 4, 'omitted is_read returns read and unread notifications');
    $search = $model->findAll($userId, ['is_read'=>0,'search'=>'Food']);
    nfAssert(count($search) === 1 && (int)$search[0]['id'] === $warningId, 'is_read=0 composes with search');
    nfAssert(count($model->findAll($userId, ['is_read'=>0,'type'=>'budget_warning'])) === 2, 'is_read=0 composes with type filter');
    nfAssert(count($model->findAll($userId, ['is_read'=>0], 1, 1)) === 1 && $model->countAll($userId, ['is_read'=>0]) === 3, 'is_read=0 composes with pagination and keeps filtered total');
    nfAssert(count($model->findAll($userId, ['is_read'=>0,'period'=>'today'])) === 2, 'is_read=0 composes with period filter');
    nfAssert($model->countUnread($userId) === 3, 'unread count query remains correct');
    nfAssert(count($model->findRecent($userId, 2)) === 2, 'recent notification listing respects its limit');
    nfAssert($service->markAsRead($systemId, $userId) && (int)$model->findById($systemId, $userId)['is_read'] === 1, 'mark one notification read works');
    nfAssert($service->markAllAsRead($userId) && $model->countUnread($userId) === 0, 'mark all notifications read works');
    nfAssert($service->delete($warningId, $userId) && !$model->findById($warningId, $userId), 'delete one notification works');
    nfAssert($service->deleteAll($userId) && $model->countAll($userId) === 0, 'delete all notifications works');
} finally {
    if ($userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}
