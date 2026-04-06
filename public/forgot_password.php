აქაც
<?php
session_start();
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../vendor/autoload.php';  // PHPMailer

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $error = 'ელ.ფოსტა სავალდებულოა.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($user = $stmt->fetch()) {
            $token = bin2hex(random_bytes(50));
            $expires = date('Y-m-d H:i:s', time()+3600);
            $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
                ->execute([$user['id'], $token, $expires]);
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'yourgmail@gmail.com';
                $mail->Password = 'your_app_password';
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->setFrom('no-reply@domain.com','HMS');
                $mail->addAddress($email);
                $mail->Subject = 'პაროლის აღდგენა';
                $link = "https://yourdomain.com/public/reset_password.php?token=$token";
                $mail->Body = "გამარჯობა,\n\nპაროლის განახლებისთვის დააწკაპუნე ბმულზე:\n$link\n\nბმული ვრცელდება 1 საათით.";
                $mail->send();
                $success = 'ბმული გაგზავნილია.';
            } catch (Exception $e) {
                $error = 'ელ.ფოსტის გაგზავნის შეცდომა.';
            }
        } else {
            $error = 'ელ.ფოსტა არ მოიძებნა.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>პაროლის აღდგენა</title>
    
    <!-- Google Fonts - Noto Sans Georgian -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body, input, button, a {
            font-family: "Noto Sans Georgian", sans-serif !important;
        }
    </style>
  <link rel="stylesheet" href="css/preclinic-theme.css">
</head>
<body>
    <div class="login-container">
        <form class="login-form" method="post">
            <h2>პაროლის აღდგენა</h2>
            <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
            <?php if ($success): ?><div class="success"><?=htmlspecialchars($success)?></div><?php endif; ?>
            <input name="email" type="email" placeholder="ელ.ფოსტა" required>
            <button type="submit">გაგზავნა</button>
            <a href="index.php" class="back-link">უკან შესვლაზე დაბრუნება</a>
        </form>
    </div>
</body>
</html>