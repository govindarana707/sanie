<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/KarobarOutstandingService.php';

class KarobarTransaction {
    private $conn;
    private $table = 'karobar_transactions';
    private $outstandingService;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->outstandingService = new KarobarOutstandingService();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . "
                  (user_id, person_id, type, amount, account_id, expense_transaction_id, income_transaction_id, payment_method, client_request_id, description, transaction_date, due_date)
                  VALUES (:user_id, :person_id, :type, :amount, :account_id, :expense_transaction_id, :income_transaction_id, :payment_method, :client_request_id, :description, :transaction_date, :due_date)";
        
        $stmt = $this->conn->prepare($query);
        
        $expenseTxId = $data['expense_transaction_id'] ?? null;
        $incomeTxId = $data['income_transaction_id'] ?? null;
        $paymentMethod = $data['payment_method'] ?? null;
        $clientRequestId = $data['client_request_id'] ?? null;
        $dueDate = $data['due_date'] ?? null;

        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':person_id', $data['person_id']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':expense_transaction_id', $expenseTxId);
        $stmt->bindParam(':income_transaction_id', $incomeTxId);
        $stmt->bindParam(':payment_method', $paymentMethod);
        $stmt->bindParam(':client_request_id', $clientRequestId);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':transaction_date', $data['transaction_date']);
        $stmt->bindParam(':due_date', $dueDate);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function updateLinkedIds($id, $expenseId, $incomeId) {
        $query = "UPDATE " . $this->table . " SET 
                  expense_transaction_id = :expense_id,
                  income_transaction_id = :income_id
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':expense_id', $expenseId);
        $stmt->bindParam(':income_id', $incomeId);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    public function findAll($userId, $filters = [], $limit = 50, $offset = 0) {
        return $this->findPage($userId,$filters,max(1,(int)floor($offset/max(1,$limit))+1),$limit)['transactions'];
    }

    public function findPage(int $userId,array $filters,int $page,int $limit):array {
        $query = "SELECT kt.*, 
                  p.name as person_name, p.phone as person_phone, p.photo as person_photo, p.type as person_type,
                  a.name as account_name
                  FROM " . $this->table . " kt
                  LEFT JOIN people p ON kt.person_id = p.id
                  LEFT JOIN accounts a ON kt.account_id = a.id
                  WHERE kt.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND kt.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['person_id'])) {
            $query .= " AND kt.person_id = :person_id";
            $params[':person_id'] = $filters['person_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND kt.transaction_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $query .= " AND kt.transaction_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (p.name LIKE :search OR p.phone LIKE :search2 OR kt.description LIKE :search3)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
        }
        
        $where=$query;
        $query .= " ORDER BY kt.transaction_date DESC, kt.created_at DESC,kt.id DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $offset=($page-1)*$limit;
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $countSql=preg_replace('/^SELECT[\s\S]+?FROM /','SELECT COUNT(*) total_rows,COALESCE(SUM(CASE WHEN kt.type=\'lent\' THEN kt.amount ELSE 0 END),0) total_lent,COALESCE(SUM(CASE WHEN kt.type=\'borrowed\' THEN kt.amount ELSE 0 END),0) total_borrowed,COALESCE(SUM(CASE WHEN kt.type=\'returned\' THEN kt.amount ELSE 0 END),0) total_returned,COALESCE(SUM(CASE WHEN kt.type=\'repaid\' THEN kt.amount ELSE 0 END),0) total_repaid FROM ',$where,1);
        $aggregateStmt=$this->conn->prepare($countSql);foreach($params as$key=>$value)$aggregateStmt->bindValue($key,$value);$aggregateStmt->execute();$summary=$aggregateStmt->fetch(PDO::FETCH_ASSOC)?:[];$total=(int)($summary['total_rows']??0);
        return['transactions'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'offset'=>$offset,'total_rows'=>$total,'total_pages'=>$total?(int)ceil($total/$limit):0,'has_previous'=>$page>1&&$total>0,'has_next'=>$page*$limit<$total],'summary'=>['total_lent'=>(float)($summary['total_lent']??0),'total_borrowed'=>(float)($summary['total_borrowed']??0),'total_returned'=>(float)($summary['total_returned']??0),'total_repaid'=>(float)($summary['total_repaid']??0)]];
    }

    public function findById($id, $userId) {
        $query = "SELECT kt.*, 
                  p.name as person_name, p.phone as person_phone, p.photo as person_photo, p.type as person_type,
                  a.name as account_name
                  FROM " . $this->table . " kt
                  LEFT JOIN people p ON kt.person_id = p.id
                  LEFT JOIN accounts a ON kt.account_id = a.id
                  WHERE kt.id = :id AND kt.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByIdForUpdate($id, $userId) {
        $query = "SELECT * FROM " . $this->table . "
                  WHERE id = :id AND user_id = :user_id
                  LIMIT 1 FOR UPDATE";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByClientRequestId($clientRequestId, $userId) {
        if (!$clientRequestId) return false;
        $query = "SELECT * FROM " . $this->table . "
                  WHERE user_id = :user_id AND client_request_id = :client_request_id
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':client_request_id', $clientRequestId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data, $expectedVersion = null) {
        $query = "UPDATE " . $this->table . " SET 
                  person_id = :person_id,
                  type = :type,
                  amount = :amount,
                  account_id = :account_id,
                  description = :description,
                  transaction_date = :transaction_date,
                  due_date = :due_date,
                  payment_method = :payment_method,
                  version = version + 1,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        if ($expectedVersion !== null) $query .= " AND version = :expected_version";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':person_id', $data['person_id']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':account_id', $data['account_id']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':transaction_date', $data['transaction_date']);
        $stmt->bindParam(':due_date', $data['due_date']);
        $updatePaymentMethod = $data['payment_method'] ?? null;
        $stmt->bindParam(':payment_method', $updatePaymentMethod);
        if ($expectedVersion !== null) $stmt->bindValue(':expected_version', (int)$expectedVersion, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getDashboard($userId) {
        $query = "SELECT COUNT(DISTINCT person_id) as people_count,
                  COUNT(*) as total_transactions
                  FROM " . $this->table . " 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $recentQuery = "SELECT kt.*, p.name as person_name
                       FROM karobar_transactions kt
                       LEFT JOIN people p ON kt.person_id = p.id
                       WHERE kt.user_id = :user_id
                       ORDER BY kt.created_at DESC LIMIT 5";
        
        $stmt = $this->conn->prepare($recentQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $stats['recent_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $monthlyQuery = "SELECT DATE_FORMAT(transaction_date, '%b') as month,
                        MONTH(transaction_date) as month_num,
                        SUM(CASE WHEN type = 'lent' THEN amount ELSE 0 END) as lent,
                        SUM(CASE WHEN type = 'borrowed' THEN amount ELSE 0 END) as borrowed,
                        SUM(CASE WHEN type = 'returned' THEN amount ELSE 0 END) as returned,
                        SUM(CASE WHEN type = 'repaid' THEN amount ELSE 0 END) as repaid
                        FROM karobar_transactions
                        WHERE user_id = :user_id AND YEAR(transaction_date) = YEAR(CURDATE())
                        GROUP BY MONTH(transaction_date), DATE_FORMAT(transaction_date, '%b')
                        ORDER BY month_num ASC";
        
        $stmt = $this->conn->prepare($monthlyQuery);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $stats['monthly_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Current positions, overdue values, and person balances always come
        // from the shared settlement-allocation service. Queries above this
        // point provide only historical dashboard context.
        $positions = $this->outstandingService->getSummary($userId);
        $stats = array_merge($stats, $positions);
        $receivable = $positions['people_balances'];
        $payable = $positions['people_balances'];
        usort($receivable, fn($a,$b)=>(float)$b['receivable_outstanding']<=>(float)$a['receivable_outstanding']);
        usort($payable, fn($a,$b)=>(float)$b['payable_outstanding']<=>(float)$a['payable_outstanding']);
        $stats['largest_debtor'] = $receivable && (float)$receivable[0]['receivable_outstanding']>0
            ? ['name'=>$receivable[0]['name'],'amount'=>(float)$receivable[0]['receivable_outstanding']] : null;
        $stats['largest_creditor'] = $payable && (float)$payable[0]['payable_outstanding']>0
            ? ['name'=>$payable[0]['name'],'amount'=>(float)$payable[0]['payable_outstanding']] : null;
        return $stats;
    }

    public function getReports($userId, $filters = []) {
        $query = "SELECT kt.*, p.name as person_name, p.type as person_type
                  FROM " . $this->table . " kt
                  LEFT JOIN people p ON kt.person_id = p.id
                  WHERE kt.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND kt.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['person_id'])) {
            $query .= " AND kt.person_id = :person_id";
            $params[':person_id'] = $filters['person_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND kt.transaction_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $query .= " AND kt.transaction_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        $query .= " ORDER BY kt.transaction_date DESC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
