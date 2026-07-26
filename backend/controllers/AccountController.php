<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Account.php';

class AccountController {
    private $accountModel;

    public function __construct() {
        $this->accountModel = new Account();
    }

    public function index() {
        $userId = Middleware::auth();
        $accounts = $this->accountModel->findAll($userId);
        Response::success($accounts);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $account = $this->accountModel->findById($id, $userId);
        
        if ($account) {
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
            'currency' => $data['currency'] ?? 'NPR',
            'color' => $data['color'] ?? '#10B981',
            'icon' => $data['icon'] ?? 'wallet',
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false
        ];

        $accountId = $this->accountModel->create($accountData);
        
        if ($accountId) {
            $account = $this->accountModel->findById($accountId, $userId);
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
            'balance' => $data['balance'] ?? $existingAccount['balance'],
            'currency' => $data['currency'] ?? $existingAccount['currency'],
            'color' => $data['color'] ?? $existingAccount['color'],
            'icon' => $data['icon'] ?? $existingAccount['icon'],
            'is_active' => $data['is_active'] ?? $existingAccount['is_active'],
            'is_default' => $data['is_default'] ?? $existingAccount['is_default']
        ];

        if ($this->accountModel->update($id, $userId, $accountData)) {
            $account = $this->accountModel->findById($id, $userId);
            Response::success($account, 'Account updated successfully');
        }
        
        Response::serverError('Account update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        
        if ($this->accountModel->delete($id, $userId)) {
            Response::success(null, 'Account deleted successfully');
        }
        
        Response::serverError('Account deletion failed');
    }

    public function totalBalance() {
        $userId = Middleware::auth();
        $totalBalance = $this->accountModel->getTotalBalance($userId);
        Response::success(['total_balance' => $totalBalance]);
    }
}
