<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/jwt.php';
require_once __DIR__.'/../services/RecurrenceCalculator.php';

function recurringHttp(string$method,string$path,int$userId,?array$body=null):array{$base=rtrim(getenv('TEST_API_BASE')?:'http://localhost/sanie/backend/api','/');$token=JWT::encode(['user_id'=>$userId]);$options=['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",'ignore_errors'=>true,'timeout'=>10];if($body!==null)$options['content']=json_encode($body);$raw=file_get_contents("{$base}/{$path}",false,stream_context_create(['http'=>$options]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return[(int)($m[1]??0),json_decode($raw?:'null',true)];}
function rhAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);}
$db=(new Database())->getConnection();$users=[];$failed=false;
try{
    $today=(new RecurrenceCalculator())->today();
    $todayDate=new DateTimeImmutable($today,new DateTimeZone(RecurrenceCalculator::TIMEZONE));
    $oneDayAgo=$todayDate->modify('-1 day')->format('Y-m-d');
    $twoDaysAgo=$todayDate->modify('-2 days')->format('Y-m-d');
    $threeDaysAgo=$todayDate->modify('-3 days')->format('Y-m-d');
    $futureDate=$todayDate->modify('+7 days')->format('Y-m-d');
    foreach(['owner','foreign']as$label){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,?)");$s->execute(['recurring-http-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT),'Recurring HTTP']);$users[$label]=(int)$db->lastInsertId();}
    $owner=$users['owner'];$foreign=$users['foreign'];$s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'HTTP Recurring Cash','cash',1000,1000,1,0)");$s->execute([$owner]);$account=(int)$db->lastInsertId();$s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'HTTP Recurring Expense','expense','active',0)");$s->execute([$owner]);$category=(int)$db->lastInsertId();
    $payload=['account_id'=>$account,'category_id'=>$category,'amount'=>100,'type'=>'expense','frequency'=>'daily','start_date'=>$futureDate,'description'=>'HTTP future'];
    [$status,$created]=recurringHttp('POST','recurring-transactions',$owner,$payload);rhAssert($status===201&&($created['success']??false),'owner create failed');$futureId=(int)$created['data']['id'];
    [$status,$list]=recurringHttp('GET','recurring-transactions',$owner);rhAssert($status===200&&count($list['data'])===1,'owner list failed');
    foreach([['GET',"recurring-transactions/{$futureId}",null],['PUT',"recurring-transactions/{$futureId}",['amount'=>200]],['POST',"recurring-transactions/{$futureId}/deactivate",[]],['POST',"recurring-transactions/{$futureId}/activate",['mode'=>'resume']],['DELETE',"recurring-transactions/{$futureId}",null]]as[$method,$path,$body]){[$foreignStatus]=recurringHttp($method,$path,$foreign,$body);rhAssert($foreignStatus===404,"foreign {$method} {$path} was not isolated");}
    [$status,$edited]=recurringHttp('PUT',"recurring-transactions/{$futureId}",$owner,['amount'=>125]);rhAssert($status===200&&(float)$edited['data']['amount']===125.0,'future edit failed');
    [$status]=recurringHttp('POST',"recurring-transactions/{$futureId}/deactivate",$owner,[]);rhAssert($status===200,'deactivation failed');
    [$status,$activated]=recurringHttp('POST',"recurring-transactions/{$futureId}/activate",$owner,['mode'=>'resume']);rhAssert($status===200&&$activated['data']['status']==='activated','activation failed');
    [$status]=recurringHttp('DELETE',"recurring-transactions/{$futureId}",$owner);rhAssert($status===200,'history-free deletion failed');

    $duePayload=array_merge($payload,['start_date'=>$today,'description'=>'HTTP due']);[$status,$due]=recurringHttp('POST','recurring-transactions',$owner,$duePayload);rhAssert($status===201,'due definition create failed');$dueId=(int)$due['data']['id'];
    [$status,$processed]=recurringHttp('POST','recurring-transactions/process',$owner,[]);rhAssert($status===200&&count($processed['data']['generated'])===1,'HTTP automatic process failed');
    [$status,$repeat]=recurringHttp('POST','recurring-transactions/process',$owner,[]);rhAssert($status===200&&count($repeat['data']['generated'])===0,'HTTP retry generated duplicate');
    $s=$db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND recurring_definition_id=?');$s->execute([$owner,$dueId]);rhAssert((int)$s->fetchColumn()===1,'HTTP retry did not remain exactly once');
    [$status]=recurringHttp('DELETE',"recurring-transactions/{$dueId}",$owner);rhAssert($status===409,'generated-history delete did not conflict');

    $backlogPayload=array_merge($payload,['start_date'=>$twoDaysAgo,'description'=>'HTTP backlog']);[, $backlog]=recurringHttp('POST','recurring-transactions',$owner,$backlogPayload);$backlogId=(int)$backlog['data']['id'];
    [$status,$processBacklog]=recurringHttp('POST','recurring-transactions/process',$owner,[]);rhAssert($status===200&&in_array($backlogId,array_column($processBacklog['data']['review_required'],'definition_id'),true),'HTTP backlog not flagged');
    [$status,$review]=recurringHttp('GET',"recurring-transactions/{$backlogId}/review",$owner);rhAssert($status===200&&$review['data']['occurrences']===[$twoDaysAgo,$oneDayAgo,$today],'authoritative review dates wrong');
    [$status]=recurringHttp('POST',"recurring-transactions/{$backlogId}/reconcile",$owner,['decisions'=>[['date'=>$threeDaysAgo,'action'=>'generate']]]);rhAssert($status===422,'invalid reconciliation date accepted');
    $decisions=[['date'=>$twoDaysAgo,'action'=>'generate'],['date'=>$oneDayAgo,'action'=>'skip'],['date'=>$today,'action'=>'generate']];
    [$status,$reconciled]=recurringHttp('POST',"recurring-transactions/{$backlogId}/reconcile",$owner,['decisions'=>$decisions]);rhAssert($status===200&&$reconciled['data']['status']==='reconciled','HTTP reconciliation failed');
    [$status,$retryReconcile]=recurringHttp('POST',"recurring-transactions/{$backlogId}/reconcile",$owner,['decisions'=>$decisions]);rhAssert($status===200&&$retryReconcile['data']['status']==='already_reconciled','HTTP reconciliation retry unsafe');
    [$status,$foreignProcess]=recurringHttp('POST','recurring-transactions/process',$foreign,[]);rhAssert($status===200&&array_sum($foreignProcess['data']['counts'])===0,'foreign processor affected owner data');
    echo"PASS: recurring CRUD, ownership, processor retry, lifecycle conflict, and reconciliation HTTP contracts\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}
finally{foreach(array_reverse($users)as$id){try{$db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM recurring_transactions WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM categories WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}catch(Throwable$e){fwrite(STDERR,"Cleanup warning: {$e->getMessage()}\n");}}}
exit($failed?1:0);
