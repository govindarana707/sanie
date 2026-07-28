<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Category.php';

class SavingsController {
    private $goalModel;
    private $accountModel;
    private $transactionModel;
    private $categoryModel;

    public function __construct() {
        $this->goalModel = new Goal();
        $this->accountModel = new Account();
        $this->transactionModel = new Transaction();
        $this->categoryModel = new Category();
    }

    public function data() {
        $userId = Middleware::auth();

        $goals = $this->goalModel->findAll($userId);
        $goalProgress = [];
        $totalInGoals = 0;
        foreach ($goals as $goal) {
            $progress = $this->goalModel->getGoalProgress($goal['id'], $userId);
            if ($progress) {
                $goalProgress[] = $progress;
                $totalInGoals += (float)$goal['current_amount'];
            }
        }

        $accounts = $this->accountModel->findAll($userId);
        $savingsAccounts = [];
        $totalInAccounts = 0;
        foreach ($accounts as $account) {
            if ($account['type'] === 'savings' && $account['is_active']) {
                $savingsAccounts[] = $account;
                $totalInAccounts += (float)$account['balance'];
            }
        }

        $recentTransactions = $this->transactionModel->findRecentByAccounts(
            $userId,
            array_column($savingsAccounts, 'id'),
            10
        );

        $totalSavings = $totalInGoals + $totalInAccounts;

        Response::success([
            'goals' => $goalProgress,
            'savings_accounts' => $savingsAccounts,
            'total_savings' => $totalSavings,
            'total_in_goals' => $totalInGoals,
            'total_in_accounts' => $totalInAccounts,
            'recent_transactions' => $recentTransactions
        ]);
    }
}
