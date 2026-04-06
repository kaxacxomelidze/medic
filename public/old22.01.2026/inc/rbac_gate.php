<?php
/**
 * rbac_gate.php — Minimal RBAC “gate”
 * - Expects $pdo to be defined by the caller (your page)
 * - Blocks access unless user is logged-in and has permission to the current PHP file
 * - Works with single-role (users.role_id) and optional multi-role (user_roles)
 *
 * Optional defines you can set BEFORE including this file on your page:
 *   define('RBAC_ALLOW_ADMIN_BYPASS', true);  // admin/superadmin always allowed
 *   define('RBAC_DEBUG', false);              // true + ?__acl=1 prints decision JSON
 *   define('RBAC_AUTO_REGISTER', false);      // if true: first visit to unknown page auto-adds to `pages`
 *   define('RBAC_LOGIN_URL', '/index.php');   // where to send non-authenticated users
 */

if (!defined('RBAC_ALLOW_ADMIN_BYPASS')) define('RBAC_ALLOW_ADMIN_BYPASS', true);
if (!defined('RBAC_DEBUG'))               define('RBAC_DEBUG', false);
if (!defined('RBAC_AUTO_REGISTER'))       define('RBAC_AUTO_REGISTER', false);
if (!defined('RBAC_LOGIN_URL'))           define('RBAC_LOGIN_URL', '/index.php');

/* ---------- helpers ---------- */
if (!function_exists('rbac_is_ajax')) {
  function rbac_is_ajax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  }
}
if (!function_exists('rbac_table_exists')) {
  function rbac_table_exists(PDO $pdo, string $t): bool {
    try {
      $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
      if (!$db) return false;
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
      $st->execute([$db, $t]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('rbac_current_user_id')) {
  function rbac_current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
}
if (!function_exists('rbac_current_username')) {
  function rbac_current_username(PDO $pdo): string {
    if (!empty($_SESSION['username'])) return (string)$_SESSION['username'];
    $uid = rbac_current_user_id();
    if ($uid > 0 && rbac_table_exists($pdo,'users')) {
      $st = $pdo->prepare("SELECT username FROM users WHERE id=?");
      $st->execute([$uid]); $u = $st->fetchColumn();
      if ($u) return (string)$u;
    }
    return '';
  }
}
if (!function_exists('rbac_resolve_file')) {
  function rbac_resolve_file(?string $file=null): string {
    if ($file && $file !== '') return basename($file);
    $p = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    return basename((string)$p);
  }
}

/* ---------- auth redirect if not logged in ---------- */
if (!function_exists('rbac_require_login')) {
  function rbac_require_login(): void {
    if (rbac_current_user_id() > 0) return;
    if (rbac_is_ajax()) {
      http_response_code(401);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'err', 'msg'=>'unauthorized']); exit;
    }
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: ".RBAC_LOGIN_URL."?next={$next}");
    exit;
  }
}

/* ---------- bypass logic ---------- */
if (!function_exists('rbac_has_bypass')) {
  function rbac_has_bypass(PDO $pdo): bool {
    if (!RBAC_ALLOW_ADMIN_BYPASS) return false;
    $uid = rbac_current_user_id(); if ($uid <= 0) return false;

    $uname = strtolower(rbac_current_username($pdo));
    if ($uname === 'admin') return true;

    if (!rbac_table_exists($pdo,'users')) return false;

    // single-role via users.role / users.role_id
    if (rbac_table_exists($pdo,'roles')) {
      $st = $pdo->prepare("SELECT LOWER(COALESCE(r.name, u.role)) AS rname
                           FROM users u LEFT JOIN roles r ON r.id=u.role_id
                           WHERE u.id=? LIMIT 1");
      $st->execute([$uid]);
      $role = strtolower((string)($st->fetchColumn() ?? ''));
      if ($role === 'superadmin') return true;
    } else {
      $st = $pdo->prepare("SELECT LOWER(role) FROM users WHERE id=?");
      $st->execute([$uid]);
      if ((string)$st->fetchColumn() === 'superadmin') return true;
    }

    // multi-role via user_roles (optional)
    if (rbac_table_exists($pdo,'user_roles') && rbac_table_exists($pdo,'roles')) {
      $st = $pdo->prepare("SELECT 1 FROM user_roles ur
                           JOIN roles r ON r.id=ur.role_id
                           WHERE ur.user_id=? AND LOWER(r.name)='superadmin' LIMIT 1");
      $st->execute([$uid]);
      if ($st->fetchColumn()) return true;
    }
    return false;
  }
}

/* ---------- core decision ---------- */
if (!function_exists('rbac_can_access')) {
  function rbac_can_access(PDO $pdo, ?string $file=null, array &$debug=null): bool {
    $uid  = rbac_current_user_id();
    $file = rbac_resolve_file($file);

    $debug = [
      'file' => $file,
      'uid'  => $uid,
      'bypass' => false,
      'page_id' => null,
      'role_id' => null,
      'via'     => null,
      'result'  => null,
    ];

    // no pages table → allow everything (nothing to protect)
    if (!rbac_table_exists($pdo,'pages')) { $debug['result']='allow:no_pages_table'; return true; }

    // find page id (optionally auto-register)
    $ps = $pdo->prepare("SELECT id FROM pages WHERE file=? LIMIT 1");
    $ps->execute([$file]);
    $pageId = (int)($ps->fetchColumn() ?: 0);

    if ($pageId <= 0 && RBAC_AUTO_REGISTER) {
      $ins = $pdo->prepare("INSERT IGNORE INTO pages (file,label) VALUES (?,?)");
      $ins->execute([$file, $file]);
      $ps->execute([$file]);
      $pageId = (int)($ps->fetchColumn() ?: 0);
    }
    $debug['page_id'] = $pageId;

    if ($pageId <= 0) { $debug['result']='deny:page_not_registered'; return false; }
    if ($uid <= 0)    { $debug['result']='deny:not_logged_in';        return false; }

    // bypass?
    if (rbac_has_bypass($pdo)) { $debug['bypass']=true; $debug['result']='allow:bypass'; return true; }

    // per-user override wins (optional)
    if (rbac_table_exists($pdo,'user_pages')) {
      $st = $pdo->prepare("SELECT allowed FROM user_pages WHERE user_id=? AND page_id=? LIMIT 1");
      $st->execute([$uid, $pageId]);
      $ovr = $st->fetchColumn();
      if ($ovr !== false) {
        $debug['via']='user_pages';
        $debug['result'] = ((int)$ovr === 1) ? 'allow:user_override' : 'deny:user_override';
        return ((int)$ovr === 1);
      }
    }

    // via role_pages + user_roles (multi-role)
    if (rbac_table_exists($pdo,'user_roles') && rbac_table_exists($pdo,'role_pages')) {
      $rs = $pdo->prepare("
        SELECT MAX(rp.allowed) AS allowed_any
        FROM user_roles ur
        JOIN role_pages rp ON rp.role_id=ur.role_id
        WHERE ur.user_id=? AND rp.page_id=?
      ");
      $rs->execute([$uid, $pageId]);
      $allowedAny = $rs->fetchColumn();
      if ($allowedAny !== null) {
        $debug['via']='user_roles';
        $debug['result'] = ((int)$allowedAny === 1) ? 'allow:user_roles' : 'deny:user_roles';
        return ((int)$allowedAny === 1);
      }
    }

    // single-role path: users.role_id + role_pages
    if (rbac_table_exists($pdo,'users') && rbac_table_exists($pdo,'role_pages')) {
      $s = $pdo->prepare("SELECT role_id FROM users WHERE id=? LIMIT 1");
      $s->execute([$uid]); $rid = (int)($s->fetchColumn() ?: 0);
      $debug['role_id'] = $rid;
      if ($rid > 0) {
        $rs = $pdo->prepare("SELECT allowed FROM role_pages WHERE role_id=? AND page_id=? LIMIT 1");
        $rs->execute([$rid,$pageId]);
        $allow = $rs->fetchColumn();
        if ($allow !== false) {
          $debug['via']='users.role_id';
          $debug['result'] = ((int)$allow === 1) ? 'allow:role' : 'deny:role';
          return ((int)$allow === 1);
        }
      }
    }

    $debug['result']='deny:default';
    return false;
  }
}

/* ---------- public API ---------- */
if (!function_exists('requirePage')) {
  function requirePage(PDO $pdo, ?string $file=null): void {
    rbac_require_login();
    $dbg = [];
    $ok = rbac_can_access($pdo, $file, $dbg);

    // debug JSON if enabled and requested
    if (RBAC_DEBUG && isset($_GET['__acl'])) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode($dbg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit;
    }

    if ($ok) return;

    if (rbac_is_ajax()) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'err','msg'=>'forbidden']); exit;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $f = htmlspecialchars(rbac_resolve_file($file), ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta charset='utf-8'><title>403 Forbidden</title>
      <div style='font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:32px'>
        <h1 style='margin:0 0 8px'>403 — Access denied</h1>
        <div style='color:#555'>No permission for <code>{$f}</code>.</div>
      </div>";
    exit;
  }
}

if (!function_exists('can_page')) {
  function can_page(string $file): bool {
    global $pdo;
    if (!isset($pdo) || !$pdo) return true; // fail-open if no PDO
    if (rbac_has_bypass($pdo)) return true;
    $dbg = []; return rbac_can_access($pdo, $file, $dbg);
  }
}
if (empty($GLOBALS['can']) || !is_callable($GLOBALS['can'])) {
  $GLOBALS['can'] = function(string $file) use ($pdo): bool {
    if (!isset($pdo) || !$pdo) return true;
    if (rbac_has_bypass($pdo)) return true;
    $dbg=[]; return rbac_can_access($pdo, $file, $dbg);
  };
}
