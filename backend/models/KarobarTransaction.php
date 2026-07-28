<?php

require_once __DIR__ . '/../config/database.php';

class KarobarTransaction {
    private $conn;
    private $table = 'karobar_transactions';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, person_id, type, amount, account_id, expense_transaction_id, income_transaction_id, payment_method, description, transaction_date, due_date) 
                  VALUES (:user_id, :person_id, :type, :amount, :account_id, :expense_transaction_id, :income_transaction_id, :payment_method, :description, :transaction_date, :due_date)";
        
        $stmt = $this->conn->prepare($query);
        
        $expenseTxId = $data['expense_transaction_id'] ?? null;
        $incomeTxId = $data['income_transaction_id'] ?? null;
        $paymentMethod = $data['payment_method'] ?? null;
        $dueDate = $data['due_date'] ?? null;

        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':person_id', $data['person_id']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':expense_transaction_id', $expenseTxId);
        $stmt->bindParam(':income_transaction_id', $incomeTxId);
        $stmt->bindParam(':payment_method', $paymentMethod);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':transaction_date', $data['transaction_date']);
        $stmt->bindParam(':due_date', $dueDate);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function updateLinkedIds($id, $expenseId, $incomeId) {
        $query = "UPDATE " . $this->table . " SET 
                  expense_transaction_id = :expense_id,
                  income_transaction_id = :income_id
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':expense_id', $expenseId);
        $stmt->bindParam(':income_id', $incomeId);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    public function findAll($userId, $filters = [], $limit = 50, $offset = 0) {
        $query = "SELECT kt.*, 
                  p.name as person_name, p.phone as person_phone, p.photo as person_photo, p.type as person_type,
                  a.name as account_name
                  FROM " . $this->table . " kt
                  LEFT JOIN people p ON kt.person_id = p.id
                  LEFT JOIN accounts a ON kt.account_id = a.id
                  WHERE kt.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND kt.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['person_id'])) {
            $query .= " AND kt.person_id = :person_id";
            $params[':person_id'] = $filters['person_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND kt.transaction_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $query .= " AND kt.transaction_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (p.name LIKE :search OR p.phone LIKE :search2 OR kt.description LIKE :search3)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
        }
        
        $query .= " ORDER BY kt.transaction_date DESC, kt.created_at DESC LIMIT :limit OFFSET :offset";
        
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
        $query = "SELECT kt.*, 
                  p.name as person_name, p.phone as person_phone, p.photo as person_photo, p.type as person_type,
                  a.name as account_name
                  FROM " . $this->table . " kt
                  LEFT JOIN people p ON kt.person_id = p.id
                  LEFT JOIN accounts a ON kt.account_id = a.id
                  WHERE kt.id = :id AND kt.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  person_id = :person_id,
                  type = :type,
                  amount = :amount,
                  account_id = :account_id,
                  description = :description,
                  transaction_date = :transaction_date,
                  due_date = :due_date,
                  payment_method = :payment_method,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':person_id', $data['person_id']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':transaction_date', $data['transaction_date']);
        $stmt->bindParam(':due_date', $data['due_date']);
        $updatePaymentMethod = $data['payment_method'] ?? null;
        $stmt->bindParam(':payment_method', $updatePaymentMethod);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getDashboard($userId) {
        $query = "SELECT 
                  COALESCE(SUM(CASE WHEN type = 'lent' THEN amount ELSE 0 END), 0) -
                  COALESCE(SUM(CASE WHEN type = 'returned' THEN amount ELSE 0 END), 0) as total_receivable,
                  COALESCE(SUM(CASE WHEN type = 'borrowed' THEN amount ELSE 0 END), 0) -
                  COALESCE(SUM(CASE WHEN type = 'repaid' THEN amount ELSE 0 END), 0) as total_payable,
                  COUNT(DISTINCT person_id) as people_count,
                  COUNT(*) as total_transactions
                  FROM " . $this->table . " 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $overdueQuery = "SELECT COALESCE(SUM(amount), 0) as overdue_amount 
                        FROM " . $this->table . " 
                        WHERE user_id = :user_id AND due_date IS NOT NULL AND due_date < CURDATE() AND type IN ('lent', 'borrowed')";
        
        $stmt = $this->conn->prepare($overdueQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $overdue = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['overdue_amount'] = floatval($overdue['overdue_amount'] ?? 0);
        
        $debtorQuery = "SELECT p.name,
                       COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) -
                       COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as amount
                       FROM people p
                       JOIN karobar_transactions kt ON p.id = kt.person_id
                       WHERE kt.user_id = :user_id
                       GROUP BY p.id, p.name
                       HAVING amount > 0
                       ORDER BY amount DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($debtorQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $stats['largest_debtor'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $creditorQuery = "SELECT p.name,
                         COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) -
                         COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as amount
                         FROM people p
                         JOIN karobar_transactions kt ON p.id = kt.person_id
                         WHERE kt.user_id = :user_id
                         GROUP BY p.id, p.name
                         HAVING amount > 0
                         ORDER BY amount DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($creditorQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $stats['largest_creditor'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $recentQuery = "SELECT kt.*, p.name as person_name
                       FROM karobar_transactions kt
                       LEFT JOIN people p ON kt.person_id = p.id
                       WHERE kt.user_id = :user_id
                       ORDER BY kt.created_at DESC LIMIT 5";
        
        $stmt = $this->conn->prepare($recentQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $stats['recent_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $peopleQuery = "SELECT p.id, p.name, p.photo, p.type,
                       COALESCE(SUM(CASE WHEN kt.type IN ('lent','repaid') THEN kt.amount ELSE 0 END), 0) -
                       COALESCE(SUM(CASE WHEN kt.type IN ('borrowed','returned') THEN kt.amount ELSE 0 END), 0) as balance
                       FROM people p
                       LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                       WHERE p.user_id = :user_id2 AND p.status = 'active'
                       GROUP BY p.id, p.name, p.photo, p.type
                       ORDER BY balance DESC";
        
        $stmt = $this->conn->prepare($peopleQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();
        $stats['people_balances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $monthlyQuery = "SELECT DATE_FORMAT(transaction_date, '%b') as month,
                        MONTH(transaction_date) as month_num,
                        SUM(CASE WHEN type = 'lent' THEN amount ELSE 0 END) as lent,
                        SUM(CASE WHEN type = 'borrowed' THEN amount ELSE 0 END) as borrowed,
                        SUM(CASE WHEN type = 'returned' THEN amount ELSE 0 END) as returned,
                        SUM(CASE WHEN type = 'repaid' THEN amount ELSE 0 END) as repaid
                        FROM karobar_transactions
                        WHERE user_id = :user_id AND YEAR(transaction_date) = YEAR(CURDATE())
                        GROUP BY MONTH(transaction_date), DATE_FORMAT(transaction_date, '%b')
                        ORDER BY month_num ASC";
        
        $stmt = $this->conn->prepare($monthlyQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $stats['monthly_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }

    public function getReports($userId, $filters = []) {
        $query = "SELECT kt.*, p.name as person_name, p.type as person_type
                  FROM " . $this->table . " kt
                  LEFT JOIN people p ON kt.person_id = p.id
                  WHERE kt.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND kt.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['person_id'])) {
            $query .= " AND kt.person_id = :person_id";
            $params[':person_id'] = $filters['person_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND kt.transaction_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $query .= " AND kt.transaction_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        $query .= " ORDER BY kt.transaction_date DESC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
