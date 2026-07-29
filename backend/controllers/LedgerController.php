<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Category.php';

class LedgerController {
    private $transactionModel;
    private $accountModel;
    private $categoryModel;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->accountModel = new Account();
        $this->categoryModel = new Category();
    }

    public function index() {
        $userId = Middleware::auth();

        $filters = [
            'type' => $_GET['type'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'account_id' => $_GET['account_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'search' => $_GET['search'] ?? null,
            'sort' => $_GET['sort'] ?? 't.date',
            'direction' => $_GET['direction'] ?? 'DESC'
        ];

        $limit = min((int)($_GET['limit'] ?? 500), 2000);
        $offset = (int)($_GET['offset'] ?? 0);

        $transactions = $this->transactionModel->getLedger($userId, $filters, $limit, $offset);
        $totalCount = $this->transactionModel->getLedgerCount($userId, $filters);

        // Compute summary for the filtered period
        $summary = $this->transactionModel->getLedgerSummary($userId, $filters);
        $periodIncome = floatval($summary['period_income'] ?? 0);
        $periodExpense = floatval($summary['period_expense'] ?? 0);
        $periodNet = $periodIncome - $periodExpense;
        $periodCount = (int)$summary['period_count'];

        // Compute opening balance: all income - expense before the period
        $openingBalance = 0;
        if (!empty($filters['start_date'])) {
            $openingBalance = $this->transactionModel->getOpeningBalance($userId, $filters['start_date']);
        }

        // Calculate running balance for each transaction
        $runningBalance = $openingBalance;
        foreach ($transactions as &$tx) {
            $amount = floatval($tx['amount']);
            if ($tx['type'] === 'income') {
                $runningBalance += $amount;
            } elseif ($tx['type'] === 'expense') {
                $runningBalance -= $amount;
            }
            $tx['running_balance'] = $runningBalance;
        }
        unset($tx);

        $closingBalance = $runningBalance;

        Response::success([
            'transactions' => $transactions,
            'total_count' => $totalCount,
            'summary' => [
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'period_income' => $periodIncome,
                'period_expense' => $periodExpense,
                'period_net' => $periodNet,
                'period_count' => $periodCount,
            ],
            'filters' => [
                'limit' => $limit,
                'offset' => $offset,
            ]
        ]);
    }

    public function filters() {
        $userId = Middleware::auth();

        $accounts = $this->accountModel->findAll($userId);
        $categories = $this->categoryModel->findAll($userId);

        Response::success([
            'accounts' => $accounts,
            'categories' => $categories,
        ]);
    }
}
