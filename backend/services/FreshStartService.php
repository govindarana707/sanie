<?php

require_once __DIR__ . '/InitialUserDataService.php';

class FreshStartValidationException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

class FreshStartService
{
    private const INTENT_TTL_SECONDS = 600;

    public function __construct(private PDO $conn, private $failureProbe = null) {}

    public function prepare(int $userId): array
    {
        $summary = $this->summary($userId);
        $operationId = $this->uuid();
        $intent = bin2hex(random_bytes(32));
        $this->conn->beginTransaction();
        try {
            $cancel = $this->conn->prepare("UPDATE fresh_start_operations SET status='cancelled' WHERE user_id=:user_id AND status IN ('prepared','verified')");
            $cancel->execute([':user_id'=>$userId]);
            $stmt = $this->conn->prepare(
                "INSERT INTO fresh_start_operations(operation_id,user_id,intent_hash,status,summary_json,expires_at)
                 VALUES(:operation_id,:user_id,:intent_hash,'prepared',:summary_json,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE))"
            );
            $stmt->execute([
                ':operation_id'=>$operationId, ':user_id'=>$userId,
                ':intent_hash'=>hash('sha256',$intent), ':summary_json'=>json_encode($summary),
            ]);
            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
        return [
            'operation_id'=>$operationId,
            'intent_token'=>$intent,
            'expires_in'=>self::INTENT_TTL_SECONDS,
            'summary'=>$summary,
            'preserved'=>[
                'SanIE account and login credentials','name, email, phone, and profile photo',
                'email verification and two-factor authentication settings','shared system categories',
            ],
            'backup'=>[
                'available'=>true,
                'format'=>'JSON',
                'limitations'=>'Includes database records and attachment metadata. Uploaded attachment files are not embedded.',
            ],
        ];
    }

    public function verify(int $userId, string $operationId, string $intent, string $password, string $phrase): array
    {
        if (!hash_equals('RESET ALL DATA', trim($phrase))) throw new FreshStartValidationException('Confirmation phrase is incorrect.');
        if ($password === '') throw new FreshStartValidationException('Authentication could not be verified.',401);
        $operation = $this->validOperation($userId,$operationId,$intent,['prepared','verified']);
        $stmt=$this->conn->prepare('SELECT password FROM users WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>$userId]);
        $hash=$stmt->fetchColumn();
        if (!is_string($hash) || $hash==='' || !password_verify($password,$hash)) {
            throw new FreshStartValidationException('Authentication could not be verified.',401);
        }
        $confirmation=bin2hex(random_bytes(32));
        $update=$this->conn->prepare("UPDATE fresh_start_operations SET confirmation_hash=:hash,status='verified',verified_at=CURRENT_TIMESTAMP,expires_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 5 MINUTE) WHERE operation_id=:operation_id AND user_id=:user_id AND status IN ('prepared','verified')");
        $update->execute([':hash'=>hash('sha256',$confirmation),':operation_id'=>$operation['operation_id'],':user_id'=>$userId]);
        if ($update->rowCount()!==1) throw new FreshStartValidationException('Reset verification expired. Start again.',409);
        return ['operation_id'=>$operationId,'confirmation_token'=>$confirmation,'expires_in'=>300];
    }

    public function export(int $userId, string $operationId, string $intent): array
    {
        $this->validOperation($userId,$operationId,$intent,['prepared','verified']);
        $tables=['accounts','transactions','attachments','recurring_transactions','categories','subcategories','budgets','goals','people','karobar_transactions','tasks','notifications','notification_events','reports','ai_analysis_history','activity_logs'];
        $data=[];
        foreach($tables as $table){
            $stmt=$this->conn->prepare("SELECT * FROM `{$table}` WHERE user_id=:user_id ORDER BY id");
            $stmt->execute([':user_id'=>$userId]);
            $data[$table]=$stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $profile=$this->conn->prepare('SELECT id,email,first_name,last_name,phone,avatar,currency,language,theme,notification_preferences,settings,created_at FROM users WHERE id=:id');
        $profile->execute([':id'=>$userId]);
        return [
            'format'=>'sanie-fresh-start-export-v1','generated_at'=>gmdate('c'),
            'limitations'=>['Uploaded attachment files are not embedded.','This export is not an in-application restore package.'],
            'profile'=>$profile->fetch(PDO::FETCH_ASSOC),'data'=>$data,
        ];
    }

    public function execute(int $userId, string $operationId, string $intent, string $confirmation): array
    {
        if ($intent==='' || $confirmation==='') throw new FreshStartValidationException('Reset authorization is missing.',403);
        $lockName='sanie:fresh-start:'.$userId;
        $lock=$this->conn->prepare('SELECT GET_LOCK(:name,5)');$lock->execute([':name'=>$lockName]);
        if ((int)$lock->fetchColumn()!==1) throw new FreshStartValidationException('A reset is already in progress.',409);
        try {
            $this->conn->beginTransaction();
            $operation=$this->lockVerifiedOperation($userId,$operationId,$intent,$confirmation);
            $this->assertTenantIntegrity($userId);
            $this->probe('before_deletion');
            $files=$this->attachmentPaths($userId);
            foreach($files as $path){
                $job=$this->conn->prepare("INSERT IGNORE INTO fresh_start_file_cleanup(operation_id,relative_path) VALUES(:operation_id,:path)");
                $job->execute([':operation_id'=>$operationId,':path'=>$path]);
            }
            $mark=$this->conn->prepare("UPDATE fresh_start_operations SET status='processing' WHERE operation_id=:id AND user_id=:user_id");
            $mark->execute([':id'=>$operationId,':user_id'=>$userId]);

            foreach(['attachments','notifications','notification_events','reports','ai_analysis_history','activity_logs','tasks','budgets','karobar_transactions','transactions','recurring_transactions','goals','people','subcategories','categories','accounts'] as $table){
                $stmt=$this->conn->prepare("DELETE FROM `{$table}` WHERE user_id=:user_id");
                $stmt->execute([':user_id'=>$userId]);
            }
            $this->conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=:user_id')->execute([':user_id'=>$userId]);
            (new InitialUserDataService($this->conn))->createDefaults($userId);
            $this->probe('after_defaults');
            $user=$this->conn->prepare("UPDATE users SET currency='NPR',language='en',theme='light',notification_preferences='[]',settings='[]',token_version=token_version+1,data_generation=data_generation+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id");
            $user->execute([':id'=>$userId]);
            if($user->rowCount()!==1)throw new RuntimeException('User reset state could not be updated.');
            $finish=$this->conn->prepare("UPDATE fresh_start_operations SET status='completed',completed_at=CURRENT_TIMESTAMP,confirmation_hash=NULL WHERE operation_id=:id AND user_id=:user_id");
            $finish->execute([':id'=>$operationId,':user_id'=>$userId]);
            $versions=$this->conn->prepare('SELECT token_version,data_generation FROM users WHERE id=:id');
            $versions->execute([':id'=>$userId]);$versions=$versions->fetch(PDO::FETCH_ASSOC);
            $this->conn->commit();
        } catch(Throwable $e){
            if($this->conn->inTransaction())$this->conn->rollBack();
            throw $e;
        } finally {
            try{$release=$this->conn->prepare('SELECT RELEASE_LOCK(:name)');$release->execute([':name'=>$lockName]);}catch(Throwable $ignored){}
        }

        $cleanup=$this->retryCleanup($operationId);
        return [
            'operation_id'=>$operationId,'cleanup_status'=>$cleanup['pending']>0?'pending':'complete',
            'cleanup'=>$cleanup,'token_version'=>(int)$versions['token_version'],'data_generation'=>(int)$versions['data_generation'],
        ];
    }

    public function retryCleanup(string $operationId): array
    {
        $stmt=$this->conn->prepare("SELECT id,relative_path FROM fresh_start_file_cleanup WHERE operation_id=:id AND status<>'completed'");
        $stmt->execute([':id'=>$operationId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending=0;
        foreach($rows as $row){
            $absolute=$this->safeUploadPath((string)$row['relative_path']);
            $ok=$absolute!==null && (!is_file($absolute) || @unlink($absolute));
            $update=$this->conn->prepare("UPDATE fresh_start_file_cleanup SET status=:status,attempts=attempts+1,last_error_code=:error WHERE id=:id");
            $update->execute([':status'=>$ok?'completed':'failed',':error'=>$ok?null:'FILE_DELETE_FAILED',':id'=>$row['id']]);
            if(!$ok)$pending++;
        }
        $this->conn->prepare("DELETE FROM fresh_start_file_cleanup WHERE operation_id=:id AND status='completed'")->execute([':id'=>$operationId]);
        $status=$pending>0?'cleanup_pending':'completed';
        $this->conn->prepare("UPDATE fresh_start_operations SET status=:status WHERE operation_id=:id AND status IN ('completed','cleanup_pending')")->execute([':status'=>$status,':id'=>$operationId]);
        return ['total'=>count($rows),'pending'=>$pending];
    }

    public function status(int $userId,string $operationId):array
    {
        $stmt=$this->conn->prepare('SELECT status,completed_at FROM fresh_start_operations WHERE operation_id=:id AND user_id=:user_id LIMIT 1');
        $stmt->execute([':id'=>$operationId,':user_id'=>$userId]);$operation=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$operation)throw new FreshStartValidationException('Reset operation was not found.',404);
        $cleanup=in_array($operation['status'],['completed','cleanup_pending'],true)?$this->retryCleanup($operationId):['total'=>0,'pending'=>0];
        return ['operation_id'=>$operationId,'status'=>$cleanup['pending']>0?'cleanup_pending':$operation['status'],'cleanup'=>$cleanup,'completed_at'=>$operation['completed_at']];
    }

    public function summary(int $userId): array
    {
        $groups=[
            'transactions'=>['transactions'], 'accounts'=>['accounts'], 'categories'=>['categories','subcategories'],
            'budgets'=>['budgets'], 'goals_and_savings'=>['goals'], 'karobar'=>['people','karobar_transactions'],
            'tasks'=>['tasks'], 'recurring'=>['recurring_transactions'], 'notifications'=>['notifications','notification_events'],
            'reports_and_analysis'=>['reports','ai_analysis_history'], 'attachments'=>['attachments'], 'other'=>['activity_logs'],
        ];
        $result=[];
        foreach($groups as $label=>$tables){$total=0;foreach($tables as$table){$stmt=$this->conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE user_id=:user_id");$stmt->execute([':user_id'=>$userId]);$total+=(int)$stmt->fetchColumn();}$result[$label]=$total;}
        $result['total_records']=array_sum($result);
        return $result;
    }

    private function validOperation(int $userId,string $id,string $intent,array $statuses): array
    {
        if(!preg_match('/^[a-f0-9-]{36}$/i',$id)||$intent==='')throw new FreshStartValidationException('Reset session is invalid.',403);
        $marks=implode(',',array_fill(0,count($statuses),'?'));
        $stmt=$this->conn->prepare("SELECT * FROM fresh_start_operations WHERE operation_id=? AND user_id=? AND status IN ({$marks}) AND expires_at>CURRENT_TIMESTAMP LIMIT 1");
        $stmt->execute(array_merge([$id,$userId],$statuses));$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row||!hash_equals((string)$row['intent_hash'],hash('sha256',$intent)))throw new FreshStartValidationException('Reset session is invalid or expired.',403);
        return$row;
    }

    private function lockVerifiedOperation(int$userId,string$id,string$intent,string$confirmation):array
    {
        $stmt=$this->conn->prepare("SELECT * FROM fresh_start_operations WHERE operation_id=:id AND user_id=:user_id AND status='verified' AND expires_at>CURRENT_TIMESTAMP FOR UPDATE");
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row||!hash_equals((string)$row['intent_hash'],hash('sha256',$intent))||!hash_equals((string)$row['confirmation_hash'],hash('sha256',$confirmation)))throw new FreshStartValidationException('Final reset authorization is invalid or expired.',403);
        return$row;
    }

    private function attachmentPaths(int$userId):array
    {
        $stmt=$this->conn->prepare('SELECT file_path FROM attachments WHERE user_id=:user_id');$stmt->execute([':user_id'=>$userId]);
        return array_values(array_unique(array_filter(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)))));
    }

    private function safeUploadPath(string$relative):?string
    {
        $relative=str_replace('\\','/',$relative);$relative=preg_replace('#^/?(?:backend/)?uploads/#','',$relative);
        if($relative===''||str_contains($relative,'..')||str_starts_with($relative,'avatars/'))return null;
        $root=realpath(__DIR__.'/../uploads');if(!$root)return null;
        $candidate=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $parent=realpath(dirname($candidate));
        return $parent!==false&&str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR),strtolower($root.DIRECTORY_SEPARATOR))?$candidate:null;
    }

    private function assertTenantIntegrity(int$userId):void
    {
        $checks=[
            "SELECT 1 FROM categories child JOIN categories parent ON parent.id=child.parent_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM subcategories child JOIN categories parent ON parent.id=child.category_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN accounts parent ON parent.id IN(child.account_id,child.from_account_id,child.to_account_id) WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN categories parent ON parent.id=child.category_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN subcategories parent ON parent.id=child.subcategory_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN goals parent ON parent.id=child.goal_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN recurring_transactions parent ON parent.id=child.recurring_definition_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN karobar_transactions parent ON parent.id=child.karobar_transaction_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM transactions child JOIN transactions parent ON parent.id=child.transfer_parent_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM recurring_transactions child JOIN accounts parent ON parent.id=child.account_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM recurring_transactions child JOIN categories parent ON parent.id=child.category_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM recurring_transactions child JOIN subcategories parent ON parent.id=child.subcategory_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM budgets child JOIN categories parent ON parent.id=child.category_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM budgets child JOIN subcategories parent ON parent.id=child.subcategory_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM karobar_transactions child JOIN people parent ON parent.id=child.person_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM karobar_transactions child JOIN accounts parent ON parent.id=child.account_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM karobar_transactions child JOIN transactions parent ON parent.id IN(child.expense_transaction_id,child.income_transaction_id) WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM attachments child JOIN transactions parent ON parent.id=child.transaction_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
            "SELECT 1 FROM attachments child JOIN goals parent ON parent.id=child.goal_id WHERE NOT(child.user_id<=>:foreign_user) AND parent.user_id=:owner LIMIT 1",
        ];
        foreach($checks as$sql){$stmt=$this->conn->prepare($sql);$stmt->execute([':foreign_user'=>$userId,':owner'=>$userId]);if($stmt->fetchColumn())throw new RuntimeException('Cross-account relationship detected; reset aborted safely.');}
    }

    private function probe(string$point):void { if(is_callable($this->failureProbe))($this->failureProbe)($point); }
    private function uuid():string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
}
