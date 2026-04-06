<?php
/**
 * SanMedic Full Backup Creator
 */
error_reporting(E_ALL);
ini_set("display_errors", 0);

session_start();
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST["upload_s3"])) {
    echo json_encode(["success" => false, "error" => "Invalid request"]);
    exit;
}

set_time_limit(3600);
ini_set("memory_limit", "2048M");

require_once dirname(dirname(__DIR__)) . "/config/s3_config.php";

$upload_s3 = $_POST["upload_s3"] === "1";
$backup_dir = __DIR__;
$logs_dir = "$backup_dir/logs";
$log_file = "$logs_dir/backup_" . date("Y-m-d") . ".log";

@mkdir($logs_dir, 0777, true);

function write_log($msg) {
    global $log_file;
    $date = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[$date] $msg\n", FILE_APPEND);
}

function s3_upload_file($file_path, $s3_key) {
    $bucket = S3_BUCKET;
    $endpoint = str_replace("https://", "", S3_ENDPOINT);
    $url = "https://$bucket.$endpoint/$s3_key";
    $content_type = "application/octet-stream";
    
    $date = gmdate("D, d M Y H:i:s T");
    $string_to_sign = "PUT\n\n$content_type\n$date\n/$bucket/$s3_key";
    $signature = base64_encode(hash_hmac("sha1", $string_to_sign, S3_SECRET_KEY, true));
    
    $fh = fopen($file_path, "r");
    if (!$fh) return false;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fh);
    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file_path));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $bucket.$endpoint",
        "Date: $date",
        "Content-Type: $content_type",
        "Authorization: AWS " . S3_ACCESS_KEY . ":$signature"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
    
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    
    return $http_code == 200;
}

try {
    write_log("=== ბექაპი დაიწყო ===");
    
    $date_str = date("Y-m-d");
    $backup_name = "sanmedic_full_backup_$date_str.zip";
    $temp_dir = "/tmp/sanmedic_backup_" . time();
    $sql_file = "$temp_dir/database.sql";
    $backup_path = "$backup_dir/$backup_name";
    
    @mkdir($temp_dir, 0777, true);
    write_log("Temp dir: $temp_dir");
    
    // 1. Database dump
    write_log("მონაცემთა ბაზის ექსპორტი...");
    $cmd = "mysqldump -h sanmedic-db -u root -pSanMedic_Root_2026! --ssl=0 --single-transaction sanmedic_clinic > " . escapeshellarg($sql_file) . " 2>&1";
    exec($cmd, $output, $return_var);
    
    if ($return_var !== 0 || !file_exists($sql_file) || filesize($sql_file) < 1000) {
        throw new Exception("Database export failed");
    }
    $db_size = round(filesize($sql_file) / 1024 / 1024, 2);
    write_log("ბაზა ექსპორტირდა: {$db_size} MB");
    
    // 2. კოდის არქივირება
    write_log("კოდის არქივირება...");
    $public_dir = dirname(__DIR__);
    $code_archive = "$temp_dir/code.tar.gz";
    
    $exclude = "--exclude=backups --exclude=uploads --exclude=*.log";
    exec("cd " . escapeshellarg($public_dir) . " && tar -czf " . escapeshellarg($code_archive) . " $exclude . 2>&1", $out, $ret);
    
    if (file_exists($code_archive)) {
        write_log("კოდი არქივირდა: " . round(filesize($code_archive) / 1024 / 1024, 2) . " MB");
    }
    
    // 3. Uploads არქივირება
    write_log("Uploads არქივირება...");
    $uploads_dir = "$public_dir/uploads";
    $uploads_archive = "$temp_dir/uploads.tar.gz";
    
    if (is_dir($uploads_dir)) {
        exec("tar -czf " . escapeshellarg($uploads_archive) . " -C " . escapeshellarg($public_dir) . " uploads 2>&1");
        if (file_exists($uploads_archive)) {
            write_log("Uploads არქივირდა: " . round(filesize($uploads_archive) / 1024 / 1024, 2) . " MB");
        }
    } else {
        touch($uploads_archive);
        write_log("Uploads დირექტორია არ არსებობს");
    }
    
    // 4. Config არქივირება
    write_log("Config არქივირება...");
    $config_dir = dirname(dirname($public_dir)) . "/config";
    $config_archive = "$temp_dir/config.tar.gz";
    
    if (is_dir($config_dir)) {
        exec("tar -czf " . escapeshellarg($config_archive) . " -C " . escapeshellarg(dirname($config_dir)) . " config 2>&1");
    } else {
        touch($config_archive);
    }
    
    // 5. ZIP შექმნა
    write_log("ZIP არქივის შექმნა...");
    exec("cd " . escapeshellarg($temp_dir) . " && zip -q " . escapeshellarg($backup_path) . " *.sql *.tar.gz 2>&1", $out, $ret);
    
    if (!file_exists($backup_path)) {
        throw new Exception("ZIP creation failed");
    }
    
    $backup_size = filesize($backup_path);
    $backup_size_mb = round($backup_size / 1024 / 1024, 2);
    $backup_size_gb = round($backup_size / 1024 / 1024 / 1024, 2);
    $size_text = $backup_size_gb >= 1 ? "{$backup_size_gb} GB" : "{$backup_size_mb} MB";
    
    write_log("ბექაპი შეიქმნა: $backup_name ($size_text)");
    
    // 6. Cleanup temp
    exec("rm -rf " . escapeshellarg($temp_dir));
    
    // 7. S3 upload
    if ($upload_s3 && defined("S3_ENABLED") && S3_ENABLED) {
        write_log("S3-ზე ატვირთვა...");
        $s3_key = S3_BACKUP_PREFIX . $backup_name;
        
        if (s3_upload_file($backup_path, $s3_key)) {
            write_log("S3-ზე აიტვირთა!");
        } else {
            write_log("S3 ატვირთვა ვერ მოხერხდა");
        }
    }
    
    // 8. Update info
    file_put_contents("$backup_dir/backup_info.json", json_encode([
        "last_backup" => date("Y-m-d H:i:s"),
        "backup_file" => $backup_name,
        "backup_size" => $size_text,
        "backup_type" => $upload_s3 ? "both" : "local"
    ], JSON_PRETTY_PRINT));
    
    write_log("=== ბექაპი დასრულდა წარმატებით ===");
    
    echo json_encode([
        "success" => true,
        "message" => $upload_s3 ? "ბექაპი შეიქმნა და S3-ზე აიტვირთა!" : "ბექაპი წარმატებით შეიქმნა!",
        "file" => $backup_name,
        "size" => $size_text
    ]);
    
} catch (Exception $e) {
    write_log("ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
