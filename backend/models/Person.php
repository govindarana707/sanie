<?php

require_once __DIR__ . '/../config/database.php';

class Person {
    private $conn;
    private $table = 'people';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, name, type, phone, email, address, photo, notes, status) 
                  VALUES (:user_id, :name, :type, :phone, :email, :address, :photo, :notes, :status)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':photo', $data['photo']);
        $stmt->bindParam(':notes', $data['notes']);
        $stmt->bindParam(':status', $data['status']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId, $filters = [], $limit = 100, $offset = 0) {
        $query = "SELECT p.*,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as total_lent,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as total_borrowed,
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as total_returned,
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as total_repaid,
                  COUNT(kt.id) as transaction_count,
                  MAX(kt.created_at) as last_transaction_date
                  FROM " . $this->table . " p
                  LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2";
        
        $params = [':user_id' => $userId, ':user_id2' => $userId];
        
        if (!empty($filters['status'])) {
            $query .= " AND p.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['type'])) {
            $query .= " AND p.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (p.name LIKE :search OR p.phone LIKE :search2 OR p.email LIKE :search3 OR p.notes LIKE :search4)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $params[':search4'] = $searchTerm;
        }
        
        $query .= " GROUP BY p.id ORDER BY p.name ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($people as &$person) {
            $person['balance'] = $this->calculateBalance($person);
        }
        
        return $people;
    }

    public function findById($id, $userId) {
        $query = "SELECT p.* FROM " . $this->table . " p
                  WHERE p.id = :id AND p.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($person) {
            $person['total_lent'] = $this->getPersonAggregate($id, $userId, 'lent');
            $person['total_borrowed'] = $this->getPersonAggregate($id, $userId, 'borrowed');
            $person['total_returned'] = $this->getPersonAggregate($id, $userId, 'returned');
            $person['total_repaid'] = $this->getPersonAggregate($id, $userId, 'repaid');
            $person['transaction_count'] = $this->getTransactionCount($id, $userId);
            $person['last_transaction_date'] = $this->getLastTransactionDate($id, $userId);
            $person['balance'] = $this->calculateBalanceFromDB($id, $userId);
        }
        
        return $person;
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  name = :name,
                  type = :type,
                  phone = :phone,
                  email = :email,
                  address = :address,
                  photo = :photo,
                  notes = :notes,
                  status = :status,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':photo', $data['photo']);
        $stmt->bindParam(':notes', $data['notes']);
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

    public function getLedger($personId, $userId, $limit = 100, $offset = 0) {
        $query = "SELECT kt.*, 
                  a.name as account_name
                  FROM karobar_transactions kt
                  LEFT JOIN accounts a ON kt.account_id = a.id
                  WHERE kt.person_id = :person_id AND kt.user_id = :user_id
                  ORDER BY kt.transaction_date ASC, kt.created_at ASC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $runningBalance = 0;
        foreach ($transactions as &$tx) {
            $runningBalance = $this->calculateRunningBalance($tx, $runningBalance);
            $tx['running_balance'] = $runningBalance;
        }
        
        return $transactions;
    }

    private function calculateRunningBalance($tx, $currentBalance) {
        switch ($tx['type']) {
            case 'lent':
                return $currentBalance + floatval($tx['amount']);
            case 'borrowed':
                return $currentBalance - floatval($tx['amount']);
            case 'returned':
                return $currentBalance - floatval($tx['amount']);
            case 'repaid':
                return $currentBalance + floatval($tx['amount']);
            case 'adjustment':
                return $currentBalance + floatval($tx['amount']);
            default:
                return $currentBalance;
        }
    }

    private function calculateBalance($personData) {
        $lent = floatval($personData['total_lent'] ?? 0);
        $borrowed = floatval($personData['total_borrowed'] ?? 0);
        $returned = floatval($personData['total_returned'] ?? 0);
        $repaid = floatval($personData['total_repaid'] ?? 0);
        
        return ($lent + $repaid) - ($borrowed + $returned);
    }

    private function calculateBalanceFromDB($personId, $userId) {
        $query = "SELECT type, amount FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id
                  ORDER BY transaction_date ASC, created_at ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $balance = 0;
        foreach ($transactions as $tx) {
            $balance = $this->calculateRunningBalance($tx, $balance);
        }
        
        return $balance;
    }

    private function getPersonAggregate($personId, $userId, $type) {
        $query = "SELECT COALESCE(SUM(amount), 0) as total FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id AND type = :type";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return floatval($result['total'] ?? 0);
    }

    private function getTransactionCount($personId, $userId) {
        $query = "SELECT COUNT(*) as count FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return intval($result['count'] ?? 0);
    }

    private function getLastTransactionDate($personId, $userId) {
        $query = "SELECT MAX(transaction_date) as last_date FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['last_date'] ?? null;
    }
}
