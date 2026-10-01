<?php
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../models/Budget.php';
require_once __DIR__.'/../services/NotificationService.php';
require_once __DIR__.'/../controllers/AnalysisController.php';

function bxAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "PASS: {$message}\n";}
function bxClose(float $expected,float $actual,string $message):void{bxAssert(abs($expected-$actual)<.001,"{$message} (expected {$expected}, got {$actual})");}

$db=(new Database())->getConnection();$userId=null;
try{
    $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Budget Cross Module')")->execute(['budget-cross-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$userId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Food','expense','active',0)")->execute([$userId]);$categoryId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO subcategories(user_id,category_id,name,status)VALUES(?,?,'Restaurant','active')")->execute([$userId,$categoryId]);$subcategoryId=(int)$db->lastInsertId();
    $insert=$db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,subcategory_id,description)VALUES(?,'expense',?,?,?,?, 'budget cross fixture')");
    $insert->execute([$userId,60,'2026-08-10',$categoryId,$subcategoryId]);$trackedTransactionId=(int)$db->lastInsertId();
    $insert->execute([$userId,25,'2026-08-11',$categoryId,null]);
    $db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,description)VALUES(?,'income',500,'2026-08-12',?,'excluded income')")->execute([$userId,$categoryId]);
    $db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,description)VALUES(?,'expense',999,'2026-09-01',?,'outside period')")->execute([$userId,$categoryId]);

    $model=new Budget();$base=['user_id'=>$userId,'amount'=>100,'period'=>'monthly','start_date'=>'2026-08-01','end_date'=>'2026-08-31','alert_threshold'=>80,'is_active'=>1];
    $categoryBudget=(int)$model->create(array_merge($base,['name'=>'Food','category_id'=>$categoryId,'subcategory_id'=>null]));
    $childBudget=(int)$model->create(array_merge($base,['name'=>'Restaurant','category_id'=>$categoryId,'subcategory_id'=>$subcategoryId]));
    $overallBudget=(int)$model->create(array_merge($base,['name'=>'All expenses','category_id'=>null,'subcategory_id'=>null]));
    $rows=$model->getBatchProgress([$categoryBudget,$childBudget,$overallBudget],$userId,'2026-08-01','2026-08-31');$byId=[];foreach($rows as$row)$byId[(int)$row['budget_id']]=$row;
    bxClose(85,(float)$byId[$categoryBudget]['spent'],'category progress is authoritative');
    bxClose(60,(float)$byId[$childBudget]['spent'],'subcategory progress is authoritative');
    bxClose(85,(float)$byId[$overallBudget]['spent'],'all-expense progress is authoritative');
    bxClose(0,(float)$model->getBatchProgress([$categoryBudget],$userId,'2026-09-01','2026-09-30')[0]['spent'],'consumer period intersects stored dates');
    $aggregate=$model->getAggregateProgress([$categoryBudget,$childBudget,$overallBudget],$userId,'2026-08-01','2026-08-31');bxClose(85,(float)$aggregate['unique_spent'],'overlapping aggregate counts unique expense rows');
    $analysisController=new AnalysisController();$analysisMethod=new ReflectionMethod($analysisController,'getBudgetData');$analysisMethod->setAccessible(true);$analysisBudget=$analysisMethod->invoke($analysisController,$userId,'2026-08-01','2026-08-31');
    bxClose((15+40+15)/3,(float)$analysisBudget['health_score'],'Analysis health uses the same authoritative progress rows');
    bxAssert($analysisBudget['statuses']===['warning','on_track','warning'],'Analysis statuses match category, subcategory and all-expense progress');

    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Travel','expense','active',0)")->execute([$userId]);$travelCategoryId=(int)$db->lastInsertId();
    $travelBudget=(int)$model->create(array_merge($base,['name'=>'Travel','category_id'=>$travelCategoryId,'subcategory_id'=>null]));
    $db->prepare('UPDATE transactions SET amount=70 WHERE id=? AND user_id=?')->execute([$trackedTransactionId,$userId]);
    bxClose(95,(float)$model->getBudgetProgress($categoryBudget,$userId)['spent'],'transaction amount edit updates progress');
    $db->prepare('UPDATE transactions SET amount=60, category_id=?, subcategory_id=NULL WHERE id=? AND user_id=?')->execute([$travelCategoryId,$trackedTransactionId,$userId]);
    bxClose(25,(float)$model->getBudgetProgress($categoryBudget,$userId)['spent'],'transaction category edit removes old category spend');
    bxClose(60,(float)$model->getBudgetProgress($travelBudget,$userId)['spent'],'transaction category edit adds new category spend');
    $db->prepare('UPDATE transactions SET category_id=?, subcategory_id=NULL WHERE id=? AND user_id=?')->execute([$categoryId,$trackedTransactionId,$userId]);
    bxClose(0,(float)$model->getBudgetProgress($childBudget,$userId)['spent'],'transaction subcategory edit removes child spend');
    $db->prepare('UPDATE transactions SET subcategory_id=?, date=? WHERE id=? AND user_id=?')->execute([$subcategoryId,'2026-09-10',$trackedTransactionId,$userId]);
    bxClose(25,(float)$model->getBudgetProgress($categoryBudget,$userId)['spent'],'transaction date edit removes out-of-period spend');
    $db->prepare('UPDATE transactions SET date=? WHERE id=? AND user_id=?')->execute(['2026-08-10',$trackedTransactionId,$userId]);
    $db->prepare('DELETE FROM transactions WHERE id=? AND user_id=?')->execute([$trackedTransactionId,$userId]);
    bxClose(25,(float)$model->getBudgetProgress($categoryBudget,$userId)['spent'],'transaction deletion updates progress');
    $insert->execute([$userId,60,'2026-08-10',$categoryId,$subcategoryId]);
    bxClose(85,(float)$model->getBudgetProgress($categoryBudget,$userId)['spent'],'transaction recreation restores progress');

    $notifications=new NotificationService();$progress=$byId[$categoryBudget];
    bxAssert($notifications->syncBudgetAlertState($userId,$progress),'normal to warning creates notification');
    bxAssert(!$notifications->syncBudgetAlertState($userId,$progress),'repeated warning read is deduplicated');
    $db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,description)VALUES(?,'expense',20,'2026-08-13',?,'threshold transition')")->execute([$userId,$categoryId]);
    $progress=$model->getBudgetProgress($categoryBudget,$userId);bxAssert($notifications->syncBudgetAlertState($userId,$progress),'warning to exceeded creates notification');bxAssert(!$notifications->syncBudgetAlertState($userId,$progress),'repeated exceeded read is deduplicated');
    $db->prepare("DELETE FROM transactions WHERE user_id=? AND description IN ('budget cross fixture','threshold transition')")->execute([$userId]);
    $progress=$model->getBudgetProgress($categoryBudget,$userId);bxAssert($notifications->syncBudgetAlertState($userId,$progress),'exceeded to normal records recovery');bxAssert(!$notifications->syncBudgetAlertState($userId,$progress),'repeated normal read is deduplicated');

    $analysis=file_get_contents(__DIR__.'/../controllers/AnalysisController.php');$dashboard=file_get_contents(__DIR__.'/../controllers/DashboardController.php');$reports=file_get_contents(__DIR__.'/../controllers/ReportsController.php');
    bxAssert(str_contains($analysis,'$this->budgetModel->getBatchProgress'),'Analysis reuses authoritative budget progress');
    bxAssert(str_contains($dashboard,'$this->budgetModel->getBatchProgress'),'Dashboard reuses authoritative budget progress');
    bxAssert(str_contains($reports,'$this->budgetModel->getBatchProgress'),'Reports reuse authoritative range-aware budget progress');
}finally{if($userId)$db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);}
