<?php
$_SERVER['HTTP_HOST']='localhost';require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../includes/jwt.php';
function statementHttp(string $path,int $uid):array{$base=rtrim(getenv('TEST_API_BASE')?:'http://localhost/sanie/backend/api','/');$token=JWT::encode(['user_id'=>$uid]);$raw=file_get_contents("{$base}/{$path}",false,stream_context_create(['http'=>['method'=>'GET','header'=>"Authorization: Bearer {$token}\r\n",'ignore_errors'=>true,'timeout'=>10]]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return[(int)($m[1]??0),json_decode($raw?:'null',true)];}
$db=(new Database())->getConnection();$users=[];
try{foreach(['owner','foreign']as$l){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Statement HTTP')");$s->execute(['statement-http-'.$l.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$users[$l]=(int)$db->lastInsertId();}
$s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'HTTP Statement','cash',10000,10000,1,0)");$s->execute([$users['owner']]);$account=(int)$db->lastInsertId();
$s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'HTTP Income','income','active',0)");$s->execute([$users['owner']]);$category=(int)$db->lastInsertId();
$s=$db->prepare("INSERT INTO transactions(user_id,account_id,category_id,amount,type,date,description)VALUES(?,?,?,100,'income','2026-08-01','HTTP row')");for($i=0;$i<12;$i++)$s->execute([$users['owner'],$account,$category]);
[$status,$body]=statementHttp("accounts/{$account}/statement?start_date=2026-08-01&end_date=2026-08-01&page=2&limit=5&search=HTTP",$users['owner']);
if($status!==200||!($body['success']??false))throw new RuntimeException("Statement returned HTTP {$status}");$data=$body['data'];
foreach(['opening_balance','page_opening_balance','closing_balance','money_in','money_out','net_change','pagination','statement']as$key)if(!array_key_exists($key,$data))throw new RuntimeException("Response omitted {$key}");
if((int)$data['pagination']['page']!==2||(int)$data['pagination']['total_rows']!==12)throw new RuntimeException('Pagination metadata is incorrect');
if(abs((float)$data['page_opening_balance']-10500)>0.001||abs((float)$data['statement'][1]['running_balance']-10600)>0.001)throw new RuntimeException('Page-two running balance reset');
[$foreignStatus]=statementHttp("accounts/{$account}/statement",$users['foreign']);if($foreignStatus!==404)throw new RuntimeException("Foreign account returned HTTP {$foreignStatus}");
echo "PASS: account statement HTTP contract preserves page continuity and ownership\n";
}catch(Throwable$e){fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");$failed=true;}finally{foreach($users as$uid){foreach(['transactions','categories','accounts']as$t){try{$db->prepare("DELETE FROM {$t} WHERE user_id=?")->execute([$uid]);}catch(Throwable$ignored){}}try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);}catch(Throwable$ignored){}}}exit(!empty($failed)?1:0);
