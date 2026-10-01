<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PasswordPolicy.php';

class CredentialValidationException extends RuntimeException {
    public int $status;
    public array $errors;
    public function __construct(string$message,int$status=422,array$errors=[]){parent::__construct($message);$this->status=$status;$this->errors=$errors;}
}

class CredentialService {
    public const RESET_TOKEN_BYTES = 32;
    private PDO $conn;
    private $failureHook;

    public function __construct(?PDO$connection=null,?callable$failureHook=null){$this->conn=$connection?:(new Database())->getConnection();if(!$this->conn)throw new RuntimeException('Database connection unavailable.');$this->failureHook=$failureHook;}

    public function changePassword(int$userId,array$data):array {
        $current=$data['current_password']??null;$new=$data['new_password']??null;
        if(!is_string($current)||$current==='')throw new CredentialValidationException('Validation failed',422,['current_password'=>'Current password is required.']);
        if($error=PasswordPolicy::error($new))throw new CredentialValidationException('Validation failed',422,['new_password'=>$error]);
        if(array_key_exists('new_password_confirmation',$data)){
            if(!is_string($data['new_password_confirmation'])||!hash_equals($new,$data['new_password_confirmation']))throw new CredentialValidationException('Validation failed',422,['new_password_confirmation'=>'Password confirmation does not match.']);
        }
        $this->conn->beginTransaction();
        try{
            $stmt=$this->conn->prepare('SELECT password,token_version FROM users WHERE id=:id LIMIT 1 FOR UPDATE');$stmt->execute([':id'=>$userId]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$user)throw new CredentialValidationException('User not found.',404);
            if(!password_verify($current,(string)$user['password']))throw new CredentialValidationException('Current password is incorrect.',401);
            if(password_verify($new,(string)$user['password']))throw new CredentialValidationException('Validation failed',422,['new_password'=>'New password must be different from the current password.']);
            $hash=password_hash($new,PASSWORD_DEFAULT);if(!is_string($hash)||$hash==='')throw new RuntimeException('Password hashing failed.');
            $update=$this->conn->prepare('UPDATE users SET password=:password,token_version=token_version+1,password_changed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $update->execute([':password'=>$hash,':id'=>$userId]);if($update->rowCount()!==1)throw new RuntimeException('Password update failed.');
            if($this->failureHook)($this->failureHook)('after_credential_update');
            $version=(int)$user['token_version']+1;$this->conn->commit();return['token_version'=>$version];
        }catch(Throwable$e){if($this->conn->inTransaction())$this->conn->rollBack();throw$e;}
    }

    public function createPasswordReset(string $email, int $ttlSeconds = 3600): ?array {
        $email = strtolower(trim($email));
        $this->conn->beginTransaction();
        try {
            $this->conn->prepare('DELETE FROM password_reset_tokens WHERE expires_at <= CURRENT_TIMESTAMP')->execute();
            $stmt = $this->conn->prepare('SELECT id,email FROM users WHERE email=:email LIMIT 1 FOR UPDATE');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $this->conn->commit();
                return null;
            }

            $this->conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=:user_id')
                ->execute([':user_id' => (int)$user['id']]);
            $token = bin2hex(random_bytes(self::RESET_TOKEN_BYTES));
            $tokenHash = hash('sha256', $token);
            $ttlSeconds = max(1, $ttlSeconds);
            $insert = $this->conn->prepare(
                "INSERT INTO password_reset_tokens(user_id,token,expires_at) VALUES(:user_id,:token,DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$ttlSeconds} SECOND))"
            );
            $insert->execute([
                ':user_id' => (int)$user['id'],
                ':token' => $tokenHash,
            ]);
            if ($insert->rowCount() !== 1) throw new RuntimeException('Password reset token creation failed.');
            $expiresValue = $this->conn->query('SELECT expires_at FROM password_reset_tokens WHERE id=' . (int)$this->conn->lastInsertId())->fetchColumn();
            if (!$expiresValue) throw new RuntimeException('Password reset expiry could not be read.');
            $expiresAt = new DateTimeImmutable((string)$expiresValue);
            $this->conn->commit();
            return ['email' => (string)$user['email'], 'token' => $token, 'expires_at' => $expiresAt];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    public function validatePasswordResetToken($token): bool {
        if (!$this->isValidResetTokenFormat($token)) return false;
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM password_reset_tokens r INNER JOIN users u ON u.id=r.user_id '
            . 'WHERE r.token=:token AND r.expires_at>CURRENT_TIMESTAMP LIMIT 1'
        );
        $stmt->execute([':token' => hash('sha256', $token)]);
        return (bool)$stmt->fetchColumn();
    }

    /** Remove only the reset token created for a delivery that failed. */
    public function invalidatePasswordResetToken($token): bool {
        if (!$this->isValidResetTokenFormat($token)) return false;
        $stmt = $this->conn->prepare('DELETE FROM password_reset_tokens WHERE token=:token');
        $stmt->execute([':token' => hash('sha256', $token)]);
        return $stmt->rowCount() > 0;
    }

    public function resetPassword(array $data): array {
        $token = $data['token'] ?? null;
        $new = $data['new_password'] ?? null;
        if (!$this->isValidResetTokenFormat($token)) {
            throw new CredentialValidationException('Reset link is invalid or expired.', 422, ['token' => 'Reset link is invalid or expired.']);
        }
        if ($error = PasswordPolicy::error($new)) {
            throw new CredentialValidationException('Validation failed', 422, ['new_password' => $error]);
        }
        if (array_key_exists('new_password_confirmation', $data)) {
            if (!is_string($data['new_password_confirmation']) || !hash_equals($new, $data['new_password_confirmation'])) {
                throw new CredentialValidationException('Validation failed', 422, ['new_password_confirmation' => 'Password confirmation does not match.']);
            }
        }

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                'SELECT r.id AS reset_id,r.user_id,u.password,u.token_version '
                . 'FROM password_reset_tokens r INNER JOIN users u ON u.id=r.user_id '
                . 'WHERE r.token=:token AND r.expires_at>CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':token' => hash('sha256', $token)]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                throw new CredentialValidationException('Reset link is invalid or expired.', 422, ['token' => 'Reset link is invalid or expired.']);
            }
            if (password_verify($new, (string)$record['password'])) {
                throw new CredentialValidationException('Validation failed', 422, ['new_password' => 'New password must be different from the current password.']);
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') throw new RuntimeException('Password hashing failed.');
            $update = $this->conn->prepare(
                'UPDATE users SET password=:password,token_version=token_version+1,password_changed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
            );
            $update->execute([':password' => $hash, ':id' => (int)$record['user_id']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Password update failed.');
            if ($this->failureHook) ($this->failureHook)('after_reset_credential_update');

            $consume = $this->conn->prepare('DELETE FROM password_reset_tokens WHERE id=:id AND token=:token');
            $consume->execute([':id' => (int)$record['reset_id'], ':token' => hash('sha256', $token)]);
            if ($consume->rowCount() !== 1) throw new RuntimeException('Password reset token consumption failed.');
            if ($this->failureHook) ($this->failureHook)('after_reset_token_consumption');
            $this->conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=:user_id')
                ->execute([':user_id' => (int)$record['user_id']]);
            $version = (int)$record['token_version'] + 1;
            $this->conn->commit();
            return ['user_id' => (int)$record['user_id'], 'token_version' => $version];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    private function isValidResetTokenFormat($token): bool {
        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
    }
}
