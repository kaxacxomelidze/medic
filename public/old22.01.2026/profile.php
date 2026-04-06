<?php
// profile.php — User/Admin profile page (single file, AJAX endpoints + UI)

// ===========================
// 1) Session & Auth
// ===========================
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

if (empty($_SESSION['user_id'])) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'err', 'msg' => 'unauthorized']);
        exit;
    }
    header('Location: index.php');
    exit;
}

// ===========================
// 2) Bootstrap
// ===========================
require __DIR__ . '/../config/config.php'; // must define $pdo (PDO::ERRMODE_EXCEPTION)
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// Security headers (page responses)
if (!$isAjax) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: interest-cohort=()');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jres($arr, $code=200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function get_csrf() { return $_SESSION['csrf_token'] ?? ''; }
function check_csrf() {
    $tok = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals($_SESSION['csrf_token'] ?? '', $tok);
}

// Helpers: DB small utils
function pdoRow(PDO $pdo, string $sql, array $p=[]) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function pdoAll(PDO $pdo, string $sql, array $p=[]) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function pdoExec(PDO $pdo, string $sql, array $p=[]) {
    $st = $pdo->prepare($sql);
    return $st->execute($p);
}

// Support verifying legacy $2b$ bcrypt hashes (DB dump includes one)
function verify_password_legacy($password, $hashFromDb) {
    $hash = (string)$hashFromDb;
    if (strpos($hash, '$2b$') === 0) {
        // convert to $2y$ for PHP's password_verify compatibility
        $hash = '$2y$' . substr($hash, 4);
    }
    return password_verify($password, $hash);
}

// Current user
$uid = (int)$_SESSION['user_id'];
$user = pdoRow($pdo, "SELECT id, username, password_hash, role, created_at FROM users WHERE id=?", [$uid]);
if (!$user) {
    // Session stale
    if ($isAjax) jres(['status'=>'err','msg'=>'user_not_found'], 404);
    header('Location: index.php');
    exit;
}
$isAdmin = ($user['role'] === 'admin');

// ===========================
// 3) AJAX Endpoints
// ===========================
$action = $_GET['action'] ?? '';
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        jres(['status'=>'err','msg'=>'bad_csrf'], 400);
    }

    try {
        if ($action === 'change_password') {
            $old = trim($_POST['old_password'] ?? '');
            $new = trim($_POST['new_password'] ?? '');
            $rep = trim($_POST['new_password2'] ?? '');

            if ($new === '' || $rep === '' || $old === '') jres(['status'=>'err','msg'=>'სრული ველები შეავსეთ'], 422);
            if ($new !== $rep) jres(['status'=>'err','msg'=>'ახალი პაროლი და გამეორება არ ემთხვევა'], 422);
            if (mb_strlen($new, 'UTF-8') < 8) jres(['status'=>'err','msg'=>'პაროლი უნდა იყოს მინ. 8 სიმბოლო'], 422);
            if (hash_equals($old, $new)) jres(['status'=>'err','msg'=>'ახალი პაროლი არ შეიძლება ემთხვეოდეს ძველს'], 422);

            // verify old
            $ok = verify_password_legacy($old, $user['password_hash']);
            if (!$ok) jres(['status'=>'err','msg'=>'ძველი პაროლი არასწორია'], 403);

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            pdoExec($pdo, "UPDATE users SET password_hash=? WHERE id=?", [$newHash, $uid]);

            jres(['status'=>'ok','msg'=>'პაროლი წარმატებით შეიცვალა']);
        }

        if ($action === 'save_company') {
            if (!$isAdmin) jres(['status'=>'err','msg'=>'admin_only'], 403);

            $fields = [
                'name','tax_id','address','phone1','phone2',
                'bank_name','bank_code','bank_iban','director_name','accountant_name'
            ];
            $vals = [];
            foreach ($fields as $f) { $vals[$f] = trim($_POST[$f] ?? ''); }

            // Upsert company_profile with id=1
            $exists = pdoRow($pdo, "SELECT id FROM company_profile WHERE id=1");
            if ($exists) {
                pdoExec($pdo, "UPDATE company_profile
                                SET name=:name, tax_id=:tax_id, address=:address, phone1=:phone1, phone2=:phone2,
                                    bank_name=:bank_name, bank_code=:bank_code, bank_iban=:bank_iban,
                                    director_name=:director_name, accountant_name=:accountant_name
                                WHERE id=1", $vals);
            } else {
                pdoExec($pdo, "INSERT INTO company_profile
                                (id,name,tax_id,address,phone1,phone2,bank_name,bank_code,bank_iban,director_name,accountant_name)
                                VALUES (1,:name,:tax_id,:address,:phone1,:phone2,:bank_name,:bank_code,:bank_iban,:director_name,:accountant_name)", $vals);
            }
            jres(['status'=>'ok','msg'=>'კომპანიის პროფილი შენახულია']);
        }

        if ($action === 'save_doctor') {
            $first = trim($_POST['first_name'] ?? '');
            $last  = trim($_POST['last_name'] ?? '');
            $mobile= trim($_POST['mobile'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $birth = trim($_POST['birthdate'] ?? '');

            $doc = pdoRow($pdo, "SELECT id FROM doctors WHERE user_id=?", [$uid]);
            if ($doc) {
                pdoExec($pdo, "UPDATE doctors SET first_name=?, last_name=?, mobile=?, email=?, birthdate=?
                               WHERE user_id=?", [$first, $last, $mobile, $email, ($birth ?: '0000-00-00'), $uid]);
            } else {
                // Insert new doctor row with minimal defaults
                pdoExec($pdo, "INSERT INTO doctors (user_id, personal_id, first_name, last_name, father_name, birthdate, gender, status, created_at, updated_at)
                               VALUES (?, '', ?, ?, '', ?, 'მამრობითი', 'აქტიური', NOW(), NOW())",
                               [$uid, $first, $last, ($birth ?: '0000-00-00')]);
            }
            jres(['status'=>'ok','msg'=>'ექიმის პროფილი შენახულია']);
        }

        if ($action === 'link_doctor') {
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            if ($doctor_id <= 0) jres(['status'=>'err','msg'=>'არასწორი იდენტიფიკატორი'], 422);

            // Ensure doctor exists
            $doc = pdoRow($pdo, "SELECT id, user_id FROM doctors WHERE id=?", [$doctor_id]);
            if (!$doc) jres(['status'=>'err','msg'=>'ექიმი ვერ მოიძებნა'], 404);
            if (!empty($doc['user_id']) && (int)$doc['user_id'] !== $uid) {
                jres(['status'=>'err','msg'=>'ეს ექიმი უკვე მიბმულია სხვა მომხმარებელზე'], 409);
            }
            // Unlink other doctor rows linked to this user
            pdoExec($pdo, "UPDATE doctors SET user_id=NULL WHERE user_id=?", [$uid]);
            // Link selected
            pdoExec($pdo, "UPDATE doctors SET user_id=? WHERE id=?", [$uid, $doctor_id]);

            jres(['status'=>'ok','msg'=>'ექიმი წარმატებით მიებმათ']);
        }

        if ($action === 'toggle_permission') {
            if (!$isAdmin) jres(['status'=>'err','msg'=>'admin_only'], 403);
            $perm = trim($_POST['permission'] ?? '');
            if ($perm === '') jres(['status'=>'err','msg'=>'არცერთი უფლებაა არჩეული'], 422);

            $has = pdoRow($pdo, "SELECT 1 FROM user_permissions WHERE user_id=? AND permission=?", [$uid, $perm]);
            if ($has) {
                pdoExec($pdo, "DELETE FROM user_permissions WHERE user_id=? AND permission=?", [$uid, $perm]);
                jres(['status'=>'ok','msg'=>'უფლება მოხსნა','removed'=>true,'permission'=>$perm]);
            } else {
                pdoExec($pdo, "INSERT INTO user_permissions (user_id, permission) VALUES (?,?)", [$uid, $perm]);
                jres(['status'=>'ok','msg'=>'უფლება დაემატა','added'=>true,'permission'=>$perm]);
            }
        }

        // Unknown action
        jres(['status'=>'err','msg'=>'unknown_action'], 400);

    } catch (Throwable $e) {
        jres(['status'=>'err','msg'=>'server_error','detail'=>$e->getMessage()], 500);
    }
}

// ===========================
// 4) Page Data (GET render)
// ===========================

// Doctor row (if linked)
$doctor = pdoRow($pdo, "SELECT id, personal_id, first_name, last_name, father_name, birthdate, gender, address, mobile, email, status
                        FROM doctors WHERE user_id=?", [$uid]);

// Permissions for this user
$perms = pdoAll($pdo, "SELECT permission FROM user_permissions WHERE user_id=? ORDER BY permission", [$uid]);
$permSet = array_column($perms, 'permission');

// Company profile (admin sees + can edit)
$company = pdoRow($pdo, "SELECT * FROM company_profile WHERE id=1");

// Last login attempts
$attempts = pdoAll($pdo, "SELECT attempt_time, ip_address, successful FROM login_attempts WHERE user_id=? ORDER BY attempt_time DESC LIMIT 10", [$uid]);

// Opened patients (last 5) with names
$opened = pdoAll($pdo, "SELECT up.patient_id, up.created_at, p.first_name, p.last_name, p.personal_id
                        FROM user_opened_patients up
                        JOIN patients p ON p.id = up.patient_id
                        WHERE up.user_id=?
                        ORDER BY up.created_at DESC
                        LIMIT 5", [$uid]);

// Quick stats
$invMine = pdoRow($pdo, "SELECT COUNT(*) AS c FROM invoices WHERE created_by=?", [$uid]);
$patientsTotal = pdoRow($pdo, "SELECT COUNT(*) AS c FROM patients");
$paymentsSum = pdoRow($pdo, "SELECT COALESCE(SUM(amount),0) AS s FROM payments");

// v_method_balance view (if available)
$methodBalance = [];
try {
    $methodBalance = pdoAll($pdo, "SELECT method, payments_sum, expenses_sum, balance FROM v_method_balance ORDER BY method");
} catch (Throwable $e) {
    // view not present — ignore
}

// Available permissions (union known + present)
$known = [
    'access_angarishebi','access_dashboard','access_forward','access_hr','access_journal','access_nomenklatura','add_patient'
];
$allPerms = array_values(array_unique(array_merge($known, $permSet)));
sort($allPerms);

// CSRF token for forms
$CSRF = get_csrf();

// ===========================
// 5) Render HTML
// ===========================
?>
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>პროფილი • <?=h($user['username'])?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#f7f8fc; --card:#ffffff; --ink:#121417; --muted:#5e6a7a; --pri:#2563eb;
    --ok:#16a34a; --err:#dc2626; --bd:#e5e7eb; --nav:#0f172a; --nav-ink:#cbd5e1;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 "Noto Sans Georgian",sans-serif}
  a{color:inherit;text-decoration:none}
  .container{max-width:1100px;margin:0 auto;padding:0 16px}

  /* Top navigation tabs (your requested markup) */
  .topbar{background:var(--nav); color:#fff}
  .topbar .tabs{display:flex; gap:12px; list-style:none; margin:0; padding:10px 0}
  .topbar .tabs li a{
    display:block; padding:8px 12px; border-radius:999px; color:var(--nav-ink);
    border:1px solid rgba(255,255,255,.12);
  }
  .topbar .tabs li a:hover{color:#fff; border-color:#fff}
  .topbar .tabs li a.active{background:#1e293b; color:#fff; border-color:#1e293b}

  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  .header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
  .title{font-size:22px;font-weight:700}
  .badge{display:inline-block;padding:2px 8px;border:1px solid var(--bd);border-radius:999px;color:var(--muted);font-size:12px}
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0}
  .card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:14px}
  .card h3{margin:0 0 8px 0;font-size:16px}
  .muted{color:var(--muted)}
  .row{display:grid;grid-template-columns:1fr 2fr;gap:8px;margin:8px 0;align-items:center}
  input[type=text],input[type=date],input[type=password],textarea{width:100%;padding:8px 10px;border:1px solid var(--bd);border-radius:8px;background:#fff}
  textarea{min-height:80px;resize:vertical}
  .btn{display:inline-flex;gap:8px;align-items:center;background:var(--pri);color:#fff;border:none;padding:8px 14px;border-radius:8px;cursor:pointer}
  .btn:disabled{opacity:.6;cursor:not-allowed}
  .btn.outline{background:#fff;color:var(--pri);border:1px solid var(--pri)}
  .btn.success{background:var(--ok)}
  .btn.danger{background:var(--err)}

  /* Namespaced internal page tabs to avoid clash with UL.tabs */
  .p-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
  .p-tab{padding:8px 12px;border:1px solid var(--bd);border-radius:999px;background:#fff;cursor:pointer}
  .p-tab.active{background:var(--pri);color:#fff;border-color:var(--pri)}
  .panel{display:none}
  .panel.active{display:block}

  .table{width:100%;border-collapse:collapse}
  .table th,.table td{border-bottom:1px solid var(--bd);padding:8px;text-align:left}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px}
  .notice{margin-top:10px;font-size:13px}
  .notice.ok{color:var(--ok)}
  .notice.err{color:var(--err)}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

  @media (max-width:900px){
    .cards{grid-template-columns:1fr;}
    .row{grid-template-columns:1fr;}
    .grid2{grid-template-columns:1fr;}
  }
</style>
</head>
<body>

<!-- ======= TOP NAV (requested) ======= -->
<div class="topbar">
  <div class="container">
    <ul class="tabs">
      <li><a href="dashboard.php">რეგისტრაცია</a></li>
      <li><a href="patient_hstory.php">პაციენტის ისტორია</a></li>
      <li><a href="nomenklatura.php">ნომენკლატურა</a></li>
      <li><a href="angarishebi.php">ანგარიშები</a></li>
    </ul>
  </div>
</div>

<div class="wrap">
  <div class="header">
    <div>
      <div class="title">პროფილი — <?=h($user['username'])?></div>
      <div class="muted">როლი: <span class="badge"><?=h($user['role'])?></span> • შექმნილია: <?=h($user['created_at'])?></div>
    </div>
    <div>
      <span class="badge">User ID: <?=$user['id']?></span>
      <?php if ($doctor): ?>
        <span class="badge">Doctor ID: <?=$doctor['id']?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="cards">
    <div class="card">
      <h3>ჩემი ინვოისები</h3>
      <div class="muted">თქვენ მიერ შექმნილი ინვოისების რაოდენობა</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px"><?= (int)($invMine['c'] ?? 0) ?></div>
    </div>
    <div class="card">
      <h3>პაციენტები</h3>
      <div class="muted">სისტემაში რეგისტრირებული</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px"><?= (int)($patientsTotal['c'] ?? 0) ?></div>
    </div>
    <div class="card">
      <h3>გადახდები (სრული)</h3>
      <div class="muted">payments.amount ჯამი</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px"><?= number_format((float)($paymentsSum['s'] ?? 0), 2) ?></div>
    </div>
  </div>

  <div class="p-tabs" id="tabs">
    <button class="p-tab active" data-tab="account">აკაუნტი</button>
    <button class="p-tab" data-tab="doctor">ექიმის პროფილი</button>
    <button class="p-tab" data-tab="security">უსაფრთხოება</button>
    <button class="p-tab" data-tab="permissions">უფლებები</button>
    <?php if ($isAdmin): ?><button class="p-tab" data-tab="company">კომპანია</button><?php endif; ?>
    <button class="p-tab" data-tab="activity">აქტივობა & სტატისტიკა</button>
  </div>

  <!-- Account panel (read-only basics now) -->
  <div class="panel active" id="panel-account">
    <div class="card">
      <h3>მომხმარებლის ინფორმაცია</h3>
      <div class="row"><div>მომხმარებელი</div><div><?=h($user['username'])?></div></div>
      <div class="row"><div>როლი</div><div><span class="pill"><?=h($user['role'])?></span></div></div>
      <div class="row"><div>შექმნის თარიღი</div><div><?=h($user['created_at'])?></div></div>
      <div class="notice">ექიმის დამატებითი მონაცემები შეგიძლიათ შეავსოთ „ექიმის პროფილში“.</div>
    </div>
  </div>

  <!-- Doctor profile -->
  <div class="panel" id="panel-doctor">
    <div class="card">
      <h3>ექიმის პროფილი (მიმაგრება/რედაქტირება)</h3>
      <?php if (!$doctor): ?>
        <div class="notice">ამ მომხმარებელს ექიმის ბარათი ჯერ არ აქვს მიბმული.</div>
        <form id="linkDoctorForm" class="grid2" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?=h($CSRF)?>">
          <div class="row"><div>მიმაგრება არსებულზე (doctor_id)</div><div><input type="text" name="doctor_id" placeholder=" напр. 1"></div></div>
          <div class="row"><div></div><div><button class="btn outline" type="submit">მიმაგრება</button></div></div>
        </form>
        <div class="muted" style="margin-top:8px">ან შეავსეთ ქვედა ფორმა ახალის შესაქმნელად.</div>
      <?php endif; ?>

      <form id="doctorForm" class="grid2" autocomplete="off" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?=h($CSRF)?>">
        <div class="row"><div>სახელი</div><div><input type="text" name="first_name" value="<?=h($doctor['first_name'] ?? '')?>"></div></div>
        <div class="row"><div>გვარი</div><div><input type="text" name="last_name" value="<?=h($doctor['last_name'] ?? '')?>"></div></div>
        <div class="row"><div>დაბ. თარ.</div><div><input type="date" name="birthdate" value="<?=h(($doctor['birthdate'] ?? '') === '0000-00-00' ? '' : ($doctor['birthdate'] ?? ''))?>"></div></div>
        <div class="row"><div>მობილური</div><div><input type="text" name="mobile" value="<?=h($doctor['mobile'] ?? '')?>"></div></div>
        <div class="row"><div>ელ.ფოსტა</div><div><input type="text" name="email" value="<?=h($doctor['email'] ?? '')?>"></div></div>
        <div class="row"><div></div><div><button class="btn" type="submit">შენახვა</button></div></div>
      </form>
      <div id="doctorMsg" class="notice"></div>
    </div>
  </div>

  <!-- Security -->
  <div class="panel" id="panel-security">
    <div class="card">
      <h3>პაროლის შეცვლა</h3>
      <form id="pwdForm" class="grid2" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h($CSRF)?>">
        <div class="row"><div>ძველი პაროლი</div><div><input type="password" name="old_password" autocomplete="current-password"></div></div>
        <div class="row"><div>ახალი პაროლი</div><div><input type="password" name="new_password" autocomplete="new-password"></div></div>
        <div class="row"><div>განმეორება</div><div><input type="password" name="new_password2" autocomplete="new-password"></div></div>
        <div class="row"><div></div><div><button class="btn danger" type="submit">პაროლის შეცვლა</button></div></div>
      </form>
      <div id="pwdMsg" class="notice"></div>
    </div>
  </div>

  <!-- Permissions -->
  <div class="panel" id="panel-permissions">
    <div class="card">
      <h3>უფლებები</h3>
      <div class="muted">ჩართული უფლებები აღნიშნულია. <?= $isAdmin ? 'როგორც ადმინი, შეგიძლიათ ჩართვა/გამორთვა.' : 'მხოლოდ ნახვა.'?></div>
      <div style="margin-top:8px">
        <?php foreach ($allPerms as $p):
          $has = in_array($p, $permSet, true);
        ?>
          <label style="display:flex;align-items:center;gap:8px;margin:6px 0">
            <input type="checkbox" class="permCheck" data-perm="<?=h($p)?>" <?= $has ? 'checked' : '' ?> <?= $isAdmin ? '' : 'disabled' ?>>
            <span><?=h($p)?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div id="permMsg" class="notice"></div>
    </div>
  </div>

  <!-- Company (admin only) -->
  <?php if ($isAdmin): ?>
  <div class="panel" id="panel-company">
    <div class="card">
      <h3>კომპანიის პროფილი (admin)</h3>
      <form id="compForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=h($CSRF)?>">
        <div class="grid2">
          <div class="row"><div>დასახელება</div><div><input type="text" name="name" value="<?=h($company['name'] ?? '')?>"></div></div>
          <div class="row"><div>საბ. კოდი</div><div><input type="text" name="tax_id" value="<?=h($company['tax_id'] ?? '')?>"></div></div>
          <div class="row"><div>მისამართი</div><div><input type="text" name="address" value="<?=h($company['address'] ?? '')?>"></div></div>
          <div class="row"><div>ტელ.1</div><div><input type="text" name="phone1" value="<?=h($company['phone1'] ?? '')?>"></div></div>
          <div class="row"><div>ტელ.2</div><div><input type="text" name="phone2" value="<?=h($company['phone2'] ?? '')?>"></div></div>
          <div class="row"><div>ბანკი</div><div><input type="text" name="bank_name" value="<?=h($company['bank_name'] ?? '')?>"></div></div>
          <div class="row"><div>ბანკ. კოდი</div><div><input type="text" name="bank_code" value="<?=h($company['bank_code'] ?? '')?>"></div></div>
          <div class="row"><div>IBAN</div><div><input type="text" name="bank_iban" value="<?=h($company['bank_iban'] ?? '')?>"></div></div>
          <div class="row"><div>დირექტორი</div><div><input type="text" name="director_name" value="<?=h($company['director_name'] ?? '')?>"></div></div>
          <div class="row"><div>ბუღალტერი</div><div><input type="text" name="accountant_name" value="<?=h($company['accountant_name'] ?? '')?>"></div></div>
        </div>
        <div class="row"><div></div><div><button class="btn success" type="submit">შენახვა</button></div></div>
      </form>
      <div class="muted" style="margin-top:6px">ბოლო განახლება: <?=h($company['updated_at'] ?? '—')?></div>
      <div id="compMsg" class="notice"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Activity & Stats -->
  <div class="panel" id="panel-activity">
    <div class="card">
      <h3>ბოლო შესვლები</h3>
      <table class="table">
        <thead><tr><th>დრო</th><th>IP</th><th>სტატუსი</th></tr></thead>
        <tbody>
          <?php if ($attempts): foreach ($attempts as $a): ?>
            <tr>
              <td><?=h($a['attempt_time'])?></td>
              <td><?=h($a['ip_address'])?></td>
              <td><?=((int)$a['successful']===1?'<span class="pill">წარმატებული</span>':'<span class="pill" style="background:#fee2e2;color:#991b1b">წარუმატებელი</span>')?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3" class="muted">ჩანაწერი არაა</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card" style="margin-top:12px">
      <h3>ბალანსი მეთოდებით (v_method_balance)</h3>
      <table class="table">
        <thead><tr><th>მეთოდი</th><th>შემოსავალი</th><th>ხარჯი</th><th>ბალანსი</th></tr></thead>
        <tbody>
          <?php if ($methodBalance): foreach ($methodBalance as $mb): ?>
            <tr>
              <td><?=h($mb['method'])?></td>
              <td><?=number_format((float)$mb['payments_sum'],2)?></td>
              <td><?=number_format((float)$mb['expenses_sum'],2)?></td>
              <td><?=number_format((float)$mb['balance'],2)?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4" class="muted">ცხრილი/ხედვა დროებით მიუწვდომელია</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card" style="margin-top:12px">
      <h3>ბოლო გახსნილი პაციენტები</h3>
      <table class="table">
        <thead><tr><th>დრო</th><th>პაციენტი</th><th>პირადი №</th></tr></thead>
        <tbody>
          <?php if ($opened): foreach ($opened as $o): ?>
            <tr>
              <td><?=h($o['created_at'])?></td>
              <td><?=h($o['first_name'].' '.$o['last_name'])?></td>
              <td><?=h($o['personal_id'])?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3" class="muted">ჩანაწერი არაა</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Tabs (namespaced to avoid collisions with UL.tabs)
  const tabsWrap = document.getElementById('tabs');
  const switchTab = (name) => {
    document.querySelectorAll('.p-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.id === 'panel-'+name));
  };
  if (tabsWrap) {
    tabsWrap.addEventListener('click', (e) => {
      const b = e.target.closest('.p-tab');
      if (b && b.dataset.tab) switchTab(b.dataset.tab);
    });
  }

  // Fetch helper
  const postForm = async (url, formEl, disableBtn=true) => {
    const btn = formEl.querySelector('button[type="submit"]');
    if (disableBtn && btn) btn.disabled = true;
    try {
      const fd = new FormData(formEl);
      const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With':'XMLHttpRequest' }, body: fd });
      const ct = res.headers.get('content-type') || '';
      const j = ct.includes('application/json') ? await res.json() : {};
      if (!res.ok) throw new Error((j && j.msg) ? j.msg : 'Server error');
      return j;
    } finally {
      if (disableBtn && btn) btn.disabled = false;
    }
  };

  // Doctor link + save
  const linkDoctorForm = document.getElementById('linkDoctorForm');
  const doctorForm = document.getElementById('doctorForm');
  const doctorMsg = document.getElementById('doctorMsg');

  if (linkDoctorForm) {
    linkDoctorForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (doctorMsg){ doctorMsg.textContent = ''; doctorMsg.className='notice'; }
      try {
        const j = await postForm('?action=link_doctor', linkDoctorForm);
        if (doctorMsg){ doctorMsg.textContent = j.msg || 'OK'; doctorMsg.className = 'notice ok'; }
        setTimeout(()=> location.reload(), 700);
      } catch (err) {
        if (doctorMsg){ doctorMsg.textContent = err.message; doctorMsg.className = 'notice err'; }
      }
    });
  }
  if (doctorForm) {
    doctorForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (doctorMsg){ doctorMsg.textContent = ''; doctorMsg.className='notice'; }
      try {
        const j = await postForm('?action=save_doctor', doctorForm);
        if (doctorMsg){ doctorMsg.textContent = j.msg || 'OK'; doctorMsg.className = 'notice ok'; }
      } catch (err) {
        if (doctorMsg){ doctorMsg.textContent = err.message; doctorMsg.className = 'notice err'; }
      }
    });
  }

  // Password change
  const pwdForm = document.getElementById('pwdForm');
  const pwdMsg = document.getElementById('pwdMsg');
  if (pwdForm) {
    pwdForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (pwdMsg){ pwdMsg.textContent = ''; pwdMsg.className='notice'; }
      try {
        const j = await postForm('?action=change_password', pwdForm);
        if (pwdMsg){ pwdMsg.textContent = j.msg || 'OK'; pwdMsg.className = 'notice ok'; }
        pwdForm.reset();
      } catch (err) {
        if (pwdMsg){ pwdMsg.textContent = err.message; pwdMsg.className = 'notice err'; }
      }
    });
  }

  // Company save (admin)
  const compForm = document.getElementById('compForm');
  const compMsg = document.getElementById('compMsg');
  if (compForm) {
    compForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (compMsg){ compMsg.textContent = ''; compMsg.className='notice'; }
      try {
        const j = await postForm('?action=save_company', compForm);
        if (compMsg){ compMsg.textContent = j.msg || 'OK'; compMsg.className = 'notice ok'; }
      } catch (err) {
        if (compMsg){ compMsg.textContent = err.message; compMsg.className = 'notice err'; }
      }
    });
  }

  // Toggle permissions (admin)
  const permMsg = document.getElementById('permMsg');
  document.querySelectorAll('.permCheck').forEach(chk => {
    chk.addEventListener('change', async (e) => {
      const perm = e.target.dataset.perm;
      try {
        const fd = new FormData();
        fd.append('csrf_token', '<?=h($CSRF)?>');
        fd.append('permission', perm);
        const res = await fetch('?action=toggle_permission', {
          method:'POST',
          headers:{'X-Requested-With':'XMLHttpRequest'},
          body: fd
        });
        const ct = res.headers.get('content-type') || '';
        const j = ct.includes('application/json') ? await res.json() : {};
        if (!res.ok) throw new Error((j && j.msg) ? j.msg : 'Server error');
        if (permMsg) { permMsg.textContent = j.msg || 'OK'; permMsg.className = 'notice ok'; }
      } catch (err) {
        e.target.checked = !e.target.checked; // revert visual change
        if (permMsg) { permMsg.textContent = err.message; permMsg.className = 'notice err'; }
      }
    });
  });
});
</script>
</body>
</html>
