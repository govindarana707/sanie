<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Authoritative derived Karobar position service.
 * Settlements are person-level, so they are allocated deterministically to
 * origins by transaction_date, created_at, then id (oldest origin first).
 */
class KarobarOutstandingService {
    private PDO $conn;

    public function __construct() {
        $connection = (new Database())->getConnection();
        if (!$connection) throw new RuntimeException('Database connection unavailable.');
        $this->conn = $connection;
    }

    public function getOrigins($userId, array $filters = []): array {
        $today = $filters['comparison_date'] ?? date('Y-m-d');
        $where = [];
        $params = [':origin_user'=>$userId, ':settlement_user'=>$userId, ':today'=>$today];
        if (!empty($filters['person_id'])) { $where[]='a.person_id=:person_id'; $params[':person_id']=(int)$filters['person_id']; }
        if (!empty($filters['direction'])) {
            $type = $filters['direction']==='receivable' ? 'lent' : 'borrowed';
            $where[]='a.type=:origin_type'; $params[':origin_type']=$type;
        }
        if (!empty($filters['start_date'])) { $where[]='a.transaction_date>=:start_date'; $params[':start_date']=$filters['start_date']; }
        if (!empty($filters['end_date'])) { $where[]='a.transaction_date<=:end_date'; $params[':end_date']=$filters['end_date']; }
        if (empty($filters['include_settled'])) $where[]='a.outstanding_amount>0';
        if (!empty($filters['overdue_only'])) {
            $where[]='a.due_date IS NOT NULL AND a.due_date<:today_filter AND a.outstanding_amount>0';
            $params[':today_filter']=$today;
        }

        $sql = $this->allocationCte() . ' SELECT a.*,
                CASE WHEN a.due_date IS NOT NULL AND a.due_date<:today AND a.outstanding_amount>0 THEN 1 ELSE 0 END is_overdue,
                CASE WHEN a.outstanding_amount<=0 THEN 1 ELSE 0 END is_settled
            FROM allocated a ' . ($where ? 'WHERE '.implode(' AND ',$where) : '') . '
            ORDER BY a.transaction_date ASC,a.created_at ASC,a.id ASC';
        $stmt=$this->conn->prepare($sql);
        foreach($params as$key=>$value)$stmt->bindValue($key,$value);
        $stmt->execute();
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row){
            $row['original_amount']=(float)$row['amount'];
            $row['outstanding_amount']=max(0,(float)$row['outstanding_amount']);
            $row['is_overdue']=(bool)$row['is_overdue'];
            $row['is_settled']=(bool)$row['is_settled'];
            $row['direction']=$row['type']==='lent'?'receivable':'payable';
        }
        return $rows;
    }

    public function getPersonPositions($userId, ?int $personId=null): array {
        $sql="SELECT p.id,p.name,p.photo,p.type,p.phone,p.address,
              GREATEST(0,COALESCE(SUM(CASE WHEN kt.type='lent' THEN kt.amount WHEN kt.type='returned' THEN -kt.amount ELSE 0 END),0)) receivable_outstanding,
              GREATEST(0,COALESCE(SUM(CASE WHEN kt.type='borrowed' THEN kt.amount WHEN kt.type='repaid' THEN -kt.amount ELSE 0 END),0)) payable_outstanding,
              COUNT(kt.id) transaction_count,MAX(kt.transaction_date) last_transaction_date
              FROM people p LEFT JOIN karobar_transactions kt ON kt.person_id=p.id AND kt.user_id=:karobar_user
              WHERE p.user_id=:people_user";
        $params=[':karobar_user'=>$userId,':people_user'=>$userId];
        if($personId!==null){$sql.=' AND p.id=:person_id';$params[':person_id']=$personId;}
        $sql.=' GROUP BY p.id,p.name,p.photo,p.type,p.phone,p.address ORDER BY p.name ASC';
        $stmt=$this->conn->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $originFilters=['include_settled'=>true];
        if($personId!==null)$originFilters['person_id']=$personId;
        $origins=$this->getOrigins($userId,$originFilters);
        $byPerson=[];
        foreach($origins as$origin){
            $pid=(int)$origin['person_id'];
            $byPerson[$pid]??=['overdue_receivable'=>0.0,'overdue_payable'=>0.0,'overdue_count'=>0,'active_count'=>0,'settled_count'=>0,'next_due_date'=>null,'last_overdue_date'=>null];
            if($origin['is_settled']){$byPerson[$pid]['settled_count']++;continue;}
            $byPerson[$pid]['active_count']++;
            if($origin['due_date']!==null&&$origin['due_date']>=date('Y-m-d')&&($byPerson[$pid]['next_due_date']===null||$origin['due_date']<$byPerson[$pid]['next_due_date']))$byPerson[$pid]['next_due_date']=$origin['due_date'];
            if($origin['is_overdue']){
                $field=$origin['direction']==='receivable'?'overdue_receivable':'overdue_payable';
                $byPerson[$pid][$field]+=$origin['outstanding_amount'];$byPerson[$pid]['overdue_count']++;
                if($byPerson[$pid]['last_overdue_date']===null||$origin['due_date']>$byPerson[$pid]['last_overdue_date'])$byPerson[$pid]['last_overdue_date']=$origin['due_date'];
            }
        }
        foreach($rows as&$row){
            foreach(['receivable_outstanding','payable_outstanding']as$f)$row[$f]=(float)$row[$f];
            $row=array_merge($row,$byPerson[(int)$row['id']]??['overdue_receivable'=>0.0,'overdue_payable'=>0.0,'overdue_count'=>0,'active_count'=>0,'settled_count'=>0,'next_due_date'=>null,'last_overdue_date'=>null]);
            $row['balance']=$row['receivable_outstanding']-$row['payable_outstanding'];
        }
        return $rows;
    }

    public function getPersonPosition($userId,int $personId): ?array {
        $rows=$this->getPersonPositions($userId,$personId);return$rows[0]??null;
    }

    public function getSummary($userId): array {
        $people=$this->getPersonPositions($userId);$summary=['total_receivable'=>0.0,'total_payable'=>0.0,'overdue_receivable'=>0.0,'overdue_payable'=>0.0,'overdue_amount'=>0.0,'overdue_count'=>0,'active_count'=>0,'settled_count'=>0,'people_balances'=>$people];
        foreach($people as$p){foreach(['total_receivable'=>'receivable_outstanding','total_payable'=>'payable_outstanding','overdue_receivable'=>'overdue_receivable','overdue_payable'=>'overdue_payable']as$out=>$in)$summary[$out]+=(float)$p[$in];$summary['overdue_count']+=(int)$p['overdue_count'];$summary['active_count']+=(int)$p['active_count'];$summary['settled_count']+=(int)$p['settled_count'];}
        $summary['overdue_amount']=$summary['overdue_receivable']+$summary['overdue_payable'];$summary['net_karobar']=$summary['total_receivable']-$summary['total_payable'];return$summary;
    }

    private function allocationCte(): string {
        return "WITH settlements AS(
          SELECT person_id,
            COALESCE(SUM(CASE WHEN type='returned' THEN amount ELSE 0 END),0) returned_total,
            COALESCE(SUM(CASE WHEN type='repaid' THEN amount ELSE 0 END),0) repaid_total
          FROM karobar_transactions WHERE user_id=:settlement_user GROUP BY person_id
        ),origins AS(
          SELECT kt.*,p.name person_name,p.type person_type,
            COALESCE(CASE WHEN kt.type='lent' THEN s.returned_total ELSE s.repaid_total END,0) settlement_total,
            COALESCE(SUM(kt.amount) OVER(PARTITION BY kt.person_id,kt.type ORDER BY kt.transaction_date,kt.created_at,kt.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) prior_origin_amount
          FROM karobar_transactions kt JOIN people p ON p.id=kt.person_id
          LEFT JOIN settlements s ON s.person_id=kt.person_id
          WHERE kt.user_id=:origin_user AND kt.type IN('lent','borrowed')
        ),allocated AS(
          SELECT o.*,GREATEST(0,o.amount-LEAST(o.amount,GREATEST(0,o.settlement_total-o.prior_origin_amount))) outstanding_amount
          FROM origins o
        )";
    }
}
