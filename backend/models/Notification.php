<?php

require_once __DIR__ . '/../config/database.php';

class Notification {
    private $conn;
    private $table = 'notifications';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, type, title, message, icon, color, priority, reference_type, reference_id) 
                  VALUES (:user_id, :type, :title, :message, :icon, :color, :priority, :reference_type, :reference_id)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':title', $data['title']);
        $stmt->bindParam(':message', $data['message']);
        $stmt->bindParam(':icon', $data['icon']);
        $stmt->bindParam(':color', $data['color']);
        $stmt->bindParam(':priority', $data['priority']);
        $stmt->bindParam(':reference_type', $data['reference_type']);
        $stmt->bindParam(':reference_id', $data['reference_id']);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function findAll($userId, $filters = [], $limit = 50, $offset = 0) {
        $query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if (!empty($filters['type'])) {
            $query .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        if (array_key_exists('is_read', $filters) && in_array($filters['is_read'], [0, 1, '0', '1'], true)) {
            $query .= " AND is_read = :is_read";
            $params[':is_read'] = (int)$filters['is_read'];
        }

        if (!empty($filters['search'])) {
            $query .= " AND (title LIKE :search_title OR message LIKE :search_message)";
            $params[':search_title'] = '%' . $filters['search'] . '%';
            $params[':search_message'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['period'])) {
            switch ($filters['period']) {
                case 'today':
                    $query .= " AND DATE(created_at) = CURDATE()";
                    break;
                case 'week':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
            }
        }

        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll($userId, $filters = []) {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if (!empty($filters['type'])) {
            $query .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        if (array_key_exists('is_read', $filters) && in_array($filters['is_read'], [0, 1, '0', '1'], true)) {
            $query .= " AND is_read = :is_read";
            $params[':is_read'] = (int)$filters['is_read'];
        }

        if (!empty($filters['search'])) {
            $query .= " AND (title LIKE :search_title OR message LIKE :search_message)";
            $params[':search_title'] = '%' . $filters['search'] . '%';
            $params[':search_message'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['period'])) {
            switch ($filters['period']) {
                case 'today':
                    $query .= " AND DATE(created_at) = CURDATE()";
                    break;
                case 'week':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
            }
        }

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['total'] : 0;
    }

    public function countUnread($userId) {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE user_id = :user_id AND is_read = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['total'] : 0;
    }

    public function findRecent($userId, $limit = 10) {
        $query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findLatestByReferenceTypes($userId, $referenceType, $referenceId, array $types) {
        $types=array_values(array_filter(array_map('strval',$types)));
        if(!$types)return false;
        $placeholders=implode(',',array_fill(0,count($types),'?'));
        $stmt=$this->conn->prepare("SELECT * FROM {$this->table} WHERE user_id=? AND reference_type=? AND reference_id=? AND type IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge([$userId,$referenceType,$referenceId],$types));
        return$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function markAsRead($id, $userId) {
        $query = "UPDATE " . $this->table . " SET is_read = 1 WHERE id = :id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function markAllAsRead($userId) {
        $query = "UPDATE " . $this->table . " SET is_read = 1 WHERE user_id = :user_id AND is_read = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function deleteAll($userId) {
        $query = "DELETE FROM " . $this->table . " WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function deleteByReference($referenceType, $referenceId, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE reference_type = :reference_type AND reference_id = :reference_id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':reference_type', $referenceType);
        $stmt->bindParam(':reference_id', $referenceId);
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }
}
