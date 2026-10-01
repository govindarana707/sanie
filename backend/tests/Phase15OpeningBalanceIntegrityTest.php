<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../models/Account.php';

function p15obAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=(new Database())->getConnection();$user=null;$failed=false;
try{
 $db->prepare("INSERT INTO users(email,password,first_name)VALUES(?,?,'Opening Test')")->execute(['opening-p15-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();$model=new Account();
 foreach(['0','1','1.50','-25.75','999999999999.99','-999999999999.99']as$i=>$value){$id=$model->create(['user_id'=>$user,'name'=>'Valid '.$i,'type'=>$value[0]==='-'?'credit_card':'cash','opening_balance'=>$value,'balance'=>$value,'is_active'=>1]);p15obAssert($id>0,'valid opening balance rejected: '.$value);}
 $before=(int)$db->query("SELECT COUNT(*) FROM accounts WHERE user_id={$user}")->fetchColumn();
 foreach(['1.999','0.001','abc','NaN','Infinity','1e2','1000000000000.00','-1000000000000.00']as$i=>$value){try{$model->create(['user_id'=>$user,'name'=>'Invalid '.$i,'type'=>'cash','opening_balance'=>$value,'balance'=>$value,'is_active'=>1]);throw new RuntimeException('invalid opening balance accepted: '.$value);}catch(InvalidArgumentException$expected){}}
 p15obAssert((int)$db->query("SELECT COUNT(*) FROM accounts WHERE user_id={$user}")->fetchColumn()===$before,'invalid account state persisted');
 $id=(int)$db->query("SELECT id FROM accounts WHERE user_id={$user} ORDER BY id LIMIT 1")->fetchColumn();$existing=$model->findById($id,$user);$valid=array_merge($existing,['opening_balance'=>'12.34']);p15obAssert($model->update($id,$user,$valid),'valid opening edit failed');
 foreach(['12.345','1e2','1000000000000.00']as$value){try{$model->update($id,$user,array_merge($valid,['opening_balance'=>$value]));throw new RuntimeException('invalid opening edit accepted: '.$value);}catch(InvalidArgumentException$expected){}}
 p15obAssert((float)$model->findById($id,$user)['opening_balance']===12.34,'invalid opening edit changed account');
 echo"PASS: signed account opening balances are strict on create and edit without silent coercion\n";
}catch(Throwable$e){$failed=true;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");}finally{if($user){$db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$user]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}}exit($failed?1:0);
