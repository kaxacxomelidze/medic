<?php
session_start();
require __DIR__ . '/../config/config.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? ''; if (!$token) die('არავალიდური ბმული');
$stmt = $pdo->prepare('SELECT user_id, expires_at, used FROM password_resets WHERE token=?');
$stmt->execute([$token]);
$reset = $stmt->fetch();
if (!$reset || $reset['used'] || strtotime($reset['expires_at'])<time()) die('ბმული ვადა გასულია');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $pass = $_POST['password'] ?? '';
    if (strlen($pass)<6) $error='პაროლი მინიმუმ 6 სიმბოლოა.';
    else {
        $hash=password_hash($pass,PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash,$reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used=1 WHERE token=?')->execute([$token]);
        $success='პაროლი განახლდა.';
    }
}
?>
<!DOCTYPE html>
<html lang="ka">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>პაროლის განახლება</title><link rel="stylesheet" href="css/styles.css"></head>
<body>
    <div class="login-container">
        <form class="login-form" method="post">
            <h2>პაროლის განახლება</h2>
            <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
            <?php if ($success): ?><div class="success"><?=htmlspecialchars($success)?></div><?php endif; ?>
            <input name="password" type="password" placeholder="ახალი პაროლი" required>
            <button type="submit">განახლება</button>
            <a href="index.php" class="back-link">უკან ავტორიზაციაზე</a>
        </form>
    </div>
</body>
</html>