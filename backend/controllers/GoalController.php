<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../models/Transaction.php';
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
        $goal = $this->goalModel->findById($id, $userId);
        if (!$goal) Response::notFound('Goal not found');
        if ($this->goalModel->countContributions($id, $userId) > 0) {
            Response::error('Delete the goal contributions before deleting this goal', 422);
        }
        try {
            if ($this->goalModel->delete($id, $userId)) {
                Response::success(null, 'Goal deleted successfully');
            }
        } catch (PDOException $e) {
            Response::error('Goal cannot be deleted while linked financial records exist', 422);
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
        
        $errors = Middleware::validateRequired($data, ['amount', 'account_id', 'date', 'client_request_id']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $requestId = trim((string)$data['client_request_id']);
            if (!preg_match('/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|req_[A-Za-z0-9_]{10,60})$/i', $requestId)) {
                Response::error('Validation failed', 422, ['client_request_id' => 'Invalid client request identifier']);
            }
            $resource = $this->accountingService->contributeToGoal(
                $id,
                $userId,
                $data['amount'],
                $data['account_id'],
                $data['date'],
                $data['description'] ?? '',
                $requestId
            );

            $progress = $this->goalModel->getGoalProgress($id, $userId);
            if ($progress && !empty($progress['is_completed'])) {
                $this->notifService->create($userId, 'goal_achieved',
                    'Goal Achieved!',
                    'Congratulations! You have reached your goal target.',
                    'goal', $id);
            }
            Response::success($resource, 'Contribution added successfully', 201);
        } catch (TransactionConflictException $e) {
            Response::error($e->getMessage(), 409);
        } catch (GoalContributionAuthorizationException $e) {
            Response::error('Goal or account not found or access denied', 403);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function contributions($goalId) {
        $userId = Middleware::auth();
        if (!$this->goalModel->findById($goalId, $userId)) Response::notFound('Goal not found');
        Response::success($this->goalModel->findContributions($goalId, $userId));
    }

    public function updateContribution($goalId, $contributionId) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $baseVersion = $this->readContributionVersion($data);
        try {
            $existing = (new Transaction())->findById($contributionId, $userId);
            if (!$existing || (int)($existing['goal_id'] ?? 0) !== (int)$goalId) Response::notFound('Goal contribution not found');
            $resource = $this->accountingService->updateGoalContribution($contributionId, $userId, $data, $baseVersion);
            Response::success($resource, 'Contribution updated successfully');
        } catch (TransactionConflictException $e) {
            Response::error($e->getMessage(), 409);
        } catch (GoalContributionAuthorizationException $e) {
            Response::error('Goal contribution not found or access denied', 403);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function deleteContribution($goalId, $contributionId) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $baseVersion = $this->readContributionVersion($data);
        try {
            $existing = (new Transaction())->findById($contributionId, $userId);
            if (!$existing || (int)($existing['goal_id'] ?? 0) !== (int)$goalId) Response::notFound('Goal contribution not found');
            $this->accountingService->deleteGoalContribution($contributionId, $userId, $baseVersion);
            Response::success(['id' => (int)$contributionId], 'Contribution deleted successfully');
        } catch (TransactionConflictException $e) {
            Response::error($e->getMessage(), 409);
        } catch (GoalContributionAuthorizationException $e) {
            Response::error('Goal contribution not found or access denied', 403);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    private function readContributionVersion(array $data): int {
        $version = filter_var($data['base_version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            Response::error('Validation failed', 422, ['base_version' => 'A valid base version is required']);
        }
        return (int)$version;
    }
}
