<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Person.php';

class PersonController {
    private $personModel;

    public function __construct() {
        $this->personModel = new Person();
    }

    public function index() {
        $userId = Middleware::auth();
        
        $filters = [
            'status' => ($_GET['status'] ?? 'active') === 'all' ? null : ($_GET['status'] ?? 'active'),
            'type' => $_GET['type'] ?? null,
            'search' => $_GET['search'] ?? null
        ];
        
        $limit = max(1,min(200,(int)($_GET['limit'] ?? 20)));
        $page = max(1,(int)($_GET['page'] ?? 1));
        $offset = isset($_GET['offset'])?max(0,(int)$_GET['offset']):($page-1)*$limit;
        $page=(int)floor($offset/$limit)+1;
        
        $people = $this->personModel->findAll($userId, $filters, $limit, $offset);
        $total=$this->personModel->countAll($userId,$filters);
        Response::success(['people'=>$people,'pagination'=>['page'=>$page,'limit'=>$limit,'offset'=>$offset,'total_rows'=>$total,'total_pages'=>$total?(int)ceil($total/$limit):0,'has_previous'=>$page>1&&$total>0,'has_next'=>$offset+$limit<$total]]);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $person = $this->personModel->findById($id, $userId);
        
        if ($person) {
            Response::success($person);
        }
        
        Response::notFound('Person not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['name']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $personData = [
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'] ?? 'person',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'address' => $data['address'] ?? '',
            'photo' => $data['photo'] ?? '',
            'notes' => $data['notes'] ?? '',
            'status' => 'active'
        ];

        $personId = $this->personModel->create($personData);
        
        if ($personId) {
            $person = $this->personModel->findById($personId, $userId);
            Response::success($person, 'Person created successfully', 201);
        }
        
        Response::serverError('Person creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingPerson = $this->personModel->findById($id, $userId);
        if (!$existingPerson) {
            Response::notFound('Person not found');
        }

        $requestedStatus=$data['status']??$existingPerson['status'];
        if(!in_array($requestedStatus,['active','archived'],true)){
            Response::error('Invalid person status.',422);
        }

        $personData = [
            'name' => $data['name'] ?? $existingPerson['name'],
            'type' => $data['type'] ?? $existingPerson['type'] ?? 'person',
            'phone' => $data['phone'] ?? $existingPerson['phone'],
            'email' => $data['email'] ?? $existingPerson['email'],
            'address' => $data['address'] ?? $existingPerson['address'],
            'photo' => $data['photo'] ?? $existingPerson['photo'],
            'notes' => $data['notes'] ?? $existingPerson['notes'],
            'status' => $requestedStatus
        ];

        if ($this->personModel->update($id, $userId, $personData)) {
            $person = $this->personModel->findById($id, $userId);
            Response::success($person, 'Person updated successfully');
        }
        
        Response::serverError('Person update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        $person = $this->personModel->findById($id, $userId);
        
        if (!$person) {
            Response::notFound('Person not found');
        }

        $result=$this->personModel->removeSafely($id,$userId);
        if ($result) {
            $message=$result['action']==='archived'
                ? 'Person archived. Financial history and outstanding balances were preserved.'
                : 'Person permanently deleted because no financial history existed.';
            Response::success($result,$message);
        }
        
        Response::serverError('Person deletion failed');
    }

    public function ledger($personId) {
        $userId = Middleware::auth();
        $limit=max(1,min(200,(int)($_GET['limit']??25)));$page=max(1,(int)($_GET['page']??1));
        if(isset($_GET['offset']))$page=(int)floor(max(0,(int)$_GET['offset'])/$limit)+1;
        
        $person = $this->personModel->findById($personId, $userId);
        if (!$person) {
            Response::notFound('Person not found');
        }
        
        $filters=['start_date'=>$_GET['start_date']??null,'end_date'=>$_GET['end_date']??null,'type'=>$_GET['type']??null,'search'=>$_GET['search']??null];
        $filters=array_filter($filters,fn($v)=>$v!==null&&$v!=='');
        $result=$this->personModel->getLedgerPage((int)$personId,(int)$userId,$filters,$page,$limit);
        Response::success([
            'person' => $person,
            'ledger' => $result['ledger'],
            'balance' => $person['balance'],
            'pagination'=>$result['pagination'],
            'history_context'=>$result['history_context']
        ]);
    }
}
