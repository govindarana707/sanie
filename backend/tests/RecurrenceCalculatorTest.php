<?php

require_once __DIR__.'/../services/RecurrenceCalculator.php';

function rcAssert($expected,$actual,string$message):void{if($expected!==$actual)throw new RuntimeException("{$message}: expected ".json_encode($expected).', got '.json_encode($actual));}
$c=new RecurrenceCalculator();$passed=0;$failed=0;
$run=function(string$name,callable$test)use(&$passed,&$failed):void{try{$test();$passed++;echo"PASS: $name\n";}catch(Throwable$e){$failed++;fwrite(STDERR,"FAIL: $name - {$e->getMessage()}\n");}};
$base=fn(array$overrides)=>array_merge(['frequency'=>'daily','start_date'=>'2026-08-24','end_date'=>null,'day_of_week'=>null,'day_of_month'=>null,'next_occurrence'=>null],$overrides);

$run('daily start is inclusive and advances one day',function()use($c,$base){$d=$base([]);rcAssert('2026-08-24',$c->initialOccurrence($d),'daily initial');rcAssert('2026-08-25',$c->nextOccurrence($d,'2026-08-24'),'daily next');});
$run('weekly ISO weekdays Monday through Sunday',function()use($c,$base){foreach(range(1,7)as$iso){$d=$base(['frequency'=>'weekly','day_of_week'=>$iso,'start_date'=>'2026-08-24']);$expected=(new DateTimeImmutable('2026-08-24'))->modify('+'.($iso-1).' days')->format('Y-m-d');rcAssert($expected,$c->initialOccurrence($d),"ISO weekday {$iso}");}});
$run('bi-weekly remains anchored every fourteen days',function()use($c,$base){$d=$base(['frequency'=>'bi_weekly','day_of_week'=>1]);rcAssert('2026-09-07',$c->nextOccurrence($d,'2026-08-24'),'bi-weekly next');rcAssert('2026-09-21',$c->nextOccurrence($d,'2026-09-07'),'bi-weekly second');});
$run('monthly 31 clamps and restores anchor',function()use($c,$base){$d=$base(['frequency'=>'monthly','day_of_month'=>31,'start_date'=>'2026-01-31']);rcAssert('2026-02-28',$c->nextOccurrence($d,'2026-01-31'),'February clamp');rcAssert('2026-03-31',$c->nextOccurrence($d,'2026-02-28'),'March restore');});
$run('monthly 29 and 30 clamp without drift',function()use($c,$base){foreach([29,30]as$day){$d=$base(['frequency'=>'monthly','day_of_month'=>$day,'start_date'=>"2026-01-{$day}"]);rcAssert('2026-02-28',$c->nextOccurrence($d,"2026-01-{$day}"),"day {$day} clamp");rcAssert("2026-03-{$day}",$c->nextOccurrence($d,'2026-02-28'),"day {$day} restore");}});
$run('quarterly preserves original day anchor',function()use($c,$base){$d=$base(['frequency'=>'quarterly','day_of_month'=>31,'start_date'=>'2026-11-30']);rcAssert('2026-11-30',$c->initialOccurrence($d),'quarterly initial clamp');rcAssert('2027-02-28',$c->nextOccurrence($d,'2026-11-30'),'quarterly February clamp');rcAssert('2027-05-31',$c->nextOccurrence($d,'2027-02-28'),'quarterly anchor restore');});
$run('yearly February 29 clamps and restores in leap year',function()use($c,$base){$d=$base(['frequency'=>'yearly','start_date'=>'2024-02-29']);rcAssert('2025-02-28',$c->nextOccurrence($d,'2024-02-29'),'non-leap clamp');rcAssert('2026-02-28',$c->nextOccurrence($d,'2025-02-28'),'second non-leap');rcAssert('2028-02-29',$c->initialOccurrence($d,'2028-01-01'),'leap restore');});
$run('start and end dates are inclusive',function()use($c,$base){$d=$base(['end_date'=>'2026-08-25']);rcAssert('2026-08-24',$c->initialOccurrence($d),'inclusive start');rcAssert('2026-08-25',$c->nextOccurrence($d,'2026-08-24'),'inclusive end');rcAssert(null,$c->nextOccurrence($d,'2026-08-25'),'after end');});
$run('due list distinguishes one from multiple',function()use($c,$base){$one=$base(['next_occurrence'=>'2026-08-24']);rcAssert(['2026-08-24'],$c->dueOccurrences($one,'2026-08-24',2),'one due');$many=$base(['next_occurrence'=>'2026-08-23']);rcAssert(['2026-08-23','2026-08-24'],$c->dueOccurrences($many,'2026-08-24',2),'multiple due');});
$run('invalid ISO weekday is rejected',function()use($c,$base){try{$c->initialOccurrence($base(['frequency'=>'weekly','day_of_week'=>0]));throw new RuntimeException('invalid weekday accepted');}catch(InvalidArgumentException$e){rcAssert(true,str_contains($e->getMessage(),'ISO weekday'),'wrong validation');}});

echo"RESULT: $passed passed, $failed failed, 0 skipped\n";exit($failed?1:0);
