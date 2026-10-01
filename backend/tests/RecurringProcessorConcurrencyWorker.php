<?php

$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../services/AccountingService.php';

[$script,$definitionId,$userId,$barrier]=$argv;
$deadline=microtime(true)+10;
while(!file_exists($barrier)&&microtime(true)<$deadline)usleep(10000);
try{
    $result=(new AccountingService())->processRecurringOccurrence((int)$definitionId,(int)$userId,null,'generate',false,true);
    echo json_encode(['ok'=>true,'status'=>$result['status']??null]);
}catch(Throwable$e){echo json_encode(['ok'=>false,'class'=>get_class($e),'message'=>$e->getMessage()]);}
