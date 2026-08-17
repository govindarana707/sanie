<?php

require_once __DIR__ . '/../config/database.php';

class ReportingPaginationService {
    private PDO $conn;

    public function __construct(?PDO $connection = null) {
        $this->conn = $connection ?: (new Database())->getConnection();
        if (!$this->conn) throw new RuntimeException('Database connection unavailable.');
    }

    public function incomeExpensePage(int $userId, array $filters, int $page, int $limit): array {
        [$where, $params] = $this->reportPredicate($userId, $filters);
        $offset = ($page - 1) * $limit;
        $delta = "CASE WHEN t.type='income' THEN t.amount WHEN t.type='expense' THEN -t.amount ELSE 0 END";
        $sql = "SELECT t.id,t.date,t.created_at,t.type,t.amount,t.description,
                       c.name category_name,sc.name subcategory_name,
                       COALESCE(a.name,CONCAT_WS(' → ',fa.name,ta.name),'-') account_name,
                       SUM({$delta}) OVER(ORDER BY t.date ASC,t.created_at ASC,t.id ASC) balance
                FROM transactions t
                LEFT JOIN categories c ON c.id=t.category_id
                LEFT JOIN subcategories sc ON sc.id=t.subcategory_id
                LEFT JOIN accounts a ON a.id=t.account_id
                LEFT JOIN accounts fa ON fa.id=t.from_account_id
                LEFT JOIN accounts ta ON ta.id=t.to_account_id
                {$where}
                ORDER BY t.date ASC,t.created_at ASC,t.id ASC
                LIMIT :page_limit OFFSET :page_offset";
        $stmt=$this->conn->prepare($sql);$this->bind($stmt,$params);$stmt->bindValue(':page_limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':page_offset',$offset,PDO::PARAM_INT);$stmt->execute();
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt=$this->conn->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN subcategories sc ON sc.id=t.subcategory_id LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN accounts fa ON fa.id=t.from_account_id LEFT JOIN accounts ta ON ta.id=t.to_account_id {$where}");
        $this->bind($countStmt,$params);$countStmt->execute();$total=(int)$countStmt->fetchColumn();

        $summaryStmt=$this->conn->prepare("SELECT
            COALESCE(SUM(CASE WHEN t.type='income' THEN t.amount ELSE 0 END),0) total_income,
            COALESCE(SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END),0) total_expense,
            SUM(t.type='income') income_count,SUM(t.type='expense') expense_count
            FROM transactions t LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN subcategories sc ON sc.id=t.subcategory_id LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN accounts fa ON fa.id=t.from_account_id LEFT JOIN accounts ta ON ta.id=t.to_account_id {$where}");
        $this->bind($summaryStmt,$params);$summaryStmt->execute();$summary=$summaryStmt->fetch(PDO::FETCH_ASSOC)?:[];
        $income=(float)($summary['total_income']??0);$expense=(float)($summary['total_expense']??0);
        $pageOpening=$rows?(float)$rows[0]['balance']-(($rows[0]['type']==='income'?(float)$rows[0]['amount']:0)-($rows[0]['type']==='expense'?(float)$rows[0]['amount']:0)):($total>0?$income-$expense:0);
        foreach($rows as&$row){$row['income']=$row['type']==='income'?(float)$row['amount']:0;$row['expense']=$row['type']==='expense'?(float)$row['amount']:0;$row['balance']=(float)$row['balance'];$row['category']=$row['category_name']??'-';$row['account']=$row['account_name']??'-';}unset($row);
        return ['rows'=>$rows,'pagination'=>$this->pagination($page,$limit,$total),'summary'=>['total_income'=>$income,'total_expense'=>$expense,'net'=>$income-$expense,'balance'=>$income-$expense,'income_count'=>(int)($summary['income_count']??0),'expense_count'=>(int)($summary['expense_count']??0),'page_opening'=>$pageOpening]];
    }

    public function ledgerPage(int $userId, array $filters, int $page, int $limit): array {
        $accountId=!empty($filters['account_id'])?(int)$filters['account_id']:null;
        $base=$this->baseBalance($userId,$accountId);
        [$events,$eventParams]=$this->ledgerEventsSql($userId,$accountId);
        [$visible,$visibleParams]=$this->ledgerVisiblePredicate($filters);
        $endSql=!empty($filters['end_date'])?' AND e.event_date<=:ledger_end':'';
        $params=array_merge($eventParams,$visibleParams,[':base_balance'=>$base]);
        if(!empty($filters['end_date']))$params[':ledger_end']=$filters['end_date'];
        $offset=($page-1)*$limit;
        $cte="WITH events AS ({$events}), scored AS (
                SELECT e.*,:base_balance+SUM(e.cash_delta) OVER(ORDER BY e.event_date ASC,e.created_at ASC,e.source_rank ASC,e.source_id ASC) running_balance
                FROM events e WHERE 1=1{$endSql})";
        $sql="{$cte} SELECT * FROM scored WHERE {$visible} ORDER BY event_date ASC,created_at ASC,source_rank ASC,source_id ASC LIMIT :page_limit OFFSET :page_offset";
        $stmt=$this->conn->prepare($sql);$this->bind($stmt,$params);$stmt->bindValue(':page_limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':page_offset',$offset,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt=$this->conn->prepare("{$cte} SELECT COUNT(*) total_count,
            COALESCE(SUM(CASE WHEN entry_source='transaction' AND event_type='income' THEN amount ELSE 0 END),0) period_income,
            COALESCE(SUM(CASE WHEN entry_source='transaction' AND event_type='expense' THEN amount ELSE 0 END),0) period_expense
            FROM scored WHERE {$visible}");
        $this->bind($countStmt,$params);$countStmt->execute();$aggregate=$countStmt->fetch(PDO::FETCH_ASSOC)?:[];$total=(int)($aggregate['total_count']??0);

        $rangeOpening=$this->ledgerPointBalance($events,$eventParams,$base,!empty($filters['start_date'])?$filters['start_date']:null,null);
        $rangeClosing=$this->ledgerPointBalance($events,$eventParams,$base,null,!empty($filters['end_date'])?$filters['end_date']:null);
        $pageOpening=$rows?(float)$rows[0]['running_balance']-(float)$rows[0]['cash_delta']:($total>0?$rangeClosing:$rangeOpening);
        foreach($rows as&$row){$row['id']=$row['source_id'];$row['date']=$row['event_date'];$row['type']=$row['event_type'];$row['running_balance']=(float)$row['running_balance'];$row['cash_delta']=(float)$row['cash_delta'];$row['amount']=(float)$row['amount'];}unset($row);
        return ['transactions'=>$rows,'pagination'=>$this->pagination($page,$limit,$total),'summary'=>[
            'range_opening'=>$rangeOpening,'page_opening'=>$pageOpening,'opening_balance'=>$rangeOpening,'closing_balance'=>$rangeClosing,
            'period_income'=>(float)($aggregate['period_income']??0),'period_expense'=>(float)($aggregate['period_expense']??0),
            'period_net'=>$rangeClosing-$rangeOpening,'period_count'=>$total
        ]];
    }

    private function reportPredicate(int$userId,array$filters):array {
        $parts=['t.user_id=:report_user'];$params=[':report_user'=>$userId];
        $map=['start_date'=>['t.date>=:report_start',':report_start'],'end_date'=>['t.date<=:report_end',':report_end'],'type'=>['t.type=:report_type',':report_type'],'category_id'=>['t.category_id=:report_category',':report_category'],'subcategory_id'=>['t.subcategory_id=:report_subcategory',':report_subcategory']];
        foreach($map as$key=>[$sql,$ph])if(!empty($filters[$key])){$parts[]=$sql;$params[$ph]=$filters[$key];}
        if(!empty($filters['account_id'])){$parts[]='(t.account_id=:report_account1 OR t.from_account_id=:report_account2 OR t.to_account_id=:report_account3)';foreach([':report_account1',':report_account2',':report_account3']as$ph)$params[$ph]=(int)$filters['account_id'];}
        if(!empty($filters['search'])){$parts[]="(t.description LIKE :report_search1 OR c.name LIKE :report_search2 OR sc.name LIKE :report_search3 OR a.name LIKE :report_search4 OR fa.name LIKE :report_search5 OR ta.name LIKE :report_search6)";$term='%'.$filters['search'].'%';for($i=1;$i<=6;$i++)$params[":report_search{$i}"]=$term;}
        return ['WHERE '.implode(' AND ',$parts),$params];
    }

    private function ledgerEventsSql(int$userId,?int$accountId):array {
        $params=[':event_user1'=>$userId,':event_user2'=>$userId];
        if($accountId){$txScope=' AND (t.account_id=:event_account1 OR t.from_account_id=:event_account2 OR t.to_account_id=:event_account3)';$kScope=' AND k.account_id=:event_account4';for($i=1;$i<=4;$i++)$params[":event_account{$i}"]=$accountId;
            $delta="CASE WHEN t.type='income' AND t.account_id={$accountId} THEN t.amount WHEN t.type IN('expense','goal_contribution') AND t.account_id={$accountId} THEN -t.amount WHEN t.type='transfer' AND t.to_account_id={$accountId} THEN t.amount WHEN t.type='transfer' AND t.from_account_id={$accountId} THEN -t.amount ELSE 0 END";
        }else{$txScope='';$kScope='';$delta="CASE WHEN t.type='income' AND t.account_id IS NOT NULL THEN t.amount WHEN t.type IN('expense','goal_contribution') AND t.account_id IS NOT NULL THEN -t.amount ELSE 0 END";}
        $sql="SELECT 'transaction' entry_source,1 source_rank,t.id source_id,t.date event_date,t.created_at,t.type event_type,t.amount,{$delta} cash_delta,t.description,
                     t.category_id,t.subcategory_id,t.account_id,t.from_account_id,t.to_account_id,c.name category_name,c.icon category_icon,c.color category_color,sc.name subcategory_name,
                     a.name account_name,fa.name from_account_name,ta.name to_account_name,kt.id karobar_id,kt.type karobar_type,p.name person_name,
                     CONCAT_WS(' ',t.description,c.name,sc.name,a.name,fa.name,ta.name,p.name) search_text
              FROM transactions t LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN subcategories sc ON sc.id=t.subcategory_id LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN accounts fa ON fa.id=t.from_account_id LEFT JOIN accounts ta ON ta.id=t.to_account_id LEFT JOIN karobar_transactions kt ON kt.id=t.karobar_transaction_id LEFT JOIN people p ON p.id=kt.person_id
              WHERE t.user_id=:event_user1{$txScope}
              UNION ALL
              SELECT 'karobar',2,k.id,k.transaction_date,k.created_at,k.type,k.amount,CASE WHEN k.type IN('borrowed','returned') THEN k.amount WHEN k.type IN('lent','repaid') THEN -k.amount ELSE 0 END,k.description,
                     NULL,NULL,k.account_id,NULL,NULL,'Karobar','people','#8B5CF6',NULL,a.name,NULL,NULL,k.id,k.type,p.name,CONCAT_WS(' ',k.description,p.name,a.name,k.type)
              FROM karobar_transactions k LEFT JOIN accounts a ON a.id=k.account_id LEFT JOIN people p ON p.id=k.person_id WHERE k.user_id=:event_user2 AND k.account_id IS NOT NULL{$kScope}";
        return[$sql,$params];
    }

    private function ledgerVisiblePredicate(array$filters):array {
        $parts=['1=1'];$params=[];
        if(!empty($filters['start_date'])){$parts[]='event_date>=:visible_start';$params[':visible_start']=$filters['start_date'];}
        if(!empty($filters['type'])){$parts[]='event_type=:visible_type';$params[':visible_type']=$filters['type'];}
        if(!empty($filters['category_id'])){$parts[]='category_id=:visible_category';$params[':visible_category']=(int)$filters['category_id'];}
        if(!empty($filters['subcategory_id'])){$parts[]='subcategory_id=:visible_subcategory';$params[':visible_subcategory']=(int)$filters['subcategory_id'];}
        if(!empty($filters['search'])){$parts[]='search_text LIKE :visible_search';$params[':visible_search']='%'.$filters['search'].'%';}
        return[implode(' AND ',$parts),$params];
    }

    private function baseBalance(int$userId,?int$accountId):float {$sql='SELECT COALESCE(SUM(opening_balance),0) FROM accounts WHERE user_id=:user_id';$params=[':user_id'=>$userId];if($accountId){$sql.=' AND id=:account_id';$params[':account_id']=$accountId;}$stmt=$this->conn->prepare($sql);$stmt->execute($params);return(float)$stmt->fetchColumn();}
    private function ledgerPointBalance(string$events,array$params,float$base,?string$before,?string$through):float {$where='1=1';if($before){$where.=' AND event_date<:point_before';$params[':point_before']=$before;}if($through){$where.=' AND event_date<=:point_through';$params[':point_through']=$through;}$stmt=$this->conn->prepare("WITH events AS ({$events}) SELECT :point_base+COALESCE(SUM(cash_delta),0) FROM events WHERE {$where}");$params[':point_base']=$base;$this->bind($stmt,$params);$stmt->execute();return(float)$stmt->fetchColumn();}
    private function pagination(int$page,int$limit,int$total):array{return['page'=>$page,'limit'=>$limit,'total_rows'=>$total,'total_pages'=>$total?(int)ceil($total/$limit):0,'has_previous'=>$page>1&&$total>0,'has_next'=>$page*$limit<$total];}
    private function bind(PDOStatement$stmt,array$params):void{foreach($params as$key=>$value)$stmt->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);}
}
