<?php
// isAjaxRequest helper
function isAjaxRequest(): bool {
    return isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest";
}
// Show real error while you fix 500s (optional in prod)
// ini_set("display_errors","1"); ini_set("display_startup_errors","1"); error_reporting(E_ALL);

// Production mode - errors to log only
ini_set("display_errors", "0");
ini_set("display_startup_errors", "0");
ini_set("log_errors", "1");
error_reporting(E_ALL);

// 1) Session
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }


$isAjax = isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest";
// 2) RBAC switches — define BEFORE including the gate

error_log("AJAX_DEBUG: HTTP_X_REQUESTED_WITH=" . ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "NOT_SET") . ", isAjax=" . ($isAjax ? "true" : "false") . ", METHOD=" . $_SERVER["REQUEST_METHOD"]);
defined("RBAC_ALLOW_ADMIN_BYPASS") || define("RBAC_ALLOW_ADMIN_BYPASS", true);
defined("RBAC_DEBUG")              || define("RBAC_DEBUG", false);
defined("RBAC_AUTO_REGISTER")      || define("RBAC_AUTO_REGISTER", false);
defined("RBAC_LOGIN_URL")          || define("RBAC_LOGIN_URL", "/index.php");

// 3) Load DB config (must define $pdo). Try a few sensible locations.
$__root = rtrim($_SERVER["DOCUMENT_ROOT"] ?? __DIR__, "/");
$__candidates = [
  $__root."/config/config.php",
  __DIR__."/../config/config.php",
  __DIR__."/config/config.php",
];
$__loaded_cfg = false;
foreach ($__candidates as $__cfg) {
  if (is_file($__cfg)) { require_once $__cfg; $__loaded_cfg = true; break; }
}
if (!($__loaded_cfg && isset($pdo) && $pdo instanceof PDO)) {
  http_response_code(500);
  error_log("SanMedic: DB config not found or \$pdo not created.");
  echo "სისტემის შეცდომა. გთხოვთ დაუკავშირდეთ ადმინისტრატორს.";
  exit;
}

// 4) RBAC gate + enforce
require_once $__root."/inc/rbac_gate.php";
requirePage($pdo);

// Optional: flash helper
if (!function_exists("flash")) {
  function flash(string $type, string $msg): void {
    $_SESSION["flash"] ??= [];
    $_SESSION["flash"][] = ["type" => $type, "msg" => $msg];
  }
}
if (empty($_SESSION['user_id'])) {
    if (isAjaxRequest()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'err', 'msg' => 'unauthorized']);
        exit;
    }
    header('Location: index.php');
    exit;
}
// put this before the <ul class="tabs"> (and before the upnav that uses $cur)
$cur = $currentFile ?? basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? 'index.php');

// if not already defined, define $tabs too
$tabs = [
  ['file' => 'dashboard.php',      'label' => 'რეგისტრაცია',       'icon' => 'fa-user-plus'],
  ['file' => 'patient_hstory.php', 'label' => 'პაციენტის ისტორია', 'icon' => 'fa-notes-medical'],
  ['file' => 'nomenklatura.php',   'label' => 'ნომენკლატურა',      'icon' => 'fa-list'],
  ['file' => 'angarishebi.php',    'label' => 'ანგარიშები',        'icon' => 'fa-file-invoice'],
];
// ---------------------------
// 2) CONFIG + COMMON
// ---------------------------
require __DIR__ . '/../config/config.php';
$error = '';

// Debug logging for AJAX issues
if ($_SERVER["REQUEST_METHOD"] === "POST" && isAjaxRequest()) {
    slog("AJAX POST received", ["action" => $_POST["action"] ?? "none", "headers" => getallheaders()]);
}
/* =========================
   RBAC: per-page access gate
   ========================= */

// Helpers to check schema pieces safely
if (!function_exists('tableExists')) {
    function tableExists(PDO $pdo, string $table): bool {
        try {
            $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
            if (!$db) return false;
            $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
            $st->execute([$db, $table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('columnExists')) {
    function columnExists(PDO $pdo, string $table, string $col): bool {
        try {
            $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
            if (!$db) return false;
            $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
            $st->execute([$db, $table, $col]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

// Fetch user roles (supports either users.role_id or user_roles M2M)
if (!function_exists('fetchUserRoles')) {
    function fetchUserRoles(PDO $pdo, int $uid): array {
        if ($uid <= 0) return [];
        // Prefer many-to-many table if present
        if (tableExists($pdo, 'user_roles') && columnExists($pdo, 'user_roles', 'user_id')) {
            $sql = "SELECT r.name
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = ?";
            $st = $pdo->prepare($sql);
            $st->execute([$uid]);
            return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
        }
        // Fallback: users.role_id
        if (tableExists($pdo, 'users') && columnExists($pdo, 'users', 'role_id')) {
            $sql = "SELECT r.name
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ?";
            $st = $pdo->prepare($sql);
            $st->execute([$uid]);
            $name = $st->fetchColumn();
            return $name ? [$name] : [];
        }
        return [];
    }
}

// Ensure current page exists in `pages` (auto-register)
if (!function_exists('ensurePageRecord')) {
    function ensurePageRecord(PDO $pdo, string $file, ?string $label = null): ?int {
        if (!tableExists($pdo, 'pages')) return null;
        try {
            $label = $label ?? $file;
            $sel = $pdo->prepare("SELECT id FROM pages WHERE file=? LIMIT 1");
            $sel->execute([$file]);
            $pid = $sel->fetchColumn();
            if ($pid) return (int)$pid;
            $ins = $pdo->prepare("INSERT INTO pages (file,label) VALUES (?,?)");
            $ins->execute([$file, $label]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) { return null; }
    }
}

// Core permission check
if (!function_exists('userCanAccessPage')) {
    function userCanAccessPage(PDO $pdo, int $uid, string $file, array $roles): bool {
        // If RBAC tables are missing, allow (compatibility mode)
        if (!tableExists($pdo, 'roles') || !tableExists($pdo, 'pages') || !tableExists($pdo, 'role_pages')) {
            return true;
        }

        // Admin bypass
        // Super roles bypass (admin & superadmin)
$superBypass = ['admin','superadmin'];
foreach ($roles as $r) {
    if (in_array(mb_strtolower((string)$r, 'UTF-8'), $superBypass, true)) {
        return true;
    }
}


        // Make sure page is registered
        ensurePageRecord($pdo, $file);

        if (empty($roles)) return false;

        // Allowed for any of the user's roles?
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql = "
          SELECT 1
          FROM role_pages rp
          JOIN pages p ON p.id = rp.page_id
          JOIN roles r ON r.id = rp.role_id
          WHERE p.file = ?
            AND r.name IN ($placeholders)
            AND COALESCE(rp.allowed, 0) = 1
          LIMIT 1";
        $args = array_merge([$file], $roles);
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return (bool)$st->fetchColumn();
    }
}

// Enforce RBAC for this request
$currentFile = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$userId      = (int)($_SESSION['user_id'] ?? 0);
$userRoles   = fetchUserRoles($pdo, $userId);
// --- Hard bypass if this account's username is "admin" ---
$__superUserBypass = false;
try {
    // If username is in session, prefer that
    $sessUname = strtolower((string)($_SESSION['username'] ?? ''));
    if ($sessUname === 'admin') {
        $__superUserBypass = true;
    } elseif (tableExists($pdo, 'users') && columnExists($pdo, 'users', 'username')) {
        // Otherwise read from DB
        $st = $pdo->prepare("SELECT LOWER(username) FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $__superUserBypass = ($st->fetchColumn() === 'admin');
    }
} catch (Throwable $e) {
    // ignore
}

if (!$__superUserBypass && !userCanAccessPage($pdo, $userId, $currentFile, $userRoles)) {
    if (isAjaxRequest()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'err', 'msg' => 'forbidden']);
    } else {
        http_response_code(403);
        flash('error', 'თქვენ არ გაქვთ ამ გვერდზე წვდომა.');
        echo '<h1 style="font-family:system-ui,sans-serif;margin:24px;">403 – Access denied</h1>';
    }
    exit;
}
/* RBAC helper for views (tabs etc.) */
$can = function(string $file) use ($pdo, $userId, $userRoles, $__superUserBypass): bool {
    if ($__superUserBypass) return true; // username 'admin' bypass
    return userCanAccessPage($pdo, $userId, basename($file), $userRoles);
};

/* =========================
   END RBAC
   ========================= */

// Helpers for date/age/print-subject
if (!function_exists('_dt')) {
    function _dt(?string $s): ?DateTime {
        $s = trim((string)$s);
        if ($s === '' || $s === '0000-00-00') return null;
        try { return new DateTime($s); } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('fmt_date')) {
    function fmt_date(?string $s, string $format = 'd.m.Y'): string {
        $d = _dt($s);
        return $d ? $d->format($format) : '';
    }
}

if (!function_exists('calc_age_years')) {
    function calc_age_years(?string $date): ?int {
        $d = _dt($date);
        if (!$d) return null;
        $now = new DateTime('today');
        return (int)$d->diff($now)->y;
    }
}

/**
 * აბრუნებს საბეჭდი სუბიექტს: თუ <18 და relative_* შევსებულია → წარმომადგენელი, სხვა შემთხვევაში → პაციენტი.
 */
if (!function_exists('pick_print_subject')) {
    function pick_print_subject(array $p): array {
        $age    = calc_age_years($p['birthdate'] ?? null);
        $minor  = ($age !== null && $age < 18);

        $hasGuardian =
            trim((string)($p['relative_first_name']  ?? '')) !== '' ||
            trim((string)($p['relative_last_name']   ?? '')) !== '' ||
            trim((string)($p['relative_personal_id'] ?? '')) !== '';

        $pat_full = trim((string)($p['last_name'] ?? '') . ' ' . (string)($p['first_name'] ?? ''));
        $pat_pid  = (string)($p['personal_id'] ?? '');

        if ($minor && $hasGuardian) {
            return [
                'who'              => 'guardian',
                'relation'         => (string)($p['relative_type'] ?? 'კანონიერი წარმომადგენელი'),
                'full_name'        => trim((string)($p['relative_last_name'] ?? '') . ' ' . (string)($p['relative_first_name'] ?? '')) ?: '—',
                'personal_id'      => (string)($p['relative_personal_id'] ?? ''),
                'birthdate'        => (string)($p['relative_birthdate'] ?? ''),
                'gender'           => (string)($p['relative_gender'] ?? ''),
                'phone'            => (string)($p['relative_phone'] ?? ($p['phone'] ?? $p['mobile'] ?? $p['telephone'] ?? '')),
                'address'          => (string)($p['relative_address'] ?? ($p['address'] ?? '')),
                'workplace'        => (string)($p['workplace'] ?? ''),
                'represented_full' => $pat_full,
                'represented_pid'  => $pat_pid,
                'is_minor'         => true,
            ];
        }
        return [
            'who'              => 'patient',
            'relation'         => 'პაციენტი',
            'full_name'        => $pat_full,
            'personal_id'      => $pat_pid,
            'birthdate'        => (string)($p['birthdate'] ?? ''),
            'gender'           => (string)($p['gender'] ?? ''),
            'phone'            => (string)($p['phone'] ?? $p['mobile'] ?? $p['telephone'] ?? ''),
            'address'          => (string)($p['address'] ?? ''),
            'workplace'        => (string)($p['workplace'] ?? ''),
            'represented_full' => $pat_full,
            'represented_pid'  => $pat_pid,
            'is_minor'         => false,
        ];
    }
}

// ---------------------------
/* 3) დონორის utility-ები */
function donor_get_balance(PDO $pdo, int $pid): array {
    $q1 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM patient_guarantees WHERE patient_id = ?");
    $q1->execute([$pid]);
    $total = (float)$q1->fetchColumn();

    $q2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE patient_id = ? AND method = 'donor'");
    $q2->execute([$pid]);
    $used = (float)$q2->fetchColumn();

    $left = max($total - $used, 0);
    return ['total'=>$total, 'used'=>$used, 'left'=>$left];
}

/**
 * დონორის თანხის გამოყენება = payments-ში method='donor'
 */
function donor_apply(PDO $pdo, int $pid, float $wantAmount, ?string $orderNo = null): array {
    $bal = donor_get_balance($pdo, $pid);
    $apply = min(max($wantAmount, 0), $bal['left']);
    if ($apply <= 0) {
        return ['ok'=>false, 'msg'=>'დონორის ნაშთი არაა ან თანხა არასწორია'];
    }
    $ins = $pdo->prepare("
        INSERT INTO payments (patient_id, paid_at, method, amount, order_no)
        VALUES (?, NOW(), 'donor', ?, ?)
    ");
    $ins->execute([$pid, $apply, $orderNo]);
    return ['ok'=>true, 'applied'=>$apply];
}

// =====================================================================
// 4) ACTION: INLINE 200-/ა PDF (GET?action=generate_200a)
// =====================================================================
if (($_GET['action'] ?? '') === 'generate_200a') {
    $prevDisplay = ini_set('display_errors', '0');

    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) { http_response_code(400); exit('Invalid patient_id'); }

    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { http_response_code(404); exit('Patient not found'); }

    // TCPDF
    $tcpdfLoaded = false;
    foreach ([__DIR__ . '/../tcpdf/tcpdf.php', __DIR__ . '/tcpdf/tcpdf.php', dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/tcpdf.php'] as $cand) {
        if (is_file($cand)) { require_once $cand; $tcpdfLoaded = true; break; }
    }
    if (!$tcpdfLoaded) { http_response_code(500); exit('TCPDF library missing'); }

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $fmt = fn($n) => number_format((float)$n, 2, '.', '');

    $subj = pick_print_subject($patient);
    $subject_name   = $esc($subj['full_name']);
    $subject_pid    = $esc($subj['personal_id']);
    $subject_birth  = $esc(fmt_date($subj['birthdate'] ?? null));
    $subject_gender = $esc($subj['gender']);
    $subject_phone  = $esc($subj['phone']);
    $subject_addr   = $esc($subj['address']);
    $subject_work   = $esc($subj['workplace']);
    $subject_rel    = $esc($subj['relation']);
    $child_line     = ($subj['who'] === 'guardian')
        ? ('არასრულწლოვანი პაციენტი: '.$esc($subj['represented_full']).' (პ/ნ '.$esc($subj['represented_pid']).')')
        : '';
    $childMetaRow = $child_line ? '<tr><td class="lbl">შენიშვნა:</td><td class="val">'.$child_line.'</td></tr>' : '';

    // invoice params + services
    $invoiceNo   = $esc($_GET['invoice_no']   ?? ('INV-' . date('Ymd') . '-' . $patient_id));
    $invoiceDate = $esc(fmt_date($_GET['invoice_date'] ?? date('Y-m-d')));

    $svcStmt = $pdo->prepare("
      SELECT ps.quantity, ps.unit_price, ps.`sum`,
             COALESCE(s.name, CONCAT('Service #', ps.service_id)) AS name
      FROM patient_services ps
      LEFT JOIN services s ON s.id = ps.service_id
      WHERE ps.patient_id = ?
      ORDER BY ps.id
    ");
    $svcStmt->execute([$patient_id]);
    $svcRows = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    $svcTotal = 0.0;
    $rowsHtml = '';
    $idx = 1;
    foreach ($svcRows as $r) {
        $q   = (int)($r['quantity'] ?? 1);
        $up  = (float)($r['unit_price'] ?? 0);
        $sum = (float)($r['sum'] ?? ($q * $up));
        $svcTotal += $sum;

        $rowsHtml .= '<tr>'
          . '<td style="text-align:center;">' . $idx++ . '</td>'
          . '<td>' . $esc($r['name'] ?? '') . '</td>'
          . '<td style="text-align:right;">' . $fmt($up)  . '</td>'
          . '<td style="text-align:center;">' . $q        . '</td>'
          . '<td style="text-align:right;">' . $fmt($sum) . '</td>'
          . '</tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="5" style="text-align:center;color:#666;padding:8px;">სერვისები არ არის დამატებული</td></tr>';
    }

    $stmtPay = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE patient_id = ?");
    $stmtPay->execute([$patient_id]);
    $paidTotal = (float)$stmtPay->fetchColumn();

    $balance = max($svcTotal - $paidTotal, 0);

    $svcTotalFmt  = $fmt($svcTotal);
    $paidTotalFmt = $fmt($paidTotal);
    $balanceFmt   = $fmt($balance);

    // Choose: invoice or base 200-/a
    if (!empty($_GET['invoice'])) {
        $html = <<<HTML
<style>
  * { font-size: 11px; line-height: 1.4; }
  h2,h3 { text-align:center; margin:0; padding:0; }
  h2 { margin-bottom:6px; }
  h3 { margin-bottom:12px; }
  table { width:100%; border-collapse:collapse; }
  .meta td { padding:3px 4px; vertical-align:top; }
  .lbl { width:28%; font-weight:bold; }
  .val { width:72%; }
  .itms th, .itms td { border:0.8px solid #999; padding:6px 8px; }
  .totals td { padding:6px 8px; }
  .right { text-align:right; }
  .muted { color:#555; }
</style>

<h2>„სანმედი“</h2>
<h3>ინვოისი • ფორმა 200-/ა</h3>

<table class="meta" style="margin:10px 0 6px;">
  <tr><td class="lbl">ინვოისის №:</td><td class="val">{$invoiceNo}</td></tr>
  <tr><td class="lbl">ინვოისის თარიღი:</td><td class="val">{$invoiceDate}</td></tr>
  <tr><td class="lbl">კლიენტი:</td><td class="val">{$subject_name} <span class="muted">({$subject_rel})</span></td></tr>
  <tr><td class="lbl">პირადი №:</td><td class="val">{$subject_pid}</td></tr>
  <tr><td class="lbl">დაბადების თარიღი:</td><td class="val">{$subject_birth}</td></tr>
  <tr><td class="lbl">სქესი:</td><td class="val">{$subject_gender}</td></tr>
  <tr><td class="lbl">ტელეფონი:</td><td class="val">{$subject_phone}</td></tr>
  <tr><td class="lbl">მისამართი:</td><td class="val">{$subject_addr}</td></tr>
  <tr><td class="lbl">სამუშაო ადგილი:</td><td class="val">{$subject_work}</td></tr>
  {$childMetaRow}
</table>

<div style="height:6px;"></div>

<table class="itms">
  <thead>
    <tr>
      <th style="width:6%;">№</th>
      <th>სერვისი</th>
      <th style="width:14%;text-align:right;">ერთ. ფასი</th>
      <th style="width:10%;text-align:center;">რაოდ.</th>
      <th style="width:16%;text-align:right;">ჯამი</th>
    </tr>
  </thead>
  <tbody>
    {$rowsHtml}
  </tbody>
</table>

<table style="margin-top:10px;">
  <tr class="totals"><td style="width:68%;"></td><td style="width:16%; font-weight:bold;" class="right">სულ:</td><td style="width:16%;" class="right">{$svcTotalFmt}</td></tr>
  <tr class="totals"><td></td><td class="right">გადახდილი:</td><td class="right">{$paidTotalFmt}</td></tr>
  <tr class="totals"><td></td><td class="right" style="font-weight:bold;">დავალიანება:</td><td class="right" style="font-weight:bold;">{$balanceFmt}</td></tr>
</table>

<div class="muted" style="margin-top:10px;">* ფორმა 200-/ა — პაციენტის ბარათის მონაცემებია თავმოყრილი ზედა ბლოკში.</div>
HTML;
    } else {
        $htmlChildRow = $childMetaRow; // reuse
        $html = <<<HTML
<style>
  * { font-size: 11px; line-height: 1.4; }
  h2,h3 { text-align:center; margin:0; padding:0; }
  h2 { margin-bottom:6px; }
  h3 { margin-bottom:12px; }
  table { width:100%; border-collapse:collapse; }
  .lbl { width:36%; font-weight:bold; vertical-align:top; }
  .val { width:64%; }
  .sp { height:10px; }
  .line { border-bottom: 0.8px solid #999; height: 12px; }
  .muted { color:#555; }
</style>

<h2>„სანმედი“</h2>
<h3>ამბულატორიული პაციენტის სამედიცინო ბარათი</h3>

<table>
  <tr><td class="lbl">გვარი, სახელი:</td><td class="val">{$subject_name} <span class="muted">({$subject_rel})</span></td></tr>
  <tr><td class="lbl">სქესი:</td><td class="val">{$subject_gender}</td></tr>
  <tr><td class="lbl">დაბადების თარიღი</td><td class="val">{$subject_birth}</td></tr>
  <tr><td class="lbl">ტელეფონი</td><td class="val">{$subject_phone}</td></tr>
  <tr><td class="lbl">პირადი ნომერი</td><td class="val">{$subject_pid}</td></tr>
  <tr><td class="lbl">მისამართი</td><td class="val">{$subject_addr}</td></tr>
  <tr><td class="lbl">სამუშაო ადგილი, პროფესია</td><td class="val">{$subject_work}</td></tr>
  {$htmlChildRow}
</table>

<div class="sp"></div>

<table>
  <tr><td class="lbl">შესაძლებლობების შეზღუდვის სტატუსი:</td><td class="val"> ზომიერი ______, მნიშვნელოვანი ______, მკვეთრი ______</td></tr>
</table>

<div class="sp"></div>

<table>
  <tr><td class="lbl">სისხლის ჯგუფი</td><td class="val"></td></tr>
  <tr><td class="lbl">Rh-ფაქტორი</td><td class="val"></td></tr>
</table>

<div class="sp"></div>

<table>
  <tr><td class="lbl">სისხლის გადასხმები</td><td class="val"><div class="line"></div><div style="font-size:10px;">(როდის და რამდენი)</div></td></tr>
</table>

<div class="sp"></div>

<table>
  <tr><td class="lbl">ალერგია</td><td class="val"><div class="line"></div><div style="font-size:10px;">(მედიკამენტი, საკვები და სხვა. რეაქციის ტიპი)</div></td></tr>
</table>

<div class="sp"></div>

<table>
  <tr><td class="lbl">გადატანილი ქირურგიული ჩარევები</td><td class="val"><div class="line"></div></td></tr>
  <tr><td class="lbl">გადატანილი ინფექციური დაავადებები</td><td class="val"><div class="line"></div></td></tr>
  <tr><td class="lbl">ქრონიკული დაავადებები (მ.შ. გენეტიკური დაავადებები) და მავნე ჩვევები</td><td class="val"><div class="line"></div></td></tr>
</table>

<div class="sp"></div>

<table>
  <tr><td class="lbl">სადაზღვევო პოლისის ნომერი</td><td class="val"></td></tr>
  <tr><td class="lbl">სადაზღვევო კომპანია  -</td><td class="val"></td></tr>
</table>
HTML;
    }

    $pdf = new TCPDF('P','mm','A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12,12,12);
    $pdf->SetAutoPageBreak(true,12);
    $pdf->SetFont('dejavusans','',10,'',true);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    while (ob_get_level() > 0) { @ob_end_clean(); }
    $saveDir = __DIR__ . '/pdfs';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0775, true); }
    $filePath = $saveDir . '/200a_' . $patient_id . '.pdf';

    $pdf->Output($filePath, 'FI');
    exit;
}

// =====================================================================
// 5) ACTION: INLINE 200-8/ა PDF (GET?action=generate_200_8a)
// Alias also supports action=generate_2008a
// =====================================================================
$actionTmp = ($_GET['action'] ?? '');
if ($actionTmp === 'generate_200_8a' || $actionTmp === 'generate_2008a') {
    $prevDisplay = ini_set('display_errors', '0');

    $patient_id = (int)($_GET['patient_id'] ?? 0);
    $doctor_id  = (int)($_GET['doctor_id']  ?? 0);
    if ($patient_id <= 0) { http_response_code(400); exit('Invalid patient_id'); }

    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { http_response_code(404); exit('Patient not found'); }

    // Doctor (optional)
    $doctorFull = '';
    if ($doctor_id > 0) {
        $ds = $pdo->prepare("SELECT first_name, last_name FROM doctors WHERE id = ?");
        $ds->execute([$doctor_id]);
        if ($d = $ds->fetch(PDO::FETCH_ASSOC)) {
            $doctorFull = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        }
    }

    // TCPDF
    $tcpdfLoaded = false;
    foreach ([__DIR__ . '/../tcpdf/tcpdf.php', __DIR__ . '/tcpdf/tcpdf.php', dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/tcpdf.php'] as $cand) {
        if (is_file($cand)) { require_once $cand; $tcpdfLoaded = true; break; }
    }
    if (!$tcpdfLoaded) { http_response_code(500); exit('TCPDF library missing'); }

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $subj = pick_print_subject($patient);
    $subject_name = $esc($subj['full_name']);
    $subject_rel  = $esc($subj['relation']);
    $child_line   = ($subj['who'] === 'guardian')
        ? ('არასრულწლოვანი პაციენტი: '.$esc($subj['represented_full']).' (პ/ნ '.$esc($subj['represented_pid']).')')
        : '';
    $doctorFullEsc = $esc($doctorFull);
    $childRow2008a = $child_line ? '<div class="row" style="margin-top:6px;">'.$child_line.'</div>' : '';

    $html = <<<HTML
<style>
  * { font-size: 11px; line-height: 1.35; }
  h2,h3 { text-align:center; margin:0; padding:0; }
  h2 { margin-bottom:6px; }
  h3 { margin-bottom:14px; }
  .row{ margin: 8px 0; }
  .lbl{ display:inline-block; min-width: 60px; font-weight:bold; vertical-align:top; }
  .line{ display:inline-block; border-bottom:0.8px solid #999; min-width: 220px; height:14px; vertical-align:bottom; }
  .small{ font-size:10px; color:#444; }
</style>

<h2>„სანმედი“</h2>
<h3>პაციენტის წერილობითი თანხმობა სამედიცინო<br/>მომსახურების გაწევაზე</h3>

<div class="row">
  <span class="lbl">მე</span>
  <span class="line"> {$subject_name} </span>
  <div class="small">({$subject_rel} — სახელი, გვარი)</div>
</div>
{$childRow2008a}

<div class="row">
მივიღე ინფორმაცია სამედიცინო მომსახურების გაწევის შესახებ. მკურნალმა ექიმმა გამაცნო სამედიცინო მომსახურების მიზანი, მისი მიმდინარეობა, თავისებურებანი და შესაძლო გართულებები. ასევე ჩემთვის ცნობილია სამედიცინო მომსახურებაზე უარის შემთხვევაში დამდგარი შედეგის შესახებ.
</div>

<div class="row" style="margin-top:16px;">
  მე, ექიმი
  <span class="line" style="min-width:200px;"> {$doctorFullEsc} </span>
  <div class="small">(სახელი, გვარი)</div>
</div>

<div class="row">
ვადასტურებ, რომ პაციენტს პასუხი გაეცა ყველა შეკითხვაზე, რაც შეეხება მის ჯანმრთელობას, დაავადებას, მკურნალობას. ასევე მიიღო პასუხი მკურნალობის ალტერნატიულ მეთოდებზე და მის ღირებულებაზე.
</div>

<div class="row" style="margin-top:14px;">
  პაციენტის (ან კან. წარმომადგენლის) ხელმოწერა
  <span class="line" style="min-width:260px;"></span>
</div>

<div class="row" style="margin-top:10px;">
  თარიღი <span class="line" style="min-width:120px;"></span>
</div>
HTML;

    $pdf = new TCPDF('P','mm','A4', true, 'UTF-8', false);
    $pdf->SetCreator('SanMedic HMS');
    $pdf->SetAuthor('SanMedic HMS');
    $pdf->SetTitle('200-8/ა – თანხმობა');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12,12,12);
    $pdf->SetAutoPageBreak(true,12);
    $pdf->SetFont('dejavusans','',10,'',true);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    while (ob_get_level() > 0) { @ob_end_clean(); }
    $saveDir = __DIR__ . '/pdfs';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0775, true); }
    $filePath = $saveDir . '/200-8a_' . $patient_id . '.pdf';
    $pdf->Output($filePath, 'FI');
    exit;
}

// === INLINE თანხმობის ხელწერილი (TCPDF) ===
if (($_GET['action'] ?? '') === 'generate_consent') {
    $prevDisplay = ini_set('display_errors', '0');

    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) { http_response_code(400); exit('Invalid patient_id'); }

    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { http_response_code(404); exit('Patient not found'); }

    $tcpdfLoaded = false;
    foreach ([__DIR__.'/../tcpdf/tcpdf.php', __DIR__.'/tcpdf/tcpdf.php', dirname(__DIR__).'/vendor/tecnickcom/tcpdf/tcpdf.php'] as $cand) {
        if (is_file($cand)) { require_once $cand; $tcpdfLoaded = true; break; }
    }
    if (!$tcpdfLoaded) { http_response_code(500); exit('TCPDF library missing'); }

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $full_name   = trim($esc($patient['last_name'] ?? '').' '.$esc($patient['first_name'] ?? ''));
    $personal_id = $esc($patient['personal_id'] ?? '');
    $birthdate   = $esc(fmt_date($patient['birthdate'] ?? null));
    $today = fmt_date(date('Y-m-d')) . ' ' . date('H:i');

    $html = <<<HTML
<style>
  * { font-size: 11px; line-height: 1.5; }
  h2 { text-align:center; margin:0 0 10px 0; }
  .row { margin:4px 0; }
  .lbl { font-weight:bold; }
  .gap { height:6px; }
</style>

<h2>„სანმედი“</h2>
<h3 style="text-align:center;margin:0 0 14px 0;">თანხმობის ხელწერილი</h3>

<div class="row">ქ. თბილისი</div>
<div class="row">{$today}</div>
<div class="gap"></div>

<div class="row">
თანხმობას ვაცხადებ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ405695323) მიერ განხორციელდეს ჩემი ვიდეო მონიტორინგი, აგრეთვე დამუშავდეს ჩემი პერსონალური მონაცემები (სახელი, გვარი, პირადი ნომერი, დაბადების თარიღი, ტელეფონის ნომერი, ელექტრონული ფოსტა, ინფორმაცია სქესის შესახებ, ინფორმაცია მისამართის შესახებ და ა.შ.). ასევე თანახმა ვარ, რომ შპს ,,კლინიკა სანმედი’’-ს (ს/ნ 405695323) მიერ განხორციელდეს ჩემი არასრულწლოვანი შვილი
</div>

<div class="gap"></div>
<div class="row">
(სახელი/გვარი; <span class="lbl">{$full_name}</span> &nbsp;&nbsp; პირადი ნომერი <span class="lbl">{$personal_id}</span> &nbsp;&nbsp; დაბადების თარიღი <span class="lbl">{$birthdate}</span>)
</div>

<div class="gap"></div>
<div class="row">
პერსონალური მონაცემები (სახელი, გვარი, პირადი ნომერი, დაბადების თარიღი, ტელეფონის ნომერი, ელექტრონული ფოსტა, ინფორმაცია სქესის შესახებ, ინფორმაცია მისამართის შესახებ, ინფორმაცია ჯანმრთელობის მდგომარეობის შესახებ და ა.შ.). თანახმა ვარ, რომ აღნიშნული ვიდეო მონიტორინგი განხორციელდეს დამსაქმებლის - შპს ,,სანმედი’’-ს (ს/ნ405695323) ოფისში, შემდეგ მისამართზე: ქ. თბილისი, მებრძოლთა ქუჩა N55. შპს ,,კლინიკა სანმედი’’-სგან (ს/ნ405695323) აღნიშნულ ხელწერილზე ხელმოწერამდე განმემარტა, რომ ვიდეო მონიტორინგის განხორციელებაზე/მონაცემთა დამუშავებაზე თანხმობის გამოხატვა არის ნებაყოფლობითი და ნებისმიერ დროს მაქვს უფლება გამოვიხმო აღნიშნული თანხმობა. ასევე განმემარტა, რომ აღნიშნული მონაცემები შენახული იქნება არა უმეტეს ათი წლის ვადით, ვიდეო მონიტორინგი განხორციელდება აღნიშნულ დაწესებულებაში ჩემი ყოფნის პერიოდის განმავლობაში. ვიდეო ჩანაწერებზე წვდომისა და შენახვის უფლება ექნება შპს ,,კლინიკა სანმედი’’-ს (ს/ნ405695323) დირექტორს და მათი განადგურება განხორციელდება მის მიერ, შენახვის ვადის გასვლისთანავე. ასევე მეცნობა, რომ დამუშავებული ვიდეო ჩანაწერები, ასევე სხვა პერსონალური მონაცემები შეინახება სპეციალურ და დაცულ მოწყობილობაში და დაცული იქნება ნებისმიერი არამართლზომიერი ხელყოფისა და გამოყენებისგან. ზემოთ აღნიშნულის თაობაზე მოხდა ჩემი ინფორმირება, რაზეც თანახმა ვარ და პრეტენზია არ გამაჩნია.
</div>

<div class="gap"></div>
<div class="row"><span class="lbl">სახელი/გვარი</span> ________________________________</div>
<div class="row"><span class="lbl">პირადი ნომერი</span> ________________________________</div>
<div class="row"><span class="lbl">ხელმოწერა</span> _______________________________________</div>
HTML;

    $pdf = new TCPDF('P','mm','A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12,12,12);
    $pdf->SetAutoPageBreak(true,12);
    $pdf->SetFont('dejavusans','',10,'',true);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    while (ob_get_level() > 0) { @ob_end_clean(); }
    $saveDir = __DIR__ . '/pdfs';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0775, true); }
    $filePath = $saveDir . '/consent_' . $patient_id . '.pdf';
    $pdf->Output($filePath, 'FI');
    exit;
}

// === INLINE ხელშეკრულება (TCPDF) ===
if (($_GET['action'] ?? '') === 'generate_contract') {
    $prevDisplay = ini_set('display_errors', '0');

    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) { http_response_code(400); exit('Invalid patient_id'); }

    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { http_response_code(404); exit('Patient not found'); }

    $tcpdfLoaded = false;
    foreach ([__DIR__.'/../tcpdf/tcpdf.php', __DIR__.'/tcpdf/tcpdf.php', dirname(__DIR__).'/vendor/tecnickcom/tcpdf/tcpdf.php'] as $cand) {
        if (is_file($cand)) { require_once $cand; $tcpdfLoaded = true; break; }
    }
    if (!$tcpdfLoaded) { http_response_code(500); exit('TCPDF library missing'); }

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $subj = pick_print_subject($patient);
    $subject_name = $esc($subj['full_name']);
    $subject_pid  = $esc($subj['personal_id']);
    $subject_birth= $esc(fmt_date($subj['birthdate'] ?? null));
    $subject_addr = $esc($subj['address']);
    $subject_rel  = $esc($subj['relation']);

    // phrase for section 1
    $subjectLine = $subj['who']==='guardian'
        ? 'არასრულწლოვანი პაციენტისათვის – '.$esc($subj['represented_full']).' (პ/ნ '.$esc($subj['represented_pid']).'), რომლის წარმომადგენელია '.$subject_name.' (პ.ნ '.$subject_pid.', დაბ.თ '.$subject_birth.'),'
        : $subject_name.'-ს (პ.ნ '.$subject_pid.', დაბ.თ '.$subject_birth.'),';

    $email       = $esc($patient['email'] ?? '');
    $city        = 'ქ. თბილისი';
    $nowStr      = fmt_date(date('Y-m-d')) . ' ' . date('H:i');

    $html = <<<HTML
<style>
  * { font-size: 11px; line-height: 1.45; }
  h2,h3 { text-align:center; margin:0; padding:0; }
  h2 { margin-bottom:6px; }
  h3 { margin: 0 0 12px 0; }
  .row { width:100%; }
  .muted { color:#444; }
  .mb8 { margin-bottom:8px; }
  .mb10 { margin-bottom:10px; }
  .mb14 { margin-bottom:14px; }
  .sec h4 { margin:14px 0 6px 0; font-size: 11.5px; }
  .sig { margin-top: 14px; }
  .sig .col { display:inline-block; width:49%; vertical-align:top; }
  .hr { border-bottom: 0.8px solid #999; height: 12px; }
</style>

<h2>„სანმედი“</h2>
<h3>ხელშეკრულება<br/>სამედიცინო მომსახურების შესახებ</h3>

<div class="row mb10"><b>{$city}</b> &nbsp; <span class="muted">{$nowStr}</span></div>

<div class="row mb8">
  <b>ერთი მხრივ</b> {$subject_name} <span class="muted">({$subject_rel})</span> &nbsp; მის: {$subject_addr} &nbsp; ელ.ფოსტა: {$email}
  <br/><span class="muted">(შემდეგში - ,,კლიენტი’’ ან ,,პაციენტი’’)</span>
</div>

<div class="row mb14">
  და <b>მეორე მხრივ</b> შპს “კლინიკა სანმედი”, ს/კ: 405695323, წარმოდგენილი დირექტორის
  მარიამ ღუღუნიშვილის (პ/ნ 01001098074) სახით (შემდგომში - ”კლინიკა”), მოქმედი საქართველოს
  კანონმდებლობის საფუძველზე, ვაფორმებთ წინამდებარე ხელშეკრულებას შემდეგზე:
</div>

<div class="sec">
  <h4>1. ხელშეკრულების საგანი</h4>
  კლინიკა სანმედი ახორციელებს {$subjectLine} სამედიცინო მომსახურებას შეთანხმებული მოცულობით და პირობებით.
</div>

<div class="sec">
  <h4>2. ძირითადი უფლება-მოვალეობები</h4>
  2.1. პაციენტი/კლიენტი ვალდებულია: <br/>
  2.1.1. კლინიკას მიაწოდოს ზუსტი ინფორმაცია ჯანმრთელობის მდგომარეობისა და გადატანილი დაავადებების შესახებ; <br/>
  2.1.2. თითოეულ დაგეგმილ პროცედურაზე გამოცხადდეს შეთანხმებულ დროს, ხოლო შეუძლებლობისას გონივრულ ვადაში აცნობოს კლინიკას; <br/>
  2.1.3. შეთანხმებულ ვადაში გადაიხადოს მომსახურების საფასური. <br/><br/>
  2.2. პაციენტი/კლიენტი უფლებამოსილია: <br/>
  2.2.1. კლინიკისგან მოითხოვოს ჯეროვანი სამედიცინო მომსახურების გაწევა; <br/>
  2.2.2. გამოთქვას მოსაზრება მიღებულ მომსახურებასთან დაკავშირებით. <br/><br/>
  2.3. კლინიკა ვალდებულია: <br/>
  2.3.1. კლიენტს/პაციენტს სამედიცინო მომსახურება გაუწიოს ჯეროვნად; <br/>
  2.3.2. მოთხოვნის შემთხვევაში გონივრულ ვადაში მიაწოდოს ინფორმაცია მისთვის გაწეული მომსახურების შესახებ; <br/>
  2.3.3. მოსთხოვოს კლიენტს/პაციენტს ჯანმრთელობის მდგომარეობისა და გადატანილი დაავადებების შესახებ ზუსტი ინფორმაცია. <br/><br/>
  2.4. კლინიკა უფლებამოსildir: <br/>
  2.4.1. მიიღოს გაწეული მომსახურების საფასური; <br/>
  2.4.2. დაამუშაოს პაციენტის მაიდენტიფიცირებელი მონაცემები მოქმედი კანონმდებლობით; <br/>
  2.4.3. საფასურის მიუღებლობის შემთხვევაში მიმართოს კანონით დადგენილ ღონისძიებებს.
</div>

<div class="sec">
  <h4>3. კონფიდენციალურობა</h4>
  3.1. ნებისმიერი ინფორმაცია, რომელსაც კლინიკა შეიტყობს მომსახურების გაწევისას, ითვლება
  კონფიდენციალურად და პაციენტის/კლიენტის თანხმობის გარეშე არ გადაეცემა მესამე პირებს,
  გარდა კანონით გათვალისწინებული შემთხვევებისა.
</div>

<div class="sec">
  <h4>5. მომსახურების ანაზღაურება</h4>
  5.1. კლიენტი/პაციენტი ვალდებულია მომსახურების საფასური გადაიხადოს მიღებისთანავე. <br/>
  5.2. პერიოდული მომსახურებისას გადახდა ხდება თითოეული მიღებისთანავე. <br/>
  5.3. დაზღვევით სარგებლობისას – გადახდა ხდება დაზღვევის პირობების შესაბამისად.
</div>

<div class="sec">
  <h4>7. დავათა გადაწყვეტა</h4>
  7.1. დავები გადაწყდება მოლაპარაკების გზით, ხოლო შეთანხმების невозможლობისას – სასამართლო წესით.
</div>

<div class="sec">
  <h4>8. ხელშეკრულების შეწყვეტა</h4>
  8.1. შეწყვეტა შესაძლებელია მხარეთა შეთანხმებით, ერთ-ერთი მხარის ინიციატივით, ვადის გასვლით ან
  კანონით გათვალისწინებულ სხვა საფუძვლებზე.
</div>

<div class="sec">
  <h4>9. დასკვნითი დებულებანი</h4>
  9.1. ხელშეკრულება ძალაშია ხელმოწერის მომენტიდან მის სრულ შესრულებამდე. <br/>
  9.2. ცვლილება/დამატება – მხოლოდ წერილობით და მხარეთა ხელმოწერით. <br/>
  9.3. შედგენილია ორ ეგზემპლარად ქართულ ენაზე, თანაბარი იურიდიული ძალით.
</div>

<div class="sig">
  <div class="col">
    <b>კლინიკა</b><br/>
    შპს ,,კლინიკა სანმედი’’ (ს/ნ 405695323)<br/>
    დირექტორი – მარიამ ღუღუნიშვილი
  </div>
  <div class="col">
    <b>კლიენტი/პაციენტი</b><br/>
    სახელი/გვარი: {$subject_name}<br/>
    პირადი ნომერი: {$subject_pid}
  </div>
</div>
HTML;

    $pdf = new TCPDF('P','mm','A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('SanMedic HMS');
    $pdf->SetAuthor('SanMedic HMS');
    $pdf->SetTitle('ხელშეკრულება – სამედიცინო მომსახურების შესახებ');
    $pdf->SetMargins(12,12,12);
    $pdf->SetAutoPageBreak(true,12);
    $pdf->SetFont('dejavusans','',11,'',true);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    while (ob_get_level() > 0) { @ob_end_clean(); }
    $saveDir = __DIR__ . '/pdfs';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0775, true); }
    $filePath = $saveDir . '/contract_' . $patient_id . '.pdf';
    $pdf->Output($filePath, 'FI');
    exit;
}

// ===== START AJAX HANDLER (services + guarantee) =====
if (isAjaxRequest() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $data   = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $action = $data['action'] ?? '';
    $error  = '';

    if ($action === 'save_patient_services') {
        $doctor_id  = intval($data['doctor_id']  ?? 0);
        $patient_id = intval($data['patient_id'] ?? 0);
        $services   = $data['services'] ?? [];

        if (!$patient_id || !is_array($services)) {
            $error = "Invalid data.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                  INSERT INTO patient_services
                    (patient_id, service_id, quantity, unit_price, `sum`, doctor_id)
                  VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($services as $svc) {
                    $stmt->execute([
                        $patient_id,
                        intval($svc['service_id']),
                        intval($svc['quantity']),
                        floatval($svc['unit_price']),
                        floatval($svc['sum']),
                        $doctor_id
                    ]);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $error ? 'err' : 'ok', 'msg' => $error]);
        exit;
    }

    if ($action === 'get_donor_balance') {
        $pid = intval($data['patient_id'] ?? 0);
        $b = donor_get_balance($pdo, $pid);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'ok'] + $b, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'apply_donor') {
        $pid     = intval($data['patient_id'] ?? 0);
        $amount  = (float)($data['amount'] ?? 0);
        $orderNo = trim($data['order_no'] ?? '');

        if ($amount <= 0 && $pid > 0) {
            $qSvc = $pdo->prepare("SELECT COALESCE(SUM(`sum`),0) FROM patient_services WHERE patient_id = ?");
            $qSvc->execute([$pid]);
            $svc = (float)$qSvc->fetchColumn();

            $qPay = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE patient_id = ?");
            $qPay->execute([$pid]);
            $paidAll = (float)$qPay->fetchColumn();

            $debt = max($svc - $paidAll, 0);
            $left = donor_get_balance($pdo, $pid)['left'];
            $amount = min($debt, $left);
        }

        $res = donor_apply($pdo, $pid, $amount, $orderNo ?: null);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res['ok'] ? ['status'=>'ok','applied'=>$res['applied']]
                                    : ['status'=>'err','msg'=>$res['msg']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add_or_activate') {
        $pid = intval($data['patient_id'] ?? 0);
        if ($pid <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'err','message'=>'არასწორი patient_id']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
              INSERT INTO patient_cards (patient_id) VALUES (?)
              ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $stmt->execute([$pid]);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'ok']);
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'err','message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_guarantee') {
        $patient_id = intval($data['patient_id'] ?? 0);
        $donor      = trim($data['donor'] ?? '');
        $amount     = (float)($data['amount'] ?? 0);
        $gdate      = trim($data['guarantee_date'] ?? '');
        $vdate      = trim($data['validity_date'] ?? '');
        $gnum       = trim($data['guarantee_number'] ?? '');
        $gcomment   = trim($data['guarantee_comment'] ?? '');

        if ($patient_id <= 0 || $amount <= 0) {
            $error = 'არასწორი მონაცემი';
        } else {
            try {
                $stmt = $pdo->prepare("
                  INSERT INTO patient_guarantees
                    (patient_id, is_virtual_advance, donor, amount, guarantee_date, validity_date, guarantee_number, guarantee_comment)
                  VALUES (?,?,?,?,?,?,?,?)
                ");
                $ok = $stmt->execute([
                    $patient_id, 1, $donor, $amount,
                    ($gdate ?: null), ($vdate ?: null), ($gnum ?: null), ($gcomment ?: null)
                ]);
                if (!$ok) $error = 'ვერ ჩაიწერა';
            } catch (PDOException $e) {
                $error = $e->getMessage();
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $error ? 'err' : 'ok', 'msg' => $error]);
        exit;
    }
}
// ===== END AJAX HANDLER =====

// === 1) SAVE ADMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_admission') {
    slog_action('save_admission_start', ['patient_id' => $_POST['patient_id'] ?? 'none', 'isAjax' => isAjaxRequest()]);
    $id = intval($_POST['patient_id'] ?? 0);

    if (!$id) {
        $error = "პაციენტის ID არასწორია!";
    } else {
        // sanitizers
        $intOrNull = function($v){ $v = isset($v) ? trim((string)$v) : ''; return ($v === '' ? null : (int)$v); };
        $floatOrNull = function($v){ $v = isset($v) ? trim((string)$v) : ''; if ($v === '') return null; $v = str_replace([' ', ','], ['', '.'], $v); return is_numeric($v) ? (float)$v : null; };
        $strOrNull = function($v){ $v = isset($v) ? trim((string)$v) : ''; return ($v === '' ? null : $v); };

        $fields = [
            'registration_date' => $_POST['registration_date'] ?: date('Y-m-d'),
            'entry_type'        => $strOrNull($_POST['entry_type'] ?? ''),
            'department'        => $strOrNull($_POST['department'] ?? ''),
            'stationary_type'   => $strOrNull($_POST['stationary_type'] ?? ''),
            'comment'           => $strOrNull($_POST['comment'] ?? ''),

            'donor'             => $strOrNull($_POST['donor'] ?? ''),
            'amount'            => $floatOrNull($_POST['amount'] ?? null),
            'guarantee_date'    => ($_POST['guarantee_date'] ?? '') ? $_POST['guarantee_date'] : null,
            'validity_date'     => ($_POST['validity_date'] ?? '') ? $_POST['validity_date']  : null,
            'guarantee_number'  => $strOrNull($_POST['guarantee_number'] ?? ''),
            'guarantee_comment' => $strOrNull($_POST['guarantee_comment'] ?? ''),

            'region'            => $intOrNull($_POST['region'] ?? null),
            'raion_hid'         => $intOrNull($_POST['raion_hid'] ?? null),

            'raion'             => $strOrNull($_POST['raion'] ?? ''),
            'city'              => $strOrNull($_POST['city'] ?? ''),
            'other_address'     => $strOrNull($_POST['other_address'] ?? ''),
            'education'         => $strOrNull($_POST['education'] ?? ''),
            'marital_status'    => $strOrNull($_POST['marital_status'] ?? ''),
            'employment'        => $strOrNull($_POST['employment'] ?? ''),
        ];

        try {
            $stmt = $pdo->prepare("
                UPDATE patients SET
                  registration_date=:registration_date,
                  entry_type=:entry_type,
                  department=:department,
                  stationary_type=:stationary_type,
                  comment=:comment,

                  donor=:donor,
                  amount=:amount,
                  guarantee_date=:guarantee_date,
                  validity_date=:validity_date,
                  guarantee_number=:guarantee_number,
                  guarantee_comment=:guarantee_comment,

                  region=:region,
                  raion_hid=:raion_hid,
                  raion=:raion,
                  city=:city,
                  other_address=:other_address,
                  education=:education,
                  marital_status=:marital_status,
                  employment=:employment
                WHERE id=:id
            ");
            $fields['id'] = $id;
            $stmt->execute($fields);
        } catch (PDOException $e) {
            $error = "შეცდომა რეგისტრაციის განახლებისას: " . $e->getMessage();
        }
    }

    if ($error) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'err', 'msg' => $error]);
            exit;
        } else {
            echo "<div class='error'>" . htmlspecialchars($error) . "</div>";
            exit;
        }
    }

    $stmt2 = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt2->execute([$id]);
    $patient = $stmt2->fetch(PDO::FETCH_ASSOC);

    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'patient' => $patient]);
        exit;
    } else {
        header('Location: dashboard.php');
        exit;
    }
}

// === 2) DELETE PATIENT (soft when referenced)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_patient') {
    $id = intval($_POST['delete_id'] ?? 0);
    $error = '';
    $mode  = 'hard';

    if ($id > 0) {
        try {
            $has = function(PDO $pdo, string $sql, int $id): bool {
                $st = $pdo->prepare($sql);
                $st->execute([$id]);
                return (int)$st->fetchColumn() > 0;
            };

            $refInvoices   = $has($pdo, "SELECT COUNT(*) FROM invoices           WHERE patient_id = ?", $id);
            $refServices   = $has($pdo, "SELECT COUNT(*) FROM patient_services   WHERE patient_id = ?", $id);
            $refPayments   = $has($pdo, "SELECT COUNT(*) FROM payments           WHERE patient_id = ?", $id);
            $refGuarantees = $has($pdo, "SELECT COUNT(*) FROM patient_guarantees WHERE patient_id = ?", $id);
            $refCards      = $has($pdo, "SELECT COUNT(*) FROM patient_cards      WHERE patient_id = ?", $id);

            $hasRefs = ($refInvoices || $refServices || $refPayments || $refGuarantees || $refCards);

            if ($hasRefs) {
                $stmt = $pdo->prepare("UPDATE patients SET status = 'არქივირებული' WHERE id = ?");
                $stmt->execute([$id]);
                $mode = 'soft';
            } else {
                $stmt = $pdo->prepare('DELETE FROM patients WHERE id = ?');
                $stmt->execute([$id]);
                $mode = 'hard';
            }

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = "ვერ წაიშალა: პაციენტი დაკავშირებულია სხვა ჩანაწერებთან (მაგ., ინვოისები/გადახდები).";
            } else {
                $error = "Error deleting patient: " . $e->getMessage();
            }
        }
    } else {
        $error = "Invalid delete ID.";
    }

    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $error ? ['status'=>'err','msg'=>$error]
                   : ['status'=>'ok','deleted_id'=>$id,'mode'=>$mode],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    } else {
        if ($error) {
            flash('error', $error);
        } else {
            flash($mode === 'soft' ? 'info' : 'success',
                  $mode === 'soft'
                    ? 'პაციენტი გადატანილია არქივში, რადგან დაკავშირებულია სხვა ჩანაწერებთან.'
                    : 'პაციენტი წარმატებით წაიშალა.');
        }
        header('Location: dashboard.php');
        exit;
    }
}

// === 3) CUSTOM INFO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'custom_info') {
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $extra_info = trim($_POST['extra_info'] ?? '');
    if ($patient_id) {
        try {
            $stmt = $pdo->prepare("UPDATE patients SET extra_info = ? WHERE id = ?");
            $stmt->execute([$extra_info, $patient_id]);
        } catch (PDOException $e) {
            $error = "Error updating custom info: " . $e->getMessage();
        }
    } else {
        $error = "Invalid patient ID for custom info.";
    }

    if (!empty($error)) {
        echo "<div class='error'>" . htmlspecialchars($error) . "</div>";
    }
    header("Location: dashboard.php");
    exit;
}

// === 4) EDIT PATIENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_patient') {
    $id                = intval($_POST['edit_id'] ?? 0);
    $personal_id       = trim($_POST['personal_id'] ?? '');
    $first_name        = trim($_POST['first_name'] ?? '');
    $last_name         = trim($_POST['last_name'] ?? '');
    $father_name       = trim($_POST['father_name'] ?? '');
    $birthdate         = (($_POST['birthdate'] ?? '') !== '') ? $_POST['birthdate'] : null;

    $gender            = $_POST['gender'] ?? '';
    $phone             = trim($_POST['phone'] ?? '');
    $citizenship       = trim($_POST['citizenship'] ?? 'საქართველო');
    $workplace         = trim($_POST['workplace'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $telephone         = trim($_POST['telephone'] ?? '');
    $address           = trim($_POST['address'] ?? '');
    $legal_address     = trim($_POST['legal_address'] ?? '');
    $relative_type     = trim($_POST['relative_type'] ?? '');
    $relative_first    = trim($_POST['relative_first_name'] ?? '');
    $relative_last     = trim($_POST['relative_last_name'] ?? '');
    $relative_pid      = trim($_POST['relative_personal_id'] ?? '');
    $relative_birth    = (($_POST['relative_birthdate'] ?? '') !== '') ? $_POST['relative_birthdate'] : null;

    $relative_gender   = $_POST['relative_gender'] ?? '';
    $relative_phone    = trim($_POST['relative_phone'] ?? '');
    $relative_address  = trim($_POST['relative_address'] ?? '');
    $registration_date = $_POST['registration_date'] ?? date('Y-m-d');
    $entry_type        = trim($_POST['entry_type'] ?? '');
    $department        = trim($_POST['department'] ?? '');
    $stationary_type   = trim($_POST['stationary_type'] ?? '');
    $comment           = trim($_POST['comment'] ?? '');
    $status            = trim($_POST['status'] ?? 'აქტიური');

    // Minor validation
    $age = calc_age_years($birthdate ?: null);
    if ($age !== null && $age < 18) {
        $hasGuardian = ($relative_first !== '' || $relative_last !== '' || $relative_pid !== '');
        if (!$hasGuardian) {
            $error = "არასრულწლოვნის შემთხვევაში შეავსეთ წარმომადგენელი (relative_* ველები).";
        }
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE patients SET
                    personal_id          = ?, first_name           = ?, last_name         = ?, father_name       = ?,
                    birthdate            = ?, gender               = ?, phone             = ?,
                    citizenship          = ?, legal_address       = ?, workplace         = ?,
                    email                = ?, telephone            = ?, address           = ?,
                    relative_type        = ?, relative_first_name  = ?, relative_last_name= ?,
                    relative_personal_id = ?, relative_birthdate   = ?, relative_gender   = ?,
                    relative_phone       = ?, relative_address     = ?,
                    registration_date    = ?, entry_type           = ?, department        = ?,
                    stationary_type      = ?, comment              = ?, status            = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $personal_id, $first_name, $last_name, $father_name,
                $birthdate, $gender, $phone,
                $citizenship, $legal_address, $workplace,
                $email, $telephone, $address,
                $relative_type, $relative_first, $relative_last,
                $relative_pid, $relative_birth, $relative_gender,
                $relative_phone, $relative_address,
                $registration_date, $entry_type, $department,
                $stationary_type, $comment, $status,
                $id
            ]);
        } catch (PDOException $e) {
            $error = "Error updating patient: " . $e->getMessage();
        }
    }

    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        if (!empty($error)) {
            echo json_encode(['status' => 'err', 'msg' => $error]);
        } else {
            $stmt2 = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt2->execute([$id]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'ok', 'patient' => $row]);
        }
        exit;
    } else {
        if (!empty($error)) {
            echo "<div class='error'>" . htmlspecialchars($error) . "</div>";
            exit;
        }
        header('Location: dashboard.php');
        exit;
    }
}

// === 5) ADD PATIENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_patient') {
    $personal_id       = trim($_POST['personal_id'] ?? '');
    $first_name        = trim($_POST['first_name'] ?? '');
    $last_name         = trim($_POST['last_name'] ?? '');
    $father_name       = trim($_POST['father_name'] ?? '');
    $birthdate         = $_POST['birthdate'] ?? '';
    $gender            = $_POST['gender'] ?? '';
    $phone             = trim($_POST['phone'] ?? '');
    $citizenship       = trim($_POST['citizenship'] ?? 'საქართველო');
    $legal_address     = trim($_POST['legal_address'] ?? '');
    $workplace         = trim($_POST['workplace'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $telephone         = trim($_POST['telephone'] ?? '');
    $address           = trim($_POST['address'] ?? '');
    $relative_type     = trim($_POST['relative_type'] ?? '');
    $relative_first    = trim($_POST['relative_first_name'] ?? '');
    $relative_last     = trim($_POST['relative_last_name'] ?? '');
    $relative_pid      = trim($_POST['relative_personal_id'] ?? '');
    $relative_birth    = ($_POST['relative_birthdate'] ?? '') ?: null;
    $relative_gender   = $_POST['relative_gender'] ?? '';
    $relative_phone    = trim($_POST['relative_phone'] ?? '');
    $relative_address  = trim($_POST['relative_address'] ?? '');
    $registration_date = $_POST['registration_date'] ?? date('Y-m-d');
    $entry_type        = trim($_POST['entry_type'] ?? '');
    $department        = trim($_POST['department'] ?? '');
    $stationary_type   = trim($_POST['stationary_type'] ?? '');
    $comment           = trim($_POST['comment'] ?? '');

    $isAjaxReq = (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    );

    if (!$personal_id || !$first_name || !$last_name || !$birthdate || !$gender) {
        $error = "სავალდებულო ველები აუცილებელია!";
    } else {
        $age = calc_age_years($birthdate ?: null);
        if ($age !== null && $age < 18) {
            $hasGuardian = ($relative_first !== '' || $relative_last !== '' || $relative_pid !== '');
            if (!$hasGuardian) {
                $error = "არასრულწლოვნის შემთხვევაში შეავსეთ წარმომადგენელი (relative_* ველები).";
            }
        }

        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO patients (
                        personal_id, first_name, last_name, father_name, birthdate, gender, phone,
                        citizenship, legal_address, workplace, email, telephone, address, status,
                        relative_type, relative_first_name, relative_last_name, relative_personal_id,
                        relative_birthdate, relative_gender, relative_phone, relative_address,
                        registration_date, entry_type, department, stationary_type, comment
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, 'აქტიური',
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?
                    )
                ");
                $stmt->execute([
                    $personal_id, $first_name, $last_name, $father_name, $birthdate, $gender, $phone,
                    $citizenship, $legal_address, $workplace, $email, $telephone, $address,
                    $relative_type, $relative_first, $relative_last, $relative_pid,
                    $relative_birth, $relative_gender, $relative_phone, $relative_address,
                    $registration_date, $entry_type, $department, $stationary_type, $comment
                ]);
                $newId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = "ამ პირადი ნომრით პაციენტი უკვე არსებობს.";
                } else {
                    $error = "Error adding patient: " . $e->getMessage();
                }
                error_log("add_patient failed: " . $e->getMessage());
            }
        }
    }

    if ($isAjaxReq) {
        header('Content-Type: application/json; charset=utf-8');
        if (!empty($error)) {
            echo json_encode(['status' => 'err', 'msg' => $error]);
        } else {
            $stmt2 = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt2->execute([$newId]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'ok', 'patient' => $row]);
        }
        exit;
    }

    if (!empty($error)) {
        echo "<div class='error' style='margin:16px 40px;font-weight:600;color:#e74c3c;'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>";
        exit;
    }

    header('Location: dashboard.php?added=1');
    exit;
}

// 6) FETCH LIST + dropdown data
$where = [];
$params = [];
if (!empty($_GET['search_first_name'])) {
    $where[] = 'first_name LIKE ?';
    $params[] = '%' . trim($_GET['search_first_name']) . '%';
}
if (!empty($_GET['search_last_name'])) {
    $where[] = 'last_name LIKE ?';
    $params[] = '%' . trim($_GET['search_last_name']) . '%';
}
if (!empty($_GET['search_personal_id'])) {
    $where[] = 'personal_id LIKE ?';
    $params[] = '%' . trim($_GET['search_personal_id']) . '%';
}
if (!empty($_GET['search_order_id'])) {
    $where[] = 'id = ?';
    $params[] = intval($_GET['search_order_id']);
}

$sql = 'SELECT * FROM patients' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 10';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$services = $pdo->query("SELECT id, name, price FROM services ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$doctors  = $pdo->query("SELECT id, first_name, last_name FROM doctors ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);

$telRows = $pdo->query("
    SELECT
        last_name,
        first_name,
        phone,
        mobile,
        telephone,
        TRIM(CONCAT_WS(' / ',
            NULLIF(phone,''),
            NULLIF(mobile,''),
            NULLIF(telephone,'')
        )) AS phones
    FROM doctors
    ORDER BY last_name, first_name
")->fetchAll(PDO::FETCH_ASSOC);

// (optional) read posted service values
$service_id    = intval($_POST['service_id'] ?? 0);
$service_price = floatval($_POST['service_price'] ?? 0);
?>

<!DOCTYPE html>
<html lang="ka">

<head>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ka.js"></script>

  <meta charset="UTF-8">
  <title>SanMedic – რეგისტრაცია</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Add font links here -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
      /* Global Font Family */
      body, input, textarea, select, button {
          font-family: "Noto Sans Georgian", sans-serif;
      }

      /* View Modal Styles */
#viewModal {
    display: none;               /* დამალული ჩასატვირთად */
    position: fixed;             /* ფიქსირებული პოზიცია მთელ ეკრანზე */
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.35);
    justify-content: center;     /* children ცენტრში ჰორიზონტალურად */
    align-items: center;         /* children ცენტრში ვერტიკალურად */
    z-index: 9999;
    backdrop-filter: blur(5px);
}
#viewModal .modal-content {
    background: #fff;
    border-radius: 12px;
    max-width: 750px;
    width: 90%;
    padding: 30px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow-y: auto;
    max-height: 90vh;
}
/* დემოგრაფიის პანელი */
.demography-panel {
  margin-top: 30px;
  border: 1px solid #ddd;
  border-radius: 8px;
  background: #fff;
}
.demography-panel .panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f7f7f5;
  padding: 12px 20px;
  border-bottom: 1px solid #ededed;
}
.demography-panel .panel-title {
  font-size: 1.1em;
  color: #21c1a6;
  margin: 0;
}
.demography-panel .btn-copy {
  border: 1px solid #e6dede;
  background-color: #f8ffff;
  padding: 4px 10px;
  cursor: pointer;
  font-size: 0.9em;
  border-radius: 4px;
}
.demography-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  padding: 20px;
}
.demography-grid .form-group {
  display: flex;
  flex-direction: column;
}
.demography-grid label {
  font-size: 0.9em;
  color: #555;
  margin-bottom: 4px;
}
     body {
          font-family: "Noto Sans Georgian", sans-serif;
          background: #f9f8f2;
          color: #222;
          min-height: 100vh;
          margin: 0;
          padding: 0;
      }
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
      .user-menu-wrap {
          display: flex;
          align-items: center;
          gap: 16px;
          position: relative;
      }
      .user-btn {
          display: flex;
          align-items: center;
          gap: 8px;
          color: #21c1a6;
          cursor: pointer;
          font-size: 18px;
          background: #f9f9f9;
          border-radius: 18px;
          padding: 6px 19px 6px 14px;
          font-weight: 500;
          border: 1.5px solid #e0e0e0;
          transition: background .14s;
          outline: none;
          user-select: none;
      }
      .user-btn:focus, .user-btn:hover { background: #eafcf8; }
      .user-dropdown {
          position: absolute;
          top: 44px;
          min-width: 140px;
          background: #fff;
          border: 1.5px solid #e0e0e0;
          border-radius: 10px;
          box-shadow: 0 4px 18px 0 rgba(23,60,84,.07);
          display: none;
          flex-direction: column;
          padding: 10px 0;
          z-index: 2222;
      }
      .user-menu-wrap:focus-within .user-dropdown,
      .user-btn:focus + .user-dropdown,
      .user-btn.open + .user-dropdown { display: flex; }
      .user-dropdown a {
          padding: 8px 20px 8px 16px;
          color: #20756b;
          text-decoration: none;
          display: flex;
          align-items: center;
          gap: 9px;
          font-size: 16px;
          transition: background .13s, color .13s;
      }
      .user-dropdown a:hover {
          background: #f7fdf7;
          color: #21c1a6;
      }
      .logout-btn {
          background: #f4f6f7;
          color: #e74c3c;
          border: 1.5px solid #e0e0e0;
          border-radius: 18px;
          padding: 6px 19px 6px 16px;
          font-size: 15.5px;
          font-weight: 600;
          margin-left: 8px;
          text-decoration: none;
          transition: background .12s, color .12s;
          display: flex;
          align-items: center;
          gap: 6px;
      }
      .logout-btn:hover {
          background: #ffeaea;
          color: #c0392b;
          border-color: #e3b4b1;
      }
.demography-grid select,
.demography-grid input {
  padding: 8px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 0.95em;
  background: #fafafa;
}
.demography-grid select:focus,
.demography-grid input:focus {
  border-color: #21c1a6;
  background: #fff;
}
/* რეგისტრაციის პანელი */
.admission-panel {
  margin-top: 30px;
  border: 1px solid #ddd;
  border-radius: 8px;
  background: #fff;
}
.admission-panel .panel-header {
  display: flex;
  align-items: center;
  padding: 12px 20px;
  background: #f7f7f5;
  border-bottom: 1px solid #ededed;
}
.admission-panel .panel-title {
  font-size: 1.1em;
  color: #21c1a6;
  margin: 0;
}
.admission-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  padding: 20px;
}
.admission-grid .form-group {
  display: flex;
  flex-direction: column;
}
.admission-grid label {
  font-size: 0.9em;
  color: #555;
  margin-bottom: 4px;
}
.admission-grid input,
.admission-grid select,
.admission-grid textarea {
  padding: 8px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 0.95em;
  background: #fafafa;
}
.admission-grid input:focus,
.admission-grid select:focus,
.admission-grid textarea:focus {
  border-color: #21c1a6;
  background: #fff;
}
.admission-grid .full-width {
  grid-column: 1 / -1;
}
.overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.35);
  display:flex;
  justify-content:center;
  align-items:center;
  z-index:10000;
  backdrop-filter:blur(5px);
}
.innerfrm{
  background:#fff;
  border-radius:12px;
  box-shadow:0 10px 25px rgba(0,0,0,.1);
  padding:20px 28px 30px;
  overflow:auto;
  max-height:90vh;
}
.bxclsF{
  position:absolute;
  top:12px;
  right:16px;
  font-size:28px;
  color:#999;
  text-decoration:none;
}
.bxclsF:hover{color:#555;}
.Ctable{
  width:100%;
  border-collapse:collapse;
  font-size:15px;
}
.Ctable th, .Ctable td{
  border:1px solid #ddd;
  padding:8px 10px;
  text-align:left;
}
.Ctable tr:nth-child(even){background:#f9f9f9;}

/* საგარანტიო უკვე გაქვს მაღლა, მაგრამ full-width კლასის დამატებამ და grid-სინგ მიაპყრობს */
.guarantee-panel { /* არსებობს */ }
.guarantee-grid .full-width {
  grid-column: 1 / -1;
}

        /* Panel overrides */
        .guarantee-panel { margin-top: 30px; }
        .panel-header { background: none; border-bottom: none; }
        .panel-title { color: #21c1a6; margin: 0; }
        .text-center { text-align: center; }
        .checkbox-label { color: #8985E7; font-size: 15px; cursor: default; }
        .checkbox-input { margin-left: 8px; vertical-align: middle; }
        .guarantee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            align-items: flex-end;
            margin-top: 20px;
        }
        .autocomplete-dropdown {
            position: absolute;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background: #eee;
            border: 1px solid #999;
            z-index: 1000;
        }
        .flex-inline {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .comment-group {
            grid-column: span 2;
        }
        .btn-toggle {
            background: #21c1a6;
            color: #fff;
            border: none;
            border-radius: 4px;
            width: 36px;
            height: 36px;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
        }

        .tab-content.hidden { display: none; }
        .tg td {
            padding: 6px 12px;
            border: 1px solid #ddd;
        }
        .tg tr:nth-child(even) { background: #f9f9f9; }
        .tg tr:nth-child(odd) { background: #fff; }
        .tg b { color: #444; }
        fieldset legend { font-weight: bold; }
        body {
            font-family: "Noto Sans Georgian", sans-serif;
            background: #f9f8f2;
            color: #222;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1650px;
            margin: 38px auto 48px auto;
            padding: 0 40px;
        }
        .tabs {
            list-style: none;
            display: flex;
            border-bottom: 2px solid #d8d6d3;
            margin-bottom: 18px;
            gap: 3px;
            padding-left: 0;
            background: none;
            font-size: 16px;
        }
        .tabs a {
            display: block;
            padding: 10px 30px 9px 30px;
            background: #21c1a6;
            color: #fff;
            text-decoration: none;
            border-top-left-radius: 7px;
            border-top-right-radius: 7px;
            transition: background .13s;
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .tabs a.active, .tabs a:hover {
            background: #fff;
            color: #21c1a6;
            border-bottom: 2px solid #f9f8f2;
        }
        .panel {
            background: #fff;
            border: 1.5px solid #e4e4e4;
            border-radius: 8px;
            margin-bottom: 28px;
            overflow: visible;
            box-shadow: 0 2px 10px rgba(31,61,124,0.05);
            max-width: 100%;
            min-width: 0;
        }
        .panel-header {
            background: #f7f7f5;
            padding: 18px 28px 13px 32px;
            font-size: 1.08em;
            font-weight: 700;
            border-bottom: 1px solid #ededed;
            letter-spacing: .01em;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .panel-body {
            padding: 23px 32px 18px 32px;
            display: flex;
            flex-wrap: wrap;
            gap: 30px 36px;
            align-items: center;
            background: none;
        }

        .user-menu-wrap {
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
        }
        .user-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #21c1a6;
            cursor: pointer;
            font-size: 18px;
            background: #f9f9f9;
            border-radius: 18px;
            padding: 6px 19px 6px 14px;
            font-weight: 500;
            border: 1.5px solid #e0e0e0;
            transition: background .14s;
            outline: none;
            user-select: none;
        }
        .user-btn:focus, .user-btn:hover { background: #eafcf8; }
        .user-dropdown {
            position: absolute;
            top: 44px;
            min-width: 140px;
            background: #fff;
            border: 1.5px solid #e0e0e0;
            border-radius: 10px;
            box-shadow: 0 4px 18px 0 rgba(23,60,84,.07);
            display: none;
            flex-direction: column;
            padding: 10px 0;
            z-index: 2222;
        }
        .user-menu-wrap:focus-within .user-dropdown,
        .user-btn:focus + .user-dropdown,
        .user-btn.open + .user-dropdown { display: flex; }
        .user-dropdown a {
            padding: 8px 20px 8px 16px;
            color: #20756b;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 16px;
            transition: background .13s, color .13s;
        }
        .user-dropdown a:hover {
            background: #f7fdf7;
            color: #21c1a6;
        }
        .logout-btn {
            background: #f4f6f7;
            color: #e74c3c;
            border: 1.5px solid #e0e0e0;
            border-radius: 18px;
            padding: 6px 19px 6px 16px;
            font-size: 15.5px;
            font-weight: 600;
            margin-left: 8px;
            text-decoration: none;
            transition: background .12s, color .12s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .logout-btn:hover {
            background: #ffeaea;
            color: #c0392b;
            border-color: #e3b4b1;
        }
        .form-group {
            position: relative;
            flex: 1 1 180px;
            min-width: 170px;
            max-width: 300px;
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            color: #414141;
            font-size: 14.5px;
            margin-bottom: 5px;
            margin-left: 2px;
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1.2px solid #cdd5de;
            border-radius: 4px;
            font-size: 15px;
            background: #f9f9f9;
            outline: none;
            transition: border .16s;
        }
        .form-group input:focus,
        .form-group select:focus {
            border: 1.7px solid #21c1a6;
            background: #fff;
        }
        .btn-search {
            padding: 12px 26px;
            background: #21c1a6;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 6px;
            transition: background .13s;
        }
        .btn-search:hover { background: #18a591; }
        .btn-main {
            padding: 12px 30px;
            background: #21c1a6;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 6px;
            transition: background .13s;
        }
        .btn-main:hover { background: #18a591; }
        .error {
            color: #e74c3c;
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 16px;
            margin-left: 2px;
        }
        .patients-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
            margin-top: 2px;
            border-radius: 6px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 1px 8px 0 rgba(31,61,124,0.04);
            min-width: 900px;
        }
        /* ===================================
   საგარანტიო სექციის დიზაინი
=================================== */
.guarantee-panel {
  margin-top: 30px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  border-radius: 8px;
  overflow: hidden;
}
.guarantee-panel .panel-header {
  display: flex;
  align-items: center;
  background: #e0f2f1;
  padding: 16px 20px;
  border-bottom: 1px solid #b2dfdb;
}
.guarantee-panel .panel-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 1.2em;
  color: #00695c;
  margin: 0;
}
.guarantee-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
  gap: 16px 24px;
  padding: 20px;
}
.guarantee-grid .form-group {
  display: flex;
  flex-direction: column;
}
.guarantee-grid label {
  font-size: 0.95em;
  color: #333;
  margin-bottom: 6px;
}
.guarantee-grid input[type="text"],
.guarantee-grid input[type="date"],
.guarantee-grid textarea {
  padding: 10px;
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 0.95em;
  background: #fafafa;
  transition: border-color .2s, background .2s;
}
.guarantee-grid input[type="text"]:focus,
.guarantee-grid input[type="date"]:focus,
.guarantee-grid textarea:focus {
  border-color: #26a69a;
  background: #fff;
  outline: none;
}
.guarantee-grid .full-width {
  grid-column: 1 / -1;
}#upnav.upnav{margin-top:10px;display:flex;gap:12px;border-bottom:2px solid #ddd;padding:6px 40px;}
#upnav.upnav a{text-decoration:none;color:#21c1a6;padding:6px 12px;border-radius:4px;font-weight:600;}
#upnav.upnav a.active,#upnav.upnav a:hover,#upnav.upnav a:focus{background:#21c1a6;color:#fff;outline:none;}
.checkbox-group label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 1em;
  font-weight: 500;
}
.checkbox-group input[type="checkbox"] {
  transform: scale(1.2);
}

        .patients-table th,
        .patients-table td {
            border-bottom: 1px solid #ececec;
            padding: 13px 10px;
            text-align: left;
            font-weight: 400;
        }
        .patients-table th {
            background: #21c1a6;
            color: #fff;
            font-size: 15.5px;
            font-weight: bold;
            border-bottom: 2px solid #e5e0df;
            letter-spacing: .01em;
        }
        .patients-table tr:last-child td { border-bottom: none; }
        .patients-table tbody tr:nth-child(odd) { background: #f7fdf7; }
        .patients-table tbody tr:hover { background: #e6fbf3; }
        .patients-table .actions button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 3px 7px;
            color: #21c1a6;
            font-size: 18px;
            transition: color .14s;
        }
        .patients-table .actions button:hover { color: #e67e22; }
        .patients-table .actions form button:hover { color: #e74c3c; }
        .patients-table .empty-row {
            text-align: center;
            color: #888;
            padding: 22px;
        }
        #editModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.35);
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }
        #editModal .modal-content {
            background: #fff;
            border-radius: 12px;
            max-width: 750px;
            width: 90%;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow-y: auto;
            max-height: 90vh;
        }
        #editModal .close-x {
            position: absolute;
            top: 12px;
            right: 12px;
            background: none;
            border: none;
            font-size: 30px;
            color: #aaa;
            cursor: pointer;
            transition: color 0.2s ease-in-out;
        }
        #editModal .close-x:hover { color: #555; }
        .tabs.subtab {
            display: flex;
            list-style: none;
            padding: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .tabs.subtab li { margin-right: 5px; }
        .tabs.subtab a {
            padding: 10px 18px;
            background: #e9f7f5;
            color: #21c1a6;
            text-decoration: none;
            border-radius: 6px 6px 0 0;
            transition: background 0.3s, color 0.3s;
            font-weight: 600;
        }
        .tabs.subtab a.active,
        .tabs.subtab a:hover {
            background: #21c1a6;
            color: #fff;
        }
        .tab-content {
            border: 1px solid #f0f0f0;
            padding: 25px;
            background: #fafafa;
            border-radius: 0 6px 6px 6px;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.03);
        }
        .tab-content.hidden { display: none; }
        #editModal .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 14px;
        }
        #editModal .form-group label {
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
            font-size: 14px;
        }
        #editModal .form-group input,
        #editModal .form-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fff;
            transition: border-color 0.2s;
        }
        #editModal .form-group input:focus,
        #editModal .form-group select:focus {
            border-color: #21c1a6;
            box-shadow: 0 0 5px rgba(33, 193, 166, 0.3);
            outline: none;
        }
        #editModal .btn-main {
            padding: 10px 30px;
            background: #21c1a6;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        #editModal .btn-main:hover { background: #1aa38e; }
        .patients-table tbody tr:hover {
            background: #f3fdfb;
            transition: background 0.2s ease;
        }
        @media (max-width: 600px) {
            #editModal .modal-content {
                padding: 15px;
                max-width: 95%;
            }
            .tabs.subtab a {
                padding: 8px 12px;
                font-size: 13px;
            }
            .tab-content { padding: 15px; }
            .form-group { margin-bottom: 10px; }
        }
    </style>
</head>
<body>
<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:50px;width:auto;">
    </a>
    <div class="user-menu-wrap" tabindex="0" aria-haspopup="true" aria-expanded="false" role="button">
        <div class="user-btn" tabindex="0" aria-label="მომხმარებლის მენიუ">
            <i class="fas fa-user-circle"></i>
            <span><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="user-dropdown" tabindex="-1" role="menu" aria-label="მომხმარებლის მენიუ">
            <a href="#" id="telLink" role="menuitem"><i class="fas fa-phone"></i> ტელ</a>
            <a href="profile.php" role="menuitem"><i class="fas fa-user"></i> პროფილი</a>
            <?php if (($_SESSION["role"] ?? "") === "superadmin"): ?><a href="admin/logs.php" role="menuitem"><i class="fas fa-scroll"></i> ლოგები</a><?php endif; ?>
        </div>
        <a href="logout.php" class="logout-btn" title="გამოსვლა" aria-label="გასვლა">
            <i class="fas fa-sign-out-alt"></i> გამოსვლა
        </a>
    </div>
</div>

<!-- >>> შენი upnav აქ <<< -->
<nav id="upnav" class="upnav" role="navigation" aria-label="Secondary navigation">
  <a href="dashboard.php" class="<?= $cur=='dashboard.php' ? 'active' : '' ?>"><i class="fas fa-home"></i> მთავარი</a>
  <a href="doctors.php"   class="<?= $cur=='doctors.php'   ? 'active' : '' ?>"><i class="fas fa-users"></i> HR</a>
  <a href="journal.php"   class="<?= $cur=='journal.php'   ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i> რეპორტი</a>
    <a href="test.php"   class="<?= $cur=='test.php'   ? 'active' : '' ?>"><i class="fas fa-calendar-alt"></i> გრაფიკი</a>
</nav>
<!-- <<< /upnav >>> -->

<?php if (!empty($_SESSION['flash'])): ?>
  <!-- Flash styles (safe to keep inline or move to your CSS bundle) -->
  <style>
    .flash-wrap{max-width:1650px;margin:12px auto 0;padding:0 40px}
    .flash{position:relative;display:flex;align-items:flex-start;gap:10px;margin:6px 0;padding:11px 14px 11px 12px;
           border-radius:8px;border:1px solid; box-shadow:0 1px 8px rgba(31,61,124,0.04);}
    .flash.success{background:#e8f7ef;border-color:#bfead1;color:#196c3d}
    .flash.error{background:#fdecea;border-color:#f5c6cb;color:#7d2323}
    .flash.info{background:#e8f4fd;border-color:#bcdffb;color:#1f5f8b}
    .flash.warning{background:#fff8e1;border-color:#ffe8a3;color:#7a5a00}
    .flash .flash-close{position:absolute;right:10px;top:8px;border:0;background:transparent;cursor:pointer;
                        font-size:22px;line-height:1;color:#777}
    .flash .flash-close:hover{color:#444}
    .flash.fade-out{opacity:0;max-height:0;margin:0;padding-top:0;padding-bottom:0;border-width:0;overflow:hidden;transition:all .6s ease}
  </style>

  <div class="flash-wrap" aria-live="polite" aria-atomic="true">
    <?php foreach ($_SESSION['flash'] as $f): 
      $type = $f['type'] ?? 'info';
      $msg  = $f['msg']  ?? '';
    ?>
      <div class="flash <?= htmlspecialchars($type) ?>" role="alert">
        <span><?= htmlspecialchars($msg) ?></span>
        <button type="button" class="flash-close" aria-label="დახურვა">&times;</button>
      </div>
    <?php endforeach; $_SESSION['flash'] = []; // clear after showing ?>
  </div>

  <script>
    // Close buttons
    document.querySelectorAll('.flash .flash-close').forEach(btn=>{
      btn.addEventListener('click',()=>btn.parentElement.classList.add('fade-out'));
    });
    // Auto-hide after 5s
    setTimeout(()=>document.querySelectorAll('.flash').forEach(el=>el.classList.add('fade-out')), 5000);
  </script>
<?php endif; ?>


<div class="container">
    <ul class="tabs">
      <?php foreach ($tabs as $t):
            if (!$can($t['file'])) continue;  // hide locked tab
            $active = ($t['file'] === $cur) ? 'active' : ''; ?>
        <li>
          <a href="<?= htmlspecialchars($t['file']) ?>" class="<?= $active ?>">
            <?php if (!empty($t['icon'])): ?>
              <i class="fas <?= htmlspecialchars($t['icon']) ?>"></i>
            <?php endif; ?>
            <?= htmlspecialchars($t['label']) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>


    <div id="search-panel" class="panel">
        <div class="panel-header">პაციენტის ძებნა</div>
        <form method="get" class="panel-body">
            <div class="form-group">
                <label>სახელი</label>
                <input type="text" name="search_first_name" value="<?=htmlspecialchars($_GET['search_first_name'] ?? '')?>" placeholder="სახელი">
            </div>
            <div class="form-group">
                <label>გვარი</label>
                <input type="text" name="search_last_name" value="<?=htmlspecialchars($_GET['search_last_name'] ?? '')?>" placeholder="გვარი">
            </div>
            <div class="form-group">
                <label>პირადი №</label>
                <input type="text" name="search_personal_id" value="<?=htmlspecialchars($_GET['search_personal_id'] ?? '')?>" placeholder="პირადი ნომერი">
            </div>
            <div class="form-group">
                <label>რიგითი №</label>
                <input type="text" name="search_order_id" value="<?=htmlspecialchars($_GET['search_order_id'] ?? '')?>" placeholder="რიგითი ნომერი">
            </div>
            <button class="btn-search" type="submit">
                <i class="fas fa-search"></i> ძიება
            </button>
        </form>
    </div>

    <div id="editModal" style="display:none;">
        <div class="modal-content">
            <button class="close-x" onclick="closeEditModal()" title="დახურვა">×</button>
            <ul class="tabs subtab" id="editTabs">
                <li><a href="#tab-info" class="active">პერსონალური ინფორმაცია</a></li>
                <li><a href="#tab-programs">პროგრამები</a></li>
            </ul>
            <div id="tab-info" class="tab-content">
                <form id="editForm" method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:20px 32px;">
                    <input type="hidden" name="action" value="edit_patient">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <div class="form-group">
                        <label>რიგითი №</label>
                        <input type="text" name="id" id="id" readonly>
                    </div>
                    <div class="form-group">
                        <label>უნიკალური კოდი</label>
                        <input type="text" name="unique_code" id="unique_code" readonly>
                    </div>
                    <div class="form-group">
                        <label>შექმნა</label>
                        <input type="text" name="created_by" id="created_by" readonly>
                    </div>
                    <div class="form-group">
                        <label>შექმნის თარიღი</label>
                        <input type="text" name="created_at" id="created_at" readonly>
                    </div>
                    <div style="grid-column:1/3;border-bottom:1.2px solid #e6e6e6;margin-bottom:10px;"></div>
                    <div class="form-group"><label>პირადი №*</label><input type="text" name="personal_id" id="personal_id" required></div>
                    <div class="form-group"><label>სახელი*</label><input type="text" name="first_name" id="first_name" required></div>
                    <div class="form-group"><label>გვარი*</label><input type="text" name="last_name" id="last_name" required></div>
                    <!-- your field -->
<!-- EDIT DATE: DMY display + hidden ISO -->
<div class="form-group">
  <label>დაბადების თარიღი*</label>
  <input type="text" id="edit_birthdate_dmy" placeholder="dd.mm.yyyy" inputmode="numeric" required>
  <input type="hidden" name="birthdate" id="edit_birthdate_iso">
</div>


                    <div class="form-group"><label>სქესი*</label>
                        <select name="gender" id="gender" required>
                            <option value="">–</option>
                            <option value="მამრობითი">მამრობითი</option>
                            <option value="მდედრობითი">მდედრობითი</option>
                        </select>
                    </div>
                    <div class="form-group"><label>ტელეფონი</label><input type="tel" name="phone" id="phone"></div>
                    <div class="form-group"><label>მობილური</label><input type="tel" name="mobile" id="mobile"></div>
                    <div class="form-group"><label>იმეილი</label><input type="email" name="email" id="email"></div>
                    <div class="form-group"><label>მოქალაქეობა</label><input type="text" name="citizenship" id="citizenship"></div>
                    <div class="form-group"><label>მისამართი</label><input type="text" name="address" id="address"></div>
                    <div class="form-group"><label>სამუშაო ადგილი</label><input type="text" name="workplace" id="workplace"></div>
                    <div class="form-group"><label>სტატუსი</label>
                        <select name="status" id="status">
                            <option value="აქტიური">აქტიური</option>
                            <option value="გარდაცვლილი">გარდაცვლილი</option>
                        </select>
                    </div>
                    <div style="grid-column:1/3;border-bottom:1.2px solid #e6e6e6;margin-bottom:8px;"></div>
                    <div class="form-group"><label>ნათესავი</label>
                        <select name="relative_type" id="relative_type">
                            <option value="">–</option>
                            <option value="დედა">დედა</option>
                            <option value="მამა">მამა</option>
                            <option value="და">და</option>
                            <option value="ძმა">ძმა</option>
                            <option value="სხვა">სხვა...</option>
                        </select>
                    </div>
                    <div class="form-group"><label>სახელი</label><input type="text" name="relative_first_name" id="relative_first_name"></div>
                    <div class="form-group"><label>გვარი</label><input type="text" name="relative_last_name" id="relative_last_name"></div>
                    <div class="form-group"><label>პირადი №</label><input type="text" name="relative_personal_id" id="relative_personal_id"></div>
                    <div class="form-group"><label>დაბადების თარიღი</label><input type="date" name="relative_birthdate" id="relative_birthdate"></div>
                    <div class="form-group"><label>სქესი</label>
                        <select name="relative_gender" id="relative_gender">
                            <option value="">–</option>
                            <option value="მამრობითი">მამრობითი</option>
                            <option value="მდედრობითი">მდედრობითი</option>
                        </select>
                    </div>
                    <div class="form-group"><label>ტელეფონი</label><input type="tel" name="relative_phone" id="relative_phone"></div>
                    <div class="form-group"><label>მისამართი</label><input type="text" name="relative_address" id="relative_address"></div>
                    <div class="form-group" style="grid-column:1/3;text-align:right;">
                        <button type="submit" class="btn-main">შენახვა</button>
                    </div>
                </form>
            </div>
            <div id="tab-programs" class="tab-content hidden">
                <h3>პროგრამები</h3>
            </div>
        </div>
    </div>
    <div id="viewModal" style="display:none;">
    <div class="modal-content">
        <button class="close-x" onclick="closeViewModal()" title="დახურვა">×</button>
        <div id="viewContent"></div>
    </div>
    </div>


    <div id="add-panel" class="panel">
        <div class="panel-header">პაციენტის დამატება</div>
        <form method="post" class="panel-body">
            <input type="hidden" name="action" value="add_patient">
            <?php if ($error): ?>
                <div class="error"><?=htmlspecialchars($error)?></div>
            <?php endif; ?>
            <div class="form-group"><label>პირადი ნომერი*</label><input type="text" name="personal_id" required placeholder="პირადი ნომერი"></div>
            <div class="form-group"><label>სახელი*</label><input type="text" name="first_name" required placeholder="სახელი"></div>
            <div class="form-group"><label>გვარი*</label><input type="text" name="last_name" required placeholder="გვარი"></div>
            <div class="form-group"><label>მამის სახელი</label><input type="text" name="father_name" placeholder="მამის სახელი"></div>
<div class="form-group">
  <label>დაბადების თარიღი*</label>
  <input type="date" name="birthdate" id="add_birthdate" required>
</div>
<script>
  // optional: block future dates
  const ab = document.getElementById('add_birthdate');
  if (ab) ab.max = new Date().toISOString().slice(0,10);
</script>
           <div class="form-group"><label>სქესი*</label>
                <select name="gender" required>
                    <option value="">–</option>
                    <option value="მამრობითი">მამრობითი</option>
                    <option value="მდედრობითი">მდედრობითი</option>
                </select>
            </div>
            <div class="form-group"><label>მობილური</label><input type="tel" name="phone" placeholder="555 12 34 56"></div>
            <div class="form-group"><label>მოქალაქეობა</label>
                <select name="citizenship">
                    <option value="საქართველო">საქართველო</option>
                    <option value="სხვა">სხვა</option>
                </select>
            </div>
            <div class="form-group"><label>იურიდიული მისამართი</label><input type="text" name="address" placeholder="მისამართი"></div>
            <div class="form-group"><label>სამუშაო ადგილი</label><input type="text" name="workplace" placeholder="სამუშაო ადგილი"></div>
            <div class="form-group"><label>იმეილი</label><input type="email" name="email" placeholder="ელ.ფოსტა"></div>
            <div class="form-group"><label>ტელ</label><input type="tel" name="telephone" placeholder="ტელეფონის ნომერი"></div>
            <div class="form-group" style="max-width:180px;">
                <button type="button" onclick="toggleRelativeBlock()" class="btn-main" style="margin-bottom: 15px;">
                    <i class=""></i> 
                </button>
                <div id="relative-block" style="display: none;">
                    <div class="form-group"><label>ნათესავი</label>
                        <select name="relative_type" id="relative_type">
                            <option value="">–</option>
                            <option value="დედა">დედა</option>
                            <option value="მამა">მამა</option>
                            <option value="და">და</option>
                            <option value="ძმა">ძმა</option>
                            <option value="სხვა">სხვა...</option>
                        </select>
                    </div>
                    <div class="form-group"><label>სახელი</label><input type="text" name="relative_first_name" id="relative_first_name"></div>
                    <div class="form-group"><label>გვარი</label><input type="text" name="relative_last_name" id="relative_last_name"></div>
                    <div class="form-group"><label>პირადი №</label><input type="text" name="relative_personal_id" id="relative_personal_id"></div>
                    <div class="form-group"><label>დაბადების თარიღი</label><input type="date" name="relative_birthdate" id="relative_birthdate"></div>
                    <div class="form-group"><label>სქესი</label>
                        <select name="relative_gender" id="relative_gender">
                            <option value="">–</option>
                            <option value="მამრობითი">მამრობითი</option>
                            <option value="მდედრობითი">მდედრობითი</option>
                        </select>
                    </div>
                    <div class="form-group"><label>ტელეფონი</label><input type="tel" name="relative_phone" id="relative_phone"></div>
                    <div class="form-group"><label>მისამართი</label><input type="text" name="relative_address" id="relative_address"></div>
                </div>
                <button class="btn-main" type="submit"><i class="fas fa-user-plus"></i> დამატება</button>
            </div>
        </form>
    </div>

    <div id="customModal" style="display:none;">
        <div class="modal-content">
            <button class="close-x" onclick="closeCustomModal()" title="დახურვა">×</button>
            <h2 style="margin-bottom:20px;">დამატებითი ინფორმაცია</h2>
            <form id="customForm" method="post">
                <input type="hidden" name="action" value="custom_info">
                <input type="hidden" name="patient_id" id="custom_patient_id">
                <div class="form-group">
                    <label>შენიშვნა</label>
                    <textarea name="extra_info" id="extra_info" rows="4" style="width:100%;border:1px solid #ccc;padding:10px;border-radius:6px;"></textarea>
                </div>
                <div style="text-align:right;">
                    <button type="submit" class="btn-main">შენახვა</button>
                </div>
            </form>
        </div>
    </div>
<div id="telModal" class="overlay" style="display:none;">
  <div class="innerfrm ui-draggable" style="width:900px; position:relative; top:5px;">
    <a href="javascript:void(0);" class="bxclsF zdx nondrg" onclick="closeTelModal()">×</a>
    <br>
<div id="onemliin" class="e0_">
  <div style="margin-top:30px">
    <table class="Ctable nondrg">
      <thead>
        <tr>
          <th width="25%">გვარი</th>
          <th width="25%">სახელი</th>
          <th width="50%">ტელეფონ(ები)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($telRows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['last_name']) ?></td>
          <td><?= htmlspecialchars($r['first_name']) ?></td>
          <td>
            <?php
echo htmlspecialchars(implode(' / ', array_filter(array_unique([
    $r['phone'] ?? '',
    $r['mobile'] ?? '',
    $r['telephone'] ?? ''
]))));
            ?>
          </td>
        </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    </div>
    <div id="ldispin" class="mnhov" style="position:absolute;left:0;top:0;width:100%;height:100%;display:none;">
      <div style="margin:auto;margin-top:150px;width:50px;height:50px;background-image:url(../../images/spinner.gif)"></div>
    </div>
  </div>
</div>


<div id="afterSaveModal" class="overlay" style="display:none;">
  <div class="innerfrm ui-draggable" style="width:900px; position:relative; top:5px;">
    <a href="javascript:void(0);" class="bxclsF zdx nondrg" onclick="closeAfterSaveModal()">×</a>
    <div id="afterSaveContent"></div>
  </div>
</div>


    <div class="panel" style="overflow-x:auto;">
        <table class="patients-table">
            <thead>
                <tr>
                    <th>რიგითი №</th>
                    <th>პირადი №</th>
                    <th>სახელი</th>
                    <th>გვარი</th>
                    <th> თარიღი</th>
                    <th>სქესი</th>
                    <th>ტელეფონი</th>
                    <th>მისამართი</th>
                    <th>მოქმედებები</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $p): ?>
                    <tr>
                        <td><?=htmlspecialchars($p['id'])?></td>
                        <td><?=htmlspecialchars($p['personal_id'])?></td>
                        <td><?=htmlspecialchars($p['first_name'])?></td>
                        <td><?=htmlspecialchars($p['last_name'])?></td>
                        <td><?=htmlspecialchars(fmt_date($p['birthdate'] ?? null), ENT_QUOTES, 'UTF-8')?></td>
                        <td><?=htmlspecialchars($p['gender'])?></td>
                        <td><?=htmlspecialchars($p['phone'])?></td>
<td>
  <?php
    $addr  = trim((string)($p['address'] ?? ''));
    $laddr = trim((string)($p['legal_address'] ?? ''));
    echo htmlspecialchars($addr !== '' ? $addr : ($laddr !== '' ? $laddr : '—'), ENT_QUOTES, 'UTF-8');
  ?>
</td>
                        <td class="actions" onclick="event.stopPropagation();">
                            <button title="რედაქტირება" onclick='openEditModal(<?=json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>)'>
                                <i class="fas fa-pen"></i>
                            </button>
 <button title="დათვალიერება" onclick='openViewModal(<?=json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>)'>
    <i class="fas fa-eye"></i>
</button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('ნამდვილად გსურს წაშლა?');">
                                <input type="hidden" name="action" value="delete_patient">
                                <input type="hidden" name="delete_id" value="<?=htmlspecialchars($p['id'])?>">
                                <button type="submit" title="წაშლა">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$patients): ?>
                    <tr>
                        <td colspan="9" class="empty-row">ჩანაწერი ვერ მოიძებნა</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>

function toggleRelativeBlock() {
    const block = document.getElementById('relative-block');
    block.style.display = (block.style.display === 'none' || block.style.display === '') ? 'block' : 'none';
}
  const services = <?= json_encode($services, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const doctors  = <?= json_encode($doctors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  function _pad2(n){return String(n).padStart(2,'0');}
function isoToDmy(iso){
  if(!iso) return '';
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if(!m) return '';
  return `${m[3]}.${m[2]}.${m[1]}`;
}
function dmyToIso(dmy){
  if(!dmy) return '';
  const m = String(dmy).match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if(!m) return '';
  const d = _pad2(m[1]), mo = _pad2(m[2]), y = m[3];
  // basic validity (reject future dates)
  const dt = new Date(`${y}-${mo}-${d}T00:00:00`);
  if (isNaN(dt.getTime())) return '';
  const today = new Date(); today.setHours(0,0,0,0);
  if (dt > today) return '';
  return `${y}-${mo}-${d}`;
}

// init flatpickr on the DMY edit field, if available
document.addEventListener('DOMContentLoaded', ()=>{
  const dmy = document.getElementById('edit_birthdate_dmy');
  if (window.flatpickr && dmy){
    const opts = { dateFormat: 'd.m.Y', allowInput:true, maxDate: new Date() };
    try {
      // ka locale if loaded
      if (window.flatpickr.l10ns && window.flatpickr.l10ns.ka) opts['locale'] = 'ka';
    }catch(_){}
    const fp = flatpickr(dmy, opts);
    dmy.addEventListener('change', ()=>{
      document.getElementById('edit_birthdate_iso').value = dmyToIso(dmy.value);
    });
  } else if (dmy) {
    // no flatpickr—still keep hidden ISO in sync
    dmy.addEventListener('input', ()=>{
      document.getElementById('edit_birthdate_iso').value = dmyToIso(dmy.value);
    });
  }
});
function openEditModal(p = {}) {
  // ---- helpers ----
  const val = (k) => (p && p[k] != null ? String(p[k]) : '');

  const truthy = (v) => {
    const s = String(v).toLowerCase();
    return s === '1' || s === 'true' || s === 'on' || s === 'yes';
  };

  const isoToDmy = (iso) => {
    if (!iso) return '';
    const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
  };

  const dmyToIsoSafe = (dmy) => {
    if (typeof dmyToIso === 'function') return dmyToIso(dmy);
    const m = String(dmy).match(/^(\d{2})[.\-/](\d{2})[.\-/](\d{4})$/);
    return m ? `${m[3]}-${m[2]}-${m[1]}` : '';
  };

  // Try multiple selectors for a field (id and name; with/without "edit_" prefix)
  const findFieldEls = (key) => {
    const ids = [`edit_${key}`, key, `${key}_input`, `${key}-input`];
    for (const id of ids) {
      const el = document.getElementById(id);
      if (el) return [el];
    }
    // radios/checkbox groups by name
    const byName = document.querySelectorAll(
      `input[name="${key}"], select[name="${key}"], textarea[name="${key}"], input[name="edit_${key}"], select[name="edit_${key}"], textarea[name="edit_${key}"]`
    );
    if (byName.length) return Array.from(byName);
    return [];
  };

  // Set a value into one or more inputs (handles radios, select, checkbox, text/date/number)
  const setField = (key, value) => {
    const els = findFieldEls(key);
    if (!els.length) return;

    // If these are radios, set by value
    const radios = els.filter((e) => e.tagName === 'INPUT' && e.type === 'radio');
    if (radios.length) {
      radios.forEach((r) => (r.checked = (String(r.value) === String(value))));
      return;
    }

    els.forEach((el) => {
      if (el.tagName === 'SELECT') {
        // If option missing, add it so .value sticks visually too
        const hasOption = Array.from(el.options).some((o) => String(o.value) === String(value));
        if (!hasOption && value !== '') {
          const opt = document.createElement('option');
          opt.value = String(value);
          opt.textContent = String(value);
          el.appendChild(opt);
        }
        el.value = String(value);
      } else if (el.tagName === 'INPUT' && el.type === 'checkbox') {
        el.checked = truthy(value);
      } else if (el.tagName === 'INPUT' && el.type === 'date') {
        el.value = value || '';
      } else {
        el.value = value != null ? String(value) : '';
      }
    });
  };

  // Date pair setter: supports
  // 1) hidden iso + visible dmy ( *_iso / *_dmy ) with optional "edit_" prefix
  // 2) single <input type="date" id="birthdate"> or id="edit_birthdate"
  const setDateField = (key, isoVal) => {
    const prefixes = ['edit_', ''];
    let setAny = false;

    for (const pref of prefixes) {
      const isoEl = document.getElementById(`${pref}${key}_iso`);
      const dmyEl = document.getElementById(`${pref}${key}_dmy`);
      if (isoEl || dmyEl) {
        if (isoEl) isoEl.value = isoVal || '';
        if (dmyEl) dmyEl.value = isoToDmy(isoVal || '');
        setAny = true;
      }
      const dateInput = document.getElementById(`${pref}${key}`);
      if (dateInput && dateInput.tagName === 'INPUT' && dateInput.type === 'date') {
        dateInput.value = isoVal || '';
        setAny = true;
      }
    }

    // If nothing matched, fall back to generic setter (maybe it's a plain text input)
    if (!setAny) setField(key, isoVal || '');
  };

  // ---- fill all fields ----
  const keys = [
    'id','unique_code','created_by','created_at','personal_id','first_name','last_name','father_name','birthdate',
    'gender','phone','mobile','email','citizenship','address','legal_address','workplace','status',
    'relative_type','relative_first_name','relative_last_name','relative_personal_id','relative_birthdate',
    'relative_gender','relative_phone','relative_address'
  ];

  keys.forEach((k) => {
    if (k === 'birthdate') {
      setDateField('birthdate', val('birthdate'));
    } else if (k === 'relative_birthdate') {
      setDateField('relative_birthdate', val('relative_birthdate'));
    } else {
      setField(k, val(k));
    }
  });

  // ---- UI actions ----
  const tabLink = document.querySelector('#editTabs a[href="#tab-info"]');
  if (tabLink) { try { tabLink.click(); } catch (_) {} }

  const modal = document.getElementById('editModal');
  if (modal) modal.style.display = 'flex';

  // ---- submit wiring for date pairs (both patient + relative) ----
  const ef = document.getElementById('editForm');
  if (ef && !ef.__wiredDOB) {
    ef.addEventListener('submit', (e) => {
      const pairs = [
        // try both prefixed and unprefixed
        ['edit_birthdate_dmy', 'edit_birthdate_iso', 'დაბადების თარიღი'],
        ['birthdate_dmy',      'birthdate_iso',      'დაბადების თარიღი'],
        ['edit_relative_birthdate_dmy', 'edit_relative_birthdate_iso', 'ნათესავის დაბადების თარიღი'],
        ['relative_birthdate_dmy',      'relative_birthdate_iso',      'ნათესავის დაბადების თარიღი'],
      ];
      for (const [dmyId, isoId, label] of pairs) {
        const dmy = document.getElementById(dmyId);
        const iso = document.getElementById(isoId);
        if (dmy && iso) {
          const v = dmyToIsoSafe(dmy.value);
          if (!v && dmy.value.trim() !== '') {
            alert(`${label} არასწორია (გამოიყენეთ ფორმატი dd.mm.yyyy)`);
            e.preventDefault();
            return;
          }
          iso.value = v;
        }
      }
    });
    ef.__wiredDOB = true;
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openCustomModal(p) {
    document.getElementById('customModal').style.display = 'flex';
    document.getElementById('custom_patient_id').value = p.id;
    document.getElementById('extra_info').value = p.extra_info || '';
}

function closeCustomModal() {
    document.getElementById('customModal').style.display = 'none';
}
// >>> ADD THIS BLOCK <<<
function setVal(id, v){
  const el = document.getElementById(id);
  if (el) el.value = v ?? '';
}
function openTelModal(){ document.getElementById('telModal').style.display='flex'; }
function closeTelModal(){ document.getElementById('telModal').style.display='none'; }

document.addEventListener('DOMContentLoaded', ()=>{
  const telLink = document.getElementById('telLink');
  if(telLink) telLink.addEventListener('click', e=>{
    e.preventDefault();
    openTelModal();
  });
});

function setActiveUpnav(){
  const hash = window.location.hash || '#1';
  document.querySelectorAll('#upnav a').forEach(a=>{
    a.classList.toggle('active', a.getAttribute('href') === hash);
  });
}
document.querySelectorAll('#upnav a').forEach(a=>{
  a.addEventListener('click', e=>{
    // თუ უბრალოდ სტილის შეცვლა გინდა და არა რეალური ნავიგაცია, შეგიძლია preventDefault გააკეთო
    setActiveUpnav();
  });
});
window.addEventListener('hashchange', setActiveUpnav);
setActiveUpnav();


function fillDemography(p){
  setVal('mo_regions',      p.region);
  setVal('mo_raions_hid',   p.raion_hid);
  setVal('mo_raions',       p.raion);
  setVal('mo_city',         p.city);
  setVal('mo_otheraddress', p.other_address);
  setVal('mo_ganatleba',    p.education);
  setVal('mo_ojaxi',        p.marital_status);
  setVal('mo_dasaqmeba',    p.employment);
}
function openViewModal(p) {
  const content = `
    <form id="viewForm" method="post" style="display:flex;flex-direction:column;gap:16px;">
      <input type="hidden" name="action" value="save_admission">
      <input type="hidden" name="patient_id" value="${p.id}">
      
      <!-- 1) რეგისტრაცია -->
      <fieldset class="panel admission-panel">
        <legend class="panel-header">
          <h2 class="panel-title"><span>1</span> რეგისტრაცია</h2>
        </legend>
        <div class="admission-grid">
          <div class="form-group">
            <label for="registration_date_input">რეგისტრაციის თარიღი</label>
            <input type="date" name="registration_date" id="registration_date_input" required value="${p.registration_date || new Date().toISOString().slice(0,10)}">
          </div>
 
        </div>
      </fieldset>

 <!-- 2) საგარანტიო -->
<fieldset class="panel guarantee-panel">
  <legend class="panel-header">
    <h2 class="panel-title"><i class="fas fa-shield-alt"></i> 2 საგარანტიო</h2>
  </legend>
  <div class="guarantee-grid" id="guaranteeFormWrap">
    <div class="form-group full-width checkbox-group">
      <label for="viravans">
        <input type="checkbox" id="viravans" checked disabled>
        ვირტუალურ ავანსად დაჯენა
      </label>
    </div>

    <!-- მნიშვნელობა გაიგზავნება როგორც 1 -->
    <input type="hidden" name="is_virtual_advance" id="is_virtual_advance" value="1">
    <!-- მნიშვნელოვანია: პაციენტის ID -->
    <input type="hidden" name="patient_id" id="guar_patient_id" value="">

    <div class="form-group">
      <label for="mo_timwh">დონორი</label>
      <input type="text" name="donor" id="mo_timwh" value="">
    </div>
    <div class="form-group">
      <label for="mo_timamo">თანხა</label>
      <input type="text" name="amount" id="mo_timamo" value="">
    </div>
    <div class="form-group">
      <label for="mo_timltdat">თარიღი</label>
      <input type="date" name="guarantee_date" id="mo_timltdat" value="">
    </div>
    <div class="form-group">
      <label for="mo_timdrdat">ვადა</label>
      <input type="date" name="validity_date" id="mo_timdrdat" value="">
    </div>
    <div class="form-group">
      <label for="mo_letterno">ნომერი</label>
      <input type="text" name="guarantee_number" id="mo_letterno" value="">
    </div>
    <div class="form-group comment-group full-width">
      <label for="mo_ppcomment">კომენტარი</label>
      <textarea name="guarantee_comment" id="mo_ppcomment" rows="2"></textarea>
    </div>

    <div class="form-group full-width" style="text-align:right; margin-top:8px">
      <button type="button" id="btnSaveGuarantee" class="btn">საგარანტიოს შენახვა</button>
    </div>
  </div>
</fieldset>


      <!-- 3) დემოგრაფია -->
      <fieldset class="panel demography-panel">
        <legend class="panel-header">
          <h2 class="panel-title">3 დემოგრაფია</h2>
          <button type="button" class="btn-copy">კოპირება</button>
        </legend>
        <div class="demography-grid">
          <div class="form-group">
            <label for="mo_regions">რეგიონი</label>
            <select name="region" id="mo_regions" class="nondrg"></select>
          </div>
          <div class="form-group">
            <label for="mo_raions_hid">რაიონი</label>
            <select name="raion_hid" id="mo_raions_hid" class="nondrg" style="display:none"></select>
            <select name="raion" id="mo_raions" class="nondrg"></select>
          </div>
          <div class="form-group">
            <label for="mo_city">ქალაქი</label>
            <input type="text" name="city" id="mo_city" class="nondrg" value="${p.city || ''}">
          </div>
          <div class="form-group">
            <label for="mo_otheraddress">ფაქტიური მისამართი</label>
            <input type="text" name="other_address" id="mo_otheraddress" class="nondrg" value="${p.other_address || ''}">
          </div>
          <div class="form-group">
            <label for="mo_ganatleba">განათლება</label>
            <select name="education" id="mo_ganatleba" class="nondrg"></select>
          </div>
          <div class="form-group">
            <label for="mo_ojaxi">ოჯახური მდგომარეობა</label>
            <select name="marital_status" id="mo_ojaxi" class="nondrg"></select>
          </div>
          <div class="form-group">
            <label for="mo_dasaqmeba">დასაქმება</label>
            <select name="employment" id="mo_dasaqmeba" class="nondrg"></select>
          </div>
        </div>
      </fieldset>

      <div style="text-align:right; margin-top:12px;">
        <button type="submit" class="btn-main">შენახვა</button>
      </div>
    </form>`;

  document.getElementById('viewContent').innerHTML = content;
  document.getElementById('viewModal').style.display = 'flex';

  const vf = document.getElementById('viewForm');
  vf.addEventListener("submit", submitAdmission);

  fillDemography(p);
  attachGuaranteeHandlers();
  initGuaranteeToggle();
  // ჩავსვათ patient_id hidden-ში
document.getElementById('guar_patient_id').value = p.id;

// "საგარანტიოს შენახვა" ღილაკი
const gbtn = document.getElementById('btnSaveGuarantee');
if (gbtn) gbtn.addEventListener('click', async () => {
  const fd = new URLSearchParams({
    action: 'save_guarantee',
    patient_id: String(p.id),
    donor: document.getElementById('mo_timwh').value || '',
    amount: document.getElementById('mo_timamo').value || '',
    guarantee_date: document.getElementById('mo_timltdat').value || '',
    validity_date: document.getElementById('mo_timdrdat').value || '',
    guarantee_number: document.getElementById('mo_letterno').value || '',
    guarantee_comment: document.getElementById('mo_ppcomment').value || ''
  });

  try{
    const r = await fetch('dashboard.php', {
        credentials: 'same-origin',
      method: 'POST',
      headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
      body: fd.toString()
    });
    const j = await r.json();
    if (j.status === 'ok') {
      alert('საგარანტიო შეინახა');
    } else {
      alert('შეცდომა: ' + (j.msg || 'ვერ ჩაიწერა'));
    }
  }catch(e){
    alert('ქსელის შეცდომა');
  }
});

}
/**
 * დონორის ბალანსი მხოლოდ არსებული ცხრილებით:
 * total  = patient_guarantees ჯამი
 * used   = payments(method='donor') ჯამი
 * left   = max(total - used, 0)
 */


function attachGuaranteeHandlers(){
  const viravans = document.getElementById('viravans');
  const vd = document.getElementById('mo_timdrdat');
  if (viravans && vd){
    vd.disabled = !viravans.checked;
    viravans.addEventListener('change', () => {
      vd.disabled = !viravans.checked;
      if (viravans.checked) vd.focus();
    });
  }
}


function closeViewModal() {
  document.getElementById('viewModal').style.display = 'none';
}


function renderSummary(p) {
  const container = document.getElementById('summaryContent');
  if (!container) return;

  const html = `
    <table style="width: 100%; border-collapse: collapse;">
      <tr><td><b>რეგისტრაციის თარიღი:</b></td><td>${p.registration_date || '-'}</td></tr>

      <tr><td><b>კომენტარი:</b></td><td>${p.comment || '-'}</td></tr>
      <tr><td><b>დონორი:</b></td><td>${p.donor || '-'}</td></tr>
      <tr><td><b>თანხა:</b></td><td>${p.amount || '-'}</td></tr>
      <tr><td><b>საგარანტიო თარიღი:</b></td><td>${p.guarantee_date || '-'}</td></tr>
      <tr><td><b>საგარანტიო ვადა:</b></td><td>${p.validity_date || '-'}</td></tr>
      <tr><td><b>საგარანტიო ნომერი:</b></td><td>${p.guarantee_number || '-'}</td></tr>
      <tr><td><b>საგარანტიო კომენტარი:</b></td><td>${p.guarantee_comment || '-'}</td></tr>
      <tr><td><b>რეგიონი:</b></td><td>${p.region || '-'}</td></tr>
      <tr><td><b>რაიონი:</b></td><td>${p.raion || '-'}</td></tr>
      <tr><td><b>ქალაქი:</b></td><td>${p.city || '-'}</td></tr>
      <tr><td><b>ფაქტიური მისამართი:</b></td><td>${p.other_address || '-'}</td></tr>
      <tr><td><b>განათლება:</b></td><td>${p.education || '-'}</td></tr>
      <tr><td><b>ოჯახური მდგომარეობა:</b></td><td>${p.marital_status || '-'}</td></tr>
      <tr><td><b>დასაქმება:</b></td><td>${p.employment || '-'}</td></tr>
    </table>
  `;

  container.innerHTML = html;
}

  document.addEventListener('DOMContentLoaded', () => {
    // User-menu
    const userBtn = document.querySelector('.user-btn');
    const userDropdown = document.querySelector('.user-dropdown');
    document.addEventListener('click', e => {
      if (userBtn.contains(e.target)) {
        userDropdown.style.display = userDropdown.style.display === 'flex' ? 'none' : 'flex';
        userBtn.classList.toggle('open');
      } else if (!userDropdown.contains(e.target)) {
        userDropdown.style.display = 'none';
        userBtn.classList.remove('open');
      }
    });

    // Escape to close
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        closeEditModal();
        closeViewModal();
        closeCustomModal();
      }
    });

    // Modal backdrop clicks
    ['editModal','viewModal','customModal'].forEach(id => {
      const modal = document.getElementById(id);
      if (!modal) return;
      modal.addEventListener('click', e => {
        if (e.target === modal) {
          if (id === 'viewModal') closeViewModal();
          if (id === 'editModal') closeEditModal();
          if (id === 'customModal') closeCustomModal();
        }
      });
    });

    // Tabs switching
    document.querySelectorAll('#editTabs a').forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#editTabs a').forEach(x => x.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(tc => tc.classList.add('hidden'));
        a.classList.add('active');
        const content = document.querySelector(a.getAttribute('href'));
        if (content) content.classList.remove('hidden');
      });
    });
  });
  function initGuaranteeToggle() {
    const block = document.getElementById('mo_jjo');
    const btn   = document.getElementById('mo_tog');
    if (!block || !btn) return;
    block.style.display = 'none';
    btn.textContent = '+';
    btn.addEventListener('click', () => {
      const open = block.style.display === 'block';
      block.style.display = open ? 'none' : 'block';
      btn.textContent     = open ? '+' : '-';
    });
  }
  // ვადის ველი ჩართეთ/გამორთეთ ჩექბოქსით
// ვადის ველი ჩართეთ/გამორთეთ ჩექბოქსით (robust)
const cg = document.getElementById('viravans');     // <input type="checkbox" id="viravans" ...>
const vd = document.getElementById('mo_timdrdat');  // validity date

if (cg && vd) {
  vd.disabled = !cg.checked;
  // NOTE: 'viravans' is currently disabled in HTML; remove 'disabled' if you want this to fire.
  cg.addEventListener('change', () => {
    vd.disabled = !cg.checked;
    if (cg.checked) vd.focus();
  });
}

document.addEventListener('DOMContentLoaded', () => {
    const userBtn = document.querySelector('.user-btn');
    const userDropdown = document.querySelector('.user-dropdown');
    document.addEventListener('click', e => {
        if (userBtn.contains(e.target)) {
            userDropdown.style.display = userDropdown.style.display === 'flex' ? 'none' : 'flex';
            userBtn.classList.toggle('open');
        } else if (!userDropdown.contains(e.target)) {
            userDropdown.style.display = 'none';
            userBtn.classList.remove('open');
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeEditModal();
            closeViewModal();
            closeCustomModal();
        }
    });

    const editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('click', e => {
            if (e.target === editModal) closeEditModal();
        });
    }

    const viewModal = document.getElementById('viewModal');
    if (viewModal) {
        viewModal.addEventListener('click', e => {
            if (e.target === viewModal) closeViewModal();
        });
    }

    const customModal = document.getElementById('customModal');
    if (customModal) {
        customModal.addEventListener('click', e => {
            if (e.target === customModal) closeCustomModal();
        });
    }

    document.querySelectorAll('#editTabs a').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            document.querySelectorAll('#editTabs a').forEach(x => x.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.add('hidden'));
            a.classList.add('active');
            const content = document.querySelector(a.getAttribute('href'));
            if (content) content.classList.remove('hidden');
        });
    });
});


function submitAdmission(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'იტვირთება...';

    const fd = new FormData(form);

    fetch('dashboard.php', {
        credentials: 'same-origin',
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'ok') {
            closeViewModal();
            renderAfterSaveTable(d.patient);
            // არ არის საჭირო აქ btn.disabled=false რადგან მოდალი იხურება
        } else {
            alert('შეცდომა შენახვისას: ' + (d.msg || 'უცნობი შეცდომა'));
            btn.disabled = false;
            btn.textContent = 'შენახვა';
        }
    })
    .catch(err => {
        console.error(err);
        alert('ქსელის შეცდომა');
        btn.disabled = false;
        btn.textContent = 'შენახვა';
    });
}
function updatePatientRow(patient) {
    const row = document.querySelector(`.patients-table tr[data-id="${patient.id}"]`);
    if (row) {
        row.querySelector('td:nth-child(2)').textContent = patient.personal_id || '';
        row.querySelector('td:nth-child(3)').textContent = patient.first_name || '';
        row.querySelector('td:nth-child(4)').textContent = patient.last_name || '';
        row.querySelector('td:nth-child(5)').textContent = patient.birthdate || '';
        row.querySelector('td:nth-child(6)').textContent = patient.gender || '';
        row.querySelector('td:nth-child(7)').textContent = patient.phone || '';
        row.querySelector('td:nth-child(8)').textContent =
  (patient.address && patient.address.trim()) ||
  (patient.legal_address && patient.legal_address.trim()) ||
  '—';
    } else {
        const tbody = document.querySelector('.patients-table tbody');
        if (!tbody) return;

        const tr = document.createElement('tr');
        tr.setAttribute('data-id', patient.id);

        tr.innerHTML = `
            <td>${patient.id || ''}</td>
            <td>${patient.personal_id || ''}</td>
            <td>${patient.first_name || ''}</td>
            <td>${patient.last_name || ''}</td>
            <td>${patient.birthdate || ''}</td>
            <td>${patient.gender || ''}</td>
            <td>${patient.phone || ''}</td>
            <td>${patient.legal_address || ''}</td>
            <td class="actions" onclick="event.stopPropagation();">
                <button title="რედაქტირება" onclick='openEditModal(${JSON.stringify(patient)})'>
                    <i class="fas fa-pen"></i>
                </button>
                <button title="დათვალიერება" onclick='openViewModal(${JSON.stringify(patient)})'>
                    <i class="fas fa-eye"></i>
                </button>
                <form method="post" style="display:inline;" onsubmit="return confirm('ნამდვილად გსურს წაშლა?');">
                    <input type="hidden" name="action" value="delete_patient">
                    <input type="hidden" name="delete_id" value="${patient.id}">
                    <button type="submit" title="წაშლა">
                        <i class="fas fa-times-circle"></i>
                    </button>
                </form>
            </td>
        `;
        tbody.prepend(tr);
    }
}

function renderAfterSaveTable(p){
  const html = `
  <div style="visibility:hidden;margin-top:-20px" id="subGMsg" class="uu"></div>
  <span style="display:none" class="r0_"><input type="hidden" id="hididanalizi"></span>
  <span style="display:none" class="r0_"><input type="hidden" id="hdPtRgID" value="${p.id}R${p.id}"></span>

  <div id="inter2" class="e0_">
    <input type="hidden" value="${p.id}" id="hd_reg">
    <input type="hidden" value="0" id="hd_daz">
    <input type="hidden" value="0" id="hd_gabk">

    <div style="background-color:#FFF;border-radius:5px;border:1px solid #E8E8E5;padding:20px;display:table;margin:0 auto;">
      <div style="font-size:15px;color:#4E4E7B;font-family:serif;display:table;margin:0 auto">
        <table>
          <tr><td colspan="2" style="text-align:center;color:#7D6C6C;font-size:16px;font-weight:bold;padding-bottom:10px;">${p.entry_type||''}</td></tr>
          <tr><td style="padding-right:30px">პაციენტი:</td><td>${(p.first_name||'')} ${(p.last_name||'')}</td></tr>
        </table>
      </div>
    </div>

    <fieldset style="background-color:#FFF;padding:20px;min-height:220px;border:1px solid #E8E8E5;margin-top:15px;border-radius:6px;">
      <!-- დამატებული სერვისების სია -->
      <div id="added-services-container" style="margin-top:15px;">
        <h4 style="margin-bottom:8px;">დამატებული კვლევები</h4>

        <div id="donorBox" style="margin:12px 0; padding:12px; border:1px dashed #26a69a; border-radius:8px;">
          <div style="font-weight:700; color:#00695c">დონორის სტატუსი</div>
          <div style="margin-top:6px">
            სულ: <b><span id="dn_total">0.00</span></b> • გამოყენებული: <b><span id="dn_used">0.00</span></b> • დარჩენილი: <b><span id="dn_left">0.00</span></b>
          </div>
          <div style="margin-top:8px; display:flex; gap:8px; align-items:center;">
            <input type="number" id="dn_amount" placeholder="თანხა" min="0" step="0.01" style="max-width:160px; padding:8px;">
            <button type="button" id="btnApplyDonor" class="btn" style="background:#21c1a6;color:#fff;border:none;border-radius:6px;padding:8px 12px;">დონორით დაფარვა</button>
            <small class="muted"></small>
          </div>
        </div>

        <table id="added-services-table" style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr style="background:#f0f0f0;">
              <th style="padding:6px; border:1px solid #ccc;">კვლევა</th>
              <th style="padding:6px; border:1px solid #ccc;">ერთეულური ფასი</th>
              <th style="padding:6px; border:1px solid #ccc;">რაოდ.</th>
              <th style="padding:6px; border:1px solid #ccc;">ჯამი</th>
              <th style="padding:6px; border:1px solid #ccc;">მოქმედება</th>
            </tr>
          </thead>
          <tbody></tbody>
          <tfoot>
            <tr>
              <td colspan="3" style="text-align:right; padding:6px; font-weight:bold; border:1px solid #ccc;">სრული ჯამი:</td>
              <td id="total-sum" style="padding:6px; font-weight:bold; border:1px solid #ccc;">0.00</td>
              <td style="border:1px solid #ccc;"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <legend style="padding:5px;color:#7D6C6C">კვლევები</legend>
      <div style="font-size:12px">
        <table class="tg blol" cellpadding="3" id="mo_nltable" style="font-size:13px;">
          <tr>
            <td width="18%"></td>
            <td width="60%" style="text-align:center;">
              <input type="checkbox" id="mo_fgvb" style="width:22px;height:22px;vertical-align:middle;display:none"> სერვისი/ქვესერვისი
            </td>
            <td width="7%"></td>
            <td width="10%" colspan="2"></td>
            <td width="5%" style="vertical-align:bottom;"></td>
          </tr>
          <tr id="TCb">
            <td width="18%">
              <label style="color:#7D6C6C">კლინიკა
                <select id="ri_sendcmp" style="height:30px" class="nondrg">
                  <option value="0">შიდა</option>
                </select>
              </label>
            </td>
            <td width="60%">
              <label style="color:#7D6C6C">კვლევა</label>
              <select name="service_id" id="mo_itms" style="height:30px;padding-left:4px" class="nondrg knop" autocomplete="off">
                <option value="">აირჩიეთ სერვისი</option>
                ${services.map(s => `<option value="${s.id}" data-price="${Number(s.price)||0}">${s.name}</option>`).join('')}
              </select>
            </td>
            <td width="7%">
              <label for="mo_fas" style="color:#7D6C6C">ფასი</label>
              <!-- გახადეთ რედაქტირებადი: -->
              <input type="number" class="nondrg" id="mo_fas"
                     style="height:30px;text-align:center" value="" step="0.01" min="0">
            </td>
            <td width="10%" colspan="2">
              <label style="color:#7D6C6C">რაოდ</label>
              <input type="number" class="nondrg" id="rg_raod"
                     style="height:30px;padding-left:4px;text-align:center" min="1" step="1" value="1">
            </td>
            <td width="5%" style="vertical-align:bottom;">
              <input type="button" style="height:30px;width:30px" id="mo_nzinst" class="smadbut" value="+">
            </td>
          </tr>
        </table>
      </div>

      <div style="margin-top:18px; display:flex; align-items:center; gap:16px;">
        <label for="selected_doctor" style="color:#7D6C6C;font-weight:bold;">მკურნალი ექიმი:</label>
        <select id="selected_doctor" style="height:32px; min-width:210px;">
          <option value="">აირჩიეთ ექიმი</option>
          <?= count($doctors) ? implode('', array_map(fn($d) => '<option value="'.$d['id'].'">'.htmlspecialchars($d['last_name'].' '.$d['first_name']).'</option>', $doctors)) : '' ?>
        </select>
      </div>

      <br>
      <?php $hasCard = !empty($cardsMap[(int)$p['id']]); ?>
<button
  class="open-card-btn"
  data-pid="<?= (int)$p['id'] ?>"
  data-activated="<?= $hasCard ? 1 : 0 ?>"
  <?= $hasCard ? 'disabled' : '' ?>
  style="background: <?= $hasCard ? '#28a745' : '#007bff' ?>; color:#fff; border:none; border-radius:4px; padding:6px 10px; cursor:<?= $hasCard ? 'default' : 'pointer' ?>;"
>
  <?= $hasCard ? 'დამატებულია ✓' : 'ბარათის გახსნა' ?>
</button>
      <button type="button" id="btn200"
              style="padding:8px 12px; background:#21c1a6; color:#fff; border:none; border-radius:4px; font-weight:600;">
        200-/ა PDF
      </button>
      <button type="button" id="btn2008a"
              style="padding:8px 12px; background:#21c1a6; color:#fff; border:none; border-radius:4px; font-weight:600;">
        200-8/ა PDF
      </button>
      <button type="button" id="btnConsent"
              style="padding:8px 12px; background:#6c63ff; color:#fff; border:none; border-radius:4px; font-weight:600;">
        თანხმობის ხელწერილი
      </button>
      <button type="button" id="btnContract"
              style="padding:8px 12px; background:#6c63ff; color:#fff; border:none; border-radius:4px; font-weight:600;">
        ხელშეკრულება
      </button>

      <div style="text-align:right;margin-top:20px">
        <button id="gvber" class="rgpap" type="button">მიმართვების შენახვა</button>
      </div>
    </fieldset>

    <div style="margin-top:20px;text-align:right">
      <a href="javascript:void(0);" style="margin-right:20px;" class="rgpap">გადახდა ≫</a>
      <a href="javascript:void(0);" class="rgpap bxclsF" onclick="closeAfterSaveModal()"></a>
    </div>
  </div>`;

  // mount UI
  document.getElementById('afterSaveContent').innerHTML = html;
  document.getElementById('afterSaveModal').style.display = 'flex';

  // ===== PDF / doc buttons =====
  const pid = (document.getElementById('hd_reg')?.value || String(p?.id || '')).trim();

  const btn200 = document.getElementById('btn200');
  if (btn200) btn200.addEventListener('click', () => {
    if (!/^\d+$/.test(pid)) return alert('პაციენტის ID ვერ მოიძებნა');
    window.open('dashboard.php?action=generate_200a&patient_id=' + encodeURIComponent(pid), '_blank');
  });

  const btn2008a = document.getElementById('btn2008a');
  if (btn2008a) btn2008a.addEventListener('click', () => {
    if (!/^\d+$/.test(pid)) return alert('პაციენტის ID ვერ მოიძებნა');
    const did = document.getElementById('selected_doctor')?.value || '';
    window.open('dashboard.php?action=generate_200_8a&patient_id=' + encodeURIComponent(pid) + '&doctor_id=' + encodeURIComponent(did), '_blank');
  });

  const btnContract = document.getElementById('btnContract');
  if (btnContract) btnContract.addEventListener('click', () => {
    if (!/^\d+$/.test(pid)) return alert('პაციენტის ID ვერ მოიძებნა');
    window.open('dashboard.php?action=generate_contract&patient_id=' + encodeURIComponent(pid), '_blank');
  });

  const btnConsent = document.getElementById('btnConsent');
  if (btnConsent) btnConsent.addEventListener('click', () => {
    if (!/^\d+$/.test(pid)) return alert('პაციენტის ID ვერ მოიძებნა');
    window.open('dashboard.php?action=generate_consent&patient_id=' + encodeURIComponent(pid), '_blank');
  });
document.querySelectorAll('.open-card-btn').forEach(btn => {
  const pid = btn.dataset.pid;

  const markAdded = () => {
    btn.textContent = 'დამატებულია ✓';
    btn.style.background = '#28a745';
    btn.style.color = '#fff';
    btn.disabled = true;
    btn.setAttribute('aria-disabled', 'true');
  };

  // on load: თუ უკვე აქვს ბარათი ან ლოკალურადაა დამახსოვრებული
  if (btn.dataset.activated === '1' || localStorage.getItem('card:'+pid) === '1') {
    markAdded();
  }

  btn.addEventListener('click', async () => {
    if (btn.disabled) return;
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'მიმდინარეობს...';

    try {
      const resp = await fetch('dashboard.php', {
        credentials: 'same-origin',
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                   'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ action: 'add_or_activate', patient_id: pid })
      });
      const data = await resp.json();
      if (!resp.ok || data.status !== 'ok') throw new Error(data.message || `HTTP ${resp.status}`);
      markAdded();
      btn.dataset.activated = '1';
      localStorage.setItem('card:'+pid, '1');
    } catch (e) {
      alert('შეცდომა: ' + e.message);
      btn.disabled = false;
      btn.textContent = original;
    }
  });
});


  // ===== Donor balance & apply =====
  fetch('dashboard.php', {
        credentials: 'same-origin',
    method:'POST',
    headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action:'get_donor_balance', patient_id: p.id})
  })
  .then(r=>r.json()).then(j=>{
    if(j.status==='ok'){
      document.getElementById('dn_total').textContent = Number(j.total||0).toFixed(2);
      document.getElementById('dn_used').textContent  = Number(j.used ||0).toFixed(2);
      document.getElementById('dn_left').textContent  = Number(j.left ||0).toFixed(2);
    }
  });

  const btnApplyDonor = document.getElementById('btnApplyDonor');
  if (btnApplyDonor) {
    btnApplyDonor.addEventListener('click', ()=>{
      const amt = parseFloatSafe(document.getElementById('dn_amount').value || '0');
      fetch('dashboard.php', {
        credentials: 'same-origin',
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action:'apply_donor', patient_id: p.id, amount: String(amt) })
      })
      .then(r=>r.json())
      .then(j=>{
        if (j.status==='ok') {
          alert('დონორით დაფარულია: ' + Number(j.applied||0).toFixed(2));
        } else {
          alert('შეცდომა: ' + (j.msg || ''));
        }
      })
      .catch(()=>alert('ქსელის შეცდომა'));
    });
  }

  // ===== Default price fill on service change (price editable!) =====
  wirePriceUpdate(document);

  // ===== “+” add selected service (uses editable price) =====
  const plusBtn = document.getElementById('mo_nzinst');
  if (plusBtn) plusBtn.addEventListener('click', addSelectedService);

  // ===== Save to backend with edited values =====
  const saveBtn = document.getElementById('gvber');
  if (saveBtn) {
    saveBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      const doctorId = document.getElementById('selected_doctor')?.value || '';
      if (!doctorId) return alert('აირჩიეთ ექიმი!');
      const rows = document.querySelectorAll('#added-services-table tbody tr');
      if (!rows.length) return alert('სერვისები არჩეული არაა');

      const servicesPayload = Array.from(rows).map(tr => {
        const sid = tr.getAttribute('data-service-id');
        const qty = parseInt((tr.querySelector('.inp-qty')?.value || '1'), 10) || 1;
        const price = parseFloatSafe(tr.querySelector('.inp-price')?.value || '0');
        return {
          service_id: sid,
          quantity:   qty,
          unit_price: price,
          sum:        +(price * qty).toFixed(2)
        };
      });

      try{
        const r = await fetch('dashboard.php', {
        credentials: 'same-origin',
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'save_patient_services',
            patient_id: pid,
            doctor_id:  doctorId,
            services:   servicesPayload
          })
        });
        const j = await r.json();
        if (j.status === 'ok') {
          alert('შენახულია!');
        } else {
          throw new Error(j.msg || 'უცნობი შეცდომა');
        }
      } catch(err){
        alert(err.message || 'ქსელის შეცდომა');
      }
    });
  }

  // ===== Row-level editing (price/qty) & remove =====
  const tbody = document.querySelector('#added-services-table tbody');
  if (tbody) {
    // edit: recalc per-row + total on input change
    tbody.addEventListener('input', (e) => {
      const t = e.target;
      if (!t.classList.contains('inp-price') && !t.classList.contains('inp-qty')) return;
      const tr = t.closest('tr'); if (!tr) return;
      const price = Math.max(0, parseFloatSafe(tr.querySelector('.inp-price')?.value || '0'));
      const qty   = Math.max(1, parseInt(tr.querySelector('.inp-qty')?.value || '1', 10));
      tr.querySelector('.svc-sum').textContent = (price * qty).toFixed(2);
      updateTotalSum();
    });

    // remove
    tbody.addEventListener('click', (e) => {
      if (e.target.classList.contains('remove-service')) {
        e.target.closest('tr')?.remove();
        updateTotalSum();
      }
    });
  }
}

/* ================== helpers (required) ================== */
function closeAfterSaveModal(){ document.getElementById('afterSaveModal').style.display = 'none'; }
function parseFloatSafe(v){ const f = parseFloat(String(v).replace(',', '.')); return isNaN(f) ? 0 : f; }

function updateTotalSum(){
  const tbody = document.querySelector('#added-services-table tbody');
  if (!tbody) return;
  let total = 0;
  tbody.querySelectorAll('tr').forEach(tr => {
    total += parseFloatSafe(tr.querySelector('.svc-sum')?.textContent || '0');
  });
  const totalEl = document.getElementById('total-sum');
  if (totalEl) totalEl.textContent = total.toFixed(2);
}

function wirePriceUpdate(context=document){
  const serviceSelect = context.querySelector('#mo_itms');
  const priceInput    = context.querySelector('#mo_fas');
  if (!serviceSelect || !priceInput) return;
  const setDefault = () => {
    const opt = serviceSelect.options[serviceSelect.selectedIndex];
    const def = Number(opt?.getAttribute('data-price')) || 0;
    if (!priceInput.value) priceInput.value = def.toFixed(2); // მხოლოდ მაშინ ჩასვი თუ ცარიელია
  };
  setDefault();
  serviceSelect.addEventListener('change', () => {
    const opt = serviceSelect.options[serviceSelect.selectedIndex];
    const def = Number(opt?.getAttribute('data-price')) || 0;
    priceInput.value = def.toFixed(2); // შეცვლისას ყოველთვის დააყენე default
  });
}

function addSelectedService(){
  const serviceSelect = document.getElementById('mo_itms');
  const qtyInput      = document.getElementById('rg_raod');
  const priceInput    = document.getElementById('mo_fas');
  const tbody         = document.querySelector('#added-services-table tbody');
  if (!serviceSelect || !qtyInput || !priceInput || !tbody) return alert('ელემენტები ვერ მოიძებნა!');

  const opt         = serviceSelect.options[serviceSelect.selectedIndex];
  const serviceId   = opt?.value || '';
  const serviceName = (opt?.textContent || '').trim();
  const unitPrice   = Math.max(0, parseFloatSafe(priceInput.value || (opt?.getAttribute('data-price')||'0')));
  const quantity    = Math.max(1, parseInt(qtyInput.value || '1', 10));
  const sum         = unitPrice * quantity;

  if (!serviceId) return alert('აირჩიეთ სერვისი');

  // Always add a new row (allows different custom prices for the same service)
  const tr = document.createElement('tr');
  tr.setAttribute('data-service-id', serviceId);
  tr.innerHTML = `
    <td>${serviceName}</td>
    <td>
      <input type="number" class="inp-price" step="0.01" min="0"
             value="${unitPrice.toFixed(2)}"
             style="width:120px;padding:6px;text-align:right;">
    </td>
    <td>
      <input type="number" class="inp-qty" step="1" min="1"
             value="${quantity}"
             style="width:80px;padding:6px;text-align:center;">
    </td>
    <td class="svc-sum">${sum.toFixed(2)}</td>
    <td><button type="button" class="remove-service">წაშლა</button></td>
  `;
  tbody.appendChild(tr);

  // reset inputs for next add
  serviceSelect.value = '';
  priceInput.value = '';
  qtyInput.value = '1';
  updateTotalSum();
}
 // Convert Date -> 'YYYY-MM-DD' in local time (no TZ skew)
  const toISO = d => d ? new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,10) : "";

  // ADD
  const addPicker = flatpickr("#add_birthdate_dmy", {
    dateFormat: "d.m.Y",
    maxDate: "today",
    allowInput: true,
    locale: flatpickr.l10ns.ka,
    onChange: (dates) => {
      document.getElementById("add_birthdate_iso").value = toISO(dates[0]);
    },
    onClose: (dates, str, inst) => {
      if (!dates.length) {
        const m = inst.input.value.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
        document.getElementById("add_birthdate_iso").value = m ? `${m[3]}-${m[2]}-${m[1]}` : "";
      }
    }
  });

  // EDIT
  const editPicker = flatpickr("#edit_birthdate_dmy", {
    dateFormat: "d.m.Y",
    maxDate: "today",
    allowInput: true,
    locale: flatpickr.l10ns.ka,
    onChange: (dates) => {
      document.getElementById("edit_birthdate_iso").value = toISO(dates[0]);
    },
    onClose: (dates, str, inst) => {
      if (!dates.length) {f
        const m = inst.input.value.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
        document.getElementById("edit_birthdate_iso").value = m ? `${m[3]}-${m[2]}-${m[1]}` : "";
      }
    }
  });

  // If your openEditModal(p) fills fields, also set the calendar:
  // after you fill inputs inside openEditModal(p):
  // if (p.birthdate) { editPicker.setDate(p.birthdate, true); } // p.birthdate = "YYYY-MM-DD"

  // Optional safety: ensure hidden is set before ADD submit
  document.querySelector('form.panel-body[action=""] [name="action"][value="add_patient"]')?.form
    .addEventListener('submit', (e) => {
      const iso = document.getElementById('add_birthdate_iso').value.trim();
      if (!iso) {
        e.preventDefault();
        alert('შეიყვანეთ დაბადების თარიღი ფორმატით dd.mm.yyyy');
      }
    });

</script>
</body>
</html>