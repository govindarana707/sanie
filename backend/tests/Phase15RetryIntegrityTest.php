<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/KarobarService.php';
require_once __DIR__ . '/../services/KarobarOutstandingService.php';
require_once __DIR__ . '/../services/BalanceService.php';
require_once __DIR__ . '/../services/ReportingPaginationService.php';
require_once __DIR__ . '/../models/Budget.php';

function p15Assert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function p15Close(float $expected, float $actual, string $message): void { p15Assert(abs($expected - $actual) < .001, "{$message}: expected {$expected}, got {$actual}"); }
function p15Conflict(callable $operation, string $message): void { try { $operation(); } catch (TransactionConflictException|KarobarConflictException $e) { return; } throw new RuntimeException($message); }

$db = (new Database())->getConnection(); $users = []; $failed = false;
try {
    foreach (['owner', 'foreign'] as $label) {
        $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Phase 15')")
            ->execute(['phase15-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner']; $foreign = $users['foreign'];
    $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'P15 Cash','cash',1000,1000,1,0)")->execute([$owner]); $account=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'P15 Other','cash',1000,1000,1,0)")->execute([$foreign]); $foreignAccount=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'P15 Expense','expense','active',0)")->execute([$owner]); $category=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'P15 Other Expense','expense','active',0)")->execute([$foreign]); $foreignCategory=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,'P15 Person','person')")->execute([$owner]); $person=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO people(user_id,name,type)VALUES(?,'P15 Other Person','person')")->execute([$foreign]); $foreignPerson=(int)$db->lastInsertId();
    $budget=(int)(new Budget())->create(['user_id'=>$owner,'name'=>'P15 Retry Budget','amount'=>500,'period'=>'monthly','category_id'=>$category,'subcategory_id'=>null,'start_date'=>'2026-08-01','end_date'=>'2026-08-31','alert_threshold'=>80,'is_active'=>1]);

    $accounting = new AccountingService();
    $ordinary = ['type'=>'expense','amount'=>'100.00','account_id'=>$account,'category_id'=>$category,'date'=>'2026-08-25','description'=>'Lost response','payment_method'=>'cash','client_request_id'=>'req_phase15_ordinary_retry'];
    $first = $accounting->createOrdinaryTransaction($owner, $ordinary); $retry = $accounting->createOrdinaryTransaction($owner, $ordinary);
    p15Assert(!$first['replayed'] && $retry['replayed'] && $first['transaction_id'] === $retry['transaction_id'], 'ordinary retry did not reuse the committed transaction');
    p15Assert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$owner} AND client_request_id='req_phase15_ordinary_retry'")->fetchColumn()===1, 'ordinary retry duplicated a transaction');
    p15Close(900, (float)$db->query("SELECT balance FROM accounts WHERE id={$account}")->fetchColumn(), 'ordinary retry changed balance more than once');
    p15Close(100,(float)(new Budget())->getBudgetProgress($budget,$owner)['spent'],'ordinary retry changed budget more than once');
    p15Close(100,(float)(new BalanceService())->getStatistics($owner,'2026-08-01','2026-08-31')['total_expense'],'ordinary retry changed Dashboard more than once');
    $report=(new ReportingPaginationService())->incomeExpensePage($owner,['start_date'=>'2026-08-01','end_date'=>'2026-08-31'],1,50);p15Close(100,(float)$report['summary']['total_expense'],'ordinary retry changed report more than once');p15Assert($report['pagination']['total_rows']===1,'ordinary retry duplicated report rows');
    $ledger=(new ReportingPaginationService())->ledgerPage($owner,['account_id'=>$account,'start_date'=>'2026-08-01','end_date'=>'2026-08-31'],1,50);p15Close(100,(float)$ledger['summary']['period_expense'],'ordinary retry changed ledger more than once');p15Assert($ledger['pagination']['total_rows']===1,'ordinary retry duplicated ledger rows');
    p15Conflict(fn()=>$accounting->createOrdinaryTransaction($owner, array_merge($ordinary,['amount'=>'101.00'])), 'ordinary mismatched replay was accepted');
    $sameKeyOther = array_merge($ordinary,['account_id'=>$foreignAccount,'category_id'=>$foreignCategory]);
    p15Assert(!(new AccountingService())->createOrdinaryTransaction($foreign,$sameKeyOther)['replayed'], 'request identity was not owner scoped');

    $karobar = new KarobarService(); $outstanding = new KarobarOutstandingService();
    foreach ([['lent',50.0],['borrowed',60.0],['adjustment',20.0]] as [$type,$amount]) {
        $payload=['person_id'=>$person,'account_id'=>$type==='adjustment'?null:$account,'type'=>$type,'amount'=>number_format($amount,2,'.',''),'transaction_date'=>'2026-08-25','description'=>'P15 '.$type,'client_request_id'=>'p15-origin-'.$type];
        $id1=$karobar->createTransaction($payload,$owner);$balanceBeforeRetry=(float)$db->query("SELECT balance FROM accounts WHERE id={$account}")->fetchColumn();$id2=$karobar->createTransaction($payload,$owner);
        p15Assert($id1===$id2,'Karobar '.$type.' retry returned a different record');
        p15Assert((int)$db->query("SELECT COUNT(*) FROM karobar_transactions WHERE user_id={$owner} AND client_request_id='p15-origin-{$type}'")->fetchColumn()===1,'Karobar '.$type.' retry duplicated origin');
        p15Close($balanceBeforeRetry,(float)$db->query("SELECT balance FROM accounts WHERE id={$account}")->fetchColumn(),'Karobar '.$type.' retry repeated account effect');
        p15Conflict(fn()=>$karobar->createTransaction(array_merge($payload,['amount'=>number_format($amount+1,2,'.','')]),$owner),'Karobar mismatched replay was accepted');
    }
    $position=$outstanding->getPersonPosition($owner,$person);p15Close(50,(float)$position['receivable_outstanding'],'receivable duplicated');p15Close(60,(float)$position['payable_outstanding'],'payable duplicated');
    $foreignPayload=['person_id'=>$foreignPerson,'account_id'=>$foreignAccount,'type'=>'lent','amount'=>'5.00','transaction_date'=>'2026-08-25','description'=>'Foreign scope','client_request_id'=>'p15-origin-lent'];
    p15Assert((new KarobarService())->createTransaction($foreignPayload,$foreign)>0,'Karobar identity was not owner scoped');

    if (!function_exists('proc_open')) throw new RuntimeException('proc_open unavailable for concurrency test');
    $worker=__DIR__.DIRECTORY_SEPARATOR.'Phase15RetryConcurrencyWorker.php';
    foreach ([['ordinary',$category,'req_phase15_concurrent_tx'],['karobar',$person,'p15-concurrent-karobar']] as [$workflow,$reference,$key]) {
        $barrier=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sanie-p15-'.bin2hex(random_bytes(7)).'.start';$processes=[];
        for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,$worker,$workflow,(string)$owner,(string)$account,(string)$reference,$key,$barrier],[1=>['pipe','w'],2=>['pipe','w']],$pipes);p15Assert(is_resource($process),'worker did not start');$processes[]=[$process,$pipes];}
        touch($barrier);$ids=[];foreach($processes as[$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$decoded=json_decode($stdout,true);p15Assert($exit===0&&($decoded['ok']??false),"worker failed: {$stderr} {$stdout}");$ids[]=(int)$decoded['id'];}unlink($barrier);p15Assert(count(array_unique($ids))===1,$workflow.' concurrent retry created different records');
    }
    p15Assert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$owner} AND client_request_id='req_phase15_concurrent_tx'")->fetchColumn()===1,'concurrent ordinary create duplicated');
    p15Assert((int)$db->query("SELECT COUNT(*) FROM karobar_transactions WHERE user_id={$owner} AND client_request_id='p15-concurrent-karobar'")->fetchColumn()===1,'concurrent Karobar create duplicated');
    echo "PASS: Phase 15 ordinary and Karobar retries, mismatches, ownership, balances, outstanding, and concurrency are safe\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");}
finally{foreach(array_reverse($users)as$uid){foreach(['notification_events','notifications','transactions','karobar_transactions','budgets','people','categories','accounts']as$table){try{$db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$uid]);}catch(Throwable$ignored){}}try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);}catch(Throwable$ignored){}}}
exit($failed?1:0);
