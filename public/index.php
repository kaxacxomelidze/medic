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
<<<<<<< HEAD
  <link rel="stylesheet" href="css/preclinic-theme.css">
=======
  <link rel="stylesheet" href="/css/preclinic-theme.css">
>>>>>>> origin/main
</head>
<body>
  <div class="login-outer">
    <img src="/img/logo-White.png?v=2" alt="Sanmedic Logo" class="auth-logo-center">
    <div class="login-container">
      <div class="login-form">
        <h2>ავტორიზაცია</h2>
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
        </form>
      </div>
    </div>
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
