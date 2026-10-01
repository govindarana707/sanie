<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../services/NotificationService.php';

class NotificationController {
    private $service;

    public function __construct() {
        $this->service = new NotificationService();
    }

    public function index() {
        $userId = Middleware::auth();

        $filters = [
            'type'    => $_GET['type'] ?? null,
            'is_read' => isset($_GET['is_read']) ? (int)$_GET['is_read'] : null,
            'search'  => $_GET['search'] ?? null,
            'period'  => $_GET['period'] ?? null,
        ];

        $filters = array_filter($filters, fn($v) => $v !== null);

        $limit  = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);

        $notifications = $this->service->getAll($userId, $filters, $limit, $offset);
        $total         = $this->service->getCount($userId, $filters);
        $unreadCount   = $this->service->getUnreadCount($userId);

        Response::success([
            'notifications' => $notifications,
            'total'         => $total,
            'unread_count'  => $unreadCount,
        ]);
    }

    public function unreadCount() {
        $userId = Middleware::auth();
        try {
            $this->service->processKarobarReminders((int)$userId);
        } catch (Throwable $e) {
            error_log('Karobar reminder processing failed: ' . $e->getMessage());
        }
        $count = $this->service->getUnreadCount($userId);
        Response::success(['unread_count' => $count]);
    }

    public function recent() {
        $userId = Middleware::auth();
        $limit = (int)($_GET['limit'] ?? 10);
        $notifications = $this->service->getRecent($userId, $limit);
        $unreadCount = $this->service->getUnreadCount($userId);
        Response::success([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    public function markRead($id) {
        $userId = Middleware::auth();

        $notification = $this->service->getById($id, $userId);
        if (!$notification) {
            Response::notFound('Notification not found');
        }

        $this->service->markAsRead($id, $userId);
        Response::success(null, 'Notification marked as read');
    }

    public function markAllRead() {
        $userId = Middleware::auth();
        $this->service->markAllAsRead($userId);
        Response::success(null, 'All notifications marked as read');
    }

    public function destroy($id) {
        $userId = Middleware::auth();

        $notification = $this->service->getById($id, $userId);
        if (!$notification) {
            Response::notFound('Notification not found');
        }

        $this->service->delete($id, $userId);
        Response::success(null, 'Notification deleted');
    }

    public function destroyAll() {
        $userId = Middleware::auth();
        $this->service->deleteAll($userId);
        Response::success(null, 'All notifications deleted');
    }
}
