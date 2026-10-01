<?php
$_SERVER['HTTP_HOST']='localhost';require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../models/Task.php';
function tsAssert(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);echo"PASS: {$message}\n";}
$db=(new Database())->getConnection();$owner=null;$other=null;
try{
 foreach(['owner','other']as$label){$db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Task Restore')")->execute(["task-restore-{$label}-".bin2hex(random_bytes(4)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);if($label==='owner')$owner=(int)$db->lastInsertId();else$other=(int)$db->lastInsertId();}
 $tasks=new Task($db);$data=['task_type'=>'board_study','title'=>'Database Administration','content'=>'Restore content','due_date'=>'2026-09-01','display_date_bs'=>'2083-05-17','subject'=>'Database Administration','unit_label'=>'Unit 1','status'=>'in_progress','priority'=>'urgent','reminder_at'=>'2026-08-30 09:15:00','summary_url'=>'https://drive.google.com/file/d/restore-summary/view','seed_key'=>'restore-board-fixture'];$id=$tasks->create($owner,$data);
 tsAssert($tasks->delete($id,$owner)&&!$tasks->findById($id,$owner),'soft delete hides task from normal reads');
 $deleted=$tasks->findDeleted($owner);tsAssert(count($deleted)===1&&(int)$deleted[0]['id']===$id,'deleted task remains internally queryable');
 tsAssert(!$tasks->restore($id,$other),'foreign user cannot restore task');
 tsAssert($tasks->restore($id,$owner),'owner can restore soft-deleted task');$restored=$tasks->findById($id,$owner);
 foreach(['task_type','title','content','due_date','display_date_bs','subject','unit_label','status','priority','reminder_at','summary_url','seed_key']as$field)tsAssert((string)$restored[$field]===(string)$data[$field],"restore preserves {$field}");
 tsAssert($tasks->restore($id,$owner),'repeated restore is idempotent');
 tsAssert(count($tasks->findDeleted($owner))===0,'restored task leaves deleted list');
}finally{foreach(array_filter([$owner,$other])as$uid)$db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);}
