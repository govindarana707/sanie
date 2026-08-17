<?php

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private static $sharedConnection = null;
    private $lastError = null;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->db_name = getenv('DB_DATABASE') ?: 'sanie_db';
        $this->username = getenv('DB_USERNAME') ?: 'root';
        $this->password = getenv('DB_PASSWORD') ?: '';
    }

    public function getConnection() {
        if (self::$sharedConnection instanceof PDO) {
            return self::$sharedConnection;
        }

        try {
            self::$sharedConnection = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_PERSISTENT => true,
                ]
            );
            self::$sharedConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$sharedConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$sharedConnection->setAttribute(PDO::ATTR_TIMEOUT, 5);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('Database connection failed: ' . $e->getMessage());
            self::$sharedConnection = null;
        }

        return self::$sharedConnection;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function closeConnection() {
        self::$sharedConnection = null;
    }
}
