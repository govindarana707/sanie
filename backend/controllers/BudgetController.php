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
        $progress=$this->budgetModel->getBatchProgress(array_column($budgets,'id'),$userId);
        $byId=[];foreach($progress as$row)$byId[(int)$row['budget_id']]=$row;
        foreach($budgets as&$budget){$row=$byId[(int)$budget['id']]??null;$budget['used']=$row['spent']??0;$budget['spent']=$budget['used'];$budget['remaining']=$row['remaining']??(float)$budget['amount'];$budget['percentage']=$row['percentage']??0;}
        Response::success($budgets);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $budget = $this->budgetModel->findById($id, $userId);
        
        if ($budget) {
            $progress=$this->budgetModel->getBudgetProgress($id,$userId);
            Response::success(array_merge($budget,['used'=>$progress['spent'],'spent'=>$progress['spent'],'remaining'=>$progress['remaining'],'percentage'=>$progress['percentage']]));
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

        try {
            $budgetId = $this->budgetModel->create($budgetData);
        } catch (DuplicateBudgetException $e) {
            Response::error($e->getMessage(), 409, [
                'code' => 'BUDGET_DUPLICATE',
                'existing_budget' => $e->existingBudget,
            ]);
        } catch (BudgetValidationException $e) {
            Response::error($e->getMessage(), 422);
        }
        
        if ($budgetId) {
            $budget = $this->budgetModel->findById($budgetId, $userId);
            $progress = $this->budgetModel->getBudgetProgress($budgetId, $userId);
            $budget = array_merge($budget, [
                'used' => $progress['spent'], 'spent' => $progress['spent'],
                'remaining' => $progress['remaining'], 'percentage' => $progress['percentage']
            ]);
            $this->notifService->create($userId, 'category_created',
                'Budget Created',
                "Budget \"{$data['name']}\" with limit Rs " . number_format($data['amount'], 0) . " has been created.",
                'budget', $budgetId);
            $this->notifService->syncUserBudgetAlertStates($userId);
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
            'category_id' => array_key_exists('category_id',$data)?$data['category_id']:$existingBudget['category_id'],
            'subcategory_id' => array_key_exists('subcategory_id',$data)
                ?$data['subcategory_id']
                :(array_key_exists('category_id',$data)&&$data['category_id']!=$existingBudget['category_id']?null:$existingBudget['subcategory_id']),
            'name' => $data['name'] ?? $existingBudget['name'],
            'amount' => $data['amount'] ?? $existingBudget['amount'],
            'period' => $data['period'] ?? $existingBudget['period'],
            'start_date' => $data['start_date'] ?? $existingBudget['start_date'],
            'end_date' => $data['end_date'] ?? $existingBudget['end_date'],
            'alert_threshold' => $data['alert_threshold'] ?? $existingBudget['alert_threshold'],
            'is_active' => $data['is_active'] ?? $existingBudget['is_active']
        ];

        try{$updated=$this->budgetModel->update($id,$userId,$budgetData);}catch(BudgetValidationException$e){Response::error($e->getMessage(),422);}
        if ($updated) {
            $budget = $this->budgetModel->findById($id, $userId);
            $progress = $this->budgetModel->getBudgetProgress($id, $userId);
            $budget = array_merge($budget, [
                'used' => $progress['spent'], 'spent' => $progress['spent'],
                'remaining' => $progress['remaining'], 'percentage' => $progress['percentage']
            ]);
            $this->notifService->syncUserBudgetAlertStates($userId);
            Response::success($budget, 'Budget updated successfully');
        }
        
        Response::serverError('Budget update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        if(!$this->budgetModel->findById($id,$userId))Response::notFound('Budget not found');
        if ($this->budgetModel->delete($id, $userId)) {
            Response::success(null, 'Budget deleted successfully');
        }
        
        Response::serverError('Budget deletion failed');
    }

    public function progress($id) {
        $userId = Middleware::auth();
        $progress = $this->budgetModel->getBudgetProgress($id, $userId);
        
        if ($progress) Response::success($progress);
        
        Response::notFound('Budget not found');
    }

    public function bulkStore() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        $budgets = $data['budgets'] ?? [];

        if (empty($budgets)) {
            Response::error('No budgets provided', 422);
        }

        $errors = [];
        $valid = [];
        foreach ($budgets as $i => $b) {
            $errs = [];
            if (empty($b['name'])) $errs[] = "Row $i: name required";
            if (empty($b['amount']) || (float)$b['amount'] <= 0) $errs[] = "Row $i: valid amount required";
            if (!empty($errs)) {
                $errors = array_merge($errors, $errs);
                continue;
            }
            $valid[] = [
                'name' => $b['name'],
                'amount' => (float)$b['amount'],
                'period' => $b['period'] ?? 'monthly',
                'category_id' => $b['category_id'] ?? null,
                'subcategory_id' => $b['subcategory_id'] ?? null,
                'start_date' => $b['start_date'] ?? date('Y-m-01'),
                'end_date' => $b['end_date'] ?? date('Y-m-t'),
                'alert_threshold' => $b['alert_threshold'] ?? 80,
                'is_active' => $b['is_active'] ?? true
            ];
        }

        if (!empty($errors)) {
            Response::error('Validation failed', 422, ['details' => $errors]);
        }

        try{$insertedIds = $this->budgetModel->bulkCreate($valid, $userId);}catch(BudgetValidationException$e){Response::error($e->getMessage(),422);}

        if ($insertedIds === false) {
            Response::serverError('Bulk budget creation failed');
        }

        $this->notifService->syncUserBudgetAlertStates($userId);

        Response::success([
            'created' => count($insertedIds),
            'ids' => $insertedIds
        ], count($insertedIds) . ' budget(s) created successfully', 201);
    }

    public function suggestions() {
        $userId = Middleware::auth();
        $period = $_GET['period'] ?? 'monthly';
        $months = max(1, min(12, (int)($_GET['months'] ?? 3)));
        $suggestions = $this->budgetModel->getSuggestions($userId, $period, $months);
        Response::success($suggestions);
    }

    public function copyPrevious() {
        $userId = Middleware::auth();
        $period = $_GET['period'] ?? 'monthly';
        $source = $_GET['source'] ?? 'month'; // 'month' or 'year'

        // Calculate source date range (previous month or previous year)
        if ($source === 'year') {
            $startDate = date('Y-m-01', strtotime('-1 year'));
            $endDate = date('Y-m-t', strtotime('-1 year'));
        } else {
            $startDate = date('Y-m-01', strtotime('-1 month'));
            $endDate = date('Y-m-t', strtotime('-1 month'));
        }

        $budgets = $this->budgetModel->findByPeriod($userId, $period, $startDate, $endDate);

        // Map to current period with adjusted dates
        $now = new DateTime();
        $currentStart = $now->format('Y-m-01');
        $currentEnd = $now->format('Y-m-t');
        $currentYear = $now->format('Y');

        $result = array_map(function ($b) use ($currentStart, $currentEnd, $period) {
            return [
                'name' => $b['name'],
                'amount' => (float)$b['amount'],
                'period' => $period,
                'category_id' => $b['category_id'],
                'subcategory_id' => $b['subcategory_id'],
                'category_name' => $b['category_name'] ?? null,
                'category_icon' => $b['category_icon'] ?? null,
                'category_color' => $b['category_color'] ?? null,
                'subcategory_name' => $b['subcategory_name'] ?? null,
                'scope_label' => $b['scope_label'] ?? null,
                'start_date' => $currentStart,
                'end_date' => $currentEnd
            ];
        }, $budgets);

        Response::success($result);
    }

    public function copyToMonth() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            $result = $this->budgetModel->copyMonthlyBudgets(
                $userId,
                $data['budget_ids'] ?? [],
                (string)($data['source_month'] ?? ''),
                (string)($data['target_month'] ?? '')
            );
        } catch (BudgetValidationException $e) {
            Response::error($e->getMessage(), 422);
        }
        if ($result === false) Response::serverError('Budget copy failed');
        $this->notifService->syncUserBudgetAlertStates($userId);
        $message = $result['created'] . ' budget(s) copied successfully';
        if (!empty($result['skipped'])) $message .= '; ' . count($result['skipped']) . ' already existed';
        Response::success($result, $message, $result['created'] ? 201 : 200);
    }

    public function progressBatch() {
        $userId = Middleware::auth();
        $ids = isset($_GET['ids']) ? array_map('intval', explode(',', $_GET['ids'])) : [];
        if (empty($ids)) {
            Response::success([]);
            return;
        }
        $startDate = $this->queryDate('start_date');
        $endDate = $this->queryDate('end_date');
        if (($startDate === null) !== ($endDate === null)) {
            Response::error('Both start_date and end_date are required', 422);
        }
        if ($startDate !== null && $startDate > $endDate) {
            Response::error('start_date cannot be after end_date', 422);
        }
        $progress = $this->budgetModel->getBatchProgress($ids, $userId, $startDate, $endDate);
        Response::success($progress);
    }

    public function aggregate() {
        $userId=Middleware::auth();
        $ids=isset($_GET['ids'])?array_map('intval',explode(',',$_GET['ids'])):[];
        if(empty($ids)){Response::success(['allocated_budget'=>0,'unique_spent'=>0,'remaining'=>0,'budget_count'=>0,'transaction_count'=>0]);return;}
        $startDate=$this->queryDate('start_date');$endDate=$this->queryDate('end_date');
        if(($startDate===null)!==($endDate===null))Response::error('Both start_date and end_date are required',422);
        if($startDate!==null&&$startDate>$endDate)Response::error('start_date cannot be after end_date',422);
        Response::success($this->budgetModel->getAggregateProgress($ids,$userId,$startDate,$endDate));
    }

    private function queryDate(string $key): ?string {
        if (!isset($_GET[$key]) || $_GET[$key] === '') return null;
        $value = (string)$_GET[$key];
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            Response::error("Invalid {$key}", 422);
        }
        return $value;
    }
}
