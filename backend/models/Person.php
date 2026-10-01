<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/KarobarOutstandingService.php';

class Person {
    private $conn;
    private $table = 'people';
    private $outstandingService;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->outstandingService = new KarobarOutstandingService();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, name, type, phone, email, address, photo, notes, status) 
                  VALUES (:user_id, :name, :type, :phone, :email, :address, :photo, :notes, :status)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':photo', $data['photo']);
        $stmt->bindParam(':notes', $data['notes']);
        $stmt->bindParam(':status', $data['status']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId, $filters = [], $limit = 100, $offset = 0) {
        $query = "SELECT p.*,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as total_lent,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as total_borrowed,
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as total_returned,
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as total_repaid,
                  COUNT(kt.id) as transaction_count,
                  MAX(kt.created_at) as last_transaction_date
                  FROM " . $this->table . " p
                  LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2";
        
        $params = [':user_id' => $userId, ':user_id2' => $userId];
        
        if (!empty($filters['status'])) {
            $query .= " AND p.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['type'])) {
            $query .= " AND p.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (p.name LIKE :search OR p.phone LIKE :search2 OR p.email LIKE :search3 OR p.notes LIKE :search4)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $params[':search4'] = $searchTerm;
        }
        
        $query .= " GROUP BY p.id ORDER BY p.name ASC,p.id ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $positions=[];
        foreach($this->outstandingService->getPersonPositions($userId) as$position)$positions[(int)$position['id']]=$position;
        foreach ($people as &$person) {
            $position=$positions[(int)$person['id']]??[];
            $person['receivable_outstanding']=(float)($position['receivable_outstanding']??0);
            $person['payable_outstanding']=(float)($position['payable_outstanding']??0);
            $person['overdue_receivable']=(float)($position['overdue_receivable']??0);
            $person['overdue_payable']=(float)($position['overdue_payable']??0);
            $person['overdue_count']=(int)($position['overdue_count']??0);
            $person['balance']=$person['receivable_outstanding']-$person['payable_outstanding'];
        }
        
        return $people;
    }

    public function countAll($userId, array $filters = []): int {
        $query='SELECT COUNT(*) FROM people p WHERE p.user_id=:user_id';$params=[':user_id'=>$userId];
        if(!empty($filters['status'])){$query.=' AND p.status=:status';$params[':status']=$filters['status'];}
        if(!empty($filters['type'])){$query.=' AND p.type=:type';$params[':type']=$filters['type'];}
        if(!empty($filters['search'])){$query.=' AND (p.name LIKE :s1 OR p.phone LIKE :s2 OR p.email LIKE :s3 OR p.notes LIKE :s4)';$term='%'.$filters['search'].'%';foreach([':s1',':s2',':s3',':s4']as$key)$params[$key]=$term;}
        $stmt=$this->conn->prepare($query);$stmt->execute($params);return(int)$stmt->fetchColumn();
    }

    public function findById($id, $userId) {
        $query = "SELECT p.* FROM " . $this->table . " p
                  WHERE p.id = :id AND p.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($person) {
            $person['total_lent'] = $this->getPersonAggregate($id, $userId, 'lent');
            $person['total_borrowed'] = $this->getPersonAggregate($id, $userId, 'borrowed');
            $person['total_returned'] = $this->getPersonAggregate($id, $userId, 'returned');
            $person['total_repaid'] = $this->getPersonAggregate($id, $userId, 'repaid');
            $person['transaction_count'] = $this->getTransactionCount($id, $userId);
            $person['last_transaction_date'] = $this->getLastTransactionDate($id, $userId);
            $position=$this->outstandingService->getPersonPosition($userId,(int)$id);
            $person['receivable_outstanding']=(float)($position['receivable_outstanding']??0);
            $person['payable_outstanding']=(float)($position['payable_outstanding']??0);
            $person['overdue_receivable']=(float)($position['overdue_receivable']??0);
            $person['overdue_payable']=(float)($position['overdue_payable']??0);
            $person['overdue_count']=(int)($position['overdue_count']??0);
            $person['active_count']=(int)($position['active_count']??0);
            $person['settled_count']=(int)($position['settled_count']??0);
            $person['balance']=$person['receivable_outstanding']-$person['payable_outstanding'];
        }
        
        return $person;
    }

    public function findActiveById($id, $userId) {
        $person = $this->findById($id, $userId);
        return $person && ($person['status'] ?? 'active') === 'active' ? $person : false;
    }

    public function update($id, $userId, $data) {
        $query = "UPDATE " . $this->table . " SET 
                  name = :name,
                  type = :type,
                  phone = :phone,
                  email = :email,
                  address = :address,
                  photo = :photo,
                  notes = :notes,
                  status = :status,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':photo', $data['photo']);
        $stmt->bindParam(':notes', $data['notes']);
        $stmt->bindParam(':status', $data['status']);
        
        return $stmt->execute();
    }

    /**
     * A person without financial history is deleted. A person referenced by
     * Karobar history is archived so every financial person_id stays valid.
     */
    public function removeSafely($id, $userId): ?array {
        $this->conn->beginTransaction();
        try {
            $stmt=$this->conn->prepare('SELECT * FROM people WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE');
            $stmt->execute([':id'=>(int)$id,':uid'=>(int)$userId]);
            $person=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$person){$this->conn->rollBack();return null;}

            $stmt=$this->conn->prepare('SELECT COUNT(*) FROM karobar_transactions WHERE person_id=:id AND user_id=:uid');
            $stmt->execute([':id'=>(int)$id,':uid'=>(int)$userId]);
            $historyCount=(int)$stmt->fetchColumn();
            if($historyCount>0){
                $stmt=$this->conn->prepare("UPDATE people SET status='archived',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:uid");
                $stmt->execute([':id'=>(int)$id,':uid'=>(int)$userId]);
                $action='archived';
            }else{
                $stmt=$this->conn->prepare('DELETE FROM people WHERE id=:id AND user_id=:uid');
                $stmt->execute([':id'=>(int)$id,':uid'=>(int)$userId]);
                $action='deleted';
            }
            $this->conn->commit();
            return ['action'=>$action,'person_id'=>(int)$id,'history_count'=>$historyCount];
        }catch(Throwable $e){
            if($this->conn->inTransaction())$this->conn->rollBack();
            throw $e;
        }
    }

    public function delete($id, $userId) {
        return $this->removeSafely($id,$userId);
    }

    public function getLedger($personId, $userId, $limit = 100, $offset = 0) {
        return $this->getLedgerPage($personId,$userId,[],max(1,(int)floor($offset/max(1,$limit))+1),$limit)['ledger'];
    }

    public function getLedgerPage(int $personId,int $userId,array $filters,int $page,int $limit):array {
        $parts=['kt.person_id=:person_id','kt.user_id=:user_id'];$params=[':person_id'=>$personId,':user_id'=>$userId];
        if(!empty($filters['start_date'])){$parts[]='kt.transaction_date>=:start_date';$params[':start_date']=$filters['start_date'];}
        if(!empty($filters['end_date'])){$parts[]='kt.transaction_date<=:end_date';$params[':end_date']=$filters['end_date'];}
        if(!empty($filters['type'])){$parts[]='kt.type=:ledger_type';$params[':ledger_type']=$filters['type'];}
        if(!empty($filters['search'])){$parts[]='(kt.description LIKE :search1 OR kt.type LIKE :search2 OR CAST(kt.amount AS CHAR) LIKE :search3)';$term='%'.$filters['search'].'%';$params[':search1']=$term;$params[':search2']=$term;$params[':search3']=$term;}
        $visible=implode(' AND ',$parts);$offset=($page-1)*$limit;
        $delta="CASE kt.type WHEN 'lent' THEN kt.amount WHEN 'borrowed' THEN -kt.amount WHEN 'returned' THEN -kt.amount WHEN 'repaid' THEN kt.amount WHEN 'adjustment' THEN kt.amount ELSE 0 END";
        $scored="SELECT kt.*,a.name account_name,SUM({$delta}) OVER(ORDER BY kt.transaction_date ASC,kt.created_at ASC,kt.id ASC) running_balance
                 FROM karobar_transactions kt LEFT JOIN accounts a ON a.id=kt.account_id
                 WHERE kt.person_id=:score_person AND kt.user_id=:score_user";
        $query="SELECT scored.* FROM ({$scored}) scored JOIN karobar_transactions kt ON kt.id=scored.id WHERE {$visible}
                ORDER BY scored.transaction_date ASC,scored.created_at ASC,scored.id ASC LIMIT :page_limit OFFSET :page_offset";
        $queryParams=array_merge([':score_person'=>$personId,':score_user'=>$userId],$params);
        $stmt=$this->conn->prepare($query);foreach($queryParams as$key=>$value)$stmt->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);$stmt->bindValue(':page_limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':page_offset',$offset,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row)$row['running_balance']=(float)$row['running_balance'];unset($row);
        $count=$this->conn->prepare("SELECT COUNT(*) FROM karobar_transactions kt WHERE {$visible}");foreach($params as$key=>$value)$count->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);$count->execute();$total=(int)$count->fetchColumn();
        $monthlyStmt=$this->conn->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') month,type,SUM(amount) amount FROM karobar_transactions WHERE person_id=? AND user_id=? GROUP BY DATE_FORMAT(transaction_date,'%Y-%m'),type ORDER BY month ASC");$monthlyStmt->execute([$personId,$userId]);
        $recentStmt=$this->conn->prepare('SELECT kt.*,a.name account_name FROM karobar_transactions kt LEFT JOIN accounts a ON a.id=kt.account_id WHERE kt.person_id=? AND kt.user_id=? ORDER BY kt.transaction_date DESC,kt.created_at DESC,kt.id DESC LIMIT 10');$recentStmt->execute([$personId,$userId]);
        return['ledger'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'offset'=>$offset,'total_rows'=>$total,'total_pages'=>$total?(int)ceil($total/$limit):0,'has_previous'=>$page>1&&$total>0,'has_next'=>$page*$limit<$total],'history_context'=>['monthly'=>$monthlyStmt->fetchAll(PDO::FETCH_ASSOC),'recent'=>$recentStmt->fetchAll(PDO::FETCH_ASSOC)]];
    }

    private function calculateRunningBalance($tx, $currentBalance) {
        switch ($tx['type']) {
            case 'lent':
                return $currentBalance + floatval($tx['amount']);
            case 'borrowed':
                return $currentBalance - floatval($tx['amount']);
            case 'returned':
                return $currentBalance - floatval($tx['amount']);
            case 'repaid':
                return $currentBalance + floatval($tx['amount']);
            case 'adjustment':
                return $currentBalance + floatval($tx['amount']);
            default:
                return $currentBalance;
        }
    }

    private function calculateBalance($personData) {
        $lent = floatval($personData['total_lent'] ?? 0);
        $borrowed = floatval($personData['total_borrowed'] ?? 0);
        $returned = floatval($personData['total_returned'] ?? 0);
        $repaid = floatval($personData['total_repaid'] ?? 0);
        
        return ($lent + $repaid) - ($borrowed + $returned);
    }

    private function calculateBalanceFromDB($personId, $userId) {
        $query = "SELECT type, amount FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id
                  ORDER BY transaction_date ASC, created_at ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $balance = 0;
        foreach ($transactions as $tx) {
            $balance = $this->calculateRunningBalance($tx, $balance);
        }
        
        return $balance;
    }

    private function getPersonAggregate($personId, $userId, $type) {
        $query = "SELECT COALESCE(SUM(amount), 0) as total FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id AND type = :type";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return floatval($result['total'] ?? 0);
    }

    private function getTransactionCount($personId, $userId) {
        $query = "SELECT COUNT(*) as count FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return intval($result['count'] ?? 0);
    }

    private function getLastTransactionDate($personId, $userId) {
        $query = "SELECT MAX(transaction_date) as last_date FROM karobar_transactions 
                  WHERE person_id = :person_id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['last_date'] ?? null;
    }
}
