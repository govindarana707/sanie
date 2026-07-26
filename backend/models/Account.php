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
                  (user_id, name, type, account_number, balance, currency, color, icon, is_active, is_default) 
                  VALUES (:user_id, :name, :type, :account_number, :balance, :currency, :color, :icon, :is_active, :is_default)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':account_number', $data['account_number']);
        $stmt->bindParam(':balance', $data['balance']);
        $stmt->bindParam(':currency', $data['currency']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':is_active', $data['is_active']);
        $stmt->bindParam(':is_default', $data['is_default']);
        
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
                  balance = :balance,
                  currency = :currency,
                  color = :color,
                  icon = :icon,
                  is_active = :is_active,
                  is_default = :is_default,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':account_number', $data['account_number']);
        $stmt->bindParam(':balance', $data['balance']);
        $stmt->bindParam(':currency', $data['currency']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':is_active', $data['is_active']);
        $stmt->bindParam(':is_default', $data['is_default']);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function updateBalance($id, $amount) {
        $query = "UPDATE " . $this->table . " SET balance = balance + :amount WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    public function getTotalBalance($userId) {
        $query = "SELECT SUM(balance) as total_balance FROM " . $this->table . " WHERE user_id = :user_id AND is_active = TRUE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_balance'] ?? 0;
    }
}
