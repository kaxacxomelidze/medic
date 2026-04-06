<?php
// my-patient.php — Light theme, tidy UI, official-looking printable forms (200-/ა, 100/ა, consent, contract)

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }


/* --- Robust config include & DB sanity check (prevents blank HTTP 500) --- */
$__conf_candidates = [
  __DIR__ . '/../config/config.php',
  __DIR__ . '/config.php',
];
$__conf_path = null;
foreach ($__conf_candidates as $__c) {
  if (is_file($__c)) { $__conf_path = $__c; break; }
}
if (!$__conf_path) {
  http_response_code(500);
  echo 'Config file not found (looked for ../config/config.php or ./config.php).';
  exit;
}
require $__conf_path;
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo 'Database connection ($pdo) not initialized in config.';
  exit;
}

/* --- Debug flag (URL ?debug=1 or env APP_DEBUG=1) --- */
$__DEBUG = (
  (isset($_GET['debug']) && $_GET['debug'] === '1') ||
  (!empty($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] !== '0')
);

/* --- Error handling (show details only in debug) --- */
ini_set('display_errors', $__DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line){
  throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function(Throwable $e) use ($__DEBUG){
  error_log('[my-patient.php] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
  http_response_code(500);
  if ($__DEBUG) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Server error (debug mode)\n";
    echo $e->getMessage()."\n\nAt: ".$e->getFile().':'.$e->getLine()."\n\n".$e->getTraceAsString();
  } else {
    echo 'Server error.';
  }
  exit;
});
// Never use guardian/parent data in any forms (incl. 200-/ა, 100/ა, contract)
if (!defined('EHR_NO_GUARDIAN_IN_FORMS')) {
  define('EHR_NO_GUARDIAN_IN_FORMS', true);
}

// --- Basic auth guard ---
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

// --- Common security headers for the full page (won't affect AJAX sub-responses) ---
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

/* ================= Helpers ================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmtDateShort($d){
  if(!$d) return '—';
  $t = (string)$d;
  return substr($t,0,16); // YYYY-MM-DD HH:MM
}
function latestServiceMeta(PDO $pdo, int $pid): array {
  try {
    // თუ შენთან ექიმების ცხრილი სხვანაირად ქვია, აქ JOIN-ს შეცვლი
    $st = $pdo->prepare("
      SELECT 
        ps.created_at,
        ps.doctor_id,
        TRIM(CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,''))) AS doctor_full_name
      FROM patient_services ps
      LEFT JOIN doctors d ON d.id = ps.doctor_id
      WHERE ps.patient_id = ?
      ORDER BY ps.created_at DESC, ps.service_record_id DESC
      LIMIT 1
    ");
    $st->execute([$pid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

/* --- mbstring-safe fallbacks --- */
function g_mb_tolower($s){
  return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
}
function g_mb_substr($s, $start, $len = null){
  if (function_exists('mb_substr')) return mb_substr((string)$s, $start, $len ?? null, 'UTF-8');
  $s = (string)$s; return $len === null ? substr($s, $start) : substr($s, $start, $len);
}
function g_first_char($s){
  $s = (string)$s;
  if (function_exists('mb_substr')) return mb_substr($s, 0, 1, 'UTF-8');
  if (preg_match('/^(.).*/us', $s, $m)) return $m[1];
  return substr($s, 0, 1);
}
function latestDoctorName(PDO $pdo, int $patientId): string {
  $st = $pdo->prepare("
    SELECT COALESCE(CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')),'') AS doctor_name
    FROM patient_services ps
    LEFT JOIN doctors d ON d.id = ps.doctor_id
    WHERE ps.patient_id = ?
    ORDER BY ps.created_at DESC, ps.service_record_id DESC
    LIMIT 1
  ");
  $st->execute([$patientId]);
  return trim((string)$st->fetchColumn());
}

function serviceDoctorName(PDO $pdo, int $serviceId): string {
  $st = $pdo->prepare("
    SELECT COALESCE(CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')),'') AS doctor_name
    FROM patient_services ps
    LEFT JOIN doctors d ON d.id = ps.doctor_id
    WHERE ps.service_record_id = ?
    LIMIT 1
  ");
  $st->execute([$serviceId]);
  return trim((string)$st->fetchColumn());
}

function latestForm100aUpdatedAt(PDO $pdo, int $patientId): ?string {
  // ბოლო update დრო (თუ საერთოდ არსებობს Form100a-ები ამ პაციენტზე)
  if (!ensureForm100aTable($pdo)) return null;

  $st = $pdo->prepare("
    SELECT updated_at
    FROM patient_form100a
    WHERE patient_id = ?
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$patientId]);
  $dt = $st->fetchColumn();
  return $dt ? (string)$dt : null;
}

function fmtDMY(?string $dt): string {
  if (!$dt) return date('d-m-Y');
  $ts = strtotime($dt);
  return $ts ? date('d-m-Y', $ts) : date('d-m-Y');
}

function geGender($g){
  // თუ უკვე სრული ქართული ტექსტია dashboard-დან
  $original = trim((string)$g);
  if ($original === 'მამრობითი') return 'მამრობითი';
  if ($original === 'მდედრობითი') return 'მდედრობითი';
  
  $v = g_mb_tolower($original);
  if ($v === '' || $v === '0' || $v === 'u' || $v === 'unknown') return '—';
  
  // ციფრული კოდები (1=მამრობითი, 2=მდედრობითი)
  if ($v === '1' || $v === 1) return 'მამრობითი';
  if ($v === '2' || $v === 2) return 'მდედრობითი';
  
  // ინგლისური (male/female)
  if (preg_match('/^(m|male|man)\b/iu', $v)) return 'მამრობითი';
  if (preg_match('/^(f|female|woman)\b/iu', $v)) return 'მდედრობითი';
  
  // ქართული
  if (preg_match('/^(კ|კაცი|მ|მამ|მამრ)/u', $v)) return 'მამრობითი';
  if (preg_match('/^(ქ|ქალი|მდედ)/u', $v)) return 'მდედრობითი';
  
  // Default: თუ არაფერი არ ემთხვა, დავაბრუნოთ original value debug-ისთვის
  error_log("[geGender] Unknown gender value: " . var_export($g, true));
  return '—';
}
function calcAge($birth){
  if (!$birth) return '—';
  try{
    $bd=new DateTime(substr((string)$birth,0,10));
    $diff=$bd->diff(new DateTime('now'));
    return $diff->y.' წელი '.$diff->m.' თვე '.$diff->d.' დღე';
  }catch(Throwable $e){ return '—'; }
}

/* === exact-age helpers for minor/guardian logic (NEW) === */
function ageYears($birth){
  if (!$birth) return null;
  try{
    $bd = new DateTime(substr((string)$birth, 0, 10));
    $diff = $bd->diff(new DateTime('now'));
    return (int)$diff->y;
  }catch(Throwable $e){ return null; }
}
function isMinor($birth, int $limit = 18): bool {
  $y = ageYears($birth);
  return $y !== null && $y < $limit;
}

function tryFetchTimeline(PDO $pdo, int $pid){
  $sql = "
    SELECT ps.service_record_id AS service_id, ps.created_at, COALESCE(ps.doctor_id,0) AS doctor_id,
           COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' - ',COALESCE(s.name,'')),' - '), s.name, '') AS service_name,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM patient_services ps
    LEFT JOIN services s ON s.id=ps.service_id
    LEFT JOIN doctors  d ON d.id=ps.doctor_id
    WHERE ps.patient_id=?
    ORDER BY ps.created_at DESC, ps.service_record_id DESC
    LIMIT 50";
  $st=$pdo->prepare($sql);
  $st->execute([$pid]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ===== JSON/FK support & tables (with caching) ===== */
$__db_cache = [];

function dbSupportsJson(PDO $pdo): bool {
  global $__db_cache;
  if (isset($__db_cache['json'])) return $__db_cache['json'];
  try { $r = $pdo->query("SELECT JSON_VALID('[]')")->fetchColumn(); $__db_cache['json'] = ($r !== false); return $__db_cache['json']; }
  catch (Throwable $e) { $__db_cache['json'] = false; return false; }
}
function dbSupportsFK(PDO $pdo): bool {
  global $__db_cache;
  if (isset($__db_cache['fk'])) return $__db_cache['fk'];
  try {
    $eng = $pdo->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($eng as $row) {
      if (isset($row['Engine'], $row['Support']) && strtoupper($row['Engine'])==='INNODB' && strtoupper($row['Support'])!=='NO') {
        $__db_cache['fk'] = true;
        return true;
      }
    }
  } catch (Throwable $e) {}
  $__db_cache['fk'] = false;
  return false;
}
function ensureForm200aTable(PDO $pdo): bool {
  global $__db_cache;
  if (!empty($__db_cache['form200a_ok'])) return true;
  try {
    $useJson = dbSupportsJson($pdo);
    $useFK   = dbSupportsFK($pdo);
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS patient_form200a (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        service_id INT UNSIGNED NOT NULL UNIQUE,
        patient_id INT UNSIGNED NOT NULL,
        payload ".($useJson ? "JSON" : "LONGTEXT")." NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        INDEX (patient_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    if ($useFK) {
      $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
      if ($db) {
        $chk = $pdo->prepare("
          SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
          WHERE TABLE_SCHEMA=? AND TABLE_NAME='patient_form200a' AND CONSTRAINT_NAME='fk_pf200a_service'
        ");
        $chk->execute([$db]);
        if (!(int)$chk->fetchColumn()) {
          try {
            $pdo->exec("ALTER TABLE patient_form200a
                        ADD CONSTRAINT fk_pf200a_service
                        FOREIGN KEY (service_id) REFERENCES patient_services(id)
                        ON DELETE CASCADE");
          } catch (Throwable $e) {}
        }
      }
    }
    $__db_cache['form200a_ok'] = true;
    return true;
  } catch (Throwable $e) { return false; }
}
function ensureForm100aTable(PDO $pdo): bool {
  global $__db_cache;
  if (!empty($__db_cache['form100a_ok'])) return true;
  try {
    $useJson = dbSupportsJson($pdo);
    $useFK   = dbSupportsFK($pdo);
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS patient_form100a (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        service_id INT UNSIGNED NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        payload ".($useJson ? "JSON" : "LONGTEXT")." NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        INDEX (patient_id),
        INDEX (service_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    if ($useFK) {
      $db  = $pdo->query("SELECT DATABASE()")->fetchColumn();
      if ($db) {
        $chk = $pdo->prepare("
          SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
          WHERE TABLE_SCHEMA=? AND TABLE_NAME='patient_form100a' AND CONSTRAINT_NAME='fk_pf100a_service'
        ");
        $chk->execute([$db]);
        if (!(int)$chk->fetchColumn()) {
          try {
            $pdo->exec("ALTER TABLE patient_form100a
                        ADD CONSTRAINT fk_pf100a_service
                        FOREIGN KEY (service_id) REFERENCES patient_services(id)
                        ON DELETE CASCADE");
          } catch (Throwable $e) { /* optional */ }
        }
      }
    }
    $__db_cache['form100a_ok'] = true;
    return true;
  } catch (Throwable $e) { return false; }
}

// ═══════════════ FORM 100/ა LOGGING SYSTEM ═══════════════
function ensureForm100aLogTable(PDO $pdo): bool {
  global $__db_cache;
  if (!empty($__db_cache['form100a_log_ok'])) return true;
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS form100a_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        service_id INT UNSIGNED NOT NULL DEFAULT 0,
        patient_id INT UNSIGNED NOT NULL DEFAULT 0,
        action ENUM('save','load','load_fallback','save_insert','save_update','save_error','load_error') NOT NULL,
        field_summary TEXT NULL COMMENT 'JSON: field=>filled/empty status',
        payload_size INT UNSIGNED NOT NULL DEFAULT 0,
        db_record_id INT UNSIGNED NULL COMMENT 'patient_form100a.id affected',
        user_id INT UNSIGNED NULL,
        ip VARCHAR(45) NULL,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        INDEX idx_service (service_id),
        INDEX idx_patient (patient_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $__db_cache['form100a_log_ok'] = true;
    return true;
  } catch (Throwable $e) {
    error_log("[FORM100A_LOG] table create failed: " . $e->getMessage());
    return false;
  }
}

/**
 * Log a form100a event to the database.
 * All form100a fields are tracked: which are filled and which are empty.
 */
function logForm100a(PDO $pdo, string $action, array $params = []): void {
  try {
    if (!ensureForm100aLogTable($pdo)) return;

    // Define ALL known form100a fields
    $ALL_FIELDS = [
      'dest', 'address', 'workplace', 'date_send', 'date_admit', 'date_discharge',
      'diag', 'icd10_code', 'past', 'anamn', 'course', 'therapy',
      'cond_send', 'cond_discharge', 'recom', 'recom1', 'issue_date',
      'doctor_name_auto'
    ];

    $payload = $params['payload'] ?? [];
    $fieldSummary = [];
    foreach ($ALL_FIELDS as $f) {
      $val = $payload[$f] ?? '';
      $fieldSummary[$f] = [
        'filled' => ($val !== ''),
        'length' => mb_strlen((string)$val),
      ];
    }
    // Add any extra fields not in known list
    foreach ($payload as $k => $v) {
      if (!in_array($k, $ALL_FIELDS, true)) {
        $fieldSummary['_extra_' . $k] = [
          'filled' => ($v !== ''),
          'length' => mb_strlen((string)$v),
        ];
      }
    }

    $userId = null;
    if (isset($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
    elseif (isset($_SESSION['admin_id'])) $userId = (int)$_SESSION['admin_id'];

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $st = $pdo->prepare("
      INSERT INTO form100a_log (service_id, patient_id, action, field_summary, payload_size, db_record_id, user_id, ip, note)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
      (int)($params['service_id'] ?? 0),
      (int)($params['patient_id'] ?? 0),
      $action,
      json_encode($fieldSummary, JSON_UNESCAPED_UNICODE),
      (int)($params['payload_size'] ?? 0),
      $params['db_record_id'] ?? null,
      $userId,
      $ip,
      $params['note'] ?? null,
    ]);
  } catch (Throwable $e) {
    // Logging should never break the main flow
    error_log("[FORM100A_LOG] write failed: " . $e->getMessage());
  }
}

function ensureForm100aTplTable(PDO $pdo): bool {
  global $__db_cache;
  if (!empty($__db_cache['form100a_tpl_ok'])) return true;
  try {
    // 1) თუ არ არსებობს - შევქმნათ სრული ცხრილი
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS form100a_templates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        payload LONGTEXT NOT NULL, -- JSON text (no personal data)
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_name (name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2) თუ ადრე სხვა სტრუქტურით იყო შექმნილი - ვცადოთ აკლებული სვეტების/ინდექსის დამატება
    try {
      $pdo->exec("ALTER TABLE form100a_templates ADD COLUMN payload LONGTEXT NOT NULL");
    } catch (Throwable $e) {
      // თუ უკვე არსებობს, ამ შეცდომას ვაიგნორებთ
    }

    try {
      $pdo->exec("ALTER TABLE form100a_templates ADD COLUMN created_by BIGINT UNSIGNED NULL");
    } catch (Throwable $e) {
    }

    try {
      $pdo->exec("ALTER TABLE form100a_templates ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
    } catch (Throwable $e) {
    }

    try {
      $pdo->exec("ALTER TABLE form100a_templates ADD UNIQUE KEY uniq_name (name)");
    } catch (Throwable $e) {
    }

    $__db_cache['form100a_tpl_ok'] = true;
    return true;
  } catch (Throwable $e) {
    error_log('[ensureForm100aTplTable] '.$e->getMessage());
    return false;
  }
}
function stripForm100aPersonalFromPayload(array $payload): array {
  $blockedKeys = [
    'name','full_name','patient_name',
    'personal','personal_id','pn',
    'birthdate','dob','date_of_birth',
    'address','addr',
  ];
  foreach ($blockedKeys as $k) {
    if (array_key_exists($k, $payload)) {
      unset($payload[$k]);
    }
  }
  return $payload;
}

function tableExists(PDO $pdo, string $table): bool {
  global $__db_cache;
  $key = 'tbl_'.$table;
  if (isset($__db_cache[$key])) return $__db_cache[$key];
  try {
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    if ($st && $st->fetchColumn()) { $__db_cache[$key] = true; return true; }
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($db) {
      $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1";
      $chk = $pdo->prepare($sql);
      $chk->execute([$db, $table]);
      $__db_cache[$key] = (bool)$chk->fetchColumn();
      return $__db_cache[$key];
    }
  } catch (Throwable $e) {}
  $__db_cache[$key] = false;
  return false;
}
function columnExists(PDO $pdo, string $table, string $column): bool {
  global $__db_cache;
  $key = 'col_'.$table.'_'.$column;
  if (isset($__db_cache[$key])) return $__db_cache[$key];
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if (!$db) { $__db_cache[$key] = false; return false; }
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$db,$table,$column]);
    $__db_cache[$key] = (bool)$st->fetchColumn();
    return $__db_cache[$key];
  } catch (Throwable $e) { $__db_cache[$key] = false; return false; }
}

/* ================= AJAX / DIAGNOSTICS ================= */
$action = $_GET['action'] ?? '';

/* ----------------------- DIAG ----------------------- */
if ($action === 'diag') {
  header('Content-Type: application/json; charset=utf-8');

  $resp = [
    'status'        => 'ok',
    'php_version'   => PHP_VERSION,
    'debug'         => $__DEBUG,
    'extensions'    => [
      'mbstring'   => extension_loaded('mbstring'),
      'pdo_mysql'  => in_array('mysql', PDO::getAvailableDrivers(), true),
    ],
    'session'       => session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive',
    'config_path'   => isset($__conf_path) ? $__conf_path : null,
    'database'      => null,
    'tables'        => [],
    'checks'        => [],
  ];

  try { $resp['database'] = $pdo->query("SELECT DATABASE()")->fetchColumn() ?: null; } catch (Throwable $e) {
    $resp['checks'][] = ['db_name', 'fail', $e->getMessage()];
  }

  $tbls = ['patients','doctors','patient_services'];
  foreach ($tbls as $t) {
    try {
      $resp['tables'][$t] = tableExists($pdo, $t);
    } catch (Throwable $e) {
      $resp['tables'][$t] = false;
      $resp['checks'][] = ["table:$t", 'fail', $e->getMessage()];
    }
  }

  try { $pdo->query("SELECT 1"); $resp['checks'][] = ['select1','ok']; }
  catch (Throwable $e) { $resp['checks'][] = ['select1','fail',$e->getMessage()]; }

  try {
    if (!empty($resp['tables']['patients'])) {
      $st = $pdo->query("SELECT COUNT(*) FROM patients");
      $resp['checks'][] = ['patients_count','ok', (int)$st->fetchColumn()];
    }
  } catch (Throwable $e) { $resp['checks'][] = ['patients_count','fail',$e->getMessage()]; }

  echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}




/* ----------------------- FILTERS ----------------------- */
if ($action === 'filters') {
  header('Content-Type: application/json; charset=utf-8');
  try{
    $st=$pdo->query("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM doctors ORDER BY last_name, first_name");
    echo json_encode([
      'status'=>'ok',
      'doctors'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: [],
      'departments'=>[['id'=>'','name'=>'ყველა'],['id'=>'amb','name'=>'ამბულატორია']]
    ]);
  }catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'სერვერის შეცდომა']);
  }
  exit;
}

function loadVisitContext(PDO $pdo, int $serviceId): ?array {
  if ($serviceId <= 0) return null;
  
  // Check which columns exist in patients table (cached per request)
  static $__columns_cache = null;
  if ($__columns_cache === null) {
    try {
      $st = $pdo->query("SHOW COLUMNS FROM patients");
      $__columns_cache = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Field');
      error_log('[loadVisitContext] Found '.count($__columns_cache).' columns in patients table');
    } catch (Throwable $e) {
      error_log('[loadVisitContext] Could not get patients table structure: '.$e->getMessage());
      $__columns_cache = [];
    }
  }
  
  // Build column list based on what exists
  $has_relative = in_array('relative_first_name', $__columns_cache);
  error_log('[loadVisitContext] has_relative='.($has_relative?'yes':'no').' for service_id='.$serviceId);
  
    // Check if relative_email exists (might not be in all DBs)
    $has_relative_email = in_array('relative_email', $__columns_cache);
    
    $relative_cols = $has_relative ? 
    "COALESCE(p.relative_first_name,'') AS relative_first_name, 
     COALESCE(p.relative_last_name,'') AS relative_last_name, 
     COALESCE(p.relative_personal_id,'') AS relative_personal_id,
     COALESCE(p.relative_address,'') AS relative_address,
     ".($has_relative_email ? "COALESCE(p.relative_email,'') AS relative_email," : "'' AS relative_email,")."
     COALESCE(p.relative_phone,'') AS relative_phone" :
    "'' AS relative_first_name, '' AS relative_last_name, '' AS relative_personal_id,
     '' AS relative_address, '' AS relative_email, '' AS relative_phone";  try {
    $sql = "
      SELECT 
        ps.service_record_id AS service_id, 
        ps.patient_id, 
        ps.created_at,
        COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' - ',COALESCE(s.name,'')),' - '), COALESCE(s.name,''), '') AS service_name,
        COALESCE(CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')), '') AS doctor_name,
        COALESCE(p.first_name, '') AS first_name, 
        COALESCE(p.last_name, '') AS last_name, 
        COALESCE(p.personal_id, '') AS personal_id, 
        COALESCE(p.birthdate, '') AS birthdate,
        COALESCE(p.address,'') AS address,
        ".(in_array('email', $__columns_cache) ? "COALESCE(p.email,'') AS email," : "'' AS email,")."
        COALESCE(p.phone,'') AS phone,
        {$relative_cols}
      FROM patient_services ps
      LEFT JOIN services s ON s.id = ps.service_id
      LEFT JOIN doctors d ON d.id = ps.doctor_id
      LEFT JOIN patients p ON p.id = ps.patient_id
      WHERE ps.service_record_id = ?
      LIMIT 1
    ";
    error_log('[loadVisitContext] Executing query for service_id='.$serviceId);
    error_log('[loadVisitContext] has_relative='.$has_relative);
    error_log('[loadVisitContext] relative_cols='.substr($relative_cols, 0, 150));
    
    $st = $pdo->prepare($sql);
    
    try {
      $st->execute([$serviceId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $sqlError) {
      error_log('[loadVisitContext] SQL Execute Error: '.$sqlError->getMessage());
      error_log('[loadVisitContext] Full SQL: '.$sql);
      return null;
    }
    
    if (!$row) {
      error_log('[loadVisitContext] Query returned no rows for service_id='.$serviceId);
      error_log('[loadVisitContext] Full SQL was: '.preg_replace('/\s+/', ' ', $sql));
      return null;
    }
    
    error_log('[loadVisitContext] Query returned row: patient_id='.$row['patient_id'].', name='.$row['first_name'].' '.$row['last_name']);
  } catch (Throwable $e) {
    error_log('[loadVisitContext] Query failed: '.$e->getMessage().' | SQL: '.substr($sql ?? '', 0, 200));
    return null;
  }

  $visit = [
    'service_id'  => (int)$row['service_id'],
    'patient_id'  => (int)$row['patient_id'],
    'created_at'  => (string)$row['created_at'],
    'doctor_name' => (string)($row['doctor_name'] ?? ''),
    'service_name'=> (string)($row['service_name'] ?? ''),
  ];

  $patient = [
    'id'          => (int)$row['patient_id'],
    'first_name'  => (string)($row['first_name'] ?? ''),
    'last_name'   => (string)($row['last_name'] ?? ''),
    'full_name'   => trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')),
    'personal_id' => (string)($row['personal_id'] ?? ''),
    'birthdate'   => (string)($row['birthdate'] ?? ''),
    'address'     => (string)($row['address'] ?? ''),
    'email'       => (string)($row['email'] ?? ''),
    'phone'       => (string)($row['phone'] ?? ''),
  ];

  // Build guardian/relative info first
  $relative_full_name = trim(($row['relative_first_name'] ?? '').' '.($row['relative_last_name'] ?? ''));
  $has_relative = ($relative_full_name !== '' || !empty($row['relative_personal_id']));

  // Check if minor (under 18) - with fallback: if has relative info, assume minor
  $is_minor = false;
  if (!empty($row['birthdate'])) {
    try {
      $dob = new DateTime(substr((string)$row['birthdate'], 0, 10));
      $age = $dob->diff(new DateTime('today'))->y;
      // Check if age is reasonable (0-150) and under 18
      $is_minor = ($age >= 0 && $age < 150 && $age < 18);
    } catch (Throwable $e) { 
      $is_minor = false; 
    }
  }
  
  // FALLBACK: If has relative info but age check failed or age seems wrong, assume it's a minor
  if (!$is_minor && $has_relative) {
    $is_minor = true;
  }

  // If minor (or has relative info) and has relative data, use relative as legal party
  if ($is_minor && $has_relative) {
    $party = [
      'is_guardian' => true,
      'name'        => $relative_full_name,
      'personal_id' => (string)($row['relative_personal_id'] ?? ''),
      'address'     => (string)($row['relative_address'] ?? ''),
      'email'       => (string)($row['relative_email'] ?? ''),
      'phone'       => (string)($row['relative_phone'] ?? ''),
    ];
  } else {
    // Otherwise use patient as legal party
    $party = [
      'is_guardian' => false,
      'name'        => $patient['full_name'],
      'personal_id' => $patient['personal_id'],
      'address'     => $patient['address'],
      'email'       => $patient['email'],
      'phone'       => $patient['phone'],
    ];
  }

  return ['visit'=>$visit, 'patient'=>$patient, 'legal_party'=>$party];
}


if ($action === 'list') {
  header('Content-Type: application/json; charset=utf-8');
  try{
    $doctor_id=(int)($_GET['doctor_id'] ?? 0);
    $dept=trim($_GET['dept'] ?? '');
    $sql="
      SELECT p.id,p.personal_id,p.first_name,p.last_name,p.gender,p.birthdate,p.phone
      FROM patients p
      ".($doctor_id>0 ? "INNER JOIN (SELECT DISTINCT patient_id FROM patient_services WHERE doctor_id=?) fdoc ON fdoc.patient_id=p.id":"")."
      ".($dept==='amb' ? "INNER JOIN (SELECT DISTINCT patient_id FROM patient_services) fdept ON fdept.patient_id=p.id":"")."
      ORDER BY p.last_name, p.first_name
      LIMIT 500";
    $args=[]; if ($doctor_id>0) $args[]=$doctor_id;
    $st=$pdo->prepare($sql); $st->execute($args);
    echo json_encode(['status'=>'ok','rows'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  }catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'სერვერის შეცდომა']);
  }
  exit;
}

if ($action === 'patient_timeline') {
  header('Content-Type: text/html; charset=utf-8');
  $pid=(int)($_GET['patient_id'] ?? 0);
  if ($pid<=0) { http_response_code(400); echo 'bad id'; exit; }

  try{
    $stP=$pdo->prepare("SELECT first_name,last_name FROM patients WHERE id=?"); $stP->execute([$pid]);
    $p=$stP->fetch(PDO::FETCH_ASSOC) ?: [];
    $rows=tryFetchTimeline($pdo,$pid);
  }catch(Throwable $e){
    http_response_code(500); echo '<div class="muted">ვერ ჩაიტვირთა.</div>'; exit;
  }

  ob_start(); ?>
  <div class="panel-title">პაციენტი: <b><?= h(trim(($p['first_name']??'').' '.($p['last_name']??''))) ?: '—' ?></b></div>

  <table class="timeline-table" id="rightRows" aria-label="რიგების სია">
    <colgroup><col style="width:52px"><col style="width:180px"><col><col style="width:28px"></colgroup>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="4" class="muted center">ჩანაწერი არ არის.</td></tr>
    <?php else: foreach($rows as $r): $sid=(int)$r['service_id']; ?>
      <tr id="S<?= $sid ?>" tabindex="0" aria-label="სერვის რიგი">
        <td class="cell-center">
          <button type="button" class="btn icon nwrgdoc" data-service="<?= $sid ?>" title="გახსნა" aria-label="გახსნა">◱</button>
        </td>
        <td><div class="mono"><?= h(fmtDateShort($r['created_at'])) ?></div></td>
        <td>
          <div class="strong"><?= h($r['service_name'] ?: 'სერვისი') ?></div>
          <?php if (!empty($r['doctor_name'])): ?>
            <span class="chip"><?= h($r['doctor_name']) ?></span>
          <?php endif; ?>
        </td>
        <td></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php
  echo ob_get_clean(); exit;
}

/* ----------------------- BOX MODAL ----------------------- */
if ($action === 'box') {
  header('Content-Type: text/html; charset=utf-8');
  $sid=(int)($_GET['service_id'] ?? 0);
  if ($sid<=0) { http_response_code(400); echo 'bad id'; exit; }

  try{
    $st=$pdo->prepare("
      SELECT ps.service_record_id AS service_id, ps.patient_id, ps.created_at,
             p.first_name,p.last_name,p.gender,p.birthdate,
             CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
             COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' - ',COALESCE(s.name,'')),' - '), s.name, '') AS service_name
      FROM patient_services ps
      LEFT JOIN patients p ON p.id=ps.patient_id
      LEFT JOIN doctors d  ON d.id=ps.doctor_id
      LEFT JOIN services s ON s.id=ps.service_id
      WHERE ps.service_record_id=? LIMIT 1");
    $st->execute([$sid]); $row=$st->fetch(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ $row = false; }

  if(!$row){
    echo '<div class="modal-card" role="dialog" aria-modal="true"><button class="btn ghost closeBtn" style="position:absolute;top:10px;right:10px" aria-label="დახურვა">✕</button><div class="pad" style="padding:28px">ჩანაწერი ვერ მოიძებნა.</div></div>';
    exit;
  }

  $fullName=trim(($row['first_name']??'').' '.($row['last_name']??''));
  $gender=geGender($row['gender']??'');
  $dobStr=$row['birthdate'] ? date('d-m-Y', strtotime($row['birthdate'])) : '—';
  $ageStr=calcAge($row['birthdate']??null);
  $dateStr=$row['created_at'] ? date('d-m-Y H:i', strtotime($row['created_at'])) : '—';
  ?>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="btn ghost closeBtn" aria-label="დახურვა">✕</button>

    <div class="modal-head">
      <div class="avatar"><?= h(g_first_char($fullName ?: '—')) ?></div>
      <div class="title-wrap">
        <div class="title"><?= h($fullName ?: '—') ?></div>
        <div class="subtitle mono">სერვისი: <?= h($row['service_name'] ?: '—') ?> · <?= h($dateStr) ?></div>
      </div>
    </div>

    <div class="modal-body">
      <aside class="leftnav" id="lfgttg">
        <a href="javascript:void(0)" n="patientdata" id="EHR" class="navbtn primary">EHR</a>
        <a href="javascript:void(0)" n="kvlevebif100shi" id="kvllist" class="navbtn">კლევების სია</a>
        <a href="javascript:void(0)" n="camb-form100" id="CAGtfrm100" class="navbtn">წამბუ 100/ა*</a>
        <a href="javascript:void(0)" n="anamnesisvite" id="anamvite" class="navbtn">ANAMNESIS VITAE</a>
        <a href="javascript:void(0)" n="amb-form100" id="AGtfrm100" class="navbtn">ამბუ 100/ა*</a>
        <a href="javascript:void(0)" n="anamnesismorbi" id="anammorni" class="navbtn">ANAMNESIS MORBI</a>
        <a href="javascript:void(0)" n="sendEHR" id="sendehdid" class="navbtn">MOH</a>
        <a href="javascript:void(0)" n="exitusambu" id="exitambid" class="navbtn">გაწერა</a>
        <div class="navgroup">ფორმები</div>
        <a href="javascript:void(0)" n="forma200a" id="F200-a" class="navbtn">200-/ა*</a>
        <a href="javascript:void(0)" n="formrep" id="Frep" class="navbtn">რეპორტი</a>
        <a href="javascript:void(0)" n="formrep2" id="Frep2" class="navbtn">რეპორტი 2</a>
        <a href="javascript:void(0)" n="forma2003a" id="F200-3a" class="navbtn">200-3/ა</a>
        <a href="javascript:void(0)" n="forma2006a" id="F200-6a" class="navbtn">200-6/ა</a>
        <a href="javascript:void(0)" n="forma2007a" id="F200-7a" class="navbtn">200-7/ა*</a>
        <a href="javascript:void(0)" n="forma2008a" id="F200-8a" class="navbtn">200-8/ა*</a>
        <a href="javascript:void(0)" n="forma20010a" id="F200-10a" class="navbtn">200-10/ა*</a>
        <a href="javascript:void(0)" n="formaAppos" id="F-Appo" class="navbtn">დანიშნულება</a>
        <a href="javascript:void(0)" n="formaPrescriptio" id="F-Prescriptions" class="navbtn">ჩანიშნვები ⊚</a>
        <a href="javascript:void(0)" n="inspect" id="inspid" class="navbtn">ინსპექტირება</a>
        <a href="javascript:void(0)" n="frm2002" id="frm2002id" class="navbtn">200-2/ა (Vitals)</a>
        <a href="javascript:void(0)" n="docorders" id="docors" class="navbtn">შეკვეთა</a>
        <a href="javascript:void(0)" n="docstats" id="labrps" class="navbtn">Stat</a>
        <a href="javascript:void(0)" n="epresc" id="mohpresc" class="navbtn">E-prescription</a>
        <div class="navgroup">გასინჯვის ფურცელი</div>
        <a href="javascript:void(0)" n="d4lf5xbmwg3tegkg" id="z2qt7092rz7c3rin3" class="navbtn">თანხმობის ხელწერილი*</a>
        <a href="javascript:void(0)" n="cmydjokr34vjakc6" id="f28dzhbm0rwtw3n0" class="navbtn">ხელშეკრულება*</a>
      </aside>

      <section class="content">
        <div class="card soft">
          <div class="grid-2" role="group" aria-label="პაციენტის ძირითადი ინფორმაცია">
            <div><div class="muted">სქესი</div><div class="strong"><?= h($gender) ?></div></div>
            <div><div class="muted">დაბადების თარიღი</div><div class="strong"><?= h($dobStr) ?></div></div>
            <div><div class="muted">ასაკი</div><div class="strong"><?= h($ageStr) ?></div></div>
            <div><div class="muted">ექიმი</div><div class="strong"><?= h($row['doctor_name'] ?: '—') ?></div></div>
          </div>
        </div>

        <div class="card">
          <div class="card-title" style="padding:16px 16px 0; font-weight:800;">ბოლო ოპერაცია</div>
          <div class="table-scroll" style="margin:10px 16px 16px">
            <table class="clean-table" aria-label="ბოლო ოპერაცია">
              <thead>
                <tr>
                  <th>თარიღი</th><th>ექიმი</th><th>ტიპი</th><th>დასახელება</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="mono"><?= h($dateStr) ?></td>
                  <td><?= h($row['doctor_name'] ?: '—') ?></td>
                  <td>—</td>
                  <td class="strong"><?= h($row['service_name'] ?: '—') ?></td>
                  <td class="cell-right"><button class="btn ghost sm" type="button">კოპირება</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </section>
    </div>
  </div>
  <?php
  exit;
}


/* ----------------------- FORM 200-/ა ----------------------- */
if ($action === 'form200a') {
  header('Content-Type: text/html; charset=utf-8');

  // ---------- small helpers (defined once) ----------
  if (!function_exists('h')) {
    function h($s) {
      return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
  }

  if (!function_exists('geGender')) {
    // If your project already defines geGender(), this won't override it.
    function geGender($v): string {
      $v = strtolower(trim((string)$v));
      if (in_array($v, ['m','male','კაცი','მამრ.','მამრ'])) return 'კაცი';
      if (in_array($v, ['f','female','ქალი','მდედრ.','მდედრ'])) return 'ქალი';
      return '';
    }
  }

  if (!function_exists('tableExists')) {
    function tableExists(PDO $pdo, string $table): bool {
      try {
        $q = $pdo->prepare("
          SELECT 1
          FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?
          LIMIT 1
        ");
        $q->execute([$table]);
        return (bool)$q->fetchColumn();
      } catch (Throwable $e) { return false; }
    }
  }

  if (!function_exists('columnExists')) {
    function columnExists(PDO $pdo, string $table, string $column): bool {
      try {
        $q = $pdo->prepare("
          SELECT 1
          FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = ?
            AND column_name = ?
          LIMIT 1
        ");
        $q->execute([$table, $column]);
        return (bool)$q->fetchColumn();
      } catch (Throwable $e) { return false; }
    }
  }

  if (!function_exists('ensureForm200aTable')) {
    function ensureForm200aTable(PDO $pdo): bool {
      try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS `patient_form200a` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `service_id` BIGINT UNSIGNED NOT NULL,
            `payload`    LONGTEXT NOT NULL,     -- JSON stored as text (works on all MySQL/MariaDB)
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_service` (`service_id`),
            KEY `idx_patient` (`patient_id`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
      } catch (Throwable $e) { return false; }
    }
  }

  if (!function_exists('lastWorkplaceForPatient')) {
    /**
     * Pull latest workplace/employer from previous forms or profile.
     * Priority: latest 200ა → latest 100ა → patient_employment → columns on patients.
     */
    function lastWorkplaceForPatient(PDO $pdo, int $patientId): string {
      if ($patientId <= 0) return '';

      // 1) Latest Form 200ა
      if (tableExists($pdo, 'patient_form200a')) {
        try {
          $q = $pdo->prepare("
            SELECT payload FROM patient_form200a
            WHERE patient_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
          ");
          $q->execute([$patientId]);
          $pl = $q->fetchColumn();
          if ($pl) {
            $j = json_decode((string)$pl, true);
            if (is_array($j)) {
              foreach (['workplace','employer','work_place'] as $k) {
                $v = trim((string)($j[$k] ?? ''));
                if ($v !== '') return $v;
              }
            }
          }
        } catch (Throwable $e) {}
      }

      // 2) Latest Form 100ა
      if (tableExists($pdo, 'patient_form100a')) {
        try {
          $q = $pdo->prepare("
            SELECT payload FROM patient_form100a
            WHERE patient_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
          ");
          $q->execute([$patientId]);
          $pl = $q->fetchColumn();
          if ($pl) {
            $j = json_decode((string)$pl, true);
            if (is_array($j)) {
              foreach (['workplace','employer','work_place'] as $k) {
                $v = trim((string)($j[$k] ?? ''));
                if ($v !== '') return $v;
              }
            }
          }
        } catch (Throwable $e) {}
      }

      // 3) Employment table (optional)
      if (tableExists($pdo, 'patient_employment')) {
        try {
          $q = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(employer_name),''), NULLIF(TRIM(workplace), ''))
            FROM patient_employment
            WHERE patient_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
          ");
          $q->execute([$patientId]);
          $v = trim((string)$q->fetchColumn());
          if ($v !== '') return $v;
        } catch (Throwable $e) {}
      }

      // 4) Fallback to potential columns on `patients`
      foreach (['workplace','employer','work_place','occupation','profession','job'] as $col) {
        if (columnExists($pdo, 'patients', $col)) {
          try {
            $q = $pdo->prepare("SELECT $col FROM patients WHERE id=? LIMIT 1");
            $q->execute([$patientId]);
            $v = trim((string)$q->fetchColumn());
            if ($v !== '') return $v;
          } catch (Throwable $e) {}
        }
      }

      return '';
    }
  }
  // ---------- /helpers ----------

  $sid = (int)($_GET['service_id'] ?? 0);
  if ($sid <= 0) { http_response_code(400); echo 'bad id'; exit; }

  // Fetch visit + patient
  try {
    $st = $pdo->prepare("
      SELECT 
        ps.service_record_id AS service_id, ps.patient_id, ps.created_at,
        p.first_name, p.last_name, p.personal_id, p.birthdate, COALESCE(p.phone,'') AS phone,
        COALESCE(p.address,'') AS address, p.gender
      FROM patient_services ps
      LEFT JOIN patients p ON p.id = ps.patient_id
      WHERE ps.service_record_id=? LIMIT 1
    ");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $row = false; }

  if (!$row) { echo '<div class="muted">ვიზიტი ვერ მოიძებნა.</div>'; exit; }

  // Patient id first (needed for workplace autofill)
  $pid = (int)($row['patient_id'] ?? 0);

  // Load saved payload
  $saved = [];
  if (ensureForm200aTable($pdo)) {
    try {
      $q = $pdo->prepare("SELECT payload FROM patient_form200a WHERE service_id=? LIMIT 1");
      $q->execute([$sid]);
      $pl = $q->fetchColumn();
      if ($pl) { $saved = json_decode((string)$pl, true) ?: []; }
    } catch (Throwable $e) {}
  }

  // Prefill (from patient row)
  $fullName = trim(($row['first_name']??'').' '.($row['last_name']??''));
  $personal = (string)($row['personal_id'] ?? '');
  $dob      = !empty($row['birthdate']) ? date('d/m/Y', strtotime($row['birthdate'])) : '';
  $gender   = geGender($row['gender'] ?? '');
  $phone    = (string)($row['phone'] ?? '');
  $addr     = (string)($row['address'] ?? '');
  $numLine  = sprintf('%d-%s / %s', (int)$row['service_id'], date('Y', strtotime($row['created_at'] ?? 'now')), $personal);

  // Saved fields (with workplace auto-fill fallback)
  $workplace     = trim((string)($saved['workplace']     ?? ''));
  if ($workplace === '') { $workplace = lastWorkplaceForPatient($pdo, $pid); }

  $blood_group   = (string)($saved['blood_group']   ?? '');
  $rh            = (string)($saved['rh']            ?? '');
  $transfusions  = (string)($saved['transfusions']  ?? '');
  $allergy       = (string)($saved['allergy']       ?? '');
  $surgeries     = (string)($saved['surgeries']     ?? '');
  $infections    = (string)($saved['infections']    ?? '');
  $chronic       = (string)($saved['chronic']       ?? '');
  $policy_no     = (string)($saved['policy_no']     ?? '');
  $insurer       = (string)($saved['insurer']       ?? '');
  $limit_mod     = !empty($saved['limit_mod']) ? 1 : 0; // ზომიერი
  $limit_sig     = !empty($saved['limit_sig']) ? 1 : 0; // მნიშვნელოვანი
  $limit_sev     = !empty($saved['limit_sev']) ? 1 : 0; // მკვეთრი

  ob_start(); ?>
  <div class="card form100a-card printable">
    <div class="form100a-head">
      <div class="form100a-title">
        <span class="pill">ფორმა 200-/ა</span>
        <span><?= h($fullName ?: '—') ?></span>
      </div>
      <div class="form100a-actions">
        <button type="button" class="btn ghost sm" onclick="printOnly(this.closest('.printable'))">ბეჭდვა</button>
        <button type="button" class="btn ghost sm" id="btnSave200a">შენახვა</button>
      </div>
    </div>

    <div class="form100a-body" id="f200aRoot" data-service="<?= (int)$sid ?>">
      <div id="713" class="olvn tmpldiv nondrg mcffx">
        <input type="hidden" id="frmlni" value="4">
        <input type="hidden" id="tmplload" value="globaltemplateCaller">

        <div style="text-align:center">
          <span style="font-size:18px;font-weight:bold;">ამბულატორიული პაციენტის სამედიცინო ბარათი</span>
        </div>
        <br>

        <div class="fmdv"><span>#</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:260px" value="<?= h($numLine) ?>"></span>
        </div>

        <div class="fmdv"><span>გვარი, სახელი:</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:400px" value="<?= h($fullName) ?>"></span>
        </div>

        <div class="fmdv"><span>სქესი:</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:200px" value="<?= h($gender) ?>"></span>
        </div>

        <div class="fmdv">
          <span>დაბადების თარიღი</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:140px" value="<?= h($dob) ?>"></span>
          <span>ტელეფონი</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:240px" value="<?= h($phone) ?>"></span>
        </div>

        <div class="fmdv"><span>პირადი ნომერი (ასეთის არსებობის შემთხვევაში)</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:220px" value="<?= h($personal) ?>"></span>
        </div>

        <div class="fmdv"><span>მისამართი</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:600px" value="<?= h($addr) ?>"></span>
        </div>

        <div class="fmdv"><span>სამუშაო ადგილი, პროფესია</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:560px" data-f200a="workplace" value="<?= h($workplace) ?>"></span>
        </div>

        <div class="fmdv">
          <span>შესაძლებლობების შეზღუდვის სტატუსი:</span>
          <span> ზომიერი</span><span><input type="checkbox" class="ko" data-f200a="limit_mod" <?= $limit_mod ? 'checked' : '' ?>></span>
          <span>, მნიშვნელოვანი</span><span><input type="checkbox" class="ko" data-f200a="limit_sig" <?= $limit_sig ? 'checked' : '' ?>></span>
          <span>, მკვეთრი</span><span><input type="checkbox" class="ko" data-f200a="limit_sev" <?= $limit_sev ? 'checked' : '' ?>></span>
        </div>

        <div class="fmdv"><span>სისხლის ჯგუფი</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:240px" data-f200a="blood_group" value="<?= h($blood_group) ?>"></span>
          <span>Rh-ფაქტორი</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:240px" data-f200a="rh" value="<?= h($rh) ?>"></span>
        </div>

        <div class="fmdv"><span>სისხლის გადასხმები</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:500px" data-f200a="transfusions" value="<?= h($transfusions) ?>"></span>
        </div>
        <div class="fmdv"><span class="jo" style="margin-left:290px">(როდის და რამდენი)</span></div>

        <div class="fmdv"><span>ალერგია</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:600px" data-f200a="allergy" value="<?= h($allergy) ?>"></span>
        </div>
        <div class="fmdv"><span class="jo" style="margin-left:290px">(მედიკამენტი, საკვები და სხვა. რეაქციის ტიპი)</span></div>

        <div class="fmdv"><span>გადატანილი ქირურგიული ჩარევები</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:600px" data-f200a="surgeries" value="<?= h($surgeries) ?>"></span>
        </div>

        <div class="fmdv"><span>გადატანილი ინფექციური დაავადებები</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:500px" data-f200a="infections" value="<?= h($infections) ?>"></span>
        </div>

        <div class="fmdv"><span>ქრონიკული დაავადებები (მ.შ. გენეტიკური დაავადებები) და მავნე ჩვევები</span></div>
        <div class="fmdv"><span class="ml"><input type="text" class="nondrg ko" style="width:700px" data-f200a="chronic" value="<?= h($chronic) ?>"></span></div>

        <div class="fmdv"><span>სადაზღვევო პოლისის ნომერი</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:200px" data-f200a="policy_no" value="<?= h($policy_no) ?>"></span>
        </div>
        <div class="fmdv"><span>სადაზღვევო კომპანია</span>
          <span class="ml"><input type="text" class="nondrg ko" style="width:400px" data-f200a="insurer" value="<?= h($insurer) ?>"></span>
        </div>
      </div>
    </div>
  </div>
  <?php
  echo ob_get_clean(); exit;
}
if ($action === 'contract_show') {
  header('Content-Type: text/html; charset=utf-8');
  $sid = (int)($_GET['service_id'] ?? 0);
  
  if ($sid <= 0) { 
    http_response_code(400); 
    echo '<div class="muted">არასწორი service_id: '.h($sid).'</div>'; 
    exit; 
  }

  $ctx = loadVisitContext($pdo, $sid);
  
  if (!$ctx) { 
    $debugInfo = ['error' => 'loadVisitContext returned null', 'service_id' => $sid];
    error_log('[contract_show] loadVisitContext returned null for service_id='.$sid);
    
    // Detailed debug
    try {
      // Check patient_services structure
      $check = $pdo->prepare("SELECT ps.*, p.first_name, p.last_name FROM patient_services ps LEFT JOIN patients p ON p.id=ps.patient_id WHERE ps.service_record_id=?");
      $check->execute([$sid]);
      $exists = $check->fetch(PDO::FETCH_ASSOC);
      
      if ($exists) {
        $debugInfo['patient_services'] = array_filter($exists, fn($k) => $k !== 'first_name' && $k !== 'last_name', ARRAY_FILTER_USE_KEY);
        $debugInfo['patient_name'] = trim(($exists['first_name'] ?? '').' '.($exists['last_name'] ?? ''));
        
        // Try to manually fetch patient
        $pid = $exists['patient_id'] ?? 0;
        if ($pid > 0) {
          $p = $pdo->prepare("SELECT relative_first_name, relative_last_name, relative_personal_id, first_name, last_name, birthdate FROM patients WHERE id=?");
          $p->execute([$pid]);
          $patient = $p->fetch(PDO::FETCH_ASSOC);
          
          if ($patient) {
            $debugInfo['patient_record'] = $patient;
          } else {
            $debugInfo['patient_record'] = 'NOT FOUND';
          }
        }
        
        // Try the actual loadVisitContext SQL manually
        $testSql = "SELECT ps.service_record_id, ps.patient_id, s.name as service_name, d.first_name as doc_fname 
                    FROM patient_services ps 
                    LEFT JOIN services s ON s.id = ps.service_id 
                    LEFT JOIN doctors d ON d.id = ps.doctor_id 
                    WHERE ps.service_record_id = ?";
        $test = $pdo->prepare($testSql);
        $test->execute([$sid]);
        $testResult = $test->fetch(PDO::FETCH_ASSOC);
        $debugInfo['test_query_result'] = $testResult ?: 'NULL';
        
        $jsonDebug = json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo '<div style="padding:20px;background:#fff3cd;border:2px solid #ffc107;border-radius:8px;margin:20px;">';
        echo '<h3 style="color:#856404;">🔍 Debug Info (გახსენით F12 Console)</h3>';
        echo '<pre style="background:#fff;padding:15px;border:1px solid #ddd;border-radius:4px;overflow:auto;max-height:400px;font-size:11px;">'.h($jsonDebug).'</pre>';
        echo '</div>';
        echo '<script>';
        echo 'console.group("🔍 Contract Debug Info");';
        echo 'console.log('.$jsonDebug.');';
        echo 'console.table('.json_encode($debugInfo['patient_services'] ?? []).');';
        echo 'console.groupEnd();';
        echo 'console.warn("loadVisitContext() returned NULL - check data above");';
        echo '</script>';
      } else {
        echo '<div class="muted">ვიზიტი (service_id='.$sid.') არ არსებობს patient_services ცხრილში.</div>';
      }
    } catch (Throwable $e) {
      $debugInfo['exception'] = $e->getMessage();
      error_log('[contract_show] Error checking service: '.$e->getMessage());
      echo '<div class="muted">SQL შეცდომა - ნახეთ F12 Console</div>';
      echo '<script>console.error("SQL Error:", '.json_encode($debugInfo, JSON_UNESCAPED_UNICODE).');</script>';
    }
    exit; 
  }

  $now        = date('d-m-Y H:i');
  $facility   = (string)($_SESSION['facility_name'] ?? '„კლინიკა სანმედი“');
  $clinic_legal = [
    'name'         => 'შპს „კლინიკა სანმედი“',
    'id'           => '405695323',
    'director'     => 'მარიამ ღუღუნიშვილი',
    'director_pid' => '01001098074',
    'city'         => 'ქ. თბილისი',
  ];

  $visit = $ctx['visit'] ?? [];
  $p     = $ctx['patient'] ?? [];
  $party = $ctx['legal_party'] ?? [];

  $child_name = $p['full_name']    ?? '';
  $child_pid  = $p['personal_id']  ?? '';
  $child_dob  = $p['birthdate']    ?? '';
ob_start(); ?>
<?php
// ========= Signer info from $party (already resolved in loadVisitContext) =========
// $party already contains correct signer: guardian if minor+has_relative, or patient otherwise
$sign = (is_array($party ?? null) && !empty($party)) ? $party : [
  'name'        => $child_name ?? '',
  'personal_id' => $child_pid  ?? '',
  'address'     => '',
  'email'       => '',
];

// Dynamic label for the signer block
$is_guardian = ($party['is_guardian'] ?? false);
$sign_label = $is_guardian ? 'კანონიერი წარმომადგენელი' : 'კლიენტი/პაციენტი';
?>
<style>
/* ===== Contract (screen) — polished, paper-like with layered shadows ===== */
.contract-doc{
  --font:"DejaVu Sans","Noto Sans Georgian","Sylfaen","Arial Unicode MS",Arial,Helvetica,sans-serif;
  --fg:#0b1220; --muted:#5b6473; --br:#e6eaf0; --chip:#eef8f5; --accent:#14937c;
  --ink:#0f172a; --ink-2:#1f2937;
  --paper:#ffffff; --subtle:#f7fbfa;
  --ring:0 0 0 3px rgba(20,147,124,.22);
  --shadow-1:0 22px 70px rgba(2,12,27,.12);
  --shadow-2:0 8px 28px rgba(2,12,27,.08);
  --shadow-3:0 3px 10px rgba(2,12,27,.06);
  font-family:var(--font);
  position:relative;
  background:var(--paper);
  border:1px solid var(--br);
  border-radius:16px;
  box-shadow:var(--shadow-1), var(--shadow-2);
  overflow:hidden;
  isolation:isolate;
}

/* big soft vignette below the card for “floating paper” effect */
.contract-doc::before,
.contract-doc::after{
  content:"";
  position:absolute; inset:auto -8% -24px -8%;
  height:50px; border-radius:50%;
  filter:blur(18px);
  background:radial-gradient(50% 80% at 50% 50%, rgba(12,26,52,.12), transparent 70%);
  z-index:-1;
}
.contract-doc::after{
  inset:auto -20% -44px -20%;
  height:70px; filter:blur(26px);
  opacity:.7;
}

/* Header */
.contract-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding:16px 18px;
  border-bottom:1px solid var(--br);
  background:
    linear-gradient(180deg,#fff 0%,#f7fbf9 70%,#f2faf6 100%),
    radial-gradient(1200px 80px at 50% -40px, rgba(20,147,124,.06), transparent);
}
.contract-title{ display:flex; align-items:center; gap:12px; color:var(--ink); }
.contract-title .pill{
  display:inline-flex; align-items:center; gap:6px; padding:6px 12px;
  background:var(--chip); border:1px solid #d9f2ea; border-radius:999px;
  font-weight:800; font-size:13px; color:#145e50; box-shadow:inset 0 1px 0 rgba(20,147,124,.12);
}
.contract-title .who{ font-size:18px; font-weight:900; letter-spacing:.2px; color:#0d152a }
.contract-actions{ display:flex; gap:8px }
.btn{
  appearance:none; padding:9px 12px; border-radius:10px; cursor:pointer; font-weight:900;
  border:1px solid #e8edf3; background:#fff; color:#0c1223;
  box-shadow:0 1px 0 rgba(2,8,23,.04), 0 6px 14px rgba(2,8,23,.06);
  transition:transform .08s ease, box-shadow .15s ease, background .15s ease, border-color .15s ease;
}
.btn:hover{ background:#f7fafc; border-color:#dfe7ef; box-shadow:0 10px 24px rgba(2,8,23,.10) }
.btn:active{ transform:translateY(0.5px) }
.btn:focus-visible{ outline:0; box-shadow:var(--ring) }
.btn.sm{ padding:7px 10px; font-size:12px; border-radius:9px }

  .icd10-item {
    padding: 6px 10px;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
    line-height: 1.3;
  }
  .icd10-item:last-child {
    border-bottom: none;
  }
  .icd10-item:hover {
    background: #f3f4f6;
  }
  .icd10-code {
    font-weight: 600;
    margin-right: .4rem;
  }
  .icd10-title {
    color: #4b5563;
  }
/* Body & sections */
.contract-body{
  padding:0px;
  line-height:1.4; font-size:13px; color:var(--fg);
  background:
    linear-gradient(0deg, #fff 0%, #fff 100%),
    radial-gradient(900px 140px at 50% -40px, rgba(20,147,124,.04), transparent);
}
.section-title{
  position:relative;
  font-weight:900; color:#0c1830; font-size:15px; letter-spacing:.2px;
  margin:18px 0 10px;
  padding-top:8px;
}
.section-title::after{
  content:""; display:block; height:1px; margin-top:10px;
  background:linear-gradient(90deg, rgba(20,147,124,.35), rgba(20,147,124,0));
}
/* Search ველი */
input[type="search"]{
  width:100%;
  padding:10px 12px;
  border:1px solid var(--stroke);
  border-radius:10px;
  background:#fff;
  color:#000;
  transition: border-color .15s, box-shadow .15s;
}
input[type="search"]:focus-visible{
  border-color:#9adfd1;
  box-shadow:var(--focus);
}

/* ცარიელი შედეგების მწკრივი უფრო თვალსაჩინო */
#rows tr.empty-row td{
  padding:28px 10px;
  font-weight:800;
}

/* Patients header ჩარჩოში რომ არ დაიკარგოს */
.patients-table thead th{
  position: sticky;
  top: 0;
  z-index: 1;
}

/* Rows & fields */
.row{ display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; margin:10px 0 }
.row .lbl{ color:var(--muted); min-width:110px; font-size:13px; text-transform:uppercase; letter-spacing:.6px }
.i, .ta{
  border:1px solid var(--br); background:#fbfffe; border-radius:12px;
  padding:10px 12px; font:inherit; width:auto; min-width:220px; color:#0d152a;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.4), var(--shadow-3);
  transition:border-color .15s ease, box-shadow .15s ease, background .15s.ease;
}
.i::placeholder, .ta::placeholder{ color:#9aa3ae }
.i:hover, .ta:hover{ border-color:#d6e6e1; background:#fcfffd }
.i:focus-visible, .ta:focus-visible{ outline:0; border-color:#99decf; box-shadow:var(--ring) }
.ta{ width:100%; min-height:92px; resize:vertical; line-height:1.55 }

.row.compact .i{ min-width:160px }

/* Micro separators to mimic official forms */
.row + .row{ position:relative }
.row + .row::before{
  content:""; position:absolute; left:0; right:0; top:-6px; height:1px;
  background:linear-gradient(90deg, rgba(14,165,233,.2), rgba(20,147,124,.12) 30%, transparent 80%);
}

/* Signature line */
.sigline{
  display:inline-block; min-width:80mm; border-bottom:1px solid #0b0b0b30;
  height:10mm; vertical-align:bottom; box-shadow:inset 0 -1px 0 rgba(0,0,0,.12);
}

/* ===== PRINT (scoped to the temporary print frame) ===== */
@page{ size:A4 portrait; margin:14mm 16mm 16mm 16mm }
html.contract-printing, html.contract-printing body{
  background:#fff !important; color:#000 !important;
  font-family:"DejaVu Sans","Noto Sans Georgian","Sylfaen","Arial Unicode MS",Arial,Helvetica,sans-serif !important;
}
html.contract-printing body *{ display:none !important }
html.contract-printing #_contractPrintRoot,
html.contract-printing #_contractPrintRoot *{ display:revert !important }
@supports not (display:revert){
  html.contract-printing #_contractPrintRoot,
  html.contract-printing #_contractPrintRoot *{ display:initial !important }
}
html.contract-printing #_contractPrintRoot{
  box-shadow:none !important; border:0 !important; padding:0 !important; background:#fff !important;
}

/* Typography for print */
html.contract-printing #_contractPrintRoot{ font-size:12.5pt !important; line-height:1.4 !important }
html.contract-printing .contract-head{ border:0 !important; background:none !important; padding:0 0 6mm 0 !important }
html.contract-printing .contract-title .pill{
  background:none !important; border:0 !important; padding:0 !important; font-size:12pt !important; color:#000 !important
}
html.contract-printing .contract-title .who{ font-size:15pt !important; color:#000 !important }
html.contract-printing .contract-actions{ display:none !important }
html.contract-printing .contract-body{ padding:0 !important }

/* Rows/labels in print */
html.contract-printing .row{ gap:6mm !important; margin:2.4mm 0 !important; break-inside:avoid !important }
html.contract-printing .row .lbl{ min-width:45mm !important; color:#000 !important; font-weight:700 !important }
html.contract-printing .row::before{ display:none !IMPORTANT }

/* Replace inputs with clean text (JS injects .print-text*) */
/* Controls print as plain text — JS კონვერტაცია აღარ არის საჭირო */
html.contract-printing *{ text-shadow:none !important; box-shadow:none !important; filter:none !important; }

html.contract-printing input,
html.contract-printing textarea,
html.contract-printing select{
  display:inline-block !important;
  border:0 !important; outline:0 !important; background:transparent !important;
  box-shadow:none !important; padding:0 !important; margin:0 !important;
  width:auto !important; min-width:35mm !important;
  color:#000 !important; font:inherit !important;
}
html.contract-printing textarea{ white-space:pre-wrap !important; }

/* Footer page numbers */
html.contract-printing .print-footer{ position:fixed; right:16mm; bottom:10mm; font-size:9pt; color:#000 }
@page{ @bottom-right{ content:"გვერდი " counter(page) " / " counter(pages) } }

/* Reduced motion */
@media (prefers-reduced-motion:reduce){
  *{ animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; scroll-behavior:auto !important }
}
</style>
<div class="contract-doc printable" id="contractDoc">
  <div class="contract-head">
    <div class="contract-title">
      <span class="who">ხელშეკრულება სამედიცინო მომსახურების შესახებ</span>
    </div>
    <div class="contract-actions">
      <button type="button" class="btn sm" onclick="printOnly('#contractDoc')">ბეჭდვა</button>
    </div>
  </div>

  <div class="contract-body">
    <div class="row" style="justify-content:center;margin-bottom:20px;">
      <?php if ($is_guardian): ?>
        <div style="font-size:14px;color:#6c757d;">
          პაციენტი: <b style="color:#0d152a;"><?= h($child_name) ?></b><br>
          კანონიერი წარმომადგენელი: <b style="color:#0d152a;"><?= h($sign['name'] ?? '') ?></b>
        </div>
      <?php else: ?>
        <div style="font-size:14px;color:#6c757d;">
          პაციენტი: <b style="color:#0d152a;"><?= h($sign['name'] ?: $child_name) ?></b>
        </div>
      <?php endif; ?>
    </div>

    <div class="row" style="justify-content:center">
      <div class="lbl"></div>
      <div style="font-weight:800; color:#0e1a34; letter-spacing:.2px;font-size:16px;">ხელშეკრულება სამედიცინო მომსახურების შესახებ</div>
    </div>

    <div class="row compact">
      <div class="lbl">ქალაქი</div>
      <input class="i" type="text" value="<?= h($clinic_legal['city']) ?>">
      <div class="lbl">თარიღი</div>
      <input class="i" type="text" value="<?= h($now) ?>">
    </div>

    <div class="row">
      <div class="lbl">ერთი მხრივ</div>
      <!-- party -> sign (will be the relative if patient is <18 and relative_* present) -->
      <input class="i" type="text" value="<?= h($sign['name'] ?? '') ?>" placeholder="სახელი/გვარი">
      <input class="i" type="text" value="<?= h($sign['personal_id'] ?? '') ?>" placeholder="პ/ნ">
      <div class="lbl">მისამართი</div>
      <input class="i" style="min-width:360px" type="text" value="<?= h($sign['address'] ?? '') ?>" placeholder="მისამართი">
    </div>

    <div class="row">
      <div class="lbl">ელ.ფოსტა</div>
      <!-- party -> sign -->
      <input class="i" type="text" value="<?= h($sign['email'] ?? '') ?>" placeholder="example@mail.com">
    </div>

    <div class="row">
      <textarea class="ta" lang="ka">
(შემდგომში — „პაციენტი“ ან „კლიენტი“) და მეორე მხრივ <?= h($clinic_legal['name']) ?>, ს/კ: <?= h($clinic_legal['id']) ?>, წარმოდგენილი დირექტორის <?= h($clinic_legal['director']) ?> (პ/ნ <?= h($clinic_legal['director_pid']) ?>) სახელით (შემდგომში — „კლინიკა“), მოქმედი საქართველოს კანონმდებლობის საფუძველზე, ვაფორმებთ წინამდებარე ხელშეკრულებას შემდეგ პირობებზე:
      </textarea>
    </div>

    <div class="section-title">1. ხელშეკრულების საგანი</div>
    <div class="row">
      <div class="lbl">მომსახურება</div>
      <div><?= h($facility) ?> ახორციელებს</div>
      <input class="i" type="text" value="სამედიცინო მომსახურებას">
      <input class="i" type="text" value="<?= h($child_name) ?>" placeholder="პაციენტის სახელი, გვარი" style="min-width:320px">
    </div>

    <div class="row compact">
      <div class="lbl">პირადი №</div>
      <input class="i" type="text" value="<?= h($child_pid) ?>">
      <div class="lbl">დაბ. თარიღი</div>
      <input class="i" type="text" value="<?= h($child_dob) ?>" placeholder="YYYY-MM-DD">
    </div>

    <div class="row">
      <textarea class="ta" lang="ka">მეტყველების თერაპევტის კონსულტაციის მომსახურებას (ერთჯერადი თერაპიის ღირებულება შეადგენს 40 ლარს).</textarea>
    </div>

    <div class="section-title">2. ძირითადი უფლება-მოვალეობები</div>
    <div class="row">
      <textarea class="ta" lang="ka" style="min-height:180px">
2.1. პაციენტი/კლიენტი ვალდებულია:
  2.1.1. კლინიკას მიაწოდოს ზუსტი ინფორმაცია ჯანმრთელობის მდგომარეობისა და გადატანილი დაავადებების შესახებ;
  2.1.2. თითოეულ დაგეგმილ პროცედურაზე გამოცხადდეს შეთანხმებულ დროს; შეუძლებლობის შემთხვევაში გონივრულ ვადაში აცნობოს კლინიკას;
  2.1.3. შეთანხმებულ ვადაში გადაიხადოს მომსახურების საფასური.

2.2. პაციენტი/კლიენტი უფლებამოსილია:
  2.2.1. კლინიკისგან მოითხოვოს ჯეროვანი სამედიცინო მომსახურების გაწევა;
  2.2.2. გამოთქვას მოსაზრება მიღებულ მომსახურებასთან დაკავშირებით.

2.3. კლინიკა ვალდებულია:
  2.3.1. კლიენტს/პაციენტს გაუწიოს სამედიცინო მომსახურება ჯეროვნად;
  2.3.2. მოთხოვნის შემთხვევაში, კლიენტს/პაციენტს გონივრულ ვადაში მიაწოდოს ინფორმაცია მისთვის გაწეული მომსახურების შესახებ;
  2.3.3. კლიენტის/პაციენტისაგან მოითხოვოს ჯანმრთელობის მდგომარეობისა და გადატანილი დაავადებების შესახებ ზუსტი ინფორმაციის წარდგენა.
      </textarea>
    </div>

    <div class="section-title">3. კონფიდენციალურობა</div>
    <div class="row">
      <textarea class="ta" lang="ka">3.1. ნებისმიერი ინფორმაცია, რომელსაც კლინიკა სამედიცინო მომსახურების გაწევისას შეიტყობს, არის კონფიდენციალური და პაციენტის/კლიენტის თანხმობის გარეშე არ გადაეცემა მესამე პირებს, გარდა საქართველოს კანონმდებლობით გათვალისწინებული შემთხვევებისა.</textarea>
    </div>

    <div class="section-title">5. მომსახურების ანაზღაურება</div>
    <div class="row">
      <textarea class="ta" lang="ka">
5.1. კლიენტი/პაციენტი ვალდებულია კლინიკას გადაუხადოს მომსახურების საფასური მისი მიღებისთანავე.
5.2. თუ მომსახურება ხორცილდება პერიოდულად, თითოეული ვიზიტის ღირებულება იფარება მიღებისთანავე.
5.3. დაზღვევის მომსახურების შემთხვევაში, გადახდა ხდება დაზღვევის პირობების შესაბამისად.
      </textarea>
    </div>

    <div class="section-title">7. დავათა გადაწყვეტა</div>
    <div class="row">
      <textarea class="ta" lang="ka">7.1. ყველა დავა გადაწყდება მოლაპარაკების გზით. 7.2. შეუთანხმებლობის შემთხვევაში — სასამართლოს წესით.</textarea>
    </div>

    <div class="section-title">8. ხელშეკრულების შეწყვეტა</div>
    <div class="row">
      <textarea class="ta" lang="ka">8.1. ხელშეკრულება შეიძლება შეწყდეს ერთ-ერთი მხარის ინიციატივით, მხარეთა შეთანხმებით, ვადის გასვლით ან კანონით გათვალისწინებულ შემთხვევებში.</textarea>
    </div>

    <div class="section-title">9. დასკვნითი დებულებები</div>
    <div class="row">
      <textarea class="ta" lang="ka">9.1. ხელშეკრულება ძალაშია ხელმოწერისთანავე. 9.2. ცვლილებები ძალაში შევა მხოლოდ მხარეთა წერილობითი შეთანხმებით. 9.3. ხელშეკრულება შედგენილია ორ ეგზემპლარად, ქართულ ენაზე.</textarea>
    </div>

    <div class="section-title">მხარეთა ხელმოწერა</div>

    <div class="row">
      <div class="lbl">კლინიკა</div>
      <div>
        <input class="i" type="text" value="<?= h($clinic_legal['name']) ?>"> — ს/კ
        <input class="i" type="text" value="<?= h($clinic_legal['id']) ?>"> — დირექტორი:
        <input class="i" type="text" value="<?= h($clinic_legal['director']) ?>"> (პ/ნ
        <input class="i" type="text" value="<?= h($clinic_legal['director_pid']) ?>">)
      </div>
    </div>

    <div class="row">
      <!-- label becomes dynamic -->
      <div class="lbl"><?= h($sign_label) ?></div>
      <div>
        <span>სახელი/გვარი</span>
        <input class="i" type="text" value="<?= h($sign['name'] ?? '') ?>">
        <span>პ/ნ</span>
        <input class="i" type="text" value="<?= h($sign['personal_id'] ?? '') ?>">
      </div>
    </div>

    <div class="row">
      <div class="lbl">ხელმოწერები</div>
      <span class="sigline"></span>
      <span style="min-width:12mm"></span>
      <span class="sigline"></span>
    </div>

    <div class="print-footer"></div>
  </div>
</div>

<script>
/**
 * contractPrint(selector)
 * - Clones the node
 * - Replaces inputs/selects/textareas with plain text equivalents
 * - Shows only the clone during print in clean, large Georgian font
 */
function contractPrint(selector){
  const src = document.querySelector(selector);
  if(!src){ window.print(); return; }

  // Build a clean clone
  const clone = src.cloneNode(true);
  clone.id = '_contractPrintRoot';

  // Convert controls -> text spans
  const orig = src.querySelectorAll('input, textarea, select');
  const cln  = clone.querySelectorAll('input, textarea, select');

  const asText = (el) => {
    if(!el) return {kind:'text', text:''};
    const tag = el.tagName, type = (el.getAttribute('type')||'text').toLowerCase();
    if(tag==='TEXTAREA') return {kind:'textarea', text: el.value||''};
    if(tag==='SELECT'){
      const opts = Array.from(el.selectedOptions||[]);
      return {kind:'select', text: opts.length ? opts.map(o=>o.textContent||o.value||'').join(', ') : (el.value||'')};
    }
    if(tag==='INPUT'){
      if(type==='hidden') return {kind:'hidden', text:''};
      if(type==='checkbox'||type==='radio') return {kind:type, text: el.checked ? '✓' : ''};
      return {kind:'input', text: el.value||''};
    }
    return {kind:'text', text:''};
  };

  for(let i=0;i<cln.length;i++){
    const c = cln[i];
    const o = orig[i] || c;
    const data = asText(o);

    if(data.kind==='hidden'){ c.remove(); continue; }

    const span = document.createElement('span');
    span.className = ({
      textarea:'print-textarea',
      select:'print-select',
      input:'print-text',
      checkbox:'print-check',
      radio:'print-check',
      text:'print-text'
    })[data.kind] || 'print-text';
    span.textContent = data.text;
    c.replaceWith(span);
  }

  // Inject into DOM, print only the clone
  document.documentElement.classList.add('contract-printing');
  document.body.appendChild(clone);
  window.onafterprint = () => {
    document.documentElement.classList.remove('contract-printing');
    clone.remove();
    window.onafterprint = null;
  };
  window.print();
}

// Back-compat for existing onclick="printOnly('#contractDoc')"
if (typeof window.printOnly !== 'function') {
  window.printOnly = contractPrint;
}

// autosize textareas on screen
(function(){
  const grow = t => { t.style.height='auto'; t.style.height=(t.scrollHeight)+'px'; };
  document.querySelectorAll('#contractDoc .ta').forEach(t => { grow(t); t.addEventListener('input', () => grow(t)); });
})();
</script>
<?php
echo ob_get_clean(); exit;
}


/* ----------------------- CONSENT ----------------------- */
if ($action === 'consent_show') {
  header('Content-Type: text/html; charset=utf-8');
  $sid = (int)($_GET['service_id'] ?? 0);
  if ($sid <= 0) { http_response_code(400); echo 'bad id'; exit; }

  try {
    $st = $pdo->prepare("
      SELECT 
        ps.service_record_id AS service_id, ps.patient_id, ps.created_at,
        p.first_name, p.last_name, p.personal_id, p.birthdate,
        CONCAT(d.first_name,' ',d.last_name) AS doctor_name
      FROM patient_services ps
      LEFT JOIN patients p ON p.id = ps.patient_id
      LEFT JOIN doctors  d ON d.id = ps.doctor_id
      WHERE ps.service_record_id=? LIMIT 1
    ");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $row = false; }

  if (!$row) { echo '<div class="muted">ვიზიტი ვერ მოიძებნა.</div>'; exit; }

  $now       = date('d-m-Y H:i');
  $fullName  = trim(($row['first_name']??'').' '.($row['last_name']??'')); 
  $personal  = (string)($row['personal_id'] ?? '');
  $dob       = $row['birthdate'] ? date('Y-m-d', strtotime($row['birthdate'])) : '';
  $doctor    = (string)($row['doctor_name'] ?? '');
  $facility  = (string)($_SESSION['facility_name'] ?? '„კლინიკა სანმედი“');

  ob_start(); ?>
  <div class="card form100a-card printable">
    <div class="form100a-head">
      <div class="form100a-title">
        <span class="pill">თანხმობა</span>
        <span><?= h($fullName ?: '—') ?></span>
      </div>
      <div class="form100a-actions">
        <button type="button" class="btn ghost sm" onclick="printOnly(this.closest('.printable'))">ბეჭდვა</button>
      </div>
    </div>

    <div class="form100a-body" id="consentRoot" data-service="<?= (int)$sid ?>">
      <div style="text-align:center"><span style="font-size:16px;font-weight:600;">თანხმობა სამედიცინო მომსახურებაზე</span></div>
      <br>

      <div class="fmdv">
        <span>დრო/თარიღი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($now) ?>"></span>
        <span class="ml">დადგ. ადგილი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($facility) ?>"></span>
      </div>

      <div class="fmdv">
        <span>პაციენტი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($fullName) ?>" placeholder="სახელი გვარი"></span>
        <span>პ/ნ</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($personal) ?>" placeholder="პირადი №"></span>
        <span>დაბ. თარიღი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($dob) ?>" placeholder="YYYY-MM-DD"></span>
      </div>

      <div class="fmdv">
        <span>მკურნალი ექიმი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($doctor) ?>" placeholder="ექიმი"></span>
      </div>

      <div class="fmdv">
        <div class="si-wrapper" style="width:100%;">
          <textarea lang="ka" class="speech-input nondrg ko auto-grow" style="width:100%;height:120px;overflow:hidden;">
ვადასტურებ, რომ მივიღე ამომწურავი ინფორმაცია შეპირებული სამედიცინო მომსახურების შესახებ (მიზანი, მეთოდი, სარგებელი, შესაძლო რისკები და გართულებები), ალტერნატიულ მეთოდებზე და უარის თქმის შემთხვევაში შესაძლო შედეგებზე. მივიღე პასუხები ყველა არსებულ შეკითხვაზე და მაქვს შესაძლებლობა დამატებითი კითხვების დასმის.

ვადასტურებ, რომ ვეთანხმები აღნიშნული მომსახურების ჩატარებას ზემოაღნიშნული ინფორმაციის საფუძველზე.
          </textarea>
        </div>
      </div>

      <div class="fmdv">
        <span>დამატებითი შენიშვნები</span>
      </div>
      <div class="fmdv">
        <textarea class="nondrg ko auto-grow" style="width:100%;height:80px;overflow:hidden;" placeholder="შენიშვნები (არასავალდებულო)"></textarea>
      </div>

      <div class="fmdv"><span class="hd">ხელმოწერები</span></div>

      <div class="fmdv">
        <span>პაციენტი/მინდობილიც</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($fullName) ?>" placeholder="სახელი, გვარი"></span>
        <span>პ/ნ</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($personal) ?>" placeholder="პირადი №"></span>
      </div>

      <div class="fmdv">
        <span>მკურნალი ექიმი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($doctor) ?>" placeholder="ექიმი"></span>
      </div>

      <div class="fmdv">
        <span>თარიღი</span>
        <span class="ml"><input type="text" class="nondrg ko" value="<?= h($now) ?>"></span>
      </div>
    </div>
  </div>

  <script>
  // autosize textareas
  (function(){
    const grow = t => { t.style.height = 'auto'; t.style.height = (t.scrollHeight) + 'px'; };
    document.querySelectorAll('.auto-grow').forEach(t => { grow(t); t.addEventListener('input', () => grow(t)); });
  })();
  </script>
  <?php
  echo ob_get_clean(); exit;
}

/* ----------------------- CONSENT WRITTEN (new, fixes 500) ----------------------- */
if ($action === 'consent_written') {
  header('Content-Type: text/html; charset=utf-8');
  $sid = (int)($_GET['service_id'] ?? 0);
  if ($sid <= 0) { http_response_code(400); echo 'bad id'; exit; }

  try {
    $st = $pdo->prepare("
      SELECT 
        ps.service_record_id AS service_id, ps.patient_id, ps.created_at,
        p.first_name, p.last_name, p.personal_id, p.birthdate,
        CONCAT(d.first_name,' ',d.last_name) AS doctor_name
      FROM patient_services ps
      LEFT JOIN patients p ON p.id = ps.patient_id
      LEFT JOIN doctors  d ON d.id = ps.doctor_id
      WHERE ps.service_record_id=? LIMIT 1
    ");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $row = false; }

  if (!$row) { echo '<div class="muted">ვიზიტი ვერ მოიძებნა.</div>'; exit; }

  $now       = date('d-m-Y H:i');
  $fullName  = trim(($row['first_name']??'').' '.($row['last_name']??''));
  $personal  = (string)($row['personal_id'] ?? '');
  $dob       = $row['birthdate'] ? date('Y-m-d', strtotime($row['birthdate'])) : '';
  $doctor    = (string)($row['doctor_name'] ?? '');
  $facility  = (string)($_SESSION['facility_name'] ?? '„კლინიკა სანმედი“');
  $city      = 'ქ. თბილისი';

  ob_start(); ?>
  <div class="card form100a-card printable">
    <div class="form100a-head">
      <div class="form100a-title">
        <span class="pill">თანხმობის ხელწერილი</span>
        <span><?= h($fullName ?: '—') ?></span>
      </div>

      <div class="form100a-actions">
        <button type="button" class="btn ghost sm" onclick="setConsent('child')">ბავშვის თანხმობა</button>
        <button type="button" class="btn ghost sm" onclick="setConsent('adult')">ზრდასრულის თანხმობა</button>
        <button type="button" class="btn ghost sm" onclick="printOnly(this.closest('.printable'))">ბეჭდვა</button>
      </div>
    </div>

    <div class="form100a-body">
      <div id="910" class="olvn tmpldiv nondrg mcffx">
        <input type="hidden" id="frmlni" value="103">
        <input type="hidden" id="tmplload" value="globaltemplateCaller">

        <div style="text-align:center"><span style="font-size:14px;font-weight:bold;">თანხმობის ხელწერილი</span></div>
        <br>

        <div class="fmdv"><span><input type="text" class="nondrg ko" value="<?= h($city) ?>"></span></div>
        <div class="fmdv">
          <span class="ml"><input type="text" class="nondrg ko" value="<?= h($now) ?>"></span>
        </div>

        <div class="fmdv">
          <span>
            <div class="rpdv">
              <textarea
                id="consentText"
                data-facility="<?= h($facility) ?>"
                class="nondrg ko auto-grow"
                style="width:100%;height:140px;"
              >თანხმობას ვაცხადებ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ405695323) მიერ განხორციელდეს ჩემი ვიდეო მონიტორინგი, აგრეთვე დამუშავდეს ჩემი პერსონალური მონაცემები (სახელი, გვარი, პირადი ნომერი, დაბადების თარიღი, ტელეფონის ნომერი, ელექტრონული ფოსტა, ინფორმაცია სქესის შესახებ, ინფორმაცია მისამართის შესახებ და ა.შ.). ასევე თანახმა ვარ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ 405695323) მიერ განხორციელდეს ჩემი არასრულწლოვანი შვილი</textarea>
            </div>
          </span>
        </div>

        <div class="fmdv">
          <span>სახელი/გვარი</span>
          <span class="ml"><input type="text" class="nondrg ko" value="<?= h($fullName) ?>"></span>
        </div>

        <div class="fmdv">
          <span>პირადი ნომერი</span>
          <span class="ml"><input type="text" class="nondrg ko" value="<?= h($personal) ?>"></span>
        </div>

        <div class="fmdv">
          <span>დაბადების თარიღი</span>
          <span class="ml"><input type="text" class="nondrg ko" value="<?= h($dob) ?>"></span>
        </div>
<br>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea lang="ka" class="speech-input nondrg ko auto-grow" style="width:100%;height:220px;overflow-y:auto;padding-right:26px;">
პერსონალური მონაცემები (სახელი, გვარი, პირადი ნომერი, დაბადების თარიღი, ტელეფონის ნომერი, ელექტრონული ფოსტა, ინფორმაცია სქესის შესახებ, ინფორმაცია მისამართის შესახებ, ინფორმაცია ჯანმრთელობის მდგომარეობის შესახებ და ა.შ.). თანახმა ვარ, რომ აღნიშნული ვიდეო მონიტორინგი განხორციელდეს დამსაქმებლის - შპს ,,სანმედი’’-ს (ს/ნ405695323) ოფისში, შემდეგ მისამართზე: ქ. თბილისი, მებრძოლთა ქუჩა N55. შპს ,,კლინიკა სანმედი’’-სგან (ს/ნ405695323) აღნიშნულ ხელწერილზე ხელმოწერამდე განმემარტა, რომ ვიდეო მონიტორინგის განხორციელებაზე/მონაცემთა დამუშავებაზე თანხმობის გამოხატვა არის ნებაყოფლობითი და ნებისმიერ დროს მაქვს უფლება გამოვიხმო აღნიშნული თანხმობა. ასევე განმემარტა, რომ აღნიშნული მონაცემები შენახული იქნება არა უმეტეს ათი წლის ვადით, ვიდეო მონიტორინგი განხორციელდება აღნიშნულ დაწესებულებაში ჩემი ყოფნის პერიოდის განმავლობაში. ვიდეო ჩანაწერებზე წვდომისა და შენახვის უფლება ექნება შპს ,,კლინიკა სანმედი’’-ს (ს/ნ405695323) დირექტორს და მათი განადგურება განხორციელდება მის მიერ, შენახვის ვადის გასვლისთანავე. ასევე მეცნობა, რომ დამუშავებული ვიდეო ჩანაწერები, ასევე სხვა პერსონალური მონაცემები შეინახება სპეციალურ და დაცულ მოწყობილობაში და დაცული იქნება ნებისმიერი არამართლზომიერი ხელყოფისა და გამოყენებისგან. ზემოთ აღნიშნულის თაობაზე მოხდა ჩემი ინფორმირება, რაზეც თანახმა ვარ და პრეტენზია არ გამაჩნია.
           </textarea>
            </div>
          </span>
        </div>

        <div class="fmdv">
          <span>მკურნალი ექიმი</span>
          <span class="ml"><input type="text" class="nondrg ko" value="<?= h($doctor) ?>"></span>
        </div>
<br>
        <div class="fmdv">
          <span>სახელი/გვარი</span>
          <span style="margin-left:12px">_______________________________________</span>
        </div>
        <br>
                <div class="fmdv">
          <span>პირადი ნომერი</span>
          <span style="margin-left:12px">_______________________________________</span>
        </div>
        <br>
                <div class="fmdv">
          <span>ხელმოწერა</span>
          <span style="margin-left:12px">_______________________________________</span>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const grow = t => { t.style.height = 'auto'; t.style.height = (t.scrollHeight) + 'px'; };
    document.querySelectorAll('.auto-grow').forEach(t => { grow(t); t.addEventListener('input', () => grow(t)); });
  })();
  </script>

  <?php
  echo ob_get_clean(); exit;
}

/* ----------------------- FORM 100/ა (IMPROVED, official print) ----------------------- */
if ($action === 'form100a') {
  header('Content-Type: text/html; charset=utf-8');
  $sid = (int)($_GET['service_id'] ?? 0);
  if ($sid <= 0) { http_response_code(400); echo 'bad id'; exit; }

  // ვიზიტი/პაციენტი
  try {
    $st = $pdo->prepare("
      SELECT 
        ps.service_record_id AS service_id, ps.patient_id, ps.created_at,
        p.first_name, p.last_name, p.birthdate, p.personal_id,
        COALESCE(p.address,'') AS address,
        CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
        COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' ',COALESCE(s.name,'')),' '), s.name, '') AS service_title
      FROM patient_services ps
      LEFT JOIN patients p ON p.id = ps.patient_id
      LEFT JOIN doctors  d ON d.id = ps.doctor_id
      LEFT JOIN services s ON s.id = ps.service_id
      WHERE ps.service_record_id=? LIMIT 1
    ");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $row = false; }

  if (!$row) { echo '<div class="muted">ვიზიტი ვერ მოიძებნა.</div>'; exit; }

  $pid        = (int)$row['patient_id'];
  $visitDT    = $row['created_at'] ? date('d-m-Y H:i', strtotime($row['created_at'])) : '';
  $visitDay   = $row['created_at'] ? date('Y-m-d',     strtotime($row['created_at'])) : '';
  $fullName   = trim(($row['first_name']??'').' '.($row['last_name']??''));
  $dob        = $row['birthdate'] ? date('d/m/Y', strtotime($row['birthdate'])) : '';
  $personal   = (string)($row['personal_id'] ?? '');
  $address    = (string)($row['address']     ?? '');
  $doctorName = (string)($row['doctor_name'] ?? '');
  $facility   = (string)($_SESSION['facility_name'] ?? '„სანმედი“');
  $issueDate  = $row['created_at'] ? date('d-m-Y', strtotime($row['created_at'])) : date('d-m-Y');

  // Load existing saved payload (if any)
$existing = [];

if (
    ensureForm100aTable($pdo)
) {
    try {
        // First try by service_record_id
        $q = $pdo->prepare("SELECT payload FROM patient_form100a WHERE service_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$sid]);
        $pl = $q->fetchColumn();
        // Fallback: old records stored patient_id as service_id
        if (!$pl && $pid > 0) {
            $q2 = $pdo->prepare("SELECT payload FROM patient_form100a WHERE service_id=? AND patient_id=? ORDER BY id DESC LIMIT 1");
            $q2->execute([$pid, $pid]);
            $pl = $q2->fetchColumn();
        }
        if ($pl) {
            $existing = json_decode($pl, true) ?: [];
        }
    } catch (Throwable $e) {
        logForm100a($pdo, 'load_error', [
            'service_id' => $sid,
            'patient_id' => $pid,
            'note' => 'Load exception: ' . $e->getMessage(),
        ]);
    }
    // Log load event with field tracking
    logForm100a($pdo, !empty($existing) ? 'load' : 'load_fallback', [
        'service_id' => $sid,
        'patient_id' => $pid,
        'payload' => $existing,
        'payload_size' => strlen($pl ?: ''),
        'note' => !empty($existing) ? 'Loaded OK, fields=' . count($existing) : 'No data found',
    ]);
}


  // 8b. Auto-fill icd10_code from diag if not saved yet
  if (empty($existing["icd10_code"]) && !empty($existing["diag"])) {
    if (preg_match("/^([A-Z]\\d{2}(?:\\.\\d{1,2})?)\\s/", $existing["diag"], $m)) {
      $existing["icd10_code"] = $m[0] ? trim($m[1]) : "";
    }
  }

  // 9. დიაგნოზი — auto-fill if empty
  $diagText = $existing['diag'] ?? '';
  if ($diagText === '') {
    if (function_exists('tableExists') && tableExists($pdo, 'patient_diagnoses')) {
      try {
        $qd = $pdo->prepare("
          SELECT TRIM(CONCAT(COALESCE(code,''), CASE WHEN code<>'' AND name<>'' THEN ' - ' ELSE '' END, COALESCE(name,''))) AS dn
          FROM patient_diagnoses
          WHERE patient_id=?
          ORDER BY created_at DESC, id DESC
          LIMIT 1
        ");
        $qd->execute([$pid]);
        $drow = $qd->fetch(PDO::FETCH_ASSOC);
        if (!empty($drow['dn'])) { $diagText = 'ძირითადი დიაგნოზი:'."\n".$drow['dn']."\n\n"; }
      } catch (Throwable $e) { /* silent */ }
    }
  }

  // 12. იმავე დღეს შესრულებული სერვისები
  $doneItems = [];
  try {
    $qi = $pdo->prepare("
      SELECT 
        COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' ',COALESCE(s.name,'')),' '), s.name, '') AS t,
        COUNT(*) AS cnt
      FROM patient_services ps
      JOIN services s ON s.id = ps.service_id
      WHERE ps.patient_id=? AND DATE(ps.created_at)=?
      GROUP BY t
      ORDER BY t
    ");
    $qi->execute([$pid, $visitDay]);
    foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $it) {
      if (!$it['t']) continue;
      $doneItems[] = ['t' => $it['t'], 'cnt' => (int)$it['cnt']];
    }
  } catch (Throwable $e) { /* silent */ }

    // Defaults from existing
    $dest           = $existing['dest']           ?? 'დანიშნულებისამებრ წარსადგენად';
    $workplace      = $existing['workplace']      ?? '';
    $date_send      = $existing['date_send']      ?? '';
    $date_admit     = $existing['date_admit']     ?? '';
    $date_discharge = $existing['date_discharge'] ?? '';
    $course         = $existing['course']         ?? '';
    $therapy        = $existing['therapy']        ?? '';
    $cond_send      = $existing['cond_send']      ?? '';
    $cond_discharge = $existing['cond_discharge'] ?? '';
    $recom          = $existing['recom']           ?? '';
    $recom1         = $existing['recom1']          ?? '';
    
    /**
     * 🔹 ISSUE DATE — ყოველთვის ბოლო დამატებული თერაპიის / ფორმის თარიღი
     *   - თუ არსებობს Form100a ჩანაწერი → იღებს updated_at-ს
     *   - თუ არა → დღევანდელი თარიღი
     */
    /**
 * ✅ ISSUE DATE — კონკრეტული სერვისის თარიღი
 * ✅ DOCTOR     — კონკრეტული სერვისის ექიმი (არა ბოლო, არამედ გახსნილი სერვისის!)
 */
// $issueDate და $doctorName უკვე სწორად არის დაყენებული $row-დან (ხაზი 1946-1949)
// აქ დამატებით არაფერი გვჭირდება, რადგან $row იღებს კონკრეტულ service_id-ს


 ob_start(); ?>
  <div class="card form100a-card printable">
    <div class="form100a-head">
      <div class="form100a-title">
        <span class="pill">ფორმა № IV-100/ა</span>
        <span><?= h($fullName ?: '—') ?></span>
      </div>
      <div class="form100a-actions">
        <!-- NEW: Global templates (top-right, next to print/save) -->
        <select id="f100aTplSelect" class="btn ghost sm" style="min-width:180px;">
          <option value="">შაბლონები...</option>
        </select>
        <button type="button" class="btn ghost sm" id="btnSaveTpl100a">შაბლონად შენახვა</button>

        <!-- existing buttons (unchanged IDs for your current JS) -->
        <button type="button" class="btn ghost sm" id="btnPrint100a">ბეჭდვა</button>
        <button type="button" class="btn ghost sm" id="btnSave100a">შენახვა</button>
      </div>
    </div>

    <div class="form100a-body print-form-iv100a" id="f100aRoot" data-service="<?= (int)$sid ?>">
      <div id="734" class="olvn tmpldiv nondrg mcffx">

        <table style="width:100%;table-layout:fixed;border-collapse:collapse;" border="0">
          <tbody>
            <tr>
              <td width="50%" style="text-align:left;vertical-align:top"><span class="iv100a-date" style="font-size:11px;color:#555;"><?= h($visitDT) ?></span></td>
              <td width="50%" style="text-align:right;vertical-align:top;line-height:12px;">
                <span class="iv100a-approval">დამტკიცებულია<br>
                საქართველოს შრომის, ჯანმრთელობისა<br>
                და სოციალური დაცვის მინისტრის<br>
                2007 წ. 09.08 № 338/ნ ბრძანებით</span>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="iv100a-title-1">სამედიცინო დოკუმენტაცია ფორმა № IV-100/ა</div>
        <div class="iv100a-title-2">ცნობა ჯანმრთელობის მდგომარეობის შესახებ</div>

        <div class="fmdv" style="justify-content:flex-end"><span>ცნობა # <?= (int)$sid ?></span></div>

        <!-- 1 -->
        <div class="fmdv">
          <span class="br">
            1. ცნობის გამცემი დაწესებულება ან/და ექიმი სპეციალისტი (სახელი, გვარი, სპეციალობა, სერტიფიკატის № / საკონტაქტო)
          </span>
        </div>
        <div class="fmdv">
          <span class="ml">
            <input type="text" class="nondrg ko" value="<?= h($facility) ?> — <?= h($doctorName ?: '—') ?>">
          </span>
        </div>

        <!-- 2 -->
        <div class="fmdv">
          <span class="br">2. დაწესებულების დასახელება, მისამართი სადაც იგზავნება ცნობა</span>
        </div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="dest"
                        style="width:100%;height:44px;overflow-y:hidden;padding-right:26px;"><?= h($dest) ?></textarea>
            </div>
          </span>
        </div>

        <!-- 3 -->
        <div class="fmdv">
          <span>3. პაციენტის სახელი და გვარი</span>
          <span class="ml">
            <input type="text" class="nondrg ko" value="<?= h($fullName) ?>">
          </span>
        </div>

        <!-- 4 -->
        <div class="fmdv">
          <span>4. დაბადების თარიღი (რიცხვი/თვე/წელი)</span>
          <span class="ml">
            <input type="text" class="nondrg ko" value="<?= h($dob) ?>">
          </span>
        </div>

        <!-- 5 -->
        <div class="fmdv">
          <span>5. პირადი ნომერი</span>
          <span class="ml">
            <input type="text" class="nondrg ko" value="<?= h($personal) ?>">
          </span>
          <span class="oj">(ივსება 16 წელს მიღწეული პირის შემთხვევაში)</span>
        </div>

        <!-- 6 -->
        <div class="fmdv">
          <span>6. მისამართი</span>
          <span class="ml">
            <input type="text" class="nondrg ko" data-f100a="address" value="<?= h($existing["address"] ?? $address) ?>">
          </span>
        </div>

        <!-- 7 -->
        <div class="fmdv">
          <span class="br">
            7. სამუშაო ადგილი და თანამდებობა (სასწავლებლის/კლასი/კურსი, თუ მოსწავლე/სტუდენტია)
          </span>
        </div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="workplace"
                        style="width:100%;height:44px;overflow-y:hidden;padding-right:26px;"><?= h($workplace) ?></textarea>
            </div>
          </span>
        </div>

        <!-- 8 -->
        <div class="fmdv"><span>8. თარიღები</span></div>
        <div class="fmdv">
          <span class="oj">ა) ექიმთან მიმართვის</span>
          <span class="ml">
            <input type="text" class="nondrg ko" value="<?= h($visitDT) ?>">
          </span>
          <span class="oj">ბ) სტაციონარში გაგზავნის</span>
          <span class="ml">
            <input type="text" class="nondrg ko" data-f100a="date_send" value="<?= h($date_send) ?>">
          </span>
          <span class="oj">გ) სტაციონარში მოთავსების</span>
          <span class="ml">
            <input type="text" class="nondrg ko" data-f100a="date_admit" value="<?= h($date_admit) ?>">
          </span>
          <span class="oj">დ) გაწერის</span>
          <span class="ml">
            <input type="text" class="nondrg ko" data-f100a="date_discharge" value="<?= h($date_discharge) ?>">
          </span>
        </div>


        <!-- 9 -->
         <div class="fmdv">
  <span>9. დასკვნა ჯანმრთელობის მდგომარეობის შესახებ / სრულდიაგნოზი</span>
</div>

<!-- ძირითადი დიაგნოზის ტექსტი -->
<div class="fmdv">
  <div class="si-wrapper icd10-wrapper" style="position:relative;">
    <textarea
      id="icd10_textarea"
      class="speech-input nondrg ko"
      data-f100a="diag"
      style="width:100%;height:120px;overflow-y:hidden;padding-right:26px;"
    ><?= h($diagText) ?></textarea>
  </div>
</div>

<!-- ICD-10 ძიების input + dropdown -->
<div class="fmdv">
  <div class="icd10-box" style="position:relative; max-width:400px;">
    <label for="icd10_input">ICD-10 კოდი / დასახელება</label>
    <input
      type="text"
      id="icd10_input"
      class="nondrg ko"
      data-f100a="icd10_code"
      autocomplete="off"
      placeholder="მაგ: A00 ან ქოლერა"
      value="<?= h($existing["icd10_code"] ?? "") ?>"
      style="width:100%;"
    >

    <!-- dropdown for suggestions -->
    <div id="icd10_results" style="
           display:none;
           position:absolute;
           left:0; right:0; top:100%;
           max-height:220px;
           overflow-y:auto;
           background:#fff;
           border:1px solid #d0d7e2;
           border-radius:6px;
           box-shadow:0 8px 24px rgba(15,23,42,.16);
           z-index:999;
           font-size:13px;
         ">
    </div>
  </div>
</div>

<!-- არჩევის რიგების სტილები (საჭიროა icd10-item / icd10-code / icd10-title) -->
<style>
  .icd10-item {
    display: flex;
    gap: 8px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 13px;
    line-height: 1.4;
  }
  .icd10-item:nth-child(2n) {
    background: #fafafa;
  }
  .icd10-item:hover {
    background: #e6f0ff;
  }
  .icd10-code {
    font-weight: 600;
    min-width: 58px;
    white-space: nowrap;
    color: #1a4fb8;
  }
  .icd10-title {
    flex: 1;
    color: #333;
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {

  /**
   * ICD-10 autocomplete (icd10_input / icd10_results / icd10_textarea)
   */

  const $input    = document.getElementById('icd10_input');
  const $results  = document.getElementById('icd10_results');
  const $textarea = document.getElementById('icd10_textarea');

  console.log('[ICD10] init', { input: $input, results: $results, textarea: $textarea });

  if (!$input || !$results) {
    console.warn('[ICD10] widgets not found in DOM');
    return;
  }

  let lastQuery       = '';
  let abortController = null;
  let blurHideTimer   = null;

  function clearResults() {
    $results.innerHTML = '';
    $results.style.display = 'none';
  }

  function appendToTextarea(line) {
    if (!$textarea) return;
    const current = ($textarea.value || '').trim();
    $textarea.value = current ? (current + "\n" + line) : line;
  }

  function renderItems(items) {
    if (!items || !items.length) {
      clearResults();
      return;
    }

    const frag = document.createDocumentFragment();

    items.forEach(function(item) {
      const code  = String(item.code || '').trim();
      const title = String(item.title || '').trim();
      const full  = (item.full && String(item.full).trim()) ||
                    (code && title ? (code + ' — ' + title) :
                     code || title || '');

      if (!full) return;

      const row = document.createElement('div');
      row.className = 'icd10-item';

      const spanCode  = document.createElement('span');
      spanCode.className = 'icd10-code';
      spanCode.textContent = code;

      const spanTitle = document.createElement('span');
      spanTitle.className = 'icd10-title';
      spanTitle.textContent = title || full;

      row.appendChild(spanCode);
      row.appendChild(spanTitle);

      row.addEventListener('mousedown', function(ev) {
        // blur-ზე რომ არ დაიკარგოს ჩამოსაშლელი
        ev.preventDefault();

        appendToTextarea(full);
        if (code) {
          $input.value = code;
        } else {
          $input.value = full;
        }

        clearResults();
      });

      frag.appendChild(row);
    });

    $results.innerHTML = '';
    $results.appendChild(frag);
    $results.style.display = 'block';
  }

  async function runSearch(q) {
    q = (q || '').trim();
    lastQuery = q;

    if (!q) {
      clearResults();
      return;
    }

    // ძველი მოთხოვნის გაუქმება
    if (abortController) {
      abortController.abort();
    }
    abortController = new AbortController();

    try {
      const url = '?action=icd10_search&q=' + encodeURIComponent(q);
      console.log('[ICD10] fetch', url);

      const resp = await fetch(url, {
        method: 'GET',
        signal: abortController.signal,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      if (!resp.ok) {
        throw new Error('HTTP ' + resp.status);
      }

      const data = await resp.json();
      console.log('[ICD10] response', data);

      // იმ შემთხვევაში, თუ ამ დროის განმავლობაში input შეიცვალა
      if (q !== lastQuery) {
        return;
      }

      if (data && data.status === 'ok' && Array.isArray(data.items)) {
        renderItems(data.items);
      } else {
        clearResults();
      }
    } catch (e) {
      if (e.name === 'AbortError') {
        // ნორმალურია სწრაფი টাইპინგისას
        return;
      }
      console.error('[ICD10] error:', e);
      clearResults();
    }
  }

  // input-ზე ძიება
  $input.addEventListener('input', function() {
    const q = this.value || '';
    runSearch(q);
  });

  // Esc → ჩამოსაშლის დამალვა
  $input.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      clearResults();
    }
  });

  // focus-ზე, თუ უკვე გვაქვს შედეგები, ისევ ვაჩვენოთ
  $input.addEventListener('focus', function() {
    if ($results.innerHTML.trim()) {
      $results.style.display = 'block';
    }
  });

  // blur-ზე – პატარა დაყოვნებით ვმალავთ,
  // რომ mousedown-ზე არჩევამ მოასწროს
  $input.addEventListener('blur', function() {
    blurHideTimer = setTimeout(function() {
      clearResults();
    }, 150);
  });

  // ჩამოსაშლელზე დაჭერისას blur-ის ტაიმერი არ იმუშავოს
  $results.addEventListener('mousedown', function() {
    if (blurHideTimer) {
      clearTimeout(blurHideTimer);
      blurHideTimer = null;
    }
  });

});
</script>

        <!-- 10 -->
        <div class="fmdv"><span>10. გადატანილი დაავადებები</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="past"
                        style="width:100%;height:60px;overflow-y:hidden;padding-right:26px;"><?= h($existing['past'] ?? '') ?></textarea>
            </div>
          </span>
        </div>

        <!-- 11 -->
        <div class="fmdv"><span>11. მოკლე ანამნეზი</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="anamn"
                        style="width:100%;height:120px;overflow-y:hidden;padding-right:26px;"><?= h($existing['anamn'] ?? '') ?></textarea>
            </div>
          </span>
        </div>

        <!-- 12 -->
        <div class="fmdv"><span>12. ჩატარებული დიაგნოსტიკური გამოკვლევები და კონსულტაციები</span></div>
        <div class="fmdv">
          <span>
            <div class="rpdv">
              <?php if (!$doneItems): ?>
                <div class="muted">—</div>
              <?php else: ?>
                <ul class="print-list" style="margin:0;padding-left:18px;">
                  <?php foreach ($doneItems as $it): ?>
                    <li><?= h($it['t']) ?> (<?= (int)$it['cnt'] ?>)</li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </span>
        </div>

        <!-- 13 -->
        <div class="fmdv"><span>13. ავადმყოფობის მიმდინარეობა</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="course"
                        style="width:100%;height:66px;overflow-y:hidden;padding-right:26px;"><?= h($course) ?></textarea>
            </div>
          </span>
        </div>

        <!-- 14 -->
        <div class="fmdv"><span>14. ჩატარებული მკურნალობა</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="therapy"
                        style="width:100%;height:66px;overflow-y:hidden;padding-right:26px;"><?= h($therapy) ?></textarea>
            </div>
          </span>
        </div>

        <!-- 15 -->
        <div class="fmdv"><span>15. მდგომარეობა სტაციონარში გაგზავნისას</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="cond_send"
                        style="width:100%;height:60px;overflow-y:hidden;padding-right:26px;"><?= h($cond_send) ?></textarea>
            </div>
          </span>
        </div>

        <!-- 16 -->
        <div class="fmdv"><span>16. მდგომარეობა სტაციონარიდან გაწერისას</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="speech-input nondrg ko"
                        data-f100a="cond_discharge"
                        style="width:100%;height:60px;overflow-y:hidden;padding-right:26px;"><?= h($cond_discharge) ?></textarea>
            </div>
          </span>
        </div>

        <!-- 17 -->
        <div class="fmdv"><span>17. სამკურნალო რეკომენდაციები</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="nondrg ko"
                        data-f100a="recom"
                        style="width:100%;height:90px;"><?= h($recom) ?></textarea>
            </div>
          </span>
        </div>
        <!-- 17 -->
        <div class="fmdv"><span>18. შრომითი რეკომენდაციები</span></div>
        <div class="fmdv">
          <span>
            <div class="si-wrapper">
              <textarea class="nondrg ko"
                        data-f100a="recom1"
                        style="width:100%;height:90px;"><?= h($recom1) ?></textarea>
            </div>
          </span>
        </div>
        <!-- 18 -->
        <div class="fmdv">
          <span>19. მკურნალი ექიმი (ექიმი სპეციალისტი)</span>
          <span class="ml">
            <input type="text" class="nondrg ko" value="<?= h($doctorName) ?>">
          </span>
        </div>

        <!-- 19 -->
        <div class="fmdv"><span>20. ხელმოწერა</span></div>
        <div class="fmdv">
          <span class="op">
            ______________________________________________________________________________________________________
          </span>
        </div>

        <!-- 20 -->
        <div class="fmdv">
          <span>20. ცნობის გაცემის თარიღი</span>
          <span class="ml">
            <input type="text" class="nondrg ko"
                   data-f100a="issue_date"
                   value="<?= h($issueDate) ?>">
          </span>
        </div>

        <div class="fmdv"><span class="oz">ბეჭდის ადგილი</span></div>

        <div class="iv100a-print-footer">
          გვერდი <span class="pageno"></span>/<span class="pagecount"></span>
        </div>

      </div><!-- /#734 -->
    </div><!-- /#f100aRoot -->
  </div><!-- /.card -->


<?php
echo ob_get_clean();
exit;
}



/* ----------------------- SAVE (200ა/100ა) ----------------------- */
if ($action === 'form200a_save' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  $sid = (int)($_POST['service_id'] ?? 0);
  $payloadStr = $_POST['payload'] ?? '';
  if ($sid<=0 || $payloadStr==='') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad args']); exit; }
  $payload = json_decode($payloadStr, true);
  if (!is_array($payload)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad json']); exit; }
  if (!ensureForm200aTable($pdo)) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'table create failed']); exit; }

  try{
    $q = $pdo->prepare("SELECT patient_id, created_at FROM patient_services WHERE service_record_id=?");
    $q->execute([$sid]);
    $pid = (int)$q->fetchColumn();
    if ($pid<=0) { echo json_encode(['status'=>'error','message'=>'service not found']); exit; }

    $st = $pdo->prepare("
      INSERT INTO patient_form200a (service_id, patient_id, payload)
      VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE payload=VALUES(payload), updated_at=CURRENT_TIMESTAMP
    ");
    $ok = $st->execute([$sid, $pid, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    if (!$ok) throw new RuntimeException('execute failed');

    // Log successful save with full field tracking
  logForm100a($pdo, $existingId ? 'save_update' : 'save_insert', [
    'service_id' => $sid,
    'patient_id' => $pid,
    'payload' => $payload,
    'payload_size' => strlen($jsonPayload),
    'db_record_id' => $existingId ?: (int)$pdo->lastInsertId(),
    'note' => ($existingId ? 'UPDATE id='.$existingId : 'INSERT new') . ' fields=' . count($payload),
  ]);
  echo json_encode(['status'=>'ok']);
  } catch (Throwable $e) {
    logForm100a($pdo, 'save_error', [
      'service_id' => $sid ?? 0,
      'patient_id' => $pid ?? 0,
      'payload' => $payload ?? [],
      'payload_size' => strlen($jsonPayload ?? ''),
      'note' => 'Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(),
    ]);
    http_response_code(500);
    $dbg = (isset($_GET['debug']) && $_GET['debug']==='1') || !empty($_ENV['APP_DEBUG']);
    echo json_encode(['status'=>'error','message'=>'save failed'.($dbg?(': '.$e->getMessage()):'')]);
  }
  exit;
}
if ($action === 'icd10_search') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['status' => 'ok', 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $like = '%' . $q . '%';

        $sql = "
            SELECT
                id,
                code,
                title
            FROM `icd10_titles`
            WHERE
                code  LIKE :q_code_like
                OR title LIKE :q_title_like
            ORDER BY
                (code = :q_exact) DESC,
                code ASC
            LIMIT 20
        ";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':q_code_like'  => $like,
            ':q_title_like' => $like,
            ':q_exact'      => $q,
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $code  = (string)($r['code']  ?? '');
            $title = (string)($r['title'] ?? '');

            if ($code === '' && $title === '') {
                continue;
            }

            $items[] = [
                'code'  => $code,
                'title' => $title,
                'full'  => trim($code . ' — ' . $title),
            ];
        }

        echo json_encode([
            'status' => 'ok',
            'items'  => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Throwable $e) {
        error_log('[icd10_search] ' . $e->getMessage());

        if (!empty($__DEBUG)) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'DEBUG: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'სერვერის შეცდომა',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    exit;
}




/* ─── generateForm100aPdf: სერვერზე PDF ავტოშენახვა ─── */
function generateForm100aPdf(PDO $pdo, int $sid, int $pid, array $payload): array {
    $q = $pdo->prepare("
      SELECT ps.patient_id, ps.created_at,
             COALESCE(p.first_name,'') AS first_name, COALESCE(p.last_name,'') AS last_name,
             p.birthdate, COALESCE(p.personal_id,'') AS personal_id,
             COALESCE(p.address,'') AS address,
             COALESCE(CONCAT(d.first_name,' ',d.last_name),'') AS doctor_name
      FROM patient_services ps
      LEFT JOIN patients p ON p.id = ps.patient_id
      LEFT JOIN doctors d ON d.id = ps.doctor_id
      WHERE ps.service_record_id = ?
    ");
    $q->execute([$sid]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['error'=>'not found'];

    $fullName = trim($row['first_name'].' '.$row['last_name']);
    $dob = $row['birthdate'] ? date('d/m/Y', strtotime($row['birthdate'])) : '';
    $personal = $row['personal_id'];
    $address = $row['address'];
    $doctorName = $row['doctor_name'];
    $visitDT = $row['created_at'] ? date('d-m-Y H:i', strtotime($row['created_at'])) : '';
    $visitDay = $row['created_at'] ? date('Y-m-d', strtotime($row['created_at'])) : '';
    $facility = (string)($_SESSION['facility_name'] ?? '„კლინიკა სანმედი"');

    $dest = $payload['dest'] ?? 'დანიშნულებისამებრ წარსადგენად';
    $addr = $payload['address'] ?? $address;
    $workplace = $payload['workplace'] ?? '';
    $date_send = $payload['date_send'] ?? '';
    $date_admit = $payload['date_admit'] ?? '';
    $date_discharge = $payload['date_discharge'] ?? '';
    $diagText = $payload['diag'] ?? '';
    $icd10 = $payload['icd10_code'] ?? '';
    $past = $payload['past'] ?? '';
    $anamn = $payload['anamn'] ?? '';
    $course = $payload['course'] ?? '';
    $therapy = $payload['therapy'] ?? '';
    $cond_send = $payload['cond_send'] ?? '';
    $cond_discharge = $payload['cond_discharge'] ?? '';
    $recom = $payload['recom'] ?? '';
    $recom1 = $payload['recom1'] ?? '';
    $issueDate = $payload['issue_date'] ?? ($row['created_at'] ? date('d-m-Y', strtotime($row['created_at'])) : date('d-m-Y'));
    $docNameAuto = $payload['doctor_name_auto'] ?? $doctorName;

    $doneItems = [];
    try {
      $qi = $pdo->prepare("SELECT COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' ',COALESCE(s.name,'')),' '), s.name, '') AS t, COUNT(*) AS cnt FROM patient_services ps JOIN services s ON s.id = ps.service_id WHERE ps.patient_id=? AND DATE(ps.created_at)=? GROUP BY t ORDER BY t");
      $qi->execute([$pid, $visitDay]);
      $doneItems = $qi->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $doneHtml = '';
    if ($doneItems) {
      $doneHtml = '<ul>';
      foreach ($doneItems as $it) { if ($it['t']) $doneHtml .= '<li>'.h($it['t']).' ('.(int)$it['cnt'].')</li>'; }
      $doneHtml .= '</ul>';
    } else { $doneHtml = '<span style="color:#888">&mdash;</span>'; }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
      @page{margin:14mm}body{font-family:DejaVu Sans,sans-serif;font-size:10pt;line-height:1.5;color:#000}
      .hr{text-align:right;font-size:7.5pt;line-height:1.3;color:#555}.tm{text-align:center;font-size:12pt;font-weight:bold;margin:10px 0 2px}
      .ts{text-align:center;font-size:11pt;margin-bottom:10px}.cn{text-align:right;font-size:9pt;margin-bottom:6px}
      .f{margin:4px 0}.fl{font-weight:bold}.fv{border-bottom:1px dotted #999;display:inline-block;min-width:200px;padding:1px 4px}
      .s{margin:6px 0 2px}.sl{font-weight:bold}.tb{border:1px solid #ddd;padding:4px 6px;min-height:20px;margin:2px 0 6px;white-space:pre-wrap;word-wrap:break-word}
      table.dt{width:100%;border-collapse:collapse;margin:4px 0}table.dt td{padding:2px 4px;font-size:9pt}table.dt .dl{color:#555}
      .fs{margin-top:14px;text-align:center;font-size:9pt;color:#555}ul{margin:2px 0;padding-left:18px}li{margin:1px 0}
    </style>
  <link rel="stylesheet" href="/css/preclinic-theme.css">
</head><body>';
    $html .= '<table width="100%"><tr><td style="vertical-align:top;font-size:8pt;color:#555">'.h($visitDT).'</td><td class="hr">დამტკიცებულია<br>საქართველოს შრომის, ჯანმრთელობისა<br>და სოციალური დაცვის მინისტრის<br>2007 წ. 09.08 &#8470; 338/ნ ბრძანებით</td></tr></table>';
    $html .= '<div class="tm">სამედიცინო დოკუმენტაცია ფორმა &#8470; IV-100/ა</div>';
    $html .= '<div class="ts">ცნობა ჯანმრთელობის მდგომარეობის შესახებ</div>';
    $html .= '<div class="cn">ცნობა # '.(int)$sid.'</div>';
    $html .= '<div class="f"><span class="fl">1. ცნობის გამცემი დაწესებულება ან/და ექიმი</span><br><span class="fv">'.h($facility).' &mdash; '.h($docNameAuto ?: '—').'</span></div>';
    $html .= '<div class="f"><span class="fl">2. დაწესებულების დასახელება, მისამართი სადაც იგზავნება ცნობა</span><div class="tb">'.h($dest).'</div></div>';
    $html .= '<div class="f"><span class="fl">3. პაციენტის სახელი და გვარი</span> <span class="fv">'.h($fullName).'</span></div>';
    $html .= '<div class="f"><span class="fl">4. დაბადების თარიღი</span> <span class="fv">'.h($dob).'</span></div>';
    $html .= '<div class="f"><span class="fl">5. პირადი ნომერი</span> <span class="fv">'.h($personal).'</span></div>';
    $html .= '<div class="f"><span class="fl">6. მისამართი</span> <span class="fv">'.h($addr).'</span></div>';
    $html .= '<div class="f"><span class="fl">7. სამუშაო ადგილი</span><div class="tb">'.h($workplace).'</div></div>';
    $html .= '<div class="f"><span class="fl">8. თარიღები</span></div>';
    $html .= '<table class="dt"><tr><td class="dl">ა) ექიმთან მიმართვის</td><td class="fv">'.h($visitDT).'</td><td class="dl">ბ) სტაციონარში გაგზავნის</td><td class="fv">'.h($date_send).'</td></tr>';
    $html .= '<tr><td class="dl">გ) სტაციონარში მოთავსების</td><td class="fv">'.h($date_admit).'</td><td class="dl">დ) გაწერის</td><td class="fv">'.h($date_discharge).'</td></tr></table>';
    $html .= '<div class="s"><span class="sl">9. დასკვნა / სრული დიაგნოზი</span><div class="tb">'.nl2br(h($diagText)).'</div></div>';
    if ($icd10) $html .= '<div class="f"><span class="fl">ICD-10:</span> <span class="fv">'.h($icd10).'</span></div>';
    $html .= '<div class="s"><span class="sl">10. გადატანილი დაავადებები</span><div class="tb">'.nl2br(h($past)).'</div></div>';
    $html .= '<div class="s"><span class="sl">11. მოკლე ანამნეზი</span><div class="tb">'.nl2br(h($anamn)).'</div></div>';
    $html .= '<div class="s"><span class="sl">12. ჩატარებული დიაგნოსტიკური გამოკვლევები</span><div class="tb">'.$doneHtml.'</div></div>';
    $html .= '<div class="s"><span class="sl">13. ავადმყოფობის მიმდინარეობა</span><div class="tb">'.nl2br(h($course)).'</div></div>';
    $html .= '<div class="s"><span class="sl">14. ჩატარებული მკურნალობა</span><div class="tb">'.nl2br(h($therapy)).'</div></div>';
    $html .= '<div class="s"><span class="sl">15. მდგომარეობა სტაციონარში გაგზავნისას</span><div class="tb">'.nl2br(h($cond_send)).'</div></div>';
    $html .= '<div class="s"><span class="sl">16. მდგომარეობა სტაციონარიდან გაწერისას</span><div class="tb">'.nl2br(h($cond_discharge)).'</div></div>';
    $html .= '<div class="s"><span class="sl">17. სამკურნალო რეკომენდაციები</span><div class="tb">'.nl2br(h($recom)).'</div></div>';
    $html .= '<div class="s"><span class="sl">18. შრომითი რეკომენდაციები</span><div class="tb">'.nl2br(h($recom1)).'</div></div>';
    $html .= '<div class="f"><span class="fl">19. მკურნალი ექიმი</span> <span class="fv">'.h($docNameAuto).'</span></div>';
    $html .= '<div style="margin-top:14px"><span class="fl">20. ხელმოწერა</span><br><br>__________________________________________</div>';
    $html .= '<div class="f"><span class="fl">ცნობის გაცემის თარიღი</span> <span class="fv">'.h($issueDate).'</span></div>';
    $html .= '<div class="fs">ბეჭდის ადგილი</div></body></html>';

    require_once __DIR__ . '/vendor/autoload.php';
    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', true);
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfDir = __DIR__ . '/pdf/form100a';
    if (!is_dir($pdfDir)) @mkdir($pdfDir, 0775, true);
    $ts = date('Ymd_His');
    $safeName = preg_replace('/[^a-zA-Z0-9_\x{10A0}-\x{10FF}]/u', '_', $fullName);
    $filename = "form100a_{$sid}_{$safeName}_{$ts}.pdf";
    $filepath = $pdfDir . '/' . $filename;
    if (false === @file_put_contents($filepath, $dompdf->output())) {
      throw new \RuntimeException('PDF write failed');
    }
    return ['pdf_url' => 'pdf/form100a/' . $filename, 'filename' => $filename];
}

if ($action === 'form100a_save' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  $sid = (int)($_POST['service_id'] ?? 0);
  $payloadStr = $_POST['payload'] ?? '';
  if ($sid<=0 || $payloadStr==='') {
    logForm100a($pdo, 'save_error', ['service_id'=>$sid, 'note'=>'bad args: sid='.$sid.' payloadLen='.strlen($payloadStr)]);
    http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad args']); exit;
  }
  $payload = json_decode($payloadStr, true);
  if (!is_array($payload)) {
    logForm100a($pdo, 'save_error', ['service_id'=>$sid, 'note'=>'bad json']);
    http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad json']); exit;
  }
  if (!ensureForm100aTable($pdo)) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'table create failed']); exit; }

  try{
    $q = $pdo->prepare("SELECT patient_id, created_at FROM patient_services WHERE service_record_id=?");
    $q->execute([$sid]);
    $svcRow = $q->fetch(PDO::FETCH_ASSOC);
    $pid = (int)($svcRow['patient_id'] ?? 0);
    $visitDate = !empty($svcRow['created_at']) ? date('d-m-Y', strtotime($svcRow['created_at'])) : date('d-m-Y');
    if ($pid<=0) { echo json_encode(['status'=>'error','message'=>'service not found']); exit; }
  $payload['doctor_name_auto'] = serviceDoctorName($pdo, $sid);
  $payload['issue_date'] = $visitDate;
  $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  
  // UPSERT: თუ არსებობს - განვაახლოთ, თუ არა - დავამატოთ
  $checkSt = $pdo->prepare('SELECT id FROM patient_form100a WHERE service_id = ? ORDER BY id DESC LIMIT 1');
  $checkSt->execute([$sid]);
  $existingId = $checkSt->fetchColumn();
  
  if ($existingId) {
    $st = $pdo->prepare('UPDATE patient_form100a SET payload = ?, updated_at = NOW() WHERE id = ?');
    $ok = $st->execute([$jsonPayload, $existingId]);
  } else {
    $st = $pdo->prepare('INSERT INTO patient_form100a (service_id, patient_id, payload) VALUES (?,?,?)');
    $ok = $st->execute([$sid, $pid, $jsonPayload]);
  }
  if (!$ok) { throw new RuntimeException('execute failed'); }

  // ─── AUTO PDF: ყოველ save-ზე ავტომატურად PDF ─── 
  $pdfInfo = null;
  try {
    $pdfInfo = generateForm100aPdf($pdo, $sid, $pid, $payload);
  } catch (Throwable $pdfErr) {
    error_log('[form100a] auto-PDF failed: ' . $pdfErr->getMessage());
  }
  echo json_encode(['status'=>'ok', 'pdf_url'=>($pdfInfo['pdf_url'] ?? null)]);
  } catch (Throwable $e) {
    http_response_code(500);
    $dbg = (isset($_GET['debug']) && $_GET['debug']==='1') || !empty($_ENV['APP_DEBUG']);
    echo json_encode(['status'=>'error','message'=>'save failed'.($dbg?(': '.$e->getMessage()):'')]);
  }
  exit;
}
if ($action === 'form100a_tpl_list') {
  header('Content-Type: application/json; charset=utf-8');
  if (!ensureForm100aTplTable($pdo)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'table create failed']);
    exit;
  }

  try {
    $st = $pdo->query("
      SELECT id, name
      FROM form100a_templates
      ORDER BY created_at DESC, id DESC
      LIMIT 100
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['status'=>'ok','items'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'load failed']);
  }
  exit;
}

if ($action === 'form100a_tpl_get') {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'bad id']);
    exit;
  }
  if (!ensureForm100aTplTable($pdo)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'table create failed']);
    exit;
  }

  try {
    $st = $pdo->prepare("SELECT payload FROM form100a_templates WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $pl = $st->fetchColumn();
    if (!$pl) {
      echo json_encode(['status'=>'error','message'=>'not found']);
      exit;
    }
    $payload = json_decode($pl, true) ?: [];
    echo json_encode(['status'=>'ok','payload'=>$payload], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'load failed']);
  }
  exit;
}

if ($action === 'form100a_tpl_save' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');

  if (!ensureForm100aTplTable($pdo)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'table create failed']);
    exit;
  }

  $name = trim((string)($_POST['name'] ?? ''));
  $payloadStr = $_POST['payload'] ?? '';

  if ($name === '' || $payloadStr === '') {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'empty name or payload']);
    exit;
  }

  $payload = json_decode($payloadStr, true);
  if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'bad json']);
    exit;
  }

  $payload = stripForm100aPersonalFromPayload($payload);

  $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

  try {
    $sql = "
      INSERT INTO form100a_templates (name, payload, created_by)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE
        payload = VALUES(payload),
        created_by = VALUES(created_by),
        created_at = CURRENT_TIMESTAMP
    ";
    $st = $pdo->prepare($sql);
    $ok = $st->execute([
      $name,
      json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      $createdBy
    ]);

    if (!$ok) {
      throw new RuntimeException('execute failed');
    }

    echo json_encode(['status'=>'ok']);
  } catch (Throwable $e) {
    http_response_code(500);
    $dbg = (isset($_GET['debug']) && $_GET['debug']==='1') || !empty($_ENV['APP_DEBUG']);
    echo json_encode(['status'=>'error','message'=>'save failed'.($dbg?(': '.$e->getMessage()):'')]);
  }
  exit;
}


/* ----------------------- KV PANEL ----------------------- */
if ($action === 'kv_panel') {
  header('Content-Type: text/html; charset=utf-8');
  $sid = (int)($_GET['service_id'] ?? 0);
  if ($sid <= 0) { http_response_code(400); echo 'bad id'; exit; }

  $st = $pdo->prepare("SELECT patient_id, DATE(created_at) AS ddate FROM patient_services WHERE service_record_id=? LIMIT 1");
  $st->execute([$sid]);
  $srv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$srv) { echo '<div class="muted">ვიზიტი ვერ მოიძებნა.</div>'; exit; }
  $pid = (int)$srv['patient_id'];
  $curDay = $srv['ddate'];

  $hasKV = columnExists($pdo,'patient_services','kv');
  $hasANS = columnExists($pdo,'patient_services','ans');

  $qCur = $pdo->prepare("
    SELECT 
      ps.service_record_id AS id,
      ps.created_at,
      COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' - ',COALESCE(s.name,'')),' - '), s.name, '') AS title,
      ".($hasKV ? "COALESCE(ps.kv,0)" : "0")." AS kv,
      ".($hasANS ? "COALESCE(ps.ans,0)" : "0")." AS ans
    FROM patient_services ps
    JOIN services s ON s.id = ps.service_id
    WHERE ps.patient_id = ?
      AND DATE(ps.created_at) = ?
    ORDER BY ps.created_at DESC, ps.service_record_id DESC
  ");
  $qCur->execute([$pid, $curDay]);
  $curRows = $qCur->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $qHist = $pdo->prepare("
    SELECT 
      ps.service_record_id AS id,
      ps.created_at,
      COALESCE(NULLIF(CONCAT(COALESCE(s.code,''),' - ',COALESCE(s.name,'')),' - '), s.name, '') AS title,
      ".($hasKV ? "COALESCE(ps.kv,0)" : "0")." AS kv,
      ".($hasANS ? "COALESCE(ps.ans,0)" : "0")." AS ans
    FROM patient_services ps
    JOIN services s ON s.id = ps.service_id
    WHERE ps.patient_id = ?
      AND DATE(ps.created_at) <> ?
    ORDER BY ps.created_at DESC, ps.service_record_id DESC
    LIMIT 300
  ");
  $qHist->execute([$pid, $curDay]);
  $histRows = $qHist->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $piNotice='';
  if (!$hasKV || !$hasANS) {
    $piNotice = '<div class="muted" style="margin:8px 0">შენახვა ჩეკბოქსებით ხელმისაწვდომია, თუ <b>patient_services</b>-ში გაქვს ველები <code>kv TINYINT</code> და <code>ans TINYINT</code>. ახლა ისინი არ არიან, ამიტომ ჩეკბოქსები დაბლოკილია.</div>';
  }

  ob_start(); ?>
  <div id="inter7" class="rgnvfx">
    <div style="text-align:center;font-weight:bold;color:#5B5BDE;">კვლევები და კონსულტაციები მიმდინარე ვიზიტზე</div>
    <?= $piNotice ?>

    <div class="table-scroll" style="margin-top:20px">
      <table class="Ctable" id="f100kvlevebi" style="font-size:14px">
        <tbody>
          <tr>
            <th width="17%">თარიღი</th>
            <th width="60%">დასახელება</th>
            <th width="13%">კვლევა</th>
            <th width="10%">პასუხი</th>
          </tr>

          <tr id="TGv">
            <td></td>
            <td><input type="text" class="nondrg" id="kv-search" placeholder="ძებნა დასახელებით..."></td>
            <td style="text-align:center">
              <div><a href="#" data-act="check-all-kv"   data-service="<?= (int)$sid ?>" style="color:black;font-size:11px" <?= ($hasKV?'':'aria-disabled="true" tabindex="-1"') ?>>ყველას მონიშვნა</a></div>
              <div style="margin-top:5px"><a href="#" data-act="uncheck-all-kv" data-service="<?= (int)$sid ?>" style="color:black;font-size:11px" <?= ($hasKV?'':'aria-disabled="true" tabindex="-1"') ?>>ყველას მოხსნა</a></div>
            </td>
            <td style="text-align:center">
              <div><a href="#" data-act="check-all-ans"   data-service="<?= (int)$sid ?>" style="color:black;font-size:11px" <?= ($hasANS?'':'aria-disabled="true" tabindex="-1"') ?>>ყველას მონიშვნა</a></div>
              <div style="margin-top:5px"><a href="#" data-act="uncheck-all-ans" data-service="<?= (int)$sid ?>" style="color:black;font-size:11px" <?= ($hasANS?'':'aria-disabled="true" tabindex="-1"') ?>>ყველას მოხსნა</a></div>
            </td>
          </tr>

          <?php if (!$curRows): ?>
            <tr><td colspan="4" class="muted center">ჩანაწერი არ არის.</td></tr>
          <?php else:
            foreach ($curRows as $r):
              $rid = (int)$r['id'];
              $d   = $r['created_at'] ? date('d-m-Y H:i', strtotime($r['created_at'])) : '—';
              $t   = $r['title'] ?? '—';
              $kv  = (int)$r['kv'] === 1 ? 'checked' : '';
              $ans = (int)$r['ans'] === 1 ? 'checked' : '';
          ?>
            <tr id="ps<?= $rid ?>" data-row data-id="<?= $rid ?>">
              <td><?= h($d) ?></td>
              <td data-name><?= h($t) ?></td>
              <td style="text-align:center"><input type="checkbox" class="zm4" <?= $kv ?> <?= ($hasKV?'':'disabled') ?>></td>
              <td style="text-align:center"><input type="checkbox" class="zm5" <?= $ans ?> <?= ($hasANS?'':'disabled') ?>></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div style="text-align:center;font-weight:bold;color:#5B5BDE;margin-top:30px;">კვლევები და კონსულტაციები წარსულ ვიზიტებზე</div>
    <div class="table-scroll" style="margin-top:12px">
      <table class="Ctable" id="f100kvlevebidz" style="font-size:14px">
        <tbody>
          <tr>
            <th width="17%">თარიღი</th>
            <th width="60%">დასახელება</th>
            <th width="13%">კვლევა</th>
            <th width="10%">პასუხი</th>
          </tr>
          <?php if (!$histRows): ?>
            <tr><td colspan="4" class="muted center">ჩანაწერი არ არის.</td></tr>
          <?php else:
            foreach ($histRows as $r):
              $rid = (int)$r['id'];
              $d   = $r['created_at'] ? date('d-m-Y H:i', strtotime($r['created_at'])) : '—';
              $t   = $r['title'] ?? '—';
              $kv  = (int)$r['kv'] === 1 ? 'checked' : '';
              $ans = (int)$r['ans'] === 1 ? 'checked' : '';
          ?>
            <tr id="ps<?= $rid ?>" data-row data-id="<?= $rid ?>">
              <td><?= h($d) ?></td>
              <td data-name><?= h($t) ?></td>
              <td style="text-align:center"><input type="checkbox" class="zm4" <?= $kv ?> disabled></td>
              <td style="text-align:center"><input type="checkbox" class="zm5" <?= $ans ?> disabled></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  echo ob_get_clean(); exit;
}

/* ----------------------- TOGGLES ----------------------- */
if ($action === 'kv_toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  if (!columnExists($pdo,'patient_services','kv') && !columnExists($pdo,'patient_services','ans')) {
    echo json_encode(['status'=>'error','message'=>'kv/ans columns not found']); exit;
  }
  $ps_id = (int)($_POST['pi_id'] ?? 0);
  $field = $_POST['field'] ?? '';
  $value = (int)($_POST['value'] ?? 0);
  if ($ps_id<=0 || !in_array($field, ['kv','ans'], true)) {
    http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad args']); exit;
  }
  if (!columnExists($pdo,'patient_services',$field)) {
    echo json_encode(['status'=>'error','message'=>"$field column not found"]); exit;
  }
  $sql = "UPDATE patient_services SET {$field}=? WHERE service_record_id=?";
  $st = $pdo->prepare($sql); $st->execute([$value, $ps_id]);
  echo json_encode(['status'=>'ok']); exit;
}

if ($action === 'kv_bulk' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  $target     = $_POST['target'] ?? '';
  $value      = (int)($_POST['value'] ?? 0);
  $service_id = (int)($_POST['service_id'] ?? 0);
  if ($service_id<=0 || !in_array($target, ['kv','ans'], true)) {
    http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad args']); exit;
  }
  if (!columnExists($pdo,'patient_services',$target)) {
    echo json_encode(['status'=>'error','message'=>"$target column not found"]); exit;
  }

  $st = $pdo->prepare("SELECT patient_id, DATE(created_at) AS ddate FROM patient_services WHERE service_record_id=?");
  $st->execute([$service_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { echo json_encode(['status'=>'error','message'=>'service not found']); exit; }

  $upd = $pdo->prepare("UPDATE patient_services SET {$target}=? WHERE patient_id=? AND DATE(created_at)=?");
  $upd->execute([$value, (int)$row['patient_id'], $row['ddate']]);
  echo json_encode(['status'=>'ok']); exit;
}

/* ================= PAGE (HTML + CSS + JS) ================= */
$cur = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!doctype html>
<html lang="ka">
<head>
  <meta charset="utf-8">
  <title>ჩემი პაციენტები</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <!-- Google Fonts - Noto Sans Georgian -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap"
      rel="stylesheet"
      media="screen"
      onload="this.media='all'">

  
  <style>
/* ======================
   Light Theme • Mint Palette
   ====================== */
:root{
  --bg:#f7f9fb;
  --surface:#ffffff;
  --surface-2:#fbfcfd;
  --text:#111827;
  --muted:#6b7280;
  --brand:#14937c;
  --brand-2:#0f6e5d; /* fixed hex */
  --stroke:#e5e7eb;
  --chip:#e6f6f3;
  --chip-br:#cfeee8;
  --accent:#0ea5e9;
  --danger:#ef4444;
  --shadow:0 8px 24px rgba(2,8,23,.08);
  --radius:14px; --radius-sm:10px; --radius-lg:18px;
  --pad:16px;
  --focus:0 0 0 3px rgba(20,147,124,.28);
}

*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:"Noto Sans Georgian",sans-serif;
  background:var(--bg);
  color:var(--text);
  letter-spacing:.2px;
}
:focus-visible{outline:0; box-shadow:var(--focus)}

/* ===== Topbar / Nav ===== */
.topbar{
  position:sticky; top:0; z-index:10;
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 18px;
  background:var(--brand);
  color:#fff;
  border-bottom:1px solid var(--stroke);
  box-shadow:var(--shadow);
}
.brand{display:flex; align-items:center; gap:10px; font-weight:800}
.brand .dot{width:10px;height:10px;border-radius:50%;background:#fff; box-shadow:0 0 8px rgba(255,255,255,.4)}

/* Layout */
.container{
  max-width:1600px; margin:20px auto; padding:0 18px;
  display:grid; grid-template-columns: 1fr 420px; gap:18px
}
@media (max-width:1100px){ .container{grid-template-columns:1fr} }

/* ===== Cards ===== */
.card{
  background:var(--surface); border:1px solid var(--stroke);
  border-radius:var(--radius); box-shadow:var(--shadow);
}
.card .head{
  padding:14px var(--pad); border-bottom:1px solid var(--stroke);
  display:flex; align-items:center; justify-content:space-between;
}
.card .title{font-size:16px; font-weight:900; color:#0f172a; letter-spacing:.2px}
.card .body{ padding:var(--pad) }
.soft{ background:var(--surface-2) }

/* ===== Filters ===== */
.filters{ display:grid; grid-template-columns:1fr 1fr; gap:12px }
@media (max-width:700px){ .filters{grid-template-columns:1fr} }
.f-group label{ display:block; font-size:12px; color:var(--muted); margin:0 0 6px }
select{
  width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--stroke);
  background:#fff; color:#000; outline:none; transition: box-shadow .15s, border-color .15s;
}
select:focus-visible{ border-color:#9adfd1; box-shadow:var(--focus) }

/* ===== Patients table ===== */
.patients-table{ width:100%; border-collapse:separate; border-spacing:0 8px }
.patients-table thead th{
  font-size:12px; text-transform:uppercase; letter-spacing:.8px; color:#fff;
  background:var(--brand);
  text-align:left; padding:8px 10px; border:0;
}
.patients-table tbody tr{
  background:#fff;
  border:1px solid #e6edf5; border-radius:12px; overflow:hidden;
  transition: transform .12s ease, background .15s ease;
}
.patients-table tbody tr:hover{ transform:translateY(-1px); background:#e7f6f1 }
.patients-table tbody tr:focus-visible{ box-shadow:var(--focus) }
.patients-table td{ padding:12px 10px; border-bottom:0 }
.patients-table tr.selected{ background:#c9eee2 }

/* ===== Timeline & generic tables ===== */
.timeline-table{ width:100%; border-collapse:collapse }
.timeline-table td{ border-bottom:1px dashed #e7eef7; padding:10px 8px }
.panel-title{ font-weight:900; color:#0f172a; padding:4px 0 10px; border-bottom:1px solid var(--stroke); margin-bottom:6px }

.table-scroll{ overflow:auto; border:1px solid var(--stroke); border-radius:12px; background:#fff }
.clean-table{ width:100%; border-collapse:collapse }
.clean-table th, .clean-table td{ padding:12px 12px; border-bottom:1px solid #eef2f7 }
.clean-table thead th{ color:#374151; font-size:12px; text-transform:uppercase; letter-spacing:.6px; background:#f1fbf8 }

/* ===== Typographic helpers ===== */
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; color:#374151 }
.muted{ color:var(--muted) }
.center{ text-align:center }
.strong{ font-weight:800 }
.cell-center{ text-align:center }
.cell-right{ text-align:right }
.pill{
  display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px;
  background:var(--chip); border:1px solid var(--chip-br); color:#145e50; font-weight:800; font-size:12px
}
.chip{
  display:inline-flex; align-items:center; gap:6px;
  padding:4px 8px;
  border-radius:999px; background:var(--chip); border:1px solid var(--chip-br);
  color:var(--brand-2); font-size:12px; font-weight:800;
}

/* ===== Grid helpers ===== */
.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px }
@media (max-width:600px){ .grid-2{ grid-template-columns:1fr } }

/* ===== Investigations table ===== */
.Ctable { width:100%; border-collapse:collapse; background:#fff }
.Ctable th, .Ctable td { border-bottom:1px solid #eef2f7; padding:10px 12px }
.Ctable th { font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; text-align:left }
.rgnvfx { padding:10px 0 }

/* ===== Tabs & Subtabs ===== */
.tabs{list-style:none;display:flex;gap:6px;padding-left:0;margin:0 0 14px;border-bottom:2px solid #ddd}
.tabs a{
  padding:10px 18px;text-decoration:none;font-weight:700;
  background:#fff;color:var(--brand);border:1px solid var(--chip-br);
  border-top-left-radius:10px;border-top-right-radius:10px;
}
.tabs a:hover,.tabs a.active{background:var(--brand);color:#fff;border-color:var(--brand)}
.subtabswrap{max-width:1650px;margin:0 auto 6px;padding:0 24px}
.subtabs{list-style:none;display:flex;gap:6px;margin:0 0 12px;padding:0;border-bottom:2px solid #e6e6e6}
.subtabs a{
  display:inline-block;padding:8px 14px;text-decoration:none;font-weight:700;
  background:#fff;color:var(--brand);border:1px solid var(--chip-br);
  border-top-left-radius:8px;border-top-right-radius:8px;
}
.subtabs a:hover,.subtabs a.active{background:var(--brand);color:#fff;border-color:var(--brand)}

#upnav.upnav{
  margin-top:10px; display:flex; gap:12px;
  border-bottom:2px solid #ddd; padding-bottom:6px;
}
#upnav.upnav a{
  text-decoration:none; color:var(--brand);
  padding:6px 12px; border-radius:8px; font-weight:700;
}
#upnav.upnav a:hover{ background:var(--chip) }
#upnav.upnav a:focus-visible{ box-shadow:var(--focus) }
#upnav.upnav a.active{ background:var(--brand); color:#fff }

/* ===== Buttons ===== */
.btn{
  appearance:none; border:1px solid transparent; background:var(--brand); color:#fff;
  padding:8px 12px; border-radius:10px; font-weight:900; cursor:pointer;
  transition: transform .08s ease, box-shadow .15s ease, background .15s ease;
  box-shadow:0 6px 14px rgba(20,147,124,.18);
}
.btn:hover{ transform:translateY(-1px); background:var(--brand-2) }
.btn:active{ transform:translateY(0) }
.btn:focus-visible{ box-shadow:var(--focus) }
.btn.icon{ width:36px; height:36px; display:inline-grid; place-items:center; font-size:16px; padding:0 }
.btn.ghost{ background:#fff; color:#000; border:1px solid #e5e7eb; box-shadow:none }
.btn.ghost:hover{ background:#f3f4f6 }
.btn.sm{ padding:6px 10px; font-size:12px; border-radius:8px }

/* ===== Toast ===== */
.toast{
  position:fixed; right:18px; bottom:18px; background:var(--brand-2); color:#fff;
  padding:10px 14px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.15); z-index:1000; font-weight:800;
}
.toast.err{ background:var(--danger) }

/* ===== Modal layout ===== */
.modal-body{ height:min(76vh,820px); overflow:hidden; display:grid; grid-template-columns:260px 1fr; gap:16px }
@media (max-width:900px){ .modal-body{ grid-template-columns:1fr } }
.content{ max-height:100%; overflow:auto;  flex-direction:column; gap:16px; padding:8px }

/* ===== LEFT NAV (aside) ===== */
.leftnav{
  border:1px solid var(--stroke);
  border-radius:12px;
  background:var(--surface-2);
  padding:8px;
  height:100%; max-height:66vh;
  overflow:auto;
}
.leftnav .navgroup{
  margin:10px 8px 6px;
  font-size:11px; color:#6b7280;
  text-transform:uppercase; letter-spacing:.6px;
}
.leftnav .navbtn{
  display:flex; align-items:center; gap:8px;
  min-height:36px;
  padding:8px 10px;
  background:#fff;
  color:#000;
  border:1px solid var(--stroke);
  border-radius:10px;
  text-decoration:none;
  font-weight:700;
  transition: background .15s, border-color .15s, transform .08s;
  word-break: break-word;
}
.leftnav .navbtn:hover{ background:var(--chip); border-color:var(--chip-br); transform:translateY(-1px) }
.leftnav .navbtn:focus-visible{ box-shadow:var(--focus) }
.leftnav .navbtn.primary{background:#ecfdf5; border-color:#bbf7d0; color:#166534 }
.leftnav .navbtn.is-active,
.leftnav .navbtn[aria-current="page"]{
  background:var(--brand); color:#fff; border-color:var(--brand);
}
.leftnav .navbtn::after{
  content: attr(data-badge);
  display: inline-flex; align-items:center; justify-content:center;
  min-width:14px; height:14px; margin-left:auto;
  padding:0 4px; border-radius:999px; font-size:10px; font-weight:800;
  background:transparent; color:transparent; border:1px solid transparent;
}
.leftnav .navbtn[data-badge]::after{
  background:var(--chip); color:#145e50; border-color:var(--chip-br);
}

/* ===== Form 100a / 200a cards ===== */
.form100a-card{ border:1px solid var(--stroke); border-radius:var(--radius); background:#fff; box-shadow:var(--shadow); display:flex; flex-direction:column; overflow:hidden }
.form100a-head{
  position:sticky; top:0; z-index:1;
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:12px 14px; background:linear-gradient(180deg,#fff 0%,#f3fbf9 100%);
  border-bottom:1px solid var(--stroke)
}
.form100a-title{ font-weight:900; letter-spacing:.2px; color:#0f172a; display:flex; align-items:center; gap:10px }
.form100a-actions{ display:flex; gap:8px }
.form100a-actions .btn.ghost{ border-radius:8px; padding:6px 10px }
.form100a-body{ padding:16px; max-height:calc(76vh - 56px); overflow:auto }
.form100a-body .fmdv{ margin:6px 0 }
.form100a-body input.nondrg.ko,
.form100a-body textarea.nondrg.ko{
  background:#fbfffe; border:1px solid #dcefeb; border-radius:10px;
  padding:8px 10px; color:#111827; width:100%;
  transition: box-shadow .15s ease, border-color .15s ease;
}
.form100a-body input.nondrg.ko:focus-visible,
.form100a-body textarea.nondrg.ko:focus-visible{ border-color:#9adfd1; box-shadow:var(--focus) }
.form100a-body .rpdv{ border:1px dashed #cfeee8; background:#f3fbf9; padding:10px; border-radius:10px }
.form100a-body .br, .form100a-body .oj, .form100a-body .op, .form100a-body .oz, .form100a-body .ml{ color:#374151; font-size:14px }


/* Print-only page footer hidden on screen */
.iv100a-print-footer{ display:none; }

/* ===== Modal wrapper ===== */
.modal-overlay{
  position:fixed; inset:0; display:none; align-items:flex-start; justify-content:center; z-index:999;
  background:rgba(0,0,0,.18); backdrop-filter: blur(4px);
}
.modal-overlay.show{ display:flex }
.modal-card{
  width:min(980px,calc(100% - 28px));
  margin-top:28px; background:#fff; border:1px solid var(--stroke);
  border-radius:var(--radius-lg); box-shadow:0 24px 60px rgba(2,8,23,.15);
  position:relative; overflow:hidden; animation: pop .14s ease-out;
}
@keyframes pop{ from{ transform: translateY(8px); opacity:.6 } to{ transform: translateY(0); opacity:1 } }
.modal-head{ display:flex; gap:14px; align-items:center; padding:16px 18px; border-bottom:1px solid var(--stroke) }
.avatar{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; font-weight:900; background:#dff6f1; color:#146a5a; border:1px solid #cfeee8 }
.title-wrap{ display:flex; flex-direction:column; gap:2px }
.title{ font-weight:900; letter-spacing:.2px; color:#0f172a }
.subtitle{ color:#64748b; font-size:12px }
.closeBtn{ position:absolute; top:10px; right:10px }

/* ===== PRINT: CLEAN (no inputs, no lines) ===== */
@page { size: A4 portrait; margin: 12mm 12mm 14mm 12mm; }
@media print{
  body{
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-family: "DejaVu Sans","Noto Sans Georgian","Sylfaen","Arial Unicode MS",Arial,Helvetica,sans-serif !important;
    color:#000 !important;
    background:#fff !important;
  }

  /* Hide whole app UI while printing just the target */
  html.printing body *{ display:none !important; }

  /* Show only the active printable node */
  html.printing .print-active, 
  html.printing .print-active *{ display:revert !important; }

  @supports not (display:revert) {
    html.printing .print-active, 
    html.printing .print-active *{ display:initial !important; }
  }

  /* Neutralize wrappers */
  html.printing .container, 
  html.printing .modal-overlay, 
  html.printing .modal-card, 
  html.printing .modal-body, 
  html.printing .content{
    display:block !important; position:static !important; inset:auto !important;
    height:auto !important; max-height:none !important; overflow:visible !important;
    border:0 !important; box-shadow:none !important; background:#fff !important;
    padding:0 !important; margin:0 !important;
  }

  /* Remove UI chrome */
  html.printing .topbar, html.printing .upnav, html.printing .subtabswrap, html.printing nav, html.printing .tabs, html.printing .leftnav,
  html.printing .form100a-actions, html.printing .btn, html.printing .toast{ display:none !important; }

  /* Printable card refinements */
  html.printing .print-active{ box-shadow:none !important; border:0 !important; background:#fff !important; padding:0 !important; margin:0 !important; }
  html.printing .form100a-card{ border:0 !important; margin:0 !important; padding:0 !important; }
  
  /* ფორმა 100 - header-ი სრულად ვმალავთ ბეჭდვაზე, რადგან მთავარი სათაურები ფორმის შიგნით არის */
  html.printing .form100a-head{
    display:none !important;
  }
  
  /* ფორმის body - ყველაფერი პირდაპირ პირველი ფურცლის თავიდან იწყება */
  html.printing .form100a-body{ 
    padding:0 !important; 
    margin:0 !important;
    max-height:none !important; 
    overflow:visible !important; 
  }
  
  /* #734 კონტეინერი - ნულოვანი margin/padding */
  html.printing .olvn.tmpldiv{ 
    padding:0 !important; 
    margin:0 !important; 
  }

  /* Compact rows */
  html.printing .fmdv{
    display:flex !important; align-items:baseline !important;
    gap:5mm !important; margin:2.2mm 0 !important; break-inside:avoid !important;
    font-size:11pt !important; line-height:1.25 !important;
  }
  /* დიდი textarea-ების შემცველი ბლოკები შეიძლება გაიყოს გვერდებს შორის */
  html.printing .fmdv:has(textarea),
  html.printing .fmdv:has(.si-wrapper),
  html.printing .fmdv:has(.print-textarea) {
    break-inside:auto !important;
    page-break-inside:auto !important;
  }
  /* textarea თავად შეიძლება გაიყოს */
  html.printing textarea,
  html.printing .print-textarea {
    break-inside:auto !important;
    page-break-inside:auto !important;
  }
  html.printing .fmdv > span:first-child{ min-width:45mm !important; font-weight:700 !important; color:#000 !important; }

  /* *** CLEAN PRINT RULES (fixed) *** */
  /* Hide any remaining live controls in print clone (just in case) */
  @media print {   /* გლობალურად მოვაშოროთ მძიმე ეფექტები */   * {      text-shadow: none !important;     box-shadow: none !important;     filter: none !important;   }    /* კონტროლები იბეჭდება, როგორც უბრალო ტექსტი */   html.printing input,   html.printing textarea,   html.printing select {     display: inline-block !important;     border: 0 !important;     outline: 0 !important;     background: transparent !important;     box-shadow: none !important;     padding: 0 !important;     margin: 0 !important;     width: auto !important;     min-width: 35mm !important; /* სურვილისამებრ */     color: #000 !important;     font: inherit !important;   }   html.printing textarea { white-space: pre-wrap !important; }    html.printing input[type=checkbox],   html.printing input[type=radio] {     transform: scale(1.1);     accent-color: #000;   }    /* დარჩეს მხოლოდ სასაბეჭდ ბლოკი */   html.printing body *{ display:none !important; }   html.printing .print-active,   html.printing .print-active *{ display:revert !important; }   @supports not (display:revert){     html.printing .print-active,     html.printing .print-active *{ display:initial !important; }   }    /* wrapper-ებისა და ქრომის განულება */   html.printing .container,   html.printing .modal-overlay,   html.printing .modal-card,   html.printing .modal-body,   html.printing .content{     display:block !important; position:static !important; inset:auto !important;     height:auto !important; max-height:none !important; overflow:visible !important;     border:0 !important; box-shadow:none !important; background:#fff !important;     padding:0 !important; margin:0 !important;   }    /* გვერდის მინდვრები */   @page { size: A4 portrait; margin: 12mm 12mm 14mm 12mm; }    /* ეკრანული ღილაკები/ჰედერები დამალული */   html.printing .topbar,    html.printing .upnav,    html.printing .subtabswrap,   html.printing nav,    html.printing .tabs,    html.printing .leftnav,   html.printing .form100a-actions,    html.printing .btn,    html.printing .toast { display:none !important; } }

  /* Keep rpdv content; only remove decoration */
  html.printing .op { display:none !important; }
  html.printing .rpdv {
    border:0 !important;
    background:transparent !important;
    padding:0 !important;
  }

  /* Plain text stand-ins produced by JS */
  html.printing .print-text, 
  html.printing .print-textarea, 
  html.printing .print-select,
  html.printing .print-check {
    font: inherit !important;
    color:#000 !important;
    white-space:pre-wrap !important;
  }

  /* Titles */
  html.printing .iv100a-approval{ font-size:9pt !important; line-height:1.2 !important; }
  html.printing .iv100a-title-1{ font-size:12pt !important; font-weight:600 !important; margin-top:2mm !important; text-align:center !important; }
  html.printing .iv100a-title-2{ font-size:14pt !important; font-weight:800 !important; margin:1mm 0 4mm 0 !important; text-align:center !important; }

  /* Footer with page numbers (works in Chromium-based, modern Firefox) */
  html.printing .iv100a-print-footer{
    display:block !important; position:fixed !important; right:12mm !important; bottom:8mm !important; font-size:9pt !important; color:#000 !important;
  }
  @page{
    @bottom-right{
      content: "გვერდი " counter(page) " / " counter(pages);
    }
  }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce){
  *{ animation-duration:0.01ms !important; animation-iteration-count:1 !IMPORTANT; transition-duration:0.01ms !important; scroll-behavior:auto !important }
}
  </style>
</head>
<body>

  <!-- Top bar -->
  <div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;"><img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:40px;width:auto;margin-right:12px;background:#fff;padding:4px 8px;border-radius:6px;"></a>
    <div class="brand"><span class="dot"></span> <span>EHR • პაციენტები</span></div>
    <div class="user-menu-wrap" id="userMenu">
      <div class="user-btn" id="userBtn">Მომხმარებელი ▾</div>
      <div class="user-dropdown" id="userDropdown">
        <a href="profile.php">პროფილი</a>
        <a href="logout.php">გასვლა</a>
      </div>
    </div>
  </div>

  <!-- Upnav -->
  <nav id="upnav" class="upnav" role="navigation" aria-label="Secondary navigation">
    <a href="dashboard.php" class="<?= $cur=='dashboard.php' ? 'active' : '' ?>">მთავარი</a>
    <a href="doctors.php"   class="<?= $cur=='doctors.php'   ? 'active' : '' ?>">HR</a>
    <a href="reports.php"   class="<?= $cur=='reports.php'   ? 'active' : '' ?>">რეპორტი</a>
  </nav>

  <br>

  <!-- Main tabs -->
  <div class="container">
    <div class="mainContent">
      <ul class="tabs">
        <li><a href="dashboard.php"   class="<?= $cur=='dashboard.php'?'active':'' ?>">რეგისტრაცია</a></li>
        <li><a href="patient_hstory.php" class="<?= $cur=='patient_hstory.php'?'active':'' ?>">პაციენტების მართვა</a></li>
        <li><a href="nomenklatura.php"   class="<?= $cur=='nomenklatura.php'?'active':'' ?>">ნომენკლატურა</a></li>
        <li><a href="administration.php" class="<?= $cur=='administration.php'?'active':'' ?>">ადმინისტრირება</a></li>
        <li><a href="angarishebi.php"    class="<?= $cur=='angarishebi.php'?'active':'' ?>">ანგარიშები</a></li>
      </ul>
    </div>
  </div>

  <!-- Subtabs -->
  <div class="subtabswrap">
    <ul class="subtabs">
      <li>
        <a href="patient_hstory.php"
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

  <!-- Content -->
  <div class="container">
    <div class="card" aria-labelledby="myPatientsTitle">
      <div class="head"><div class="title" id="myPatientsTitle">ჩემი პაციენტები</div></div>
      <div class="body">
        <div class="filters" role="group" aria-label="ფილტრები">
          <div class="f-group">
            <label for="f_doctor">მკურნალი ექიმი</label>
            <select id="f_doctor"><option value="0">ყველა</option></select>
          </div>
          <div class="f-group">
              <label for="f_patient">პაციენტის ძიება</label>
              <input
                type="search"
                id="f_patient"
                placeholder="სახელი, გვარი, პირადი № ან ტელეფონი"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="პაციენტის ძიება">
            </div>
<script>
(function(){
  const $q   = document.getElementById('f_patient');
  const $doc = document.getElementById('f_doctor');

  // ქართულზე ნორმალიზაცია + უსაფრთხო ქეის-დაუნ
  const lower = (s)=> (s && s.toLocaleLowerCase ? s.toLocaleLowerCase('ka') : String(s||'').toLowerCase());
  const norm  = (s)=> lower(s).replace(/\s+/g,' ').trim();

  // ყოველთვის ახლიდან მოიძიე ელემენტები, რადგან ეს სკრიპტი გაეშვა მანამდე, სანამ ისინი გაიპარსებოდა DOM-ში
  const getTbody = ()=> document.getElementById('rows');
  const getDept  = ()=> document.getElementById('f_dept');

  function hideLoadingRow($tbody){
    const lr = Array.from($tbody.querySelectorAll('tr')).find(tr =>
      tr.textContent && tr.textContent.indexOf('იტვირთება') !== -1
    );
    if (lr) lr.style.display = 'none';
  }

  function runFilter(){
    const $tbody = getTbody();
    if (!$tbody) return; // ჯერ არაა მოხატული tbody — მერე ისევ გამოიძახებთ

    hideLoadingRow($tbody);

    const q       = norm($q ? $q.value : '');
    const docVal  = $doc ? $doc.value : '0';
    const docTxt  = ($doc && $doc.selectedOptions[0]) ? norm($doc.selectedOptions[0].textContent) : '';

    const $dept   = getDept();
    const deptVal = $dept ? $dept.value : '';
    const deptTxt = ($dept && $dept.selectedOptions[0]) ? norm($dept.selectedOptions[0].textContent) : '';

    let visible = 0;

    Array.from($tbody.querySelectorAll('tr')).forEach(tr => {
      // placeholder-ების გამოტოვება
      const isPlaceholder = tr.querySelector('.muted') && tr.cells.length === 1;
      if (isPlaceholder) { tr.style.display = 'none'; return; }

      const rowText  = norm(tr.textContent);
      const rowDocId = tr.getAttribute('data-doctor-id') || '';
      const rowDept  = tr.getAttribute('data-dept') || '';

      const matchQ    = !q || rowText.includes(q);
      const matchDoc  = (docVal==='0') || rowDocId===docVal || (docTxt && rowText.includes(docTxt));
      const matchDept = (!deptVal) || rowDept===deptVal || (deptTxt && rowText.includes(deptTxt));

      const show = matchQ && matchDoc && matchDept;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    // „ჩანაწერები არ არის.“
    let emptyRow = $tbody.querySelector('tr.empty-row');
    if (!visible) {
      if (!emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.className = 'empty-row';
        const td = document.createElement('td');
        td.colSpan = 7; td.className = 'center muted';
        td.textContent = 'ჩანაწერები არ არის.';
        emptyRow.appendChild(td);
        $tbody.appendChild(emptyRow);
      }
      emptyRow.style.display = '';
    } else if (emptyRow) {
      emptyRow.style.display = 'none';
    }
  }

  // გლობალურად, რომ მონაცემების ჩატვირთვის შემდეგაც გამოიძახო
  window.runFilter = runFilter;

  // Event-ები
  function debounce(fn,ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(null,a),ms); }; }
  const debRun = debounce(runFilter, 180);

  if ($q){
    $q.addEventListener('input', debRun);
    $q.addEventListener('keydown', e => { if (e.key === 'Enter') runFilter(); });
  }
  if ($doc) $doc.addEventListener('change', runFilter);

  // როცა გვერდი ბოლომდე დაიხატება — უკვე იარსებებს #f_dept და #rows
  document.addEventListener('DOMContentLoaded', () => {
    const $dept = getDept();
    if ($dept) $dept.addEventListener('change', runFilter);
    runFilter();
  });
})();
</script>


          <div class="f-group">
            <label for="f_dept">განყოფილება</label>
            <select id="f_dept"><option value="">ყველა</option><option value="amb">ამბულატორია</option></select>
          </div>
        </div>

        <div style="margin-top:12px; overflow:auto">
          <table class="patients-table" id="tbl" aria-label="პაციენტების ცხრილი">
            <thead>
              <tr>
                <th>#</th><th>პირადი #</th><th>სახელი</th><th>გვარი</th><th>სქესი</th><th>დაბ. თარიღი</th><th>ტელეფონი</th>
              </tr>
            </thead>
            <tbody id="rows">
              <tr><td colspan="7" class="center muted">იტვირთება…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <aside class="card side" id="side" aria-labelledby="recentQueuesTitle">
      <div class="head"><div class="title" id="recentQueuesTitle">ბოლო რიგები</div></div>
      <div class="body" id="sideWrap"><div class="info-hint">აირჩიე პაციენტი ცხრილიდან.</div></div>
    </aside>
  </div>

  <!-- Modal root -->
  <div id="modalRoot" class="modal-overlay" aria-hidden="true"></div>

  <script>
  // helpers
  const $q=(s,r=document)=>r.querySelector(s);
  const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
  function fmtGender(g){
    const orig=(g??'').toString().trim();
    if(orig==='მამრობითი')return'მამრობითი';
    if(orig==='მდედრობითი')return'მდედრობითი';
    const v=orig.toLowerCase();
    if(!v)return'—';
    if(v==='1')return'მამრობითი';
    if(v==='2')return'მდედრობითი';
    if(v==='0'||v==='u'||v==='unknown')return'—';
    if(/^(m|male|man)\b/.test(v))return'მამრობითი';
    if(/^(f|female|woman)\b/.test(v))return'მდედრობითი';
    if(/^(კ|კაცი|მ|მამ|მამრ)/.test(v))return'მამრობითი';
    if(/^(ქ|ქალი|მდედ)/.test(v))return'მდედრობითი';
    return'—';
  }
  function fmtDate(d){
    if(!d)return'–';
    const t=d.toString();
    return t.length>=10?t.slice(0,10):t;
  }
  function debounce(fn,ms){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn.apply(null,a),ms);};}

  /**
   * printOnly(target) — CLEAN PRINT, with value rendering
   */
  function printOnly(target) {
    console.time('printOnly');

    var src = (typeof target === 'string') ? document.querySelector(target) : target;
    if (!src) { window.print(); console.timeEnd('printOnly'); return; }

    // ---- Clone (keep attributes, skip common UI noise) ----
    console.time('clone');
    function fastCloneDeep(node) {
      var c = node.cloneNode(false);
      var kids = node.childNodes;
      for (var i = 0; i < kids.length; i++) {
        var k = kids[i];
        if (k.nodeType === 3) c.appendChild(k.cloneNode(true)); // text
        else if (k.nodeType === 1) {
          if (k.matches('.no-print, .contract-actions, .btn')) continue;
          c.appendChild(k.cloneNode(true));
        }
      }
      return c;
    }
    var clone = fastCloneDeep(src);
    Array.prototype.forEach.call(clone.querySelectorAll('.no-print, .contract-actions, .btn, script'), function(n){ n.remove(); });
    console.timeEnd('clone');

    // ---- Controls -> text ----
    console.time('processElements');
    var els = clone.querySelectorAll('[id], img[srcset], input, textarea, select, [contenteditable]');
    var controls = [], images = [];
    for (var i=0; i<els.length; i++) {
      var el = els[i];
      if (el.hasAttribute('id')) el.removeAttribute('id');
      if (el.matches('input, textarea, select, [contenteditable]')) controls.push(el);
      else if (el.matches('img[srcset]')) images.push(el);
    }

    function extractForPrint(el){
      var tag = el.tagName;
      if (tag === 'TEXTAREA') return { kind:'textarea', text: el.value || '' };
      if (tag === 'SELECT') {
        var opts = el.selectedOptions ? Array.prototype.slice.call(el.selectedOptions) : [];
        var t = opts.length ? opts.map(function(op){ return (op.textContent||op.value||'').trim(); }).join(', ') : (el.value||'');
        return { kind:'select', text:t };
      }
      if (tag === 'INPUT') {
        var type = (el.getAttribute('type')||'text').toLowerCase();
        if (type === 'hidden')   return { kind:'hidden', text:'' };
        if (type === 'checkbox') return { kind:'checkbox', text: el.checked ? '✓' : '' };
        if (type === 'radio')    return { kind:'radio',    text: el.checked ? '●' : '' };
        return { kind:'input', text: el.value || '' };
      }
      if (el.hasAttribute('contenteditable')) return { kind:'editable', text:(el.textContent||'').trim() };
      return { kind:'text', text:'' };
    }

    (function processControls(){
      var ops = [];
      for (var i=0; i<controls.length; i++) {
        var c = controls[i], d = extractForPrint(c);
        if (d.kind === 'hidden') { if (c.parentNode) ops.push({p:c.parentNode, old:c, neu:null}); continue; }
        var span = document.createElement('span');
        span.className = 'print-' + d.kind;
        span.textContent = d.text;
        if (d.kind === 'textarea') span.style.whiteSpace = 'pre-wrap';
        var st = c.getAttribute('style');
        if (st) span.setAttribute('style', st + '; white-space:' + (d.kind==='textarea'?'pre-wrap':'normal') + ';');
        if (c.parentNode) ops.push({p:c.parentNode, old:c, neu:span});
      }
      for (var j=0; j<ops.length; j++) {
        var r = ops[j]; if (r.neu) r.p.replaceChild(r.neu, r.old); else r.p.removeChild(r.old);
      }
    })();

    for (var k=0; k<images.length; k++) {
      var img = images[k];
      img.removeAttribute('srcset');
      try { img.decoding = 'sync'; } catch(e){}
      try { img.loading  = 'eager'; } catch(e){}
      img.setAttribute('referrerpolicy','no-referrer');
      img.style.maxWidth = '100%'; img.style.height = 'auto';
    }
    console.timeEnd('processElements');

    // ---- Build wrapper ----
    console.time('createWrapper');
    var fragment = document.createDocumentFragment();
    var wrap = document.createElement('div');
    wrap.className = 'print-active';
    wrap.style.position = 'fixed';
    wrap.style.left = '-99999px';
    wrap.style.top = '0';
    wrap.style.width = '0';
    wrap.style.height = '0';
    wrap.style.overflow = 'hidden';
    wrap.style.contain = 'layout style paint';

    var style = document.createElement('style');
    style.setAttribute('media', 'print');
    style.appendChild(document.createTextNode([
      '@page{size:A4;margin:14mm;}',
      'html.printing body>*:not(.print-active){display:none!important;}',
      '.print-active,.print-active *{',
      '  box-shadow:none!important;text-shadow:none!important;filter:none!important;',
      '  backdrop-filter:none!important;animation:none!important;transition:none!important;',
      '  font-family:system-ui, Arial, sans-serif!important;',
      '  background:none!important;background-image:none!important;',
      '  -webkit-print-color-adjust:exact; print-color-adjust:exact;',
      '}',
      '.print-active{position:static!important;left:auto!important;top:auto!important;',
      '  width:auto!important;height:auto!important;overflow:visible!important;contain:none!important;}',
      '.print-active .vh-100,.print-active .h-screen,.print-active .min-h-screen{height:auto!important;min-height:auto!important;}',
      '.print-active [style*="100vh"]{height:auto!important;min-height:auto!important;}',
      '.print-active img, .print-active figure{page-break-inside:avoid;break-inside:avoid-page;}',
      '.print-active .page, .print-active .sheet{page-break-after:auto!important;break-after:auto!important;}',
      '.print-active .contract-actions,.print-active .btn,.print-active .no-print{display:none!important;}',
      '.print-active .contract-doc::before,.print-active .contract-doc::after{content:none!important;display:none!important;}',
      '/* ფორმა 100 - header დამალვა და კონტენტი პირველი ფურცლის თავიდან */',
      '.print-active .form100a-head{display:none!important;}',
      '.print-active .form100a-card{border:0!important;margin:0!important;padding:0!important;}',
      '.print-active .form100a-body{padding:0!important;margin:0!important;}',
      '.print-active .olvn.tmpldiv{padding:0!important;margin:0!important;}',
      '/* დიდი textarea-ები შეიძლება გაიყოს გვერდებს შორის */',
      '.print-active .print-textarea{break-inside:auto!important;page-break-inside:auto!important;}',
      '.print-active .si-wrapper{break-inside:auto!important;page-break-inside:auto!important;}'
    ].join('\n')));

    var baseStyle = document.createElement('style');
    baseStyle.appendChild(document.createTextNode([
      '.print-active{padding:0;margin:0;}',
      '.print-active .page, .print-active .sheet{page-break-after:auto;}'
    ].join('\n')));

    wrap.appendChild(style);
    wrap.appendChild(baseStyle);
    wrap.appendChild(clone);
    fragment.appendChild(wrap);
    console.timeEnd('createWrapper');

    function stripTrailingBreaks(root){
      var sel = [
        '.page-break','.pagebreak','.page_break','.page-sep','.page-separator',
        '[style*="page-break-after:always"]',
        '[style*="break-after: page"]','[style*="break-after:page"]'
      ].join(',');
      var nodes = root.querySelectorAll(sel);
      for (var i = nodes.length - 1; i >= 0; i--) {
        var el = nodes[i];
        var sib = el.nextSibling, trailingOnly = true;
        while (sib) {
          if (sib.nodeType === 3 && sib.nodeValue.trim()==='') { sib = sib.nextSibling; continue; }
          if (sib.nodeType === 1 && sib.offsetHeight === 0)    { sib = sib.nextSibling; continue; }
          trailingOnly = false; break;
        }
        if (trailingOnly) el.remove();
      }
      var force1 = root.querySelectorAll('[style*="page-break-after"]');
      for (var j=0; j<force1.length; j++) force1[j].style.setProperty('page-break-after','auto','important');
      var force2 = root.querySelectorAll('[style*="break-after"]');
      for (var k=0; k<force2.length; k++) force2[k].style.setProperty('break-after','auto','important');
    }

    console.time('printAndCleanup');

    function cleanup() {
      try {
        if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap);
        document.documentElement.classList.remove('printing');
      } finally {
        window.removeEventListener('afterprint', cleanup);
        console.timeEnd('printAndCleanup');
        console.timeEnd('printOnly');
      }
    }

    function go() {
      document.body.appendChild(fragment);
      stripTrailingBreaks(wrap);
      document.documentElement.classList.add('printing');

      if ('onafterprint' in window) {
        window.addEventListener('afterprint', cleanup, { once:true });
      } else {
        setTimeout(cleanup, 600);
      }
      window.print();
    }

    requestAnimationFrame(go);
    window.contractPrint = function(selector){ return printOnly(selector); };
  }

  /* =========================
     Existing code below kept; ES5-safe
     ========================= */

  // preload filters
  fetch('?action=filters')
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.status === 'ok' && Array.isArray(j.doctors)) {
        var sel = $q('#f_doctor');
        j.doctors.forEach(function(d){
          var o = document.createElement('option');
          o.value = d.id;
          o.textContent = d.name;
          sel.appendChild(o);
        });
      }
    })
    .catch(function(){ /* silent */ });

  ['f_doctor','f_dept'].forEach(function(id){
    $q('#' + id).addEventListener('change', debounce(loadList, 160));
  });

  var lastSelected = 0;

  function loadList(){
    var prms = new URLSearchParams({
      action: 'list',
      doctor_id: ($q('#f_doctor').value || '0'),
      dept: ($q('#f_dept').value || '')
    });

    $q('#rows').innerHTML = '<tr><td colspan="7" class="center muted">იტვირთება…</td></tr>';

    fetch('?' + prms.toString())
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.status !== 'ok') {
          $q('#rows').innerHTML = '<tr><td colspan="7" class="center">შეცდომა</td></tr>';
          return;
        }
        if (!Array.isArray(j.rows) || !j.rows.length) {
          $q('#rows').innerHTML = '<tr><td colspan="7" class="center muted">ჩანაწერი არ არის</td></tr>';
          $q('#sideWrap').innerHTML = '<div class="info-hint">აირჩიე პაციენტი ცხრილიდან.</div>';
          return;
        }

        var fr = document.createDocumentFragment();
        j.rows.forEach(function(r, i){
          var tr = document.createElement('tr');
          tr.dataset.id = r.id;
          tr.tabIndex = 0;
          tr.setAttribute('role','button');
          tr.setAttribute('aria-label','პაციენტის არჩევა');
          tr.innerHTML =
            '<td>' + (i+1) + '</td>' +
            '<td>' + esc(r.personal_id || '') + '</td>' +
            '<td>' + esc(r.first_name  || '') + '</td>' +
            '<td>' + esc(r.last_name   || '') + '</td>' +
            '<td>' + fmtGender(r.gender) + '</td>' +
            '<td>' + fmtDate(r.birthdate) + '</td>' +
            '<td>' + esc(r.phone || '') + '</td>';

          tr.addEventListener('click', function(){ selectRow(tr); });
          tr.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectRow(tr); }
          });
          fr.appendChild(tr);
        });
        $q('#rows').innerHTML = '';
        $q('#rows').appendChild(fr);

        if (lastSelected) {
          var trSel = $q('#rows tr[data-id="' + String(lastSelected).replace(/"/g, '\\"') + '"]');
          if (trSel) selectRow(trSel, true);
        }
      })
      .catch(function(){
        $q('#rows').innerHTML = '<tr><td colspan="7" class="center">ქსელის შეცდომა</td></tr>';
      });
  }

  function selectRow(tr, silent){
    if (silent === undefined) silent = false;

    $$('#rows tr').forEach(function(x){ x.classList.remove('selected'); });
    tr.classList.add('selected');

    var pid = tr.dataset.id;
    lastSelected = pid;

    if (!silent) { $q('#sideWrap').innerHTML = '<span class="pill">პაციენტი #' + esc(pid) + '</span>'; }

    fetch('?action=patient_timeline&patient_id=' + encodeURIComponent(pid))
      .then(function(r){ return r.text(); })
      .then(function(html){
        $q('#sideWrap').innerHTML = html;

        $$('#rightRows .nwrgdoc', $q('#sideWrap')).forEach(function(btn){
          btn.addEventListener('click', function(){
            var sid = btn.getAttribute('data-service');
            if (sid) openBox(sid);
          });
        });

        $$('#rightRows tr[tabindex="0"]', $q('#sideWrap')).forEach(function(row){
          row.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
              var b = row.querySelector('.nwrgdoc');
              if (b) {
                var sid = b.getAttribute('data-service');
                if (sid) openBox(sid);
              }
            }
          });
        });
      })
      .catch(function(){
        $q('#sideWrap').innerHTML = '<div class="info-hint">ვერ ჩაიტვირთა.</div>';
      });
  }

  function bindForm200a(sid, contentEl){
    const root   = contentEl.querySelector('#f200aRoot');
    const saveBtn= contentEl.querySelector('#btnSave200a');
    if (!root || !saveBtn) return;
    saveBtn.addEventListener('click', ()=>{
      const data = {};
      contentEl.querySelectorAll('#f200aRoot [data-f200a]').forEach(el=>{
        const k = el.getAttribute('data-f200a');
        if (el.type === 'checkbox') data[k] = el.checked ? 1 : 0;
        else data[k] = (el.value ?? '').toString();
      });
      fetch('?action=form200a_save', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ service_id: sid, payload: JSON.stringify(data) })
      })
      .then(r=>r.json())
      .then(j=>{ showToast(j.status==='ok' ? 'შენახულია' : 'შენახვა ვერ მოხერხდა', j.status!=='ok'); })
      .catch(()=> showToast('ქსელის შეცდომა', true));
    });
  }

function bindForm100a(sid, contentEl){
  const root     = $q('#f100aRoot', contentEl);
  const saveBtn  = $q('#btnSave100a', contentEl);
  const printBtn = $q('#btnPrint100a', contentEl);

  if (!root) return;

  function collectFormData() {
    const data = {};
    $$('#f100aRoot [data-f100a]', contentEl).forEach(el=>{
      const key = el.getAttribute('data-f100a');
      data[key] = (el.value ?? '').toString();
    });
    // Fix: ICD-10 search input-is mnishvneloba rom ar daikargos
    const icd10Input = root ? root.querySelector('#icd10_input') : null;
    if (icd10Input) {
      const icdVal = (icd10Input.value || '').trim();
      if (icdVal && (!data.diag || !data.diag.trim())) {
        data.diag = icdVal;
      } else if (icdVal && data.diag && data.diag.trim() && data.diag.indexOf(icdVal) === -1) {
        // Tu diag ukve shevsebulia da icd10 skhva mnishvnelobaa - daematos
        data.diag = icdVal + '\n' + data.diag;
      }
    }
    return data;
  }

  function saveForm() {
    const data = collectFormData();
    return fetch('?action=form100a_save', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        service_id: sid,
        payload: JSON.stringify(data)
      })
    })
    .then(r=>r.json());
  }

    if (saveBtn) {
      saveBtn.onclick = () => {
        saveBtn.disabled = true; // ბონუსი: დუბლ-კლიკი აღარ გააკეთებს ბევრ ჩანაწერს
        saveForm()
          .then(j => {
            showToast(
              j.status === 'ok' ? '✅ შენახულია' : '❌ შენახვა ვერ მოხერხდა',
              j.status !== 'ok'
            );
          })
          .catch(() => showToast('❌ ქსელის შეცდომა', true))
          .finally(() => { saveBtn.disabled = false; });
      };
    }


    if (printBtn) {
      printBtn.onclick = () => {
        printBtn.disabled = true;
        saveForm()
          .then(j=>{
            if (j.status === 'ok') {
              showToast('✅ შენახულია, იბეჭდება...', false);
              setTimeout(() => {
                printOnly(printBtn.closest('.printable'));
              }, 500);
            } else {
              showToast('❌ შენახვა ვერ მოხერხდა! ბეჭდვა გაუქმებულია.', true);
            }
          })
          .catch(()=> showToast('❌ ქსელის შეცდომა! ბეჭდვა გაუქმებულია.', true))
          .finally(()=> { printBtn.disabled = false; });
      };
    }


  const tplSelect  = $q('#f100aTplSelect', contentEl);
  const tplSaveBtn = $q('#btnSaveTpl100a', contentEl);

  function applyTemplatePayload(payload){
    if (!payload || typeof payload !== 'object') return;
    $$('#f100aRoot [data-f100a]', contentEl).forEach(el=>{
      const key = el.getAttribute('data-f100a');
      if (Object.prototype.hasOwnProperty.call(payload, key)) {
        el.value = (payload[key] ?? '').toString();
        if (el.tagName === 'TEXTAREA') {
          el.style.height = 'auto';
          el.style.height = (el.scrollHeight) + 'px';
        }
      }
    });
  }

  function loadTemplates(){
    if (!tplSelect) return;
    fetch('?action=form100a_tpl_list', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(j => {
        if (j.status !== 'ok' || !Array.isArray(j.items)) return;
        tplSelect.innerHTML = '<option value="">შაბლონები...</option>';
        j.items.forEach(it => {
          const opt = document.createElement('option');
          opt.value = it.id;
          opt.textContent = it.name;
          tplSelect.appendChild(opt);
        });
      })
      .catch(()=>{/* ჩუმად */});
  }

if (tplSaveBtn) {
  tplSaveBtn.onclick = () => {
    if (tplSaveBtn.dataset.busy === '1') return;
    tplSaveBtn.dataset.busy = '1';
    tplSaveBtn.disabled = true;

    const name = (prompt('შაბლონის სახელი:', '') || '').trim();
    if (!name) {
      tplSaveBtn.dataset.busy = '0';
      tplSaveBtn.disabled = false;
      return;
    }

    const data = collectFormData();

    fetch('?action=form100a_tpl_save&debug=1', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      credentials: 'same-origin',
      body: new URLSearchParams({
        name: name,
        payload: JSON.stringify(data)
      })
    })
    .then(r => r.json())
    .then(j => {
      if (j.status === 'ok') {
        showToast('✅ შაბლონი შენახულია', false);
        loadTemplates();
      } else {
        showToast('❌ შაბლონის შენახვა ვერ მოხერხდა', true);
      }
    })
    .catch(() => showToast('❌ ქსელის შეცდომა (შაბლონი)', true))
    .finally(() => {
      tplSaveBtn.dataset.busy = '0';
      tplSaveBtn.disabled = false;
    });
  };
}

if (tplSelect) {
  tplSelect.onchange = () => {
    const id = tplSelect.value;
    if (!id) return;

    // სურვილისამებრ: busy ბლოკიც დავამატოთ
    if (tplSelect.dataset.busy === '1') return;
    tplSelect.dataset.busy = '1';

    fetch(`?action=form100a_tpl_get&id=${encodeURIComponent(id)}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(j => {
        if (j.status === 'ok' && j.payload) {
          applyTemplatePayload(j.payload);
          showToast('✅ შაბლონი გამოყენებულია', false);
        } else {
          showToast('❌ შაბლონის ჩატვირთვა ვერ მოხერხდა', true);
        }
      })
      .catch(() => showToast('❌ ქსელის შეცდომა (ჩატვირთვა)', true))
      .finally(() => {
        tplSelect.dataset.busy = '0';
      });
  };
}


  loadTemplates();

  // 🔹 აქ ვამაგრებთ ICD-10 autocomplete-ს უკვე ჩატვირთულ form100a-მოდალზე
  if (typeof initICD10 === 'function') {
    initICD10(contentEl);
  }
}

  function showToast(msg, isErr){
    const t = document.createElement('div');
    t.className = 'toast'+(isErr?' err':'' );
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(()=>{ t.remove(); }, 1800);
  }

  function openBox(serviceId){
    const modal = $q('#modalRoot');
    modal.dataset.serviceId = String(serviceId);
    modal.innerHTML = '<div class="modal-card" role="dialog" aria-modal="true"><button class="btn ghost closeBtn" style="position:absolute;top:10px;right:10px" aria-label="დახურვა">✕</button><div class="pad" style="padding:28px"><div class="muted">იტვირთება…</div></div></div>';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');

    const rebindClose = () => {
      const btn = modal.querySelector('.closeBtn');
      if (btn) btn.addEventListener('click', closeBox, { once: true });
      const onOverlay = (e) => { if (e.target === modal) { closeBox(); } };
      modal.addEventListener('click', onOverlay, { once: true });
    };
    rebindClose();

    fetch(`?action=box&service_id=${encodeURIComponent(serviceId)}`)
      .then(r => r.text())
      .then(html => {
        modal.innerHTML = html;
        rebindClose();

        const content = $q('.content', modal);
        if (!content) return;

        const isDisabled = el =>
          el?.getAttribute('aria-disabled') === 'true' || el?.hasAttribute('disabled');

        function bindKvEvents(sid){
          const $c = content;

          const searchInput = $q('#kv-search', $c);
          if (searchInput) {
            searchInput.addEventListener('input', function() {
              const q = this.value.trim().toLowerCase();
              $$('#f100kvlevebi [data-row]', $c).forEach(tr => {
                const name = ($q('[data-name]', tr)?.textContent || '').toLowerCase();
                tr.style.display = name.includes(q) ? '' : 'none';
              });
            });
          }

          $c.addEventListener('click', function(ev){
            const a = ev.target.closest('a[data-act]');
            if (!a) return;
            if (isDisabled(a)) return;
            ev.preventDefault();

            const act = a.getAttribute('data-act');
            let target = null, value = null;
            if (act === 'check-all-kv')   { target = 'kv';  value = 1; }
            if (act === 'uncheck-all-kv') { target = 'kv';  value = 0; }
            if (act === 'check-all-ans')  { target = 'ans'; value = 1; }
            if (act === 'uncheck-all-ans'){ target = 'ans'; value = 0; }

            if (target !== null) {
              fetch('?action=kv_bulk', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ service_id: sid, target, value })
              }).then(r => r.json()).then(j => {
                if (j.status === 'ok') {
                  const selector = target === 'kv' ? '.zm4' : '.zm5';
                  $$('#f100kvlevebi [data-row] ' + selector, $c).forEach(cb => { cb.checked = !!value; });
                }
              });
            }
          });

          $c.addEventListener('change', function(ev){
            const cb = ev.target;
            if (!(cb instanceof HTMLInputElement)) return;
            if (!cb.classList.contains('zm4') && !cb.classList.contains('zm5')) return;
            const tr = cb.closest('[data-row]'); if (!tr) return;
            const id = tr.getAttribute('data-id'); if (!id) return;
            const field = cb.classList.contains('zm4') ? 'kv' : 'ans';
            const value = cb.checked ? 1 : 0;
            fetch('?action=kv_toggle', {
              method: 'POST',
              headers: {'Content-Type':'application/x-www-form-urlencoded'},
              body: new URLSearchParams({ pi_id: id, field, value })
            }).then(r => r.json()).then(j => {
              if (j.status !== 'ok') { cb.checked = !cb.checked; }
            }).catch(() => { cb.checked = !cb.checked; });
          });
        }

        modal.addEventListener('click', function(e){
          const a = e.target.closest('#lfgttg .navbtn');
          if (!a) return;
          if (isDisabled(a)) return;
          e.preventDefault();

          const sid = parseInt(modal.dataset.serviceId || '0', 10);
          const name = a.getAttribute('n') || a.id || a.textContent.trim();

          $$('#lfgttg .navbtn', modal).forEach(x => x.classList.remove('is-active'));
          a.classList.add('is-active');

          content.innerHTML = '<div class="pad" style="padding:18px">იტვირთება…</div>';

          if (a.id === 'kvllist' || name === 'kvlevebif100shi') {
            fetch(`?action=kv_panel&service_id=${encodeURIComponent(sid)}`)
              .then(r => r.text())
              .then(html => { content.innerHTML = html; bindKvEvents(sid); content.scrollTop = 0; })
              .catch(() => { content.innerHTML = '<div class="pad">ვერ ჩაიტვირთა.</div>'; });
          }
          else if (a.id === 'AGtfrm100' || name === 'amb-form100') {
            fetch(`?action=form100a&service_id=${encodeURIComponent(sid)}`)
              .then(r => r.text())
              .then(html => { content.innerHTML = html; bindForm100a(sid, content); content.scrollTop = 0; })
              .catch(() => { content.innerHTML = '<div class="pad">ვერ ჩაიტვირთა.</div>'; });
          }
          else if (a.id === 'z2qt7092rz7c3rin3' || name === 'd4lf5xbmwg3tegkg') {
            fetch(`?action=consent_written&service_id=${encodeURIComponent(sid)}`)
              .then(r => r.text())
              .then(html => { content.innerHTML = html; content.scrollTop = 0; })
              .catch(() => { content.innerHTML = '<div class="pad">ვერ ჩაიტვირთა.</div>'; });
          }
          else if (a.id === 'f28dzhbm0rwtw3n0' || name === 'cmydjokr34vjakc6') {
            fetch(`?action=contract_show&service_id=${encodeURIComponent(sid)}`)
              .then(r => r.text())
              .then(html => { content.innerHTML = html; content.scrollTop = 0; })
              .catch(() => { content.innerHTML = '<div class="pad">ვერ ჩაიტვირთა.</div>'; });
          }
          else if (a.id === 'F200-a' || name === 'forma200a') {
            fetch(`?action=form200a&service_id=${encodeURIComponent(sid)}`)
              .then(r => r.text())
              .then(html => { content.innerHTML = html; bindForm200a(sid, content); content.scrollTop = 0; })
              .catch(() => { content.innerHTML = '<div class="pad">ვერ ჩაიტვირთა.</div>'; });
          }
          else {
            alert('Action: ' + name + ' (აქ ჩასვი რეალური ქმედება)');
          }
        });
      })
      .catch(() => {
        modal.innerHTML = '<div class="modal-card" role="dialog" aria-modal="true"><button class="btn ghost closeBtn" style="position:absolute;top:10px;right:10px" aria-label="დახურვა">✕</button><div class="pad" style="padding:28px">შეცდომა ჩატვირთვისას.</div></div>';
        rebindClose();
      });
  }
  window.openBox = openBox;

  function closeBox(){
    const modal = $q('#modalRoot');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    delete modal.dataset.serviceId;
    modal.innerHTML = '';
    const sel = $q('#rows tr.selected');
    if (sel) sel.focus();
  }
  window.closeBox = closeBox;

  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const modal = $q('#modalRoot');
      if (modal && modal.classList.contains('show')) closeBox();
    }
  });

  (function(){
    const wrap = $q('#userMenu');
    const btn  = $q('#userBtn');
    const dd   = $q('#userDropdown');
    if (!wrap || !btn || !dd) return;

    wrap.setAttribute('role', 'group');
    btn.setAttribute('role', 'button');
    btn.setAttribute('aria-haspopup', 'menu');
    btn.setAttribute('aria-controls', 'userDropdown');
    btn.tabIndex = 0;
    dd.setAttribute('role', 'menu');
    dd.setAttribute('aria-hidden', 'true');

    const items = Array.from(dd.querySelectorAll('a'));
    items.forEach(a => { a.setAttribute('role','menuitem'); a.tabIndex = -1; });

    let open = false;
    let lastFocus = null;

    const set = (v) => {
      open = !!v;
      dd.style.display = open ? 'block' : 'none';
      dd.setAttribute('aria-hidden', open ? 'false' : 'true');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      wrap.setAttribute('aria-haspopup', 'true');
      if (open) {
        lastFocus = document.activeElement;
        (items[0] || dd).focus();
      } else {
        (lastFocus || btn).focus();
      }
    };
    set(false);

    const toggle = (e) => { e?.preventDefault(); set(!open); };
    btn.addEventListener('click', toggle);
    btn.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
      if (e.key === 'ArrowDown' && !open) { e.preventDefault(); set(true); }
      if (e.key === 'Escape' && open) { e.preventDefault(); set(false); }
    });

    dd.addEventListener('keydown', (e) => {
      const i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { e.preventDefault(); set(false); return; }
      if (!items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); (items[(i+1) % items.length]).focus(); }
      if (e.key === 'ArrowUp')   { e.preventDefault(); (items[(i-1+items.length) % items.length]).focus(); }
      if (e.key === 'Home')      { e.preventDefault(); items[0].focus(); }
      if (e.key === 'End')       { e.preventDefault(); items[items.length-1].focus(); }
      if (e.key === 'Tab')       { set(false); }
    });

    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target) && open) set(false);
    });
  })();

  (function(){
    const onKey = (e) => {
      if (e.key !== 'Escape') return;
      const modal = $q('#modalRoot');
      if (modal && modal.classList.contains('show') && typeof closeBox === 'function') {
        e.preventDefault();
        closeBox();
      }
    };
    window.addEventListener('keydown', onKey);
  })();

  (function(){
    const $ = (sel, root=document)=>root.querySelector(sel);
    const $$ = (sel, root=document)=>Array.from(root.querySelectorAll(sel));
    const input = document.getElementById('patientSearch');
    const box   = document.getElementById('patientSearchResults');

    function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

    const isoToDmy = (iso)=>{
      if(!iso) return '';
      const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
      return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
    };

    async function searchPatients(q){
      if(!q || q.trim().length < 2){ box.innerHTML=''; return; }
      try{
        const r = await fetch(`?action=patient_search&q=${encodeURIComponent(q)}&limit=20`, {credentials:'same-origin'});
        const data = await r.json();
        renderResults(Array.isArray(data.items)?data.items:[]);
      }catch(e){
        console.error(e);
        box.innerHTML = '<div style="padding:10px;color:#b91c1c">შეცდომა ძებნაში</div>';
      }
    }

    function renderResults(items){
      if(!items.length){
        box.innerHTML = '<div style="padding:10px;color:#64748b">შედეგი ვერ მოიძებნა</div>';
        return;
      }
      const rows = items.map(it=>{
        const full = [it.first_name||'', it.last_name||''].join(' ').trim();
        const phone = it.mobile || it.phone || '';
        const dob = isoToDmy(it.birthdate || '');
        return `
          <tr data-id="${it.id||''}">
            <td>${it.personal_id || ''}</td>
            <td>${full}</td>
            <td>${dob}</td>
            <td>${phone}</td>
            <td class="actions">
              <button class="btn" type="button" data-edit="${it.id||''}">რედაქტირება</button>
            </td>
          </tr>`;
      }).join('');
      box.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>პ/ნ</th><th>სახელი, გვარი</th><th>დ. თარიღი</th><th>ტელ.</th><th></th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>`;

      $$('#patientSearchResults tbody tr').forEach(tr=>{
        tr.addEventListener('click', (e)=>{
          if(e.target && e.target.matches('button[data-edit]')) return;
          const id = tr.getAttribute('data-id');
          if(id) loadPatient(+id);
        });
      });
      $$('button[data-edit]', box).forEach(btn=>{
        btn.addEventListener('click', (e)=>{
          e.stopPropagation();
          const id = btn.getAttribute('data-edit');
          if(id) loadPatient(+id);
        });
      });
    }

    window.loadPatient = async function(id){
      try{
        const r = await fetch(`?action=patient_get&id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
        const data = await r.json();
        if(data && data.item){
          openEditModal(data.item);
        }else{
          alert('პაციენტის ჩატვირთვა ვერ მოხერხდა');
        }
      }catch(e){
        console.error(e);
        alert('პაციენტის ჩატვირთვა ვერ მოხერხდა');
      }
    };

    if(input){
      input.addEventListener('input', debounce(e=>searchPatients(e.target.value), 250));
      input.addEventListener('keydown', (e)=>{
        if(e.key === 'Enter'){
          e.preventDefault();
          const first = box.querySelector('button[data-edit]');
          if(first) first.click();
        }
      });
    }
  })();

  (function(){
    if (typeof printOnly === 'function') {
      window.printOnly = printOnly;
    }
    if (typeof openBox === 'function') {
      window.openBox = openBox;
    }
    if (typeof closeBox === 'function') {
      window.closeBox = closeBox;
    }

    if (typeof loadList === 'function') {
      loadList();
    }
  })();
  </script>



<script>
(function() {

  // გავხადოთ გლობალური, რომ bindForm100a-დან გამოვიყენოთ
  window.initICD10 = function(scope) {
    const root = scope || document;

    const $input    = root.querySelector('#icd10_input');
    const $results  = root.querySelector('#icd10_results');
    const $textarea = root.querySelector('#icd10_textarea');

    if (!$input || !$results) {
      console.log('[ICD10] widgets not found in this scope');
      return;
    }

    console.log('[ICD10] init OK', { $input, $results, $textarea });

    let lastQuery       = '';
    let abortController = null;
    let blurHideTimer   = null;

    function clearResults() {
      $results.innerHTML = '';
      $results.style.display = 'none';
    }

    function appendToTextarea(line) {
      if (!$textarea) return;
      const current = ($textarea.value || '').trim();
      $textarea.value = current ? (current + "\n" + line) : line;
    }

    function renderItems(items) {
      if (!items || !items.length) {
        clearResults();
        return;
      }

      const frag = document.createDocumentFragment();

      items.forEach(function(item) {
        const code  = String(item.code || '').trim();
        const title = String(item.title || '').trim();
        const full  = (item.full && String(item.full).trim()) ||
                      (code && title ? (code + ' — ' + title) :
                       code || title || '');

        if (!full) return;

        const row = document.createElement('div');
        row.className = 'icd10-item';

        const spanCode  = document.createElement('span');
        spanCode.className = 'icd10-code';
        spanCode.textContent = code;

        const spanTitle = document.createElement('span');
        spanTitle.className = 'icd10-title';
        spanTitle.textContent = title || full;

        row.appendChild(spanCode);
        row.appendChild(spanTitle);

        row.addEventListener('mousedown', function(ev) {
          ev.preventDefault();

          appendToTextarea(full);
          if (code) {
            $input.value = code;
          } else {
            $input.value = full;
          }

          clearResults();
        });

        frag.appendChild(row);
      });

      $results.innerHTML = '';
      $results.appendChild(frag);
      $results.style.display = 'block';
    }

    async function runSearch(q) {
      q = (q || '').trim();
      lastQuery = q;

      if (!q) {
        clearResults();
        return;
      }

      if (abortController) {
        abortController.abort();
      }
      abortController = new AbortController();

      try {
        const url = '?action=icd10_search&q=' + encodeURIComponent(q);
        console.log('[ICD10] fetch', url);

        const resp = await fetch(url, {
          method: 'GET',
          signal: abortController.signal,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        if (!resp.ok) {
          throw new Error('HTTP ' + resp.status);
        }

        const data = await resp.json();
        console.log('[ICD10] response', data);

        if (q !== lastQuery) {
          return;
        }

        if (data && data.status === 'ok' && Array.isArray(data.items)) {
          renderItems(data.items);
        } else {
          clearResults();
        }
      } catch (e) {
        if (e.name === 'AbortError') {
          return;
        }
        console.error('[ICD10] error:', e);
        clearResults();
      }
    }

    // input-ზე ძიება
    $input.addEventListener('input', function() {
      const q = this.value || '';
      runSearch(q);
    });

    // Esc → ჩამოსაშლის დამალვა
    $input.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        clearResults();
      }
    });

    // focus-ზე, თუ უკვე გვაქვს შედეგები, ისევ ვაჩვენოთ
    $input.addEventListener('focus', function() {
      if ($results.innerHTML.trim()) {
        $results.style.display = 'block';
      }
    });

    // blur-ზე – პატარა დაყოვნებით, რომ mousedown-ზე მოასწროს
    $input.addEventListener('blur', function() {
      blurHideTimer = setTimeout(function() {
        clearResults();
      }, 150);
    });

    // dropdown-ზე mousedown არ გაუქროს
    $results.addEventListener('mousedown', function() {
      if (blurHideTimer) {
        clearTimeout(blurHideTimer);
        blurHideTimer = null;
      }
    });
  };

})();
/* Global function (works even when consent HTML is loaded via AJAX) */
window.setConsent = function(type){
  const textarea = document.getElementById('consentText');
  if (!textarea) { console.error('consentText textarea not found'); return; }

  const facility = textarea.getAttribute('data-facility') || '';

  const childText =
`თანხმობას ვაცხადებ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ405695323) მიერ განხორციელდეს ჩემი ვიდეო მონიტორინგი, აგრეთვე დამუშავდეს ჩემი პერსონალური მონაცემები (სახელი, გვარი, პირადი ნომერი, დაბადების თარიღი, ტელეფონის ნომერი, ელექტრონული ფოსტა, ინფორმაცია სქესის შესახებ, ინფორმაცია მისამართის შესახებ და ა.შ.). ასევე თანახმა ვარ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ 405695323) მიერ განხორციელდეს ჩემი არასრულწლოვანი შვილი.`;

  const adultText =
`თანხმობას ვაცხადებ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ405695323) მიერ განხორციელდეს ჩემი ვიდეო მონიტორინგი, აგრეთვე დამუშავდეს ჩემი პერსონალური მონაცემები (სახელი, გვარი, პირადი ნომერი, დაბადების თარიღი, ტელეფონის ნომერი, ელექტრონული ფოსტა, ინფორმაცია სქესის შესახებ, ინფორმაცია მისამართის შესახებ და ა.შ.).`;

  textarea.value = (type === 'adult') ? adultText : childText;

  textarea.style.height = 'auto';
  textarea.style.height = textarea.scrollHeight + 'px';
};
</script>

</body>
</html>

