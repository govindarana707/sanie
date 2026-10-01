<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/MoneyValidator.php';

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
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        
        $errors = Middleware::validateRequired($data, ['name', 'target_amount']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            if (isset($data['status']) && $data['status'] !== 'active') {
                throw new InvalidArgumentException('New goals must begin in the active state');
            }
            $initialRaw = array_key_exists('initial_amount', $data) ? $data['initial_amount'] : ($data['current_amount'] ?? 0);
            $initial = MoneyValidator::parse($initialRaw, true, 'Initial amount');
            $goalData = [
                'user_id' => $userId,
                'name' => $this->validateName($data['name']),
                'target_amount' => MoneyValidator::parse($data['target_amount'], false, 'Target amount'),
                'initial_amount' => $initial,
                'current_amount' => $initial,
                'deadline' => $this->validateDeadline($data['deadline'] ?? null),
                'icon' => $this->validateIcon($data['icon'] ?? 'fa-bullseye'),
                'color' => $this->validateColor($data['color'] ?? '#10B981'),
                'description' => trim((string)($data['description'] ?? '')),
                'status' => 'active'
            ];
        } catch (InvalidArgumentException $error) {
            Response::error($error->getMessage(), 422);
        }

        $goalId = $this->goalModel->create($goalData);
        
        if ($goalId) {
            $this->goalModel->synchronizeCompletion($goalId,$userId);
            $goal = $this->goalModel->findById($goalId, $userId);
            $this->notifService->create($userId, 'goal_created',
                'Goal Created',
                "New goal \"{$goalData['name']}\" with target Rs " . number_format($goalData['target_amount'], 2) . " has been created.",
                'goal', $goalId);
            $this->notifService->notifyGoalAchievementOnce($userId,$goalId);
            Response::success($goal, 'Goal created successfully', 201);
        }
        
        Response::serverError('Goal creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        
        $existingGoal = $this->goalModel->findById($id, $userId);
        if (!$existingGoal) {
            Response::notFound('Goal not found');
        }

        try {
            $version = $this->readGoalVersion($data);
            if (array_key_exists('current_amount', $data) || array_key_exists('initial_amount', $data)) {
                throw new InvalidArgumentException('Goal progress can only be changed through contributions');
            }
            $target = array_key_exists('target_amount', $data)
                ? MoneyValidator::parse($data['target_amount'], false, 'Target amount')
                : (float)$existingGoal['target_amount'];
            if ($existingGoal['status'] === 'completed' && abs($target - (float)$existingGoal['target_amount']) > .001) {
                throw new InvalidArgumentException('A completed goal target cannot be changed');
            }
            $status = $this->validateTransition((string)$existingGoal['status'], (string)($data['status'] ?? $existingGoal['status']));
            $goalData = [
                'name' => array_key_exists('name', $data) ? $this->validateName($data['name']) : $existingGoal['name'],
                'target_amount' => $target,
                'deadline' => array_key_exists('deadline', $data) ? $this->validateDeadline($data['deadline']) : $existingGoal['deadline'],
                'icon' => array_key_exists('icon', $data) ? $this->validateIcon($data['icon']) : $existingGoal['icon'],
                'color' => array_key_exists('color', $data) ? $this->validateColor($data['color']) : $existingGoal['color'],
                'description' => array_key_exists('description', $data) ? trim((string)$data['description']) : $existingGoal['description'],
                'status' => $status
            ];
        } catch (InvalidArgumentException $error) {
            Response::error($error->getMessage(), 422);
        }

        if ($this->goalModel->update($id, $userId, $goalData, $version)) {
            $this->goalModel->synchronizeCompletion($id,$userId);
            $goal = $this->goalModel->findById($id, $userId);
            $this->notifService->notifyGoalAchievementOnce($userId,$id);
            Response::success($goal, 'Goal updated successfully');
        }

        if ($this->goalModel->findById($id, $userId)) {
            Response::error('This goal was changed elsewhere. Refresh and try again.', 409);
        }
        Response::notFound('Goal not found');
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

            $this->notifService->notifyGoalAchievementOnce($userId,$id);
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
            $this->notifService->notifyGoalAchievementOnce($userId,$goalId);
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

    private function readGoalVersion(array $data): int {
        $version = filter_var($data['base_version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            throw new InvalidArgumentException('A valid goal version is required');
        }
        return (int)$version;
    }

    private function validateName($value): string {
        $name = trim((string)$value);
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '') throw new InvalidArgumentException('Goal name is required');
        if ($length > 100) throw new InvalidArgumentException('Goal name must be 100 characters or fewer');
        return $name;
    }

    private function validateDeadline($value): ?string {
        if ($value === null || $value === '') return null;
        $text = (string)$value;
        $date = DateTime::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text || (int)$date->format('Y') < 1000 || (int)$date->format('Y') > 9999) {
            throw new InvalidArgumentException('Deadline must be a valid YYYY-MM-DD date');
        }
        return $text;
    }

    private function validateIcon($value): string {
        $icon = trim((string)$value);
        if ($icon === '' || strlen($icon) > 50) throw new InvalidArgumentException('Goal icon is invalid');
        return $icon;
    }

    private function validateColor($value): string {
        $color = trim((string)$value);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) throw new InvalidArgumentException('Goal color must use #RRGGBB format');
        return $color;
    }

    private function validateTransition(string $current, string $requested): string {
        if (!in_array($current, ['active', 'paused', 'completed'], true) || !in_array($requested, ['active', 'paused', 'completed'], true)) {
            throw new InvalidArgumentException('Invalid goal status transition');
        }
        if ($current === 'completed' && $requested !== 'completed') {
            throw new InvalidArgumentException('Completed goals cannot be resumed or reopened');
        }
        if ($requested === 'completed' && $current !== 'completed') {
            throw new InvalidArgumentException('Goals complete automatically when their target is reached');
        }
        if (($current === 'active' && !in_array($requested, ['active', 'paused'], true)) ||
            ($current === 'paused' && !in_array($requested, ['paused', 'active'], true))) {
            throw new InvalidArgumentException('Invalid goal status transition');
        }
        return $requested;
    }
}
