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

    public function bulkCreate($entries, $userId) {
        if (empty($entries)) return [];

        $this->conn->beginTransaction();
        try {
            $inserted = [];
            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table}
                 (user_id, category_id, subcategory_id, name, amount, period, start_date, end_date, alert_threshold, is_active)
                 VALUES (:user_id, :category_id, :subcategory_id, :name, :amount, :period, :start_date, :end_date, :alert_threshold, :is_active)"
            );

            foreach ($entries as $e) {
                $stmt->bindValue(':user_id', $userId);
                $stmt->bindValue(':category_id', $e['category_id'] ?? null);
                $stmt->bindValue(':subcategory_id', $e['subcategory_id'] ?? null);
                $stmt->bindValue(':name', $e['name']);
                $stmt->bindValue(':amount', $e['amount']);
                $stmt->bindValue(':period', $e['period']);
                $stmt->bindValue(':start_date', $e['start_date'] ?? date('Y-m-01'));
                $stmt->bindValue(':end_date', $e['end_date'] ?? date('Y-m-t'));
                $stmt->bindValue(':alert_threshold', $e['alert_threshold'] ?? 80);
                $stmt->bindValue(':is_active', $e['is_active'] ?? true);
                $stmt->execute();
                $inserted[] = $this->conn->lastInsertId();
            }

            $this->conn->commit();
            return $inserted;
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log('Budget::bulkCreate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getSuggestions($userId, $period = 'monthly', $months = 3) {
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime("-{$months} months"));

        $query = "SELECT
                    c.id AS category_id,
                    c.name AS category_name,
                    c.icon AS category_icon,
                    c.color AS category_color,
                    c.type AS category_type,
                    ROUND(COALESCE(SUM(t.amount) / ?, 0), 0) AS avg_spent
                  FROM categories c
                  LEFT JOIN transactions t
                    ON t.category_id = c.id
                    AND t.user_id = ?
                    AND t.date BETWEEN ? AND ?
                  WHERE (c.user_id = ? OR c.user_id IS NULL)
                  GROUP BY c.id
                  ORDER BY avg_spent DESC, c.name ASC";

        $divisor = max(1, $months);
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(1, $divisor, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId);
        $stmt->bindValue(3, $start);
        $stmt->bindValue(4, $end);
        $stmt->bindValue(5, $userId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByPeriod($userId, $period, $startDate, $endDate) {
        $query = "SELECT b.*,
                         c.name AS category_name, c.icon AS category_icon, c.color AS category_color
                  FROM {$this->table} b
                  LEFT JOIN categories c ON b.category_id = c.id
                  WHERE b.user_id = :user_id
                    AND b.period = :period
                    AND b.start_date >= :start_date
                    AND b.end_date <= :end_date
                  ORDER BY b.name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':period', $period);
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBatchProgress($ids, $userId) {
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $query = "SELECT
                    b.id AS budget_id,
                    b.name AS budget_name,
                    b.amount AS budget_amount,
                    b.alert_threshold,
                    c.name AS category_name,
                    c.icon AS category_icon,
                    c.color AS category_color,
                    sc.name AS subcategory_name,
                    COALESCE(SUM(t.amount), 0) AS spent
                  FROM budgets b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN categories sc ON b.subcategory_id = sc.id
                  LEFT JOIN transactions t
                    ON t.user_id = b.user_id
                    AND t.type = 'expense'
                    AND t.date BETWEEN b.start_date AND b.end_date
                    AND (b.category_id IS NULL OR t.category_id = b.category_id)
                    AND (b.subcategory_id IS NULL OR t.subcategory_id = b.subcategory_id)
                  WHERE b.id IN ($placeholders) AND b.user_id = ?
                  GROUP BY b.id";

        $stmt = $this->conn->prepare($query);
        $params = array_merge($ids, [$userId]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $progress = [];
        foreach ($rows as $r) {
            $spent = (float)$r['spent'];
            $amount = (float)$r['budget_amount'];
            $remaining = $amount - $spent;
            $percentage = $amount > 0 ? ($spent / $amount) * 100 : 0;

            $progress[] = [
                'budget_id' => $r['budget_id'],
                'budget' => [
                    'id' => $r['budget_id'],
                    'name' => $r['budget_name'],
                    'amount' => $r['budget_amount'],
                    'category_name' => $r['category_name'],
                    'category_icon' => $r['category_icon'],
                    'category_color' => $r['category_color'],
                    'subcategory_name' => $r['subcategory_name'],
                ],
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage' => $percentage,
                'is_over_budget' => $spent > $amount,
                'is_near_limit' => $percentage >= (float)$r['alert_threshold']
            ];
        }

        return $progress;
    }
}
