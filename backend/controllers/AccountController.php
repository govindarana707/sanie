<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/BalanceService.php';
require_once __DIR__ . '/../services/MoneyValidator.php';

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

        try {
            $openingBalance = MoneyValidator::parseSigned($data['opening_balance'] ?? $data['balance'] ?? 0, 'Opening balance');
        } catch (InvalidArgumentException $e) {
            Response::error('Validation failed', 422, ['opening_balance' => $e->getMessage()]);
        }

        $accountData = [
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'account_number' => $data['account_number'] ?? '',
            'balance' => $openingBalance,
            'opening_balance' => $openingBalance,
            'currency' => $data['currency'] ?? 'NPR',
            'color' => $data['color'] ?? '#10B981',
            'icon' => $data['icon'] ?? 'wallet',
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false
            ,'include_in_savings' => $data['include_in_savings'] ?? (($data['type'] ?? '') === 'savings'),
            'include_in_net_balance' => array_key_exists('include_in_net_balance', $data) ? $data['include_in_net_balance'] : true
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

        try {
            $openingBalance = MoneyValidator::parseSigned($data['opening_balance'] ?? $existingAccount['opening_balance'], 'Opening balance');
        } catch (InvalidArgumentException $e) {
            Response::error('Validation failed', 422, ['opening_balance' => $e->getMessage()]);
        }

        $accountData = [
            'name' => $data['name'] ?? $existingAccount['name'],
            'type' => $data['type'] ?? $existingAccount['type'],
            'account_number' => $data['account_number'] ?? $existingAccount['account_number'],
            'currency' => $data['currency'] ?? $existingAccount['currency'],
            'color' => $data['color'] ?? $existingAccount['color'],
            'icon' => $data['icon'] ?? $existingAccount['icon'],
            'opening_balance' => $openingBalance,
            'is_active' => $data['is_active'] ?? $existingAccount['is_active'],
            'is_default' => $data['is_default'] ?? $existingAccount['is_default']
            ,'include_in_savings' => $data['include_in_savings'] ?? $existingAccount['include_in_savings'],
            'include_in_net_balance' => array_key_exists('include_in_net_balance', $data) ? $data['include_in_net_balance'] : $existingAccount['include_in_net_balance']
        ];

        if ($this->accountModel->update($id, $userId, $accountData)) {
            $calculatedBalance = $this->balanceService->recalculateAndPersistAccountBalance($id, $userId);
            $account = $this->accountModel->findById($id, $userId);
            $account['calculated_balance'] = $calculatedBalance;
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

        if ($this->accountModel->getRecurringTransactionCount($id, $userId) > 0) {
            Response::error('Cannot delete account used by recurring transaction templates. Delete those templates first.', 409);
        }

        if ($this->accountModel->getKarobarTransactionCount($id, $userId) > 0) {
            Response::error('Cannot delete account with existing Karobar history. Preserve or reassign that history first.', 409);
        }

        if ($this->accountModel->delete($id, $userId)) {
            Response::success(null, 'Account deleted successfully');
        }

        Response::serverError('Account deletion failed');
    }

    public function totalBalance() {
        $userId = Middleware::auth();
        $totalBalance = $this->balanceService->getNetBalance($userId);
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
        foreach (['start_date','end_date'] as $field) {
            if (!empty($filters[$field])) {
                $parsed = DateTime::createFromFormat('!Y-m-d', (string)$filters[$field]);
                if (!$parsed || $parsed->format('Y-m-d') !== $filters[$field]) {
                    Response::error('Validation failed', 422, [$field=>'A valid date is required']);
                }
            }
        }
        if (!empty($filters['start_date']) && !empty($filters['end_date']) && $filters['start_date'] > $filters['end_date']) {
            Response::error('Validation failed', 422, ['date_range'=>'Start date cannot be after end date']);
        }
        if (!empty($filters['type']) && !in_array($filters['type'], ['income','expense','transfer','goal_contribution','karobar'], true)) {
            Response::error('Validation failed', 422, ['type'=>'Invalid statement type']);
        }
        foreach (['category_id','subcategory_id'] as $field) {
            if ($filters[$field] !== null && filter_var($filters[$field], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) {
                Response::error('Validation failed', 422, [$field=>'A valid identifier is required']);
            }
        }
        if ($filters['search'] !== null && strlen((string)$filters['search']) > 100) {
            Response::error('Validation failed', 422, ['search'=>'Search must be 100 characters or fewer']);
        }
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : ($page - 1) * $limit;

        $statementResult = $this->balanceService->getAccountStatement($id, $userId, $filters, $limit, $offset);
        $summary = $statementResult['summary'];
        $analytics = $this->accountModel->getAnalytics($userId, $id);
        $cashFlow = $this->accountModel->getMonthlyCashFlow($userId, $id);

        $calculatedBalance = $this->balanceService->calculateAccountBalance($id, $userId);

        // Enrich account with calculated balance
        $account['calculated_balance'] = $calculatedBalance;

        Response::success([
            'account' => $account,
            'statement' => $statementResult['rows'],
            'summary' => $summary,
            'analytics' => $analytics,
            'cash_flow' => $cashFlow,
            'calculated_balance' => $calculatedBalance,
            'opening_balance' => $statementResult['opening_balance'],
            'page_opening_balance' => $statementResult['page_opening_balance'],
            'closing_balance' => $statementResult['closing_balance'],
            'money_in' => $statementResult['money_in'],
            'money_out' => $statementResult['money_out'],
            'net_change' => $statementResult['net_change'],
            'pagination' => $statementResult['pagination'],
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
