<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/BalanceService.php';

class TransactionConflictException extends RuntimeException {}
class TransferAuthorizationException extends RuntimeException {}
class GoalContributionAuthorizationException extends RuntimeException {}

/**
 * AccountingService – Centralized double-entry accounting engine.
 *
 * Every balance mutation MUST go through this service.
 * All balance CALCULATIONS are delegated to BalanceService.
 *
 * Transaction types:
 *   income   – Money enters a user-owned account. Increases net worth.
 *   expense  – Money leaves a user-owned account permanently. Decreases net worth.
 *   transfer – Money moves between two user-owned accounts. Net worth unchanged.
 *   goal_contribution – Money moves from an account asset to a goal asset.
 */
class AccountingService {
    private $conn;
    private $accountModel;
    private $transactionModel;
    private $goalModel;
    private $balanceService;
    private $transferFailureInjector = null;
    private $goalContributionFailureInjector = null;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->accountModel = new Account();
        $this->transactionModel = new Transaction();
        $this->goalModel = new Goal();
        $this->balanceService = new BalanceService();
    }

    /* ------------------------------------------------------------------
     *  CREATE
     * ----------------------------------------------------------------*/

    public function createTransaction($userId, $data) {
        if (($data['dynamic_subcategory_type'] ?? null) === 'goal' && !empty($data['dynamic_subcategory_id'])) {
            return $this->createGoalContribution($data['dynamic_subcategory_id'], $userId, [
                'amount' => $data['amount'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'date' => $data['date'] ?? null,
                'description' => $data['description'] ?? '',
                'client_request_id' => $data['client_request_id'] ?? null,
            ]);
        }
        if (($data['type'] ?? null) === 'transfer') {
            return $this->createTransfer($userId, $data);
        }
        $this->conn->beginTransaction();

        try {
            $type     = $data['type'];
            $amount   = floatval($data['amount']);
            $feeAmount = floatval($data['fee_amount'] ?? 0);

            // For transfers with fee, validate combined balance
            if ($type === 'transfer' && $feeAmount > 0) {
                $data['_check_amount'] = $amount + $feeAmount;
            }
            $this->validateTransaction($userId, $data, $type);

            $transactionData = [
                'user_id'              => $userId,
                'account_id'           => null,
                'category_id'          => $data['category_id'] ?? null,
                'subcategory_id'       => $data['subcategory_id'] ?? null,
                'amount'               => $amount,
                'type'                 => $type,
                'payment_method'       => $data['payment_method'] ?? null,
                'karobar_transaction_id' => $data['karobar_transaction_id'] ?? null,
                'client_request_id'     => $data['client_request_id'] ?? null,
                'date'                 => $data['date'],
                'description'          => $data['description'] ?? '',
                'from_account_id'      => $data['from_account_id'] ?? null,
                'to_account_id'        => $data['to_account_id'] ?? null,
            ];

            switch ($type) {
                case 'income':
                    $accountId = $data['to_account_id'] ?? $data['account_id'];
                    $transactionData['account_id'] = $accountId;
                    break;

                case 'expense':
                    $accountId = $data['from_account_id'] ?? $data['account_id'];
                    $transactionData['account_id'] = $accountId;
                    break;

                case 'transfer':
                    $accountId = $data['from_account_id'];
                    $transactionData['account_id'] = $accountId;
                    break;
            }

            $transactionId = $this->transactionModel->create($transactionData);

            if (!$transactionId) {
                $this->conn->rollBack();
                return false;
            }

            // If transfer has a fee, create a linked expense transaction
            $feeTransactionId = null;
            if ($type === 'transfer' && $feeAmount > 0) {
                $feeDesc = ($data['description'] ?? 'Transfer') . ' Fee';
                $feeTransactionId = $this->transactionModel->create([
                    'user_id'              => $userId,
                    'account_id'           => $data['from_account_id'],
                    'category_id'          => $data['fee_category_id'] ?? null,
                    'subcategory_id'       => null,
                    'amount'               => $feeAmount,
                    'type'                 => 'expense',
                    'payment_method'       => 'transfer',
                    'karobar_transaction_id' => null,
                    'date'                 => $data['date'],
                    'description'          => $feeDesc,
                    'from_account_id'      => null,
                    'to_account_id'        => null,
                ]);

                if (!$feeTransactionId) {
                    $this->conn->rollBack();
                    return false;
                }
            }

            // Recalculate balances for ALL affected accounts using BalanceService
            $affectedAccounts = $this->getAffectedAccounts($data);
            if ($type === 'transfer' && $feeAmount > 0 && !empty($data['from_account_id'])) {
                $affectedAccounts[] = $data['from_account_id'];
            }
            foreach (array_unique($affectedAccounts) as $aid) {
                $this->recalculateAndUpdateBalance($aid, $userId);
            }

            $this->conn->commit();
            return $feeTransactionId ? $feeTransactionId : $transactionId;

        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     *  UPDATE
     * ----------------------------------------------------------------*/

    public function updateTransaction($id, $userId, $newData, $baseVersion = null) {
        $candidate = $this->transactionModel->findById($id, $userId);
        if ($candidate && ($candidate['type'] === 'transfer' || !empty($candidate['transfer_parent_id']))) {
            return $this->updateTransfer($id, $userId, $newData, $baseVersion);
        }
        if ($candidate && $candidate['type'] === 'goal_contribution') {
            return $this->updateGoalContribution($id, $userId, $newData, $baseVersion);
        }
        if ($candidate && ($newData['type'] ?? null) === 'transfer') {
            throw new InvalidArgumentException('Create a new transfer instead of converting an existing transaction');
        }
        $this->conn->beginTransaction();

        try {
            $existing = $this->transactionModel->findById($id, $userId);
            if (!$existing) {
                throw new \Exception("Transaction not found");
            }
            if ($baseVersion !== null && (int)$existing['version'] !== (int)$baseVersion) {
                throw new TransactionConflictException('This transaction was changed elsewhere.');
            }

            $merged = [
                'account_id'      => $newData['account_id']      ?? $existing['account_id'],
                'category_id'     => $newData['category_id']     ?? $existing['category_id'],
                'subcategory_id'  => $newData['subcategory_id']  ?? $existing['subcategory_id'],
                'amount'          => floatval($newData['amount']  ?? $existing['amount']),
                'type'            => $newData['type']             ?? $existing['type'],
                'date'            => $newData['date']             ?? $existing['date'],
                'description'     => $newData['description']      ?? $existing['description'],
                'from_account_id' => $newData['from_account_id'] ?? $existing['from_account_id'],
                'to_account_id'   => $newData['to_account_id']   ?? $existing['to_account_id'],
                'payment_method'  => $newData['payment_method']  ?? $existing['payment_method'],
            ];

            $validateData = array_merge($merged, ['user_id' => $userId]);
            $this->validateTransaction($userId, $validateData, $merged['type']);

            $updated = $this->transactionModel->update($id, $userId, $merged, $baseVersion);
            if (!$updated) throw new TransactionConflictException('This transaction was changed elsewhere.');

            // Recalculate balances for ALL affected accounts (old + new)
            $affectedAccounts = [];
            foreach (['from_account_id', 'to_account_id', 'account_id'] as $f) {
                if (!empty($existing[$f])) $affectedAccounts[] = $existing[$f];
                if (!empty($merged[$f]) && !in_array($merged[$f], $affectedAccounts)) $affectedAccounts[] = $merged[$f];
            }
            foreach (array_unique($affectedAccounts) as $aid) {
                $this->recalculateAndUpdateBalance($aid, $userId);
            }

            $this->conn->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     *  DELETE
     * ----------------------------------------------------------------*/

    public function deleteTransaction($id, $userId, $baseVersion = null) {
        $candidate = $this->transactionModel->findById($id, $userId);
        if ($candidate && ($candidate['type'] === 'transfer' || !empty($candidate['transfer_parent_id']))) {
            return $this->deleteTransfer($id, $userId, $baseVersion);
        }
        if ($candidate && $candidate['type'] === 'goal_contribution') {
            return $this->deleteGoalContribution($id, $userId, $baseVersion);
        }
        $this->conn->beginTransaction();

        try {
            $transaction = $this->transactionModel->findById($id, $userId);
            if (!$transaction) {
                throw new \Exception("Transaction not found");
            }
            if ($baseVersion !== null && (int)$transaction['version'] !== (int)$baseVersion) {
                throw new TransactionConflictException('This transaction was changed before deletion.');
            }

            $affectedAccounts = [];
            foreach (['from_account_id', 'to_account_id', 'account_id'] as $f) {
                if (!empty($transaction[$f])) $affectedAccounts[] = $transaction[$f];
            }

            $deleted = $this->transactionModel->delete($id, $userId, $baseVersion);
            if (!$deleted) throw new TransactionConflictException('This transaction was changed before deletion.');

            foreach (array_unique($affectedAccounts) as $aid) {
                $this->recalculateAndUpdateBalance($aid, $userId);
            }

            $this->conn->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    public function bulkDeleteTransactions(array $ids, $userId): int {
        $this->conn->beginTransaction();
        try {
            $transactions = [];
            $affectedAccounts = [];
            $affectedGoals = [];
            $goalAccounts = [];

            foreach ($ids as $id) {
                $transaction = $this->transactionModel->findByIdForUpdate($id, $userId);
                if (!$transaction) {
                    throw new \Exception("Transaction {$id} was not found or access was denied");
                }
                if (!empty($transaction['transfer_parent_id'])) {
                    $transaction = $this->transactionModel->findByIdForUpdate($transaction['transfer_parent_id'], $userId);
                    if (!$transaction || $transaction['type'] !== 'transfer') {
                        throw new RuntimeException("Transfer group for transaction {$id} is invalid");
                    }
                }
                if (!empty($transaction['karobar_transaction_id'])) {
                    throw new \Exception('Linked credit transactions must be deleted from Karobar');
                }
                $transactions[(int)$transaction['id']] = $transaction;
                if ($transaction['type'] === 'goal_contribution' && !empty($transaction['goal_id'])) {
                    $affectedGoals[] = (int)$transaction['goal_id'];
                    $goalAccounts[] = (int)$transaction['account_id'];
                }
                foreach (['from_account_id', 'to_account_id', 'account_id'] as $field) {
                    if (!empty($transaction[$field])) $affectedAccounts[] = $transaction[$field];
                }
            }

            foreach (array_unique($affectedGoals) as $goalId) {
                if (!$this->goalModel->findByIdForUpdate($goalId, $userId)) {
                    throw new GoalContributionAuthorizationException('Goal not found or access denied');
                }
            }
            if ($goalAccounts) $this->lockGoalAccounts($goalAccounts, $userId);

            foreach ($transactions as $transaction) {
                if ($transaction['type'] === 'transfer') {
                    $fee = $this->transactionModel->findTransferFee($transaction['id'], $userId, true);
                    if ($fee && !$this->transactionModel->delete($fee['id'], $userId)) {
                        throw new RuntimeException("Failed to delete fee for transfer {$transaction['id']}");
                    }
                }
                if (!$this->transactionModel->delete($transaction['id'], $userId)) {
                    throw new \Exception("Failed to delete transaction {$transaction['id']}");
                }
            }
            foreach (array_unique($affectedGoals) as $goalId) {
                if (!$this->goalModel->recalculateCurrentAmount($goalId, $userId)) {
                    throw new RuntimeException("Failed to update goal {$goalId} after bulk deletion");
                }
            }
            foreach (array_unique($affectedAccounts) as $accountId) {
                $this->recalculateAndUpdateBalance($accountId, $userId);
            }

            $this->conn->commit();
            return count($transactions);
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     *  GOAL ALLOCATION
     * ----------------------------------------------------------------*/

    public function contributeToGoal($goalId, $userId, $amount, $accountId, $date = null, $description = '', $clientRequestId = null) {
        return $this->createGoalContribution($goalId, $userId, [
            'amount' => $amount,
            'account_id' => $accountId,
            'date' => $date ?: date('Y-m-d'),
            'description' => $description,
            'client_request_id' => $clientRequestId,
        ]);
    }

    public function setGoalContributionFailureInjector(?callable $injector): void {
        $this->goalContributionFailureInjector = $injector;
    }

    public function getGoalContributionResource($contributionId, $userId): array {
        $contribution = $this->transactionModel->findById($contributionId, $userId);
        if (!$contribution || $contribution['type'] !== 'goal_contribution' || empty($contribution['goal_id'])) {
            throw new RuntimeException('Goal contribution not found');
        }
        $goal = $this->goalModel->findById($contribution['goal_id'], $userId);
        if (!$goal) throw new RuntimeException('Goal contribution is not linked to an accessible goal');
        return [
            'contribution_id' => (int)$contribution['id'],
            'contribution' => $contribution,
            'goal' => $goal,
        ];
    }

    public function createGoalContribution($goalId, $userId, array $data): array {
        $normalized = $this->normalizeGoalContribution($data);
        $requestId = $data['client_request_id'] ?? null;
        if ($requestId) {
            $existing = $this->transactionModel->findByClientRequestId($requestId, $userId);
            if ($existing) return $this->replayGoalContribution($existing, (int)$goalId, $normalized, $userId);
        }

        $this->conn->beginTransaction();
        try {
            $goal = $this->goalModel->findByIdForUpdate($goalId, $userId);
            if (!$goal) throw new GoalContributionAuthorizationException('Goal not found or access denied');
            $account = $this->lockGoalAccount($normalized['account_id'], $userId);
            if ((float)$account['balance'] < $normalized['amount']) {
                throw new InvalidArgumentException('Insufficient balance in the selected account');
            }
            $contributionId = $this->transactionModel->create([
                'user_id' => $userId,
                'account_id' => $normalized['account_id'],
                'from_account_id' => $normalized['account_id'],
                'to_account_id' => null,
                'category_id' => null,
                'subcategory_id' => null,
                'amount' => $normalized['amount'],
                'type' => 'goal_contribution',
                'payment_method' => 'goal',
                'karobar_transaction_id' => null,
                'client_request_id' => $requestId,
                'transfer_parent_id' => null,
                'goal_id' => (int)$goalId,
                'date' => $normalized['date'],
                'description' => $normalized['description'] ?: "Contribution to goal: {$goal['name']}",
            ]);
            if (!$contributionId) throw new RuntimeException('Goal contribution creation failed');
            $this->invokeGoalContributionFailure('after_contribution_record');
            if (!$this->goalModel->recalculateCurrentAmount($goalId, $userId)) {
                throw new RuntimeException('Goal progress update failed');
            }
            $this->invokeGoalContributionFailure('after_goal_progress');
            $this->recalculateAndUpdateBalance($normalized['account_id'], $userId);
            $this->conn->commit();
            return $this->getGoalContributionResource($contributionId, $userId);
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            if ($e instanceof PDOException && $requestId && (string)$e->getCode() === '23000') {
                $existing = $this->transactionModel->findByClientRequestId($requestId, $userId);
                if ($existing) return $this->replayGoalContribution($existing, (int)$goalId, $normalized, $userId);
            }
            throw $e;
        }
    }

    public function updateGoalContribution($id, $userId, array $data, $baseVersion = null): array {
        $this->conn->beginTransaction();
        try {
            $existing = $this->transactionModel->findByIdForUpdate($id, $userId);
            if (!$existing || $existing['type'] !== 'goal_contribution' || empty($existing['goal_id'])) {
                throw new GoalContributionAuthorizationException('Goal contribution not found or access denied');
            }
            if ($baseVersion !== null && (int)$existing['version'] !== (int)$baseVersion) {
                throw new TransactionConflictException('This contribution was changed elsewhere.');
            }
            $goal = $this->goalModel->findByIdForUpdate($existing['goal_id'], $userId);
            if (!$goal) throw new GoalContributionAuthorizationException('Goal not found or access denied');
            $normalized = $this->normalizeGoalContribution([
                'amount' => $data['amount'] ?? $existing['amount'],
                'account_id' => $data['account_id'] ?? $existing['account_id'],
                'date' => $data['date'] ?? $existing['date'],
                'description' => $data['description'] ?? $existing['description'],
            ]);
            $accounts = $this->lockGoalAccounts([$existing['account_id'], $normalized['account_id']], $userId);
            $available = (float)$accounts[$normalized['account_id']]['balance'];
            if ((int)$normalized['account_id'] === (int)$existing['account_id']) $available += (float)$existing['amount'];
            if ($available < $normalized['amount']) throw new InvalidArgumentException('Insufficient balance in the selected account');

            $updated = $this->transactionModel->update($id, $userId, [
                'account_id' => $normalized['account_id'],
                'from_account_id' => $normalized['account_id'],
                'to_account_id' => null,
                'category_id' => null,
                'subcategory_id' => null,
                'amount' => $normalized['amount'],
                'type' => 'goal_contribution',
                'payment_method' => 'goal',
                'date' => $normalized['date'],
                'description' => $normalized['description'],
            ], $baseVersion);
            if (!$updated) throw new TransactionConflictException('This contribution was changed elsewhere.');
            if (!$this->goalModel->recalculateCurrentAmount($existing['goal_id'], $userId)) {
                throw new RuntimeException('Goal progress update failed');
            }
            foreach (array_unique([(int)$existing['account_id'], $normalized['account_id']]) as $accountId) {
                $this->recalculateAndUpdateBalance($accountId, $userId);
            }
            $this->conn->commit();
            return $this->getGoalContributionResource($id, $userId);
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    public function deleteGoalContribution($id, $userId, $baseVersion = null): bool {
        $this->conn->beginTransaction();
        try {
            $existing = $this->transactionModel->findByIdForUpdate($id, $userId);
            if (!$existing || $existing['type'] !== 'goal_contribution' || empty($existing['goal_id'])) {
                throw new GoalContributionAuthorizationException('Goal contribution not found or access denied');
            }
            if ($baseVersion !== null && (int)$existing['version'] !== (int)$baseVersion) {
                throw new TransactionConflictException('This contribution was changed before deletion.');
            }
            if (!$this->goalModel->findByIdForUpdate($existing['goal_id'], $userId)) {
                throw new GoalContributionAuthorizationException('Goal not found or access denied');
            }
            $this->lockGoalAccount($existing['account_id'], $userId);
            if (!$this->transactionModel->delete($id, $userId, $baseVersion)) {
                throw new TransactionConflictException('This contribution was changed before deletion.');
            }
            if (!$this->goalModel->recalculateCurrentAmount($existing['goal_id'], $userId)) {
                throw new RuntimeException('Goal progress update failed');
            }
            $this->recalculateAndUpdateBalance($existing['account_id'], $userId);
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    private function normalizeGoalContribution(array $data): array {
        $amount = $this->strictMoney($data['amount'] ?? null, false, 'Contribution amount');
        $accountId = filter_var($data['account_id'] ?? null, FILTER_VALIDATE_INT);
        if ($accountId === false || $accountId < 1) throw new InvalidArgumentException('A valid contribution account is required');
        $dateValue = (string)($data['date'] ?? '');
        $date = DateTime::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue || (int)$date->format('Y') < 1000 || (int)$date->format('Y') > 9999) {
            throw new InvalidArgumentException('A valid contribution date is required');
        }
        $description = trim((string)($data['description'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
        if ($length > 255) throw new InvalidArgumentException('Contribution note must be 255 characters or fewer');
        return ['amount' => $amount, 'account_id' => (int)$accountId, 'date' => $dateValue, 'description' => $description];
    }

    private function lockGoalAccount($accountId, $userId): array {
        $accounts = $this->lockGoalAccounts([$accountId], $userId);
        return $accounts[(int)$accountId];
    }

    private function lockGoalAccounts(array $accountIds, $userId): array {
        $ids = array_values(array_unique(array_map('intval', $accountIds)));
        sort($ids, SORT_NUMERIC);
        $accounts = [];
        foreach ($ids as $id) {
            $stmt = $this->conn->prepare('SELECT id, user_id, balance, is_active FROM accounts WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account || (int)$account['user_id'] !== (int)$userId) {
                throw new GoalContributionAuthorizationException('Account not found or access denied');
            }
            if (!(int)$account['is_active']) throw new InvalidArgumentException('Account is not active');
            $accounts[$id] = $account;
        }
        return $accounts;
    }

    private function replayGoalContribution(array $existing, int $goalId, array $expected, $userId): array {
        $matches = $existing['type'] === 'goal_contribution'
            && (int)$existing['goal_id'] === $goalId
            && (int)$existing['account_id'] === $expected['account_id']
            && number_format((float)$existing['amount'], 2, '.', '') === number_format($expected['amount'], 2, '.', '')
            && (string)$existing['date'] === $expected['date']
            && (string)$existing['description'] === ($expected['description'] ?: (string)$existing['description']);
        if (!$matches) throw new TransactionConflictException('Client request ID payload does not match the original contribution');
        return $this->getGoalContributionResource($existing['id'], $userId);
    }

    private function invokeGoalContributionFailure(string $point): void {
        if ($this->goalContributionFailureInjector) call_user_func($this->goalContributionFailureInjector, $point);
    }

    /* ------------------------------------------------------------------
     *  DEPOSIT TO SAVINGS
     * ----------------------------------------------------------------*/

    public function depositToSavings($userId, $fromAccountId, $toAccountId, $amount, $description = '') {
        return $this->createTransaction($userId, [
            'type'            => 'transfer',
            'amount'          => $amount,
            'from_account_id' => $fromAccountId,
            'to_account_id'   => $toAccountId,
            'category_id'     => null,
            'date'            => date('Y-m-d'),
            'description'     => $description ?: 'Deposit to savings account',
        ]);
    }

    public function setTransferFailureInjector(?callable $injector): void {
        $this->transferFailureInjector = $injector;
    }

    public function getTransferResource($transferId, $userId): array {
        $transfer = $this->transactionModel->findById($transferId, $userId);
        if (!$transfer || $transfer['type'] !== 'transfer') {
            throw new RuntimeException('Transfer not found');
        }
        $fee = $this->transactionModel->findTransferFee($transfer['id'], $userId);
        $source = $transfer;
        $source['direction'] = 'out';
        $source['account_id'] = $transfer['from_account_id'];
        $destination = $transfer;
        $destination['direction'] = 'in';
        $destination['account_id'] = $transfer['to_account_id'];
        return [
            'transfer_id' => (int)$transfer['id'],
            'transfer' => $transfer,
            'source_transaction' => $source,
            'destination_transaction' => $destination,
            'fee_transaction' => $fee ?: null,
        ];
    }

    private function createTransfer($userId, array $data): array {
        $normalized = $this->normalizeTransferData($data);
        $requestId = $data['client_request_id'] ?? null;
        if ($requestId) {
            $existing = $this->transactionModel->findByClientRequestId($requestId, $userId);
            if ($existing) return $this->replayTransfer($existing, $normalized, $userId);
        }

        $this->conn->beginTransaction();
        try {
            $accounts = $this->lockOwnedAccounts(
                [$normalized['from_account_id'], $normalized['to_account_id']],
                $userId
            );
            $this->validateTransferCategory($normalized, $userId);
            if ((float)$accounts[$normalized['from_account_id']]['balance'] < $normalized['amount'] + $normalized['fee_amount']) {
                throw new InvalidArgumentException('Insufficient balance in source account');
            }

            $transferId = $this->transactionModel->create([
                'user_id' => $userId,
                'account_id' => $normalized['from_account_id'],
                'from_account_id' => $normalized['from_account_id'],
                'to_account_id' => $normalized['to_account_id'],
                'category_id' => null,
                'subcategory_id' => null,
                'amount' => $normalized['amount'],
                'type' => 'transfer',
                'payment_method' => 'transfer',
                'karobar_transaction_id' => null,
                'client_request_id' => $requestId,
                'transfer_parent_id' => null,
                'date' => $normalized['date'],
                'description' => $normalized['description'],
            ]);
            if (!$transferId) throw new RuntimeException('Transfer creation failed');
            $this->invokeTransferFailure('after_source_record');
            // One canonical row records both account sides; this hook verifies that
            // failures after establishing the destination effect still roll back.
            $this->invokeTransferFailure('after_destination_record');

            $this->syncTransferFee($transferId, $userId, null, $normalized);
            $this->recalculateAndUpdateBalance($normalized['from_account_id'], $userId);
            $this->recalculateAndUpdateBalance($normalized['to_account_id'], $userId);
            $this->conn->commit();
            return $this->getTransferResource($transferId, $userId);
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            if ($e instanceof PDOException && $requestId && (string)$e->getCode() === '23000') {
                $existing = $this->transactionModel->findByClientRequestId($requestId, $userId);
                if ($existing) return $this->replayTransfer($existing, $normalized, $userId);
            }
            throw $e;
        }
    }

    private function updateTransfer($id, $userId, array $newData, $baseVersion): array {
        $this->conn->beginTransaction();
        try {
            $selected = $this->transactionModel->findByIdForUpdate($id, $userId);
            if (!$selected) throw new RuntimeException('Transfer not found');
            $transferId = !empty($selected['transfer_parent_id']) ? $selected['transfer_parent_id'] : $selected['id'];
            $transfer = $transferId == $selected['id'] ? $selected : $this->transactionModel->findByIdForUpdate($transferId, $userId);
            if (!$transfer || $transfer['type'] !== 'transfer') throw new RuntimeException('Transfer not found');
            if ($baseVersion !== null && (int)$transfer['version'] !== (int)$baseVersion) {
                throw new TransactionConflictException('This transfer was changed elsewhere.');
            }
            if (isset($newData['type']) && $newData['type'] !== 'transfer') {
                throw new InvalidArgumentException('A transfer cannot be converted to another transaction type');
            }
            $oldFee = $this->transactionModel->findTransferFee($transferId, $userId, true);
            $normalized = $this->normalizeTransferData([
                'amount' => $newData['amount'] ?? $transfer['amount'],
                'from_account_id' => $newData['from_account_id'] ?? $transfer['from_account_id'],
                'to_account_id' => $newData['to_account_id'] ?? $transfer['to_account_id'],
                'date' => $newData['date'] ?? $transfer['date'],
                'description' => $newData['description'] ?? $transfer['description'],
                'fee_amount' => array_key_exists('fee_amount', $newData) ? $newData['fee_amount'] : ($oldFee['amount'] ?? 0),
                'fee_category_id' => array_key_exists('fee_category_id', $newData) ? $newData['fee_category_id'] : ($oldFee['category_id'] ?? null),
            ]);
            $accounts = $this->lockOwnedAccounts(array_filter([
                $transfer['from_account_id'], $transfer['to_account_id'],
                $normalized['from_account_id'], $normalized['to_account_id'],
            ]), $userId);
            $this->validateTransferCategory($normalized, $userId);

            $available = (float)$accounts[$normalized['from_account_id']]['balance'];
            if ((int)$normalized['from_account_id'] === (int)$transfer['from_account_id']) {
                $available += (float)$transfer['amount'] + (float)($oldFee['amount'] ?? 0);
            }
            if ((int)$normalized['from_account_id'] === (int)$transfer['to_account_id']) {
                $available -= (float)$transfer['amount'];
            }
            if ($available < $normalized['amount'] + $normalized['fee_amount']) {
                throw new InvalidArgumentException('Insufficient balance in source account');
            }

            $updated = $this->transactionModel->update($transferId, $userId, [
                'account_id' => $normalized['from_account_id'],
                'from_account_id' => $normalized['from_account_id'],
                'to_account_id' => $normalized['to_account_id'],
                'category_id' => null,
                'subcategory_id' => null,
                'amount' => $normalized['amount'],
                'type' => 'transfer',
                'payment_method' => 'transfer',
                'date' => $normalized['date'],
                'description' => $normalized['description'],
            ], $baseVersion);
            if (!$updated) throw new TransactionConflictException('This transfer was changed elsewhere.');
            $this->syncTransferFee($transferId, $userId, $oldFee, $normalized);
            foreach (array_unique(array_map('intval', [
                $transfer['from_account_id'], $transfer['to_account_id'],
                $normalized['from_account_id'], $normalized['to_account_id'],
            ])) as $accountId) {
                $this->recalculateAndUpdateBalance($accountId, $userId);
            }
            $this->conn->commit();
            return $this->getTransferResource($transferId, $userId);
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    private function deleteTransfer($id, $userId, $baseVersion): bool {
        $this->conn->beginTransaction();
        try {
            $selected = $this->transactionModel->findByIdForUpdate($id, $userId);
            if (!$selected) throw new RuntimeException('Transfer not found');
            $transferId = !empty($selected['transfer_parent_id']) ? $selected['transfer_parent_id'] : $selected['id'];
            $transfer = $transferId == $selected['id'] ? $selected : $this->transactionModel->findByIdForUpdate($transferId, $userId);
            if (!$transfer || $transfer['type'] !== 'transfer') throw new RuntimeException('Transfer not found');
            if ($baseVersion !== null && (int)$transfer['version'] !== (int)$baseVersion) {
                throw new TransactionConflictException('This transfer was changed before deletion.');
            }
            $fee = $this->transactionModel->findTransferFee($transferId, $userId, true);
            $this->lockOwnedAccounts([$transfer['from_account_id'], $transfer['to_account_id']], $userId);
            if ($fee && !$this->transactionModel->delete($fee['id'], $userId)) {
                throw new RuntimeException('Transfer fee deletion failed');
            }
            if (!$this->transactionModel->delete($transferId, $userId, $baseVersion)) {
                throw new TransactionConflictException('This transfer was changed before deletion.');
            }
            $this->recalculateAndUpdateBalance($transfer['from_account_id'], $userId);
            $this->recalculateAndUpdateBalance($transfer['to_account_id'], $userId);
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    private function normalizeTransferData(array $data): array {
        $amount = $this->strictMoney($data['amount'] ?? null, false, 'Transfer amount');
        $fee = $this->strictMoney($data['fee_amount'] ?? 0, true, 'Transfer fee');
        $from = filter_var($data['from_account_id'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($data['to_account_id'] ?? null, FILTER_VALIDATE_INT);
        if ($from === false || $from < 1) throw new InvalidArgumentException('Source account is required for transfer');
        if ($to === false || $to < 1) throw new InvalidArgumentException('Destination account is required for transfer');
        if ((int)$from === (int)$to) throw new InvalidArgumentException('Cannot transfer to the same account');
        $dateValue = (string)($data['date'] ?? '');
        $date = DateTime::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue || (int)$date->format('Y') < 1000 || (int)$date->format('Y') > 9999) {
            throw new InvalidArgumentException('A valid transfer date is required');
        }
        $description = trim((string)($data['description'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
        if ($length > 255) throw new InvalidArgumentException('Description must be 255 characters or fewer');
        return [
            'amount' => $amount, 'fee_amount' => $fee,
            'fee_category_id' => $data['fee_category_id'] ?? null,
            'from_account_id' => (int)$from, 'to_account_id' => (int)$to,
            'date' => $dateValue, 'description' => $description,
        ];
    }

    private function strictMoney($raw, bool $allowZero, string $label): float {
        if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
            throw new InvalidArgumentException("{$label} must be a finite number");
        }
        $text = trim((string)$raw);
        if (!preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $text)) {
            throw new InvalidArgumentException("{$label} must use at most two decimal places");
        }
        $value = (float)$text;
        if (!is_finite($value) || (!$allowZero && $value <= 0) || ($allowZero && $value < 0) || $value > 999999999999.99) {
            throw new InvalidArgumentException("{$label} is outside the supported range");
        }
        return $value;
    }

    private function lockOwnedAccounts(array $accountIds, $userId): array {
        $ids = array_values(array_unique(array_map('intval', $accountIds)));
        sort($ids, SORT_NUMERIC);
        $accounts = [];
        foreach ($ids as $id) {
            $stmt = $this->conn->prepare('SELECT id, user_id, balance, is_active FROM accounts WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account || (int)$account['user_id'] !== (int)$userId) {
                throw new TransferAuthorizationException('Account not found or access denied');
            }
            if (!(int)$account['is_active']) throw new InvalidArgumentException('Account is not active');
            $accounts[$id] = $account;
        }
        return $accounts;
    }

    private function validateTransferCategory(array $data, $userId): void {
        if ($data['fee_amount'] <= 0) return;
        $categoryId = filter_var($data['fee_category_id'], FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) throw new InvalidArgumentException('A valid fee category is required');
        $this->validateCategoryOwnership($categoryId, $userId, 'expense');
    }

    private function syncTransferFee($transferId, $userId, $existingFee, array $data): void {
        if ($data['fee_amount'] == 0.0) {
            if ($existingFee && !$this->transactionModel->delete($existingFee['id'], $userId)) {
                throw new RuntimeException('Transfer fee removal failed');
            }
            return;
        }
        $feeData = [
            'user_id' => $userId, 'account_id' => $data['from_account_id'],
            'from_account_id' => null, 'to_account_id' => null,
            'category_id' => (int)$data['fee_category_id'], 'subcategory_id' => null,
            'amount' => $data['fee_amount'], 'type' => 'expense',
            'payment_method' => 'transfer', 'karobar_transaction_id' => null,
            'client_request_id' => null, 'transfer_parent_id' => $transferId,
            'date' => $data['date'],
            'description' => ($data['description'] !== '' ? $data['description'] : 'Transfer') . ' Fee',
        ];
        if ($existingFee) {
            if (!$this->transactionModel->update($existingFee['id'], $userId, $feeData)) {
                throw new RuntimeException('Transfer fee update failed');
            }
        } elseif (!$this->transactionModel->create($feeData)) {
            throw new RuntimeException('Transfer fee creation failed');
        }
    }

    private function replayTransfer(array $existing, array $expected, $userId): array {
        if ($existing['type'] !== 'transfer') throw new TransactionConflictException('Client request ID is already in use');
        $fee = $this->transactionModel->findTransferFee($existing['id'], $userId);
        $matches = (int)$existing['from_account_id'] === $expected['from_account_id']
            && (int)$existing['to_account_id'] === $expected['to_account_id']
            && number_format((float)$existing['amount'], 2, '.', '') === number_format($expected['amount'], 2, '.', '')
            && (string)$existing['date'] === $expected['date']
            && (string)$existing['description'] === $expected['description']
            && number_format((float)($fee['amount'] ?? 0), 2, '.', '') === number_format($expected['fee_amount'], 2, '.', '')
            && (int)($fee['category_id'] ?? 0) === (int)($expected['fee_category_id'] ?? 0);
        if (!$matches) throw new TransactionConflictException('Client request ID payload does not match the original transfer');
        return $this->getTransferResource($existing['id'], $userId);
    }

    private function invokeTransferFailure(string $point): void {
        if ($this->transferFailureInjector) call_user_func($this->transferFailureInjector, $point);
    }

    /* ------------------------------------------------------------------
     *  VALIDATION
     * ----------------------------------------------------------------*/

    private function validateTransaction($userId, $data, $type) {
        if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
            throw new \InvalidArgumentException('Invalid transaction type');
        }

        $rawAmount = $data['amount'] ?? null;
        $amount = is_numeric($rawAmount) ? (float)$rawAmount : 0;

        if (!is_finite($amount) || $amount <= 0 || $amount > 999999999999.99) {
            throw new \InvalidArgumentException('Amount must be a valid positive value');
        }

        $dateValue = (string)($data['date'] ?? '');
        $date = \DateTime::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue) {
            throw new \InvalidArgumentException('A valid transaction date is required');
        }

        $description = (string)($data['description'] ?? '');
        $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
        if ($descriptionLength > 255) {
            throw new \InvalidArgumentException('Description must be 255 characters or fewer');
        }

        $paymentMethod = $data['payment_method'] ?? null;
        if ($paymentMethod !== null && $paymentMethod !== '' && !preg_match('/^[a-z0-9_-]{1,30}$/i', (string)$paymentMethod)) {
            throw new \InvalidArgumentException('Invalid payment method');
        }

        if (in_array($type, ['income', 'expense'], true)) {
            $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
            if ($categoryId === false || $categoryId < 1) {
                throw new \InvalidArgumentException('Category is required');
            }
            $this->validateCategoryOwnership($categoryId, $userId, $type);
            if (!empty($data['subcategory_id'])) {
                $this->validateSubcategoryOwnership($data['subcategory_id'], $categoryId, $userId);
            }
        }

        if ($type === 'transfer' && (float)($data['fee_amount'] ?? 0) > 0) {
            $feeCategoryId = filter_var($data['fee_category_id'] ?? null, FILTER_VALIDATE_INT);
            if ($feeCategoryId === false || $feeCategoryId < 1) {
                throw new \InvalidArgumentException('A valid fee category is required');
            }
            $this->validateCategoryOwnership($feeCategoryId, $userId, 'expense');
        }

        switch ($type) {
            case 'income':
                $accountId = $data['to_account_id'] ?? $data['account_id'] ?? null;
                if (!$accountId) {
                    throw new \Exception("Receiving account is required for income");
                }
                $this->validateAccountOwnership($accountId, $userId);
                break;

            case 'expense':
                $accountId = $data['from_account_id'] ?? $data['account_id'] ?? null;
                if (!$accountId) {
                    throw new \Exception("Spending account is required for expense");
                }
                $this->validateAccountOwnership($accountId, $userId);
                break;

            case 'transfer':
                if (empty($data['from_account_id'])) {
                    throw new \Exception("Source account is required for transfer");
                }
                $this->validateAccountOwnership($data['from_account_id'], $userId);

                if (empty($data['to_account_id'])) {
                    throw new \Exception("Destination account is required for transfer");
                }
                $this->validateAccountOwnership($data['to_account_id'], $userId);

                if (strval($data['from_account_id']) === strval($data['to_account_id'])) {
                    throw new \Exception("Cannot transfer to the same account");
                }

                // Check balance using calculated balance (include fee for transfers)
                $checkAmount = $data['_check_amount'] ?? $amount;
                $fromBalance = $this->balanceService->calculateAccountBalance($data['from_account_id'], $userId);
                if ($fromBalance < $checkAmount) {
                    throw new \Exception("Insufficient balance in source account (needs Rs " . number_format($checkAmount, 0) . ")");
                }
                break;
        }
    }

    private function validateAccountOwnership($accountId, $userId) {
        $stmt = $this->conn->prepare("SELECT id, is_active FROM accounts WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->bindValue(':id', $accountId);
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new \Exception("Account not found or access denied");
        }
        if (!$account['is_active']) {
            throw new \Exception("Account is not active");
        }
    }

    private function validateCategoryOwnership($categoryId, $userId, $type) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM categories
             WHERE id = :id AND type = :type AND status = 'active'
               AND (user_id IS NULL OR user_id = :uid)
             LIMIT 1"
        );
        $stmt->bindValue(':id', (int)$categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':type', $type);
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException('Selected category is unavailable');
        }
    }

    private function validateSubcategoryOwnership($subcategoryId, $categoryId, $userId) {
        $subcategoryId = filter_var($subcategoryId, FILTER_VALIDATE_INT);
        if ($subcategoryId === false || $subcategoryId < 1) {
            throw new \InvalidArgumentException('Invalid subcategory');
        }
        $stmt = $this->conn->prepare(
            "SELECT sc.id FROM subcategories sc
             JOIN categories c ON c.id = sc.category_id
             WHERE sc.id = :id AND sc.category_id = :category_id
               AND sc.status = 'active' AND c.status = 'active'
               AND (sc.user_id IS NULL OR sc.user_id = :subcategory_uid)
               AND (c.user_id IS NULL OR c.user_id = :category_uid)
             LIMIT 1"
        );
        $stmt->bindValue(':id', (int)$subcategoryId, PDO::PARAM_INT);
        $stmt->bindValue(':category_id', (int)$categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':subcategory_uid', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':category_uid', (int)$userId, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException('Selected subcategory is unavailable');
        }
    }

    /* ------------------------------------------------------------------
     *  BALANCE RECALCULATION
     * ----------------------------------------------------------------*/

    public function recalculateAndUpdateBalance($accountId, $userId) {
        $calculated = $this->balanceService->calculateAccountBalance($accountId, $userId);
        $query = "UPDATE accounts SET balance = :balance WHERE id = :id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':balance', $calculated);
        $stmt->bindValue(':id', $accountId);
        $stmt->bindValue(':user_id', $userId);
        return $stmt->execute();
    }

    /* ------------------------------------------------------------------
     *  HELPERS
     * ----------------------------------------------------------------*/

    private function getAffectedAccounts($data): array {
        $accounts = [];
        switch ($data['type']) {
            case 'income':
                if (!empty($data['to_account_id'])) $accounts[] = $data['to_account_id'];
                if (!empty($data['account_id']) && !in_array($data['account_id'], $accounts)) $accounts[] = $data['account_id'];
                break;
            case 'expense':
                if (!empty($data['from_account_id'])) $accounts[] = $data['from_account_id'];
                if (!empty($data['account_id']) && !in_array($data['account_id'], $accounts)) $accounts[] = $data['account_id'];
                break;
            case 'transfer':
                if (!empty($data['from_account_id'])) $accounts[] = $data['from_account_id'];
                if (!empty($data['to_account_id'])) $accounts[] = $data['to_account_id'];
                break;
        }
        return $accounts;
    }

}
