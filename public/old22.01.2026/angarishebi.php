<?php
// angarishebi.php — Donor-backed accounts dashboard (unique per patient)

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__ . '/../config/config.php'; // must define $pdo (PDO::ERRMODE_EXCEPTION)

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$cur = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// RBAC shim so `$can($file)` is always callable (remove if you already have it)
if (!isset($can) || !is_callable($can)) {
  $can = fn(string $file): bool => function_exists('can') ? (bool)can($file) : true;
}

// Tabs + icons (Font Awesome 6 class names)
$tabs = [
  ['file' => 'dashboard.php',      'label' => 'რეგისტრაცია',       'icon' => 'fa-user-plus'],
  ['file' => 'patient_hstory.php', 'label' => 'პაციენტის ისტორია', 'icon' => 'fa-notes-medical'],
  ['file' => 'nomenklatura.php',   'label' => 'ნომენკლატურა',      'icon' => 'fa-list'],
  ['file' => 'angarishebi.php',    'label' => 'ანგარიშები',        'icon' => 'fa-file-invoice'],
];
// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: interest-cohort=()');
// ✅ CSP: allow Google Fonts stylesheet + font files
header("Content-Security-Policy: " .
  "default-src 'self'; " .
  "img-src 'self' data:; " .
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
  "font-src 'self' https://fonts.gstatic.com data:; " .
  "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com; " .
  "script-src 'self' 'unsafe-inline'; " .
  "frame-ancestors 'self'; " .
  "base-uri 'self'; " .
  "form-action 'self'"
);
// Current file (for active state)

// კონსტანტები — დააბრკოლე ხელახალი გამოცხადება
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (!defined('PAGE_SIZE_DEFAULT')) define('PAGE_SIZE_DEFAULT', 50);
if (!defined('PAGE_SIZE_MAX')) define('PAGE_SIZE_MAX', 200);

// DB charset
try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) { /* ignore */ }

// ---------- helpers ----------
function money($n){ $n = is_null($n)?0:(float)$n; return number_format($n, 2, '.', ','); }
function dstr($d){ if(!$d || $d==='0000-00-00' || $d==='0000-00-00 00:00:00') return ''; return substr((string)$d,0,10); }
function likeWrap($s){ return '%'.$s.'%'; }

// Strict date validator (reject 0000-00-00 and invalids)
function safeDate($s){
  $s = trim((string)$s);
  if ($s === '' || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $s);
  $err = DateTime::getLastErrors();
  if ($dt && $err['warning_count']===0 && $err['error_count']===0) return $dt->format('Y-m-d');
  return '';
}

function clampInt($v,$min,$max){ $v=(int)$v; if($v<$min)$v=$min; if($v>$max)$v=$max; return $v; }
function entryShort($entry){
  $e = trim((string)$entry);
  if ($e === '') return '';
  $map = [
    'ამბულატორია' => 'ამბუ',
    'გადაუდებელი' => 'გადა',
    'საგანგებო'   => 'საგა',
    'სტაციონარი'  => 'სტაც',
    'დღიური'      => 'დღიური',
    'თერაპია'     => 'თერ',
  ];
  return $map[$e] ?? $e;
}
function tableHasColumn(PDO $pdo, string $table, string $column): bool {
  try {
    $table = preg_replace('/[^a-zA-Z0-9_]/','', $table);
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch();
  } catch (Throwable $e){ return false; }
}

// Optional columns
$HAS_BRANCH         = tableHasColumn($pdo, 'patients', 'branch_id');
$HAS_DISCHARGE_DATE = tableHasColumn($pdo, 'patients', 'discharge_date');
$HAS_PAY_METHOD     = tableHasColumn($pdo, 'payments', 'method');
$HAS_PAY_DONOR_ID   = tableHasColumn($pdo, 'payments', 'donor_id');
$HAS_PAY_CREATED    = tableHasColumn($pdo, 'payments', 'created_at');

// pick patient_services amount column
$SVC_AMOUNT_COL = 'sum';
foreach (['sum','amount','total'] as $cand) {
  if (tableHasColumn($pdo, 'patient_services', $cand)) { $SVC_AMOUNT_COL = $cand; break; }
}

// ---------- SQL builders ----------
function buildSubSvc(string $amtCol): string {
  $amtCol = preg_replace('/[^a-zA-Z0-9_]/','', $amtCol);
  return "
    SELECT ps.patient_id, SUM(CAST(COALESCE(ps.`$amtCol`,0) AS DECIMAL(16,2))) AS svc_total
    FROM patient_services ps
    GROUP BY ps.patient_id
  ";
}

// Sanitize zero dates via TO_DAYS()
function buildSubCred(): string {
  return "
    SELECT
      x.patient_id,
      SUM(CAST(COALESCE(x.amount,0) AS DECIMAL(16,2))) AS credit_total,
      TRIM(BOTH ', ' FROM GROUP_CONCAT(DISTINCT NULLIF(x.donor,'') ORDER BY x.donor SEPARATOR ', ')) AS donors_txt,
      MAX(CASE WHEN TO_DAYS(x.guarantee_date)=0 THEN NULL ELSE x.guarantee_date END) AS max_gdate,
      MAX(CASE WHEN TO_DAYS(x.validity_date)=0  THEN NULL ELSE x.validity_date  END) AS max_vdate
    FROM (
      SELECT 
        pg.patient_id,
        pg.amount,
        CONVERT(pg.donor USING utf8mb4) COLLATE utf8mb4_unicode_ci AS donor,
        pg.guarantee_date,
        pg.validity_date
      FROM patient_guarantees pg
      UNION ALL
      SELECT
        p.id AS patient_id,
        COALESCE(p.amount,0) AS amount,
        CONVERT(p.donor USING utf8mb4) COLLATE utf8mb4_unicode_ci AS donor,
        p.guarantee_date,
        p.validity_date
      FROM patients p
      WHERE (NULLIF(p.donor,'') IS NOT NULL OR COALESCE(p.amount,0) <> 0)
    ) x
    GROUP BY x.patient_id
  ";
}
function buildSubPayAll(): string {
  return "
    SELECT patient_id, SUM(CAST(COALESCE(amount,0) AS DECIMAL(16,2))) AS paid_total
    FROM payments
    GROUP BY patient_id
  ";
}
function buildSubPayDonor(bool $hasMethod, bool $hasDonorId): string {
  $conds = [];
  if ($hasDonorId) { $conds[] = "donor_id IS NOT NULL"; }
  if ($hasMethod)  { $conds[] = "LOWER(TRIM(`method`))='donor'"; }
  if (!$conds)     { $conds[] = "0=1"; }
  $where = implode(' OR ', $conds);
  return "
    SELECT patient_id,
           SUM(CAST(COALESCE(amount,0) AS DECIMAL(16,2))) AS paid_donor
    FROM payments
    WHERE $where
    GROUP BY patient_id
  ";
}

/**
 * Build aggregated query.
 */
function buildAggSQL(
  array $filters, array &$args, string $sort, string $dir,
  int $limit, int $offset, bool $forCount=false,
  bool $hasBranch=false, string $svcAmtCol='sum',
  bool $hasMethod=false, bool $hasDonorId=false,
  bool $hasDischarge=false
): string {

  $svc       = buildSubSvc($svcAmtCol);
  $cred      = buildSubCred();
  $pay_all   = buildSubPayAll();
  $pay_donor = buildSubPayDonor($hasMethod, $hasDonorId);

  $aggBase = "
    SELECT
      p.id,
      p.personal_id,
      p.first_name,
      p.last_name,
      p.registration_date,
      p.entry_type
      ".($hasBranch ? ", p.branch_id" : "")."
      ".($hasDischarge ? ", p.discharge_date" : "").",
      cred.donors_txt,
      cred.max_gdate,
      cred.max_vdate,
      COALESCE(svc.svc_total,0)     AS svc_total,
      COALESCE(cred.credit_total,0) AS credit_total,
      COALESCE(pay_a.paid_total,0)  AS paid_total,
      COALESCE(pay_d.paid_donor,0)  AS paid_donor
    FROM patients p
      INNER JOIN ( $cred ) cred ON cred.patient_id = p.id
      LEFT  JOIN ( $svc )  svc  ON svc.patient_id  = p.id
      LEFT  JOIN ( $pay_all ) pay_a ON pay_a.patient_id = p.id
      LEFT  JOIN ( $pay_donor ) pay_d ON pay_d.patient_id = p.id
  ";

  $outerWhere = [];
  if ($filters['first_name']!==''){ $outerWhere[]="agg.first_name LIKE ?"; $args[] = likeWrap($filters['first_name']); }
  if ($filters['last_name']!==''){  $outerWhere[]="agg.last_name  LIKE ?";  $args[] = likeWrap($filters['last_name']); }
  if ($filters['personal_id']!==''){ $outerWhere[]="agg.personal_id LIKE ?"; $args[] = likeWrap($filters['personal_id']); }
  if (!empty($filters['branch_id']) && $hasBranch){ $outerWhere[]="agg.branch_id = ?"; $args[] = (int)$filters['branch_id']; }

  $reg_from = safeDate($filters['reg_from']); if ($reg_from){ $outerWhere[]="agg.registration_date >= ?"; $args[] = $reg_from; }
  $reg_to   = safeDate($filters['reg_to']);   if ($reg_to){   $outerWhere[]="agg.registration_date <= ?"; $args[] = $reg_to; }
  $g_from   = safeDate($filters['g_from']);   if ($g_from){   $outerWhere[]="agg.max_gdate >= ?";        $args[] = $g_from; }
  $g_to     = safeDate($filters['g_to']);     if ($g_to){     $outerWhere[]="agg.max_gdate <= ?";        $args[] = $g_to; }

  if ($filters['donor']!==''){    $outerWhere[]="agg.donors_txt LIKE ?"; $args[] = likeWrap($filters['donor']); }
  if ($filters['entry_type']!==''){ $outerWhere[]="agg.entry_type = ?";   $args[] = $filters['entry_type']; }

  // donor-focused filters (server-side)
  if (!empty($filters['only_has_donor_left'])) {
    // left_to_pay = LEAST(credit_total, svc_total) - paid_donor > 0
    $outerWhere[] = "(LEAST(COALESCE(agg.credit_total,0), COALESCE(agg.svc_total,0)) - COALESCE(agg.paid_donor,0)) > 0";
  }
  if (!empty($filters['only_has_debt'])) {
    $outerWhere[] = "(COALESCE(agg.svc_total,0) - COALESCE(agg.credit_total,0)) > 0";
  }
  if (!empty($filters['only_expired'])) {
    $outerWhere[] = "(agg.max_vdate IS NOT NULL AND agg.max_vdate < CURDATE())";
  }

  $whereSql  = $outerWhere ? ('WHERE '.implode(' AND ',$outerWhere)) : '';

  // Sorting map — default to credit_total if unknown
  $sortMap = [
    'last_name'         => 'agg.last_name',
    'first_name'        => 'agg.first_name',
    'personal_id'       => 'agg.personal_id',
    'registration_date' => 'agg.registration_date',
    'discharge_date'    => $hasDischarge ? 'agg.discharge_date' : 'agg.registration_date',
    'credit_total'      => 'agg.credit_total',
    'applied'           => 'LEAST(agg.credit_total, agg.svc_total)',
    'paid_donor'        => 'agg.paid_donor',
    'max_vdate'         => 'agg.max_vdate',
    'max_gdate'         => 'agg.max_gdate',
    // new: sort by left_to_pay
    'left_to_pay'       => '(LEAST(agg.credit_total, agg.svc_total) - agg.paid_donor)',
  ];
  $sortCol = $sortMap[$sort] ?? 'agg.credit_total';
  $dirSql  = ($dir === 'asc') ? 'ASC' : 'DESC';
  $orderSql = "ORDER BY {$sortCol} {$dirSql}, agg.last_name ASC, agg.first_name ASC, agg.id ASC";

  if ($forCount) {
    return "SELECT COUNT(*) AS cnt FROM ( $aggBase ) agg $whereSql";
  }

  $limitSql = "LIMIT $limit OFFSET $offset";
  return "
    SELECT
      agg.id, agg.personal_id, agg.first_name, agg.last_name, agg.registration_date, agg.entry_type
      ".($hasBranch ? ", agg.branch_id" : "")."
      ".($hasDischarge ? ", agg.discharge_date" : "").",
      agg.donors_txt, agg.max_gdate, agg.max_vdate,
      agg.svc_total, agg.credit_total, agg.paid_total, agg.paid_donor
    FROM ( $aggBase ) agg
    $whereSql
    $orderSql
    $limitSql
  ";
}

function filtersFromGET(bool $hasBranch): array {
  return [
    'first_name'         => trim($_GET['first_name']   ?? ''),
    'last_name'          => trim($_GET['last_name']    ?? ''),
    'personal_id'        => trim($_GET['personal_id']  ?? ''),
    'reg_from'           => trim($_GET['reg_from']     ?? ''),
    'reg_to'             => trim($_GET['reg_to']       ?? ''),
    'donor'              => trim($_GET['donor']        ?? ''),
    'g_from'             => trim($_GET['g_from']       ?? ''),
    'g_to'               => trim($_GET['g_to']         ?? ''),
    'entry_type'         => trim($_GET['entry_type']   ?? ''),
    'only_has_donor_left'=> (isset($_GET['only_has_donor_left']) && $_GET['only_has_donor_left'] === '1'),
    'only_has_debt'      => (isset($_GET['only_has_debt']) && $_GET['only_has_debt'] === '1'),
    'only_expired'       => (isset($_GET['only_expired']) && $_GET['only_expired'] === '1'),
    'branch_id'          => $hasBranch ? (int)($_GET['branch_id'] ?? 0) : 0,
  ];
}

// ---------- AJAX ----------
$action = $_GET['action'] ?? '';

if ($action === 'list') {
  header('Content-Type: application/json; charset=utf-8');

  $filters = filtersFromGET($HAS_BRANCH);

  $page = max(1, (int)($_GET['page'] ?? 1));
  $ps   = clampInt(($_GET['pagesize'] ?? PAGE_SIZE_DEFAULT), 10, PAGE_SIZE_MAX);
  $offset = ($page-1)*$ps;

  $sort = strtolower(trim($_GET['sort'] ?? 'credit_total'));
  $dir  = strtolower(trim($_GET['dir']  ?? 'desc'));
  if (!in_array($dir, ['asc','desc'], true)) $dir='desc';

  try {
    // total rows
    $argsC = [];
    $sqlC  = buildAggSQL($filters, $argsC, $sort, $dir, 0, 0, true, $HAS_BRANCH, $SVC_AMOUNT_COL, $HAS_PAY_METHOD, $HAS_PAY_DONOR_ID, $HAS_DISCHARGE_DATE);
    $stc   = $pdo->prepare($sqlC);
    $stc->execute($argsC);
    $totalRows = (int)($stc->fetchColumn() ?: 0);

    // page data
    $args = [];
    $sql  = buildAggSQL($filters, $args, $sort, $dir, $ps, $offset, false, $HAS_BRANCH, $SVC_AMOUNT_COL, $HAS_PAY_METHOD, $HAS_PAY_DONOR_ID, $HAS_DISCHARGE_DATE);
    $st   = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // page totals for donor-focused columns
    $tot_credit=$tot_applied=$tot_paid_donor=$tot_left_to_pay=0.0;
    foreach ($rows as &$r) {
      $r['entry_short'] = entryShort($r['entry_type']);
      $credit = (float)($r['credit_total'] ?? 0);
      $svc    = (float)($r['svc_total'] ?? 0);
      $paid_d = (float)($r['paid_donor'] ?? 0);
      $applied = min(max($credit,0), max($svc,0)); // min(credit_total, svc_total)
      $left_to_pay = max($applied - $paid_d, 0);   // owed to donor

      $r['applied']      = $applied;
      $r['left_to_pay']  = $left_to_pay;
      $r['usage_pct']    = $applied>0 ? max(0,min(100, round(($paid_d/$applied)*100))) : 0;

      $today = date('Y-m-d');
      $v = $r['max_vdate'] ?? '';
      $r['is_active']  = ($v && $v !== '0000-00-00' && $v >= $today) ? 1 : 0;

      $tot_credit       += $credit;
      $tot_applied      += $applied;
      $tot_paid_donor   += $paid_d;
      $tot_left_to_pay  += $left_to_pay;
    }

    echo json_encode([
      'status'   => 'ok',
      'rows'     => $rows,
      'totals'   => [
        'credit_total'    => $tot_credit,
        'applied_total'   => $tot_applied,
        'paid_donor'      => $tot_paid_donor,
        'left_to_pay'     => $tot_left_to_pay,
        'count'           => count($rows),
      ],
      'page'     => $page,
      'pagesize' => $ps,
      'total'    => $totalRows,
      'sort'     => $sort,
      'dir'      => $dir,
      'has_branch'    => $HAS_BRANCH ? 1 : 0,
      'has_discharge' => $HAS_DISCHARGE_DATE ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    if (APP_DEBUG) { error_log('[angarishebi:list] '.$e->getMessage()); }
    http_response_code(500);
    echo json_encode([
      'status'=>'error',
      'message'=> APP_DEBUG ? $e->getMessage() : 'სერვერის შეცდომა',
    ], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'grand_totals') {
  header('Content-Type: application/json; charset=utf-8');
  $filters = filtersFromGET($HAS_BRANCH);

  try {
    $args = [];
    $sql = buildAggSQL($filters, $args, 'credit_total', 'desc', PHP_INT_MAX, 0, false, $HAS_BRANCH, $SVC_AMOUNT_COL, $HAS_PAY_METHOD, $HAS_PAY_DONOR_ID, $HAS_DISCHARGE_DATE);
    $st  = $pdo->prepare($sql);
    $st->execute($args);
    $tot_credit=$tot_applied=$tot_paid_donor=$tot_left_to_pay=0.0;
    while($r = $st->fetch(PDO::FETCH_ASSOC)){
      $credit = (float)($r['credit_total'] ?? 0);
      $svc    = (float)($r['svc_total'] ?? 0);
      $paid_d = (float)($r['paid_donor'] ?? 0);
      $applied = min(max($credit,0), max($svc,0));
      $left_to_pay = max($applied - $paid_d, 0);
      $tot_credit       += $credit;
      $tot_applied      += $applied;
      $tot_paid_donor   += $paid_d;
      $tot_left_to_pay  += $left_to_pay;
    }
    echo json_encode([
      'status'=>'ok',
      'totals'=>[
        'credit_total'  => $tot_credit,
        'applied_total' => $tot_applied,
        'paid_donor'    => $tot_paid_donor,
        'left_to_pay'   => $tot_left_to_pay,
      ]
    ], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e){
    if (APP_DEBUG) { error_log('[angarishebi:grand] '.$e->getMessage()); }
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>APP_DEBUG?$e->getMessage():'სერვერის შეცდომა'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'patient_details') {
  header('Content-Type: application/json; charset=utf-8');
  $pid = (int)($_GET['patient_id'] ?? 0);
  if ($pid<=0){ echo json_encode(['status'=>'error','message'=>'არასწორი პაციენტი']); exit; }

  try {
    // Patient basic
    $selCols = "id, first_name, last_name, personal_id, entry_type, registration_date";
    if ($HAS_DISCHARGE_DATE) { $selCols .= ", discharge_date"; }
    $st = $pdo->prepare("SELECT $selCols FROM patients WHERE id=?");
    $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if(!$p){ echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ მოიძებნა']); exit; }

    // Donor guarantees (order by sanitized date)
    if ($HAS_PAY_DONOR_ID) {
      $don = $pdo->prepare("
        SELECT pg.id, pg.donor, pg.amount, pg.guarantee_date, pg.validity_date,
               COALESCE((SELECT SUM(amount) FROM payments WHERE patient_id=? AND donor_id = pg.id),0) AS paid_by_this_donor
        FROM patient_guarantees pg
        WHERE pg.patient_id = ?
        ORDER BY (CASE WHEN TO_DAYS(pg.guarantee_date)=0 THEN NULL ELSE pg.guarantee_date END) DESC, pg.id DESC
      ");
      $don->execute([$pid, $pid]);
    } else {
      $don = $pdo->prepare("
        SELECT pg.id, pg.donor, pg.amount, pg.guarantee_date, pg.validity_date,
               0 AS paid_by_this_donor
        FROM patient_guarantees pg
        WHERE pg.patient_id = ?
        ORDER BY (CASE WHEN TO_DAYS(pg.guarantee_date)=0 THEN NULL ELSE pg.guarantee_date END) DESC, pg.id DESC
      ");
      $don->execute([$pid]);
    }
    $donors = $don->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Payments (schema-aware: method/created_at optional)
    $cols = ["id","amount"];
    if ($HAS_PAY_DONOR_ID) $cols[] = "donor_id";
    else $cols[] = "NULL AS donor_id";
    if ($HAS_PAY_METHOD)  $cols[] = "`method`";
    if ($HAS_PAY_CREATED) $cols[] = "created_at";
    $sel = implode(',', $cols);

    $pay = $pdo->prepare("
      SELECT $sel
      FROM payments
      WHERE patient_id = ?
      ORDER BY ".($HAS_PAY_CREATED ? "created_at DESC, " : "")."id DESC
      LIMIT 200
    ");
    $pay->execute([$pid]);
    $payments = $pay->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Services summary
    $svc = $pdo->prepare("
      SELECT COUNT(*) AS cnt, COALESCE(SUM(`{$SVC_AMOUNT_COL}`),0) AS svc_total
      FROM patient_services
      WHERE patient_id = ?
    ");
    $svc->execute([$pid]);
    $svcRow = $svc->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'svc_total'=>0];

    echo json_encode([
      'status'=>'ok',
      'patient'=>$p,
      'donors'=>$donors,
      'payments'=>$payments,
      'services'=>$svcRow,
    ], JSON_UNESCAPED_UNICODE);

  } catch (Throwable $e){
    if (APP_DEBUG) { error_log('[angarishebi:details] '.$e->getMessage()); }
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>APP_DEBUG?$e->getMessage():'სერვერის შეცდომა'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'export_excel') {
  // export all filtered rows as an Excel-compatible HTML table (.xls)
  $filters = filtersFromGET($HAS_BRANCH);
  $args = [];
  $sql  = buildAggSQL(
    $filters, $args,
    'credit_total', 'desc',
    PHP_INT_MAX, 0, false,
    $HAS_BRANCH, $SVC_AMOUNT_COL, $HAS_PAY_METHOD, $HAS_PAY_DONOR_ID, $HAS_DISCHARGE_DATE
  );

  $filename = 'angarishebi_' . date('Ymd_His') . '.xls';

  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Pragma: public');
  header('Cache-Control: max-age=0');

  $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

  echo "<html><head><meta charset=\"UTF-8\"></head><body>";
  echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"3\">";
  echo "<thead><tr>";
  $heads = [
    '#','სტაც #','სახელი','გვარი','პირადი #','რეგისტრაციის თარიღი',
    'გაწერის თარიღი','დონორი','წერილის თარიღი','ვადა',
    'თანხა','ჩამოკრედიტებული','გადახდილი','დარჩენილი (დონორი)'
  ];
  foreach ($heads as $h) echo "<th>".$e($h)."</th>";
  echo "</tr></thead><tbody>";

  try {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $i=0;
    while($r = $st->fetch(PDO::FETCH_ASSOC)){
      $i++;
      $entry_sh   = entryShort($r['entry_type'] ?? '');
      $discharge  = $HAS_DISCHARGE_DATE ? dstr($r['discharge_date'] ?? '') : '';
      $credit     = (float)($r['credit_total'] ?? 0);
      $svc        = (float)($r['svc_total'] ?? 0);
      $paid_donor = (float)($r['paid_donor'] ?? 0);
      $applied    = min(max($credit,0), max($svc,0));
      $left_to_pay= max($applied - $paid_donor, 0);

      echo "<tr>";
      echo "<td>".$i."</td>";
      echo "<td>".$e($entry_sh)."</td>";
      echo "<td>".$e($r['first_name']     ?? '')."</td>";
      echo "<td>".$e($r['last_name']      ?? '')."</td>";
      echo "<td style=\"mso-number-format:'@';\">".$e($r['personal_id'] ?? '')."</td>";
      echo "<td>".$e(dstr($r['registration_date'] ?? ''))."</td>";
      echo "<td>".$e($discharge)."</td>";
      echo "<td>".$e($r['donors_txt']     ?? '')."</td>";
      echo "<td>".$e(dstr($r['max_gdate'] ?? ''))."</td>";
      echo "<td>".$e(dstr($r['max_vdate'] ?? ''))."</td>";
      echo "<td style=\"mso-number-format:'#,##0.00';\">".money($credit)."</td>";
      echo "<td style=\"mso-number-format:'#,##0.00';\">".money($applied)."</td>";
      echo "<td style=\"mso-number-format:'#,##0.00';\">".money($paid_donor)."</td>";
      echo "<td style=\"mso-number-format:'#,##0.00';\">".money($left_to_pay)."</td>";
      echo "</tr>";
    }
  } catch (Throwable $e) {
    if (APP_DEBUG) { error_log('[angarishebi:export_excel] '.$e->getMessage()); }
  }

  echo "</tbody></table></body></html>";
  exit;
}

// ---------- PAGE ----------
?>
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<title>ანგარიშები • დონორები</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts - Noto Sans Georgian -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">

<style>
:root{--bg:#f9f8f2;--surface:#fff;--text:#222;--muted:#6b7280;--brand:#21c1a6;--stroke:#e5e7eb;--shadow:0 6px 18px rgba(0,0,0,.06);--warn:#f59e0b;--danger:#ef4444;--good:#10b981}
*{box-sizing:border-box}html,body{height:100%}body{margin:0;font-family:"Noto Sans Georgian",sans-serif;background:var(--bg);color:var(--text)}
.topbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:var(--brand);color:#fff;box-shadow:var(--shadow)}
.brand{display:flex;gap:10px;font-weight:800}.brand .dot{width:10px;height:10px;border-radius:50%;background:#fff}
.container{max-width:1750px;margin:20px auto;padding:0 18px}
.tabs{list-style:none;display:flex;gap:6px;padding-left:0;margin:0 0 14px;border-bottom:2px solid #ddd}
.tabs a{padding:10px 18px;background:var(--brand);color:#fff;border-top-left-radius:7px;border-top-right-radius:7px;text-decoration:none}
.tabs a.active,.tabs a:hover{background:#fff;color:var(--brand)}
.subtabswrap{max-width:1750px;margin:0 auto 6px;padding:0 24px}
.subtabs{list-style:none;display:flex;gap:6px;margin:0 0 12px;padding:0;border-bottom:2px solid #e6e6e6}
.subtabs a{display:inline-block;padding:8px 14px;text-decoration:none;border-top-left-radius:8px;border-top-right-radius:8px;background:var(--brand);color:#fff;font-weight:600}
.subtabs a:hover,.subtabs a.active{background:#fff;color:var(--brand);border:1px solid #cfeee8;border-bottom-color:#fff}
.card{background:#fff;border:1px solid var(--stroke);border-radius:14px;box-shadow:var(--shadow)}
.card .head{position:sticky;top:56px;background:#fff;padding:14px 16px;border-bottom:1px solid var(--stroke);display:flex;align-items:center;gap:12px;flex-wrap:wrap;z-index:5}
.head .title{font-size:16px;font-weight:800;color:#0f172a;flex:1}
.card .body{padding:16px}
.filters{display:grid;grid-template-columns:repeat(8,1fr);gap:10px}
@media (max-width:1400px){.filters{grid-template-columns:repeat(4,1fr)}}
@media (max-width:800px){.filters{grid-template-columns:1fr}}
.f-group label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}
input[type=text],select,button{font-size:14px}
input[type=text],select{width:100%;padding:9px 10px;border:1px solid var(--stroke);border-radius:10px;background:#fff;outline:none}
input[type=text]:focus,select:focus{border-color:#9adfd1;box-shadow:0 0 0 4px rgba(33,193,166,.15)}
.checks{display:flex;gap:12px;align-items:center}
.checks label{display:flex;gap:8px;align-items:center;font-size:13px;color:#334155}
.btn{padding:10px 14px;border-radius:10px;border:1px solid #0bb192;background:#10b981;color:#fff;font-weight:700;cursor:pointer}
.btn:hover{background:#0bb192}
.btn.ghost{background:#fff;color:#0bb192;border-color:#9adfd1}
.btn.ghost:hover{background:#eefaf6}
.table-scroll{overflow:auto;border:1px solid var(--stroke);border-radius:12px}
.Ctable{width:100%;border-collapse:collapse;background:#fff}
.Ctable th,.Ctable td{border-bottom:1px solid #eef2f7;padding:10px 12px;vertical-align:middle;white-space:nowrap}
.Ctable thead th{font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;text-align:left;background:#f1fbf8;position:sticky;top:0;cursor:pointer} /* ✅ sticky header */
.Ctable tbody tr:hover{background:#f7fffd}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;color:#374151}
.right{text-align:right}.center{text-align:center}.muted{color:#6b7280}
.pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#e9f7f3;border:1px solid #d5f1ea;color:#145e50;font-weight:700;font-size:12px}
.totals{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.totals .item{background:#fff;border:1px solid var(--stroke);border-radius:10px;padding:8px 12px;font-weight:800}
.totals .hint{font-size:12px;color:#64748b}
.pager{display:flex;gap:8px;align-items:center;margin-left:auto}
.pager input{width:70px}
/* keep numeric & date columns aligned and readable */
.Ctable th[data-k="credit_total"], .Ctable th[data-k="applied"], .Ctable th[data-k="paid_donor"], .Ctable th[data-k="left_to_pay"], .Ctable td.right { min-width: 120px; }
.Ctable th[data-k="personal_id"] { min-width: 120px; }
.Ctable th[data-k="registration_date"],
.Ctable th[data-k="discharge_date"],
.Ctable th[data-k="max_gdate"],
.Ctable th[data-k="max_vdate"] { min-width: 130px; }

.sort-ind{margin-left:6px;font-size:11px;color:#0f766e}
.badge{display:inline-block;padding:3px 7px;border-radius:8px;font-size:11px;border:1px solid transparent}
.badge.warn{background:#fff8e6;border-color:#fde68a;color:#92400e}
.badge.danger{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.badge.good{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.progress{height:6px;background:#f1f5f9;border-radius:999px;overflow:hidden;width:100%}
.progress > span{display:block;height:100%;background:#10b981} /* fill color */
.stack{display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:140px}
.stack .num{font-variant-numeric:tabular-nums}
.tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.user-menu-wrap{position:relative}.user-btn{cursor:pointer}
.user-dropdown{display:none;position:absolute;right:0;top:32px;background:#fff;border:1px solid var(--stroke);border-radius:10px;box-shadow:var(--shadow);min-width:180px;overflow:hidden}
.user-dropdown a{display:block;padding:10px 12px;text-decoration:none;color:#111827}
.user-dropdown a:hover{background:#f9f9fb}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;padding:20px}
.modal .panel{background:#fff;border-radius:14px;max-width:900px;width:100%;max-height:90vh;overflow:auto;border:1px solid var(--stroke);box-shadow:var(--shadow)}
.modal .panel .hd{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--stroke)}
.modal .panel .bd{padding:14px}
.modal .panel .ft{padding:12px 14px;border-top:1px solid var(--stroke);display:flex;justify-content:flex-end;gap:8px}
</style>
</head>
<body>

<div class="topbar">
  <div class="brand"><span class="dot"></span><span>EHR • ანგარიშები</span></div>
  <div class="user-menu-wrap">
    <div class="user-btn" id="userBtn">Მომხმარებელი ▾</div>
    <div class="user-dropdown" id="userDropdown">
      <a href="profile.php">პროფილი</a>
      <a href="logout.php">გასვლა</a>
    </div>
  </div>
</div>

<div class="container">
 <ul class="tabs">
  <?php foreach ($tabs as $t):
        if (!$can($t['file'])) continue;
        $active = ($t['file'] === $cur) ? 'active' : ''; ?>
    <li>
      <a href="<?= htmlspecialchars($t['file'], ENT_QUOTES, 'UTF-8') ?>" class="<?= $active ?>">
        <?php if (!empty($t['icon'])): ?>
          <i class="fa-solid <?= htmlspecialchars($t['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
        <?php endif; ?>
        <?= htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8') ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
</div>

<div class="subtabswrap">
  <ul class="subtabs">
    <li><a href="angarishebi.php" class="active">დონორები</a></li>
    <li><a href="balance.php">ბალანსი</a></li>
    <li><a href="expense_add.php">გადახდები</a></li>
  </ul>
</div>

<div class="container">
  <div class="card">
    <div class="head">
      <div class="title">დონორების ანგარიში (არ მეორდება)</div>
      <div class="totals" id="totBar">
        <div class="item">თანხა: <span id="t_credit" class="mono">0.00</span></div>
        <div class="item">ჩამოკრედიტებული: <span id="t_applied" class="mono">0.00</span></div>
        <div class="item">გადახდილი: <span id="t_paid_d" class="mono">0.00</span></div>
        <div class="item">დარჩენილი (დონორი): <span id="t_left_d" class="mono">0.00</span></div>
        <div class="item"><span class="pill">ჩანაწერი: <span id="t_cnt" class="mono">0</span></span></div>
        <button class="btn ghost" id="btnExport">ექსელში გატანა</button>
        <button class="btn ghost" id="btnGrand">მთლიანი ჯამი</button>
        <div class="pager">
          <button class="btn ghost" id="btnPrev">‹</button>
          <span class="muted">გვერდი</span>
          <input type="text" id="inpPage" value="1" />
          <span class="muted">/ <span id="lblPages">1</span></span>
          <select id="selPageSize" title="სტრიქონები გვერდზე">
            <option>25</option><option selected>50</option><option>100</option><option>200</option>
          </select>
          <button class="btn ghost" id="btnNext">›</button>
        </div>
      </div>
    </div>

    <div class="body">
      <div class="filters" role="group" aria-label="ფილტრები">
        <div class="f-group"><label>სახელი</label><input type="text" id="f_fname" placeholder="მაგ. გიორგი"></div>
        <div class="f-group"><label>გვარი</label><input type="text" id="f_lname" placeholder="მაგ. ბერიძე"></div>
        <div class="f-group"><label>პირადი #</label><input type="text" id="f_pid" placeholder="XXXXXXXXXXX"></div>
        <div class="f-group"><label>რეგ. — დან</label><input type="text" id="f_rfrom" placeholder="YYYY-MM-DD"></div>
        <div class="f-group"><label>რეგ. — მდე</label><input type="text" id="f_rto" placeholder="YYYY-MM-DD"></div>
        <div class="f-group"><label>მომართვის ტიპი</label>
          <select id="f_entry">
            <option value="">— ყველა —</option>
            <option>ამბულატორია</option>
            <option>გადაუდებელი</option>
            <option>საგანგებო</option>
            <option>სტაციონარი</option>
            <option>დღიური</option>
            <option>თერაპია</option>
          </select>
        </div>
        <div class="f-group"><label>დონორი</label><input type="text" id="f_donor" placeholder="დონორის ძიება"></div>
        <div class="f-group"><label>წერ. თარიღი — დან</label><input type="text" id="f_gfrom" placeholder="YYYY-MM-DD"></div>
        <div class="f-group"><label>წერ. თარიღი — მდე</label><input type="text" id="f_gto" placeholder="YYYY-MM-DD"></div>
        <div class="f-group checks" style="grid-column: span 2;">
          <label><input type="checkbox" id="f_only_left" value="1"> მხოლოდ დონორის ნაშთიანი</label>
          <label><input type="checkbox" id="f_only_debt" value="1"> მხოლოდ დავალიანებიანი</label>
          <label><input type="checkbox" id="f_only_expired" value="1"> მხოლოდ ვადა-გასული</label>
        </div>
        <div class="f-group" id="branchWrap" style="display:none">
          <label>ფილიალი</label>
          <input type="text" id="f_branch" placeholder="branch_id (ციფრი)">
        </div>
      </div>

      <div style="margin:12px 0; display:flex; gap:8px; align-items:center">
        <button class="btn" id="btnSearch">ძიება</button>
        <button class="btn ghost" id="btnClear">გასუფთავება</button>
        <span class="totals hint" id="grandHint" style="display:none">* მთლიანი ჯამი ჩაიტვირთა</span>
      </div>

      <div class="table-scroll">
        <table class="Ctable" id="tbl" data-sort="credit_total" data-dir="desc">
          <thead>
            <tr>
              <th>#</th>
              <th data-k="entry_short">სტაც # <span class="sort-ind"></span></th>
              <th data-k="first_name">სახელი <span class="sort-ind"></span></th>
              <th data-k="last_name">გვარი <span class="sort-ind"></span></th>
              <th data-k="personal_id">პირადი # <span class="sort-ind"></span></th>
              <th data-k="registration_date">რეგისტრაციის თარიღი <span class="sort-ind"></span></th>
              <th data-k="discharge_date" id="thDisch">გაწერის თარიღი <span class="sort-ind"></span></th>
              <th>დონორი</th>
              <th data-k="max_gdate">წერილის თარიღი <span class="sort-ind"></span></th>
              <th data-k="max_vdate">ვადა <span class="sort-ind"></span></th>

              <th class="right" data-k="credit_total">თანხა <span class="sort-ind"></span></th>
              <th class="right" data-k="applied">ჩამოკრედიტებული <span class="sort-ind"></span></th>
              <th class="right" data-k="paid_donor">გადახდილი <span class="sort-ind"></span></th>
              <th class="right" data-k="left_to_pay">დარჩენილი (დონორი) <span class="sort-ind"></span></th>

              <th>ქმედება</th>
            </tr>
          </thead>
          <tbody id="rows">
            <tr><td colspan="15" class="center muted">იტვირთება…</td></tr>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<!-- Patient details modal -->
<div class="modal" id="mdDetails" aria-hidden="true">
  <div class="panel">
    <div class="hd">
      <div><strong id="mdTitle">დეტალები</strong></div>
      <button class="btn ghost" id="mdClose">დაკეტვა</button>
    </div>
    <div class="bd" id="mdBody">
      იტვირთება…
    </div>
    <div class="ft">
      <button class="btn" onclick="window.print()">ბეჭდვა</button>
      <button class="btn ghost" id="mdClose2">დახურვა</button>
    </div>
  </div>
</div>

<script>
function qs(s,r){return (r||document).querySelector(s);}
function qsa(s,r){return Array.from((r||document).querySelectorAll(s));}
function money(n){ n=Number(n||0); return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function val(id){ var el=qs('#'+id); return el?(el.type==='checkbox'?(el.checked?'1':''):el.value.trim()):''; }
function fmtDate(x){ if(!x) return ''; var s=String(x); return s.length>=10?s.slice(0,10):s; }
function clamp(v,min,max){ v=parseInt(v||0,10); if(isNaN(v)) v=min; if(v<min)v=min; if(v>max)v=max; return v; }
function debounce(fn,ms){let t;return function(...a){clearTimeout(t);t=setTimeout(()=>fn.apply(this,a),ms);} }
function esc(s){ return String(s??'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

let state = { page: 1, pages: 1, pagesize: 50, sort: 'credit_total', dir: 'desc', has_branch: 0, has_discharge: 0 };

// ✅ dynamic colspan (discharge column may be hidden)
function currentColspan(){ return state.has_discharge ? 15 : 14; }

function paramsObj(extra={}){
  const o = {
    action:'list',
    first_name:val('f_fname'),
    last_name:val('f_lname'),
    personal_id:val('f_pid'),
    reg_from:val('f_rfrom'),
    reg_to:val('f_rto'),
    entry_type:val('f_entry'),
    donor:val('f_donor'),
    g_from:val('f_gfrom'),
    g_to:val('f_gto'),
    only_has_donor_left:val('f_only_left'),
    only_has_debt:val('f_only_debt'),
    only_expired:val('f_only_expired'),
    page: state.page,
    pagesize: state.pagesize,
    sort: state.sort,
    dir: state.dir
  };
  if (state.has_branch) {
    o.branch_id = qs('#f_branch')?.value.trim() || '';
  }
  return Object.assign(o, extra||{});
}

function setLoading(){
  const tb=qs('#rows'); if(!tb) return;
  tb.innerHTML='';
  const tr=document.createElement('tr'), td=document.createElement('td');
  td.colSpan=currentColspan(); td.className='center muted'; td.textContent='იტვირთება...';
  tr.appendChild(td); tb.appendChild(tr);
}

// ✅ Status badges: აქტიურია (green) / არარის აქტივი (red)
function badgeFlags(r){
  const today = new Date().toISOString().slice(0,10);
  const v = r.max_vdate || '';
  const isActive = (v && v !== '0000-00-00' && v >= today);
  return isActive
    ? '<span class="badge good">აქტიურია</span>'
    : '<span class="badge danger">არარის აქტიური</span>';
}

function donorLeftBadge(r){
  const credit = Number(r.credit_total||0);
  const svc    = Number(r.svc_total||0);
  const paid_d = Number(r.paid_donor||0);
  const applied = Math.min(Math.max(credit,0), Math.max(svc,0));
  const left = Math.max(applied - paid_d, 0);
  if (left > 0) {
    return ' <span class="badge warn">დარჩენილი: ' + money(left) + '</span>';
  }
  return '';
}

function stackNumberWithBar(amount, pct){
  pct = Math.max(0, Math.min(100, Number(pct||0)));
  return '<div class="stack right">'+
           '<div class="num mono">'+money(amount)+'</div>'+
           '<div class="progress" aria-label="გადახდილი '+pct+'%"><span style="width:'+pct+'%"></span></div>'+
         '</div>';
}

function buildRow(idx,r){
  const tr=document.createElement('tr');
  const addHTML=(h,cls)=>{ const td=document.createElement('td'); if(cls) td.className=cls; td.innerHTML=h; tr.appendChild(td); };
  const addText=(t,cls)=>{ const td=document.createElement('td'); if(cls) td.className=cls; td.textContent=(t==null?'':String(t)); tr.appendChild(td); };

  const rowNo = (state.page-1)*state.pagesize + idx + 1;
  addText(String(rowNo));
  addText(r.entry_short||'');
  addText(r.first_name||'');
  addText(r.last_name||'');
  addText(r.personal_id||'','mono');
  addText(fmtDate(r.registration_date),'mono');

  if (state.has_discharge) {
    addText(fmtDate(r.discharge_date),'mono');
  }

  addHTML(esc(r.donors_txt||'') + donorLeftBadge(r) + ' ' + badgeFlags(r));
  addText(fmtDate(r.max_gdate),'mono');
  addText(fmtDate(r.max_vdate),'mono');

  const credit = Number(r.credit_total||0);
  const applied = Number(r.applied||Math.min(Math.max(credit,0), Math.max(Number(r.svc_total||0),0)));
  const paid_d = Number(r.paid_donor||0);
  const left = Math.max(applied - paid_d, 0);
  const usagePct = applied>0 ? Math.round((paid_d/applied)*100) : 0;

  addText(money(credit),'right mono');
  addHTML(stackNumberWithBar(applied, usagePct),'right');
  addText(money(paid_d),'right mono');
  addText(money(left),'right mono');

  const btn = document.createElement('button');
  btn.className='btn ghost';
  btn.textContent='დეტალები';
  btn.onclick = ()=> openDetails(r.id, r.first_name+' '+r.last_name);
  const tdAct = document.createElement('td'); tdAct.appendChild(btn); tr.appendChild(tdAct);

  return tr;
}

function applySortIndicators(){
  const ths = qsa('#tbl thead th[data-k]');
  ths.forEach(th=>{
    const k = th.getAttribute('data-k');
    const ind = th.querySelector('.sort-ind'); if(!ind) return;
    const map = {
      entry_short:  'last_name',
      first_name:   'first_name',
      last_name:    'last_name',
      personal_id:  'personal_id',
      registration_date: 'registration_date',
      discharge_date: 'discharge_date',
      max_gdate:    'max_gdate',
      max_vdate:    'max_vdate',
      credit_total: 'credit_total',
      applied:      'applied',
      paid_donor:   'paid_donor',
      left_to_pay:  'left_to_pay',
    };
    const srv = map[k] || 'credit_total';
    if (srv === state.sort) {
      ind.textContent = state.dir === 'asc' ? '▲' : '▼';
      ind.style.color = '#0f766e';
      ind.setAttribute('aria-label', state.dir === 'asc' ? 'ზრდადი' : 'კლებადი');
    } else {
      ind.textContent = '';
      ind.removeAttribute('aria-label');
      ind.style.color = '';
    }
  });
}

function load(){
  const params=new URLSearchParams(paramsObj());
  setLoading();
  fetch('?'+params.toString(), {credentials:'same-origin'})
    .then(async r => {
      const j = await r.json().catch(()=>null);
      if (!r.ok || !j || j.status!=='ok') throw new Error((j && j.message) || ('HTTP '+r.status));
      return j;
    })
    .then(j => {
      state.has_branch    = Number(j.has_branch||0);
      state.has_discharge = Number(j.has_discharge||0);
      if(!state.has_discharge){ const th = qs('#thDisch'); if (th) th.style.display='none'; }

      const body=qs('#rows'); body.innerHTML='';
      const rows=j.rows||[];
      if(rows.length===0){
        const tr=document.createElement('tr'), td=document.createElement('td');
        td.colSpan=currentColspan(); td.className='center muted'; td.textContent='ჩანაწერი არ არის';
        tr.appendChild(td); body.appendChild(tr);
      }else{
        const frag=document.createDocumentFragment();
        rows.forEach((r,i)=> frag.appendChild(buildRow(i,r)));
        body.appendChild(frag);
      }

      const t=j.totals||{};
      qs('#t_credit').textContent  = money(t.credit_total||0);
      qs('#t_applied').textContent = money(t.applied_total||0);
      qs('#t_paid_d').textContent  = money(t.paid_donor||0);
      qs('#t_left_d').textContent  = money(t.left_to_pay||0);
      qs('#t_cnt').textContent     = String(t.count||0);

      state.page     = parseInt(j.page||1,10);
      state.pagesize = parseInt(j.pagesize||50,10);
      const total    = parseInt(j.total||0,10);
      const pages    = Math.max(1, Math.ceil(total / state.pagesize));
      state.pages    = pages;
      qs('#inpPage').value = String(state.page);
      qs('#lblPages').textContent = String(pages);

      applySortIndicators();
      qs('#grandHint').style.display='none';
    })
    .catch((e)=>{
      const body=qs('#rows'); body.innerHTML='';
      const tr=document.createElement('tr'), td=document.createElement('td');
      td.colSpan=currentColspan(); td.className='center'; td.textContent='შეცდომა: '+(e && e.message ? e.message : 'ქსელის შეცდომა'); tr.appendChild(td); body.appendChild(tr);
      console && console.error && console.error(e);
    });
}

function loadGrandTotals(){
  const p = new URLSearchParams(Object.assign(paramsObj(), {action:'grand_totals'}));
  fetch('?'+p.toString(), {credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{
      if(!j || j.status!=='ok') return;
      const t=j.totals||{};
      qs('#t_credit').textContent  = money(t.credit_total||0);
      qs('#t_applied').textContent = money(t.applied_total||0);
      qs('#t_paid_d').textContent  = money(t.paid_donor||0);
      qs('#t_left_d').textContent  = money(t.left_to_pay||0);
      qs('#grandHint').style.display='inline-flex';
    })
    .catch(()=>{ /* ignore */ });
}

// details modal
function openDetails(pid, title){
  const md = qs('#mdDetails');
  qs('#mdTitle').textContent = title + ' • დეტალები';
  qs('#mdBody').innerHTML = 'იტვირთება…';
  md.style.display='flex';

  const p = new URLSearchParams({action:'patient_details', patient_id:String(pid)});
  fetch('?'+p.toString(), {credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{
      if(!j || j.status!=='ok'){
        qs('#mdBody').textContent = (j&&j.message)||'შეცდომა';
        return;
      }
      const {patient, donors, payments, services} = j;
      const svcTotal = Number(services?.svc_total||0);

      // Donors table with status
      let donorsHtml = '';
      {
        const donorCols = ['ID','დონორი','თანხა','გადახდ.','ნაშთი','წერილი','ვადა','სტატუსი'];
        let html = `<table class="Ctable" style="width:100%">
          <thead><tr>${
            donorCols.map(h => {
              const cls = (h==='თანხა' || h==='გადახდ.' || h==='ნაშთი') ? ' class="right"' : '';
              return `<th${cls}>${h}</th>`;
            }).join('')
          }</tr></thead><tbody>`;

        if (!donors || donors.length === 0) {
          html += `<tr><td class="center muted" colspan="${donorCols.length}">დონორი არ მოიძებნა</td></tr>`;
        } else {
          const today = new Date().toISOString().slice(0,10);
          (donors || []).forEach(d => {
            const left = Math.max(Number(d.amount || 0) - Number(d.paid_by_this_donor || 0), 0);
            const v = d.validity_date || '';
            const isActive = (v && v !== '0000-00-00' && v >= today);
            const statusBadge = isActive
              ? '<span class="badge good">აქტიურია</span>'
              : '<span class="badge danger">არარის აქტიური</span>';

            html += '<tr>'
              + `<td class="mono">${esc(d.id ?? '')}</td>`
              + `<td>${esc(d.donor ?? '')}</td>`
              + `<td class="right mono">${money(d.amount)}</td>`
              + `<td class="right mono">${money(d.paid_by_this_donor)}</td>`
              + `<td class="right mono">${money(left)}</td>`
              + `<td class="mono">${fmtDate(d.guarantee_date)}</td>`
              + `<td class="mono">${fmtDate(d.validity_date)}</td>`
              + `<td>${statusBadge}</td>`
              + '</tr>';
          });
        }
        html += '</tbody></table>';
        donorsHtml = html;
      }

      // Payments table
      let payHtml = '';
      {
        const hasCreated = Array.isArray(payments) && payments.length > 0 && Object.prototype.hasOwnProperty.call(payments[0], 'created_at');
        const hasMethod  = Array.isArray(payments) && payments.length > 0 && Object.prototype.hasOwnProperty.call(payments[0], 'method');

        const headers = ['#']
          .concat(hasCreated ? ['თარიღი'] : [])
          .concat(hasMethod ? ['მეთოდი'] : [])
          .concat(['თანხა','დონორი ID']);

        let html = `<table class="Ctable" style="width:100%">
          <thead><tr>${
            headers.map(h => {
              const cls = (h === 'თანხა') ? ' class="right"' : '';
              return `<th${cls}>${h}</th>`;
            }).join('')
          }</tr></thead><tbody>`;

        if (!payments || payments.length === 0) {
          html += `<tr><td class="center muted" colspan="${headers.length}">გადახდა არ მოიძებნა</td></tr>`;
        } else {
          (payments || []).forEach(pm => {
            html += '<tr>'
              + `<td class="mono">${esc(pm.id ?? '')}</td>`
              + (hasCreated ? `<td class="mono">${fmtDate(pm.created_at)}</td>` : '')
              + (hasMethod  ? `<td>${esc(pm.method || '')}</td>` : '')
              + `<td class="right mono">${money(pm.amount)}</td>`
              + `<td class="mono">${pm.donor_id == null ? '—' : esc(pm.donor_id)}</td>`
              + '</tr>';
          });
        }

        html += '</tbody></table>';
        payHtml = html;
      }

      qs('#mdBody').innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">'+
          '<div><h3>დონორები</h3>'+donorsHtml+'</div>'+
          '<div><h3>გადახდები</h3>'+payHtml+'<div style="margin-top:12px"><strong>სერვისების ჯამი:</strong> <span class="mono">'+money(svcTotal)+'</span></div></div>'+
        '</div>';
    })
    .catch(()=>{ qs('#mdBody').textContent='ქსელის შეცდომა'; });
}

// events
const doLoad = debounce(()=>{ state.page=1; load(); }, 200);

['f_fname','f_lname','f_pid','f_rfrom','f_rto','f_entry','f_donor','f_gfrom','f_gto','f_only_left','f_only_debt','f_only_expired','f_branch']
  .forEach(id=>{ const el=qs('#'+id); if(el){ el.addEventListener('input', doLoad); el.addEventListener('change', doLoad); }});

qs('#btnSearch').addEventListener('click',()=>{ state.page=1; load(); });
qs('#btnClear').addEventListener('click',function(){
  ['f_fname','f_lname','f_pid','f_rfrom','f_rto','f_entry','f_donor','f_gfrom','f_gto','f_only_left','f_only_debt','f_only_expired','f_branch'].forEach(function(id){
    const el=qs('#'+id); if(!el) return;
    if(el.tagName==='SELECT') el.selectedIndex=0;
    else if(el.type==='checkbox') el.checked=false;
    else el.value='';
  });
  state.page=1; load();
});

// Excel export
qs('#btnExport').addEventListener('click',function(){
  const p=new URLSearchParams(Object.assign(paramsObj(), {action:'export_excel'}));
  window.location.href='?'+p.toString();
});

qs('#btnGrand').addEventListener('click',loadGrandTotals);
qs('#btnPrev').addEventListener('click',()=>{ if(state.page>1){ state.page--; load(); }});
qs('#btnNext').addEventListener('click',()=>{ if(state.page<state.pages){ state.page++; load(); }});
qs('#inpPage').addEventListener('keydown',(e)=>{ if(e.key==='Enter'){ let pg=clamp(e.target.value,1,state.pages); state.page=pg; load(); }});
qs('#selPageSize').addEventListener('change',(e)=>{ state.pagesize = clamp(e.target.value,10,200); state.page=1; load(); });

// sort by clicking headers
qsa('#tbl thead th[data-k]').forEach(th=>{
  th.addEventListener('click', ()=>{
    const k = th.getAttribute('data-k');
    const map = {
      entry_short:  'last_name',
      first_name:   'first_name',
      last_name:    'last_name',
      personal_id:  'personal_id',
      registration_date: 'registration_date',
      discharge_date: 'discharge_date',
      max_gdate:    'max_gdate',
      max_vdate:    'max_vdate',
      credit_total: 'credit_total',
      applied:      'applied',
      paid_donor:   'paid_donor',
      left_to_pay:  'left_to_pay'
    };
    const srv = map[k] || 'credit_total';
    if(state.sort === srv){ state.dir = (state.dir==='asc'?'desc':'asc'); }
    else { state.sort = srv; state.dir='asc'; }
    state.page=1; load();
  });
});

// share link (optional)
function shareLink(){
  const params = new URLSearchParams(paramsObj());
  ['action','page','pagesize','sort','dir'].forEach(k=>params.delete(k));
  const url = location.origin + location.pathname + '?' + params.toString();
  navigator.clipboard.writeText(url).then(()=> alert('ბმული დაკოპირდა'));
}
(function(){
  const btnShare=document.createElement('button'); btnShare.className='btn ghost'; btnShare.id='btnShare'; btnShare.textContent='ბმულის გაზიარება';
  const titleWrap = document.querySelector('.head');
  if (titleWrap) titleWrap.appendChild(btnShare);
  const bs = document.querySelector('#btnShare'); if (bs) bs.addEventListener('click', shareLink);
})();

// user dropdown
var userBtn=qs('#userBtn'), dd=qs('#userDropdown');
userBtn&&userBtn.addEventListener('click',function(){ dd.style.display=(dd.style.display==='none'||!dd.style.display)?'block':'none'; });
document.addEventListener('click',function(e){ if(dd && !dd.contains(e.target) && e.target!==userBtn) dd.style.display='none'; });

// initial
(function(){
  const sel=qs('#selPageSize'); if(sel){ state.pagesize=parseInt(sel.value,10)||50; }

  // init from URL filters (share link)
  const sp = new URLSearchParams(location.search);
  if(sp.size){
    const set = (id,key)=>{ if(!qs('#'+id)) return; const v=sp.get(key); if(v!=null){ if(qs('#'+id).type==='checkbox') qs('#'+id).checked=(v==='1'); else qs('#'+id).value=v; } };
    set('f_fname','first_name'); set('f_lname','last_name'); set('f_pid','personal_id');
    set('f_rfrom','reg_from'); set('f_rto','reg_to');
    set('f_entry','entry_type'); set('f_donor','donor');
    set('f_gfrom','g_from'); set('f_gto','g_to');
    set('f_only_left','only_has_donor_left'); set('f_only_debt','only_has_debt'); set('f_only_expired','only_expired');
    if (qs('#f_branch')) set('f_branch','branch_id');
  }

  load();
})();

// modal close
qs('#mdClose').addEventListener('click',()=> qs('#mdDetails').style.display='none');
qs('#mdClose2').addEventListener('click',()=> qs('#mdDetails').style.display='none');
document.addEventListener('keydown', e=>{ if(e.key==='Escape') qs('#mdDetails').style.display='none'; });
</script>
</body>
</html>
