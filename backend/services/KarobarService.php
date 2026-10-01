<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/KarobarTransaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Person.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/KarobarOutstandingService.php';

class KarobarValidationException extends InvalidArgumentException {}
class KarobarAuthorizationException extends RuntimeException {}
class KarobarNotFoundException extends RuntimeException {}
class KarobarConflictException extends RuntimeException {}

class KarobarService {
    private $conn;
    private $karobarModel;
    private $accountModel;
    private $transactionModel;
    private $personModel;
    private $notifService;
    private $accountingService;
    private $outstandingService;
    private $creditPurchaseFailureInjector = null;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->karobarModel = new KarobarTransaction();
        $this->accountModel = new Account();
        $this->transactionModel = new Transaction();
        $this->personModel = new Person();
        $this->notifService = new NotificationService();
        $this->accountingService = new AccountingService();
        $this->outstandingService = new KarobarOutstandingService();
    }

    public function createTransaction($data, $userId) {
        $type = $data['type'] ?? null;
        if ($type === 'repaid') {
            return $this->processRepayment($data, $userId);
        }
        if ($type === 'returned') {
            return $this->processReceiving($data, $userId);
        }

        $requestId = $this->validateClientRequestId($data['client_request_id'] ?? null);
        if (!$requestId) throw new KarobarValidationException('A client request ID is required for Karobar transactions.');
        $data['client_request_id'] = $requestId;
        $existingRequest = $this->karobarModel->findByClientRequestId($requestId, $userId);
        if ($existingRequest) {
            $this->assertIdempotentOriginMatches($existingRequest, $data);
            return (int)$existingRequest['id'];
        }

        $this->conn->beginTransaction();

        try {
            $this->validateKarobarData($data, $userId);
            $this->lockOwnedPerson($data['person_id'], $userId, true);
            if (!empty($data['account_id'])) {
                $this->lockOwnedAccount($data['account_id'], $userId);
            }
            $existingRequest = $this->karobarModel->findByClientRequestId($requestId, $userId);
            if ($existingRequest) {
                $this->assertIdempotentOriginMatches($existingRequest, $data);
                $this->conn->commit();
                return (int)$existingRequest['id'];
            }
            $karobarData = [
                'user_id' => $userId,
                'person_id' => $data['person_id'],
                'type' => $data['type'],
                'amount' => floatval($data['amount']),
                'account_id' => $data['account_id'] ?? null,
                'description' => $data['description'] ?? '',
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'] ?? null,
                'payment_method' => $data['payment_method'] ?? null
                ,'client_request_id' => $data['client_request_id'] ?? null
            ];

            $karobarId = $this->karobarModel->create($karobarData);

            if (!$karobarId) {
                $this->conn->rollBack();
                return false;
            }

            if ($karobarData['account_id']) {
                $this->accountingService->recalculateAndUpdateBalance($karobarData['account_id'], $userId);
            }

            $this->sendKarobarNotification($karobarData, $karobarId, $userId);

            $this->conn->commit();
            return (int)$karobarId;
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            if ((string)$e->getCode() === '23000') {
                $existingRequest = $this->karobarModel->findByClientRequestId($requestId, $userId);
                if ($existingRequest) {
                    $this->assertIdempotentOriginMatches($existingRequest, $data);
                    return (int)$existingRequest['id'];
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::createTransaction error: " . $e->getMessage());
            throw $e;
        }
    }

    public function processCreditPurchase($expenseData, $userId) {
        // Resolve ownership before the idempotency lookup; active status is
        // enforced by the locked create path so a retry remains replayable
        // even if the person was archived after the original commit.
        $normalized = $this->normalizeCreditPurchase($expenseData, $userId, true, false);
        $this->conn->beginTransaction();

        try {
            $existing = $this->findCreditPurchaseByRequestId($normalized['client_request_id'], $userId);
            if ($existing) {
                $this->assertCreditPurchaseReplayMatches($existing, $normalized);
                $this->conn->commit();
                return $this->getCreditPurchaseResource((int)$existing['karobar_transaction_id'], $userId);
            }

            $this->lockOwnedPerson($normalized['creditor_id'], $userId, true);
            $existing = $this->findCreditPurchaseByRequestId($normalized['client_request_id'], $userId);
            if ($existing) {
                $this->assertCreditPurchaseReplayMatches($existing, $normalized);
                $this->conn->commit();
                return $this->getCreditPurchaseResource((int)$existing['karobar_transaction_id'], $userId);
            }

            $karobarId = $this->karobarModel->create([
                'user_id' => $userId,
                'person_id' => $normalized['creditor_id'],
                'type' => 'borrowed',
                'amount' => $normalized['amount'],
                'account_id' => null,
                'description' => $normalized['description'],
                'transaction_date' => $normalized['date'],
                'due_date' => $normalized['due_date'],
                'payment_method' => 'credit',
                'client_request_id' => $normalized['client_request_id'],
            ]);

            if (!$karobarId) throw new RuntimeException('Unable to create the credit payable.');
            $this->invokeCreditPurchaseFailure('after_payable_create');

            $expenseTransactionId = $this->transactionModel->create([
                'user_id' => $userId,
                'account_id' => null,
                'from_account_id' => null,
                'to_account_id' => null,
                'category_id' => $normalized['category_id'],
                'subcategory_id' => $normalized['subcategory_id'],
                'amount' => $normalized['amount'],
                'type' => 'expense',
                'payment_method' => 'credit',
                'karobar_transaction_id' => $karobarId,
                'client_request_id' => $normalized['client_request_id'],
                'date' => $normalized['date'],
                'description' => $normalized['description']
            ]);

            if (!$expenseTransactionId || !$this->karobarModel->updateLinkedIds($karobarId, $expenseTransactionId, null)) {
                throw new RuntimeException('Unable to durably link the credit purchase.');
            }
            $this->invokeCreditPurchaseFailure('after_expense_create');

            $this->conn->commit();

            $this->sendKarobarNotification([
                'type' => 'borrowed',
                'amount' => $normalized['amount'],
                'person_id' => $normalized['creditor_id'],
                'transaction_date' => $normalized['date']
            ], $karobarId, $userId);

            return $this->getCreditPurchaseResource((int)$karobarId, $userId);
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            if ((string)$e->getCode() === '23000') {
                $existing = $this->findCreditPurchaseByRequestId($normalized['client_request_id'], $userId);
                if ($existing) {
                    $this->assertCreditPurchaseReplayMatches($existing, $normalized);
                    return $this->getCreditPurchaseResource((int)$existing['karobar_transaction_id'], $userId);
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::processCreditPurchase error: " . $e->getMessage());
            throw $e;
        }
    }

    public function setCreditPurchaseFailureInjector(?callable $injector): void {
        $this->creditPurchaseFailureInjector = $injector;
    }

    protected function invokeCreditPurchaseFailure(string $point): void {
        if ($this->creditPurchaseFailureInjector) call_user_func($this->creditPurchaseFailureInjector, $point);
    }

    public function processRepayment($repayData, $userId) {
        return $this->processPayment($repayData, $userId, 'repaid');
    }

    public function processReceiving($receiveData, $userId) {
        return $this->processPayment($receiveData, $userId, 'returned');
    }

    private function processPayment($data, $userId, $type) {
        $clientRequestId = null;
        $personId = null;
        $accountId = null;
        $amount = null;
        $date = null;
        $this->conn->beginTransaction();
        try {
            $personId = $this->parsePositiveId($data['person_id'] ?? null, 'person');
            $accountId = $data['account_id'] ?? $this->getDefaultAccountId($userId);
            if (!$accountId) {
                throw new KarobarValidationException('A payment account is required.');
            }
            $accountId = $this->parsePositiveId($accountId, 'account');
            $amount = $this->parseMoneyAmount($data['amount'] ?? null);
            $date = $this->validateDate($data['transaction_date'] ?? null, 'transaction date');
            $clientRequestId = $this->validateClientRequestId($data['client_request_id'] ?? null);

            $existingRequest = $clientRequestId
                ? $this->karobarModel->findByClientRequestId($clientRequestId, $userId)
                : false;
            if ($existingRequest) {
                $this->assertIdempotentPaymentMatches($existingRequest, $type, $personId, $accountId, $amount, $date);
                $this->conn->commit();
                return (int)$existingRequest['id'];
            }

            $this->lockOwnedPerson($personId, $userId, true);
            $this->lockOwnedAccount($accountId, $userId);

            // Recheck after acquiring the per-person lock. A competing request
            // may have committed while this request was waiting.
            if ($clientRequestId) {
                $existingRequest = $this->karobarModel->findByClientRequestId($clientRequestId, $userId);
                if ($existingRequest) {
                    $this->assertIdempotentPaymentMatches($existingRequest, $type, $personId, $accountId, $amount, $date);
                    $this->conn->commit();
                    return (int)$existingRequest['id'];
                }
            }

            $outstanding = $this->getOutstandingForPayment($personId, $userId, $type, null, true);
            $this->assertPaymentWithinOutstanding($amount, $outstanding, $type);
            $this->assertPaymentDateIsValidForDebt($personId, $userId, $type, $date);

            $karobarId = $this->karobarModel->create([
                'user_id' => $userId,
                'person_id' => $personId,
                'type' => $type,
                'amount' => $amount,
                'account_id' => $accountId,
                'description' => $data['description'] ?? ($type === 'repaid' ? 'Repayment' : 'Money received'),
                'transaction_date' => $date,
                'due_date' => null,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'client_request_id' => $clientRequestId,
            ]);

            if (!$karobarId) {
                throw new RuntimeException('Unable to create the payment record.');
            }

            $this->afterPaymentRecordCreated($karobarId, $data, $userId);

            if (!$this->accountingService->recalculateAndUpdateBalance($accountId, $userId)) {
                throw new RuntimeException('Unable to update the account balance.');
            }

            $remaining = $this->getOutstandingForPayment($personId, $userId, $type, null, true);
            if ($remaining < -0.00001) {
                throw new RuntimeException('Payment would make the outstanding balance negative.');
            }

            $this->sendKarobarNotification([
                'type' => $type,
                'amount' => $amount,
                'person_id' => $personId,
                'transaction_date' => $date
            ], $karobarId, $userId);

            $this->conn->commit();
            return (int)$karobarId;
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();

            // A retry can race on the unique idempotency key before it obtains
            // the person lock. Resolve that race as a replay, not a 500 error.
            if ($clientRequestId && (string)$e->getCode() === '23000') {
                $existingRequest = $this->karobarModel->findByClientRequestId($clientRequestId, $userId);
                if ($existingRequest) {
                    $this->assertIdempotentPaymentMatches(
                        $existingRequest,
                        $type,
                        $personId,
                        $accountId,
                        $amount,
                        $date
                    );
                    return (int)$existingRequest['id'];
                }
            }

            error_log("KarobarService::processPayment error: " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::processPayment error: " . $e->getMessage());
            throw $e;
        }
    }

    protected function afterPaymentRecordCreated($karobarId, $data, $userId): void {
        // Test seam for verifying transaction rollback after the dependent write.
    }

    public function getCreditPurchaseResource($karobarId, $userId): array {
        $payable = $this->karobarModel->findById($karobarId, $userId);
        if (!$this->isCreditPurchaseOrigin($payable)) {
            throw new KarobarNotFoundException('Credit purchase not found.');
        }
        $transaction = $this->transactionModel->findById($payable['expense_transaction_id'], $userId);
        if (!$this->creditPurchasePairMatches($payable, $transaction)) {
            throw new KarobarConflictException('The linked credit purchase is incomplete or inconsistent.');
        }
        $outstanding = $this->getOutstandingForPayment($payable['person_id'], $userId, 'repaid');
        $transaction['creditor_id'] = (int)$payable['person_id'];
        $transaction['due_date'] = $payable['due_date'];
        $transaction['karobar_version'] = (int)$payable['version'];
        return [
            'karobar_id' => (int)$payable['id'],
            'transaction_id' => (int)$transaction['id'],
            'version' => (int)$transaction['version'],
            'outstanding' => max(0, $outstanding),
            'transaction' => $transaction,
            'payable' => $payable,
        ];
    }

    public function updateCreditPurchaseByTransaction($transactionId, array $data, $userId, $baseVersion): array {
        $transaction = $this->transactionModel->findById($transactionId, $userId);
        if (!$transaction || empty($transaction['karobar_transaction_id'])) {
            throw new KarobarNotFoundException('Credit purchase not found.');
        }
        return $this->updateCreditPurchase((int)$transaction['karobar_transaction_id'], $data, $userId, $baseVersion);
    }

    public function updateCreditPurchase($karobarId, array $data, $userId, $baseVersion): array {
        $baseVersion = $this->parsePositiveId($baseVersion, 'base version');
        $candidate = $this->findOwnedTransactionOrFail($karobarId, $userId);
        if (!$this->isCreditPurchaseOrigin($candidate)) {
            throw new KarobarValidationException('Only linked credit-purchase origins use this operation.');
        }
        $candidateTransaction = $this->transactionModel->findById($candidate['expense_transaction_id'], $userId);
        if (!$this->creditPurchasePairMatches($candidate, $candidateTransaction)) {
            throw new KarobarConflictException('The linked credit purchase is incomplete or inconsistent.');
        }

        $input = [
            'amount' => $data['amount'] ?? $candidate['amount'],
            'category_id' => $data['category_id'] ?? $candidateTransaction['category_id'],
            'subcategory_id' => array_key_exists('subcategory_id', $data) ? $data['subcategory_id'] : $candidateTransaction['subcategory_id'],
            'creditor_id' => $data['creditor_id'] ?? $data['person_id'] ?? $candidate['person_id'],
            'date' => $data['date'] ?? $data['transaction_date'] ?? $candidate['transaction_date'],
            'description' => $data['description'] ?? $candidate['description'],
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $candidate['due_date'],
            'client_request_id' => $candidateTransaction['client_request_id'],
        ];
        $normalized = $this->normalizeCreditPurchase($input, $userId, false, false);
        if ((int)$normalized['creditor_id'] !== (int)$candidate['person_id']) {
            $this->assertOwnedPersonAvailable($normalized['creditor_id'],$userId,true);
        }

        $personIds = array_values(array_unique([(int)$candidate['person_id'], (int)$normalized['creditor_id']]));
        sort($personIds, SORT_NUMERIC);
        $this->conn->beginTransaction();
        try {
            foreach ($personIds as $personId) $this->lockOwnedPerson($personId, $userId);
            $payable = $this->karobarModel->findByIdForUpdate($karobarId, $userId);
            $transaction = $payable
                ? $this->transactionModel->findByIdForUpdate($payable['expense_transaction_id'], $userId)
                : false;
            if (!$this->creditPurchasePairMatches($payable, $transaction)) {
                throw new KarobarConflictException('The linked credit purchase changed or is inconsistent.');
            }
            if ((int)$transaction['version'] !== $baseVersion || (int)$payable['version'] !== $baseVersion) {
                throw new KarobarConflictException('This credit purchase was changed elsewhere.');
            }

            $this->assertCreditPurchaseHistoryAllowsUpdate($payable, $normalized, $userId);
            $updatedPayable = $this->karobarModel->update($karobarId, $userId, [
                'person_id' => $normalized['creditor_id'], 'type' => 'borrowed',
                'amount' => $normalized['amount'], 'account_id' => null,
                'description' => $normalized['description'],
                'transaction_date' => $normalized['date'], 'due_date' => $normalized['due_date'],
                'payment_method' => 'credit',
            ], $baseVersion);
            if (!$updatedPayable) throw new KarobarConflictException('This credit purchase was changed elsewhere.');
            $this->invokeCreditPurchaseFailure('after_payable_update');

            $updatedTransaction = $this->transactionModel->update($transaction['id'], $userId, [
                'account_id' => null, 'from_account_id' => null, 'to_account_id' => null,
                'category_id' => $normalized['category_id'], 'subcategory_id' => $normalized['subcategory_id'],
                'amount' => $normalized['amount'], 'type' => 'expense', 'payment_method' => 'credit',
                'date' => $normalized['date'], 'description' => $normalized['description'],
            ], $baseVersion);
            if (!$updatedTransaction) throw new KarobarConflictException('This credit purchase was changed elsewhere.');
            $this->invokeCreditPurchaseFailure('after_expense_update');

            $this->conn->commit();
            return $this->getCreditPurchaseResource($karobarId, $userId);
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    public function deleteCreditPurchaseByTransaction($transactionId, $userId, $baseVersion): bool {
        $transaction = $this->transactionModel->findById($transactionId, $userId);
        if (!$transaction || empty($transaction['karobar_transaction_id'])) {
            throw new KarobarNotFoundException('Credit purchase not found.');
        }
        return $this->deleteCreditPurchase((int)$transaction['karobar_transaction_id'], $userId, $baseVersion);
    }

    public function deleteCreditPurchase($karobarId, $userId, $baseVersion): bool {
        $baseVersion = $this->parsePositiveId($baseVersion, 'base version');
        $candidate = $this->findOwnedTransactionOrFail($karobarId, $userId);
        if (!$this->isCreditPurchaseOrigin($candidate)) {
            throw new KarobarValidationException('Only linked credit-purchase origins use this operation.');
        }
        $this->conn->beginTransaction();
        try {
            $this->lockOwnedPerson($candidate['person_id'], $userId);
            $payable = $this->karobarModel->findByIdForUpdate($karobarId, $userId);
            $transaction = $payable
                ? $this->transactionModel->findByIdForUpdate($payable['expense_transaction_id'], $userId)
                : false;
            if (!$this->creditPurchasePairMatches($payable, $transaction)) {
                throw new KarobarConflictException('The linked credit purchase changed or is inconsistent.');
            }
            if ((int)$transaction['version'] !== $baseVersion || (int)$payable['version'] !== $baseVersion) {
                throw new KarobarConflictException('This credit purchase was changed before deletion.');
            }
            if ($this->sumPersonType($payable['person_id'], $userId, 'repaid', true) > 0) {
                throw new KarobarConflictException('This credit purchase cannot be deleted while repayment history exists for the creditor.');
            }
            if (!$this->karobarModel->updateLinkedIds($karobarId, null, null)) {
                throw new RuntimeException('Unable to unlink the credit purchase.');
            }
            if (!$this->transactionModel->delete($transaction['id'], $userId, $baseVersion)) {
                throw new KarobarConflictException('This credit purchase was changed before deletion.');
            }
            if (!$this->karobarModel->delete($karobarId, $userId)) {
                throw new RuntimeException('Unable to delete the credit payable.');
            }
            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    public function deleteTransaction($id, $userId, $baseVersion = null) {
        $candidate = $this->findOwnedTransactionOrFail($id, $userId);
        if ($this->isCreditPurchaseOrigin($candidate)) {
            if ($baseVersion === null) throw new KarobarValidationException('A base version is required for linked credit purchases.');
            return $this->deleteCreditPurchase($id, $userId, $baseVersion);
        }
        $this->conn->beginTransaction();

        try {
            $transaction = $this->findOwnedTransactionOrFail($id, $userId);
            $this->lockOwnedPerson($transaction['person_id'], $userId);
            if (!empty($transaction['account_id'])) {
                $this->lockOwnedAccount($transaction['account_id'], $userId);
            }
            $transaction = $this->karobarModel->findByIdForUpdate($id, $userId);
            if (!$transaction) throw new KarobarConflictException('The payment changed before deletion.');

            if (!empty($transaction['expense_transaction_id'])) {
                $this->transactionModel->delete($transaction['expense_transaction_id'], $userId);
            }
            if (!empty($transaction['income_transaction_id'])) {
                $this->transactionModel->delete($transaction['income_transaction_id'], $userId);
            }
            $this->karobarModel->delete($id, $userId);

            if ($transaction['account_id']) {
                $this->accountingService->recalculateAndUpdateBalance($transaction['account_id'], $userId);
            }

            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::deleteTransaction error: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateTransaction($id, $data, $userId, $baseVersion = null) {
        $candidate = $this->findOwnedTransactionOrFail($id, $userId);
        if ($this->isCreditPurchaseOrigin($candidate)) {
            if ($baseVersion === null) throw new KarobarValidationException('A base version is required for linked credit purchases.');
            return $this->updateCreditPurchase($id, $data, $userId, $baseVersion);
        }
        $this->conn->beginTransaction();
        try {
            $existing = $this->findOwnedTransactionOrFail($id, $userId);

            $merged = [
                'person_id' => $data['person_id'] ?? $existing['person_id'],
                'type' => $data['type'] ?? $existing['type'],
                'amount' => $data['amount'] ?? $existing['amount'],
                'account_id' => array_key_exists('account_id', $data) ? $data['account_id'] : $existing['account_id'],
                'description' => $data['description'] ?? $existing['description'],
                'transaction_date' => $data['transaction_date'] ?? $existing['transaction_date'],
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $existing['due_date'],
                'payment_method' => $data['payment_method'] ?? $existing['payment_method'],
            ];

            $personIds = array_values(array_unique([(int)$existing['person_id'], (int)$merged['person_id']]));
            sort($personIds, SORT_NUMERIC);
            foreach ($personIds as $personId) $this->lockOwnedPerson($personId, $userId);

            $existing = $this->karobarModel->findByIdForUpdate($id, $userId);
            if (!$existing) throw new KarobarConflictException('The transaction changed before update.');

            $existingIsPayment = in_array($existing['type'], ['repaid', 'returned'], true);
            $newIsPayment = in_array($merged['type'], ['repaid', 'returned'], true);
            if ($existingIsPayment || $newIsPayment) {
                if (!$existingIsPayment || !$newIsPayment
                    || $existing['type'] !== $merged['type']
                    || (int)$existing['person_id'] !== (int)$merged['person_id']) {
                    throw new KarobarValidationException('Repayment and receiving records cannot change person or transaction type. Delete the payment and record a new one.');
                }

                $merged['amount'] = $this->parseMoneyAmount($merged['amount']);
                $merged['transaction_date'] = $this->validateDate($merged['transaction_date'], 'transaction date');
                $merged['account_id'] = $this->parsePositiveId($merged['account_id'] ?? null, 'account');
                $accountIdsToLock = array_values(array_unique(array_filter([
                    (int)$existing['account_id'],
                    (int)$merged['account_id'],
                ])));
                sort($accountIdsToLock, SORT_NUMERIC);
                foreach ($accountIdsToLock as $accountId) $this->lockOwnedAccount($accountId, $userId);

                $outstanding = $this->getOutstandingForPayment(
                    $merged['person_id'],
                    $userId,
                    $merged['type'],
                    (int)$existing['id'],
                    true
                );
                $this->assertPaymentWithinOutstanding($merged['amount'], $outstanding, $merged['type']);
                $this->assertPaymentDateIsValidForDebt(
                    $merged['person_id'],
                    $userId,
                    $merged['type'],
                    $merged['transaction_date']
                );
            } else {
                $this->validateKarobarData(
                    $merged,
                    $userId,
                    (int)$merged['person_id'] !== (int)$existing['person_id']
                );
                if (!empty($merged['account_id'])) $this->lockOwnedAccount($merged['account_id'], $userId);
            }
            if (!$this->karobarModel->update($id, $userId, $merged)) {
                throw new Exception('Transaction update failed');
            }

            $accountIds = array_unique(array_filter([$existing['account_id'], $merged['account_id']]));
            foreach ($accountIds as $accountId) {
                $this->accountingService->recalculateAndUpdateBalance($accountId, $userId);
            }
            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    private function normalizeCreditPurchase(array $data, $userId, bool $requireRequestId = true, bool $requireActivePerson = true): array {
        $amount = $this->parseMoneyAmount($data['amount'] ?? null);
        $date = $this->validateDate($data['date'] ?? null, 'transaction date');
        $creditorId = $this->parsePositiveId($data['creditor_id'] ?? null, 'creditor');
        $this->assertOwnedPersonAvailable($creditorId,$userId,$requireActivePerson);

        $categoryId = $this->parsePositiveId($data['category_id'] ?? null, 'category');
        $stmt = $this->conn->prepare(
            "SELECT id FROM categories WHERE id=:id AND type='expense' AND status='active'
             AND (user_id IS NULL OR user_id=:uid) LIMIT 1"
        );
        $stmt->execute([':id' => $categoryId, ':uid' => $userId]);
        if (!$stmt->fetchColumn()) throw new KarobarAuthorizationException('The selected expense category is unavailable.');

        $subcategoryId = $data['subcategory_id'] ?? null;
        if ($subcategoryId !== null && $subcategoryId !== '') {
            $subcategoryId = $this->parsePositiveId($subcategoryId, 'subcategory');
            $stmt = $this->conn->prepare(
                "SELECT sc.id FROM subcategories sc JOIN categories c ON c.id=sc.category_id
                 WHERE sc.id=:id AND sc.category_id=:category_id
                   AND sc.status='active' AND c.status='active'
                   AND (sc.user_id IS NULL OR sc.user_id=:suid)
                   AND (c.user_id IS NULL OR c.user_id=:cuid) LIMIT 1"
            );
            $stmt->execute([':id' => $subcategoryId, ':category_id' => $categoryId, ':suid' => $userId, ':cuid' => $userId]);
            if (!$stmt->fetchColumn()) throw new KarobarAuthorizationException('The selected expense subcategory is unavailable.');
        } else {
            $subcategoryId = null;
        }

        $description = trim((string)($data['description'] ?? 'Credit purchase'));
        $length = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
        if ($length > 255) throw new KarobarValidationException('Description must be 255 characters or fewer.');
        $dueDate = $data['due_date'] ?? null;
        if ($dueDate !== null && $dueDate !== '') {
            $dueDate = $this->validateDate($dueDate, 'due date');
            if ($dueDate < $date) throw new KarobarValidationException('Due date cannot be before the purchase date.');
        } else {
            $dueDate = null;
        }
        $requestId = $this->validateClientRequestId($data['client_request_id'] ?? null);
        if ($requireRequestId && !$requestId) throw new KarobarValidationException('A client request ID is required for credit purchases.');

        return [
            'amount' => $amount, 'date' => $date, 'creditor_id' => $creditorId,
            'category_id' => $categoryId, 'subcategory_id' => $subcategoryId,
            'description' => $description, 'due_date' => $dueDate,
            'client_request_id' => $requestId,
        ];
    }

    private function findCreditPurchaseByRequestId($requestId, $userId) {
        if (!$requestId) return false;
        $stmt = $this->conn->prepare(
            "SELECT t.*, kt.id AS karobar_id, kt.person_id, kt.amount AS payable_amount,
                    kt.transaction_date, kt.due_date AS payable_due_date,
                    kt.description AS payable_description, kt.expense_transaction_id
             FROM transactions t JOIN karobar_transactions kt ON kt.id=t.karobar_transaction_id
             WHERE t.user_id=:uid AND kt.user_id=:uid2 AND t.client_request_id=:request_id
             LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':uid2' => $userId, ':request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $row['karobar_transaction_id'] = $row['karobar_id'];
        return $row;
    }

    private function assertCreditPurchaseReplayMatches(array $existing, array $expected): void {
        $matches = $existing['type'] === 'expense' && $existing['payment_method'] === 'credit'
            && (int)$existing['person_id'] === $expected['creditor_id']
            && (int)$existing['category_id'] === $expected['category_id']
            && (int)($existing['subcategory_id'] ?? 0) === (int)($expected['subcategory_id'] ?? 0)
            && (int)round((float)$existing['amount'] * 100) === (int)round($expected['amount'] * 100)
            && (int)round((float)$existing['payable_amount'] * 100) === (int)round($expected['amount'] * 100)
            && $existing['date'] === $expected['date'] && $existing['transaction_date'] === $expected['date']
            && (string)$existing['description'] === $expected['description']
            && (string)$existing['payable_description'] === $expected['description']
            && (string)($existing['payable_due_date'] ?? '') === (string)($expected['due_date'] ?? '');
        if (!$matches) throw new KarobarConflictException('This request ID was already used for a different credit purchase.');
    }

    private function isCreditPurchaseOrigin($row): bool {
        return is_array($row) && $row['type'] === 'borrowed'
            && $row['payment_method'] === 'credit' && !empty($row['expense_transaction_id']);
    }

    private function creditPurchasePairMatches($payable, $transaction): bool {
        return $this->isCreditPurchaseOrigin($payable) && is_array($transaction)
            && $transaction['type'] === 'expense' && $transaction['payment_method'] === 'credit'
            && (int)$transaction['karobar_transaction_id'] === (int)$payable['id']
            && (int)$payable['expense_transaction_id'] === (int)$transaction['id']
            && (int)$transaction['user_id'] === (int)$payable['user_id'];
    }

    private function assertCreditPurchaseHistoryAllowsUpdate(array $payable, array $newData, $userId): void {
        $oldPersonId = (int)$payable['person_id'];
        $newPersonId = (int)$newData['creditor_id'];
        $repaid = $this->sumPersonType($oldPersonId, $userId, 'repaid', true);
        if ($oldPersonId !== $newPersonId && $repaid > 0) {
            throw new KarobarConflictException('The creditor cannot be changed after repayment history exists.');
        }
        $otherBorrowed = $this->sumPersonType($oldPersonId, $userId, 'borrowed', true, (int)$payable['id']);
        if ($oldPersonId === $newPersonId && $otherBorrowed + $newData['amount'] + 0.00001 < $repaid) {
            throw new KarobarConflictException('The credit amount cannot be lower than existing repayments.');
        }
        if ($repaid > 0) {
            $stmt = $this->conn->prepare(
                "SELECT MIN(transaction_date) FROM karobar_transactions
                 WHERE user_id=:uid AND person_id=:pid AND type='repaid'"
            );
            $stmt->execute([':uid' => $userId, ':pid' => $oldPersonId]);
            $firstRepayment = $stmt->fetchColumn();
            if ($firstRepayment && $newData['date'] > $firstRepayment) {
                throw new KarobarConflictException('The purchase date cannot be moved after an existing repayment date.');
            }
        }
    }

    private function sumPersonType($personId, $userId, string $type, bool $lockRows = false, $excludeId = null): float {
        $query = 'SELECT id, amount FROM karobar_transactions WHERE user_id=:uid AND person_id=:pid AND type=:type';
        $params = [':uid' => $userId, ':pid' => $personId, ':type' => $type];
        if ($excludeId !== null) {
            $query .= ' AND id<>:exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        if ($lockRows) $query .= ' FOR UPDATE';
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $total = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $total += (float)$row['amount'];
        return round($total, 2);
    }

    private function validateKarobarData($data, $userId, bool $requireActivePerson = true) {
        $validTypes = ['lent', 'borrowed', 'returned', 'repaid', 'adjustment'];
        if (!in_array($data['type'] ?? '', $validTypes, true)) {
            throw new KarobarValidationException('Invalid Karobar transaction type.');
        }
        $this->parseMoneyAmount($data['amount'] ?? null);
        $this->validateDate($data['transaction_date'] ?? null, 'transaction date');
        if (($data['type'] ?? '') !== 'adjustment' && empty($data['account_id'])) {
            throw new KarobarValidationException('Account is required for cash-moving Karobar transactions.');
        }
        if (!empty($data['account_id'])) {
            $this->assertAccountOwnership($data['account_id'], $userId);
        }
        $this->assertOwnedPersonAvailable($data['person_id']??0,$userId,$requireActivePerson);
    }

    private function assertAccountOwnership($accountId, $userId) {
        $stmt = $this->conn->prepare('SELECT id FROM accounts WHERE id = :id AND user_id = :uid AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $accountId, ':uid' => $userId]);
        if (!$stmt->fetchColumn()) $this->throwAccountAccessError($accountId);
    }

    private function parsePositiveId($value, $label): int {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new KarobarValidationException("A valid {$label} is required.");
        }
        return (int)$id;
    }

    private function parseMoneyAmount($value): float {
        if (!is_numeric($value)) {
            throw new KarobarValidationException('Invalid payment amount.');
        }
        $amount = (float)$value;
        if (!is_finite($amount) || $amount <= 0 || $amount > 999999999999.99) {
            throw new KarobarValidationException('Invalid payment amount. Amount must be greater than zero.');
        }
        $rounded = round($amount, 2);
        if (abs($amount - $rounded) > 0.0000001) {
            throw new KarobarValidationException('Invalid payment amount. Use no more than two decimal places.');
        }
        return $rounded;
    }

    private function validateDate($value, $label): string {
        $dateValue = (string)$value;
        $date = DateTime::createFromFormat('!Y-m-d', $dateValue);
        $year = $date ? (int)$date->format('Y') : 0;
        if (!$date || $date->format('Y-m-d') !== $dateValue || $year < 1000 || $year > 9999) {
            throw new KarobarValidationException("A valid {$label} is required.");
        }
        return $dateValue;
    }

    private function validateClientRequestId($value): ?string {
        if ($value === null || $value === '') return null;
        $id = trim((string)$value);
        if (strlen($id) < 8 || strlen($id) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            throw new KarobarValidationException('A valid client request ID is required.');
        }
        return $id;
    }

    private function lockOwnedPerson($personId, $userId, bool $requireActive = false): array {
        $personId = $this->parsePositiveId($personId, 'person');
        $stmt = $this->conn->prepare('SELECT * FROM people WHERE id = :id AND user_id = :uid LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $personId, ':uid' => $userId]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) $this->throwPersonAccessError($personId);
        if($requireActive&&($person['status']??'active')!=='active'){
            throw new KarobarValidationException('The selected person is archived. Restore them before recording new financial activity.');
        }
        return $person;
    }

    private function assertOwnedPersonAvailable($personId,$userId,bool$requireActive):array {
        $person=$this->personModel->findById($personId,$userId);
        if(!$person)$this->throwPersonAccessError($personId);
        if($requireActive&&($person['status']??'active')!=='active'){
            throw new KarobarValidationException('The selected person is archived. Restore them before recording new financial activity.');
        }
        return $person;
    }

    private function lockOwnedAccount($accountId, $userId): array {
        $accountId = $this->parsePositiveId($accountId, 'account');
        $stmt = $this->conn->prepare('SELECT * FROM accounts WHERE id = :id AND user_id = :uid LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $accountId, ':uid' => $userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) $this->throwAccountAccessError($accountId);
        if (empty($account['is_active'])) throw new KarobarValidationException('The selected account is inactive.');
        return $account;
    }

    private function throwPersonAccessError($personId): void {
        $stmt = $this->conn->prepare('SELECT 1 FROM people WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$personId]);
        if ($stmt->fetchColumn()) throw new KarobarAuthorizationException('You do not have access to this person.');
        throw new KarobarNotFoundException('Person not found.');
    }

    private function throwAccountAccessError($accountId): void {
        $stmt = $this->conn->prepare('SELECT 1 FROM accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$accountId]);
        if ($stmt->fetchColumn()) throw new KarobarAuthorizationException('You do not have access to this account.');
        throw new KarobarNotFoundException('Account not found.');
    }

    private function findOwnedTransactionOrFail($id, $userId): array {
        $transaction = $this->karobarModel->findById($id, $userId);
        if ($transaction) return $transaction;
        $stmt = $this->conn->prepare('SELECT 1 FROM karobar_transactions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$id]);
        if ($stmt->fetchColumn()) throw new KarobarAuthorizationException('You do not have access to this debt transaction.');
        throw new KarobarNotFoundException('Debt transaction not found.');
    }

    private function getPersonNetBalance($personId, $userId, $excludeId = null): float {
        $query = "SELECT COALESCE(SUM(CASE
                    WHEN type IN ('lent', 'repaid') THEN amount
                    WHEN type IN ('borrowed', 'returned') THEN -amount
                    ELSE amount END), 0)
                  FROM karobar_transactions
                  WHERE person_id = :person_id AND user_id = :user_id";
        $params = [':person_id' => $personId, ':user_id' => $userId];
        if ($excludeId !== null) {
            $query .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function getOutstandingForPayment($personId, $userId, $type, $excludeId = null, bool $lockRows = false): float {
        $sourceType = $type === 'repaid' ? 'borrowed' : 'lent';
        $paymentType = $type === 'repaid' ? 'repaid' : 'returned';

        // MySQL's default REPEATABLE READ can preserve an old snapshot even
        // after this transaction waited for the per-person lock. A locking
        // read is a current read, so a competing payment committed while we
        // waited is included in this calculation.
        if ($lockRows) {
            $query = 'SELECT id, type, amount FROM karobar_transactions
                      WHERE person_id = :person_id AND user_id = :user_id
                        AND type IN (:source_type, :payment_type)';
            $params = [
                ':source_type' => $sourceType,
                ':payment_type' => $paymentType,
                ':person_id' => $personId,
                ':user_id' => $userId,
            ];
            if ($excludeId !== null) {
                $query .= ' AND id <> :exclude_id';
                $params[':exclude_id'] = $excludeId;
            }
            $query .= ' FOR UPDATE';
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $outstanding = 0.0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $outstanding += $row['type'] === $sourceType
                    ? (float)$row['amount']
                    : -(float)$row['amount'];
            }
            return round($outstanding, 2);
        }

        $query = "SELECT
                    COALESCE(SUM(CASE WHEN type = :source_type THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN type = :payment_type THEN amount ELSE 0 END), 0)
                  FROM karobar_transactions
                  WHERE person_id = :person_id AND user_id = :user_id";
        $params = [
            ':source_type' => $sourceType,
            ':payment_type' => $paymentType,
            ':person_id' => $personId,
            ':user_id' => $userId,
        ];
        if ($excludeId !== null) {
            $query .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function assertPaymentWithinOutstanding($amount, $outstanding, $type): void {
        if ($outstanding <= 0) {
            $label = $type === 'repaid' ? 'debt' : 'receivable';
            throw new KarobarConflictException("This {$label} is already fully settled.");
        }
        if ((int)round($amount * 100) > (int)round($outstanding * 100)) {
            $label = $type === 'repaid' ? 'Repayment' : 'Receiving amount';
            throw new KarobarValidationException("{$label} exceeds the remaining outstanding balance.");
        }
    }

    private function assertPaymentDateIsValidForDebt($personId, $userId, $type, $paymentDate): void {
        $sourceType = $type === 'repaid' ? 'borrowed' : 'lent';
        $stmt = $this->conn->prepare(
            'SELECT MIN(transaction_date) FROM karobar_transactions
             WHERE person_id = :person_id AND user_id = :user_id AND type = :source_type'
        );
        $stmt->execute([':person_id' => $personId, ':user_id' => $userId, ':source_type' => $sourceType]);
        $firstDebtDate = $stmt->fetchColumn();
        if (!$firstDebtDate) throw new KarobarConflictException('No outstanding debt exists for this payment.');
        if ($paymentDate < $firstDebtDate) {
            throw new KarobarValidationException('Payment date cannot be before the original debt date.');
        }
    }

    private function assertIdempotentPaymentMatches($existing, $type, $personId, $accountId, $amount, $date): void {
        $matches = $existing['type'] === $type
            && (int)$existing['person_id'] === (int)$personId
            && (int)$existing['account_id'] === (int)$accountId
            && (int)round((float)$existing['amount'] * 100) === (int)round($amount * 100)
            && $existing['transaction_date'] === $date;
        if (!$matches) {
            throw new KarobarConflictException('This request ID was already used for a different payment.');
        }
    }

    private function assertIdempotentOriginMatches(array $existing, array $expected): void {
        $type = (string)($expected['type'] ?? '');
        if (!in_array($type, ['lent', 'borrowed', 'adjustment'], true)) {
            throw new KarobarConflictException('This request ID is already in use.');
        }
        $amount = $this->parseMoneyAmount($expected['amount'] ?? null);
        $date = $this->validateDate($expected['transaction_date'] ?? null, 'transaction date');
        $dueDate = ($expected['due_date'] ?? null) ?: null;
        if ($dueDate !== null) $dueDate = $this->validateDate($dueDate, 'due date');
        $matches = $existing['type'] === $type
            && (int)$existing['person_id'] === (int)($expected['person_id'] ?? 0)
            && (int)($existing['account_id'] ?? 0) === (int)($expected['account_id'] ?? 0)
            && (int)round((float)$existing['amount'] * 100) === (int)round($amount * 100)
            && (string)$existing['transaction_date'] === $date
            && (string)($existing['due_date'] ?? '') === (string)($dueDate ?? '')
            && (string)$existing['description'] === (string)($expected['description'] ?? '')
            && (string)($existing['payment_method'] ?? '') === (string)($expected['payment_method'] ?? '');
        if (!$matches) {
            throw new KarobarConflictException('This request ID was already used for a different Karobar transaction.');
        }
    }

    public function getPaymentState($transactionId, $userId): array {
        $transaction = $this->karobarModel->findById($transactionId, $userId);
        if (!$transaction || !in_array($transaction['type'], ['repaid', 'returned'], true)) {
            throw new KarobarNotFoundException('Payment transaction not found.');
        }
        $outstanding = $this->getOutstandingForPayment(
            $transaction['person_id'],
            $userId,
            $transaction['type']
        );
        $account = $this->accountModel->findById($transaction['account_id'], $userId);
        return [
            'remaining_outstanding' => max(0, $outstanding),
            'account_balance' => $account ? (float)$account['balance'] : null,
        ];
    }

    public function getDefaultAccountId($userId) {
        $query = "SELECT id FROM accounts WHERE user_id = :user_id AND is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }

    private function findOrCreateKarobarCategory($name, $type) {
        $query = "SELECT id FROM categories WHERE name = :name AND type = :type AND is_default = 0 AND user_id IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return $result;
        }

        $insertQuery = "INSERT INTO categories (name, type, icon, color, is_default, status) VALUES (:name, :type, 'handshake', '#8B5CF6', 0, 'active')";
        $stmt = $this->conn->prepare($insertQuery);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);

        if ($stmt->execute()) {
            return ['id' => $this->conn->lastInsertId()];
        }

        return null;
    }

    private function sendKarobarNotification($karobarData, $karobarId, $userId) {
        try {
            $type = $karobarData['type'];
            $amount = number_format(floatval($karobarData['amount']), 0);
            $person = $this->personModel->findById($karobarData['person_id'], $userId);
            $personName = $person ? $person['name'] : 'Unknown';

            switch ($type) {
                case 'lent':
                    $this->notifService->create($userId, 'karobar_paid',
                        'Money Lent',
                        "Rs {$amount} lent to {$personName}.",
                        'karobar', $karobarId);
                    if (floatval($karobarData['amount']) >= 10000) {
                        $this->notifService->create($userId, 'system',
                            'Large Lending Alert',
                            "You lent Rs {$amount} to {$personName}. This is a significant amount.",
                            'karobar', $karobarId);
                    }
                    break;
                case 'borrowed':
                    $this->notifService->create($userId, 'karobar_received',
                        'Money Borrowed',
                        "Rs {$amount} borrowed from {$personName}.",
                        'karobar', $karobarId);
                    if (floatval($karobarData['amount']) >= 10000) {
                        $this->notifService->create($userId, 'system',
                            'Large Borrowing Alert',
                            "You borrowed Rs {$amount} from {$personName}. This is a significant amount.",
                            'karobar', $karobarId);
                    }
                    break;
                case 'returned':
                    $this->notifService->create($userId, 'karobar_received',
                        'Money Received',
                        "Rs {$amount} received from {$personName}.",
                        'karobar', $karobarId);
                    break;
                case 'repaid':
                    $this->notifService->create($userId, 'karobar_paid',
                        'Repayment Completed',
                        "Rs {$amount} repaid to {$personName}.",
                        'karobar', $karobarId);
                    break;
            }
        } catch (\Throwable $e) {
            error_log("KarobarService::sendKarobarNotification error: " . $e->getMessage());
        }
    }

    public function getDashboardData($userId) {
        $dashboard = $this->karobarModel->getDashboard($userId);
        return array_merge($dashboard, $this->outstandingService->getSummary($userId));
    }

    public function getCreditReports($userId, $filters = []) {
        $reportType = $filters['report_type'] ?? 'all';

        $result = [
            'receivable_report' => [],
            'payable_report' => [],
            'shop_wise_report' => [],
            'person_wise_report' => [],
            'outstanding_report' => [],
            'monthly_credit_report' => []
        ];

        if ($reportType === 'all' || $reportType === 'receivable') {
            $result['receivable_report'] = $this->getReceivableReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'payable') {
            $result['payable_report'] = $this->getPayableReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'shop_wise') {
            $result['shop_wise_report'] = $this->getShopWiseReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'person_wise') {
            $result['person_wise_report'] = $this->getPersonWiseReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'outstanding') {
            $result['outstanding_report'] = $this->getOutstandingReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'monthly_credit') {
            $result['monthly_credit_report'] = $this->getMonthlyCreditReport($userId, $filters);
        }

        return $result;
    }

    private function getReceivableReport($userId, $filters) {
        return $this->outstandingService->getOrigins($userId, array_merge($filters, ['direction'=>'receivable']));
    }

    private function getPayableReport($userId, $filters) {
        return $this->outstandingService->getOrigins($userId, array_merge($filters, ['direction'=>'payable']));
    }

    private function getShopWiseReport($userId, $filters) {
        $query = "SELECT p.id, p.name, p.type, p.phone, p.address,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as total_lent,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as total_borrowed,
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as total_returned,
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as total_repaid,
                  COUNT(kt.id) as transaction_count
                  FROM people p
                  LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2 AND p.type IN ('shop', 'vendor', 'business')
                  GROUP BY p.id, p.name, p.type, p.phone, p.address
                  ORDER BY total_lent + total_borrowed DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPersonWiseReport($userId, $filters) {
        $query = "SELECT p.id, p.name, p.type, p.phone,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as total_lent,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as total_borrowed,
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as total_returned,
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as total_repaid,
                  COUNT(kt.id) as transaction_count,
                  MAX(kt.transaction_date) as last_transaction_date
                  FROM people p
                  LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2
                  GROUP BY p.id, p.name, p.type, p.phone
                  HAVING total_lent + total_borrowed > 0
                  ORDER BY total_lent + total_borrowed DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getOutstandingReport($userId, $filters) {
        $rows=$this->outstandingService->getPersonPositions($userId);
        $rows=array_values(array_filter($rows,fn($row)=>$row['receivable_outstanding']>0||$row['payable_outstanding']>0));
        foreach($rows as&$row){$row['receivable_balance']=$row['receivable_outstanding'];$row['payable_balance']=$row['payable_outstanding'];}
        usort($rows,fn($a,$b)=>max($b['receivable_outstanding'],$b['payable_outstanding'])<=>max($a['receivable_outstanding'],$a['payable_outstanding']));
        return $rows;
    }

    private function getMonthlyCreditReport($userId, $filters) {
        $startDate = $filters['start_date'] ?? date('Y-m-01');
        $endDate = $filters['end_date'] ?? date('Y-m-t');

        $query = "SELECT DATE_FORMAT(transaction_date, '%b %Y') as month_label,
                  DATE_FORMAT(transaction_date, '%Y-%m') as month_key,
                  MONTH(transaction_date) as month_num,
                  YEAR(transaction_date) as year_num,
                  SUM(CASE WHEN type = 'lent' THEN amount ELSE 0 END) as total_lent,
                  SUM(CASE WHEN type = 'borrowed' THEN amount ELSE 0 END) as total_borrowed,
                  SUM(CASE WHEN type = 'returned' THEN amount ELSE 0 END) as total_returned,
                  SUM(CASE WHEN type = 'repaid' THEN amount ELSE 0 END) as total_repaid,
                  COUNT(*) as transaction_count
                  FROM karobar_transactions
                  WHERE user_id = :user_id
                  AND transaction_date BETWEEN :start_date AND :end_date
                  GROUP BY YEAR(transaction_date), MONTH(transaction_date), month_label, month_key
                  ORDER BY year_num DESC, month_num DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAIAnalysis($userId) {
        $data = $this->getDashboardData($userId);
        $peopleBalances = $data['people_balances'] ?? [];

        $totalReceivable = (float)($data['total_receivable'] ?? 0);
        $totalPayable = (float)($data['total_payable'] ?? 0);
        $netPosition = $totalReceivable - $totalPayable;
        $activePeople = array_values(array_filter($peopleBalances, fn($person) => (int)($person['transaction_count'] ?? 0) > 0));
        $peopleYouOwe = count(array_filter($activePeople, fn($person) => (float)$person['payable_outstanding'] > 0));
        $peopleWhoOweYou = count(array_filter($activePeople, fn($person) => (float)$person['receivable_outstanding'] > 0));

        $mostBorrowedPerson = $this->getMostBorrowedPerson($userId);
        $mostOwed = $this->getMostOwedPerson($userId);
        $mostReceivable = $this->getMostReceivablePerson($userId);
        $creditDependency = $this->getCreditDependency($userId);
        $monthlyTrend = $this->getMonthlyDebtTrend($userId);
        $repaymentStats = $this->getRepaymentStats($userId);
        $avgRepaymentDays = $repaymentStats['average_days'];
        $highestCreditor = $this->getHighestCreditor($userId);
        $highestDebtor = $this->getHighestDebtor($userId);
        $payableOrigins = $this->outstandingService->getOrigins($userId, ['direction' => 'payable']);
        $unresolvedDebts = count($payableOrigins);
        $oldestOverdue = null;
        foreach ($payableOrigins as $origin) {
            if (!$origin['is_overdue']) continue;
            if ($oldestOverdue === null || $origin['due_date'] < $oldestOverdue['due_date']) $oldestOverdue = $origin;
        }

        $history = $this->getBorrowingHistoryTotals($userId);
        $hasKarobarActivity = (int)$history['transaction_count'] > 0;
        $trendComparison = $this->getDebtTrendComparison($monthlyTrend);
        $health = $this->calculateKarobarHealth([
            'has_activity' => $hasKarobarActivity,
            'total_receivable' => $totalReceivable,
            'total_payable' => $totalPayable,
            'overdue_payable' => (float)($data['overdue_payable'] ?? 0),
            'total_borrowed' => (float)$history['total_borrowed'],
            'completed_debts' => $repaymentStats['completed_count'],
            'borrowed_debts' => $repaymentStats['borrowed_count'],
            'avg_repayment_days' => $avgRepaymentDays,
            'credit_dependency' => $creditDependency,
            'unresolved_debts' => $unresolvedDebts,
            'trend' => $trendComparison
        ]);

        $insights = [];
        if ($hasKarobarActivity && $totalPayable == 0.0 && $totalReceivable == 0.0) {
            $insights[] = 'You currently have no outstanding Karobar obligations.';
        } elseif ($netPosition < 0) {
            $insights[] = 'Your current Karobar position is negative by ' . $this->formatRupees(abs($netPosition)) . ' because payables exceed receivables.';
        } elseif ($netPosition > 0) {
            $insights[] = 'Your current Karobar position is positive by ' . $this->formatRupees($netPosition) . ' because receivables exceed payables.';
        }
        if ($trendComparison['direction'] === 'decreasing' && $trendComparison['percent'] !== null) {
            $insights[] = 'Outstanding debt decreased by ' . $this->formatPercent($trendComparison['percent']) . ' compared with last month.';
        } elseif ($trendComparison['direction'] === 'increasing' && $trendComparison['percent'] !== null) {
            $insights[] = 'Outstanding debt increased by ' . $this->formatPercent($trendComparison['percent']) . ' compared with last month.';
        }
        if ($highestCreditor && $totalPayable > 0 && $peopleYouOwe > 1 && ((float)$highestCreditor['amount'] / $totalPayable) >= 0.6) {
            $insights[] = 'Most of your outstanding payable is concentrated with ' . $highestCreditor['name'] . '.';
        }
        if ($repaymentStats['trend'] === 'improving') {
            $insights[] = 'Repayment speed has improved compared with your previous completed debts.';
        }

        $recommendations = [];
        $overduePayable = (float)($data['overdue_payable'] ?? 0);
        if ($overduePayable > 0) {
            $oldestSuffix = $oldestOverdue ? ' Start with the obligation due ' . date('M j, Y', strtotime($oldestOverdue['due_date'])) . '.' : '';
            $recommendations[] = $this->formatRupees($overduePayable) . ' is overdue. Prioritize the oldest overdue obligation first.' . $oldestSuffix;
        }
        if ($totalPayable > $totalReceivable) {
            $recommendations[] = 'Your payables exceed your receivables by ' . $this->formatRupees($totalPayable - $totalReceivable) . '. Prioritize reducing outstanding obligations.';
        }
        if ($trendComparison['direction'] === 'increasing') {
            $change = $trendComparison['percent'] !== null
                ? $this->formatPercent($trendComparison['percent'])
                : $this->formatRupees($trendComparison['amount']);
            $recommendations[] = 'Outstanding debt increased by ' . $change . ' compared with last month. Consider limiting new borrowing until the balance starts declining.';
        }
        if ($avgRepaymentDays !== null && $avgRepaymentDays > 30) {
            $recommendations[] = 'Completed debts took an average of ' . $avgRepaymentDays . ' days to repay. Plan shorter, realistic repayment windows.';
        }
        if ($repaymentStats['trend'] === 'improving') {
            $recommendations[] = 'Your repayment pattern is improving. Continue maintaining the current repayment pace.';
        }
        if ($totalPayable == 0.0 && $hasKarobarActivity) {
            $recommendations[] = 'You currently have no outstanding payable balance. Avoid unnecessary borrowing and maintain this position.';
        }
        $recommendations = array_slice(array_values(array_unique($recommendations)), 0, 4);

        return [
            'health_score' => $health['score'],
            'health_status' => $health['status'],
            'health_factors' => $health['factors'],
            'total_receivable' => $totalReceivable,
            'total_payable' => $totalPayable,
            'net_position' => $netPosition,
            'net_karobar' => $netPosition,
            'most_borrowed_person' => $mostBorrowedPerson,
            'most_borrowed_shop' => $mostBorrowedPerson,
            'most_owed_person' => $mostOwed,
            'most_receivable_person' => $mostReceivable,
            'credit_dependency' => $creditDependency,
            'monthly_trend' => $monthlyTrend,
            'monthly_trend_has_activity' => count(array_filter($monthlyTrend, fn($month) => (float)$month['borrowed'] > 0 || (float)$month['repaid'] > 0)) > 0,
            'avg_repayment_days' => $avgRepaymentDays,
            'highest_creditor' => $highestCreditor,
            'highest_debtor' => $highestDebtor,
            'insights' => $insights,
            'recommendations' => $recommendations,
            'active_people_count' => count($activePeople),
            'people_count' => count($activePeople),
            'people_you_owe' => $peopleYouOwe,
            'people_who_owe_you' => $peopleWhoOweYou,
            'has_activity' => $hasKarobarActivity
        ];
    }

    private function getMostBorrowedPerson($userId) {
        $query = "SELECT p.name, SUM(kt.amount) as amount
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id AND kt.type = 'borrowed'
                  GROUP BY p.id, p.name
                  ORDER BY amount DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getMostOwedPerson($userId) {
        return $this->largestOutstandingPerson($userId, 'payable_outstanding');
    }

    private function getMostReceivablePerson($userId) {
        return $this->largestOutstandingPerson($userId, 'receivable_outstanding');
    }

    private function getCreditDependency($userId): ?float {
        $query = "SELECT
                  (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = :credit_user_id AND type = 'expense' AND payment_method = 'credit') as credit_expenses,
                  (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = :expense_user_id AND type = 'expense') as total_expenses
                  FROM karobar_transactions WHERE user_id = :karobar_user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':credit_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':expense_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':karobar_user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalExpenses = floatval($result['total_expenses'] ?? 0);
        $creditExpenses = floatval($result['credit_expenses'] ?? 0);

        return $totalExpenses > 0 ? round(min(100, ($creditExpenses / $totalExpenses) * 100), 1) : null;
    }

    private function getMonthlyDebtTrend($userId) {
        $endMonth = new DateTimeImmutable('first day of this month');
        $startMonth = $endMonth->modify('-11 months');
        $endDate = $endMonth->modify('last day of this month')->format('Y-m-d');
        $query = "SELECT person_id, type, amount, transaction_date
                  FROM karobar_transactions
                  WHERE user_id = :user_id AND type IN ('borrowed','repaid') AND transaction_date <= :end_date
                  ORDER BY transaction_date ASC, created_at ASC, id ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();
        $opening = [];
        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $personId = (int)$row['person_id'];
            $amount = (float)$row['amount'];
            $delta = $row['type'] === 'borrowed' ? $amount : -$amount;
            $monthKey = substr($row['transaction_date'], 0, 7);
            if ($row['transaction_date'] < $startMonth->format('Y-m-d')) {
                $opening[$personId] = max(0.0, ($opening[$personId] ?? 0.0) + $delta);
                continue;
            }
            $events[$monthKey][$personId] ??= ['borrowed' => 0.0, 'repaid' => 0.0];
            $events[$monthKey][$personId][$row['type']] += $amount;
        }
        $balances = $opening;
        $result = [];
        for ($cursor = $startMonth; $cursor <= $endMonth; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m');
            $borrowed = 0.0;
            $repaid = 0.0;
            foreach ($events[$key] ?? [] as $personId => $amounts) {
                $borrowed += $amounts['borrowed'];
                $repaid += $amounts['repaid'];
                $balances[$personId] = max(0.0, ($balances[$personId] ?? 0.0) + $amounts['borrowed'] - $amounts['repaid']);
            }
            $result[] = [
                'month' => $cursor->format('M Y'),
                'month_key' => $key,
                'borrowed' => round($borrowed, 2),
                'repaid' => round($repaid, 2),
                'outstanding_balance' => round(array_sum($balances), 2)
            ];
        }
        return $result;
    }

    private function getRepaymentStats($userId): array {
        $query = "SELECT id, person_id, type, amount, transaction_date
                  FROM karobar_transactions
                  WHERE user_id = :user_id AND type IN ('borrowed','repaid')
                  ORDER BY transaction_date ASC, created_at ASC, id ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $queues = [];
        $borrowedCount = 0;
        $completedDays = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $personId = (int)$row['person_id'];
            if ($row['type'] === 'borrowed') {
                $queues[$personId][] = ['remaining' => (float)$row['amount'], 'date' => $row['transaction_date']];
                $borrowedCount++;
                continue;
            }
            $payment = (float)$row['amount'];
            while ($payment > 0.00001 && !empty($queues[$personId])) {
                $origin =& $queues[$personId][0];
                $applied = min($payment, $origin['remaining']);
                $origin['remaining'] -= $applied;
                $payment -= $applied;
                if ($origin['remaining'] <= 0.00001) {
                    $start = new DateTimeImmutable($origin['date']);
                    $completedDays[] = (int)$start->diff(new DateTimeImmutable($row['transaction_date']))->format('%a');
                    array_shift($queues[$personId]);
                }
                unset($origin);
            }
        }
        $average = $completedDays ? (int)round(array_sum($completedDays) / count($completedDays)) : null;
        $trend = null;
        if (count($completedDays) >= 4) {
            $split = (int)floor(count($completedDays) / 2);
            $previous = array_slice($completedDays, 0, $split);
            $recent = array_slice($completedDays, $split);
            $previousAverage = array_sum($previous) / count($previous);
            $recentAverage = array_sum($recent) / count($recent);
            if ($recentAverage < $previousAverage - 0.5) $trend = 'improving';
            elseif ($recentAverage > $previousAverage + 0.5) $trend = 'worsening';
            else $trend = 'stable';
        }
        return ['average_days' => $average, 'completed_count' => count($completedDays), 'borrowed_count' => $borrowedCount, 'trend' => $trend];
    }

    private function getBorrowingHistoryTotals($userId): array {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(CASE WHEN type IN('lent','borrowed','returned','repaid') THEN 1 ELSE 0 END),0) transaction_count,
            COALESCE(SUM(CASE WHEN type='borrowed' THEN amount ELSE 0 END),0) total_borrowed,
            COALESCE(SUM(CASE WHEN type='repaid' THEN amount ELSE 0 END),0) total_repaid
            FROM karobar_transactions WHERE user_id=:user_id");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['transaction_count' => 0, 'total_borrowed' => 0, 'total_repaid' => 0];
    }

    private function getDebtTrendComparison(array $trend): array {
        if (count($trend) < 2) return ['direction' => 'unavailable', 'percent' => null, 'amount' => 0.0];
        $current = (float)$trend[count($trend) - 1]['outstanding_balance'];
        $previous = (float)$trend[count($trend) - 2]['outstanding_balance'];
        $amount = round(abs($current - $previous), 2);
        if ($amount < 0.01) return ['direction' => 'stable', 'percent' => 0.0, 'amount' => 0.0];
        return [
            'direction' => $current > $previous ? 'increasing' : 'decreasing',
            'percent' => $previous > 0 ? round(($amount / $previous) * 100, 1) : null,
            'amount' => $amount
        ];
    }

    private function calculateKarobarHealth(array $metrics): array {
        if (!$metrics['has_activity']) return ['score' => null, 'status' => 'Not enough data', 'factors' => []];
        $factors = [];
        $add = function(string $key, string $label, float $weight, float $ratio) use (&$factors): void {
            $factors[$key] = ['label' => $label, 'weight' => $weight, 'score' => round(max(0, min(1, $ratio)) * 100, 1)];
        };
        if ($metrics['total_borrowed'] > 0) {
            $add('outstanding_burden', 'Outstanding payable burden', 20, 1 - ($metrics['total_payable'] / $metrics['total_borrowed']));
            $add('repayment_consistency', 'Completed debt consistency', 15, $metrics['borrowed_debts'] > 0 ? $metrics['completed_debts'] / $metrics['borrowed_debts'] : 0);
            $add('unresolved_debts', 'Unresolved debts', 5, 1 / (1 + ($metrics['unresolved_debts'] / 3)));
        }
        $exposure = $metrics['total_receivable'] + $metrics['total_payable'];
        if ($exposure > 0) $add('net_balance', 'Receivable vs payable balance', 15, $metrics['total_receivable'] / $exposure);
        else $add('net_balance', 'Receivable vs payable balance', 15, 1);
        if ($metrics['total_payable'] > 0) $add('overdue_payable', 'On-time payable position', 20, 1 - ($metrics['overdue_payable'] / $metrics['total_payable']));
        else $add('overdue_payable', 'On-time payable position', 20, 1);
        if ($metrics['avg_repayment_days'] !== null) $add('repayment_speed', 'Average repayment speed', 10, 1 - (($metrics['avg_repayment_days'] - 7) / 53));
        if ($metrics['total_borrowed'] > 0 && $metrics['trend']['direction'] !== 'unavailable') {
            $trendRatio = $metrics['trend']['direction'] === 'decreasing' ? 1 : ($metrics['trend']['direction'] === 'stable' ? 0.75 : 0);
            $add('debt_trend', 'Monthly debt direction', 10, $trendRatio);
        }
        if ($metrics['credit_dependency'] !== null) $add('credit_dependency', 'Credit dependency', 5, 1 - ($metrics['credit_dependency'] / 100));
        $weight = array_sum(array_column($factors, 'weight'));
        $score = $weight > 0 ? (int)round(array_sum(array_map(fn($factor) => $factor['score'] * $factor['weight'], $factors)) / $weight) : null;
        $status = $score === null ? 'Not enough data' : ($score >= 80 ? 'Excellent' : ($score >= 65 ? 'Good' : ($score >= 45 ? 'Average' : ($score >= 25 ? 'Poor' : 'Critical'))));
        return ['score' => $score, 'status' => $status, 'factors' => $factors];
    }

    private function formatRupees(float $amount): string {
        return 'Rs ' . number_format($amount, abs($amount - round($amount)) < 0.005 ? 0 : 2);
    }

    private function formatPercent(float $percent): string {
        return number_format($percent, abs($percent - round($percent)) < 0.05 ? 0 : 1) . '%';
    }

    private function getHighestCreditor($userId) {
        return $this->largestOutstandingPerson($userId, 'payable_outstanding');
    }

    private function getHighestDebtor($userId) {
        return $this->largestOutstandingPerson($userId, 'receivable_outstanding');
    }

    private function largestOutstandingPerson($userId, string $field): ?array {
        $rows=$this->outstandingService->getPersonPositions($userId);
        usort($rows,fn($a,$b)=>(float)$b[$field]<=>(float)$a[$field]);
        if(!$rows||(float)$rows[0][$field]<=0)return null;
        return ['name'=>$rows[0]['name'],'amount'=>(float)$rows[0][$field]];
    }
}
