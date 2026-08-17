<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/KarobarTransaction.php';
require_once __DIR__ . '/../services/KarobarService.php';

class KarobarController {
    private $karobarModel;
    private $karobarService;

    public function __construct() {
        $this->karobarModel = new KarobarTransaction();
        $this->karobarService = new KarobarService();
    }

    public function index() {
        $userId = Middleware::auth();
        
        $filters = [
            'type' => $_GET['type'] ?? null,
            'person_id' => $_GET['person_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'search' => $_GET['search'] ?? null
        ];
        
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $transactions = $this->karobarModel->findAll($userId, $filters, $limit, $offset);
        Response::success($transactions);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $transaction = $this->karobarModel->findById($id, $userId);
        
        if ($transaction) {
            if ($transaction['payment_method'] === 'credit' && !empty($transaction['expense_transaction_id'])) {
                $transaction['linked_credit_purchase'] = $this->karobarService->getCreditPurchaseResource($id, $userId);
            }
            Response::success($transaction);
        }
        
        Response::notFound('Transaction not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['person_id', 'type', 'amount', 'transaction_date']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if (in_array($data['type'] ?? '', ['repaid', 'returned'], true) && empty($data['client_request_id'])) {
            Response::error('Validation failed', 422, ['client_request_id' => 'Client request ID is required for payments']);
        }
        if (in_array($data['type'] ?? '', ['repaid', 'returned'], true) && empty($data['account_id'])) {
            Response::error('Validation failed', 422, ['account_id' => 'Account is required for payments']);
        }

        try {
            $transactionId = $this->karobarService->createTransaction($data, $userId);
            
            if ($transactionId) {
                $transaction = $this->karobarModel->findById($transactionId, $userId);
                Response::success($transaction, 'Transaction created successfully', 201);
            }
            
            Response::serverError('Transaction creation failed');
        } catch (\Throwable $e) { $this->respondToServiceException($e); }
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingTransaction = $this->karobarModel->findById($id, $userId);
        if (!$existingTransaction) {
            Response::notFound('Transaction not found');
        }

        $karobarData = [
            'person_id' => $data['person_id'] ?? $existingTransaction['person_id'],
            'type' => $data['type'] ?? $existingTransaction['type'],
            'amount' => $data['amount'] ?? $existingTransaction['amount'],
            'account_id' => $data['account_id'] ?? $existingTransaction['account_id'],
            'description' => $data['description'] ?? $existingTransaction['description'],
            'transaction_date' => $data['transaction_date'] ?? $existingTransaction['transaction_date'],
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $existingTransaction['due_date']
        ];

        $karobarData['payment_method'] = $data['payment_method'] ?? $existingTransaction['payment_method'];
        foreach (['category_id', 'subcategory_id', 'creditor_id', 'date'] as $field) {
            if (array_key_exists($field, $data)) $karobarData[$field] = $data[$field];
        }
        $baseVersion = $data['base_version'] ?? null;

        try {
            $updated = $this->karobarService->updateTransaction($id, $karobarData, $userId, $baseVersion);
            if ($updated) {
                if (is_array($updated)) Response::success($updated, 'Credit purchase updated successfully');
                $transaction = $this->karobarModel->findById($id, $userId);
                Response::success($transaction, 'Transaction updated successfully');
            }
            Response::serverError('Transaction update failed');
        } catch (\Throwable $e) { $this->respondToServiceException($e); }
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $transaction = $this->karobarModel->findById($id, $userId);
        
        if (!$transaction) {
            Response::notFound('Transaction not found');
        }

        try {
            $this->karobarService->deleteTransaction($id, $userId, $data['base_version'] ?? null);
            Response::success(null, 'Transaction deleted successfully');
        } catch (\Throwable $e) { $this->respondToServiceException($e); }
    }

    public function dashboard() {
        $userId = Middleware::auth();
        $dashboard = $this->karobarService->getDashboardData($userId);
        Response::success($dashboard);
    }

    public function repayment() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['person_id', 'amount', 'account_id', 'transaction_date', 'client_request_id']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $result = $this->karobarService->processRepayment($data, $userId);
            
            if ($result) {
                $transaction = $this->karobarModel->findById($result, $userId);
                $transaction = array_merge($transaction, $this->karobarService->getPaymentState($result, $userId));
                Response::success($transaction, 'Repayment recorded successfully', 201);
            }
            
            Response::serverError('Repayment failed');
        } catch (\Throwable $e) { $this->respondToServiceException($e); }
    }

    public function receiving() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['person_id', 'amount', 'account_id', 'transaction_date', 'client_request_id']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $result = $this->karobarService->processReceiving($data, $userId);
            
            if ($result) {
                $transaction = $this->karobarModel->findById($result, $userId);
                $transaction = array_merge($transaction, $this->karobarService->getPaymentState($result, $userId));
                Response::success($transaction, 'Receiving recorded successfully', 201);
            }
            
            Response::serverError('Receiving failed');
        } catch (\Throwable $e) { $this->respondToServiceException($e); }
    }

    public function creditReports() {
        $userId = Middleware::auth();
        
        $filters = [
            'report_type' => $_GET['report_type'] ?? 'all',
            'type' => $_GET['type'] ?? null,
            'person_id' => $_GET['person_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null
        ];
        
        $reports = $this->karobarService->getCreditReports($userId, $filters);
        Response::success($reports);
    }

    public function aiAnalysis() {
        $userId = Middleware::auth();
        
        $analysis = $this->karobarService->getAIAnalysis($userId);
        Response::success($analysis);
    }

    private function respondToServiceException(\Throwable $error): void {
        if ($error instanceof KarobarAuthorizationException) {
            Response::forbidden($error->getMessage());
        }
        if ($error instanceof KarobarNotFoundException) {
            Response::notFound($error->getMessage());
        }
        if ($error instanceof KarobarConflictException) {
            Response::error($error->getMessage(), 409);
        }
        if ($error instanceof KarobarValidationException) {
            Response::error($error->getMessage(), 422);
        }
        Response::serverError();
    }
}
