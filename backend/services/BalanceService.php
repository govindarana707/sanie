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
 * Dashboard Net Worth = Net Balance + Goal Assets + Total Receivable - Total Payable
 */
class BalanceService {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Calculate a balance from the immutable ledgers: opening balance, normal
     * transactions, transfers, and cash-moving Karobar entries.
     */
    public function calculateAccountBalance($accountId, $userId): float {
        $query = "SELECT
                    a.opening_balance,
                    COALESCE(SUM(CASE WHEN t.type = 'income'   AND t.account_id = :aid1 THEN t.amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN t.type = 'expense'  AND t.account_id = :aid2 THEN t.amount ELSE 0 END), 0) as total_expense,
                    COALESCE(SUM(CASE WHEN t.type = 'goal_contribution' AND t.account_id = :aid9 THEN t.amount ELSE 0 END), 0) as goal_contributions_out,
                    COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.to_account_id   = :aid3 THEN t.amount ELSE 0 END), 0) as transfer_in,
                    COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.from_account_id = :aid4 THEN t.amount ELSE 0 END), 0) as transfer_out,
                    COALESCE((
                        SELECT SUM(CASE
                            WHEN kt.type IN ('borrowed', 'returned') THEN kt.amount
                            WHEN kt.type IN ('lent', 'repaid') THEN -kt.amount
                            ELSE 0
                        END)
                        FROM karobar_transactions kt
                        WHERE kt.account_id = a.id AND kt.user_id = a.user_id
                    ), 0) as karobar_net
                  FROM accounts a
                  LEFT JOIN transactions t ON t.user_id = a.user_id
                    AND (t.account_id = :aid5 OR t.from_account_id = :aid6 OR t.to_account_id = :aid7)
                  WHERE a.id = :aid8 AND a.user_id = :uid
                  GROUP BY a.id, a.opening_balance";

        $stmt = $this->conn->prepare($query);
        foreach ([1=>$accountId, 2=>$accountId, 3=>$accountId, 4=>$accountId,
                  5=>$accountId, 6=>$accountId, 7=>$accountId, 8=>$accountId, 9=>$accountId] as $k => $v) {
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
             + floatval($row['karobar_net'] ?? 0)
             - floatval($row['total_expense'] ?? 0)
             - floatval($row['goal_contributions_out'] ?? 0)
             - floatval($row['transfer_out'] ?? 0);
    }

    /**
     * Rebuild the cached account balance from the authoritative ledgers.
     * This is required when an editable opening balance changes.
     */
    public function recalculateAndPersistAccountBalance($accountId, $userId): float {
        $calculated = $this->calculateAccountBalance($accountId, $userId);
        $stmt = $this->conn->prepare(
            'UPDATE accounts SET balance = :balance WHERE id = :id AND user_id = :user_id'
        );
        $stmt->bindValue(':balance', $calculated);
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0 && !$this->getAccount($accountId, $userId)) {
            throw new RuntimeException('Account not found');
        }

        return $calculated;
    }

    /**
     * Build a full statement with running_balance for an account.
     * Returns: [ 'opening_balance' => float, 'entries' => [...], 'calculated_balance' => float ]
     */
    public function buildStatement($transactions, $openingBalance, $accountId = null): array {
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
            } elseif ($tx['type'] === 'goal_contribution') {
                $moneyOut = floatval($tx['amount']);
                $runningBalance -= $moneyOut;
            } elseif ($tx['type'] === 'transfer') {
                $fromId = $tx['from_account_id'] ?? null;
                $toId   = $tx['to_account_id'] ?? null;
                // Determine direction relative to this account
                if ($accountId !== null && (int)$toId === (int)$accountId) {
                    // Transfer IN
                    $moneyIn = floatval($tx['amount']);
                    $runningBalance += $moneyIn;
                } elseif ($accountId !== null && (int)$fromId === (int)$accountId) {
                    // Transfer OUT (or ambiguous — default to out)
                    $moneyOut = floatval($tx['amount']);
                    $runningBalance -= $moneyOut;
                }
            }

            $entries[] = [
                'id'                => $tx['id'] ?? null,
                'date'              => $tx['date'],
                'type'              => $tx['type'],
                'category_name'     => $tx['category_name'] ?? ($tx['type'] === 'transfer' ? 'Transfer' : ($tx['type'] === 'goal_contribution' ? 'Savings Goal' : '')),
                'subcategory_name'  => $tx['subcategory_name'] ?? '',
                'description'       => $tx['type'] === 'transfer' && $accountId !== null
                    ? ((int)($tx['to_account_id'] ?? 0) === (int)$accountId
                        ? 'Transfer from ' . ($tx['from_account_name'] ?? 'source account')
                        : 'Transfer to ' . ($tx['to_account_name'] ?? 'destination account'))
                    : ($tx['description'] ?? ''),
                'transfer_direction' => $tx['type'] === 'transfer'
                    ? ((int)($tx['to_account_id'] ?? 0) === (int)$accountId ? 'in' : 'out')
                    : null,
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
     * Return a real-balance account statement. Date filters define the
     * financial range; type/category/search filters only choose visible rows.
     * Hidden rows still contribute to every row's running balance.
     */
    public function getAccountStatement($accountId, $userId, array $filters = [], int $limit = 50, int $offset = 0): array {
        $account = $this->getAccount($accountId, $userId);
        if (!$account) throw new RuntimeException('Account not found');
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $opening = (float)($account['opening_balance'] ?? 0);
        $cte = $this->accountEventsCte();
        [$visibleWhere, $visibleParams] = $this->statementWhere($filters, true);
        [$rangeWhere, $rangeParams] = $this->statementWhere($filters, false);
        $baseParams = $this->accountEventParams($accountId, $userId);

        $rowsSql = $cte . ", sequenced AS (
            SELECT e.*, :opening_balance + SUM(e.effect) OVER (
                ORDER BY e.event_date ASC, e.created_at ASC, e.source_rank ASC, e.source_id ASC
                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
            ) AS running_balance
            FROM account_events e
        )
        SELECT * FROM sequenced e {$visibleWhere}
        ORDER BY e.event_date ASC, e.created_at ASC, e.source_rank ASC, e.source_id ASC
        LIMIT :statement_limit OFFSET :statement_offset";
        $stmt = $this->conn->prepare($rowsSql);
        $this->bindStatementParams($stmt, $baseParams + $visibleParams + [':opening_balance'=>$opening]);
        $stmt->bindValue(':statement_limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':statement_offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->conn->prepare($cte . " SELECT COUNT(*) FROM account_events e {$visibleWhere}");
        $this->bindStatementParams($countStmt, $baseParams + $visibleParams);
        $countStmt->execute();
        $totalRows = (int)$countStmt->fetchColumn();

        $rangeStmt = $this->conn->prepare($cte . " SELECT
            COALESCE(SUM(CASE WHEN e.effect > 0 THEN e.effect ELSE 0 END),0) money_in,
            COALESCE(SUM(CASE WHEN e.effect < 0 THEN -e.effect ELSE 0 END),0) money_out,
            COALESCE(SUM(e.effect),0) net_change,
            COALESCE(SUM(CASE WHEN e.event_type='income' THEN e.amount ELSE 0 END),0) total_income,
            COALESCE(SUM(CASE WHEN e.event_type='expense' THEN e.amount ELSE 0 END),0) total_expense,
            COALESCE(SUM(CASE WHEN e.event_type='transfer' AND e.effect>0 THEN e.amount ELSE 0 END),0) transfer_in,
            COALESCE(SUM(CASE WHEN e.event_type='transfer' AND e.effect<0 THEN e.amount ELSE 0 END),0) transfer_out,
            COALESCE(SUM(CASE WHEN e.event_type='goal_contribution' THEN e.amount ELSE 0 END),0) goal_contributions_out,
            COALESCE(SUM(CASE WHEN e.event_type='karobar_borrowed' THEN e.amount ELSE 0 END),0) karobar_received,
            COALESCE(SUM(CASE WHEN e.event_type='karobar_lent' THEN e.amount ELSE 0 END),0) karobar_paid,
            COALESCE(SUM(CASE WHEN e.event_type='karobar_repaid' THEN e.amount ELSE 0 END),0) karobar_repayment_made,
            COALESCE(SUM(CASE WHEN e.event_type='karobar_returned' THEN e.amount ELSE 0 END),0) karobar_repayment_received,
            COUNT(*) transaction_count
            FROM account_events e {$rangeWhere}");
        $this->bindStatementParams($rangeStmt, $baseParams + $rangeParams);
        $rangeStmt->execute();
        $summary = $rangeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $rangeOpening = $opening;
        if (!empty($filters['start_date'])) {
            $priorStmt = $this->conn->prepare($cte . ' SELECT COALESCE(SUM(e.effect),0) FROM account_events e WHERE e.event_date < :prior_start');
            $this->bindStatementParams($priorStmt, $baseParams + [':prior_start'=>$filters['start_date']]);
            $priorStmt->execute();
            $rangeOpening += (float)$priorStmt->fetchColumn();
        }
        $closing = $rangeOpening + (float)($summary['net_change'] ?? 0);
        $pageOpening = $rows
            ? (float)$rows[0]['running_balance'] - (float)$rows[0]['effect']
            : $closing;

        $entries = [[
            'date'=>null, 'type'=>'opening', 'category_name'=>'Opening Balance',
            'subcategory_name'=>'', 'description'=>'Balance before this page',
            'reference'=>'', 'money_in'=>null, 'money_out'=>null,
            'running_balance'=>$pageOpening,
        ]];
        foreach ($rows as $row) {
            $effect = (float)$row['effect'];
            $entries[] = [
                'id'=>$row['source_id'], 'source'=>$row['source'], 'date'=>$row['event_date'],
                'created_at'=>$row['created_at'], 'type'=>$row['event_type'],
                'category_name'=>$row['category_name'] ?: '', 'subcategory_name'=>$row['subcategory_name'] ?: '',
                'description'=>$row['description'] ?: '', 'reference'=>$row['reference'] ?: '',
                'money_in'=>$effect > 0 ? $effect : null, 'money_out'=>$effect < 0 ? -$effect : null,
                'running_balance'=>(float)$row['running_balance'],
                'transfer_direction'=>$row['event_type']==='transfer' ? ($effect > 0 ? 'in' : 'out') : null,
                'karobar_type'=>str_starts_with($row['event_type'], 'karobar_') ? substr($row['event_type'], 8) : null,
            ];
        }
        $summary['money_in'] = (float)($summary['money_in'] ?? 0);
        $summary['money_out'] = (float)($summary['money_out'] ?? 0);
        $summary['net_change'] = (float)($summary['net_change'] ?? 0);
        $summary['opening_balance'] = $rangeOpening;
        $summary['closing_balance'] = $closing;
        $summary['filtered_row_count'] = $totalRows;

        return [
            'opening_balance'=>$rangeOpening, 'page_opening_balance'=>$pageOpening,
            'rows'=>$entries, 'closing_balance'=>$closing,
            'money_in'=>$summary['money_in'], 'money_out'=>$summary['money_out'],
            'net_change'=>$summary['net_change'], 'summary'=>$summary,
            'pagination'=>[
                'limit'=>$limit, 'offset'=>$offset, 'page'=>(int)floor($offset/$limit)+1,
                'total_rows'=>$totalRows, 'total_pages'=>(int)ceil($totalRows/$limit),
            ],
        ];
    }

    private function accountEventsCte(): string {
        return "WITH account_events AS (
            SELECT 'transaction' source, t.id source_id, t.date event_date, t.created_at, 1 source_rank,
                   t.type event_type, t.amount,
                   CASE
                     WHEN t.type='income' AND t.account_id=:effect_income_account THEN t.amount
                     WHEN t.type='expense' AND t.account_id=:effect_expense_account THEN -t.amount
                     WHEN t.type='goal_contribution' AND t.account_id=:effect_goal_account THEN -t.amount
                     WHEN t.type='transfer' AND t.to_account_id=:effect_transfer_in THEN t.amount
                     WHEN t.type='transfer' AND t.from_account_id=:effect_transfer_out THEN -t.amount
                     ELSE 0 END effect,
                   c.id category_id, t.subcategory_id, c.name category_name, sc.name subcategory_name,
                   CASE WHEN t.type='transfer' AND t.to_account_id=:description_transfer_in
                        THEN CONCAT('Transfer from ',COALESCE(fa.name,'source account'))
                        WHEN t.type='transfer' AND t.from_account_id=:description_transfer_out
                        THEN CONCAT('Transfer to ',COALESCE(ta.name,'destination account'))
                        ELSE t.description END description,
                   COALESCE(t.payment_method,'') reference
            FROM transactions t
            LEFT JOIN categories c ON c.id=t.category_id
            LEFT JOIN subcategories sc ON sc.id=t.subcategory_id
            LEFT JOIN accounts fa ON fa.id=t.from_account_id
            LEFT JOIN accounts ta ON ta.id=t.to_account_id
            WHERE t.user_id=:transaction_user
              AND (t.account_id=:where_account OR t.from_account_id=:where_from OR t.to_account_id=:where_to)
            UNION ALL
            SELECT 'karobar', kt.id, kt.transaction_date, kt.created_at, 2,
                   CONCAT('karobar_',kt.type), kt.amount,
                   CASE WHEN kt.type IN ('borrowed','returned') THEN kt.amount
                        WHEN kt.type IN ('lent','repaid') THEN -kt.amount ELSE 0 END,
                   NULL, NULL, 'Karobar', NULL, kt.description,
                   CONCAT(COALESCE(p.name,'Unknown'),' · ',COALESCE(kt.payment_method,''))
            FROM karobar_transactions kt
            LEFT JOIN people p ON p.id=kt.person_id
            WHERE kt.user_id=:karobar_user AND kt.account_id=:karobar_account
              AND kt.type IN ('borrowed','lent','returned','repaid')
        )";
    }

    private function accountEventParams($accountId, $userId): array {
        return [
            ':effect_income_account'=>$accountId, ':effect_expense_account'=>$accountId,
            ':effect_goal_account'=>$accountId, ':effect_transfer_in'=>$accountId,
            ':effect_transfer_out'=>$accountId, ':description_transfer_in'=>$accountId,
            ':description_transfer_out'=>$accountId, ':transaction_user'=>$userId,
            ':where_account'=>$accountId, ':where_from'=>$accountId, ':where_to'=>$accountId,
            ':karobar_user'=>$userId, ':karobar_account'=>$accountId,
        ];
    }

    private function statementWhere(array $filters, bool $includeVisibility): array {
        $conditions=[]; $params=[];
        if (!empty($filters['start_date'])) { $conditions[]='e.event_date >= :filter_start'; $params[':filter_start']=$filters['start_date']; }
        if (!empty($filters['end_date'])) { $conditions[]='e.event_date <= :filter_end'; $params[':filter_end']=$filters['end_date']; }
        if ($includeVisibility && !empty($filters['type'])) {
            if ($filters['type']==='karobar') $conditions[]="e.event_type LIKE 'karobar_%'";
            else { $conditions[]='e.event_type = :filter_type'; $params[':filter_type']=$filters['type']; }
        }
        if ($includeVisibility && !empty($filters['category_id'])) { $conditions[]='e.category_id = :filter_category'; $params[':filter_category']=$filters['category_id']; }
        if ($includeVisibility && !empty($filters['subcategory_id'])) { $conditions[]='e.subcategory_id = :filter_subcategory'; $params[':filter_subcategory']=$filters['subcategory_id']; }
        if ($includeVisibility && !empty($filters['search'])) {
            $conditions[]="CONCAT_WS(' ',e.description,e.category_name,e.subcategory_name,e.reference) LIKE :filter_search";
            $params[':filter_search']='%'.$filters['search'].'%';
        }
        return [$conditions ? 'WHERE '.implode(' AND ',$conditions) : '', $params];
    }

    private function bindStatementParams(PDOStatement $stmt, array $params): void {
        foreach ($params as $key=>$value) $stmt->bindValue($key, $value);
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
     * Net Balance: active account balances explicitly included by the user.
     * The stored inclusion preference is the sole eligibility rule.
     */
    public function getNetBalance($userId): float {
        $accounts = $this->getAccountsWithBalances($userId);
        $total = 0;
        foreach ($accounts as $acct) {
            if (!empty($acct['is_active']) && !empty($acct['include_in_net_balance'])) {
                $total += $acct['calculated_balance'];
            }
        }
        return $total;
    }

    /** Backward-compatible alias for callers that previously requested total balance. */
    public function getTotalBalance($userId): float {
        return $this->getNetBalance($userId);
    }

    /**
     * Liquid balance, excluding accounts classified as savings.
     */
    public function getCashBalance($userId): float {
        $total = 0;
        foreach ($this->getAccountsWithBalances($userId) as $acct) {
            $isSavings = $acct['type'] === 'savings' || !empty($acct['include_in_savings']);
            if ($acct['is_active'] && !$isSavings) {
                $total += $acct['calculated_balance'];
            }
        }
        return $total;
    }

    /**
     * Savings balance (savings accounts or accounts explicitly included).
     */
    public function getSavingsBalance($userId): float {
        $total = 0;
        $accounts = $this->getAccountsWithBalances($userId);
        foreach ($accounts as $acct) {
            if (($acct['type'] === 'savings' || !empty($acct['include_in_savings'])) && $acct['is_active']) {
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
    public function getAccountOverview($userId, $periodStart = null, $periodEnd = null): array {
        $incomeFilter = $periodStart && $periodEnd
            ? " AND t.date >= :pstart1 AND t.date <= :pend1"
            : "";
        $expenseFilter = $periodStart && $periodEnd
            ? " AND t.date >= :pstart2 AND t.date <= :pend2"
            : "";

        $query = "SELECT a.id, a.name, a.type, a.color, a.icon, a.is_active,
                         a.is_default, a.include_in_savings, a.include_in_net_balance, a.account_number, a.created_at, a.opening_balance,
                         (SELECT MAX(t.date) FROM transactions t
                           WHERE (t.account_id = a.id OR t.from_account_id = a.id OR t.to_account_id = a.id)
                             AND t.user_id = a.user_id
                         ) as last_transaction_date,
                         (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                           WHERE t.type = 'income' AND t.account_id = a.id AND t.user_id = a.user_id$incomeFilter
                         ) as total_income,
                         (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                           WHERE t.type = 'expense' AND t.account_id = a.id AND t.user_id = a.user_id$expenseFilter
                         ) as total_expense
                  FROM accounts a
                  WHERE a.user_id = :uid AND a.is_active = TRUE
                  ORDER BY a.is_default DESC, a.name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        if ($periodStart && $periodEnd) {
            $stmt->bindValue(':pstart1', $periodStart);
            $stmt->bindValue(':pend1', $periodEnd);
            $stmt->bindValue(':pstart2', $periodStart);
            $stmt->bindValue(':pend2', $periodEnd);
        }
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
