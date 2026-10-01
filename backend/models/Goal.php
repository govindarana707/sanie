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
                  (user_id, name, target_amount, initial_amount, current_amount, deadline, icon, color, description, status)
                  VALUES (:user_id, :name, :target_amount, :initial_amount, :current_amount, :deadline, :icon, :color, :description, :status)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':target_amount', $data['target_amount']);
        $stmt->bindValue(':initial_amount', $data['initial_amount'] ?? $data['current_amount'] ?? 0);
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

    public function update($id, $userId, $data, $expectedVersion = null) {
        $query = "UPDATE " . $this->table . " SET 
                  name = :name,
                  target_amount = :target_amount,
                  deadline = :deadline,
                  icon = :icon,
                  color = :color,
                  description = :description,
                  status = :status,
                  version = version + 1,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        if ($expectedVersion !== null) $query .= " AND version = :expected_version";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':target_amount', $data['target_amount']);
        $stmt->bindParam(':deadline', $data['deadline']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':status', $data['status']);
        if ($expectedVersion !== null) $stmt->bindValue(':expected_version', (int)$expectedVersion, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->rowCount() === 1;
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function findByIdForUpdate($id, $userId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table} WHERE id = :id AND user_id = :user_id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function recalculateCurrentAmount($id, $userId): bool {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} g
             SET g.current_amount = g.initial_amount + COALESCE((
                 SELECT SUM(t.amount) FROM transactions t
                 WHERE t.goal_id = g.id AND t.user_id = g.user_id AND t.type = 'goal_contribution'
             ), 0),
             g.status = CASE WHEN g.status = 'active' AND g.current_amount >= g.target_amount THEN 'completed' ELSE g.status END,
             g.updated_at = CURRENT_TIMESTAMP
             WHERE g.id = :id AND g.user_id = :user_id"
        );
        return $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }

    public function synchronizeCompletion($id, $userId): bool {
        $stmt=$this->conn->prepare("UPDATE {$this->table} SET status='completed',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:user_id AND status='active' AND current_amount>=target_amount");
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);
        return $stmt->rowCount()===1;
    }

    public function findContributions($goalId, $userId): array {
        $stmt = $this->conn->prepare(
            "SELECT t.*, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id AND a.user_id = t.user_id
             WHERE t.goal_id = :goal_id AND t.user_id = :user_id AND t.type = 'goal_contribution'
             ORDER BY t.date DESC, t.created_at DESC"
        );
        $stmt->execute([':goal_id' => $goalId, ':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countContributions($goalId, $userId): int {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM transactions
             WHERE goal_id = :goal_id AND user_id = :user_id AND type = 'goal_contribution'"
        );
        $stmt->execute([':goal_id' => $goalId, ':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function getTotalSaved($userId): float {
        $stmt = $this->conn->prepare('SELECT COALESCE(SUM(current_amount), 0) FROM goals WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        return (float)$stmt->fetchColumn();
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
