<?php
require dirname(dirname(__DIR__)) . "/config/s3_config.php";

$testFile = "/tmp/test_s3.txt";
file_put_contents($testFile, "Test upload " . date("Y-m-d H:i:s"));

$bucket = S3_BUCKET;
$endpoint = str_replace("https://", "", S3_ENDPOINT);
$s3_key = "test/test_" . time() . ".txt";

$content_type = "text/plain";
$date = gmdate("D, d M Y H:i:s T");
$string_to_sign = "PUT\n\n$content_type\n$date\n/$bucket/$s3_key";
$signature = base64_encode(hash_hmac("sha1", $string_to_sign, S3_SECRET_KEY, true));

$url = "https://$bucket.$endpoint/$s3_key";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_PUT, true);
curl_setopt($ch, CURLOPT_INFILE, fopen($testFile, "r"));
curl_setopt($ch, CURLOPT_INFILESIZE, filesize($testFile));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Host: $bucket.$endpoint",
    "Date: $date",
    "Content-Type: $content_type",
    "Authorization: AWS " . S3_ACCESS_KEY . ":$signature"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $code\n";
if ($code == 200) echo "SUCCESS!\n";
else echo "Error: $error\nResponse: $response\n";
