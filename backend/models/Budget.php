<?php

require_once __DIR__ . '/../config/database.php';

class BudgetValidationException extends InvalidArgumentException {}

class Budget {
    private $conn;
    private $table = 'budgets';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $data=$this->normalize($data,(int)$data['user_id']);
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, category_id, subcategory_id, name, amount, period, start_date, end_date, alert_threshold, is_active) 
                  VALUES (:user_id, :category_id, :subcategory_id, :name, :amount, :period, :start_date, :end_date, :alert_threshold, :is_active)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':subcategory_id', $data['subcategory_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':period', $data['period']);
        $stmt->bindParam(':start_date', $data['start_date']);
        $stmt->bindParam(':end_date', $data['end_date']);
        $stmt->bindParam(':alert_threshold', $data['alert_threshold']);
        $stmt->bindParam(':is_active', $data['is_active']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }

    public function findAll($userId) {
        $query = "SELECT b.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name
                  FROM " . $this->table . " b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN subcategories sc ON b.subcategory_id = sc.id
                  WHERE b.user_id = :user_id
                  ORDER BY b.is_active DESC, b.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row)$row['scope_label']=$this->scopeLabel($row['category_name'],$row['subcategory_name']);
        return$rows;
    }

    public function findById($id, $userId) {
        $query = "SELECT b.*, 
                  c.name as category_name, c.icon as category_icon, c.color as category_color,
                  sc.name as subcategory_name
                  FROM " . $this->table . " b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN subcategories sc ON b.subcategory_id = sc.id
                  WHERE b.id = :id AND b.user_id = :user_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if($row)$row['scope_label']=$this->scopeLabel($row['category_name'],$row['subcategory_name']);
        return$row;
    }

    public function update($id, $userId, $data) {
        $data=$this->normalize($data,(int)$userId);
        $query = "UPDATE " . $this->table . " SET 
                  category_id = :category_id,
                  subcategory_id = :subcategory_id,
                  name = :name,
                  amount = :amount,
                  period = :period,
                  start_date = :start_date,
                  end_date = :end_date,
                  alert_threshold = :alert_threshold,
                  is_active = :is_active,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':category_id', $data['category_id']);
        $stmt->bindParam(':subcategory_id', $data['subcategory_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':period', $data['period']);
        $stmt->bindParam(':start_date', $data['start_date']);
        $stmt->bindParam(':end_date', $data['end_date']);
        $stmt->bindParam(':alert_threshold', $data['alert_threshold']);
        $stmt->bindParam(':is_active', $data['is_active']);
        
        return $stmt->execute();
    }

    public function delete($id, $userId) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }

    public function getBudgetProgress($budgetId, $userId) {
        $budget = $this->findById($budgetId, $userId);
        if(!$budget)return null;
        $rows=$this->getBatchProgress([(int)$budgetId],$userId);
        if(!$rows)return null;
        $row=$rows[0];$row['budget']=$budget;
        return$row;
    }

    public function bulkCreate($entries, $userId) {
        if (empty($entries)) return [];

        $entries=array_map(fn($entry)=>$this->normalize(array_merge($entry,['user_id'=>$userId]),(int)$userId),$entries);

        $this->conn->beginTransaction();
        try {
            $inserted = [];
            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table}
                 (user_id, category_id, subcategory_id, name, amount, period, start_date, end_date, alert_threshold, is_active)
                 VALUES (:user_id, :category_id, :subcategory_id, :name, :amount, :period, :start_date, :end_date, :alert_threshold, :is_active)"
            );

            foreach ($entries as $e) {
                $stmt->bindValue(':user_id', $userId);
                $stmt->bindValue(':category_id', $e['category_id'] ?? null);
                $stmt->bindValue(':subcategory_id', $e['subcategory_id'] ?? null);
                $stmt->bindValue(':name', $e['name']);
                $stmt->bindValue(':amount', $e['amount']);
                $stmt->bindValue(':period', $e['period']);
                $stmt->bindValue(':start_date', $e['start_date'] ?? date('Y-m-01'));
                $stmt->bindValue(':end_date', $e['end_date'] ?? date('Y-m-t'));
                $stmt->bindValue(':alert_threshold', $e['alert_threshold'] ?? 80);
                $stmt->bindValue(':is_active', $e['is_active'] ?? true);
                $stmt->execute();
                $inserted[] = $this->conn->lastInsertId();
            }

            $this->conn->commit();
            return $inserted;
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log('Budget::bulkCreate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getSuggestions($userId, $period = 'monthly', $months = 3) {
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime("-{$months} months"));

        $query = "SELECT
                    c.id AS category_id,
                    c.name AS category_name,
                    c.icon AS category_icon,
                    c.color AS category_color,
                    c.type AS category_type,
                    ROUND(COALESCE(SUM(t.amount) / ?, 0), 0) AS avg_spent
                  FROM categories c
                  LEFT JOIN transactions t
                    ON t.category_id = c.id
                    AND t.user_id = ?
                    AND t.type = 'expense'
                    AND t.date BETWEEN ? AND ?
                  WHERE (c.user_id = ? OR c.user_id IS NULL)
                    AND c.type = 'expense' AND c.status = 'active' AND c.parent_id IS NULL
                  GROUP BY c.id
                  ORDER BY avg_spent DESC, c.name ASC";

        $divisor = max(1, $months);
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(1, $divisor, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId);
        $stmt->bindValue(3, $start);
        $stmt->bindValue(4, $end);
        $stmt->bindValue(5, $userId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByPeriod($userId, $period, $startDate, $endDate) {
        $query = "SELECT b.*,
                         c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                         sc.name AS subcategory_name
                  FROM {$this->table} b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN subcategories sc ON b.subcategory_id = sc.id
                  WHERE b.user_id = :user_id
                    AND b.period = :period
                    AND b.start_date >= :start_date
                    AND b.end_date <= :end_date
                  ORDER BY b.name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':period', $period);
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row)$row['scope_label']=$this->scopeLabel($row['category_name'],$row['subcategory_name']);
        return$rows;
    }

    public function getBatchProgress($ids, $userId, $periodStart = null, $periodEnd = null) {
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)$ids),fn($id)=>$id>0)));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $query = "SELECT
                    b.id AS budget_id,
                    b.name AS budget_name,
                    b.amount AS budget_amount,
                    b.category_id,b.subcategory_id,b.period,b.start_date,b.end_date,
                    b.alert_threshold,
                    c.name AS category_name,
                    c.icon AS category_icon,
                    c.color AS category_color,
                    sc.name AS subcategory_name,
                    COALESCE(SUM(t.amount), 0) AS spent
                  FROM budgets b
                  LEFT JOIN categories c ON b.category_id = c.id
                  LEFT JOIN subcategories sc ON b.subcategory_id = sc.id
                  LEFT JOIN transactions t
                    ON t.user_id = b.user_id
                    AND t.type = 'expense'
                    AND t.date BETWEEN b.start_date AND b.end_date
                    AND (b.category_id IS NULL OR t.category_id = b.category_id)
                    AND (b.subcategory_id IS NULL OR t.subcategory_id = b.subcategory_id)";
        $params = [];

        if ($periodStart) {
            $query .= " AND t.date >= ?";
            $params[] = $periodStart;
        }
        if ($periodEnd) {
            $query .= " AND t.date <= ?";
            $params[] = $periodEnd;
        }

        $query .= " WHERE b.id IN ($placeholders) AND b.user_id = ? GROUP BY b.id";
        $params = array_merge($params, $ids, [$userId]);

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $progress = [];
        foreach ($rows as $r) {
            $spent = (float)$r['spent'];
            $amount = (float)$r['budget_amount'];
            $remaining = $amount - $spent;
            $percentage = $amount > 0 ? ($spent / $amount) * 100 : 0;

            $progress[] = [
                'budget_id' => $r['budget_id'],
                'budget_amount' => $r['budget_amount'],
                'budget' => [
                    'id' => $r['budget_id'],
                    'name' => $r['budget_name'],
                    'amount' => $r['budget_amount'],
                    'category_id'=>$r['category_id'],'subcategory_id'=>$r['subcategory_id'],
                    'period'=>$r['period'],'start_date'=>$r['start_date'],'end_date'=>$r['end_date'],
                    'category_name' => $r['category_name'],
                    'category_icon' => $r['category_icon'],
                    'category_color' => $r['category_color'],
                    'subcategory_name' => $r['subcategory_name'],
                    'scope_label' => $this->scopeLabel($r['category_name'],$r['subcategory_name']),
                ],
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage' => $percentage,
                'is_over_budget' => $spent > $amount,
                'is_near_limit' => $percentage >= (float)$r['alert_threshold']
            ];
        }

        return $progress;
    }

    private function normalize(array$data,int$userId):array {
        $name=trim((string)($data['name']??''));
        if($name===''||strlen($name)>100)throw new BudgetValidationException('Budget name is required and must be 100 characters or fewer.');
        if(!isset($data['amount'])||!is_numeric($data['amount']))throw new BudgetValidationException('Budget amount must be a positive number.');
        $amount=(float)$data['amount'];
        if(!is_finite($amount)||$amount<=0||$amount>999999999999.99||abs($amount-round($amount,2))>.0000001)throw new BudgetValidationException('Budget amount must be positive and use no more than two decimal places.');
        $period=(string)($data['period']??'monthly');
        if(!in_array($period,['daily','weekly','monthly','yearly'],true))throw new BudgetValidationException('Unsupported budget period.');
        $start=$this->dateValue($data['start_date']??date('Y-m-01'),'start date');$end=$this->dateValue($data['end_date']??date('Y-m-t'),'end date');
        if($start>$end)throw new BudgetValidationException('Budget start date cannot be after the end date.');
        $threshold=$data['alert_threshold']??80;
        if(!is_numeric($threshold)||!is_finite((float)$threshold)||(float)$threshold<0||(float)$threshold>100)throw new BudgetValidationException('Alert threshold must be between 0 and 100.');
        $categoryId=$this->nullableId($data['category_id']??null,'category');$subcategoryId=$this->nullableId($data['subcategory_id']??null,'subcategory');
        if($categoryId===null&&$subcategoryId!==null)throw new BudgetValidationException('A subcategory budget requires its parent category.');
        if($categoryId!==null){
            $stmt=$this->conn->prepare("SELECT id FROM categories WHERE id=:id AND type='expense' AND status='active' AND parent_id IS NULL AND (user_id=:uid OR user_id IS NULL) LIMIT 1");
            $stmt->execute([':id'=>$categoryId,':uid'=>$userId]);
            if(!$stmt->fetchColumn())throw new BudgetValidationException('Selected category is unavailable.');
        }
        if($subcategoryId!==null){
            $stmt=$this->conn->prepare("SELECT sc.id FROM subcategories sc JOIN categories c ON c.id=sc.category_id WHERE sc.id=:id AND sc.category_id=:category_id AND sc.status='active' AND c.status='active' AND c.type='expense' AND (sc.user_id=:suid OR sc.user_id IS NULL) AND (c.user_id=:cuid OR c.user_id IS NULL) LIMIT 1");
            $stmt->execute([':id'=>$subcategoryId,':category_id'=>$categoryId,':suid'=>$userId,':cuid'=>$userId]);
            if(!$stmt->fetchColumn())throw new BudgetValidationException('Selected subcategory does not belong to the selected category or is unavailable.');
        }
        $active=array_key_exists('is_active',$data)?(!empty($data['is_active'])?1:0):1;
        return array_merge($data,['user_id'=>$userId,'name'=>$name,'amount'=>round($amount,2),'period'=>$period,'start_date'=>$start,'end_date'=>$end,'category_id'=>$categoryId,'subcategory_id'=>$subcategoryId,'alert_threshold'=>round((float)$threshold,2),'is_active'=>$active]);
    }

    private function nullableId($value,string$label):?int {
        if($value===null||$value==='')return null;$id=filter_var($value,FILTER_VALIDATE_INT);
        if($id===false||$id<1)throw new BudgetValidationException("Invalid {$label}.");return(int)$id;
    }

    private function dateValue($value,string$label):string {
        $raw=(string)$value;$date=DateTime::createFromFormat('!Y-m-d',$raw);
        if(!$date||$date->format('Y-m-d')!==$raw)throw new BudgetValidationException("Invalid budget {$label}.");return$raw;
    }

    private function scopeLabel($category,$subcategory):string {
        if($subcategory)return(string)$category.' / '.(string)$subcategory;
        return$category?(string)$category:'All expenses';
    }
}
