<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../models/Transaction.php';
require_once __DIR__.'/../services/BalanceService.php';
require_once __DIR__.'/../services/AccountingService.php';
require_once __DIR__.'/../services/KarobarService.php';

function stAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function stClose(float $expected,float $actual,string $message):void{if(abs($expected-$actual)>0.001)throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");}

$db=(new Database())->getConnection(); $users=[]; $passed=0; $failed=0;
$run=function(string $name,callable $test)use(&$passed,&$failed):void{try{$test();$passed++;echo "PASS: {$name}\n";}catch(Throwable $e){$failed++;fwrite(STDERR,"FAIL: {$name} - {$e->getMessage()}\n");}};
try{
    foreach(['owner','foreign'] as $label){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Statement Test')");$s->execute(['statement-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$users[$label]=(int)$db->lastInsertId();}
    $owner=$users['owner'];
    $account=function(int $user,string $name,float $opening)use($db):int{$s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,?,'cash',?,?,1,0)");$s->execute([$user,$name,$opening,$opening]);return(int)$db->lastInsertId();};
    $category=function(int $user,string $name,string $type)use($db):int{$s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,?,?,'active',0)");$s->execute([$user,$name,$type]);return(int)$db->lastInsertId();};
    $person=function(int $user,string $name)use($db):int{$s=$db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,?,'person')");$s->execute([$user,$name]);return(int)$db->lastInsertId();};
    $a=$account($owner,'Statement A',10000); $b=$account($owner,'Statement B',1000); $foreignAccount=$account($users['foreign'],'Foreign',99);
    $incomeCat=$category($owner,'Statement Income','income'); $expenseCat=$category($owner,'Statement Expense','expense'); $feeCat=$category($owner,'Statement Fee','expense');
    $vendor=$person($owner,'Statement Vendor');
    $tx=new Transaction(); $balance=new BalanceService(); $accounting=new AccountingService(); $karobar=new KarobarService();
    $createTx=function(int $accountId,string $type,float $amount,string $date,string $description,int $categoryId)use($tx,$owner):int{return(int)$tx->create(['user_id'=>$owner,'account_id'=>$accountId,'category_id'=>$categoryId,'amount'=>$amount,'type'=>$type,'payment_method'=>'cash','date'=>$date,'description'=>$description]);};

    $run('basic and date-filter openings use all prior effects',function()use($createTx,$a,$incomeCat,$expenseCat,$balance,$owner):void{
        $createTx($a,'income',5000,'2026-08-01','Aug income',$incomeCat);
        $createTx($a,'expense',2000,'2026-08-05','Aug expense',$expenseCat);
        $createTx($a,'expense',1000,'2026-08-10','Boundary expense',$expenseCat);
        $createTx($a,'income',3000,'2026-08-12','Boundary income',$incomeCat);
        $full=$balance->getAccountStatement($a,$owner,[],50,0);
        stClose(15000,$full['closing_balance'],'full closing');
        stClose(15000,(float)end($full['rows'])['running_balance'],'full last running');
        $range=$balance->getAccountStatement($a,$owner,['start_date'=>'2026-08-10','end_date'=>'2026-08-12'],50,0);
        stClose(13000,$range['opening_balance'],'filtered opening'); stClose(15000,$range['closing_balance'],'filtered closing');
        stClose(13000,(float)$range['rows'][0]['running_balance'],'opening row');
        stClose(12000,(float)$range['rows'][1]['running_balance'],'first boundary row');
        stClose(15000,(float)$range['rows'][2]['running_balance'],'last boundary row');
        stClose($range['closing_balance'],$range['opening_balance']+$range['money_in']-$range['money_out'],'range totals invariant');
    });

    $run('non-date filter preserves hidden real-balance activity',function()use($balance,$a,$owner):void{
        $r=$balance->getAccountStatement($a,$owner,['type'=>'expense'],50,0);
        $expenses=array_values(array_filter($r['rows'],fn($row)=>$row['type']==='expense'));
        stClose(13000,(float)$expenses[0]['running_balance'],'first expense actual balance');
        stClose(12000,(float)$expenses[1]['running_balance'],'second expense includes hidden income');
        stClose(15000,$r['closing_balance'],'type filter changed real closing');
    });

    $run('transfer, fee and destination directions remain correct',function()use($accounting,$owner,$a,$b,$feeCat,$balance):void{
        $accounting->createTransaction($owner,['type'=>'transfer','account_id'=>$a,'from_account_id'=>$a,'to_account_id'=>$b,'amount'=>2000,'date'=>'2026-08-13','description'=>'Statement transfer','fee_amount'=>50,'fee_category_id'=>$feeCat,'client_request_id'=>'req_statement_transfer_001']);
        $source=$balance->getAccountStatement($a,$owner,['start_date'=>'2026-08-13','end_date'=>'2026-08-13'],50,0);
        stClose(2050,$source['money_out'],'source transfer plus fee');
        $transfer=array_values(array_filter($source['rows'],fn($r)=>$r['type']==='transfer'))[0];
        stClose(2000,(float)$transfer['money_out'],'source transfer direction');
        $dest=$balance->getAccountStatement($b,$owner,[],50,0);
        stClose(2000,$dest['money_in'],'destination money in'); stClose(3000,$dest['closing_balance'],'destination closing');
    });

    $run('goal contribution is OUT but not expense',function()use($db,$owner,$a,$accounting,$balance):void{
        $s=$db->prepare("INSERT INTO goals(user_id,name,target_amount,initial_amount,current_amount,status)VALUES(?,'Statement Goal',5000,0,0,'active')");$s->execute([$owner]);$goal=(int)$db->lastInsertId();
        $accounting->createGoalContribution($goal,$owner,['account_id'=>$a,'amount'=>1000,'date'=>'2026-08-14','description'=>'Statement goal','client_request_id'=>'req_statement_goal_001']);
        $r=$balance->getAccountStatement($a,$owner,['start_date'=>'2026-08-14','end_date'=>'2026-08-14'],50,0);
        stClose(1000,$r['money_out'],'goal statement out'); stClose(0,(float)$r['summary']['total_expense'],'goal counted as expense');
    });

    $run('credit origin is excluded while repayment and receiving affect cash',function()use($karobar,$owner,$vendor,$expenseCat,$a,$db,$balance):void{
        $credit=$karobar->processCreditPurchase(['amount'=>5000,'creditor_id'=>$vendor,'category_id'=>$expenseCat,'date'=>'2026-08-15','description'=>'Statement credit','client_request_id'=>'req_statement_credit_001'],$owner);
        $before=$balance->getAccountStatement($a,$owner,['start_date'=>'2026-08-15','end_date'=>'2026-08-15'],50,0);
        stAssert($before['pagination']['total_rows']===0,'credit origin appeared in cash statement');
        $karobar->processRepayment(['person_id'=>$vendor,'account_id'=>$a,'amount'=>1500,'transaction_date'=>'2026-08-16','client_request_id'=>'req_statement_repay_001'],$owner);
        $debtor=(int)$db->lastInsertId();
        $s=$db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,'Statement Debtor','person')");$s->execute([$owner]);$debtor=(int)$db->lastInsertId();
        $s=$db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,account_id,transaction_date,description)VALUES(?,?,'lent',1500,NULL,'2026-08-01','Prior receivable')");$s->execute([$owner,$debtor]);
        $karobar->processReceiving(['person_id'=>$debtor,'account_id'=>$a,'amount'=>1000,'transaction_date'=>'2026-08-17','client_request_id'=>'req_statement_receive_001'],$owner);
        $movement=$balance->getAccountStatement($a,$owner,['start_date'=>'2026-08-16','end_date'=>'2026-08-17'],50,0);
        stClose(1000,$movement['money_in'],'receiving direction'); stClose(1500,$movement['money_out'],'repayment direction');
        $types=array_column($movement['rows'],'type'); stAssert(in_array('karobar_repaid',$types,true)&&in_array('karobar_returned',$types,true),'Karobar cash rows missing');
    });

    $run('pagination continues and identical timestamps order by source and id',function()use($createTx,$b,$incomeCat,$balance,$owner,$db):void{
        for($i=1;$i<=25;$i++)$createTx($b,'income',10,'2026-08-20','Page row '.str_pad((string)$i,2,'0',STR_PAD_LEFT),$incomeCat);
        $db->exec("UPDATE transactions SET created_at='2026-08-20 12:00:00' WHERE user_id={$owner} AND account_id={$b} AND date='2026-08-20'");
        $p1=$balance->getAccountStatement($b,$owner,['start_date'=>'2026-08-20'],10,0);$p2=$balance->getAccountStatement($b,$owner,['start_date'=>'2026-08-20'],10,10);
        stClose((float)end($p1['rows'])['running_balance'],$p2['page_opening_balance'],'page two reset');
        stClose($p2['page_opening_balance']+10,(float)$p2['rows'][1]['running_balance'],'page two first row');
        $again=$balance->getAccountStatement($b,$owner,['start_date'=>'2026-08-20'],10,10);
        stAssert(array_column($p2['rows'],'id')===array_column($again['rows'],'id'),'same timestamp order changed');
    });

    $run('filter plus pagination uses hidden events and empty ranges do not reset',function()use($balance,$b,$owner):void{
        $page=$balance->getAccountStatement($b,$owner,['start_date'=>'2026-08-20','type'=>'income','search'=>'Page row'],5,5);
        stClose($page['page_opening_balance']+10,(float)$page['rows'][1]['running_balance'],'combined filter page continuity');
        $empty=$balance->getAccountStatement($b,$owner,['start_date'=>'2027-01-01','end_date'=>'2027-01-31'],10,0);
        stClose($empty['opening_balance'],$empty['closing_balance'],'empty range reset'); stAssert(count($empty['rows'])===1,'empty range has bogus rows');
    });

    $run('manual scenario A closes at 5,950 and filtered last operations open at 6,500',function()use($account,$owner,$createTx,$incomeCat,$expenseCat,$accounting,$feeCat,$db,$karobar,$balance,$b):void{
        $manual=$account($owner,'Manual Statement',5000);
        $create=function(string$type,float$amount,string$date,string$description,int$category)use($db,$owner,$manual):void{$s=$db->prepare('INSERT INTO transactions(user_id,account_id,category_id,amount,type,date,description)VALUES(?,?,?,?,?,?,?)');$s->execute([$owner,$manual,$category,$amount,$type,$date,$description]);};
        $create('income',2000,'2026-09-01','Manual income',$incomeCat);
        $create('expense',500,'2026-09-02','Manual expense',$expenseCat);
        $accounting->createTransaction($owner,['type'=>'transfer','account_id'=>$manual,'from_account_id'=>$manual,'to_account_id'=>$b,'amount'=>1000,'date'=>'2026-09-03','description'=>'Manual transfer','fee_amount'=>50,'fee_category_id'=>$feeCat,'client_request_id'=>'req_statement_manual_transfer']);
        $s=$db->prepare("INSERT INTO goals(user_id,name,target_amount,initial_amount,current_amount,status)VALUES(?,'Manual Statement Goal',5000,0,0,'active')");$s->execute([$owner]);$goal=(int)$db->lastInsertId();
        $accounting->createGoalContribution($goal,$owner,['account_id'=>$manual,'amount'=>500,'date'=>'2026-09-04','description'=>'Manual goal','client_request_id'=>'req_statement_manual_goal']);
        $s=$db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,'Manual Debtor','person')");$s->execute([$owner]);$debtor=(int)$db->lastInsertId();
        $s=$db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,account_id,transaction_date,description)VALUES(?,?,'lent',1000,NULL,'2026-09-01','Manual receivable')");$s->execute([$owner,$debtor]);
        $karobar->processReceiving(['person_id'=>$debtor,'account_id'=>$manual,'amount'=>1000,'transaction_date'=>'2026-09-05','client_request_id'=>'req_statement_manual_receive'],$owner);
        $full=$balance->getAccountStatement($manual,$owner,[],50,0);stClose(5950,$full['closing_balance'],'manual closing');stClose($balance->calculateAccountBalance($manual,$owner),$full['closing_balance'],'manual current balance');
        $last=$balance->getAccountStatement($manual,$owner,['start_date'=>'2026-09-03','end_date'=>'2026-09-05'],50,0);stClose(6500,$last['opening_balance'],'manual filtered opening');stClose(5950,$last['closing_balance'],'manual filtered closing');
    });

    $run('full closing equals live account balance and foreign account is rejected',function()use($balance,$a,$owner,$foreignAccount):void{
        $r=$balance->getAccountStatement($a,$owner,[],200,0); stClose($balance->calculateAccountBalance($a,$owner),$r['closing_balance'],'statement/current balance mismatch');
        try{$balance->getAccountStatement($foreignAccount,$owner,[],10,0);throw new RuntimeException('foreign account succeeded');}catch(RuntimeException $e){stAssert($e->getMessage()==='Account not found','unexpected foreign-account error');}
    });
}finally{
    foreach($users as $uid){foreach(['transactions','karobar_transactions','goals','people','categories','accounts']as$table){try{$db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$uid]);}catch(Throwable $ignored){}}try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);}catch(Throwable $ignored){}}
}
echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";exit($failed?1:0);
