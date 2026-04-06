<html>
<head>
    <title>Logo Test</title>
    <style>
        .topbar {
            background: #21c1a6;
            color: white;
            padding: 12px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
  <link rel="stylesheet" href="css/preclinic-theme.css">
</head>
<body>
    <h2>Logo Test Page</h2>
    
    <h3>1. topbar-ში ლოგო:</h3>
    <div class="topbar">
        <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
            <img src="/img/logo-White.png" alt="SanMedic" style="height:36px;width:auto;">
        </a>
        <span>მარჯვენა მხარე</span>
    </div>
    
    <h3>2. პირდაპირ img:</h3>
    <img src="/img/logo-White.png" style="height:50px; border:1px solid red;">
    
    <h3>3. სურათის URL:</h3>
    <p><a href="/img/logo-White.png" target="_blank">/img/logo-White.png</a></p>
    
    <h3>4. სურათი არსებობს?</h3>
    <?php
    $path = '/root/sanmedic/public/img/logo-White.png';
    if (file_exists($path)) {
        echo "<p style='color:green'>✅ ფაილი არსებობს: " . filesize($path) . " bytes</p>";
    } else {
    }
    ?>
</body>
</html>
