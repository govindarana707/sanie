<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../database/SchemaMigrator.php';
require_once __DIR__ . '/../includes/jwt.php';

function summaryHttp(string $method, string $path, int $userId, ?array $body = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id'=>$userId]);
    $options = ['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",'ignore_errors'=>true,'timeout'=>10];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http'=>$options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}
function summaryAssert(bool $condition, string $message): void {
    static $count = 0;
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['summary_assertions'] = ++$count;
}

$db = (new Database())->getConnection();
(new SchemaMigrator($db))->run();
$userId = null;
try {
    $db->prepare('INSERT INTO users(email,password,first_name,token_version) VALUES(?,?,?,1)')->execute([
        'task-summary-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('Task-Summary-2026!', PASSWORD_DEFAULT), 'Task Summary'
    ]);
    $userId = (int)$db->lastInsertId();

    [$status,$without] = summaryHttp('POST','tasks',$userId,['title'=>'Without summary']);
    summaryAssert($status === 201 && array_key_exists('summary_url',$without['data']) && $without['data']['summary_url'] === null, 'create without summary_url failed');

    [$status,$with] = summaryHttp('POST','tasks',$userId,['title'=>'With summary','summary_url'=>'  https://drive.google.com/file/d/example/view?usp=sharing  ']);
    summaryAssert($status === 201 && $with['data']['summary_url'] === 'https://drive.google.com/file/d/example/view?usp=sharing', 'valid summary_url was not trimmed and stored');
    $id = (int)$with['data']['id'];

    [$status,$updated] = summaryHttp('PUT',"tasks/{$id}",$userId,['summary_url'=>'https://docs.google.com/document/d/example/edit']);
    summaryAssert($status === 200 && $updated['data']['summary_url'] === 'https://docs.google.com/document/d/example/edit', 'summary_url update failed');

    [$status,$replaced] = summaryHttp('PUT',"tasks/{$id}",$userId,['summary_url'=>'https://example.com/direct-summary.pdf']);
    summaryAssert($status === 200 && $replaced['data']['summary_url'] === 'https://example.com/direct-summary.pdf', 'summary_url replacement failed');

    [$status,$read] = summaryHttp('GET',"tasks/{$id}",$userId);
    summaryAssert($status === 200 && $read['data']['summary_url'] === 'https://example.com/direct-summary.pdf', 'task response omitted summary_url');

    [$status,$cleared] = summaryHttp('PUT',"tasks/{$id}",$userId,['summary_url'=>'   ']);
    summaryAssert($status === 200 && $cleared['data']['summary_url'] === null, 'empty summary_url was not normalized to NULL');

    foreach (['javascript:alert(1)','data:text/html,x','file:///tmp/a.pdf','vbscript:msgbox(1)','http://example.com/a.pdf'] as $unsafe) {
        [$status] = summaryHttp('PUT',"tasks/{$id}",$userId,['summary_url'=>$unsafe]);
        summaryAssert($status === 422, "unsafe summary URL was accepted: {$unsafe}");
    }
    [$status] = summaryHttp('PUT',"tasks/{$id}",$userId,['summary_url'=>['https://example.com/a.pdf']]);
    summaryAssert($status === 422, 'non-string summary URL was accepted');

    summaryHttp('PUT',"tasks/{$id}",$userId,['summary_url'=>'https://www.dropbox.com/s/example/summary.pdf']);
    summaryHttp('DELETE',"tasks/{$id}",$userId);
    [, $restored] = summaryHttp('POST',"tasks/{$id}/restore",$userId,[]);
    summaryAssert($restored['data']['summary_url'] === 'https://www.dropbox.com/s/example/summary.pdf', 'delete/restore did not preserve summary_url');

    echo 'PASS: ' . ($GLOBALS['summary_assertions'] ?? 0) . " task summary-link HTTP assertions\n";
} finally {
    if ($userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}
