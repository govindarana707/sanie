<?php

require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Task.php';
require_once __DIR__ . '/../services/NotificationService.php';

class TaskController {
    private Task $tasks;
    private const TYPES = ['general','board_study'];
    private const STATUSES = ['pending','in_progress','completed'];
    private const PRIORITIES = ['low','normal','high','urgent'];
    private const SUBJECTS = ['Database Administration','Cloud Computing','Cyber Law & Professional Ethics'];

    public function __construct() { $this->tasks = new Task(); }

    public function index(): void {
        $userId = Middleware::auth();
        $filters = [];
        foreach (['task_type','status','subject','due_date'] as $field) {
            if (isset($_GET[$field])) $filters[$field] = trim((string)$_GET[$field]);
        }
        if (isset($filters['task_type']) && !in_array($filters['task_type'], self::TYPES, true)) Response::error('Invalid task type', 422);
        if (isset($filters['status']) && !in_array($filters['status'], self::STATUSES, true)) Response::error('Invalid task status', 422);
        if (isset($filters['due_date']) && !$this->validDate($filters['due_date'])) Response::error('Invalid due date', 422);
        Response::success($this->tasks->findAll($userId, $filters));
    }

    public function show($id): void {
        $task = $this->tasks->findById($this->id($id), Middleware::auth());
        $task ? Response::success($task) : Response::notFound('Task not found');
    }

    public function deleted(): void { Response::success($this->tasks->findDeleted(Middleware::auth())); }

    public function restore($id): void {
        $userId=Middleware::auth();$taskId=$this->id($id);
        if(!$this->tasks->findAnyById($taskId,$userId))Response::notFound('Task not found');
        if(!$this->tasks->restore($taskId,$userId))Response::notFound('Task not found');
        Response::success($this->tasks->findById($taskId,$userId),'Task restored successfully');
    }

    public function store(): void {
        $userId = Middleware::auth();
        $data = $this->json();
        $data['task_type'] = 'general';
        $normalized = $this->validate($data);
        $id = $this->tasks->create($userId, $normalized);
        Response::success($this->tasks->findById($id, $userId), 'Task created successfully', 201);
    }

    public function update($id): void {
        $userId = Middleware::auth();
        $taskId = $this->id($id);
        $existing = $this->tasks->findById($taskId, $userId);
        if (!$existing) Response::notFound('Task not found');
        $data = array_merge($existing, $this->json());
        $data['task_type'] = $existing['task_type'];
        $normalized = $this->validate($data);
        $this->tasks->update($taskId, $userId, $normalized);
        Response::success($this->tasks->findById($taskId, $userId), 'Task updated successfully');
    }

    public function completion($id): void {
        $userId = Middleware::auth();
        $taskId = $this->id($id);
        if (!$this->tasks->findById($taskId, $userId)) Response::notFound('Task not found');
        $data = $this->json();
        if (!array_key_exists('completed', $data) || !is_bool($data['completed'])) Response::error('Completed must be a boolean', 422);
        $this->tasks->setCompletion($taskId, $userId, $data['completed']);
        Response::success($this->tasks->findById($taskId, $userId), 'Task status updated');
    }

    public function destroy($id): void {
        $userId = Middleware::auth();
        $taskId = $this->id($id);
        if (!$this->tasks->delete($taskId, $userId)) Response::notFound('Task not found');
        Response::success(null, 'Task deleted successfully');
    }

    public function importBoardStudy(): void {
        $userId = Middleware::auth();
        $plan = require __DIR__ . '/../data/board_study_plan.php';
        if (count($plan) !== 45) Response::serverError('Board Study plan data is incomplete');
        $imported = $this->tasks->importBoardPlan($userId, $plan);
        $items = $this->tasks->findAll($userId, ['task_type'=>'board_study']);
        Response::success(['imported'=>$imported,'total'=>count($items),'items'=>$items], $imported ? 'Board Study plan imported' : 'Board Study plan already imported', $imported ? 201 : 200);
    }

    public function processReminders(): void {
        $userId = Middleware::auth();
        $created = (new NotificationService())->processTaskReminders($userId);
        Response::success(['created'=>$created], $created ? "{$created} task reminder(s) delivered" : 'No new task reminders');
    }

    private function validate(array $data): array {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) Response::error('Title is required and must not exceed 255 characters', 422);
        $type = (string)($data['task_type'] ?? 'general');
        $status = (string)($data['status'] ?? 'pending');
        $priority = (string)($data['priority'] ?? 'normal');
        if (!in_array($type, self::TYPES, true) || !in_array($status, self::STATUSES, true) || !in_array($priority, self::PRIORITIES, true)) Response::error('Invalid task value', 422);
        foreach (['due_date','display_date_bs'] as $field) {
            if (!empty($data[$field]) && !$this->validDate((string)$data[$field])) Response::error("Invalid {$field}", 422);
        }
        $subject = isset($data['subject']) ? trim((string)$data['subject']) : null;
        if ($type === 'board_study' && !in_array($subject, self::SUBJECTS, true)) Response::error('Invalid Board Study subject', 422);
        if (array_key_exists('summary_url', $data) && $data['summary_url'] !== null && !is_string($data['summary_url'])) {
            Response::error('Summary link must be a valid HTTPS URL', 422);
        }
        $summaryUrl = trim((string)($data['summary_url'] ?? ''));
        if ($summaryUrl !== '' && !$this->validSummaryUrl($summaryUrl)) {
            Response::error('Summary link must be a valid HTTPS URL', 422);
        }
        return [
            'task_type'=>$type, 'title'=>$title, 'content'=>trim((string)($data['content'] ?? '')) ?: null,
            'due_date'=>($data['due_date'] ?? null) ?: null, 'display_date_bs'=>($data['display_date_bs'] ?? null) ?: null,
            'subject'=>$subject ?: null, 'unit_label'=>trim((string)($data['unit_label'] ?? '')) ?: null,
            'status'=>$status, 'completed_at'=>$data['completed_at'] ?? null, 'priority'=>$priority,
            'reminder_at'=>$this->reminderValue($data['reminder_at'] ?? null),
            'summary_url'=>$summaryUrl ?: null,
        ];
    }

    private function json(): array {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) Response::error('A valid JSON object is required', 422);
        return $data;
    }
    private function id($id): int { if (!ctype_digit((string)$id) || (int)$id < 1) Response::notFound('Task not found'); return (int)$id; }
    private function validDate(string $date): bool { $value = DateTime::createFromFormat('!Y-m-d', $date); return $value && $value->format('Y-m-d') === $date; }
    private function validSummaryUrl(string $url): bool {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && trim((string)($parts['host'] ?? '')) !== '';
    }
    private function reminderValue($value): ?string {
        $raw = trim((string)$value);
        if ($raw === '') return null;
        foreach (['!Y-m-d\\TH:i','!Y-m-d H:i:s'] as $format) {
            $date = DateTime::createFromFormat($format, $raw);
            if ($date && $date->format($format === '!Y-m-d\\TH:i' ? 'Y-m-d\\TH:i' : 'Y-m-d H:i:s') === $raw) return $date->format('Y-m-d H:i:s');
        }
        Response::error('Invalid reminder timestamp', 422);
    }
}
