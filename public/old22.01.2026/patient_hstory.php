<?php
// ---- patient.Tabs setup (put once, before rendering the header) ----
function current_file(): string {
  // robust even with proxies and query strings
  $path = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  return basename($path ?: 'index.php');
}


// Current file (for active state)
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


// Optional: don’t render the full layout for AJAX calls
$is_ajax = ($_SERVER['REQUEST_METHOD'] === 'POST') || !empty($_GET['action']);


if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_guard(): void {
  $tok = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)$tok)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(419);
    echo json_encode(['status'=>'error','message'=>'csrf_failed']);
    exit;
  }
}

// (remove the stateless define; we are not stateless)
// define('STATELESS_MODE', true);

require __DIR__ . '/../config/config.php'; // must define $pdo (PDO, ERRMODE_EXCEPTION)

// Dompdf autoloader - check both locations
$DOMPDF_AVAILABLE = false;
$vendorPaths = [
  __DIR__ . '/vendor/autoload.php',
  __DIR__ . '/../vendor/autoload.php'
];
foreach ($vendorPaths as $vp) {
  if (file_exists($vp)) {
    require $vp;
    $DOMPDF_AVAILABLE = true;
    break;
  }
}

use Dompdf\Dompdf;
use Dompdf\Options;

/* ===================== Supplier / Organization Info for Printing ===================== */
$ORG = [
  'title'       => '„სანმედი“ -',
  'legal_name'  => 'ისნის რაიონის გამგეობა',
  'tax_id'      => '405695323',
  'address_1'   => 'ერთიანობისთვის მებრძოლთა',
  'address_2'   => 'ქუჩა N55',
  'phones'      => '555550845 / 558291614',
  'bank_name'   => 'სს "ბანკი"',
  'bank_code'   => 'BAGAGE22',
  'iban'        => 'GE02BG0000000589324177',
];

/* ===================== Helpers & Bootstrap (v8) ===================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf2($v){
  if (!is_numeric($v)) $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
  return number_format((float)$v, 2, '.', '');
}
function mapMethodServer($v){
  $v = strtolower(trim((string)$v));
  if (in_array($v, ['825','bog','bank_bog','bog_bank','ბოგ','ბოღ','bank of georgia'], true)) return 'bog';
  if (in_array($v, ['858','transfer','გადმორიცხვა'], true)) return 'transfer';
  if (in_array($v, ['34','cash','სალარო','salaro','კეში','kesi','cashier'], true)) return 'cash';
  if ($v === 'donor') return 'donor';
  return $v ?: 'cash';
}
function generateOrderSeq(PDO $pdo, string $prefix='INV'): string {
  $today = date('Y-m-d');

  // Map prefix → table/column used for daily sequencing
  $table   = 'invoices';
  $dateCol = 'issued_at';
  if (strtoupper($prefix) === 'PAY') {
    $table   = 'payments';
    $dateCol = 'paid_at';
  }

  $sql = "SELECT COUNT(*) FROM `$table` WHERE DATE(`$dateCol`) = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$today]);
  $n = (int)$st->fetchColumn() + 1;

  return sprintf('%s-%s-%04d', strtoupper($prefix), date('Ymd'), $n);
}


$DOMPDF_AVAILABLE = class_exists('\\Dompdf\\Dompdf');
if (!$DOMPDF_AVAILABLE) {
  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (is_file($autoload)) { require_once $autoload; $DOMPDF_AVAILABLE = class_exists('\\Dompdf\\Dompdf'); }
}
header_remove('X-Powered-By'); // პატარა hardening

/* ---- Simple auth redirect for browser GET (non-AJAX) ---- */
$logged_in = isset($_SESSION['user_id']);
if (!$logged_in && ($_SERVER['REQUEST_METHOD'] === 'GET') && empty($_GET['action'])) {
  header('Location: index.php'); exit;
}

/* ---- Session setup ---- */
$_SESSION['opened_patients']   = isset($_SESSION['opened_patients']) ? array_values(array_unique(array_map('intval', $_SESSION['opened_patients']))) : [];
$_SESSION['active_patient_id'] = isset($_SESSION['active_patient_id']) ? (int)$_SESSION['active_patient_id'] : 0;

function addAndActivatePatient(int $pid): void {
  if ($pid<=0) return;
  $_SESSION['opened_patients'] = array_values(array_unique(array_map('intval', $_SESSION['opened_patients'])));
  if (!in_array($pid, $_SESSION['opened_patients'], true)) {
    $_SESSION['opened_patients'][] = $pid;
  }
  $_SESSION['active_patient_id'] = $pid;
}

/* ---- JSON guard ---- */
function json_guard_auth() {
  if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'error','message'=>'auth_required']);
    exit;
  }
}
/* Accept JSON bodies too (merge into $_POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false && empty($_POST)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
      foreach ($json as $k => $v) {
        if (!array_key_exists($k, $_POST)) $_POST[$k] = $v;
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['action']??'')==='buyer_search') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim((string)($_GET['q'] ?? ''));
  if (mb_strlen($q, 'UTF-8') < 2) { echo json_encode(['rows'=>[]]); exit; }

  $like = '%'.$q.'%';
  $rows = [];
  $seen = [];

  // helper to push unique rows by (name|addr) key
  $push = function($id,$name,$addr='') use (&$rows,&$seen){
    $k = mb_strtolower(trim($name.'|'.$addr),'UTF-8');
    if ($name==='' || isset($seen[$k])) return;
    $seen[$k] = true;
    $rows[] = ['id'=>$id, 'name'=>$name, 'address'=>$addr ?: '-'];
  };

  // 1) donors table (if it exists)
  try {
    $st = $pdo->prepare("SELECT id, name, COALESCE(address,'') AS address
                           FROM donors
                          WHERE name LIKE ? OR address LIKE ?
                          ORDER BY name ASC
                          LIMIT 20");
    $st->execute([$like,$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $push('donor:'.$r['id'], (string)$r['name'], (string)$r['address']);
    }
  } catch (Throwable $e) { /* table may not exist */ }

  // 2) distinct donors from patient_guarantees (string column)
  try {
    $st = $pdo->prepare("SELECT DISTINCT donor AS name
                           FROM patient_guarantees
                          WHERE donor IS NOT NULL AND donor <> '' AND donor LIKE ?
                          ORDER BY donor ASC
                          LIMIT 20");
    $st->execute([$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $push('guar:'.md5($r['name']), (string)$r['name'], '');
    }
  } catch (Throwable $e) { /* ignore */ }

  // 3) fallback: allow choosing a patient as payer/donor
  try {
    $st = $pdo->prepare("SELECT id,
                                CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS full_name,
                                COALESCE(personal_id,'') AS pid
                           FROM patients
                          WHERE first_name LIKE ? OR last_name LIKE ? OR personal_id LIKE ?
                          ORDER BY id DESC
                          LIMIT 20");
    $st->execute([$like,$like,$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $nm  = trim((string)$r['full_name']) ?: '—';
      $pid = (string)$r['pid'];
      $addr = $pid ? ('პ/ნ: '.$pid) : '';
      $push('patient:'.$r['id'], $nm, $addr);
    }
  } catch (Throwable $e) { /* ignore */ }

  echo json_encode(['rows'=>$rows]); exit;
}


// ATTACH donor guarantee to a specific invoice
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='attach_donor_to_invoice')) {
  header('Content-Type: application/json; charset=utf-8'); json_guard_auth();

  $invoice_id   = (int)($_POST['invoice_id'] ?? 0);
  $guarantee_id = (int)($_POST['guarantee_id'] ?? 0);
  if ($invoice_id <= 0 || $guarantee_id <= 0) {
    echo json_encode(['status'=>'error','message'=>'bad params']); exit;
  }

  try {
    $pdo->beginTransaction();

    // sanity: guarantee and invoice must exist and belong to the same patient
    $st = $pdo->prepare("
      SELECT i.patient_id AS ip, g.patient_id AS gp
      FROM invoices i
      JOIN patient_guarantees g ON g.id = ?
      WHERE i.id = ?
      FOR UPDATE
    ");
    $st->execute([$guarantee_id, $invoice_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { throw new RuntimeException('invoice/guarantee not found'); }
    if ((int)$row['ip'] !== (int)$row['gp']) { throw new RuntimeException('patient mismatch'); }

    $u = $pdo->prepare("UPDATE invoices SET donor_guarantee_id=? WHERE id=?");
    $u->execute([$guarantee_id, $invoice_id]);

    $pdo->commit();
    echo json_encode(['status'=>'ok']); exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
  }
}

// DETACH donor guarantee from an invoice
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='detach_donor_from_invoice')) {
  header('Content-Type: application/json; charset=utf-8'); json_guard_auth();

  $invoice_id = (int)($_POST['invoice_id'] ?? 0);
  if ($invoice_id <= 0) { echo json_encode(['status'=>'error','message'=>'bad params']); exit; }

  try {
    $st = $pdo->prepare("UPDATE invoices SET donor_guarantee_id=NULL WHERE id=?");
    $st->execute([$invoice_id]);
    echo json_encode(['status'=>'ok']); exit;

  } catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='attach_donor_to_patient') {
  header('Content-Type: application/json; charset=utf-8'); json_guard_auth();
  echo json_encode(['status'=>'ok']); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='detach_donor_from_patient') {
  header('Content-Type: application/json; charset=utf-8'); json_guard_auth();
  echo json_encode(['status'=>'ok']); exit;
}
/* --- UPDATE PAYMENT (supports donor edits with balance checks) --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='update_payment')) {
  header('Content-Type: application/json; charset=utf-8');
  json_guard_auth();

  $pid        = (int)($_POST['patient_id'] ?? 0);
  $pay_id     = (int)($_POST['payment_id'] ?? 0);
  $paid_at    = trim((string)($_POST['paid_at'] ?? ''));
  $method     = mapMethodServer($_POST['method'] ?? '');
  $amountRaw  = ($_POST['amount'] ?? 0);
  $amount     = (float)$amountRaw; // nf2 later on write
  $order_no   = trim((string)($_POST['order_no'] ?? ''));
  $donor_id   = (int)($_POST['donor_id'] ?? 0); // optional, only for donor edits

  if ($pid<=0 || $pay_id<=0) { echo json_encode(['status'=>'error','message'=>'bad params']); exit; }
  if (!($amount>0)) { echo json_encode(['status'=>'error','message'=>'თანხა უნდა იყოს > 0']); exit; }
  if (!in_array($method, ['cash','bog','transfer','donor'], true)) {
    echo json_encode(['status'=>'error','message'=>'ტიპი არასწორია']); exit;
  }

  try {
    $pdo->beginTransaction();

    // Lock current payment
    $st = $pdo->prepare("SELECT patient_id, LOWER(method) AS method, guarantee_id FROM payments WHERE id=? FOR UPDATE");
    $st->execute([$pay_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['patient_id'] !== $pid) {
      throw new RuntimeException('ჩანაწერი ვერ მოიძებნა');
    }

    $oldMethod = (string)($row['method'] ?? '');
    $oldGuarId = (int)($row['guarantee_id'] ?? 0);

    if ($paid_at === '') $paid_at = date('Y-m-d H:i:s');

    // Helper: table exists?
    $tblExists = function(PDO $pdo, string $t): bool {
      $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
      $q->execute([$t]); return (bool)$q->fetchColumn();
    };
    $hasUsages = $tblExists($pdo, 'guarantee_usages');
    
    // 🔍 DEBUG: Log if guarantee_usages table exists
    error_log("🔍 [update_payment] guarantee_usages table exists: " . ($hasUsages ? 'YES' : 'NO'));
    
    // 🆕 AUTO-CREATE guarantee_usages table if it doesn't exist
    if (!$hasUsages) {
      error_log("🔧 [update_payment] Creating guarantee_usages table...");
      try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS guarantee_usages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id INT NOT NULL,
            guarantee_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment (payment_id),
            INDEX idx_guarantee (guarantee_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $hasUsages = true;
        error_log("✅ [update_payment] guarantee_usages table created successfully!");
      } catch (Throwable $e) {
        error_log("❌ [update_payment] Failed to create guarantee_usages table: " . $e->getMessage());
      }
    }

    // ============= DONOR BRANCH =============
    if ($method === 'donor') {
      // choose target guarantee: prefer explicit donor_id else keep existing
      $targetGuarId = $donor_id ?: $oldGuarId;
      if ($targetGuarId <= 0) {
        throw new RuntimeException('აირჩიე ავანსატორი (დონორი)');
      }

      // sanity: guarantee must belong to this patient
      $gInfo = $pdo->prepare("
        SELECT id, amount, COALESCE(is_virtual_advance,0) AS is_wallet
        FROM patient_guarantees
        WHERE id=? AND patient_id=?
        FOR UPDATE
      ");
      $gInfo->execute([$targetGuarId, $pid]);
      $g = $gInfo->fetch(PDO::FETCH_ASSOC);
      if (!$g) throw new RuntimeException('საგარანტიო ჩანაწერი ვერ მოიძებნა');

      // left = total - usages (EXCLUDING this payment's usage, so edits can reuse their own amount)
      $leftQ = $pdo->prepare("
        SELECT (g.amount - COALESCE((
          SELECT SUM(u.amount) FROM guarantee_usages u
          WHERE u.guarantee_id = g.id
            AND u.payment_id <> ?
        ),0)) AS left_amount
        FROM patient_guarantees g
        WHERE g.id = ?
        FOR UPDATE
      ");
      $leftQ->execute([$pay_id, $targetGuarId]);
      $left = (float)($leftQ->fetchColumn() ?? 0.0);

      // Enough balance?
      if ($amount > $left + 0.00001) {
        // your requested error text:
        throw new RuntimeException('დონორს არ აქვს საკმარისი ნაშთი');
      }

      // Update the payment row
      $u = $pdo->prepare("UPDATE payments SET paid_at=?, method='donor', amount=?, order_no=?, guarantee_id=? WHERE id=?");
      $u->execute([$paid_at, nf2($amount), ($order_no ?: null), $targetGuarId, $pay_id]);

      // Upsert the usage row to match the new amount/guarantee
      if ($hasUsages) {
        error_log("🔍 [update_payment] Attempting to INSERT/UPDATE guarantee_usages for payment_id={$pay_id}, guarantee_id={$targetGuarId}, amount={$amount}");
        
        $selU = $pdo->prepare("SELECT id FROM guarantee_usages WHERE payment_id=? LIMIT 1 FOR UPDATE");
        $selU->execute([$pay_id]);
        $uid = (int)($selU->fetchColumn() ?: 0);
        if ($uid > 0) {
          error_log("✏️ [update_payment] UPDATING existing guarantee_usage id={$uid}");
          $pdo->prepare("UPDATE guarantee_usages SET guarantee_id=?, amount=? WHERE id=?")
              ->execute([$targetGuarId, nf2($amount), $uid]);
        } else {
          error_log("➕ [update_payment] INSERTING new guarantee_usage");
          $pdo->prepare("INSERT INTO guarantee_usages (payment_id, guarantee_id, amount, created_at) VALUES (?,?,?,NOW())")
              ->execute([$pay_id, $targetGuarId, nf2($amount)]);
        }
        error_log("✅ [update_payment] guarantee_usages operation completed successfully");
      } else {
        error_log("⚠️ [update_payment] guarantee_usages table DOES NOT EXIST - skipping usage tracking!");
      }

      $pdo->commit();
      echo json_encode(['status'=>'ok','row'=>[
        'id'=>$pay_id,
        'paid_at'=>$paid_at,
        'method'=>'donor',
        'amount'=>nf2($amount),
        'order_no'=>$order_no,
        'guarantee_id'=>$targetGuarId
      ]]);
      exit;
    }

    // ============= NON-DONOR BRANCH =============
    // If we are switching away from donor → drop usages and clear guarantee_id
    if ($oldMethod === 'donor') {
      if ($hasUsages) {
        $pdo->prepare("DELETE FROM guarantee_usages WHERE payment_id=?")->execute([$pay_id]);
      }
      $pdo->prepare("UPDATE payments SET guarantee_id=NULL WHERE id=?")->execute([$pay_id]);
    }

    $u = $pdo->prepare("UPDATE payments SET paid_at=?, method=?, amount=?, order_no=? WHERE id=?");
    $u->execute([$paid_at, $method, nf2($amount), ($order_no ?: null), $pay_id]);

    $pdo->commit();
    echo json_encode([
      'status'=>'ok',
      'row'=>[
        'id'=>$pay_id,
        'paid_at'=>$paid_at,
        'method'=>$method,
        'amount'=>nf2($amount),
        'order_no'=>$order_no
      ]
    ]);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
  }
}


/* ---- Forget-all (server) ---- */
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'forget_all')) {

  header_remove('X-Powered-By');
  header('Content-Type: application/json; charset=utf-8');

  // Option A: only clear app memory, keep auth
  $_SESSION['opened_patients']   = [];
  $_SESSION['active_patient_id'] = 0;

  // Option B (full wipe) if requested: destroy entire session (logout)
  $full = isset($_POST['full']) ? (bool)$_POST['full'] : (bool)($_GET['full'] ?? false);
  if ($full) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
  }

  echo json_encode(['status' => 'ok']);
  exit;
}

/* ===================== POST actions (v8) ===================== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  header_remove('X-Powered-By');
  header('Content-Type: application/json; charset=utf-8');
  json_guard_auth();

  $action = $_POST['action'] ?? '';
  if ($action === '') {
      // Nothing matched: fail as JSON, never fall through to render HTML
  echo json_encode(['status'=>'error','message'=>'unknown_action','debug'=>['action'=>$action]]);
  exit;
} // <-- end POST router

  /* --- close_opened (fixed precedence) --- */
  if ($action==='close_opened') {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id>0) {
      $_SESSION['opened_patients'] = array_values(array_filter($_SESSION['opened_patients'], fn($x)=>(int)$x !== $id));
      if ((int)$_SESSION['active_patient_id']===$id) {
        $_SESSION['active_patient_id'] = !empty($_SESSION['opened_patients']) ? (int)end($_SESSION['opened_patients']) : 0;
      }
      echo json_encode(['status'=>'ok']); exit;
    }
    echo json_encode(['status'=>'error','message'=>'bad id']); exit;
  }

  /* --- add_or_activate (unchanged) --- */
  if ($action === 'add_or_activate') {
    $pid = (int)($_POST['patient_id'] ?? 0);
    if ($pid > 0) {
      addAndActivatePatient($pid);
      echo json_encode([
        'status'            => 'ok',
        'opened_patients'   => array_values($_SESSION['opened_patients']),
        'active_patient_id' => (int)($_SESSION['active_patient_id'] ?? 0),
      ]);
    } else {
      echo json_encode(['status'=>'error','message'=>'არასწორი პაციენტის ID']);
    }
    exit;
  }

  /* --- save_guarantee (unchanged) --- */
  if ($action === 'save_guarantee') {
    $pid    = (int)$_POST['patient_id'] ?? 0;
    $donor  = trim($_POST['donor'] ?? '');
    $amount = (float)$_POST['amount'] ?? 0;
    $gdate  = trim($_POST['guarantee_date'] ?? '');
    $vdate  = trim($_POST['validity_date'] ?? '');
    $gnum   = trim($_POST['guarantee_number'] ?? '');
    $gcomm  = trim($_POST['guarantee_comment'] ?? '');

    if ($pid <= 0 || $amount <= 0) {
      echo json_encode(['status'=>'error','message'=>'არასწორი მონაცემი']); exit;
    }

    try {
      $stmt = $pdo->prepare("
        INSERT INTO patient_guarantees
          (patient_id, is_virtual_advance, donor, amount, guarantee_date, validity_date, guarantee_number, guarantee_comment)
        VALUES (?,?,?,?,?,?,?,?)
      ");
      $ok = $stmt->execute([
        $pid, 1, $donor, $amount,
        ($gdate ?: null), ($vdate ?: null), ($gnum ?: null), ($gcomm ?: null)
      ]);
      echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $ok ? '' : 'ვერ ჩაიწერა']);
    } catch (Throwable $e) {
      echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
  }
/* --- CREATE PREPAYMENT (patient deposit into wallet) --- */
if ($action === 'create_prepayment') {
  header('Content-Type: application/json; charset=utf-8');
  json_guard_auth();

  $pid     = (int)($_POST['patient_id'] ?? ($_SESSION['active_patient_id'] ?? 0));
  $amount  = (float)($_POST['amount'] ?? 0);
  $method  = mapMethodServer($_POST['method'] ?? 'cash'); // cash/bog/transfer
  $paid_at = trim((string)($_POST['paid_at'] ?? '')) ?: date('Y-m-d H:i:s');
  $label   = trim((string)($_POST['label'] ?? 'პაციენტის ავანსი'));
  $order   = trim((string)($_POST['order_no'] ?? '')) ?: generateOrderSeq($pdo, 'PAY');

  if ($pid <= 0) { echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ განისაზღვრა']); exit; }
  if (!in_array($method, ['cash','bog','transfer'], true)) {
    echo json_encode(['status'=>'error','message'=>'არასწორი გადახდის ტიპი']); exit;
  }
  if (!($amount > 0)) { echo json_encode(['status'=>'error','message'=>'თანხა უნდა იყოს > 0']); exit; }

  try {
    $pdo->beginTransaction();

    // 1) Wallet row (virtual advance)
    $st = $pdo->prepare("
      INSERT INTO patient_guarantees
        (patient_id, is_virtual_advance, donor, amount, guarantee_date, validity_date, guarantee_number, guarantee_comment)
      VALUES (?,?,?,?,?,NULL,NULL,NULL)
    ");
    $st->execute([$pid, 1, $label, nf2($amount), $paid_at]);
    $guarId = (int)$pdo->lastInsertId();

    // 2) Real receipt (cash/bog/transfer) linked to that wallet
    $st = $pdo->prepare("
      INSERT INTO payments (patient_id, paid_at, method, amount, order_no, guarantee_id)
      VALUES (?,?,?,?,?,?)
    ");
    $st->execute([$pid, $paid_at, $method, nf2($amount), $order, $guarId]);

    $pdo->commit();
    echo json_encode(['status'=>'ok','guarantee_id'=>$guarId,'order_no'=>$order,'paid_at'=>$paid_at,'amount'=>nf2($amount),'label'=>$label]);
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>'DB შეცდომა: '.$e->getMessage()]); exit;
  }
}

  /* --- INSERT PAYMENT (DB) + donor usage (supports old and new names) --- */
  if ($action==='insert_payment' || $action==='insert_payment_demo') {
    $pid      = (int)($_POST['patient_id'] ?? ($_SESSION['active_patient_id'] ?? 0));
    $paid_at  = trim($_POST['paid_at'] ?? ''); if ($paid_at==='') $paid_at = date('Y-m-d H:i:s');
    $method   = mapMethodServer($_POST['method'] ?? 'cash');
    $amount   = (float)($_POST['amount'] ?? 0);
    $order    = trim($_POST['order_no'] ?? '');
    $donor_id = (int)($_POST['donor_id'] ?? 0);
    $don_appl = max(0.0, (float)($_POST['donor_applied'] ?? 0));

    if ($pid<=0) { echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ განისაზღვრა']); exit; }
    if ($order === '') {
      $order = 'ORD-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(2)),0,4);
    }
    if (!($amount > 0) && !($don_appl > 0)) {
      echo json_encode(['status'=>'error','message'=>'შეიყვანე თანხა ან აირჩიე ავანსატორი']); exit;
    }
    if ($don_appl > 0 && $donor_id <= 0) {
      echo json_encode(['status'=>'error','message'=>'აირჩიე ავანსატორი (დონორი)']); exit;
    }

    $pdo->beginTransaction();
    try{
      // Real payment
      if ($amount > 0){
        $st = $pdo->prepare("INSERT INTO payments (patient_id, paid_at, method, amount, order_no, guarantee_id) VALUES (?,?,?,?,?,NULL)");
        $st->execute([$pid, $paid_at, $method, nf2($amount), $order]);
      }

      // Donor application -> donor payment row + guarantee_usages row
      if ($donor_id > 0 && $don_appl > 0){
        // Lock selected guarantee row to avoid race-conditions
        $st = $pdo->prepare("
          SELECT (g.amount - COALESCE((SELECT SUM(u.amount) FROM guarantee_usages u WHERE u.guarantee_id=g.id),0)) AS left_amount
          FROM patient_guarantees g
          WHERE g.id=? AND g.patient_id=?
          FOR UPDATE
        ");
        $st->execute([$donor_id, $pid]);
        $left = (float)($st->fetchColumn() ?? 0.0);
        $use  = min($left, $don_appl);
        if ($use <= 0) throw new RuntimeException('საგარანტიო ნაშთი არასაკმარისია');

        // Insert donor payment row
        $st = $pdo->prepare("INSERT INTO payments (patient_id, paid_at, method, amount, order_no, guarantee_id) VALUES (?,?,?,?,?,?)");
        $st->execute([$pid, $paid_at, 'donor', nf2($use), $order, $donor_id]);
        $donPayId = (int)$pdo->lastInsertId();

        // Usage row
        $st = $pdo->prepare("INSERT INTO guarantee_usages (payment_id, guarantee_id, amount, created_at) VALUES (?,?,?,NOW())");
        $st->execute([$donPayId, $donor_id, nf2($use)]);
      }

      $pdo->commit();
      echo json_encode(['status'=>'ok','order_no'=>$order]); exit;
    } catch (Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status'=>'error','message'=>'DB შეცდომა: '.$e->getMessage()]); exit;
    }
  }
  /* --- CREATE SERVICE INVOICE (DB) + PDF/HTML (supports old and new names) --- */
  if ($action === 'create_invoice' || $action === 'create_invoice_demo') {
    $pid         = (int)($_POST['patient_id'] ?? ($_SESSION['active_patient_id'] ?? 0));
    $ids_raw     = trim($_POST['service_ids'] ?? ''); // e.g. "28@29@31@"
    $service_ids = array_values(array_unique(array_filter(array_map('intval', explode('@', $ids_raw)), fn($x)=>$x>0)));

    if ($pid <= 0) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ განისაზღვრა']); exit; }
    if (empty($service_ids)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','message'=>'აირჩიეთ სერვისები ინვოისისთვის']); exit; }

    // -------- helpers (local) ----------
    $nf2_local = function($v){
      if (!is_numeric($v)) $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
      return number_format((float)$v, 2, '.', '');
    };
    $moneyWords = function($amount){
      $gel = (int)floor((float)$amount);
      $tet = (int)round(((float)$amount - $gel) * 100);
      $u = ['ნული','ერთი','ორი','სამი','ოთხი','ხუთი','ექვსი','შვიდი','რვა','ცხრა'];
      $tens = ['','ათი','ოცი','ოცდაათი','ორმოცი','ორმოცდაათი','სამოცი','სამოცდაათი','ოთხმოცი','ოთხმოცდაათი'];
      $hund = ['','ასი','ორასი','სამასი','ოთხასი','ხუთასი','ექვსასი','შვიდასი','რვაასი','ცხრაასი'];
      $sub99 = function($x) use($u,$tens){
        if($x<10) return $u[$x];
        if($x===10) return 'ათი';
        if($x>10 && $x<20){
          $teen=['თერთმეტი','თორმეტი','ცამეტი','თოთხმეტი','თხუთმეტი','თექვსმეტი','ჩვიდმეტი','თვრამეტი','ცხრამეტი'];
          return $teen[$x-11];
        }
        $t=(int)floor($x/10); $r=$x%10;
        if(in_array($t,[2,4,6,8],true) && $r===0) return $tens[$t];
        if($r===0) return $tens[$t];
        if(in_array($t,[2,4,6,8],true)) return preg_replace('/ი$/','',$tens[$t]).'და'.$u[$r];
        return $tens[$t].' და '.$u[$r];
      };
      $sub999 = function($x) use($sub99,$hund){
        if($x<100) return $sub99($x);
        $h=(int)floor($x/100); $r=$x%100; $w=$hund[$h];
        return $r ? ($w.' '.$sub99($r)) : $w;
      };
      $sub999999 = function($x) use($sub999){
        if($x<1000) return $sub999($x);
        $th=(int)floor($x/1000); $r=$x%1000;
        $thW = ($th===1) ? 'ათასი' : ($sub999($th).' ათასი');
        return $r ? ($thW.' '.$sub999($r)) : $thW;
      };
      $words = $sub999999($gel);
      return "ასანაზღაურებელი თანხა სიტყვებით: {$words} ლარი და ".str_pad((string)$tet,2,'0',STR_PAD_LEFT)." თეთრი";
    };

    try {
      // Load selected service rows
      $in = implode(',', array_fill(0, count($service_ids), '?'));
      $params = $service_ids; array_unshift($params, $pid);
      $q = $pdo->prepare("
        SELECT ps.id, ps.quantity,
               COALESCE(ps.unit_price, s.price) AS unit_price,
               COALESCE(ps.sum, ps.quantity * COALESCE(ps.unit_price, s.price)) AS line_total,
               s.name AS service_name
        FROM patient_services ps
        JOIN services s ON ps.service_id = s.id
        WHERE ps.patient_id = ? AND ps.id IN ($in)
        ORDER BY ps.created_at ASC, ps.id ASC
      ");
      $q->execute($params);
      $rows = $q->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) { throw new RuntimeException('არჩეული სერვისები ვერ მოიძებნა'); }

      $total = 0.0; foreach ($rows as $r) $total += (float)$r['line_total'];

      // Generate order + insert invoice & items
      $order_no = generateOrderSeq($pdo, 'INV');
      $attempts = 0; $invoice_id = 0;

      while (true) {
        try {
          $pdo->beginTransaction();

          // invoice header
          $ins = $pdo->prepare("
            INSERT INTO invoices (patient_id, order_no, total_amount, issued_at, created_by, notes, donor_guarantee_id)
            VALUES (?,?,?,NOW(),?,'draft',NULL)
          ");
          $ins->execute([$pid, $order_no, $nf2_local($total), (int)($_SESSION['user_id'] ?? null)]);
          $invoice_id = (int)$pdo->lastInsertId();

          // items
          $insItemNew = $pdo->prepare("
            INSERT INTO invoice_items (invoice_id, patient_service_id, description, quantity, unit_price, line_total, comment)
            VALUES (?,?,?,?,?,?,?)
          ");
          $insItemOld = $pdo->prepare("
            INSERT INTO invoice_items (invoice_id, patient_service_id, quantity, unit_price, line_total, comment)
            VALUES (?,?,?,?,?,?)
          ");

          foreach ($rows as $r) {
            $qty  = (float)$r['quantity'];
            $unit = (float)$r['unit_price'];
            $sum  = (float)$r['line_total'];
            $title = (string)($r['service_name'] ?? '—');
            try {
              $insItemNew->execute([$invoice_id, (int)$r['id'], $title, $qty, $unit, $sum, null]);
            } catch (PDOException $e) {
              if ($e->getCode()==='42S22' || stripos($e->getMessage(),'Unknown column')!==false) {
                $insItemOld->execute([$invoice_id, (int)$r['id'], $qty, $unit, $sum, $title]);
              } else { throw $e; }
            }
          }

          $pdo->commit();
          break;
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          if ($e instanceof PDOException && $e->getCode()==='23000' && $attempts < 3) {
            usleep(50000); $order_no = generateOrderSeq($pdo, 'INV'); $attempts++; continue;
          }
          throw $e;
        }
      }

      // --------- fetch patient header info ----------
      $patQ = $pdo->prepare("SELECT personal_id, first_name, last_name FROM patients WHERE id=?");
      $patQ->execute([$pid]);
      $pat = $patQ->fetch(PDO::FETCH_ASSOC) ?: ['personal_id'=>'','first_name'=>'','last_name'=>''];

      // --------- compute "state" figures (soft, SQL-based when available) ----------
      $state_limit  = 0.0;
      try {
        $col = $pdo->query("SHOW COLUMNS FROM patient_insurance LIKE 'limit_amount'")->fetch();
        if ($col) {
          $limQ = $pdo->prepare("
            SELECT COALESCE(SUM(pi.limit_amount),0)
            FROM invoice_items ii
            JOIN patient_services ps ON ps.id = ii.patient_service_id
            LEFT JOIN patient_insurance pi ON pi.patient_id = ps.patient_id AND pi.service_id = ps.service_id
            WHERE ii.invoice_id = ?
          ");
          $limQ->execute([$invoice_id]);
          $state_limit = (float)$limQ->fetchColumn();
        } else {
          try {
            $limQ2 = $pdo->prepare("SELECT COALESCE(SUM(limit_amount),0) FROM v_invoice_limits WHERE invoice_id=?");
            $limQ2->execute([$invoice_id]);
            $state_limit = (float)$limQ2->fetchColumn();
          } catch(Throwable $e2){ /* ignore */ }
        }
      } catch(Throwable $e1){ /* ignore */ }

      $state_paid  = min($total, $state_limit);
      $patient_due = max($total - $state_paid, 0.0);

      // ---------- build lines for printing ----------
      $printLines = [];
      foreach ($rows as $r) {
        $printLines[] = [
          'title'   => (string)($r['service_name'] ?? '—'),
          'qty'     => (float)$r['quantity'],
          'price'   => (float)$r['unit_price'],
          'sum'     => (float)$r['line_total'],
          'comment' => null,
        ];
      }

      // ---------- output (PDF preferred) — improved ----------
      $dir = __DIR__ . '/invoices';
      if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

      // Shared HTML (ORG header is in $ORG from your file)
      ob_start(); ?>
      <!doctype html>
      <html lang="ka">
        <head>
            <link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
      referrerpolicy="no-referrer">

            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>ინვოისი — <?= h($order_no) ?></title>
          <style>
            @page { size: A4 portrait; margin: 14mm; }
            html, body { margin: 0; padding: 0; background: #fff; }
           /* დაამატეთ თავიდან, სხვა წესებამდე */
@font-face{
  font-family:'Noto Sans Georgian';
  font-style:normal;
  font-weight:400;
  src: url('assets/fonts/NotoSansGeorgian-Regular.ttf') format('truetype');
}
@font-face{
  font-family:'Noto Sans Georgian';
  font-style:normal;
  font-weight:700;
  src: url('assets/fonts/NotoSansGeorgian-Bold.ttf') format('truetype');
}

/* ახალი body */
body{
  font-family: "Noto Sans Georgian", "DejaVu Sans", Arial, sans-serif;
  font-size:12.5px; color:#1F2328;
}

            h1{ font-size:18px; margin:0 0 8px }

            .doc-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; border-bottom:2px solid #111; padding-bottom:8px; }
            .brand{ display:flex; gap:12px; align-items:center; }
            .logo-box{ width:42px; height:42px; border:1.5px solid #111; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; }
            .brand-meta .title{ font-size:15px; font-weight:700; }
            .brand-meta .sub{ color:#555; }

            .doc-meta{ text-align:right; }
            .doc-title{ font-weight:800; font-size:16px; letter-spacing:.2px; }
            .doc-no{ font-size:14px; margin-top:2px; }
            .doc-date{ color:#555; }

            .grid{ display:grid; grid-template-columns:1fr 1fr; gap:8px 24px; margin-top:10px }
            .box{ border:1px solid #cfcfcf; padding:8px; border-radius:6px }
            .row{ display:flex; justify-content:space-between; gap:8px; border-bottom:1px dashed #e6e6e6; padding:3px 0 }
            .row:last-child{ border-bottom:none }
            .row span{ color:#555 }

            table{ width:100%; border-collapse:collapse; margin-top:10px }
            th,td{ border:1px solid #ddd; padding:6px; text-align:left; vertical-align:top }
            th{ background:#f6f6f6; font-weight:700 }
            .right{ text-align:right }
            .tot{ font-weight:700 }

            .words{ margin-top:10px; color:#666 }
            .sig{ margin-top:22px; display:flex; gap:40px }
            .sig .line{ width:260px; border-top:1px solid #000; text-align:center; padding-top:4px }
          </style>
        </head>
        <body>
          <div class="doc-head">
            <div class="brand">
              <div class="logo-box">LOGO</div>
              <div class="brand-meta">
                <div class="title"><?= h($ORG['title']) ?></div>
                <div class="sub">საიდ. კოდი: <?= h($ORG['tax_id']) ?></div>
                <div class="sub"><?= h(trim(($ORG['address_1'] ?? '').' '.($ORG['address_2'] ?? ''))) ?></div>
                <div class="sub"><?= h($ORG['phones']) ?></div>
              </div>
            </div>
            <div class="doc-meta">
              <div class="doc-title">ანგარიშ-ფაქტურა (ინვოისი)</div>
              <div class="doc-no">№ <?= h($order_no) ?> / ID #<?= (int)$invoice_id ?></div>
              <div class="doc-date"><?= h(date('Y-m-d H:i:s')) ?></div>
            </div>
          </div>

          <div class="grid">
            <div class="box">
              <div class="row"><span><b>მიმწოდებელი</b></span><span></span></div>
              <div class="row"><span>დასახელება:</span><b><?= h($ORG['title']) ?></b></div>
              <div class="row"><span>საიდ. კოდი:</span><b><?= h($ORG['tax_id']) ?></b></div>
              <div class="row"><span>მისამართი:</span><b><?= h(trim(($ORG['address_1'] ?? '').' '.($ORG['address_2'] ?? ''))) ?></b></div>
              <div class="row"><span>ტელეფონი:</span><b><?= h($ORG['phones']) ?></b></div>
              <div class="row"><span>ბანკი:</span><b><?= h($ORG['bank_name']) ?> | <?= h($ORG['bank_code']) ?></b></div>
              <div class="row"><span>ა/ა:</span><b><?= h($ORG['iban']) ?></b></div>
              <?php if (!empty($ORG['contact'])): ?>
              <div class="row"><span>კონტაქტი:</span><b><?= h($ORG['contact']) ?></b></div>
              <?php endif; ?>
            </div>
            <div class="box">
              <div class="row"><span><b>გადამხდელი</b></span><span></span></div>
              <div class="row"><span>პ/ნ:</span><b><?= h($pat['personal_id'] ?? '') ?></b></div>
              <div class="row"><span>სახელი, გვარი:</span><b><?= h(trim(($pat['first_name'] ?? '').' '.($pat['last_name'] ?? ''))) ?></b></div>
              <div class="row"><span>ინვოისის ID:</span><b>#<?= (int)$invoice_id ?></b></div>
            </div>
          </div>

          <table>
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>გაწეული მომსახურების ან შესრულებული სამუშაოს დასახელება</th>
                <th style="width:80px"  class="right">რაოდ.</th>
                <th style="width:100px" class="right">თანხა</th>
                <th style="width:110px" class="right">ჯამი</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach($printLines as $L): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= h($L['title']) ?></td>
                  <td class="right"><?= $nf2_local($L['qty']) ?></td>
                  <td class="right"><?= $nf2_local($L['price']) ?></td>
                  <td class="right"><?= $nf2_local($L['sum']) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td colspan="4" class="right tot">სრული ღირებულება</td>
                <td class="right tot"><?= $nf2_local($total) ?></td>
              </tr>
              <tr>
                <td colspan="4" class="right">სახელმწიფოს ლიმიტი</td>
                <td class="right"><?= $nf2_local($state_limit) ?></td>
              </tr>
              <tr>
                <td colspan="4" class="right">სახელმწიფოს მიერ ანაზღაურებული თანხა</td>
                <td class="right"><?= $nf2_local($state_paid) ?></td>
              </tr>
              <tr>
                <td colspan="4" class="right tot">პაციენტის მიერ ასანაზღაურებელი თანხა</td>
                <td class="right tot"><?= $nf2_local($patient_due) ?></td>
              </tr>
            </tbody>
          </table>

          <div class="words"><?= h($moneyWords($patient_due)) ?></div>

          <div class="sig">
            <div class="line">გენერალური დირექტორი</div>
            <div class="line">მთავარი ბუღალტერი</div>
          </div>
        </body>
      </html>
      <?php
      $html = ob_get_clean();

 // Prefer PDF; gracefully fall back to HTML if Dompdf is missing or fails
if ($DOMPDF_AVAILABLE) {
  try {
    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', true);
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('defaultFont', 'DejaVu Sans'); // Georgian glyphs
    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfAbs = $dir . '/invoice_' . $invoice_id . '.pdf';
    if (false === @file_put_contents($pdfAbs, $dompdf->output())) {
      throw new \RuntimeException('PDF write failed');
    }
    $pdf_url = 'invoices/invoice_' . $invoice_id . '.pdf';

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'status'      => 'ok',
      'invoice_id'  => $invoice_id,
      'order_no'    => $order_no,
      'total'       => $nf2_local($total),
      'pdf_url'     => $pdf_url,
      'state_limit' => $nf2_local($state_limit),
      'state_paid'  => $nf2_local($state_paid),
      'patient_due' => $nf2_local($patient_due)
    ]);
    exit;
  } catch (\Throwable $e) {
    // fall through to HTML
  }
}

// HTML fallback
$file = $dir . '/invoice_' . $invoice_id . '.html';
@file_put_contents($file, $html);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'status'=>'ok',
  'invoice_id'=>$invoice_id,
  'order_no'=>$order_no,
  'total'=>$nf2_local($total),
  'pdf_url'=>'invoices/invoice_'.$invoice_id.'.html',
  'state_limit'=> $nf2_local($state_limit),
  'state_paid' => $nf2_local($state_paid),
  'patient_due'=> $nf2_local($patient_due)
]);
exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
exit;
}
/* --- CREATE PAYMENT RECEIPT (PDF/HTML) — improved (with service names) --- */
if ($action === 'create_payment_invoice_demo') {
  $pid = (int)($_POST['patient_id'] ?? (int)($_SESSION['active_patient_id'] ?? 0));
  $ids_raw = trim($_POST['payment_ids'] ?? '');
  $pay_ids = array_values(array_unique(array_filter(array_map('intval', explode('@', $ids_raw)), fn($x)=>$x>0)));

  header('Content-Type: application/json; charset=utf-8');

  if ($pid<=0) { echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ განისაზღვრა']); exit; }
  if (!$pay_ids) { echo json_encode(['status'=>'error','message'=>'აირჩიეთ გადახდები']); exit; }

  // Patient header
  $patQ = $pdo->prepare("SELECT personal_id, first_name, last_name FROM patients WHERE id=?");
  $patQ->execute([$pid]);
  $pat = $patQ->fetch(PDO::FETCH_ASSOC) ?: ['personal_id'=>'','first_name'=>'','last_name'=>''];
  $payer_name = trim(($pat['first_name'] ?? '').' '.($pat['last_name'] ?? '')) ?: '—';
  $payer_pid  = (string)($pat['personal_id'] ?? '—');

  // Fetch payments
  $in = implode(',', array_fill(0, count($pay_ids), '?'));
  $args = $pay_ids; array_unshift($args, $pid);
  $sql = "SELECT id, paid_at, method, amount, order_no
          FROM payments
          WHERE patient_id = ? AND id IN ($in)
          ORDER BY paid_at ASC, id ASC";
  $st = $pdo->prepare($sql); $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) { echo json_encode(['status'=>'error','message'=>'არჩეული გადახდები ვერ მოიძებნა']); exit; }

  // Totals + method split + paid_at window
  $total = 0.0; $byMethod = [];
  $minPaidAt = null; $maxPaidAt = null;
  foreach ($rows as $r) {
    $amt = (float)$r['amount']; $total += $amt;
    $m = strtolower((string)$r['method']); $byMethod[$m] = ($byMethod[$m] ?? 0.0) + $amt;
    $t = strtotime((string)$r['paid_at'] ?: '');
    if ($t) { $minPaidAt = is_null($minPaidAt) ? $t : min($minPaidAt, $t); $maxPaidAt = is_null($maxPaidAt) ? $t : max($maxPaidAt, $t); }
  }
  $methodLabel = static function(string $m): string {
    $m = strtolower(trim($m));
    return match($m){
      'cash'     => 'სალარო',
      'bog'      => 'BOG',
      'transfer' => 'გადმორიცხვა',
      'donor'    => 'დონორი',
      default    => $m,
    };
  };

  /* ---------- Helpers for schema detection ---------- */
  $tblExists = function(PDO $pdo, string $t): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
  };
  $getCols = function(PDO $pdo, string $t): array {
    $cols = [];
    $r = $pdo->query("SHOW COLUMNS FROM `$t`");
    if ($r) { $cols = array_map(fn($x)=>$x['Field'], $r->fetchAll(PDO::FETCH_ASSOC)); }
    return $cols;
  };
  $pickCol = function(array $available, array $candidates, $default=null) {
    foreach ($candidates as $c) if (in_array($c, $available, true)) return $c;
    return $default;
  };
  // NEW: column existence helper
  $colExists = function(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $q->execute([$table, $col]); return (bool)$q->fetchColumn();
  };

  /* ---------- Detect tables/columns ---------- */
  $servicesTbl = 'services';
  $hasPaymentServices = $tblExists($pdo, 'payment_services');
  $hasServicesTable   = $tblExists($pdo, $servicesTbl);

  $serviceCols = [
    'id'        => null,
    'patient_id'=> null,
    'name'      => null,
    'qty'       => null,
    'unit'      => null,
    'sum'       => null,
    'doctor'    => null,
    'date'      => null,
  ];
  if ($hasServicesTable) {
    $all = $getCols($pdo, $servicesTbl);
    $serviceCols = [
      'id'        => $pickCol($all, ['id']),
      'patient_id'=> $pickCol($all, ['patient_id','pt_id','patient']),
      'name'      => $pickCol($all, ['service_name','name','title'], 'id'),
      'qty'       => $pickCol($all, ['quantity','qty','q']),
      'unit'      => $pickCol($all, ['unit_price','price','unit_cost','cost']),
      'sum'       => $pickCol($all, ['sum','total','amount','line_total']),
      'doctor'    => $pickCol($all, ['doctor_name','doctor','physician','medic']),
      'date'      => $pickCol($all, ['created_at','date','service_date','performed_at']),
    ];
  }

  // Optional services.code column
  $hasServiceCode = false;
  if ($hasServicesTable) {
    try {
      $col = $pdo->query("SHOW COLUMNS FROM `$servicesTbl` LIKE 'code'");
      $hasServiceCode = (bool)($col && $col->fetch());
    } catch (Throwable $e) { /* ignore */ }
  }

  // patient_services fallback availability
  $hasPatientServices = $tblExists($pdo, 'patient_services');

  /* ---------- Load services used (for totals + header list) ---------- */
  $srvRows  = [];
  $srvTotal = 0.0;
  $serviceNamesUniq = [];

  if ($hasPaymentServices && $hasServicesTable) {
    // EXACT services linked via payment_services (service_id path)
    $in2 = implode(',', array_fill(0, count($pay_ids), '?'));
    $sel = "
      SELECT 
        s.`{$serviceCols['id']}`   AS service_id,
        s.`{$serviceCols['name']}` AS service_name"
        .($hasServiceCode          ? ", s.`code` AS service_code" : "")
        .($serviceCols['qty']   ? ", s.`{$serviceCols['qty']}`   AS quantity"   : "")
        .($serviceCols['unit']  ? ", s.`{$serviceCols['unit']}`  AS unit_price" : "")
        .($serviceCols['sum']   ? ", s.`{$serviceCols['sum']}`   AS sum"        : "")
        .($serviceCols['doctor']? ", s.`{$serviceCols['doctor']}` AS doctor_name": "")
        .($serviceCols['date']  ? ", s.`{$serviceCols['date']}`  AS created_at" : "")."
      FROM `payment_services` ps
      JOIN `$servicesTbl` s ON s.`{$serviceCols['id']}` = ps.service_id
      WHERE ps.payment_id IN ($in2)
      ORDER BY ".($serviceCols['date'] ? "s.`{$serviceCols['date']}` ASC," : "")." s.`{$serviceCols['id']}` ASC";
    $stS  = $pdo->prepare($sel);
    $stS->execute($pay_ids);
    $srvRows = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];

  } elseif ($hasPatientServices) {
    // Fallback: patient_services within [minPaidAt, maxPaidAt], join services for names
    $psColsAll = $getCols($pdo, 'patient_services');
    $psCols = [
      'id'         => $pickCol($psColsAll, ['id']),
      'patient_id' => $pickCol($psColsAll, ['patient_id','pt_id','patient']),
      'service_id' => $pickCol($psColsAll, ['service_id','sid']),
      'qty'        => $pickCol($psColsAll, ['quantity','qty','q']),
      'unit'       => $pickCol($psColsAll, ['unit_price','price']),
      'sum'        => $pickCol($psColsAll, ['sum','total','amount','line_total']),
      'date'       => $pickCol($psColsAll, ['created_at','date','service_date','performed_at']),
      'doctor_id'  => $pickCol($psColsAll, ['doctor_id']),
    ];

    $where  = "WHERE ps.`{$psCols['patient_id']}` = ?";
    $params = [$pid];
    if ($psCols['date'] && $minPaidAt && $maxPaidAt) {
      $where .= " AND ps.`{$psCols['date']}` BETWEEN ? AND ?";
      $params[] = date('Y-m-d H:i:s', $minPaidAt);
      $params[] = date('Y-m-d H:i:s', $maxPaidAt);
    }

    $doctorJoin = '';
    $doctorSel  = '';
    if ($tblExists($pdo, 'doctors') && $psCols['doctor_id']) {
      $doctorJoin = " LEFT JOIN doctors d ON d.id = ps.`{$psCols['doctor_id']}` ";
      $doctorSel  = " , CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')) AS doctor_name ";
    }

    $nameSel = $hasServicesTable
      ? "COALESCE(s.name, CONCAT('#', ps.`{$psCols['service_id']}`))"
      : "CONCAT('#', ps.`{$psCols['service_id']}`)";

    $codeSel = ($hasServicesTable && $hasServiceCode) ? ", s.code AS service_code" : "";
    $qtySel   = $psCols['qty']  ? ", ps.`{$psCols['qty']}`  AS quantity"   : "";
    $unitSel  = $psCols['unit'] ? ", ps.`{$psCols['unit']}` AS unit_price" : "";
    $sumSel   = $psCols['sum']  ? ", ps.`{$psCols['sum']}`  AS sum"        : "";
    $dateSel  = $psCols['date'] ? ", ps.`{$psCols['date']}` AS created_at" : "";

    $joinServices = $hasServicesTable ? " LEFT JOIN `$servicesTbl` s ON s.id = ps.`{$psCols['service_id']}` " : "";

    $sqlS = "
      SELECT 
        ps.`{$psCols['service_id']}` AS service_id,
        $nameSel                      AS service_name
        $codeSel
        $qtySel
        $unitSel
        $sumSel
        $dateSel
        $doctorSel
      FROM `patient_services` ps
      $joinServices
      $doctorJoin
      $where
      ORDER BY ".($psCols['date'] ? "ps.`{$psCols['date']}` DESC, " : "")." ps.`{$psCols['id']}` DESC
      LIMIT 200";
    $stS = $pdo->prepare($sqlS);
    $stS->execute($params);
    $srvRows = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];

  } elseif ($hasServicesTable && $serviceCols['patient_id']) {
    // Last resort: services table by patient_id
    $where  = "WHERE s.`{$serviceCols['patient_id']}` = ?";
    $params = [$pid];
    if ($serviceCols['date'] && $minPaidAt && $maxPaidAt) {
      $where .= " AND s.`{$serviceCols['date']}` BETWEEN ? AND ?";
      $params[] = date('Y-m-d H:i:s', $minPaidAt);
      $params[] = date('Y-m-d H:i:s', $maxPaidAt);
    }
    $sel = "
      SELECT 
        s.`{$serviceCols['id']}`   AS service_id,
        s.`{$serviceCols['name']}` AS service_name"
        .($hasServiceCode          ? ", s.`code` AS service_code" : "")
        .($serviceCols['qty']   ? ", s.`{$serviceCols['qty']}`   AS quantity"   : "")
        .($serviceCols['unit']  ? ", s.`{$serviceCols['unit']}`  AS unit_price" : "")
        .($serviceCols['sum']   ? ", s.`{$serviceCols['sum']}`   AS sum"        : "")
        .($serviceCols['doctor']? ", s.`{$serviceCols['doctor']}` AS doctor_name": "")
        .($serviceCols['date']  ? ", s.`{$serviceCols['date']}`  AS created_at" : "")."
      FROM `$servicesTbl` s
      $where
      ORDER BY ".($serviceCols['date'] ? "s.`{$serviceCols['date']}` DESC, " : "")." s.`{$serviceCols['id']}` DESC
      LIMIT 100";
    $stS = $pdo->prepare($sel);
    $stS->execute($params);
    $srvRows = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // Compute services total + unique names
  foreach ($srvRows as $r) {
    $line = isset($r['sum']) ? (float)$r['sum'] : ((float)($r['quantity'] ?? 1) * (float)($r['unit_price'] ?? 0));
    $srvTotal += $line;
    $nm = trim((string)($r['service_name'] ?? ''));
    if ($nm !== '' && !in_array($nm, $serviceNamesUniq, true)) $serviceNamesUniq[] = $nm;
  }
// ---- OVERRIDE from frontend: visible service titles (e.g. "PR05 ქცევის თერაპია [2025-10-07 23:11:53]")
$clientSvcFallback = trim((string)($_POST['svc_fallback'] ?? ''));
if ($clientSvcFallback !== '') {
  // keep it safe/short
  if (function_exists('mb_substr')) {
    $clientSvcFallback = mb_substr($clientSvcFallback, 0, 2000, 'UTF-8');
  } else {
    $clientSvcFallback = substr($clientSvcFallback, 0, 2000);
  }
  // split on comma or pipe, de-dup, trim
  $parts = preg_split('/\s*[|,]\s*/u', $clientSvcFallback, -1, PREG_SPLIT_NO_EMPTY);
  if ($parts) {
    $serviceNamesUniq = array_values(array_unique(array_map('trim', $parts)));
  }
}

/* ---------- Per-payment services (FOR THE PAYMENTS TABLE “სერვისი” COLUMN) ---------- */
$servicesByPayment = [];

if (!empty($pay_ids)) {
  // helpers
  $tblExists = $tblExists ?? function(PDO $pdo, string $t): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
  };

  $getCols   = $getCols   ?? fn(PDO $pdo,$t)=>($r=$pdo->query("SHOW COLUMNS FROM `$t`")) ? array_map(fn($x)=>$x['Field'],$r->fetchAll(PDO::FETCH_ASSOC)) : [];
  $pickCol   = $pickCol   ?? fn(array $a, array $c, $d=null)=> (function() use($a,$c,$d){ foreach($c as $x){ if(in_array($x,$a,true)) return $x; } return $d; })();

  $hasPaymentServices = $tblExists($pdo,'payment_services');
  $hasPatientServices = $tblExists($pdo,'patient_services');
  $hasServicesTable   = $tblExists($pdo,'services');

  // columns
  $psColsAll = $hasPatientServices ? $getCols($pdo,'patient_services') : [];
  $psCols = [
    'id'          => $pickCol($psColsAll, ['id']),
    'service_id'  => $pickCol($psColsAll, ['service_id','sid']),
    'patient_id'  => $pickCol($psColsAll, ['patient_id','pt_id','patient']),
    'created_at'  => $pickCol($psColsAll, ['created_at','date','service_date','performed_at']),
    'description' => $pickCol($psColsAll, ['description','descr','desc','title','name','service_name','comment','notes']),
  ];

  $svcColsAll = $hasServicesTable ? $getCols($pdo,'services') : [];
  $serviceCols = [
    'id'   => $pickCol($svcColsAll, ['id']),
    'name' => $pickCol($svcColsAll, ['name','service_name','title']),
  ];
  $hasServiceCode = $hasServicesTable && in_array('code',$svcColsAll,true);

  // label parts
  $serviceNameExpr = ($hasServicesTable && $serviceCols['name'])
    ? (($hasServiceCode
        ? "TRIM(BOTH ' — ' FROM CONCAT(COALESCE(s.`code`,''),' — ',COALESCE(s.`{$serviceCols['name']}`,'')))"
        : "COALESCE(s.`{$serviceCols['name']}`,'')"))
    : "''";

  $labelCore = ($psCols['description'])
    ? "COALESCE(NULLIF(TRIM(psv.`{$psCols['description']}`),''), $serviceNameExpr)"
    : $serviceNameExpr;

  $labelWithTs = $psCols['created_at']
    ? "TRIM(CONCAT($labelCore, ' [', DATE_FORMAT(psv.`{$psCols['created_at']}`,'%Y-%m-%d %H:%i:%s'), ']'))"
    : $labelCore;

  // 1) Exact: payment_services.patient_service_id → patient_services (best)
$hasPsId = (bool)$pdo->query("
  SELECT 1 
  FROM information_schema.columns 
  WHERE table_schema = DATABASE() 
    AND table_name = 'payment_services' 
    AND column_name = 'patient_service_id'
  LIMIT 1
")->fetchColumn();

  if ($hasPaymentServices && $hasPsId && $psCols['id']) {
    $inMap = implode(',', array_fill(0, count($pay_ids), '?'));
    $joinS = ($hasServicesTable && $serviceCols['id']) ? "LEFT JOIN `services` s ON s.`{$serviceCols['id']}` = psv.`{$psCols['service_id']}`" : "";
    $sql   = "
      SELECT pmt.payment_id,
             GROUP_CONCAT(DISTINCT $labelWithTs ORDER BY psv.`{$psCols['id']}` SEPARATOR ', ') AS svc_label
      FROM payment_services pmt
      JOIN patient_services psv ON psv.`{$psCols['id']}` = pmt.patient_service_id
      $joinS
      WHERE pmt.payment_id IN ($inMap)
      GROUP BY pmt.payment_id
    ";
    $stm = $pdo->prepare($sql);
    $stm->execute($pay_ids);
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $servicesByPayment[(int)$r['payment_id']] = trim((string)($r['svc_label'] ?? ''));
    }
  }

  // 2) If only payment_services.service_id exists → map to patient's services by (service_id, patient_id)
$hasSvcId = (bool)$pdo->query("
  SELECT 1 
  FROM information_schema.columns 
  WHERE table_schema = DATABASE() 
    AND table_name = 'payment_services' 
    AND column_name = 'service_id'
  LIMIT 1
")->fetchColumn();

  if ($hasPaymentServices && empty($servicesByPayment) && $hasSvcId && $hasPatientServices && $psCols['service_id'] && $psCols['patient_id']) {
    $inMap = implode(',', array_fill(0, count($pay_ids), '?'));
    $joinS = ($hasServicesTable && $serviceCols['id']) ? "LEFT JOIN `services` s ON s.`{$serviceCols['id']}` = psv.`{$psCols['service_id']}`" : "";
    $sql   = "
      SELECT pmt.payment_id,
             GROUP_CONCAT(DISTINCT $labelWithTs ORDER BY psv.`{$psCols['id']}` SEPARATOR ', ') AS svc_label
      FROM payment_services pmt
      JOIN patient_services psv 
        ON psv.`{$psCols['service_id']}` = pmt.service_id
       AND psv.`{$psCols['patient_id']}` = ?
      $joinS
      WHERE pmt.payment_id IN ($inMap)
      GROUP BY pmt.payment_id
    ";
    $params = array_merge([$pid], $pay_ids);
    $stm = $pdo->prepare($sql);
    $stm->execute($params);
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $servicesByPayment[(int)$r['payment_id']] = trim((string)($r['svc_label'] ?? ''));
    }
  }

  // 3) Fallback for donor-only payments (no payment_services rows): same-day patient_services
  $missingIds = array_values(array_diff($pay_ids, array_keys($servicesByPayment)));
  if ($hasPatientServices && $missingIds) {
    // Load services in the payments window (min/max already computed above)
    $psSel = "
      SELECT psv.`{$psCols['id']}` AS id,
             " . ($psCols['created_at'] ? "DATE(psv.`{$psCols['created_at']}`)" : "DATE(NOW())") . " AS dkey,
             $labelWithTs AS label
      FROM patient_services psv
      " . (($hasServicesTable && $serviceCols['id']) ? "LEFT JOIN services s ON s.`{$serviceCols['id']}`=psv.`{$psCols['service_id']}`" : "") . "
      WHERE psv.`{$psCols['patient_id']}` = ?
      " . ($psCols['created_at'] && $minPaidAt && $maxPaidAt ? "AND psv.`{$psCols['created_at']}` BETWEEN ? AND ?" : "") . "
      ORDER BY " . ($psCols['created_at'] ? "psv.`{$psCols['created_at']}`" : "psv.`{$psCols['id']}`") . " ASC
    ";
    $params = [$pid];
    if ($psCols['created_at'] && $minPaidAt && $maxPaidAt) {
      $params[] = date('Y-m-d 00:00:00', $minPaidAt);
      $params[] = date('Y-m-d 23:59:59', $maxPaidAt);
    }
    $st = $pdo->prepare($psSel); $st->execute($params);
    $svcByDate = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $d = (string)$r['dkey'];
      $lbl = trim((string)$r['label']);
      if ($lbl !== '') $svcByDate[$d][] = $lbl;
    }

    foreach ($rows as $r) {
      $pidRow = (int)$r['id'];
      if (isset($servicesByPayment[$pidRow])) continue;
      $d = substr((string)$r['paid_at'], 0, 10);
      if (!empty($svcByDate[$d])) {
        $servicesByPayment[$pidRow] = implode(', ', array_values(array_unique($svcByDate[$d])));
      }
    }
  }

  // Final absolute fallback: header's unique list (keeps table non-empty)
  if (!empty($serviceNamesUniq)) {
    foreach ($pay_ids as $pidRow) {
      if (empty($servicesByPayment[(int)$pidRow])) {
        $servicesByPayment[(int)$pidRow] = implode(', ', $serviceNamesUniq);
      }
    }
  }
}

/* ---------- Per-payment invoice refs (for the payments table) ---------- */
$invoiceByPayment = [];

if (!empty($pay_ids)) {
  // 1) Best: payment_services.patient_service_id -> invoice_items -> invoices
  $hasPsIdCol = (bool)$pdo->query("
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payment_services'
      AND column_name = 'patient_service_id'
  ")->fetchColumn();

  if ($hasPsIdCol) {
    $inMap = implode(',', array_fill(0, count($pay_ids), '?'));
    $sql = "
      SELECT ps.payment_id,
             GROUP_CONCAT(
               DISTINCT CONCAT(inv.order_no,' (ID ',inv.id,')')
               ORDER BY inv.issued_at ASC, inv.id ASC
               SEPARATOR ', '
             ) AS inv_label
      FROM payment_services ps
      JOIN invoice_items ii ON ii.patient_service_id = ps.patient_service_id
      JOIN invoices inv ON inv.id = ii.invoice_id
      WHERE ps.payment_id IN ($inMap)
      GROUP BY ps.payment_id
    ";
    $stm = $pdo->prepare($sql);
    $stm->execute($pay_ids);
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $invoiceByPayment[(int)$r['payment_id']] = (string)($r['inv_label'] ?? '');
    }
  }

  // 2) Fallback: if only payment_services.service_id exists, map via patient_services -> invoice_items
  $hasSvcIdCol = (bool)$pdo->query("
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payment_services'
      AND column_name = 'service_id'
  ")->fetchColumn();

  if (empty($invoiceByPayment) && $hasSvcIdCol) {
    $inMap = implode(',', array_fill(0, count($pay_ids), '?'));
    $sql = "
      SELECT ps.payment_id,
             GROUP_CONCAT(
               DISTINCT CONCAT(inv.order_no,' (ID ',inv.id,')')
               ORDER BY inv.issued_at ASC, inv.id ASC
               SEPARATOR ', '
             ) AS inv_label
      FROM payment_services ps
      JOIN patient_services psv 
        ON psv.service_id = ps.service_id
       AND psv.patient_id = ?
      JOIN invoice_items ii ON ii.patient_service_id = psv.id
      JOIN invoices inv ON inv.id = ii.invoice_id
      WHERE ps.payment_id IN ($inMap)
      GROUP BY ps.payment_id
    ";
    $params = array_merge([$pid], $pay_ids);
    $stm = $pdo->prepare($sql);
    $stm->execute($params);
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $invoiceByPayment[(int)$r['payment_id']] = (string)($r['inv_label'] ?? '');
    }
  }

  // 3) Last resort: same-day match by DATE(paid_at) within the payments window
  $missing = array_values(array_diff($pay_ids, array_keys($invoiceByPayment)));
  if ($missing) {
    $params = [$pid];
    $dateFilter = '';
    if ($minPaidAt && $maxPaidAt) {
      $dateFilter = " AND inv.issued_at BETWEEN ? AND ? ";
      $params[] = date('Y-m-d 00:00:00', $minPaidAt);
      $params[] = date('Y-m-d 23:59:59', $maxPaidAt);
    }

    $sql = "
      SELECT DATE(inv.issued_at) AS dkey,
             GROUP_CONCAT(
               DISTINCT CONCAT(inv.order_no,' (ID ',inv.id,')')
               ORDER BY inv.issued_at ASC, inv.id ASC
               SEPARATOR ', '
             ) AS invs
      FROM invoice_items ii
      JOIN invoices inv ON inv.id = ii.invoice_id
      JOIN patient_services psv ON psv.id = ii.patient_service_id
      WHERE psv.patient_id = ?
      $dateFilter
      GROUP BY DATE(inv.issued_at)
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $invByDate = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $invByDate[(string)$r['dkey']] = (string)$r['invs'];
    }

    foreach ($rows as $r) {
      $pidRow = (int)$r['id'];
      if (isset($invoiceByPayment[$pidRow])) continue;
      $d = substr((string)$r['paid_at'], 0, 10);
      if (!empty($invByDate[$d])) {
        $invoiceByPayment[$pidRow] = $invByDate[$d];
      }
    }
  }
}


  // Doc number & output dir
  $doc_no = generateOrderSeq($pdo, 'PAY');
  $dir = __DIR__ . '/invoices';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);

  // Build HTML once (used by PDF and fallback)
  ob_start(); ?>
  <!doctype html>
  <html lang="ka">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>
        @page { size: A4 portrait; margin: 14mm; }
        html, body { margin:0; padding:0; background:#fff; }
        body{ font-family:"DejaVu Sans", system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size:12.5px; color:#1F2328; }
        h1{ font-size:18px; margin:0 0 8px; }
        .muted{ color:#666 }
        .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:8px 24px; margin:10px 0 6px; }
        .box{ border:1px solid #cfcfcf; padding:8px; border-radius:6px; }
        .row{ display:flex; justify-content:space-between; gap:8px; border-bottom:1px dashed #e6e6e6; padding:3px 0; }
        .row:last-child{ border-bottom:none; }
        .row span{ color:#555; }
        table{ width:100%; border-collapse:collapse; margin-top:10px; }
        th,td{ border:1px solid #ddd; padding:6px; text-align:left; vertical-align:top; }
        th{ background:#f6f6f6; font-weight:700; }
        .right{ text-align:right; }
        .totals{ margin-top:10px; border:1px solid #cfcfcf; border-radius:6px; overflow:hidden; }
        .tot-row{ display:flex; justify-content:space-between; padding:8px 10px; border-bottom:1px solid #e9e9e9; }
        .tot-row:last-child{ border-bottom:none; }
        .tot-row strong{ font-variant-numeric: tabular-nums; }
        .sign{ margin-top:22px; display:flex; gap:40px; }
        .sigline{ width:260px; border-top:1px solid #000; text-align:center; padding-top:4px; }
        .section-title{ margin-top:18px; font-weight:700; }
      </style>
    </head>
    <body>
      <h1>გადახდების ქვითარი № <?= h($doc_no) ?></h1>
      <div class="muted">დრო: <?= h(date('Y-m-d H:i:s')) ?></div>

      <div class="grid">
        <div class="box">
          <div class="row"><span><b>მიმწოდებელი</b></span><span></span></div>
          <div class="row"><span>დასახელება:</span><b><?= h($ORG['title']) ?></b></div>
          <div class="row"><span>საიდ. კოდი:</span><b><?= h($ORG['tax_id']) ?></b></div>
          <div class="row"><span>მისამართი:</span><b><?= h(trim(($ORG['address_1'] ?? '').' '.($ORG['address_2'] ?? ''))) ?></b></div>
          <div class="row"><span>ტელ:</span><b><?= h($ORG['phones']) ?></b></div>
          <div class="row"><span>ბანკი:</span><b><?= h($ORG['bank_name']) ?> | <?= h($ORG['bank_code']) ?></b></div>
          <div class="row"><span>ა/ა:</span><b><?= h($ORG['iban']) ?></b></div>
        </div>
        <div class="box">
          <div class="row"><span><b>გადამხდელი</b></span><span></span></div>
          <div class="row"><span>სახელი, გვარი:</span><b><?= h($payer_name) ?></b></div>
          <div class="row"><span>პ/ნ:</span><b><?= h($payer_pid) ?></b></div>
          <div class="row"><span>ჩანაწერები:</span><b><?= count($rows) ?></b></div>

<?php if (!empty($serviceNamesUniq)): ?>
  <div class="row">
    <span>სერვისები:</span>
    <b><?= h(implode(', ', $serviceNamesUniq)) ?></b>
  </div>
<?php endif; ?>
</div>
</div>

<!-- Payments table -->
<div class="section-title">გადახდები</div>
<table>
  <thead>
    <tr>
      <th style="width:40px">#</th>
      <th style="width:140px">თარიღი</th>
      <th style="width:120px">ტიპი</th>
      <th>სერვისი</th>
      <th style="width:160px">ორდერი</th>
      <th style="width:110px" class="right">თანხა</th>
    </tr>
  </thead>
  <tbody>
    <?php $i=0; foreach ($rows as $r): $i++;
      // Per-payment service label (with smart fallbacks)
      $svcRaw = $servicesByPayment[(int)$r['id']] ?? '';
      $svc    = trim($svcRaw) !== '' ? $svcRaw
               : (!empty($serviceNamesUniq) ? implode(', ', $serviceNamesUniq) : '—');

      // If we have invoice refs, append them
      $invLbl = $invoiceByPayment[(int)$r['id']] ?? '';
      if ($invLbl !== '') { $svc .= ' — ინვოისი: ' . $invLbl; }
    ?>
      <tr>
        <td><?= $i ?></td>
        <td><?= h($r['paid_at']) ?></td>
        <td><?= h($methodLabel($r['method'])) ?></td>
        <td><?= h($svc) ?></td>
        <td><?= h($r['order_no'] ?? '') ?></td>
        <td class="right"><?= nf2($r['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>



      <?php if (!empty($byMethod)): ?>
      <div class="totals">
        <?php foreach ($byMethod as $m=>$sum): ?>
          <div class="tot-row"><span><?= h($methodLabel($m)) ?></span><strong><?= nf2($sum) ?></strong></div>
        <?php endforeach; ?>
        <div class="tot-row"><span><b>სულ</b></span><strong><?= nf2($total) ?></strong></div>
      </div>
      <?php endif; ?>

      <!-- Services used (from selected payments / fallback) -->
      <?php if (!empty($srvRows)): ?>
        <div class="section-title">სერვისები (ამ ქვითარზე არჩეული გადახდებიდან)</div>
        <table>
          <thead>
            <tr>
              <th style="width:40px">#</th>
              <th>სერვისი</th>
              <th style="width:70px" class="right">რაოდ.</th>
              <th style="width:90px" class="right">ფასი</th>
              <th style="width:110px" class="right">ჯამი</th>
              <th style="width:150px">ექიმი</th>
              <th style="width:140px">თარიღი</th>
            </tr>
          </thead>
          <tbody>
            <?php $j=0; foreach ($srvRows as $s): $j++;
              $qty  = isset($s['quantity'])   ? (float)$s['quantity']   : 1.0;
              $unit = isset($s['unit_price']) ? (float)$s['unit_price'] : 0.0;
              $sum  = isset($s['sum'])        ? (float)$s['sum']        : ($qty*$unit);
              $label = trim(($s['service_code'] ?? '').' — '.($s['service_name'] ?? ''));
              if ($label === '— ') $label = ($s['service_name'] ?? ('#'.$s['service_id']));
            ?>
              <tr>
                <td><?= $j ?></td>
                <td><?= h($label) ?></td>
                <td class="right"><?= h(nf2($qty)) ?></td>
                <td class="right"><?= h(nf2($unit)) ?></td>
                <td class="right"><?= h(nf2($sum)) ?></td>
                <td><?= h($s['doctor_name'] ?? '—') ?></td>
                <td><?= h($s['created_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td colspan="4" class="right"><b>სერვისების ჯამი</b></td>
              <td class="right"><b><?= nf2($srvTotal) ?></b></td>
              <td colspan="2"></td>
            </tr>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="sign">
        <div class="sigline">გენერალური დირექტორი</div>
        <div class="sigline">მთავარი ბუღალტერი</div>
      </div>
    </body>
  </html>
  <?php
  $html = ob_get_clean();

  // Prefer PDF; graceful fallback to HTML
  if ($DOMPDF_AVAILABLE) {
    try {
      $opts = new \Dompdf\Options();
      $opts->set('isRemoteEnabled', true);
      $opts->set('isHtml5ParserEnabled', true);
      $opts->set('defaultFont', 'DejaVu Sans');
      $dompdf = new \Dompdf\Dompdf($opts);
      $dompdf->loadHtml($html, 'UTF-8');
      $dompdf->setPaper('A4', 'portrait');
      $dompdf->render();

      $fileAbs = $dir . '/payment_invoice_' . $doc_no . '.pdf';
      if (false === @file_put_contents($fileAbs, $dompdf->output())) {
        throw new \RuntimeException('PDF write failed');
      }

      echo json_encode([
        'status'         => 'ok',
        'receipt_id'     => $doc_no,
        'doc_no'         => $doc_no,
        'total'          => nf2($total),
        'pdf_url'        => 'invoices/payment_invoice_'.$doc_no.'.pdf',
        'services_count' => count($srvRows),
        'services_total' => nf2($srvTotal),
      ]);
      exit;

    } catch (\Throwable $e) {
      // fall through to HTML
    }
  }

  // HTML fallback
  $file = $dir . '/payment_invoice_' . $doc_no . '.html';
  @file_put_contents($file, $html);
  echo json_encode([
    'status'         => 'ok',
    'receipt_id'     => $doc_no,
    'doc_no'         => $doc_no,
    'total'          => nf2($total),
    'pdf_url'        => 'invoices/payment_invoice_'.$doc_no.'.html',
    'services_count' => count($srvRows),
    'services_total' => nf2($srvTotal),
  ]);
  exit;
}

  /* --- UPDATE PATIENT (fixed $last_name var) --- */
  if ($action==='update_patient') {
    $pid        = (int)($_POST['patient_id'] ?? 0);
    $personalId = trim($_POST['personal_id'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $birthdate  = trim($_POST['birthdate'] ?? ''); // YYYY-mm-dd

    if ($pid<=0 || $personalId==='' || $firstName==='' || $lastName==='' || $birthdate==='') {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'error','message'=>'ყველა ველი სავალდებულოა']); exit;
    }

    $stmt = $pdo->prepare("
      UPDATE patients
         SET personal_id = ?, first_name = ?, last_name = ?, birthdate = ?
       WHERE id = ?
    ");
    $ok = $stmt->execute([$personalId, $firstName, $lastName, $birthdate, $pid]);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>$ok?'ok':'error','message'=>$ok?'':'განახლება ვერ შესრულდა']);
    exit;
  }

  /* --- DELETE PATIENT (extended: also clear donor usages) --- */
  if ($action==='delete_patient') {
    $pid = (int)($_POST['patient_id'] ?? 0);
    if ($pid<=0) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','message'=>'არასწორი ID']); exit; }

    try {
      $pdo->beginTransaction();

      // delete donor usages first (for this patient's payments)
      $pdo->prepare("DELETE FROM guarantee_usages WHERE payment_id IN (SELECT id FROM payments WHERE patient_id=?)")->execute([$pid]);

      // dependent rows
      $pdo->prepare("DELETE FROM payments WHERE patient_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM patient_services WHERE patient_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM patient_guarantees WHERE patient_id=?")->execute([$pid]);

      // NEW: remove invoice_items for all this patient's invoices (avoid orphans)
      $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE patient_id=?)")->execute([$pid]);

      $pdo->prepare("DELETE FROM invoices WHERE patient_id=?")->execute([$pid]); // optional: if desired

      // session cleanup
      $_SESSION['opened_patients'] = array_values(
        array_filter($_SESSION['opened_patients'] ?? [], fn($x)=>(int)$x !== $pid)
      );
      if ((int)($_SESSION['active_patient_id'] ?? 0) === $pid) {
        $_SESSION['active_patient_id'] = !empty($_SESSION['opened_patients'])
          ? (int)end($_SESSION['opened_patients']) : 0;
      }

      $pdo->commit();
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'status'=>'ok',
        'opened_patients'=>$_SESSION['opened_patients'],
        'active_patient_id'=> (int)($_SESSION['active_patient_id'] ?? 0)
      ]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'error','message'=>'ვერ წაიშალა: '.$e->getMessage()]);
    }
    exit;
  }

  /* --- ADD INVOICE LINE (manual panel) --- */
  if ($action === 'add_invoice_line') {
    header('Content-Type: application/json; charset=utf-8');
    json_guard_auth();

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);         // hd_inkal100
    $service_id = (int)($_POST['service_id'] ?? 0);         // optional
    $title      = trim($_POST['title'] ?? '');              // free text if no service_id
    $qty        = max(1.0, (float)($_POST['qty'] ?? 1));
    $price      = (float)($_POST['price'] ?? 0);
    $comment    = trim($_POST['comment'] ?? '');
    $price_type = trim($_POST['price_type'] ?? 'შიდა სტანდარტი');

    if ($patient_id <= 0) { echo json_encode(['status'=>'error','message'=>'bad patient']); exit; }
    if (!($price > 0))    { echo json_encode(['status'=>'error','message'=>'შეიყვანე ფასი']); exit; }

    try {
      $pdo->beginTransaction();

      // If no invoice yet, create a draft one
      if ($invoice_id <= 0) {
        $order_no = generateOrderSeq($pdo, 'INV');
        $insInv = $pdo->prepare("
          INSERT INTO invoices (patient_id, order_no, total_amount, issued_at, created_by, notes, donor_guarantee_id)
          VALUES (?,?,0,NOW(),?,'draft',NULL)
        ");
        $insInv->execute([$patient_id, $order_no, (int)($_SESSION['user_id'] ?? null)]);
        $invoice_id = (int)$pdo->lastInsertId();
      }

      // If service_id given, fetch a nice title (code — name)
      if ($service_id > 0 && $title === '') {
        $s = $pdo->prepare("SELECT CONCAT(COALESCE(NULLIF(TRIM(code),''),'RL'),' — ', name) FROM services WHERE id=?");
        $s->execute([$service_id]);
        $title = (string)($s->fetchColumn() ?? '');
      }
      // title can be empty if no service selected

      $line_total = $qty * $price;

      // Try insert WITH description; on 42S22 (unknown column) fallback to WITHOUT it and append title to comment.
      $new_item_id = 0;
      try {
        $insIt = $pdo->prepare("
          INSERT INTO invoice_items (invoice_id, patient_service_id, description, quantity, unit_price, line_total, comment)
          VALUES (?,?,?,?,?,?,?)
        ");
        $insIt->execute([$invoice_id, ($service_id ?: null), $title, $qty, $price, $line_total, ($comment ?: null)]);
        $new_item_id = (int)$pdo->lastInsertId();
      } catch (PDOException $e) {
        $msg = $e->getMessage();
        if ($e->getCode() === '42S22' || stripos($msg, 'Unknown column') !== false) {
          // Fallback: no description column in this DB
          $fallbackComment = ($title ? ($title . "\n") : '') . ($comment ?: '');
          $insIt2 = $pdo->prepare("
            INSERT INTO invoice_items (invoice_id, patient_service_id, quantity, unit_price, line_total, comment)
            VALUES (?,?,?,?,?,?)
          ");
          $insIt2->execute([$invoice_id, ($service_id ?: null), $qty, $price, $line_total, ($fallbackComment ?: null)]);
          $new_item_id = (int)$pdo->lastInsertId();
        } else {
          throw $e;
        }
      }

      // Recompute and update invoice total
      $tot = $pdo->prepare("SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=?");
      $tot->execute([$invoice_id]);
      $total_amount = (float)$tot->fetchColumn();

      $upd = $pdo->prepare("UPDATE invoices SET total_amount=? WHERE id=?");
      $upd->execute([$total_amount, $invoice_id]);

      $pdo->commit();

      echo json_encode([
        'status'     => 'ok',
        'invoice_id' => $invoice_id,
        'item_id'    => $new_item_id,
        'item' => [
          'title'     => $title,
          'priceType' => $price_type,
          'qty'       => $qty,
          'price'     => $price,
          'sum'       => $line_total,
          'comment'   => $comment,
        ],
        'total' => $total_amount
      ]);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status'=>'error','message'=>'DB შეცდომა: '.$e->getMessage()]);
      exit;
    }
  }

  /* --- CREATE INVOICE DRAFT (header only, no placeholder line) --- */
  if ($action === 'create_invoice_draft') {
    header('Content-Type: application/json; charset=utf-8');
    json_guard_auth();

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    if ($patient_id <= 0) { echo json_encode(['status'=>'error','message'=>'bad patient']); exit; }

    try{
      $pdo->beginTransaction();

      // Create invoice header (draft) - no placeholder lines
      $order_no = generateOrderSeq($pdo, 'INV');
      $insInv = $pdo->prepare("
        INSERT INTO invoices (patient_id, order_no, total_amount, issued_at, created_by, notes, donor_guarantee_id)
        VALUES (?,?,0,NOW(),?,'draft',NULL)
      ");
      $insInv->execute([$patient_id, $order_no, (int)($_SESSION['user_id'] ?? null)]);
      $invoice_id = (int)$pdo->lastInsertId();

      $pdo->commit();

      echo json_encode([
        'status'      => 'ok',
        'invoice_id'  => $invoice_id,
        'order_no'    => $order_no,
        'issued_at'   => date('Y-m-d H:i:s')
      ]);
      exit;
    } catch (Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status'=>'error','message'=>'DB შეცდომა: '.$e->getMessage()]);
      exit;
    }
  }
/* --- DELETE PAYMENT (safe for donor & wallet) --- */
if ($action === 'delete_payment') {
  header('Content-Type: application/json; charset=utf-8');
  json_guard_auth();

  $pid    = (int)($_POST['patient_id'] ?? 0);
  $pay_id = (int)($_POST['payment_id'] ?? 0);
  if ($pid <= 0 || $pay_id <= 0) {
    echo json_encode(['status'=>'error','message'=>'bad params']); exit;
  }

  try {
    $pdo->beginTransaction();

    // Lock the payment row and read critical fields
    $st = $pdo->prepare("
      SELECT patient_id, LOWER(method) AS method, guarantee_id, amount
      FROM payments
      WHERE id = ?
      FOR UPDATE
    ");
    $st->execute([$pay_id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || (int)$p['patient_id'] !== $pid) {
      throw new RuntimeException('ჩანაწერი ვერ მოიძებნა');
    }

    $method = (string)$p['method'];
    $guarId = (int)($p['guarantee_id'] ?? 0);

    // helper: table exists?
    $tblExists = function(PDO $pdo, string $t): bool {
      $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
      $q->execute([$t]); return (bool)$q->fetchColumn();
    };

    // donor payment → remove usages then payment
    if ($method === 'donor') {
      if ($tblExists($pdo,'guarantee_usages')) {
        $pdo->prepare("DELETE FROM guarantee_usages WHERE payment_id=?")->execute([$pay_id]);
      }
      $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$pay_id]);
      $pdo->commit();
      echo json_encode(['status'=>'ok','deleted'=>'donor']); exit;
    }

    // wallet/prepayment: payment has guarantee_id that points to is_virtual_advance=1
    if ($guarId > 0) {
      $gq = $pdo->prepare("SELECT COALESCE(is_virtual_advance,0) FROM patient_guarantees WHERE id=? AND patient_id=? FOR UPDATE");
      $gq->execute([$guarId,$pid]);
      $isWallet = (int)($gq->fetchColumn() ?? 0) === 1;

      if ($isWallet) {
        // deny delete if any usages exist for this wallet guarantee
        $used = (float)$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM guarantee_usages WHERE guarantee_id=?")->execute([$guarId]) ? 
                 (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM guarantee_usages WHERE guarantee_id=".(int)$guarId)->fetchColumn() : 0.0;
        if ($used > 0) {
          throw new RuntimeException('ავანსი უკვე გამოყენებულია — ჯერ გააუქმე გამოყენებები');
        }
        // delete payment_services links (if any)
        if ($tblExists($pdo,'payment_services')) {
          $pdo->prepare("DELETE FROM payment_services WHERE payment_id=?")->execute([$pay_id]);
        }
        // delete the payment and the wallet guarantee row
        $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$pay_id]);
        $pdo->prepare("DELETE FROM patient_guarantees WHERE id=?")->execute([$guarId]);

        $pdo->commit();
        echo json_encode(['status'=>'ok','deleted'=>'wallet']); exit;
      }
    }

    // regular payment (cash/bog/transfer)
    if ($tblExists($pdo,'payment_services')) {
      $pdo->prepare("DELETE FROM payment_services WHERE payment_id=?")->execute([$pay_id]);
    }
    $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$pay_id]);

    $pdo->commit();
    echo json_encode(['status'=>'ok','deleted'=>'regular']); exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
  }
}

  /* --- DELETE INVOICE LINE (recalc total) --- */
  /* --- DELETE PATIENT SERVICE (single row) --- */
if ($action === 'delete_patient_service') {
  header('Content-Type: application/json; charset=utf-8');
  json_guard_auth();

  $ps_id = (int)($_POST['patient_service_id'] ?? 0);
  $pid   = (int)($_POST['patient_id'] ?? 0);

  if ($ps_id <= 0 || $pid <= 0) {
    echo json_encode(['status'=>'error','message'=>'bad params']); exit;
  }

  try {
    $pdo->beginTransaction();

    // own row?
    $own = $pdo->prepare("SELECT patient_id FROM patient_services WHERE id=? FOR UPDATE");
    $own->execute([$ps_id]);
    $row = $own->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['patient_id'] !== $pid) {
      throw new RuntimeException('service not found for this patient');
    }

    // block if it’s already in any invoice
    $inv = $pdo->prepare("
      SELECT GROUP_CONCAT(CONCAT(inv.order_no,' (ID ',inv.id,')') ORDER BY inv.issued_at ASC, inv.id ASC SEPARATOR ', ')
      FROM invoice_items ii
      JOIN invoices inv ON inv.id = ii.invoice_id
      WHERE ii.patient_service_id = ?
    ");
    $inv->execute([$ps_id]);
    $invs = (string)($inv->fetchColumn() ?? '');
    if ($invs !== '') {
      throw new RuntimeException('სერვისი უკვე მიბმულია ინვოისში: '.$invs.' — ჯერ წაშალეთ იქიდან.');
    }

    // detach from payment_services if that column exists
    $hasPsIdCol = (bool)$pdo->query("
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'payment_services'
        AND column_name = 'patient_service_id'
    ")->fetchColumn();
    if ($hasPsIdCol) {
      $pdo->prepare("DELETE FROM payment_services WHERE patient_service_id=?")->execute([$ps_id]);
    }

    // finally delete the service
    $del = $pdo->prepare("DELETE FROM patient_services WHERE id=? AND patient_id=?");
    $del->execute([$ps_id, $pid]);
    if ($del->rowCount() < 1) {
      throw new RuntimeException('ვერ წაიშალა');
    }

    $pdo->commit();
    echo json_encode(['status'=>'ok']); exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
  }
}

  if ($action === 'delete_invoice_item') {
    header('Content-Type: application/json; charset=utf-8');
    json_guard_auth();

    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $item_id    = (int)($_POST['item_id'] ?? 0);
    if ($invoice_id <= 0 || $item_id <= 0) {
      echo json_encode(['status'=>'error','message'=>'bad params']); exit;
    }

    try {
      $pdo->beginTransaction();

      // sanity: must belong to this invoice
      $chk = $pdo->prepare("SELECT 1 FROM invoice_items WHERE id=? AND invoice_id=?");
      $chk->execute([$item_id, $invoice_id]);
      if (!$chk->fetchColumn()) {
        throw new RuntimeException('item not found for this invoice');
      }

      // delete
      $del = $pdo->prepare("DELETE FROM invoice_items WHERE id=?");
      $del->execute([$item_id]);

      // recompute invoice header total
      $tot = $pdo->prepare("SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=?");
      $tot->execute([$invoice_id]);
      $total_amount = (float)$tot->fetchColumn();

      $upd = $pdo->prepare("UPDATE invoices SET total_amount=? WHERE id=?");
      $upd->execute([nf2($total_amount), $invoice_id]);

      $pdo->commit();
      echo json_encode(['status'=>'ok','total'=>$total_amount]); // send fresh total
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
  }

  /* --- SAVE INSURANCE ROWS (robust, idempotent UPSERT) --- */
  if ($action === 'save_insurance_rows') {
    header('Content-Type: application/json; charset=utf-8');
    json_guard_auth();

    $pid      = (int)($_POST['patient_id'] ?? 0);
    $rowsJson = $_POST['rows'] ?? '[]';

    // defensive JSON decode
    $rows = json_decode($rowsJson, true, 256);
    if ($pid <= 0 || !is_array($rows)) {
      echo json_encode(['status' => 'error', 'message' => 'bad_payload']);
      exit;
    }

    try {
      $pdo->beginTransaction();

      // If DB has UNIQUE KEY(patient_id, service_id), we can do ON DUPLICATE KEY.
      // Otherwise we’ll fall back to manual upsert.
      $hasUnique = false;
      try {
        $chk = $pdo->query("SHOW INDEX FROM patient_insurance");
        foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $ix) {
          if (
            isset($ix['Non_unique']) && (int)$ix['Non_unique'] === 0 &&
            isset($ix['Column_name']) && in_array($ix['Column_name'], ['patient_id','service_id'], true)
          ) {
            $hasUnique = true; // crude but fine
          }
        }
      } catch (Throwable $e) {
        /* ignore schema introspection errors — we’ll manual upsert */
      }

      if ($hasUnique) {
        // fast path: native upsert
        $insUp = $pdo->prepare("
          INSERT INTO patient_insurance
            (patient_id, service_id, price_type, referral_number, policy_number, field1, field2, field3)
          VALUES (?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            price_type=VALUES(price_type),
            referral_number=VALUES(referral_number),
            policy_number=VALUES(policy_number),
            field1=VALUES(field1),
            field2=VALUES(field2),
            field3=VALUES(field3)
        ");

        foreach ($rows as $r) {
          $sid = (int)($r['service_id'] ?? 0);
          if ($sid <= 0) continue;

          $pt  = trim((string)($r['price_type'] ?? 'შიდა'));
          $ref = trim((string)($r['referral_number'] ?? ''));
          $pol = trim((string)($r['policy_number'] ?? ''));
          $f1  = trim((string)($r['field1'] ?? ''));
          $f2  = trim((string)($r['field2'] ?? ''));
          $f3  = trim((string)($r['field3'] ?? ''));

          $insUp->execute([$pid,$sid,$pt,$ref,$pol,$f1,$f2,$f3]);
        }

      } else {
        // manual upsert (works without unique index)
        $sel = $pdo->prepare("SELECT id FROM patient_insurance WHERE patient_id=? AND service_id=? LIMIT 1");
        $ins = $pdo->prepare("
          INSERT INTO patient_insurance
            (patient_id, service_id, price_type, referral_number, policy_number, field1, field2, field3)
          VALUES (?,?,?,?,?,?,?,?)
        ");
        $upd = $pdo->prepare("
          UPDATE patient_insurance
             SET price_type=?, referral_number=?, policy_number=?, field1=?, field2=?, field3=?
           WHERE id=?
        ");

        foreach ($rows as $r) {
          $sid = (int)($r['service_id'] ?? 0);
          if ($sid <= 0) continue;

          $pt  = trim((string)($r['price_type'] ?? 'შიდა'));
          $ref = trim((string)($r['referral_number'] ?? ''));
          $pol = trim((string)($r['policy_number'] ?? ''));
          $f1  = trim((string)($r['field1'] ?? ''));
          $f2  = trim((string)($r['field2'] ?? ''));
          $f3  = trim((string)($r['field3'] ?? ''));

          $sel->execute([$pid,$sid]);
          $id = (int)($sel->fetchColumn() ?: 0);
          if ($id > 0) {
            $upd->execute([$pt,$ref,$pol,$f1,$f2,$f3,$id]);
          } else {
            $ins->execute([$pid,$sid,$pt,$ref,$pol,$f1,$f2,$f3]);
          }
        }
      }

      $pdo->commit();
      echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

} // <-- end POST router (fixed extra brace)

/* ===================== GET guards ===================== */
// If AJAX GET with action and not authorized -> JSON error
if (($_SERVER['REQUEST_METHOD']==='GET') && isset($_GET['action']) && !isset($_SESSION['user_id'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'error','message'=>'auth_required']);
  exit;
}

// Activate patient via URL
if (!empty($_GET['patient_id'])) {
  $pid = (int)$_GET['patient_id']; if ($pid>0) addAndActivatePatient($pid);
}

/* ===================== GET: services list for patient (sidebar) ===================== */
if (
  $_SERVER['REQUEST_METHOD']==='GET' &&
  (($_GET['action'] ?? '')==='get_services') &&
  isset($_GET['id'])
){
  header('Content-Type: application/json; charset=utf-8');
  $patient_id = (int)$_GET['id'];
  $stmt = $pdo->prepare("
    SELECT ps.id, ps.quantity, ps.unit_price, ps.sum, s.name AS service_name,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name, ps.created_at
    FROM patient_services ps
    JOIN services s ON ps.service_id = s.id
    LEFT JOIN doctors d ON ps.doctor_id = d.id
    WHERE ps.patient_id = ?
    ORDER BY ps.id
  ");
  $stmt->execute([$patient_id]);
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
}
/* ===================== GET: payment modal (v8) ===================== */
if (
  $_SERVER['REQUEST_METHOD']==='GET' &&
  (($_GET['action'] ?? '')==='pay_view') &&
  isset($_GET['id'])
){
  header('Content-Type: text/html; charset=utf-8');
  $pid = (int)$_GET['id'];

  // Patient name
  $q = $pdo->prepare("SELECT first_name,last_name FROM patients WHERE id=?");
  $q->execute([$pid]);
  $pat = $q->fetch(PDO::FETCH_ASSOC);
  $full = h(trim(($pat['first_name'] ?? '').' '.($pat['last_name'] ?? ''))) ?: 'პაციენტი';

  // Services
  $s = $pdo->prepare("
    SELECT 
      ps.id,
      ps.quantity,
      COALESCE(ps.unit_price, s.price) AS unit_price,
      COALESCE(ps.sum, ps.quantity * COALESCE(ps.unit_price, s.price)) AS sum,
      s.name AS service_name,
      ps.created_at,
      (
        SELECT GROUP_CONCAT(
                 CONCAT(inv.order_no, ' (ID ', inv.id, ')')
                 ORDER BY inv.issued_at ASC, inv.id ASC
                 SEPARATOR ', '
               )
        FROM invoice_items ii
        JOIN invoices inv ON inv.id = ii.invoice_id
        WHERE ii.patient_service_id = ps.id
      ) AS inv_refs
    FROM patient_services ps
    JOIN services s ON s.id = ps.service_id
    WHERE ps.patient_id = ?
    ORDER BY ps.created_at ASC, ps.id ASC
  ");
  $s->execute([$pid]); 
  $services = $s->fetchAll(PDO::FETCH_ASSOC);

  // Payments (all) + flag wallet deposits
  $p = $pdo->prepare("
    SELECT p.id, p.paid_at, LOWER(p.method) AS method, p.amount, p.order_no, p.guarantee_id,
           COALESCE(g.is_virtual_advance,0) AS is_wallet
    FROM payments p
    LEFT JOIN patient_guarantees g ON g.id = p.guarantee_id
    WHERE p.patient_id = ?
    ORDER BY p.paid_at ASC, p.id ASC
  ");
  $p->execute([$pid]); 
  $payments = $p->fetchAll(PDO::FETCH_ASSOC);

  // Guarantees (donors + wallet/prepayment)
  $gq = $pdo->prepare("
    SELECT g.id, g.donor, COALESCE(g.is_virtual_advance,0) AS is_wallet,
           g.amount - COALESCE((SELECT SUM(u.amount) FROM guarantee_usages u WHERE u.guarantee_id=g.id),0) AS left_amount
    FROM patient_guarantees g
    WHERE g.patient_id = ?
    HAVING left_amount > 0
    ORDER BY g.id ASC
  "); 
  $gq->execute([$pid]); 
  $guarantees = $gq->fetchAll(PDO::FETCH_ASSOC) ?: [];

  /* >>> NEW: wallet (prepayment) balance — sum of left_amount where is_virtual_advance = 1 */
  $wallet_left = 0.0;
  foreach ($guarantees as $g) {
    if ((int)($g['is_wallet'] ?? 0) === 1) {
      $wallet_left += max(0.0, (float)($g['left_amount'] ?? 0));
    }
  }
  /* <<< END NEW */

  // Aggregates
  $total_services = 0.0; 
  foreach($services as $r) $total_services += (float)$r['sum'];

  // Real (cash/bog/transfer) payments — exclude wallet deposits (prepayments) and donors
  $total_paid_real = 0.0;
  foreach ($payments as $r){
    $m = strtolower((string)($r['method'] ?? ''));
    $isWallet = (int)($r['is_wallet'] ?? 0);
    if (in_array($m, ['cash','bog','transfer'], true) && $isWallet === 0) {
      $total_paid_real += (float)$r['amount'];
    }
  }

  // How much of guarantees (incl. wallet) is already applied to services
  $donor_applied_total = (float)$pdo->query("
    SELECT COALESCE(SUM(u.amount),0)
    FROM guarantee_usages u
    JOIN payments pm ON pm.id = u.payment_id
    WHERE pm.patient_id = ".(int)$pid
  )->fetchColumn();

  // Total donor/wallet left (for the badge below the donor select)
  $donor_total_left = 0.0; 
  foreach($guarantees as $g) $donor_total_left += max(0.0,(float)$g['left_amount']);

  // FIFO mark rows as paid using real + donor applied
  usort(
    $services, 
    fn($a,$b)=> (strcmp((string)$a['created_at'],(string)$b['created_at']) ?: ((int)$a['id']<=> (int)$b['id']))
  );
  $rem = $total_paid_real + $donor_applied_total;
  foreach ($services as $k=>$r) {
    $line = (float)$r['sum'];
    $took = max(0.0, min($rem, $line));
    $services[$k]['__paid']    = $took;
    $services[$k]['__is_paid'] = ($took + 0.005) >= $line;
    $rem -= $took;
  }

  $now_human = date('Y-m-d H:i');

  ?>
  <div class="modal">
    <button class="close" data-close>&times;</button>

    <h3 style="margin:6px 6px 14px 6px; color:#178e7b;"><?= $full ?> — გადახდა</h3>

    <div class="pay-topbar" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:6px 8px;background:#f3fbf9;border:1px solid #e4f2ef;border-radius:8px;margin-bottom:10px">
      <label class="chk"><input type="checkbox" id="chk_all_rows" checked> მონიშვნა ყველა</label>
      <label class="chk"><input type="checkbox" id="fix_zero_all"> „ფიქს.“ ველების ნული</label>

      <div class="tabs">
        <a href="javascript:void(0)" id="ins_tab_1" class="tab active">დაზღვევა I</a>
        <a href="javascript:void(0)" id="ins_tab_2" class="tab">დაზღვევა II</a>
        <a href="javascript:void(0)" id="ins_tab_3" class="tab">დაზღვევა III</a>
      </div>

      <span class="sel-badge" style="background:#fff;border:1px solid #cfece6;padding:6px 10px;border-radius:6px">
        <span>მონიშნულის გადასახდელი:</span> <b id="sel_badge">0.00</b>
      </span>

      <!-- Print selected rows -->
      <a href="javascript:void(0)" class="btn" id="btn_print_selected" style="display:inline-block;padding:6px 10px;border-radius:6px;background:#fff;color:#116a5b;text-decoration:none;font-weight:600;border:1px solid #d6efeb">ბეჭდვა (მონიშნული)</a>

      <label class="chk"><input type="checkbox" id="chk_debt_all"> დავალიანების მონიშვნა</label>

      <!-- >>> The fixed badge: shows + X GEL when a prepayment exists -->
      <div class="total-badge" style="margin-left:auto;background:#fff;border:1px dashed #21c1a6;padding:6px 10px;border-radius:6px">
        <span>დარჩენილი გადასახდელი:</span>
        <strong id="en3m" data-wallet-left="<?= nf2($wallet_left) ?>">0.00</strong>
        <?php if ($wallet_left > 0): ?>
          <span id="prepayHint" style="margin-left:6px;font-weight:700">+ <?= nf2($wallet_left) ?> GEL</span>
        <?php else: ?>
          <span id="prepayHint" style="display:none"></span>
        <?php endif; ?>
      </div>
      <!-- <<< End fixed badge -->
    </div>

    <input type="hidden" id="pa_pid" value="<?= (int)$pid ?>">

    <div class="table-wrap" style="overflow:auto">
      <input type="hidden" id="hd_izptit">
      <table class="pay-table" id="fs_amounttypet" style="width:100%;border-collapse:collapse;font-size:14px;min-width:920px">
        <thead>
          <tr class="skyhead1">
            <th style="width:28px"></th>
            <th>დასახელება</th>
            <th style="width:120px">ფიქს. (ფასი)</th>
            <th style="width:70px">ფასი</th>
            <th class="fdaz1" style="width:100px">ქვედა ლიმიტი</th>
            <th class="fdaz1" style="width:110px">სადაზ % 
              <div class="mini-stack" style="display:flex;gap:4px;align-items:center;margin-top:4px">
                <input type="text" id="dgperg" class="mini" style="width:40px;padding:4px;border:1px solid #bbb;border-radius:4px;text-align:center">
                <button id="btn_ins_percent_1" class="mini-btn" style="width:26px;height:26px;border:1px solid #bbb;background:#fff;border-radius:4px;cursor:pointer">+</button>
              </div>
            </th>
            <th class="fdaz1" style="width:100px">ზედა ლიმიტი</th>
            <th class="fdaz2 diaz" style="width:0">ქვედა</th>
            <th class="fdaz2 diaz" style="width:0">%</th>
            <th class="fdaz2 diaz" style="width:0">ზედა</th>
            <th class="fdaz3 diaz" style="width:0">ქვედა</th>
            <th class="fdaz3 diaz" style="width:0">%</th>
            <th class="fdaz3 diaz" style="width:0">ზედა</th>
            <th style="width:90px">ფასდა % 
              <div class="mini-stack" style="display:flex;gap:4px;align-items:center;margin-top:4px">
                <input type="text" id="jjdhrge" class="mini" style="width:40px;padding:4px;border:1px solid #bbb;border-radius:4px;text-align:center">
                <button id="btn_price_percent" class="mini-btn" style="width:26px;height:26px;border:1px solid #bbb;background:#fff;border-radius:4px;cursor:pointer">+</button>
              </div>
            </th>
            <th style="width:38px"></th>
            <th style="width:86px">გადახდ.</th>
            <th style="width:86px">ჯამი</th>
            <th style="width:86px">გადახდილი</th>
            <th style="width:86px">წაშლა</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=0; foreach($services as $srow): $i++;
            $rid = (int)$srow['id'];
            $qty = (float)$srow['quantity'];
            $unit = (float)$srow['unit_price'];
            $sum = (float)$srow['sum'];
            $title = h(($srow['service_name'] ?? '').' ['.($srow['created_at'] ?? '').']');
            $capPaid = min((float)($srow['__paid'] ?? 0), $sum);
            $isPaid = ($capPaid + 0.005) >= $sum;
            $checkedAttr = $isPaid ? '' : 'checked';
            $rowCls = $isPaid ? 'paid' : '';
          ?>
          <tr id="T<?= $rid ?>" class="<?= $rowCls ?>" data-qty="<?= h((string)$qty) ?>" data-paid-initial="<?= nf2($capPaid) ?>">
            <td><input type="checkbox" class="srv-chk" data-fully-paid="<?= $isPaid?1:0 ?>" <?= $checkedAttr ?>></td>
            <td>
              <input type="hidden" id="we<?= $i ?>r" value="<?= (int)$pid.'@'.$rid ?>">
              <?= $title ?>
              <?php if (!empty($srow['inv_refs'])): ?>
                <div class="small">ინვოისი(ები): <?= h($srow['inv_refs']) ?></div>
              <?php else: ?>
                <div class="small" style="color:#999">ინვოისი: —</div>
              <?php endif; ?>
            </td>
            <td><input type="text" class="np isdoubl" id="re<?= $i ?>r" value="<?= nf2($unit) ?>" inputmode="decimal"></td>
            <td class="center"><input class="_v" type="checkbox" disabled checked></td>
            <td class="fdaz1"><input id="je<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz1"><input id="pe<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz1"><input id="ze<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz2 diaz"><input id="jo<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz2 diaz"><input id="le<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz2 diaz"><input id="oe<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz3 diaz"><input id="ji<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz3 diaz"><input id="me<?= $i ?>r" type="text" value="0"></td>
            <td class="fdaz3 diaz"><input id="fe<?= $i ?>r" type="text" value="0"></td>
            <td><input id="ds<?= $i ?>r" type="text" value="0"></td>
            <td class="center"><input type="checkbox" id="ce<?= $i ?>r" class="k"></td>
            <td class="center"><input type="text" class="vt2 disCol" disabled></td>
            <td class="cce tcc"><?= nf2($sum) ?></td>
            <td class="lb2 cg"><?= nf2($capPaid) ?></td>
            <td class="center">
              <?php if (empty($srow['inv_refs'])): ?>
                <button type="button" class="btn danger-btn del-srv" data-ps-id="<?= (int)$rid ?>" data-pid="<?= (int)$pid ?>">წაშლა</button>
              <?php else: ?>
                <button type="button" class="btn danger-btn" disabled title="სერვისი მიბმულია ინვოისზე — წაშლა შეუძლებელია">წაშლა</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="btn-row" style="margin:8px 0;display:flex;gap:8px;flex-wrap:wrap">
      <a href="javascript:void(0)" class="btn" id="btn_make_invoice" style="display:inline-block;padding:6px 10px;border-radius:6px;background:#e6f6f3;color:#116a5b;text-decoration:none;font-weight:600;border:1px solid #d6efeb">ინვოისის ID-ების ნახვა</a>
      <a href="javascript:void(0)" class="btn" id="btn_issue_invoice" style="display:inline-block;padding:6px 10px;border-radius:6px;background:#e6f6f3;color:#116a5b;text-decoration:none;font-weight:600;border:1px solid #d6efeb">ინვოისის შექმნა (<?= $DOMPDF_AVAILABLE ? 'PDF' : 'HTML' ?>)</a>
    </div>

    <div class="card pay-form" style="background:#fff;border:1px solid #ececec;border-radius:8px;padding:12px;margin-top:10px">
      <div class="row" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
        <label>თარიღი <input type="text" id="pa_date" value="<?= h($now_human) ?>"></label>
        <label>გადახდის ტიპი
          <select id="pa_cmpto">
            <option value="34">სალარო</option>
            <option value="825">BOG</option>
            <option value="858">გადმორიცხვა</option>
          </select>
        </label>
        <label>კლასი
          <select id="gadtyp">
            <option value="1" selected>შემოსავალი</option>
            <option value="2">გასავალი</option>
          </select>
        </label>
        <label>სერვისი
          <input type="text" id="order_no" placeholder="გთხოვთ ჩაწეროთ სერვისი" autocomplete="off">
        </label>

        <label>ავანსატორი
          <select id="pa_dontype">
            <option value=""></option>
            <?php foreach ($guarantees as $g): ?>
              <option value="<?= (int)$g['id'] ?>" data-amount="<?= nf2($g['left_amount']) ?>">
                <?= h($g['donor']) ?> — <?= nf2($g['left_amount']) ?> ₾
              </option>
            <?php endforeach; ?>
          </select>
          <small style="display:block;color:#178e7b;margin-top:4px">
            ნაშთი (სულ): <b id="donor_left_badge" data-total-init="<?= nf2($donor_total_left) ?>"><?= nf2($donor_total_left) ?></b> ₾
          </small>
        </label>
      </div>

      <div class="row" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:8px">
        <label>პროცედურების ჯამი <input type="text" id="pa_pipa" value="<?= nf2($total_services) ?>" readonly></label>
        <label>საგარანტიო (გათვალისწინებული) <input type="text" id="pa_empay" value="0.00" readonly></label>
        <label>რეალურად გადახდილი <input type="text" id="pa_gadax" value="<?= nf2($total_paid_real) ?>" readonly></label>
        <label>დარჩენილი გადასახდელი <input type="text" id="pa_gadasax" value="0.00" readonly></label>
      </div>

      <div class="actions" style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
        <input id="pa_amo" value="0.00" placeholder="გადასახდელი: 0.00">
        <button class="pay-btn" id="pa_insrt" style="padding:8px 14px;border:none;border-radius:6px;background:#21c1a6;color:#fff;font-weight:700;cursor:pointer">შეტანა</button>
      </div>

      <!-- runtime donor cache -->
      <input type="hidden" id="donor_id_now" value="">
      <input type="hidden" id="donor_applied_now" value="0.00">
    </div>

    <div class="card" style="background:#fff;border:1px solid #ececec;border-radius:8px;padding:12px;margin-top:10px">
      <div class="pay-actions" style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <label class="chk"><input type="checkbox" id="chk_all_payments"> მონიშვნა ყველა გადახდა</label>
        <a href="javascript:void(0)" class="btn" id="btn_issue_payment_invoice" style="display:inline-block;padding:6px 10px;border-radius:6px;background:#e6f6f3;color:#116a5b;text-decoration:none;font-weight:600;border:1px solid #d6efeb">გადახდების ინვოისი (<?= $DOMPDF_AVAILABLE ? 'PDF' : 'HTML' ?>)</a>
      </div>
      <div class="table-wrap" style="overflow:auto">
        <table class="simple-table" id="pa_pa" style="width:100%;border-collapse:collapse;font-size:14px">
          <thead>
            <tr>
              <th style="width:28px"></th>
              <th style="width:22%">თარიღი</th>
              <th style="width:30%">ტიპი</th>
              <th style="width:18%">ორდერის #</th>
              <th style="width:14%">თანხა</th>
              <th style="width:16%">ქმედება</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$payments): ?>
              <tr>
                <td colspan="6" style="text-align:center;color:#777;font-style:italic">ჩანაწერი არ არის.</td>
              </tr>
            <?php else: foreach ($payments as $pr): ?>
              <tr id="O<?= (int)$pr['id'] ?>">
                <td><input type="checkbox" class="zl"></td>
                <td><?= h($pr['paid_at']) ?></td>
                <?php $isWallet = !empty($pr['is_wallet']); ?>
                <td><b><?= h($pr['method']) ?><?= $isWallet ? ' — ავანსი' : '' ?></b></td>
                <td><?= h($pr['order_no'] ?? '') ?></td>
                <td><?= nf2($pr['amount']) ?></td>
                <td>
                <button type="button"
                        class="btn edit-btn edit-payment"
                        data-pay-id="<?= (int)$pr['id'] ?>"
                        data-pid="<?= (int)$pid ?>"
                        data-paid-at="<?= h($pr['paid_at']) ?>"
                        data-method="<?= h(strtolower($pr['method'])) ?>"
                        data-amount="<?= nf2($pr['amount']) ?>"
                        data-order-no="<?= h($pr['order_no'] ?? '') ?>"
                        data-guarantee-id="<?= (int)($pr['guarantee_id'] ?? 0) ?>"
                        <?= (strtolower($pr['method'])==='donor' ? 'data-donor="1"' : '') ?>>
                  რედაქტირება
                </button>

                    <button type="button"
                      class="btn danger-btn delete-payment"
                      data-pay-id="<?= (int)$pr['id'] ?>"
                      data-pid="<?= (int)$pid ?>"
                      data-method="<?= h(strtolower($pr['method'])) ?>"
                      data-wallet="<?= $isWallet ? 1 : 0 ?>">
                წაშლა
              </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php
  exit;
}


/* ===================== GET: single patient (edit) ===================== */
if (
  $_SERVER['REQUEST_METHOD']==='GET' &&
  (($_GET['action'] ?? '')==='get_patient') &&
  isset($_GET['id'])
){
  header('Content-Type: application/json; charset=utf-8');
  $pid = (int)$_GET['id'];
  $q = $pdo->prepare("SELECT id, personal_id, first_name, last_name, birthdate FROM patients WHERE id=?");
  $q->execute([$pid]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'ვერ მოიძებნა']); exit; }
  echo json_encode(['status'=>'ok','data'=>$row]); exit;
}

/* ===================== GET: insurance rows for invoice panel ===================== */
if (
  $_SERVER['REQUEST_METHOD']==='GET' &&
  (($_GET['action'] ?? '')==='get_insurance_rows') &&
  isset($_GET['patient_id'])
){
  header('Content-Type: application/json; charset=utf-8');
  $pid = (int)$_GET['patient_id'];
  if ($pid<=0){ echo json_encode(['status'=>'error','message'=>'bad patient']); exit; }

  // distinct by service to avoid duplicate lines for same service_id
    $sql = "
      SELECT
        ps.service_id AS sid,
        MIN(s.code) AS code,
        MIN(s.name) AS name,
        MIN(COALESCE(NULLIF(TRIM(s.svc_type), ''), 'შიდა')) AS price_type,
        MIN(COALESCE(pi.referral_number,'')) AS referral_number,
        MIN(COALESCE(pi.policy_number,''))   AS policy_number,
        MIN(COALESCE(pi.field1,'')) AS field1,
        MIN(COALESCE(pi.field2,'')) AS field2,
        MIN(COALESCE(pi.field3,'')) AS field3
      FROM patient_services ps
      JOIN services s ON s.id = ps.service_id
      LEFT JOIN patient_insurance pi
        ON pi.patient_id = ps.patient_id AND pi.service_id = ps.service_id
      WHERE ps.patient_id = ?
      GROUP BY ps.service_id
      ORDER BY MIN(s.name) ASC, ps.service_id ASC
    ";

  $st = $pdo->prepare($sql); $st->execute([$pid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode(['status'=>'ok','rows'=>$rows]); exit;
}

/* ===================== GET: invoices list for invoice panel ===================== */
if (
  $_SERVER['REQUEST_METHOD']==='GET' &&
  (($_GET['action'] ?? '')==='get_invoices') &&
  isset($_GET['patient_id'])
){
  header('Content-Type: application/json; charset=utf-8');
  $pid = (int)$_GET['patient_id'];
  if ($pid<=0){ echo json_encode(['status'=>'error','message'=>'bad patient']); exit; }

  $st = $pdo->prepare("
    SELECT id, order_no, total_amount, issued_at, notes
    FROM invoices
    WHERE patient_id = ?
    ORDER BY issued_at DESC, id DESC
  ");
  $st->execute([$pid]);
  echo json_encode(['status'=>'ok','rows'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: []]); exit;
}
/* ===================== GET: invoice items for builder ===================== */
if (
  $_SERVER['REQUEST_METHOD']==='GET' &&
  (($_GET['action'] ?? '')==='get_invoice_items') &&
  isset($_GET['invoice_id'])
){
  header('Content-Type: application/json; charset=utf-8');
  $iid = (int)$_GET['invoice_id'];
  if ($iid<=0){ echo json_encode(['status'=>'error','message'=>'bad invoice']); exit; }

  try{
    // Try with description column (new schema)
    $sql = "SELECT id, quantity, unit_price, line_total, comment, description
            FROM invoice_items
            WHERE invoice_id = ?
            ORDER BY id ASC";
    $st = $pdo->prepare($sql); $st->execute([$iid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  } catch (PDOException $e) {
    // Fallback: old schema (no description column)
    if ($e->getCode()==='42S22' || stripos($e->getMessage(), 'Unknown column') !== false){
      $sql = "SELECT id, quantity, unit_price, line_total, comment
              FROM invoice_items
              WHERE invoice_id = ?
              ORDER BY id ASC";
      $st = $pdo->prepare($sql); $st->execute([$iid]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      // normalize shape: add description = null
      foreach ($rows as &$r){ if(!array_key_exists('description',$r)) $r['description']=null; }
      unset($r);
    } else {
      echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
    }
  }

  // Also send server-known total if you want to trust DB over client sum
  $totSt = $pdo->prepare("SELECT total_amount FROM invoices WHERE id=?");
  $totSt->execute([$iid]);
  $total = (float)($totSt->fetchColumn() ?? 0);

  echo json_encode(['status'=>'ok','items'=>$rows,'total'=>$total]); exit;
}

/* ===================== Main grid data ===================== */
$activePatientId = (int)($_SESSION['active_patient_id'] ?? 0);
$openedIds = $_SESSION['opened_patients'] ?? [];

$patients = [];
if (!empty($openedIds)) {
  $in  = implode(',', array_fill(0, count($openedIds), '?'));

  // v8: donor_applied uses guarantee_usages; paid_amount excludes donor rows
  $sql = "
    SELECT
      p.id,
      p.personal_id,
      p.first_name,
      p.last_name,
      p.birthdate,
      COALESCE(SUM(ps.sum), 0) AS total_sum,
      COALESCE(pay.paid_real, 0)     AS paid_amount,
      COALESCE(guu.applied, 0)       AS donor_applied,
      GREATEST(
        COALESCE(SUM(ps.sum),0) - COALESCE(pay.paid_real,0) - COALESCE(guu.applied,0),
        0
      ) AS debt,
      CASE WHEN COALESCE(SUM(ps.sum),0) > 0
           THEN ROUND(100 * (COALESCE(pay.paid_real,0) + COALESCE(guu.applied,0)) / COALESCE(SUM(ps.sum),0))
           ELSE 0 END AS percent_paid
    FROM patients p
    LEFT JOIN patient_services ps ON ps.patient_id = p.id
    LEFT JOIN (
      SELECT patient_id, SUM(amount) AS paid_real
      FROM payments
      WHERE method <> 'donor'
      GROUP BY patient_id
    ) pay ON pay.patient_id = p.id
    LEFT JOIN (
      SELECT p.patient_id, SUM(u.amount) AS applied
      FROM guarantee_usages u
      JOIN payments p ON p.id = u.payment_id
      GROUP BY p.patient_id
    ) guu ON guu.patient_id = p.id
    WHERE p.id IN ($in)
    GROUP BY p.id, p.personal_id, p.first_name, p.last_name, p.birthdate, pay.paid_real, guu.applied
    ORDER BY p.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($openedIds);
  $patients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} else {
  // Fallback: show ALL patients (latest first) so the grid isn't empty after logout
  $sql = "
    SELECT
      p.id,
      p.personal_id,
      p.first_name,
      p.last_name,
      p.birthdate,
      COALESCE(SUM(ps.sum), 0) AS total_sum,
      COALESCE(pay.paid_real, 0)     AS paid_amount,
      COALESCE(guu.applied, 0)       AS donor_applied,
      GREATEST(
        COALESCE(SUM(ps.sum),0) - COALESCE(pay.paid_real,0) - COALESCE(guu.applied,0),
        0
      ) AS debt,
      CASE WHEN COALESCE(SUM(ps.sum),0) > 0
           THEN ROUND(100 * (COALESCE(pay.paid_real,0) + COALESCE(guu.applied,0)) / COALESCE(SUM(ps.sum),0))
           ELSE 0 END AS percent_paid
    FROM patients p
    LEFT JOIN patient_services ps ON ps.patient_id = p.id
    LEFT JOIN (
      SELECT patient_id, SUM(amount) AS paid_real
      FROM payments
      WHERE method <> 'donor'
      GROUP BY patient_id
    ) pay ON pay.patient_id = p.id
    LEFT JOIN (
      SELECT pm.patient_id, SUM(u.amount) AS applied
      FROM guarantee_usages u
      JOIN payments pm ON pm.id = u.payment_id
      GROUP BY pm.patient_id
    ) guu ON guu.patient_id = p.id
    GROUP BY p.id, p.personal_id, p.first_name, p.last_name, p.birthdate, pay.paid_real, guu.applied
    ORDER BY p.id DESC
  ";
  $patients = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$cur = basename($_SERVER['PHP_SELF']);
?>

<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>პაციენტების მართვა</title>
<style>
:root{ --brand:#21c1a6; --brand-2:#14937c; --bg:#f9f8f2; --text:#222; --muted:#888; }
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);margin:0}
.topbar{background:var(--brand);color:#fff;padding:12px 24px;display:flex;justify-content:flex-end;align-items:center}
.user-menu-wrap{position:relative}
.user-btn{display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer}
.user-dropdown{position:absolute;top:36px;right:0;background:#fff;border:1px solid #e4e4e4;border-radius:8px;display:none;min-width:140px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.user-dropdown a{display:block;padding:10px 14px;color:#1e665b;text-decoration:none}
.user-dropdown a:hover{background:#e6f6f3}
.container{max-width:1650px;margin:32px auto;padding:0 24px;display:flex;gap:20px}
.mainContent{flex:1;min-width:0}
.rightSidebar{width:380px;background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;height:fit-content}
@media (max-width:1100px){ .container{flex-direction:column} .rightSidebar{width:100%} }
.tabs{list-style:none;display:flex;gap:6px;padding-left:0;margin:0 0 14px;border-bottom:2px solid #ddd}
.tabs i {
  display: inline-block;
  margin-right: .4rem;
  line-height: 1;
}

.tabs a{padding:10px 18px;background:var(--brand);color:#fff;border-top-left-radius:7px;border-top-right-radius:7px;text-decoration:none}
.tabs a.active,.tabs a:hover{background:#fff;color:var(--brand)}
.patients-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(31,61,124,.08)}
.patients-table th,.patients-table td{padding:12px;border-bottom:1px solid #eee;text-align:left}
.patients-table th{background:var(--brand);color:#fff}
.subtabswrap{max-width:1650px;margin:0 auto 6px;padding:0 24px}
.subtabs{list-style:none;display:flex;gap:6px;margin:0 0 12px;padding:0;border-bottom:2px solid #e6e6e6}
.subtabs a{display:inline-block;padding:8px 14px;text-decoration:none;border-top-left-radius:8px;border-top-right-radius:8px;background:var(--brand);color:#fff;font-weight:600}
.subtabs a:hover,.subtabs a.active{background:#fff;color:var(--brand);border:1px solid #cfeee8;border-bottom-color:#fff}

.patients-table tbody tr.selected{background:#b5e6d6}
.action-links-table a{display:block;text-align:center;font-weight:600;padding:6px 10px;color:var(--brand);text-decoration:none;border-radius:6px;border:1px solid #d6efeb;background:#e6f6f3}
.action-links-table a.disabled{color:#aaa!important;pointer-events:none;cursor:default;background:#f3f3f3;border-color:#eee}
.action-links-table a:hover{background:#dff3ef}
#loadingIndicator{display:none;margin-left:auto;border:2px solid var(--brand);border-top:2px solid transparent;border-radius:50%;width:16px;height:16px;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
#payOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-start;justify-content:center;padding:32px 12px;z-index:9999}
#payOverlay .modal{position:relative;background:#fff;border-radius:10px;box-shadow:0 10px 26px rgba(0,0,0,.25);width:960px;max-width:100%;max-height:calc(100vh - 64px);overflow:auto;padding:16px;border:1px solid #e8e8e8}
#payOverlay .close{position:absolute;top:10px;right:10px;width:34px;height:34px;border:1px solid #ddd;border-radius:50%;background:#f7f7f7;cursor:pointer}
#payOverlay .close:hover{background:var(--brand);color:#fff;border-color:var(--brand)}
.pay-topbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:6px 8px;background:#f3fbf9;border:1px solid #e4f2ef;border-radius:8px;margin-bottom:10px}
ul.tabs{display:flex;gap:12px;list-style:none;padding:0;margin:0}
ul.tabs a{display:block;padding:8px 12px;border-radius:6px;text-decoration:none;border:1px solid #ddd}
ul.tabs a.active{background:#e6f6f3;border-color:#21c1a6;color:#116a5b;font-weight:700}

.pay-topbar .tabs{border:0;margin:0;gap:0}
.pay-topbar .tabs .tab{display:block;padding:6px 10px;border:1px solid #d5d5d5;color:#6f6d6d;background:#fff;text-decoration:none}
.pay-topbar .tabs .tab.active{background:#fff;color:#6f6d6d;border-bottom:2px solid #fff;box-shadow:inset 0 -2px 0 var(--bg)}
.total-badge{margin-left:auto;background:#fff;border:1px dashed #21c1a6;padding:6px 10px;border-radius:6px}
.sel-badge{background:#fff;border:1px solid #cfece6;padding:6px 10px;border-radius:6px}
.tabs i { display: inline-block; margin-right: .4rem; }

.table-wrap{overflow:auto}
.pay-table{width:100%;border-collapse:collapse;font-size:14px;min-width:920px}
.pay-table th,.pay-table td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
.pay-table thead th{background:var(--brand);color:#fff}
.pay-table input[type="text"]{width:100%;padding:6px;border:1px solid #ccc;border-radius:6px;text-align:center}
.mini-stack{display:flex;gap:4px;align-items:center;margin-top:4px}
.mini{width:40px;padding:4px;border:1px solid #bbb;border-radius:4px;text-align:center}
.mini-btn{width:26px;height:26px;border:1px solid #bbb;background:#fff;border-radius:4px;cursor:pointer}
.btn-row{margin:8px 0}
.btn{display:inline-block;padding:6px 10px;border-radius:6px;background:#e6f6f3;color:#116a5b;text-decoration:none;font-weight:600;border:1px solid #d6efeb;margin-left:6px}
.edit-btn{background:#eef6ff;color:#115b9b;border-color:#d9e9ff}
.danger-btn{background:#ffecec;color:#9b1111;border-color:#ffd3d3}
.card{background:#fff;border:1px solid #ececec;border-radius:8px;padding:12px;margin-top:10px}
.pay-form .row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.pay-form input[type="text"], .pay-form select{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px}
.pay-form .actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}
.pay-btn{padding:8px 14px;border:none;border-radius:6px;background:#21c1a6;color:#fff;font-weight:700;cursor:pointer}
.pay-btn:hover{background:#14937c}
.simple-table{width:100%;border-collapse:collapse;font-size:14px}
.simple-table th,.simple-table td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
.lb2.cg,.cce.tcc{white-space:nowrap}
.disCol{opacity:.6}
.diaz{display:none}
tr.paid{opacity:.55}
tr.paid td:nth-child(2){text-decoration:line-through}
.small{font-size:12px;color:#666}
.pay-actions{display:flex;gap:8px;align-items:center;margin:8px 0}
@media (max-width:1200px){ #payOverlay .modal{width:96%} }
@media (max-width:992px){ .pay-form .row{grid-template-columns:repeat(2,1fr)} .table-wrap{overflow:auto} .pay-table{min-width:880px} }
@media (max-width:640px){ .pay-form .row{grid-template-columns:1fr} .pay-table{min-width:720px} .btn,.pay-btn{font-size:13px;padding:8px 10px} }
.patient-detail-section{border-top:1px solid #ddd;margin-top:10px;padding-top:8px;font-size:14px;color:#444}
#searchInput{border:1.5px solid #ccc}</style>

<!-- Bridge server org info -> JS -->
<script>
window.__ORG__ = <?= json_encode($ORG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
</head>
<body>

<div class="topbar">
  <div class="user-menu-wrap" id="userMenu">
    <div class="user-btn" id="userBtn">
      <span><?= h($_SESSION['username'] ?? 'მომხმარებელი') ?></span>
      <span style="font-weight:900">▾</span>
    </div>
    <div class="user-dropdown" id="userDropdown">
      <a href="profile.php">პროფილი</a>
      <a href="logout.php">გასვლა</a>
    </div>
  </div>
</div>

<div class="container">
  <div class="mainContent">
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



    <!-- Subtabs -->
    <div class="subtabswrap">
      <ul class="subtabs">
        <a href="patient_hstory.php<?= $activePatientId ? ('?patient_id='.(int)$activePatientId) : '' ?>"
          class="<?= basename($_SERVER['PHP_SELF'])==='patient_hstory.php' ? 'active' : '' ?>">
          ისტორია
        </a>

        </li>
        <li>
          <a href="my-patient.php"
             class="<?= basename($_SERVER['PHP_SELF'])==='my-patient.php' ? 'active' : '' ?>">
            ჩემი პაციენტები
          </a>
        </li>
      </ul>
    </div>


    <label for="searchInput" style="display:block;margin-bottom:8px;font-weight:600;color:var(--brand)">ძებნა პაციენტებში</label>
    <input id="searchInput" type="text" placeholder="სახელი, გვარი, პ/ნ..." style="width:100%;padding:8px 10px;border:1.5px solid #ccc;border-radius:6px;margin-bottom:14px">

    <h2 style="margin:6px 0 10px">პაციენტების სია</h2>
    <table class="patients-table" id="patientsTable">
      <thead>
        <tr>
          <th>პ/ნ</th><th>სახელი</th><th>გვარი</th><th>თარიღი</th>
          <th>#</th><th>ჯამი</th><th>%</th><th>ვალი</th><th>გადახდ.</th><th>ქმედება</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$patients): ?>
          <tr><td colspan="10" style="text-align:center;color:#888;font-style:italic">ჩანაწერი ვერ მოიძებნა</td></tr>
        <?php else: foreach($patients as $p): ?>
          <tr data-id="<?= (int)$p['id'] ?>"
    data-firstname="<?= h($p['first_name']) ?>"
    data-lastname="<?= h($p['last_name']) ?>"
    data-personalid="<?= h($p['personal_id']) ?>">

            <td class="col-personal"><?= h($p['personal_id']) ?></td>
            <td class="col-first"><?= h($p['first_name']) ?></td>
            <td class="col-last"><?= h($p['last_name']) ?></td>
            <td class="col-birth"><?= $p['birthdate'] ? h(date('Y-m-d', strtotime($p['birthdate']))) : '–' ?></td>
            <td><?= (int)$p['id'] ?></td>
            <td><?= nf2($p['total_sum']) ?></td>
            <td><?= (int)$p['percent_paid'] ?>%</td>
            <td><?= nf2($p['debt']) ?></td>
            <td><?= nf2($p['paid_amount']) ?></td>
            <td>
              <button class="pay-btn" onclick="openPayModal(<?= (int)$p['id'] ?>)">გადახდა</button>
              <button class="btn edit-btn" onclick="openEditPatient(<?= (int)$p['id'] ?>)">რედაქტირება</button>
              <button class="btn danger-btn" onclick="confirmDeletePatient(<?= (int)$p['id'] ?>, this)">წაშლა</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="rightSidebar" aria-live="polite" aria-atomic="true" aria-label="Patient details and actions">
    <div style="height:auto; position: relative;">
      <table class="action-links-table" border="0" cellpadding="0" cellspacing="0" style="width:100%; table-layout: fixed;">
        <tbody>
          <tr>
            <td><a href="#" id="mr_pay" class="disabled" tabindex="-1" aria-disabled="true">გადახდა</a></td>
            <td><a href="#" id="mr_chkout" class="disabled" tabindex="-1" aria-disabled="true">გაწერა</a></td>
            <td><a href="#" id="mr_rwrt" class="disabled" tabindex="-1" aria-disabled="true">გადაწერა</a></td>
            <td style="padding-right:0"><a href="#" id="mr_rpinvo" class="disabled" tabindex="-1" aria-disabled="true">ინვოისი</a></td>
          </tr>
          <tr>
            <td style="padding-top:6px;" colspan="2"><a href="#" id="mr_rpxcalc" class="disabled" tabindex="-1" aria-disabled="true">კალკულაცია</a></td>
            <td style="padding-top:6px;"><a href="#" id="mr_rpxcfqt" class="disabled" tabindex="-1" aria-disabled="true">ანალიზები</a></td>
            <td style="padding-top:6px;padding-right:0"><a href="#" id="mr_prcwages" class="disabled" tabindex="-1" aria-disabled="true">ხელფასები</a></td>
          </tr>
        </tbody>
      </table>
      <div id="loadingIndicator" title="იტვირთება..."></div>
    </div>

    <div style="width:100%; margin-top:16px;">
      <input type="hidden" id="hdPtRgID" aria-hidden="true" />
      <div style="padding-bottom:4px;">
        <div id="fullname" style="color:#639; position:relative; font-size:16px; font-weight:600; margin: 0px auto 6px auto; text-align:center;" aria-live="polite" aria-atomic="true">
          აირჩიეთ პაციენტი სიისგან, რომ ნახოთ დეტალები.
        </div>
      </div>
    </div>

    <div style="margin-top: 16px;">
      <div class="patient-detail-section" id="patientHistory" aria-live="polite" aria-atomic="true" style="display:none;">
        <h3 style="margin:0 0 8px; color:var(--brand)">მომსახურებები</h3>
        <div id="historyContent" style="font-size:14px">იტვირთება...</div>
      </div>
      <div class="patient-detail-section" id="patientAppointments" aria-live="polite" aria-atomic="true" style="display:none;">
        <h3>აპოინტმენტები</h3>
        <p id="appointmentsContent">აპოინტმენტების სია და დეტალები...</p>
      </div>
    </div>
  </div>
</div>

<div id="payOverlay" role="dialog" aria-modal="true"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script>
function recalcPaymentsSummary(){
  let paidReal = 0;
  $('#pa_pa tbody tr').each(function(){
    const m = $(this).find('td').eq(2).text().trim().toLowerCase();
    const amt = parseFloat(($(this).find('td').eq(4).text()||'').replace(/[^0-9.]/g,''))||0;
    if (m !== 'donor') paidReal += amt;
  });
  $('#pa_gadax').val(nf2(paidReal));
}
// Cancel closes dialog
$(document).on('click', '#ep_cancel', function () {
  $('#dlgEditPay').remove();
});

// Save -> POST -> update row -> close
$(document).on('click', '#ep_save', function () {
  const $dlg   = $('#dlgEditPay');
  const payId  = Number($dlg.data('pay-id'));
  const pid    = Number($dlg.data('pid'));

  const payload = new URLSearchParams({
    action:   'update_payment',
    patient_id: String(pid),
    payment_id: String(payId),
    paid_at:  $('#ep_paid_at').val(),
    method:   $('#ep_method').val(),
    amount:   $('#ep_amount').val(),
    order_no: $('#ep_order').val()
  });

  fetch('', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: payload
  })
  .then(r => r.json())
  .then(j => {
    if (j.status !== 'ok') {
      alert(j.message || 'ვერ შეინახა.');
      return;
    }

    // Update the payments table row
    const $tr = $('#pa_pa').find(`tr#O${payId}`);
    if ($tr.length) {
      $tr.find('td').eq(1).text(j.row.paid_at);
      $tr.find('td').eq(2).html('<b>'+ j.row.method +'</b>');
      $tr.find('td').eq(3).text(j.row.order_no || '');
      $tr.find('td').eq(4).text(j.row.amount);
    }

    // Recalc summary, close dialog
    if (typeof recalcPaymentsSummary === 'function') recalcPaymentsSummary();
    $dlg.remove();
  })
  .catch(err => {
    console.error(err);
    alert('ქსელის შეცდომა.');
  });
});// EDIT PAYMENT (adds conditional "დონორი" option)
// OPEN EDIT DIALOG (with donor support)
$(document).on('click', '#payOverlay .edit-payment', function () {
  const $btn    = $(this);
  const isDonor = !!$btn.data('donor');
  const payId   = Number($btn.data('pay-id'));
  const pid     = Number($btn.data('pid'));
  const paidAt  = String($btn.data('paid-at') || '');
  const method  = String($btn.data('method')  || 'cash').toLowerCase();
  const amount  = String($btn.data('amount')  || '0.00');
  const orderNo = String($btn.data('order-no')|| '');
  const guarId  = Number($btn.data('guarantee-id') || 0);

  // Grab donor options from the existing select (#pa_dontype) so amounts are in data-amount
  const $sourceSel = $('#pa_dontype');
  const donorOptionsHTML = $sourceSel.length ? $sourceSel.html() : '<option value=""></option>';

  const donorLeftBadge = document.getElementById('donor_left_badge');
  const donorLeftTotal = donorLeftBadge ? parseFloat(donorLeftBadge.getAttribute('data-total-init') || '0') : 0;
  const showDonor = isDonor || donorLeftTotal > 0;

  const donorSection = showDonor ? `
    <label>ავანსატორი
      <select id="ep_donor" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">
        ${donorOptionsHTML}
      </select>
    </label>
  ` : '';

  const html = `
    <div id="dlgEditPay" data-pay-id="${payId}" data-pid="${pid}"
         style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:10000;">
      <div style="background:#fff;border:1px solid #ddd;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.25);
                  width:520px;max-width:calc(100vw - 40px);padding:16px;position:relative;">
        <button class="close" id="ep_cancel"
                style="position:absolute;top:10px;right:10px;width:34px;height:34px;border:1px solid #ddd;border-radius:50%;
                       background:#f7f7f7;cursor:pointer">&times;</button>
        <h3 style="margin:0 0 10px;">გადახდის რედაქტირება</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label>თარიღი
            <input type="text" id="ep_paid_at" value="${paidAt}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">
          </label>
          <label>ტიპი
            <select id="ep_method" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">
              <option value="cash" ${method==='cash'?'selected':''}>სალარო</option>
              <option value="bog" ${method==='bog'?'selected':''}>BOG</option>
              <option value="transfer" ${method==='transfer'?'selected':''}>გადმორიცხვა</option>
              ${showDonor ? `<option value="donor" ${method==='donor'?'selected':''}>დონორი</option>` : ''}
            </select>
          </label>
          <label>თანხა
            <input type="text" id="ep_amount" value="${amount}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">
          </label>
          <label>ორდერი
            <input type="text" id="ep_order" value="${orderNo}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">
          </label>

          <div id="ep_donor_wrap" style="grid-column:1 / span 2; ${ (showDonor && method==='donor') ? '' : 'display:none;' }">
            ${donorSection}
            <small style="display:block;color:#178e7b;margin-top:6px">
              * თუ შერჩეულ დონორს არ ეყოფა თანხა, შენახვისას გამოჩნდება შეცდომა.
            </small>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
          <button id="ep_cancel" class="btn" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;background:#fff">გაუქმება</button>
          <button id="ep_save" class="pay-btn" style="padding:8px 12px;border:none;border-radius:6px;background:#21c1a6;color:#fff;font-weight:700;cursor:pointer">
            შენახვა
          </button>
        </div>
      </div>
    </div>`;

  $('#payOverlay').append(html).show();

  // Preselect donor in the new select if we had one on the row
  if (showDonor && guarId) {
    $('#ep_donor').val(String(guarId));
  }
});

// Toggle donor select visibility when method changes
$(document).on('change', '#ep_method', function(){
  const isDon = $(this).val() === 'donor';
  $('#ep_donor_wrap').toggle(!!isDon);
});

// SAVE handler (posts donor_id when method = donor)
$(document).on('click', '#ep_save', async function(){
  const dlg     = document.getElementById('dlgEditPay');
  const payId   = Number(dlg.getAttribute('data-pay-id'));
  const pid     = Number(dlg.getAttribute('data-pid'));
  const paidAt  = String($('#ep_paid_at').val() || '');
  const method  = String($('#ep_method').val()  || 'cash').toLowerCase();
  const amountS = String($('#ep_amount').val()  || '0').replace(',', '.');
  const amount  = parseFloat(amountS) || 0;
  const orderNo = String($('#ep_order').val()   || '');
  let donorId   = 0;

  if (method === 'donor') {
    const $opt = $('#ep_donor option:selected');
    donorId = parseInt($opt.val() || '0', 10) || 0;

    if (!donorId) {
      alert('აირჩიე ავანსატორი (დონორი)'); return;
    }

    // Client-side soft check (server does the real one too)
    const left = parseFloat(String($opt.data('amount') || '0').replace(',', '.')) || 0;
    // If editing an existing donor on the same guarantee, allow reusing its own previous amount
    const prevMethod = String($('[data-pay-id="'+payId+'"].edit-payment').data('method') || '').toLowerCase();
    const prevGuar   = Number($('[data-pay-id="'+payId+'"].edit-payment').data('guarantee-id') || 0);
    const prevAmt    = parseFloat(String($('[data-pay-id="'+payId+'"].edit-payment').data('amount') || '0').replace(',', '.')) || 0;
    const allowed    = left + ((prevMethod==='donor' && prevGuar===donorId) ? prevAmt : 0);

    if (amount > allowed + 1e-6) {
      alert('დონორს არ აქვს საკმარისი ნაშთი'); return;
    }
  }

  try{
    const res = await fetch('patient_hstory.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'update_payment',
        patient_id: pid,
        payment_id: payId,
        paid_at: paidAt,
        method,
        amount,
        order_no: orderNo,
        donor_id: donorId || undefined
      })
    });
    const j = await res.json();
    if (j.status !== 'ok') throw new Error(j.message || 'შეცდომა');

    // close dialog
    $('#dlgEditPay').remove();

    // Update the row UI quickly (method/amount/order)
    const $row = $('#O'+payId);
    if ($row.length) {
      $row.find('td').eq(2).html('<b>'+method+(method==='donor' ? ' — დონორი' : '')+'</b>');
      $row.find('td').eq(3).text(orderNo);
      $row.find('td').eq(4).text((Math.round(parseFloat(j.row.amount)*100)/100).toFixed(2));
      // also refresh data-* on Edit button
      const $btn = $row.find('.edit-payment');
      $btn.data('method', method);
      $btn.data('amount', j.row.amount);
      if (method==='donor') {
        $btn.attr('data-donor','1').data('donor',1);
        if (j.row.guarantee_id) {
          $btn.attr('data-guarantee-id', j.row.guarantee_id).data('guarantee-id', j.row.guarantee_id);
        }
      } else {
        $btn.removeAttr('data-donor').data('donor', null);
        $btn.attr('data-guarantee-id','0').data('guarantee-id', 0);
      }
    }

  } catch (e) {
    alert(e.message || 'შეცდომა');
  }
});

// close dialog
$(document).on('click', '#ep_cancel', function(){ $('#dlgEditPay').remove(); });



function nf2(n){ n = Number(n)||0; return n.toFixed(2); }
function recalcPayModalTotals(){
  let total = 0, selected = 0;
  $('#fs_amounttypet tbody tr').each(function(){
    const $tr = $(this);
    const sum = parseFloat(($tr.find('.cce.tcc').text()||'').replace(/[^0-9.]/g,''))||0;
    total += sum;
    if ($tr.find('.srv-chk').prop('checked')) selected += sum;
  });
  $('#pa_pipa').val(nf2(total));      // პროცედურების ჯამი
  $('#sel_badge').text(nf2(selected)); // მონიშნულის გადასახდელი
  $('#en3m').text(nf2(selected));      // დარჩენილი გადასახდელი (your UI used same number)
}

$(document).on('click', '#payOverlay .del-srv', function(){
  const $btn = $(this);
  const psId = $btn.data('ps-id');
  const pid  = $btn.data('pid');
  const $tr  = $btn.closest('tr');

  if (!confirm('წავშალოთ სერვისი?')) return;

  fetch('', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
    body: new URLSearchParams({
      action: 'delete_patient_service',
      patient_service_id: psId,
      patient_id: pid
    })
  }).then(r=>r.json()).then(j=>{
    if (j.status === 'ok') {
      $tr.remove();
      recalcPayModalTotals();
    } else {
      alert(j.message || 'წაშლა ვერ შესრულდა');
    }
  }).catch(()=> alert('ქსელის შეცდომა'));
});
 function addPatientRow(p){
  const tbody = document.querySelector('#patientsTable tbody');

  // remove the "ჩანაწერი ვერ მოიძებნა" placeholder if present
  const empty = tbody.querySelector('tr td[colspan="10"]');
  if (empty) empty.parentElement.remove();

  const tr = document.createElement('tr');
  tr.setAttribute('data-id', String(p.id));
  tr.setAttribute('data-firstname', p.first_name || '');
  tr.setAttribute('data-lastname',  p.last_name  || '');
  tr.setAttribute('data-personalid', p.personal_id || ''); // ← add this
  tr.innerHTML = `
    <td class="col-personal">${esc(p.personal_id || '')}</td>
    <td class="col-first">${esc(p.first_name || '')}</td>
    <td class="col-last">${esc(p.last_name || '')}</td>
    <td class="col-birth">${p.birthdate ? esc(p.birthdate) : '–'}</td>
    <td>${Number(p.id)||0}</td>
    <td>${(Number(p.total_sum)||0).toFixed(2)}</td>
    <td>${Number(p.percent_paid||0)}%</td>
    <td>${(Number(p.debt)||0).toFixed(2)}</td>
    <td>${(Number(p.paid_amount)||0).toFixed(2)}</td>
    <td>
      <button class="pay-btn" onclick="openPayModal(${Number(p.id)||0})">გადახდა</button>
      <button class="btn edit-btn" onclick="openEditPatient(${Number(p.id)||0})">რედაქტირება</button>
      <button class="btn danger-btn" onclick="confirmDeletePatient(${Number(p.id)||0}, this)">წაშლა</button>
    </td>`;

  tbody.prepend(tr);
  tr.scrollIntoView({behavior:'smooth', block:'center'});
  tr.click();

  // ✅ persist in the place that actually updates the session
  ServerSync.tryNowOrQueue({
    url: 'patient_hstory.php',
    data: { action: 'add_or_activate', patient_id: p.id }
  });
}

function markOpenedAndActive(pid){
  ServerSync.tryNowOrQueue({ url: '', data: { action: 'add_or_activate', patient_id: pid } });
}

// (Safety: lightweight defaults if these aren’t defined elsewhere)
if (typeof confirmDeletePatient !== 'function') {
  window.confirmDeletePatient = function(pid, btn){
    if (!confirm('წავშალოთ პაციენტი?')) return;
    btn?.setAttribute('disabled','true');
    fetch('', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams({ action:'delete_patient', patient_id: pid })
    }).then(r=>r.json()).then(j=>{
      if (j.status === 'ok') {
        const tr = document.querySelector(`#patientsTable tbody tr[data-id="${pid}"]`);
        tr?.remove();
      } else {
        alert(j.message || 'ვერ წაიშალა'); btn?.removeAttribute('disabled');
      }
    }).catch(()=>{ alert('შეცდომა'); btn?.removeAttribute('disabled'); });
  };
}
document.addEventListener('click', async function(e){
  const btn = e.target.closest('.delete-payment');
  if (!btn) return;

  const payId  = +btn.getAttribute('data-pay-id');
  const pid    = +btn.getAttribute('data-pid');
  const method = (btn.getAttribute('data-method')||'').toLowerCase();
  const isWallet = +btn.getAttribute('data-wallet') === 1;

  let msg = 'ჩანაწერის წაშლა? ეს ქმედება შეუქცევადია.';
  if (method === 'donor') {
    msg = 'ეს არის დონორის გადახდა. წაშლა ასევე მოხსნის მის გამოყენებებს. გავაგრძელოთ?';
  } else if (isWallet) {
    msg = 'ეს არის ავანსის (პრეპეიმენტის) ჩანაწერი. წაშლა მოხდება მხოლოდ იმ შემთხვევაში, თუ ავანსი გამოყენებული არ არის. გავაგრძელოთ?';
  }

  if (!confirm(msg)) return;

  btn.disabled = true;

  try {
    const res = await fetch('patient_hstory.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'delete_payment',
        patient_id: pid,
        payment_id: payId
      })
    });
    const j = await res.json();

    if (j.status !== 'ok') throw new Error(j.message || 'ვერ წაიშალა');

    // remove row
    const tr = document.getElementById('O' + payId);
    if (tr && tr.parentNode) tr.parentNode.removeChild(tr);

    // quick recalc: sum remaining "amount" column (5th col) and update #real-paid field
    (function recalcTotals(){
      const rows = document.querySelectorAll('#pa_pa tbody tr');
      let sum = 0;
      rows.forEach(r=>{
        const tds = r.querySelectorAll('td');
        if (tds.length >= 5) {
          const v = (tds[4].textContent||'').replace(/[^0-9.,-]/g,'').replace(',', '.');
          const n = parseFloat(v);
          // exclude donor from "რეალურად გადახდილი"
          const methodTd = tds[2]?.textContent?.toLowerCase() || '';
          if (!/დონორ/.test(methodTd)) sum += (isFinite(n) ? n : 0);
        }
      });
      const fld = document.getElementById('pa_gadax');
      if (fld) fld.value = (Math.round(sum*100)/100).toFixed(2);
    })();

  } catch (err) {
    alert(err.message || 'შეცდომა');
  } finally {
    btn.disabled = false;
  }
});
/* --- user menu --- */
document.getElementById('userBtn').addEventListener('click', () => {
  const dd = document.getElementById('userDropdown');
  dd.style.display = dd.style.display==='block' ? 'none' : 'block';
});
document.addEventListener('click', (e)=>{
  const w=document.getElementById('userMenu');
  if(!w.contains(e.target)) document.getElementById('userDropdown').style.display='none';
});

/* helpers */
function updateActionLinks(enabled){
  document.querySelectorAll('.action-links-table a').forEach(a=>{
    if(enabled){ a.classList.remove('disabled'); a.setAttribute('aria-disabled','false'); a.tabIndex=0; }
    else{ a.classList.add('disabled'); a.setAttribute('aria-disabled','true'); a.tabIndex=-1; }
  });
}
function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ===================== ADD: SERVER SYNC (retry queue) ===================== */
/* Paste once. Robust offline queue that retries sends when connection is back. */
const ServerSync = (() => {
  const KEY = '__sync_queue_v1__';
  const MAX_RETRIES = 6;
  const FLUSH_EVERY_MS = 30000;

  const readQ = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch { return []; } };
  const writeQ = (q) => localStorage.setItem(KEY, JSON.stringify(q));

  const enqueue = (job) => {
    const q = readQ();
    q.push(Object.assign({ attempt: 0, ts: Date.now() }, job));
    writeQ(q);
  };

  const toFormData = (obj) => {
    const fd = new FormData();
    Object.entries(obj || {}).forEach(([k, v]) => fd.append(k, v));
    return fd;
  };

  async function send(job){
    const resp = await fetch(job.url || '', { method:'POST', body: toFormData(job.data), credentials:'same-origin' });
    const ct = resp.headers.get('content-type') || '';
    const j  = ct.includes('application/json') ? await resp.json() : null;
    if (!resp.ok || !j || j.status !== 'ok') {
      const msg = (j && j.message) || `HTTP ${resp.status}`;
      throw new Error(msg);
    }
    return j;
  }

  async function flush(){
    if (!navigator.onLine) return { ok:false, reason:'offline' };
    const q = readQ(); if (!q.length) return { ok:true };
    const keep = [];
    for (const job of q){
      try { await send(job); }
      catch (err){
        job.attempt = (job.attempt||0) + 1;
        if (job.attempt < MAX_RETRIES) keep.push(job);
        else console.warn('Dropping job after max retries:', job, err);
      }
    }
    writeQ(keep);
    return { ok: keep.length === 0 };
  }

  async function tryNowOrQueue(job){
    try { const res = await send(job); return { ok:true, res }; }
    catch (err){ enqueue(job); setTimeout(flush, 1500); return { ok:false, err }; }
  }

  // background triggers
  window.addEventListener('online', () => setTimeout(flush, 150));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) flush(); });
  setInterval(flush, FLUSH_EVERY_MS);

  return { tryNowOrQueue, flush, enqueue };
})();

/* ================== delay + glAj (shim) ================== */
(function(){
  // debounce helper used by inline onkeyup
  if (!window.delay){
    let __t; window.delay = function(fn, ms){ clearTimeout(__t); __t = setTimeout(fn, ms||0); };
  }
  if (window.glAj) return;

  function escHtml(s){ return String(s??'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  /* ====== NEW: Local donors (variations) + Tbilisi districts ====== */
  const TBILISI_DISTRICTS = [
    'მთაწმინდა','ვაკე','საბურთალო','დიდუბე','ჩუღურეთი',
    'ნაძალადევი','გლდანი','ისანი','სამგორი','კრწანისი'
  ];
  const DONOR_VARIATIONS = [
    'დონორი','ავანსატორი','სპონსორი',
    'მშობელი','მშობელი — დედა','მშობელი — მამა',
    'მინდობით პირი','მეუღლე','ფიზიკური პირი','იურიდიული პირი'
  ];
  const LOCAL_DONORS = [
    ...DONOR_VARIATIONS.map((v, i) => ({ id: `local:var:${i}`,  name: v,               address: '-' })),
    ...TBILISI_DISTRICTS.map((d, i) => ({ id: `local:dist:${i}`, name: `დონორი — ${d}`, address: d  }))
  ];
  function norm(s){ return String(s||'').toLowerCase().trim(); }

  /* ====== UPDATED: buyerSearch merges remote + local ====== */
  async function buyerSearch(q){
    const qq = norm(q);
    let remote = [];
    try{
      const r = await fetch(`?action=buyer_search&q=${encodeURIComponent(q)}`, {credentials:'same-origin'});
      const j = await r.json().catch(()=>[]);
      const rows = Array.isArray(j) ? j : (j.rows||[]);
      remote = rows.map(x=>({
        id: x.id || x.buyer_id || x.ID || '',
        name: x.name || x.title || x.company || '',
        address: x.address || x.addr || '-'
      })).filter(x=>x.id && x.name);
    }catch(_){ /* ignore, fallback to local only */ }

    const localMatches = LOCAL_DONORS.filter(it =>
      norm(it.name).includes(qq) || norm(it.address).includes(qq)
    );

    const merged = [...remote, ...localMatches];
    const seen = new Set();
    const unique = [];
    for (const it of merged){
      const key = norm(it.name)+'|'+norm(it.address);
      if (seen.has(key)) continue;
      seen.add(key);
      unique.push(it);
    }
    return unique;
  }

  window.glAj = async function(action, target, _a,_b,_c,_sep1,_sep2, payloadA, payloadB){
    try{
      if(action==='search4v'){
        const inp = document.activeElement;
        const listSel = inp?.getAttribute('l') || '';
        const list = listSel ? document.querySelector(listSel) : null;
        const q = (inp?.value||'').trim();
        if (!list) return;
        if (q.length < 2){ list.innerHTML=''; return; }

        const items = await buyerSearch(q);
        list.innerHTML = items.length
          ? items.map(it=>`<div class="item" data-id="${escHtml(it.id)}" data-name="${escHtml(it.name)}" data-addr="${escHtml(it.address||'-')}" style="padding:6px 8px;cursor:pointer">${escHtml(it.name)} — <span class="muted" style="color:#777">${escHtml(it.address||'-')}</span></div>`).join('')
          : '<div class="item muted" style="padding:6px 8px;color:#888">ვერ მოიძებნა</div>';

        list.onclick = (e)=>{
          const it = e.target.closest('.item'); if(!it) return;
          const wrap = list.closest('table.tg') || document;
          const hid  = wrap.querySelector('input[id^="hdansvl"]');
          const nm   = wrap.querySelector('input[id^="rfbn"]');
          const ad   = wrap.querySelector('input[id^="rfbn"][id*="ads"]') || wrap.querySelector('input[id^="rfbn–ads"]');

          if (hid) hid.value = it.dataset.id||'';
          if (nm)  nm.value  = it.dataset.name||'-';
          if (ad)  ad.value  = it.dataset.addr||'-';

          // გლობალური დონორი (ბეჭდვისთვის)
          window.__DONOR_ID__   = it.dataset.id || '';
          window.__DONOR_NAME__ = it.dataset.name || '—';
          window.__DONOR_ADDR__ = it.dataset.addr || '-';

          list.innerHTML='';
        };
        return;
      }

      if (action==='insert4v' && target==='grdm_avк'){
        const active = document.activeElement;
        const wrap = active?.closest('table.tg') || document;
        const hid  = wrap.querySelector('input[id^="hdansvl"]');
        const nm   = wrap.querySelector('input[id^="rfbn"]');
        const ad   = wrap.querySelector('input[id^="rfbn"][id*="ads"]') || wrap.querySelector('input[id^="rfbn–ads"]');

        const id   = hid?.value?.trim() || '';
        const name = (nm?.value||'—').trim();
        const addr = (ad?.value||'-').trim();

        if (!id){ alert('아이რჩიე დონორი სიიდან'); return; }

        window.__DONOR_ID__   = id;
        window.__DONOR_NAME__ = name;
        window.__DONOR_ADDR__ = addr;

        // local:* ჩანაწერის შემთხვევაში მხოლოდ ლოკალურად ვინახავთ (სერვერზე არ ვაგზავნით)
        if (id.startsWith('local:')){
          alert('დონორი შენახულია (ლოკალური ჩანაწერი)');
          return;
        }

        try{
          const inv = wrap.closest('[id^="builder-"]')?.id?.split('-')[1] || '';
          if (inv){
            await fetch('', {
              method:'POST', credentials:'same-origin',
              headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
              body: new URLSearchParams({ action:'attach_donor_to_invoice', invoice_id: inv, donor_id: id }).toString()
            });
          } else {
            // no builder found -> save on patient level (best effort)
            const pid = document.getElementById('hdPtRgID')?.value || '';
            if (pid){
              await fetch('', {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: new URLSearchParams({ action:'attach_donor_to_patient', patient_id: pid, donor_id: id }).toString()
              });
            }
          }
        }catch(_){}
        alert('დონორი შენახულია'); return;
      }

      if (action==='insert4v' && (target==='bvgdel' || target==='ბvgdel')){
        const active = document.activeElement;
        const wrap = active?.closest('table.tg') || document;
        const hid  = wrap.querySelector('input[id^="hdansvl"]');
        const nm   = wrap.querySelector('input[id^="rfbn"]');
        const ad   = wrap.querySelector('input[id^="rfbn"][id*="ads"]') || wrap.querySelector('input[id^="rfbn–ads"]');

        if (hid) hid.value='';
        if (nm)  nm.value='-';
        if (ad)  ad.value='-';

        const prevInv = wrap.closest('[id^="builder-"]')?.id?.split('-')[1] || '';
        const pid = document.getElementById('hdPtRgID')?.value || '';

        window.__DONOR_ID__   = '';
        window.__DONOR_NAME__ = '—';
        window.__DONOR_ADDR__ = '-';

        try{
          if (prevInv){
            await fetch('', {
              method:'POST', credentials:'same-origin',
              headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
              body: new URLSearchParams({ action:'detach_donor_from_invoice', invoice_id: prevInv }).toString()
            });
          } else if (pid){
            await fetch('', {
              method:'POST', credentials:'same-origin',
              headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
              body: new URLSearchParams({ action:'detach_donor_from_patient', patient_id: pid }).toString()
            });
          }
        }catch(_){}
        alert('დონორი მოხსნილია'); return;
      }
    }catch(err){
      console.error('glAj shim error:', err);
    }
  };
})();

/* =================== NEW: A4 PDF HELPERS (html2pdf) =================== */
async function ensureHtml2Pdf(){
  if (window.html2pdf) return;
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    s.onload = resolve; s.onerror = () => reject(new Error('html2pdf load failed'));
    document.head.appendChild(s);
  });
}

async function saveElementAsA4Pdf(el, filename){
  await ensureHtml2Pdf();

  // Prepare an offscreen container
  const stage = document.createElement('div');
  stage.style.position = 'fixed';
  stage.style.left = '-99999px';
  stage.style.top = '0';
  stage.style.width = '210mm';

  // If target already has a4-sheet, reuse its structure; else wrap clone to A4
  let source;
  if (el.classList && el.classList.contains('a4-sheet')){
    source = el.cloneNode(true);
  } else {
    source = document.createElement('div');
    source.className = 'a4-sheet';
    source.style.width = '210mm';
    source.style.minHeight = '297mm';
    source.style.background = '#fff';
    source.style.boxSizing = 'border-box';
    source.style.padding = '14mm';
    source.appendChild(el.cloneNode(true));
  }

  stage.appendChild(source);
  document.body.appendChild(stage);

  const opt = {
    margin: 0,
    filename: filename || 'document.pdf',
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, letterRendering: true, useCORS: true },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
    pagebreak: { mode: ['css', 'legacy'] }
  };

  try{
    await window.html2pdf().from(source).set(opt).save();
  } finally {
    document.body.removeChild(stage);
  }
}

/* =================== PRINTING HELPERS (GLOBAL) =================== */
function gelToWordsKa(n) {
  n = Math.floor(Math.max(0, n));
  const u = ['ნული','ერთი','ორი','სამი','ოთხი','ხუთი','ექვსი','შვიდი','რვა','ცხრა'];
  const teen = ['თერთმეტი','თორმეტი','ცამეტი','თოთხმეტი','თხუთმეტი','თექვსმეტი','ჩვიდმეტი','თვრამეტი','ცხრამეტი'];
  const tens = ['','ათი','ოცი','ოცდაათი','ორმოცი','ორმოცდაათი','სამოცი','სამოცდაათი','ოთხმოცი','ოთხმოცდაათი'];
  const hundreds = ['','ასი','ორასი','სამასი','ოთხასი','ხუთასი','ექვსასი','შვიდასი','რვაასი','ცხრაასი'];

  function sub99(x){
    if (x < 10) return u[x];
    if (x === 10) return 'ათი';
    if (x > 10 && x < 20) return teen[x - 11];
    const t = Math.floor(x / 10), r = x % 10;
    if (r === 0) return tens[t];
    if ([2,4,6,8].includes(t)) return tens[t].replace(/ი$/, '') + 'და' + u[r];
    return tens[t] + ' და ' + u[r];
  }

  function sub999(x){
    if (x < 100) return sub99(x);
    const h = Math.floor(x / 100), r = x % 100;
    return r ? (hundreds[h] + ' ' + sub99(r)) : hundreds[h];
  }

  function sub999999(x){
    if (x < 1000) return sub999(x);
    const th = Math.floor(x / 1000), r = x % 1000;
    let thW = '';
    if (th === 1) thW = 'ათასი';
    else if (th > 1 && th < 20) {
      const small = ['','ერთი','ორი','სამი','ოთხი','ხუთი','ექვსი','შვიდი','რვა','ცხრა','ათი','თერთმეტი','თორმეტი','ცამეტი','თოთხმეტი','თხუთმეტი','თექვსმეტი','ჩვიდმეტი','თვრამეტი','ცხრამეტი'];
      thW = small[th] + ' ათასი';
    } else thW = sub999(th) + ' ათასი';
    return r ? (thW + ' ' + sub999(r)) : thW;
  }

  return sub999999(n);
}

function moneyWordsLine(amount){
  const gel = Math.floor(Number(amount)||0);
  const tet = Math.round(((Number(amount)||0) - gel) * 100);
  const gelWords = gelToWordsKa(gel) || 'ნული';
  const tet2 = String(tet).padStart(2,'0');
  return `ასანაზღაურებელი თანხა სიტყვებით: ${gelWords} ლარი და ${tet2} თეთრი`;
}

/* ========= STRICT A4 INVOICE (matches sample) ========= */
window.buildInvoiceHTML = function buildInvoiceHTML({docNo, docDate, lines, total, payerName, payerPid, donorName}){
  const org = Object.assign({
    title: '„სანმედი“ -',
    tax_id: '405695323',
    address_1: 'ერთიანობისთვის মებრძოლთა ქუჩა N55',
    phones: '555550845 / 558291614',
    bank_name: 'სს "ბანკი"',
    bank_code: 'BAGAGE22',
    iban: 'GE02BG0000000589324177',
    /* NEW: logo path (defaults to sanmed.jpg) */
    logo: 'sanmed.jpg'
  }, (window.__ORG__||{}));

  const payer = Object.assign({
    name: payerName || window.__PAYER_NAME__ || '—',
    pid:  payerPid  || window.__PAYER_PID__  || '—',
    donor: donorName || window.__DONOR_NAME__ || '—'
  }, {});

  const rows = (lines||[]).map((r,i)=>{
    const c = (r.comment||'') ? `<div class="muted" style="white-space:pre-wrap">${esc(r.comment)}</div>` : '';
    return `<tr>
      <td class="c">${i+1}</td>
      <td>${esc(r.title||'—')}${c}</td>
      <td class="r">${Number(r.qty||1)}</td>
      <td class="r">${Number(r.price||0).toFixed(2)}</td>
      <td class="r">${Number(r.sum||0).toFixed(2)}</td>
    </tr>`;
  }).join('');

  const tot = Number(total||0);

  return `<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<title>ინვოისი — ${esc(docNo||'')}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { size: A4 portrait; margin: 14mm; }
  @media print { html, body { width: 210mm; } }
  html, body { margin: 0; padding: 0; background: #f5f5f5; }
  .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; background: #fff; }
  .content { padding: 12mm; font-family: "DejaVu Sans", system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1F2328; font-size: 12.5px; }

  .doc-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; border-bottom:2px solid #111; padding-bottom:8px; }
  .brand{ display:flex; gap:12px; align-items:center; }
  /* NEW: show the actual logo image */
  .logo-img{ width:62px; height:62px; object-fit:contain; border:1.5px solid #111; border-radius:4px; background:#fff; }
  /* Fallback box (shows only if image fails) */
  .logo-box{ width:42px; height:42px; border:1.5px solid #111; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; }
  .brand-title{ font-size:15px; font-weight:700; }
  .brand-sub{ color:#555; }

  .doc-meta{ text-align:right; }
  .doc-title{ font-weight:800; font-size:16px; letter-spacing:.2px; }
  .doc-no{ font-size:14px; margin-top:2px; }
  .doc-date{ color:#555; }

  .info-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:10px 18px; margin:12px 0 4px; }
  .box{ border:1px solid #cfcfcf; border-radius:6px; padding:8px; }
  .box-title{ font-weight:700; margin-bottom:6px; }
  .row{ display:flex; justify-content:space-between; gap:8px; border-bottom:1px dashed #e6e6e6; padding:3px 0; }
  .row:last-child{ border-bottom:none; }
  .row span{ color:#555; }

  table.items{ width:100%; border-collapse:collapse; margin-top:10px; }
  table.items th, table.items td{ border:1px solid #dcdcdc; padding:6px 8px; vertical-align:top; }
  table.items thead th{ background:#f6f6f6; font-weight:700; }
  .r{ text-align:right; }
  .c{ text-align:center; }
  .muted{ color:#666; }

  .totals{ margin-top:12px; border:1px solid #cfcfcf; border-radius:6px; overflow:hidden; }
  .tot-row{ display:flex; justify-content:space-between; padding:8px 10px; border-bottom:1px solid #e9e9e9; }
  .tot-row:last-child{ border-bottom:none; }
  .tot-row b{ font-variant-numeric: tabular-nums; }
  .tot-row.due{ background:#f8f9fb; font-weight:800; }

  .words{ margin-top:10px; color:#666; }
  .sign{ margin-top:22px; display:flex; gap:40px; }
  .sigline{ width:260px; border-top:1px solid #000; text-align:center; padding-top:4px; }
</style>
</head>
<body>
  <div class="sheet">
    <div class="content">
      <div class="doc-head">
        <div class="brand">
          <!-- NEW: logo image with safe fallback -->
          <img class="logo-img" src="${esc(org.logo || 'sanmed.jpg')}" alt="Logo" onerror="this.outerHTML='<div class=&quot;logo-box&quot;>LOGO</div>'">
          <div class="brand-meta">
            <div class="brand-title">${esc(org.title)}</div>
            <div class="brand-sub">საიდ. კოდი: ${esc(org.tax_id)}</div>
            <div class="brand-sub">${esc(org.address_1||'')}</div>
            <div class="brand-sub">${esc(org.phones||'')}</div>
          </div>
        </div>
        <div class="doc-meta">
          <div class="doc-title">ანგარიშ-ფაქტურა (ინვოისი)</div>
          <div class="doc-no">№ ${esc(String(docNo||'').replace(/^[^0-9#-]*/,''))}</div>
          <div class="doc-date">${esc(docDate||'')}</div>
        </div>
      </div>

      <div class="info-grid">
        <div class="box">
          <div class="box-title">მიმწოდებელი</div>
          <div class="row"><span>დასახელება:</span><b>${esc(org.title)}</b></div>
          <div class="row"><span>საიდ. კოდი:</span><b>${esc(org.tax_id)}</b></div>
          <div class="row"><span>მისამართი:</span><b>${esc(org.address_1||'')}</b></div>
          <div class="row"><span>ტელ.:</span><b>${esc(org.phones||'')}</b></div>
          <div class="row"><span>ბანკი:</span><b>${esc(org.bank_name||'')} | ${esc(org.bank_code||'')}</b></div>
          <div class="row"><span>ა/ა:</span><b>${esc(org.iban||'')}</b></div>
        </div>
        <div class="box">
          <div class="box-title">გადამხდელი</div>
          <div class="row"><span>დამზადებულია (პაციენტი):</span><b>${esc(payer.name)}</b></div>
          <div class="row"><span>პაციენტის პ/ნ:</span><b>${esc(payer.pid)}</b></div>
          <div class="row"><span>დონორი:</span><b>${esc(payer.donor)}</b></div>
        </div>
      </div>

      <table class="items">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>გაწეული მომსახურების ან შესრულებული სამუშაოს დასახელება</th>
            <th style="width:80px" class="r">რაოდენობა</th>
            <th style="width:100px" class="r">თანხა</th>
            <th style="width:110px" class="r">ჯამი</th>
          </tr>
        </thead>
        <tbody>
          ${rows || `<tr>
            <td class="c">1</td>
            <td>—</td>
            <td class="r">1</td>
            <td class="r">0.00</td>
            <td class="r">0.00</td>
          </tr>`}
        </tbody>
      </table>

      <div class="totals">
        <div class="tot-row"><span>სულ ასანაზღაურებელია</span><b>${tot.toFixed(2)}</b></div>
      </div>

      <div class="words">${esc(moneyWordsLine(tot))}</div>

      <div class="sign">
        <div class="sigline">გენერალური დირექტორი</div>
        <div class="sigline">მთავარი ბუღალტერი</div>
      </div>
    </div>
  </div>
</body>
</html>`;
};

// Print (writes the full HTML doc returned by buildInvoiceHTML)
window.printInvoice = (function(){
  const _orig = function printInvoice({docNo, docDate, lines, total, payerName, payerPid, donorName}){
    const html = window.buildInvoiceHTML({docNo, docDate, lines, total, payerName, payerPid, donorName});
    const w = window.open('', '_blank');
    w.document.open(); w.document.write(html); w.document.close(); w.focus(); w.print();
  };
  return function(opts){
    opts = opts || {};
    if (!opts.donorName && window.__DONOR_NAME__) opts.donorName = window.__DONOR_NAME__;
    _orig(opts);
  };
})();

/* =================== 100/a PRINT HELPERS =================== */
function _serialize100AForPrint(containerEl){
  const clone = containerEl.cloneNode(true);

  // remove non-print controls
  clone.querySelectorAll('.bxclsF, .btnltyrb a, .btnltyrb input[type="button"], button').forEach(el=>el.remove());

  // inputs -> spans with current values
  clone.querySelectorAll('input, textarea, select').forEach(el=>{
    const val = (el.value ?? el.textContent ?? '').toString();
    const span = document.createElement('span');
    span.textContent = val;
    span.style.whiteSpace = 'pre-wrap';
    span.style.display = 'inline-block';
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      span.style.borderBottom = '1px dotted #222';
      span.style.padding = '2px 4px';
      const w = parseFloat(getComputedStyle(el).width);
      if (w) span.style.minWidth = Math.max(120, w - 6) + 'px';
    }
    el.replaceWith(span);
  });

  return clone.outerHTML;
}

window.printForm100A = function(invId){
  const wrap = document.querySelector(`#wrap_100a_${CSS.escape(String(invId))}`);
  if (!wrap){ alert('ფორმა 100/ა ვერ მოიძებნა.'); return; }

  const bodyHtml = _serialize100AForPrint(wrap);
  const html = `
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<title>ფორმა № IV-100/ა — ბეჭდვა</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { size: A4 portrait; margin: 14mm; }
  @media print { html, body { width: 210mm; } }
  html, body { margin:0; padding:0; background:#fff; }
  body { font-family: "DejaVu Sans", system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#111; font-size:12.5px; }
  .mcffx { padding-left:8px; padding-right:8px; }
  .fmdv { margin:6px 0; line-height:1.35; }
  .fmdv .br { display:block; font-size:12px; color:#333; margin-bottom:4px; }
  .ml { margin-left:6px; }
  .rpdv { white-space:pre-wrap; }
  a { color:inherit; text-decoration:none; }
</style>
</head>
<body>
${bodyHtml}
</body>
</html>`;
  const w = window.open('', '_blank');
  w.document.open(); w.document.write(html); w.document.close();
  w.focus(); w.print();
};

/* =================== NEW: 100/a DIRECT PDF (A4) =================== */
window.saveForm100AAsPdf = async function(invId){
  const el = document.querySelector(`#wrap_100a_${CSS.escape(String(invId))} .a4-sheet`) ||
             document.querySelector(`#wrap_100a_${CSS.escape(String(invId))}`);
  if (!el){ alert('ფორმა 100/ა ვერ მოიძებნა.'); return; }
  try{
    await saveElementAsA4Pdf(el, `IV-100a-${invId}.pdf`);
  }catch(err){
    alert('PDF გენერირების შეცდომა, გაიხსნება ბეჭდვის ფანჯარა.');
    window.printForm100A(invId);
  }
};

/* --- patients click -> details --- */
const patientsTable = document.getElementById('patientsTable');
const fullnameDisplay = document.getElementById('fullname');
const historyWrap = document.getElementById('patientHistory');
const historyContent = document.getElementById('historyContent');
const hdPtRgID = document.getElementById('hdPtRgID');

patientsTable.addEventListener('click', (e)=>{
  const tr = e.target.closest('tr'); if(!tr || !tr.dataset.id) return;
  patientsTable.querySelectorAll('tbody tr').forEach(r=>r.classList.remove('selected'));
  tr.classList.add('selected');

  const pid = tr.dataset.id, fn = tr.dataset.firstname, ln = tr.dataset.lastname;
  hdPtRgID.value = pid;

  fullnameDisplay.textContent = `${fn} ${ln}`;
  historyWrap.style.display='block';
  historyContent.innerHTML='იტვირთება...';
  updateActionLinks(true);

  /* === NEW: Save current patient meta for invoices === */
  const personal = (tr.querySelector('.col-personal')?.textContent || tr.dataset.personalId || '').trim();
  window.__CURRENT_PATIENT__ = {
    name: `${fn||''} ${ln||''}`.trim(),
    pid: personal || '—',
    birth: (tr.querySelector('.col-birth')?.textContent || '').trim()
  };
  window.__PAYER_NAME__ = window.__CURRENT_PATIENT__.name;
  window.__PAYER_PID__  = window.__CURRENT_PATIENT__.pid;

  fetch(`?action=get_services&id=${pid}`)
    .then(r=>r.json())
    .then(rows=>{
      if(!rows.length){ historyContent.innerHTML='<em>მიმართვები არ არის.</em>'; return; }
      let h = '<div class="table-wrap"><table class="simple-table"><thead><tr><th>სერვისი</th><th>რაოდ.</th><th>ფასი</th><th>ჯამი</th><th>ექიმი</th><th>თარიღი</th></tr></thead><tbody>';
      rows.forEach(s=>{
        h += `<tr>
          <td>${s.service_name||''}</td>
          <td>${s.quantity||''}</td>
          <td>${Number(s.unit_price||0).toFixed(2)}</td>
          <td>${Number(s.sum||0).toFixed(2)}</td>
          <td>${s.doctor_name||'–'}</td>
          <td>${s.created_at||'–'}</td>
        </tr>`;
      });
      h+='</tbody></table></div>';
      historyContent.innerHTML = h;
    }).catch(()=> historyContent.innerHTML='<span style="color:#c00">შეცდომა ჩატვირთვაში</span>');
});

/* --- search --- */
document.getElementById('searchInput').addEventListener('input', function(){
  const q=this.value.toLowerCase().trim();
  document.querySelectorAll('#patientsTable tbody tr').forEach(row=>{
    const t = Array.from(row.children).slice(0,3).map(td=>td.textContent.toLowerCase()).join(' ');
    row.style.display = t.includes(q) ? '' : 'none';
  });
});

/* ========== Payment overlay open/close ========== */
const payOverlay = document.getElementById('payOverlay');

function openPayModal(pid){
  if(!pid){ alert('აირჩიე პაციენტი'); return; }
  payOverlay.style.display='flex'; // harmless placeholder
  payOverlay.style.display='flex'; // will be reset below (harmless)
  payOverlay.style.display='flex';
  payOverlay.innerHTML='<div class="modal"><button class="close" data-close>&times;</button><div style="padding:30px;text-align:center">იტვირთება...</div></div>';
  fetch(`?action=pay_view&id=${encodeURIComponent(pid)}`)
    .then(r=>{
      const ct = r.headers.get('content-type')||'';
      if(!r.ok || !ct.includes('text/html')) throw new Error('unauthorized_or_bad_response');
      return r.text();
    })
    .then(html=>{
      payOverlay.innerHTML = html;
      setTimeout(wirePaymentModalRoot,0);
    }).catch(()=>{
      payOverlay.innerHTML='<div class="modal"><button class="close" data-close>&times;</button><div style="padding:30px;color:#c00">ვერ ჩაიტვირთა</div></div>';
    });
}
function closePayModal(){ payOverlay.style.display='none'; payOverlay.innerHTML=''; }
payOverlay.addEventListener('click', (e)=>{ if(e.target===payOverlay || e.target.hasAttribute('data-close')) closePayModal(); });

/* მარჯვენა პანელიდან გადახდა */
document.getElementById('mr_pay').addEventListener('click', function(e){
  e.preventDefault();
  if (this.classList.contains('disabled')) return;
  const pid = hdPtRgID.value || (document.querySelector('#patientsTable tbody tr.selected')?.dataset.id || '');
  if(!pid){ alert('გთხოვთ აირჩიოთ პაციენტი.'); return; }
  openPayModal(pid);
});

/* ======== Payment modal logic (ვ8) ======== */
function wirePaymentModalRoot(){
  const $root = $('.modal'); if(!$root.length) return;

  const money = v => { const n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? 0 : Math.max(0, n); };
  const fmt2  = n => (Number(n)||0).toFixed(2);
  const mapMethod = v => (v==='825'?'bog':(v==='858'?'transfer':'cash'));

  function setInsTab(x){
    $root.find('.fdaz1,.fdaz2,.fdaz3').removeClass('diaz');
    if(x===1){ $root.find('.fdaz2,.fdaz3').addClass('diaz'); }
    if(x===2){ $root.find('.fdaz1,.fdaz3').addClass('diaz'); }
    if(x===3){ $root.find('.fdaz1,.fdaz2').addClass('diaz'); }
    $root.find('.tabs .tab').removeClass('active');
    $root.find('#ins_tab_'+x).addClass('active');
  }
  setInsTab(1);
  $root.on('click','#ins_tab_1',()=>setInsTab(1));
  $root.on('click','#ins_tab_2',()=>setInsTab(2));
  $root.on('click','#ins_tab_3',()=>setInsTab(3));

  function rowSum($tr){
    const qty = money($tr.data('qty')||'1') || 1;
    const prc = money($tr.find('.np').val()||'0');
    return qty * prc;
  }
  function recalcRow($tr){
    const sum = rowSum($tr);
    $tr.find('.cce').text(fmt2(sum));
    const initPaid = money($tr.data('paid-initial')||0);
    const paid = Math.min(initPaid, sum);
    $tr.find('.lb2').text(fmt2(paid));
    const fully = (paid + 0.005) >= sum;
    $tr.toggleClass('paid', fully);
    const $chk = $tr.find('.srv-chk');
    $chk.attr('data-fully-paid', fully ? '1' : '0');
    if (fully) $chk.prop('checked', false);
    applyLockToCheckbox($chk.get(0));
  }
  function getSelectedRows(){ return $root.find('#fs_amounttypet tbody tr').filter((_,tr)=>$(tr).find('.srv-chk').prop('checked')); }
  function rowValues($tr){
    const sum  = money($tr.find('.cce').text()||0);
    const paid = money($tr.find('.lb2').text()||0);
    return { sum, paid, debt: Math.max(sum - paid, 0) };
  }
  function calcSelectedDebt(){
    let s=0; getSelectedRows().each((_,tr)=>{ s += rowValues($(tr)).debt; }); return s;
  }
  function vt2SumSelected(){
    let s=0; getSelectedRows().each((_,tr)=>{
      const $r=$(tr), $i=$r.find('.vt2'), $k=$r.find('.k');
      if($k.prop('checked') && $i.length && !$i.prop('disabled')) s += money($i.val());
    }); return s;
  }
  function vt2SumExcept($tr){
    let s=0; getSelectedRows().each((_,tr)=>{
      if (tr===$tr.get(0)) return;
      const $r=$(tr), $i=$r.find('.vt2'), $k=$r.find('.k');
      if($k.prop('checked') && $i.length && !$i.prop('disabled')) s += money($i.val());
    }); return s;
  }

  function getDonorAvailable(){
    const $sel = $root.find('#pa_dontype'); if(!$sel.val()) return 0;
    const amt = parseFloat($sel.find('option:selected').data('amount')||'0')||0; return Math.max(0,amt);
  }
  function setDonorBadge(avail, applied){
    const initTotal = parseFloat($root.find('#donor_left_badge').data('total-init')||'0')||0;
    const totalLeft = Math.max(0, initTotal - applied);
    $root.find('#donor_left_badge').text(fmt2(totalLeft));
    $root.find('#pa_empay').val(fmt2(applied));
    $root.find('#donor_id_now').val(($root.find('#pa_dontype').val()||'').trim());
    $root.find('#donor_applied_now').val(fmt2(applied));
  }

  function setPayInputState(debt){
    const $amo = $root.find('#pa_amo');
    if (debt <= 0.000001) {
      $amo.val('').prop('disabled', true).attr('placeholder','დავალიანება არ არის');
    } else {
      const cur = money($amo.val());
      $amo.prop('disabled', false);
      if (!cur || cur > debt) $amo.val(fmt2(debt));
      $amo.attr('placeholder', 'გადასახდელი: ' + fmt2(debt));
    }
  }

  function recalcTotals(){
    let totalServices=0; $root.find('#fs_amounttypet .cce').each((_,el)=> totalServices += money($(el).text()));
    $root.find('#pa_pipa').val(fmt2(totalServices));

    let totalPaidReal=0; $root.find('#fs_amounttypet .lb2').each((_,el)=> totalPaidReal += money($(el).text()));
    $root.find('#pa_gadax').val(fmt2(totalPaidReal));

    const selDebt  = calcSelectedDebt();
    const vt2Sel   = vt2SumSelected();
    const donorAvail = getDonorAvailable();
    const donorApplied = Math.min(donorAvail, Math.max(selDebt - vt2Sel, 0));
    const remain = Math.max(selDebt - vt2Sel - donorApplied, 0);

    $root.find('#sel_badge').text(fmt2(selDebt));
    $root.find('#en3m').text(fmt2(remain));
    $root.find('#pa_gadasax').val(fmt2(remain));
    setDonorBadge(donorAvail, donorApplied);

    const debtMode = $root.find('#chk_debt_all').prop('checked');
    const $amo = $root.find('#pa_amo');
    if (debtMode){
      $amo.val(fmt2(vt2Sel)).prop('disabled', true).attr('placeholder','Σ vt2: '+fmt2(vt2Sel));
    } else {
      $amo.prop('disabled', false);
      const cur = money($amo.val());
      if (!cur || cur > remain) $amo.val(fmt2(remain));
      $amo.attr('placeholder','გადასახდელი: '+fmt2(remain));
    }

    const eligible = $root.find('#fs_amounttypet .srv-chk').filter((_,ch)=>($(ch).data('fully-paid')||0)!==1 && !ch.disabled);
    const allOn = eligible.length>0 && eligible.toArray().every(ch=>ch.checked);
    $root.find('#chk_all_rows').prop('checked', allOn);
  }

  function recomputeAllocation(){ $root.find('#fs_amounttypet tbody tr').each((_,tr)=> recalcRow($(tr))); }

  function clearDebtMarks(){
    $root.find('#fs_amounttypet tbody tr').each((_,tr)=>{
      const $r=$(tr); $r.find('.vt2').val('').prop('disabled',true).addClass('disCol'); $r.find('.k').prop('checked',false);
    });
  }
  function activateDebtModeZeros(){
    const $rows = getSelectedRows();
    $rows.each((_,tr)=>{
      const $r=$(tr); const {debt}=rowValues($r);
      const $vt=$r.find('.vt2'), $k=$r.find('.k');
      if(debt<=0){ $vt.val('').prop('disabled',true).addClass('disCol'); $k.prop('checked',false); }
      else{ $k.prop('checked',true); $vt.prop('disabled',false).removeClass('disCol'); if(!$vt.val()) $vt.val('0.00'); }
    });
    $root.find('#fs_amounttypet tbody tr').not($rows).each((_,tr)=>{
      const $r=$(tr); $r.find('.vt2').val('').prop('disabled',true).addClass('disCol'); $r.find('.k').prop('checked',false);
    });
  }
  function refreshDebtMode(){ if($root.find('#chk_debt_all').prop('checked')) activateDebtModeZeros(); }

  function applyLockToCheckbox(chk){
    const lock = true;
    const isPaid = parseInt(chk.dataset.fullyPaid||'0',10)===1;
    if (lock && isPaid){ chk.checked=false; chk.disabled=true; chk.title='გადახდილია'; }
    else{ chk.disabled=false; chk.title=''; }
  }
  function applyLockAll(){ $root.find('#fs_amounttypet .srv-chk').each((_,ch)=>applyLockToCheckbox(ch)); }

  $root.on('change','#chk_all_rows', e=>{
    const on=$(e.currentTarget).prop('checked');
    $root.find('#fs_amounttypet .srv-chk').each((_,ch)=>{
      const isPaid = parseInt(ch.dataset.fullyPaid||'0',10)===1;
      if (isPaid) ch.checked=false;
      else if(!ch.disabled) ch.checked=on;
    });
    refreshDebtMode(); recalcTotals();
  });
  $root.on('click','#btn_clear_sel', ()=>{
    $root.find('#fs_amounttypet .srv-chk').prop('checked',false);
    $root.find('#chk_all_rows').prop('checked',false);
    if ($root.find('#chk_debt_all').prop('checked')) clearDebtMarks();
    recalcTotals();
  });
  $root.on('change','#fix_zero_all', e=>{
    const on=$(e.currentTarget).prop('checked');
    $root.find('#fs_amounttypet .np').each((_,i)=>{
      const $i=$(i);
      if(on){ $i.data('prev',$i.val()); $i.val('0'); }
      else if($i.data('prev')!==undefined){ $i.val($i.data('prev')); }
    });
    recomputeAllocation(); refreshDebtMode(); recalcTotals();
  });
  $root.on('click','#btn_ins_percent_1', ()=>{
    const p = ($root.find('#dgperg').val()||'').trim();
    $root.find('#fs_amounttypet tbody tr > td:nth-child(6) input[type="text"]:not(.disCol)').val(p);
  });
  $root.on('click','#btn_price_percent', ()=>{
    const p = ($root.find('#jjdhrge').val()||'').trim();
    $root.find('#fs_amounttypet tbody tr > td:nth-child(14) input[type="text"]:not(.disCol)').val(p);
  });
  $root.on('change','#fs_amounttypet .srv-chk', ()=>{ refreshDebtMode(); recalcTotals(); });
  $root.on('change','#fs_amounttypet .k', e=>{
    const $tr=$(e.currentTarget).closest('tr');
    const {debt}=rowValues($tr);
    const $vt=$tr.find('.vt2');
    if ($(e.currentTarget).prop('checked')){
      if (debt<=0){ $vt.val('').prop('disabled',true).addClass('disCol'); }
      else { $vt.prop('disabled',false).removeClass('disCol'); if(!$vt.val()) $vt.val('0.00'); }
    } else { $vt.val('').prop('disabled',true).addClass('disCol'); }
    recalcTotals();
  });
  $root.on('input','#fs_amounttypet .np', e=>{ recalcRow($(e.currentTarget).closest('tr')); recomputeAllocation(); refreshDebtMode(); recalcTotals(); });
  $root.on('input','#fs_amounttypet .vt2', e=>{
    const $tr=$(e.currentTarget).closest('tr');
    let v=(e.currentTarget.value||'').replace(',', '.').replace(/[^\d.]/g, '');
    const parts=v.split('.'); if(parts.length>2) v=parts.shift()+'.'+parts.join('');
    let n=parseFloat(v||'0')||0;
    const {debt}=rowValues($tr);
    const other=vt2SumExcept($tr);
    const selDebt=calcSelectedDebt();
    const maxRow=Math.min(debt, Math.max(selDebt - other, 0));
    if (n>maxRow) n=maxRow; if (n<0) n=0;
    e.currentTarget.value=fmt2(n);
    recalcTotals();
  });
  // accept the correct id for the debt toggle
  $root.on('change','#chk_debt_all', e=>{ if($(e.currentTarget).prop('checked')) activateDebtModeZeros(); else clearDebtMarks(); recalcTotals(); });
  $root.on('change','#pa_dontype', ()=> recalcTotals());

  $root.on('click','#btn_make_invoice', ()=>{
    let s=''; getSelectedRows().each((_,tr)=>{ const id=tr.id; if(id) s+=id.substring(1)+'@'; });
    $root.find('#hd_izptit').val(s);
    alert('აირჩეული სერვისები: '+(s||'(ცარიელი)'));
  });
  $root.on('click','#btn_issue_invoice', async ()=>{
    let serviceIds=''; getSelectedRows().each((_,tr)=>{ const id=tr.id; serviceIds += id.substring(1)+'@'; });
    if (!serviceIds){ alert('აირჩიეთ მინიმუმ ორი სერვისი'); return; }
    const fd=new FormData(); fd.append('action','create_invoice_demo'); fd.append('service_ids', serviceIds);
    const r=await fetch('', {method:'POST', body:fd}); const j=await r.json().catch(()=>({}));
    if (j.status==='ok'){ alert('ინვოისი ჩაიწერა. №: '+j.invoice_id+' | ორდერი: '+j.order_no); if (j.pdf_url) window.open(j.pdf_url,'_blank'); }
    else { alert(j.message||'ვერ ჩაიწერა'); }
  });
  /* ====== Select-all for payments ====== */
  $root.on('change', '#chk_all_payments', function(){
    const on = !!this.checked;
    $root.find('#pa_pa tbody .zl').prop('checked', on);
  });

  /* ====== Issue "payment invoice" PDF ====== */
  $root.on('click', '#btn_issue_payment_invoice', async function(e){
    e.preventDefault();

    const $btn = $(this);
    if ($btn.data('busy')) return;
    $btn.data('busy', 1).css('opacity', '.6');

    try {
      // 1) Patient id (primary source: hidden input from modal)
      const pid = parseInt(
        ($root.find('#pa_pid').val() || $('#hdPtRgID').val() || '0'),
        10
      ) || 0;

      if (!pid) {
        alert('პაციენტი ვერ განისაზღვრა');
        return;
      }

      // 2) Collect selected payments from the table (#pa_pa)
      const ids = [];
      $root.find('#pa_pa tbody tr').each(function(){
        const $tr = $(this);
        if ($tr.find('.zl').prop('checked')) {
          const raw = String($tr.attr('id') || '').trim(); // "O123"
          const id  = parseInt(raw.replace(/^O/, ''), 10);
          if (id) ids.push(id);
        }
      });

      if (!ids.length) {
        alert('აირჩიეთ გადახდები');
        return;
      }

      // 3) Send to PHP: action=create_payment_invoice_demo
      const body = new URLSearchParams({
        action: 'create_payment_invoice_demo',
        patient_id: String(pid),
        // Server can parse "28@29@31@" or "28@29@31" — both are OK
        payment_ids: ids.join('@') + '@'
      });

      const resp = await fetch('', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      });

      const json = await resp.json().catch(() => null);
      if (!resp.ok || !json || json.status !== 'ok') {
        alert((json && json.message) || 'შეცდომა ინვოისის შექმნისას');
        return;
      }

      // 4) Open the generated document
      const url = json.pdf_url || json.html_url || json.url;
      if (url) window.open(url, '_blank');
      else alert('ფაილის ბმული ვერ დადგინდა');

    } catch (err) {
      console.error(err);
      alert('შეცდომა. სცადეთ თავიდან.');
    } finally {
      $btn.data('busy', 0).css('opacity', '');
    }
  });

  $root.on('click', '#pa_insrt', async (e)=>{
    e.preventDefault();
    const pidSel = $('#pa_pid').val() || '';
    const paid_at = ($root.find('#pa_date').val()||'').trim().replace(' ','T');
    const method  = mapMethod($root.find('#pa_cmpto').val());
    const orderNo = ($root.find('#order_no').val()||'').trim();

    const debtMode = $root.find('#chk_debt_all').prop('checked');
    let amount=0;
    if (debtMode){ amount = vt2SumSelected(); }
    else{
      amount = money($root.find('#pa_amo').val());
      if (!(amount>0)) amount = money($root.find('#pa_gadasax').val()) || money($root.find('#en3m').text());
    }

    const donor_id = ($root.find('#donor_id_now').val()||'').trim();
    const donor_applied = money($root.find('#donor_applied_now').val()||0);

    if (!(amount>0) && !(donor_applied>0)) { alert('შეიყვანე თანხა ან აირჩიე ავანსატორი'); return; }

    const fd=new FormData();
    fd.append('action','insert_payment_demo');
    fd.append('patient_id', pidSel);
    // safer: PHP or fallback stays valid JS due to backticks
    fd.append('paid_at', paid_at || `<?= date('Y-m-d\\TH:i') ?>`);
    fd.append('method', method);
    fd.append('amount', fmt2(amount));
    fd.append('order_no', orderNo);
    fd.append('donor_id', donor_id);
    fd.append('donor_applied', fmt2(donor_applied));

    const r=await fetch('', {method:'POST', body:fd}); const j=await r.json().catch(()=>({}));
    if (j.status==='ok'){ alert('გადახდა ჩაიწერა. ორდერი: '+(j.order_no||orderNo||'(გენერირებულია)')); openPayModal(pidSel); }
    else { alert(j.message||'შეცდომა'); }
  });

  $root.on('change','#chk_all_payments', e=>{
    const on=$(e.currentTarget).prop('checked');
    $root.find('#pa_pa .zl').prop('checked', on);
  });
  $root.on('click','#btn_issue_payment_invoice', async ()=>{
    let ids=''; $root.find('#pa_pa .zl:checked').each((_,ch)=>{
      const id=$(ch).closest('tr').attr('id')||''; if (id.startsWith('O')) ids += id.substring(1) + '@';
    });
    if (!ids) { alert('아이რჩიეთ მინიმუმ ერთი გადახდა'); return; }

    const fd=new FormData();
    fd.append('action','create_payment_invoice_demo');
    fd.append('payment_ids', ids);

    const r=await fetch('', {method:'POST', body:fd});
    const j=await r.json().catch(()=> ({}));
    if (j.status==='ოკ'){
      alert('გადახდების ინვოისი ჩაიწერა. №: ' + j.receipt_id + ' | დოკ: ' + j.doc_no);
      if (j.pdf_url) window.open(j.pdf_url, '_blank');
    } else {
      alert(j.message || 'ვერ ჩაიწერა');
    }
  });

  $root.on('click', '#btn_print_selected', ()=>{
    const $rows = (function(){ return $root.find('#fs_amounttypet tbody tr').filter((_,tr)=>$(tr).find('.srv-chk').prop('checked')); })();
    const picked = $rows;
    if(!picked.length){ alert('아이რჩიე მინიმუმ ერთი სერვისი'); return; }

    const lines = [];
    picked.each((_,tr)=>{
      const $tr = $(tr);
      const titleCell = $tr.find('td').eq(1).text().trim() || '—';
      const qty  = parseFloat(String($tr.data('qty')||'1')) || 1;
      const price= parseFloat(($tr.find('.np').val()||'0').replace(',','.')) || 0;
      const sum  = qty * price;

      lines.push({ title: titleCell, qty, price, sum, comment: '' });
    });

    const total = lines.reduce((s,i)=>s+Number(i.sum||0),0);
    const docNo = '5';
    const docDate = new Date().toISOString().slice(0,10);

    const payerName = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.name) || window.__PAYER_NAME__ || '—';
    const payerPid  = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.pid)  || window.__PAYER_PID__  || '—';

    window.printInvoice({ docNo, docDate, lines, total, payerName, payerPid });
  });

  $root.find('#fs_amounttypet tbody tr').each((_,tr)=> recalcRow($(tr)));
  applyLockAll();
  recalcTotals();
}

/* -------------------- Edit patient modal -------------------- */
function openEditPatient(pid){
  if(!pid){ alert('არასწორი პაციენტი'); return; }
  payOverlay.style.display='flex';
  payOverlay.innerHTML='<div class="modal"><button class="close" data-close>&times;</button><div style="padding:30px;text-align:center">იტვირთება...</div></div>';

  fetch(`?action=get_patient&id=${encodeURIComponent(pid)}`)
    .then(r=>r.json())
    .then(j=>{
      if(j.status!=='ok'){ throw new Error(j.message||'ვერ მოიძებნა'); }
      const d = j.data;
      const html = `
        <div class="modal">
          <button class="close" data-close>&times;</button>
          <h3 style="margin:6px 6px 14px 6px; color:#178e7b">პაციენტის რედაქტირება (#${d.id})</h3>
          <form id="editPatientForm" class="card" onsubmit="return false;">
            <div class="pay-form row" style="grid-template-columns:repeat(2,1fr)">
              <label>პირადი ნომერი
                <input type="text" name="personal_id" value="${esc(d.personal_id||'')}" required>
              </label>
              <label>დაბადების თარიღი (YYYY-MM-DD)
                <input type="text" name="birthdate" value="${esc(d.birthdate||'')}" required>
              </label>
              <label>სახელი
                <input type="text" name="first_name" value="${esc(d.first_name||'')}" required>
              </label>
              <label>გვარი
                <input type="text" name="last_name" value="${esc(d.last_name||'')}" required>
              </label>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:8px">
              <button type="button" class="btn" onclick="closePayModal()">გაუქმება</button>
              <button type="submit" class="pay-btn">შენახვა</button>
            </div>
          </form>
        </div>`;
      payOverlay.innerHTML = html;

      document.getElementById('editPatientForm').addEventListener('submit', function(){
        const fd = new FormData(this);
        fd.append('action','update_patient');
        fd.append('patient_id', String(d.id));

        fetch('', {method:'POST', body:fd})
          .then(r=>r.json())
          .then(resp=>{
            if(resp.status==='ok'){
              const row = document.querySelector(`#patientsTable tbody tr[data-id="${d.id}"]`);
              if (row){
                row.querySelector('.col-personal').textContent = this.personal_id.value;
                row.querySelector('.col-first').textContent = this.first_name.value;
                row.querySelector('.col-last').textContent = this.last_name.value;
                row.querySelector('.col-birth').textContent = this.birthdate.value || '–';
                row.dataset.firstname = this.first_name.value;
                row.dataset.lastname  = this.last_name.value;
              }
              closePayModal();
            } else {
              alert(resp.message || 'ვერ განახლდა');
            }
          }).catch(()=> alert('ქსელის შეცდომა'));
      });
    })
    .catch(err=>{
      payOverlay.innerHTML = '<div class="modal"><button class="close" data-close>&times;</button><div style="padding:30px;color:#c00">შეცდომა: '+(err.message||'')+'</div></div>';
    });
}

/* -------------------- Delete patient -------------------- */
function confirmDeletePatient(pid, btn){
  if(!pid) return;
  if(!confirm('ნამდვილად გსურთ პაციენტის და მასთან დაკავშირებული მონაცემების წაშლა?')) return;

  const fd = new FormData();
  fd.append('action','delete_patient');
  fd.append('patient_id', String(pid));

  btn.disabled = true;
  fetch('', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(j=>{
      if(j.status==='ok'){
        const row = document.querySelector(`#patientsTable tbody tr[data-id="${pid}"]`);
        if (row) row.remove();

        const sel = document.querySelector('#patientsTable tbody tr.selected');
        if (sel && sel.dataset.id == String(pid)) {
          fullnameDisplay.textContent = '아이რჩიეთ პაციენტი სიისგან, რომ ნახოთ დეტალები.';
          historyWrap.style.display='none';
          historyContent.innerHTML='';
          hdPtRgID.value = '';
          updateActionLinks(false);
        }
        alert('წაშლილია');
      } else {
        alert(j.message || 'წაშლა ვერ შესრულდა');
      }
    })
    .catch(()=> alert('ქსელის შეცდომა'))
    .finally(()=>{ btn.disabled = false; });
}
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.del-srv');
  if (!btn) return;
  const psId = btn.dataset.psId, pid = btn.dataset.pid;
  if (!psId || !pid) return;

  if (!confirm('წაშლა? ამ სერვისს ინვოისი არ აქვს მიბმული.')) return;

  const resp = await fetch('my-patient.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': (window.__CSRF__||'')},
    body: JSON.stringify({action: 'delete_patient_service', patient_service_id: psId, patient_id: pid})
  }).then(r => r.json()).catch(() => ({}));

  if (resp && resp.status === 'ok') {
    const tr = document.getElementById('T' + psId);
    if (tr) tr.remove();
  } else {
    alert((resp && resp.message) || 'ვერ წაიშალა');
  }
});

/* --------- Generic .open-card-btn support --------- */
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.open-card-btn');
  if (!btn) return;

  const pid = btn.getAttribute('data-patient-id');
  if (!pid) { alert('პაციენტი არ არის არჩეული'); return; }

  const originalText = btn.textContent;
  btn.disabled = true; btn.textContent = 'მიმდინარეობს...';

  try {
    const resp = await fetch('patient_hstory.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'add_or_activate', patient_id: pid }).toString()
    });

    const ct = resp.headers.get('content-type') || '';
    const raw = await resp.text();
    let data;
    if (ct.includes('application/json')) {
      data = JSON.parse(raw);
    } else {
      throw new Error('არავალიდური პასუხი (JSON არაა): ' + raw.slice(0, 120) + '...');
    }

    if (data.status === 'ok') {
      btn.textContent = 'დამატებულია ✓';
      btn.style.background = '#28a745';
      setTimeout(() => {
        btn.disabled = false;
        btn.textContent = originalText;
        btn.style.background = '#007bff';
      }, 1500);
    } else {
      alert(data.message || 'დაფიქსირდა შეცდომა');
      btn.disabled = false; btn.textContent = originalText;
    }
  } catch (err) {
    alert('შეცდომა: ' + (err.message || 'ქსელური'));
    btn.disabled = false; btn.textContent = originalText;
  }
});

/* ========= INVOICE PANEL (mr_rpinvo) — headers/list/builder/print ========= */
function openInvoicePanel(pid){
  const overlay = document.getElementById('payOverlay');
  if(!pid){ alert('აირჩიე პაციენტი'); return; }
  overlay.style.display = 'flex'; // harmless placeholder
  overlay.style.display = 'flex';
  overlay.innerHTML = `
  <div class="modal" style="width:960px;max-width:100%;position:relative">
    <button class="close" data-close aria-label="Close">&times;</button>

    <div class="innerfrm" style="width: 900px; position: relative; top: 5px;">
      <div id="inter2">
        <div style="text-align:center;margin-bottom:10px">
          <a href="javascript:void(0)" id="levoz"
             style="width:auto;text-decoration:none;padding:6px 10px;background:#FFF;border:1px solid #A7C3DE">
             ახალი ჩანაწერის შექმნა
          </a>
        </div>

        <div style="width:600px;margin:0 auto">
          <table class="tt tbalce" border="0" id="tab_invres">
            <tbody>
              <tr id="TCb">
                <td width="6%">#</td>
                <td width="20%">თარიღი</td>
                <td width="60%"></td>
                <td>წაღებულია</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="nondrg" style="width:900px;padding-top:30px;">
          <h4 style="color:#6E6ED7;margin:0 0 10px">სადაზღვეოს მიმართვები</h4>
          <table class="pp" style="width:100%;background-color:#D5C8C8;margin-top:5px">
            <tbody>
              <tr>
                <td style="width:10%">ფასის ტიპი</td>
                <td style="width:51%">სერვისი</td>
                <td style="width:13%;text-align:center"></td>
                <td style="width:13%;text-align:center"></td>
                <td style="width:13%;text-align:center"></td>
              </tr>
            </tbody>
          </table>

            <table class="pp" id="lvria" style="width:100%;background-color:#fff;">
              <tbody id="insBody">
                <tr><td colspan="6" style="padding:10px;color:#666">იტვირთება...</td></tr>
              </tbody>
            </table>
        </div>

        <div style="margin-top:10px;text-align:right">
          <input type="button" value="შენახვა" class="rgpap" id="btnSaveInvMeta">
        </div>
      </div>
    </div>
  </div>`;

  const escHtml2 = s => String(s ?? '').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const fmt2 = n => (Number(n)||0).toFixed(2);
  const fmt0 = n => String(Number(n)||0);

  /* ----- load insurance rows ----- */
  const insTbody = overlay.querySelector('#insBody');
  function rowHtml(r){
    const code = r.code || '';
    const service = r.name || '';
    const priceType = r.price_type || 'შიდა';
    const ref = r.referral_number || '';
    const pol = r.policy_number || '';
    const f1 = r.field1 || '', f2 = r.field2 || '', f3 = r.field3 || '';

    return `
    <tr data-service-id="${r.sid}">
      <td style="width:10%;border-bottom:1px solid #ccc;background:#f7f7f7">${escHtml2(priceType)}</td>
      <td style="width:44%;border-bottom:1px solid #ccc;background:#f7f7f7">
        <input type="hidden" class="svc-id" value="${r.sid}">
        ${code ? escHtml2(code) + ' — ' : ''}${escHtml2(service)}
      </td>
      <td style="width:7%;border-bottom:1px solid #ccc;background:#f7f7f7">მიმართვის#<br><br><br>პოლისის#</td>
      <td style="width:13%;border-bottom:1px solid #ccc;background:#f7f7f7">
        <input type="text" class="in-ref" value="${escHtml2(ref)}"><br><br>
        <input type="text" class="in-pol" value="${escHtml2(pol)}">
      </td>
      <td style="width:13%;border-bottom:1px solid #ccc;background:#f7f7f7">
        <input type="text" class="in-f1" value="${escHtml2(f1)}"><br><br>
        <input type="text" class="in-f2" value="${escHtml2(f2)}">
      </td>
      <td style="width:13%;border-bottom:1px solid #ccc;background:#f7f7f7">
        <input type="text" class="in-f3" value="${escHtml2(f3)}"><br><br>
        <input type="text" value="" disabled style="opacity:.35">
      </td>
    </tr>`;
  }

  async function loadInsuranceRows(){
    insTbody.innerHTML = `<tr><td colspan="6" style="padding:10px;color:#666">იტვირთება...</td></tr>`;
    try{
      const r = await fetch(`?action=get_insurance_rows&patient_id=${encodeURIComponent(pid)}`, {credentials:'same-origin'});
      const j = await r.json();
      if (j.status !== 'ok') throw new Error(j.message || 'load_failed');
      if (!j.rows.length){
        insTbody.innerHTML = `<tr><td colspan="6" style="padding:10px;color:#666">სერვისები ვერ მოიძებნა.</td></tr>`;
        return;
      }
      insTbody.innerHTML = j.rows.map(rowHtml).join('');
    }catch(e){
      insTbody.innerHTML = `<tr><td colspan="6" style="padding:10px;color:#c00">შეცდომა ჩატვირთვისას</td></tr>`;
    }
  }
  loadInsuranceRows();

  overlay.querySelector('#btnSaveInvMeta').addEventListener('click', async ()=>{
    const rows = [];
    overlay.querySelectorAll('#insBody tr').forEach(tr=>{
      const sid = parseInt(tr.getAttribute('data-service-id')||'0',10);
      if (!sid) return;
      rows.push({
        service_id: sid,
        price_type: (tr.children[0]?.textContent||'').trim() || 'შიდა',
        referral_number: (tr.querySelector('.in-ref')?.value||'').trim(),
        policy_number: (tr.querySelector('.in-pol')?.value||'').trim(),
        field1: (tr.querySelector('.in-f1')?.value||'').trim(),
        field2: (tr.querySelector('.in-f2')?.value||'').trim(),
        field3: (tr.querySelector('.in-f3')?.value||'').trim(),
      });
    });

    const fd = new FormData();
    fd.append('action','save_insurance_rows');
    fd.append('patient_id', String(pid));
    fd.append('rows', JSON.stringify(rows));

    try{
      const r = await fetch('', {method:'POST', body:fd, credentials:'same-origin'});
      const j = await r.json();
      if (j.status === 'ok') { alert('შენახულია'); }
      else { alert(j.message || 'ვერ შეინახა'); }
    }catch(e){ alert('ქსელის შეცდომა'); }
  });

  /* ----- invoices header table ----- */
  const tableBody = overlay.querySelector('#tab_invres tbody');
  async function loadInvoiceHeaderRows(){
    tableBody.innerHTML = `
      <tr id="TCბ"><td width="6%">#</td><td width="20%">თარიღი</td><td width="60%"></td><td>წაღებულია</td></tr>`;
    try{
      const r = await fetch(`?action=get_invoices&patient_id=${encodeURIComponent(pid)}`, {credentials:'same-origin'});
      const j = await r.json();
      if (j.status !== 'ok') throw new Error(j.message || 'load_failed');
      let seq = 0;
      j.rows.forEach(row=>{
        seq++;
        const tr = document.createElement('tr');
        tr.id = String(row.id);
        tr.innerHTML = `
          <td>${seq}</td>
          <td>${(row.issued_at||'').replace('T',' ')}</td>
          <td>
            <div style="text-align:left">
              <a href="javascript:void(0)" class="rgpap invT" data-t="1">ინვოისი</a>
              <a href="javascript:void(0)" class="rgpap invT" data-t="2" style="margin-left:6px">კალკულაცია</a>
              <a href="javascript:void(0)" class="rgpap invT" data-t="3" style="margin-left:6px">100/a</a>
              <a href="javascript:void(0)" class="rgpap invPrint" data-id="${row.id}" style="margin-left:10px">ბეჭდვა</a>
            </div>
          </td>
          <td><input type="checkbox" disabled></td>`;
        tableBody.appendChild(tr);
      });
    }catch(e){/* ignore */ }
  }
  loadInvoiceHeaderRows();

  // Helper to ensure a builder slot under header row
  function ensureBuilder(invId){
    const row = overlay.querySelector('#tab_invres tbody tr#' + CSS.escape(String(invId)));
    if (!row) return null;
    let next = row.nextElementSibling;
    if (!next || !next.classList.contains('builder-row')){
      const br = document.createElement('tr');
      br.className = 'builder-row';
      br.dataset.invoiceId = invId;
      const td = document.createElement('td'); td.colSpan = 4;
      td.innerHTML = `<div id="builder-${invId}" style="margin-top:20px;padding:10px"></div>`;
      br.appendChild(td);
      row.parentNode.insertBefore(br, row.nextElementSibling);
      next = br;
    }
    return next.querySelector(`#builder-${invId}`);
  }

  async function loadAndPopulateDonor(invId, host){
    try{
      const r = await fetch(`?action=get_invoice_donor&invoice_id=${encodeURIComponent(invId)}`, {credentials:'same-origin'});
      const j = await r.json().catch(()=> ({}));
      const name = j?.name || j?.donor_name || '';
      const addr = j?.address || j?.donor_address || '';
      const id   = j?.id || j?.donor_id || '';

      if (name){
        const box = host.querySelector(`#donorblk-${invId}`);
        if (box){
          const nm = box.querySelector(`#rfbn-${invId}`);
          const ad = box.querySelector(`#rfbn_ads-${invId}`);
          const hid= box.querySelector(`#hdansvl-${invId}`);
          if (nm) nm.value = name;
          if (ad) ad.value = addr || '-';
          if (hid) hid.value = String(id||'');
        }
        window.__DONOR_ID__   = String(id||'');
        window.__DONOR_NAME__ = name;
        window.__DONOR_ADDR__ = addr || '-';
      }
    }catch(_){}
  }

  // ========= Form 100/a renderer (WITH SERVER SYNC) =========
  window.mountForm100A = async function(invId){
    const host = ensureBuilder(invId); if (!host) return;

    // helpers
    const fmtNow = () => new Date().toISOString().slice(0,16).replace('T',' ');
    const lsKey = 'form100a:'+invId;

    // Fetch invoice items for section #12
    let items = [];
    try{
      const r = await fetch(`?action=get_invoice_items&invoice_id=${encodeURIComponent(invId)}`, {credentials:'same-origin'});
      const j = await r.json();
      if (j.status==='ok' && Array.isArray(j.items)) items = j.items;
    }catch(_){}

    const servicesHtml = items.length
      ? `<div class="რpdv" data-k="investigations">${items.map(x=>{
          const code = (x.code||'').trim();
          const nm   = (x.description||'').trim() || (x.comment||'').split('\n')[0] || '—';
          const qty  = Number(x.quantity||1);
          return `${escHtml2(code ? code+' ' : '')}${escHtml2(nm)}(${qty})`;
        }).join('<br>')}</div>`
      : `<div class="rpdv" data-k="investigations"></div>`;

    const orgTitle   = (window.__ORG__ && window.__ORG__.title) || '„სანმედი“ -';
    const pName      = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.name) || '—';
    const pPID       = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.pid)  || '—';
    const pBirth     = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.birth) || '—';
    const issuedAt   = fmtNow();

    host.innerHTML = `
      <style>
        .mcffx{ width:auto !important; padding-left:8px !important; padding-right:8px !important; }
        .fmdv{ margin:6px 0; line-height:1.35; }
        .fmdv .br{ display:block; font-size:12px; color:#333; margin-bottom:4px; }
        .fmdv .ml{ margin-left:6px; }
        .fmdv input[type="text"], .fmdv textarea{ border:1px solid #ccc; border-radius:4px; padding:4px 6px; font-size:12px; }
        .fmdv textarea{ width:100%; resize:vertical; min-height:44px; }
        .ui-draggable .bxclsF{ position:absolute; top:6px; right:6px; text-decoration:none; font-size:20px; line-height:20px; }
        .btnლtyrb { margin-top:8px; }
        .btnლtyrb .rgpap{ padding:6px 10px; border:1px solid #A7C3DE; background:#fff; cursor:pointer; }
        .muted-note{font-size:12px;color:#666;margin-left:8px}
      </style>

      <div class="innerfrm ui-draggable" style="width:900px; position:relative; top:5px; left:auto; right:auto;">
        <a href="javascript:void(0);" class="bxclsF nondrg zdx" title="დახურვა">×</a>
        <br>

        <div id="inter2" class="e0_">
          <div style="width:600px;margin:0 auto">
            <table class="tt tbalce" border="0" id="tab_invres_100a_${invId}">
              <tbody>
                <tr id="TCb">
                  <td width="6%">#</td>
                  <td width="20%">თარიღი</td>
                  <td width="60%"></td>
                  <td>წაღებულია</td>
                </tr>
                <tr id="${escHtml2(String(invId))}">
                  <td>—</td>
                  <td>${escHtml2(issuedAt)}</td>
                  <td>
                    <div style="text-align:left">
                      <a href="javascript:void(0)" class="rgpap invT" data-t="1">ინვოისი</a>
                      <a href="javascript:void(0)" class="rgpap invT" data-t="2" style="margin-left:6px">კალკულაცია</a>
                      <a href="javascript:void(0)" class="rgpap invT fmse" data-t="3" style="margin-left:6px">100/a</a>
                      <span class="muted-note" id="save_state_${invId}"></span>
                    </div>
                  </td>
                  <td><input type="checkbox" disabled></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div id="sswew" style="margin-top:20px;padding:10px">
            <div id="L100A_${invId}">
              <div id="wrap_100a_${invId}" class="olvn tmpldiv nondrg mcffx">
                <div style="text-align:center">
                  <span style="font-size:10px;">დამტკიცებულია
                  საქართველოს შრომის, ჯანმრთელობისა და სოციალური დაცვის მინისტრის
                  2007 წ. 09.08 № 338/ნ ბრძანებით</span>
                </div>
                <div style="text-align:center"><span style="font-size:17px;">სამედიცინო დოკუმენტაცია ფორმა № IV-100/ა</span></div>
                <div style="text-align:center"><span style="font-size:20px;font-family:mtavruli;font-weight:bold;">ცნობა ჯანმრთელობის მდგომარეობის შესახებ</span></div><br>

                <div class="fmdv"><span class="br">1. ცნობის გამცემი დაწესებულების დასახელება...</span></div>
                <div class="fmdv"><span class="ml"><input data-k="org_name" type="text" readonly style="width:830px" class="nondrg ko" value="${escHtml2(orgTitle)}"></span></div>

                <div class="fmdv"><span class="br">2. დაწესებულების დასახელება, მისამართი სადაც იგზავნება ცნობა</span></div>
                <div class="fmdv"><textarea data-k="recipient" class="nondrg ko" lang="ka" style="width:100%;">დანიშნულებისამებრ წარსადგენად</textarea></div>

                <div class="fmdv"><span>3. პაციენტის სახელი და გვარი</span><span class="ml"><input data-k="patient_name" type="text" readonly style="width:610px" class="nondrg ko" value="${escHtml2(pName)}"></span></div>
                <div class="fmdv"><span>4. დაბადების თარიღი (რიცხვი/თვე/წელი)</span><span class="ml"><input data-k="birthdate" type="text" readonly style="width:530px" class="nondrg ko" value="${escHtml2(pBirth || '')}"></span></div>
                <div class="fmdv"><span>5. პირადი ნომერი</span><span class="ml"><input data-k="personal_id" type="text" readonly style="width:695px" class="nondrg ko" value="${escHtml2(pPID)}"></span></div>
                <div class="fmdv"><span style="margin-left:290px" class="jo">(ივსება 16 წელს მიღწეული პირის შემთხვევაში)</span></div>

                <div class="fmdv"><span>6. მისამართი</span><span class="ml"><input data-k="address" type="text" style="width:730px" class="nondrg ko" value=""></span></div>
                <div class="fmdv"><span>7. სამუშაო ადგილი და თანამდებობა ...</span></div>
                <div class="fmdv"><textarea data-k="work" class="nondrg ko" lang="ка" style="width:100%;"></textarea></div>

                <div class="fmdv"><span>8. თარიქები: ა) ექიმთან მიმართვის</span><span class="ml"><input data-k="visit_date" type="text" style="width:520px" class="nondrg ko" value="${escHtml2(issuedAt)}"></span></div>
                <div class="fmdv"><span class="oj">ბ) სტაციონარში გაგზავნის</span><span class="ml"><input data-k="sent_date" type="text" style="width:490px" class="nondrg ko" value=""></span></div>
                <div class="fmdv"><span class="oj">გ) სტაციონარში მოთავსების</span><span class="ml"><input data-k="admit_date" type="text" style="width:480px" class="nondrg ko" value=""></span></div>
                <div class="fmdv"><span class="oj">დ) გაწერის</span><span class="ml"><input data-k="discharge_date" type="text" style="width:600px" class="nondrg ko" value=""></span></div>

                <div class="fmdv"><span>9. დასკვნა ჯანმრთელობის მდგომარეობის შესახებ ...</span></div>
                <div class="fmdv"><textarea data-k="conclusion" class="nondrg ko" lang="ka" style="width:100%;"></textarea></div>

                <div class="fmdv"><span>10. გადატანილი დაავადებები</span></div>
                <div class="fmdv"><textarea data-k="diseases" class="nondrg ko" lang="ka" style="width:100%;"></textarea></div>

                <div class="fmdv"><span>11. მოკლე ანამნეზი</span></div>
                <div class="fmdv"><textarea data-k="anamnesis" class="nondrg ko" lang="ka" style="width:100%;"></textarea></div>

                <div class="fmdv"><span>12. ჩატარებული დიაგნოსტიკური გამოკვლევები და კონსულტაციები</span></div>
                <div class="fmdv"><span>${servicesHtml}</span></div>

                <div class="fmdv"><span>13. ავადმყოფობის მიმდინარეობა</span></div>
                <div class="fmdv"><textarea data-k="course" class="nondrg ko" lang="ka" style="width:100%;" n="7854"></textarea></div>

                <div class="fmdv"><span>14. ჩატარებული მკურნალობა</span></div>
                <div class="fmdv"><textarea data-k="treatment" class="nondrg ko" lang="ka" style="width:100%;" n="7856"></textarea></div>

                <div class="fmdv"><span>15. მდგომარეობა სტაციონარში გაგზავნისას</span></div>
                <div class="fmdv"><textarea data-k="state_send" class="nondrg ko" lang="ka" style="width:100%;" n="7858"></textarea></div>

                <div class="fmdv"><span>16. მდგომარეობა სტაციონარიდან გაწერისას</span></div>
                <div class="fmdv"><textarea data-k="state_discharge" class="nondrg ko" lang="ka" style="width:100%;" n="7860"></textarea></div>

                <div class="fmdv"><span>17. სამკურნალო და შრომითი რეკომენდაციები</span></div>
                <div class="fmdv"><div class="rpdv" data-k="recommendations"></div></div>

                <div class="fmdv"><span>18. მკურნალი ექიმი (ექიმი სპეციალისტი)</span><span class="ml"><input data-k="doctor" type="text" style="width:510px" class="nondrg ko" value=""></span></div>

                <div class="fmdv"><span>19. დაწესებულების ხელმძღვანელის ... ხელმოწერა</span></div>
                <div class="fmdv"><span class="op">______________________________________________________________________________________________________</span></div>

                <div class="fmdv"><span>20. ცნობის გაცემის თარიღი</span><span class="ml"><input data-k="issue_date" type="text" style="width:610px" class="nondrg ko" value=""></span></div>
                <div class="fmdv"><span class="oz">ბეჭდის ადგილი</span></div>

                <div class="btnლtyrb">
                  <input type="button" id="btn_save_100a_${invId}" class="rgpap" value="შენახვა" disabled>
                  <a href="javascript:void(0)" id="btn_print_100a_${invId}" class="rgpap" style="margin-left:20px">ბეჭდვა</a>
                  <!-- NEW: manual sync trigger -->
                  <button id="btn_flush_sync_${invId}" class="rgpap" style="margin-left:10px">ახლავე გაგზავნა</button>
                </div>
                <div class="muted-note" id="save_state_${invId}"></div>
              </div>
            </div>
          </div>

          <div class="nondrg" style="width:900px;padding-top:50px;">
            <h4 style="color:#6E6ED7">სადაზღვეოს მიმართვები</h4>
            <table class="pp" style="width:100%;background-color:#D5C8C8;margin-top:15px">
              <tbody><tr>
                <td style="width:10%">ფასის ტიპი</td>
                <td style="width:51%">სერვისი</td>
                <td style="width:13%;text-align:center"></td>
                <td style="width:13%;text-align:center"></td>
                <td style="width:13%;text-align:center"></td>
              </tr></tbody>
            </table>
            <table class="pp" id="lvria_100a_${invId}" style="width:100%;background:#fff;">
              <tbody><tr>
                <td style="width:10%;border-bottom:1px solid #ccc;background:#f7f7f7">შიდა</td>
                <td style="width:44%;border-bottom:1px solid #ccc;background:#f7f7f7">—</td>
                <td style="width:7%;border-bottom:1px solid #ccc;background:#f7f7f7">მიმართვის#<br><br><br>პოლისის#</td>
                <td style="width:13%;border-bottom:1px solid #ccc;background:#f7f7f7"><input type="text" value=""><br><br><input type="text" value=""></td>
                <td style="width:13%;border-bottom:1px solid #ccc;background:#f7f7f7"><input type="text" value=""><br><br><input type="text" value=""></td>
                <td style="width:13%;border-bottom:1px solid #ccc;background:#f7f7f7"><input type="text" value=""><br><br><input type="text" value=""></td>
              </tr></tbody>
            </table>
          </div>

          <div style="margin-top:10px;text-align:right">
            <input type="button" value="შენახვა" class="rgpap">
          </div>

          <div id="LoadingImage2" class="mnhov" style="position:absolute; left:0; top:0; width:100%; height:100%; display:none;">
            <div style="margin:auto;margin-top:150px;width:50px;height:50px;background-image:url(../../images/spinner.gif)"></div>
          </div>
        </div>
      </div>`;

    // Close (×)
    const closeBtn = host.querySelector('.bxclsF');
    if (closeBtn) closeBtn.addEventListener('click', ()=>{ host.innerHTML=''; });

    // Switcher links
    host.querySelectorAll('.invT').forEach(a=>{
      a.addEventListener('click', (ev)=>{
        ev.preventDefault();
        const t = a.getAttribute('data-t');
        if (t==='1') mountBuilder(invId, null);
        if (t==='2') alert('კალკულაცია მოგვიანებით');
        // t==='3' already here
      });
    });

    // ====== field helpers for SAVE/LOAD ======
// ... (no further changes below this point; code continues exactly as in your version)
  };
  document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('#btn_issue_payment_invoice');
  if (!btn) return;

  const pid = Number(document.getElementById('pa_pid')?.value || 0);

  // collect selected payments from #pa_pa
  const payIds = Array.from(document.querySelectorAll('#pa_pa tbody .zl:checked'))
    .map(ch => (ch.closest('tr')?.id || '').replace(/^O/, ''))
    .filter(Boolean);

  if (!pid || !payIds.length) {
    alert('აირჩიეთ პაციენტი და მინ. 1 გადახდა');
    return;
  }

  // collect visible service titles from checked rows in #fs_amounttypet (2nd <td>)
  const svcTitles = Array.from(document.querySelectorAll('#fs_amounttypet tbody tr'))
    .filter(tr => tr.querySelector('.srv-chk')?.checked)
    .map(tr => (tr.cells[1]?.innerText || '').replace(/\s+/g, ' ').trim())
    .filter(Boolean);

  const form = new URLSearchParams();
  form.set('action', 'create_payment_invoice_demo');
  form.set('patient_id', String(pid));
  form.set('payment_ids', payIds.join('@'));       // e.g. "1174@85"
  form.set('svc_fallback', svcTitles.join(', '));  // << send the visible text

  try {
    const resp = await fetch('', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: form.toString()
    }).then(r => r.json());

    if (!resp || resp.status !== 'ok') throw new Error(resp?.message || 'შეცდომა ქვითრის შექმნისას');
    window.open(resp.pdf_url, '_blank');
  } catch (err) {
    alert(err.message || 'შეცდომა');
  }
});

// ========= /Form 100/a renderer =========

  // Header print (uses current patient)
  tableBody.addEventListener('click', async (e)=>{
    const a = e.target.closest('a.invPrint');
    if(!a) return;
    const iid = a.getAttribute('data-id');
    if(!iid) return;
    const r  = await fetch(`?action=get_invoice_items&invoice_id=${encodeURIComponent(iid)}`, {credentials:'same-origin'});
    const j  = await r.json();
    if(j.status!=='ok'){ alert(j.message||'ვერ ჩაიტვირთა'); return; }
    const items = (j.items||[]).map(x=>{
      // Get service title from description or first line of comment
      const title = (x.description && String(x.description).trim())
        ? x.description
        : (x.comment ? String(x.comment).split('\n')[0] : '—');
      
      // Remove first line from comment if it matches the title (avoid duplication)
      let cleanComment = x.comment || '';
      if (cleanComment) {
        const lines = cleanComment.split('\n');
        if (lines[0] && lines[0].trim() === String(title).trim()) {
          cleanComment = lines.slice(1).join('\n').trim();
        }
      }
      
      return {
        title: title,
        qty: Number(x.quantity||1),
        price: Number(x.unit_price||0),
        sum: Number(x.line_total||0),
        comment: cleanComment
      };
    });
    const total = Number(j.total ?? items.reduce((s,i)=>s+i.sum,0));

    const donorFromServer = j.donor_name || (j.donor && j.donor.name) || (j.meta && j.meta.donor_name) || '';
    if (donorFromServer){
      window.__DONOR_NAME__ = donorFromServer;
      window.__DONOR_ADDR__ = j.donor_address || '-';
      window.__DONOR_ID__   = j.donor_id || '';
    }

    const payerName = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.name) || window.__PAYER_NAME__ || '—';
    const payerPid  = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.pid)  || window.__PAYER_PID__  || '—';

    window.printInvoice({
      docNo: String(iid),
      docDate: new Date().toISOString().slice(0,10),
      lines: items,
      total,
      payerName,
      payerPid,
      donorName: donorFromServer || window.__DONOR_NAME__ || '—'
    });
  });

  function mountBuilder(invId, initial){
    const host = ensureBuilder(invId); if (!host) return;

    const initTitle   = initial?.title   ?? '';
    const initQty     = initial?.qty     ?? 1;
    const initPrice   = initial?.price   ?? 0;
    const initComment = initial?.comment ?? '';

    const ids = {
      select: `zi_endgben-${invId}`,
      comm:   `pl_comm-${invId}`,
      title:  `serchinvi-${invId}`,
      qty:    `pl_raod-${invId}`,
      price:  `pl_amo-${invId}`,
      table:  `invoid-${invId}`,
      sum:    `insum-${invId}`,
      add:    `btn_add_line-${invId}`,
      print:  `btn_print_invoice-${invId}`
    };

    /* --- DONOR block (prepended) --- */
    const donorHtml = `
      <table class="tg" id="donorblk-${invId}" style="margin:8px 0 10px 0; width:100%">
        <tbody>
          <tr>
            <td width="90%">
              <label style="display:block;font-size:11px">დონორის ძებნა</label>
              <input type="hidden" id="hdansvl-${invId}" value="">
              <input type="text" id="srchrfng-${invId}" l="#fidfid-${invId}"
                     onkeyup="var c=event.which||event.keyCode; if(c==40||c==38||c==13||c==37||c==39)return; $('#hdansvl-${invId}').val(''); $('#fidfid-${invId}').empty(); if((this.value||'').length<2)return; delay(function(){ glAj('search4v','ვgebad','','','','|','|',{'srchrfng':'11'}); },500);"
                     class="nondrg knop" style="height:26px">
              <div id="fidfid-${invId}" class="kcham nondrg" n="hdansvl-${invId}" m="srchrfng-${invId},srchrfng-${invId}"
                   style="border-width:0 1px 1px 1px;border-style:solid;border-color:gray;position:absolute;width:759px;height:auto;overflow-y:visible;font-size:12px;background:#eee;z-index:10;"></div>
            </td>
            <td style="vertical-align:bottom;text-align:center">
              <a href="javascript:void(0)"
                 onclick="glAj('insert4v','grdm_avк','','','subGMsg2','|','|');"
                 style="font-size:14px" class="rgpap">შენახვა</a>
            </td>
          </tr>
          <tr>
            <td>
              <label style="display:block;font-size:11px">დონორი
                <input type="text" id="rfbn-${invId}" disabled class="nondrg disCol" value="-" style="height:26px">
              </label>
            </td>
            <td rowspan="2" style="text-align:center">
              <a href="javascript:void(0);" onclick="glAj('insert4v','ბvgdel','','','subGMsg2','|','|');" style="font-size:12px">განადგურება</a>
            </td>
          </tr>
          <tr>
            <td>
              <label style="display:block;font-size:11px">დონორის მისამართი
                <input type="text" id="rfbn_ads-${invId}" disabled class="nondrg disCol" value="-" style="height:26px">
              </label>
            </td>
          </tr>
        </tbody>
      </table>`;

    host.innerHTML = donorHtml + `
      <style>#zviod-${invId} td{padding:3px} #zviod-${invId} th{padding:6px}</style>
      <div style="border:1px solid #87CEEB;padding:16px;background-color:#FFF;">
        <table class="tg" id="zviod-${invId}">
          <tbody>
            <tr>
              <td colspan="2">
                <label style="font-size:12px;color:blue">ფასის ტიპი
                  <select id="${ids.select}" style="margin-bottom:1px;height:28px;padding:4px;" class="nondrg">
                    <option value="შიდა სტანდარტი">შიდა სტანდარტი</option>
                    <option value="შიდა გასაყიდი ფასი">შიდა გასაყიდი ფასი</option>
                    <option value="ძველი პაციენტები">ძველი პაციენტები</option>
                    <option value="ძველი პაცი 2">ძველი პაცი 2</option>
                  </select>
                </label>
              </td>
              <td rowspan="3" width="45%">
                <label style="font-size:12px;color:blue">კომენტარი
                  <Textarea id="${ids.comm}" style="font-size:12px;height:133px;width:100%;resize:none;vertical-align:bottom;padding:4px">${escHtml2(initComment)}</Textarea>
                </label>
              </td>
              <td width="5%" rowspan="3" style="vertical-align:bottom">
                <button type="button" id="${ids.add}" style="width:44px;height:44px;font-size:24px;font-weight:bold;cursor:pointer;background:#28a745;color:white;border:none;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.2)" class="smadbut" title="შენახვა (ხაზი)">+</button>
              </td>
            </tr>

            <tr>
              <td colspan="2">
                <label style="font-size:12px;color:blue">გაწეული მომსახურების დასახელება
                  <input type="text" style="padding:4px;height:28px" class="nondrg knop" id="${ids.title}" value="${escHtml2(initTitle)}">
                </label>
              </td>
            </tr>

            <tr>
              <td>
                <label style="font-size:12px;color:blue">რაოდენობა
                  <input type="text" style="padding:4px;height:28px" class="nondrg" id="${ids.qty}" value="${fmt0(initQty)}">
                </label>
              </td>
              <td>
                <label style="font-size:12px;color:blue">თანხა
                  <input type="text" style="padding:4px;height:28px" class="nondrg" id="${ids.price}" value="${fmt2(initPrice)}">
                </label>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div style="padding:16px;margin-bottom:50px">
        <table class="NT pad nondrg" id="${ids.table}" style="margin-top:20px" border="1">
          <tbody>
            <tr id="TCb">
              <th width="35%" style="text-align:center">დასახელება</th>
              <th width="10%" style="text-align:center">რაოდენობა</th>
              <th width="10%" style="text-align:center">თანხა</th>
              <th width="10%" style="text-align:center">ჯამი</th>
              <th width="30%" style="text-align:center">კომენტარი</th>
              <th width="5%"></th>
            </tr>
            <tr data-sum-row="1">
              <td></td><td></td><td></td>
              <td><b style="font-size:18px" id="${ids.sum}">0.00</b></td>
              <td></td><td></td>
            </tr>
          </tbody>
        </table>
        <div style="text-align:right" class="noprint">
          <button id="${ids.print}" class="btn" style="margin-top:8px">ბეჭდვა</button>
        </div>
      </div>`;

    // populate donor if already attached
    loadAndPopulateDonor(invId, host);

    function recalcLocalSum(){
      const tbody = host.querySelector(`#${ids.table} tbody`);
      let sum = 0;
      tbody.querySelectorAll('tr[data-line]').forEach(tr=>{
        sum += parseFloat(tr.getAttribute('data-line-sum')||'0')||0;
      });
      host.querySelector(`#${ids.sum}`).textContent = fmt2(sum);
    }

    function renderItemRow(item){
      const tbody  = host.querySelector(`#${ids.table} tbody`);
      const sumRow = tbody.querySelector('tr[data-sum-row]');
      const tr = document.createElement('tr');
      tr.setAttribute('data-line','1');
      tr.setAttribute('data-line-sum', String(Number(item.line_total)||0));
      tr.setAttribute('data-item-id', String(item.id||''));

      const title = (item.description && String(item.description).trim())
        ? item.description
        : (item.comment ? String(item.comment).split('\n')[0] : '—');

      tr.innerHTML = `
        <td title="${escHtml2(item.price_type || 'შიდა სტანდარტი')}">${escHtml2(title)}</td>
        <td>${fmt0(item.quantity ?? 1)}</td>
        <td>${fmt2(item.unit_price ?? 0)}</td>
        <td>${fmt2(item.line_total ?? 0)}</td>
        <td><pre>${escHtml2(item.comment ?? '')}</pre></td>
        <td><div class="del" title="წაშლა" style="cursor:pointer"></div></td>`;

      tr.querySelector('.del').addEventListener('click', async ()=>{
        const itemId = tr.getAttribute('data-item-id');
        if (!itemId) { tr.remove(); recalcLocalSum(); return; }
        if (!confirm('წაიშალოს ეს ხაზი?')) return;

        const fd = new FormData();
        fd.append('action','delete_invoice_item');
        fd.append('invoice_id', String(invId));
        fd.append('item_id', String(itemId));
        try{
          const r = await fetch('', {method:'POST', body:fd, credentials:'same-origin'});
          const j = await r.json();
          if (j.status === 'ok') { await loadExistingItems(); }
          else { alert(j.message || 'წაშლა ვერ შესრულდა'); }
        }catch(e){ alert('ქსელის შეცდომა'); }
      });

      tbody.insertBefore(tr, sumRow);
    }

    async function loadExistingItems(){
      try{
        const r = await fetch(`?action=get_invoice_items&invoice_id=${encodeURIComponent(invId)}`, {credentials:'same-origin'});
        const j = await r.json();
        host.querySelectorAll(`#${ids.table} tbody tr[data-line]`).forEach(x=>x.remove());
        if (j.status === 'OK' || j.status === 'ok') {
          (j.items||[]).forEach(renderItemRow);
          if (j.total != null) host.querySelector(`#${ids.sum}`).textContent = fmt2(j.total);
          else recalcLocalSum();
        } else {
          recalcLocalSum();
        }
      }catch(e){ /* ignore */ }
    }
    loadExistingItems();

    // Add line
    host.querySelector('#'+ids.add).addEventListener('click', async ()=>{
      const title = (host.querySelector('#'+ids.title)?.value||'').trim();
      const qty   = Math.max(1, parseFloat(host.querySelector('#'+ids.qty)?.value||1) || 1);
      const price = parseFloat(host.querySelector('#'+ids.price)?.value||0) || 0;
      const comment   = (host.querySelector('#'+ids.comm)?.value||'').trim();
      const priceType = (host.querySelector('#'+ids.select)?.value||'შიდა სტანდარტი').trim();

      if (!(price > 0)) { alert('შეიყვანე ფასი'); return; }

      const fd = new FormData();
      fd.append('action','add_invoice_line');
      fd.append('patient_id', String(pid));
      fd.append('invoice_id', String(invId));
      fd.append('service_id','');
      fd.append('title', title);
      fd.append('qty', String(qty));
      fd.append('price', String(price));
      fd.append('comment', comment);
      fd.append('price_type', priceType);

      try{
        const r = await fetch('', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json();
        if (j.status !== 'ok') { alert(j.message || 'DB შეცდომა'); return; }
        await loadExistingItems();
        host.querySelector('#'+ids.title).value = '';
        host.querySelector('#'+ids.qty).value   = '1';
        host.querySelector('#'+ids.price).value = fmt2(0);
      }catch(e){ alert('ქსელის შეცდომა'); }
    });

    // Print button under builder (STRICT A4 + current patient)
    host.querySelector('#'+ids.print).addEventListener('click', async ()=>{
      const r = await fetch(`?action=get_invoice_items&invoice_id=${encodeURIComponent(invId)}`, {credentials:'same-origin'});
      const j = await r.json();
      if(j.status!=='ok'){ alert(j.message||'ვერ ჩაიტვირთა'); return; }
      const items = (j.items||[]).map(x=>{
        // Get service title from description or first line of comment
        const title = (x.description && String(x.description).trim())
          ? x.description
          : (x.comment ? String(x.comment).split('\n')[0] : '—');
        
        // Remove first line from comment if it matches the title (avoid duplication)
        let cleanComment = x.comment || '';
        if (cleanComment) {
          const lines = cleanComment.split('\n');
          if (lines[0] && lines[0].trim() === String(title).trim()) {
            cleanComment = lines.slice(1).join('\n').trim();
          }
        }
        
        return {
          title: title,
          qty: Number(x.quantity||1),
          price: Number(x.unit_price||0),
          sum: Number(x.line_total||0),
          comment: cleanComment
        };
      });
      const total = Number(j.total ?? items.reduce((s,i)=>s+i.sum,0));
      const issued = new Date().toISOString().slice(0,10);

      const donorFromServer = j.donor_name || (j.donor && j.donor.name) || (j.meta && j.meta.donor_name) || '';
      if (donorFromServer){
        window.__DONOR_NAME__ = donorFromServer;
        window.__DONOR_ADDR__ = j.donor_address || '-';
        window.__DONOR_ID__   = j.donor_id || '';
      }

      const payerName = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.name) || window.__PAYER_NAME__ || '—';
      const payerPid  = (window.__CURRENT_PATIENT__ && window.__CURRENT_PATIENT__.pid)  || window.__PAYER_PID__  || '—';

      window.printInvoice({
        docNo: String(invId),
        docDate: issued,
        lines: items,
        total,
        payerName,
        payerPid,
        donorName: donorFromServer || window.__DONOR_NAME__ || '—'
      });
    });
  } // mountForm100A

  // header table actions: open builder / calc / 100a
  tableBody.addEventListener('click', (e)=>{
    const a = e.target.closest('a.invT'); if (!a) return;
    e.preventDefault();
    const invId = a.closest('tr')?.id;
    const t     = a.dataset.t;
    tableBody.querySelectorAll('a.invT').forEach(x=>x.classList.remove('fmse'));
    a.classList.add('fmse');

    if (t === '1' && invId) {
      mountBuilder(invId, null);
      const br = overlay.querySelector(`.builder-row[data-invoice-id="${invId}"]`);
      if (br) br.scrollIntoView({behavior:'smooth', block:'start'});
    } else if (t === '2') {
      alert('კალკულაცია მოგვიანობით');
    } else if (t === '3' && invId) {
      if (typeof window.mountForm100A === 'function') {
        window.mountForm100A(invId);
        const br = overlay.querySelector(`.builder-row[data-invoice-id="${invId}"]`);
        if (br) br.scrollIntoView({behavior:'smooth', block:'start'});
      } else {
        alert('ფორმა 100/a არაა მიერთებული.');
      }
    }
  });

  async function createDraft(){
    const fd = new FormData();
    fd.append('action', 'create_invoice_draft');
    fd.append('patient_id', String(pid));
    try{
      const r = await fetch('', { method:'POST', body: fd, credentials:'same-origin' });
      const j = await r.json();
      if (!j || j.status !== 'ok') { alert((j && j.message) ? j.message : 'შეცდომა შექმნისას'); return; }

      const invId  = j.invoice_id;
      const tstamp = j.issued_at || '';
      const tbody  = overlay.querySelector('#tab_invres tbody');

      const tr = document.createElement('tr');
      tr.id = String(invId);
      const seq = tbody.querySelectorAll('tr').length;
      tr.innerHTML = `
        <td>${seq}</td>
        <td>${tstamp.replace('T',' ')}</td>
        <td>
          <div style="text-align:left">
            <a href="javascript:void(0)" class="rgpap invT fmse" data-t="1">ინვოისი</a>
            <a href="javascript:void(0)" class="rgpap invT" data-t="2" style="margin-left:6px">კალკულაცია</a>
            <a href="javascript:void(0)" class="rgpap invT" data-t="3" style="margin-left:6px">100/a</a>
            <a href="javascript:void(0)" class="rgpap invPrint" data-id="${invId}" style="margin-left:10px">ბეჭდვა</a>
          </div>
        </td>
        <td><input type="checkbox" disabled></td>`;
      tbody.appendChild(tr);

      mountBuilder(invId, j.item || null);
      const br = overlay.querySelector(`.builder-row[data-invoice-id="${invId}"]`);
      if (br) br.scrollIntoView({behavior:'smooth', block:'start'});

    }catch(e){ alert('ქსელის შეცდომა'); }
  }
  overlay.querySelector('#levoz').addEventListener('click', e=>{ e.preventDefault(); createDraft(); });

  overlay.addEventListener('click', (e)=>{ if(e.target===overlay || e.target.hasAttribute('data-close')) { overlay.style.display='none'; overlay.innerHTML=''; }});
} // openInvoicePanel

// right-sidebar button wiring (ინვოისი)
(function(){
  const btn = document.getElementById('mr_rpinvo');
  if (!btn) return;
  btn.addEventListener('click', function(e){
    e.preventDefault();
    if (this.classList.contains('disabled')) return;
    const pid = document.getElementById('hdPtRgID')?.value ||
                (document.querySelector('#patientsTable tbody tr.selected')?.dataset.id || '');
    if(!pid){ alert('გთხოვთ აირჩიოთ პაციენტი.'); return; }
    openInvoicePanel(pid);
  });
})();

/* ====== OPTIONAL: Bind static 100/a #50 PDF button if present ====== */
(function(){
  const btn = document.getElementById('btn_print_100a_50');
  if (!btn) return;
  btn.addEventListener('click', async function(e){
    e.preventDefault();
    const el = document.querySelector('#wrap_100a_50 .a4-sheet') || document.querySelector('#wrap_100a_50');
    if (!el){ alert('ფორმა 100/ა ვერ მოიძებნა.'); return; }
    try{
      await saveElementAsA4Pdf(el, 'IV-100a-50.pdf');
    }catch(err){
      alert('PDF გენერირების შეცდომა');
      window.print();
    }
  });
})();
(function(){
  const inp = document.getElementById('searchInput');
  const tbody = document.querySelector('#patientsTable tbody');

  if (!inp || !tbody) return;

  function norm(s){ return String(s||'').toLowerCase().trim(); }

  function rowMatches(tr, tokens){
    const fn  = norm(tr.dataset.firstname);
    const ln  = norm(tr.dataset.lastname);
    const pid = norm(tr.dataset.personalid);
    const txt = [pid, fn, ln].join(' ');
    for (const t of tokens){ if (!txt.includes(t)) return false; }
    return true;
  }

  function ensureNoResultRow(){
    let nr = tbody.querySelector('tr.__nores');
    if (!nr){
      nr = document.createElement('tr');
      nr.className = '__nores';
      nr.innerHTML = '<td colspan="10" style="text-align:center;color:#888;font-style:italic">ჩანაწერი ვერ მოიძებნა</td>';
      tbody.appendChild(nr);
    }
    return nr;
  }

  function filterPatients(q){
    const tokens = norm(q).split(/\s+/).filter(Boolean);
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => !tr.classList.contains('__nores'));

    if (tokens.length === 0){
      rows.forEach(tr => tr.style.display = '');
      const nr = tbody.querySelector('tr.__nores'); if (nr) nr.remove();
      return;
    }

    let vis = 0;
    rows.forEach(tr => {
      const show = rowMatches(tr, tokens);
      tr.style.display = show ? '' : 'none';
      if (show) vis++;
    });

    if (vis === 0) ensureNoResultRow().style.display = '';
    else { const nr = tbody.querySelector('tr.__nores'); if (nr) nr.remove(); }
  }

  // simple debounce
  let t;
  inp.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => filterPatients(inp.value), 150);
  });
})();
</script>

</body>
</html>