<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/KarobarOutstandingService.php';
require_once __DIR__.'/../services/KarobarService.php';
require_once __DIR__.'/../models/Person.php';

function koAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function koClose(float $expected,float $actual,string $message):void{if(abs($expected-$actual)>.001)throw new RuntimeException("$message: expected $expected, got $actual");}

$db=(new Database())->getConnection();
if(!$db){fwrite(STDERR,"FAIL: database unavailable\n");exit(1);}
$users=[];$passed=0;$failed=0;
$run=function(string $name,callable $test)use(&$passed,&$failed):void{try{$test();$passed++;echo "PASS: $name\n";}catch(Throwable $e){$failed++;fwrite(STDERR,"FAIL: $name - {$e->getMessage()}\n");}};

try{
    foreach(['owner','foreign']as$label){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Outstanding Test')");$s->execute(['ko-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$users[$label]=(int)$db->lastInsertId();}
    $person=function(int$user,string$name)use($db):int{$s=$db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,?,'person')");$s->execute([$user,$name]);return(int)$db->lastInsertId();};
    $tx=function(int$user,int$person,string$type,float$amount,string$date,?string$due=null)use($db):int{$s=$db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,transaction_date,due_date,description)VALUES(?,?,?,?,?,?,?)");$s->execute([$user,$person,$type,$amount,$date,$due,'Phase 7 fixture']);return(int)$db->lastInsertId();};
    $owner=$users['owner'];$foreign=$users['foreign'];$past=date('Y-m-d',strtotime('-10 days'));$today=date('Y-m-d');$future=date('Y-m-d',strtotime('+10 days'));
    $out=new KarobarOutstandingService();$service=new KarobarService();

    $run('partial repayment updates origin, dashboard, report and person equally',function()use($person,$tx,$owner,$past,$today,$out,$service):void{
        $p=$person($owner,'Partial payable');$tx($owner,$p,'borrowed',12000,$past,$past);$tx($owner,$p,'repaid',5000,$today);
        $origin=$out->getOrigins($owner,['person_id'=>$p,'direction'=>'payable'])[0];$position=$out->getPersonPosition($owner,$p);$report=$service->getCreditReports($owner,['person_id'=>$p])['payable_report'];$dash=$service->getDashboardData($owner);
        koClose(7000,$origin['outstanding_amount'],'origin remaining');koAssert($origin['is_overdue'],'partial origin should be overdue');koClose(7000,$position['payable_outstanding'],'person payable');koClose(7000,$report[0]['outstanding_amount'],'report payable');
        $dashPerson=array_values(array_filter($dash['people_balances'],fn($x)=>(int)$x['id']===$p))[0];koClose(7000,$dashPerson['payable_outstanding'],'dashboard person payable');koClose(7000,$dashPerson['overdue_payable'],'dashboard overdue');
    });

    $run('full settlement disappears from active overdue but remains historical',function()use($person,$tx,$owner,$past,$today,$out):void{
        $p=$person($owner,'Settled payable');$tx($owner,$p,'borrowed',6000,$past,$past);$tx($owner,$p,'repaid',6000,$today);
        koAssert(count($out->getOrigins($owner,['person_id'=>$p]))===0,'settled origin remained active');$history=$out->getOrigins($owner,['person_id'=>$p,'include_settled'=>true]);koAssert(count($history)===1&&$history[0]['is_settled']&&!$history[0]['is_overdue'],'settled history flags wrong');
    });

    $run('receivable partial receipt uses remaining amount',function()use($person,$tx,$owner,$past,$today,$out):void{
        $p=$person($owner,'Partial receivable');$tx($owner,$p,'lent',10000,$past,$past);$tx($owner,$p,'returned',4000,$today);$o=$out->getOrigins($owner,['person_id'=>$p,'direction'=>'receivable'])[0];koClose(6000,$o['outstanding_amount'],'receivable remaining');koAssert($o['is_overdue'],'receivable overdue flag');
    });

    $run('due today, future and null are not overdue',function()use($person,$tx,$owner,$today,$future,$out):void{
        $p=$person($owner,'Date boundaries');$tx($owner,$p,'borrowed',100,$today,$today);$tx($owner,$p,'borrowed',200,$today,$future);$tx($owner,$p,'borrowed',300,$today,null);foreach($out->getOrigins($owner,['person_id'=>$p])as$o)koAssert(!$o['is_overdue'],'non-past due date marked overdue');
    });

    $run('oldest-origin-first allocation is deterministic',function()use($person,$tx,$owner,$past,$future,$today,$out):void{
        $p=$person($owner,'FIFO origins');$first=$tx($owner,$p,'borrowed',10000,$past,$past);$second=$tx($owner,$p,'borrowed',5000,$today,$future);$tx($owner,$p,'repaid',6000,$today);$rows=$out->getOrigins($owner,['person_id'=>$p]);koAssert((int)$rows[0]['id']===$first&&(int)$rows[1]['id']===$second,'origin order changed');koClose(4000,$rows[0]['outstanding_amount'],'first remaining');koClose(5000,$rows[1]['outstanding_amount'],'second remaining');koAssert($rows[0]['is_overdue']&&!$rows[1]['is_overdue'],'FIFO overdue allocation wrong');
    });

    $run('payment edit and delete immediately recalculate',function()use($db,$person,$tx,$owner,$past,$today,$out):void{
        $p=$person($owner,'Mutable payment');$tx($owner,$p,'borrowed',10000,$past,$past);$payment=$tx($owner,$p,'repaid',2000,$today);koClose(8000,$out->getPersonPosition($owner,$p)['payable_outstanding'],'initial edit fixture');$s=$db->prepare('UPDATE karobar_transactions SET amount=5000 WHERE id=? AND user_id=?');$s->execute([$payment,$owner]);koClose(5000,$out->getPersonPosition($owner,$p)['payable_outstanding'],'edited payment');$s=$db->prepare('DELETE FROM karobar_transactions WHERE id=? AND user_id=?');$s->execute([$payment,$owner]);koClose(10000,$out->getPersonPosition($owner,$p)['payable_outstanding'],'deleted payment');
    });

    $run('opposing positions remain separate and do not falsely settle',function()use($person,$tx,$owner,$past,$out):void{
        $p=$person($owner,'Two directions');$tx($owner,$p,'borrowed',5000,$past,$past);$tx($owner,$p,'lent',5000,$past,$past);$position=$out->getPersonPosition($owner,$p);koClose(5000,$position['payable_outstanding'],'separate payable');koClose(5000,$position['receivable_outstanding'],'separate receivable');koAssert($position['active_count']===2&&$position['overdue_count']===2,'net zero was treated as settled');
    });

    $run('foreign user data is excluded',function()use($person,$tx,$owner,$foreign,$past,$out):void{
        $p=$person($foreign,'Foreign debt');$tx($foreign,$p,'borrowed',999999,$past,$past);$summary=$out->getSummary($owner);koAssert($summary['total_payable']<999999,'foreign payable leaked');koAssert(count(array_filter($summary['people_balances'],fn($x)=>(int)$x['id']===$p))===0,'foreign person leaked');
    });

    $run('credit purchase expense remains while repayment reduces overdue payable',function()use($db,$person,$owner,$past,$today,$service,$out):void{
        $p=$person($owner,'Credit vendor');$s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Phase 7 Credit','expense','active',0)");$s->execute([$owner]);$category=(int)$db->lastInsertId();$s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'Phase 7 Cash','cash',20000,20000,1,0)");$s->execute([$owner]);$account=(int)$db->lastInsertId();
        $purchase=$service->processCreditPurchase(['amount'=>5000,'creditor_id'=>$p,'category_id'=>$category,'date'=>$past,'description'=>'Credit stock','due_date'=>$past,'client_request_id'=>'ko-credit-'.bin2hex(random_bytes(5))],$owner);$service->processRepayment(['person_id'=>$p,'account_id'=>$account,'amount'=>2000,'transaction_date'=>$today,'client_request_id'=>'ko-repay-'.bin2hex(random_bytes(5))],$owner);$position=$out->getPersonPosition($owner,$p);koClose(3000,$position['payable_outstanding'],'credit payable remaining');koClose(3000,$position['overdue_payable'],'credit overdue remaining');$s=$db->prepare("SELECT amount FROM transactions WHERE id=? AND user_id=? AND type='expense'");$s->execute([$purchase['transaction_id'],$owner]);koClose(5000,(float)$s->fetchColumn(),'historical expense changed');
    });
}finally{foreach(array_reverse($users)as$id){$s=$db->prepare('DELETE FROM users WHERE id=?');$s->execute([$id]);}}

echo "Karobar outstanding integrity: $passed passed, $failed failed.\n";
exit($failed?1:0);
