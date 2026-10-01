<?php
$_SERVER['HTTP_HOST']='localhost';require_once __DIR__.'/../config/database.php';
$db=(new Database())->getConnection();$user=null;$failed=false;$barrier=null;
try{
 if(!function_exists('proc_open'))throw new RuntimeException('proc_open unavailable');
 $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Notification Concurrency')")->execute(['notification-concurrency-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO people(user_id,name,status)VALUES(?,'Concurrent Person','active')")->execute([$user]);$person=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,transaction_date,due_date)VALUES(?,?,'lent',100,'2026-08-01','2026-08-25')")->execute([$user,$person]);$origin=(int)$db->lastInsertId();
 $barrier=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sanie-notification-'.bin2hex(random_bytes(8)).'.start';$worker=__DIR__.DIRECTORY_SEPARATOR.'NotificationConcurrencyWorker.php';$processes=[];
 for($i=0;$i<2;$i++){$pipes=[];$p=proc_open([PHP_BINARY,$worker,(string)$user,(string)$origin,$barrier],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Unable to start worker');$processes[]=[$p,$pipes];}
 touch($barrier);foreach($processes as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException("Worker failed: {$err} {$out}");}
 $s=$db->prepare("SELECT COUNT(*) FROM notification_events WHERE user_id=? AND event_key=?");$s->execute([$user,"karobar:{$origin}:2026-08-25:due"]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Concurrent evaluators did not create exactly one event claim');
 $s=$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND reference_type='karobar' AND reference_id=? AND type='karobar_due'");$s->execute([$user,$origin]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Concurrent evaluators did not create exactly one visible notification');
 echo"PASS: concurrent evaluators create one durable claim and one visible notification\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}finally{if($barrier&&file_exists($barrier))unlink($barrier);if($user)$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}exit($failed?1:0);
