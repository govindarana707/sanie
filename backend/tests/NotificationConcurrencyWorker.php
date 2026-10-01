<?php
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../services/NotificationService.php';
[$script,$user,$origin,$barrier]=$argv;
$deadline=microtime(true)+10;while(!file_exists($barrier)&&microtime(true)<$deadline)usleep(1000);
try{$r=(new NotificationService())->processKarobarReminders((int)$user,'2026-08-25 12:00:00');echo json_encode($r);exit(0);}catch(Throwable$e){fwrite(STDERR,$e->getMessage());exit(1);}
