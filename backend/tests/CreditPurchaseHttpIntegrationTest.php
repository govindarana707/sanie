<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function creditHttp(string $method, string $path, int $userId, ?array $body = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $options = ['method'=>$method, 'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n", 'ignore_errors'=>true, 'timeout'=>10];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http'=>$options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

$db = (new Database())->getConnection();
$users = [];
try {
    foreach (['owner','foreign'] as $label) {
        $stmt=$db->prepare("INSERT INTO users (email,password,first_name) VALUES (?,?,'Credit HTTP')");
        $stmt->execute(['credit-http-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('fixture',PASSWORD_DEFAULT)]);
        $users[$label]=(int)$db->lastInsertId();
    }
    $stmt=$db->prepare("INSERT INTO people (user_id,name,type) VALUES (?,'HTTP Vendor','vendor')");
    $stmt->execute([$users['owner']]); $person=(int)$db->lastInsertId();
    $stmt->execute([$users['foreign']]); $foreignPerson=(int)$db->lastInsertId();
    $stmt=$db->prepare("INSERT INTO categories (user_id,name,type,status,is_default) VALUES (?,'HTTP Credit','expense','active',0)");
    $stmt->execute([$users['owner']]); $category=(int)$db->lastInsertId();
    $stmt=$db->prepare("INSERT INTO accounts (user_id,name,type,balance,opening_balance,is_active,is_default) VALUES (?,'HTTP Cash','cash',20000,20000,1,0)");
    $stmt->execute([$users['owner']]); $account=(int)$db->lastInsertId();

    $payload=['type'=>'expense','amount'=>5000,'date'=>'2026-08-01','category_id'=>$category,'subcategory_id'=>null,'payment_method'=>'credit','creditor_id'=>$person,'due_date'=>'2026-09-01','description'=>'HTTP credit purchase','client_request_id'=>'req_credit_http_integrity_001'];
    [$status,$created]=creditHttp('POST','transactions',$users['owner'],$payload);
    if($status!==201||!($created['success']??false)) throw new RuntimeException("Create returned HTTP {$status}: ".json_encode($created));
    $transactionId=(int)($created['data']['transaction_id']??0); $karobarId=(int)($created['data']['karobar_id']??0); $version=(int)($created['data']['version']??0);
    if(!$transactionId||!$karobarId||!$version||abs((float)$created['data']['outstanding']-5000)>0.001) throw new RuntimeException('Create response is incomplete');
    [$retryStatus,$retry]=creditHttp('POST','transactions',$users['owner'],$payload);
    if($retryStatus!==201||(int)$retry['data']['transaction_id']!==$transactionId) throw new RuntimeException('Retry did not return the canonical pair');
    [$mismatchStatus]=creditHttp('POST','transactions',$users['owner'],array_merge($payload,['amount'=>5001]));
    if($mismatchStatus!==409) throw new RuntimeException("Idempotency mismatch returned HTTP {$mismatchStatus}");
    [$showStatus,$shown]=creditHttp('GET',"transactions/{$transactionId}",$users['owner']);
    if($showStatus!==200||(int)($shown['data']['creditor_id']??0)!==$person) throw new RuntimeException('Linked transaction response omitted creditor');
    [$foreignStatus]=creditHttp('POST','transactions',$users['foreign'],array_merge($payload,['creditor_id'=>$foreignPerson,'client_request_id'=>'req_credit_http_foreign_001']));
    if($foreignStatus!==403) throw new RuntimeException("Foreign category returned HTTP {$foreignStatus}");
    [$updateStatus,$updated]=creditHttp('PUT',"transactions/{$transactionId}",$users['owner'],['amount'=>7500,'date'=>'2026-08-02','category_id'=>$category,'creditor_id'=>$person,'description'=>'Updated credit','base_version'=>$version]);
    if($updateStatus!==200||abs((float)$updated['data']['transaction']['amount']-7500)>0.001||abs((float)$updated['data']['payable']['amount']-7500)>0.001) throw new RuntimeException("Paired update returned HTTP {$updateStatus}");
    $newVersion=(int)$updated['data']['version'];
    [$staleStatus]=creditHttp('PUT',"karobar/{$karobarId}",$users['owner'],['amount'=>7600,'base_version'=>$version]);
    if($staleStatus!==409) throw new RuntimeException("Stale Karobar edit returned HTTP {$staleStatus}");
    [$repayStatus]=creditHttp('POST','karobar/repayment',$users['owner'],['person_id'=>$person,'account_id'=>$account,'amount'=>6000,'transaction_date'=>'2026-08-03','client_request_id'=>'req_credit_http_repay_001']);
    if($repayStatus!==201) throw new RuntimeException("Repayment returned HTTP {$repayStatus}");
    [$invalidStatus]=creditHttp('PUT',"transactions/{$transactionId}",$users['owner'],['amount'=>5000,'base_version'=>$newVersion]);
    if($invalidStatus!==409) throw new RuntimeException("Impossible reduction returned HTTP {$invalidStatus}");
    [$deleteStatus]=creditHttp('DELETE',"transactions/{$transactionId}",$users['owner'],['base_version'=>$newVersion]);
    if($deleteStatus!==409) throw new RuntimeException("Delete with repayments returned HTTP {$deleteStatus}");
    echo "PASS: credit purchase HTTP lifecycle returns and protects the linked pair\n";
} catch(Throwable $e){ fwrite(STDERR,'FAIL: '.$e->getMessage()."\n"); $failed=true; }
finally{
    foreach($users as $userId){
        try{$db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]);}catch(Throwable $ignored){}
        try{$db->prepare('DELETE FROM karobar_transactions WHERE user_id=?')->execute([$userId]);}catch(Throwable $ignored){}
        try{$db->prepare('DELETE FROM people WHERE user_id=?')->execute([$userId]);}catch(Throwable $ignored){}
        try{$db->prepare('DELETE FROM categories WHERE user_id=?')->execute([$userId]);}catch(Throwable $ignored){}
        try{$db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$userId]);}catch(Throwable $ignored){}
        try{$db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);}catch(Throwable $ignored){}
    }
}
exit(!empty($failed)?1:0);
