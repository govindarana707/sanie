<?php

require_once __DIR__ . '/../config/database.php';

class Transaction {
    private $conn;
    private $table = 'transactions';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, account_id, category_id, subcategory_id, amount, type, date, time, description, notes, tags, is_recurring, is_favorite, location_lat, location_lng, location_address, receipt_path, voice_note_path) 
                  VALUES (:user_id, :account_id, :category_id, :subcategory_id, :amount, :type, :date, :time, :description, :notes, :tags, :is_recurring, :is_favorite, :location_lat, :location_lng, :location_address, :receipt_path, :voice_note_path)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':subcategory_id', $data['subcategory_id']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':date', $data['date']);
        $stmt->bindParam(':time', $data['time']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':notes', $data['notes']);
        $stmt->bindParam(':tags', $data['tags']);
        $stmt->bindParam(':is_recurring', $data['is_recurring']);
        $stmt->bindParam(':is_favorite', $data['is_favorite']);
        $stmt->bindParam(':location_lat', $data['location_lat']);
        $stmt->bindParam(':location_lng', $data['location_lng']);
        $stmt->bindParam(':location_address', $data['location_address']);
        $stmt->bindParam(':receipt_path', $data['receipt_path']);
        $stmt->bindParam(':voice_note_path', $data['voice_note_path']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId, $filters = [], $limit = 50, $offset = 0) {
        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN categories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  WHERE t.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (!empty($filters['account_id'])) {
            $query .= " AND t.account_id = :account_id";
            $params[':account_id'] = $filters['account_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND t.date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $query .= " AND t.date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (t.description LIKE :search OR t.notes LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $query .= " ORDER BY t.date DESC, t.created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId) {
        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN categories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  WHERE t.id = :id AND t.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  account_id = :account_id,
                  category_id = :category_id,
                  subcategory_id = :subcategory_id,
                  amount = :amount,
                  type = :type,
                  date = :date,
                  time = :time,
                  description = :description,
                  notes = :notes,
                  tags = :tags,
                  is_recurring = :is_recurring,
                  is_favorite = :is_favorite,
                  location_lat = :location_lat,
                  location_lng = :location_lng,
                  location_address = :location_address,
                  receipt_path = :receipt_path,
                  voice_note_path = :voice_note_path,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':subcategory_id', $data['subcategory_id']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':date', $data['date']);
        $stmt->bindParam(':time', $data['time']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':notes', $data['notes']);
        $stmt->bindParam(':tags', $data['tags']);
        $stmt->bindParam(':is_recurring', $data['is_recurring']);
        $stmt->bindParam(':is_favorite', $data['is_favorite']);
        $stmt->bindParam(':location_lat', $data['location_lat']);
        $stmt->bindParam(':location_lng', $data['location_lng']);
        $stmt->bindParam(':location_address', $data['location_address']);
        $stmt->bindParam(':receipt_path', $data['receipt_path']);
        $stmt->bindParam(':voice_note_path', $data['voice_note_path']);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getStatistics($userId, $startDate, $endDate) {
        $query = "SELECT 
                  SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                  SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
                  SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as balance,
                  COUNT(CASE WHEN type = 'income' THEN 1 END) as income_count,
                  COUNT(CASE WHEN type = 'expense' THEN 1 END) as expense_count
                  FROM " . $this->table . " 
                  WHERE user_id = :user_id AND date BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getCategoryBreakdown($userId, $startDate, $endDate, $type) {
        $query = "SELECT 
                  c.name as category_name,
                  c.icon as category_icon,
                  c.color as category_color,
                  SUM(t.amount) as total_amount,
                  COUNT(t.id) as transaction_count
                  FROM " . $this->table . " t
                  JOIN categories c ON t.category_id = c.id
                  WHERE t.user_id = :user_id AND t.date BETWEEN :start_date AND :end_date AND t.type = :type
                  GROUP BY c.id, c.name, c.icon, c.color
                  ORDER BY total_amount DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
