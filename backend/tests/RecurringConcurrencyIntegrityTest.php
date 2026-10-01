<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/RecurringTransactionService.php';

$db=(new Database())->getConnection();$user=null;$failed=false;
try{
    $today=(new RecurrenceCalculator())->today();
    $tomorrow=(new DateTimeImmutable($today,new DateTimeZone(RecurrenceCalculator::TIMEZONE)))->modify('+1 day')->format('Y-m-d');
    if(!function_exists('proc_open'))throw new RuntimeException('proc_open unavailable');
    $s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,?)");$s->execute(['recurring-concurrency-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT),'Recurring Concurrency']);$user=(int)$db->lastInsertId();
    $s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'Concurrency Cash','cash',1000,1000,1,0)");$s->execute([$user]);$account=(int)$db->lastInsertId();
    $s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Concurrency Expense','expense','active',0)");$s->execute([$user]);$category=(int)$db->lastInsertId();
    $definition=(new RecurringTransactionService())->create($user,['account_id'=>$account,'category_id'=>$category,'amount'=>100,'type'=>'expense','frequency'=>'daily','start_date'=>$today,'description'=>'Concurrency']);
    $id=(int)$definition['id'];$barrier=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sanie-recurring-'.bin2hex(random_bytes(8)).'.start';$worker=__DIR__.DIRECTORY_SEPARATOR.'RecurringProcessorConcurrencyWorker.php';$processes=[];
    for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,$worker,(string)$id,(string)$user,$barrier],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Unable to start worker');$processes[]=[$process,$pipes];}
    touch($barrier);$results=[];
    foreach($processes as[$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$decoded=json_decode($stdout,true);if($exit!==0||!is_array($decoded))throw new RuntimeException("Worker failed: {$stderr} {$stdout}");$results[]=$decoded;}
    if(file_exists($barrier))unlink($barrier);
    $s=$db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND recurring_definition_id=? AND recurring_occurrence_date=?');$s->execute([$user,$id,$today]);
    if((int)$s->fetchColumn()!==1)throw new RuntimeException('Concurrent processors created a duplicate occurrence: '.json_encode($results));
    $statuses=array_column($results,'status');if(!in_array('generated',$statuses,true)||!in_array('not_due',$statuses,true))throw new RuntimeException('Concurrent outcomes were not generated/not_due: '.json_encode($results));
    $s=$db->prepare('SELECT next_occurrence FROM recurring_transactions WHERE id=?');$s->execute([$id]);if($s->fetchColumn()!==$tomorrow)throw new RuntimeException('Concurrent cursor advancement is incorrect');
    echo"PASS: concurrent processors create exactly one occurrence and advance one cursor\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}
finally{if($user){try{$db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM recurring_transactions WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM categories WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}catch(Throwable$e){fwrite(STDERR,"Cleanup warning: {$e->getMessage()}\n");}}}
exit($failed?1:0);
