<?php
session_start();

if (empty($_SESSION["admin_logged_in"])) {
    header("Location: /admin/");
    exit;
}

$logs_dir = __DIR__ . "/logs";
$logs = [];

if (is_dir($logs_dir)) {
    foreach (glob("$logs_dir/*.log") as $file) {
        $logs[] = [
            "name" => basename($file),
            "size" => filesize($file),
            "date" => filemtime($file),
            "path" => $file
        ];
    }
    usort($logs, fn($a, $b) => $b["date"] - $a["date"]);
}

$selected_log = "";
$log_content = "";

if (isset($_GET["view"]) && !empty($_GET["file"])) {
    $file = basename($_GET["file"]);
    $path = "$logs_dir/$file";
    if (file_exists($path)) {
        $selected_log = $file;
        $log_content = file_get_contents($path);
    }
}

if (isset($_POST["clear_logs"])) {
    foreach (glob("$logs_dir/*.log") as $file) {
        unlink($file);
    }
    header("Location: logs.php?cleared=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ლოგები - SanMedic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); min-height: 100vh; }
        .log-content { background: #1a1a2e; color: #00ff00; font-family: monospace; padding: 20px; border-radius: 10px; max-height: 600px; overflow: auto; white-space: pre-wrap; }
        .log-line { margin: 2px 0; }
        .log-line.SUCCESS { color: #00ff00; }
        .log-line.ERROR { color: #ff4444; }
        .log-line.WARN { color: #ffaa00; }
        .log-line.INFO { color: #00aaff; }
    </style>
</head>
<body class="py-4">
<div class="container">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-white mb-0"><i class="bi bi-file-text"></i> ბექაპის ლოგები</h2>
        <a href="/backups/" class="btn btn-light"><i class="bi bi-arrow-left"></i> უკან</a>
    </div>
    
    <?php if (isset($_GET["cleared"])): ?>
    <div class="alert alert-success">ლოგები წაიშალა!</div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list"></i> ლოგ ფაილები</span>
                    <?php if (!empty($logs)): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm(&quot;ყველა წავშალო?&quot;)">
                        <button type="submit" name="clear_logs" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (empty($logs)): ?>
                    <div class="text-center py-4 text-muted">ლოგები არ მოიძებნა</div>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <a href="?view=1&file=<?= urlencode($log["name"]) ?>" 
                       class="list-group-item list-group-item-action <?= $selected_log === $log["name"] ? "active" : "" ?>">
                        <div class="d-flex justify-content-between">
                            <span><?= htmlspecialchars($log["name"]) ?></span>
                            <small><?= date("d.m", $log["date"]) ?></small>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-terminal"></i> 
                    <?= $selected_log ? htmlspecialchars($selected_log) : "აირჩიეთ ლოგ ფაილი" ?>
                </div>
                <div class="card-body p-0">
                    <?php if ($log_content): ?>
                    <div class="log-content">
<?php
$lines = explode("\n", $log_content);
foreach ($lines as $line) {
    $class = "";
    if (strpos($line, "[SUCCESS]") !== false) $class = "SUCCESS";
    elseif (strpos($line, "[ERROR]") !== false) $class = "ERROR";
    elseif (strpos($line, "[WARN]") !== false) $class = "WARN";
    elseif (strpos($line, "[INFO]") !== false) $class = "INFO";
    echo "<div class=\"log-line $class\">" . htmlspecialchars($line) . "</div>";
}
?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-file-text" style="font-size: 3rem;"></i>
                        <p class="mt-2">აირჩიეთ ლოგ ფაილი სანახავად</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
