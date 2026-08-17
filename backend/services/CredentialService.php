<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PasswordPolicy.php';

class CredentialValidationException extends RuntimeException {
    public int $status;
    public array $errors;
    public function __construct(string$message,int$status=422,array$errors=[]){parent::__construct($message);$this->status=$status;$this->errors=$errors;}
}

class CredentialService {
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
}
