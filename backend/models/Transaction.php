<?php

require_once __DIR__ . '/../config/database.php';

class Transaction {
    private $conn;
    private $table = 'transactions';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . "
                  (user_id, account_id, from_account_id, to_account_id, category_id, subcategory_id, amount, type, payment_method, karobar_transaction_id, client_request_id, transfer_parent_id, goal_id, recurring_definition_id, recurring_occurrence_date, date, description)
                  VALUES (:user_id, :account_id, :from_account_id, :to_account_id, :category_id, :subcategory_id, :amount, :type, :payment_method, :karobar_transaction_id, :client_request_id, :transfer_parent_id, :goal_id, :recurring_definition_id, :recurring_occurrence_date, :date, :description)";
        
        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(':user_id', $data['user_id']);
        $stmt->bindValue(':account_id', $data['account_id'] ?? null);
        $stmt->bindValue(':from_account_id', $data['from_account_id'] ?? null);
        $stmt->bindValue(':to_account_id', $data['to_account_id'] ?? null);
        $stmt->bindValue(':category_id', $data['category_id'] ?? null);
        $stmt->bindValue(':subcategory_id', $data['subcategory_id'] ?? null);
        $stmt->bindValue(':amount', $data['amount']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':payment_method', $data['payment_method'] ?? null);
        $stmt->bindValue(':karobar_transaction_id', $data['karobar_transaction_id'] ?? null);
        $stmt->bindValue(':client_request_id', $data['client_request_id'] ?? null);
        $stmt->bindValue(':transfer_parent_id', $data['transfer_parent_id'] ?? null);
        $stmt->bindValue(':goal_id', $data['goal_id'] ?? null);
        $stmt->bindValue(':recurring_definition_id', $data['recurring_definition_id'] ?? null);
        $stmt->bindValue(':recurring_occurrence_date', $data['recurring_occurrence_date'] ?? null);
        $stmt->bindValue(':date', $data['date']);
        $stmt->bindValue(':description', $data['description'] ?? '');
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function updateKarobarLink($transactionId, $karobarTransactionId) {
        $query = "UPDATE " . $this->table . " SET karobar_transaction_id = :karobar_id WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':karobar_id', $karobarTransactionId);
        $stmt->bindParam(':id', $transactionId);
        return $stmt->execute();
    }

    public function findAll($userId, $filters = [], $limit = 50, $offset = 0) {
        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type,
                  fa.name as from_account_name, ta.name as to_account_name
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  WHERE t.user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (!empty($filters['account_id'])) {
            $query .= " AND (t.account_id = :account_id OR t.from_account_id = :from_account_id OR t.to_account_id = :to_account_id)";
            $params[':account_id'] = $filters['account_id'];
            $params[':from_account_id'] = $filters['account_id'];
            $params[':to_account_id'] = $filters['account_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND t.date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $query .= " AND t.date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (t.description LIKE :search_description
                        OR t.type LIKE :search_type
                        OR c.name LIKE :search_category
                        OR sc.name LIKE :search_subcategory
                        OR a.name LIKE :search_account
                        OR fa.name LIKE :search_from_account
                        OR ta.name LIKE :search_to_account
                        OR CAST(t.amount AS CHAR) LIKE :search_amount)";
            $term = '%' . $filters['search'] . '%';
            $params[':search_description'] = $term;
            $params[':search_type'] = $term;
            $params[':search_category'] = $term;
            $params[':search_subcategory'] = $term;
            $params[':search_account'] = $term;
            $params[':search_from_account'] = $term;
            $params[':search_to_account'] = $term;
            $params[':search_amount'] = $term;
        }
        
        $query .= " ORDER BY t.date DESC, t.created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $userId) {
        $query = "SELECT t.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type,
                  fa.name as from_account_name, ta.name as to_account_name
                  FROM " . $this->table . " t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  WHERE t.id = :id AND t.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByClientRequestId($clientRequestId, $userId) {
        if (!$clientRequestId) return false;
        $query = "SELECT id FROM " . $this->table . "
                  WHERE user_id = :user_id AND client_request_id = :client_request_id
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':client_request_id', $clientRequestId);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id ? $this->findById($id, $userId) : false;
    }

    public function findByRecurringOccurrence(int $definitionId, int $userId, string $occurrenceDate) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM {$this->table}
             WHERE user_id=:user_id AND recurring_definition_id=:definition_id
               AND recurring_occurrence_date=:occurrence_date LIMIT 1"
        );
        $stmt->execute([':user_id'=>$userId,':definition_id'=>$definitionId,':occurrence_date'=>$occurrenceDate]);
        $id=$stmt->fetchColumn();
        return $id ? $this->findById($id,$userId) : false;
    }

    public function existsById($id) {
        $stmt = $this->conn->prepare("SELECT 1 FROM " . $this->table . " WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    public function findByIdForUpdate($id, $userId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table} WHERE id = :id AND user_id = :user_id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findTransferFee($transferId, $userId, $forUpdate = false) {
        $query = "SELECT * FROM {$this->table}
                  WHERE transfer_parent_id = :transfer_id AND user_id = :user_id
                  LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':transfer_id' => $transferId, ':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $data, $expectedVersion = null) {
        $fromAccountId = $data['from_account_id'] ?? null;
        $toAccountId = $data['to_account_id'] ?? null;
        $paymentMethod = $data['payment_method'] ?? null;

        $query = "UPDATE " . $this->table . " SET 
                  account_id = :account_id,
                  from_account_id = :from_account_id,
                  to_account_id = :to_account_id,
                  category_id = :category_id,
                  subcategory_id = :subcategory_id,
                  amount = :amount,
                  type = :type,
                  payment_method = :payment_method,
                  date = :date,
                  description = :description,
                  version = version + 1,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        if ($expectedVersion !== null) {
            $query .= " AND version = :expected_version";
        }
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':account_id', $data['account_id'] ?? null);
        $stmt->bindValue(':from_account_id', $data['from_account_id'] ?? null);
        $stmt->bindValue(':to_account_id', $data['to_account_id'] ?? null);
        $stmt->bindValue(':category_id', $data['category_id'] ?? null);
        $stmt->bindValue(':subcategory_id', $data['subcategory_id'] ?? null);
        $stmt->bindValue(':amount', $data['amount']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':payment_method', $data['payment_method'] ?? null);
        $stmt->bindValue(':date', $data['date']);
        $stmt->bindValue(':description', $data['description'] ?? '');
        if ($expectedVersion !== null) $stmt->bindValue(':expected_version', (int)$expectedVersion, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete($id, $userId, $expectedVersion = null) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        if ($expectedVersion !== null) $query .= " AND version = :expected_version";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        if ($expectedVersion !== null) $stmt->bindValue(':expected_version', (int)$expectedVersion, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function getStatistics($userId, $startDate, $endDate) {
        $query = "SELECT 
                  SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                  SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
                  SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as balance,
                  COUNT(CASE WHEN type = 'income' THEN 1 END) as income_count,
                  COUNT(CASE WHEN type = 'expense' THEN 1 END) as expense_count
                  FROM " . $this->table . " 
                  WHERE user_id = :user_id AND date BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getCategoryBreakdown($userId, $startDate, $endDate, $type) {
        $query = "SELECT 
                  c.name as category_name,
                  c.icon as category_icon,
                  c.color as category_color,
                  SUM(t.amount) as total_amount,
                  COUNT(t.id) as transaction_count
                  FROM " . $this->table . " t
                  JOIN categories c ON t.category_id = c.id
                  WHERE t.user_id = :user_id AND t.date BETWEEN :start_date AND :end_date AND t.type = :type
                  GROUP BY c.id, c.name, c.icon, c.color
                  ORDER BY total_amount DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

   public function findRecentByAccounts($userId, $accountIds, $limit = 10)
{
    if (empty($accountIds)) {
        return [];
    }

    $accountPlaceholders = [];
    $fromPlaceholders = [];
    $toPlaceholders = [];
    $params = [
        ':user_id' => $userId
    ];

    foreach ($accountIds as $i => $id) {
        foreach (['acc', 'from_acc', 'to_acc'] as $prefix) {
            $ph = ":{$prefix}{$i}";
            $params[$ph] = (int)$id;
            if ($prefix === 'acc') $accountPlaceholders[] = $ph;
            elseif ($prefix === 'from_acc') $fromPlaceholders[] = $ph;
            else $toPlaceholders[] = $ph;
        }
    }

    $accountIn = implode(',', $accountPlaceholders);
    $fromIn = implode(',', $fromPlaceholders);
    $toIn = implode(',', $toPlaceholders);
    $limit = max(1, min(100, (int)$limit));

    $sql = "
        SELECT
            t.*,
            c.name AS category_name,
            c.icon AS category_icon,
            c.color AS category_color,
            sc.name AS subcategory_name,
            a.name AS account_name,
            a.type AS account_type,
            fa.name AS from_account_name,
            ta.name AS to_account_name
        FROM transactions t
        LEFT JOIN categories c ON c.id=t.category_id
        LEFT JOIN subcategories sc ON sc.id=t.subcategory_id
        LEFT JOIN accounts a ON a.id=t.account_id
        LEFT JOIN accounts fa ON fa.id=t.from_account_id
        LEFT JOIN accounts ta ON ta.id=t.to_account_id
        WHERE
            t.user_id=:user_id
            AND (
                t.account_id IN ($accountIn)
                OR t.from_account_id IN ($fromIn)
                OR t.to_account_id IN ($toIn)
            )
        ORDER BY t.date DESC,t.created_at DESC
        LIMIT $limit
    ";

    $stmt = $this->conn->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    public function getLedger($userId, $filters = [], $limit = 500, $offset = 0) {
        $params = [':user_id' => $userId];

        $query = "SELECT t.*,
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name,
                  a.name as account_name, a.type as account_type,
                  fa.name as from_account_name, ta.name as to_account_name,
                  kt.id as karobar_id, kt.type as karobar_type, kt.description as karobar_description,
                  p.name as person_name, p.photo as person_photo
                  FROM transactions t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  LEFT JOIN accounts a ON t.account_id = a.id
                  LEFT JOIN accounts fa ON t.from_account_id = fa.id
                  LEFT JOIN accounts ta ON t.to_account_id = ta.id
                  LEFT JOIN karobar_transactions kt ON t.karobar_transaction_id = kt.id
                  LEFT JOIN people p ON kt.person_id = p.id
                  WHERE t.user_id = :user_id";

        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        if (!empty($filters['account_id'])) {
            $query .= " AND (t.account_id = :account_id OR t.from_account_id = :account_id2 OR t.to_account_id = :account_id3)";
            $params[':account_id'] = $filters['account_id'];
            $params[':account_id2'] = $filters['account_id'];
            $params[':account_id3'] = $filters['account_id'];
        }
        if (!empty($filters['start_date'])) {
            $query .= " AND t.date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND t.date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        if (!empty($filters['search'])) {
            $query .= " AND (t.description LIKE :search OR c.name LIKE :search2 OR sc.name LIKE :search3)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
        }

        $sortField = $filters['sort'] ?? 't.date';
        $sortDir = strtoupper($filters['direction'] ?? 'DESC');
        if (!in_array($sortDir, ['ASC', 'DESC'])) $sortDir = 'DESC';
        $allowedSorts = ['t.date', 't.amount', 't.type', 't.description', 't.created_at'];
        if (!in_array($sortField, $allowedSorts)) $sortField = 't.date';

        $query .= " ORDER BY $sortField $sortDir, t.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLedgerCount($userId, $filters = []) {
        $params = [':user_id' => $userId];

        $query = "SELECT COUNT(*) as total
                  FROM transactions t
                  LEFT JOIN categories c ON t.category_id = c.id
                  LEFT JOIN subcategories sc ON t.subcategory_id = sc.id
                  WHERE t.user_id = :user_id";

        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        if (!empty($filters['account_id'])) {
            $query .= " AND (t.account_id = :account_id OR t.from_account_id = :account_id2 OR t.to_account_id = :account_id3)";
            $params[':account_id'] = $filters['account_id'];
            $params[':account_id2'] = $filters['account_id'];
            $params[':account_id3'] = $filters['account_id'];
        }
        if (!empty($filters['start_date'])) {
            $query .= " AND t.date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND t.date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        if (!empty($filters['search'])) {
            $query .= " AND (t.description LIKE :search OR c.name LIKE :search2 OR sc.name LIKE :search3)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[':search'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
        }

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public function getLedgerSummary($userId, $filters = []) {
        $params = [':user_id' => $userId];

        $query = "SELECT
                  COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) as period_income,
                  COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as period_expense,
                  COUNT(*) as period_count
                  FROM transactions t
                  LEFT JOIN categories c ON t.category_id = c.id
                  WHERE t.user_id = :user_id";

        if (!empty($filters['type'])) {
            $query .= " AND t.type = :type";
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['category_id'])) {
            $query .= " AND t.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        if (!empty($filters['account_id'])) {
            $query .= " AND (t.account_id = :account_id OR t.from_account_id = :account_id2 OR t.to_account_id = :account_id3)";
            $params[':account_id'] = $filters['account_id'];
            $params[':account_id2'] = $filters['account_id'];
            $params[':account_id3'] = $filters['account_id'];
        }
        if (!empty($filters['start_date'])) {
            $query .= " AND t.date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND t.date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getOpeningBalance($userId, $beforeDate) {
        $query = "SELECT
                  COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) -
                  COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as balance
                  FROM transactions
                  WHERE user_id = :user_id AND date < :before_date";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':before_date', $beforeDate);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return floatval($row['balance'] ?? 0);
    }

    public function getKarobarLedger($userId, $filters = []) {
        $params = [':user_id' => $userId];
        $query = "SELECT kt.id, kt.type, kt.amount, kt.transaction_date AS date,
                         kt.description, kt.account_id, a.name AS account_name,
                         p.name AS person_name
                  FROM karobar_transactions kt
                  LEFT JOIN accounts a ON a.id = kt.account_id
                  LEFT JOIN people p ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id AND kt.account_id IS NOT NULL";
        if (!empty($filters['account_id'])) {
            $query .= ' AND kt.account_id = :account_id';
            $params[':account_id'] = $filters['account_id'];
        }
        if (!empty($filters['start_date'])) {
            $query .= ' AND kt.transaction_date >= :start_date';
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= ' AND kt.transaction_date <= :end_date';
            $params[':end_date'] = $filters['end_date'];
        }
        if (!empty($filters['search'])) {
            $query .= ' AND (kt.description LIKE :search OR p.name LIKE :person_search)';
            $term = '%' . $filters['search'] . '%';
            $params[':search'] = $term;
            $params[':person_search'] = $term;
        }
        $query .= ' ORDER BY kt.transaction_date ASC, kt.created_at ASC';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLedgerOpeningBalance($userId, $beforeDate, $accountId = null): float {
        if ($accountId) {
            $query = "SELECT a.opening_balance
                      + COALESCE((SELECT SUM(CASE
                          WHEN t.type='income' AND t.account_id=a.id THEN t.amount
                          WHEN t.type='expense' AND t.account_id=a.id THEN -t.amount
                          WHEN t.type='goal_contribution' AND t.account_id=a.id THEN -t.amount
                          WHEN t.type='transfer' AND t.to_account_id=a.id THEN t.amount
                          WHEN t.type='transfer' AND t.from_account_id=a.id THEN -t.amount
                          ELSE 0 END)
                        FROM transactions t WHERE t.user_id=a.user_id AND t.date < :before1), 0)
                      + COALESCE((SELECT SUM(CASE
                          WHEN k.type IN ('borrowed','returned') THEN k.amount
                          WHEN k.type IN ('lent','repaid') THEN -k.amount ELSE 0 END)
                        FROM karobar_transactions k WHERE k.user_id=a.user_id AND k.account_id=a.id AND k.transaction_date < :before2), 0)
                      AS balance
                      FROM accounts a WHERE a.id=:account_id AND a.user_id=:user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':before1'=>$beforeDate, ':before2'=>$beforeDate, ':account_id'=>$accountId, ':user_id'=>$userId]);
            return (float)($stmt->fetchColumn() ?: 0);
        }

        $query = "SELECT
                    COALESCE((SELECT SUM(opening_balance) FROM accounts WHERE user_id=:u1), 0)
                  + COALESCE((SELECT SUM(CASE WHEN type='income' THEN amount WHEN type IN ('expense','goal_contribution') THEN -amount ELSE 0 END)
                              FROM transactions WHERE user_id=:u2 AND date < :before1), 0)
                  + COALESCE((SELECT SUM(CASE WHEN type IN ('borrowed','returned') THEN amount
                                             WHEN type IN ('lent','repaid') THEN -amount ELSE 0 END)
                              FROM karobar_transactions WHERE user_id=:u3 AND account_id IS NOT NULL AND transaction_date < :before2), 0)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':u1'=>$userId, ':u2'=>$userId, ':before1'=>$beforeDate, ':u3'=>$userId, ':before2'=>$beforeDate]);
        return (float)$stmt->fetchColumn();
    }

    public function getMonthlyData($userId, $year = null, $startDate = null, $endDate = null) {
        // If date range provided, use it instead of year
        if ($startDate && $endDate) {
            $rangeStart = new DateTime($startDate);
            $rangeEnd = new DateTime($endDate);
            $days = $rangeStart->diff($rangeEnd)->days;

            if ($days <= 31) {
                $label = "DATE_FORMAT(date, '%b %e')";
            } elseif ($days <= 60) {
                $label = "CONCAT('Wk ', WEEK(date, 1) - WEEK(DATE_SUB(date, INTERVAL DAYOFMONTH(date)-1 DAY), 1) + 1)";
            } else {
                $label = "DATE_FORMAT(date, '%b')";
            }

            $query = "SELECT
                        $label AS month,
                        MONTH(date) AS month_num,
                        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
                      FROM " . $this->table . "
                      WHERE user_id = :user_id AND date >= :start_date AND date <= :end_date
                      GROUP BY $label, month_num
                      ORDER BY MIN(date) ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':start_date', $startDate);
            $stmt->bindParam(':end_date', $endDate);
        } else {
            $year = $year ?? (int)date('Y');
            $query = "SELECT
                        DATE_FORMAT(date, '%b') AS month,
                        MONTH(date) AS month_num,
                        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
                      FROM " . $this->table . "
                      WHERE user_id = :user_id AND YEAR(date) = :year
                      GROUP BY MONTH(date), DATE_FORMAT(date, '%b')
                      ORDER BY month_num ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':year', $year, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
