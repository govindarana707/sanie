<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/BalanceService.php';

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
 */
class AccountingService {
    private $conn;
    private $accountModel;
    private $transactionModel;
    private $goalModel;
    private $balanceService;

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

            // Goal contribution
            if (!empty($data['dynamic_subcategory_type']) && $data['dynamic_subcategory_type'] === 'goal' && !empty($data['dynamic_subcategory_id'])) {
                $this->goalModel->addContribution(
                    intval($data['dynamic_subcategory_id']),
                    $userId,
                    $amount
                );
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

    public function updateTransaction($id, $userId, $newData) {
        $this->conn->beginTransaction();

        try {
            $existing = $this->transactionModel->findById($id, $userId);
            if (!$existing) {
                throw new \Exception("Transaction not found");
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

            $validateData = array_merge($newData, ['user_id' => $userId]);
            $this->validateTransaction($userId, $validateData, $merged['type']);

            $this->transactionModel->update($id, $userId, $merged);

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

    public function deleteTransaction($id, $userId) {
        $this->conn->beginTransaction();

        try {
            $transaction = $this->transactionModel->findById($id, $userId);
            if (!$transaction) {
                throw new \Exception("Transaction not found");
            }

            $affectedAccounts = [];
            foreach (['from_account_id', 'to_account_id', 'account_id'] as $f) {
                if (!empty($transaction[$f])) $affectedAccounts[] = $transaction[$f];
            }

            // Reverse goal contribution if applicable
            if (strpos($transaction['description'], 'Allocation to goal:') === 0) {
                $goalName = str_replace('Allocation to goal: ', '', $transaction['description']);
                $goals = $this->goalModel->findAll($userId);
                foreach ($goals as $goal) {
                    if ($goal['name'] === $goalName) {
                        $this->goalModel->addContribution($goal['id'], $userId, -$transaction['amount']);
                        break;
                    }
                }
            }

            $this->transactionModel->delete($id, $userId);

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
     *  GOAL ALLOCATION
     * ----------------------------------------------------------------*/

    public function contributeToGoal($goalId, $userId, $amount, $accountId) {
        $this->conn->beginTransaction();

        try {
            $amount = floatval($amount);
            if ($amount <= 0) {
                throw new \Exception("Contribution amount must be positive");
            }

            // Validate account ownership and balance via BalanceService
            $this->validateAccountOwnership($accountId, $userId);
            $currentBalance = $this->balanceService->calculateAccountBalance($accountId, $userId);
            if ($currentBalance < $amount) {
                throw new \Exception("Insufficient balance in the selected account");
            }

            $goal = $this->goalModel->findById($goalId, $userId);
            if (!$goal) {
                throw new \Exception("Goal not found");
            }

            // Increase goal current_amount
            $this->goalModel->addContribution($goalId, $userId, $amount);

            // Create expense transaction for goal contribution
            $transactionData = [
                'user_id'              => $userId,
                'account_id'           => $accountId,
                'category_id'          => null,
                'subcategory_id'       => null,
                'amount'               => $amount,
                'type'                 => 'expense',
                'payment_method'       => null,
                'karobar_transaction_id' => null,
                'date'                 => date('Y-m-d'),
                'description'          => "Allocation to goal: {$goal['name']}",
                'from_account_id'      => null,
                'to_account_id'        => null,
            ];

            $transactionId = $this->transactionModel->create($transactionData);

            // Recalculate balance from scratch
            $this->recalculateAndUpdateBalance($accountId, $userId);

            $this->conn->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
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

    /* ------------------------------------------------------------------
     *  VALIDATION
     * ----------------------------------------------------------------*/

    private function validateTransaction($userId, $data, $type) {
        $amount = floatval($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new \Exception("Amount must be positive");
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

    /**
     * Direct balance column mutation.
     * Used ONLY by KarobarService for operations that don't create regular transactions.
     * NOTE: This bypasses the recalculate-from-scratch model.
     */
    public function updateAccountBalance($accountId, $amount) {
        $query = "UPDATE accounts SET balance = balance + :amount WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':amount', $amount);
        $stmt->bindValue(':id', $accountId);
        return $stmt->execute();
    }
}
