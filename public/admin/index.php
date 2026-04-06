<?php
/**
 * SanMedic Admin Panel
 * Login: admin / admin123
 */
session_start();

// Admin credentials
define("ADMIN_USER", "admin");
define("ADMIN_PASS", "admin123");

$error = "";

// Check login
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["login"])) {
    $user = trim($_POST["username"] ?? "");
    error_log("LOGIN ATTEMPT: user=" . $_POST["username"] . " pass=" . $_POST["password"]);
    $pass = $_POST["password"] ?? "";
    
    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_login_time"] = time();
        header("Location: index.php");
        exit;
    } else {
        $error = "არასწორი მომხმარებელი ან პაროლი";
    }
}

// Logout
if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$isLoggedIn = isset($_SESSION["admin_logged_in"]) && $_SESSION["admin_logged_in"] === true;

// Connect to database for stats
require_once dirname(dirname(__DIR__)) . "/config/config.php";
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SanMedic Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); min-height: 100vh; }
        .login-card { max-width: 400px; margin: 100px auto; }
        .admin-nav { background: #1e3c72; }
        .menu-card { transition: transform 0.2s; cursor: pointer; }
        .menu-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
        .menu-icon { font-size: 3rem; color: #1e3c72; }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- Login Form -->
<div class="container">
    <div class="login-card">
        <div class="card shadow-lg">
            <div class="card-header bg-primary text-white text-center py-4">
                <h4><i class="bi bi-shield-lock"></i> SanMedic Admin</h4>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">მომხმარებელი</label>
                        <input type="text" name="username" class="form-control form-control-lg" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">პაროლი</label>
                        <input type="password" name="password" class="form-control form-control-lg" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-box-arrow-in-right"></i> შესვლა
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Admin Dashboard -->
<nav class="navbar navbar-dark admin-nav py-3">
    <div class="container">
        <span class="navbar-brand"><i class="bi bi-speedometer2"></i> SanMedic Admin</span>
        <a href="?logout" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> გასვლა</a>
    </div>
</nav>

<div class="container py-5">
    <div class="row g-4">
        <!-- Backup -->
        <div class="col-md-4">
            <a href="/backups/" class="text-decoration-none">
                <div class="card menu-card h-100">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-cloud-download menu-icon"></i>
                        <h4 class="mt-3">Backup</h4>
                        <p class="text-muted">მონაცემების backup</p>
                    </div>
                </div>
            </a>
        </div>
        
        <!-- Logs -->
        <div class="col-md-4">
            <a href="logs.php" class="text-decoration-none">
                <div class="card menu-card h-100">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-journal-text menu-icon"></i>
                        <h4 class="mt-3">ლოგები</h4>
                        <p class="text-muted">სისტემის ლოგები</p>
                    </div>
                </div>
            </a>
        </div>
        
        <!-- System -->
        <div class="col-md-4">
            <a href="system.php" class="text-decoration-none">
                <div class="card menu-card h-100">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-gear menu-icon"></i>
                        <h4 class="mt-3">სისტემა</h4>
                        <p class="text-muted">სისტემის ინფორმაცია</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5><i class="bi bi-bar-chart"></i> სტატისტიკა</h5></div>
                <div class="card-body">
                    <?php
                    try {
                        $stats = [];
                        $stats["patients"] = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
                        $stats["services"] = $pdo->query("SELECT COUNT(*) FROM patient_services")->fetchColumn();
                        $stats["payments"] = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
                    } catch (Exception $e) {
                        $stats = ["patients" => 0, "services" => 0, "payments" => 0];
                    }
                    ?>
                    <div class="row text-center">
                        <div class="col-md-4">
                            <h2 class="text-primary"><?= number_format($stats["patients"]) ?></h2>
                            <p>პაციენტები</p>
                        </div>
                        <div class="col-md-4">
                            <h2 class="text-success"><?= number_format($stats["services"]) ?></h2>
                            <p>სერვისები</p>
                        </div>
                        <div class="col-md-4">
                            <h2 class="text-info"><?= number_format($stats["payments"], 2) ?> ₾</h2>
                            <p>გადახდები</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
