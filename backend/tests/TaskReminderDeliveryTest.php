<?php
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../models/Task.php';
require_once __DIR__.'/../services/NotificationService.php';
function trAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}
$db=(new Database())->getConnection();$userId=null;
try{
    $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Task Reminder')")->execute(['task-reminder-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$userId=(int)$db->lastInsertId();
    $tasks=new Task($db);$service=new NotificationService();
    $base=['task_type'=>'general','title'=>'Reminder task','content'=>null,'due_date'=>'2026-08-24','display_date_bs'=>null,'subject'=>null,'unit_label'=>null,'status'=>'pending','priority'=>'normal','seed_key'=>null];
    $future=$tasks->create($userId,array_merge($base,['title'=>'Future','reminder_at'=>'2026-08-25 09:00:00']));
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===0,'future reminder is not delivered');
    $due=$tasks->create($userId,array_merge($base,['title'=>'Due','reminder_at'=>'2026-08-24 09:00:00']));
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===1,'due reminder is delivered once');
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===0,'repeated reminder evaluation is deduplicated');
    $completed=$tasks->create($userId,array_merge($base,['title'=>'Completed','status'=>'completed','reminder_at'=>'2026-08-24 08:00:00']));
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===0,'completed task reminder is ineligible');
    $deleted=$tasks->create($userId,array_merge($base,['title'=>'Deleted','reminder_at'=>'2026-08-24 08:00:00']));$tasks->delete($deleted,$userId);
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===0,'soft-deleted task reminder is ineligible');
    $changed=$tasks->findById($due,$userId);$changed['reminder_at']='2026-08-24 10:00:00';$tasks->update($due,$userId,$changed);
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===1,'changed due reminder can deliver once for its new timestamp');
    $changed=$tasks->findById($due,$userId);$changed['reminder_at']=null;$tasks->update($due,$userId,$changed);
    trAssert($service->processTaskReminders($userId,'2026-08-24 12:00:00')===0,'removed reminder is ineligible');
    trAssert((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$userId} AND type='task_reminder'")->fetchColumn()===2,'only eligible reminder identities created notifications');
    trAssert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$userId}")->fetchColumn()===0,'reminder delivery does not mutate financial records');
}finally{if($userId)$db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);}
