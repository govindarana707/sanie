<?php

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/response.php';

class Middleware {
    public static function auth() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (!$authHeader) {
            Response::unauthorized('Authorization header required');
        }
        
        $token = str_replace('Bearer ', '', $authHeader);
        $payload = JWT::decode($token);
        
        if (!$payload) {
            Response::unauthorized('Invalid or expired token');
        }
        
        return $payload['user_id'];
    }

    public static function sanitizeInput($data) {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeInput($value);
            } else {
                $sanitized[$key] = htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
            }
        }
        return $sanitized;
    }

    public static function validateRequired($data, $requiredFields) {
        $errors = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        return $errors;
    }
}
