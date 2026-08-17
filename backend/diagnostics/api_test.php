<?php
require_once __DIR__ . '/../includes/diagnostic_guard.php';

error_reporting(E_ALL);
ini_set('display_errors',1);

header('Content-Type: text/html; charset=utf-8');


function success($msg){

    echo "<tr>
            <td style='color:green'>✅</td>
            <td>$msg</td>
          </tr>";

}


function error_msg($msg){

    echo "<tr style='background:#ffe5e5'>
            <td>❌</td>
            <td>$msg</td>
          </tr>";

}


function warning($msg){

    echo "<tr style='background:#fff8dc'>
            <td>⚠️</td>
            <td>$msg</td>
          </tr>";

}


?>

<!DOCTYPE html>
<html>

<head>

<title>SanIE API Diagnostic</title>

<style>

body{
    font-family:Arial;
    background:#f5f5f5;
    margin:30px;
}

table{

    width:100%;
    background:white;
    border-collapse:collapse;

}

td,th{

    border:1px solid #ddd;
    padding:12px;

}

th{

    background:#10B981;
    color:white;

}

pre{

    background:#272822;
    color:white;
    padding:15px;

}

</style>

</head>


<body>


<h2>🚀 SanIE API Diagnostic</h2>


<table>


<tr>

<th>Status</th>
<th>Test</th>

</tr>


<?php


try{


/*
|--------------------------------------------------------------------------
| Load Config
|--------------------------------------------------------------------------
*/


$config = __DIR__."/../config/database.php";


if(file_exists($config)){

    require_once $config;

    success("Database Config Loaded");

}else{

    throw new Exception(
        "Database config missing"
    );

}



/*
|--------------------------------------------------------------------------
| Database Test
|--------------------------------------------------------------------------
*/


$db = new Database();


$conn = $db->getConnection();



if($conn){

    success("Database Connection OK");

}else{

    throw new Exception(
        "Database Connection Failed"
    );

}



/*
|--------------------------------------------------------------------------
| JWT Test
|--------------------------------------------------------------------------
*/


$jwtFile = __DIR__."/../includes/jwt.php";


if(file_exists($jwtFile)){

    require_once $jwtFile;

    success("JWT File Loaded");

}else{

    warning(
        "JWT File Not Found"
    );

}



if(class_exists("JWT")){

    success("JWT Class Available");

}else{

    warning(
        "JWT Class Not Detected"
    );

}



/*
|--------------------------------------------------------------------------
| API Folder Test
|--------------------------------------------------------------------------
*/


$apiPath = __DIR__."/../api";


if(is_dir($apiPath)){

    success(
        "API Directory Exists"
    );

}else{

    warning(
        "API Directory Missing"
    );

}



/*
|--------------------------------------------------------------------------
| Authorization Test
|--------------------------------------------------------------------------
*/


$token = "";


if(isset($_SERVER['HTTP_AUTHORIZATION'])){


    $header=$_SERVER['HTTP_AUTHORIZATION'];


    if(str_starts_with($header,"Bearer ")){

        $token =
        substr($header,7);

    }

}



if($token){

    success(
        "JWT Token Received"
    );


}else{

    warning(
        "No JWT Token Received (Normal for browser testing)"
    );

}



?>

</table>


<h3>🔑 Token Status</h3>


<pre>

<?php

echo $token ?: "No Token";

?>

</pre>



<h3>📡 Server Request</h3>


<pre>

<?php

print_r($_SERVER);

?>

</pre>



<?php


}

catch(Throwable $e){


error_msg(
    $e->getMessage()
);


echo "</table>";


echo "<h3>Error Details</h3>";

echo "<pre>";

echo $e->getMessage();

echo "\n\nFile: ".$e->getFile();

echo "\nLine: ".$e->getLine();


echo "</pre>";


}


?>


</body>

</html>
