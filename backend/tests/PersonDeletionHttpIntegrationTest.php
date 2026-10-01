<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/jwt.php';

function pdHttp(string$method,string$path,int$user,?array$body=null):array{
    $base=rtrim(getenv('TEST_API_BASE')?:'http://localhost/sanie/backend/api','/');$token=JWT::encode(['user_id'=>$user]);
    $options=['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",'ignore_errors'=>true,'timeout'=>10];if($body!==null)$options['content']=json_encode($body);
    $raw=file_get_contents("$base/$path",false,stream_context_create(['http'=>$options]));$line=$http_response_header[0]??'';preg_match('/\s(\d{3})\s/',$line,$m);return[(int)($m[1]??0),json_decode($raw?:'null',true)];
}
function pdhAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);}

$db=(new Database())->getConnection();$users=[];$failed=false;
try{
    foreach(['owner','foreign']as$label){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Person HTTP')");$s->execute(['pdh-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$users[$label]=(int)$db->lastInsertId();}
    $person=function(int$user,string$name)use($db):int{$s=$db->prepare("INSERT INTO people(user_id,name,type,status)VALUES(?,?,'person','active')");$s->execute([$user,$name]);return(int)$db->lastInsertId();};$owner=$users['owner'];$foreign=$users['foreign'];$s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'HTTP Cash','cash',1000,1000,1,1)");$s->execute([$owner]);$account=(int)$db->lastInsertId();
    $empty=$person($owner,'HTTP Empty');[$status,$body]=pdHttp('DELETE','people/'.$empty,$owner);pdhAssert($status===200&&$body['data']['action']==='deleted','no-history DELETE did not report hard deletion');pdhAssert((int)$db->query("SELECT COUNT(*) FROM people WHERE id=$empty")->fetchColumn()===0,'no-history person remained');

    $history=$person($owner,'HTTP History');$s=$db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,transaction_date,due_date)VALUES(?,?,'borrowed',10000,?,?)");$past=date('Y-m-d',strtotime('-5 days'));$s->execute([$owner,$history,$past,$past]);
    [$status,$body]=pdHttp('DELETE','people/'.$history,$owner);pdhAssert($status===200&&$body['data']['action']==='archived','history DELETE did not report archival');pdhAssert(stripos($body['message'],'preserved')!==false,'archive response did not explain preservation');pdhAssert((int)$db->query("SELECT COUNT(*) FROM karobar_transactions WHERE person_id=$history")->fetchColumn()===1,'HTTP delete removed financial history');
    [, $active]=pdHttp('GET','people?status=active',$owner);pdhAssert(count(array_filter($active['data']['people'],fn($p)=>(int)$p['id']===$history))===0,'archived person remained in active selector response');
    [, $archived]=pdHttp('GET','people?status=archived',$owner);pdhAssert(count(array_filter($archived['data']['people'],fn($p)=>(int)$p['id']===$history))===1,'archived person missing from archived response');
    [, $ledger]=pdHttp('GET','people/'.$history.'/ledger',$owner);pdhAssert(($ledger['success']??false)&&count($ledger['data']['ledger'])===1,'archived historical ledger is not resolvable');
    [, $dashboard]=pdHttp('GET','karobar/dashboard',$owner);pdhAssert(abs((float)$dashboard['data']['total_payable']-10000)<.001,'archive changed dashboard outstanding');
    [$status,$blocked]=pdHttp('POST','karobar',$owner,['person_id'=>$history,'type'=>'borrowed','amount'=>1,'account_id'=>$account,'transaction_date'=>date('Y-m-d'),'client_request_id'=>'person-archived-block']);pdhAssert($status===422&&stripos($blocked['message']??'','archived')!==false,'archived person accepted new HTTP financial activity');

    [$status,$restored]=pdHttp('PUT','people/'.$history,$owner,['status'=>'active']);pdhAssert($status===200&&$restored['data']['status']==='active','owned restore failed');[, $activeAgain]=pdHttp('GET','people?status=active',$owner);pdhAssert(count(array_filter($activeAgain['data']['people'],fn($p)=>(int)$p['id']===$history))===1,'restored person did not return to active selector response');

    $foreignPerson=$person($foreign,'HTTP Foreign');[$status]=pdHttp('DELETE','people/'.$foreignPerson,$owner);pdhAssert($status===404,'foreign delete did not preserve tenant-isolated 404');pdhAssert((int)$db->query("SELECT COUNT(*) FROM people WHERE id=$foreignPerson")->fetchColumn()===1,'foreign person was deleted');
    echo"PASS: person removal HTTP contract distinguishes delete/archive, preserves history, filters selectors, restores, and enforces ownership\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}finally{foreach(array_reverse($users)as$id){try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}catch(Throwable$ignored){}}}
exit($failed?1:0);
