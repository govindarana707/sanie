<?php

require_once __DIR__ . '/../config/database.php';

class User {
    private $conn;
    private $table = 'users';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function register($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (email, password, first_name, last_name, phone, currency, language, theme) 
                  VALUES (:email, :password, :first_name, :last_name, :phone, :currency, :language, :theme)";
        
        $stmt = $this->conn->prepare($query);
        
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':first_name', $data['first_name']);
        $stmt->bindParam(':last_name', $data['last_name']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':currency', $data['currency']);
        $stmt->bindParam(':language', $data['language']);
        $stmt->bindParam(':theme', $data['theme']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function login($email, $password) {
        $query = "SELECT * FROM " . $this->table . " WHERE email = :email LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        
        return false;
    }

    public function findById($id) {
        $query = "SELECT id, email, first_name, last_name, phone, avatar, currency, language, theme, notification_preferences, settings, created_at FROM " . $this->table . " WHERE id = :id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByEmail($email) {
        $query = "SELECT * FROM " . $this->table . " WHERE email = :email LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByGoogleId($googleId) {
        $query = "SELECT * FROM " . $this->table . " WHERE google_id = :google_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':google_id', $googleId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  first_name = :first_name,
                  last_name = :last_name,
                  phone = :phone,
                  avatar = :avatar,
                  currency = :currency,
                  language = :language,
                  theme = :theme,
                  notification_preferences = :notification_preferences,
                  settings = :settings,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':first_name', $data['first_name']);
        $stmt->bindParam(':last_name', $data['last_name']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':avatar', $data['avatar']);
        $stmt->bindParam(':currency', $data['currency']);
        $stmt->bindParam(':language', $data['language']);
        $stmt->bindParam(':theme', $data['theme']);
        $stmt->bindParam(':notification_preferences', $data['notification_preferences']);
        $stmt->bindParam(':settings', $data['settings']);
        
        return $stmt->execute();
    }

    public function updatePassword($id, $newPassword) {
        $query = "UPDATE " . $this->table . " SET password = :password WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    public function verifyEmail($id) {
        $query = "UPDATE " . $this->table . " SET email_verified_at = CURRENT_TIMESTAMP WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }
}
