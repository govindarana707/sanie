<?php
if (PHP_SAPI !== 'cli' || getenv('ALLOW_DIAGNOSTICS') !== '1') {
    if (PHP_SAPI !== 'cli') http_response_code(404);
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

function ok($msg){
    echo "<tr><td>✅</td><td>{$msg}</td></tr>";
}

function fail($msg){
    echo "<tr style='background:#ffe5e5'><td>❌</td><td>{$msg}</td></tr>";
}

echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>SanIE Deployment Check</title>
<style>
body{
    font-family:Arial,sans-serif;
    background:#f5f5f5;
    padding:30px;
}
table{
    width:100%;
    border-collapse:collapse;
    background:white;
}
th,td{
    border:1px solid #ddd;
    padding:10px;
}
th{
    background:#222;
    color:white;
}
h1{
    color:#10B981;
}
pre{
    background:#272822;
    color:#fff;
    padding:15px;
    overflow:auto;
}
</style>
</head>
<body>

<h1>SanIE Deployment Diagnostic</h1>

<table>
<tr>
<th>Status</th>
<th>Check</th>
</tr>
HTML;

try{

    ok("PHP Version : ".PHP_VERSION);

    if(extension_loaded("pdo"))
        ok("PDO Loaded");
    else
        fail("PDO Missing");

    if(extension_loaded("pdo_mysql"))
        ok("PDO MySQL Loaded");
    else
        fail("PDO MySQL Missing");

    if(function_exists("getallheaders"))
        ok("getallheaders() Available");
    else
        fail("getallheaders() Not Available");

    require_once __DIR__.'/config/database.php';

    $db=new Database();
    $conn=$db->getConnection();

    if($conn){
        ok("Database Connected");
    }else{
        throw new Exception("Database Connection Failed");
    }

    $tables=$conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    ok(count($tables)." Tables Found");

    foreach([
        'users',
        'transactions',
        'accounts',
        'categories',
        'goals',
        'notifications'
    ] as $table){

        if(in_array($table,$tables))
            ok("Table Exists : ".$table);
        else
            fail("Missing Table : ".$table);
    }

    require_once __DIR__.'/includes/jwt.php';

    ok("JWT Library Loaded");

    require_once __DIR__.'/includes/middleware.php';

    ok("Middleware Loaded");

    require_once __DIR__.'/models/User.php';
    require_once __DIR__.'/models/Goal.php';
    require_once __DIR__.'/models/Account.php';
    require_once __DIR__.'/models/Transaction.php';

    ok("Models Loaded");

    $stmt=$conn->prepare("SELECT COUNT(*) total FROM users");
    $stmt->execute();

    $count=$stmt->fetch();

    ok("Users : ".$count['total']);

    $stmt=$conn->prepare("SELECT COUNT(*) total FROM transactions");
    $stmt->execute();

    $count=$stmt->fetch();

    ok("Transactions : ".$count['total']);

    $headers=function_exists("getallheaders")?getallheaders():[];

    if(isset($headers['Authorization']))
        ok("Authorization Header Received");
    else
        fail("Authorization Header Missing");

    echo "</table>";

    echo "<h2>Headers</h2>";
    echo "<pre>";
    print_r($headers);
    echo "</pre>";

}catch(Throwable $e){

    echo "</table>";

    echo "<h2 style='color:red'>ERROR</h2>";

    echo "<pre>";

    echo "Message : ".$e->getMessage()."\n\n";

    echo "File : ".$e->getFile()."\n";

    echo "Line : ".$e->getLine()."\n\n";

    echo $e->getTraceAsString();

    echo "</pre>";
}

echo "</body></html>";
