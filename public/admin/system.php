<?php
/**
 * SanMedic System Info
 */
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../../config/config.php';

// Get system info
$phpVersion = phpversion();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? 'N/A';

// Disk usage
$diskTotal = disk_total_space('/');
$diskFree = disk_free_space('/');
$diskUsed = $diskTotal - $diskFree;
$diskPercent = round(($diskUsed / $diskTotal) * 100, 1);

// Database info
try {
    $dbSize = $pdo->query("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
        FROM information_schema.tables 
        WHERE table_schema = 'sanmedic_db'
    ")->fetchColumn();
    
    $tableCount = $pdo->query("
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_schema = 'sanmedic_db'
    ")->fetchColumn();
    
    $dbStatus = 'Connected';
} catch (Exception $e) {
    $dbSize = 'N/A';
    $tableCount = 'N/A';
    $dbStatus = 'Error: ' . $e->getMessage();
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>სისტემა - SanMedic Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .admin-nav { background: #1e3c72; }
    </style>
<<<<<<< HEAD
  <link rel="stylesheet" href="../css/preclinic-theme.css">
=======
  <link rel="stylesheet" href="/css/preclinic-theme.css">
>>>>>>> origin/main
</head>
<body>

<nav class="navbar navbar-dark admin-nav">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-arrow-left"></i> უკან
        </a>
        <span class="navbar-text text-white">
            <i class="bi bi-cpu"></i> სერვერის ინფორმაცია
        </span>
    </div>
</nav>

<div class="container py-4">
    <div class="row g-4">
        
        <!-- PHP Info -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-filetype-php text-primary"></i> PHP
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><td>ვერსია</td><td><strong><?= $phpVersion ?></strong></td></tr>
                        <tr><td>სერვერი</td><td><?= htmlspecialchars($serverSoftware) ?></td></tr>
                        <tr><td>Document Root</td><td><code><?= htmlspecialchars($documentRoot) ?></code></td></tr>
                        <tr><td>Memory Limit</td><td><?= ini_get('memory_limit') ?></td></tr>
                        <tr><td>Max Upload</td><td><?= ini_get('upload_max_filesize') ?></td></tr>
                        <tr><td>Max POST</td><td><?= ini_get('post_max_size') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Database Info -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-database text-success"></i> MySQL Database
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><td>სტატუსი</td><td><span class="badge bg-success"><?= $dbStatus ?></span></td></tr>
                        <tr><td>ბაზის ზომა</td><td><strong><?= $dbSize ?> MB</strong></td></tr>
                        <tr><td>ცხრილები</td><td><?= $tableCount ?></td></tr>
                        <tr><td>ბაზის სახელი</td><td><code>sanmedic_db</code></td></tr>
                        <tr><td>Charset</td><td>utf8mb4</td></tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Disk Usage -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-hdd text-warning"></i> დისკი
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="progress" style="height: 30px;">
                                <div class="progress-bar bg-<?= $diskPercent > 90 ? 'danger' : ($diskPercent > 70 ? 'warning' : 'success') ?>" 
                                     style="width: <?= $diskPercent ?>%">
                                    <?= $diskPercent ?>% გამოყენებული
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <strong><?= formatBytes($diskUsed) ?></strong> / <?= formatBytes($diskTotal) ?>
                            <br>
                            <small class="text-muted">თავისუფალი: <?= formatBytes($diskFree) ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Docker Containers -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-box text-info"></i> Docker კონტეინერები
                </div>
                <div class="card-body">
                    <?php
                    $dockerOutput = shell_exec('docker ps --filter "name=sanmedic" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>&1');
                    ?>
                    <pre class="bg-dark text-light p-3 rounded"><?= htmlspecialchars($dockerOutput ?: 'Docker ინფორმაცია მიუწვდომელია') ?></pre>
                </div>
            </div>
        </div>
        
        <!-- Loaded Extensions -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-puzzle"></i> PHP Extensions
                </div>
                <div class="card-body">
                    <?php
                    $extensions = get_loaded_extensions();
                    sort($extensions);
                    ?>
                    <div class="row">
                        <?php foreach (array_chunk($extensions, ceil(count($extensions) / 4)) as $chunk): ?>
                        <div class="col-md-3">
                            <ul class="list-unstyled small">
                                <?php foreach ($chunk as $ext): ?>
                                <li><i class="bi bi-check-circle text-success"></i> <?= $ext ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
