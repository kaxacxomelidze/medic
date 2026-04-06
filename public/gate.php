<?php
// access_admin.php — Page Access Manager (roles, pages, role_pages, user_roles?, user_pages?)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../config/config.php';   // must define $pdo

/* ========= Gate options (you can override these BEFORE including this file) ========= */
if (!defined('RBAC_ALLOW_ADMIN_BYPASS')) define('RBAC_ALLOW_ADMIN_BYPASS', true);  // set to false to test as admin
if (!defined('RBAC_DEBUG')) define('RBAC_DEBUG', false);                            // set true and use ?__acl=1 to see decision debug
if (!defined('RBAC_AUTO_REGISTER')) define('RBAC_AUTO_REGISTER', false);            // auto-add unknown pages to `pages`

// Strict PDO behavior
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* ============================================================
   Helpers
   ============================================================ */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('isAjaxRequest')) {
  function isAjaxRequest(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  }
}
if (!function_exists('tableExists')) {
  function tableExists(PDO $pdo, string $t): bool {
    try {
      $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
      if (!$db) return false;
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
      $st->execute([$db, $t]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('currentUserId')) {
  function currentUserId(): int { return (int)($_SESSION['user_id'] ?? 0); }
}
if (!function_exists('currentUsername')) {
  function currentUsername(PDO $pdo): string {
    if (!empty($_SESSION['username'])) return (string)$_SESSION['username'];
    $uid = currentUserId();
    if ($uid > 0 && tableExists($pdo,'users')) {
      $st = $pdo->prepare("SELECT username FROM users WHERE id=?");
      $st->execute([$uid]);
      $u = $st->fetchColumn();
      if ($u) return (string)$u;
    }
    return '';
  }
}
if (!function_exists('requireLogin')) {
  function requireLogin(): void {
    if (currentUserId() > 0) return;
    if (isAjaxRequest()) {
      http_response_code(401);
      header('X-Content-Type-Options: nosniff');
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'err','msg'=>'unauthorized']); exit;
    }
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: /index.php?next={$next}");
    exit;
  }
}
if (!function_exists('resolveProtectedFile')) {
  function resolveProtectedFile(?string $file = null): string {
    if ($file && $file !== '') return basename($file);
    $p = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    return basename((string)$p);
  }
}

/* ============================================================
   BYPASS + ACCESS (supports single-role and multi-role models)
   ============================================================ */

/**
 * Bypass if RBAC_ALLOW_ADMIN_BYPASS and:
 *  - username 'admin', OR
 *  - users.role_id -> roles.name = 'superadmin', OR
 *  - users.role = 'superadmin' (string), OR
 *  - (if user_roles exists) any role named 'superadmin'
 */
if (!function_exists('userHasBypass')) {
  function userHasBypass(PDO $pdo): bool {
    if (!RBAC_ALLOW_ADMIN_BYPASS) return false;
    $uid = currentUserId(); if ($uid <= 0) return false;

    $uname = strtolower(currentUsername($pdo));
    if ($uname === 'admin') return true;

    if (!tableExists($pdo,'users')) return false;

    // Prefer roles table if present
    if (tableExists($pdo,'roles')) {
      $st = $pdo->prepare("SELECT LOWER(COALESCE(r.name, u.role)) AS rname
                           FROM users u
                           LEFT JOIN roles r ON r.id = u.role_id
                           WHERE u.id = ? LIMIT 1");
      $st->execute([$uid]);
      $rname = strtolower((string)($st->fetchColumn() ?? ''));
      if ($rname === 'superadmin') return true;
    } else {
      $st = $pdo->prepare("SELECT LOWER(role) FROM users WHERE id=? LIMIT 1");
      $st->execute([$uid]);
      $r = strtolower((string)($st->fetchColumn() ?? ''));
      if ($r === 'superadmin') return true;
    }

    if (tableExists($pdo,'user_roles') && tableExists($pdo,'roles')) {
      $st = $pdo->prepare("
        SELECT 1
        FROM user_roles ur
        JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=? AND LOWER(r.name)='superadmin'
        LIMIT 1
      ");
      $st->execute([$uid]);
      if ($st->fetchColumn()) return true;
    }

    return false;
  }
}

/**
 * Access checks via:
 *  - user_pages override (if exists)
 *  - role_pages through user_roles (if exists)
 *  - role_pages through users.role_id (single-role)
 *  - page must exist in `pages.file` (optionally auto-register)
 * Note: NO session caching → changes apply immediately.
 */
if (!function_exists('canAccessPage')) {
  function canAccessPage(PDO $pdo, ?string $file = null, array &$debug = null): bool {
    $uid  = currentUserId();
    $file = resolveProtectedFile($file);
    if ($debug !== null) { $debug['file']=$file; $debug['user_id']=$uid; }

    // If pages table missing/empty → allow
    if (!tableExists($pdo,'pages')) { if ($debug!==null) $debug['reason']='no_pages_table'; return true; }
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM pages")->fetchColumn();
    if ($cnt === 0)         { if ($debug!==null) $debug['reason']='pages_empty';     return true; }

    // Resolve page id; optionally auto-register
    $ps = $pdo->prepare("SELECT id FROM pages WHERE file=? LIMIT 1");
    $ps->execute([$file]);
    $pageId = (int)($ps->fetchColumn() ?: 0);
    if ($pageId <= 0 && RBAC_AUTO_REGISTER) {
      $ins = $pdo->prepare("INSERT INTO pages (file,label) VALUES (?,?)");
      $ins->execute([$file, $file]);
      $pageId = (int)$pdo->lastInsertId();
    }

    if ($debug!==null) $debug['page_id'] = $pageId;

    if ($pageId <= 0) { if ($debug!==null) $debug['reason']='page_not_registered'; return false; }
    if ($uid <= 0)    { if ($debug!==null) $debug['reason']='not_logged_in';       return false; }

    // Per-user override wins
    if (tableExists($pdo,'user_pages')) {
      $us = $pdo->prepare("SELECT allowed FROM user_pages WHERE user_id=? AND page_id=? LIMIT 1");
      $us->execute([$uid,$pageId]);
      $ovr = $us->fetchColumn();
      if ($ovr !== false) {
        if ($debug!==null) { $debug['mode']='user_override'; $debug['allowed']=(int)$ovr; }
        return ((int)$ovr === 1);
      }
    }

    // Role-based via role_pages
    if (tableExists($pdo,'role_pages')) {
      if (tableExists($pdo,'user_roles')) {
        $rs = $pdo->prepare("
          SELECT MAX(rp.allowed) AS allowed_any
          FROM user_roles ur
          JOIN role_pages rp ON rp.role_id=ur.role_id
          WHERE ur.user_id=? AND rp.page_id=?
        ");
        $rs->execute([$uid,$pageId]);
        $allowedAny = $rs->fetchColumn();
        if ($debug!==null) { $debug['mode']='multi_role'; $debug['allowed_any'] = is_null($allowedAny)?null:(int)$allowedAny; }
        return ($allowedAny !== null && (int)$allowedAny === 1);
      } else {
        // Single-role path (your DB: users.role_id)
        $rid = 0;
        if (tableExists($pdo,'users')) {
          $s = $pdo->prepare("SELECT role_id FROM users WHERE id=? LIMIT 1");
          $s->execute([$uid]);
          $rid = (int)($s->fetchColumn() ?: 0);
        }
        if ($debug!==null) $debug['role_id'] = $rid;
        if ($rid > 0) {
          $rs = $pdo->prepare("SELECT allowed FROM role_pages WHERE role_id=? AND page_id=? LIMIT 1");
          $rs->execute([$rid,$pageId]);
          $allow = $rs->fetchColumn();
          if ($debug!==null) { $debug['mode']='single_role'; $debug['allowed'] = ($allow===false?null:(int)$allow); }
          return ($allow !== false && (int)$allow === 1);
        }
      }
    }

    if ($debug!==null) $debug['reason']='no_rule_matches';
    return false;
  }
}

/* ========= expose can_page() and $can for templates ========= */
if (!function_exists('can_page')) {
  function can_page(string $file): bool {
    global $pdo;
    if (!isset($pdo) || !$pdo) return true; // fail-open if misconfigured
    if (userHasBypass($pdo)) return true;
    return canAccessPage($pdo, $file);
  }
}
if (empty($GLOBALS['can']) || !is_callable($GLOBALS['can'])) {
  $GLOBALS['can'] = function(string $file) use ($pdo): bool {
    if (userHasBypass($pdo)) return true;
    return canAccessPage($pdo, $file);
  };
}

if (!function_exists('requirePage')) {
  function requirePage(PDO $pdo, ?string $file = null): void {
    requireLogin();
    if (userHasBypass($pdo)) return;

    // live decision debug
    if (RBAC_DEBUG && isset($_GET['__acl'])) {
      $dbg = [];
      $ok = canAccessPage($pdo, $file, $dbg);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>$ok,'debug'=>$dbg], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
      exit;
    }

    if (canAccessPage($pdo, $file)) return;

    if (isAjaxRequest()) {
      http_response_code(403);
      header('X-Content-Type-Options: nosniff');
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'err','msg'=>'forbidden']); exit;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $f = h(resolveProtectedFile($file));
    echo "<!doctype html><meta charset='utf-8'><title>403 Forbidden</title>
      <div style='font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:32px'>
        <h1 style='margin:0 0 8px'>403 — Access denied</h1>
        <div style='color:#555'>No permission for <code>{$f}</code>.</div>
      </div>";
    exit;
  }
}

/* --------- first-load bootstrap --------- */
(function(PDO $pdo) {
  $thisFile = basename($_SERVER['SCRIPT_NAME'] ?? __FILE__);
  try {
    if (tableExists($pdo,'pages')) {
      $ps = $pdo->prepare("SELECT id FROM pages WHERE file=? LIMIT 1");
      $ps->execute([$thisFile]);
      if (!$ps->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO pages (file,label) VALUES (?,?)");
        $ins->execute([$thisFile, 'Page Access Manager']);
      }
    }
    if (tableExists($pdo,'roles')) {
      $cnt = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
      if ($cnt === 0) {
        $pdo->prepare("INSERT INTO roles (name,label) VALUES ('superadmin','Super Admin')")->execute();
        $rid = (int)$pdo->lastInsertId();
        if (tableExists($pdo,'users')) {
          $uid = (int)($_SESSION['user_id'] ?? 0);
          if ($uid > 0) {
            $pdo->prepare("UPDATE users SET role_id=? , role='superadmin' WHERE id=?")->execute([$rid, $uid]);
          }
        }
      }
    }
  } catch (Throwable $e) {
    error_log('access_admin bootstrap: '.$e->getMessage());
  }
})($pdo);

/* ------------------------ Gate enforcement ------------------------ */
requirePage($pdo);

/* ----------------------------- CSRF ------------------------------ */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
if (!function_exists('csrf_guard_or_die')) {
  function csrf_guard_or_die(): void {
    $tok = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$tok)) {
      http_response_code(419);
      header('X-Content-Type-Options: nosniff');
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'err','msg'=>'csrf_failed']);
      exit;
    }
  }
}

/* ----------------------- JSON helpers -------------------- */
if (!function_exists('clearAclCachesFor')) {
  function clearAclCachesFor(int $userId): void {
    unset(
      $_SESSION["roles_cache_$userId"],
      $_SESSION["page_acl_cache_user_$userId"],
      $_SESSION['page_acl_cache'],
      $_SESSION['roles_cache']
    );
  }
}
function json_ok($data = []): void {
  header('X-Content-Type-Options: nosniff');
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'ok'] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($msg, $code = 400): void {
  http_response_code($code);
  header('X-Content-Type-Options: nosniff');
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'err','msg'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* --------------------------- AJAX actions ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  csrf_guard_or_die();
  $action = (string)$_POST['action'];

  try {
    // ---- Roles CRUD ----
    if ($action === 'add_role') {
      if (!tableExists($pdo,'roles')) json_err('roles table missing', 500);
      $name = trim($_POST['name'] ?? '');
      if ($name === '') json_err('Role name required');
      // Safer for schemas where roles.label is NOT NULL
      $st = $pdo->prepare("INSERT INTO roles (name,label) VALUES (?, '')");
      $st->execute([$name]);
      json_ok(['id'=>(int)$pdo->lastInsertId(), 'name'=>$name]);
    }

    if ($action === 'rename_role') {
      if (!tableExists($pdo,'roles')) json_err('roles table missing', 500);
      $id   = (int)$_POST['id'];
      $name = trim($_POST['name'] ?? '');
      if (!$id || $name==='') json_err('Invalid data');
      $st = $pdo->prepare("UPDATE roles SET name=? WHERE id=?");
      $st->execute([$name, $id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    if ($action === 'delete_role') {
      if (!tableExists($pdo,'roles')) json_err('roles table missing', 500);
      $id = (int)$_POST['id'];
      if (!$id) json_err('Invalid role id');

      // Prevent delete if in use by any user (single-role model)
      if (tableExists($pdo,'users')) {
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id=?");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) json_err('Role is assigned to users; reassign first');
      }

      if (tableExists($pdo,'role_pages')) {
        $pdo->prepare("DELETE FROM role_pages WHERE role_id=?")->execute([$id]);
      }
      $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    // ---- Pages CRUD ----
    if ($action === 'add_page') {
      if (!tableExists($pdo,'pages')) json_err('pages table missing', 500);
      $file  = trim($_POST['file'] ?? '');
      $label = trim($_POST['label'] ?? '');
      if ($file==='') json_err('File is required');
      if ($label==='') $label = $file;
      $st = $pdo->prepare("INSERT INTO pages (file,label) VALUES (?,?)");
      $st->execute([$file, $label]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok(['id'=>(int)$pdo->lastInsertId(), 'file'=>$file, 'label'=>$label]);
    }

    if ($action === 'edit_page') {
      if (!tableExists($pdo,'pages')) json_err('pages table missing', 500);
      $id    = (int)$_POST['id'];
      $file  = trim($_POST['file'] ?? '');
      $label = trim($_POST['label'] ?? '');
      if (!$id || $file==='') json_err('Invalid data');
      if ($label==='') $label = $file;
      $st = $pdo->prepare("UPDATE pages SET file=?, label=? WHERE id=?");
      $st->execute([$file, $label, $id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    if ($action === 'delete_page') {
      if (!tableExists($pdo,'pages')) json_err('pages table missing', 500);
      $id = (int)$_POST['id'];
      if (!$id) json_err('Invalid page id');
      if (tableExists($pdo,'role_pages')) { $pdo->prepare("DELETE FROM role_pages WHERE page_id=?")->execute([$id]); }
      if (tableExists($pdo,'user_pages')) { $pdo->prepare("DELETE FROM user_pages WHERE page_id=?")->execute([$id]); }
      $pdo->prepare("DELETE FROM pages WHERE id=?")->execute([$id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    // ---- Role ↔ Page (matrix) ----
    if ($action === 'toggle_role_page') {
      if (!tableExists($pdo,'role_pages')) json_err('role_pages table missing', 500);
      $roleId = (int)$_POST['role_id'];
      $pageId = (int)$_POST['page_id'];
      $allow  = (int)($_POST['allowed'] ?? 0);
      if (!$roleId || !$pageId) json_err('Invalid ids');

      // Works with PRIMARY KEY or UNIQUE(role_id,page_id)
      $st = $pdo->prepare("
        INSERT INTO role_pages (role_id, page_id, allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)
      ");
      $st->execute([$roleId, $pageId, $allow ? 1 : 0]);

      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    // ---- Users (works with or without user_roles) ----
    if ($action === 'load_user') {
      $uid = (int)$_POST['user_id'];
      if (!$uid) json_err('Invalid user id');

      $userRoles = [];

      if (tableExists($pdo,'user_roles') && tableExists($pdo,'roles')) {
        $r = $pdo->prepare("SELECT r.id, r.name
                            FROM user_roles ur
                            JOIN roles r ON r.id=ur.role_id
                            WHERE ur.user_id=?
                            ORDER BY r.name");
        $r->execute([$uid]);
        $userRoles = $r->fetchAll(PDO::FETCH_ASSOC);
      } elseif (tableExists($pdo,'users') && tableExists($pdo,'roles')) {
        // Single-role: map users.role_id to one role
        $r = $pdo->prepare("SELECT r.id, r.name
                            FROM users u
                            LEFT JOIN roles r ON r.id=u.role_id
                            WHERE u.id=?");
        $r->execute([$uid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) $userRoles = [ $row ];
      }

      $overrides = [];
      if (tableExists($pdo,'user_pages') && tableExists($pdo,'pages')) {
        $o = $pdo->prepare("SELECT p.id AS page_id, p.file, COALESCE(p.label,p.file) AS label, up.allowed
                            FROM user_pages up
                            JOIN pages p ON p.id=up.page_id
                            WHERE up.user_id=?");
        $o->execute([$uid]);
        $overrides = $o->fetchAll(PDO::FETCH_ASSOC);
      }

      json_ok(['user_roles'=>$userRoles, 'overrides'=>$overrides]);
    }

    if ($action === 'set_user_roles') {
      $uid = (int)($_POST['user_id'] ?? 0);
      if (!$uid) json_err('Invalid user id');
      $ids = array_filter(array_map('intval', explode(',', (string)$_POST['role_ids'] ?? '')));

      if (tableExists($pdo,'user_roles')) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$uid]);
        if ($ids) {
          $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)");
          foreach ($ids as $rid) { $st->execute([$uid, $rid]); }
        }
        $pdo->commit();
      } elseif (tableExists($pdo,'users')) {
        // Single-role: take the first selected role id (or NULL)
        $rid = $ids ? (int)$ids[0] : null;
        $st = $pdo->prepare("UPDATE users SET role_id=? WHERE id=?");
        $st->execute([$rid, $uid]);
      }

      clearAclCachesFor($uid);
      json_ok();
    }

    if ($action === 'set_user_page_override') {
      if (!tableExists($pdo,'user_pages')) json_err('user_pages table missing', 500);
      $uid    = (int)($_POST['user_id'] ?? 0);
      $pageId = (int)($_POST['page_id'] ?? 0);
      $mode   = (string)($_POST['mode'] ?? 'inherit'); // inherit|allow|deny
      if (!$uid || !$pageId) json_err('Invalid ids');

      if ($mode === 'inherit') {
        $pdo->prepare("DELETE FROM user_pages WHERE user_id=? AND page_id=?")->execute([$uid, $pageId]);
      } else {
        $allowed = ($mode === 'allow') ? 1 : 0;
        $st = $pdo->prepare("
          INSERT INTO user_pages (user_id, page_id, allowed)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)
        ");
        $st->execute([$uid, $pageId, $allowed]);
      }
      clearAclCachesFor($uid);
      json_ok();
    }

    // ---- Optional: quick register files into pages ----
    if ($action === 'scan_register_files') {
      if (!tableExists($pdo,'pages')) json_err('pages table missing', 500);
      $files = array_filter(array_map('trim', explode(',', (string)($_POST['files'] ?? ''))));
      if (!$files) json_err('No files provided');
      $ins = $pdo->prepare("INSERT IGNORE INTO pages (file,label) VALUES (?,?)");
      foreach ($files as $f) { $ins->execute([$f, $f]); }
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok(['added'=>count($files)]);
    }

    json_err('Unknown action', 400);
  } catch (Throwable $e) {
    error_log('access_admin ajax: '.$e->getMessage());
    json_err('Server error', 500);
  }
}

/* ------------------------- Page render (GET) --------------------- */
$roles  = tableExists($pdo,'roles')  ? $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$pages  = tableExists($pdo,'pages')  ? $pdo->query("SELECT id, file, COALESCE(label,file) AS label FROM pages ORDER BY label")->fetchAll(PDO::FETCH_ASSOC) : [];
$users  = tableExists($pdo,'users')  ? $pdo->query("SELECT id, username, role_id FROM users ORDER BY username LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) : [];
$rpRows = (tableExists($pdo,'role_pages') && $roles && $pages)
  ? $pdo->query("SELECT role_id, page_id, COALESCE(allowed,0) AS allowed FROM role_pages")->fetchAll(PDO::FETCH_ASSOC)
  : [];

$matrix = []; // [page_id][role_id] = allowed
foreach ($rpRows as $r) { $matrix[(int)$r['page_id']][(int)$r['role_id']] = (int)$r['allowed']; }

?>
<!doctype html>
<html lang="ka">
<head>
  <meta charset="utf-8">
  <title>Page Access Manager</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?=h($_SESSION['csrf'])?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,"Noto Sans Georgian",Arial,sans-serif;background:#f6f7fb;color:#1b1f23;margin:0}
    .wrap{max-width:1200px;margin:28px auto;padding:0 20px}
    h1{margin:0 0 18px 0;font-size:22px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 2px 8px rgba(15,23,42,.04)}
    .panel .hd{padding:12px 16px;border-bottom:1px solid #edf2f7;font-weight:700;display:flex;align-items:center;justify-content:space-between}
    .panel .bd{padding:14px 16px}
    label{font-size:14px;color:#374151}
    input[type=text], select{padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;width:100%}
    button{background:#21c1a6;color:#fff;border:0;border-radius:8px;padding:8px 12px;font-weight:600;cursor:pointer}
    button.secondary{background:#e2e8f0;color:#111827}
    button:disabled{opacity:.6;cursor:not-allowed}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #eef2f7;padding:8px 10px;text-align:left}
    th{background:#f8fafc;font-weight:700;font-size:13px}
    .muted{color:#64748b}
    .flex{display:flex;gap:8px;align-items:center}
    .row{display:flex;gap:12px;margin:8px 0}
    .tag{display:inline-block;background:#eef2ff;color:#334155;padding:2px 8px;border-radius:999px;font-size:12px}
    .matrix{overflow:auto;border:1px solid #e2e8f0;border-radius:8px}
    .matrix table{min-width:700px}
    .sticky{position:sticky;left:0;background:#fff}
    .help{font-size:12px;color:#6b7280}
    .pill{border:1px solid #cbd5e1;border-radius:999px;padding:6px 10px}
    .tri label{margin-right:10px}
    .small{font-size:12px}
  </style>
  <link rel="stylesheet" href="css/preclinic-theme.css">
</head>
<body>
  <div class="wrap">
    <h1><i class="fa-solid fa-lock"></i> Page Access Manager</h1>
    <div class="grid">
      <!-- ROLES -->
      <div class="panel">
        <div class="hd">Roles</div>
        <div class="bd">
          <div class="row">
            <input type="text" id="role_name" placeholder="New role name">
            <button id="btnAddRole">Add</button>
          </div>
          <table>
            <thead><tr><th>Role</th><th width="140">Actions</th></tr></thead>
            <tbody id="rolesBody">
              <?php foreach ($roles as $r): ?>
                <tr data-role-id="<?=$r['id']?>">
                  <td><input type="text" class="pill role-edit" value="<?=h($r['name'])?>" data-id="<?=$r['id']?>"></td>
                  <td>
                    <button class="secondary btnRenameRole" data-id="<?=$r['id']?>">Rename</button>
                    <button class="secondary btnDelRole" data-id="<?=$r['id']?>">Delete</button>
                  </td>
                </tr>
              <?php endforeach; if (!$roles): ?>
                <tr><td colspan="2" class="muted">No roles yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- PAGES -->
      <div class="panel">
        <div class="hd">Pages</div>
        <div class="bd">
          <div class="row">
            <input type="text" id="page_file" placeholder="dashboard.php">
            <input type="text" id="page_label" placeholder="Label (optional)">
            <button id="btnAddPage">Add</button>
          </div>
          <div class="row small help">
            Tip: you can paste a comma-separated list (e.g., <code>dashboard.php, patient_hstory.php, nomenklatura.php</code>) using the “Scan & Register” button below.
          </div>
          <div class="row">
            <input type="text" id="scan_files" placeholder="file1.php, file2.php, file3.php">
            <button class="secondary" id="btnScan">Scan & Register</button>
          </div>
          <table>
            <thead><tr><th>File</th><th>Label</th><th width="160">Actions</th></tr></thead>
            <tbody id="pagesBody">
              <?php foreach ($pages as $p): ?>
                <tr data-page-id="<?=$p['id']?>">
                  <td><input class="pill page-file"  data-id="<?=$p['id']?>" value="<?=h($p['file'])?>"></td>
                  <td><input class="pill page-label" data-id="<?=$p['id']?>" value="<?=h($p['label'])?>"></td>
                  <td>
                    <button class="secondary btnEditPage" data-id="<?=$p['id']?>">Save</button>
                    <button class="secondary btnDelPage"  data-id="<?=$p['id']?>">Delete</button>
                  </td>
                </tr>
              <?php endforeach; if (!$pages): ?>
                <tr><td colspan="3" class="muted">No pages yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MATRIX -->
    <div class="panel" style="margin-top:16px;">
      <div class="hd">Role → Page access matrix</div>
      <div class="bd matrix">
        <table>
          <thead>
            <tr>
              <th class="sticky">Page</th>
              <?php foreach ($roles as $r): ?>
                <th><?=h($r['name'])?></th>
              <?php endforeach; if (!$roles): ?>
                <th class="muted">No roles</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pages as $p): ?>
              <tr data-page-id="<?=$p['id']?>">
                <td class="sticky"><span class="tag"><?=h($p['file'])?></span> &nbsp; <?=h($p['label'])?></td>
                <?php foreach ($roles as $r):
                  $checked = !empty($matrix[(int)$p['id']][(int)$r['id']]);
                ?>
                  <td>
                    <input type="checkbox"
                           class="rpToggle"
                           data-page-id="<?=$p['id']?>"
                           data-role-id="<?=$r['id']?>"
                           <?= $checked ? 'checked' : '' ?>>
                  </td>
                <?php endforeach; if (!$roles): ?>
                  <td class="muted small">Create roles first</td>
                <?php endif; ?>
              </tr>
            <?php endforeach; if (!$pages): ?>
              <tr><td colspan="<?=max(count($roles),1)+1?>" class="muted">Add pages to build the matrix</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- USER MANAGEMENT -->
    <div class="panel" style="margin-top:16px;">
      <div class="hd">Users — roles and per-page overrides</div>
      <div class="bd">
        <div class="row">
          <select id="userSelect">
            <option value="">Select user…</option>
            <?php foreach ($users as $u): ?>
              <option value="<?=$u['id']?>"><?=h($u['username'])?></option>
            <?php endforeach; ?>
          </select>
          <span class="help">Shows first 200 users. To manage others, search server-side or adjust LIMIT.</span>
        </div>

        <div id="userArea" style="display:none;">
          <h3 style="margin:12px 0 8px;">Roles</h3>
          <div id="userRoles" class="row"></div>
          <div class="row"><button id="btnSaveUserRoles">Save user roles</button></div>

          <h3 style="margin:18px 0 8px;">Per-page overrides <span class="help">(inherit from roles by default)</span></h3>
          <div class="matrix" id="overridesBox" style="padding:8px;">
            <table>
              <thead><tr><th class="sticky">Page</th><th>Mode</th></tr></thead>
              <tbody id="ovrBody">
                <?php foreach ($pages as $p): ?>
                  <tr data-page-id="<?=$p['id']?>">
                    <td class="sticky"><span class="tag"><?=h($p['file'])?></span> &nbsp; <?=h($p['label'])?></td>
                    <td class="tri">
                      <label><input type="radio" name="ovr_<?=$p['id']?>" value="inherit" checked> inherit</label>
                      <label><input type="radio" name="ovr_<?=$p['id']?>" value="allow"> allow</label>
                      <label><input type="radio" name="ovr_<?=$p['id']?>" value="deny"> deny</label>
                    </td>
                  </tr>
                <?php endforeach; if (!$pages): ?>
                  <tr><td colspan="2" class="muted">No pages</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="help" style="margin-top:8px;">Overrides save immediately when you click a radio option.</div>
        </div>
      </div>
    </div>

    <div class="help" style="margin-top:12px;">
      <b>Notes:</b> Username <code>admin</code> and role <code>superadmin</code> bypass checks (unless you set <code>RBAC_ALLOW_ADMIN_BYPASS=false</code>).
      Keep file names in “pages” exactly as the PHP files. For live decision info, enable <code>RBAC_DEBUG</code> and open this page with <code>?__acl=1</code>.
    </div>
  </div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function post(action, data={}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k,v] of Object.entries(data)) fd.append(k, v);
  return fetch(location.pathname, {
      method:'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(async r => {
      const ct = r.headers.get('Content-Type') || '';
      if (!ct.includes('application/json')) {
        const txt = await r.text();
        throw new Error(txt.slice(0,300) || ('HTTP '+r.status));
      }
      return r.json();
    });
}

// ---- Roles ----
document.getElementById('btnAddRole')?.addEventListener('click', () => {
  const name = document.getElementById('role_name').value.trim();
  if (!name) return;
  post('add_role', {name}).then(j=>{
    if (j.status==='ok') location.reload();
    else alert(j.msg||'Error');
  }).catch(e=>alert(e.message||e));
});
document.querySelectorAll('.btnRenameRole').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    const input = document.querySelector('.role-edit[data-id="'+id+'"]');
    post('rename_role', {id, name: input.value.trim()})
      .then(j=>{ if (j.status==='ok') location.reload(); else alert(j.msg||'Error'); })
      .catch(e=>alert(e.message||e));
  });
});
document.querySelectorAll('.btnDelRole').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    if (!confirm('Delete role? Users must be reassigned first.')) return;
    post('delete_role', {id: btn.dataset.id})
      .then(j=>{ if (j.status==='ok') location.reload(); else alert(j.msg||'Error'); })
      .catch(e=>alert(e.message||e));
  });
});

// ---- Pages ----
document.getElementById('btnAddPage')?.addEventListener('click', ()=>{
  const file  = document.getElementById('page_file').value.trim();
  const label = document.getElementById('page_label').value.trim();
  if (!file) return;
  post('add_page', {file, label}).then(j=>{
    if (j.status==='ok') location.reload();
    else alert(j.msg||'Error');
  }).catch(e=>alert(e.message||e));
});
document.querySelectorAll('.btnEditPage').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id    = btn.dataset.id;
    const file  = document.querySelector('.page-file[data-id="'+id+'"]').value.trim();
    const label = document.querySelector('.page-label[data-id="'+id+'"]').value.trim();
    if (!file) { alert('File required'); return; }
    post('edit_page', {id, file, label}).then(j=>{
      if (j.status==='ok') location.reload();
      else alert(j.msg||'Error');
    }).catch(e=>alert(e.message||e));
  });
});
document.querySelectorAll('.btnDelPage').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    if (!confirm('Delete page and related mappings?')) return;
    post('delete_page', {id: btn.dataset.id}).then(j=>{
      if (j.status==='ok') location.reload();
      else alert(j.msg||'Error');
    }).catch(e=>alert(e.message||e));
  });
});
document.getElementById('btnScan')?.addEventListener('click', ()=>{
  const files = document.getElementById('scan_files').value.trim();
  if (!files) return;
  post('scan_register_files', {files}).then(j=>{
    if (j.status==='ok') location.reload();
    else alert(j.msg||'Error');
  }).catch(e=>alert(e.message||e));
});

// ---- Matrix toggles ----
document.querySelectorAll('.rpToggle').forEach(ch=>{
  ch.addEventListener('change', ()=>{
    const role_id = ch.dataset.roleId;
    const page_id = ch.dataset.pageId;
    const allowed = ch.checked ? 1 : 0;
    post('toggle_role_page', {role_id, page_id, allowed}).then(j=>{
      if (j.status!=='ok') { alert(j.msg||'Error'); ch.checked = !ch.checked; }
    }).catch(e=>{ alert(e.message||e); ch.checked = !ch.checked; });
  });
});

// ---- Users ----
const userSelect = document.getElementById('userSelect');
const userArea   = document.getElementById('userArea');
const userRoles  = document.getElementById('userRoles');
const btnSaveUR  = document.getElementById('btnSaveUserRoles');

let currentUserId = null;

userSelect?.addEventListener('change', ()=>{
  currentUserId = userSelect.value || null;
  if (!currentUserId) { userArea.style.display='none'; return; }
  post('load_user', {user_id: currentUserId}).then(j=>{
    if (j.status!=='ok') { alert(j.msg||'Error'); return; }
    userArea.style.display='block';

    // Build roles checkboxes (single-role: only one will be checked)
    userRoles.innerHTML = '';
    <?php
      $ROLES_JSON = json_encode($roles, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
      echo "const ALL_ROLES = $ROLES_JSON;\n";
    ?>
    const userRoleIds = new Set((j.user_roles||[]).map(r=>String(r.id)));
    ALL_ROLES.forEach(r=>{
      const w = document.createElement('label');
      w.className = 'pill';
      const cb = document.createElement('input');
      cb.type='checkbox'; cb.value = r.id; cb.checked = userRoleIds.has(String(r.id));
      w.appendChild(cb);
      w.append(' '+r.name);
      userRoles.appendChild(w);
    });

    // Reset all overrides to inherit
    document.querySelectorAll('#ovrBody input[type=radio][value=inherit]').forEach(r=>r.checked=true);
    // Apply overrides from server
    (j.overrides||[]).forEach(o=>{
      const name = 'ovr_'+o.page_id;
      const val  = (String(o.allowed)==='1') ? 'allow' : 'deny';
      const input = document.querySelector('input[name="'+name+'"][value="'+val+'"]');
      if (input) input.checked = true;
    });
  }).catch(e=>alert(e.message||e));
});

btnSaveUR?.addEventListener('click', ()=>{
  if (!currentUserId) return;
  const ids = Array.from(userRoles.querySelectorAll('input[type=checkbox]:checked')).map(cb=>cb.value);
  post('set_user_roles', {user_id: currentUserId, role_ids: ids.join(',')}).then(j=>{
    if (j.status==='ok') { alert('Saved'); } else { alert(j.msg||'Error'); }
  }).catch(e=>alert(e.message||e));
});

// per-page override autosave
document.querySelectorAll('#ovrBody input[type=radio]').forEach(r=>{
  r.addEventListener('change', ()=>{
    if (!currentUserId) return;
    const tr = r.closest('tr');
    const page_id = tr.dataset.pageId;
    const mode = r.value; // inherit|allow|deny
    post('set_user_page_override', {user_id: currentUserId, page_id, mode}).then(j=>{
      if (j.status!=='ok') alert(j.msg||'Error');
    }).catch(e=>alert(e.message||e));
  });
});
</script>
</body>
</html>
