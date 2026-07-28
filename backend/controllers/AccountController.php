<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/BalanceService.php';

class AccountController {
    private $accountModel;
    private $notifService;
    private $balanceService;

    public function __construct() {
        $this->accountModel = new Account();
        $this->notifService = new NotificationService();
        $this->balanceService = new BalanceService();
    }

    public function index() {
        $userId = Middleware::auth();
        $accounts = $this->accountModel->findAll($userId);
        // Enrich with calculated balances
        foreach ($accounts as &$acct) {
            $acct['calculated_balance'] = $this->balanceService->calculateAccountBalance($acct['id'], $userId);
        }
        Response::success($accounts);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $account = $this->accountModel->findById($id, $userId);

        if ($account) {
            $account['calculated_balance'] = $this->balanceService->calculateAccountBalance($id, $userId);
            Response::success($account);
        }

        Response::notFound('Account not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);

        $errors = Middleware::validateRequired($data, ['name', 'type']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $accountData = [
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'account_number' => $data['account_number'] ?? '',
            'balance' => $data['balance'] ?? 0,
            'opening_balance' => $data['opening_balance'] ?? $data['balance'] ?? 0,
            'currency' => $data['currency'] ?? 'NPR',
            'color' => $data['color'] ?? '#10B981',
            'icon' => $data['icon'] ?? 'wallet',
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false
        ];

        $accountId = $this->accountModel->create($accountData);

        if ($accountId) {
            $account = $this->accountModel->findById($accountId, $userId);
            $account['calculated_balance'] = $this->balanceService->calculateAccountBalance($accountId, $userId);
            $this->notifService->create($userId, 'account_added',
                'Account Added',
                "New account \"{$data['name']}\" has been created.",
                'account', $accountId);
            Response::success($account, 'Account created successfully', 201);
        }

        Response::serverError('Account creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);

        $existingAccount = $this->accountModel->findById($id, $userId);
        if (!$existingAccount) {
            Response::notFound('Account not found');
        }

        $accountData = [
            'name' => $data['name'] ?? $existingAccount['name'],
            'type' => $data['type'] ?? $existingAccount['type'],
            'account_number' => $data['account_number'] ?? $existingAccount['account_number'],
            'currency' => $data['currency'] ?? $existingAccount['currency'],
            'color' => $data['color'] ?? $existingAccount['color'],
            'icon' => $data['icon'] ?? $existingAccount['icon'],
            'is_active' => $data['is_active'] ?? $existingAccount['is_active'],
            'is_default' => $data['is_default'] ?? $existingAccount['is_default']
        ];

        if ($this->accountModel->update($id, $userId, $accountData)) {
            $account = $this->accountModel->findById($id, $userId);
            $account['calculated_balance'] = $this->balanceService->calculateAccountBalance($id, $userId);
            Response::success($account, 'Account updated successfully');
        }

        Response::serverError('Account update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();

        // Check if account has transactions
        $txStmt = $this->accountModel->getTransactionCount($id, $userId);
        if ($txStmt > 0) {
            Response::error('Cannot delete account with existing transactions. Please delete all transactions first.', 409);
        }

        if ($this->accountModel->delete($id, $userId)) {
            Response::success(null, 'Account deleted successfully');
        }

        Response::serverError('Account deletion failed');
    }

    public function totalBalance() {
        $userId = Middleware::auth();
        $totalBalance = $this->balanceService->getTotalBalance($userId);
        Response::success(['total_balance' => $totalBalance]);
    }

    public function overview() {
        $userId = Middleware::auth();
        $accounts = $this->balanceService->getAccountOverview($userId);
        Response::success($accounts);
    }

    public function statement($id) {
        $userId = Middleware::auth();
        $account = $this->accountModel->findById($id, $userId);
        if (!$account) {
            Response::notFound('Account not found');
        }

        $filters = [
            'type' => $_GET['type'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'subcategory_id' => $_GET['subcategory_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        $transactions = $this->accountModel->getStatement($userId, $id, $filters);
        $summary = $this->accountModel->getStatementSummary($userId, $id, $filters['start_date'] ?? null, $filters['end_date'] ?? null);
        $analytics = $this->accountModel->getAnalytics($userId, $id);
        $cashFlow = $this->accountModel->getMonthlyCashFlow($userId, $id);

        // Build statement via BalanceService (centralized calculation)
        $openingBalance = floatval($account['opening_balance'] ?? 0);
        $statementResult = $this->balanceService->buildStatement($transactions, $openingBalance);
        $calculatedBalance = $statementResult['calculated_balance'];

        // Enrich account with calculated balance
        $account['calculated_balance'] = $calculatedBalance;

        Response::success([
            'account' => $account,
            'statement' => $statementResult['entries'],
            'summary' => $summary,
            'analytics' => $analytics,
            'cash_flow' => $cashFlow,
            'calculated_balance' => $calculatedBalance,
        ]);
    }

    public function analytics($id) {
        $userId = Middleware::auth();
        $account = $this->accountModel->findById($id, $userId);
        if (!$account) {
            Response::notFound('Account not found');
        }

        $analytics = $this->accountModel->getAnalytics($userId, $id);
        Response::success($analytics);
    }
}
