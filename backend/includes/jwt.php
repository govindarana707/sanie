<?php

require_once __DIR__ . '/../config/config.php';

class JWT {
    private static $secret = JWT_SECRET;
    private static $algorithm = JWT_ALGORITHM;

    public static function encode($payload) {
        if(isset($payload['user_id'])&&!isset($payload['token_version'])){
            require_once __DIR__.'/../config/database.php';$conn=(new Database())->getConnection();$version=0;
            if($conn){$stmt=$conn->prepare('SELECT token_version FROM users WHERE id=:id LIMIT 1');$stmt->execute([':id'=>(int)$payload['user_id']]);$version=(int)($stmt->fetchColumn()?:0);}
            $payload['token_version']=$version;
        }
        $header = json_encode(['typ' => 'JWT', 'alg' => self::$algorithm]);
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRATION;
        $payload['jti'] = bin2hex(random_bytes(16));
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode($token) {
        $tokenParts = explode('.', $token);
        
        if (count($tokenParts) !== 3) {
            return false;
        }
        
        $header = self::base64UrlDecode($tokenParts[0]);
        $payload = self::base64UrlDecode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];

        if ($header === false || $payload === false) return false;
        $decodedHeader = json_decode($header, true);
        $decodedPayload = json_decode($payload, true);
        if (!is_array($decodedHeader) || !is_array($decodedPayload)) return false;
        if (($decodedHeader['alg'] ?? null) !== self::$algorithm || ($decodedHeader['typ'] ?? null) !== 'JWT') return false;

        $signature = hash_hmac('sha256', $tokenParts[0] . "." . $tokenParts[1], self::$secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        if (!hash_equals($base64UrlSignature, $signatureProvided)) {
            return false;
        }

        if (isset($decodedPayload['exp']) && (!is_numeric($decodedPayload['exp']) || (int)$decodedPayload['exp'] < time())) {
            return false;
        }

        return $decodedPayload;
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        if (!is_string($data) || !preg_match('/^[A-Za-z0-9_-]+$/', $data)) return false;
        $padding = strlen($data) % 4;
        if ($padding) $data .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    public static function getUserIdFromToken($token) {
        $payload = self::decode($token);
        if(!$payload||!isset($payload['user_id'],$payload['token_version']))return false;
        require_once __DIR__.'/../config/database.php';$conn=(new Database())->getConnection();if(!$conn)return false;
        $stmt=$conn->prepare('SELECT token_version FROM users WHERE id=:id LIMIT 1');$stmt->execute([':id'=>(int)$payload['user_id']]);$version=$stmt->fetchColumn();
        return$version!==false&&hash_equals((string)(int)$version,(string)(int)$payload['token_version'])?(int)$payload['user_id']:false;
    }
}
