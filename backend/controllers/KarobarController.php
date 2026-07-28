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

        try {
            $transactionId = $this->karobarService->createTransaction($data, $userId);
            
            if ($transactionId) {
                $transaction = $this->karobarModel->findById($transactionId, $userId);
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
            'due_date' => $data['due_date'] ?? $existingTransaction['due_date']
        ];

        if ($this->karobarModel->update($id, $userId, $karobarData)) {
            $transaction = $this->karobarModel->findById($id, $userId);
            Response::success($transaction, 'Transaction updated successfully');
        }
        
        Response::serverError('Transaction update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        $transaction = $this->karobarModel->findById($id, $userId);
        
        if (!$transaction) {
            Response::notFound('Transaction not found');
        }

        try {
            $this->karobarService->deleteTransaction($id, $userId);
            Response::success(null, 'Transaction deleted successfully');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function dashboard() {
        $userId = Middleware::auth();
        $dashboard = $this->karobarService->getDashboardData($userId);
        Response::success($dashboard);
    }

    public function repayment() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['person_id', 'amount', 'transaction_date']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $result = $this->karobarService->processRepayment($data, $userId);
            
            if ($result) {
                $transaction = $this->karobarModel->findById($result, $userId);
                Response::success($transaction, 'Repayment recorded successfully', 201);
            }
            
            Response::serverError('Repayment failed');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function receiving() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['person_id', 'amount', 'transaction_date']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $result = $this->karobarService->processReceiving($data, $userId);
            
            if ($result) {
                $transaction = $this->karobarModel->findById($result, $userId);
                Response::success($transaction, 'Receiving recorded successfully', 201);
            }
            
            Response::serverError('Receiving failed');
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
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
}