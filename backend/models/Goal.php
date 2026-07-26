<?php

require_once __DIR__ . '/../config/database.php';

class Goal {
    private $conn;
    private $table = 'goals';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, name, target_amount, current_amount, deadline, icon, color, description, status) 
                  VALUES (:user_id, :name, :target_amount, :current_amount, :deadline, :icon, :color, :description, :status)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':target_amount', $data['target_amount']);
        $stmt->bindParam(':current_amount', $data['current_amount']);
        $stmt->bindParam(':deadline', $data['deadline']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':status', $data['status']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId) {
        $query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id ORDER BY status DESC, deadline ASC";
        
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
                  target_amount = :target_amount,
                  current_amount = :current_amount,
                  deadline = :deadline,
                  icon = :icon,
                  color = :color,
                  description = :description,
                  status = :status,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':target_amount', $data['target_amount']);
        $stmt->bindParam(':current_amount', $data['current_amount']);
        $stmt->bindParam(':deadline', $data['deadline']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':status', $data['status']);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function addContribution($id, $userId, $amount) {
        $query = "UPDATE " . $this->table . " SET current_amount = current_amount + :amount WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getGoalProgress($id, $userId) {
        $goal = $this->findById($id, $userId);
        
        if (!$goal) {
            return null;
        }
        
        $percentage = ($goal['target_amount'] > 0) ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
        $remaining = $goal['target_amount'] - $goal['current_amount'];
        
        // Calculate forecast completion date
        $forecastDate = null;
        if ($goal['current_amount'] > 0 && $goal['deadline']) {
            $daysRemaining = (strtotime($goal['deadline']) - time()) / 86400;
            if ($daysRemaining > 0) {
                $dailySavingRate = $goal['current_amount'] / max(1, (time() - strtotime($goal['created_at'])) / 86400);
                $daysToComplete = $remaining / max(0.01, $dailySavingRate);
                $forecastDate = date('Y-m-d', time() + ($daysToComplete * 86400));
            }
        }
        
        return [
            'goal' => $goal,
            'percentage' => $percentage,
            'remaining' => $remaining,
            'is_completed' => $percentage >= 100,
            'forecast_date' => $forecastDate
        ];
    }
}
