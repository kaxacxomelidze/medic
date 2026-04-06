<?php
// access_admin.php — Page Access Manager (roles, pages, role_pages, user_roles, user_pages)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../config/config.php';   // must define $pdo (PDO::ERRMODE_EXCEPTION)
require_once __DIR__ . '/gate.php';               // RBAC helpers (requirePage, etc.)

// ---- Require login + permission to open this page ----
requirePage($pdo);

// ---- CSRF ----
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
if (!function_exists('csrf_guard_or_die')) {
  function csrf_guard_or_die(): void {
    $tok = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$tok)) {
      http_response_code(419);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['status'=>'err','msg'=>'csrf_failed']);
      exit;
    }
  }
}

// ---- Small helpers ----
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tableExists')) {
  function tableExists(PDO $pdo, string $t): bool {
    try {
      $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
      if (!$db) return false;
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
      $st->execute([$db, $t]);
      return (bool)$st->fetchColumn();
    } catch(Throwable $e) { return false; }
  }
}
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
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'ok'] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($msg, $code = 400): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'err','msg'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

// ----------------- AJAX actions -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  csrf_guard_or_die();
  $action = (string)$_POST['action'];

  try {
    // ---- Roles CRUD ----
    if ($action === 'add_role') {
      $name = trim($_POST['name'] ?? '');
      if ($name === '') json_err('Role name required');
      $st = $pdo->prepare("INSERT INTO roles (name) VALUES (?)");
      $st->execute([$name]);
      json_ok(['id'=>(int)$pdo->lastInsertId(), 'name'=>$name]);
    }

    if ($action === 'rename_role') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if (!$id || $name==='') json_err('Invalid data');
      $st = $pdo->prepare("UPDATE roles SET name=? WHERE id=?");
      $st->execute([$name, $id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    if ($action === 'delete_role') {
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) json_err('Invalid role id');
      $inUse = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id=?");
      $inUse->execute([$id]);
      if ($inUse->fetchColumn() > 0) json_err('Role has users; reassign first');
      $pdo->prepare("DELETE FROM role_pages WHERE role_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    // ---- Pages CRUD ----
    if ($action === 'add_page') {
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
      $id    = (int)($_POST['id'] ?? 0);
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
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) json_err('Invalid page id');
      $pdo->prepare("DELETE FROM role_pages WHERE page_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM user_pages WHERE page_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM pages WHERE id=?")->execute([$id]);
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    // ---- Role ↔ Page (matrix) ----
    if ($action === 'toggle_role_page') {
      $roleId = (int)($_POST['role_id'] ?? 0);
      $pageId = (int)($_POST['page_id'] ?? 0);
      $allow  = (int)($_POST['allowed'] ?? 0);
      if (!$roleId || !$pageId) json_err('Invalid ids');

      $st = $pdo->prepare("
        INSERT INTO role_pages (role_id, page_id, allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)
      ");
      $st->execute([$roleId, $pageId, $allow ? 1 : 0]);

      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok();
    }

    // ---- User details (roles + per-page overrides) ----
    if ($action === 'load_user') {
      $uid = (int)$_POST['user_id'];
      if (!$uid) json_err('Invalid user id');

      $r = $pdo->prepare("SELECT r.id, r.name
                          FROM user_roles ur
                          JOIN roles r ON r.id=ur.role_id
                          WHERE ur.user_id=?
                          ORDER BY r.name");
      $r->execute([$uid]);
      $userRoles = $r->fetchAll(PDO::FETCH_ASSOC);

      $o = $pdo->prepare("SELECT p.id AS page_id, p.file, COALESCE(p.label,p.file) AS label, up.allowed
                          FROM user_pages up
                          JOIN pages p ON p.id=up.page_id
                          WHERE up.user_id=?");
      $o->execute([$uid]);
      $overrides = $o->fetchAll(PDO::FETCH_ASSOC);

      json_ok(['user_roles'=>$userRoles, 'overrides'=>$overrides]);
    }

    if ($action === 'set_user_roles') {
      $uid = (int)($_POST['user_id'] ?? 0);
      if (!$uid) json_err('Invalid user id');
      $ids = array_filter(array_map('intval', explode(',', (string)($_POST['role_ids'] ?? ''))));
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$uid]);
      if ($ids) {
        $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)");
        foreach ($ids as $rid) { $st->execute([$uid, $rid]); }
      }
      $pdo->commit();
      clearAclCachesFor($uid);
      json_ok();
    }

    if ($action === 'set_user_page_override') {
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
      $files = array_filter(array_map('trim', explode(',', (string)($_POST['files'] ?? ''))));
      if (!$files) json_err('No files provided');
      $ins = $pdo->prepare("INSERT IGNORE INTO pages (file,label) VALUES (?,?)");
      foreach ($files as $f) { $ins->execute([$f, $f]); }
      clearAclCachesFor((int)($_SESSION['user_id'] ?? 0));
      json_ok(['added'=>count($files)]);
    }

    json_err('Unknown action', 400);
  } catch (Throwable $e) {
    json_err($e->getMessage(), 500);
  }
}

// ----------------- Page render (GET) -----------------
$roles  = tableExists($pdo,'roles')  ? $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$pages  = tableExists($pdo,'pages')  ? $pdo->query("SELECT id, file, COALESCE(label,file) AS label FROM pages ORDER BY label")->fetchAll(PDO::FETCH_ASSOC) : [];
$users  = tableExists($pdo,'users')  ? $pdo->query("SELECT id, username FROM users ORDER BY username LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) : [];
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
<<<<<<< HEAD
  <link rel="stylesheet" href="css/preclinic-theme.css">
=======
  <link rel="stylesheet" href="/css/preclinic-theme.css">
>>>>>>> origin/main
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
      <b>Notes:</b> Superadmin role and username <code>admin</code> bypass checks. The “pages” table drives everything; keep file names exactly as your PHP files.
    </div>
  </div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function post(action, data={}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k,v] of Object.entries(data)) fd.append(k, v);
  return fetch(location.pathname, { method:'POST', body: fd })
    .then(r => r.json());
}

// ---- Roles ----
document.getElementById('btnAddRole')?.addEventListener('click', () => {
  const name = document.getElementById('role_name').value.trim();
  if (!name) return;
  post('add_role', {name}).then(j=>{
    if (j.status==='ok') location.reload();
    else alert(j.msg||'Error');
  });
});
document.querySelectorAll('.btnRenameRole').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    const input = document.querySelector('.role-edit[data-id="'+id+'"]');
    post('rename_role', {id, name: input.value.trim()})
      .then(j=>{ if (j.status==='ok') location.reload(); else alert(j.msg||'Error'); });
  });
});
document.querySelectorAll('.btnDelRole').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    if (!confirm('Delete role? Users must be reassigned first.')) return;
    post('delete_role', {id: btn.dataset.id})
      .then(j=>{ if (j.status==='ok') location.reload(); else alert(j.msg||'Error'); });
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
  });
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
    });
  });
});
document.querySelectorAll('.btnDelPage').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    if (!confirm('Delete page and related mappings?')) return;
    post('delete_page', {id: btn.dataset.id}).then(j=>{
      if (j.status==='ok') location.reload();
      else alert(j.msg||'Error');
    });
  });
});
document.getElementById('btnScan')?.addEventListener('click', ()=>{
  const files = document.getElementById('scan_files').value.trim();
  if (!files) return;
  post('scan_register_files', {files}).then(j=>{
    if (j.status==='ok') location.reload();
    else alert(j.msg||'Error');
  });
});

// ---- Matrix toggles ----
document.querySelectorAll('.rpToggle').forEach(ch=>{
  ch.addEventListener('change', ()=>{
    const role_id = ch.dataset.roleId;
    const page_id = ch.dataset.pageId;
    const allowed = ch.checked ? 1 : 0;
    post('toggle_role_page', {role_id, page_id, allowed}).then(j=>{
      if (j.status!=='ok') { alert(j.msg||'Error'); ch.checked = !ch.checked; }
    });
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

    // Build roles checkboxes
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
  });
});

btnSaveUR?.addEventListener('click', ()=>{
  if (!currentUserId) return;
  const ids = Array.from(userRoles.querySelectorAll('input[type=checkbox]:checked')).map(cb=>cb.value);
  post('set_user_roles', {user_id: currentUserId, role_ids: ids.join(',')}).then(j=>{
    if (j.status==='ok') { alert('Saved'); } else { alert(j.msg||'Error'); }
  });
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
    });
  });
});
</script>
</body>
</html>
