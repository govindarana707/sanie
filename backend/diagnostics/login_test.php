<?php
require_once __DIR__ . '/../includes/diagnostic_guard.php';

header("Content-Type: application/json");


$url = "https://sanie.govindarana.com.np/backend/api/auth/login";


$data = [

    "email" => "YOUR_EMAIL",
    "password" => "YOUR_PASSWORD"

];


$ch = curl_init($url);


curl_setopt($ch, CURLOPT_POST, true);

curl_setopt(
    $ch,
    CURLOPT_POSTFIELDS,
    json_encode($data)
);


curl_setopt($ch, CURLOPT_HTTPHEADER, [

    "Content-Type: application/json",
    "Accept: application/json"

]);


curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


// temporary SSL test
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);



$response = curl_exec($ch);



if(curl_errno($ch)){

    echo json_encode([

        "curl_error" => curl_error($ch)

    ]);

    exit;

}



$httpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);


curl_close($ch);



echo json_encode([

    "http_code" => $httpCode,

    "response" => json_decode($response,true)

],JSON_PRETTY_PRINT);
