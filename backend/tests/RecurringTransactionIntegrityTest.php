<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/RecurringTransactionService.php';

function rtAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);}
function rtClose(float$expected,float$actual,string$message):void{if(abs($expected-$actual)>.001)throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");}
$db=(new Database())->getConnection();if(!$db)exit(1);$users=[];$passed=0;$failed=0;
$run=function(string$name,callable$test)use(&$passed,&$failed):void{try{$test();$passed++;echo"PASS: $name\n";}catch(Throwable$e){$failed++;fwrite(STDERR,"FAIL: $name - {$e->getMessage()}\n");}};

try{
    $today=(new RecurrenceCalculator())->today();
    $todayDate=new DateTimeImmutable($today,new DateTimeZone(RecurrenceCalculator::TIMEZONE));
    $tomorrow=$todayDate->modify('+1 day')->format('Y-m-d');
    $oneDayAgo=$todayDate->modify('-1 day')->format('Y-m-d');
    $twoDaysAgo=$todayDate->modify('-2 days')->format('Y-m-d');
    $threeDaysAgo=$todayDate->modify('-3 days')->format('Y-m-d');
    $futureDate=$todayDate->modify('+7 days')->format('Y-m-d');
    foreach(['owner','foreign']as$label){$s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,?)");$s->execute(['recurring-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT),'Recurring']);$users[$label]=(int)$db->lastInsertId();}
    $owner=$users['owner'];$foreign=$users['foreign'];
    $s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'Recurring Cash','cash',1000,1000,1,0)");$s->execute([$owner]);$account=(int)$db->lastInsertId();
    $s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'Foreign Cash','cash',1000,1000,1,0)");$s->execute([$foreign]);$foreignAccount=(int)$db->lastInsertId();
    $s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Recurring Expense','expense','active',0)");$s->execute([$owner]);$expenseCategory=(int)$db->lastInsertId();
    $s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Recurring Income','income','active',0)");$s->execute([$owner]);$incomeCategory=(int)$db->lastInsertId();
    $s=$db->prepare("INSERT INTO subcategories(category_id,user_id,name,status,sort_order)VALUES(?,?,'Recurring Child','active',0)");$s->execute([$expenseCategory,$owner]);$subcategory=(int)$db->lastInsertId();
    $service=new RecurringTransactionService();
    $payload=function(array$overrides=[])use($account,$expenseCategory,$today):array{return array_merge(['account_id'=>$account,'category_id'=>$expenseCategory,'subcategory_id'=>null,'amount'=>100,'type'=>'expense','frequency'=>'daily','start_date'=>$today,'end_date'=>null,'description'=>'Recurring fixture','notes'=>null],$overrides);};
    $txCount=function(int$id)use($db,$owner):int{$s=$db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND recurring_definition_id=?');$s->execute([$owner,$id]);return(int)$s->fetchColumn();};
    $row=function(int$id)use($db,$owner):array{$s=$db->prepare('SELECT * FROM recurring_transactions WHERE id=? AND user_id=?');$s->execute([$id,$owner]);return$s->fetch(PDO::FETCH_ASSOC);};

    $created=null;
    $run('create stores inclusive first cursor without financial execution',function()use(&$created,$service,$payload,$owner):void{$created=$service->create($owner,$payload());});
    if($created===null)throw new RuntimeException('definition fixture was not created');
    if((int)$created['user_id']!==$owner){throw new RuntimeException('created definition owner mismatch');}
    $run('definition creation is separate from occurrence generation',function()use($created,$txCount,$today):void{rtAssert($created['next_occurrence']===$today,'wrong inclusive cursor');rtAssert($txCount((int)$created['id'])===0,'definition creation generated finance');});

    $dueId=(int)$created['id'];
    $run('due recurrence-defining edit is blocked',function()use($service,$dueId,$owner,$payload):void{try{$service->update($dueId,$owner,$payload(['amount'=>125]));throw new RuntimeException('due edit succeeded');}catch(RecurringDueConflictException$e){rtAssert(str_contains($e->getMessage(),'Reconcile'),'unclear due edit conflict');}});
    $run('one due occurrence generates exactly one ordinary expense',function()use($service,$dueId,$owner,$db,$txCount,$row,$today,$tomorrow):void{$result=$service->processDue($owner);rtAssert(count($result['generated'])===1,'due occurrence not generated');rtAssert($txCount($dueId)===1,'wrong generated count');$s=$db->prepare('SELECT * FROM transactions WHERE recurring_definition_id=?');$s->execute([$dueId]);$tx=$s->fetch(PDO::FETCH_ASSOC);rtAssert($tx['recurring_occurrence_date']===$today,'scheduled provenance missing');rtAssert($tx['client_request_id']==="recurring:{$dueId}:{$today}",'deterministic request ID missing');rtAssert($row($dueId)['next_occurrence']===$tomorrow,'cursor did not advance');$balance=(float)$db->query("SELECT balance FROM accounts WHERE id=".(int)$tx['account_id'])->fetchColumn();rtClose(900,$balance,'expense balance');});
    $run('stale cursor repairs existing occurrence without duplication',function()use($db,$dueId,$owner,$service,$txCount,$row,$today,$tomorrow):void{$db->prepare("UPDATE recurring_transactions SET next_occurrence=?,is_active=1 WHERE id=? AND user_id=?")->execute([$today,$dueId,$owner]);$result=$service->processDue($owner);rtAssert(count($result['already_processed'])===1,'existing occurrence not recognized');rtAssert($txCount($dueId)===1,'stale retry duplicated transaction');rtAssert($row($dueId)['next_occurrence']===$tomorrow,'stale cursor was not repaired');});
    $run('definition edit changes future only and preserves generated history',function()use($service,$dueId,$owner,$payload,$db):void{$updated=$service->update($dueId,$owner,$payload(['amount'=>150]));rtClose(150,(float)$updated['amount'],'future amount');$s=$db->prepare('SELECT amount FROM transactions WHERE recurring_definition_id=?');$s->execute([$dueId]);rtClose(100,(float)$s->fetchColumn(),'historical amount changed');});
    $run('history blocks hard deletion while deactivation preserves it',function()use($service,$dueId,$owner,$txCount):void{$service->deactivate($dueId,$owner);rtAssert($txCount($dueId)===1,'deactivation removed transaction');try{$service->delete($dueId,$owner);throw new RuntimeException('history definition deleted');}catch(RecurringHistoryConflictException$e){rtAssert(true,'');}});

    $backlog=$service->create($owner,$payload(['start_date'=>$twoDaysAgo,'description'=>'Backlog']));$backlogId=(int)$backlog['id'];
    $run('multiple due dates require review and generate none automatically',function()use($service,$owner,$backlogId,$txCount):void{$result=$service->processDue($owner);$ids=array_column($result['review_required'],'definition_id');rtAssert(in_array($backlogId,$ids,true),'backlog not flagged');rtAssert($txCount($backlogId)===0,'backlog generated automatically');});
    $run('arbitrary reconciliation dates are rejected before mutation',function()use($service,$owner,$backlogId,$txCount,$threeDaysAgo):void{try{$service->reconcile($backlogId,$owner,[['date'=>$threeDaysAgo,'action'=>'generate']]);throw new RuntimeException('arbitrary date accepted');}catch(InvalidArgumentException$e){rtAssert($txCount($backlogId)===0,'invalid reconciliation partially mutated');}});
    $run('mixed Generate and Skip reconciles every authoritative date',function()use($service,$owner,$backlogId,$txCount,$row,$twoDaysAgo,$oneDayAgo,$today,$tomorrow):void{$decisions=[['date'=>$twoDaysAgo,'action'=>'generate'],['date'=>$oneDayAgo,'action'=>'skip'],['date'=>$today,'action'=>'generate']];$r=$service->reconcile($backlogId,$owner,$decisions);rtAssert($r['status']==='reconciled','reconciliation failed');rtAssert($txCount($backlogId)===2,'Generate/Skip produced wrong count');rtAssert($row($backlogId)['next_occurrence']===$tomorrow,'reconciliation cursor wrong');$retry=$service->reconcile($backlogId,$owner,$decisions);rtAssert($retry['status']==='already_reconciled','duplicate reconciliation not safe');});

    $failure=$service->create($owner,$payload(['description'=>'Rollback fixture']));$failureId=(int)$failure['id'];
    $run('failure after transaction insert rolls back transaction and cursor',function()use($failureId,$owner,$db,$txCount,$row,$today):void{$accounting=new AccountingService();$accounting->setRecurringFailureInjector(fn($point)=>$point==='after_transaction_create'?throw new RuntimeException('Injected recurring failure'):null);try{$accounting->processRecurringOccurrence($failureId,$owner,null,'generate',false,true);throw new RuntimeException('injected failure did not throw');}catch(RuntimeException$e){rtAssert($txCount($failureId)===0,'failed transaction committed');rtAssert($row($failureId)['next_occurrence']===$today,'failed cursor advanced');}});
    $run('notification failure cannot roll back committed finance',function()use($failureId,$owner,$txCount,$row,$tomorrow):void{$accounting=new AccountingService();$accounting->setRecurringFailureInjector(fn($point)=>$point==='notification'?throw new RuntimeException('Injected notification failure'):null);$r=$accounting->processRecurringOccurrence($failureId,$owner,null,'generate',false,true);rtAssert($r['status']==='generated','financial result failed with notification');rtAssert($txCount($failureId)===1,'notification failure removed transaction');rtAssert($row($failureId)['next_occurrence']===$tomorrow,'notification failure blocked cursor');});

    $invalid=$service->create($owner,$payload(['description'=>'Invalid account']));$invalidId=(int)$invalid['id'];
    $run('invalid account deactivates definition without advancing',function()use($db,$account,$service,$owner,$invalidId,$row,$txCount,$today):void{$db->prepare('UPDATE accounts SET is_active=0 WHERE id=?')->execute([$account]);$r=$service->processDue($owner);$ids=array_column($r['failed_validation'],'definition_id');rtAssert(in_array($invalidId,$ids,true),'invalid definition not reported');$d=$row($invalidId);rtAssert((int)$d['is_active']===0,'invalid definition not deactivated');rtAssert($d['next_occurrence']===$today,'invalid cursor advanced');rtAssert($txCount($invalidId)===0,'invalid definition generated transaction');$db->prepare('UPDATE accounts SET is_active=1 WHERE id=?')->execute([$account]);});

    $subInvalid=$service->create($owner,$payload(['subcategory_id'=>$subcategory,'description'=>'Invalid child']));$subInvalidId=(int)$subInvalid['id'];
    $run('archived subcategory deactivates definition without malformed transaction',function()use($db,$subcategory,$service,$owner,$subInvalidId,$row,$txCount):void{$db->prepare("UPDATE subcategories SET status='archived' WHERE id=?")->execute([$subcategory]);$service->processDue($owner);rtAssert((int)$row($subInvalidId)['is_active']===0,'archived child definition stayed active');rtAssert($txCount($subInvalidId)===0,'archived child generated transaction');});

    $future=$service->create($owner,$payload(['start_date'=>$futureDate,'description'=>'Future delete']));
    $run('future definition supports deactivate, future resume, and hard delete',function()use($service,$owner,$future,$futureDate):void{$id=(int)$future['id'];$service->deactivate($id,$owner);$activated=$service->activate($id,$owner,'resume');rtAssert($activated['status']==='activated','future activation failed');rtAssert($activated['definition']['next_occurrence']===$futureDate,'future activation cursor wrong');$service->delete($id,$owner);try{$service->get($id,$owner);throw new RuntimeException('deleted definition remains');}catch(RecurringAuthorizationException$e){rtAssert(true,'');}});
    $run('foreign user cannot read or process owner definition',function()use($service,$dueId,$foreign):void{try{$service->get($dueId,$foreign);throw new RuntimeException('foreign read succeeded');}catch(RecurringAuthorizationException$e){rtAssert(true,'');}$result=$service->processDue($foreign);rtAssert(array_sum($result['counts'])===0,'foreign processor saw owner definitions');});
}finally{
    foreach(array_reverse($users)as$id){try{$db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM recurring_transactions WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM budgets WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM subcategories WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM categories WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}catch(Throwable$e){fwrite(STDERR,"Cleanup warning: {$e->getMessage()}\n");}}
}
echo"RESULT: $passed passed, $failed failed, 0 skipped\n";exit($failed?1:0);
