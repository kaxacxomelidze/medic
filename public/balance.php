<?php
// balance.php — Reports / Balance (calendar-enabled, correct-by-filter balance, checkbox multiselects)
// Requires: config/config.php -> $pdo (PDO::ERRMODE_EXCEPTION), session with user_id
// PHP 8+

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

/* -------- Security Headers -------- */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: interest-cohort=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
/* Allow Google Fonts under CSP (page uses <link> to fonts.googleapis.com) */
header("Content-Security-Policy: ".
  "default-src 'self'; ".
  "connect-src 'self'; ".
  "img-src 'self' data:; ".
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ".
  "font-src 'self' https://fonts.gstatic.com data:; ".
  "script-src 'self' 'unsafe-inline'; ".
  "frame-ancestors 'self'; ".
  "base-uri 'self'; ".
  "form-action 'self'"
);

// კონსტანტები — დააბრკოლე ხელახალი გამოცხადება
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (!defined('PAGE_SIZE_DEFAULT')) define('PAGE_SIZE_DEFAULT', 50);
if (!defined('PAGE_SIZE_MAX')) define('PAGE_SIZE_MAX', 200);

/* --- DB charset --- */
try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) {}

/* -------- Helpers -------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ $n=is_null($n)?0:(float)$n; return number_format($n,2,'.',','); }
function like($s){ return '%'.$s.'%'; }
function safeFloat($v){ $v=(string)$v; $v=str_replace(',','.',$v); return is_numeric($v)? (float)$v : 0.0; }
function clampInt($v,$min,$max){ $x=(int)$v; if($x<$min)$x=$min; if($x>$max)$x=$max; return $x; }

/* -------- DB helpers (optional columns/tables) -------- */
function tableExists(PDO $pdo, string $name): bool {
  try { $st=$pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$name]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function columnExists(PDO $pdo, string $table, string $column): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$column]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}

/** Canonicalization + labels (სინონიმებით) */
function methodAliases(string $key): array {
  $m = mb_strtolower(trim($key), 'UTF-8');
  return match ($m) {
    'cash'     => ['cash','34','სალარო','salaro','კეში','kesi','cashier'],
    'bog'      => ['bog','825','ბოგ','ბოღ','bank of georgia','bank_bog','bog_bank'],
    'founder'  => ['founder','446','დამფუძნებელი'],
    'transfer' => ['transfer','გადმორიცხვა','tbc','bank_tbc'],
    'bank'     => ['bank','ბანკი'],
    default    => [$m],
  };
}
function canonicalMethod(string $m): string {
  $x = mb_strtolower(trim($m), 'UTF-8');
  foreach (['bog','cash','founder','transfer','bank'] as $k) {
    if (in_array($x, methodAliases($k), true)) return $k;
  }
  return 'other';
}
function mapMethodLabel(string $m): string {
  return [
    'cash'=>'სალარო',
    'bog'=>'BOG',
    'founder'=>'დამფუძნებელი',
    'transfer'=>'გადმორიცხვა',
    'bank'=>'ბანკი',
    'other'=>'სხვა',
  ][$m] ?? 'სხვა';
}

/**
 * Parses date strings into YYYY-MM-DD.
 * Accepts:
 *  - DD-MM-YYYY (1-2 digit day/month ok)
 *  - YYYY-MM-DD
 *  - MM/DD/YYYY
 *  - DD/MM/YYYY  (heuristic: if first > 12 treat as DD/MM)
 */
function parseDateFlexible($s){
  $s = trim((string)$s);
  if ($s==='') return '';

  $s = str_replace(['.',','], ['',''], $s); // normalize minor junk

  // Hyphen: D-M-YYYY or DD-MM-YYYY
  if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
    [$all,$d,$mth,$y] = $m;
    if (checkdate((int)$mth,(int)$d,(int)$y)) return sprintf('%04d-%02d-%02d', $y,$mth,$d);
    return '';
  }
  // ISO: YYYY-MM-DD
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
    [$all,$y,$mth,$d] = $m;
    if (checkdate((int)$mth,(int)$d,(int)$y)) return "$y-$mth-$d";
    return '';
  }
  // Slash: either MM/DD/YYYY or DD/MM/YYYY (decide by first piece)
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
    [$all, $a, $b, $y] = $m;
    $a = (int)$a; $b = (int)$b; $y = (int)$y;
    if ($a > 12) { $d=$a; $mth=$b; }
    else if ($b > 12) { $d=$b; $mth=$a; }
    else { $mth=$a; $d=$b; }
    if (checkdate($mth,$d,$y)) return sprintf('%04d-%02d-%02d', $y,$mth,$d);
    return '';
  }
  return '';
}

/** expand canonical CSV into full IN(...) for SQL (covers synonyms) */
function buildMethodFilterSQL(?string $col, string $csv, array &$args): string {
  $csv = trim((string)$csv);
  if ($csv==='') return '';
  $vals = array_values(array_filter(array_map('trim', explode(',',$csv)), fn($v)=>$v!==''));
  if (!$vals || !$col) return '';
  $expanded = [];
  foreach ($vals as $v) {
    $canon = canonicalMethod($v);
    foreach (methodAliases($canon) as $syn) {
      $syn = mb_strtolower(trim($syn),'UTF-8');
      if ($syn!=='') $expanded[] = $syn;
    }
  }
  $expanded = array_values(array_unique($expanded));
  if (!$expanded) return '';
  $ph = implode(',', array_fill(0,count($expanded),'?'));
  foreach($expanded as $m){ $args[] = strtolower($m); }
  return " AND LOWER(TRIM($col)) IN ($ph)";
}

/** read GET filters (clamped) */
function filtersGET(){
  $d1 = parseDateFlexible($_GET['dat_from'] ?? '');
  $d2 = parseDateFlexible($_GET['dat_to']   ?? '');
  $page = max(1,(int)($_GET['page']??1));
  $psize = clampInt($_GET['pagesize'] ?? PAGE_SIZE_DEFAULT, 10, PAGE_SIZE_MAX);

  return [
    'dat_from'  => $d1,
    'dat_to'    => $d2,
    'personal'  => trim((string)($_GET['personal']??'')),
    'fullname'  => trim((string)($_GET['fullname']??'')),
    'entry'     => trim((string)($_GET['entry']??'')),              // 1=ამბულატორია, 2=სტაციონარი
    'department_in'=> trim((string)($_GET['department_in']??'')),   // CSV (patients.department)
    'program'   => trim((string)($_GET['program']??'')),            // services.svc_group (optional)
    'method_in' => trim((string)($_GET['method_in']??'')),          // CSV (canonical)
    'order_no'  => trim((string)($_GET['order_no']??'')),
    'amount_from'=> trim((string)($_GET['amount_from']??'')),
    'amount_to'  => trim((string)($_GET['amount_to']??'')),
    'page'      => $page,
    'pagesize'  => $psize,
    'sort'      => trim((string)($_GET['sort']??'paid_at')),
    'dir'       => strtolower(trim((string)($_GET['dir']??'desc')))==='asc'?'asc':'desc',
  ];
}

/* -------- feature flags for optional tables/columns -------- */
$HAS_PAY_DEL         = columnExists($pdo,'payments','deleted_at');
$HAS_PAY_METHOD      = columnExists($pdo,'payments','method');
$HAS_PAY_ORDER_NO    = columnExists($pdo,'payments','order_no');
$HAS_PAY_CREATED_BY  = columnExists($pdo,'payments','created_by');
$HAS_PAY_PAID_AT     = columnExists($pdo,'payments','paid_at');
$PAY_AT_COL          = $HAS_PAY_PAID_AT ? 'paid_at' : (columnExists($pdo,'payments','created_at') ? 'created_at' : 'paid_at'); // fallback

$HAS_EXP_DEL         = columnExists($pdo,'expenses','deleted_at');
$HAS_EXP_METHOD      = columnExists($pdo,'expenses','method');
$HAS_EXP_CREATED_BY  = columnExists($pdo,'expenses','created_by');
$HAS_EXP_AT          = columnExists($pdo,'expenses','expense_at');
$EXP_AT_COL          = $HAS_EXP_AT ? 'expense_at' : (columnExists($pdo,'expenses','created_at') ? 'created_at' : 'expense_at');

$HAS_USERS           = tableExists($pdo,'users');

$HAS_INVOICES        = tableExists($pdo,'invoices');
$HAS_INV_CREATED_BY  = $HAS_INVOICES && columnExists($pdo,'invoices','created_by');

$HAS_PAT_DEPT        = columnExists($pdo,'patients','department');

$HAS_SVC_JOIN        = tableExists($pdo,'services') && tableExists($pdo,'patient_services')
                       && columnExists($pdo,'services','svc_group')
                       && columnExists($pdo,'patient_services','service_id')
                       && columnExists($pdo,'patient_services','patient_id');

/* ------------------------- SQL Builders ------------------------- */
/** Income list: payments.amount >= 0 */
function buildListSQL(array $f, array &$args, $forCount=false){
  global $HAS_PAY_DEL, $HAS_PAY_METHOD, $HAS_PAY_ORDER_NO, $HAS_PAY_CREATED_BY,
         $HAS_INVOICES, $HAS_INV_CREATED_BY, $HAS_USERS, $HAS_PAT_DEPT, $HAS_SVC_JOIN, $PAY_AT_COL;

  $where = [];

  if ($f['dat_from']!==''){ $where[] = "pmt.`$PAY_AT_COL` >= ?"; $args[] = $f['dat_from'].' 00:00:00'; }
  if ($f['dat_to']  !==''){ $where[] = "pmt.`$PAY_AT_COL` <= ?"; $args[] = $f['dat_to']  .' 23:59:59'; }

  if ($HAS_PAY_DEL) { $where[] = "pmt.deleted_at IS NULL"; }

  if ($f['personal']!==''){ $where[]="pat.personal_id LIKE ?"; $args[] = like($f['personal']); }

  if ($f['fullname']!==''){
    $parts = preg_split('/\s+/', $f['fullname'], -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts)>=2){
      $where[] = "(pat.first_name LIKE ? AND pat.last_name LIKE ?)";
      $args[] = like($parts[0]); $args[] = like($parts[1]);
    }else{
      $where[] = "(pat.first_name LIKE ? OR pat.last_name LIKE ?)";
      $args[] = like($f['fullname']); $args[] = like($f['fullname']);
    }
  }

  if ($f['entry']==='1'){ $where[]="pat.entry_type = 'ამბულატორია'"; }
  if ($f['entry']==='2'){ $where[]="pat.entry_type = 'სტაციონარი'"; }

  if ($HAS_PAT_DEPT && $f['department_in']!==''){
    $vals = array_values(array_filter(array_map('trim', explode(',',$f['department_in']))));
    if ($vals){ $ph=implode(',', array_fill(0,count($vals),'?')); $where[]="COALESCE(pat.department,'') IN ($ph)"; foreach($vals as $v){ $args[]=$v; } }
  }

  if ($HAS_SVC_JOIN && $f['program']!==''){
    $where[]="EXISTS (SELECT 1 FROM patient_services ps JOIN services s ON s.id=ps.service_id WHERE ps.patient_id = pat.id AND s.svc_group = ?)";
    $args[]=$f['program'];
  }

  // method_in with synonyms (only if payments.method exists)
  $methodClause = '';
  if ($HAS_PAY_METHOD) {
    $methodClause = buildMethodFilterSQL('pmt.method', $f['method_in'], $args);
  }

  if ($HAS_PAY_ORDER_NO && $f['order_no']!==''){ $where[]="pmt.order_no LIKE ?"; $args[] = like($f['order_no']); }

  if ($f['amount_from']!==''){ $where[]="pmt.amount >= ?"; $args[] = safeFloat($f['amount_from']); }
  if ($f['amount_to']  !==''){ $where[]="pmt.amount <= ?"; $args[] = safeFloat($f['amount_to']); }

  $where[] = "pmt.amount >= 0";

  $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : 'WHERE 1=1';

  // Joins / author resolution
  $joinUsersBy = '';
  $joinInvoice = '';
  $authorExpr  = "''";
  $seqExpr     = "NULL AS seq_no";
  $cardExpr    = "'' AS card_no";

  if ($HAS_INVOICES) {
    $joinInvoice = "LEFT JOIN invoices inv ON inv.order_no = pmt.order_no AND inv.patient_id = pmt.patient_id";
    if ($HAS_USERS && $HAS_INV_CREATED_BY) {
      $joinUsersBy = "LEFT JOIN users usr ON usr.id = inv.created_by";
      $authorExpr = "COALESCE(usr.username,'')";
    }
    $seqExpr  = "inv.id AS seq_no";
    $cardExpr = "CASE WHEN inv.id IS NULL THEN '' ELSE CONCAT('a-', LPAD(inv.id,3,'0'), '-', DATE_FORMAT(pmt.`$PAY_AT_COL`,'%Y')) END AS card_no";
  } elseif ($HAS_USERS && $HAS_PAY_CREATED_BY) {
    $joinUsersBy = "LEFT JOIN users usr ON usr.id = pmt.created_by";
    $authorExpr = "COALESCE(usr.username,'')";
  }

  $methodSel = $HAS_PAY_METHOD ? "pmt.method AS method" : "'' AS method";
  $orderSel  = $HAS_PAY_ORDER_NO ? "pmt.order_no AS order_no" : "'' AS order_no";

  $select = "
    SELECT
      pmt.id                            AS pay_id,
      pmt.`$PAY_AT_COL`                 AS paid_at,
      $seqExpr,
      $cardExpr,
      pat.personal_id                   AS personal_id,
      CONCAT(COALESCE(pat.first_name,''),' ',COALESCE(pat.last_name,'')) AS full_name,
      $methodSel,
      $orderSel,
      pmt.amount                        AS amount,
      $authorExpr                       AS author,
      pmt.`$PAY_AT_COL`                 AS when_at
    FROM payments pmt
      LEFT JOIN patients pat ON pat.id = pmt.patient_id
      $joinInvoice
      $joinUsersBy
    $wsql
    $methodClause
  ";

  if ($forCount) return "SELECT COUNT(*) FROM ($select) t";

  $sortMap = [
    'paid_at'=>'paid_at','seq_no'=>'seq_no','card_no'=>'card_no','personal'=>'personal_id',
    'full_name'=>'full_name','method'=>'method','order_no'=>'order_no','amount'=>'amount','author'=>'author','when_at'=>'when_at'
  ];
  $col = $sortMap[$f['sort']] ?? 'paid_at';
  $dir = $f['dir']==='asc' ? 'ASC' : 'DESC';

  return "$select ORDER BY $col $dir, pay_id DESC";
}

/** Expenses list: from expenses table */
function buildExpensesSQL(array $f, array &$args){
  global $HAS_EXP_DEL, $HAS_EXP_METHOD, $HAS_EXP_CREATED_BY, $HAS_USERS, $EXP_AT_COL;

  $where = [];
  if ($f['dat_from']!==''){ $where[] = "exp.`$EXP_AT_COL` >= ?"; $args[] = $f['dat_from'].' 00:00:00'; }
  if ($f['dat_to']  !==''){ $where[] = "exp.`$EXP_AT_COL` <= ?"; $args[] = $f['dat_to']  .' 23:59:59'; }
  if ($HAS_EXP_DEL) { $where[]="exp.deleted_at IS NULL"; }

  $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : 'WHERE 1=1';

  // method_in with synonyms
  $methodClause = '';
  if ($HAS_EXP_METHOD) {
    $methodClause = buildMethodFilterSQL('exp.method', $f['method_in'], $args);
  }

  $joinUsers = '';
  $authorSel = "'' AS author";
  if ($HAS_USERS && $HAS_EXP_CREATED_BY) {
    $joinUsers = "LEFT JOIN users u ON u.id = exp.created_by";
    $authorSel = "COALESCE(u.username,'') AS author";
  }

  $methodSel = $HAS_EXP_METHOD ? "exp.method AS method" : "'' AS method";

  return "
    SELECT
      exp.`$EXP_AT_COL` AS paid_at,
      $methodSel,
      exp.payee,
      exp.amount,
      $authorSel
    FROM expenses exp
      $joinUsers
    $wsql
      $methodClause
    ORDER BY exp.`$EXP_AT_COL` ASC, exp.id ASC
  ";
}

/** Balance summary per canonical method — strictly follows filters for incomes; expenses are from expenses table. */
function balanceData(PDO $pdo, array $f){
  global $HAS_PAY_DEL, $HAS_EXP_DEL, $HAS_PAY_METHOD, $HAS_EXP_METHOD,
         $HAS_PAT_DEPT, $HAS_SVC_JOIN, $PAY_AT_COL, $EXP_AT_COL;

  $d1 = $f['dat_from']; $d2 = $f['dat_to'];

  // initialize canonical buckets
  $res = [];
  foreach (['cash','bog','founder','transfer','bank','other'] as $k) {
    $res[$k] = ['label'=>mapMethodLabel($k),'start'=>0,'income'=>0,'other'=>0,'expense'=>0,'end'=>0];
  }

  // shared patient filters for INCOMES
  $where = []; $args = [];

  if ($HAS_PAY_DEL) { $where[]="pmt.deleted_at IS NULL"; }

  if ($f['personal']!==''){ $where[]="pat.personal_id LIKE ?"; $args[] = like($f['personal']); }

  if ($f['fullname']!==''){
    $parts = preg_split('/\s+/', $f['fullname'], -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts)>=2){ $where[]="(pat.first_name LIKE ? AND pat.last_name LIKE ?)"; $args[] = like($parts[0]); $args[] = like($parts[1]); }
    else { $where[]="(pat.first_name LIKE ? OR pat.last_name LIKE ?)"; $args[] = like($f['fullname']); $args[] = like($f['fullname']); }
  }

  if ($f['entry']==='1'){ $where[]="pat.entry_type = 'ამბულატორია'"; }
  if ($f['entry']==='2'){ $where[]="pat.entry_type = 'სტაციონარი'"; }

  if ($HAS_PAT_DEPT && $f['department_in']!==''){
    $vals = array_values(array_filter(array_map('trim', explode(',',$f['department_in']))));
    if ($vals){ $ph=implode(',', array_fill(0,count($vals),'?')); $where[]="COALESCE(pat.department,'') IN ($ph)"; foreach($vals as $v){ $args[]=$v; } }
  }

  if ($HAS_SVC_JOIN && $f['program']!==''){
    $where[]="EXISTS (SELECT 1 FROM patient_services ps JOIN services s ON s.id=ps.service_id WHERE ps.patient_id = pat.id AND s.svc_group = ?)"; $args[]=$f['program'];
  }

  // method_in for incomes (only if payments.method exists)
  $argsMethIncome = [];
  $methodIncomeClause = '';
  if ($HAS_PAY_METHOD) {
    $methodIncomeClause = buildMethodFilterSQL('pmt.method', $f['method_in'], $argsMethIncome);
  }

  $baseJoin = " FROM payments pmt LEFT JOIN patients pat ON pat.id = pmt.patient_id ";
  $w = $where ? ('WHERE '.implode(' AND ',$where)) : 'WHERE 1=1';

  // start — ONLY if dat_from given (otherwise 0)
  if ($d1) {
    $argsStart = array_merge($args, $argsMethIncome);
    $wStart = $w . " AND pmt.`$PAY_AT_COL` < ? ";
    $argsStart[] = $d1.' 00:00:00';
    $sqlStart = "SELECT LOWER(TRIM(pmt.method)) m, SUM(pmt.amount) s $baseJoin $wStart $methodIncomeClause GROUP BY LOWER(TRIM(pmt.method))";
    $st = $pdo->prepare($sqlStart); $st->execute($argsStart);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $k = canonicalMethod((string)($r['m'] ?? ''));
      $res[$k]['start'] = (float)($r['s'] ?? 0);
    }
  }

  // income (>=0) within range
  $argsInc = array_merge($args, $argsMethIncome); $wInc = $w;
  if ($d1){ $wInc .= " AND pmt.`$PAY_AT_COL` >= ? "; $argsInc[]=$d1.' 00:00:00'; }
  if ($d2){ $wInc .= " AND pmt.`$PAY_AT_COL` <= ? "; $argsInc[]=$d2.' 23:59:59'; }
  $wInc .= " AND pmt.amount >= 0 ";
  $sqlInc = "SELECT LOWER(TRIM(pmt.method)) m, SUM(pmt.amount) s $baseJoin $wInc $methodIncomeClause GROUP BY LOWER(TRIM(pmt.method))";
  $st = $pdo->prepare($sqlInc); $st->execute($argsInc);
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    $k = canonicalMethod((string)($r['m'] ?? ''));
    $res[$k]['income'] = (float)($r['s'] ?? 0);
  }

  // expense — from expenses table (positive numbers)
  {
    $argsExp = [];
    $methodClause = '';
    if ($HAS_EXP_METHOD) {
      $methodClause = buildMethodFilterSQL('exp.method', $f['method_in'], $argsExp);
    }

    $wExp = "WHERE 1=1";
    if ($HAS_EXP_DEL) { $wExp .= " AND exp.deleted_at IS NULL"; }
    if ($d1){ $wExp .= " AND exp.`$EXP_AT_COL` >= ?"; $argsExp[]=$d1.' 00:00:00'; }
    if ($d2){ $wExp .= " AND exp.`$EXP_AT_COL` <= ?"; $argsExp[]=$d2.' 23:59:59'; }

    $sqlExp = "SELECT LOWER(TRIM(exp.method)) m, SUM(exp.amount) s
               FROM expenses exp
               $wExp
               $methodClause
               GROUP BY LOWER(TRIM(exp.method))";
    $st = $pdo->prepare($sqlExp); $st->execute($argsExp);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $k = canonicalMethod((string)($r['m'] ?? ''));
      $res[$k]['expense'] = (float)($r['s'] ?? 0);
    }
  }

  foreach($res as $k=>&$v){ $v['end'] = $v['start'] + $v['income'] + $v['other'] - $v['expense']; }
  return $res;
}

/* ------------------------- ROUTES (AJAX/EXPORT) ------------------------- */
$action = $_GET['action'] ?? '';

if ($action === 'meta') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    global $HAS_PAT_DEPT, $HAS_SVC_JOIN, $HAS_PAY_METHOD, $HAS_EXP_METHOD;

    $dept = [];
    if ($HAS_PAT_DEPT) {
      $dept = $pdo->query("
        SELECT DISTINCT NULLIF(TRIM(department),'') AS d
        FROM patients
        WHERE NULLIF(TRIM(department),'') IS NOT NULL
        ORDER BY 1
      ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $prog = [];
    if ($HAS_SVC_JOIN) {
      $prog = $pdo->query("
        SELECT DISTINCT NULLIF(TRIM(svc_group),'') AS g
        FROM services
        WHERE NULLIF(TRIM(svc_group),'') IS NOT NULL
        ORDER BY 1
      }")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // Methods — payments ∪ expenses → canonical unique (skip deleted where applicable)
    $methRaw = [];

    if ($HAS_PAY_METHOD) {
      $condPay = $GLOBALS['HAS_PAY_DEL']
        ? "WHERE deleted_at IS NULL AND NULLIF(TRIM(method),'') IS NOT NULL"
        : "WHERE NULLIF(TRIM(method),'') IS NOT NULL";
      $methRaw = array_merge($methRaw,
        $pdo->query("SELECT DISTINCT LOWER(TRIM(method)) FROM payments $condPay")->fetchAll(PDO::FETCH_COLUMN) ?: []
      );
    }
    if ($HAS_EXP_METHOD) {
      $condExp = $GLOBALS['HAS_EXP_DEL']
        ? "WHERE deleted_at IS NULL AND NULLIF(TRIM(method),'') IS NOT NULL"
        : "WHERE NULLIF(TRIM(method),'') IS NOT NULL";
      $methRaw = array_merge($methRaw,
        $pdo->query("SELECT DISTINCT LOWER(TRIM(method)) FROM expenses $condExp")->fetchAll(PDO::FETCH_COLUMN) ?: []
      );
    }

    $seen = [];
    foreach($methRaw as $m){
      $canon = canonicalMethod((string)$m);
      $seen[$canon] = true;
    }
    foreach (['cash','bog','founder','transfer','bank','other'] as $k) { $seen[$k] = $seen[$k] ?? true; }

    $pretty = [];
    foreach(array_keys($seen) as $k){ $pretty[] = ['value'=>$k,'label'=>mapMethodLabel($k)]; }

    echo json_encode(['status'=>'ok','departments'=>$dept,'programs'=>$prog,'methods'=>$pretty], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>APP_DEBUG?$e->getMessage():'შეცდომა'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'list') {
  header('Content-Type: application/json; charset=utf-8');
  try{
    $f = filtersGET();
    $argsC = [];
    $sqlC  = buildListSQL($f, $argsC, true);
    $stc = $pdo->prepare($sqlC); $stc->execute($argsC);
    $total = (int)$stc->fetchColumn();

    $args = [];
    $sql  = buildListSQL($f, $args, false);
    $sql .= ' LIMIT '.(int)$f['pagesize'].' OFFSET '.(int)(($f['page']-1)*$f['pagesize']);
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sumAmount = 0.0;
    foreach($rows as &$r){
      $sumAmount += (float)$r['amount'];
      $r['method'] = canonicalMethod($r['method'] ?? ''); // normalize for UI
    } unset($r);

    echo json_encode([
      'status'=>'ok','rows'=>$rows,'page'=>(int)$f['page'],'pagesize' => (int)$f['pagesize'],
      'total'=>$total,'sum_amount'=>$sumAmount,'sort'=>$f['sort'],'dir'=>$f['dir']
    ], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e){
    if (APP_DEBUG) error_log('[list] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>APP_DEBUG?$e->getMessage():'სერვერის შეცდომა'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'expenses') {
  header('Content-Type: application/json; charset=utf-8');
  try{
    $f = filtersGET();
    $args = [];
    $sql  = buildExpensesSQL($f, $args);
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sum = 0.0;
    foreach($rows as &$r){
      $sum += (float)$r['amount'];
      $r['method'] = canonicalMethod($r['method'] ?? '');
    } unset($r);
    echo json_encode(['status'=>'ok','rows'=>$rows,'sum'=>$sum], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>APP_DEBUG?$e->getMessage():'სერვერის შეცდომა'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'balance') {
  header('Content-Type: application/json; charset=utf-8');
  try{
    $f = filtersGET();
    $rows = balanceData($pdo, $f);
    $tot = ['start'=>0,'income'=>0,'other'=>0,'expense'=>0,'end'=>0];
    foreach($rows as $v){ $tot['start']+=$v['start']; $tot['income']+=$v['income']; $tot['other']+=$v['other']; $tot['expense']+=$v['expense']; $tot['end']+=$v['end']; }
    echo json_encode(['status'=>'ok','rows'=>$rows,'total'=>$tot], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>APP_DEBUG?$e->getMessage():'სერვერის შეცდომა'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($action === 'export_incomes') {
  $f = filtersGET();
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="incomes_'.date('Ymd_His').'.xls"');
  echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
  $args = []; $sql = buildListSQL($f, $args, false);
  $ee = fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
  echo '<html><head><meta charset="UTF-8"></head><body><table border="1" cellspacing="0" cellpadding="3"><tr>'.
        '<th>თარიღი</th><th>რიგითი #</th><th>ბარათის #</th><th>პირადი #</th><th>სახელი გვარი</th><th>ტიპი</th><th>ორდერის #</th><th>თანხა</th><th>ავტორი</th><th>როდის</th></tr>';
  try{
    $st=$pdo->prepare($sql); $st->execute($args); $sum=0.0;
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $sum+=(float)$r['amount'];
      $label = mapMethodLabel(canonicalMethod($r['method'] ?? ''));
      echo '<tr>'.
        '<td>'.$ee(substr((string)$r['paid_at'],0,16)).'</td>'.
        '<td>'.$ee($r['seq_no'] ?? '').'</td>'.
        '<td>'.$ee($r['card_no'] ?? '').'</td>'.
        '<td>'.$ee($r['personal_id'] ?? '').'</td>'.
        '<td>'.$ee($r['full_name'] ?? '').'</td>'.
        '<td>'.$ee($label).'</td>'.
        '<td>'.$ee($r['order_no'] ?? '').'</td>'.
        '<td style="mso-number-format:\'#,##0.00\';">'.$ee(money($r['amount'] ?? 0)).'</td>'.
        '<td>'.$ee($r['author'] ?? '').'</td>'.
        '<td>'.$ee(substr((string)$r['when_at'],0,16)).'</td>'.
      '</tr>';
    }
    echo '<tr><td colspan="7"></td><td style="mso-number-format:\'#,##0.00\';"><b>'.$ee(money($sum)).'</b></td><td colspan="2"></td></tr>';
  }catch(Throwable $ex){ if(APP_DEBUG) echo '<tr><td colspan="10">'.$ee($ex->getMessage()).'</td></tr>'; }
  echo '</table></body></html>'; exit;
}

if ($action === 'export_expenses') {
  $f = filtersGET();
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="expenses_'.date('Ymd_His').'.xls"');
  echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
  $args = []; $sql = buildExpensesSQL($f, $args);
  $ee = fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
  echo '<html><head><meta charset="UTF-8"></head><body><table border="1" cellspacing="0" cellpadding="3"><tr>'.
        '<th>თარიღი</th><th>ტიპი</th><th>მიმღები</th><th>თანხა</th><th>ავტორი</th><th>როდის</th></tr>';
  try{
    $st=$pdo->prepare($sql); $st->execute($args); $sum=0.0;
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $sum+=(float)$r['amount'];
      $label = mapMethodLabel(canonicalMethod($r['method'] ?? ''));
      echo '<tr>'.
        '<td>'.$ee(substr((string)$r['paid_at'],0,16)).'</td>'.
        '<td>'.$ee($label).'</td>'.
        '<td>'.$ee($r['payee'] ?? '').'</td>'.
        '<td style="mso-number-format:\'#,##0.00\';">'.$ee(money($r['amount'] ?? 0)).'</td>'.
        '<td>'.$ee($r['author'] ?? '').'</td>'.
        '<td>'.$ee(substr((string)$r['paid_at'],0,16)).'</td>'.
      '</tr>';
    }
    echo '<tr><td colspan="3"></td><td style="mso-number-format:\'#,##0.00\';"><b>'.$ee(money($sum)).'</b></td><td colspan="2"></td></tr>';
  }catch(Throwable $ex){ if(APP_DEBUG) echo '<tr><td colspan="6">'.$ee($ex->getMessage()).'</td></tr>'; }
  echo '</table></body></html>'; exit;
}
?>
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<title>ბალანსი • ანგარიშები</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts - Noto Sans Georgian -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/preclinic-theme.css">

<style>
:root{--bg:#f9f8f2;--surface:#fff;--text:#222;--muted:#6b7280;--brand:#21c1a6;--stroke:#e5e7eb;--shadow:0 6px 18px rgba(0,0,0,.06);--warn:#f59e0b;--danger:#ef4444}
*{box-sizing:border-box}html,body{height:100%}body{margin:0;font-family:"Noto Sans Georgian",sans-serif;background:var(--bg);color:var(--text)}
.topbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:var(--brand);color:#fff;box-shadow:var(--shadow)}
.brand{display:flex;gap:10px;font-weight:800;align-items:center}.brand .dot{width:10px;height:10px;border-radius:50%;background:#fff}
.container{max-width:1750px;margin:20px auto;padding:0 18px}
.tabs{list-style:none;display:flex;gap:6px;padding-left:0;margin:0 0 14px;border-bottom:2px solid #ddd}
.tabs a{padding:10px 18px;background:var(--brand);color:#fff;border-top-left-radius:7px;border-top-right-radius:7px;text-decoration:none}
.tabs a.active,.tabs a:hover{background:#fff;color:var(--brand)}
.subtabswrap{max-width:1750px;margin:0 auto 6px;padding:0 24px}
.subtabs{list-style:none;display:flex;gap:6px;margin:0 0 12px;padding:0;border-bottom:2px solid #e6e6e6}
.subtabs a{display:inline-block;padding:8px 14px;text-decoration:none;border-top-left-radius:8px;border-top-right-radius:8px;background:var(--brand);color:#fff;font-weight:600}
.subtabs a:hover,.subtabs a.active{background:#fff;color:var(--brand);border:1px solid #cfeee8;border-bottom-color:#fff}

/* cards / head / body */
.card{background:#fff;border:1px solid var(--stroke);border-radius:14px;box-shadow:var(--shadow)}
.card .head{position:sticky;top:56px;background:#fff;padding:14px 16px;border-bottom:1px solid var(--stroke);display:flex;align-items:center;gap:12px;flex-wrap:wrap;z-index:5}
.head .title{font-size:16px;font-weight:800;color:#0f172a;flex:1}
.card .body{padding:16px}

/* filters */
.filters{display:grid;grid-template-columns:repeat(8,1fr);gap:10px}
@media (max-width:1400px){.filters{grid-template-columns:repeat(4,1fr)}}
@media (max-width:800px){.filters{grid-template-columns:1fr}}
.f-group label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}
input[type=text],select,button,input[type=date]{font-size:14px}
input[type=text],select,input[type=date]{width:100%;padding:9px 10px;border:1px solid var(--stroke);border-radius:10px;background:#fff;outline:none}
input[type=text]:focus,select:focus,input[type=date]:focus{border-color:#9adfd1;box-shadow:0 0 0 4px rgba(33,193,166,.15)}
.btn{padding:10px 14px;border-radius:10px;border:1px solid #0bb192;background:#10b981;color:#fff;font-weight:700;cursor:pointer}
.btn:hover{background:#0bb192}
.btn.ghost{background:#fff;color:#0bb192;border-color:#9adfd1}
.btn.ghost:hover{background:#eefaf6}

.table-scroll{overflow:auto;border:1px solid var(--stroke);border-radius:12px}
.Ctable{width:100%;border-collapse:collapse;background:#fff}
.Ctable th,.Ctable td{border-bottom:1px solid #eef2f7;padding:10px 12px;vertical-align:middle;white-space:nowrap}
.Ctable thead th{font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;text-align:left;background:#f1fbf8;position:sticky;top:0;cursor:pointer}
.Ctable tbody tr:hover{background:#f7fffd}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;color:#374151}
.right{text-align:right}.center{text-align:center}.muted{color:#6b7280}
.pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#e9f7f3;border:1px solid #d5f1ea;color:#145e50;font-weight:700;font-size:12px}
.totals{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.totals .item{background:#fff;border:1px solid var(--stroke);border-radius:10px;padding:8px 12px;font-weight:800}
.tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

/* user menu */
.user-menu-wrap{position:relative}.user-btn{cursor:pointer}
.user-dropdown{display:none;position:absolute;right:0;top:32px;background:#fff;border:1px solid var(--stroke);border-radius:10px;box-shadow:var(--shadow);min-width:180px;overflow:hidden}
.user-dropdown a{display:block;padding:10px 12px;text-decoration:none;color:#111827}
.user-dropdown a:hover{background:#f9fafb}

/* Checkbox multiselect (dropdown) */
.ms{position:relative}
.ms .ms-btn{width:100%;padding:9px 10px;border:1px solid var(--stroke);border-radius:10px;background:#fff;display:flex;justify-content:space-between;align-items:center;cursor:pointer}
.ms .ms-list{display:none;position:absolute;z-index:20;left:0;right:0;top:100%;margin-top:6px;background:#fff;border:1px solid var(--stroke);border-radius:10px;box-shadow:var(--shadow);max-height:280px;overflow:auto}
.ms .ms-item{padding:8px 10px;display:flex;gap:8px;align-items:center}
.ms .ms-item:hover{background:#f7fafc}
.ms .sel-hint{font-size:12px;color:#6b7280;margin-top:6px}
</style>
</head>
<body>

<!-- HEADER -->
<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:40px;width:auto;margin-right:12px;background:#fff;padding:4px 8px;border-radius:6px;">
    </a>
  <div class="brand"><span class="dot"></span><span>EHR • ანგარიშები</span></div>
  <div class="user-menu-wrap">
    <div class="user-btn" id="userBtn">მომხმარებელი ▾</div>
    <div class="user-dropdown" id="userDropdown">
      <a href="profile.php">პროფილი</a>
      <a href="logout.php">გასვლა</a>
    </div>
  </div>
</div>

<!-- MAIN TABS -->
<div class="container">
  <ul class="tabs">
    <li><a href="dashboard.php">რეგისტრაცია</a></li>
    <li><a href="patient_hstory.php">პაციენტების მართვა</a></li>
    <li><a href="nomenklatura.php">ნომენკლატურა</a></li>
    <li><a href="angarishebi.php" class="active">ანგარიშები</a></li>
  </ul>
</div>

<!-- SUBTABS -->
<div class="subtabswrap">
  <ul class="subtabs">
    <li><a href="angarishebi.php">დონორები</a></li>
    <li><a href="balance.php" class="active">ბალანსი</a></li>
    <li><a href="expense_add.php">გადახდები</a></li>
  </ul>
</div>

<div class="container">

  <!-- Filters -->
  <div class="card">
    <div class="head">
      <div class="title">ფილტრები</div>
      <div class="tools">
        <button class="btn ghost" id="btnExpIn" title="შემოსავლების Excel">შემოსავალი XLS</button>
        <button class="btn ghost" id="btnExpOut" title="გასავლების Excel">გასავალი XLS</button>
      </div>
    </div>
    <div class="body">
      <div class="filters">

        <!-- Department MS -->
        <div class="f-group">
          <label>განყოფილება</label>
          <div class="ms" id="ms-dept">
            <div class="ms-btn"><span class="ms-label">აირჩიე</span><span>▾</span></div>
            <div class="ms-list" id="dept_list"></div>
          </div>
        </div>

        <!-- Entry type -->
        <div class="f-group">
          <label>ტიპი (შემოსვლის)</label>
          <select id="stac_typ">
            <option value=""></option>
            <option value="1">ამბულატორია</option>
            <option value="2">სტაციონარული</option>
          </select>
        </div>

        <!-- Program -->
        <div class="f-group">
          <label>პროგრამები</label>
          <select id="program"><option value="">-ყველა-</option></select>
        </div>

        <!-- Method MS -->
        <div class="f-group">
          <label>გადახდის ტიპი</label>
          <div class="ms" id="ms-method">
            <div class="ms-btn"><span class="ms-label">აირჩიე</span><span>▾</span></div>
            <div class="ms-list" id="method_list"></div>
          </div>
        </div>

        <div class="f-group">
          <label>თარიღი — დან</label>
          <input type="date" id="sad_dat1" placeholder="YYYY-MM-DD">
        </div>

        <div class="f-group">
          <label>თარიღი —ამდე</label>
          <input type="date" id="sad_dat2" placeholder="YYYY-MM-DD">
        </div>

        <div class="f-group">
          <label>პირადი #</label>
          <input type="text" id="sad_docno" placeholder="პირადი">
        </div>

        <div class="f-group">
          <label>სახელი გვარი</label>
          <input type="text" id="sad_finam" placeholder="სახელი გვარი">
        </div>

        <div class="f-group">
          <label>ორდერის #</label>
          <input type="text" id="ord_no" placeholder="ORD-...">
        </div>

        <div class="f-group">
          <label>თანხა — დან</label>
          <input type="text" id="amo_fr" placeholder="0">
        </div>

        <div class="f-group">
          <label>თანხა —ამდე</label>
          <input type="text" id="amo_to" placeholder="999999">
        </div>

        <div class="f-group" style="align-self:end">
          <button class="btn" id="sad_fltb">ძებნა</button>
          <button class="btn ghost" id="btnClear">გასუფთავება</button>
        </div>

      </div>

      <div class="totals" style="margin-top:12px">
        <div class="item">გვერდის ჯამი: <span id="sumAmt" class="mono">0.00</span></div>
      </div>
    </div>
  </div>

  <!-- Incomes list -->
  <div class="card">
    <div class="head">
      <div class="title">შემოსავლების რეესტრი</div>
      <div class="tools pager">
        <button class="btn ghost" id="btnPrev">‹</button>
        <span class="muted">გვერდი</span>
        <input type="text" id="inpPage" value="1" style="width:72px">
        <span class="muted">/ <span id="lblPages">1</span></span>
        <select id="selPageSize">
          <option>25</option><option selected>50</option><option>100</option><option>200</option>
        </select>
        <button class="btn ghost" id="btnNext">›</button>
      </div>
    </div>
    <div class="body">
      <div class="table-scroll">
        <table class="Ctable" id="tbl">
          <thead>
            <tr>
              <th data-k="paid_at">თარიღი <span class="sort-ind" id="s_paid_at"></span></th>
              <th data-k="seq_no">რიგითი # <span class="sort-ind" id="s_seq_no"></span></th>
              <th data-k="card_no">ბარათის # <span class="sort-ind" id="s_card_no"></span></th>
              <th data-k="personal">პირადი # <span class="sort-ind" id="s_personal"></span></th>
              <th data-k="full_name">სახელი გვარი <span class="sort-ind" id="s_full_name"></span></th>
              <th data-k="method">ტიპი <span class="sort-ind" id="s_method"></span></th>
              <th data-k="order_no">ორდერის # <span class="sort-ind" id="s_order_no"></span></th>
              <th class="right" data-k="amount">თანხა <span class="sort-ind" id="s_amount"></span></th>
              <th data-k="author">ავტორი <span class="sort-ind" id="s_author"></span></th>
              <th data-k="when_at">როდის <span class="sort-ind" id="s_when_at"></span></th>
            </tr>
          </thead>
          <tbody id="rows"><tr><td colspan="10" class="center">ფილტრების მიხედვით მოიტანება…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Expenses -->
  <div class="card">
    <div class="head"><div class="title">გასავლები</div></div>
    <div class="body">
      <div class="table-scroll">
        <table class="Ctable" id="tblOut">
          <thead>
            <tr>
              <th>თარიღი</th>
              <th>ტიპი</th>
              <th>მიმღები</th>
              <th class="right">თანხა</th>
              <th>ავტორი</th>
              <th>როდის</th>
            </tr>
          </thead>
          <tbody id="rowsOut"><tr><td colspan="6" class="center">ფილტრების მიხედვით მოიტანება…</td></tr></tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="right"><b>ჯამი</b></td>
              <td class="right"><b id="sumOut" class="mono">0.00</b></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Balance summary -->
  <div class="card">
    <div class="head"><div class="title">ბალანსი</div></div>
    <div class="body">
      <div class="table-scroll" style="max-height:none">
        <table class="Ctable" style="min-width:760px">
          <thead>
            <tr>
              <th>ტიპი</th>
              <th class="right">საწყისი ნაშთი</th>
              <th class="right">შემოსავალი</th>
              <th class="right">შემოსავალი სხვა</th>
              <th class="right">გასავალი</th>
              <th class="right">საბოლოო ნაშთი</th>
            </tr>
          </thead>
          <tbody id="balRows"></tbody>
          <tfoot>
            <tr>
              <td class="right"><b>სულ</b></td>
              <td class="right mono" id="bStart">0.00</td>
              <td class="right mono" id="bInc">0.00</td>
              <td class="right mono" id="bOth">0.00</td>
              <td class="right mono" id="bExp">0.00</td>
              <td class="right mono" id="bEnd">0.00</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
// ---------- Utilities ----------
function qs(s,r){return (r||document).querySelector(s);}
function qsa(s,r){return Array.from((r||document).querySelectorAll(s));}
function money(n){ n=Number(n||0); return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function val(id){const el=qs('#'+id);return el?(el.type==='checkbox'?(el.checked?'1':''):el.value.trim()):'';}

// client-side label mirror of PHP
function mapMethodLabel(m){
  m = String(m||'').toLowerCase().trim();
  if(['bog','bank_bog','bog_bank','825','ბოგ','ბოღ','bank of georgia'].includes(m)) return 'BOG';
  if(['cash','34','სალარო','salaro','კეში','kesi','cashier'].includes(m)) return 'სალარო';
  if(['founder','446','დამფუძნებელი'].includes(m)) return 'დამფუძნებელი';
  if(['transfer','გადმორიცხვა','tbc','bank_tbc'].includes(m)) return 'გადმორიცხვა';
  if(['bank','ბანკი'].includes(m)) return 'ბანკი';
  return 'სხვა';
}

function csvFromMS(rootId){
  const root=qs('#'+rootId);
  return qsa('.ms-list input[type=checkbox]:checked', root).map(i=>i.value).join(',');
}
function setMSOptions(rootId, items, isMethod=false){
  const root=qs('#'+rootId);
  const list=qs('.ms-list', root);
  list.innerHTML='';
  (items||[]).forEach(v=>{
    const value = isMethod ? v.value : v;
    const label = isMethod ? (v.label || v.value) : v;
    const div=document.createElement('div'); div.className='ms-item';
    const id='ms_'+rootId+'_'+String(value).replace(/[^a-z0-9_]+/gi,'-');
    div.innerHTML = `<label for="${id}" style="display:flex;gap:8px;align-items:center;">
        <input id="${id}" type="checkbox" value="${value}">
        <span>${label}</span>
      </label>`;
    list.appendChild(div);
  });
  const btn=qs('.ms-btn', root), lab=qs('.ms-label', root);
  btn.onclick = ()=>{ list.style.display = (list.style.display==='block'?'none':'block'); };
  document.addEventListener('click', (e)=>{ if(!root.contains(e.target)) list.style.display='none'; });
  list.addEventListener('change', ()=>{
    const vals = qsa('input[type=checkbox]:checked', list).map(i=>i.nextElementSibling.textContent.trim());
    lab.textContent = vals.length? vals.join(', ') : 'აირჩიე';
    state.page=1; refreshAll();
  });
}

// ---------- Header user menu ----------
qs('#userBtn').addEventListener('click', ()=>{
  const dd=qs('#userDropdown');
  dd.style.display = dd.style.display==='block' ? 'none' : 'block';
});
document.addEventListener('click', (e)=>{
  const wrap=qs('.user-menu-wrap');
  if(wrap && !wrap.contains(e.target)){ qs('#userDropdown').style.display='none'; }
});

// ---------- State & params ----------
let state={page:1,pagesize:50,sort:'paid_at',dir:'desc',pages:1};

function toParams(extra={}){
  return Object.assign({
    action:'list',
    page:state.page, pagesize:state.pagesize, sort:state.sort, dir:state.dir,
    dat_from: val('sad_dat1'),
    dat_to:   val('sad_dat2'),
    personal: val('sad_docno'),
    fullname: val('sad_finam'),
    entry:    qs('#stac_typ').value||'',
    department_in: csvFromMS('ms-dept'),
    program:  qs('#program').value||'',
    method_in: csvFromMS('ms-method'),
    order_no: val('ord_no'),
    amount_from: val('amo_fr'),
    amount_to:   val('amo_to'),
  }, extra||{});
}

// ---------- Meta load ----------
function loadMeta(){
  return fetch('?action=meta',{credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{
      if(!j||j.status!=='ok') return;
      setMSOptions('ms-dept', j.departments||[]);
      const progSel= qs('#program'); progSel.innerHTML='<option value="">-ყველა-</option>' + (j.programs||[]).map(g=>`<option>${g}</option>`).join('');
      setMSOptions('ms-method', (j.methods||[]), true);
    });
}

// ---------- Incomes ----------
function renderIncomes(j){
  const tb=qs('#rows');
  tb.textContent='';
  const rows=j.rows||[];
  if(!rows.length){
    const tr=document.createElement('tr'); const td=document.createElement('td');
    td.colSpan=10; td.className='center'; td.textContent='ჩანაწერი არ არის';
    tr.appendChild(td); tb.appendChild(tr);
    qs('#sumAmt').textContent='0.00';
  }else{
    rows.forEach(r=>{
      const tr=document.createElement('tr');
      const add = (text, cls='')=>{
        const td=document.createElement('td'); if(cls) td.className=cls; td.textContent=text??''; tr.appendChild(td);
      };
      const addMonoRight = (text)=>{
        const td=document.createElement('td'); td.className='right';
        const sp=document.createElement('span'); sp.className='mono'; sp.textContent = text??''; td.appendChild(sp);
        tr.appendChild(td);
      };
      add((String(r.paid_at||'').slice(0,16)));
      add(r.seq_no??'');
      add(r.card_no??'');
      add(String(r.personal_id||''));
      add(r.full_name??'');
      add(mapMethodLabel(r.method||'')); // r.method canonicalized server-side
      add(r.order_no??'');
      addMonoRight(money(r.amount||0));
      add(r.author??'');
      add((String(r.when_at||'').slice(0,16)));
      tb.appendChild(tr);
    });
    qs('#sumAmt').textContent = money(j.sum_amount||0);
  }

  const total = j.total||0;
  state.pages = Math.max(1, Math.ceil(total / state.pagesize));
  qs('#lblPages').textContent = String(state.pages);

  qsa('.sort-ind').forEach(el=>el.textContent='');
  const active = qs('#s_'+(state.sort||'paid_at')); if (active) active.textContent = state.dir==='asc'?'▲':'▼';
}

function loadIncomes(){
  const p = new URLSearchParams(toParams({action:'list'}));
  const tb=qs('#rows'); tb.innerHTML='<tr><td colspan="10" class="center">იტვირთება…</td></tr>';
  fetch('?'+p.toString(),{credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{
      if(!j||j.status!=='ok'){ tb.innerHTML='<tr><td colspan="10" class="center">შეცდომა</td></tr>'; return; }
      renderIncomes(j);
    })
    .catch(()=>{ tb.innerHTML='<tr><td colspan="10" class="center">ქსელის შეცდომა</td></tr>'; });
}

// ---------- Expenses ----------
function loadExpenses(){
  const p = new URLSearchParams(toParams({action:'expenses'}));
  const tb=qs('#rowsOut'); tb.innerHTML='<tr><td colspan="6" class="center">იტვირთება…</td></tr>';
  fetch('?'+p.toString(),{credentials:'same-origin'})
   .then(r=>r.json())
   .then(j=>{
     tb.textContent='';
     if(!j||j.status!=='ok'){ tb.innerHTML='<tr><td colspan="6" class="center">შეცდომა</td></tr>'; return; }
     const rows=j.rows||[];
     if(!rows.length){ tb.innerHTML='<tr><td colspan="6" class="center">ჩანაწერი არ არის</td></tr>'; }
     let sum=0;
     rows.forEach(r=>{
       const tr=document.createElement('tr');
       const add=(text,cls='')=>{ const td=document.createElement('td'); if(cls) td.className=cls; td.textContent=text??''; tr.appendChild(td); };
       const addMonoRight=(text)=>{ const td=document.createElement('td'); td.className='right'; const sp=document.createElement('span'); sp.className='mono'; sp.textContent=text??''; td.appendChild(sp); tr.appendChild(td); };
       add(String(r.paid_at||'').slice(0,16));
       add(mapMethodLabel(r.method||'')); // canonical
       add(r.payee || '-');
       addMonoRight(money(r.amount||0));
       add(r.author||'');
       add(String(r.paid_at||'').slice(0,16));
       sum += Number(r.amount||0);
       tb.appendChild(tr);
     });
     qs('#sumOut').textContent = money(sum);
   })
   .catch(()=>{ tb.innerHTML='<tr><td colspan="6" class="center">ქსელის შეცდომა</td></tr>'; });
}

// ---------- Balance ----------
function loadBalance(){
  const p = new URLSearchParams(toParams({action:'balance'}));
  const tb=qs('#balRows'); tb.innerHTML='';
  fetch('?'+p.toString(),{credentials:'same-origin'})
   .then(r=>r.json())
   .then(j=>{
     tb.textContent='';
     if(!j||j.status!=='ok'){ const tr=document.createElement('tr'); const td=document.createElement('td'); td.colSpan=6; td.className='center'; td.textContent='შეცდომა'; tr.appendChild(td); tb.appendChild(tr); return; }
     const dict=j.rows||{};
     const order=['cash','bog','founder','transfer','bank','other'];
     order.forEach(k=>{
       const r=dict[k]; if(!r) return;
       const tr=document.createElement('tr');
       const add=(text,cls='')=>{ const td=document.createElement('td'); if(cls) td.className=cls; td.textContent=text; tr.appendChild(td); };
       add(r.label||k);
       add(money(r.start||0),'right mono');
       add(money(r.income||0),'right mono');
       add(money(r.other||0),'right mono');
       add(money(r.expense||0),'right mono');
       add(money(r.end||0),'right mono');
       tb.appendChild(tr);
     });

     const T=j.total||{start:0,income:0,other:0,expense:0,end:0};
     qs('#bStart').textContent = money(T.start||0);
     qs('#bInc').textContent   = money(T.income||0);
     qs('#bOth').textContent   = money(T.other||0);
     qs('#bExp').textContent   = money(T.expense||0);
     qs('#bEnd').textContent   = money(T.end||0);
   })
   .catch(()=>{ const tr=document.createElement('tr'); const td=document.createElement('td'); td.colSpan=6; td.className='center'; td.textContent='ქსელის შეცდომა'; tr.appendChild(td); tb.appendChild(tr); });
}

// ---------- Sorting ----------
qsa('#tbl thead th[data-k]').forEach(th=>{
  th.addEventListener('click',()=>{
    const key=th.getAttribute('data-k');
    if(state.sort===key){ state.dir=(state.dir==='asc'?'desc':'asc'); } else { state.sort=key; state.dir='asc'; }
    state.page=1; loadIncomes();
  });
});

// ---------- Events ----------
function refreshAll(){ loadIncomes(); loadExpenses(); loadBalance(); }

['sad_dat1','sad_dat2','sad_docno','sad_finam','stac_typ','program','amo_fr','amo_to','ord_no'].forEach(id=>{
  const el=qs('#'+id); if(!el) return;
  el.addEventListener('change', ()=>{ state.page=1; refreshAll(); });
  el.addEventListener('keyup', (e)=>{ if(e.key==='Enter'){ state.page=1; refreshAll(); } });
});

qs('#sad_fltb').addEventListener('click', ()=>{ state.page=1; refreshAll(); });
qs('#btnClear').addEventListener('click', ()=>{
  ['sad_dat1','sad_dat2','sad_docno','sad_finam','ord_no','amo_fr','amo_to'].forEach(id=>{ const el=qs('#'+id); if(el) el.value=''; });
  qs('#stac_typ').value='';
  qs('#program').value='';
  qsa('#ms-dept .ms-list input[type=checkbox], #ms-method .ms-list input[type=checkbox]').forEach(ch=>ch.checked=false);
  qsa('#ms-dept .ms-label, #ms-method .ms-label').forEach(l=>l.textContent='აირჩიე');
  state.page=1; refreshAll();
});

// pager
qs('#btnPrev').addEventListener('click', ()=>{ if(state.page>1){ state.page--; loadIncomes(); }});
qs('#btnNext').addEventListener('click', ()=>{ if(state.page<state.pages){ state.page++; loadIncomes(); }});
qs('#inpPage').addEventListener('keydown', e=>{ if(e.key==='Enter'){ let v=parseInt(e.target.value||'1',10); if(!v||v<1)v=1; if(v>state.pages)v=state.pages; state.page=v; loadIncomes(); }});
qs('#selPageSize').addEventListener('change', e=>{ state.pagesize = Math.max(10, Math.min(parseInt(e.target.value,10)||50, 200)); state.page=1; loadIncomes(); });

// exports
const goExpIn = ()=>{ const p=new URLSearchParams(toParams({action:'export_incomes'})); window.location.href='?'+p.toString(); };
const goExpOut= ()=>{ const p=new URLSearchParams(toParams({action:'export_expenses'})); window.location.href='?'+p.toString(); };
qs('#btnExpIn').addEventListener('click', goExpIn);
qs('#btnExpOut').addEventListener('click', goExpOut);

// ---------- Init ----------
(function(){
  state.pagesize = parseInt(qs('#selPageSize').value,10) || 50;
  loadMeta().then(()=> refreshAll());
})();
</script>
</body>
</html>
