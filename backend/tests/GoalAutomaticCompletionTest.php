<?php
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../models/Goal.php';require_once __DIR__.'/../models/Account.php';require_once __DIR__.'/../services/AccountingService.php';require_once __DIR__.'/../services/NotificationService.php';
function gaAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);echo"PASS: {$message}\n";}
$db=(new Database())->getConnection();$userId=null;
try{
 $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Goal Auto')")->execute(['goal-auto-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$userId=(int)$db->lastInsertId();
 $account=(int)(new Account())->create(['user_id'=>$userId,'name'=>'Cash','type'=>'cash','balance'=>1000,'opening_balance'=>1000,'is_active'=>1,'is_default'=>1,'include_in_savings'=>0]);
 $goals=new Goal();$goal=(int)$goals->create(['user_id'=>$userId,'name'=>'Target','target_amount'=>100,'initial_amount'=>0,'current_amount'=>0,'deadline'=>'2026-12-31','icon'=>'target','color'=>'#10B981','description'=>'','status'=>'active']);
 $accounting=new AccountingService();$notifications=new NotificationService();
 $first=$accounting->contributeToGoal($goal,$userId,60,$account,'2026-08-20','first','req_goalauto_first01');
 gaAssert($goals->findById($goal,$userId)['status']==='active','below-target contribution keeps goal active');
 gaAssert(!$notifications->notifyGoalAchievementOnce($userId,$goal),'below-target goal creates no achievement notification');
 $second=$accounting->contributeToGoal($goal,$userId,40,$account,'2026-08-21','second','req_goalauto_second1');
 gaAssert($goals->findById($goal,$userId)['status']==='completed','exact-target contribution completes active goal');
 gaAssert($notifications->notifyGoalAchievementOnce($userId,$goal),'first achievement creates one notification');
 gaAssert(!$notifications->notifyGoalAchievementOnce($userId,$goal),'repeated achievement evaluation is deduplicated');
 $accounting->updateGoalContribution($second['contribution_id'],$userId,['amount'=>20],$second['contribution']['version']);
 gaAssert((float)$goals->findById($goal,$userId)['current_amount']===80.0,'contribution edit recalculates authoritative progress');
 gaAssert($goals->findById($goal,$userId)['status']==='completed','completed goal does not reopen without a product rule');
 $current=$accounting->getGoalContributionResource($first['contribution_id'],$userId);
 $accounting->deleteGoalContribution($first['contribution_id'],$userId,$current['contribution']['version']);
 gaAssert((float)$goals->findById($goal,$userId)['current_amount']===20.0,'contribution deletion recalculates authoritative progress');
 gaAssert($goals->findById($goal,$userId)['status']==='completed','contribution deletion does not silently reopen goal');
 $above=(int)$goals->create(['user_id'=>$userId,'name'=>'Above','target_amount'=>50,'initial_amount'=>0,'current_amount'=>0,'deadline'=>null,'icon'=>'target','color'=>'#10B981','description'=>'','status'=>'active']);
 $accounting->contributeToGoal($above,$userId,60,$account,'2026-08-22','above','req_goalauto_above001');
 gaAssert($goals->findById($above,$userId)['status']==='completed','above-target contribution completes active goal');
 gaAssert((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$userId} AND type='goal_achieved' AND reference_id={$goal}")->fetchColumn()===1,'achievement notification remains single');
}finally{if($userId){foreach(['notifications','transactions','goals','accounts']as$table)$db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$userId]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);}}
