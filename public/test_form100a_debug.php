<?php
header("Content-Type: text/html; charset=utf-8");

$pdo = new PDO("mysql:host=sanmedic-db;dbname=sanmedic_clinic;charset=utf8mb4", "sanmedic", "SanMedic_User_2026!");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sid = (int)($_GET["service_id"] ?? 799);

echo "<h1>Form 100/a Debug - service_id=$sid</h1>";

// 1. Check if record exists
$q = $pdo->prepare("SELECT id, service_id, patient_id, updated_at, LENGTH(payload) as payload_len FROM patient_form100a WHERE service_id=? ORDER BY id DESC LIMIT 1");
$q->execute([$sid]);
$row = $q->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "<p style=\"color:red;\">❌ No record found for service_id=$sid</p>";
    exit;
}

echo "<table border=\"1\" style=\"border-collapse:collapse;\">";
echo "<tr><th>Field</th><th>Value</th></tr>";
foreach ($row as $k => $v) {
    echo "<tr><td>$k</td><td>" . htmlspecialchars($v) . "</td></tr>";
}
echo "</table>";

// 2. Load payload
$q2 = $pdo->prepare("SELECT payload FROM patient_form100a WHERE service_id=? ORDER BY id DESC LIMIT 1");
$q2->execute([$sid]);
$pl = $q2->fetchColumn();
$existing = json_decode($pl, true);

echo "<h2>Payload fields</h2>";
echo "<table border=\"1\" style=\"border-collapse:collapse;\">";
echo "<tr><th>Key</th><th>Value (first 100 chars)</th></tr>";
foreach ($existing as $k => $v) {
    $v = is_string($v) ? $v : json_encode($v);
    echo "<tr><td>$k</td><td>" . htmlspecialchars(mb_substr($v, 0, 100)) . "</td></tr>";
}
echo "</table>";

echo "<p style=\"color:green;\">✅ Data loads correctly from database!</p>";
