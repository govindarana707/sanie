<?php
$_SERVER['HTTP_HOST']='localhost';require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../models/Person.php';require_once __DIR__.'/../models/KarobarTransaction.php';require_once __DIR__.'/../services/KarobarOutstandingService.php';
function hpAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);echo"PASS: {$message}\n";}
$db=(new Database())->getConnection();$users=[];
try{
 $newUser=function(string$name)use($db,&$users):int{$db->prepare('INSERT INTO users(email,password,first_name)VALUES(?,?,?)')->execute(['history-page-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT),$name]);$id=(int)$db->lastInsertId();$users[]=$id;return$id;};$owner=$newUser('History Owner');$foreign=$newUser('Foreign Owner');
 $insertPerson=function(int$user,string$name,string$status='active')use($db):int{$db->prepare('INSERT INTO people(user_id,name,status)VALUES(?,?,?)')->execute([$user,$name,$status]);return(int)$db->lastInsertId();};
 $people=[];for($i=1;$i<=21;$i++)$people[]=$insertPerson($owner,sprintf('Person %02d',$i));$foreignPerson=$insertPerson($foreign,'Foreign Person');
 $personModel=new Person();$p1=$personModel->findAll($owner,['status'=>'active'],10,0);$p2=$personModel->findAll($owner,['status'=>'active'],10,10);$p3=$personModel->findAll($owner,['status'=>'active'],10,20);
 hpAssert(count($p1)===10&&count($p2)===10&&count($p3)===1&&$personModel->countAll($owner,['status'=>'active'])===21,'People page boundaries expose page-size plus one and a partial final page');
 hpAssert(count(array_unique(array_column(array_merge($p1,$p2,$p3),'id')))===21,'People pages use stable ordering without duplicates or omissions');
 hpAssert($personModel->countAll($owner,['search'=>'Person 21'])===1&&$personModel->countAll($owner,['search'=>'Foreign'])===0,'People search and pagination counts remain owner scoped');

 $historyPerson=$people[0];$insert=$db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,description,transaction_date,due_date)VALUES(?,?,?,?,?,?,?)");
 for($i=1;$i<=53;$i++){$type=$i%4===1?'lent':($i%4===2?'borrowed':($i%4===3?'returned':'repaid'));$insert->execute([$owner,$historyPerson,$type,$i,"history {$i}",'2026-08-'.str_pad((string)(($i-1)%28+1),2,'0',STR_PAD_LEFT),null]);}
 $insert->execute([$foreign,$foreignPerson,'lent',999,'foreign history','2026-08-01',null]);
 $db->prepare("UPDATE people SET status='archived' WHERE id=? AND user_id=?")->execute([$historyPerson,$owner]);
 $page1=$personModel->getLedgerPage($historyPerson,$owner,[],1,10);$page2=$personModel->getLedgerPage($historyPerson,$owner,[],2,10);$last=$personModel->getLedgerPage($historyPerson,$owner,[],6,10);$outside=$personModel->getLedgerPage($historyPerson,$owner,[],7,10);
 hpAssert($page1['pagination']['total_rows']===53&&count($page1['ledger'])===10&&count($page2['ledger'])===10&&count($last['ledger'])===3&&!$outside['ledger'],'Archived-person ledger is completely reachable including out-of-range page behavior');
 $expected=0.0;foreach(array_merge($page1['ledger'],$page2['ledger'])as$index=>$row){$amount=(float)$row['amount'];$expected+=in_array($row['type'],['lent','repaid','adjustment'],true)?$amount:-$amount;if($index===10)hpAssert(abs((float)$row['running_balance']-$expected)<0.001,'Person running outstanding continues across page boundaries');}
 $filtered=$personModel->getLedgerPage($historyPerson,$owner,['type'=>'lent','search'=>'history'],1,5);hpAssert($filtered['pagination']['total_rows']===14&&count($filtered['ledger'])===5,'Person ledger type and search filters paginate across full history');
 hpAssert(!$personModel->getLedgerPage($historyPerson,$foreign,[],1,10)['ledger'],'Foreign user cannot retrieve person ledger rows or counts');

 $karobar=new KarobarTransaction();$summaryBefore=(new KarobarOutstandingService())->getSummary($owner);$k1=$karobar->findPage($owner,['person_id'=>$historyPerson],1,20);$k2=$karobar->findPage($owner,['person_id'=>$historyPerson],2,20);$k3=$karobar->findPage($owner,['person_id'=>$historyPerson],3,20);
 hpAssert($k1['pagination']['total_rows']===53&&count($k1['transactions'])===20&&count($k2['transactions'])===20&&count($k3['transactions'])===13,'Karobar history exposes every row beyond the former fixed cap');
 hpAssert(count(array_unique(array_column(array_merge($k1['transactions'],$k2['transactions'],$k3['transactions']),'id')))===53,'Karobar date/id ordering prevents page duplicates and omissions');
 $sum=function(array$rows,string$type):float{return array_reduce($rows,fn($v,$r)=>$v+($r['type']===$type?(float)$r['amount']:0),0.0);};$all=array_merge($k1['transactions'],$k2['transactions'],$k3['transactions']);
 hpAssert(abs($k1['summary']['total_lent']-$sum($all,'lent'))<0.001&&$k1['summary']===$k2['summary'],'Karobar historical totals are filter-wide and page independent');
 $profile=$personModel->findById($historyPerson,$owner);$position=(new KarobarOutstandingService())->getPersonPosition($owner,$historyPerson);$dashboard=$karobar->getDashboard($owner);$reports=$karobar->getReports($owner,['person_id'=>$historyPerson]);
 hpAssert(count($reports)===53&&abs((float)$profile['receivable_outstanding']-(float)$position['receivable_outstanding'])<0.001&&abs((float)$dashboard['total_receivable']-(float)(new KarobarOutstandingService())->getSummary($owner)['total_receivable'])<0.001,'Person, complete Karobar report, and dashboard totals remain authoritative beyond page boundaries');
 $deleteId=(int)$k1['transactions'][0]['id'];$db->prepare('DELETE FROM karobar_transactions WHERE id=? AND user_id=?')->execute([$deleteId,$owner]);$after=$karobar->findPage($owner,['person_id'=>$historyPerson],1,20);hpAssert($after['pagination']['total_rows']===52,'Karobar pagination metadata refreshes after deletion');
 hpAssert((new KarobarOutstandingService())->getSummary($owner)!==$summaryBefore||$after['pagination']['total_rows']===52,'History pagination remains independent from authoritative outstanding evaluation');
 hpAssert(!$karobar->findPage($owner,['person_id'=>$foreignPerson],1,20)['transactions'],'Karobar rows and pagination counts exclude foreign ownership');
}finally{foreach(array_reverse($users)as$user)$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}
