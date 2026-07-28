<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/AccountingService.php';

class GoalController {
    private $goalModel;
    private $notifService;
    private $accountingService;

    public function __construct() {
        $this->goalModel = new Goal();
        $this->notifService = new NotificationService();
        $this->accountingService = new AccountingService();
    }

    public function index() {
        $userId = Middleware::auth();
        $goals = $this->goalModel->findAll($userId);
        Response::success($goals);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $goal = $this->goalModel->findById($id, $userId);
        
        if ($goal) {
            Response::success($goal);
        }
        
        Response::notFound('Goal not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['name', 'target_amount']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $goalData = [
            'user_id' => $userId,
            'name' => $data['name'],
            'target_amount' => $data['target_amount'],
            'current_amount' => $data['current_amount'] ?? 0,
            'deadline' => $data['deadline'] ?? null,
            'icon' => $data['icon'] ?? 'target',
            'color' => $data['color'] ?? '#10B981',
            'description' => $data['description'] ?? '',
            'status' => $data['status'] ?? 'active'
        ];

        $goalId = $this->goalModel->create($goalData);
        
        if ($goalId) {
            $goal = $this->goalModel->findById($goalId, $userId);
            $this->notifService->create($userId, 'goal_created',
                'Goal Created',
                "New goal \"{$data['name']}\" with target Rs " . number_format($data['target_amount'], 0) . " has been created.",
                'goal', $goalId);
            Response::success($goal, 'Goal created successfully', 201);
        }
        
        Response::serverError('Goal creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingGoal = $this->goalModel->findById($id, $userId);
        if (!$existingGoal) {
            Response::notFound('Goal not found');
        }

        $goalData = [
            'name' => $data['name'] ?? $existingGoal['name'],
            'target_amount' => $data['target_amount'] ?? $existingGoal['target_amount'],
            'current_amount' => $data['current_amount'] ?? $existingGoal['current_amount'],
            'deadline' => $data['deadline'] ?? $existingGoal['deadline'],
            'icon' => $data['icon'] ?? $existingGoal['icon'],
            'color' => $data['color'] ?? $existingGoal['color'],
            'description' => $data['description'] ?? $existingGoal['description'],
            'status' => $data['status'] ?? $existingGoal['status']
        ];

        if ($this->goalModel->update($id, $userId, $goalData)) {
            $goal = $this->goalModel->findById($id, $userId);
            Response::success($goal, 'Goal updated successfully');
        }
        
        Response::serverError('Goal update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        
        if ($this->goalModel->delete($id, $userId)) {
            Response::success(null, 'Goal deleted successfully');
        }
        
        Response::serverError('Goal deletion failed');
    }

    public function progress($id) {
        $userId = Middleware::auth();
        $progress = $this->goalModel->getGoalProgress($id, $userId);
        
        if ($progress) {
            Response::success($progress);
        }
        
        Response::notFound('Goal not found');
    }

    public function contribute($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['amount', 'account_id']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $this->accountingService->contributeToGoal(
                $id,
                $userId,
                $data['amount'],
                $data['account_id']
            );

            $progress = $this->goalModel->getGoalProgress($id, $userId);
            if ($progress && isset($progress['status']) && $progress['status'] === 'completed') {
                $this->notifService->create($userId, 'goal_achieved',
                    'Goal Achieved!',
                    'Congratulations! You have reached your goal target.',
                    'goal', $id);
            }
            Response::success($progress, 'Contribution added successfully');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
