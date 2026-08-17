<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../services/ReportingPaginationService.php';

class LedgerController {
    private $transactionModel;
    private $accountModel;
    private $categoryModel;
    private $reportingService;

    public function __construct() {
        $this->transactionModel = new Transaction();
        $this->accountModel = new Account();
        $this->categoryModel = new Category();
        $this->reportingService = new ReportingPaginationService();
    }

    public function index() {
        $userId = Middleware::auth();

        $filters = [
            'type' => $_GET['type'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'account_id' => $_GET['account_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'search' => $_GET['search'] ?? null,
            'subcategory_id' => $_GET['subcategory_id'] ?? null
        ];
        $page=max(1,(int)($_GET['page']??1));$limit=max(1,min(100,(int)($_GET['limit']??25)));
        foreach(['start_date','end_date']as$key)if(!empty($filters[$key])&&!$this->validDate($filters[$key]))Response::error('Invalid ledger date.',422);
        if(!empty($filters['start_date'])&&!empty($filters['end_date'])&&$filters['start_date']>$filters['end_date'])Response::error('Invalid ledger date range.',422);
        if(!empty($filters['account_id'])&&!$this->accountModel->findById($filters['account_id'],$userId))Response::notFound('Account not found');
        $result=$this->reportingService->ledgerPage($userId,$filters,$page,$limit);$result['total_count']=$result['pagination']['total_rows'];$result['filters']=['page'=>$page,'limit'=>$limit];
        Response::success($result);
    }

    public function filters() {
        $userId = Middleware::auth();

        $accounts = $this->accountModel->findAll($userId);
        $categories = $this->categoryModel->findAll($userId);

        Response::success([
            'accounts' => $accounts,
            'categories' => $categories,
        ]);
    }

    private function validDate($value):bool{$date=DateTime::createFromFormat('!Y-m-d',(string)$value);return$date&&$date->format('Y-m-d')===(string)$value;}
}
