<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function taskHttp(string $method, string $path, int $userId, ?array $body = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id'=>$userId]);
    $options = ['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",'ignore_errors'=>true,'timeout'=>10];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http'=>$options]));
    $line = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $line, $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}
function taskHttpAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$db = (new Database())->getConnection();
$users = [];
try {
    foreach (['owner','foreign'] as $label) {
        $stmt=$db->prepare('INSERT INTO users(email,password,first_name,token_version) VALUES(?,?,?,1)');
        $stmt->execute(['task-http-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash('Task-HTTP-2026!',PASSWORD_DEFAULT),'Task HTTP']);
        $users[$label]=(int)$db->lastInsertId();
    }
    [$status,$import]=taskHttp('POST','tasks/board-study/import',$users['owner'],[]);
    taskHttpAssert($status===201 && $import['data']['imported']===45 && $import['data']['total']===45,'first HTTP import failed');
    [$status,$repeat]=taskHttp('POST','tasks/board-study/import',$users['owner'],[]);
    taskHttpAssert($status===200 && $repeat['data']['imported']===0 && $repeat['data']['total']===45,'HTTP import was not idempotent');
    $target=$import['data']['items'][0];
    [$status,$started]=taskHttp('PUT','tasks/'.$target['id'],$users['owner'],['status'=>'in_progress']);
    taskHttpAssert($status===200 && $started['data']['status']==='in_progress' && $started['data']['completed_at']===null,'HTTP start transition failed');
    [$status,$inProgress]=taskHttp('GET','tasks?task_type=board_study&status=in_progress',$users['owner']);
    taskHttpAssert($status===200 && count($inProgress['data'])===1,'HTTP in-progress filtering failed');
    [$status,$completedBefore]=taskHttp('GET','tasks?task_type=board_study&status=completed',$users['owner']);
    taskHttpAssert($status===200 && count($completedBefore['data'])===0,'in-progress task inflated completed filtering');
    [$status,$completed]=taskHttp('PUT','tasks/'.$target['id'],$users['owner'],['status'=>'completed']);
    taskHttpAssert($status===200 && $completed['data']['status']==='completed' && $completed['data']['completed_at']!==null,'HTTP completion transition failed');
    [$status,$invalid]=taskHttp('PUT','tasks/'.$target['id'],$users['owner'],['status'=>'started']);
    taskHttpAssert($status===422,'invalid lifecycle status was accepted');
    [$status]=taskHttp('PATCH','tasks/'.$target['id'].'/completion',$users['foreign'],['completed'=>false]);
    taskHttpAssert($status===404,'foreign completion did not return isolated 404');
    [$status,$filtered]=taskHttp('GET','tasks?task_type=board_study&status=completed',$users['owner']);
    taskHttpAssert($status===200 && count($filtered['data'])===1,'HTTP status filtering failed');
    [$status,$general]=taskHttp('POST','tasks',$users['owner'],['title'=>'HTTP normal task','content'=>'Regression','due_date'=>'2026-08-22','priority'=>'high']);
    taskHttpAssert($status===201 && $general['data']['task_type']==='general','HTTP general task creation failed');
    [$status,$edited]=taskHttp('PUT','tasks/'.$target['id'],$users['owner'],['content'=>'HTTP edited target']);
    taskHttpAssert($status===200 && $edited['data']['content']==='HTTP edited target','HTTP edit failed');
    [$status]=taskHttp('DELETE','tasks/'.$target['id'],$users['owner']);
    taskHttpAssert($status===200,'HTTP delete failed');
    [, $afterDelete]=taskHttp('POST','tasks/board-study/import',$users['owner'],[]);
    taskHttpAssert($afterDelete['data']['imported']===0 && $afterDelete['data']['total']===44,'HTTP import recreated deleted target');
    echo "PASS: authenticated three-state lifecycle, CRUD, filters, completed-only progress inputs, idempotency, and ownership\n";
} finally {
    foreach (array_reverse($users) as $id) $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
}
