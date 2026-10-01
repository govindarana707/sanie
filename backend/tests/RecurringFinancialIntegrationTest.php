<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/RecurringTransactionService.php';
require_once __DIR__.'/../services/BalanceService.php';
require_once __DIR__.'/../services/ReportingPaginationService.php';
require_once __DIR__.'/../models/Budget.php';
require_once __DIR__.'/../controllers/AnalysisController.php';

function rfAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);echo"PASS: {$message}\n";}
function rfClose(float$expected,float$actual,string$message):void{rfAssert(abs($expected-$actual)<.001,"{$message} (expected {$expected}, got {$actual})");}
function rfCall(object$o,string$m,...$args){$r=new ReflectionMethod($o,$m);$r->setAccessible(true);return$r->invoke($o,...$args);}
$db=(new Database())->getConnection();$user=null;
try{
    $today=(new RecurrenceCalculator())->today();
    $todayDate=new DateTimeImmutable($today,new DateTimeZone(RecurrenceCalculator::TIMEZONE));
    $periodStart=$todayDate->modify('first day of this month')->format('Y-m-d');
    $periodEnd=$todayDate->modify('last day of this month')->format('Y-m-d');
    $s=$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,?)");$s->execute(['recurring-financial-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT),'Recurring Financial']);$user=(int)$db->lastInsertId();
    $makeAccount=function(string$name,float$opening)use($db,$user):int{$s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,?,'cash',?,?,1,0)");$s->execute([$user,$name,$opening,$opening]);return(int)$db->lastInsertId();};
    $expenseAccount=$makeAccount('Recurring Expense Account',1000);$incomeAccount=$makeAccount('Recurring Income Account',0);$normalExpenseAccount=$makeAccount('Normal Expense Account',1000);$normalIncomeAccount=$makeAccount('Normal Income Account',0);
    $s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Recurring Financial Expense','expense','active',0)");$s->execute([$user]);$expenseCategory=(int)$db->lastInsertId();$s=$db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Recurring Financial Income','income','active',0)");$s->execute([$user]);$incomeCategory=(int)$db->lastInsertId();
    $budgetId=(int)(new Budget())->create(['user_id'=>$user,'name'=>'Recurring Expense Budget','amount'=>500,'period'=>'monthly','category_id'=>$expenseCategory,'subcategory_id'=>null,'start_date'=>$periodStart,'end_date'=>$periodEnd,'alert_threshold'=>80,'is_active'=>1]);
    $service=new RecurringTransactionService();$expense=$service->create($user,['account_id'=>$expenseAccount,'category_id'=>$expenseCategory,'amount'=>100,'type'=>'expense','frequency'=>'daily','start_date'=>$today,'description'=>'Generated expense']);$income=$service->create($user,['account_id'=>$incomeAccount,'category_id'=>$incomeCategory,'amount'=>200,'type'=>'income','frequency'=>'daily','start_date'=>$today,'description'=>'Generated income']);
    $result=$service->processDue($user);rfAssert(count($result['generated'])===2,'processor generated both due ordinary transactions');
    $balance=new BalanceService();rfClose(900,$balance->calculateAccountBalance($expenseAccount,$user),'generated expense account balance');rfClose(200,$balance->calculateAccountBalance($incomeAccount,$user),'generated income account balance');
    $stats=$balance->getStatistics($user,$periodStart,$periodEnd);rfClose(100,(float)$stats['total_expense'],'Dashboard expense total');rfClose(200,(float)$stats['total_income'],'Dashboard income total');
    $progress=(new Budget())->getBudgetProgress($budgetId,$user);rfClose(100,(float)$progress['spent'],'budget consumes recurring expense exactly once');
    $report=(new ReportingPaginationService())->incomeExpensePage($user,['start_date'=>$periodStart,'end_date'=>$periodEnd],1,50);rfClose(100,(float)$report['summary']['total_expense'],'report expense total');rfClose(200,(float)$report['summary']['total_income'],'report income total');rfAssert($report['pagination']['total_rows']===2,'report contains both generated rows');
    $ledger=(new ReportingPaginationService())->ledgerPage($user,['account_id'=>$expenseAccount,'start_date'=>$periodStart,'end_date'=>$periodEnd],1,50);rfClose(100,(float)$ledger['summary']['period_expense'],'ledger expense total');rfClose(900,(float)$ledger['summary']['closing_balance'],'ledger closing balance');
    $statement=$balance->getAccountStatement($expenseAccount,$user,['start_date'=>$periodStart,'end_date'=>$periodEnd],50,0);rfClose(900,(float)$statement['closing_balance'],'account statement closing balance');rfAssert($statement['pagination']['total_rows']===1&&count($statement['rows'])===2,'account statement includes generated expense');
    $analysis=rfCall(new AnalysisController(),'queryTransactions',$user,$periodStart,$periodEnd);rfClose(100,(float)$analysis['total_expense'],'Analysis expense total');rfClose(200,(float)$analysis['total_income'],'Analysis income total');
    $accounting=new AccountingService();$accounting->createTransaction($user,['account_id'=>$normalExpenseAccount,'category_id'=>$expenseCategory,'amount'=>100,'type'=>'expense','date'=>$today,'description'=>'Equivalent normal expense','client_request_id'=>'normal-expense-'.bin2hex(random_bytes(4))]);$accounting->createTransaction($user,['account_id'=>$normalIncomeAccount,'category_id'=>$incomeCategory,'amount'=>200,'type'=>'income','date'=>$today,'description'=>'Equivalent normal income','client_request_id'=>'normal-income-'.bin2hex(random_bytes(4))]);
    rfClose($balance->calculateAccountBalance($normalExpenseAccount,$user),$balance->calculateAccountBalance($expenseAccount,$user),'recurring expense matches equivalent normal expense');rfClose($balance->calculateAccountBalance($normalIncomeAccount,$user),$balance->calculateAccountBalance($incomeAccount,$user),'recurring income matches equivalent normal income');
    $s=$db->prepare('SELECT recurring_definition_id,recurring_occurrence_date FROM transactions WHERE recurring_definition_id IN (?,?) ORDER BY id');$s->execute([(int)$expense['id'],(int)$income['id']]);$links=$s->fetchAll(PDO::FETCH_ASSOC);rfAssert(count($links)===2&&$links[0]['recurring_occurrence_date']===$today&&$links[1]['recurring_occurrence_date']===$today,'generated provenance is exposed on ordinary rows');
}finally{if($user){try{$db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM recurring_transactions WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM budgets WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM categories WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}catch(Throwable$e){fwrite(STDERR,"Cleanup warning: {$e->getMessage()}\n");}}}
