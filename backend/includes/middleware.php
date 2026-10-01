<?php

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../config/database.php';

class Middleware
{
    /**
     * Get Authorization header from every possible server environment.
     */
    private static function getAuthorizationHeader()
    {
        // Standard Apache
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }

        // Shared Hosting / FastCGI
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        // PHP CGI
        if (!empty($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }

        // Apache function
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    return trim($value);
                }
            }
        }

        // getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    public static function auth()
    {
        $authHeader = self::getAuthorizationHeader();

        if ($authHeader === '') {
            Response::unauthorized('Authorization header required');
        }

        if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            Response::unauthorized('Invalid Authorization header');
        }

        $token = trim($matches[1]);

        if ($token === '') {
            Response::unauthorized('Token missing');
        }

        $payload = JWT::decode($token);

        if (!$payload) {
            Response::unauthorized('Invalid or expired token');
        }

        if (!isset($payload['user_id'], $payload['token_version']) || !is_numeric($payload['token_version'])) {
            Response::unauthorized('Invalid token payload');
        }
        $userId=(int)$payload['user_id'];$conn=(new Database())->getConnection();
        if(!$conn)Response::serverError();
        $current=JWT::getUserSessionState($conn,$userId);
        $tokenGeneration=(int)($payload['data_generation']??1);
        if(!$current||!hash_equals((string)(int)$current['token_version'],(string)(int)$payload['token_version'])||!hash_equals((string)(int)$current['data_generation'],(string)$tokenGeneration))Response::unauthorized('Invalid or expired token');
        if(!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),['GET','HEAD','OPTIONS'],true)){
            $sent=$_SERVER['HTTP_X_SANIE_DATA_GENERATION']??null;
            if((int)$current['data_generation']>1&&(!is_numeric($sent)||!hash_equals((string)(int)$current['data_generation'],(string)(int)$sent)))Response::error('This saved change predates the latest Fresh Start and cannot be applied.',409);
        }
        return $userId;
    }

    public static function sanitizeInput($data)
    {
        if (!is_array($data)) {
            return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            $sanitized[$key] = self::sanitizeInput($value);
        }

        return $sanitized;
    }

    public static function validateRequired($data, $requiredFields)
    {
        $errors = [];

        if (!is_array($data)) {
            return ['request' => 'A valid JSON object is required'];
        }

        foreach ($requiredFields as $field) {

            if (
                !isset($data[$field]) ||
                $data[$field] === null ||
                $data[$field] === ''
            ) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        return $errors;
    }
}
