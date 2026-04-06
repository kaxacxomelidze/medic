<?php
session_start();
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "POST only"]);
    exit;
}

$s3 = isset($_POST["s3"]) && $_POST["s3"] === "1";

// Webhook-ს ვიძახებ localhost:9999
$ch = curl_init("http://172.19.0.1:9999/backup");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["s3" => $s3]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(["success" => false, "error" => $error]);
} else {
    echo $response;
}
