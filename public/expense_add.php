<?php
/**
 * expense_add.php — full page (No negatives, BOG unified, Donor removed)
 * - CRUD: insert / fetch / update / soft-delete / undelete
 * - Filters + paging + sort + Excel + CSV + print
 * - Balance = SUM(payments) - SUM(expenses) (no opening add)
 * - Method aliases & canonicalization:
 *      cash ⇢ ['cash','34','სალარო', ...]
 *      bog  ⇢ ['bog','bank_bog','bog_bank','bank of georgia','825','ბოგ','ბოღ', ...]  ← merged as ONE (BOG)
 * - Method balances card fed from SQL view: v_method_balance (method,payments_sum,expenses_sum,balance),
 *   and rows with bank_bog/bog are merged server-side into a single "BOG".
 * - DONOR removed from everywhere (options, mappings, search/export).
 */

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__ . '/../config/config.php'; // must provide $pdo

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

/* -------- Security Headers -------- */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: interest-cohort=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex');
// CSP: allow Google Fonts (stylesheet + font files)
header(
  "Content-Security-Policy: " .
  "default-src 'self'; " .
  "img-src 'self' data:; " .
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
  "font-src 'self' https://fonts.gstatic.com data:; " .
  "connect-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com; " .
  "script-src 'self' 'unsafe-inline'; " .
  "frame-ancestors 'self'; " .
  "base-uri 'self'; " .
  "form-action 'self'"
);

// კონსტანტები
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (!defined('PAGE_SIZE_DEFAULT')) define('PAGE_SIZE_DEFAULT', 50);
if (!defined('PAGE_SIZE_MAX')) define('PAGE_SIZE_MAX', 200);

/* (kept but not applied to balance) */
$OPENING = [
  'cash'     => 0.00,
  'bog'      => 0.00,
  'founder'  => 0.00,
  'transfer' => 0.00,
  'bank'     => 0.00,
];

/* -------- CSRF -------- */
if (empty($_SESSION['csrf'])) {
  try { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } catch (Throwable $e) { $_SESSION['csrf'] = md5(uniqid('', true)); }
}
function requireCsrf(): void {
  $sent = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  $tok  = (string)($_SESSION['csrf'] ?? '');
  if (!$tok || !$sent || !hash_equals($tok, $sent)) {
    http_response_code(419);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'error','message'=>'CSRF']);
    exit;
  }
}

/* -------- Helpers -------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ $n=is_null($n)?0:(float)$n; return number_format($n,2,'.',','); }
function like($s){ return '%'.$s.'%'; }

/* Returns a valid users.id or NULL (never 0) */
function resolveCreatedBy(PDO $pdo): ?int {
  $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
  if ($uid <= 0) return null;
  try {
    $st = $pdo->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $found = $st->fetchColumn();
    return $found ? (int)$found : null;
  } catch (Throwable $e) {
    return null;
  }
}

function mapMethodLabel($m){
  $m = strtolower(trim((string)$m));
  return [
    'cash'     => 'სალარო',
    'bog'      => 'BOG',
    'bank_bog' => 'BOG',
    'founder'  => 'დამფუძნებელი',
    'transfer' => 'გადმორიცხვა',
    'bank'     => 'ბანკი',
  ][$m] ?? ($m ?: 'სხვა');
}
const METHOD_WHITELIST = ['cash','bog','founder','transfer','bank']; // donor removed

/* მკაცრი წესი — ნაშთი არასდროს გახდეს უარყოფითი */
$NO_NEG_METHODS = [
  'cash'     => true,
  'bog'      => true,
  'founder'  => true,
  'transfer' => true,
  'bank'     => true,
];
function noNegEnforced(string $m): bool {
  global $NO_NEG_METHODS;
  $m = canonicalMethod($m);
  return !empty($NO_NEG_METHODS[$m]);
}

/* Sender/Giver options used by UI */
$GIVERS = [
  '34'  => ['label'=>'სალარო',       'method'=>'cash'],
  '825' => ['label'=>'BOG',          'method'=>'bog'],
  '446' => ['label'=>'დამფუძნებელი', 'method'=>'founder'],
];

/* PDO charset / attributes */
try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
  if (method_exists($pdo, 'setAttribute')) $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (Throwable $e) {}

/* -------- DB helpers -------- */
function tableExists(PDO $pdo, string $name): bool {
  try { $st = $pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$name]); return (bool)$st->fetchColumn(); }
  catch (Throwable $e) { return false; }
}
function columnExists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function viewExists(PDO $pdo, string $view): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$view]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) { return false; }
}

/* -------- Method aliases + canonicalization -------- */
function methodAliases(string $method): array {
  $m = mb_strtolower(trim($method), 'UTF-8');
  switch ($m) {
    case 'cash':
      return ['cash','34','სალარო','salaro','კეში','kesi','cashier'];
    case 'bog':
      return ['bog','825','ბოგ','ბოღ','bank of georgia','bank_bog','bog_bank'];
    case 'founder':
      return ['founder','446','დამფუძნებელი'];
    case 'transfer':
      return ['transfer','გადმორიცხვა'];
    case 'bank':
      return ['bank','ბანკი'];
    default:
      return [$m];
  }
}
function canonicalMethod(string $m): string {
  $x = mb_strtolower(trim($m), 'UTF-8');
  if (in_array($x, methodAliases('bog'), true))       return 'bog';
  if (in_array($x, methodAliases('cash'), true))      return 'cash';
  if (in_array($x, methodAliases('founder'), true))   return 'founder';
  if (in_array($x, methodAliases('transfer'), true))  return 'transfer';
  if (in_array($x, methodAliases('bank'), true))      return 'bank';
  return $x;
}

/* -------- Ensure view v_method_balance exists (best effort) -------- */
function ensureMethodBalanceView(PDO $pdo): void {
  try {
    if (viewExists($pdo, 'v_method_balance')) return;
    if (!tableExists($pdo,'payments') && !tableExists($pdo,'expenses')) return;

    $hasPD = columnExists($pdo,'payments','deleted_at');
    $pDel  = $hasPD ? "AND p.deleted_at IS NULL" : "";

    $sql = "
      CREATE OR REPLACE VIEW v_method_balance AS
      SELECT method,
             COALESCE(SUM(payments_sum),0) AS payments_sum,
             COALESCE(SUM(expenses_sum),0) AS expenses_sum,
             COALESCE(SUM(payments_sum),0) - COALESCE(SUM(expenses_sum),0) AS balance
      FROM (
        SELECT LOWER(TRIM(p.method)) AS method,
               SUM(p.amount) AS payments_sum,
               0 AS expenses_sum
        FROM payments p
        WHERE 1=1 $pDel
        GROUP BY LOWER(TRIM(p.method))

        UNION ALL

        SELECT LOWER(TRIM(e.method)) AS method,
               0 AS payments_sum,
               SUM(e.amount) AS expenses_sum
        FROM expenses e
        WHERE e.deleted_at IS NULL
        GROUP BY LOWER(TRIM(e.method))
      ) t
      GROUP BY method
    ";
    $pdo->exec($sql);
  } catch (Throwable $e) { /* ignore */ }
}
ensureMethodBalanceView($pdo);

/* -------- Auto-schema: expenses -------- */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS expenses (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      expense_at DATETIME NOT NULL,
      method VARCHAR(32) NOT NULL,
      amount DECIMAL(18,2) NOT NULL,
      payee VARCHAR(255) NOT NULL,
      note TEXT NULL,
      order_no VARCHAR(64) NULL,
      payee_code VARCHAR(32) NULL,
      doctor_id INT NULL,
      created_by INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM expenses") as $c) { $cols[strtolower($c['Field'])] = true; }
  $add = function(string $sql) use ($pdo){ try { $pdo->exec($sql); } catch(Throwable $e){} };
  $want = [
    'expense_at' => "ALTER TABLE expenses ADD COLUMN expense_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    'method'     => "ALTER TABLE expenses ADD COLUMN method VARCHAR(32) NOT NULL DEFAULT 'cash'",
    'amount'     => "ALTER TABLE expenses ADD COLUMN amount DECIMAL(18,2) NOT NULL DEFAULT 0",
    'payee'      => "ALTER TABLE expenses ADD COLUMN payee VARCHAR(255) NOT NULL DEFAULT ''",
    'note'       => "ALTER TABLE expenses ADD COLUMN note TEXT NULL",
    'order_no'   => "ALTER TABLE expenses ADD COLUMN order_no VARCHAR(64) NULL",
    'payee_code' => "ALTER TABLE expenses ADD COLUMN payee_code VARCHAR(32) NULL",
    'doctor_id'  => "ALTER TABLE expenses ADD COLUMN doctor_id INT NULL",
    'created_by' => "ALTER TABLE expenses ADD COLUMN created_by INT NOT NULL DEFAULT 0",
    'created_at' => "ALTER TABLE expenses ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'deleted_at' => "ALTER TABLE expenses ADD COLUMN deleted_at DATETIME NULL",
  ];
  foreach ($want as $name=>$sql) { if (!isset($cols[$name])) { $add($sql); } }
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expenses_expense_at ON expenses(expense_at)"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expenses_method ON expenses(method)"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expenses_doctor ON expenses(doctor_id)"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expenses_deleted ON expenses(deleted_at)"); } catch (Throwable $e) {}
} catch (Throwable $e) {
  if (APP_DEBUG) error_log('Auto-schema error: '.$e->getMessage());
}

/* -------- Doctors -------- */
function loadDoctors(PDO $pdo): array {
  try {
    $sql = "SELECT id,
              TRIM(CONCAT(NULLIF(first_name,''),' ',NULLIF(last_name,''))) AS name_core,
              NULLIF(father_name,'') AS father
            FROM doctors
            WHERE status='აქტიური'
              AND (first_name<>'' OR last_name<>'')
            ORDER BY last_name, first_name, id";
    $st = $pdo->query($sql);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $name = trim($r['name_core'] ?? '');
      if ($r['father']) $name .= ' ('.$r['father'].')';
      if ($name==='') continue;
      $out[] = ['id'=>(int)$r['id'], 'name'=>$name];
    }
    return $out;
  } catch (Throwable $e) { return []; }
}
function getDoctorName(PDO $pdo, int $id): ?string {
  try {
    $st = $pdo->prepare("SELECT TRIM(CONCAT(NULLIF(first_name,''),' ',NULLIF(last_name,''))) AS nm,
                                NULLIF(father_name,'') AS father
                         FROM doctors WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $nm = trim((string)($r['nm'] ?? ''));
    if ($nm==='') return null;
    if (!empty($r['father'])) $nm += ' ('.$r['father'].')';
    return $nm;
  } catch (Throwable $e) { return null; }
}

/**
 * Balance for a method:
 *   available = SUM(payments) - SUM(expenses)
 */
function availableFor(PDO $pdo, string $method, array $OPENING): array {
  $canon = canonicalMethod($method);

  // Try view first
  try {
    $st = $pdo->query("SELECT LOWER(TRIM(method)) AS method, payments_sum, expenses_sum FROM v_method_balance");
    $inc = 0.0; $exp = 0.0; $any = false;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      if (canonicalMethod((string)$r['method']) !== $canon) continue;
      $inc += (float)$r['payments_sum'];
      $exp += (float)$r['expenses_sum'];
      $any = true;
    }
    if ($any) return [round($inc - $exp, 2), true];
    return [0.0, true];
  } catch (Throwable $e) { /* fall back */ }

  // Fallback direct sums
  $aliases = array_map(fn($x)=>mb_strtolower(trim($x),'UTF-8'), methodAliases($canon));
  if (!$aliases) $aliases = [$canon];
  $in = implode(',', array_fill(0, count($aliases), '?'));

  $hasP = tableExists($pdo,'payments');
  $hasE = tableExists($pdo,'expenses');

  $income = 0.0; $expense = 0.0;
  if ($hasP) {
    try {
      $cond = "LOWER(TRIM(method)) IN ($in)";
      if (columnExists($pdo,'payments','deleted_at')) { $cond .= " AND deleted_at IS NULL"; }
      $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE $cond");
      $st->execute($aliases);
      $income = (float)$st->fetchColumn();
    } catch (Throwable $e) {}
  }
  if ($hasE) {
    try {
      $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses
                           WHERE deleted_at IS NULL AND LOWER(TRIM(method)) IN ($in)");
      $st->execute($aliases);
      $expense = (float)$st->fetchColumn();
    } catch (Throwable $e) {}
  }

  return [round($income - $expense, 2), ($hasP || $hasE)];
}

/* -------- Date parsing -------- */
function parseDateFlexibleDT($s){
  $s = trim((string)$s);
  if ($s==='') return '';

  $x = preg_replace('/\s+/',' ', $s);

  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})\s*([ap]m)$/i', $x, $m)) {
    $mo=(int)$m[1]; $d=(int)$m[2]; $y=(int)$m[3];
    $hh=(int)$m[4]; $mm=(int)$m[5]; $ampm=strtolower($m[6]);
    if ($hh===12) $hh = 0;
    if ($ampm==='pm') $hh += 12;
    if (checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d %02d:%02d:00',$y,$mo,$d,$hh,$mm);
    return '';
  }

  $x = str_replace(['/', 'T'], ['-', ' '], $x);

  if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})(?:\s+(\d{2}):(\d{2}))?$/', $x, $m)) {
    $d=(int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3]; $hh=(int)($m[4]??0); $mm=(int)($m[5]??0);
    if (checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d %02d:%02d:00',$y,$mo,$d,$hh,$mm);
    return '';
  }

  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?$/', $x, $m)) {
    $y=(int)$m[1]; $mo=(int)$m[2]; $d=(int)$m[3]; $hh=(int)($m[4]??0); $mm=(int)($m[5]??0);
    if (checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d %02d:%02d:00',$y,$mo,$d,$hh,$mm);
    return '';
  }

  return '';
}

/* -------- Expense get -------- */
function getExpense(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND deleted_at IS NULL");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

/* -------- Sorting whitelist -------- */
function sanitizeSort(string $by=null, string $dir=null): array {
  $map = [
    'expense_at' => 'expense_at',
    'method'     => 'method',
    'payee'      => 'payee',
    'amount'     => 'amount',
    'order_no'   => 'order_no',
    'id'         => 'id'
  ];
  $by = strtolower((string)$by);
  $dir = strtolower((string)$dir);
  $by = $map[$by] ?? 'expense_at';
  $dir = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';
  return [$by,$dir];
}

/* ===================== ACTIONS ===================== */
$action = $_REQUEST['action'] ?? '';

/* Method balances via SQL view (MERGED BOG) */
if ($action === 'method_balances') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $acc = [];
    $st = $pdo->query("SELECT method, payments_sum, expenses_sum, balance FROM v_method_balance");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $canon = canonicalMethod((string)$r['method']);
      if (!isset($acc[$canon])) $acc[$canon] = ['payments'=>0.0,'expenses'=>0.0];
      $acc[$canon]['payments'] += (float)$r['payments_sum'];
      $acc[$canon]['expenses'] += (float)$r['expenses_sum'];
    }
    $rows = [];
    foreach ($acc as $canon => $tot) {
      $rows[] = [
        'method'   => mapMethodLabel($canon),
        'payments' => round($tot['payments'],2),
        'expenses' => round($tot['expenses'],2),
        'balance'  => round($tot['payments'] - $tot['expenses'],2),
      ];
    }
    echo json_encode(['status'=>'ok','rows'=>$rows]);
  } catch (Throwable $e) {
    echo json_encode(['status'=>'empty','message'=>'view missing or inaccessible']);
  }
  exit;
}

/* INSERT */
if ($action === 'insert') {
  requireCsrf();
  header('Content-Type: application/json; charset=utf-8');
  try{
    $dt_raw    = $_POST['tanp_dat'] ?? '';
    $giver_id  = trim((string)($_POST['tanp_cmpto1'] ?? ''));
    $payeeSel  = trim((string)($_POST['tanp_cmpto'] ?? ''));
    $isnew     = !empty($_POST['tanp_isnew']);
    $payee_new = trim((string)($_POST['tanp_mimarea'] ?? ''));
    $amountRaw = (string)($_POST['tanp_amo'] ?? '');
    $note      = trim((string)($_POST['tanp_saud'] ?? ''));
    $order_no  = trim((string)($_POST['tanp_order'] ?? ''));

    $expense_at = parseDateFlexibleDT($dt_raw);
    if ($expense_at==='') throw new Exception('არასწორი თარიღი');

    if ($giver_id==='' || !isset($GIVERS[$giver_id])) throw new Exception('აირჩიე გამცემი');
    $method = strtolower($GIVERS[$giver_id]['method']);
    if (!in_array($method, METHOD_WHITELIST, true)) throw new Exception('გადახდის ტიპი დაუშვებელია');

    $payee = ''; $payee_code = null; $doctor_id = null;
    if ($isnew) {
      if ($payee_new==='') throw new Exception('მიმღების ტექსტი სავალდებულოა');
      $payee = $payee_new; $payee_code = 'other';
    } else {
      if ($payeeSel==='') throw new Exception('აირჩიე მიმღები');
      if (in_array($payeeSel, ['cash','bog','founder','salary','expense','transfer','bank'], true)) {
        $map = [
          'cash'=>'სალარო','bog'=>'BOG','founder'=>'დამფუძნებელი',
          'salary'=>'ხელფასი','expense'=>'ხარჯი','transfer'=>'გადმორიცხვა','bank'=>'ბანკი'
        ];
        $payee = $map[$payeeSel] ?? $payeeSel;
        $payee_code = $payeeSel;
      } elseif (str_starts_with($payeeSel, 'doc:')) {
        $docId = (int)substr($payeeSel,4);
        $name = getDoctorName($pdo, $docId);
        if (!$name) throw new Exception('ექიმი ვერ მოიძებნა');
        $payee = $name; $payee_code = 'doctor'; $doctor_id = $docId;
      } else { throw new Exception('მიმღების მნიშვნელობა არასწორია'); }
    }

    $amountNorm = str_replace(',', '.', preg_replace('/[^\d,\.\-]/','',$amountRaw));
    if ($amountNorm==='' || !is_numeric($amountNorm)) throw new Exception('თანხა უნდა იყოს რიცხვი');
    $amount = round((float)$amountNorm, 2);
    if ($amount <= 0) throw new Exception('თანხა უნდა იყოს დადებითი');

    list($available, $reliable) = availableFor($pdo, $method, $OPENING);
    if ($reliable && noNegEnforced($method) && $amount > $available) {
      $need = $amount - $available;
      $lbl = mapMethodLabel($method);
      throw new Exception("არასაკმარისი ნაშთი ({$lbl}). ხელმისაწვდომია: ".money($available).". საჭიროა მინიმუმ ".money($need)." დამატება.");
    }

    $st=$pdo->prepare("
      INSERT INTO expenses (expense_at, method, amount, payee, note, order_no, payee_code, doctor_id, created_by)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $st->execute([
      $expense_at, $method, $amount, $payee,
      ($note!==''?$note:null), ($order_no!==''?$order_no:null),
      ($payee_code!==null?$payee_code:null),
      ($doctor_id!==null?$doctor_id:null),
      resolveCreatedBy($pdo)
    ]);

    echo json_encode(['status'=>'ok','message'=>'დამატებულია']);
  } catch(Throwable $e){
    // Always 200 to avoid console 400 noise; send JSON error
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'შეცდომა']);
  }
  exit;
}

/* DELETE (soft) */
if ($action === 'delete') {
  requireCsrf();
  header('Content-Type: application/json; charset=utf-8');
  try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) throw new Exception('არასწორი ID');
    $st = $pdo->prepare("UPDATE expenses SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL");
    $st->execute([$id]);
    echo json_encode(['status'=>'ok']);
  } catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'შეცდომა']);
  }
  exit;
}

/* UNDELETE (restore) */
if ($action === 'undelete') {
  requireCsrf();
  header('Content-Type: application/json; charset=utf-8');
  try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) throw new Exception('არასწორი ID');
    $st = $pdo->prepare("UPDATE expenses SET deleted_at=NULL WHERE id=?");
    $st->execute([$id]);
    echo json_encode(['status'=>'ok']);
  } catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'შეცდომა']);
  }
  exit;
}

/* FETCH (for edit modal) */
if ($action === 'fetch') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id<=0) throw new Exception('არასწორი ID');
    $row = getExpense($pdo, $id);
    if (!$row) throw new Exception('ჩანაწერი ვერ მოიძებნა');

    $map = ['cash'=>'34','bog'=>'825','founder'=>'446'];
    $giver_id = $map[strtolower($row['method'])] ?? '34';

    echo json_encode(['status'=>'ok','row'=>$row,'giver_id'=>$giver_id]);
  } catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'შეცდომა']);
  }
  exit;
}

/* UPDATE */
if ($action === 'update') {
  requireCsrf();
  header('Content-Type: application/json; charset=utf-8');
  try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) throw new Exception('არასწორი ID');

    $cur = getExpense($pdo, $id);
    if (!$cur) throw new Exception('ჩანაწერი ვერ მოიძებნა');

    $dt_raw    = $_POST['ed_dat'] ?? '';
    $giver_id  = trim((string)($_POST['ed_cmpto1'] ?? ''));
    $isnew     = !empty($_POST['ed_isnew']);
    $payeeSel  = trim((string)($_POST['ed_cmpto'] ?? ''));
    $payee_new = trim((string)($_POST['ed_mimarea'] ?? ''));
    $amountRaw = (string)($_POST['ed_amo'] ?? '');
    $note      = trim((string)($_POST['ed_saud'] ?? ''));
    $order_no  = trim((string)($_POST['ed_order'] ?? ''));

    $expense_at = parseDateFlexibleDT($dt_raw);
    if ($expense_at==='') throw new Exception('არასწორი თარიღი');

    if ($giver_id==='' || !isset($GIVERS[$giver_id])) throw new Exception('აირჩიე გამცემი');
    $methodNew = strtolower($GIVERS[$giver_id]['method']);
    if (!in_array($methodNew, METHOD_WHITELIST, true)) throw new Exception('გადახდის ტიპი დაუშვებელია');

    $payee = ''; $payee_code = null; $doctor_id = null;
    if ($isnew) {
      if ($payee_new==='') throw new Exception('მიმღების ტექსტი სავალდებულოა');
      $payee = $payee_new; $payee_code = 'other';
    } else {
      if ($payeeSel==='') throw new Exception('აირჩიე მიმღები');
      if (in_array($payeeSel, ['cash','bog','founder','salary','expense','transfer','bank'], true)) {
        $mapP = [
          'cash'=>'სალარო','bog'=>'BOG','founder'=>'დამფუძნებელი',
          'salary'=>'ხელფასი','expense'=>'ხარჯი','transfer'=>'გადმორიცხვა','bank'=>'ბანკი'
        ];
        $payee = $mapP[$payeeSel] ?? $payeeSel;
        $payee_code = $payeeSel;
      } elseif (str_starts_with($payeeSel, 'doc:')) {
        $docId = (int)substr($payeeSel,4);
        $name = getDoctorName($pdo, $docId);
        if (!$name) throw new Exception('ექიმი ვერ მოიძებნა');
        $payee = $name; $payee_code = 'doctor'; $doctor_id = $docId;
      } else { throw new Exception('მიმღების მნიშვნელობა არასწორია'); }
    }

    $amountNorm = str_replace(',', '.', preg_replace('/[^\d,\.\-]/','',$amountRaw));
    if ($amountNorm==='' || !is_numeric($amountNorm)) throw new Exception('თანხა უნდა იყოს რიცხვი');
    $amountNew = round((float)$amountNorm, 2);
    if ($amountNew <= 0) throw new Exception('თანხა უნდა იყოს დადებითი');

    $methodOld = strtolower($cur['method']);
    $amountOld = (float)$cur['amount'];

    list($availableNew, $reliable) = availableFor($pdo, $methodNew, $OPENING);
    $allowed = ($methodNew === $methodOld) ? ($availableNew + $amountOld) : $availableNew;

    if ($reliable && noNegEnforced($methodNew) && $amountNew > $allowed) {
      $need = $amountNew - $allowed;
      $lbl = mapMethodLabel($methodNew);
      throw new Exception("არასაკმარისი ნაშთი ({$lbl}). ხელმისაწვდომია: ".money($allowed).". საჭიროა მინიმუმ ".money($need)." დამატება.");
    }

    $st = $pdo->prepare("
      UPDATE expenses
      SET expense_at=?, method=?, amount=?, payee=?, note=?, order_no=?, payee_code=?, doctor_id=?
      WHERE id=? AND deleted_at IS NULL
    ");
    $st->execute([
      $expense_at, $methodNew, $amountNew, $payee,
      ($note!==''?$note:null), ($order_no!==''?$order_no:null),
      ($payee_code!==null?$payee_code:null),
      ($doctor_id!==null?$doctor_id:null),
      $id
    ]);

    echo json_encode(['status'=>'ok']);
  } catch (Throwable $e) {
    // *** Fix: don't send 400; return 200 with JSON so the console doesn't show a network error
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'შეცდომა']);
  }
  exit;
}

/* SEARCH (table) */
if ($action === 'search') {
  header('Content-Type: application/json; charset=utf-8');
  try{
    $page   = (int)($_GET['page'] ?? 1); if ($page < 1) $page = 1;
    $psize  = (int)($_GET['pagesize'] ?? PAGE_SIZE_DEFAULT);
    if ($psize < 1) $psize = PAGE_SIZE_DEFAULT;
    if ($psize > PAGE_SIZE_MAX) $psize = PAGE_SIZE_MAX;
    $offset = ($page - 1) * $psize;

    $sort_by  = $_GET['sort_by'] ?? 'expense_at';
    $sort_dir = $_GET['sort_dir'] ?? 'desc';
    [$sort_by, $sort_dir] = sanitizeSort($sort_by, $sort_dir);

    $d1       = parseDateFlexibleDT($_GET['tanfl_dat1'] ?? '');
    $d2       = parseDateFlexibleDT($_GET['tanfl_dat2'] ?? '');
    $giver_id = trim((string)($_GET['tanfl_cmpto1'] ?? ''));
    $payeeSel = trim((string)($_GET['tanfl_cmpto'] ?? ''));
    $docno    = trim((string)($_GET['tanfl_docno'] ?? ''));

    $where=["deleted_at IS NULL"]; $args=[];
    if ($d1!==''){ $where[]="expense_at >= ?"; $args[]=$d1; }
    if ($d2!==''){ $where[]="expense_at <= ?"; $args[]=$d2; }
    if ($giver_id!=='' && isset($GIVERS[$giver_id])){
      $where[]="LOWER(method)=?"; $args[]= strtolower($GIVERS[$giver_id]['method']);
    }
    if ($payeeSel!=='') {
      if (in_array($payeeSel, ['cash','bog','founder','salary','expense','transfer','bank'], true)) {
        $map = [
          'cash'=>'სალარო','bog'=>'BOG','founder'=>'დამფუძნებელი',
          'salary'=>'ხელფასი','expense'=>'ხარჯი','transfer'=>'გადმორიცხვა','bank'=>'ბანკი'
        ];
        $where[] = "payee LIKE ?"; $args[] = like($map[$payeeSel] ?? $payeeSel);
      } elseif (str_starts_with($payeeSel,'doc:')) {
        $docId = (int)substr($payeeSel,4);
        $where[]="(payee_code='doctor' AND doctor_id=?)"; $args[]= $docId;
      }
    }
    if ($docno!==''){ $where[]="order_no LIKE ?"; $args[]= like($docno); }

    $wsql = 'WHERE '.implode(' AND ',$where);

    $stc = $pdo->prepare("SELECT COUNT(*) FROM expenses $wsql");
    $stc->execute($args);
    $total = (int)$stc->fetchColumn();

    $sts = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses $wsql");
    $sts->execute($args);
    $sumAll = (float)$sts->fetchColumn();

    $sql = "SELECT id, expense_at, method, amount, payee, note, order_no, payee_code, doctor_id
            FROM expenses $wsql
            ORDER BY $sort_by $sort_dir, id DESC
            LIMIT $psize OFFSET $offset";
    $st=$pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $htmlRows = '';
    foreach($rows as $r){
      $who = $r['payee'];
      if (($r['payee_code'] ?? '') === 'doctor' && !empty($r['doctor_id'])) {
        $who .= ' • ID: '.(int)$r['doctor_id'];
      }
      $htmlRows .= '<tr id="'.e($r['id']).'">'
        .'<td>'.e(date('d-m-Y H:i', strtotime($r['expense_at']))).'</td>'
        .'<td>'.e(mapMethodLabel($r['method'])).'</td>'
        .'<td>'.e($who).'</td>'
        .'<td class="right">'.e(money($r['amount'])).'</td>'
        .'<td>'.e($r['note'] ?? '').'</td>'
        .'<td>'.e($r['order_no'] ?? '').'</td>'
        .'<td>გასავალი</td>'
        .'<td class="center"><input class="zu" type="checkbox" aria-label="აირჩიე ბეჭდვისთვის"></td>'
        .'<td>'
          .'<div class="del" role="button" tabindex="0" data-id="'.e($r['id']).'" title="წაშლა"></div>'
          .'<div class="det iz" role="button" tabindex="0" data-id="'.e($r['id']).'" title="რედაქტირება"></div>'
        .'</td>'
        .'</tr>';
    }
    $pages = max(1, (int)ceil($total / $psize));
    $pager = '<div class="pager__inner">გვერდი '.e($page).' / '.e($pages).' • ჩანაწერი: '.e($total).' • ჯამი: <b>'.e(money($sumAll)).'</b></div>';
    $sumRow = '<tr class="filtrtr sticky-sum"><td></td><td></td><td class="right"><b>სულ:</b></td><td class="right"><b>'.e(money($sumAll)).'</b></td><td></td><td></td><td></td><td></td><td></td></tr>';

    echo json_encode([
      'status'=>'ok',
      'html'=> ($htmlRows ?: '<tr><td colspan="9" class="center">ჩანაწერი არ არის</td></tr>').$sumRow,
      'sum'=>$sumAll,
      'total'=>$total,
      'page'=>$page,
      'pages'=>$pages,
      'pager'=>$pager,
    ]);
  } catch(Throwable $e){
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'სერვერის შეცდომა']);
  }
  exit;
}

/* Live balance */
if ($action === 'balance_now') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $method = canonicalMethod((string)($_GET['method'] ?? 'cash'));
    if ($method==='') $method='cash';
    list($available,) = availableFor($pdo, $method, $OPENING);
    echo json_encode(['status'=>'ok','method'=>$method,'label'=>mapMethodLabel($method),'available'=>$available]);
  } catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=> APP_DEBUG ? $e->getMessage() : 'სერვერის შეცდომა']);
  }
  exit;
}

/* Excel export (HTML table -> .xls) */
if ($action === 'cexcell2v') {
  $d1 = parseDateFlexibleDT($_GET['tanfl_dat1'] ?? '');
  $d2 = parseDateFlexibleDT($_GET['tanfl_dat2'] ?? '');
  $giver_id = trim((string)($_GET['tanfl_cmpto1'] ?? ''));
  $payeeSel = trim((string)($_GET['tanfl_cmpto'] ?? ''));
  $docno    = trim((string)($_GET['tanfl_docno'] ?? ''));

  $where=["deleted_at IS NULL"]; $args=[];
  if ($d1!==''){ $where[]="expense_at >= ?"; $args[]=$d1; }
  if ($d2!==''){ $where[]="expense_at <= ?"; $args[]=$d2; }
  if ($giver_id!=='' && isset($GIVERS[$giver_id])){ $where[]="LOWER(method)=?"; $args[]= strtolower($GIVERS[$giver_id]['method']); }

  if ($payeeSel!=='') {
    if (in_array($payeeSel, ['cash','bog','founder','salary','expense','transfer','bank'], true)) {
      $map = [
        'cash'=>'სალარო','bog'=>'BOG','founder'=>'დამფუძნებელი',
        'salary'=>'ხელფასი','expense'=>'ხარჯი','transfer'=>'გადმორიცხვა','bank'=>'ბანკი'
      ];
      $where[] = "payee LIKE ?"; $args[] = like($map[$payeeSel] ?? $payeeSel);
    } elseif (str_starts_with($payeeSel,'doc:')) {
      $docId = (int)substr($payeeSel,4);
      $where[]="(payee_code='doctor' AND doctor_id=?)"; $args[]= $docId;
    }
  }
  if ($docno!==''){ $where[]="order_no LIKE ?"; $args[]= like($docno); }
  $wsql = 'WHERE '.implode(' AND ',$where);

  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="expenses_'.date('Ymd_His').'.xls"');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $ee = fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');

  $sql = "SELECT expense_at, method, amount, payee, note, order_no FROM expenses $wsql ORDER BY expense_at DESC, id DESC";
  try{
    $st=$pdo->prepare($sql); $st->execute($args);
    $sum=0.0;
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body><table border="1" cellspacing="0" cellpadding="3"><tr>'.
         '<th>თარიღი</th><th>ტიპი</th><th>მიმღები</th><th>თანხა</th><th>შენიშვნა</th><th>დოკ. #</th></tr>';
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $sum += (float)$r['amount'];
      echo '<tr>'.
        '<td>'.$ee(date('Y-m-d H:i', strtotime($r['expense_at']))).'</td>'.
        '<td>'.$ee(mapMethodLabel($r['method'])).'</td>'.
        '<td>'.$ee($r['payee'] ?? '').'</td>'.
        '<td style="mso-number-format:\'#,##0.00\'; text-align:right">'.$ee(money($r['amount'] ?? 0)).'</td>'.
        '<td>'.$ee($r['note'] ?? '').'</td>'.
        '<td>'.$ee($r['order_no'] ?? '').'</td>'.
      '</tr>';
    }
    echo '<tr><td colspan="3" style="text-align:right"><b>სულ:</b></td><td style="mso-number-format:\'#,##0.00\'; text-align:right"><b>'.$ee(money($sum)).'</b></td><td colspan="2"></td></tr>';
    echo '</table></body></html>';
  }catch(Throwable $ex){
    if(APP_DEBUG) echo '<div>'.$ee($ex->getMessage()).'</div>';
  }
  exit;
}

/* CSV export */
if ($action === 'csv_export') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="expenses_'.date('Ymd_His').'.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['datetime','method','payee','amount','note','order_no']);
  $d1 = parseDateFlexibleDT($_GET['tanfl_dat1'] ?? '');
  $d2 = parseDateFlexibleDT($_GET['tanfl_dat2'] ?? '');
  $where=["deleted_at IS NULL"]; $args=[];
  if ($d1!==''){ $where[]="expense_at >= ?"; $args[]=$d1; }
  if ($d2!==''){ $where[]="expense_at <= ?"; $args[]=$d2; }
  $wsql = 'WHERE '.implode(' AND ',$where);
  $sql = "SELECT expense_at, method, amount, payee, note, order_no FROM expenses $wsql ORDER BY expense_at DESC, id DESC";
  $st=$pdo->prepare($sql); $st->execute($args);
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out, [
      date('Y-m-d H:i', strtotime($r['expense_at'])),
      mapMethodLabel($r['method']),
      $r['payee'],
      number_format((float)$r['amount'],2,'.',''),
      preg_replace("/\r\n|\n|\r/", ' ', (string)($r['note'] ?? '')),
      (string)($r['order_no'] ?? '')
    ]);
  }
  fclose($out);
  exit;
}

/* Print single order */
if ($action === 'cpdf') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0){ echo 'არასწორი ID'; exit; }
  $st=$pdo->prepare("SELECT expense_at, method, amount, payee, note, order_no FROM expenses WHERE id=? AND deleted_at IS NULL");
  $st->execute([$id]);
  $r=$st->fetch(PDO::FETCH_ASSOC);
  if(!$r){ echo 'ვერ მოიძებნა'; exit; }
  ?>
  <!doctype html>
  <html lang="ka"><head>
  <meta charset="utf-8"><title>Expense #<?=e($id)?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px}
    .card{max-width:640px;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:0 auto}
    .row{display:flex;justify-content:space-between;margin:6px 0}
    .lbl{color:#6b7280}
    @media print{ body{padding:0} .card{border:0} }
  </style>
  </head><body onload="window.print()">
  <div class="card">
    <h2>გასავლის ორდერი #<?=e($id)?></h2>
    <div class="row"><div class="lbl">თარიღი:</div><div><?=e(date('Y-m-d H:i',strtotime($r['expense_at'])))?></div></div>
    <div class="row"><div class="lbl">ტიპი:</div><div><?=e(mapMethodLabel($r['method']))?></div></div>
    <div class="row"><div class="lbl">მიმღები:</div><div><?=e($r['payee'])?></div></div>
    <div class="row"><div class="lbl">თანხა:</div><div><?=e(money($r['amount']))?></div></div>
    <div class="row"><div class="lbl">დოკ. #:</div><div><?=e($r['order_no'] ?? '')?></div></div>
    <div class="row"><div class="lbl">საფუძველი:</div><div><?=e($r['note'] ?? '')?></div></div>
  </div>
  </body></html>
  <?php
  exit;
}

/* ===================== PAGE RENDER ===================== */
$doctors = loadDoctors($pdo);
function renderPayeeOptions(array $doctors): string {
  $html = '<option value=""></option>';
  $html .= '<option value="cash">სალარო</option>';
  $html .= '<option value="bog">BOG</option>';
  $html .= '<option value="founder">დამფუძნებელი</option>';
  $html .= '<option value="transfer">გადმორიცხვა</option>';
  $html .= '<option value="bank">ბანკი</option>';
  $html .= '<option value="salary">ხელფასი</option>';
  $html .= '<option value="expense">ხარჯი</option>';
  if ($doctors) {
    $html .= '<optgroup label="ექიმები">';
    foreach($doctors as $d){
      $html .= '<option value="doc:'.e($d['id']).'">'.e($d['name']).'</option>';
    }
    $html .= '</optgroup>';
  }
  return $html;
}
$payeeOptions = renderPayeeOptions($doctors);
$csrf = e($_SESSION['csrf'] ?? '');
?>
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<title>გასავლები</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?=$csrf?>">

<!-- Google Fonts - Noto Sans Georgian -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/preclinic-theme.css">

<style>
/* ---- Styles ---- */
:root{
  --bg:#f6f8fb;--surface:#ffffff;--text:#0f172a;--muted:#6b7280;--brand:#21c1a6;--brand-700:#0bb192;--brand-50:#eefaf6;
  --stroke:#e5e7eb;--shadow:0 6px 18px rgba(0,0,0,.06);--warn:#f59e0b;--danger:#ef4444;--success:#10b981;
  --ring:0 0 0 4px rgba(33,193,166,.15);--r-xs:7px;--r-sm:8px;--r-md:10px;--r-lg:12px;--r-xl:14px;
  --mono:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;--sans:"Noto Sans Georgian",sans-serif;
  --thead-bg:#f1fbf8;--row-hover:#f7fffd;--t-fast:120ms;--t:180ms
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:var(--sans);background:var(--bg);color:var(--text)}
.topbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:var(--brand);color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.brand{display:flex;gap:10px;font-weight:800;align-items:center}.brand .dot{width:10px;height:10px;border-radius:50%;background:#fff}
.container{max-width:1750px;margin:20px auto;padding:0 18px}
.tabs{list-style:none;display:flex;gap:6px;padding-left:0;margin:0 0 14px;border-bottom:2px solid #ddd}
.tabs a{padding:10px 18px;background:var(--brand);color:#fff;text-decoration:none;border-top-left-radius:7px;border-top-right-radius:7px}
.tabs a.active,.tabs a:hover{background:#fff;color:var(--brand)}
.subtabswrap{max-width:1750px;margin:0 auto 6px;padding:0 24px}
.subtabs{list-style:none;display:flex;gap:6px;margin:0 0 12px;padding:0;border-bottom:2px solid #e6e6e6}
.subtabs a{display:inline-block;padding:8px 14px;text-decoration:none;border-top-left-radius:8px;border-top-right-radius:8px;background:var(--brand);color:#fff;font-weight:600}
.subtabs a:hover,.subtabs a.active{background:#fff;color:var(--brand);border:1px solid #cfeee8;border-bottom-color:#fff}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.card .head{position:sticky;top:56px;background:#fff;padding:14px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;flex-wrap:wrap;z-index:5}
.head .title{font-size:16px;font-weight:800;color:#0f172a;flex:1}
.card .body{padding:16px}
input[type=text],select,button,input[type=date]{font-size:14px}
input[type=text],select,input[type=date],input[type=datetime-local],textarea{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;outline:none;transition:box-shadow .12s,border-color .12s}
input:focus,select:focus,textarea:focus{border-color:#0bb192;box-shadow:0 0 0 4px rgba(33,193,166,.15)}
textarea{min-height:38px;resize:vertical}
label{font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;display:block}
.btn{padding:10px 14px;border-radius:12px;border:1px solid #0bb192;background:#10b981;color:#fff;font-weight:700;cursor:pointer}
.btn.warn{background:#f59e0b}.btn.ghost{background:#fff;color:#0bb192;border-color:#9adfd1}.btn.danger{background:#ef4444}
.table-scroll{overflow:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.Ctable{width:100%;border-collapse:collapse}
.Ctable th,.Ctable td{border-bottom:1px solid #eef2f7;padding:10px 12px;vertical-align:middle;white-space:nowrap}
.Ctable thead th{font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;text-align:left;background:#f1fbf8;position:sticky;top:0;cursor:pointer}
.Ctable thead th[data-sortable="true"]:after{content:" ⇅";opacity:.45;font-weight:700}
.Ctable thead th.sorted-asc:after{content:" ↑";opacity:.9}
.Ctable thead th.sorted-desc:after{content:" ↓";opacity:.9}
.Ctable tbody tr:hover{background:#f7fffd}
.sticky-sum{position:sticky;bottom:0;background:#fff;box-shadow:0 -1px 0 #e5e7eb}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.right{text-align:right}.center{text-align:center}
.print,.excel,.csv,.del,.det{display:inline-block;width:18px;height:18px;border-radius:6px;background:#e5e7eb}
.print{background:linear-gradient(180deg,#4b5563,#334155)}
.excel{background:linear-gradient(180deg,#2f855a,#065f46)}
.csv{background:linear-gradient(180deg,#2563eb,#1e40af)}
.del{background:linear-gradient(180deg,#ef4444,#b91c1c)}
.det{background:linear-gradient(180deg,#9ca3af,#6b7280)}
.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#e9f7f3;border:1px solid #d5f1ea;color:#145e50;font-weight:800;font-size:13px}
.muted{color:#0f5132;opacity:.85}
.input-row{display:grid;grid-template-columns:1fr 1fr 2fr 1fr 1.2fr 1.2fr 1fr .6fr .6fr;gap:12px;align-items:end}
.field{display:flex;flex-direction:column}
.input-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.help{font-size:11px;color:#6b7280;margin-top:4px}
.badge{display:inline-block;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:999px;padding:2px 8px;font-size:11px}
.tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.bad{color:#ef4444}
.good{color:#10b981}
.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}.gap-6{gap:6px}.gap-8{gap:8px}.gap-10{gap:10px}.grid{display:grid}.mt-12{margin-top:12px}.w-100{width:100%}
.dp-none{display:none}
.overlayEdit{position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;padding:12px}
.overlayEdit.dp-none{display:none}
.innerfrm{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;width:min(760px,calc(100% - 24px));max-height:80vh;overflow:auto;position:relative}
.innerfrm .nondrg{position:absolute;right:10px;top:6px;font-size:22px;text-decoration:none;color:#6b7280}
.form-note{font-size:12px;color:#64748b}
.toast{position:fixed;right:16px;bottom:16px;max-width:360px;background:#111827;color:#fff;padding:12px 14px;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06);opacity:.96}
.toast.good{background:#065f46}.toast.bad{background:#7f1d1d}.toast.warn{background:#7c2d12}
.spinner{display:none;position:fixed;inset:0;background:rgba(255,255,255,.6);align-items:center;justify-content:center;z-index:50}
.spinner .dot{width:12px;height:12px;border-radius:50%;background:#21c1a6;animation:b 1s infinite alternate}
.spinner .dot:nth-child(2){animation-delay:.15s}.spinner .dot:nth-child(3){animation-delay:.3s}
@keyframes b{from{transform:scale(.8);opacity:.5}to{transform:scale(1.2);opacity:1}}
@media (max-width:1200px){ .input-row{grid-template-columns:1fr 1fr 2fr 1fr 1fr 1fr .8fr .6fr .6fr} }
@media (max-width:980px){ .input-row{grid-template-columns:1fr;gap:10px} }
@media print{.topbar,.tabs,.subtabswrap,.pager,.tools,#mbCard,.quick-range{display:none!important}body{background:#fff}.card,.table-scroll{border:none;box-shadow:none}.Ctable th,.Ctable td{padding:6px 8px}}
</style>
</head>
<body>

<!-- Spinner -->
<div class="spinner" id="spinner" aria-hidden="true"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>

<!-- HEADER -->
<div class="topbar" role="banner">
  <div class="brand"><span class="dot" aria-hidden="true"></span><span>EHR • ანგარიშები</span></div>
  <div class="flex items-center gap-10">
    <div id="balancePill" class="pill" title="ნაშთი არჩეული გამცემის მიხედვით">
      <span class="muted">ნაშთი</span>
      <span id="balLabel">—</span>:
      <span id="balValue" class="mono">—</span>
    </div>
  </div>
</div>

<div class="container">
  <ul class="tabs" role="tablist" aria-label="მთავარი ტაბები">
    <li><a href="dashboard.php">რეგისტრაცია</a></li>
    <li><a href="patient_hstory.php">პაციენტების მართვა</a></li>
    <li><a href="nomenklatura.php">ნომენკლატურა</a></li>
    <li><a href="expense_add.php" class="active" aria-current="page">გამოსავალი</a></li>
  </ul>
</div>

<div class="subtabswrap container" style="padding-top:0">
  <ul class="subtabs" role="tablist" aria-label="ქვეტაბები">
    <li><a href="angarishebi.php">ანგარიშები</a></li>
    <li><a href="balance.php">ბალანსი</a></li>
    <li><a href="expense_add.php" class="active" aria-current="page">გადახდები</a></li>
  </ul>
</div>

<!-- Method balances card -->
<div class="container" id="mbCard">
  <div class="card">
    <div class="head">
      <div class="title">Method Balances <span class="badge">v_method_balance</span></div>
      <button type="button" class="btn ghost" id="mbRefresh">განახლება</button>
    </div>
    <div class="body">
      <div class="table-scroll">
        <table class="Ctable">
          <thead>
            <tr><th>method</th><th class="right">payments</th><th class="right">expenses</th><th class="right">balance</th></tr>
          </thead>
          <tbody id="mbRows"><tr><td colspan="4" class="center">იტვირთება…</td></tr></tbody>
        </table>
      </div>
      <div class="form-note">ეს ცხრილი ივსება SQL ხედიდან <code>v_method_balance</code>. <b>BOG</b>-ის ყველა სინონიმი ერთიანდება ერთ სტრიქონად.</div>
    </div>
  </div>
</div>

<div style="margin-top:1px;padding-top:15px;" id="hypsub" class="container card">
  <div class="head">
    <div class="title">ახალი გასავალი</div>
  </div>
  <div class="body">

  <!-- INSERT ROW -->
  <div class="input-row inptr2 clac" style="font-size:14px;color:#374151;">
    <div class="field">
      <label for="tanp_dat">თარიღი</label>
      <div class="input-group">
        <input type="text" id="tanp_dat" autocomplete="off" class="date required mono" placeholder="DD-MM-YYYY HH:MM" inputmode="numeric" aria-describedby="dateHelp">
        <input type="datetime-local" id="tanp_dat_native" aria-label="კალენდარი" style="max-width:190px">
        <button type="button" class="btn ghost" onclick="fillNow('tanp_dat')" title="ახლა">ახლა</button>
        <button type="button" class="btn ghost" id="clearFormBtn" title="გასუფთავება">გასუფთავება</button>
      </div>
      <div id="dateHelp" class="help">შეიყვანეთ ფორმატით <span class="mono">DD-MM-YYYY HH:MM</span> ან გამოიყენეთ კალენდარი.</div>
    </div>

    <div class="field">
      <label for="tanp_cmpto1">თანხის გამცემი</label>
      <select id="tanp_cmpto1" class="required">
        <option value=""></option>
        <option value="34">სალარო</option>
        <option value="825">BOG</option>
        <option value="446">დამფუძნებელი</option>
      </select>
      <div class="help">გაცემის წყაროს მიხედვით განახლდება ზედა „ნაშთი“.</div>
    </div>

    <div class="field" style="position:relative">
      <div class="input-group">
        <input type="checkbox" id="tanp_isnew" onclick="toggleNewPayee()" aria-controls="tanp_mimarea tanp_cmpto" aria-label="ახალი მიმღები">
        <label for="tanp_cmpto" style="margin:0">თანხის მიმღები</label>
      </div>
      <select id="tanp_cmpto" style="min-width:260px;"><?= $payeeOptions ?></select>
      <input type="text" id="tanp_mimarea" style="margin-top: 6px; display: none;" placeholder="მიმღების სახელი" autocomplete="off">
      <div class="help">„ახალი მიმღები“ რეჟიმში შეგიძლიათ ტექსტურად მიუთითოთ სახელი.</div>
    </div>

    <div class="field">
      <label for="tanp_amo">თანხა</label>
      <input type="text" id="tanp_amo" class="required mono right" placeholder="0.00" inputmode="decimal" autocomplete="off">
      <div class="help">განყოფისთვის შეგიძლიათ გამოიყენოთ მძიმე (,).</div>
    </div>

    <div class="field">
      <label for="tanp_saud">საფუძველი</label>
      <textarea id="tanp_saud" placeholder="შენიშვნა"></textarea>
    </div>

    <div class="field">
      <label for="tanp_order">დოკ. #</label>
      <input type="text" id="tanp_order" placeholder="# (არასავალდებულო)" autocomplete="off">
    </div>

    <div></div>
    <div></div>

    <div class="field right">
      <button type="button" class="btn" id="tani_insb" title="შენახვა">შენახვა</button>
    </div>
  </div>

  <!-- QUICK RANGES -->
  <div class="input-group quick-range" style="margin:10px 0">
    <span class="badge">ფილტრები</span>
    <button class="btn ghost" data-range="today">დღეს</button>
    <button class="btn ghost" data-range="yesterday">გუშინ</button>
    <button class="btn ghost" data-range="week">ეს კვირა</button>
    <button class="btn ghost" data-range="month">ეს თვე</button>
    <button class="btn ghost" data-range="clear">გასუფთავება</button>
  </div>

  <!-- TABLE -->
  <div class="table-scroll" style="margin-top:14px">
  <table id="sadam_tnamk" class="Ctable" aria-describedby="pagerWrap">
    <thead>
      <tr class="clac">
        <th class="forEdt dttmth" data-field="expense_at" data-sortable="true">თარიღი</th>
        <th class="forSel" data-field="method" data-sortable="true">თანხის გამცემი</th>
        <th class="forSel" data-field="payee" data-sortable="true">თანხის მიმღები</th>
        <th class="forEdt" data-field="amount" data-sortable="true">თანხა</th>
        <th class="forEdt nreq" data-field="note">საფუძველი</th>
        <th class="forEdt" data-field="order_no" data-sortable="true">დოკუმენტის #</th>
        <th>ტიპი</th>
        <th></th>
        <th></th>
      </tr>

      <!-- FILTERS -->
      <tr id="TCb" class="filtrtr clac">
        <td>
          <div class="input-group">
            <div class="w-100">
              <input type="text" id="tanfl_dat1" autocomplete="off" class="date mono" placeholder="DD-MM-YYYY HH:MM" inputmode="numeric" aria-label="საწყისი თარიღი">
            </div>
            <div class="w-100">
              <input type="text" id="tanfl_dat2" autocomplete="off" class="date mono" placeholder="DD-MM-YYYY HH:MM" inputmode="numeric" aria-label="დასრულების თარიღი">
            </div>
          </div>
        </td>
        <td>
          <select id="tanfl_cmpto1" class="filters" style="display:block;">
            <option value=""></option><option value="34">სალარო</option><option value="825">BOG</option><option value="446">დამფუძნებელი</option>
          </select>
        </td>
        <td>
          <select class="filters" id="tanfl_cmpto"><?= $payeeOptions ?></select>
        </td>
        <td></td>
        <td></td>
        <td><input type="text" id="tanfl_docno" placeholder="#" autocomplete="off"></td>
        <td></td>
        <td class="center">
          <a href="javascript:void(0);" class="print" title="ბეჭდვა" onclick="printSelected()" aria-label="ბეჭდვა"></a>
        </td>
        <td>
          <div class="tools">
            <a href="javascript:void(0);" class="excel" title="Excel" onclick="doExport()" aria-label="Excel"></a>
            <a href="javascript:void(0);" class="csv" title="CSV" onclick="doCSV()" aria-label="CSV"></a>
            <button id="tanfl_fltb" class="btn ghost" onclick="doSearch(1)">ძებნა</button>
            <div class="small">გვერდი/რაოდ.: <input id="pagesize" type="number" min="1" max="<?= (int)PAGE_SIZE_MAX ?>" value="<?= (int)PAGE_SIZE_DEFAULT ?>" style="width:64px"></div>
          </div>
        </td>
      </tr>
    </thead>
    <tbody id="rows" aria-live="polite"></tbody>
  </table>
  </div>

  <div id="pagerWrap" class="pager" role="navigation" aria-label="გვერდები" style="padding:10px 0"></div>

  <!-- Edit modal -->
  <div class="overlayEdit modal-container dp-none" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="innerfrm">
      <a href="javascript:void(0);" onclick="closeEditModal()" class="nondrg zdx" aria-label="დახურვა">×</a>
      <div id="inter4"></div>
      <div id="feedErr" class="emHtm bad" style="display:table;margin:10px auto 0;"></div>
      <div id="LoadingImage2" class="lodc" style="display:none;"><div></div></div>
    </div>
  </div>

  </div><!-- .body -->
</div>

<div id="toast" class="toast dp-none" role="status" aria-live="polite"></div>

<script>
/* ===== Utilities ===== */
const PAYEE_OPTIONS_HTML = <?= json_encode($payeeOptions, JSON_UNESCAPED_UNICODE) ?>;
let SORT_BY = 'expense_at', SORT_DIR = 'desc';
let LAST_DELETED_ID = null;

function qs(s,r){return (r||document).querySelector(s);}
function qsa(s,r){return Array.from((r||document).querySelectorAll(s));}
function showSpinner(on){const sp=qs('#spinner'); if(!sp) return; sp.style.display = on?'flex':'none';}
function toast(msg,type='good'){const t=qs('#toast'); if(!t) return; t.innerHTML=msg; t.className='toast '+type; t.classList.remove('dp-none'); setTimeout(()=>t.classList.add('dp-none'), 3000);}

function fillNow(id){
  const el=qs('#'+id); if(!el) return;
  const d=new Date();
  const dd=String(d.getDate()).padStart(2,'0');
  const mm=String(d.getMonth()+1).padStart(2,'0');
  const yyyy=d.getFullYear();
  const HH=String(d.getHours()).padStart(2,'0');
  const MM=String(d.getMinutes()).padStart(2,'0');
  el.value = `${dd}-${mm}-${yyyy} ${HH}:${MM}`;
  const native = qs('#'+id+'_native'); if(native){ native.value = `${yyyy}-${mm}-${dd}T${HH}:${MM}`; }
}
function toggleNewPayee(){
  const cb=qs('#tanp_isnew');
  qs('#tanp_mimarea').style.display = cb && cb.checked ? 'inline-block' : 'none';
  qs('#tanp_cmpto').style.display   = cb && cb.checked ? 'none' : 'inline-block';
}
function csrf(){ return (qs('meta[name="csrf-token"]')?.getAttribute('content')) || ''; }

/* Date sync */
function toTextFromNative(nativeValue){
  if(!nativeValue) return '';
  const [datePart,timePart='00:00'] = nativeValue.split('T');
  const [y,m,d] = datePart.split('-');
  return `${d}-${m}-${y} ${timePart.substring(0,5)}`;
}
function toNativeFromText(textValue){
  if(!textValue) return '';
  const s = textValue.trim();

  let m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})\s*([ap]m)$/i);
  if (m){
    let mo = parseInt(m[1],10), d = parseInt(m[2],10), y = parseInt(m[3],10);
    let hh = parseInt(m[4],10), mm = parseInt(m[5],10);
    const ampm = m[6].toLowerCase();
    if (hh === 12) hh = 0;
    if (ampm === 'pm') hh += 12;
    const pad = n => String(n).padStart(2,'0');
    return `${y}-${pad(mo)}-${pad(d)}T${pad(hh)}:${pad(mm)}`;
  }

  const parts = s.split(' ');
  const datePart = parts[0] || '';
  const timePart = (parts[1] || '00:00').substring(0,5);
  const dmY = datePart.split('-');
  if(dmY.length===3){
    const [dd,mm,yyyy] = dmY;
    if(yyyy?.length===4) return `${yyyy}-${mm}-${dd}T${timePart}`;
  }
  return '';
}

function wireNativePicker(textId, nativeId){
  const t = qs('#'+textId);
  const n = qs('#'+nativeId);
  if(!t || !n) return;
  n.addEventListener('change', ()=>{ t.value = toTextFromNative(n.value); });
  t.addEventListener('blur', ()=>{ const v = toNativeFromText(t.value); if(v) n.value = v; });
}
wireNativePicker('tanp_dat','tanp_dat_native');

/* Money helpers + input sanitation */
function moneyFmt(n){ n=Number(n||0); return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
['tanp_amo'].forEach(id=>{
  const el=qs('#'+id);
  if(!el) return;
  el.addEventListener('input', ()=>{ el.value = el.value.replace(/[^\d,\.\-]/g,''); });
  el.addEventListener('blur', ()=>{ const v = parseFloat(el.value.replace(',','.')); if(!isNaN(v)) el.value = v.toFixed(2); });
});

/* ===== Method balances ===== */
function renderMethodBalances(rows){
  const tb = qs('#mbRows');
  if(!tb) return;
  if(!rows || !rows.length){
    tb.innerHTML = '<tr><td colspan="4" class="center">ცარიელი</td></tr>';
    return;
  }
  rows.sort((a,b)=>Math.abs(b.balance||0)-Math.abs(a.balance||0));
  tb.innerHTML = rows.map(r=>{
    const bal = Number(r.balance||0);
    const cls = bal<0 ? 'bad' : 'good';
    return `<tr>
      <td>${escapeHtml(r.method||'')}</td>
      <td class="right mono">${moneyFmt(r.payments||0)}</td>
      <td class="right mono">${moneyFmt(r.expenses||0)}</td>
      <td class="right mono ${cls}">${moneyFmt(bal)}</td>
    </tr>`;
  }).join('');
}
function loadMethodBalances(){
  fetch('?action=method_balances',{credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{ if(j && j.status==='ok'){ renderMethodBalances(j.rows||[]); } else { renderMethodBalances([]); } })
    .catch(()=> renderMethodBalances([]));
}
document.addEventListener('DOMContentLoaded', loadMethodBalances);
qs('#mbRefresh')?.addEventListener('click', loadMethodBalances);

/* ===== Live Balance pill ===== */
function mapGiverToMethod(giverId){
  if(giverId==='34') return 'cash';
  if(giverId==='825') return 'bog';
  if(giverId==='446') return 'founder';
  return 'cash';
}
function refreshBalance(){
  const giverId = (qs('#tanp_cmpto1')?.value || '').trim() || '34';
  const method = mapGiverToMethod(giverId);
  fetch('?action=balance_now&method='+encodeURIComponent(method),{credentials:'same-origin'})
   .then(r=>r.json())
   .then(j=>{
     if(!j || j.status!=='ok') return;
     qs('#balLabel').textContent = j.label || method.toUpperCase();
     qs('#balValue').textContent = moneyFmt(j.available || 0);
   }).catch(()=>{});
}
document.addEventListener('DOMContentLoaded', refreshBalance);
qs('#tanp_cmpto1')?.addEventListener('change', refreshBalance);

/* ===== INSERT ===== */
qs('#tani_insb').addEventListener('click', saveInsert);

async function saveInsert(){
  const giverId = (qs('#tanp_cmpto1')?.value||'').trim();
  const method  = mapGiverToMethod(giverId || '34');
  const amountV = parseFloat((qs('#tanp_amo')?.value||'0').replace(',','.')) || 0;

  try{
    const r = await fetch('?action=balance_now&method='+encodeURIComponent(method),{credentials:'same-origin'});
    const j = await r.json();
    const available = Number(j?.available||0);
    if (amountV > available) {
      const need = (amountV - available).toFixed(2);
      toast(`არასაკმარისი ნაშთი (${j?.label||method.toUpperCase()}). ხელმისაწვდომია: ${moneyFmt(available)}. საჭიროა მინიმუმ ${moneyFmt(need)} დამატება.`, 'bad');
      return;
    }
  }catch(e){}

  const body = new URLSearchParams();
  body.set('action','insert');
  body.set('csrf', csrf());
  body.set('tanp_dat', (qs('#tanp_dat')?.value||'').trim());
  body.set('tanp_cmpto1', (qs('#tanp_cmpto1')?.value||'').trim());
  body.set('tanp_cmpto', (qs('#tanp_cmpto')?.value||'').trim());
  body.set('tanp_isnew', (qs('#tanp_isnew')?.checked ? '1' : ''));
  body.set('tanp_mimarea', (qs('#tanp_mimarea')?.value||'').trim());
  body.set('tanp_amo', (qs('#tanp_amo')?.value||'').trim());
  body.set('tanp_saud', (qs('#tanp_saud')?.value||'').trim());
  body.set('tanp_order', (qs('#tanp_order')?.value||'').trim());

  showSpinner(true);
  fetch('?action=insert', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': csrf()},
    body: body.toString()
  })
  .then(r=>r.json())
  .then(j=>{
    if(!j || j.status!=='ok'){
      toast((j && j.message) ? j.message : 'შეცდომა','bad');
      return;
    }
    clearForm(); fillNow('tanp_dat');
    doSearch(1); refreshBalance(); loadMethodBalances();
    toast('ჩანაწერი დამატებულია','good');
  })
  .catch(()=> toast('ქსელის შეცდომა','bad'))
  .finally(()=>showSpinner(false));
}
function clearForm(){
  ['tanp_cmpto','tanp_mimarea','tanp_amo','tanp_saud','tanp_order'].forEach(id=>{ const el=qs('#'+id); if(el) el.value=''; });
  const cb=qs('#tanp_isnew'); if(cb){ cb.checked=false; toggleNewPayee(); }
}
qs('#clearFormBtn')?.addEventListener('click', ()=>{
  ['tanp_dat','tanp_dat_native'].forEach(id=>{ const el = qs('#'+id); if(el) el.value=''; });
  clearForm();
});

/* ===== DELETE (soft) + undo ===== */
document.addEventListener('click', (ev)=>{
  const d = ev.target.closest('.del');
  if(!d) return;
  const id = d.getAttribute('data-id');
  if(!id) return;
  if(!confirm('წავშალო ჩანაწერი?')) return;
  const body = new URLSearchParams();
  body.set('action','delete');
  body.set('csrf', csrf());
  body.set('id', id);
  showSpinner(true);
  fetch('?action=delete', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': csrf()},
    body: body.toString()
  })
  .then(r=>r.json())
  .then(j=>{
    if(j && j.status==='ok'){
      LAST_DELETED_ID = id;
      doSearch(1); refreshBalance(); loadMethodBalances();
      toast('წაშლილია. დააწკაპუნე აქ დასაბრუნებლად (Undo).', 'warn');
      qs('#toast')?.addEventListener('click', undoDeleteOnce, { once:true });
    } else { toast((j && j.message) ? j.message : 'შეცდომა წაშლისას', 'bad'); }
  })
  .catch(()=> toast('ქსელის შეცდომა','bad'))
  .finally(()=>showSpinner(false));
});

function undoDeleteOnce(){
  if(!LAST_DELETED_ID) return;
  const body = new URLSearchParams();
  body.set('action','undelete'); body.set('csrf', csrf()); body.set('id', String(LAST_DELETED_ID));
  fetch('?action=undelete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': csrf()},body:body.toString()})
    .then(r=>r.json())
    .then(j=>{
      if(j && j.status==='ok'){ toast('აღდგენილია','good'); doSearch(1); }
      else { toast('აღდგენა ვერ მოხერხდა','bad'); }
    }).catch(()=> toast('ქსელის შეცდომა','bad'));
}

/* ===== EDIT open ===== */
document.addEventListener('click', (ev)=>{
  const t = ev.target.closest('.det');
  if(!t) return;
  openEditModal(t.getAttribute('data-id'));
});

function openEditModal(id){
  if(!id) return;
  fetch('?action=fetch&id='+encodeURIComponent(id),{credentials:'same-origin'})
   .then(r=>r.json())
   .then(j=>{
     if(!j || j.status!=='ok'){ toast((j && j.message) ? j.message : 'ვერ მოიძებნა','bad'); return; }
     const r = j.row || {};
     const giverId = j.giver_id || '34';

     const html = `
      <h3 id="modalTitle" class="mt-0">რედაქტირება #${id}</h3>
      <div class="grid gap-8" style="grid-template-columns:1fr 1fr">
        <div>
          <label for="ed_dat">თარიღი</label>
          <div class="input-group">
            <input type="text" id="ed_dat" class="date mono" inputmode="numeric" placeholder="DD-MM-YYYY HH:MM" value="${escapeHtml(toDDMMYYYYHHMM(r.expense_at))}">
            <input type="datetime-local" id="ed_dat_native" style="max-width:180px">
          </div>
        </div>
        <div>
          <label for="ed_cmpto1">თანხის გამცემი</label>
          <select id="ed_cmpto1">
            <option value=""></option>
            <option value="34">სალარო</option>
            <option value="825">BOG</option>
            <option value="446">დამფუძნებელი</option>
          </select>
        </div>
        <div style="grid-column:1 / -1">
          <div class="input-group">
            <input type="checkbox" id="ed_isnew" aria-controls="ed_mimarea ed_cmpto" ${(r.payee_code && r.payee_code!=='doctor' && !isPredefPayee(r.payee_code)) ? 'checked' : ''}>
            <label for="ed_cmpto" style="margin:0">თანხის მიმღები</label>
          </div>
          <select id="ed_cmpto" style="min-width:260px;">${PAYEE_OPTIONS_HTML}</select>
          <input type="text" id="ed_mimarea" style="margin-top:6px; display:none;" placeholder="მიმღების სახელი">
        </div>
        <div>
          <label for="ed_amo">თანხა</label>
          <input type="text" id="ed_amo" class="mono right" inputmode="decimal" value="${Number(r.amount).toFixed(2)}">
        </div>
        <div>
          <label for="ed_order">დოკ. #</label>
          <input type="text" id="ed_order" value="${escapeHtml(r.order_no||'')}">
        </div>
        <div style="grid-column:1 / -1">
          <label for="ed_saud">საფუძველი</label>
          <textarea id="ed_saud" placeholder="შენიშვნა">${escapeHtml(r.note||'')}</textarea>
        </div>
      </div>
      <div class="flex gap-8 mt-12 justify-between">
        <button class="btn ghost" onclick="closeEditModal()">დახურვა</button>
        <div class="flex gap-8">
          <button class="btn warn" onclick="confirmDeleteFromModal(${id})">წაშლა</button>
          <button class="btn" onclick="doUpdate(${id})">შენახვა</button>
        </div>
      </div>
     `;
     qs('#inter4').innerHTML = html;
     qs('.overlayEdit').classList.remove('dp-none');

     qs('#ed_cmpto1').value = String(giverId);

     const pc = String(r.payee_code||'').toLowerCase();
     const docId = r.doctor_id ? String(r.doctor_id) : '';
     const isDoc = (pc==='doctor' && docId);
     const predefined = isPredefPayee(pc);

     if(isDoc){
       qs('#ed_isnew').checked = false;
       qs('#ed_cmpto').value = 'doc:'+docId;
       qs('#ed_mimarea').style.display='none';
       qs('#ed_cmpto').style.display='inline-block';
     } else if(predefined){
       qs('#ed_isnew').checked = false;
       qs('#ed_cmpto').value = pc;
       qs('#ed_mimarea').style.display='none';
       qs('#ed_cmpto').style.display='inline-block';
     } else {
       qs('#ed_isnew').checked = true;
       qs('#ed_mimarea').value = r.payee || '';
       qs('#ed_mimarea').style.display='inline-block';
       qs('#ed_cmpto').style.display='none';
     }

     const modalBox = document.querySelector('.overlayEdit .innerfrm');
     if (modalBox){
       modalBox.dataset.oldMethod = String((r.method||'')).toLowerCase();
       modalBox.dataset.oldAmount = String(r.amount||0);
     }

     wireNativePicker('ed_dat','ed_dat_native');
   })
   .catch(()=> toast('ქსელის შეცდომა','bad'));
}

function closeEditModal(){
  qs('.overlayEdit').classList.add('dp-none');
  qs('#inter4').innerHTML='';
  qs('#feedErr').textContent='';
}

function confirmDeleteFromModal(id){
  if(!confirm('წავშალო ჩანაწერი?')) return;
  const body = new URLSearchParams();
  body.set('action','delete'); body.set('csrf', csrf()); body.set('id', String(id));
  fetch('?action=delete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': csrf()},body:body.toString()})
   .then(r=>r.json())
   .then(j=>{
     if(j && j.status==='ok'){ closeEditModal(); doSearch(1); refreshBalance(); loadMethodBalances(); toast('წაშლილია','warn'); }
     else { toast((j && j.message)?j.message:'შეცდომა','bad'); }
   }).catch(()=> toast('ქსელის შეცდომა','bad'));
}

/* ===== UPDATE ===== */
async function doUpdate(id){
  const body = new URLSearchParams();
  body.set('action','update'); body.set('csrf', csrf());
  body.set('id', String(id));

  const methodGiver = (qs('#ed_cmpto1')?.value||'').trim();
  const methodNew   = mapGiverToMethod(methodGiver || '34');
  const amountNew   = parseFloat((qs('#ed_amo')?.value||'0').replace(',','.')) || 0;

  const modalBox = document.querySelector('.overlayEdit .innerfrm');
  const oldMethod = (modalBox?.dataset?.oldMethod||'cash');
  const oldAmount = parseFloat(modalBox?.dataset?.oldAmount||'0') || 0;

  try{
    const r = await fetch('?action=balance_now&method='+encodeURIComponent(methodNew),{credentials:'same-origin'});
    const j = await r.json();
    const available = Number(j?.available||0);
    const allowed = (methodNew === oldMethod) ? (available + oldAmount) : available;
    if (amountNew > allowed) {
      const need = (amountNew - allowed).toFixed(2);
      qs('#feedErr').textContent = `არასაკმარისი ნაშთი (${j?.label||methodNew.toUpperCase()}). ხელმისაწვდომია: ${moneyFmt(allowed)}. საჭიროა მინიმუმ ${moneyFmt(need)} დამატება.`;
      return;
    }
  }catch(e){}

  body.set('ed_dat', (qs('#ed_dat')?.value||'').trim());
  body.set('ed_cmpto1', (qs('#ed_cmpto1')?.value||'').trim());
  const isnew = !!qs('#ed_isnew')?.checked;
  body.set('ed_isnew', isnew ? '1':'' );
  body.set('ed_cmpto', (qs('#ed_cmpto')?.value||'').trim());
  body.set('ed_mimarea', (qs('#ed_mimarea')?.value||'').trim());
  body.set('ed_amo', (qs('#ed_amo')?.value||'').trim());
  body.set('ed_saud', (qs('#ed_saud')?.value||'').trim());
  body.set('ed_order', (qs('#ed_order')?.value||'').trim());

  fetch('?action=update', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': csrf()},
    body: body.toString()
  })
  .then(r=>r.json())
  .then(j=>{
    if(j && j.status==='ok'){
      closeEditModal(); doSearch(1); refreshBalance(); loadMethodBalances(); toast('განახლებულია','good');
    } else {
      qs('#feedErr').textContent = (j && j.message) ? j.message : 'შეცდომა';
    }
  })
  .catch(()=> { qs('#feedErr').textContent = 'ქსელის შეცდომა'; });
}

/* Helpers */
function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }
function toDDMMYYYYHHMM(mysqlDT){
  if(!mysqlDT) return '';
  const d = new Date(mysqlDT.replace(' ','T'));
  if (isNaN(d.getTime())) return '';
  const dd=String(d.getDate()).padStart(2,'0');
  const mm=String(d.getMonth()+1).padStart(2,'0');
  const yyyy=d.getFullYear();
  const HH=String(d.getHours()).padStart(2,'0');
  const MM=String(d.getMinutes()).padStart(2,'0');
  return `${dd}-${mm}-${yyyy} ${HH}:${MM}`;
}
function isPredefPayee(code){
  const c = String(code||'').toLowerCase();
  return ['cash','bog','founder','salary','expense','transfer','bank'].includes(c);
}

/* ===== Sorting on headers ===== */
qsa('th[data-sortable="true"]').forEach(th=>{
  th.addEventListener('click', ()=>{
    const field = th.getAttribute('data-field');
    if(!field) return;
    if(SORT_BY === field){ SORT_DIR = (SORT_DIR==='asc'?'desc':'asc'); }
    else { SORT_BY = field; SORT_DIR = 'asc'; }
    qsa('th.sorted-asc, th.sorted-desc').forEach(x=>x.classList.remove('sorted-asc','sorted-desc'));
    th.classList.add(SORT_DIR==='asc'?'sorted-asc':'sorted-desc');
    doSearch(1,true);
  });
});

/* ===== SEARCH ===== */
function doSearch(page, keepPager=false){
  const p = new URLSearchParams();
  p.set('action','search');
  p.set('tanfl_dat1', (qs('#tanfl_dat1')?.value||'').trim());
  p.set('tanfl_dat2', (qs('#tanfl_dat2')?.value||'').trim());
  p.set('tanfl_cmpto1', (qs('#tanfl_cmpto1')?.value||'').trim());
  p.set('tanfl_cmpto', (qs('#tanfl_cmpto')?.value||'').trim());
  p.set('tanfl_docno', (qs('#tanfl_docno')?.value||'').trim());
  const ps = parseInt(qs('#pagesize')?.value || '<?= (int)PAGE_SIZE_DEFAULT ?>', 10);
  if (ps) p.set('pagesize', String(ps));
  if (page) p.set('page', String(page));
  p.set('sort_by', SORT_BY);
  p.set('sort_dir', SORT_DIR);

  const rows=qs('#rows'); rows.innerHTML = '<tr><td colspan="9" class="center">იტვირთება…</td></tr>';

  fetch('?'+p.toString(),{credentials:'same-origin'})
  .then(r=>r.json())
  .then(j=>{
    if(!j || j.status!=='ok'){ rows.innerHTML='<tr><td colspan="9" class="center">შეცდომა</td></tr>'; return; }
    rows.innerHTML = j.html || '<tr><td colspan="9" class="center">ჩანაწერი არ არის</td></tr>';
    if(!keepPager) qs('#pagerWrap').innerHTML = j.pager || '';
  })
  .catch(()=>{ rows.innerHTML='<tr><td colspan="9" class="center">ქსელის შეცდომა</td></tr>'; });
}

/* EXPORT */
function doExport(){
  const p = new URLSearchParams();
  p.set('action','cexcell2v');
  p.set('tanfl_dat1', (qs('#tanfl_dat1')?.value||'').trim());
  p.set('tanfl_dat2', (qs('#tanfl_dat2')?.value||'').trim());
  p.set('tanfl_cmpto1', (qs('#tanfl_cmpto1')?.value||'').trim());
  p.set('tanfl_cmpto', (qs('#tanfl_cmpto')?.value||'').trim());
  p.set('tanfl_docno', (qs('#tanfl_docno')?.value||'').trim());
  window.location.href = '?'+p.toString();
}
function doCSV(){
  const p = new URLSearchParams();
  p.set('action','csv_export');
  p.set('tanfl_dat1', (qs('#tanfl_dat1')?.value||'').trim());
  p.set('tanfl_dat2', (qs('#tanfl_dat2')?.value||'').trim());
  window.location.href = '?'+p.toString();
}

/* PRINT selected */
function printSelected(){
  const ids = qsa('#rows tr').filter(tr => tr.querySelector('.zu')?.checked).map(tr => tr.id).filter(Boolean);
  if(!ids.length){ alert('აირჩიე ჩანაწერები ბეჭდვისთვის.'); return; }
  ids.forEach(id => window.open('?action=cpdf&id='+encodeURIComponent(id), '_blank'));
}

/* Quick date ranges */
function setQuickRange(t){
  const now = new Date();
  const pad=n=>String(n).padStart(2,'0');
  const toDDMM=(d)=> `${pad(d.getDate())}-${pad(d.getMonth()+1)}-${d.getFullYear()}`;
  const HHMM=(d)=> `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  if(t==='today'){
    const d1 = new Date(now); d1.setHours(0,0,0,0);
    qs('#tanfl_dat1').value = `${toDDMM(d1)} 00:00`;
    qs('#tanfl_dat2').value = `${toDDMM(now)} ${HHMM(now)}`;
  } else if(t==='yesterday'){
    const y1 = new Date(now); y1.setDate(y1.getDate()-1); y1.setHours(0,0,0,0);
    const y2 = new Date(y1); y2.setHours(23,59,0,0);
    qs('#tanfl_dat1').value = `${toDDMM(y1)} 00:00`;
    qs('#tanfl_dat2').value = `${toDDMM(y2)} 23:59`;
  } else if(t==='week'){
    const day = now.getDay();
    const diff = (day===0?6:day-1); // Monday start
    const w1 = new Date(now); w1.setDate(now.getDate()-diff); w1.setHours(0,0,0,0);
    qs('#tanfl_dat1').value = `${toDDMM(w1)} 00:00`;
    qs('#tanfl_dat2').value = `${toDDMM(now)} ${HHMM(now)}`;
  } else if(t==='month'){
    const m1 = new Date(now.getFullYear(), now.getMonth(), 1, 0,0,0);
    qs('#tanfl_dat1').value = `${toDDMM(m1)} 00:00`;
    qs('#tanfl_dat2').value = `${toDDMM(now)} ${HHMM(now)}`;
  } else if(t==='clear'){
    ['tanfl_dat1','tanfl_dat2','tanfl_cmpto1','tanfl_cmpto','tanfl_docno'].forEach(id=>{ const el=qs('#'+id); if(el) el.value=''; });
  }
  doSearch(1);
}
qsa('.quick-range .btn').forEach(b=>{
  b.addEventListener('click', ()=> setQuickRange(b.getAttribute('data-range')));
});

/* INIT */
document.addEventListener('DOMContentLoaded', ()=>{
  doSearch(1);
  refreshBalance();
  fillNow('tanp_dat');
});
</script>
</body>
</html>
