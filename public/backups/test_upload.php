<?php
require dirname(dirname(__DIR__)) . "/config/s3_config.php";

function s3_sign($method, $bucket, $key, $content_type = "") {
    $date = gmdate("D, d M Y H:i:s T");
    $string_to_sign = "$method\n\n$content_type\n$date\n/$bucket/$key";
    $signature = base64_encode(hash_hmac("sha1", $string_to_sign, S3_SECRET_KEY, true));
    return ["date" => $date, "auth" => "AWS " . S3_ACCESS_KEY . ":$signature"];
}

function s3_upload($file_path, $s3_key) {
    $bucket = S3_BUCKET;
    $endpoint = str_replace("https://", "", S3_ENDPOINT);
    $url = "https://$bucket.$endpoint/$s3_key";
    $content_type = "application/octet-stream";
    
    $sign = s3_sign("PUT", $bucket, $s3_key, $content_type);
    
    $fh = fopen($file_path, "r");
    if (!$fh) {
        echo "Cannot open file\n";
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fh);
    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file_path));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $bucket.$endpoint",
        "Date: " . $sign["date"],
        "Content-Type: $content_type",
        "Authorization: " . $sign["auth"]
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    
    echo "HTTP: $http_code\n";
    if ($http_code != 200) {
        echo "Error: $error\n";
        echo "Response: $response\n";
    }
    
    return $http_code == 200;
}

$file = __DIR__ . "/sanmedic_full_backup_2026-01-27.zip";
echo "File: $file\n";
echo "Size: " . number_format(filesize($file)/1024/1024, 2) . " MB\n";
echo "Uploading...\n";

$result = s3_upload($file, "sanmedic/backups/sanmedic_full_backup_2026-01-27.zip");
echo $result ? "SUCCESS!\n" : "FAILED\n";
