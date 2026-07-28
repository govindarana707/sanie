<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../services/NotificationService.php';

class BudgetController {
    private $budgetModel;
    private $notifService;

    public function __construct() {
        $this->budgetModel = new Budget();
        $this->notifService = new NotificationService();
    }

    public function index() {
        $userId = Middleware::auth();
        $budgets = $this->budgetModel->findAll($userId);
        Response::success($budgets);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $budget = $this->budgetModel->findById($id, $userId);
        
        if ($budget) {
            Response::success($budget);
        }
        
        Response::notFound('Budget not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['name', 'amount', 'period']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $budgetData = [
            'user_id' => $userId,
            'category_id' => $data['category_id'] ?? null,
            'subcategory_id' => $data['subcategory_id'] ?? null,
            'name' => $data['name'],
            'amount' => $data['amount'],
            'period' => $data['period'],
            'start_date' => $data['start_date'] ?? date('Y-m-01'),
            'end_date' => $data['end_date'] ?? date('Y-m-t'),
            'alert_threshold' => $data['alert_threshold'] ?? 80,
            'is_active' => $data['is_active'] ?? true
        ];

        $budgetId = $this->budgetModel->create($budgetData);
        
        if ($budgetId) {
            $budget = $this->budgetModel->findById($budgetId, $userId);
            $this->notifService->create($userId, 'category_created',
                'Budget Created',
                "Budget \"{$data['name']}\" with limit Rs " . number_format($data['amount'], 0) . " has been created.",
                'budget', $budgetId);
            Response::success($budget, 'Budget created successfully', 201);
        }
        
        Response::serverError('Budget creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingBudget = $this->budgetModel->findById($id, $userId);
        if (!$existingBudget) {
            Response::notFound('Budget not found');
        }

        $budgetData = [
            'category_id' => $data['category_id'] ?? $existingBudget['category_id'],
            'subcategory_id' => $data['subcategory_id'] ?? $existingBudget['subcategory_id'],
            'name' => $data['name'] ?? $existingBudget['name'],
            'amount' => $data['amount'] ?? $existingBudget['amount'],
            'period' => $data['period'] ?? $existingBudget['period'],
            'start_date' => $data['start_date'] ?? $existingBudget['start_date'],
            'end_date' => $data['end_date'] ?? $existingBudget['end_date'],
            'alert_threshold' => $data['alert_threshold'] ?? $existingBudget['alert_threshold'],
            'is_active' => $data['is_active'] ?? $existingBudget['is_active']
        ];

        if ($this->budgetModel->update($id, $userId, $budgetData)) {
            $budget = $this->budgetModel->findById($id, $userId);
            Response::success($budget, 'Budget updated successfully');
        }
        
        Response::serverError('Budget update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        
        if ($this->budgetModel->delete($id, $userId)) {
            Response::success(null, 'Budget deleted successfully');
        }
        
        Response::serverError('Budget deletion failed');
    }

    public function progress($id) {
        $userId = Middleware::auth();
        $progress = $this->budgetModel->getBudgetProgress($id, $userId);
        
        if ($progress) {
            if (isset($progress['percentage']) && $progress['percentage'] >= 100) {
                $this->notifService->create($userId, 'budget_exceeded',
                    'Budget Exceeded',
                    "Your budget has exceeded the limit! (" . round($progress['percentage']) . "% used)",
                    'budget', $id);
            } elseif (isset($progress['percentage']) && $progress['percentage'] >= 80) {
                $this->notifService->create($userId, 'budget_warning',
                    'Budget Warning',
                    "Your budget has reached " . round($progress['percentage']) . "% of the limit.",
                    'budget', $id);
            }
            Response::success($progress);
        }
        
        Response::notFound('Budget not found');
    }
}
