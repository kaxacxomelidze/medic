<?php
session_start();
header("Content-Type: application/json");

// მხოლოდ POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "POST only"]);
    exit;
}

$upload_s3 = isset($_POST["s3"]) ? "both" : "local";
$script = "/root/sanmedic/do_backup.sh";

// Background-ში გავუშვათ
$command = "nohup $script $upload_s3 > /var/log/sanmedic_instant_backup.log 2>&1 &";

// PHP-დან ვერ გავუშვებ host-ის სკრიპტს პირდაპირ
// ამიტომ trigger ფაილს ვქმნი და ხელით ვუშვებ trigger_backup.sh-ს

$trigger_val = isset($_POST["s3"]) ? "1" : "0";
file_put_contents("/var/www/html/backups/trigger", $trigger_val);

// სერვერზე cURL-ით localhost endpoint-ს ვიძახებ რომელიც trigger-ს ამოწმებს
echo json_encode(["success" => true, "message" => "ბექაპი იქმნება..."]);
