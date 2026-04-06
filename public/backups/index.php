<?php
session_start();

// S3 კონფიგურაცია
$s3_endpoint = "https://nbg1.your-objectstorage.com";
$s3_bucket = "sanmedic";
$s3_access_key = "736IUKBC61V7VPA7GEE4";
$s3_secret_key = "1EHTR4pT2FVkXKo8VxnxUsWQHB51ZMlfjpBhEOgC";
$s3_region = "nbg1";

$web_backup_dir = __DIR__;

function s3_sign($method, $uri, $headers, $payload = "") {
    global $s3_access_key, $s3_secret_key, $s3_region;
    $service = "s3";
    $algorithm = "AWS4-HMAC-SHA256";
    $date = gmdate("Ymd");
    $datetime = gmdate("Ymd\THis\Z");
    $scope = "$date/$s3_region/$service/aws4_request";
    
    $headers["x-amz-date"] = $datetime;
    $headers["x-amz-content-sha256"] = hash("sha256", $payload);
    ksort($headers);
    
    $canonical_headers = "";
    $signed_headers = "";
    foreach ($headers as $k => $v) {
        $canonical_headers .= strtolower($k) . ":" . trim($v) . "\n";
        $signed_headers .= strtolower($k) . ";";
    }
    $signed_headers = rtrim($signed_headers, ";");
    
    $canonical_request = "$method\n$uri\n\n$canonical_headers\n$signed_headers\n" . hash("sha256", $payload);
    $string_to_sign = "$algorithm\n$datetime\n$scope\n" . hash("sha256", $canonical_request);
    
    $kDate = hash_hmac("sha256", $date, "AWS4" . $s3_secret_key, true);
    $kRegion = hash_hmac("sha256", $s3_region, $kDate, true);
    $kService = hash_hmac("sha256", $service, $kRegion, true);
    $kSigning = hash_hmac("sha256", "aws4_request", $kService, true);
    $signature = hash_hmac("sha256", $string_to_sign, $kSigning);
    
    $headers["Authorization"] = "$algorithm Credential=$s3_access_key/$scope, SignedHeaders=$signed_headers, Signature=$signature";
    return $headers;
}

function s3_list() {
    global $s3_endpoint, $s3_bucket;
    $uri = "/$s3_bucket/?prefix=backups/";
    $headers = ["Host" => parse_url($s3_endpoint, PHP_URL_HOST)];
    $headers = s3_sign("GET", $uri, $headers);
    
    $ch = curl_init($s3_endpoint . $uri);
    $curl_headers = [];
    foreach ($headers as $k => $v) $curl_headers[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $backups = [];
    if (preg_match_all("/<Key>([^<]+)<\/Key>.*?<Size>(\d+)<\/Size>.*?<LastModified>([^<]+)<\/LastModified>/s", $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if (strpos($m[1], ".zip") !== false) {
                $backups[] = ["key" => $m[1], "size" => (int)$m[2], "date" => $m[3]];
            }
        }
    }
    usort($backups, fn($a, $b) => strcmp($b["date"], $a["date"]));
    return $backups;
}

function s3_upload($filepath, $key) {
    global $s3_endpoint, $s3_bucket;
    $content = file_get_contents($filepath);
    $uri = "/$s3_bucket/$key";
    $headers = [
        "Host" => parse_url($s3_endpoint, PHP_URL_HOST),
        "Content-Type" => "application/zip",
        "Content-Length" => strlen($content)
    ];
    $headers = s3_sign("PUT", $uri, $headers, $content);
    
    $ch = curl_init($s3_endpoint . $uri);
    $curl_headers = [];
    foreach ($headers as $k => $v) $curl_headers[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code == 200;
}

function s3_delete($key) {
    global $s3_endpoint, $s3_bucket;
    $uri = "/$s3_bucket/$key";
    $headers = ["Host" => parse_url($s3_endpoint, PHP_URL_HOST)];
    $headers = s3_sign("DELETE", $uri, $headers);
    
    $ch = curl_init($s3_endpoint . $uri);
    $curl_headers = [];
    foreach ($headers as $k => $v) $curl_headers[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code == 204;
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . " GB";
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . " MB";
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . " KB";
    return $bytes . " B";
}

// POST Actions
if (isset($_POST["upload_to_s3"]) && !empty($_POST["filename"])) {
    $filename = basename($_POST["filename"]);
    $filepath = $web_backup_dir . "/" . $filename;
    if (file_exists($filepath)) {
        if (s3_upload($filepath, "backups/" . $filename)) {
            $_SESSION["backup_message"] = $filename . " აიტვირთა S3-ზე!";
        } else {
            $_SESSION["backup_error"] = "S3 ატვირთვის შეცდომა";
        }
    }
    header("Location: /backups/");
    exit;
}

if (isset($_POST["delete_s3"]) && !empty($_POST["key"])) {
    if (s3_delete($_POST["key"])) {
        $_SESSION["backup_message"] = "S3 ბექაპი წაიშალა!";
    } else {
        $_SESSION["backup_error"] = "S3 წაშლის შეცდომა";
    }
    header("Location: /backups/");
    exit;
}

if (isset($_POST["delete_local"]) && !empty($_POST["filename"])) {
    $filename = basename($_POST["filename"]);
    $filepath = $web_backup_dir . "/" . $filename;
    if (file_exists($filepath) && unlink($filepath)) {
        $_SESSION["backup_message"] = $filename . " წაიშალა!";
    }
    header("Location: /backups/");
    exit;
}

// S3 Download
if (isset($_GET["s3download"]) && !empty($_GET["key"])) {
    $key = $_GET["key"];
    $filename = basename($key);
    $uri = "/$s3_bucket/$key";
    $headers = ["Host" => parse_url($s3_endpoint, PHP_URL_HOST)];
    $headers = s3_sign("GET", $uri, $headers);
    
    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    
    $ch = curl_init($s3_endpoint . $uri);
    $curl_headers = [];
    foreach ($headers as $k => $v) $curl_headers[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        return strlen($data);
    });
    curl_exec($ch);
    curl_close($ch);
    exit;
}

// Get backups
$backups = [];
foreach (glob($web_backup_dir . "/sanmedic_full_backup_*.zip") as $file) {
    $backups[] = [
        "name" => basename($file),
        "size" => filesize($file),
        "date" => filemtime($file)
    ];
}
usort($backups, fn($a, $b) => $b["date"] - $a["date"]);

$s3_backups = s3_list();

$total_local = array_sum(array_column($backups, "size"));
$total_s3 = array_sum(array_column($s3_backups, "size"));

$message = $_SESSION["backup_message"] ?? null;
$error = $_SESSION["backup_error"] ?? null;
unset($_SESSION["backup_message"], $_SESSION["backup_error"]);
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SanMedic - ბექაპ მენეჯერი</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #0f172a;
            --card: #1e293b;
            --border: #334155;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Inter", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 2rem;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .header-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
        }
        .header h1 { font-size: 1.75rem; font-weight: 700; }
        .header p { color: var(--text-muted); margin-top: 0.25rem; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border);
        }
        .stat-card .icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }
        .stat-card .icon.blue { background: rgba(99, 102, 241, 0.2); color: var(--primary); }
        .stat-card .icon.green { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        .stat-card .icon.orange { background: rgba(245, 158, 11, 0.2); color: var(--warning); }
        .stat-card .value { font-size: 1.5rem; font-weight: 700; }
        .stat-card .label { color: var(--text-muted); font-size: 0.875rem; margin-top: 0.25rem; }
        
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); }
        
        .actions-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover:not(:disabled) { background: var(--primary-dark); }
        .btn-gradient {
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            color: white;
        }
        .btn-gradient:hover:not(:disabled) { filter: brightness(1.1); }
        
        .cron-banner {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .cron-icon {
            width: 48px; height: 48px;
            background: var(--primary);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }
        .cron-info h4 { font-weight: 600; margin-bottom: 0.25rem; }
        .cron-info p { color: var(--text-muted); font-size: 0.875rem; }
        
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.5rem;
        }
        .tab {
            padding: 0.75rem 1.25rem;
            border-radius: 8px 8px 0 0;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
        }
        .tab:hover { color: var(--text); background: var(--card); }
        .tab.active { color: var(--primary); background: var(--card); border-bottom: 2px solid var(--primary); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .card {
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
        }
        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .badge-primary { background: rgba(99, 102, 241, 0.2); color: var(--primary); }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem 1.25rem; text-align: left; }
        th { color: var(--text-muted); font-weight: 500; font-size: 0.875rem; border-bottom: 1px solid var(--border); }
        tr:not(:last-child) td { border-bottom: 1px solid var(--border); }
        
        .file-info { display: flex; align-items: center; gap: 0.75rem; }
        .file-icon {
            width: 40px; height: 40px;
            background: rgba(99, 102, 241, 0.2);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: var(--primary);
        }
        .file-name { font-weight: 500; }
        .file-date { color: var(--text-muted); font-size: 0.8rem; }
        
        .actions-cell { display: flex; gap: 0.5rem; }
        .btn-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-icon.download { background: rgba(99, 102, 241, 0.2); color: var(--primary); }
        .btn-icon.download:hover { background: var(--primary); color: white; }
        .btn-icon.upload { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        .btn-icon.upload:hover { background: var(--success); color: white; }
        .btn-icon.delete { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        .btn-icon.delete:hover { background: var(--danger); color: white; }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 1.5rem;
            z-index: 1000;
        }
        .loading-overlay.show { display: flex; }
        .spinner {
            width: 48px; height: 48px;
            border: 3px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { font-size: 1.1rem; color: var(--text-muted); }
        
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            th:nth-child(2), td:nth-child(2) { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-icon"><i class="fas fa-database"></i></div>
            <div>
                <h1>ბექაპ მენეჯერი</h1>
                <p>SanMedic სისტემის სარეზერვო ასლები</p>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon blue"><i class="fas fa-hard-drive"></i></div>
                <div class="value"><?= count($backups) ?></div>
                <div class="label">ლოკალური ბექაპი</div>
            </div>
            <div class="stat-card">
                <div class="icon green"><i class="fas fa-cloud"></i></div>
                <div class="value"><?= count($s3_backups) ?></div>
                <div class="label">S3 ბექაპი</div>
            </div>
            <div class="stat-card">
                <div class="icon orange"><i class="fas fa-weight-hanging"></i></div>
                <div class="value"><?= formatSize($total_local + $total_s3) ?></div>
                <div class="label">სულ მოცულობა</div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="actions-bar">
            <button type="button" id="btnLocal" onclick="createBackup(false)" class="btn btn-primary">
                <i class="fas fa-download"></i> ლოკალური ბექაპი
            </button>
            <button type="button" id="btnS3" onclick="createBackup(true)" class="btn btn-gradient">
                <i class="fas fa-cloud-arrow-up"></i> ლოკალური + S3
            </button>
        </div>
        
        <div class="cron-banner">
            <div class="cron-icon"><i class="fas fa-clock"></i></div>
            <div class="cron-info">
                <h4>ავტომატური ბექაპი</h4>
                <p>ყოველდღე 00:00 საათზე • 31 დღიანი რეტენცია • S3 სინქრონიზაცია</p>
            </div>
        </div>
        
        <div class="tabs">
            <button class="tab active" onclick="showTab(event, local)">
                <i class="fas fa-hard-drive"></i> ლოკალური
            </button>
            <button class="tab" onclick="showTab(event, s3)">
                <i class="fas fa-cloud"></i> Hetzner S3
            </button>
        </div>
        
        <div id="local" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-folder-open"></i>
                        <span>ლოკალური ბექაპები</span>
                    </div>
                    <span class="badge badge-primary"><i class="fas fa-file-archive"></i> <?= count($backups) ?> ფაილი</span>
                </div>
                
                <?php if (empty($backups)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>ბექაპები არ მოიძებნა</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ფაილი</th>
                                <th>ზომა</th>
                                <th>მოქმედება</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td>
                                    <div class="file-info">
                                        <div class="file-icon"><i class="fas fa-file-zipper"></i></div>
                                        <div>
                                            <div class="file-name"><?= htmlspecialchars($b["name"]) ?></div>
                                            <div class="file-date"><?= date("Y-m-d H:i", $b["date"]) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= formatSize($b["size"]) ?></td>
                                <td>
                                    <div class="actions-cell">
                                        <a href="<?= htmlspecialchars($b["name"]) ?>" class="btn-icon download" title="გადმოწერა"><i class="fas fa-download"></i></a>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($b["name"]) ?>">
                                            <button type="submit" name="upload_to_s3" class="btn-icon upload" title="S3-ზე ატვირთვა"><i class="fas fa-cloud-arrow-up"></i></button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirm(წავშალოთ?)">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($b["name"]) ?>">
                                            <button type="submit" name="delete_local" class="btn-icon delete" title="წაშლა"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="s3" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-cloud"></i>
                        <span>Hetzner S3 ბექაპები</span>
                    </div>
                    <span class="badge badge-success"><i class="fas fa-file-archive"></i> <?= count($s3_backups) ?> ფაილი</span>
                </div>
                
                <?php if (empty($s3_backups)): ?>
                    <div class="empty-state">
                        <i class="fas fa-cloud"></i>
                        <p>S3 ბექაპები არ მოიძებნა</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ფაილი</th>
                                <th>ზომა</th>
                                <th>მოქმედება</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($s3_backups as $b): ?>
                            <tr>
                                <td>
                                    <div class="file-info">
                                        <div class="file-icon"><i class="fas fa-file-zipper"></i></div>
                                        <div>
                                            <div class="file-name"><?= htmlspecialchars(basename($b["key"])) ?></div>
                                            <div class="file-date"><?= date("Y-m-d H:i", strtotime($b["date"])) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= formatSize($b["size"]) ?></td>
                                <td>
                                    <div class="actions-cell">
                                        <a href="?s3download=1&key=<?= urlencode($b["key"]) ?>" class="btn-icon download" title="გადმოწერა"><i class="fas fa-download"></i></a>
                                        <form method="POST" style="display:inline" onsubmit="return confirm(S3-დან წავშალოთ?)">
                                            <input type="hidden" name="key" value="<?= htmlspecialchars($b["key"]) ?>">
                                            <button type="submit" name="delete_s3" class="btn-icon delete" title="წაშლა"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="loading-overlay" id="loading">
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">ბექაპი იქმნება...</div>
    </div>
    
    <script>
        function showTab(e, tabId) {
            document.querySelectorAll(".tab").forEach(t => t.classList.remove("active"));
            document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
            e.target.closest(".tab").classList.add("active");
            document.getElementById(tabId).classList.add("active");
        }
        
        function createBackup(withS3) {
            document.getElementById("loading").classList.add("show");
            document.getElementById("loadingText").textContent = withS3 ? "ბექაპი იქმნება და S3-ზე იტვირთება..." : "ბექაპი იქმნება...";
            
            document.getElementById("btnLocal").disabled = true;
            document.getElementById("btnS3").disabled = true;
            
            fetch("/backups/run_backup.php", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: "s3=" + (withS3 ? "1" : "0")
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById("loading").classList.remove("show");
                if (data.success) {
                    alert("✅ ბექაპი წარმატებით შეიქმნა!");
                    location.reload();
                } else {
                    alert("❌ შეცდომა: " + (data.error || "უცნობი შეცდომა"));
                    document.getElementById("btnLocal").disabled = false;
                    document.getElementById("btnS3").disabled = false;
                }
            })
            .catch(err => {
                document.getElementById("loading").classList.remove("show");
                alert("❌ შეცდომა: " + err.message);
                document.getElementById("btnLocal").disabled = false;
                document.getElementById("btnS3").disabled = false;
            });
        }
    </script>
</body>
</html>
