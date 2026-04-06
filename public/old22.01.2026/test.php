<?php
// ==============================
// SESSION FIX (MUST be BEFORE session_start)
// ==============================
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); // თუ iframe/third-party არ გჭირდება

$httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.cookie_secure', $httpsOn ? '1' : '0');

// სურვილისამებრ: სესიის ხანგრძლივობა (მინ. 0 ნიშნავს "browser session")
ini_set('session.gc_maxlifetime', '86400'); // 24h
session_set_cookie_params([
  'lifetime' => 86400,
  'path' => '/',
  'secure' => $httpsOn,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();

require __DIR__ . '/../config/config.php';
// ==============================
// AUTH GUARD (block direct access)
// ==============================
if (empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

// ===== DEBUG (დროებით) =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    http_response_code(500);
    echo "<h3>FATAL ERROR</h3>";
    echo "<pre>" . htmlspecialchars(print_r($e, true), ENT_QUOTES, 'UTF-8') . "</pre>";
  }
});

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ===== Date formatting =====
function formatKaDate($ymd) {
  try {
    $dt = new DateTime($ymd);

    if (class_exists('IntlDateFormatter')) {
      $fmt = new IntlDateFormatter(
        'ka_GE',
        IntlDateFormatter::FULL,
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::GREGORIAN
      );
      $fmt->setPattern("EEEE, d MMMM, y");
      return $fmt->format($dt);
    }

    $days = ['Sunday'=>'კვირა','Monday'=>'ორშაბათი','Tuesday'=>'სამშაბათი','Wednesday'=>'ოთხშაბათი','Thursday'=>'ხუთშაბათი','Friday'=>'პარასკევი','Saturday'=>'შაბათი'];
    $months = [1=>'იანვარი',2=>'თებერვალი',3=>'მარტი',4=>'აპრილი',5=>'მაისი',6=>'ივნისი',7=>'ივლისი',8=>'აგვისტო',9=>'სექტემბერი',10=>'ოქტომბერი',11=>'ნოემბერი',12=>'დეკემბერი'];

    $dowEn = $dt->format('l');
    $d = (int)$dt->format('j');
    $m = (int)$dt->format('n');
    $y = (int)$dt->format('Y');

    $dow = $days[$dowEn] ?? $dowEn;
    $mon = $months[$m] ?? $m;

    return "{$dow}, {$d} {$mon}, {$y}";
  } catch (Throwable $e) {
    return $ymd;
  }
}

// ===== DB handle detect =====
$pdo  = $pdo ?? null;
$conn = $conn ?? ($mysqli ?? null);

if (!$pdo && !$conn) {
  http_response_code(500);
  die("<h3>DB handle not found</h3><p>config.php-ში ვერ ვიპოვე \$pdo ან \$conn/\$mysqli.</p>");
}

// ===== DB helpers =====
function db_all($pdo, $conn, $sql, $params = [], $types = '') {
  if ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    if (!$params) {
      $res = $conn->query($sql);
      if (!$res) throw new Exception($conn->error);
      return $res->fetch_all(MYSQLI_ASSOC);
    }
    $st = $conn->prepare($sql);
    if (!$st) throw new Exception($conn->error);
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
  }
}

function db_one($pdo, $conn, $sql, $params, $types) {
  $rows = db_all($pdo, $conn, $sql, $params, $types);
  return $rows[0] ?? null;
}

function db_scalar($pdo, $conn, $sql, $params, $types) {
  if ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
  } else {
    $st = $conn->prepare($sql);
    if (!$st) throw new Exception($conn->error);
    $st->bind_param($types, ...$params);
    $st->execute();
    $r = $st->get_result()->fetch_row();
    $st->close();
    return $r ? $r[0] : null;
  }
}

function db_exec($pdo, $conn, $sql, $params, $types) {
  if ($pdo) {
    $st = $pdo->prepare($sql);
    return $st->execute($params);
  } else {
    $st = $conn->prepare($sql);
    if (!$st) throw new Exception($conn->error);
    $st->bind_param($types, ...$params);
    $ok = $st->execute();
    if (!$ok) throw new Exception($st->error);
    $st->close();
    return true;
  }
}

// ===== Load doctors =====
try {
  $doctors = db_all($pdo, $conn, "
    SELECT id, CONCAT(first_name,' ',last_name) AS full_name
    FROM doctors
    ORDER BY last_name, first_name
  ");
} catch (Throwable $e) {
  http_response_code(500);
  die("<h3>Doctors query error</h3><pre>".h($e->getMessage())."</pre>");
}

$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : (int)($doctors[0]['id'] ?? 0);
$day       = $_GET['day'] ?? date('Y-m-d');

$msg = '';
$err = '';

// ===== AJAX: patient search =====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patients') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim($_GET['q'] ?? '');
  if ($q === '' || mb_strlen($q) < 2) { echo "[]"; exit; }

  $like = '%'.$q.'%';

  try {
    $rows = db_all(
      $pdo, $conn,
      "SELECT id, CONCAT(first_name,' ',last_name) AS full_name
       FROM patients
       WHERE CONCAT(first_name,' ',last_name) LIKE ?
          OR first_name LIKE ?
          OR last_name LIKE ?
       ORDER BY last_name, first_name
       LIMIT 20",
      [$like, $like, $like],
      "sss"
    );
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

// ===== Helpers: time normalize =====
function normTimeOrNull($t) {
  $t = trim((string)$t);
  if ($t === '') return null;
  if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return '__INVALID__';
  if (strlen($t) === 5) $t .= ':00';
  return $t;
}

function clampInt($v, $min, $max, $def) {
  $v = (int)$v;
  if ($v <= 0) $v = $def;
  return max($min, min($max, $v));
}

// ===== DELETE appointment =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $del_id = (int)($_POST['appointment_id'] ?? 0);
  $doc    = (int)($_POST['doctor_id'] ?? 0);
  $d      = $_POST['day'] ?? '';

  if ($del_id > 0) {
    try {
      db_exec($pdo, $conn, "DELETE FROM patient_appointments WHERE id = ?", [$del_id], "i");
      header('Location: test.php?doctor_id='.$doc.'&day='.urlencode($d).'&deleted=1');
      exit;
    } catch (Throwable $e) {
      $err = 'წაშლის შეცდომა: '.$e->getMessage();
    }
  }
}

// ===== ADD appointment =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  $p_patient_id = (int)($_POST['patient_id'] ?? 0);
  $p_doctor_id  = (int)($_POST['doctor_id'] ?? 0);
  $p_day        = trim($_POST['day'] ?? '');
  $p_time_raw   = $_POST['time'] ?? '';
  $p_time       = normTimeOrNull($p_time_raw);
  $p_dur        = clampInt($_POST['duration_min'] ?? 20, 5, 240, 20);
  $p_note       = trim($_POST['note'] ?? '');

  if ($p_patient_id <= 0) $err = 'პაციენტი უნდა აირჩიო ძებნით (შედეგებიდან).';
  elseif ($p_doctor_id <= 0) $err = 'აირჩიე ექიმი.';
  elseif ($p_day === '') $err = 'აირჩიე თარიღი.';
  elseif ($p_time === '__INVALID__') $err = 'დრო არასწორია. გამოიყენე HH:MM (მაგ: 10:30).';

  if ($err === '' && $p_time !== null) {
    try {
      $cnt = db_scalar(
        $pdo, $conn,
        "SELECT COUNT(*) FROM patient_appointments
         WHERE doctor_id=? AND appt_date=? AND appt_time=? AND status='scheduled'",
        [$p_doctor_id, $p_day, $p_time],
        "iss"
      );
      if ((int)$cnt > 0) $err = 'ეს დრო უკვე დაკავებულია ამ ექიმთან.';
    } catch (Throwable $e) {
      $err = 'კონფლიქტის ჩეკის შეცდომა: '.$e->getMessage();
    }
  }

  if ($err === '') {
    try {
      db_exec(
        $pdo, $conn,
        "INSERT INTO patient_appointments
          (patient_id, doctor_id, appt_date, appt_time, duration_min, note)
         VALUES (?,?,?,?,?,?)",
        [$p_patient_id, $p_doctor_id, $p_day, $p_time, $p_dur, ($p_note!==''?$p_note:null)],
        "iissis"
      );

      header('Location: test.php?doctor_id='.$p_doctor_id.'&day='.urlencode($p_day).'&ok=1');
      exit;
    } catch (Throwable $e) {
      $err = 'შენახვის შეცდომა: '.$e->getMessage();
    }
  }
}

// ===== UPDATE appointment =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $edit_id      = (int)($_POST['appointment_id'] ?? 0);
  $u_patient_id = (int)($_POST['patient_id'] ?? 0);
  $u_doctor_id  = (int)($_POST['doctor_id'] ?? 0);
  $u_day        = trim($_POST['day'] ?? '');
  $u_time       = normTimeOrNull($_POST['time'] ?? '');
  $u_dur        = clampInt($_POST['duration_min'] ?? 20, 5, 240, 20);
  $u_note       = trim($_POST['note'] ?? '');
  $u_status     = $_POST['status'] ?? 'scheduled';

  $allowedStatus = ['scheduled','done','cancelled'];
  if (!in_array($u_status, $allowedStatus, true)) $u_status = 'scheduled';

  if ($edit_id <= 0) $err = 'რედაქტირების ID არასწორია.';
  elseif ($u_patient_id <= 0) $err = 'პაციენტი უნდა აირჩიო ძებნით (შედეგებიდან).';
  elseif ($u_doctor_id <= 0) $err = 'აირჩიე ექიმი.';
  elseif ($u_day === '') $err = 'აირჩიე თარიღი.';
  elseif ($u_time === '__INVALID__') $err = 'დრო არასწორია. გამოიყენე HH:MM (მაგ: 10:30).';

  if ($err === '' && $u_status === 'scheduled' && $u_time !== null) {
    try {
      $cnt = db_scalar(
        $pdo, $conn,
        "SELECT COUNT(*) FROM patient_appointments
         WHERE doctor_id=? AND appt_date=? AND appt_time=? AND status='scheduled' AND id<>?",
        [$u_doctor_id, $u_day, $u_time, $edit_id],
        "issi"
      );
      if ((int)$cnt > 0) $err = 'ეს დრო უკვე დაკავებულია ამ ექიმთან.';
    } catch (Throwable $e) {
      $err = 'კონფლიქტის ჩეკის შეცდომა: '.$e->getMessage();
    }
  }

  if ($err === '') {
    try {
      db_exec(
        $pdo, $conn,
        "UPDATE patient_appointments
         SET patient_id=?, doctor_id=?, appt_date=?, appt_time=?, duration_min=?, status=?, note=?
         WHERE id=?",
        [
          $u_patient_id,
          $u_doctor_id,
          $u_day,
          $u_time,
          $u_dur,
          $u_status,
          ($u_note!==''?$u_note:null),
          $edit_id
        ],
        "iississi"
      );

      header('Location: test.php?doctor_id='.$u_doctor_id.'&day='.urlencode($u_day).'&updated=1');
      exit;
    } catch (Throwable $e) {
      $err = 'განახლების შეცდომა: '.$e->getMessage();
    }
  }
}

// ===== SMS STUB =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sms_send') {
  $sms_type = $_POST['sms_type'] ?? 'sms1';

  $ids = $_POST['appointment_ids'] ?? [];
  if (!is_array($ids)) $ids = [];
  $ids = array_values(array_filter(array_map('intval', $ids)));

  $doc = (int)($_POST['doctor_id'] ?? 0);
  $d   = $_POST['day'] ?? '';

  $count = count($ids);

  header('Location: test.php?doctor_id='.$doc.'&day='.urlencode($d).'&sms_stub=1&sms_type='.urlencode($sms_type).'&sms_count='.$count);
  exit;
}

// messages
if (isset($_GET['ok']))      $msg = 'ჩანიშვნა დაემატა ✅';
if (isset($_GET['deleted'])) $msg = 'ჩანიშვნა წაიშალა ❌';
if (isset($_GET['updated'])) $msg = 'ჩანიშვნა განახლდა ✏️';
if (isset($_GET['sms_stub'])) {
  $t = $_GET['sms_type'] ?? '';
  $c = (int)($_GET['sms_count'] ?? 0);
  $msg = "SMS (stub) მზადაა ✅ • შაბლონი: {$t} • მონიშნულია: {$c}";
}

// ===== If editing: load appointment =====
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editRow = null;

if ($edit_id > 0) {
  try {
    $editRow = db_one(
      $pdo, $conn,
      "SELECT a.*,
              CONCAT(p.first_name,' ',p.last_name) AS patient_name
       FROM patient_appointments a
       JOIN patients p ON p.id=a.patient_id
       WHERE a.id=?",
      [$edit_id],
      "i"
    );
    if (!$editRow) {
      $err = 'რედაქტირებისთვის ჩანიშვნა ვერ მოიძებნა.';
      $edit_id = 0;
    }
  } catch (Throwable $e) {
    $err = 'რედაქტირების ჩატვირთვის შეცდომა: '.$e->getMessage();
    $edit_id = 0;
  }
}

// ===== Load appointments list =====
try {
  $appointments = db_all(
    $pdo, $conn,
    "SELECT a.id, a.patient_id, a.appt_time, a.duration_min, a.status, a.note,
            CONCAT(p.first_name,' ',p.last_name) AS patient_name
     FROM patient_appointments a
     JOIN patients p ON p.id=a.patient_id
     WHERE a.doctor_id=? AND a.appt_date=?
     ORDER BY (a.appt_time IS NULL), a.appt_time, a.id",
    [$doctor_id, $day],
    "is"
  );
} catch (Throwable $e) {
  $appointments = [];
  $err = 'ჩანიშვნების წამოღების შეცდომა: '.$e->getMessage();
}

// nav active
$cur = basename($_SERVER['PHP_SELF'] ?? 'test.php');
?>
<!doctype html>
<html lang="ka">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>გრაფიკი</title>
  <style>
    :root{--bg:#f9f8f2;--w:#fff;--b:#21c1a6;--b2:#0bb192;--st:#e5e7eb;--m:#6b7280;--sh:0 4px 12px rgba(0,0,0,.06);}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,"Noto Sans Georgian",Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:#111827}
    .top{background:var(--b);color:#fff;padding:10px 14px;position:sticky;top:0;box-shadow:var(--sh);display:flex;justify-content:space-between}
    .wrap{max-width:1400px;margin:12px auto;padding:0 10px}
    .card{background:var(--w);border:1px solid var(--st);border-radius:12px;box-shadow:var(--sh)}
    .head{padding:10px 12px;border-bottom:1px solid var(--st);display:flex;gap:10px;align-items:center}
    .muted{color:var(--m);font-size:12px}
    .filters{display:grid;grid-template-columns:repeat(12,1fr);gap:8px;padding:10px 12px;border-bottom:1px solid var(--st);background:#fbfbfb}
    .fg{display:flex;flex-direction:column;gap:4px; position:relative;}
    label{font-size:11px;color:#374151}
    input,select,textarea{border:1px solid var(--st);border-radius:10px;padding:8px 10px;font-size:13px;outline:none;background:#fff}
    textarea{min-height:42px;resize:vertical}
    .btn{padding:9px 12px;border-radius:10px;border:0;background:var(--b);color:#fff;font-weight:800;cursor:pointer}
    .btn:hover{background:var(--b2)}
    .grid{display:grid;grid-template-columns:420px 1fr;gap:12px;padding:12px}
    @media(max-width:1100px){.grid{grid-template-columns:1fr}}
    .alert{margin:10px 0;padding:10px 12px;border-radius:10px;font-size:13px}
    .ok{background:#ecfdf5;border:1px solid #bbf7d0}
    .er{background:#fee2e2;border:1px solid #fecaca}

    #upnav.upnav{margin-top:10px;display:flex;gap:12px;border-bottom:2px solid #ddd;padding:6px 40px;}
    #upnav.upnav a{text-decoration:none;color:#21c1a6;padding:6px 12px;border-radius:4px;font-weight:600;}
    #upnav.upnav a.active,#upnav.upnav a:hover,#upnav.upnav a:focus{background:#21c1a6;color:#fff;outline:none;}

    table{width:100%;border-collapse:separate;border-spacing:0}
    th,td{padding:10px;border-bottom:1px solid var(--st);vertical-align:top}
    th{background:#fff;position:sticky;top:0;text-align:left;font-size:12px}
    tr:hover td{background:#f7fffd}
    .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#374151}
    .pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid #d5f1ea;background:#e9f7f3;color:#145e50}
    .pill.cancelled{border-color:#fecaca;background:#fee2e2;color:#991b1b}
    .pill.done{border-color:#bbf7d0;background:#ecfdf5;color:#14532d}

    .results{
      position:absolute; top:66px; left:0; right:0;
      background:#fff; border:1px solid var(--st); border-radius:10px;
      box-shadow:var(--sh); overflow:hidden; display:none; z-index:50;
      max-height:260px; overflow:auto;
    }
    .item{padding:10px;cursor:pointer;border-bottom:1px solid var(--st);font-size:13px}
    .item:last-child{border-bottom:0}
    .item:hover{background:#f7fffd}
    .selectedBox{
      padding:8px 10px;border:1px dashed #bfece2;border-radius:10px;background:#f3fffb;color:#145e50;font-size:12px
    }
    .datePretty{font-weight:900;color:#0f766e}
    .dateWrap{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .actions{display:flex;gap:6px;flex-wrap:wrap}
    .btnSmall{padding:6px 10px;border-radius:8px;border:0;cursor:pointer;font-size:12px;font-weight:900}
    .btnRed{background:#dc2626;color:#fff}
    .btnGray{background:#111827;color:#fff}
    a.linkBtn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
    .sessdbg{padding:8px 12px; font-size:12px; color:#374151; background:#fff; border:1px dashed #d1d5db; border-radius:10px; margin:10px 0;}
  </style>
</head>
<body>

<div class="wrap">
  <!-- SESSION DEBUG (Remove later) -->
  <div class="sessdbg">
    SessionID: <b class="mono"><?= h(session_id()) ?></b>
    • HTTPS: <b class="mono"><?= $httpsOn ? 'on' : 'off' ?></b>
  </div>
</div>

<div class="top">
  <div style="font-weight:900">EHR • გრაფიკი</div>
  <div style="font-size:12px;opacity:.9">test.php</div>
</div>

<nav id="upnav" class="upnav" role="navigation" aria-label="Secondary navigation">
  <a href="dashboard.php" class="<?= $cur=='dashboard.php' ? 'active' : '' ?>">მთავარი</a>
  <a href="doctors.php"   class="<?= $cur=='doctors.php'   ? 'active' : '' ?>">HR</a>
  <a href="journal.php"   class="<?= $cur=='journal.php'   ? 'active' : '' ?>">რეპორტი</a>
  <a href="test.php"      class="<?= $cur=='test.php'      ? 'active' : '' ?>">გრაფიკი</a>
</nav>

<div class="wrap">
  <?php if($msg): ?><div class="alert ok"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert er"><?=h($err)?></div><?php endif; ?>

  <div class="card">
    <div class="head">
      <div style="font-weight:900">გრაფიკი • ჩანიშვნები</div>
      <div class="muted">აირჩიე ექიმი და დღე — შემდეგ დანიშნე / შეცვალე / წაშალე</div>

      <div class="muted" style="margin-left:auto">
        <div class="dateWrap">
          <span class="mono"><?=h($day)?></span>
          <span class="datePretty"><?=h(formatKaDate($day))?></span>
        </div>
      </div>
    </div>

    <form class="filters" method="get">
      <div class="fg" style="grid-column:span 6">
        <label>ექიმი</label>
        <select name="doctor_id">
          <?php foreach($doctors as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $doctor_id==(int)$d['id']?'selected':'' ?>>
              <?= h($d['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg" style="grid-column:span 4">
        <label>თარიღი</label>
        <input type="date" name="day" value="<?=h($day)?>">
        <div class="muted" style="font-size:11px; margin-top:4px;">
          <?= h(formatKaDate($day)) ?>
        </div>
      </div>

      <div class="fg" style="grid-column:span 2;align-self:end">
        <button class="btn" type="submit">ნახვა</button>
      </div>
    </form>

    <div class="grid">
      <!-- LEFT -->
      <div class="card" style="box-shadow:none">
        <div class="head">
          <?php if($edit_id && $editRow): ?>
            <div style="font-weight:900;font-size:13px">✏️ ჩანიშვნის რედაქტირება (ID: <?= (int)$edit_id ?>)</div>
            <div class="muted">შეცვალე მონაცემები და შეინახე</div>
            <div style="margin-left:auto">
              <a class="btnSmall btnGray linkBtn" href="test.php?doctor_id=<?= (int)$doctor_id ?>&day=<?= urlencode($day) ?>">დახურვა</a>
            </div>
          <?php else: ?>
            <div style="font-weight:900;font-size:13px">ჩანიშვნის დამატება</div>
            <div class="muted">ამ ექიმზე / ამ დღეზე</div>
          <?php endif; ?>
        </div>

        <?php if(!$edit_id): ?>
          <form method="post" id="addForm" style="padding:12px;display:grid;gap:10px">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="doctor_id" value="<?= (int)$doctor_id ?>">
            <input type="hidden" name="day" value="<?= h($day) ?>">

            <div class="selectedBox" style="display:block">
              არჩეული თარიღი: <b><?= h(formatKaDate($day)) ?></b>
              <span class="mono" style="margin-left:8px"><?= h($day) ?></span>
            </div>

            <input type="hidden" name="patient_id" id="patient_id_add" value="">

            <div class="fg">
              <label>პაციენტის ძებნა (ჩაწერით)</label>
              <input type="text" id="patientSearch_add" placeholder="დაწერე სახელი/გვარი..." autocomplete="off">
              <div class="results" id="patientResults_add"></div>
              <div class="selectedBox" id="selectedPatient_add" style="display:none"></div>
              <div class="muted" style="font-size:11px">აირჩიე შედეგებიდან — თორემ ფორმა არ გაიგზავნება.</div>
            </div>

            <div class="fg">
              <label>დრო (optional)</label>
              <input type="time" name="time" step="300">
            </div>

            <div class="fg">
              <label>ხანგრძლივობა (წუთი)</label>
              <input type="number" name="duration_min" value="20" min="5" max="240">
            </div>

            <div class="fg">
              <label>შენიშვნა</label>
              <textarea name="note"></textarea>
            </div>

            <button class="btn" type="submit">დამატება</button>
          </form>
        <?php else: ?>
          <?php if($editRow): ?>
            <form method="post" id="editForm" style="padding:12px;display:grid;gap:10px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="appointment_id" value="<?= (int)$editRow['id'] ?>">

              <div class="selectedBox" style="display:block">
                მიმდინარე: <b><?= h($editRow['patient_name'] ?? '') ?></b>
                <?php if(!empty($editRow['appt_date'])): ?>
                  • <span class="datePretty"><?= h(formatKaDate($editRow['appt_date'])) ?></span>
                  <span class="mono">(<?= h($editRow['appt_date']) ?>)</span>
                <?php endif; ?>
              </div>

              <div class="fg">
                <label>ექიმი</label>
                <select name="doctor_id" required>
                  <?php foreach($doctors as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= ((int)$editRow['doctor_id']==(int)$d['id'])?'selected':'' ?>>
                      <?= h($d['full_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="fg">
                <label>თარიღი</label>
                <input type="date" name="day" value="<?= h($editRow['appt_date']) ?>" required>
              </div>

              <input type="hidden" name="patient_id" id="patient_id_edit" value="<?= (int)$editRow['patient_id'] ?>">

              <div class="fg">
                <label>პაციენტის შეცვლა (ჩაწერით)</label>
                <input type="text" id="patientSearch_edit" placeholder="ჩაწერე ახალი პაციენტი..." autocomplete="off">
                <div class="results" id="patientResults_edit"></div>
                <div class="selectedBox" id="selectedPatient_edit" style="display:block">
                  არჩეული პაციენტი: <b><?= h($editRow['patient_name'] ?? '') ?></b> • ID: <?= (int)$editRow['patient_id'] ?>
                </div>
                <div class="muted" style="font-size:11px">თუ პაციენტს არ ცვლი — დატოვე როგორც არის.</div>
              </div>

              <div class="fg">
                <label>დრო (optional)</label>
                <input type="time" name="time" step="300" value="<?= h(substr((string)($editRow['appt_time'] ?? ''),0,5)) ?>">
              </div>

              <div class="fg">
                <label>ხანგრძლივობა (წუთი)</label>
                <input type="number" name="duration_min" value="<?= (int)($editRow['duration_min'] ?? 20) ?>" min="5" max="240">
              </div>

              <div class="fg">
                <label>სტატუსი</label>
                <select name="status">
                  <?php
                    $st = $editRow['status'] ?? 'scheduled';
                    $opts = ['scheduled'=>'scheduled','done'=>'done','cancelled'=>'cancelled'];
                    foreach($opts as $k=>$v):
                  ?>
                    <option value="<?=h($k)?>" <?= ($st===$k?'selected':'') ?>><?=h($v)?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="fg">
                <label>შენიშვნა</label>
                <textarea name="note"><?= h($editRow['note'] ?? '') ?></textarea>
              </div>

              <button class="btn" type="submit">შენახვა</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- RIGHT -->
      <div class="card" style="box-shadow:none;overflow:auto;max-height:75vh">
        <div class="head">
          <div style="font-weight:900;font-size:13px">დღის ჩანიშვნები</div>
          <div class="muted">სულ: <b class="mono"><?= (int)count($appointments) ?></b></div>
        </div>

        <div style="padding:10px 12px;border-bottom:1px solid var(--st);background:#fbfbfb;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <span class="muted">მონიშნული: <b class="mono" id="smsSelectedCount">0</b></span>
          <button type="button" class="btnSmall btnGray" id="smsBulkOpen">Bulk SMS</button>
          <span class="muted" style="margin-left:auto;font-size:11px">* ჯერ მხოლოდ UI / Stub — რეალური SMS მერე მიება</span>
        </div>

        <table>
          <thead>
            <tr>
              <th style="width:44px"><input type="checkbox" id="smsSelectAll" title="ყველას მონიშვნა"></th>
              <th style="width:110px">დრო</th>
              <th>პაციენტი</th>
              <th style="width:70px">წუთი</th>
              <th style="width:120px">სტატუსი</th>
              <th>შენიშვნა</th>
              <th style="width:160px">ქმედება</th>
              <th style="width:110px">SMS</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$appointments): ?>
              <tr><td colspan="8" class="muted">ამ დღეზე ჩანიშვნები არ არის.</td></tr>
            <?php else: foreach($appointments as $a): ?>
              <tr>
                <td>
                  <input type="checkbox" class="smsRow" value="<?= (int)$a['id'] ?>" title="მონიშვნა SMS-ზე">
                </td>
                <td class="mono"><?= h($a['appt_time'] ?? '') ?></td>
                <td><?= h($a['patient_name'] ?? '') ?></td>
                <td class="mono"><?= (int)($a['duration_min'] ?? 0) ?></td>
                <td>
                  <?php
                    $st = $a['status'] ?? 'scheduled';
                    $cls = 'pill';
                    if ($st === 'cancelled') $cls .= ' cancelled';
                    if ($st === 'done') $cls .= ' done';
                  ?>
                  <span class="<?=h($cls)?>"><?= h($st) ?></span>
                </td>
                <td><?= h($a['note'] ?? '') ?></td>
                <td>
                  <div class="actions">
                    <a class="btnSmall btnGray linkBtn"
                       href="test.php?doctor_id=<?= (int)$doctor_id ?>&day=<?= urlencode($day) ?>&edit_id=<?= (int)$a['id'] ?>">
                      რედაქტირება
                    </a>
                    <form method="post" onsubmit="return confirm('დარწმუნებული ხარ რომ გინდა წაშლა?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                      <input type="hidden" name="doctor_id" value="<?= (int)$doctor_id ?>">
                      <input type="hidden" name="day" value="<?= h($day) ?>">
                      <button type="submit" class="btnSmall btnRed">წაშლა</button>
                    </form>
                  </div>
                </td>
                <td>
                  <button type="button"
                          class="btnSmall btnGray smsOpen"
                          data-mode="single"
                          data-id="<?= (int)$a['id'] ?>">
                    SMS
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>

        <!-- SMS template modal -->
        <div id="smsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; padding:18px;">
          <div style="max-width:520px; margin:8vh auto; background:#fff; border-radius:14px; border:1px solid var(--st); box-shadow:var(--sh); overflow:hidden">
            <div style="padding:12px 14px; border-bottom:1px solid var(--st); display:flex; align-items:center; gap:10px;">
              <b>SMS გაგზავნა</b>
              <span class="muted" id="smsModalInfo" style="margin-left:auto"></span>
              <button type="button" class="btnSmall btnGray" id="smsModalClose">დახურვა</button>
            </div>

            <div style="padding:14px; display:grid; gap:10px;">
              <div class="fg">
                <label>შაბლონი</label>
                <select id="smsTemplateSelect">
                  <option value="sms1">SMS 1 — ვიზიტის შეხსენება</option>
                  <option value="sms2">SMS 2 — დადასტურება</option>
                  <option value="sms3">SMS 3 — გაუქმება/გადატანა</option>
                </select>
                <div class="muted" style="font-size:11px">* ტექსტები მერე მიება (ახლა stub)</div>
              </div>

              <div style="display:flex; gap:8px; justify-content:flex-end">
                <button type="button" class="btnSmall btnGray" id="smsModalSend">გაგზავნა</button>
              </div>
            </div>
          </div>
        </div>

        <!-- hidden SMS send form -->
        <form method="post" id="smsSendForm" style="display:none">
          <input type="hidden" name="action" value="sms_send">
          <input type="hidden" name="doctor_id" value="<?= (int)$doctor_id ?>">
          <input type="hidden" name="day" value="<?= h($day) ?>">
          <input type="hidden" name="sms_type" id="sms_type" value="sms1">
          <div id="sms_ids_holder"></div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function initPatientSearch(prefix) {
  const input = document.getElementById('patientSearch_' + prefix);
  const results = document.getElementById('patientResults_' + prefix);
  const pid = document.getElementById('patient_id_' + prefix);
  const selectedBox = document.getElementById('selectedPatient_' + prefix);

  if (!input || !results || !pid || !selectedBox) return;

  let t = null;
  let lastQ = '';

  function hideResults(){ results.style.display = 'none'; results.innerHTML = ''; }
  function showResults(){ results.style.display = 'block'; }

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(t);
    if (q.length < 2) { hideResults(); return; }

    t = setTimeout(async () => {
      if (q === lastQ) return;
      lastQ = q;

      try {
        const r = await fetch(`test.php?ajax=patients&q=${encodeURIComponent(q)}`, {credentials:'same-origin'});
        const data = await r.json();

        results.innerHTML = '';
        if (!Array.isArray(data)) { hideResults(); return; }

        if (data.length === 0) {
          results.innerHTML = `<div class="item" style="cursor:default;color:#6b7280">ვერ მოიძებნა</div>`;
          showResults();
          return;
        }

        data.forEach(row => {
          const div = document.createElement('div');
          div.className = 'item';
          div.textContent = row.full_name + ' (ID: ' + row.id + ')';
          div.addEventListener('click', () => {
            pid.value = row.id;
            selectedBox.style.display = 'block';
            selectedBox.innerHTML = 'არჩეული პაციენტი: <b>' + row.full_name + '</b> • ID: ' + row.id;
            hideResults();
            input.value = '';
          });
          results.appendChild(div);
        });

        showResults();
      } catch(e){
        hideResults();
      }
    }, 250);
  });

  document.addEventListener('click', (e) => {
    if (!results.contains(e.target) && e.target !== input) hideResults();
  });
}

initPatientSearch('add');
initPatientSearch('edit');

const addForm = document.getElementById('addForm');
if (addForm) {
  addForm.addEventListener('submit', (e) => {
    const pid = document.getElementById('patient_id_add');
    if (!pid || !pid.value) {
      e.preventDefault();
      alert('პაციენტი უნდა აირჩიო ძებნით (შედეგებიდან)!');
      document.getElementById('patientSearch_add').focus();
    }
  });
}

// ===== SMS UI (Stub) with Template Modal =====
(function(){
  const selectAll = document.getElementById('smsSelectAll');
  const rows = () => Array.from(document.querySelectorAll('.smsRow'));
  const countEl = document.getElementById('smsSelectedCount');

  const form = document.getElementById('smsSendForm');
  const holder = document.getElementById('sms_ids_holder');
  const smsTypeInput = document.getElementById('sms_type');

  const modal = document.getElementById('smsModal');
  const modalClose = document.getElementById('smsModalClose');
  const modalSend = document.getElementById('smsModalSend');
  const tplSelect = document.getElementById('smsTemplateSelect');
  const modalInfo = document.getElementById('smsModalInfo');

  let pendingIds = [];

  function selectedIds(){
    return rows().filter(c => c.checked).map(c => c.value);
  }
  function updateCount(){
    if (!countEl) return;
    countEl.textContent = String(selectedIds().length);
  }

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      rows().forEach(c => c.checked = selectAll.checked);
      updateCount();
    });
  }
  document.addEventListener('change', (e) => {
    if (e.target && e.target.classList.contains('smsRow')) {
      updateCount();
      if (selectAll && !e.target.checked) selectAll.checked = false;
    }
  });

  function openModal(ids){
    if (!modal) return;
    if (!ids || ids.length === 0) {
      alert('ჯერ მონიშნე ჩანიშვნები (checkbox) ✅');
      return;
    }
    pendingIds = ids.slice();
    if (modalInfo && !modalInfo.textContent.trim()) {
      modalInfo.textContent = `არჩეული: ${pendingIds.length}`;
    }
    modal.style.display = 'block';
  }

  function closeModal(){
    if (!modal) return;
    modal.style.display = 'none';
    pendingIds = [];
    if (modalInfo) modalInfo.textContent = '';
  }

  function submitSms(type, ids){
    if (!form || !holder || !smsTypeInput) return;

    smsTypeInput.value = type;
    holder.innerHTML = '';

    ids.forEach(id => {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'appointment_ids[]';
      inp.value = id;
      holder.appendChild(inp);
    });

    form.submit();
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.smsOpen');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    if (modalInfo) modalInfo.textContent = '';
    openModal([id]);
  });

  const bulkBtn = document.getElementById('smsBulkOpen');
  if (bulkBtn) {
    bulkBtn.addEventListener('click', () => {
      const ids = selectedIds();
      if (!ids.length) {
        alert('ჯერ მონიშნე ჩანიშვნები (checkbox) ✅');
        return;
      }
      if (modalInfo) modalInfo.textContent = `Bulk SMS • არჩეული: ${ids.length}`;
      openModal(ids);
    });
  }

  if (modalClose) modalClose.addEventListener('click', closeModal);

  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });
  }

  if (modalSend) {
    modalSend.addEventListener('click', () => {
      const type = (tplSelect && tplSelect.value) ? tplSelect.value : 'sms1';
      if (!pendingIds.length) {
        alert('მონიშნული არ არის ✅');
        return;
      }
      submitSms(type, pendingIds);
    });
  }

  updateCount();
})();
</script>
</body>
</html>
