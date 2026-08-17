<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/jwt.php';

function bcHttp(string $method,string $path,int $user,?array $body=null):array{
    $base=rtrim(getenv('TEST_API_BASE')?:'http://localhost/sanie/backend/api','/');$token=JWT::encode(['user_id'=>$user]);
    $options=['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",'ignore_errors'=>true,'timeout'=>10];if($body!==null)$options['content']=json_encode($body);
    $raw=file_get_contents("{$base}/{$path}",false,stream_context_create(['http'=>$options]));$line=$http_response_header[0]??'';preg_match('/\s(\d{3})\s/',$line,$m);return[(int)($m[1]??0),json_decode($raw?:'null',true)];
}
function bchAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$db=(new Database())->getConnection();$users=[];$failed=false;
try{
    foreach(['owner','foreign']as$label){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Budget HTTP')");$s->execute(['bch-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$users[$label]=(int)$db->lastInsertId();}
    $owner=$users['owner'];$foreign=$users['foreign'];
    $category=function(int$user,string$name)use($db):int{$s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,?,'expense','active',0)");$s->execute([$user,$name]);return(int)$db->lastInsertId();};
    $subcategory=function(int$user,int$parent,string$name)use($db):int{$s=$db->prepare("INSERT INTO subcategories(user_id,category_id,name,status)VALUES(?,?,?,'active')");$s->execute([$user,$parent,$name]);return(int)$db->lastInsertId();};
    $food=$category($owner,'HTTP Food');$transport=$category($owner,'HTTP Transport');$foreignCategory=$category($foreign,'HTTP Foreign');$restaurant=$subcategory($owner,$food,'HTTP Restaurant');$fuel=$subcategory($owner,$transport,'HTTP Fuel');$foreignChild=$subcategory($foreign,$foreignCategory,'HTTP Foreign Child');
    $base=['name'=>'HTTP Budget','amount'=>5000,'period'=>'monthly','start_date'=>'2026-08-01','end_date'=>'2026-08-31','category_id'=>$food,'subcategory_id'=>$restaurant];
    [$status,$created]=bcHttp('POST','budgets',$owner,$base);bchAssert($status===201,'owned subcategory budget create failed');$id=(int)$created['data']['id'];bchAssert($created['data']['scope_label']==='HTTP Food / HTTP Restaurant','create returned wrong scope label');bchAssert((float)$created['data']['used']===0.0&&(float)$created['data']['remaining']===5000.0,'create returned incoherent progress');
    foreach([
        array_merge($base,['category_id'=>$foreignCategory,'subcategory_id'=>null]),
        array_merge($base,['category_id'=>$food,'subcategory_id'=>$foreignChild]),
        array_merge($base,['category_id'=>$food,'subcategory_id'=>$fuel]),
        array_merge($base,['category_id'=>$food,'subcategory_id'=>$food]),
    ]as$invalid){[$status]=bcHttp('POST','budgets',$owner,$invalid);bchAssert($status===422,'invalid classification was not rejected');}
    [$status,$updated]=bcHttp('PUT','budgets/'.$id,$owner,['category_id'=>$transport]);bchAssert($status===200,'owned scope edit failed');bchAssert($updated['data']['subcategory_id']===null,'scope edit retained stale child');bchAssert($updated['data']['scope_label']==='HTTP Transport','scope edit returned wrong label');
    [$status]=bcHttp('PUT','budgets/'.$id,$foreign,['amount'=>1]);bchAssert($status===404,'foreign budget update did not use tenant-isolated 404');
    [$status]=bcHttp('DELETE','budgets/'.$id,$foreign);bchAssert($status===404,'foreign budget delete did not use tenant-isolated 404');
    $s=$db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,description)VALUES(?,'expense',750,'2026-08-15',?,'Budget HTTP cache fixture')");$s->execute([$owner,$transport]);
    [, $list]=bcHttp('GET','budgets',$owner);$row=array_values(array_filter($list['data'],fn($b)=>(int)$b['id']===$id))[0]??null;bchAssert($row&&(float)$row['used']===750.0&&(float)$row['remaining']===4250.0,'budget list did not refresh authoritative usage');
    [, $dashboard]=bcHttp('GET','dashboard?start_date=2026-08-01&end_date=2026-08-31',$owner);$dashRow=array_values(array_filter($dashboard['data']['budget_progress'],fn($b)=>(int)$b['budget_id']===$id))[0]??null;bchAssert($dashRow&&(float)$dashRow['spent']===750.0&&$dashRow['budget']['scope_label']==='HTTP Transport','dashboard budget usage or label diverged');
    [, $report]=bcHttp('GET','reports/budget-health',$owner);$reportRow=array_values(array_filter($report['data']['budgets'],fn($b)=>(int)$b['id']===$id))[0]??null;bchAssert($reportRow&&(float)$reportRow['spent']===750.0&&(float)$reportRow['remaining']===4250.0&&$reportRow['scope_label']==='HTTP Transport','report budget usage or label diverged');
    [$status]=bcHttp('DELETE','budgets/'.$id,$owner);bchAssert($status===200,'owned budget delete failed');bchAssert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE description='Budget HTTP cache fixture'")->fetchColumn()===1,'budget delete removed transaction history');
    echo "PASS: budget HTTP create/edit/delete validates scope and tenant ownership and returns coherent live progress\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}finally{foreach(array_reverse($users)as$id){try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}catch(Throwable$ignored){}}}
exit($failed?1:0);
