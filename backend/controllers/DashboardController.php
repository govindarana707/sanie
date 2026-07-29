<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../services/KarobarService.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/BalanceService.php';

class DashboardController {
    private $transactionModel;
    private $accountModel;
    private $budgetModel;
    private $goalModel;
    private $karobarService;
    private $accountingService;
    private $balanceService;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->accountModel = new Account();
        $this->budgetModel = new Budget();
        $this->goalModel = new Goal();
        $this->karobarService = new KarobarService();
        $this->accountingService = new AccountingService();
        $this->balanceService = new BalanceService();
    }

    public function index() {
        $userId = Middleware::auth();

        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');

        // All calculations use BalanceService
        $statistics = $this->balanceService->getStatistics($userId, $startDate, $endDate);
        $totalBalance = $this->balanceService->getTotalBalance($userId);
        $savingsBalance = $this->balanceService->getSavingsBalance($userId);
        $recentTransactions = $this->transactionModel->findAll($userId, [], 10, 0);

        $expenseBreakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, 'expense');
        $incomeBreakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, 'income');

        $year = (int) date('Y', strtotime($startDate));
        $monthlyData = $this->transactionModel->getMonthlyData($userId, $year);

        $activeBudgets = array_filter(
            $this->budgetModel->findAll($userId),
            fn($b) => $b['is_active']
        );
        $budgetProgress = !empty($activeBudgets)
            ? $this->budgetModel->getBatchProgress(array_column($activeBudgets, 'id'), $userId)
            : [];

        $goals = $this->goalModel->findAll($userId);
        $goalProgress = [];
        foreach ($goals as $goal) {
            if ($goal['status'] === 'active') {
                $progress = $this->goalModel->getGoalProgress($goal['id'], $userId);
                if ($progress) {
                    $goalProgress[] = $progress;
                }
            }
        }

        $karobarData = $this->karobarService->getDashboardData($userId);
        $totalReceivable = $karobarData['total_receivable'] ?? 0;
        $totalPayable = $karobarData['total_payable'] ?? 0;
        $netWorth = $totalBalance + $totalReceivable - $totalPayable;

        $financialHealthScore = $this->calculateFinancialHealthScore(
            $statistics, $budgetProgress, $goalProgress,
            $totalBalance, $savingsBalance, $totalReceivable, $totalPayable
        );

        $accountsOverview = $this->balanceService->getAccountOverview($userId);

        Response::success([
            'statistics' => $statistics,
            'total_balance' => $totalBalance,
            'savings_balance' => $savingsBalance,
            'total_receivable' => $totalReceivable,
            'total_payable' => $totalPayable,
            'net_worth' => $netWorth,
            'recent_transactions' => $recentTransactions,
            'expense_breakdown' => $expenseBreakdown,
            'income_breakdown' => $incomeBreakdown,
            'monthly_data' => $monthlyData,
            'budget_progress' => $budgetProgress,
            'goal_progress' => $goalProgress,
            'financial_health_score' => $financialHealthScore,
            'accounts_overview' => $accountsOverview,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
    }

    private function calculateFinancialHealthScore($statistics, $budgetProgress, $goalProgress, $totalBalance, $savingsBalance, $totalReceivable, $totalPayable) {
        $score = 0;
        $factors = 0;

        if ($statistics['total_income'] > 0) {
            $savingsRate = ($savingsBalance / $statistics['total_income']) * 100;
            $score += min(25, max(0, $savingsRate / 2));
        }
        $factors++;

        $overBudgetCount = 0;
        foreach ($budgetProgress as $budget) {
            if ($budget['is_over_budget']) {
                $overBudgetCount++;
            }
        }
        if (count($budgetProgress) > 0) {
            $budgetScore = 25 * (1 - ($overBudgetCount / count($budgetProgress)));
            $score += max(0, $budgetScore);
        }
        $factors++;

        $activeGoals = count($goalProgress);
        if ($activeGoals > 0) {
            $avgGoalProgress = array_sum(array_column($goalProgress, 'percentage')) / $activeGoals;
            $score += min(25, $avgGoalProgress / 4);
        }
        $factors++;

        $netWorth = $totalBalance + $totalReceivable - $totalPayable;
        if ($netWorth > 0) {
            $score += 25;
        }
        $factors++;

        $finalScore = $factors > 0 ? round(($score / $factors) * 100 / 100) : 0;

        $status = 'Critical';
        if ($finalScore >= 80) $status = 'Excellent';
        elseif ($finalScore >= 60) $status = 'Good';
        elseif ($finalScore >= 40) $status = 'Average';
        elseif ($finalScore >= 20) $status = 'Needs Improvement';

        return [
            'score' => $finalScore,
            'status' => $status,
            'max_score' => 100
        ];
    }

    public function quickStats() {
        $userId = Middleware::auth();

        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime('sunday this week'));
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $yearStart = date('Y-01-01');
        $yearEnd = date('Y-12-31');

        $stats = [
            'today' => $this->balanceService->getStatistics($userId, $today, $today),
            'week' => $this->balanceService->getStatistics($userId, $weekStart, $weekEnd),
            'month' => $this->balanceService->getStatistics($userId, $monthStart, $monthEnd),
            'year' => $this->balanceService->getStatistics($userId, $yearStart, $yearEnd)
        ];

        Response::success($stats);
    }
}
