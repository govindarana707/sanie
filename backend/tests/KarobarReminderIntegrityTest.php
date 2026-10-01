<?php
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/NotificationService.php';
function krAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo"PASS: {$message}\n";}
$db=(new Database())->getConnection();$users=[];
try{
    $newUser=function(string$name)use($db,&$users):int{$db->prepare('INSERT INTO users(email,password,first_name)VALUES(?,?,?)')->execute(['karobar-reminder-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT),$name]);$id=(int)$db->lastInsertId();$users[]=$id;return$id;};
    $person=function(int$user,string$name,string$status='active')use($db):int{$db->prepare('INSERT INTO people(user_id,name,status)VALUES(?,?,?)')->execute([$user,$name,$status]);return(int)$db->lastInsertId();};
    $origin=function(int$user,int$person,string$type,float$amount,?string$due)use($db):int{$db->prepare('INSERT INTO karobar_transactions(user_id,person_id,type,amount,description,transaction_date,due_date)VALUES(?,?,?,?,?,?,?)')->execute([$user,$person,$type,$amount,'reminder fixture','2026-08-01',$due]);return(int)$db->lastInsertId();};
    $settle=function(int$user,int$person,string$type,float$amount)use($db):void{$db->prepare('INSERT INTO karobar_transactions(user_id,person_id,type,amount,description,transaction_date)VALUES(?,?,?,?,?,?)')->execute([$user,$person,$type,$amount,'settlement fixture','2026-08-25']);};
    $user=$newUser('Owner');$other=$newUser('Other');$service=new NotificationService();
    $origin($user,$person($user,'No date'),'lent',100,null);$origin($user,$person($user,'Future'),'borrowed',100,'2026-08-26');
    krAssert($service->processKarobarReminders($user,'2026-08-24 23:59:00')['eligible']===0,'no-date and future Karobar rows are not due');

    $duePerson=$person($user,'Asha');$dueId=$origin($user,$duePerson,'lent',100,'2026-08-25');
    $r=$service->processKarobarReminders($user,'2026-08-25 00:01:00');krAssert($r['due']===1,'receivable due-today reminder fires at Kathmandu 00:01');
    $db->prepare('DELETE FROM notifications WHERE user_id=? AND reference_type=? AND reference_id=?')->execute([$user,'karobar',$dueId]);
    krAssert($service->processKarobarReminders($user,'2026-08-25 23:59:00')['due']===0,'deleted due notification does not repeat later the same day');
    krAssert($service->processKarobarReminders($user,'2026-08-26 00:01:00')['overdue']>=1,'same outstanding obligation gets one distinct overdue transition');

    $partialPerson=$person($user,'Bikash');$partialId=$origin($user,$partialPerson,'borrowed',100,'2026-08-25');$settle($user,$partialPerson,'repaid',40);
    $service->processKarobarReminders($user,'2026-08-25 12:00:00');$stmt=$db->prepare("SELECT message FROM notifications WHERE user_id=? AND reference_id=? AND type='karobar_due'");$stmt->execute([$user,$partialId]);
    krAssert(str_contains((string)$stmt->fetchColumn(),'60.00'),'partial settlement reminder uses authoritative remaining outstanding');

    $settledPerson=$person($user,'Chandra');$settledId=$origin($user,$settledPerson,'lent',100,'2026-08-25');$settle($user,$settledPerson,'returned',100);$service->processKarobarReminders($user,'2026-08-25 12:00:00');
    $stmt=$db->prepare('SELECT COUNT(*) FROM notification_events WHERE user_id=? AND source_id=?');$stmt->execute([$user,$settledId]);krAssert((int)$stmt->fetchColumn()===0,'full settlement before due suppresses reminder');

    $betweenPerson=$person($user,'Deepa');$betweenId=$origin($user,$betweenPerson,'lent',75,'2026-08-25');$service->processKarobarReminders($user,'2026-08-25 12:00:00');$settle($user,$betweenPerson,'returned',75);$service->processKarobarReminders($user,'2026-08-26 00:01:00');
    $stmt=$db->prepare("SELECT COUNT(*) FROM notification_events WHERE user_id=? AND source_id=? AND event_type='karobar_overdue'");$stmt->execute([$user,$betweenId]);krAssert((int)$stmt->fetchColumn()===0,'full settlement after due but before overdue suppresses overdue event');

    $editPerson=$person($user,'Eshan');$editId=$origin($user,$editPerson,'borrowed',50,'2026-08-30');$db->prepare('UPDATE karobar_transactions SET due_date=? WHERE id=?')->execute(['2026-08-25',$editId]);$service->processKarobarReminders($user,'2026-08-25 10:00:00');
    $db->prepare('UPDATE karobar_transactions SET due_date=? WHERE id=?')->execute(['2026-08-27',$editId]);$service->processKarobarReminders($user,'2026-08-27 10:00:00');$stmt=$db->prepare("SELECT COUNT(*) FROM notification_events WHERE user_id=? AND source_id=? AND event_type='karobar_due'");$stmt->execute([$user,$editId]);krAssert((int)$stmt->fetchColumn()===2,'due-date edit creates a distinct schedule identity while preserving old history');

    $archivedId=$origin($user,$person($user,'Archived','archived'),'lent',40,'2026-08-25');$deletedId=$origin($user,$person($user,'Deleted origin'),'lent',40,'2026-08-25');$db->prepare('DELETE FROM karobar_transactions WHERE id=?')->execute([$deletedId]);$service->processKarobarReminders($user,'2026-08-25 11:00:00');
    $stmt=$db->prepare('SELECT COUNT(*) FROM notification_events WHERE user_id=? AND source_id IN (?,?)');$stmt->execute([$user,$archivedId,$deletedId]);krAssert((int)$stmt->fetchColumn()===0,'archived-person and deleted Karobar records stop future reminders');

    $otherId=$origin($other,$person($other,'Foreign'),'lent',99,'2026-08-25');$service->processKarobarReminders($user,'2026-08-25 12:00:00');$stmt=$db->prepare('SELECT COUNT(*) FROM notification_events WHERE user_id=? AND source_id=?');$stmt->execute([$user,$otherId]);krAssert((int)$stmt->fetchColumn()===0,'Karobar reminder evaluation is owner isolated');

    $yearId=$origin($user,$person($user,'Year boundary'),'borrowed',25,'2026-12-31');$service->processKarobarReminders($user,'2027-01-01 00:01:00');$stmt=$db->prepare("SELECT COUNT(*) FROM notification_events WHERE user_id=? AND source_id=? AND event_type='karobar_overdue'");$stmt->execute([$user,$yearId]);krAssert((int)$stmt->fetchColumn()===1,'year-boundary overdue comparison uses Kathmandu calendar dates');

    $financialBefore=$db->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM karobar_transactions WHERE user_id={$user}")->fetch(PDO::FETCH_NUM);$service->processKarobarReminders($user,'2027-01-01 12:00:00');$financialAfter=$db->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM karobar_transactions WHERE user_id={$user}")->fetch(PDO::FETCH_NUM);
    krAssert($financialBefore===$financialAfter&&(int)$db->query("SELECT COUNT(*) FROM transactions WHERE user_id={$user}")->fetchColumn()===0,'reminder evaluation never mutates Karobar or transaction financial truth');
}finally{foreach(array_reverse($users)as$user)$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}
