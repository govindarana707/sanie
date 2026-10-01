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
                  (user_id, name, type, icon, color, description, is_default, status, is_pinned, sort_order)
                  VALUES (:user_id, :name, :type, :icon, :color, :description, :is_default, :status, :is_pinned, :sort_order)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindValue(':user_id', (int)$data['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':icon', $data['icon'] ?? null);
        $stmt->bindValue(':color', $data['color'] ?? '#6B7280');
        $stmt->bindValue(':description', $data['description'] ?? '');
        $stmt->bindValue(':is_default', (int)(bool)($data['is_default'] ?? false), PDO::PARAM_INT);
        $stmt->bindValue(':status', $data['status'] ?? 'active');
        $stmt->bindValue(':is_pinned', (int)(bool)($data['is_pinned'] ?? false), PDO::PARAM_INT);
        $stmt->bindValue(':sort_order', (int)($data['sort_order'] ?? 999), PDO::PARAM_INT);
        
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
                    SELECT category_id, type,
                           COUNT(*) AS tx_count,
                           MAX(created_at) AS last_used_at
                    FROM transactions
                    WHERE user_id = :transaction_user_id
                      AND type IN ('income', 'expense')
                    GROUP BY category_id, type
                  ) t ON t.category_id = c.id AND t.type = c.type
                  WHERE (c.user_id = :user_id OR c.user_id IS NULL)";
        
        if ($type) {
            $query .= " AND c.type = :type";
        }
        
        if ($status) {
            $query .= " AND c.status = :status";
        }
        
        $query .= " ORDER BY CASE WHEN c.user_id = :priority_user_id THEN c.is_pinned ELSE 0 END DESC,
                            CASE WHEN c.user_id = :priority_user_id_order AND c.is_pinned = 1 THEN c.sort_order ELSE 999 END ASC,
                            COALESCE(t.tx_count, 0) DESC,
                            c.name ASC,
                            c.id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':transaction_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':priority_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':priority_user_id_order', $userId, PDO::PARAM_INT);
        
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
                  is_pinned = :is_pinned,
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
        $stmt->bindValue(':is_pinned', (int)(bool)$data['is_pinned'], PDO::PARAM_INT);
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
                  is_pinned = 0,
                  sort_order = 999,
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
                  is_pinned = 0,
                  sort_order = 999,
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

    public function hasTransactions($id, $userId = null) {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE category_id = :id" . ($userId ? " AND user_id = :user_id" : "") . " LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($userId) $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
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

    public function hasBudgets($id):bool {
        $stmt=$this->conn->prepare('SELECT COUNT(*) FROM budgets WHERE category_id=:id');$stmt->execute([':id'=>(int)$id]);return(int)$stmt->fetchColumn()>0;
    }

    public function getTransactionStats($id, $userId = null) {
        $query = "SELECT COUNT(*) AS tx_count, MAX(created_at) AS last_used_at FROM transactions WHERE category_id = :id" . ($userId ? " AND user_id = :user_id" : "");
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($userId) $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
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

    public function getNextPinnedSortOrder(int $userId, string $type): int {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) + 1
             FROM {$this->table}
             WHERE user_id = :user_id AND type = :type AND status = 'active' AND is_pinned = 1"
        );
        $stmt->execute([':user_id' => $userId, ':type' => $type]);
        return max(1, (int)$stmt->fetchColumn());
    }

    public function countOwnedByType(int $userId, string $type, string $status = 'active'): int {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = :user_id AND type = :type AND status = :status"
        );
        $stmt->execute([':user_id' => $userId, ':type' => $type, ':status' => $status]);
        return (int)$stmt->fetchColumn();
    }

    public function reorderPinned(array $orders, int $userId, string $type): bool {
        return $this->reorderPinnedBatch([$type => $orders], $userId);
    }

    public function reorderPinnedBatch(array $ordersByType, int $userId): bool {
        $expectedStmt = $this->conn->prepare(
            "SELECT id FROM {$this->table}
             WHERE user_id = :user_id AND type = :type AND status = 'active' AND is_pinned = 1
             ORDER BY id ASC"
        );
        foreach ($ordersByType as $type => $orders) {
            $expectedStmt->execute([':user_id' => $userId, ':type' => $type]);
            $expectedIds = array_map('intval', $expectedStmt->fetchAll(PDO::FETCH_COLUMN));
            $providedIds = array_map(static fn($order) => (int)$order['id'], $orders);
            sort($expectedIds);
            sort($providedIds);
            if ($expectedIds !== $providedIds) return false;
        }

        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare(
                "UPDATE {$this->table}
                 SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id AND type = :type
                   AND status = 'active' AND is_pinned = 1"
            );
            foreach ($ordersByType as $type => $orders) {
                foreach (array_values($orders) as $index => $order) {
                    $stmt->execute([
                        ':sort_order' => $index + 1,
                        ':id' => (int)$order['id'],
                        ':user_id' => $userId,
                        ':type' => $type,
                    ]);
                    if ($stmt->rowCount() > 1) throw new RuntimeException('Unexpected category reorder result.');
                }
            }
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return false;
        }
    }

    public function search($userId, $query, $type = null, $status = null) {
        $sql = "SELECT DISTINCT c.*,
                    COALESCE(t.tx_count, 0) AS transaction_count,
                    t.last_used_at
                FROM " . $this->table . " c
                LEFT JOIN (
                    SELECT category_id, type,
                           COUNT(*) AS tx_count,
                           MAX(created_at) AS last_used_at
                    FROM transactions
                    WHERE user_id = :transaction_user_id
                      AND type IN ('income', 'expense')
                    GROUP BY category_id, type
                ) t ON t.category_id = c.id AND t.type = c.type
                LEFT JOIN subcategories sc ON sc.category_id = c.id AND sc.status = 'active'
                WHERE (c.user_id = :user_id OR c.user_id IS NULL) 
                AND (c.name LIKE :name_query OR c.description LIKE :description_query
                     OR sc.name LIKE :subcategory_name_query OR sc.description LIKE :subcategory_description_query)";
        
        if ($type) {
            $sql .= " AND c.type = :type";
        }
        
        if ($status) {
            $sql .= " AND c.status = :status";
        }
        
        $sql .= " ORDER BY
                    CASE
                        WHEN LOWER(c.name) = LOWER(:exact_query) THEN 0
                        WHEN LOWER(c.name) LIKE LOWER(:prefix_query) THEN 1
                        ELSE 2
                    END ASC,
                    CASE WHEN c.user_id = :priority_user_id THEN c.is_pinned ELSE 0 END DESC,
                    CASE WHEN c.user_id = :priority_user_id_order AND c.is_pinned = 1 THEN c.sort_order ELSE 999 END ASC,
                    COALESCE(t.tx_count, 0) DESC,
                    c.name ASC,
                    c.id ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':transaction_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':priority_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':priority_user_id_order', $userId, PDO::PARAM_INT);
        $searchTerm = "%{$query}%";
        $stmt->bindValue(':name_query', $searchTerm);
        $stmt->bindValue(':description_query', $searchTerm);
        $stmt->bindValue(':subcategory_name_query', $searchTerm);
        $stmt->bindValue(':subcategory_description_query', $searchTerm);
        $stmt->bindValue(':exact_query', $query);
        $stmt->bindValue(':prefix_query', $query . '%');
        
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
            'is_pinned'  => false,
            'sort_order' => 999
        ];

        return $this->create($data);
    }
}
