<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../services/RecurringTransactionService.php';

class RecurringTransactionController {
    private RecurringTransactionService $service;

    public function __construct(){ $this->service=new RecurringTransactionService(); }

    public function index():void{$this->respond(fn($userId)=>Response::success($this->service->list($userId)));}
    public function show($id):void{$this->respond(fn($userId)=>Response::success($this->service->get($this->id($id),$userId)));}
    public function store():void{$this->respond(fn($userId)=>Response::success($this->service->create($userId,$this->body()),'Recurring definition created',201));}
    public function update($id):void{$this->respond(fn($userId)=>Response::success($this->service->update($this->id($id),$userId,$this->body()),'Recurring definition updated'));}
    public function deactivate($id):void{$this->respond(fn($userId)=>Response::success($this->service->deactivate($this->id($id),$userId),'Recurring definition deactivated'));}
    public function activate($id):void{$this->respond(function($userId)use($id){$body=$this->body();Response::success($this->service->activate($this->id($id),$userId,(string)($body['mode']??'resume')),'Recurring definition activation evaluated');});}
    public function review($id):void{$this->respond(fn($userId)=>Response::success($this->service->review($this->id($id),$userId)));}
    public function reconcile($id):void{$this->respond(function($userId)use($id){$body=$this->body();if(!isset($body['decisions'])||!is_array($body['decisions']))throw new InvalidArgumentException('Reconciliation decisions are required.');Response::success($this->service->reconcile($this->id($id),$userId,$body['decisions']),'Recurring occurrences reconciled');});}
    public function process():void{$this->respond(fn($userId)=>Response::success($this->service->processDue($userId),'Due recurring definitions processed'));}
    public function destroy($id):void{$this->respond(function($userId)use($id){$this->service->delete($this->id($id),$userId);Response::success(null,'Recurring definition deleted');});}

    private function respond(callable$operation):void{
        try{$operation((int)Middleware::auth());}
        catch(RecurringAuthorizationException$e){Response::notFound($e->getMessage());}
        catch(RecurringDueConflictException|RecurringHistoryConflictException$e){Response::error($e->getMessage(),409);}
        catch(RecurringDefinitionValidationException|InvalidArgumentException$e){Response::error($e->getMessage(),422);}
        catch(Throwable$e){Response::serverError($e->getMessage());}
    }

    private function body():array{$data=json_decode(file_get_contents('php://input'),true);if(!is_array($data))throw new InvalidArgumentException('A valid JSON object is required.');return$data;}
    private function id($value):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Invalid recurring definition identifier.');return(int)$id;}
}
