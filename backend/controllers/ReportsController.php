<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Budget.php';

class ReportsController {
    private $transactionModel;
    private $budgetModel;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->budgetModel = new Budget();
    }

    public function incomeExpense() {
        $userId = Middleware::auth();

        $startDate = $_GET['start_date'] ?? date('Y-01-01');
        $endDate = $_GET['end_date'] ?? date('Y-12-31');

        $transactions = $this->transactionModel->findAll($userId, [
            'start_date' => $startDate,
            'end_date' => $endDate
        ], 1000, 0);

        $rows = [];
        $runningBalance = 0;

        foreach ($transactions as $t) {
            $income = $t['type'] === 'income' ? (float)$t['amount'] : 0;
            $expense = $t['type'] === 'expense' ? (float)$t['amount'] : 0;
            $runningBalance += $income - $expense;

            $rows[] = [
                'date' => $t['date'],
                'description' => $t['description'] ?? '',
                'category' => $t['category_name'] ?? '-',
                'account' => $t['account_name'] ?? '-',
                'income' => $income,
                'expense' => $expense,
                'balance' => $runningBalance,
                'type' => $t['type']
            ];
        }

        $stats = $this->transactionModel->getStatistics($userId, $startDate, $endDate);

        Response::success([
            'rows' => $rows,
            'statistics' => [
                'total_income' => (float)($stats['total_income'] ?? 0),
                'total_expense' => (float)($stats['total_expense'] ?? 0),
                'balance' => (float)($stats['balance'] ?? 0),
                'income_count' => (int)($stats['income_count'] ?? 0),
                'expense_count' => (int)($stats['expense_count'] ?? 0)
            ]
        ]);
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

        $budgets = $this->budgetModel->findAll($userId);
        $reportData = [];

        foreach ($budgets as $budget) {
            $progress = $this->budgetModel->getBudgetProgress($budget['id'], $userId);

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
            'total_spent' => array_sum(array_column($reportData, 'spent'))
        ]);
    }
}
