<?php

require_once __DIR__ . '/../models/Notification.php';

class NotificationService {
    private $model;

    const TYPE_MAP = [
        'transaction_added'          => ['icon' => 'fa-exchange-alt', 'color' => '#10B981', 'priority' => 'normal'],
        'transaction_updated'        => ['icon' => 'fa-exchange-alt', 'color' => '#3B82F6', 'priority' => 'normal'],
        'transaction_deleted'        => ['icon' => 'fa-trash', 'color' => '#EF4444', 'priority' => 'low'],
        'budget_warning'             => ['icon' => 'fa-wallet', 'color' => '#F59E0B', 'priority' => 'high'],
        'budget_exceeded'            => ['icon' => 'fa-wallet', 'color' => '#EF4444', 'priority' => 'urgent'],
        'goal_created'               => ['icon' => 'fa-bullseye', 'color' => '#8B5CF6', 'priority' => 'normal'],
        'goal_achieved'              => ['icon' => 'fa-trophy', 'color' => '#10B981', 'priority' => 'high'],
        'goal_overdue'               => ['icon' => 'fa-clock', 'color' => '#EF4444', 'priority' => 'high'],
        'category_created'           => ['icon' => 'fa-folder-plus', 'color' => '#6366F1', 'priority' => 'normal'],
        'category_deleted'           => ['icon' => 'fa-folder-minus', 'color' => '#EF4444', 'priority' => 'low'],
        'subcategory_created'        => ['icon' => 'fa-tag', 'color' => '#6366F1', 'priority' => 'normal'],
        'account_added'              => ['icon' => 'fa-credit-card', 'color' => '#3B82F6', 'priority' => 'normal'],
        'low_balance'                => ['icon' => 'fa-exclamation-triangle', 'color' => '#F59E0B', 'priority' => 'high'],
        'karobar_due'                => ['icon' => 'fa-handshake', 'color' => '#F97316', 'priority' => 'high'],
        'karobar_received'           => ['icon' => 'fa-hand-holding-dollar', 'color' => '#10B981', 'priority' => 'normal'],
        'karobar_paid'               => ['icon' => 'fa-money-bill-wave', 'color' => '#3B82F6', 'priority' => 'normal'],
        'credit_purchase_added'      => ['icon' => 'fa-store', 'color' => '#F97316', 'priority' => 'normal'],
        'credit_purchase_repaid'     => ['icon' => 'fa-handshake', 'color' => '#10B981', 'priority' => 'normal'],
        'overdue_payment'            => ['icon' => 'fa-clock', 'color' => '#EF4444', 'priority' => 'high'],
        'large_borrowing'            => ['icon' => 'fa-triangle-exclamation', 'color' => '#EF4444', 'priority' => 'urgent'],
        'large_lending'              => ['icon' => 'fa-triangle-exclamation', 'color' => '#F59E0B', 'priority' => 'high'],
        'monthly_report'             => ['icon' => 'fa-chart-bar', 'color' => '#8B5CF6', 'priority' => 'normal'],
        'ai_analysis'                => ['icon' => 'fa-brain', 'color' => '#EC4899', 'priority' => 'normal'],
        'backup_completed'           => ['icon' => 'fa-cloud-arrow-up', 'color' => '#10B981', 'priority' => 'normal'],
        'import_completed'           => ['icon' => 'fa-file-import', 'color' => '#10B981', 'priority' => 'normal'],
        'export_completed'           => ['icon' => 'fa-file-export', 'color' => '#10B981', 'priority' => 'normal'],
        'new_device_login'           => ['icon' => 'fa-shield-halved', 'color' => '#F59E0B', 'priority' => 'high'],
        'system'                     => ['icon' => 'fa-bell', 'color' => '#6366F1', 'priority' => 'normal'],
    ];

    const TYPE_LABELS = [
        'transaction_added'          => '💰 Transaction Added',
        'transaction_updated'        => '📝 Transaction Updated',
        'transaction_deleted'        => '🗑️ Transaction Deleted',
        'budget_warning'             => '⚠️ Budget Warning',
        'budget_exceeded'            => '🚫 Budget Exceeded',
        'goal_created'               => '🎯 Goal Created',
        'goal_achieved'              => '🏆 Goal Achieved',
        'goal_overdue'               => '⏰ Goal Overdue',
        'category_created'           => '📂 Category Created',
        'category_deleted'           => '📂 Category Deleted',
        'subcategory_created'        => '🏷️ Subcategory Created',
        'account_added'              => '💳 Account Added',
        'low_balance'                => '📉 Low Balance',
        'karobar_due'                => '🤝 Repayment Due',
        'karobar_received'           => '💰 Money Received',
        'karobar_paid'               => '💸 Money Paid',
        'credit_purchase_added'      => '🛒 Credit Purchase',
        'credit_purchase_repaid'     => '✅ Credit Repaid',
        'overdue_payment'            => '⏰ Overdue Payment',
        'large_borrowing'            => '🚨 Large Borrowing',
        'large_lending'              => '⚠️ Large Lending',
        'monthly_report'             => '📊 Monthly Report',
        'ai_analysis'                => '🤖 AI Analysis',
        'backup_completed'           => '☁️ Backup Complete',
        'import_completed'           => '📥 Import Complete',
        'export_completed'           => '📤 Export Complete',
        'new_device_login'           => '🔐 New Device Login',
        'system'                     => '🔔 System',
    ];

    public function __construct() {
        $this->model = new Notification();
    }

    public function create($userId, $type, $title, $message = '', $referenceType = null, $referenceId = null) {
        $defaults = self::TYPE_MAP[$type] ?? self::TYPE_MAP['system'];
        $label = self::TYPE_LABELS[$type] ?? $title;

        $data = [
            'user_id'        => $userId,
            'type'           => $type,
            'title'          => $title ?: $label,
            'message'        => $message,
            'icon'           => $defaults['icon'],
            'color'          => $defaults['color'],
            'priority'       => $defaults['priority'],
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ];

        return $this->model->create($data);
    }

    public function getAll($userId, $filters = [], $limit = 50, $offset = 0) {
        return $this->model->findAll($userId, $filters, $limit, $offset);
    }

    public function getRecent($userId, $limit = 10) {
        return $this->model->findRecent($userId, $limit);
    }

    public function getUnreadCount($userId) {
        return $this->model->countUnread($userId);
    }

    public function getCount($userId, $filters = []) {
        return $this->model->countAll($userId, $filters);
    }

    public function getById($id, $userId) {
        return $this->model->findById($id, $userId);
    }

    public function markAsRead($id, $userId) {
        return $this->model->markAsRead($id, $userId);
    }

    public function markAllAsRead($userId) {
        return $this->model->markAllAsRead($userId);
    }

    public function delete($id, $userId) {
        return $this->model->delete($id, $userId);
    }

    public function deleteAll($userId) {
        return $this->model->deleteAll($userId);
    }
}
