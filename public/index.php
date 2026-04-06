<?php
session_start();
require __DIR__ . "/../config/config.php";

// reCAPTCHA keys
define("RECAPTCHA_SITE_KEY", "6LfXMFMsAAAAALm-uUHQnWnEUAJ6-AJ_gpN2XPxS");
define("RECAPTCHA_SECRET_KEY", "6LfXMFMsAAAAAACwUKVXawRkbCH5rRUL0E5RZ8Ko");

// თუ უკვე ავტორიზებულია, გადაამისამართე dashboard-ზე
if (isset($_SESSION["user_id"])) {
            header("Location: dashboard.php");
    exit;
}

// BRUTE-FORCE დაცვის პარამეტრები
$max_attempts = 5;
$lockout_time = 15 * 60;
$ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";

if (!isset($_SESSION["login_attempts"])) $_SESSION["login_attempts"] = 0;
if (!isset($_SESSION["lockout_until"])) $_SESSION["lockout_until"] = 0;

$error = "";

// თუ ლოკაუტია
if ($_SESSION["login_attempts"] >= $max_attempts) {
    if (time() < $_SESSION["lockout_until"]) {
        $minutes = ceil(($_SESSION["lockout_until"] - time()) / 60);
        $error = "ბევრი წარუმატებელი მცდელობა. სცადეთ {$minutes} წუთში.";
    } else {
        $_SESSION["login_attempts"] = 0;
        $_SESSION["lockout_until"] = 0;
    }
}

// reCAPTCHA verification
function verifyRecaptcha($token) {
    $url = "https://www.google.com/recaptcha/api/siteverify";
    $data = [
        "secret" => RECAPTCHA_SECRET_KEY,
        "response" => $token,
        "remoteip" => $_SERVER["REMOTE_ADDR"] ?? ""
    ];
    
    $options = [
        "http" => [
            "header" => "Content-type: application/x-www-form-urlencoded\r\n",
            "method" => "POST",
            "content" => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === false) return false;
    
    $response = json_decode($result, true);
    return isset($response["success"]) && $response["success"] === true;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($error)) {
    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";
    $recaptchaToken = $_POST["g-recaptcha-response"] ?? "";

    if ($login === "" || $password === "") {
        $error = "ლოგინი და პაროლი სავალდებულოა.";
    } elseif (empty($recaptchaToken)) {
        $error = "გთხოვთ დაადასტუროთ რომ არ ხართ რობოტი.";
    } elseif (!verifyRecaptcha($recaptchaToken)) {
        $error = "reCAPTCHA ვერიფიკაცია ვერ მოხერხდა. სცადეთ თავიდან.";
    } else {
        $stmt = $pdo->prepare("SELECT id, password_hash, role FROM users WHERE username = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user["password_hash"])) {
            $_SESSION["user_id"]  = $user["id"];
            $_SESSION["username"] = $login;
            $_SESSION["role"]     = $user["role"];
            $_SESSION["login_attempts"] = 0;
            $_SESSION["lockout_until"] = 0;
            slog_action("login_success", ["user" => $login, "user_id" => $user["id"]]);
            header("Location: dashboard.php");
            exit;
        }
        $_SESSION["login_attempts"]++;
        slog_error("login_failed", ["username" => $login]);
        if ($_SESSION["login_attempts"] >= $max_attempts) {
            $_SESSION["lockout_until"] = time() + $lockout_time;
            $error = "ბევრი წარუმატებელი მცდელობა. სცადეთ 15 წუთში.";
        } else {
            $error = "გთხოვთ, შეიყვანეთ სწორი ლოგინი ან პაროლი.";
        }
    }
}
$isLocked = ($_SESSION["login_attempts"] >= $max_attempts && time() < $_SESSION["lockout_until"]);

$landingStats = ["patients" => 0, "services" => 0, "payments" => 0.0];
try {
    $landingStats["patients"] = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $landingStats["services"] = (int)$pdo->query("SELECT COUNT(*) FROM patient_services")->fetchColumn();
    $landingStats["payments"] = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
} catch (Throwable $e) {
    // keep zero defaults for first-run / missing tables
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sanmedic ავტორიზაცია</title>
  <link rel="icon" href="img/favicon.svg" type="image/svg+xml">
  <link rel="preload" href="fonts/HelveticaNeueLTGEO-55Roman.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="fonts/HelveticaNeueLTGEO-75Bold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="css/styles.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <link rel="stylesheet" href="css/preclinic-theme.css">
  <style>
    .home-shell{max-width:1400px;margin:26px auto;padding:0 16px;display:grid;grid-template-columns:1.25fr .95fr;gap:22px}
    .home-hero{background:#fff;border:1px solid #e4e9f7;border-radius:20px;box-shadow:0 12px 30px rgba(17,24,39,.08);overflow:hidden}
    .hero-head{padding:22px 24px;background:linear-gradient(120deg,#2e37a4,#5b66d6);color:#fff;display:flex;justify-content:space-between;gap:16px;align-items:center}
    .hero-title{font-size:28px;font-weight:800;line-height:1.2;margin:0}
    .hero-sub{margin:6px 0 0;opacity:.9}
    .hero-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:18px}
    .metric{background:#f9fbff;border:1px solid #e5ebff;border-radius:14px;padding:16px}
    .metric .label{font-size:13px;color:#667085}
    .metric .value{font-size:28px;font-weight:800;color:#2e37a4;margin-top:4px}
    .quick-links{padding:0 18px 18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .q-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid #e5ebff;background:#fff;border-radius:12px;text-decoration:none;color:#1f2937;font-weight:600}
    .q-item:hover{background:#f5f8ff}
    .q-dot{width:10px;height:10px;border-radius:50%;background:#00d0f1;box-shadow:0 0 0 4px rgba(0,208,241,.15)}
    .login-card{background:#fff;border:1px solid #e4e9f7;border-radius:20px;box-shadow:0 12px 30px rgba(17,24,39,.08);padding:22px}
    .brand-row{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .brand-row img{height:48px;width:auto;background:#2e37a4;padding:8px 10px;border-radius:10px}
    .brand-title{font-size:20px;font-weight:800;color:#2e37a4}
    .login-form h2{margin:0 0 14px}
    .login-form .error{margin-bottom:10px}
    .foot-note{padding-top:14px;font-size:12px;color:#667085}
    @media (max-width:980px){
      .home-shell{grid-template-columns:1fr}
      .hero-grid{grid-template-columns:1fr}
      .quick-links{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="home-shell">
    <section class="home-hero">
      <div class="hero-head">
        <div>
          <h1 class="hero-title">SanMedic • კლინიკის მართვის პლატფორმა</h1>
          <p class="hero-sub">ყველა ძირითადი მოდული ერთ სივრცეში — რეგისტრაცია, პაციენტები, ანგარიშები და ადმინისტრირება.</p>
        </div>
        <div class="pill">Preclinic Style</div>
      </div>
      <div class="hero-grid">
        <div class="metric">
          <div class="label">რეგისტრირებული პაციენტები</div>
          <div class="value"><?= number_format($landingStats["patients"]) ?></div>
        </div>
        <div class="metric">
          <div class="label">მომსახურების ჩანაწერები</div>
          <div class="value"><?= number_format($landingStats["services"]) ?></div>
        </div>
        <div class="metric">
          <div class="label">სულ გადახდები</div>
          <div class="value"><?= number_format($landingStats["payments"], 2) ?> ₾</div>
        </div>
      </div>
      <div class="quick-links">
        <a class="q-item" href="dashboard.php"><span class="q-dot"></span>რეგისტრაცია</a>
        <a class="q-item" href="patient_hstory.php"><span class="q-dot"></span>პაციენტების ისტორია</a>
        <a class="q-item" href="nomenklatura.php"><span class="q-dot"></span>ნომენკლატურა</a>
        <a class="q-item" href="angarishebi.php"><span class="q-dot"></span>ანგარიშები</a>
      </div>
    </section>

    <aside class="login-card">
      <div class="brand-row">
        <img src="img/logo-White.png?v=2" alt="Sanmedic Logo">
        <div>
          <div class="brand-title">SanMedic</div>
          <div class="muted">უსაფრთხო ავტორიზაცია</div>
        </div>
      </div>
      <div class="login-form">
        <h2>შესვლა სისტემაში</h2>
        <form method="post" action="">
          <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>
          <input type="text" name="login" placeholder="ლოგინი" required>
          <div class="password-wrapper">
            <input type="password" name="password" id="password" placeholder="პაროლი" required>
            <img src="img/visibility.svg" alt="ნახვა" id="togglePassword" class="toggle-password">
          </div>
          <div class="g-recaptcha" data-sitekey="<?= RECAPTCHA_SITE_KEY ?>" style="margin: 15px 0;"></div>
          <button type="submit"<?= $isLocked ? " disabled style=\"background:#888;cursor:not-allowed;\"" : "" ?>>შესვლა</button>
          <div class="foot-note">თუ ვერ შედიხართ, მიმართეთ ადმინისტრატორს.</div>
        </form>
      </div>
    </aside>
  </div>
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const passwordInput = document.getElementById("password");
      const togglePassword = document.getElementById("togglePassword");
      if (passwordInput && togglePassword) {
        togglePassword.addEventListener("click", function () {
          const isPasswordHidden = passwordInput.getAttribute("type") === "password";
          passwordInput.setAttribute("type", isPasswordHidden ? "text" : "password");
        });
      }
    });
  </script>
</body>
</html>
