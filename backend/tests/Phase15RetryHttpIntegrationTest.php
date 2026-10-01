<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/jwt.php';

function p15h(string $method,string $path,int $user,?array $body=null):array{$base=rtrim(getenv('TEST_API_BASE')?:'http://localhost/sanie/backend/api','/');$token=JWT::encode(['user_id'=>$user]);$options=['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",'ignore_errors'=>true,'timeout'=>10];if($body!==null)$options['content']=json_encode($body);$raw=file_get_contents("{$base}/{$path}",false,stream_context_create(['http'=>$options]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$match);return[(int)($match[1]??0),json_decode($raw?:'null',true)];}
function p15hAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$db=(new Database())->getConnection();$user=null;$failed=false;
try{
 $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Phase 15 HTTP')")->execute(['phase15-http-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'HTTP Cash','cash',500,500,1,0)")->execute([$user]);$account=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'HTTP Expense','expense','active',0)")->execute([$user]);$category=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,'HTTP Person','person')")->execute([$user]);$person=(int)$db->lastInsertId();

 $ordinary=['type'=>'expense','amount'=>'50.00','date'=>'2026-08-25','account_id'=>$account,'category_id'=>$category,'description'=>'HTTP lost response','payment_method'=>'cash','client_request_id'=>'req_phase15_http_ordinary'];
 [$firstStatus,$first]=p15h('POST','transactions',$user,$ordinary);[$retryStatus,$retry]=p15h('POST','transactions',$user,$ordinary);
 p15hAssert($firstStatus===201&&$retryStatus===200&&(int)$first['data']['id']===(int)$retry['data']['id'],'ordinary HTTP replay response is not safe');
 p15hAssert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$user} AND client_request_id='req_phase15_http_ordinary'")->fetchColumn()===1,'ordinary HTTP retry duplicated');
 $txId=(int)$first['data']['id'];p15hAssert((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$user} AND reference_type='transaction' AND reference_id={$txId} AND type='transaction_added'")->fetchColumn()===1,'ordinary replay duplicated notification');
 [$status]=p15h('POST','transactions',$user,array_merge($ordinary,['amount'=>'51.00']));p15hAssert($status===409,'ordinary payload mismatch did not conflict');
 $without=$ordinary;unset($without['client_request_id']);[$status]=p15h('POST','transactions',$user,$without);p15hAssert($status===422,'ordinary create without identity was accepted');
 $correctable=array_merge($ordinary,['amount'=>'1.999','client_request_id'=>'req_phase15_http_corrected']);[$status]=p15h('POST','transactions',$user,$correctable);p15hAssert($status===422,'invalid first attempt was accepted');[$status]=p15h('POST','transactions',$user,array_merge($correctable,['amount'=>'1.99']));p15hAssert($status===201,'corrected retry with unused identity failed');

 $origin=['person_id'=>$person,'account_id'=>$account,'type'=>'lent','amount'=>'75.00','transaction_date'=>'2026-08-25','description'=>'HTTP Karobar retry','client_request_id'=>'phase15-http-origin'];
 [$originStatus,$created]=p15h('POST','karobar',$user,$origin);[$originRetryStatus,$originRetry]=p15h('POST','karobar',$user,$origin);$originId=(int)$created['data']['id'];
 p15hAssert($originStatus===201&&$originRetryStatus===201&&$originId===(int)$originRetry['data']['id'],'Karobar HTTP replay response is not safe');
 p15hAssert((int)$db->query("SELECT COUNT(*) FROM karobar_transactions WHERE user_id={$user} AND client_request_id='phase15-http-origin'")->fetchColumn()===1,'Karobar HTTP retry duplicated');
 p15hAssert((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$user} AND reference_type='karobar' AND reference_id={$originId}")->fetchColumn()===1,'Karobar replay duplicated notification');
 [$status]=p15h('POST','karobar',$user,array_merge($origin,['amount'=>'76.00']));p15hAssert($status===409,'Karobar payload mismatch did not conflict');$missing=$origin;unset($missing['client_request_id']);[$status]=p15h('POST','karobar',$user,$missing);p15hAssert($status===422,'Karobar origin without identity was accepted');

 $accountPayload=['name'=>'Negative Credit','type'=>'credit_card','opening_balance'=>'-125.50','is_active'=>true];[$status,$accountBody]=p15h('POST','accounts',$user,$accountPayload);p15hAssert($status===201&&(float)$accountBody['data']['opening_balance']===-125.5,'valid signed opening balance failed');$accountCount=(int)$db->query("SELECT COUNT(*) FROM accounts WHERE user_id={$user}")->fetchColumn();
 foreach(['1.999','0.001','abc','NaN','Infinity','1e2','1000000000000.00']as$value){[$status]=p15h('POST','accounts',$user,array_merge($accountPayload,['name'=>'Invalid '.$value,'opening_balance'=>$value]));p15hAssert($status===422,'invalid opening balance accepted: '.$value);}
 p15hAssert((int)$db->query("SELECT COUNT(*) FROM accounts WHERE user_id={$user}")->fetchColumn()===$accountCount,'invalid account create persisted');$newAccount=(int)$accountBody['data']['id'];[$status]=p15h('PUT','accounts/'.$newAccount,$user,['opening_balance'=>'5.555']);p15hAssert($status===422,'invalid opening edit accepted');p15hAssert((float)$db->query("SELECT opening_balance FROM accounts WHERE id={$newAccount}")->fetchColumn()===-125.5,'invalid opening edit changed balance');
 $txCountBefore=(int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$user}")->fetchColumn();$karobarCountBefore=(int)$db->query("SELECT COUNT(*) FROM karobar_transactions WHERE user_id={$user}")->fetchColumn();[$status,$edited]=p15h('PUT','accounts/'.$account,$user,['opening_balance'=>'600.00']);p15hAssert($status===200,'valid opening edit failed');p15hAssert(abs((float)$edited['data']['calculated_balance']-473.01)<0.001,'opening edit did not recalculate authoritative balance');p15hAssert(abs((float)$db->query("SELECT balance FROM accounts WHERE id={$account}")->fetchColumn()-473.01)<0.001,'opening edit did not synchronize cached balance');p15hAssert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$user}")->fetchColumn()===$txCountBefore&&(int)$db->query("SELECT COUNT(*) FROM karobar_transactions WHERE user_id={$user}")->fetchColumn()===$karobarCountBefore,'opening edit rewrote financial history');
 echo"PASS: Phase 15 HTTP retry responses, mismatch conflicts, notification dedupe, and opening validation are correct\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");}finally{if($user){foreach(['notification_events','notifications','transactions','karobar_transactions','people','categories','accounts']as$table){try{$db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$user]);}catch(Throwable$ignored){}}try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}catch(Throwable$ignored){}}}exit($failed?1:0);
