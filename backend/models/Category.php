<?php

require_once __DIR__ . '/../config/database.php';

class Category {
    private $conn;
    private $table = 'categories';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, name, type, icon, color, description, is_default, status, sort_order) 
                  VALUES (:user_id, :name, :type, :icon, :color, :description, :is_default, :status, :sort_order)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':is_default', $data['is_default']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':sort_order', $data['sort_order']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId, $type = null, $status = 'active') {
        $query = "SELECT * FROM " . $this->table . " WHERE (user_id = :user_id OR user_id IS NULL)";
        
        if ($type) {
            $query .= " AND type = :type";
        }
        
        if ($status) {
            $query .= " AND status = :status";
        }
        
        $query .= " ORDER BY sort_order ASC, name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        
        if ($type) {
            $stmt->bindParam(':type', $type);
        }
        
        if ($status) {
            $stmt->bindParam(':status', $status);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId = null) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id";
        
        if ($userId) {
            $query .= " AND (user_id = :user_id OR user_id IS NULL)";
        }
        
        $query .= " LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($userId) {
            $stmt->bindParam(':user_id', $userId);
        }
        
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  name = :name,
                  type = :type,
                  icon = :icon,
                  color = :color,
                  description = :description,
                  status = :status,
                  sort_order = :sort_order,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':sort_order', $data['sort_order']);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id AND is_default = FALSE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function softDelete($id, $userId) {
        $query = "UPDATE " . $this->table . " SET 
                  status = 'deleted',
                  deleted_at = CURRENT_TIMESTAMP,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id AND is_default = FALSE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function archive($id, $userId) {
        $query = "UPDATE " . $this->table . " SET 
                  status = 'archived',
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function restore($id, $userId) {
        $query = "UPDATE " . $this->table . " SET 
                  status = 'active',
                  deleted_at = NULL,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getStatistics($userId) {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN type = 'income' THEN 1 ELSE 0 END) as income_count,
                    SUM(CASE WHEN type = 'expense' THEN 1 ELSE 0 END) as expense_count,
                    SUM(CASE WHEN status != 'active' THEN 1 ELSE 0 END) as inactive_count
                  FROM " . $this->table . " 
                  WHERE user_id = :user_id OR user_id IS NULL";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function hasTransactions($id) {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE category_id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    public function updateSortOrder($orders, $userId) {
        try {
            $this->conn->beginTransaction();
            
            foreach ($orders as $order) {
                $query = "UPDATE " . $this->table . " SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP 
                          WHERE id = :id AND user_id = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':sort_order', $order['sort_order']);
                $stmt->bindParam(':id', $order['id']);
                $stmt->bindParam(':user_id', $userId);
                $stmt->execute();
            }
            
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function search($userId, $query, $type = null, $status = null) {
        $sql = "SELECT * FROM " . $this->table . " 
                WHERE (user_id = :user_id OR user_id IS NULL) 
                AND (name LIKE :query OR description LIKE :query)";
        
        if ($type) {
            $sql .= " AND type = :type";
        }
        
        if ($status) {
            $sql .= " AND status = :status";
        }
        
        $sql .= " ORDER BY sort_order ASC, name ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $userId);
        $searchTerm = "%{$query}%";
        $stmt->bindParam(':query', $searchTerm);
        
        if ($type) {
            $stmt->bindParam(':type', $type);
        }
        
        if ($status) {
            $stmt->bindParam(':status', $status);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
