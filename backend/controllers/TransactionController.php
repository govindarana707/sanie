<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/KarobarService.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/BalanceService.php';

class TransactionController {
    private $transactionModel;
    private $accountModel;
    private $goalModel;
    private $notifService;
    private $karobarService;
    private $accountingService;
    private $balanceService;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->accountModel = new Account();
        $this->goalModel = new Goal();
        $this->notifService = new NotificationService();
        $this->karobarService = new KarobarService();
        $this->accountingService = new AccountingService();
        $this->balanceService = new BalanceService();
    }

    public function index() {
        $userId = Middleware::auth();
        
        $filters = [
            'type' => $_GET['type'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'account_id' => $_GET['account_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'search' => $_GET['search'] ?? null
        ];
        
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $transactions = $this->transactionModel->findAll($userId, $filters, $limit, $offset);
        Response::success($transactions);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $transaction = $this->transactionModel->findById($id, $userId);
        
        if ($transaction) {
            Response::success($transaction);
        }
        
        Response::notFound('Transaction not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['amount', 'type', 'date']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if ($data['type'] !== 'transfer' && empty($data['category_id'])) {
            Response::error('Validation failed', 422, ['category_id' => 'Category is required for income/expense transactions']);
        }

        $paymentMethod = $data['payment_method'] ?? null;

        if ($data['type'] === 'expense' && $paymentMethod === 'credit') {
            $errors2 = Middleware::validateRequired($data, ['creditor_id']);
            if (!empty($errors2)) {
                Response::error('Validation failed', 422, ['creditor_id' => 'Creditor is required for credit purchases']);
            }

            try {
                $result = $this->karobarService->processCreditPurchase([
                    'amount' => $data['amount'],
                    'category_id' => $data['category_id'] ?? null,
                    'subcategory_id' => $data['subcategory_id'] ?? null,
                    'creditor_id' => $data['creditor_id'],
                    'date' => $data['date'],
                    'description' => $data['description'] ?? '',
                    'due_date' => $data['due_date'] ?? null
                ], $userId);

                if ($result) {
                    Response::success(['karobar_id' => $result['karobar_id']], 'Credit purchase recorded successfully', 201);
                } else {
                    Response::serverError('Failed to record credit purchase');
                }
            } catch (\Throwable $e) {
                Response::serverError($e->getMessage());
            }
            return;
        }

        $dynamicType = $data['dynamic_subcategory_type'] ?? null;
        $dynamicRefId = $data['dynamic_subcategory_id'] ?? null;

        if ($dynamicType === 'account' && $dynamicRefId) {
            $accountId = (int)$dynamicRefId;
            $desc = $data['description'] ?? '';
            $data['description'] = $desc ? "Deposit to savings account: {$desc}" : 'Deposit to savings account';
            $data['account_id'] = $accountId;
        } elseif ($dynamicType === 'goal' && $dynamicRefId) {
            $desc = $data['description'] ?? '';
            $data['description'] = $desc ? "Contribution to goal: {$desc}" : 'Contribution to savings goal';
        }

        if ($data['type'] === 'transfer') {
            if (empty($data['from_account_id']) || empty($data['to_account_id'])) {
                Response::error('Validation failed', 422, ['from_account_id' => 'Source and destination accounts are required for transfers']);
            }
            $data['account_id'] = $data['from_account_id'];
        } elseif (empty($data['account_id'])) {
            Response::error('Validation failed', 422, ['account_id' => 'Account is required for non-credit transactions']);
        }

        try {
            $transactionId = $this->accountingService->createTransaction($userId, $data);

            if ($transactionId) {
                $transaction = $this->transactionModel->findById($transactionId, $userId);

                if ($dynamicType === 'goal' && $dynamicRefId) {
                    $goalId = (int)$dynamicRefId;
                    $goal = $this->goalModel->findById($goalId, $userId);
                    if ($goal) {
                        $percentage = $goal['target_amount'] > 0 ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
                        if ($percentage >= 100) {
                            $this->notifService->create($userId, 'goal_achieved',
                                'Goal Achieved!',
                                "Congratulations! You've reached your savings goal \"{$goal['name']}\".",
                                'goal', $goalId);
                        }
                    }
                    $this->notifService->create($userId, 'transaction_added',
                        'Savings Contribution',
                        "Rs " . number_format($data['amount'], 0) . " contributed to goal.",
                        'transaction', $transactionId);
                } else {
                    $typeLabel = ucfirst($data['type']);
                    $amountFormatted = number_format($data['amount'], 0);
                    $this->notifService->create($userId, 'transaction_added',
                        'Transaction Added',
                        "{$typeLabel} of Rs {$amountFormatted} was recorded.",
                        'transaction', $transactionId);
                }

                Response::success($transaction, 'Transaction created successfully', 201);
            }

            Response::serverError('Transaction creation failed');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingTransaction = $this->transactionModel->findById($id, $userId);
        if (!$existingTransaction) {
            Response::notFound('Transaction not found');
        }

        if ($existingTransaction['karobar_transaction_id']) {
            Response::error('Cannot edit a linked credit transaction. Edit the karobar transaction instead.', 422);
        }

        try {
            $this->accountingService->updateTransaction($id, $userId, $data);
            
            $transaction = $this->transactionModel->findById($id, $userId);
            $this->notifService->create($userId, 'transaction_updated',
                'Transaction Updated',
                'Your transaction has been updated.',
                'transaction', $id);
            Response::success($transaction, 'Transaction updated successfully');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        $transaction = $this->transactionModel->findById($id, $userId);
        
        if (!$transaction) {
            Response::notFound('Transaction not found');
        }

        if ($transaction['karobar_transaction_id']) {
            Response::error('Cannot delete a linked credit transaction. Delete the karobar transaction instead.', 422);
        }

        try {
            $this->accountingService->deleteTransaction($id, $userId);

            $this->notifService->create($userId, 'transaction_deleted',
                'Transaction Deleted',
                'A transaction has been removed.',
                'transaction', $id);
            Response::success(null, 'Transaction deleted successfully');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function statistics() {
        $userId = Middleware::auth();

        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');

        $statistics = $this->balanceService->getStatistics($userId, $startDate, $endDate);
        Response::success($statistics);
    }

    public function categoryBreakdown() {
        $userId = Middleware::auth();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        $type = $_GET['type'] ?? 'expense';
        
        $breakdown = $this->transactionModel->getCategoryBreakdown($userId, $startDate, $endDate, $type);
        Response::success($breakdown);
    }
}
