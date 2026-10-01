<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Durable owner-scoped delivery claims. Visible notification rows may be
 * deleted without erasing these event identities.
 */
class NotificationEvent {
    private PDO $conn;

    public function __construct(?PDO $connection = null) {
        $resolved = $connection ?: (new Database())->getConnection();
        if (!$resolved) throw new RuntimeException('Database connection unavailable.');
        $this->conn = $resolved;
    }

    public function findByKey(int $userId, string $eventKey) {
        $stmt = $this->conn->prepare('SELECT * FROM notification_events WHERE user_id=? AND event_key=? LIMIT 1');
        $stmt->execute([$userId, $eventKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findLatestBySourceTypes(int $userId, string $sourceType, int $sourceId, array $eventTypes) {
        $eventTypes = array_values(array_filter(array_map('strval', $eventTypes)));
        if (!$eventTypes) return false;
        $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
        $stmt = $this->conn->prepare("SELECT * FROM notification_events WHERE user_id=? AND source_type=? AND source_id=? AND event_type IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge([$userId, $sourceType, $sourceId], $eventTypes));
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function claimOnly(int $userId, string $eventKey, string $eventType, ?string $sourceType, ?int $sourceId, ?string $occurredAt = null): bool {
        try {
            $stmt = $this->conn->prepare('INSERT INTO notification_events(user_id,event_key,event_type,source_type,source_id,occurred_at) VALUES(?,?,?,?,?,?)');
            return $stmt->execute([$userId, $eventKey, $eventType, $sourceType, $sourceId, $occurredAt]);
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) return false;
            throw $e;
        }
    }

    public function claimAndCreate(array $event, array $notification) {
        if ($this->conn->inTransaction()) {
            throw new RuntimeException('Durable notification delivery must run outside an existing transaction.');
        }
        $this->conn->beginTransaction();
        try {
            $claim = $this->conn->prepare('INSERT INTO notification_events(user_id,event_key,event_type,source_type,source_id,occurred_at) VALUES(:user_id,:event_key,:event_type,:source_type,:source_id,:occurred_at)');
            $claim->execute([
                ':user_id'=>$event['user_id'], ':event_key'=>$event['event_key'], ':event_type'=>$event['event_type'],
                ':source_type'=>$event['source_type'], ':source_id'=>$event['source_id'], ':occurred_at'=>$event['occurred_at'],
            ]);
            $insert = $this->conn->prepare('INSERT INTO notifications(user_id,type,title,message,icon,color,priority,reference_type,reference_id) VALUES(:user_id,:type,:title,:message,:icon,:color,:priority,:reference_type,:reference_id)');
            $insert->execute($notification);
            $notificationId = (int)$this->conn->lastInsertId();
            $this->conn->commit();
            return $notificationId;
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            if ($this->isDuplicate($e)) return false;
            throw $e;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    private function isDuplicate(PDOException $e): bool {
        return (string)$e->getCode() === '23000' && (int)($e->errorInfo[1] ?? 0) === 1062;
    }
}
