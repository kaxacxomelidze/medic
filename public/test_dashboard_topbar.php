<?php
// Skip auth for testing
$_SESSION = ['username' => 'TestUser', 'role' => 'admin', 'user_id' => 1];
?>
<html lang="ka">
<head>
  <meta charset="UTF-8">
  <title>Dashboard Topbar Test</title>
  <style>
.topbar {
    background: #21c1a6;
    color: white;
    padding: 12px 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 16px;
    user-select: none;
}
  </style>
  <link rel="stylesheet" href="/css/preclinic-theme.css">
</head>
<body>
<h2>Dashboard Topbar Exact Copy</h2>
<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:36px;width:auto;">
    </a>
    <div class="user-menu-wrap">
        <div class="user-btn">
            <span>TestUser</span>
        </div>
    </div>
</div>
<hr>
<p>თუ ზემოთ ლოგო ჩანს, dashboard.php-შიც უნდა ჩანდეს.</p>
<p><a href="/img/logo-White.png" target="_blank">პირდაპირი ბმული ლოგოზე</a></p>
</body>
</html>
