<?php

class Response {
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    public static function success($data, $message = 'Success', $statusCode = 200) {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function error($message, $statusCode = 400, $errors = null) {
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if ($errors) {
            $response['errors'] = $errors;
        }
        
        self::json($response, $statusCode);
    }

    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }

    public static function forbidden($message = 'Forbidden') {
        self::error($message, 403);
    }

    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }

    public static function serverError($message = 'Internal server error') {
        $technicalMessage = trim((string)$message);
        if ($technicalMessage !== '' && $technicalMessage !== 'Internal server error') {
            error_log('[SanIE] Server error: ' . substr(preg_replace('/[\r\n]+/', ' ', $technicalMessage), 0, 500));
        }
        $publicMessage = (defined('APP_DEBUG') && APP_DEBUG && $technicalMessage !== '')
            ? $technicalMessage
            : 'Unable to process this request right now.';
        self::error($publicMessage, 500);
    }
}
