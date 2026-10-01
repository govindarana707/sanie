<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../services/BalanceService.php';

class AnalysisController {
    private $conn;
    private $budgetModel;
    private $balanceService;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->budgetModel = new Budget();
        $this->balanceService = new BalanceService();
    }

    public function index() {
        $userId = Middleware::auth();

        $period = $_GET['period'] ?? 'month';
        $dates = $this->getPeriodDates($period);
        $prevDates = $this->getPreviousPeriodDates($period);

        $startDate = $dates['start'];
        $endDate = $dates['end'];
        $prevStart = $prevDates['start'];
        $prevEnd = $prevDates['end'];

        // Current & previous period transactions
        $transactions = $this->queryTransactions($userId, $startDate, $endDate);
        $prevTransactions = $this->queryTransactions($userId, $prevStart, $prevEnd);

        // Current & previous period income/expense totals
        $currIncome = $transactions['total_income'] ?? 0;
        $currExpense = $transactions['total_expense'] ?? 0;
        $prevIncome = $prevTransactions['total_income'] ?? 0;
        $prevExpense = $prevTransactions['total_expense'] ?? 0;

        // Category breakdown for current period
        $categoryBreakdown = $this->getCategoryBreakdown($userId, $startDate, $endDate);

        // Previous period category breakdown for comparison
        $prevCategoryBreakdown = $this->getCategoryBreakdown($userId, $prevStart, $prevEnd);
        $prevCatMap = [];
        foreach ($prevCategoryBreakdown as $c) {
            $prevCatMap[$c['category_name']] = $c['total_amount'];
        }

        // Monthly data for charts (last 6-12 months)
        $monthlyData = $this->getMonthlyData($userId);

        // Budget data
        $budgets = $this->getBudgetData($userId,$startDate,$endDate);
        $budgetHealth = $budgets['health_score'];
        $budgetStatuses = $budgets['statuses'];

        // Goals
        $goals = $this->getGoalData($userId);
        $goalProgress = $goals['avg_progress'];
        $goalCount = $goals['total'];

        // Savings
        $savingsData = $this->getSavingsData($userId);
        $totalBalance = $savingsData['total_balance'];
        $savingsBalance = $savingsData['savings_balance'];

        // Calculate metrics
        $savingsRate = $currIncome > 0 ? round(($currIncome - $currExpense) / $currIncome * 100, 1) : 0;
        $prevSavingsRate = $prevIncome > 0 ? round(($prevIncome - $prevExpense) / $prevIncome * 100, 1) : 0;

        $monthlyGrowth = $prevExpense > 0 ? round(($currExpense - $prevExpense) / $prevExpense * 100, 1) : 0;

        // Expense control score (lower expense-to-income ratio = better)
        $expenseRatio = $currIncome > 0 ? $currExpense / $currIncome : 1;
        $expenseControlScore = max(0, min(100, round((1 - $expenseRatio) * 100)));

        // Income stability (compare current vs previous income)
        $incomeStability = $prevIncome > 0 ? round(min(100, ($currIncome / $prevIncome) * 100)) : 50;

        // --- FINANCIAL HEALTH SCORE ---
        // Savings rate (30%)
        $savingsScore = min(30, round($savingsRate * 0.3));
        // Budget management (25%)
        $budgetScore = min(25, round($budgetHealth * 0.25));
        // Expense control (20%)
        $expenseScore = min(20, round($expenseControlScore * 0.2));
        // Goal progress (15%)
        $goalScore = min(15, round($goalProgress * 0.15));
        // Income stability (10%)
        $incomeScore = min(10, round($incomeStability * 0.1));

        $totalScore = $savingsScore + $budgetScore + $expenseScore + $goalScore + $incomeScore;
        $totalScore = min(100, max(0, $totalScore));

        $healthStatus = $totalScore >= 90 ? 'Excellent' : ($totalScore >= 70 ? 'Good' : ($totalScore >= 50 ? 'Average' : 'Needs Improvement'));

        // --- SUMMARY ---
        $summary = $this->generateSummary($currIncome, $currExpense, $savingsRate, $prevExpense, $monthlyGrowth, $budgetHealth, $goalProgress);

        // --- INSIGHTS ---
        $insights = $this->generateInsights($currIncome, $currExpense, $savingsRate, $prevSavingsRate, $categoryBreakdown, $prevCatMap, $budgetStatuses, $goalProgress, $goalCount, $budgetHealth);

        // --- RECOMMENDATIONS ---
        $recommendations = $this->generateRecommendations($savingsRate, $expenseRatio, $categoryBreakdown, $budgetStatuses, $goalProgress, $goalCount, $budgetHealth);

        // --- CATEGORY DISTRIBUTION ---
        $totalExpenseAmount = array_sum(array_column($categoryBreakdown, 'total_amount'));
        $categoryDistribution = [];
        foreach ($categoryBreakdown as $c) {
            $amt = (float)$c['total_amount'];
            $categoryDistribution[] = [
                'category' => $c['category_name'],
                'amount' => $amt,
                'percentage' => $totalExpenseAmount > 0 ? round(($amt / $totalExpenseAmount) * 100, 1) : 0,
                'color' => $c['category_color'] ?? '#6B7280',
                'icon' => $c['category_icon'] ?? 'fas fa-tag'
            ];
        }

        // Category spending trends (compare with previous period)
        $categoryTrends = [];
        foreach ($categoryBreakdown as $c) {
            $currAmt = (float)$c['total_amount'];
            $prevAmt = (float)($prevCatMap[$c['category_name']] ?? 0);
            $change = $prevAmt > 0 ? round(($currAmt - $prevAmt) / $prevAmt * 100, 1) : ($currAmt > 0 ? 100 : 0);
            $categoryTrends[] = [
                'category' => $c['category_name'],
                'current' => $currAmt,
                'previous' => $prevAmt,
                'change_percent' => $change,
                'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat')
            ];
        }

        Response::success([
            'score' => $totalScore,
            'health_status' => $healthStatus,
            'summary' => $summary,
            'period' => $period,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'metrics' => [
                'savings_rate' => $savingsRate,
                'monthly_growth' => abs($monthlyGrowth),
                'growth_direction' => $monthlyGrowth >= 0 ? 'up' : 'down',
                'goal_progress' => round($goalProgress),
                'budget_health' => round($budgetHealth),
                'total_income' => $currIncome,
                'total_expense' => $currExpense,
                'net_savings' => $currIncome - $currExpense,
                'total_balance' => $totalBalance,
                'savings_balance' => $savingsBalance
            ],
            'monthly_data' => $monthlyData,
            'category_distribution' => $categoryDistribution,
            'category_trends' => $categoryTrends,
            'insights' => $insights,
            'recommendations' => $recommendations
        ]);
    }

    private function getPeriodDates($period, ?DateTimeImmutable $now = null) {
        $now = $now ?? new DateTimeImmutable('now');
        switch ($period) {
            case 'today':
                return ['start' => $now->format('Y-m-d'), 'end' => $now->format('Y-m-d')];
            case 'week':
                return ['start' => $now->modify('monday this week')->format('Y-m-d'), 'end' => $now->modify('sunday this week')->format('Y-m-d')];
            case 'month':
                return ['start' => $now->format('Y-m-01'), 'end' => $now->format('Y-m-t')];
            case 'year':
                return ['start' => $now->format('Y-01-01'), 'end' => $now->format('Y-12-31')];
            default:
                if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
                    return ['start' => $_GET['start_date'], 'end' => $_GET['end_date']];
                }
                return ['start' => $now->format('Y-m-01'), 'end' => $now->format('Y-m-t')];
        }
    }

    private function getPreviousPeriodDates($period, ?DateTimeImmutable $now = null) {
        $now = $now ?? new DateTimeImmutable('now');
        switch ($period) {
            case 'today':
                $yesterday = $now->modify('-1 day')->format('Y-m-d');
                return ['start' => $yesterday, 'end' => $yesterday];
            case 'week':
                $lastMon = $now->modify('monday last week')->format('Y-m-d');
                $lastSun = $now->modify('sunday last week')->format('Y-m-d');
                return ['start' => $lastMon, 'end' => $lastSun];
            case 'month':
                $previousMonth = $now->modify('first day of last month');
                $first = $previousMonth->format('Y-m-01');
                $last = $previousMonth->format('Y-m-t');
                return ['start' => $first, 'end' => $last];
            case 'year':
                $previousYear = $now->modify('-1 year');
                return ['start' => $previousYear->format('Y-01-01'), 'end' => $previousYear->format('Y-12-31')];
            default:
                $previousMonth = $now->modify('-1 month');
                return ['start' => $previousMonth->format('Y-m-01'), 'end' => $previousMonth->format('Y-m-t')];
        }
    }

    private function queryTransactions($userId, $startDate, $endDate) {
        $query = "SELECT
                    COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
                    COUNT(*) as tx_count
                  FROM transactions
                  WHERE user_id = :uid AND date BETWEEN :start AND :end";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total_income' => (float)($result['total_income'] ?? 0),
            'total_expense' => (float)($result['total_expense'] ?? 0),
            'tx_count' => (int)($result['tx_count'] ?? 0)
        ];
    }

    private function getCategoryBreakdown($userId, $startDate, $endDate) {
        $query = "SELECT
                    c.name as category_name,
                    c.icon as category_icon,
                    c.color as category_color,
                    SUM(t.amount) as total_amount,
                    COUNT(t.id) as tx_count
                  FROM transactions t
                  JOIN categories c ON t.category_id = c.id
                  WHERE t.user_id = :uid AND t.date BETWEEN :start AND :end AND t.type = 'expense'
                  GROUP BY c.id, c.name, c.icon, c.color
                  ORDER BY total_amount DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getMonthlyData($userId, ?DateTimeImmutable $now = null) {
        $query = "SELECT
                    DATE_FORMAT(date, '%b') AS month,
                    MONTH(date) AS month_num,
                    YEAR(date) AS year,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
                  FROM transactions
                  WHERE user_id = :uid AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
                  GROUP BY YEAR(date), MONTH(date), DATE_FORMAT(date, '%b')
                  ORDER BY year ASC, month_num ASC
                  LIMIT 12";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure we always have 6 months (pad with empty if needed).
        // Use year-month identity internally so equal month names in different years never collide.
        $byYearMonth = [];
        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', (int)$row['year'], (int)$row['month_num']);
            $byYearMonth[$key] = $row;
        }
        $result = [];
        $now = $now ?: new DateTimeImmutable();
        for ($i = 5; $i >= 0; $i--) {
            $dt = $now->modify("-{$i} months");
            $m = $dt->format('M');
            $row = $byYearMonth[$dt->format('Y-m')] ?? null;
            $result[] = [
                'month' => $m,
                'income' => $row ? (float)$row['income'] : 0,
                'expense' => $row ? (float)$row['expense'] : 0
            ];
        }
        return $result;
    }

    private function getBudgetData($userId,$periodStart,$periodEnd) {
        $budgets=array_values(array_filter($this->budgetModel->findAll($userId),fn($budget)=>!empty($budget['is_active'])&&$budget['start_date']<=$periodEnd&&$budget['end_date']>=$periodStart));
        $progress=$this->budgetModel->getBatchProgress(array_column($budgets,'id'),$userId,$periodStart,$periodEnd);

        $totalHealth = 0;
        $count = 0;
        $statuses = [];

        foreach ($progress as $row) {
            $percentage=(float)$row['percentage'];
            $limit=(float)$row['budget_amount'];
            $health = $limit > 0 ? max(0, 100 - $percentage) : 0;
            $totalHealth += $health;
            $count++;

            if ($percentage >= 100) {
                $statuses[] = 'exceeded';
            } elseif ($percentage >= (float)($row['budget']['alert_threshold'] ?? 80)) {
                $statuses[] = 'warning';
            } else {
                $statuses[] = 'on_track';
            }
        }

        return [
            'health_score' => $count > 0 ? $totalHealth / $count : 100,
            'statuses' => $statuses
        ];
    }

    private function getGoalData($userId) {
        $query = "SELECT current_amount, target_amount, status FROM goals WHERE user_id = :uid AND status = 'active'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalProgress = 0;
        $count = count($goals);

        foreach ($goals as $g) {
            $progress = (float)$g['target_amount'] > 0 ? ((float)$g['current_amount'] / (float)$g['target_amount']) * 100 : 0;
            $totalProgress += $progress;
        }

        return [
            'avg_progress' => $count > 0 ? $totalProgress / $count : 0,
            'total' => $count
        ];
    }

    private function getSavingsData($userId) {
        return [
            'total_balance' => $this->balanceService->getNetBalance($userId),
            'savings_balance' => $this->balanceService->getSavingsBalance($userId)
        ];
    }

    private function generateSummary($income, $expense, $savingsRate, $prevExpense, $expenseChange, $budgetHealth, $goalProgress) {
        $parts = [];

        if ($savingsRate > 0) {
            $parts[] = "Your financial health is stable. You maintained a positive savings rate of {$savingsRate}% this period.";
        } else {
            $parts[] = "Your expenses exceeded your income this period, resulting in negative savings. Consider reviewing your spending habits.";
        }

        if ($expenseChange > 5) {
            $parts[] = "Your expenses increased compared to the previous period. Try to identify areas where you can cut back.";
        } elseif ($expenseChange < -5) {
            $parts[] = "Great job reducing your expenses compared to last period!";
        } else {
            $parts[] = "Your spending remained relatively stable compared to last period.";
        }

        if ($budgetHealth < 50) {
            $parts[] = "Several budgets are close to or exceeding their limits. Consider adjusting your budget allocations.";
        } elseif ($budgetHealth >= 80) {
            $parts[] = "Your budgets are well managed with most categories within healthy ranges.";
        }

        if ($goalProgress > 75) {
            $parts[] = "Excellent progress on your financial goals! You're over 75% of the way there.";
        } elseif ($goalProgress > 0) {
            $parts[] = "You're making progress on your goals at {$goalProgress}% completion.";
        }

        return implode(' ', $parts);
    }

    private function generateInsights($income, $expense, $savingsRate, $prevSavingsRate, $categories, $prevCatMap, $budgetStatuses, $goalProgress, $goalCount, $budgetHealth) {
        $insights = [];

        // Savings rate change
        $savingsDiff = $savingsRate - $prevSavingsRate;
        if ($savingsDiff > 3) {
            $insights[] = ['type' => 'success', 'message' => "Great job! Your savings rate improved by {$savingsDiff}% compared to last period."];
        } elseif ($savingsDiff < -3) {
            $insights[] = ['type' => 'warning', 'message' => "Your savings rate dropped by " . abs($savingsDiff) . "% compared to last period. Review your spending."];
        } else {
            $insights[] = ['type' => 'info', 'message' => "Your savings rate remained steady at {$savingsRate}%."];
        }

        // Category-specific trends
        foreach ($categories as $cat) {
            $name = $cat['category_name'];
            $currAmt = (float)$cat['total_amount'];
            $prevAmt = (float)($prevCatMap[$name] ?? 0);
            if ($prevAmt > 0) {
                $change = round(($currAmt - $prevAmt) / $prevAmt * 100, 1);
                if ($change > 20) {
                    $insights[] = ['type' => 'warning', 'message' => "Your {$name} spending increased by {$change}% compared to last period."];
                } elseif ($change < -20) {
                    $insights[] = ['type' => 'success', 'message' => "Your {$name} spending decreased by " . abs($change) . "% compared to last period."];
                }
            }
        }

        // Budget insights
        $exceededCount = 0;
        $warningCount = 0;
        foreach ($budgetStatuses as $s) {
            if ($s === 'exceeded') $exceededCount++;
            if ($s === 'warning') $warningCount++;
        }
        if ($exceededCount > 0) {
            $insights[] = ['type' => 'error', 'message' => "{$exceededCount} budget(s) have been exceeded. Review and adjust your budget limits."];
        }
        if ($warningCount > 0) {
            $insights[] = ['type' => 'warning', 'message' => "{$warningCount} budget(s) are near their limit. Consider reducing spending."];
        }

        // Goal insights
        if ($goalCount > 0 && $goalProgress > 0) {
            $insights[] = ['type' => 'info', 'message' => "You have {$goalCount} active goal(s) with an average progress of " . round($goalProgress) . "%."];
        } elseif ($goalCount === 0) {
            $insights[] = ['type' => 'info', 'message' => "Consider setting up savings goals to track your financial objectives."];
        }

        // Income/expense ratio
        if ($income > 0) {
            $ratio = round(($expense / $income) * 100);
            if ($ratio > 90) {
                $insights[] = ['type' => 'error', 'message' => "You're spending {$ratio}% of your income. Try to keep expenses below 70%."];
            } elseif ($ratio < 50) {
                $insights[] = ['type' => 'success', 'message' => "You're spending only {$ratio}% of your income. Excellent financial discipline!"];
            }
        }

        // Limit to 5 insights
        return array_slice($insights, 0, 5);
    }

    private function generateRecommendations($savingsRate, $expenseRatio, $categories, $budgetStatuses, $goalProgress, $goalCount, $budgetHealth) {
        $recs = [];

        // High priority
        if ($savingsRate <= 0) {
            $recs[] = ['priority' => 'high', 'message' => 'Your expenses exceed your income. Create a budget and identify non-essential expenses to cut.'];
        }

        $exceededCount = 0;
        foreach ($budgetStatuses as $s) {
            if ($s === 'exceeded') $exceededCount++;
        }
        if ($exceededCount > 0) {
            $recs[] = ['priority' => 'high', 'message' => "{$exceededCount} budget(s) exceeded. Review your spending limits and adjust categories accordingly."];
        }

        if ($expenseRatio > 0.9) {
            $recs[] = ['priority' => 'high', 'message' => 'Your expense-to-income ratio is critically high. Focus on reducing variable expenses like dining and entertainment.'];
        }

        // Medium priority
        if ($savingsRate > 0 && $savingsRate < 10) {
            $recs[] = ['priority' => 'medium', 'message' => 'Your savings rate is below 10%. Aim to save at least 20% of your income for long-term financial security.'];
        }

        if ($goalCount > 0 && $goalProgress < 50) {
            $goalCat = !empty($categories) ? $categories[0]['category_name'] : 'expenses';
            $recs[] = ['priority' => 'medium', 'message' => "Your goal progress is below 50%. Consider allocating a fixed monthly amount toward your goals."];
        }

        $biggestCategory = !empty($categories) ? $categories[0] : null;
        if ($biggestCategory && (float)$biggestCategory['total_amount'] > 0) {
            $recs[] = ['priority' => 'medium', 'message' => "Your biggest expense category is \"{$biggestCategory['category_name']}\". Look for ways to optimize this spending."];
        }

        // Low priority
        if ($goalCount === 0) {
            $recs[] = ['priority' => 'low', 'message' => 'Set up financial goals like an emergency fund or vacation savings to stay motivated.'];
        }

        $recs[] = ['priority' => 'low', 'message' => 'Review your subscriptions and recurring payments. Cancel any services you no longer use.'];

        if ($budgetHealth < 70) {
            $recs[] = ['priority' => 'low', 'message' => 'Consider adjusting your budget limits to better reflect your actual spending patterns.'];
        }

        return $recs;
    }
}
