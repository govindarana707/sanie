<?php

require_once __DIR__ . '/../config/database.php';

/**
 * BalanceService – Centralized balance calculation engine.
 *
 * SINGLE SOURCE OF TRUTH for all balance computations.
 * All controllers MUST use this service; never calculate balances inline.
 *
 * Balance Formula:
 *   Current Balance = Opening Balance
 *                     + Income (account_id)
 *                     + Transfer In (to_account_id)
 *                     - Expense (account_id)
 *                     - Transfer Out (from_account_id)
 *
 * Net Worth = Total Balance + Total Receivable - Total Payable
 */
class BalanceService {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Calculate single account balance from opening_balance + all transactions.
     */
    public function calculateAccountBalance($accountId, $userId): float {
        $query = "SELECT
                    a.opening_balance,
                    COALESCE(SUM(CASE WHEN t.type = 'income'   AND t.account_id = :aid1 THEN t.amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN t.type = 'expense'  AND t.account_id = :aid2 THEN t.amount ELSE 0 END), 0) as total_expense,
                    COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.to_account_id   = :aid3 THEN t.amount ELSE 0 END), 0) as transfer_in,
                    COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.from_account_id = :aid4 THEN t.amount ELSE 0 END), 0) as transfer_out
                  FROM accounts a
                  LEFT JOIN transactions t ON t.user_id = a.user_id
                    AND (t.account_id = :aid5 OR t.from_account_id = :aid6 OR t.to_account_id = :aid7)
                  WHERE a.id = :aid8 AND a.user_id = :uid
                  GROUP BY a.id, a.opening_balance";

        $stmt = $this->conn->prepare($query);
        foreach ([1=>$accountId, 2=>$accountId, 3=>$accountId, 4=>$accountId,
                  5=>$accountId, 6=>$accountId, 7=>$accountId, 8=>$accountId] as $k => $v) {
            $stmt->bindValue(":aid{$k}", $v);
        }
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $acct = $this->getAccount($accountId, $userId);
            return $acct ? floatval($acct['opening_balance'] ?? 0) : 0;
        }

        return floatval($row['opening_balance'] ?? 0)
             + floatval($row['total_income'] ?? 0)
             + floatval($row['transfer_in'] ?? 0)
             - floatval($row['total_expense'] ?? 0)
             - floatval($row['transfer_out'] ?? 0);
    }

    /**
     * Build a full statement with running_balance for an account.
     * Returns: [ 'opening_balance' => float, 'entries' => [...], 'calculated_balance' => float ]
     */
    public function buildStatement($transactions, $openingBalance): array {
        $runningBalance = floatval($openingBalance);
        $entries = [];

        $entries[] = [
            'date'             => null,
            'type'             => 'opening',
            'category_name'    => 'Opening Balance',
            'subcategory_name' => '',
            'description'      => 'Opening Balance',
            'reference'        => '',
            'money_in'         => null,
            'money_out'        => null,
            'running_balance'  => $runningBalance,
        ];

        foreach ($transactions as $tx) {
            $moneyIn  = 0;
            $moneyOut = 0;

            if ($tx['type'] === 'income') {
                $moneyIn = floatval($tx['amount']);
                $runningBalance += $moneyIn;
            } elseif ($tx['type'] === 'expense') {
                $moneyOut = floatval($tx['amount']);
                $runningBalance -= $moneyOut;
            } elseif ($tx['type'] === 'transfer') {
                $accId  = $tx['account_id'] ?? null;
                $fromId = $tx['from_account_id'] ?? null;
                $toId   = $tx['to_account_id'] ?? null;
                // Determine direction relative to this account
                if ($toId && $toId == ($accId ?? $toId) && (!$fromId || $fromId != $toId)) {
                    // Transfer IN
                    $moneyIn = floatval($tx['amount']);
                    $runningBalance += $moneyIn;
                } elseif ($fromId && (!$toId || $toId != $fromId)) {
                    // Transfer OUT (or ambiguous — default to out)
                    $moneyOut = floatval($tx['amount']);
                    $runningBalance -= $moneyOut;
                }
            }

            $entries[] = [
                'id'                => $tx['id'] ?? null,
                'date'              => $tx['date'],
                'type'              => $tx['type'],
                'category_name'     => $tx['category_name'] ?? ($tx['type'] === 'transfer' ? 'Transfer' : ''),
                'subcategory_name'  => $tx['subcategory_name'] ?? '',
                'description'       => $tx['description'] ?? '',
                'reference'         => $tx['payment_method'] ?? '',
                'money_in'          => $moneyIn > 0 ? $moneyIn : null,
                'money_out'         => $moneyOut > 0 ? $moneyOut : null,
                'running_balance'   => $runningBalance,
                'karobar_type'      => $tx['karobar_type'] ?? null,
                'karobar_description' => $tx['karobar_description'] ?? null,
            ];
        }

        return [
            'opening_balance'    => floatval($openingBalance),
            'entries'            => $entries,
            'calculated_balance' => $runningBalance,
        ];
    }

    /**
     * Get all accounts with their calculated balances.
     */
    public function getAccountsWithBalances($userId): array {
        $accounts = $this->getAllAccounts($userId);
        $result = [];
        foreach ($accounts as $acct) {
            $calc = $this->calculateAccountBalance($acct['id'], $userId);
            $acct['calculated_balance'] = $calc;
            $result[] = $acct;
        }
        return $result;
    }

    /**
     * Total balance across all active accounts.
     */
    public function getTotalBalance($userId): float {
        $accounts = $this->getAccountsWithBalances($userId);
        $total = 0;
        foreach ($accounts as $acct) {
            if ($acct['is_active']) {
                $total += $acct['calculated_balance'];
            }
        }
        return $total;
    }

    /**
     * Savings balance (accounts with type = 'savings').
     */
    public function getSavingsBalance($userId): float {
        $total = 0;
        $accounts = $this->getAccountsWithBalances($userId);
        foreach ($accounts as $acct) {
            if ($acct['type'] === 'savings' && $acct['is_active']) {
                $total += $acct['calculated_balance'];
            }
        }
        return $total;
    }

    /**
     * Period statistics (income, expense, counts).
     */
    public function getStatistics($userId, $startDate, $endDate): array {
        $query = "SELECT
                    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
                    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as balance,
                    COUNT(CASE WHEN type = 'income'   THEN 1 END) as income_count,
                    COUNT(CASE WHEN type = 'expense'  THEN 1 END) as expense_count,
                    COUNT(CASE WHEN type = 'transfer' THEN 1 END) as transfer_count
                  FROM transactions
                  WHERE user_id = :uid AND date BETWEEN :start AND :end";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_income' => 0, 'total_expense' => 0, 'balance' => 0,
            'income_count' => 0, 'expense_count' => 0, 'transfer_count' => 0,
        ];
    }

    /**
     * Account overview with calculated balances.
     */
    public function getAccountOverview($userId): array {
        $query = "SELECT a.id, a.name, a.type, a.color, a.icon, a.is_active,
                         a.is_default, a.account_number, a.created_at, a.opening_balance,
                         (SELECT MAX(t.date) FROM transactions t
                           WHERE (t.account_id = a.id OR t.from_account_id = a.id OR t.to_account_id = a.id)
                             AND t.user_id = a.user_id
                         ) as last_transaction_date,
                         (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                           WHERE t.type = 'income' AND t.account_id = a.id AND t.user_id = a.user_id
                         ) as total_income,
                         (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                           WHERE t.type = 'expense' AND t.account_id = a.id AND t.user_id = a.user_id
                         ) as total_expense
                  FROM accounts a
                  WHERE a.user_id = :uid AND a.is_active = TRUE
                  ORDER BY a.is_default DESC, a.name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($accounts as &$acct) {
            $acct['calculated_balance'] = $this->calculateAccountBalance($acct['id'], $userId);
        }

        return $accounts;
    }

    // ----------------------------------------------------------------
    //  Helpers
    // ----------------------------------------------------------------

    private function getAccount($id, $userId) {
        $stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getAllAccounts($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM accounts WHERE user_id = :uid ORDER BY is_default DESC, name ASC");
        $stmt->bindValue(':uid', $userId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
