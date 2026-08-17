<?php
require_once __DIR__ . '/../includes/diagnostic_guard.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type:text/html;charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();

function ok($msg){
    echo "<tr><td style='color:green'>✔</td><td>$msg</td></tr>";
}

function fail($msg){
    echo "<tr><td style='color:red'>✖</td><td>$msg</td></tr>";
}

echo "
<!doctype html>
<html>
<head>
<title>SanIE Category Delete Diagnostic</title>
<style>
body{
font-family:Arial;
background:#f5f5f5;
padding:20px;
}
table{
width:100%;
border-collapse:collapse;
background:white;
}
td{
padding:10px;
border:1px solid #ddd;
}
h2{
margin-top:0;
}
.good{
color:green;
font-weight:bold;
}
.bad{
color:red;
font-weight:bold;
}
pre{
background:#eee;
padding:10px;
overflow:auto;
}
</style>
</head>
<body>

<h2>SanIE Category Delete Diagnostic</h2>

<table>
";

try{

    ok("Database Connected");

}catch(Exception $e){

    fail($e->getMessage());

    exit;

}

try{

    $stmt=$db->query("SHOW TABLES LIKE 'categories'");

    if($stmt->rowCount()){

        ok("categories table exists");

    }else{

        fail("categories table missing");

    }

}catch(Exception $e){

    fail($e->getMessage());

}

try{

    $stmt=$db->query("SELECT COUNT(*) total FROM categories");

    $row=$stmt->fetch(PDO::FETCH_ASSOC);

    ok("Total Categories : ".$row['total']);

}catch(Exception $e){

    fail($e->getMessage());

}

try{

    $stmt=$db->query("SHOW CREATE TABLE categories");

    $row=$stmt->fetch(PDO::FETCH_ASSOC);

    echo "<tr><td colspan='2'><b>Table Structure</b><pre>";

    print_r($row);

    echo "</pre></td></tr>";

}catch(Exception $e){

    fail($e->getMessage());

}

try{

    $stmt=$db->query("SELECT id,name,status FROM categories ORDER BY id DESC LIMIT 10");

    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<tr><td colspan='2'><b>Latest Categories</b>";

    echo "<table>";

    echo "<tr><th>ID</th><th>Name</th><th>Status</th></tr>";

    foreach($rows as $r){

        echo "<tr>";

        echo "<td>".$r['id']."</td>";

        echo "<td>".$r['name']."</td>";

        echo "<td>".$r['status']."</td>";

        echo "</tr>";

    }

    echo "</table>";

    echo "</td></tr>";

}catch(Exception $e){

    fail($e->getMessage());

}

try{

    $stmt=$db->query("SHOW TRIGGERS LIKE 'categories'");

    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    if(count($rows)){

        fail("Triggers Found");

        echo "<tr><td colspan='2'><pre>";

        print_r($rows);

        echo "</pre></td></tr>";

    }else{

        ok("No Triggers");

    }

}catch(Exception $e){

    fail($e->getMessage());

}

try{

    $stmt=$db->query("
SELECT
TABLE_NAME,
CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_NAME='categories'
AND TABLE_SCHEMA=DATABASE()
");

    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    if(count($rows)){

        fail("Foreign Keys referencing categories");

        echo "<tr><td colspan='2'><pre>";

        print_r($rows);

        echo "</pre></td></tr>";

    }else{

        ok("No Foreign Keys");

    }

}catch(Exception $e){

    fail($e->getMessage());

}

echo "

</table>

<h3>Frontend Checklist</h3>

<ul>

<li>Open Browser DevTools</li>

<li>Network → DELETE /categories/{id}</li>

<li>Verify HTTP Status = 200</li>

<li>Verify Response success=true</li>

<li>Verify category disappears after refresh</li>

<li>Disable Service Worker</li>

<li>Clear Browser Cache</li>

<li>Confirm AjaxService dispatches app:data-changed</li>

<li>Confirm loadCategories() executes after delete</li>

</ul>

</body>

</html>
";
