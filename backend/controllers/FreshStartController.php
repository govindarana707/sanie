<?php

require_once __DIR__.'/../includes/response.php';
require_once __DIR__.'/../includes/middleware.php';
require_once __DIR__.'/../includes/rate_limiter.php';
require_once __DIR__.'/../includes/jwt.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/FreshStartService.php';

class FreshStartController
{
    private PDO $conn;
    private FreshStartService $service;

    public function __construct()
    {
        $this->conn=(new Database())->getConnection();
        if(!$this->conn)Response::serverError();
        $this->service=new FreshStartService($this->conn);
    }

    public function prepare():void
    {
        $userId=$this->authorize('prepare',3,3600);
        try{Response::success($this->service->prepare($userId),'Fresh Start overview ready.');}
        catch(Throwable$e){$this->fail($e,'Unable to prepare Fresh Start.');}
    }

    public function verify():void
    {
        $userId=$this->authorize('verify',5,900);$data=$this->json();
        try{
            $result=$this->service->verify($userId,(string)($data['operation_id']??''),$this->header('X-SanIE-Reset-Intent'),(string)($data['password']??''),(string)($data['confirmation_phrase']??''));
            Response::success($result,'Identity verified.');
        }catch(Throwable$e){$this->fail($e,'Unable to verify this reset request.');}
    }

    public function export():void
    {
        $userId=$this->authorize('export',5,900);$data=$this->json();
        try{Response::success($this->service->export($userId,(string)($data['operation_id']??''),$this->header('X-SanIE-Reset-Intent')),'Fresh Start export created.');}
        catch(Throwable$e){$this->fail($e,'Unable to create the export.');}
    }

    public function execute():void
    {
        $userId=$this->authorize('execute',2,3600);$data=$this->json();
        if(($data['final_confirmation']??null)!==true)Response::error('Final confirmation is required.',422);
        try{
            $result=$this->service->execute($userId,(string)($data['operation_id']??''),$this->header('X-SanIE-Reset-Intent'),$this->header('X-SanIE-Reset-Confirmation'));
            $result['token']=JWT::encode(['user_id'=>$userId,'token_version'=>$result['token_version'],'data_generation'=>$result['data_generation']]);
            $message=$result['cleanup_status']==='complete'
                ?'Fresh start complete. Your personal data has been reset, and your SanIE account is ready to use.'
                :'Your records were reset, but file cleanup is still pending.';
            Response::success($result,$message,$result['cleanup_status']==='complete'?200:202);
        }catch(Throwable$e){$this->fail($e,'Fresh Start could not be completed. No partial database reset was kept.');}
    }

    public function status():void
    {
        $userId=$this->authorize('status',10,900);$data=$this->json();
        try{Response::success($this->service->status($userId,(string)($data['operation_id']??'')));}
        catch(Throwable$e){$this->fail($e,'Unable to check reset cleanup status.');}
    }

    private function authorize(string$action,int$limit,int$window):int
    {
        $this->assertTrustedOrigin();
        $userId=Middleware::auth();
        $key='fresh-start:'.$action.':'.$userId.':'.($_SERVER['REMOTE_ADDR']??'unknown');
        if(!RateLimiter::hitStrict($key,$limit,$window))Response::error('Too many reset attempts. Try again later.',429);
        return$userId;
    }

    private function assertTrustedOrigin():void
    {
        $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
        if($origin!==''&&!in_array($origin,ALLOWED_ORIGINS,true))Response::forbidden('Request origin is not allowed.');
        if(strtolower((string)($_SERVER['CONTENT_TYPE']??''))!==''&&!str_contains(strtolower((string)$_SERVER['CONTENT_TYPE']),'application/json'))Response::error('JSON content is required.',415);
    }

    private function json():array
    {
        $data=json_decode(file_get_contents('php://input'),true);
        if(!is_array($data))Response::error('A valid JSON object is required.',422);
        return$data;
    }

    private function header(string$name):string
    {
        $key='HTTP_'.strtoupper(str_replace('-','_',$name));
        return trim((string)($_SERVER[$key]??''));
    }

    private function fail(Throwable$e,string$fallback):void
    {
        if($e instanceof FreshStartValidationException)Response::error($e->getMessage(),$e->status);
        error_log('[SanIE] Fresh Start '.$fallback.' user-scoped operation failed: '.get_class($e));
        Response::serverError($fallback);
    }
}
