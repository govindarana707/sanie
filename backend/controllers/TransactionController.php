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
            if (!empty($transaction['transfer_parent_id'])) {
                $transaction = $this->transactionModel->findById($transaction['transfer_parent_id'], $userId);
            }
            if ($transaction['type'] === 'transfer') {
                $resource = $this->accountingService->getTransferResource($transaction['id'], $userId);
                $transaction['fee_amount'] = $resource['fee_transaction']['amount'] ?? 0;
                $transaction['fee_category_id'] = $resource['fee_transaction']['category_id'] ?? null;
                $transaction['fee_transaction'] = $resource['fee_transaction'];
            }
            if (!empty($transaction['karobar_transaction_id']) && $transaction['payment_method'] === 'credit') {
                $resource = $this->karobarService->getCreditPurchaseResource($transaction['karobar_transaction_id'], $userId);
                $transaction = $resource['transaction'];
                $transaction['linked_credit_purchase'] = $resource;
            }
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

        if ($data['type'] !== 'transfer' && ($data['dynamic_subcategory_type'] ?? null) !== 'goal' && empty($data['category_id'])) {
            Response::error('Validation failed', 422, ['category_id' => 'Category is required for income/expense transactions']);
        }

        $clientRequestId = isset($data['client_request_id']) ? trim((string)$data['client_request_id']) : null;
        $paymentMethod = $data['payment_method'] ?? null;
        if ($clientRequestId !== null && !preg_match('/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|req_[A-Za-z0-9_]{10,60})$/i', $clientRequestId)) {
            Response::error('Validation failed', 422, ['client_request_id' => 'Invalid client request identifier']);
        }
        if ($data['type'] === 'transfer' && !$clientRequestId) {
            Response::error('Validation failed', 422, ['client_request_id' => 'Client request ID is required for transfers']);
        }
        if ($clientRequestId && $data['type'] !== 'transfer' && ($data['dynamic_subcategory_type'] ?? null) !== 'goal' && $paymentMethod !== 'credit') {
            $data['client_request_id'] = $clientRequestId;
            $existing = $this->transactionModel->findByClientRequestId($clientRequestId, $userId);
            if ($existing) {
                Response::success($existing, 'Transaction already synchronized');
            }
        }

        if ($data['type'] === 'expense' && $paymentMethod === 'credit') {
            $errors2 = Middleware::validateRequired($data, ['creditor_id']);
            if (!empty($errors2)) {
                Response::error('Validation failed', 422, ['creditor_id' => 'Creditor is required for credit purchases']);
            }
            if (!$clientRequestId) {
                Response::error('Validation failed', 422, ['client_request_id' => 'Client request ID is required for credit purchases']);
            }

            try {
                $result = $this->karobarService->processCreditPurchase([
                    'amount' => $data['amount'],
                    'category_id' => $data['category_id'] ?? null,
                    'subcategory_id' => $data['subcategory_id'] ?? null,
                    'creditor_id' => $data['creditor_id'],
                    'date' => $data['date'],
                    'description' => $data['description'] ?? '',
                    'due_date' => $data['due_date'] ?? null,
                    'client_request_id' => $clientRequestId,
                ], $userId);

                if ($result) {
                    Response::success($result, 'Credit purchase recorded successfully', 201);
                } else {
                    Response::serverError('Failed to record credit purchase');
                }
            } catch (KarobarAuthorizationException $e) {
                Response::forbidden($e->getMessage());
            } catch (KarobarNotFoundException $e) {
                Response::notFound($e->getMessage());
            } catch (KarobarConflictException $e) {
                Response::error($e->getMessage(), 409);
            } catch (KarobarValidationException $e) {
                Response::error($e->getMessage(), 422);
            } catch (\Throwable $e) {
                Response::serverError($e->getMessage());
            }
            return;
        }

        $dynamicType = $data['dynamic_subcategory_type'] ?? null;
        $dynamicRefId = $data['dynamic_subcategory_id'] ?? null;
        if ($dynamicType === 'goal' && !$clientRequestId) {
            Response::error('Validation failed', 422, ['client_request_id' => 'Client request ID is required for goal contributions']);
        }

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
            $creationResult = $this->accountingService->createTransaction($userId, $data);

            if ($creationResult) {
                $isTransfer = $data['type'] === 'transfer';
                $isGoalContribution = is_array($creationResult) && isset($creationResult['contribution_id']);
                $transactionId = $isTransfer
                    ? $creationResult['transfer_id']
                    : ($isGoalContribution ? $creationResult['contribution_id'] : $creationResult);
                $transaction = ($isTransfer || $isGoalContribution)
                    ? $creationResult
                    : $this->transactionModel->findById($transactionId, $userId);

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
        } catch (TransactionConflictException $e) {
            Response::error($e->getMessage(), 409);
        } catch (TransferAuthorizationException $e) {
            Response::error('Account not found or access denied', 403);
        } catch (GoalContributionAuthorizationException $e) {
            Response::error('Goal or account not found or access denied', 403);
        } catch (\Throwable $e) {
            if ($clientRequestId && $data['type'] !== 'transfer' && ($data['dynamic_subcategory_type'] ?? null) !== 'goal') {
                $existing = $this->transactionModel->findByClientRequestId($clientRequestId, $userId);
                if ($existing) {
                    Response::success($existing, 'Transaction already synchronized');
                }
            }
            if ($e instanceof InvalidArgumentException
                || (!($e instanceof PDOException) && preg_match('/amount|account|balance|required|positive|not active|access denied/i', $e->getMessage()))) {
                Response::error($e->getMessage(), 422);
            }
            Response::serverError($e->getMessage());
        }
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $baseVersion = $this->readBaseVersion($data);
        
        $existingTransaction = $this->transactionModel->findById($id, $userId);
        if (!$existingTransaction) {
            Response::notFound('Transaction not found');
        }

        if ($existingTransaction['karobar_transaction_id']) {
            if ($baseVersion === null) Response::error('A base version is required for linked credit purchases.', 422);
            try {
                $resource = $this->karobarService->updateCreditPurchaseByTransaction($id, $data, $userId, $baseVersion);
                Response::success($resource, 'Credit purchase updated successfully');
            } catch (KarobarAuthorizationException $e) {
                Response::forbidden($e->getMessage());
            } catch (KarobarNotFoundException $e) {
                Response::notFound($e->getMessage());
            } catch (KarobarConflictException $e) {
                $this->respondConflict($id, $userId, $e->getMessage());
            } catch (KarobarValidationException $e) {
                Response::error($e->getMessage(), 422);
            } catch (\Throwable $e) {
                Response::serverError($e->getMessage());
            }
        }
        if ($baseVersion !== null && (!in_array($existingTransaction['type'], ['income', 'expense', 'transfer', 'goal_contribution'], true) || $existingTransaction['payment_method'] === 'credit')) {
            Response::error('This transaction must be edited while online', 422);
        }

        try {
            $updateResult = $this->accountingService->updateTransaction($id, $userId, $data, $baseVersion);
            $transaction = $existingTransaction['type'] === 'transfer'
                ? $updateResult
                : $this->transactionModel->findById($id, $userId);
            $this->notifService->create($userId, 'transaction_updated',
                'Transaction Updated',
                'Your transaction has been updated.',
                'transaction', $id);
            Response::success($transaction, 'Transaction updated successfully');
        } catch (TransactionConflictException $e) {
            $this->respondConflict($id, $userId, 'This transaction was changed elsewhere.');
        } catch (TransferAuthorizationException $e) {
            Response::error('Account not found or access denied', 403);
        } catch (GoalContributionAuthorizationException $e) {
            Response::error('Goal contribution not found or access denied', 403);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $baseVersion = $this->readBaseVersion($data);
        $clientRequestId = isset($data['client_request_id']) ? trim((string)$data['client_request_id']) : null;
        $transaction = $this->transactionModel->findById($id, $userId);
        
        if (!$transaction) {
            if ($clientRequestId && strlen($clientRequestId) <= 64) {
                if ($this->transactionModel->existsById($id)) {
                    Response::notFound('Transaction not found');
                }
                Response::success(['id' => (int)$id], 'Transaction was already deleted');
            }
            Response::notFound('Transaction not found');
        }

        if ($transaction['karobar_transaction_id']) {
            if ($baseVersion === null) Response::error('A base version is required for linked credit purchases.', 422);
            try {
                $this->karobarService->deleteCreditPurchaseByTransaction($id, $userId, $baseVersion);
                Response::success(['id' => (int)$id], 'Credit purchase deleted successfully');
            } catch (KarobarAuthorizationException $e) {
                Response::forbidden($e->getMessage());
            } catch (KarobarNotFoundException $e) {
                Response::notFound($e->getMessage());
            } catch (KarobarConflictException $e) {
                $this->respondConflict($id, $userId, $e->getMessage());
            } catch (KarobarValidationException $e) {
                Response::error($e->getMessage(), 422);
            } catch (\Throwable $e) {
                Response::serverError($e->getMessage());
            }
        }
        if ($baseVersion !== null && (!in_array($transaction['type'], ['income', 'expense', 'transfer', 'goal_contribution'], true) || $transaction['payment_method'] === 'credit')) {
            Response::error('This transaction must be deleted while online', 422);
        }

        try {
            $this->accountingService->deleteTransaction($id, $userId, $baseVersion);

            $this->notifService->create($userId, 'transaction_deleted',
                'Transaction Deleted',
                'A transaction has been removed.',
                'transaction', $id);
            Response::success(['id' => (int)$id], 'Transaction deleted successfully');
        } catch (TransactionConflictException $e) {
            $this->respondConflict($id, $userId, 'This transaction was changed before your delete could sync.');
        } catch (GoalContributionAuthorizationException $e) {
            Response::error('Goal contribution not found or access denied', 403);
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    private function readBaseVersion(array $data) {
        if (!array_key_exists('base_version', $data)) return null;
        $version = filter_var($data['base_version'], FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            Response::error('Validation failed', 422, ['base_version' => 'A valid base version is required']);
        }
        return (int)$version;
    }

    private function respondConflict($id, $userId, $message) {
        $serverData = $this->transactionModel->findById($id, $userId);
        Response::json([
            'success' => false,
            'code' => 'CONFLICT',
            'message' => $message,
            'serverData' => $serverData ? $this->safeConflictData($serverData) : null
        ], 409);
    }

    private function safeConflictData(array $transaction) {
        $fields = [
            'id', 'version', 'updated_at', 'date', 'type', 'amount', 'payment_method', 'description',
            'account_id', 'from_account_id', 'to_account_id', 'category_id', 'subcategory_id',
            'category_name', 'category_icon', 'category_color', 'subcategory_name',
            'account_name', 'from_account_name', 'to_account_name', 'karobar_transaction_id'
        ];
        return array_intersect_key($transaction, array_flip($fields));
    }

    public function bulkDestroy() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids)) {
            Response::error('Validation failed', 422, ['ids' => 'Transaction IDs must be an array']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids || count($ids) > 100) {
            Response::error('Select between 1 and 100 transactions', 422);
        }

        try {
            $deleted = $this->accountingService->bulkDeleteTransactions($ids, $userId);
            $this->notifService->create($userId, 'transaction_deleted',
                'Transactions Deleted',
                "{$deleted} transactions were removed.",
                'transaction', null);
            Response::success(['deleted_count' => $deleted], "{$deleted} transactions deleted successfully");
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 422);
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
