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
        
        $stmt->bindValue(':user_id', $data['user_id']);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':icon', $data['icon'] ?? null);
        $stmt->bindValue(':color', $data['color'] ?? '#6B7280');
        $stmt->bindValue(':description', $data['description'] ?? '');
        $stmt->bindValue(':is_default', $data['is_default'] ?? 0);
        $stmt->bindValue(':status', $data['status'] ?? 'active');
        $stmt->bindValue(':sort_order', $data['sort_order'] ?? 0);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId, $type = null, $status = 'active') {
        $query = "SELECT c.*,
                    COALESCE(t.tx_count, 0) AS transaction_count,
                    t.last_used_at
                  FROM " . $this->table . " c
                  LEFT JOIN (
                    SELECT category_id,
                           COUNT(*) AS tx_count,
                           MAX(created_at) AS last_used_at
                    FROM transactions
                    GROUP BY category_id
                  ) t ON t.category_id = c.id
                  WHERE (c.user_id = :user_id OR c.user_id IS NULL)";
        
        if ($type) {
            $query .= " AND c.type = :type";
        }
        
        if ($status) {
            $query .= " AND c.status = :status";
        }
        
        $query .= " ORDER BY c.sort_order ASC, c.name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        
        if ($type) {
            $stmt->bindValue(':type', $type);
        }
        
        if ($status) {
            $stmt->bindValue(':status', $status);
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
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        if ($userId) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
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
        
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':icon', $data['icon']);
        $stmt->bindValue(':color', $data['color']);
        $stmt->bindValue(':description', $data['description']);
        $stmt->bindValue(':status', $data['status']);
        $stmt->bindValue(':sort_order', $data['sort_order'], PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id AND is_default = FALSE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function softDelete($id, $userId) {
        $query = "UPDATE " . $this->table . " SET 
                  status = 'deleted',
                  deleted_at = CURRENT_TIMESTAMP,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id AND is_default = FALSE";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function archive($id, $userId) {
        $query = "UPDATE " . $this->table . " SET 
                  status = 'archived',
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function restore($id, $userId) {
        $query = "UPDATE " . $this->table . " SET 
                  status = 'active',
                  deleted_at = NULL,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
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
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function hasTransactions($id) {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE category_id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['count'] > 0) {
            return true;
        }

        $query2 = "SELECT COUNT(*) as count FROM recurring_transactions WHERE category_id = :id LIMIT 1";
        $stmt2 = $this->conn->prepare($query2);
        $stmt2->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt2->execute();
        
        $result2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        return $result2['count'] > 0;
    }

    public function getTransactionStats($id) {
        $query = "SELECT COUNT(*) AS tx_count, MAX(created_at) AS last_used_at FROM transactions WHERE category_id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateSortOrder($orders, $userId) {
        try {
            $this->conn->beginTransaction();
            
            foreach ($orders as $order) {
                $query = "UPDATE " . $this->table . " SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP 
                          WHERE id = :id AND user_id = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(':sort_order', $order['sort_order'], PDO::PARAM_INT);
                $stmt->bindValue(':id', $order['id'], PDO::PARAM_INT);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
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
        $sql = "SELECT DISTINCT c.*,
                    COALESCE(t.tx_count, 0) AS transaction_count,
                    t.last_used_at
                FROM " . $this->table . " c
                LEFT JOIN (
                    SELECT category_id,
                           COUNT(*) AS tx_count,
                           MAX(created_at) AS last_used_at
                    FROM transactions
                    GROUP BY category_id
                ) t ON t.category_id = c.id
                LEFT JOIN subcategories sc ON sc.category_id = c.id AND sc.status = 'active'
                WHERE (c.user_id = :user_id OR c.user_id IS NULL) 
                AND (c.name LIKE :query OR c.description LIKE :query OR sc.name LIKE :query OR sc.description LIKE :query)";
        
        if ($type) {
            $sql .= " AND c.type = :type";
        }
        
        if ($status) {
            $sql .= " AND c.status = :status";
        }
        
        $sql .= " ORDER BY c.sort_order ASC, c.name ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $searchTerm = "%{$query}%";
        $stmt->bindValue(':query', $searchTerm);
        
        if ($type) {
            $stmt->bindValue(':type', $type);
        }
        
        if ($status) {
            $stmt->bindValue(':status', $status);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function duplicate($id, $userId) {
        $source = $this->findById($id, $userId);
        if (!$source) return false;
        if ($source['is_default']) return false;

        $maxSortOrder = 0;
        $all = $this->findAll($userId, $source['type']);
        foreach ($all as $c) {
            if ($c['sort_order'] > $maxSortOrder) $maxSortOrder = $c['sort_order'];
        }

        $data = [
            'user_id'    => $userId,
            'name'       => $source['name'] . ' (Copy)',
            'type'       => $source['type'],
            'icon'       => $source['icon'],
            'color'      => $source['color'],
            'description'=> $source['description'],
            'is_default' => false,
            'status'     => 'active',
            'sort_order' => $maxSortOrder + 1
        ];

        return $this->create($data);
    }
}
