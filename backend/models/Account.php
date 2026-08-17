<?php

require_once __DIR__ . '/../config/database.php';

class Account {
    private $conn;
    private $table = 'accounts';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . "
                  (user_id, name, type, account_number, balance, opening_balance, currency, color, icon, is_active, is_default, include_in_savings)
                  VALUES (:user_id, :name, :type, :account_number, :balance, :opening_balance, :currency, :color, :icon, :is_active, :is_default, :include_in_savings)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindValue(':user_id', $data['user_id']);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':account_number', $data['account_number'] ?? null);
        $stmt->bindValue(':balance', $data['balance'] ?? 0);
        $stmt->bindValue(':opening_balance', $data['opening_balance'] ?? 0);
        $stmt->bindValue(':currency', $data['currency'] ?? 'NPR');
        $stmt->bindValue(':color', $data['color'] ?? '#6B7280');
        $stmt->bindValue(':icon', $data['icon'] ?? null);
        $stmt->bindValue(':is_active', !empty($data['is_active']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':is_default', !empty($data['is_default']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':include_in_savings', !empty($data['include_in_savings']) ? 1 : 0, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId) {
        $query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id ORDER BY is_default DESC, name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id AND user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET
                  name = :name,
                  type = :type,
                  account_number = :account_number,
                  currency = :currency,
                  color = :color,
                  icon = :icon,
                  opening_balance = :opening_balance,
                  is_active = :is_active,
                  is_default = :is_default,
                  include_in_savings = :include_in_savings,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':account_number', $data['account_number'] ?? null);
        $stmt->bindValue(':currency', $data['currency'] ?? 'NPR');
        $stmt->bindValue(':color', $data['color'] ?? '#6B7280');
        $stmt->bindValue(':icon', $data['icon'] ?? null);
        $stmt->bindValue(':opening_balance', $data['opening_balance'] ?? 0);
        $stmt->bindValue(':is_active', !empty($data['is_active']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':is_default', !empty($data['is_default']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':include_in_savings', !empty($data['include_in_savings']) ? 1 : 0, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function updateBalance($id, $amount) {
        $query = "UPDATE " . $this->table . " SET balance = balance + :amount WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    public function getTransactionCount($id, $userId) {
        $query = "SELECT COUNT(*) as cnt FROM transactions WHERE user_id = :uid 
                  AND (account_id = :aid OR from_account_id = :aid2 OR to_account_id = :aid3)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':aid', $id);
        $stmt->bindValue(':aid2', $id);
        $stmt->bindValue(':aid3', $id);
        $stmt->execute();
        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    }

    public function getTotalBalance($userId) {
        $query = "SELECT SUM(balance) as total_balance FROM " . $this->table . " WHERE user_id = :user_id AND is_active = TRUE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_balance'] ?? 0;
    }

    public function getSavingsBalance($userId) {
        $query = "SELECT COALESCE(SUM(balance), 0) as savings_balance FROM " . $this->table . " WHERE user_id = :user_id AND (type = 'savings' OR include_in_savings = 1) AND is_active = TRUE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['savings_balance'] ?? 0);
    }

    public function getOverviewData($userId) {
        $query = "SELECT a.id, a.name, a.type, a.balance, a.opening_balance, a.color, a.icon, a.is_active, a.is_default, a.account_number, a.created_at,
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
                  FROM " . $this->table . " a
                  WHERE a.user_id = :user_id AND a.is_active = TRUE
                  ORDER BY a.is_default DESC, a.name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatement($userId, $accountId, $filters = []) {
        $params = [':user_id' => $userId, ':account_id' => $accountId];

        $query = "SELECT t.id, t.date, t.type, t.amount, t.description, t.payment_method, t.created_at, t.account_id, t.from_account_id, t.to_account_id,
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  fa.name as from_account_name, ta.name as to_account_name,
                  kt.id as karobar_id, kt.type as karobar_type, kt.description as karobar_description
                  FROM transactions t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  LEFT JOIN karobar_transactions kt ON t.karobar_transaction_id = kt.id
                  WHERE t.user_id = :user_id
                    AND (t.account_id = :account_id OR t.from_account_id = :account_id2 OR t.to_account_id = :account_id3)";

        $params[':account_id2'] = $accountId;
        $params[':account_id3'] = $accountId;

        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        if (!empty($filters['subcategory_id'])) {
            $query .= " AND t.subcategory_id = :subcategory_id";
            $params[':subcategory_id'] = $filters['subcategory_id'];
        }
        if (!empty($filters['start_date'])) {
            $query .= " AND t.date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND t.date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        if (!empty($filters['search'])) {
            $query .= " AND (t.description LIKE :search OR c.name LIKE :search2 OR sc.name LIKE :search3)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
        }

        $query .= " ORDER BY t.date ASC, t.created_at ASC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatementSummary($userId, $accountId, $startDate = null, $endDate = null) {
        $params = [':user_id' => $userId];
        $dateFilter = '';

        if ($startDate) {
            $dateFilter .= " AND t.date >= :start_date";
            $params[':start_date'] = $startDate;
        }
        if ($endDate) {
            $dateFilter .= " AND t.date <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $query = "SELECT
                  COALESCE(SUM(CASE WHEN t.type = 'income' AND t.account_id = :aid1 THEN t.amount ELSE 0 END), 0) as total_income,
                  COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.account_id = :aid2 THEN t.amount ELSE 0 END), 0) as total_expense,
                  COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.to_account_id = :aid3 THEN t.amount ELSE 0 END), 0) as transfer_in,
                  COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.from_account_id = :aid4 THEN t.amount ELSE 0 END), 0) as transfer_out,
                  COALESCE(SUM(CASE WHEN t.type = 'goal_contribution' AND t.account_id = :aid10 THEN t.amount ELSE 0 END), 0) as goal_contributions_out,
                  COUNT(DISTINCT t.id) as transaction_count,
                  COUNT(DISTINCT CASE WHEN t.type = 'income' AND t.account_id = :aid5 THEN t.id END) as income_count,
                  COUNT(DISTINCT CASE WHEN t.type = 'expense' AND t.account_id = :aid6 THEN t.id END) as expense_count
                  FROM transactions t
                  WHERE t.user_id = :user_id
                    AND (t.account_id = :aid7 OR t.from_account_id = :aid8 OR t.to_account_id = :aid9)
                  $dateFilter";

        $params[':aid1'] = $accountId;
        $params[':aid2'] = $accountId;
        $params[':aid3'] = $accountId;
        $params[':aid4'] = $accountId;
        $params[':aid5'] = $accountId;
        $params[':aid6'] = $accountId;
        $params[':aid7'] = $accountId;
        $params[':aid8'] = $accountId;
        $params[':aid9'] = $accountId;
        $params[':aid10'] = $accountId;

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $karobarQuery = "SELECT
                         COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as karobar_received,
                         COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as karobar_paid,
                         COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as karobar_repayment_made,
                         COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as karobar_repayment_received
                         FROM karobar_transactions kt
                         WHERE kt.user_id = :kuid AND kt.account_id = :kaid";
        $kStmt = $this->conn->prepare($karobarQuery);
        $kStmt->bindParam(':kuid', $userId);
        $kStmt->bindParam(':kaid', $accountId);
        $kStmt->execute();
        $karobarStats = $kStmt->fetch(PDO::FETCH_ASSOC);

        return array_merge($stats, $karobarStats);
    }

    public function getAnalytics($userId, $accountId) {
        $query = "SELECT
                  (SELECT COALESCE(MAX(amount), 0) FROM transactions WHERE user_id = :uid1 AND type = 'expense' AND (account_id = :aid1_1 OR from_account_id = :aid1_2 OR to_account_id = :aid1_3)) as highest_expense,
                  (SELECT COALESCE(MAX(amount), 0) FROM transactions WHERE user_id = :uid2 AND type = 'income' AND (account_id = :aid2_1 OR from_account_id = :aid2_2 OR to_account_id = :aid2_3)) as highest_income,
                  (SELECT COUNT(*) FROM transactions WHERE user_id = :uid3 AND (account_id = :aid3_1 OR from_account_id = :aid3_2 OR to_account_id = :aid3_3)) as total_transactions,
                  (SELECT COUNT(*) FROM transactions WHERE user_id = :uid4 AND type = 'expense' AND (account_id = :aid4_1 OR from_account_id = :aid4_2 OR to_account_id = :aid4_3)) as expense_count,
                  (SELECT COUNT(*) FROM transactions WHERE user_id = :uid5 AND type = 'income' AND (account_id = :aid5_1 OR from_account_id = :aid5_2 OR to_account_id = :aid5_3)) as income_count,
                  (SELECT COALESCE(MAX(amount), 0) FROM transactions WHERE user_id = :uid6 AND (account_id = :aid6_1 OR from_account_id = :aid6_2 OR to_account_id = :aid6_3)) as largest_transaction";

        $stmt = $this->conn->prepare($query);
        foreach (range(1, 6) as $i) {
            $stmt->bindValue(":uid{$i}", $userId);
            $stmt->bindValue(":aid{$i}_1", $accountId);
            $stmt->bindValue(":aid{$i}_2", $accountId);
            $stmt->bindValue(":aid{$i}_3", $accountId);
        }
        $stmt->execute();
        $basic = $stmt->fetch(PDO::FETCH_ASSOC);

        $avgDailyQuery = "SELECT
                          COALESCE(AVG(daily_total), 0) as avg_daily_spending
                          FROM (
                            SELECT SUM(amount) as daily_total
                            FROM transactions
                            WHERE user_id = :user_id AND type = 'expense' AND (account_id = :aid1 OR from_account_id = :aid2 OR to_account_id = :aid3)
                            GROUP BY date
                          ) as daily";
        $aStmt = $this->conn->prepare($avgDailyQuery);
        $aStmt->bindValue(':user_id', $userId);
        $aStmt->bindValue(':aid1', $accountId);
        $aStmt->bindValue(':aid2', $accountId);
        $aStmt->bindValue(':aid3', $accountId);
        $aStmt->execute();
        $avgData = $aStmt->fetch(PDO::FETCH_ASSOC);

        $avgMonthlyQuery = "SELECT
                            COALESCE(AVG(monthly_total), 0) as avg_monthly_spending
                            FROM (
                              SELECT SUM(amount) as monthly_total
                              FROM transactions
                              WHERE user_id = :user_id AND type = 'expense' AND (account_id = :aid1 OR from_account_id = :aid2 OR to_account_id = :aid3)
                              GROUP BY YEAR(date), MONTH(date)
                            ) as monthly";
        $mStmt = $this->conn->prepare($avgMonthlyQuery);
        $mStmt->bindValue(':user_id', $userId);
        $mStmt->bindValue(':aid1', $accountId);
        $mStmt->bindValue(':aid2', $accountId);
        $mStmt->bindValue(':aid3', $accountId);
        $mStmt->execute();
        $monthData = $mStmt->fetch(PDO::FETCH_ASSOC);

        $topCatQuery = "SELECT c.name as category_name, c.color as category_color,
                        SUM(t.amount) as total_amount, COUNT(t.id) as count
                        FROM transactions t
                        JOIN categories c ON t.category_id = c.id
                        WHERE t.user_id = :user_id AND t.type = 'expense' AND (t.account_id = :aid1 OR t.from_account_id = :aid2 OR t.to_account_id = :aid3)
                        GROUP BY c.id, c.name, c.color
                        ORDER BY total_amount DESC
                        LIMIT 1";
        $cStmt = $this->conn->prepare($topCatQuery);
        $cStmt->bindValue(':user_id', $userId);
        $cStmt->bindValue(':aid1', $accountId);
        $cStmt->bindValue(':aid2', $accountId);
        $cStmt->bindValue(':aid3', $accountId);
        $cStmt->execute();
        $topCategory = $cStmt->fetch(PDO::FETCH_ASSOC);

        $recentQuery = "SELECT t.date
                        FROM transactions t
                        WHERE t.user_id = :user_id AND (t.account_id = :aid1 OR t.from_account_id = :aid2 OR t.to_account_id = :aid3)
                        ORDER BY t.date DESC
                        LIMIT 1";
        $rStmt = $this->conn->prepare($recentQuery);
        $rStmt->bindValue(':user_id', $userId);
        $rStmt->bindValue(':aid1', $accountId);
        $rStmt->bindValue(':aid2', $accountId);
        $rStmt->bindValue(':aid3', $accountId);
        $rStmt->execute();
        $recentTx = $rStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'highest_expense' => $basic['highest_expense'] ?? 0,
            'highest_income' => $basic['highest_income'] ?? 0,
            'total_transactions' => $basic['total_transactions'] ?? 0,
            'expense_count' => $basic['expense_count'] ?? 0,
            'income_count' => $basic['income_count'] ?? 0,
            'largest_transaction' => $basic['largest_transaction'] ?? 0,
            'avg_daily_spending' => round($avgData['avg_daily_spending'] ?? 0, 2),
            'avg_monthly_spending' => round($monthData['avg_monthly_spending'] ?? 0, 2),
            'most_used_category' => $topCategory,
            'last_transaction_date' => $recentTx['date'] ?? null,
        ];
    }

    public function getMonthlyCashFlow($userId, $accountId, $year = null) {
        if (!$year) $year = date('Y');
        $query = "SELECT
                    DATE_FORMAT(t.date, '%b') AS month,
                    MONTH(t.date) AS month_num,
                    COALESCE(SUM(CASE WHEN t.type = 'income' AND t.account_id = :aid1 THEN t.amount ELSE 0 END), 0) AS income,
                    COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.account_id = :aid2 THEN t.amount ELSE 0 END), 0) AS expense,
                    COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.to_account_id = :aid3 THEN t.amount ELSE 0 END), 0) AS transfer_in,
                    COALESCE(SUM(CASE WHEN t.type = 'transfer' AND t.from_account_id = :aid4 THEN t.amount ELSE 0 END), 0) AS transfer_out
                    ,COALESCE(SUM(CASE WHEN t.type = 'goal_contribution' AND t.account_id = :aid8 THEN t.amount ELSE 0 END), 0) AS goal_contributions_out
                  FROM transactions t
                  WHERE t.user_id = :user_id
                    AND (t.account_id = :aid5 OR t.from_account_id = :aid6 OR t.to_account_id = :aid7)
                    AND YEAR(t.date) = :year
                  GROUP BY MONTH(t.date), DATE_FORMAT(t.date, '%b')
                  ORDER BY month_num ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':aid1', $accountId);
        $stmt->bindValue(':aid2', $accountId);
        $stmt->bindValue(':aid3', $accountId);
        $stmt->bindValue(':aid4', $accountId);
        $stmt->bindValue(':aid5', $accountId);
        $stmt->bindValue(':aid6', $accountId);
        $stmt->bindValue(':aid7', $accountId);
        $stmt->bindValue(':aid8', $accountId);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
