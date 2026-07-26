<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Account.php';

class TransactionController {
    private $transactionModel;
    private $accountModel;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->accountModel = new Account();
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
        
        $errors = Middleware::validateRequired($data, ['account_id', 'category_id', 'amount', 'type', 'date']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $transactionData = [
            'user_id' => $userId,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'subcategory_id' => $data['subcategory_id'] ?? null,
            'amount' => $data['amount'],
            'type' => $data['type'],
            'date' => $data['date'],
            'time' => $data['time'] ?? null,
            'description' => $data['description'] ?? '',
            'notes' => $data['notes'] ?? '',
            'tags' => json_encode($data['tags'] ?? []),
            'is_recurring' => $data['is_recurring'] ?? false,
            'is_favorite' => $data['is_favorite'] ?? false,
            'location_lat' => $data['location_lat'] ?? null,
            'location_lng' => $data['location_lng'] ?? null,
            'location_address' => $data['location_address'] ?? '',
            'receipt_path' => $data['receipt_path'] ?? '',
            'voice_note_path' => $data['voice_note_path'] ?? ''
        ];

        $transactionId = $this->transactionModel->create($transactionData);
        
        if ($transactionId) {
            // Update account balance
            $amount = $data['type'] === 'income' ? $data['amount'] : -$data['amount'];
            $this->accountModel->updateBalance($data['account_id'], $amount);
            
            $transaction = $this->transactionModel->findById($transactionId, $userId);
            Response::success($transaction, 'Transaction created successfully', 201);
        }
        
        Response::serverError('Transaction creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingTransaction = $this->transactionModel->findById($id, $userId);
        if (!$existingTransaction) {
            Response::notFound('Transaction not found');
        }

        $transactionData = [
            'account_id' => $data['account_id'] ?? $existingTransaction['account_id'],
            'category_id' => $data['category_id'] ?? $existingTransaction['category_id'],
            'subcategory_id' => $data['subcategory_id'] ?? $existingTransaction['subcategory_id'],
            'amount' => $data['amount'] ?? $existingTransaction['amount'],
            'type' => $data['type'] ?? $existingTransaction['type'],
            'date' => $data['date'] ?? $existingTransaction['date'],
            'time' => $data['time'] ?? $existingTransaction['time'],
            'description' => $data['description'] ?? $existingTransaction['description'],
            'notes' => $data['notes'] ?? $existingTransaction['notes'],
            'tags' => json_encode($data['tags'] ?? json_decode($existingTransaction['tags'] ?? '[]')),
            'is_recurring' => $data['is_recurring'] ?? $existingTransaction['is_recurring'],
            'is_favorite' => $data['is_favorite'] ?? $existingTransaction['is_favorite'],
            'location_lat' => $data['location_lat'] ?? $existingTransaction['location_lat'],
            'location_lng' => $data['location_lng'] ?? $existingTransaction['location_lng'],
            'location_address' => $data['location_address'] ?? $existingTransaction['location_address'],
            'receipt_path' => $data['receipt_path'] ?? $existingTransaction['receipt_path'],
            'voice_note_path' => $data['voice_note_path'] ?? $existingTransaction['voice_note_path']
        ];

        if ($this->transactionModel->update($id, $userId, $transactionData)) {
            // Update account balance if amount or type changed
            if ($existingTransaction['amount'] != $transactionData['amount'] || $existingTransaction['type'] != $transactionData['type']) {
                $oldAmount = $existingTransaction['type'] === 'income' ? $existingTransaction['amount'] : -$existingTransaction['amount'];
                $newAmount = $transactionData['type'] === 'income' ? $transactionData['amount'] : -$transactionData['amount'];
                $difference = $newAmount - $oldAmount;
                $this->accountModel->updateBalance($transactionData['account_id'], $difference);
            }
            
            $transaction = $this->transactionModel->findById($id, $userId);
            Response::success($transaction, 'Transaction updated successfully');
        }
        
        Response::serverError('Transaction update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        $transaction = $this->transactionModel->findById($id, $userId);
        
        if (!$transaction) {
            Response::notFound('Transaction not found');
        }

        if ($this->transactionModel->delete($id, $userId)) {
            // Reverse account balance
            $amount = $transaction['type'] === 'income' ? -$transaction['amount'] : $transaction['amount'];
            $this->accountModel->updateBalance($transaction['account_id'], $amount);
            
            Response::success(null, 'Transaction deleted successfully');
        }
        
        Response::serverError('Transaction deletion failed');
    }

    public function statistics() {
        $userId = Middleware::auth();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        
        $statistics = $this->transactionModel->getStatistics($userId, $startDate, $endDate);
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
