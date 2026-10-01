<?php

require_once __DIR__ . '/../config/database.php';

class Task {
    private PDO $conn;

    public function __construct(?PDO $connection = null) {
        $resolved = $connection ?: (new Database())->getConnection();
        if (!$resolved) throw new RuntimeException('Database connection unavailable.');
        $this->conn = $resolved;
    }

    public function connection(): PDO { return $this->conn; }

    public function findAll(int $userId, array $filters = []): array {
        $where = ['user_id = :user_id', 'deleted_at IS NULL'];
        $params = [':user_id' => $userId];
        foreach (['task_type','status','subject','due_date'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $where[] = "{$field} = :{$field}";
                $params[":{$field}"] = $filters[$field];
            }
        }
        $stmt = $this->conn->prepare(
            'SELECT * FROM tasks WHERE ' . implode(' AND ', $where) .
            ' ORDER BY due_date IS NULL, due_date ASC, id ASC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId) {
        $stmt = $this->conn->prepare('SELECT * FROM tasks WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findAnyById(int $id, int $userId) {
        $stmt=$this->conn->prepare('SELECT * FROM tasks WHERE id=:id AND user_id=:user_id LIMIT 1');
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);
        return$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findDeleted(int $userId): array {
        $stmt=$this->conn->prepare('SELECT * FROM tasks WHERE user_id=:user_id AND deleted_at IS NOT NULL ORDER BY deleted_at DESC,id DESC');
        $stmt->execute([':user_id'=>$userId]);
        return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $userId, array $data): int {
        $stmt = $this->conn->prepare(
            'INSERT INTO tasks (user_id,task_type,title,content,due_date,display_date_bs,subject,unit_label,status,completed_at,priority,reminder_at,summary_url,seed_key)
             VALUES (:user_id,:task_type,:title,:content,:due_date,:display_date_bs,:subject,:unit_label,:status,:completed_at,:priority,:reminder_at,:summary_url,:seed_key)'
        );
        $stmt->execute($this->params($userId, $data));
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): bool {
        $stmt = $this->conn->prepare(
            'UPDATE tasks SET title=:title,content=:content,due_date=:due_date,display_date_bs=:display_date_bs,
             subject=:subject,unit_label=:unit_label,status=:status,completed_at=:completed_at,
             priority=:priority,reminder_at=:reminder_at,summary_url=:summary_url,updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND user_id=:user_id'
        );
        $params = $this->params($userId, $data);
        unset($params[':task_type'], $params[':seed_key']);
        $params[':id'] = $id;
        return $stmt->execute($params) && $stmt->rowCount() <= 1;
    }

    public function setCompletion(int $id, int $userId, bool $completed): bool {
        return $this->setStatus($id, $userId, $completed ? 'completed' : 'pending');
    }

    public function setStatus(int $id, int $userId, string $status): bool {
        if (!in_array($status, ['pending','in_progress','completed'], true)) {
            throw new InvalidArgumentException('Invalid task status.');
        }
        $completedAt = $status === 'completed' ? 'CURRENT_TIMESTAMP' : 'NULL';
        $stmt = $this->conn->prepare(
            "UPDATE tasks SET status=:status, completed_at={$completedAt}, updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND user_id=:user_id AND deleted_at IS NULL"
        );
        $stmt->execute([':status' => $status, ':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->conn->prepare('UPDATE tasks SET deleted_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:user_id AND deleted_at IS NULL');
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function restore(int $id, int $userId): bool {
        $stmt=$this->conn->prepare('UPDATE tasks SET deleted_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:user_id AND deleted_at IS NOT NULL');
        $stmt->execute([':id'=>$id,':user_id'=>$userId]);
        if($stmt->rowCount()===1)return true;
        return(bool)$this->findById($id,$userId);
    }

    public function findDueReminders(int $userId, string $now): array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tasks WHERE user_id=:user_id AND deleted_at IS NULL
             AND status!='completed' AND reminder_at IS NOT NULL AND reminder_at<=:now
             ORDER BY reminder_at ASC,id ASC"
        );
        $stmt->execute([':user_id'=>$userId, ':now'=>$now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function importBoardPlan(int $userId, array $plan): int {
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO tasks
             (user_id,task_type,title,content,due_date,display_date_bs,subject,unit_label,status,priority,seed_key)
             VALUES (:user_id,'board_study',:title,:content,:due_date,:display_date_bs,:subject,:unit_label,'pending','normal',:seed_key)"
        );
        $imported = 0;
        $this->conn->beginTransaction();
        try {
            foreach ($plan as $index => $item) {
                [$bsDate,$adDate,$subject,$unit,$content] = $item;
                $stmt->execute([
                    ':user_id' => $userId, ':title' => $subject, ':content' => $content,
                    ':due_date' => $adDate, ':display_date_bs' => $bsDate, ':subject' => $subject,
                    ':unit_label' => $unit, ':seed_key' => sprintf('board-2083-v1-%02d', $index + 1),
                ]);
                $imported += $stmt->rowCount();
            }
            $this->conn->commit();
            return $imported;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $error;
        }
    }

    private function params(int $userId, array $data): array {
        return [
            ':user_id'=>$userId, ':task_type'=>$data['task_type'] ?? 'general', ':title'=>$data['title'],
            ':content'=>$data['content'] ?? null, ':due_date'=>$data['due_date'] ?? null,
            ':display_date_bs'=>$data['display_date_bs'] ?? null, ':subject'=>$data['subject'] ?? null,
            ':unit_label'=>$data['unit_label'] ?? null, ':status'=>$data['status'] ?? 'pending',
            ':completed_at'=>($data['status'] ?? 'pending') === 'completed' ? ($data['completed_at'] ?? date('Y-m-d H:i:s')) : null,
            ':priority'=>$data['priority'] ?? 'normal', ':reminder_at'=>$data['reminder_at'] ?? null,
            ':summary_url'=>$data['summary_url'] ?? null,
            ':seed_key'=>$data['seed_key'] ?? null,
        ];
    }
}
