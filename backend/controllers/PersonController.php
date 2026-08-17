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
        
        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $people = $this->personModel->findAll($userId, $filters, $limit, $offset);
        Response::success($people);
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
        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $person = $this->personModel->findById($personId, $userId);
        if (!$person) {
            Response::notFound('Person not found');
        }
        
        $ledger = $this->personModel->getLedger($personId, $userId, $limit, $offset);
        Response::success([
            'person' => $person,
            'ledger' => $ledger,
            'balance' => $person['balance']
        ]);
    }
}
