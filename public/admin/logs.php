<?php
/**
 * SanMedic Logs Viewer
 */
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$logsDir = __DIR__ . '/../../logs';
$selectedLog = $_GET['log'] ?? '';
$logContent = '';

// Get available log files
$logFiles = [];
if (is_dir($logsDir)) {
    foreach (glob("{$logsDir}/*.log") as $file) {
        $logFiles[] = basename($file);
    }
    rsort($logFiles);
}

// Read selected log
if ($selectedLog && in_array($selectedLog, $logFiles)) {
    $logPath = "{$logsDir}/{$selectedLog}";
    $logContent = file_get_contents($logPath);
    
    // For JSON logs, format nicely
    if (strpos($selectedLog, 'actions_') === 0 || strpos($selectedLog, 'deletes_') === 0 || strpos($selectedLog, 'errors_') === 0) {
        $lines = explode("\n", trim($logContent));
        $formatted = [];
        foreach (array_reverse($lines) as $line) {
            if (empty($line)) continue;
            $json = json_decode($line, true);
            if ($json) {
                $formatted[] = $json;
            }
        }
        $logContent = $formatted;
    }
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ლოგები - SanMedic Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .admin-nav { background: #1e3c72; }
        .log-content { font-family: monospace; font-size: 12px; max-height: 600px; overflow-y: auto; }
        .log-entry { border-left: 3px solid #17a2b8; padding-left: 10px; margin-bottom: 10px; }
        .log-entry.delete { border-color: #dc3545; }
        .log-entry.error { border-color: #ffc107; }
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
            <i class="bi bi-journal-text"></i> სისტემის ლოგები
        </span>
    </div>
</nav>

<div class="container py-4">
    <div class="row">
        <!-- Log Files List -->
        <div class="col-md-3">
            <div class="card">
                <div class="card-header">ლოგ ფაილები</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($logFiles as $file): ?>
                    <a href="?log=<?= urlencode($file) ?>" 
                       class="list-group-item list-group-item-action <?= $selectedLog === $file ? 'active' : '' ?>">
                        <?php if (strpos($file, 'deletes_') === 0): ?>
                            <i class="bi bi-trash text-danger"></i>
                        <?php elseif (strpos($file, 'errors_') === 0): ?>
                            <i class="bi bi-exclamation-triangle text-warning"></i>
                        <?php else: ?>
                            <i class="bi bi-file-text"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($file) ?>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if (empty($logFiles)): ?>
                    <div class="list-group-item text-muted">ლოგები არ მოიძებნა</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Log Content -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <?= $selectedLog ? htmlspecialchars($selectedLog) : 'აირჩიე ლოგ ფაილი' ?>
                </div>
                <div class="card-body log-content">
                    <?php if (is_array($logContent)): ?>
                        <?php foreach ($logContent as $entry): ?>
                        <div class="log-entry <?= ($entry['action'] ?? '') === 'DELETE' ? 'delete' : '' ?>">
                            <small class="text-muted"><?= $entry['timestamp'] ?? '' ?></small>
                            <strong class="ms-2"><?= htmlspecialchars($entry['action'] ?? 'N/A') ?></strong>
                            <span class="badge bg-secondary"><?= htmlspecialchars($entry['entity'] ?? '') ?></span>
                            <span class="text-info">#<?= $entry['entity_id'] ?? '' ?></span>
                            <br>
                            <small>
                                მომხმარებელი: <?= htmlspecialchars($entry['user_name'] ?? 'unknown') ?> 
                                (ID: <?= $entry['user_id'] ?? 0 ?>) | 
                                IP: <?= htmlspecialchars($entry['ip'] ?? '') ?>
                            </small>
                            <?php if (!empty($entry['data'])): ?>
                            <details class="mt-1">
                                <summary>დეტალები</summary>
                                <pre class="bg-light p-2 mt-1"><?= htmlspecialchars(json_encode($entry['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </details>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($logContent)): ?>
                        <p class="text-muted">ცარიელია</p>
                        <?php endif; ?>
                        
                    <?php elseif ($logContent): ?>
                        <pre><?= htmlspecialchars($logContent) ?></pre>
                    <?php else: ?>
                        <p class="text-muted text-center py-5">აირჩიე ლოგ ფაილი მარცხენა მენიუდან</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
