<?php
$_SERVER['HTTP_HOST']='localhost';require_once __DIR__.'/../services/CredentialService.php';
[$script,$userId,$current,$next,$barrier]=$argv;$deadline=microtime(true)+10;while(!file_exists($barrier)&&microtime(true)<$deadline)usleep(10000);
try{$result=(new CredentialService())->changePassword((int)$userId,['current_password'=>base64_decode($current,true),'new_password'=>base64_decode($next,true),'new_password_confirmation'=>base64_decode($next,true)]);echo json_encode(['ok'=>true,'version'=>$result['token_version']]);}catch(Throwable$e){echo json_encode(['ok'=>false,'class'=>get_class($e),'status'=>$e instanceof CredentialValidationException?$e->status:500]);}
