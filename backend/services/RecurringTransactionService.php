<?php

require_once __DIR__ . '/../models/RecurringTransaction.php';
require_once __DIR__ . '/RecurrenceCalculator.php';
require_once __DIR__ . '/AccountingService.php';
require_once __DIR__ . '/NotificationService.php';

class RecurringDueConflictException extends RuntimeException {}
class RecurringHistoryConflictException extends RuntimeException {}
class RecurringAuthorizationException extends RuntimeException {}

class RecurringTransactionService {
    private RecurringTransaction $model;
    private RecurrenceCalculator $calculator;
    private AccountingService $accounting;

    public function __construct() {
        $this->model=new RecurringTransaction();
        $this->calculator=new RecurrenceCalculator();
        $this->accounting=new AccountingService();
    }

    public function list(int $userId): array {
        return array_map(fn($row)=>$this->enrich($row),$this->model->findAll($userId));
    }

    public function get(int $id,int $userId): array {
        $row=$this->model->findById($id,$userId);
        if (!$row) throw new RecurringAuthorizationException('Recurring definition not found.');
        return $this->enrich($row);
    }

    public function create(int $userId,array $input): array {
        $definition=$this->normalize($input,$userId);
        $definition['next_occurrence']=$this->calculator->initialOccurrence($definition);
        if ($definition['next_occurrence']===null) $definition['is_active']=0;
        $this->accounting->validateRecurringTemplate($userId,$definition,$definition['next_occurrence']??$definition['start_date']);
        return $this->get($this->model->create($definition),$userId);
    }

    public function update(int $id,int $userId,array $input): array {
        $existing=$this->model->findById($id,$userId);
        if (!$existing) throw new RecurringAuthorizationException('Recurring definition not found.');
        $editable=['account_id','category_id','subcategory_id','amount','type','frequency','day_of_month','day_of_week','start_date','end_date','description','notes'];
        $candidate=[];
        foreach($editable as$field)$candidate[$field]=array_key_exists($field,$input)?$input[$field]:$existing[$field];
        $candidate['is_active']=(int)$existing['is_active'];
        $candidate=$this->normalize($candidate,$userId);
        $changed=array_values(array_filter($editable,fn($field)=>$this->different($existing[$field]??null,$candidate[$field]??null)));
        if ($changed&&!empty($existing['next_occurrence'])&&$existing['next_occurrence']<=$this->calculator->today()) {
            throw new RecurringDueConflictException('Reconcile the due occurrence history before editing this recurring definition.');
        }
        $scheduleFields=['frequency','day_of_month','day_of_week','start_date','end_date'];
        $scheduleChanged=(bool)array_intersect($changed,$scheduleFields);
        if ($scheduleChanged&&empty($input['confirm_schedule_change'])) {
            throw new InvalidArgumentException('Schedule changes require explicit confirmation.');
        }
        $candidate['next_occurrence']=$scheduleChanged
            ? $this->calculator->initialOccurrence($candidate,max($candidate['start_date'],$this->calculator->today()))
            : $existing['next_occurrence'];
        if ($candidate['next_occurrence']===null) $candidate['is_active']=0;
        $this->accounting->validateRecurringTemplate($userId,$candidate,$candidate['next_occurrence']??$candidate['start_date']);
        $this->model->update($id,$userId,$candidate);
        return $this->get($id,$userId);
    }

    public function deactivate(int $id,int $userId): array {
        $this->get($id,$userId);
        $this->model->setState($id,$userId,false,null,true);
        return $this->get($id,$userId);
    }

    public function activate(int $id,int $userId,string $mode='resume'): array {
        if (!in_array($mode,['resume','review'],true)) throw new InvalidArgumentException('Activation mode must be resume or review.');
        $definition=$this->get($id,$userId);
        $this->accounting->validateRecurringTemplate($userId,$definition,$definition['next_occurrence']??$definition['start_date']);
        if ($mode==='review') {
            $review=$this->review($id,$userId);
            if ($review['occurrences']) return ['status'=>'review_required','definition'=>$definition]+$review;
        }
        $next=$this->calculator->initialOccurrence($definition,max($definition['start_date'],$this->calculator->today()));
        $this->model->setState($id,$userId,$next!==null,$next,false);
        return ['status'=>$next===null?'ended':'activated','definition'=>$this->get($id,$userId)];
    }

    public function delete(int $id,int $userId): void {
        $this->get($id,$userId);
        if ($this->model->generatedCount($id,$userId)>0) {
            throw new RecurringHistoryConflictException('Deactivate this recurring definition because generated transaction history still references it.');
        }
        if (!$this->model->delete($id,$userId)) throw new RuntimeException('Recurring definition deletion failed.');
    }

    public function review(int $id,int $userId): array {
        $definition=$this->get($id,$userId);
        $occurrences=$this->calculator->dueOccurrences($definition,$this->calculator->today(),10000);
        return ['definition_id'=>$id,'occurrences'=>$occurrences,'count'=>count($occurrences)];
    }

    public function processDue(int $userId): array {
        $today=$this->calculator->today();
        $result=['generated'=>[],'already_processed'=>[],'review_required'=>[],'skipped_inactive'=>[],'failed_validation'=>[],'failed'=>[]];
        foreach($this->model->dueDefinitionIds($userId,$today)as$id){
            try{
                $definition=$this->model->findById($id,$userId);
                if(!$definition){$result['skipped_inactive'][]=['definition_id'=>$id];continue;}
                $due=$this->calculator->dueOccurrences($definition,$today,2);
                if(count($due)>1){
                    $entry=['definition_id'=>$id,'next_occurrence'=>$definition['next_occurrence'],'due_occurrences'=>$this->calculator->dueOccurrences($definition,$today,10000)];
                    $result['review_required'][]=$entry;
                    try{(new NotificationService())->notifyRecurringReviewOnce($userId,$id,(string)$definition['next_occurrence'],count($entry['due_occurrences']));}
                    catch(Throwable$notificationError){error_log('Recurring review notification failed: '.$notificationError->getMessage());}
                    continue;
                }
                $outcome=$this->accounting->processRecurringOccurrence($id,$userId,null,'generate',false,true);
                $bucket=match($outcome['status']){
                    'generated'=>'generated','already_processed'=>'already_processed','failed_validation'=>'failed_validation',
                    'review_required'=>'review_required',default=>'skipped_inactive'
                };
                $result[$bucket][]=$outcome;
            }catch(Throwable$e){
                $result['failed'][]=['definition_id'=>$id,'message'=>$e->getMessage()];
            }
        }
        $result['counts']=array_map('count',$result);
        return$result;
    }

    public function reconcile(int $id,int $userId,array $decisions): array {
        $definition=$this->get($id,$userId);
        $due=$this->calculator->dueOccurrences($definition,$this->calculator->today(),10000);
        if(!$due&&$decisions)return['status'=>'already_reconciled','definition'=>$definition,'results'=>[]];
        $pending=[];
        foreach($decisions as$decision){
            $date=is_array($decision)?($decision['date']??null):null;$action=is_array($decision)?($decision['action']??null):null;
            if(!is_string($date)||!in_array($action,['generate','skip'],true)||$date>$this->calculator->today()||!$this->calculator->isOccurrenceInSequence($definition,$date)){
                throw new InvalidArgumentException('Reconciliation decisions must match the authoritative due occurrence sequence.');
            }
            if(!empty($definition['next_occurrence'])&&$date>=$definition['next_occurrence'])$pending[]=['date'=>$date,'action'=>$action];
        }
        if(!$pending&&$decisions)return['status'=>'already_reconciled','definition'=>$definition,'results'=>[]];
        if(count($pending)!==count($due))throw new InvalidArgumentException('A Generate or Skip decision is required for every due occurrence.');
        foreach($pending as$index=>$decision){
            if($decision['date']!==$due[$index])throw new InvalidArgumentException('Reconciliation decisions must match the authoritative due occurrence sequence.');
        }
        $wasActive=!empty($definition['is_active']);$results=[];
        foreach($pending as$decision){
            $results[]=$this->accounting->processRecurringOccurrence($id,$userId,$decision['date'],$decision['action'],true,false);
        }
        $updated=$this->get($id,$userId);
        if(!$wasActive&&!empty($updated['next_occurrence'])){
            $this->model->setState($id,$userId,true,null,true);$updated=$this->get($id,$userId);
        }
        return['status'=>'reconciled','definition'=>$updated,'results'=>$results];
    }

    private function normalize(array $input,int$userId):array{
        $type=(string)($input['type']??'');if(!in_array($type,['income','expense'],true))throw new InvalidArgumentException('Recurring type must be income or expense.');
        $amount=$input['amount']??null;if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||(float)$amount>999999999999.99)throw new InvalidArgumentException('Amount must be a valid positive value.');
        foreach(['account_id','category_id']as$field){$value=filter_var($input[$field]??null,FILTER_VALIDATE_INT);if($value===false||$value<1)throw new InvalidArgumentException("A valid {$field} is required.");$input[$field]=$value;}
        $sub=$input['subcategory_id']??null;if($sub!==null&&$sub!==''){$sub=filter_var($sub,FILTER_VALIDATE_INT);if($sub===false||$sub<1)throw new InvalidArgumentException('Invalid subcategory.');}else$sub=null;
        $description=trim((string)($input['description']??''));if(mb_strlen($description)>255)throw new InvalidArgumentException('Description must be 255 characters or fewer.');
        $definition=['user_id'=>$userId,'account_id'=>(int)$input['account_id'],'category_id'=>(int)$input['category_id'],'subcategory_id'=>$sub,
            'amount'=>(float)$amount,'type'=>$type,'frequency'=>(string)($input['frequency']??''),'day_of_month'=>$input['day_of_month']??null,
            'day_of_week'=>$input['day_of_week']??null,'start_date'=>(string)($input['start_date']??''),'end_date'=>($input['end_date']??null)?:null,
            'description'=>$description,'notes'=>trim((string)($input['notes']??''))?:null,'is_active'=>array_key_exists('is_active',$input)?(int)(bool)$input['is_active']:1];
        if(!in_array($definition['frequency'],['weekly','bi_weekly'],true))$definition['day_of_week']=null;
        if(!in_array($definition['frequency'],['monthly','quarterly'],true))$definition['day_of_month']=null;
        $this->calculator->validateSchedule($definition);return$definition;
    }

    private function enrich(array$row):array{
        $due=[];if(!empty($row['next_occurrence'])&&$row['next_occurrence']<=$this->calculator->today())$due=$this->calculator->dueOccurrences($row,$this->calculator->today(),10000);
        $row['is_active']=(bool)$row['is_active'];$row['generated_count']=(int)($row['generated_count']??0);$row['due_occurrences']=$due;$row['review_required']=count($due)>1;return$row;
    }

    private function different($left,$right):bool{return(string)($left??'')!==(string)($right??'');}
}
