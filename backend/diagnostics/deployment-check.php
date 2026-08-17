<?php
require_once __DIR__ . '/../includes/diagnostic_guard.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: text/html; charset=utf-8');


function ok($msg){
    echo "<tr>
            <td style='color:green;font-size:18px;'>✅</td>
            <td>{$msg}</td>
          </tr>";
}


function warn($msg){
    echo "<tr style='background:#fff8dc'>
            <td style='font-size:18px;'>⚠️</td>
            <td>{$msg}</td>
          </tr>";
}


function fail($msg){
    echo "<tr style='background:#ffe5e5'>
            <td style='color:red;font-size:18px;'>❌</td>
            <td>{$msg}</td>
          </tr>";
}


$authorization = '';

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">

<title>SanIE Deployment Diagnostic</title>


<style>

body{
    font-family:Arial,Helvetica,sans-serif;
    background:#f5f5f5;
    margin:30px;
}


.container{
    max-width:1200px;
    margin:auto;
}


h2{
    color:#065f46;
}


table{

    width:100%;
    background:#fff;
    border-collapse:collapse;
    margin-bottom:25px;

}


th{

    background:#10B981;
    color:white;
    padding:12px;
    text-align:left;

}


td{

    border:1px solid #ddd;
    padding:10px;

}


pre{

    background:#272822;
    color:#fff;
    padding:15px;
    border-radius:6px;
    overflow:auto;

}


.card{

    background:white;
    padding:20px;
    border-radius:10px;
    margin-bottom:20px;

}


</style>


</head>


<body>


<div class="container">


<h2>🚀 SanIE Deployment Diagnostic</h2>


<div class="card">


<table>


<tr>

<th width="80">Status</th>

<th>Check</th>

</tr>


<?php


try{


/*
|--------------------------------------------------------------------------
| PHP Environment
|--------------------------------------------------------------------------
*/


ok("PHP Version : ".PHP_VERSION);



if(extension_loaded('pdo')){

    ok("PDO Extension Loaded");

}else{

    fail("PDO Extension Missing");

}



if(extension_loaded('pdo_mysql')){

    ok("PDO MySQL Extension Loaded");

}else{

    fail("PDO MySQL Extension Missing");

}



if(function_exists('getallheaders')){

    ok("getallheaders() Available");

}else{

    warn("getallheaders() Not Available (Normal on some hosting)");

}



/*
|--------------------------------------------------------------------------
| Load Application Files
|--------------------------------------------------------------------------
*/


$files = [


    __DIR__.'/../config/database.php',

    __DIR__.'/../includes/jwt.php',

    __DIR__.'/../includes/middleware.php',

    __DIR__.'/../models/User.php',

    __DIR__.'/../models/Goal.php',

    __DIR__.'/../models/Account.php',

    __DIR__.'/../models/Transaction.php'


];



foreach($files as $file){


    if(file_exists($file)){

        require_once $file;

    }else{

        throw new Exception(
            "Missing File : ".$file
        );

    }


}


ok("All Required Files Loaded");



/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/


if(!class_exists('Database')){

    throw new Exception(
        "Database Class Not Found"
    );

}



$db = new Database();


$conn = $db->getConnection();



if(!$conn){

    throw new Exception(
        "Database Connection Failed"
    );

}


ok("Database Connected Successfully");



/*
|--------------------------------------------------------------------------
| Database Tables
|--------------------------------------------------------------------------
*/


$tables = $conn
    ->query("SHOW TABLES")
    ->fetchAll(PDO::FETCH_COLUMN);



ok(count($tables)." Database Tables Found");



$requiredTables = [

    'users',
    'transactions',
    'accounts',
    'categories',
    'subcategories',
    'goals',
    'budgets',
    'notifications'

];



foreach($requiredTables as $table){


    if(in_array($table,$tables)){


        ok("Table Exists : ".$table);


    }else{


        fail("Missing Table : ".$table);


    }


}



/*
|--------------------------------------------------------------------------
| Data Count
|--------------------------------------------------------------------------
*/


$countTables = [

    'users',
    'transactions',
    'accounts',
    'goals'


];



foreach($countTables as $table){


    if(in_array($table,$tables)){


        $stmt=$conn->query(
            "SELECT COUNT(*) AS total FROM `$table`"
        );


        $result=$stmt->fetch(PDO::FETCH_ASSOC);



        ok(
            ucfirst($table).
            " Records : ".
            $result['total']
        );


    }


}



/*
|--------------------------------------------------------------------------
| Authorization Header
|--------------------------------------------------------------------------
*/


if(!empty($_SERVER['HTTP_AUTHORIZATION'])){


    $authorization=$_SERVER['HTTP_AUTHORIZATION'];


}


elseif(!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])){


    $authorization=$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];


}


elseif(function_exists('getallheaders')){


    foreach(getallheaders() as $key=>$value){


        if(strtolower($key)=='authorization'){

            $authorization=$value;

            break;

        }

    }


}



if($authorization){


    ok("Authorization Header Received");


}else{


    warn("Authorization Header Not Found");


}



}

catch(Throwable $e){


fail($e->getMessage());


echo "</table>";

echo "<h3>Exception Details</h3>";

echo "<pre>";

echo "Message : ".$e->getMessage()."\n\n";

echo "File    : ".$e->getFile()."\n";

echo "Line    : ".$e->getLine()."\n\n";

echo $e->getTraceAsString();


echo "</pre>";


exit;


}



?>


</table>


</div>



<div class="card">


<h3>🔐 Authorization Header</h3>


<pre><?php

echo htmlspecialchars(
    $authorization ?: 'Not Found'
);

?></pre>


</div>




<div class="card">


<h3>🖥 Server Information</h3>


<pre><?php

print_r($_SERVER);

?></pre>


</div>



</div>


</body>

</html>
