<?php
/**
 * SanMedic Backup System
 */
session_start();

// Check admin login
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../../config/config.php';

$message = '';
$messageType = '';

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'create_backup') {
        $backupType = $_POST['backup_type'] ?? 'full';
        $backupDir = __DIR__ . '/../backups';
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "sanmedic_{$backupType}_backup_{$timestamp}";
        
        try {
            // Database backup
            $dbFile = "{$backupDir}/{$backupName}_database.sql";
            $cmd = "docker exec sanmedic_mysql mysqldump -u root -p'SanMedic2026#Root' --single-transaction --routines --triggers --no-tablespaces sanmedic_db > " . escapeshellarg($dbFile) . " 2>&1";
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0 || !file_exists($dbFile)) {
                throw new Exception('ბაზის ბექაპი ვერ შეიქმნა');
            }
            
            if ($backupType === 'full') {
                // Full backup with files
                $zipFile = "{$backupDir}/{$backupName}.zip";
                $siteDir = dirname(__DIR__);
                
                $zipCmd = "cd " . escapeshellarg(dirname($siteDir)) . " && zip -r " . escapeshellarg($zipFile) . " sanmedic/ -x 'sanmedic/public/backups/*' -x 'sanmedic/mysql/data/*' -x '*.log' 2>&1";
                exec($zipCmd, $zipOutput, $zipReturnCode);
                
                if ($zipReturnCode === 0 && file_exists($zipFile)) {
                    $message = "✅ სრული ბექაპი შეიქმნა: " . basename($zipFile);
                    $messageType = 'success';
                } else {
                    throw new Exception('ზიპის შექმნა ვერ მოხერხდა');
                }
            } else {
                $message = "✅ ბაზის ბექაპი შეიქმნა: " . basename($dbFile);
                $messageType = 'success';
            }
            
        } catch (Exception $e) {
            $message = "❌ შეცდომა: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
    
    if ($action === 'delete_backup') {
        $file = basename($_POST['file'] ?? '');
        $backupDir = __DIR__ . '/../backups';
        $filePath = "{$backupDir}/{$file}";
        
        if ($file && file_exists($filePath) && strpos(realpath($filePath), realpath($backupDir)) === 0) {
            unlink($filePath);
            $message = "✅ ფაილი წაიშალა: {$file}";
            $messageType = 'success';
        } else {
            $message = "❌ ფაილი ვერ წაიშალა";
            $messageType = 'danger';
        }
    }
}

// Get existing backups
$backupDir = __DIR__ . '/../backups';
$backups = [];
if (is_dir($backupDir)) {
    foreach (glob("{$backupDir}/*") as $file) {
        if (is_file($file)) {
            $backups[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'date' => filemtime($file),
                'path' => $file
            ];
        }
    }
    // Sort by date descending
    usort($backups, fn($a, $b) => $b['date'] - $a['date']);
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
    <title>ბექაპი - SanMedic Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .admin-nav { background: #1e3c72; }
    </style>
  <link rel="stylesheet" href="../css/preclinic-theme.css">
</head>
<body>

<nav class="navbar navbar-dark admin-nav">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-arrow-left"></i> უკან
        </a>
        <span class="navbar-text text-white">
            <i class="bi bi-cloud-download"></i> ბექაპის სისტემა
        </span>
    </div>
</nav>

<div class="container py-4">
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Create Backup -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-plus-circle"></i> ახალი ბექაპის შექმნა
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="create_backup">
                
                <div class="col-md-6">
                    <label class="form-label">ბექაპის ტიპი</label>
                    <select name="backup_type" class="form-select">
                        <option value="full">სრული (ფაილები + ბაზა) - ~40 MB</option>
                        <option value="db">მხოლოდ ბაზა - ~3 MB</option>
                    </select>
                </div>
                
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-success btn-lg" onclick="this.innerHTML='<span class=\'spinner-border spinner-border-sm\'></span> მზადდება...'; this.disabled=true; this.form.submit();">
                        <i class="bi bi-cloud-arrow-up"></i> ბექაპის შექმნა
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Existing Backups -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-archive"></i> არსებული ბექაპები (<?= count($backups) ?>)
        </div>
        <div class="card-body p-0">
            <?php if (empty($backups)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                    <p class="mt-2">ბექაპები არ მოიძებნა</p>
                </div>
            <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ფაილი</th>
                            <th>ზომა</th>
                            <th>თარიღი</th>
                            <th width="200">მოქმედება</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td>
                                <?php if (str_ends_with($backup['name'], '.zip')): ?>
                                    <i class="bi bi-file-zip text-warning"></i>
                                <?php else: ?>
                                    <i class="bi bi-database text-info"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($backup['name']) ?>
                            </td>
                            <td><?= formatBytes($backup['size']) ?></td>
                            <td><?= date('Y-m-d H:i', $backup['date']) ?></td>
                            <td>
                                <a href="../backups/<?= urlencode($backup['name']) ?>" 
                                   class="btn btn-sm btn-primary" download>
                                    <i class="bi bi-download"></i> გადმოწერა
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('წავშალო?')">
                                    <input type="hidden" name="action" value="delete_backup">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($backup['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Instructions -->
    <div class="card mt-4">
        <div class="card-header">
            <i class="bi bi-info-circle"></i> ახალ სერვერზე აღდგენა
        </div>
        <div class="card-body">
            <ol>
                <li>გადმოწერე სრული ბექაპი (.zip)</li>
                <li>ამოაშალე: <code>unzip sanmedic_*.zip</code></li>
                <li>შეცვალე <code>config/config.php</code> ახალი სერვერის მონაცემებით</li>
                <li>ბაზის იმპორტი: <code>mysql -u user -p database < *_database.sql</code></li>
                <li>Docker გაშვება: <code>docker-compose up -d</code></li>
            </ol>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
