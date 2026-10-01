<?php

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Task.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../models/NotificationEvent.php';
require_once __DIR__ . '/KarobarOutstandingService.php';

class NotificationService {
    private $model;
    private NotificationEvent $events;

    const TYPE_MAP = [
        'transaction_added'          => ['icon' => 'fa-exchange-alt', 'color' => '#10B981', 'priority' => 'normal'],
        'transaction_updated'        => ['icon' => 'fa-exchange-alt', 'color' => '#3B82F6', 'priority' => 'normal'],
        'transaction_deleted'        => ['icon' => 'fa-trash', 'color' => '#EF4444', 'priority' => 'low'],
        'budget_warning'             => ['icon' => 'fa-wallet', 'color' => '#F59E0B', 'priority' => 'high'],
        'budget_exceeded'            => ['icon' => 'fa-wallet', 'color' => '#EF4444', 'priority' => 'urgent'],
        'budget_normal'              => ['icon' => 'fa-wallet', 'color' => '#10B981', 'priority' => 'normal'],
        'task_reminder'              => ['icon' => 'fa-list-check', 'color' => '#6366F1', 'priority' => 'high'],
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
        'budget_normal'              => '✅ Budget Back on Track',
        'task_reminder'              => '⏰ Task Reminder',
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
        $this->events = new NotificationEvent();
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

    public function createDurable(int $userId, string $eventKey, string $eventType, string $type, string $title, string $message = '', ?string $referenceType = null, ?int $referenceId = null, ?string $occurredAt = null) {
        if ($eventKey === '' || strlen($eventKey) > 191) throw new InvalidArgumentException('Notification event key is invalid.');
        $defaults = self::TYPE_MAP[$type] ?? self::TYPE_MAP['system'];
        return $this->events->claimAndCreate([
            'user_id'=>$userId, 'event_key'=>$eventKey, 'event_type'=>$eventType,
            'source_type'=>$referenceType, 'source_id'=>$referenceId, 'occurred_at'=>$occurredAt,
        ], [
            ':user_id'=>$userId, ':type'=>$type, ':title'=>$title ?: (self::TYPE_LABELS[$type] ?? $title),
            ':message'=>$message, ':icon'=>$defaults['icon'], ':color'=>$defaults['color'],
            ':priority'=>$defaults['priority'], ':reference_type'=>$referenceType, ':reference_id'=>$referenceId,
        ]);
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

    public function syncBudgetAlertState($userId, array $progress): bool {
        $budget=$progress['budget']??[];$budgetId=(int)($progress['budget_id']??($budget['id']??0));
        if($budgetId<1)return false;
        $percentage=(float)($progress['percentage']??0);$threshold=(float)($budget['alert_threshold']??80);
        $state=$percentage>=100?'budget_exceeded':($percentage>=$threshold?'budget_warning':'budget_normal');
        $types=['budget_warning','budget_exceeded','budget_normal'];
        $latest=$this->events->findLatestBySourceTypes((int)$userId,'budget',$budgetId,$types);
        if(!$latest){
            $legacy=$this->model->findLatestByReferenceTypes($userId,'budget',$budgetId,$types);
            if($legacy){
                $this->events->claimOnly((int)$userId,'legacy:notification:'.(int)$legacy['id'],(string)$legacy['type'],'budget',$budgetId,(string)$legacy['created_at']);
                $latest=$this->events->findLatestBySourceTypes((int)$userId,'budget',$budgetId,$types);
            }
        }
        if(($latest['event_type']??null)===$state||(!$latest&&$state==='budget_normal'))return false;
        if($state==='budget_exceeded'){$title='Budget Exceeded';$message='Your budget has exceeded the limit! ('.round($percentage).'% used)';}
        elseif($state==='budget_warning'){$title='Budget Warning';$message='Your budget has reached '.round($percentage).'% of the limit.';}
        else{$title='Budget Back on Track';$message='Your budget usage is back below the warning threshold.';}
        $eventKey="budget:{$budgetId}:{$state}:after:".(int)($latest['id']??0);
        return(bool)$this->createDurable((int)$userId,$eventKey,$state,$state,$title,$message,'budget',$budgetId);
    }

    public function syncUserBudgetAlertStates($userId): int {
        try {
            $budgetModel = new Budget();
            $budgets = array_values(array_filter($budgetModel->findAll($userId), fn($budget) => !empty($budget['is_active'])));
            if (!$budgets) return 0;
            $created = 0;
            foreach ($budgetModel->getBatchProgress(array_column($budgets, 'id'), $userId) as $progress) {
                if ($this->syncBudgetAlertState($userId, $progress)) $created++;
            }
            return $created;
        } catch (Throwable $e) {
            error_log('Budget notification synchronization failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function processTaskReminders($userId, ?string $now = null): int {
        $now = $now ?: date('Y-m-d H:i:s');
        $tasks = (new Task())->findDueReminders((int)$userId, $now);
        $created = 0;
        foreach ($tasks as $task) {
            $message = 'Task reminder is due (scheduled for ' . $task['reminder_at'] . ').';
            $eventKey='task:'.(int)$task['id'].':reminder:'.str_replace(' ','T',(string)$task['reminder_at']);
            if($this->events->findByKey((int)$userId,$eventKey))continue;
            $latest = $this->model->findLatestByReferenceTypes($userId, 'task', (int)$task['id'], ['task_reminder']);
            if ($latest && $latest['message'] === $message) {
                $this->events->claimOnly((int)$userId,$eventKey,'task_reminder','task',(int)$task['id'],(string)$task['reminder_at']);
                continue;
            }
            if ($this->createDurable((int)$userId,$eventKey,'task_reminder','task_reminder',$task['title'],$message,'task',(int)$task['id'],(string)$task['reminder_at'])) $created++;
        }
        return $created;
    }

    public function notifyGoalAchievementOnce($userId, $goalId): bool {
        $goal=(new Goal())->findById($goalId,$userId);
        if(!$goal||$goal['status']!=='completed'||(float)$goal['current_amount']<(float)$goal['target_amount'])return false;
        $eventKey='goal:'.(int)$goalId.':completed';
        if($this->events->findByKey((int)$userId,$eventKey))return false;
        if($this->model->findLatestByReferenceTypes($userId,'goal',(int)$goalId,['goal_achieved'])){
            $this->events->claimOnly((int)$userId,$eventKey,'goal_achieved','goal',(int)$goalId);
            return false;
        }
        return(bool)$this->createDurable((int)$userId,$eventKey,'goal_achieved','goal_achieved','Goal Achieved!',"Congratulations! You've reached your savings goal \"{$goal['name']}\".",'goal',(int)$goalId);
    }

    public function notifyRecurringReviewOnce(int $userId,int $definitionId,string $firstDate,int $count):bool{
        $message="{$count} recurring occurrences beginning {$firstDate} require Generate or Skip review.";
        $eventKey="recurring:{$definitionId}:review:{$firstDate}:{$count}";
        if($this->events->findByKey($userId,$eventKey))return false;
        $latest=$this->model->findLatestByReferenceTypes($userId,'recurring_transaction',$definitionId,['system']);
        if($latest&&$latest['message']===$message){$this->events->claimOnly($userId,$eventKey,'recurring_review','recurring_transaction',$definitionId);return false;}
        return(bool)$this->createDurable($userId,$eventKey,'recurring_review','system','Recurring Transactions Need Review',$message,'recurring_transaction',$definitionId);
    }

    public function notifyRecurringGeneratedOnce(int $userId,int $definitionId,string $occurrenceDate,int $transactionId,string $message):bool{
        return(bool)$this->createDurable($userId,"recurring:{$definitionId}:{$occurrenceDate}:generated",'recurring_generated','transaction_added','Recurring Transaction Generated',$message,'transaction',$transactionId,$occurrenceDate.' 00:00:00');
    }

    public function notifyRecurringProblemOnce(int $userId,int $definitionId,string $message):bool{
        $identity=hash('sha256',$message);
        return(bool)$this->createDurable($userId,"recurring:{$definitionId}:problem:{$identity}",'recurring_problem','system','Recurring Transaction Needs Attention',$message,'recurring_transaction',$definitionId);
    }

    /** Read-only evaluator for due-today and overdue Karobar origins. */
    public function processKarobarReminders(int $userId,?string $now=null):array{
        $timezone=new DateTimeZone('Asia/Kathmandu');
        $instant=$now!==null?new DateTimeImmutable($now,$timezone):new DateTimeImmutable('now',$timezone);
        $today=$instant->setTimezone($timezone)->format('Y-m-d');
        $result=['due'=>0,'overdue'=>0,'already_delivered'=>0,'eligible'=>0];
        foreach((new KarobarOutstandingService())->getOrigins($userId,['comparison_date'=>$today])as$origin){
            $dueDate=$origin['due_date']??null;
            if(!$dueDate||($origin['person_status']??'active')!=='active'||(float)$origin['outstanding_amount']<=0)continue;
            $eventType=$dueDate===$today?'due':($dueDate<$today?'overdue':null);
            if($eventType===null)continue;
            $result['eligible']++;
            $id=(int)$origin['id'];$eventKey="karobar:{$id}:{$dueDate}:{$eventType}";
            if($this->events->findByKey($userId,$eventKey)){$result['already_delivered']++;continue;}
            $person=(string)$origin['person_name'];$amount=number_format((float)$origin['outstanding_amount'],2);
            $receivable=($origin['direction']??'')==='receivable';
            if($eventType==='due'){
                $title=$receivable?'Money Due Today':'Payment Due Today';
                $message=$receivable?"Rs {$amount} from {$person} is due today.":"Rs {$amount} payable to {$person} is due today.";
                $type='karobar_due';
            }else{
                $title=$receivable?'Money Overdue':'Payment Overdue';
                $message=$receivable?"Rs {$amount} from {$person} has been overdue since {$dueDate}.":"Rs {$amount} payable to {$person} has been overdue since {$dueDate}.";
                $type='overdue_payment';
            }
            if($this->createDurable($userId,$eventKey,'karobar_'.$eventType,$type,$title,$message,'karobar',$id,$dueDate.' 00:00:00'))$result[$eventType]++;
            else$result['already_delivered']++;
        }
        return$result;
    }
}
