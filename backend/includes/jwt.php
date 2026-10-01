<?php

require_once __DIR__ . '/../config/config.php';

class JWT {
    private static $secret = JWT_SECRET;
    private static $algorithm = JWT_ALGORITHM;

    public static function encode($payload) {
        if(isset($payload['user_id'])&&(!isset($payload['token_version'])||!isset($payload['data_generation']))){
            require_once __DIR__.'/../config/database.php';$conn=(new Database())->getConnection();$state=['token_version'=>0,'data_generation'=>1];
            if($conn)$state=self::getUserSessionState($conn,(int)$payload['user_id'])?:$state;
            $payload['token_version']=$payload['token_version']??(int)$state['token_version'];
            $payload['data_generation']=$payload['data_generation']??(int)$state['data_generation'];
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
        $state=self::getUserSessionState($conn,(int)$payload['user_id']);
        $generation=(int)($payload['data_generation']??1);
        return$state&&hash_equals((string)(int)$state['token_version'],(string)(int)$payload['token_version'])&&hash_equals((string)(int)$state['data_generation'],(string)$generation)?(int)$payload['user_id']:false;
    }

    public static function getUserSessionState(PDO $conn, int $userId): ?array {
        try {
            $stmt=$conn->prepare('SELECT token_version,data_generation FROM users WHERE id=:id LIMIT 1');
            $stmt->execute([':id'=>$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1]??0)!==1054) throw $e;
            $stmt=$conn->prepare('SELECT token_version FROM users WHERE id=:id LIMIT 1');
            $stmt->execute([':id'=>$userId]);
            $state=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$state) return null;
            $state['data_generation']=1;
            return $state;
        }
    }
}
