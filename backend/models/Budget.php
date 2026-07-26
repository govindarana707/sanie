<?php

require_once __DIR__ . '/../config/database.php';

class Budget {
    private $conn;
    private $table = 'budgets';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, category_id, subcategory_id, name, amount, period, start_date, end_date, alert_threshold, is_active) 
                  VALUES (:user_id, :category_id, :subcategory_id, :name, :amount, :period, :start_date, :end_date, :alert_threshold, :is_active)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':subcategory_id', $data['subcategory_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':period', $data['period']);
        $stmt->bindParam(':start_date', $data['start_date']);
        $stmt->bindParam(':end_date', $data['end_date']);
        $stmt->bindParam(':alert_threshold', $data['alert_threshold']);
        $stmt->bindParam(':is_active', $data['is_active']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId) {
        $query = "SELECT b.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name
                  FROM " . $this->table . " b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN categories sc ON b.subcategory_id = sc.id
                  WHERE b.user_id = :user_id
                  ORDER BY b.is_active DESC, b.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId) {
        $query = "SELECT b.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name
                  FROM " . $this->table . " b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN categories sc ON b.subcategory_id = sc.id
                  WHERE b.id = :id AND b.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  category_id = :category_id,
                  subcategory_id = :subcategory_id,
                  name = :name,
                  amount = :amount,
                  period = :period,
                  start_date = :start_date,
                  end_date = :end_date,
                  alert_threshold = :alert_threshold,
                  is_active = :is_active,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':subcategory_id', $data['subcategory_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':period', $data['period']);
        $stmt->bindParam(':start_date', $data['start_date']);
        $stmt->bindParam(':end_date', $data['end_date']);
        $stmt->bindParam(':alert_threshold', $data['alert_threshold']);
        $stmt->bindParam(':is_active', $data['is_active']);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getBudgetProgress($budgetId, $userId) {
        $budget = $this->findById($budgetId, $userId);
        
        if (!$budget) {
            return null;
        }
        
        $startDate = $budget['start_date'];
        $endDate = $budget['end_date'];
        $categoryId = $budget['category_id'];
        $subcategory_id = $budget['subcategory_id'];
        
        $query = "SELECT SUM(amount) as spent FROM transactions 
                  WHERE user_id = :user_id 
                  AND type = 'expense' 
                  AND date BETWEEN :start_date AND :end_date";
        
        if ($categoryId) {
            $query .= " AND category_id = :category_id";
        }
        
        if ($subcategory_id) {
            $query .= " AND subcategory_id = :subcategory_id";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        
        if ($categoryId) {
            $stmt->bindParam(':category_id', $categoryId);
        }
        
        if ($subcategory_id) {
            $stmt->bindParam(':subcategory_id', $subcategory_id);
        }
        
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $spent = $result['spent'] ?? 0;
        
        $remaining = $budget['amount'] - $spent;
        $percentage = ($budget['amount'] > 0) ? ($spent / $budget['amount']) * 100 : 0;
        
        return [
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $remaining,
            'percentage' => $percentage,
            'is_over_budget' => $spent > $budget['amount'],
            'is_near_limit' => $percentage >= $budget['alert_threshold']
        ];
    }
}
