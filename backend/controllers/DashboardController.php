<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Goal.php';

class DashboardController {
    private $transactionModel;
    private $accountModel;
    private $budgetModel;
    private $goalModel;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->accountModel = new Account();
        $this->budgetModel = new Budget();
        $this->goalModel = new Goal();
    }

    public function index() {
        $userId = Middleware::auth();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        
        // Get statistics
        $statistics = $this->transactionModel->getStatistics($userId, $startDate, $endDate);
        
        // Get total balance
        $totalBalance = $this->accountModel->getTotalBalance($userId);
        
        // Get recent transactions
        $recentTransactions = $this->transactionModel->findAll($userId, [], 10, 0);
        
        // Get category breakdown
        $expenseBreakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, 'expense');
        $incomeBreakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, 'income');
        
        // Get budgets with progress
        $budgets = $this->budgetModel->findAll($userId);
        $budgetProgress = [];
        foreach ($budgets as $budget) {
            if ($budget['is_active']) {
                $progress = $this->budgetModel->getBudgetProgress($budget['id'], $userId);
                if ($progress) {
                    $budgetProgress[] = $progress;
                }
            }
        }
        
        // Get goals with progress
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
        
        // Calculate financial health score
        $financialHealthScore = $this->calculateFinancialHealthScore($statistics, $budgetProgress, $goalProgress, $totalBalance);
        
        Response::success([
            'statistics' => $statistics,
            'total_balance' => $totalBalance,
            'recent_transactions' => $recentTransactions,
            'expense_breakdown' => $expenseBreakdown,
            'income_breakdown' => $incomeBreakdown,
            'budget_progress' => $budgetProgress,
            'goal_progress' => $goalProgress,
            'financial_health_score' => $financialHealthScore,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
    }

    private function calculateFinancialHealthScore($statistics, $budgetProgress, $goalProgress, $totalBalance) {
        $score = 0;
        $factors = 0;
        
        // Savings rate factor (25 points)
        if ($statistics['total_income'] > 0) {
            $savingsRate = ($statistics['balance'] / $statistics['total_income']) * 100;
            $score += min(25, max(0, $savingsRate / 2));
        }
        $factors++;
        
        // Budget discipline factor (25 points)
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
        
        // Goal progress factor (25 points)
        $activeGoals = count($goalProgress);
        if ($activeGoals > 0) {
            $avgGoalProgress = array_sum(array_column($goalProgress, 'percentage')) / $activeGoals;
            $score += min(25, $avgGoalProgress / 4);
        }
        $factors++;
        
        // Balance stability factor (25 points)
        if ($totalBalance > 0) {
            $score += 25;
        }
        $factors++;
        
        // Calculate final score
        $finalScore = $factors > 0 ? round(($score / $factors) * 100 / 100) : 0;
        
        // Determine health status
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
            'today' => $this->transactionModel->getStatistics($userId, $today, $today),
            'week' => $this->transactionModel->getStatistics($userId, $weekStart, $weekEnd),
            'month' => $this->transactionModel->getStatistics($userId, $monthStart, $monthEnd),
            'year' => $this->transactionModel->getStatistics($userId, $yearStart, $yearEnd)
        ];
        
        Response::success($stats);
    }
}
