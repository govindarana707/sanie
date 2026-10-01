<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../services/ReportingPaginationService.php';

class ReportsController {
    private $transactionModel;
    private $budgetModel;
    private $reportingService;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->budgetModel = new Budget();
        $this->reportingService = new ReportingPaginationService();
    }

    public function incomeExpense() {
        $userId = Middleware::auth();

        $filters = [
            'start_date'=>$_GET['start_date']??date('Y-01-01'),'end_date'=>$_GET['end_date']??date('Y-12-31'),
            'type'=>$_GET['type']??null,'category_id'=>$_GET['category_id']??null,'subcategory_id'=>$_GET['subcategory_id']??null,
            'account_id'=>$_GET['account_id']??null,'search'=>trim((string)($_GET['search']??''))
        ];
        if (array_key_exists('scope', $_GET)) {
            if (!in_array($_GET['scope'], ['all','personal','karobar'], true)) Response::error('Invalid transaction scope.',422);
            $filters['scope']=$_GET['scope'];
        }
        $page=max(1,(int)($_GET['page']??1));$limit=max(1,min(200,(int)($_GET['limit']??50)));
        if(!$this->validDate($filters['start_date'])||!$this->validDate($filters['end_date'])||$filters['start_date']>$filters['end_date'])Response::error('Invalid report date range.',422);
        $result=$this->reportingService->incomeExpensePage($userId,$filters,$page,$limit);
        $result['statistics']=$result['summary'];
        Response::success($result);
    }

    public function categoryBreakdown() {
        $userId = Middleware::auth();

        $startDate = $_GET['start_date'] ?? date('Y-01-01');
        $endDate = $_GET['end_date'] ?? date('Y-12-31');

        $breakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, 'expense');

        $totalSpent = 0;
        foreach ($breakdown as $b) {
            $totalSpent += (float)$b['total_amount'];
        }

        $categories = [];
        foreach ($breakdown as $b) {
            $amt = (float)$b['total_amount'];
            $categories[] = [
                'category_name' => $b['category_name'],
                'category_icon' => $b['category_icon'] ?? '',
                'category_color' => $b['category_color'] ?? '#6B7280',
                'total_amount' => $amt,
                'transaction_count' => (int)$b['transaction_count'],
                'percentage' => $totalSpent > 0 ? round(($amt / $totalSpent) * 100, 1) : 0
            ];
        }

        $incomeBreakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, 'income');
        $totalIncome = 0;
        foreach ($incomeBreakdown as $b) {
            $totalIncome += (float)$b['total_amount'];
        }

        $incomeCategories = [];
        foreach ($incomeBreakdown as $b) {
            $amt = (float)$b['total_amount'];
            $incomeCategories[] = [
                'category_name' => $b['category_name'],
                'category_icon' => $b['category_icon'] ?? '',
                'category_color' => $b['category_color'] ?? '#6B7280',
                'total_amount' => $amt,
                'transaction_count' => (int)$b['transaction_count'],
                'percentage' => $totalIncome > 0 ? round(($amt / $totalIncome) * 100, 1) : 0
            ];
        }

        Response::success([
            'expense_categories' => $categories,
            'income_categories' => $incomeCategories,
            'total_expense' => $totalSpent,
            'total_income' => $totalIncome
        ]);
    }

    public function budgetHealth() {
        $userId = Middleware::auth();
        $startDate = $_GET['start_date'] ?? date('Y-01-01');
        $endDate = $_GET['end_date'] ?? date('Y-12-31');
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $startDate > $endDate) {
            Response::error('Invalid report date range.', 422);
        }

        $budgets = array_values(array_filter(
            $this->budgetModel->findAll($userId),
            fn($budget) => ($budget['start_date'] ?? '') <= $endDate && ($budget['end_date'] ?? '') >= $startDate
        ));
        $budgetIds = array_map(fn($budget) => (int)$budget['id'], $budgets);
        $progressRows = $this->budgetModel->getBatchProgress($budgetIds, $userId, $startDate, $endDate);
        $progressById = [];
        foreach ($progressRows as $progress) $progressById[(int)$progress['budget_id']] = $progress;
        $reportData = [];

        foreach ($budgets as $budget) {
            $progress = $progressById[(int)$budget['id']] ?? null;

            $amount = (float)$budget['amount'];
            $spent = $progress ? (float)$progress['spent'] : 0;
            $remaining = $amount - $spent;
            $percentage = $amount > 0 ? round(($spent / $amount) * 100, 1) : 0;

            if ($percentage >= 100) {
                $status = 'Exceeded';
            } elseif ($percentage >= (float)($budget['alert_threshold'] ?? 80)) {
                $status = 'Warning';
            } elseif ($budget['is_active']) {
                $status = 'On Track';
            } else {
                $status = 'Inactive';
            }

            $reportData[] = [
                'id' => $budget['id'],
                'name' => $budget['name'],
                'category_name' => $budget['category_name'] ?? '-',
                'subcategory_name' => $budget['subcategory_name'] ?? null,
                'scope_label' => $budget['scope_label'] ?? ($budget['category_name'] ?? 'All expenses'),
                'amount' => $amount,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage' => $percentage,
                'status' => $status,
                'is_active' => (bool)$budget['is_active'],
                'period' => $budget['period']
            ];
        }

        Response::success([
            'budgets' => $reportData,
            'total_budget' => array_sum(array_column($reportData, 'amount')),
            'total_spent' => $this->budgetModel->getAggregateProgress($budgetIds, $userId, $startDate, $endDate)['unique_spent'],
            'total_individual_spent' => array_sum(array_column($reportData, 'spent'))
        ]);
    }

    private function validDate($value):bool{$date=DateTime::createFromFormat('!Y-m-d',(string)$value);return$date&&$date->format('Y-m-d')===(string)$value;}
}
