<?php

require_once __DIR__ . '/../config/database.php';

class Transaction {
    private $conn;
    private $table = 'transactions';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, account_id, from_account_id, to_account_id, category_id, subcategory_id, amount, type, payment_method, karobar_transaction_id, date, description) 
                  VALUES (:user_id, :account_id, :from_account_id, :to_account_id, :category_id, :subcategory_id, :amount, :type, :payment_method, :karobar_transaction_id, :date, :description)";
        
        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(':user_id', $data['user_id']);
        $stmt->bindValue(':account_id', $data['account_id'] ?? null);
        $stmt->bindValue(':from_account_id', $data['from_account_id'] ?? null);
        $stmt->bindValue(':to_account_id', $data['to_account_id'] ?? null);
        $stmt->bindValue(':category_id', $data['category_id'] ?? null);
        $stmt->bindValue(':subcategory_id', $data['subcategory_id'] ?? null);
        $stmt->bindValue(':amount', $data['amount']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':payment_method', $data['payment_method'] ?? null);
        $stmt->bindValue(':karobar_transaction_id', $data['karobar_transaction_id'] ?? null);
        $stmt->bindValue(':date', $data['date']);
        $stmt->bindValue(':description', $data['description'] ?? '');
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function updateKarobarLink($transactionId, $karobarTransactionId) {
        $query = "UPDATE " . $this->table . " SET karobar_transaction_id = :karobar_id WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':karobar_id', $karobarTransactionId);
        $stmt->bindParam(':id', $transactionId);
        return $stmt->execute();
    }

    public function findAll($userId, $filters = [], $limit = 50, $offset = 0) {
        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type,
                  fa.name as from_account_name, ta.name as to_account_name
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  WHERE t.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (!empty($filters['account_id'])) {
            $query .= " AND t.account_id = :account_id";
            $params[':account_id'] = $filters['account_id'];
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
            $query .= " AND (t.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $query .= " ORDER BY t.date DESC, t.created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId) {
        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type,
                  fa.name as from_account_name, ta.name as to_account_name
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  WHERE t.id = :id AND t.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $fromAccountId = $data['from_account_id'] ?? null;
        $toAccountId = $data['to_account_id'] ?? null;
        $paymentMethod = $data['payment_method'] ?? null;

        $query = "UPDATE " . $this->table . " SET 
                  account_id = :account_id,
                  from_account_id = :from_account_id,
                  to_account_id = :to_account_id,
                  category_id = :category_id,
                  subcategory_id = :subcategory_id,
                  amount = :amount,
                  type = :type,
                  payment_method = :payment_method,
                  date = :date,
                  description = :description,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':account_id', $data['account_id'] ?? null);
        $stmt->bindValue(':from_account_id', $data['from_account_id'] ?? null);
        $stmt->bindValue(':to_account_id', $data['to_account_id'] ?? null);
        $stmt->bindValue(':category_id', $data['category_id'] ?? null);
        $stmt->bindValue(':subcategory_id', $data['subcategory_id'] ?? null);
        $stmt->bindValue(':amount', $data['amount']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':payment_method', $data['payment_method'] ?? null);
        $stmt->bindValue(':date', $data['date']);
        $stmt->bindValue(':description', $data['description'] ?? '');
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getStatistics($userId, $startDate, $endDate) {
        $query = "SELECT 
                  SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                  SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
                  SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as balance,
                  COUNT(CASE WHEN type = 'income' THEN 1 END) as income_count,
                  COUNT(CASE WHEN type = 'expense' THEN 1 END) as expense_count
                  FROM " . $this->table . " 
                  WHERE user_id = :user_id AND date BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getCategoryBreakdown($userId, $startDate, $endDate, $type) {
        $query = "SELECT 
                  c.name as category_name,
                  c.icon as category_icon,
                  c.color as category_color,
                  SUM(t.amount) as total_amount,
                  COUNT(t.id) as transaction_count
                  FROM " . $this->table . " t
                  JOIN categories c ON t.category_id = c.id
                  WHERE t.user_id = :user_id AND t.date BETWEEN :start_date AND :end_date AND t.type = :type
                  GROUP BY c.id, c.name, c.icon, c.color
                  ORDER BY total_amount DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findRecentByAccounts($userId, $accountIds, $limit = 10) {
        if (empty($accountIds)) return [];

        $fromPlaceholders = [];
        $toPlaceholders = [];
        $params = [':user_id' => $userId];
        $idx = 0;

        foreach ($accountIds as $aid) {
            $key = ':aid_' . $idx;
            $fromPlaceholders[] = $key;
            $toPlaceholders[] = $key;
            $params[$key] = $aid;
            $idx++;
        }

        $fromIn = implode(',', $fromPlaceholders);
        $toIn = implode(',', $toPlaceholders);

        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type,
                  fa.name as from_account_name, ta.name as to_account_name
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  WHERE t.user_id = :user_id
                    AND (t.from_account_id IN ($fromIn) OR t.to_account_id IN ($toIn))
                  ORDER BY t.date DESC, t.created_at DESC
                  LIMIT :limit";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMonthlyData($userId, $year) {
        $query = "SELECT 
                    DATE_FORMAT(date, '%b') AS month,
                    MONTH(date) AS month_num,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
                  FROM " . $this->table . "
                  WHERE user_id = :user_id AND YEAR(date) = :year
                  GROUP BY MONTH(date), DATE_FORMAT(date, '%b')
                  ORDER BY month_num ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':year', $year, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
