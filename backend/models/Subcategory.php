<?php

require_once __DIR__ . '/../config/database.php';

class Subcategory {
    private $conn;
    private $table = 'subcategories';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (category_id, user_id, name, icon, description, status, sort_order) 
                  VALUES (:category_id, :user_id, :name, :icon, :description, :status, :sort_order)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindValue(':category_id', $data['category_id'], PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $data['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':icon', $data['icon']);
        $stmt->bindValue(':description', $data['description']);
        $stmt->bindValue(':status', $data['status']);
        $stmt->bindValue(':sort_order', $data['sort_order'], PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId, $categoryId = null, $status = 'active') {
        $query = "SELECT s.*, c.name as category_name, c.type as category_type, c.color as category_color 
                  FROM " . $this->table . " s
                  LEFT JOIN categories c ON s.category_id = c.id
                  WHERE (s.user_id = :user_id OR s.user_id IS NULL)
                    AND (c.user_id = :category_user_id OR c.user_id IS NULL)";
        
        if ($categoryId) {
            $query .= " AND s.category_id = :category_id";
        }
        
        if ($status) {
            $query .= " AND s.status = :status";
        }
        
        $query .= " ORDER BY s.sort_order ASC, s.name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':category_user_id', $userId, PDO::PARAM_INT);
        
        if ($categoryId) {
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        }
        
        if ($status) {
            $stmt->bindValue(':status', $status);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId = null) {
        $query = "SELECT s.*, c.name as category_name, c.type as category_type, c.color as category_color 
                  FROM " . $this->table . " s
                  LEFT JOIN categories c ON s.category_id = c.id
                  WHERE s.id = :id";
        
        if ($userId) {
            $query .= " AND (s.user_id = :user_id OR s.user_id IS NULL)
                        AND (c.user_id = :category_user_id OR c.user_id IS NULL)";
        }
        
        $query .= " LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        if ($userId) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':category_user_id', $userId, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  category_id = :category_id,
                  name = :name,
                  icon = :icon,
                  description = :description,
                  status = :status,
                  sort_order = :sort_order,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':category_id', $data['category_id'], PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':icon', $data['icon']);
        $stmt->bindValue(':description', $data['description']);
        $stmt->bindValue(':status', $data['status']);
        $stmt->bindValue(':sort_order', $data['sort_order'], PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
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
                  WHERE id = :id AND user_id = :user_id";
        
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

    public function getByCategory($categoryId, $userId = null, $status = 'active') {
        $query = "SELECT s.* FROM " . $this->table . " s JOIN categories c ON c.id=s.category_id WHERE s.category_id = :category_id";
        
        if ($userId) {
            $query .= " AND (s.user_id = :user_id OR s.user_id IS NULL) AND (c.user_id = :category_user_id OR c.user_id IS NULL)";
        }
        
        if ($status) {
            $query .= " AND s.status = :status";
        }
        
        $query .= " ORDER BY s.sort_order ASC, s.name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        
        if ($userId) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':category_user_id', $userId, PDO::PARAM_INT);
        }
        
        if ($status) {
            $stmt->bindValue(':status', $status);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCountByCategory($categoryId) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE category_id = :category_id AND status = 'active'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }

    public function hasTransactions($id, $userId = null) {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE subcategory_id = :id" . ($userId ? " AND user_id = :user_id" : "") . " LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($userId) $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    public function hasBudgets($id):bool {
        $stmt=$this->conn->prepare('SELECT COUNT(*) FROM budgets WHERE subcategory_id=:id');$stmt->execute([':id'=>(int)$id]);return(int)$stmt->fetchColumn()>0;
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

    public function search($userId, $query, $categoryId = null, $status = null) {
        $sql = "SELECT s.*, c.name as category_name, c.type as category_type, c.color as category_color 
                FROM " . $this->table . " s
                LEFT JOIN categories c ON s.category_id = c.id
                WHERE (s.user_id = :user_id OR s.user_id IS NULL)
                AND (c.user_id = :category_user_id OR c.user_id IS NULL)
                AND (s.name LIKE :query OR s.description LIKE :query)";
        
        if ($categoryId) {
            $sql .= " AND s.category_id = :category_id";
        }
        
        if ($status) {
            $sql .= " AND s.status = :status";
        }
        
        $sql .= " ORDER BY s.sort_order ASC, s.name ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':category_user_id', $userId, PDO::PARAM_INT);
        $searchTerm = "%{$query}%";
        $stmt->bindValue(':query', $searchTerm);
        
        if ($categoryId) {
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        }
        
        if ($status) {
            $stmt->bindValue(':status', $status);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByCategoryIds($categoryIds, $userId = null, $status = 'active') {
        if (empty($categoryIds)) return [];

        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $query = "SELECT s.* FROM " . $this->table . " s JOIN categories c ON c.id=s.category_id WHERE s.category_id IN ($placeholders)";

        if ($userId) {
            $query .= " AND (s.user_id = ? OR s.user_id IS NULL) AND (c.user_id = ? OR c.user_id IS NULL)";
        }
        if ($status) {
            $query .= " AND s.status = ?";
        }

        $query .= " ORDER BY s.sort_order ASC, s.name ASC";
        $stmt = $this->conn->prepare($query);

        $params = $categoryIds;
        if ($userId) {$params[] = $userId;$params[]=$userId;}
        if ($status) $params[] = $status;
        $stmt->execute($params);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($results as $sub) {
            $grouped[$sub['category_id']][] = $sub;
        }
        return $grouped;
    }

    public function getStatistics($userId) {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN c.type = 'income' THEN 1 ELSE 0 END) as income_count,
                    SUM(CASE WHEN c.type = 'expense' THEN 1 ELSE 0 END) as expense_count,
                    SUM(CASE WHEN s.status != 'active' THEN 1 ELSE 0 END) as inactive_count
                  FROM " . $this->table . " s
                  LEFT JOIN categories c ON s.category_id = c.id
                  WHERE (s.user_id = :user_id OR s.user_id IS NULL)
                    AND (c.user_id = :category_user_id OR c.user_id IS NULL)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':category_user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
