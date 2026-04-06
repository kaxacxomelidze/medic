<?php
// public/journal.php — Patients journal (modern UI, light theme)
// Columns: # | Personal ID | First | Last | Father | Card # | Address | Phone | DOB | Age | Registration | Insurance | Doctor | Consultation

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__ . '/../config/config.php'; // must set $pdo (PDO with ERRMODE_EXCEPTION)
// ==============================
// AUTH GUARD (block direct access)
// ==============================
if (empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

// If you need auth, uncomment:
// if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

/* --- Security headers --- */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: interest-cohort=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex'); // internal tool
// ✅ CSP: allow Google Fonts stylesheet + font files (and inline styles/scripts used here)
header("Content-Security-Policy: " .
  "default-src 'self'; " .
  "img-src 'self' data:; " .
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
  "font-src 'self' https://fonts.gstatic.com data:; " .
  "connect-src 'self'; " .
  "script-src 'self' 'unsafe-inline'; " .
  "frame-ancestors 'self'; " .
  "base-uri 'self'; " .
  "form-action 'self'"
);

/* --- Constants --- */
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (!defined('PAGE_SIZE_DEFAULT')) define('PAGE_SIZE_DEFAULT', 50);
if (!defined('PAGE_SIZE_MAX')) define('PAGE_SIZE_MAX', 200);

/* --- Charset --- */
$ERR = null;
try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) { $ERR = $e->getMessage(); }

/* --- Helpers --- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function clampInt($v,$min,$max){ $x=(int)$v; if($x<$min)$x=$min; if($x>$max)$x=$max; return $x; }
function like($s){ return '%'.$s.'%'; }
function parseDateYmd($s){
  $s = trim((string)$s);
  if ($s==='') return '';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})$/', $s, $m)) {
    [$all,$d,$mth,$y] = $m;
    if (checkdate((int)$mth,(int)$d,(int)$y)) return sprintf('%04d-%02d-%02d', $y,$mth,$d);
  }
  return '';
}
function normalize_range(string $from, string $to): array {
  if ($from && $to && $from > $to) { [$from,$to] = [$to,$from]; }
  return [$from,$to];
}
function age_text(?string $bd): string {
  if (!$bd || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$bd)) return '';
  $b = new DateTime($bd);
  $n = new DateTime('today');
  $d = $b->diff($n);
  return $d->y.' წელი '.$d->m.' თვე '.$d->d.' დღე';
}
function service_code(?string $name): string {
  if (!$name) return '';
  if (preg_match('/\b(AK\d{2})\b/u', $name, $m)) return $m[1];
  return '';
}

/* --- Filters (GET) --- */
$reg_from   = parseDateYmd($_GET['repbr_date1'] ?? '');
$reg_to     = parseDateYmd($_GET['repbr_date2'] ?? '');
[$reg_from, $reg_to] = normalize_range($reg_from, $reg_to);

$prim_sec   = trim((string)($_GET['gadnodag'] ?? '')); // 1=პირველადი, 2=მეორადი
$personal   = trim((string)($_GET['m_rsno'] ?? ''));
$first_name = trim((string)($_GET['m_fnam'] ?? ''));
$last_name  = trim((string)($_GET['m_lnam'] ?? ''));
$address    = trim((string)($_GET['m_addrs'] ?? ''));
$phone      = trim((string)($_GET['m_phon'] ?? ''));

$dob_from   = parseDateYmd($_GET['s_dab1'] ?? '');
$dob_to     = parseDateYmd($_GET['s_dab2'] ?? '');
[$dob_from, $dob_to] = normalize_range($dob_from, $dob_to);

$doctor_id_raw = trim((string)($_GET['smvlki'] ?? ''));
$doctor_id     = ($doctor_id_raw === '' ? '' : (string)(int)$doctor_id_raw); // normalize to int string or ''

/* Paging / sorting */
$page       = clampInt($_GET['page'] ?? 1, 1, 1000000);
$pagesize   = clampInt($_GET['pagesize'] ?? PAGE_SIZE_DEFAULT, 10, PAGE_SIZE_MAX);

$sort       = trim((string)($_GET['sort'] ?? 'p.registration_date'));
$dirParam   = strtolower(trim((string)($_GET['dir'] ?? 'desc')));
$dir        = $dirParam === 'asc' ? 'ASC' : 'DESC';

$sortMap = [
  'p.registration_date' => 'p.registration_date',
  'p.last_name'         => 'p.last_name',
  'p.first_name'        => 'p.first_name',
  'p.birthdate'         => 'p.birthdate',
  'last_service_at'     => 'last_service_at'
];
$orderBy = $sortMap[$sort] ?? 'p.registration_date';

/* Doctors list */
$doctors = [];
try {
  // tolerate lack of table/column differences
  $stDocs = $pdo->query("
    SELECT id, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS name
    FROM doctors
    WHERE COALESCE(status,'აქტიური')='აქტიური'
    ORDER BY last_name, first_name
  ");
  $doctors = $stDocs ? ($stDocs->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) { if (APP_DEBUG) $ERR = $e->getMessage(); }

/* WHERE (patients base) */
$args = [];
$where = ["1=1"];
if ($reg_from !== '') { $where[]="p.registration_date >= ?"; $args[] = $reg_from; }
if ($reg_to   !== '') { $where[]="p.registration_date <= ?"; $args[] = $reg_to; }
if ($personal   !== '') { $where[]="p.personal_id LIKE ?"; $args[] = like($personal); }
if ($first_name !== '') { $where[]="p.first_name  LIKE ?"; $args[] = like($first_name); }
if ($last_name  !== '') { $where[]="p.last_name   LIKE ?";  $args[] = like($last_name); }
if ($address    !== '') { $where[]="COALESCE(p.address,'') LIKE ?"; $args[] = like($address); }
if ($phone      !== '') { $where[]="COALESCE(p.phone,'')   LIKE ?"; $args[] = like($phone); }
if ($dob_from !== '')   { $where[]="p.birthdate >= ?"; $args[] = $dob_from; }
if ($dob_to   !== '')   { $where[]="p.birthdate <= ?"; $args[] = $dob_to; }

/* Primary/Secondary by count of patient_services */
if     ($prim_sec === '1') { $where[]="(SELECT COUNT(*) FROM patient_services ps WHERE ps.patient_id=p.id)=1"; }
elseif ($prim_sec === '2') { $where[]="(SELECT COUNT(*) FROM patient_services ps WHERE ps.patient_id=p.id)>1"; }

/* Doctor filter (EXISTS) */
if ($doctor_id !== '') {
  $where[] = "EXISTS (SELECT 1 FROM patient_services psd WHERE psd.patient_id=p.id AND psd.doctor_id=?)";
  $args[]  = (int)$doctor_id;
}

$whereSql = implode(' AND ', $where);

/* Count total */
try {
  $stc = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE $whereSql");
  $stc->execute($args);
  $total = (int)$stc->fetchColumn();
} catch (Throwable $e) {
  if (APP_DEBUG) $ERR = $e->getMessage();
  $total = 0;
}

/* Rows: last service / doctor / card # / insurance via subqueries
   (Tip: Ensure indexes on patient_services(patient_id, created_at), invoices(patient_id, issued_at), patient_guarantees(patient_id, created_at) for good perf.) */
$offset = ($page-1)*$pagesize;
$sql = "
SELECT
  p.id,
  p.personal_id,
  p.first_name,
  p.last_name,
  COALESCE(p.father_name,'') AS father_name,
  COALESCE(p.address,'')     AS address,
  COALESCE(p.phone,'')       AS phone,
  p.birthdate,
  p.registration_date,

  /* ბარათის #: last invoice → a-XXX-YYYY */
  (
    SELECT CASE WHEN inv.id IS NULL THEN '' ELSE
           CONCAT('a-', LPAD(inv.id,3,'0'), '-', DATE_FORMAT(inv.issued_at,'%Y')) END
    FROM invoices inv
    WHERE inv.patient_id = p.id
    ORDER BY inv.issued_at DESC, inv.id DESC
    LIMIT 1
  ) AS card_no,

  /* სადაზღვეო: latest patient_guarantees.donor */
  (
    SELECT COALESCE(pg.donor,'')
    FROM patient_guarantees pg
    WHERE pg.patient_id = p.id
    ORDER BY pg.created_at DESC, pg.id DESC
    LIMIT 1
  ) AS insurance_name,

  /* ბოლო სერვისის თარიღი/დრო */
  (
    SELECT ps.created_at
    FROM patient_services ps
    WHERE ps.patient_id = p.id
    ORDER BY ps.created_at DESC, ps.id DESC
    LIMIT 1
  ) AS last_service_at,

  /* ბოლო სერვისის სახელი */
  (
    SELECT s.name
    FROM patient_services ps
    JOIN services s ON s.id = ps.service_id
    WHERE ps.patient_id = p.id
    ORDER BY ps.created_at DESC, ps.id DESC
    LIMIT 1
  ) AS service_name,

  /* ბოლო სერვისის ექიმი */
  (
    SELECT CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,''))
    FROM patient_services ps
    JOIN doctors d ON d.id = ps.doctor_id
    WHERE ps.patient_id = p.id
    ORDER BY ps.created_at DESC, ps.id DESC
    LIMIT 1
  ) AS doctor_full

FROM patients p
WHERE $whereSql
ORDER BY $orderBy $dir, p.id DESC
LIMIT $pagesize OFFSET $offset
";

try {
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  if (APP_DEBUG) $ERR = $e->getMessage();
  $rows = [];
}

/* ---- Export helpers ---- */
function export_rows(array $rows, string $fmt): void {
  $ee = fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
  $filenameBase = 'journal_'.date('Ymd_His');

  if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filenameBase.csv\"");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','პირადი #','სახილი','გვარი','მამის სახელი','ბარათის #','მისამართი','ტელ.','დაბადების თარიღი','ასაკი','აღრიცხვის თარიღი','სადაზღვეო','მკურნალი ექიმი','კონსულტაცია']);
    $i=0;
    foreach($rows as $r){
      $age = age_text($r['birthdate'] ?? '');
      fputcsv($out, [
        ++$i,
        $r['personal_id'] ?? '',
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        $r['father_name'] ?? '',
        $r['card_no'] ?? '',
        $r['address'] ?? '',
        $r['phone'] ?? '',
        $r['birthdate'] ?? '',
        $age,
        $r['registration_date'] ?? '',
        $r['insurance_name'] ?? '',
        $r['doctor_full'] ?? '',
        $r['service_name'] ?? '',
      ]);
    }
    fclose($out);
    exit;
  }

  // default: XLS (HTML table)
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"$filenameBase.xls\"");
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo "\xEF\xBB\xBF";
  echo '<html><head><meta charset="UTF-8"></head><body>';
  echo '<table border="1" cellspacing="0" cellpadding="3">';
  echo '<tr style="background:#f3f4f6;font-weight:bold">'
     . '<th>#</th><th>პირადი #</th><th>სახელი</th><th>გვარი</th><th>მამის სახელი</th>'
     . '<th>ბარათის #</th><th>მისამართი</th><th>ტელ.</th><th>დაბადების თარიღი</th><th>ასაკი</th>'
     . '<th>აღრიცხვის თარიღი</th><th>სადაზღვეო</th><th>მკურნალი ექიმი</th><th>კონსულტაცია</th>'
     . '</tr>';
  $i=0;
  foreach ($rows as $r) {
    $svc = (string)($r['service_name'] ?? '');
    $age = age_text($r['birthdate'] ?? '');
    $isAK03 = stripos($svc,'AK03') !== false;
    echo '<tr>'
      . '<td>'.(++$i).'</td>'
      . '<td>'.$ee($r['personal_id'] ?? '').'</td>'
      . '<td>'.$ee($r['first_name'] ?? '').'</td>'
      . '<td>'.$ee($r['last_name'] ?? '').'</td>'
      . '<td>'.$ee($r['father_name'] ?? '').'</td>'
      . '<td>'.$ee($r['card_no'] ?? '').'</td>'
      . '<td>'.$ee($r['address'] ?? '').'</td>'
      . '<td>'.$ee($r['phone'] ?? '').'</td>'
      . '<td>'.$ee($r['birthdate'] ?? '').'</td>'
      . '<td>'.$ee($age).'</td>'
      . '<td>'.$ee($r['registration_date'] ?? '').'</td>'
      . '<td>'.$ee($r['insurance_name'] ?? '').'</td>'
      . '<td>'.$ee($r['doctor_full'] ?? '').'</td>'
      . '<td'.($isAK03?' style="color:#ef4444;font-weight:bold"':'').'>'.$ee($svc).'</td>'
      . '</tr>';
  }
  echo '</table></body></html>';
  exit;
}

/* ---- Exports route ----
   NOTE: We already fetched $rows using current GET params.
   The export buttons set pagesize to a very large number and page=1,
   so $rows contains the complete filtered set before we reach here. */
if (isset($_GET['action']) && ($_GET['action'] === 'export' || $_GET['action']==='export_csv')) {
  export_rows($rows, $_GET['action']==='export_csv' ? 'csv' : 'xls');
}

$cur = basename(__FILE__);
$total_pages = max(1, (int)ceil($total / $pagesize));
?>
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<title>ამბულატორიული ჟურნალი • Patients</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts - Noto Sans Georgian -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/preclinic-theme.css">

<style>
:root{
  --bg:#f9f8f2; --surface:#fff; --text:#222; --muted:#6b7280;
  --brand:#21c1a6; --brand-2:#0bb192; --stroke:#e5e7eb;
  --accent:#f1fbf8; --shadow:0 6px 18px rgba(0,0,0,.06);
  --danger:#ef4444; --danger-bg:#fee2e2; --danger-br:#fecaca;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:"Noto Sans Georgian",sans-serif;background:var(--bg);color:var(--text)}
a{color:#0f766e;text-decoration:none}
a.rowlink{border-bottom:1px dotted #0f766e}
a.rowlink:hover{border-bottom-color:transparent}

.container{max-width:1750px;margin:20px auto;padding:0 18px}

/* Top bar */
.topbar{
  position:sticky; top:0; z-index:20;
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 18px; background:var(--brand); color:#fff; box-shadow:var(--shadow);
}
.brand{display:flex; gap:10px; font-weight:800; align-items:center}
.brand .dot{width:10px; height:10px; border-radius:50%; background:#fff}
.user-menu-wrap{position:relative}
.user-btn{cursor:pointer}
.user-dropdown{display:none; position:absolute; right:0; top:32px; background:#fff; color:#111827; border:1px solid var(--stroke); border-radius:10px; box-shadow:var(--shadow); min-width:180px; overflow:hidden}
.user-dropdown a{display:block; padding:10px 12px; text-decoration:none; color:#111827}
.user-dropdown a:hover{background:#f9fafb}

/* Tabs / upnav */
#upnav.upnav{margin-top:10px;display:flex;gap:12px;border-bottom:2px solid #ddd;padding:6px 40px;}
#upnav.upnav a{text-decoration:none;color:#21c1a6;padding:6px 12px;border-radius:4px;font-weight:600;}
#upnav.upnav a.active,#upnav.upnav a:hover,#upnav.upnav a:focus{background:#21c1a6;color:#fff;outline:none;}

/* Card */
.card{background:#fff; border:1px solid var(--stroke); border-radius:14px; box-shadow:var(--shadow)}
.card .head{
  position:sticky; top:56px; background:#fff; padding:14px 16px;
  border-bottom:1px solid var(--stroke); display:flex; align-items:center; gap:12px; flex-wrap:wrap; z-index:5
}
.head .title{font-size:16px; font-weight:800; color:#0f172a; flex:1}
.card .body{padding:16px}

/* Buttons */
.btn{padding:10px 14px; border-radius:10px; border:1px solid var(--brand-2); background:var(--brand); color:#fff; font-weight:700; cursor:pointer}
.btn:hover{background:#0bb192}
.btn.ghost{background:#fff; color:#0bb192; border-color:#9adfd1}
.btn.ghost:hover{background:#eefaf6}

/* Filters */
.filters{
  display:grid; grid-template-columns:repeat(12,1fr); gap:10px;
  border-bottom:1px solid var(--stroke); background:#fff; padding:12px 16px;
  position:sticky; top:106px; z-index:10;
}
@media (max-width:1200px){.filters{grid-template-columns:repeat(6,1fr)}}
@media (max-width:720px){.filters{grid-template-columns:1fr}}
.fg{display:flex; flex-direction:column}
.fg label{font-size:12px; color:var(--muted); margin:0 0 6px}
.fg input[type=text], .fg input[type=date], .fg select{
  width:100%; padding:9px 10px; border:1px solid var(--stroke); border-radius:10px; background:#fff; outline:none; font-size:14px
}
.fg input:focus, .fg select:focus{border-color:#9adfd1; box-shadow:0 0 0 4px rgba(33,193,166,.15)}

/* Table */
.table-wrap{overflow:auto; border:1px solid var(--stroke); border-radius:12px}
.Ctable{width:100%; border-collapse:collapse; background:#fff}
.Ctable th,.Ctable td{border-bottom:1px solid #eef2f7; padding:10px 12px; vertical-align:middle; white-space:nowrap}
.Ctable thead th{
  font-size:12px; text-transform:uppercase; letter-spacing:.6px; color:#6b7280; text-align:left;
  background:var(--accent); position:sticky; top:0; cursor:pointer
}
.Ctable tbody tr:hover{background:#f7fffd}
.badge{display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#e9f7f3; border:1px solid #d5f1ea; color:#145e50; font-weight:700; font-size:12px}
.badge.danger{background:var(--danger-bg); border-color:var(--danger-br); color:#991b1b}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace; color:#374151}
.smallmuted{font-size:12px; color:#6b7280}
.section-head{padding:10px 0; font-weight:700; color:#111827}

/* Pager */
.pager{display:flex; gap:8px; align-items:center; justify-content:flex-end; margin:12px 0; flex-wrap:wrap}
.pager input[type=text], .pager select{padding:8px 10px; border:1px solid var(--stroke); border-radius:10px; background:#fff}

/* Alerts */
.alert{margin:10px 0; padding:12px 14px; border:1px solid #fecaca; background:#fff1f2; color:#7f1d1d; border-radius:10px}

/* Print */
@media print{
  .topbar, #upnav, .filters .fg:last-child, .pager, .head div[style] { display:none !important; }
  body{background:#fff}
  .card, .table-wrap{border:none; box-shadow:none}
  .Ctable thead th{position:static}
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:40px;width:auto;margin-right:12px;background:#fff;padding:4px 8px;border-radius:6px;">
    </a>
  <div class="brand"><span class="dot"></span><span>EHR • ჟურნალი</span></div>
  <div class="user-menu-wrap">
    <div class="user-btn" id="userBtn" aria-haspopup="true" aria-expanded="false">მომხმარებელი ▾</div>
    <div class="user-dropdown" id="userDropdown" role="menu" aria-label="User">
      <a href="profile.php" role="menuitem">პროფილი</a>
      <a href="logout.php" role="menuitem">გასვლა</a>
    </div>
  </div>
</div>

<!-- NAV TABS -->
<nav id="upnav" class="upnav" role="navigation" aria-label="Secondary navigation">
  <a href="dashboard.php" class="<?= $cur=='dashboard.php' ? 'active' : '' ?>">მთავარი</a>
  <a href="doctors.php"   class="<?= $cur=='doctors.php'   ? 'active' : '' ?>">HR</a>
  <a href="journal.php"   class="<?= $cur=='journal.php'   ? 'active' : '' ?>">რეპორტი</a>
</nav>

<div class="container">
  <div class="card">
    <div class="head">
      <div class="title">ამბულატორიული ჟურნალი • ბოლო სერვისით</div>
      <div class="smallmuted">სულ: <b class="mono"><?= (int)$total ?></b></div>
      <div style="margin-left:auto;display:flex;gap:8px">
        <a class="btn ghost" href="?<?= http_build_query(array_merge($_GET,['action'=>'export','page'=>1,'pagesize'=>999999])) ?>" title="Export to Excel">Excel</a>
        <a class="btn ghost" href="?<?= http_build_query(array_merge($_GET,['action'=>'export_csv','page'=>1,'pagesize'=>999999])) ?>" title="Export to CSV">CSV</a>
        <button class="btn ghost" id="btnPrint" type="button" title="ბეჭდვა">ბეჭდვა</button>
        <button class="btn ghost" id="btnClear" type="button" title="გასუფთავება">გასუფთავება</button>
      </div>
    </div>

    <?php if ($ERR && APP_DEBUG): ?>
      <div class="body"><div class="alert">DB Notice: <?= e($ERR) ?></div></div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" id="fltForm" class="filters">
      <input type="hidden" name="sort" value="<?= e($orderBy) ?>">
      <input type="hidden" name="dir"  value="<?= $dir==='ASC'?'asc':'desc' ?>">

      <div class="fg" style="grid-column:span 2">
        <label>რეგ. თარიღი — დან</label>
        <input type="date" name="repbr_date1" value="<?= e($reg_from) ?>">
      </div>
      <div class="fg" style="grid-column:span 2">
        <label>რეგ. თარიღი — მდე</label>
        <input type="date" name="repbr_date2" value="<?= e($reg_to) ?>">
      </div>
      <div class="fg" style="grid-column:span 2">
        <label>პირველადი/მეორადი</label>
        <select name="gadnodag">
          <option value="0" <?= $prim_sec==='' || $prim_sec==='0' ? 'selected' : '' ?>></option>
          <option value="1" <?= $prim_sec==='1' ? 'selected' : '' ?>>პირველადი</option>
          <option value="2" <?= $prim_sec==='2' ? 'selected' : '' ?>>მეორადი</option>
        </select>
      </div>
      <div class="fg" style="grid-column:span 3">
        <label>მკურნალი ექიმი</label>
        <select name="smvlki">
          <option value="" <?= $doctor_id===''?'selected':'' ?>></option>
          <?php foreach($doctors as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $doctor_id===(string)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg" style="grid-column:span 3">
        <label>დაბ. თარიღი — დან / მდე</label>
        <div style="display:flex;gap:6px">
          <input type="date" name="s_dab1" value="<?= e($dob_from) ?>">
          <input type="date" name="s_dab2" value="<?= e($dob_to) ?>">
        </div>
      </div>

      <div class="fg" style="grid-column:span 6">
        <label>ტექსტური ძებნა</label>
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px">
          <input type="text" name="m_rsno" placeholder="პირადი #" value="<?= e($personal) ?>">
          <input type="text" name="m_phon" placeholder="ტელ." value="<?= e($phone) ?>">
          <input type="text" name="m_fnam" placeholder="სახელი" value="<?= e($first_name) ?>">
          <input type="text" name="m_lnam" placeholder="გვარი" value="<?= e($last_name) ?>">
          <input type="text" name="m_addrs" placeholder="მისამართი" value="<?= e($address) ?>" style="grid-column:span 2">
        </div>
      </div>

      <div class="fg" style="grid-column:span 2;align-self:end">
        <button class="btn" type="submit" id="btnSearch">ძებნა</button>
      </div>
    </form>

    <div class="body">
      <div class="section-head">პაციენტების სია <span class="smallmuted">/ ბოლო კონსულტაციით</span></div>
      <div class="table-wrap">
        <table class="Ctable" id="tbl" role="table" aria-label="Patients list">
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">პირადი #</th>
              <th scope="col" data-k="p.first_name" aria-sort="none">სახელი <span class="sort-ind" id="s_p.first_name"></span></th>
              <th scope="col" data-k="p.last_name" aria-sort="none">გვარი <span class="sort-ind" id="s_p.last_name"></span></th>
              <th scope="col">მამის სახელი</th>
              <th scope="col">ბარათის #</th>
              <th scope="col">მისამართი</th>
              <th scope="col">ტელ.</th>
              <th scope="col" data-k="p.birthdate" aria-sort="none">დაბ. თარიღი <span class="sort-ind" id="s_p.birthdate"></span></th>
              <th scope="col">ასაკი</th>
              <th scope="col" data-k="p.registration_date" aria-sort="descending">აღრიცხვის თარიღი <span class="sort-ind" id="s_p.registration_date"></span></th>
              <th scope="col">სადაზღვეო</th>
              <th scope="col">მკურნალი ექიმი</th>
              <th scope="col" data-k="last_service_at" aria-sort="none">კონსულტაცია <span class="sort-ind" id="s_last_service_at"></span></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="14" style="text-align:center;padding:18px">ჩანაწერი არ არის</td></tr>
          <?php else: $i=(($page-1)*$pagesize); foreach($rows as $r):
              $svc = (string)($r['service_name'] ?? '');
              $code = service_code($svc);
              $isAK03 = (strcasecmp($code,'AK03')===0) || (stripos($svc,'AK03')!==false);
              $age = age_text($r['birthdate'] ?? '');
          ?>
            <tr>
              <td class="mono"><?= ++$i ?></td>
              <td class="mono"><?= e($r['personal_id']) ?></td>
              <td><a class="rowlink" href="patient_view.php?id=<?= (int)$r['id'] ?>"><?= e($r['first_name']) ?></a></td>
              <td><?= e($r['last_name']) ?></td>
              <td><?= e($r['father_name']) ?></td>
              <td class="mono"><?= e($r['card_no'] ?? '') ?></td>
              <td><?= e($r['address']) ?></td>
              <td class="mono"><?= e($r['phone']) ?></td>
              <td class="mono"><?= e($r['birthdate']) ?></td>
              <td><?= e($age) ?></td>
              <td class="mono"><?= e($r['registration_date']) ?></td>
              <td><?= e($r['insurance_name'] ?? '') ?></td>
              <td><?php if (!empty($r['doctor_full'])): ?><span class="badge"><?= e($r['doctor_full']) ?></span><?php endif; ?></td>
              <td>
                <?php if ($svc!==''): ?>
                  <span class="badge <?= $isAK03 ? 'danger' : '' ?>" title="<?= e($code ?: '—') ?>">
                    <?= e($svc) ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pager" role="navigation" aria-label="Pagination">
        <form method="get" action="journal.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?php foreach ($_GET as $k=>$v) { if (in_array($k,['page'],true)) continue; echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; } ?>
          <button class="btn ghost" type="submit" name="page" value="<?= max(1,$page-1) ?>" aria-label="Previous page">‹ წინა</button>
          <span class="smallmuted">გვერდი</span>
          <input type="text" name="page" value="<?= (int)$page ?>" style="width:72px" aria-label="Current page">
          <span class="smallmuted">/ <?= (int)$total_pages ?></span>
          <select name="pagesize" id="selPageSize" aria-label="Rows per page">
            <?php foreach([25,50,100,200,500] as $ps): ?>
              <option value="<?= $ps ?>" <?= $pagesize==$ps?'selected':'' ?>><?= $ps ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn ghost" type="submit" name="page" value="<?= min($total_pages,$page+1) ?>" aria-label="Next page">შემდეგი ›</button>
          <span class="smallmuted" aria-live="polite">სულ: <b class="mono"><?= (int)$total ?></b></span>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// --- User menu ---
const userBtn = document.getElementById('userBtn');
const userDropdown = document.getElementById('userDropdown');
if (userBtn) userBtn.addEventListener('click', ()=>{
  const open = userDropdown.style.display==='block';
  userDropdown.style.display = open ? 'none' : 'block';
  userBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
});
document.addEventListener('click', (e)=>{
  const wrap = document.querySelector('.user-menu-wrap');
  if (wrap && !wrap.contains(e.target)) { userDropdown.style.display='none'; userBtn?.setAttribute('aria-expanded','false'); }
});

// --- Sorting by header click (with aria-sort updates) ---
document.querySelectorAll('#tbl thead th[data-k]').forEach(th=>{
  th.addEventListener('click', ()=>{
    const k = th.getAttribute('data-k');
    const url = new URL(window.location.href);
    const curK = url.searchParams.get('sort') || 'p.registration_date';
    const curDir = (url.searchParams.get('dir') || 'desc').toLowerCase();
    let nextDir = 'asc';
    if (curK === k) nextDir = (curDir === 'asc' ? 'desc' : 'asc');
    url.searchParams.set('sort', k);
    url.searchParams.set('dir', nextDir);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
  });
});
(function(){
  const url = new URL(window.location.href);
  const k = url.searchParams.get('sort') || 'p.registration_date';
  const dir = (url.searchParams.get('dir') || 'desc').toLowerCase();
  const safeId = 's_'+k.replace(/([:.])/g,'\\$1');
  const el = document.querySelector('#'+safeId);
  if (el) el.textContent = (dir==='asc' ? '▲' : '▼');

  // set aria-sort
  document.querySelectorAll('#tbl thead th[data-k]').forEach(th=>{
    const kk = th.getAttribute('data-k');
    th.setAttribute('aria-sort', kk===k ? (dir==='asc'?'ascending':'descending') : 'none');
  });
})();

// --- Clear filters ---
document.getElementById('btnClear').addEventListener('click', function(){
  const form = document.getElementById('fltForm');
  [...form.elements].forEach(el=>{
    if (!el.name) return;
    if (['page','pagesize','action','sort','dir'].includes(el.name)) return;
    if (el.tagName==='SELECT') el.selectedIndex = 0;
    if (el.type==='date' || el.type==='text') el.value = '';
  });
  const h=document.createElement('input'); h.type='hidden'; h.name='page'; h.value='1'; form.appendChild(h);
  form.submit();
});

// --- Print ---
document.getElementById('btnPrint').addEventListener('click', ()=>{ window.print(); });

// --- Submit on Enter in text filters ---
document.querySelectorAll('.filters input[type="text"]').forEach(inp=>{
  inp.addEventListener('keydown',(e)=>{ if(e.key==='Enter'){ document.getElementById('btnSearch').click(); } });
});

// --- Auto submit on page size change ---
document.getElementById('selPageSize')?.addEventListener('change', ()=>{
  const form = document.querySelector('.pager form');
  if (!form) return;
  const h=document.createElement('input'); h.type='hidden'; h.name='page'; h.value='1'; form.appendChild(h);
  form.submit();
});
</script>
</body>
</html>
