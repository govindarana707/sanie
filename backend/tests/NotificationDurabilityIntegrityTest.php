<?php
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../models/Budget.php';
require_once __DIR__.'/../models/Task.php';
require_once __DIR__.'/../services/NotificationService.php';

function ndAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo"PASS: {$message}\n";}
$db=(new Database())->getConnection();$user=null;
try{
    $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Notification Durability')")->execute(['notification-durable-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();
    $service=new NotificationService();

    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default)VALUES(?,'Durable Food','expense','active',0)")->execute([$user]);$category=(int)$db->lastInsertId();
    $budget=(int)(new Budget())->create(['user_id'=>$user,'name'=>'Durable Budget','amount'=>100,'period'=>'monthly','category_id'=>$category,'subcategory_id'=>null,'start_date'=>'2026-08-01','end_date'=>'2026-08-31','alert_threshold'=>80,'is_active'=>1]);
    $db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,description)VALUES(?,'expense',85,'2026-08-25',?,'durable budget')")->execute([$user,$category]);$tx=(int)$db->lastInsertId();
    ndAssert($service->syncUserBudgetAlertStates($user)===1,'budget threshold creates one durable event');
    $db->prepare("DELETE FROM notifications WHERE user_id=? AND reference_type='budget'")->execute([$user]);
    ndAssert($service->syncUserBudgetAlertStates($user)===0,'deleted budget notification does not reset threshold identity');
    $db->prepare('UPDATE transactions SET amount=120 WHERE id=?')->execute([$tx]);
    ndAssert($service->syncUserBudgetAlertStates($user)===1,'next genuine budget threshold still notifies');

    $db->prepare("INSERT INTO goals(user_id,name,target_amount,initial_amount,current_amount,status,deadline)VALUES(?,'Durable Goal',100,100,100,'completed','2026-08-31')")->execute([$user]);$goal=(int)$db->lastInsertId();
    ndAssert($service->notifyGoalAchievementOnce($user,$goal),'goal achievement creates durable event');
    $db->prepare("DELETE FROM notifications WHERE user_id=? AND reference_type='goal'")->execute([$user]);
    ndAssert(!$service->notifyGoalAchievementOnce($user,$goal),'deleted goal notification does not reset completion identity');

    $task=(new Task())->create($user,['task_type'=>'general','title'=>'Durable task','content'=>null,'due_date'=>'2026-08-25','display_date_bs'=>null,'subject'=>null,'unit_label'=>null,'status'=>'pending','priority'=>'normal','reminder_at'=>'2026-08-25 09:00:00','seed_key'=>null]);
    ndAssert($service->processTaskReminders($user,'2026-08-25 10:00:00')===1,'task reminder creates durable timestamp identity');
    $db->prepare("DELETE FROM notifications WHERE user_id=? AND reference_type='task'")->execute([$user]);
    ndAssert($service->processTaskReminders($user,'2026-08-25 10:00:00')===0,'deleted task notification does not repeat unchanged reminder');
    $row=(new Task())->findById($task,$user);$row['reminder_at']='2026-08-25 09:30:00';(new Task())->update($task,$user,$row);
    ndAssert($service->processTaskReminders($user,'2026-08-25 10:00:00')===1,'changed reminder timestamp creates one new event');

    $db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$user]);
    ndAssert($service->syncUserBudgetAlertStates($user)===0&&$service->processTaskReminders($user,'2026-08-25 10:00:00')===0&&!$service->notifyGoalAchievementOnce($user,$goal),'bulk visible deletion preserves all unchanged durable identities');
    ndAssert((int)$db->query("SELECT COUNT(*) FROM notification_events WHERE user_id={$user}")->fetchColumn()===5,'durable identities remain independently stored');
    $failed=false;try{(new NotificationEvent($db))->claimAndCreate(
        ['user_id'=>$user,'event_key'=>'test:atomic-failure','event_type'=>'system','source_type'=>null,'source_id'=>null,'occurred_at'=>null],
        [':user_id'=>999999999,':type'=>'system',':title'=>'Must fail',':message'=>'',':icon'=>'fa-bell',':color'=>'#000000',':priority'=>'normal',':reference_type'=>null,':reference_id'=>null]
    );}catch(Throwable $e){$failed=true;}
    $stmt=$db->prepare('SELECT COUNT(*) FROM notification_events WHERE user_id=? AND event_key=?');$stmt->execute([$user,'test:atomic-failure']);
    ndAssert($failed&&(int)$stmt->fetchColumn()===0,'visible insertion failure rolls back the durable claim for safe retry');
}finally{if($user)$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}
