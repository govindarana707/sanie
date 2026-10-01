<?php

require_once __DIR__ . '/../config/database.php';

class RecurringTransaction {
    private PDO $conn;
    private string $table = 'recurring_transactions';

    public function __construct() {
        $connection = (new Database())->getConnection();
        if (!$connection) throw new RuntimeException('Database connection unavailable.');
        $this->conn = $connection;
    }

    public function create(array $data): int {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table}
             (user_id,account_id,category_id,subcategory_id,amount,type,frequency,day_of_month,day_of_week,start_date,end_date,next_occurrence,description,notes,is_active)
             VALUES
             (:user_id,:account_id,:category_id,:subcategory_id,:amount,:type,:frequency,:day_of_month,:day_of_week,:start_date,:end_date,:next_occurrence,:description,:notes,:is_active)"
        );
        $stmt->execute($this->params($data));
        return (int)$this->conn->lastInsertId();
    }

    public function findAll(int $userId): array {
        $stmt = $this->conn->prepare(
            "SELECT r.*,a.name account_name,c.name category_name,sc.name subcategory_name,
                    (SELECT COUNT(*) FROM transactions t WHERE t.user_id=r.user_id AND t.recurring_definition_id=r.id) generated_count
             FROM {$this->table} r
             JOIN accounts a ON a.id=r.account_id AND a.user_id=r.user_id
             JOIN categories c ON c.id=r.category_id
             LEFT JOIN subcategories sc ON sc.id=r.subcategory_id
             WHERE r.user_id=:user_id
             ORDER BY r.is_active DESC, COALESCE(r.next_occurrence,'9999-12-31'), r.id"
        );
        $stmt->execute([':user_id'=>$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId): array|false {
        $stmt = $this->conn->prepare(
            "SELECT r.*,a.name account_name,a.is_active account_is_active,
                    c.name category_name,c.status category_status,c.type category_type,
                    sc.name subcategory_name,sc.status subcategory_status,
                    (SELECT COUNT(*) FROM transactions t WHERE t.user_id=r.user_id AND t.recurring_definition_id=r.id) generated_count
             FROM {$this->table} r
             JOIN accounts a ON a.id=r.account_id AND a.user_id=r.user_id
             JOIN categories c ON c.id=r.category_id
             LEFT JOIN subcategories sc ON sc.id=r.subcategory_id
             WHERE r.id=:id AND r.user_id=:user_id LIMIT 1"
        );
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByIdForUpdate(int $id, int $userId): array|false {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id=:id AND user_id=:user_id LIMIT 1 FOR UPDATE");
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update(int $id, int $userId, array $data): bool {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET
             account_id=:account_id,category_id=:category_id,subcategory_id=:subcategory_id,
             amount=:amount,type=:type,frequency=:frequency,day_of_month=:day_of_month,day_of_week=:day_of_week,
             start_date=:start_date,end_date=:end_date,next_occurrence=:next_occurrence,
             description=:description,notes=:notes,is_active=:is_active,updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND user_id=:user_id"
        );
        $params = $this->params($data);
        $params[':id']=$id;
        $stmt->execute($params);
        return $stmt->rowCount() > 0 || $this->findById($id,$userId)!==false;
    }

    public function setState(int $id, int $userId, bool $active, ?string $nextOccurrence = null, bool $preserveCursor = true): bool {
        $sql = "UPDATE {$this->table} SET is_active=:active,updated_at=CURRENT_TIMESTAMP";
        $params=[':active'=>$active?1:0,':id'=>$id,':user_id'=>$userId];
        if (!$preserveCursor) {
            $sql .= ',next_occurrence=:next_occurrence';
            $params[':next_occurrence']=$nextOccurrence;
        }
        $sql .= ' WHERE id=:id AND user_id=:user_id';
        $stmt=$this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount()>0 || $this->findById($id,$userId)!==false;
    }

    public function advanceCursor(int $id, int $userId, string $expected, ?string $next): bool {
        $stmt=$this->conn->prepare(
            "UPDATE {$this->table} SET next_occurrence=:next_occurrence,
             is_active=CASE WHEN :next_occurrence2 IS NULL THEN 0 ELSE is_active END,
             updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND user_id=:user_id AND next_occurrence=:expected"
        );
        $stmt->execute([':next_occurrence'=>$next,':next_occurrence2'=>$next,':id'=>$id,':user_id'=>$userId,':expected'=>$expected]);
        return $stmt->rowCount()===1;
    }

    public function dueDefinitionIds(int $userId, string $today): array {
        $stmt=$this->conn->prepare(
            "SELECT id FROM {$this->table}
             WHERE user_id=:user_id AND is_active=1 AND next_occurrence IS NOT NULL
               AND next_occurrence<=:today AND (end_date IS NULL OR next_occurrence<=end_date)
             ORDER BY next_occurrence,id"
        );
        $stmt->execute([':user_id'=>$userId,':today'=>$today]);
        return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function generatedCount(int $id, int $userId): int {
        $stmt=$this->conn->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND recurring_definition_id=?');
        $stmt->execute([$userId,$id]);
        return (int)$stmt->fetchColumn();
    }

    public function delete(int $id, int $userId): bool {
        $stmt=$this->conn->prepare("DELETE FROM {$this->table} WHERE id=:id AND user_id=:user_id");
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);
        return $stmt->rowCount()===1;
    }

    private function params(array $data): array {
        return [
            ':user_id'=>(int)$data['user_id'], ':account_id'=>(int)$data['account_id'],
            ':category_id'=>(int)$data['category_id'], ':subcategory_id'=>$data['subcategory_id']??null,
            ':amount'=>$data['amount'], ':type'=>$data['type'], ':frequency'=>$data['frequency'],
            ':day_of_month'=>$data['day_of_month']??null, ':day_of_week'=>$data['day_of_week']??null,
            ':start_date'=>$data['start_date'], ':end_date'=>$data['end_date']??null,
            ':next_occurrence'=>$data['next_occurrence']??null, ':description'=>$data['description']??null,
            ':notes'=>$data['notes']??null, ':is_active'=>!empty($data['is_active'])?1:0,
        ];
    }
}
