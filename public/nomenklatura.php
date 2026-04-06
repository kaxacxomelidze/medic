<?php
// nomenklatura.php — Services master (ნომენკლატურა)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__ . '/../config/config.php'; // must set $pdo (PDO::ERRMODE_EXCEPTION)

if (empty($_SESSION['user_id'])) {
  header('Location: index.php'); exit;
}

/* ===== Security headers ===== */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: interest-cohort=()');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data:;");

/* ===== CSRF ===== */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf'];

/* ===== Charset/session collation ===== */
try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) {}

/* ===== Auto-schema (non-destructive) ===== */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS services (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(64) DEFAULT '',
      name VARCHAR(255) NOT NULL,
      svc_group VARCHAR(128) DEFAULT '',
      svc_type VARCHAR(128) DEFAULT '',
      price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      calhead TINYINT(1) NOT NULL DEFAULT 0,
      prt TINYINT(1) NOT NULL DEFAULT 0,
      itm_get_prc TINYINT(1) NOT NULL DEFAULT 0,
      description TEXT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      enabled TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $cols=[]; foreach ($pdo->query("SHOW COLUMNS FROM services") as $c){ $cols[strtolower($c['Field'])]=true; }
  $add = function($sql) use($pdo){ try{$pdo->exec($sql);}catch(Throwable $e){} };
  if (!isset($cols['code']))        $add("ALTER TABLE services ADD COLUMN code VARCHAR(64) DEFAULT '' AFTER id");
  if (!isset($cols['svc_group']))   $add("ALTER TABLE services ADD COLUMN svc_group VARCHAR(128) DEFAULT '' AFTER name");
  if (!isset($cols['svc_type']))    $add("ALTER TABLE services ADD COLUMN svc_type VARCHAR(128) DEFAULT '' AFTER svc_group");
  if (!isset($cols['price']))       $add("ALTER TABLE services ADD COLUMN price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER svc_type");
  if (!isset($cols['calhead']))     $add("ALTER TABLE services ADD COLUMN calhead TINYINT(1) NOT NULL DEFAULT 0 AFTER price");
  if (!isset($cols['prt']))         $add("ALTER TABLE services ADD COLUMN prt TINYINT(1) NOT NULL DEFAULT 0 AFTER calhead");
  if (!isset($cols['itm_get_prc'])) $add("ALTER TABLE services ADD COLUMN itm_get_prc TINYINT(1) NOT NULL DEFAULT 0 AFTER prt");
  if (!isset($cols['description'])) $add("ALTER TABLE services ADD COLUMN description TEXT NULL AFTER itm_get_prc");
  if (!isset($cols['enabled']))     $add("ALTER TABLE services ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER updated_at");

  // Helpful indexes
  $add("CREATE INDEX idx_name ON services(name)");
  $add("CREATE INDEX idx_enabled ON services(enabled)");
  $add("CREATE INDEX idx_group ON services(svc_group)");
  $add("CREATE INDEX idx_type ON services(svc_type)");
  $add("CREATE INDEX idx_code ON services(code)");
} catch (Throwable $e) {
  // ignore — UI still works
}

/* ===== Helpers ===== */
function j($x){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
function bool01($v){ return (isset($v) && (int)!!$v) ? 1 : 0; }
function cleanStr($s,$max){ $s=trim((string)$s); if (mb_strlen($s)>$max) $s=mb_substr($s,0,$max); return $s; }
function priceNorm($v){ $v = str_replace(',', '.', (string)$v); return (float)$v; }
function requireCsrf() {
  if (($_SERVER['HTTP_X_CSRF'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    j(['status'=>'err','msg'=>'CSRF validation failed']);
  }
}

/* ===== AJAX API ===== */
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($isAjax) {
  if ($method === 'POST') {
    requireCsrf();
    $isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $data   = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $action = $data['action'] ?? '';

    try {
      // List with filters/paging/sort
      if ($action === 'list_services') {
        $q      = cleanStr($data['q'] ?? '', 200);
        $grp    = cleanStr($data['group'] ?? '', 128);
        $typ    = cleanStr($data['type'] ?? '', 128);
        $enOnly = $data['enabled'] ?? '';
        $pmin   = $data['pmin'] ?? '';
        $pmax   = $data['pmax'] ?? '';
        $page   = max(1, (int)($data['page'] ?? 1));
        $size   = min(200, max(5, (int)($data['size'] ?? 50)));
        $sort   = $data['sort'] ?? 'name';
        $dir    = strtolower($data['dir'] ?? 'asc')==='desc'?'DESC':'ASC';

        $allowed = ['id','code','name','svc_group','svc_type','price','enabled','updated_at','created_at'];
        if (!in_array($sort, $allowed, true)) $sort = 'name';

        $where = [];
        $args  = [];

        if ($q !== '') {
          $where[] = "(code LIKE ? OR name LIKE ? OR svc_group LIKE ? OR svc_type LIKE ?)";
          $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%";
        }
        if ($grp !== '') { $where[]="svc_group=?"; $args[]=$grp; }
        if ($typ !== '') { $where[]="svc_type=?";  $args[]=$typ; }
        if ($enOnly !== '' && ($enOnly==='0' || $enOnly==='1' || $enOnly===0 || $enOnly===1)) {
          $where[]="enabled=?"; $args[]=(int)$enOnly;
        }
        $pminF = $pmin!=='' ? priceNorm($pmin) : null;
        $pmaxF = $pmax!=='' ? priceNorm($pmax) : null;
        if ($pminF !== null) { $where[]="price>=?"; $args[]=$pminF; }
        if ($pmaxF !== null) { $where[]="price<=?"; $args[]=$pmaxF; }

        $wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        // total
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM services $wsql");
        $stmt->execute($args);
        $total = (int)$stmt->fetchColumn();

        $offset = ($page-1)*$size;

        // with usage counters (for safe delete hint)
        $sql = "
          SELECT s.*,
                 COALESCE(ps.cnt_ps,0) as used_in_patient_services,
                 COALESCE(ii.cnt_ii,0) as used_in_invoice_items
          FROM services s
          LEFT JOIN (
            SELECT service_id, COUNT(*) cnt_ps
            FROM patient_services
            GROUP BY service_id
          ) ps ON ps.service_id = s.id
          LEFT JOIN (
            SELECT ps2.service_id, COUNT(*) cnt_ii
            FROM invoice_items ii2
            JOIN patient_services ps2 ON ps2.id = ii2.patient_service_id
            GROUP BY ps2.service_id
          ) ii ON ii.service_id = s.id
          $wsql
          ORDER BY $sort $dir, id ASC
          LIMIT $size OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        j(['status'=>'ok','rows'=>$rows,'total'=>$total,'page'=>$page,'size'=>$size]);
      }

      // Distinct options for selects
      if ($action === 'filters_meta') {
        $groups = $pdo->query("SELECT DISTINCT svc_group AS v FROM services WHERE svc_group<>'' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
        $types  = $pdo->query("SELECT DISTINCT svc_type  AS v FROM services WHERE svc_type<>''  ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
        j(['status'=>'ok','groups'=>$groups,'types'=>$types,'csrf'=>$CSRF]);
      }

      // Toggle ON/OFF
      if ($action === 'toggle_service') {
        $id = (int)($data['id'] ?? 0);
        $enabled = bool01($data['enabled'] ?? 0);
        if ($id<=0) throw new RuntimeException('არასწორი ID');
        $stmt=$pdo->prepare("UPDATE services SET enabled=? WHERE id=?");
        $stmt->execute([$enabled,$id]);
        j(['status'=>'ok']);
      }

      // Add/Edit
      if ($action === 'save_service') {
        $id        = (int)($data['id'] ?? 0);
        $code      = cleanStr($data['code'] ?? '', 64);
        $name      = cleanStr($data['name'] ?? '', 255);
        $group     = cleanStr($data['group'] ?? '', 128);
        $type      = cleanStr($data['type'] ?? 'შიდა', 128);
        $price     = priceNorm($data['price'] ?? 0);
        $calhead   = bool01($data['calhead'] ?? 0);
        $prt       = bool01($data['prt'] ?? 0);
        $itmgetprc = bool01($data['itmgetprc'] ?? 0);
        $enabled   = bool01($data['enabled'] ?? 1);
        $desc      = trim((string)($data['description'] ?? ''));

        if ($name==='')  throw new RuntimeException('„დასახელება“ სავალდებულოა.');
        if ($group==='') throw new RuntimeException('„ჯგუფი“ აირჩიე სიიდან.');
        if ($type==='')  throw new RuntimeException('„ტიპი“ სავალდებულოა.');
        if ($price<0)    throw new RuntimeException('„ფასი“ უარყოფითი ვერ იქნება.');

        // Uniqueness: prefer unique code; fallback to (name+group)
        if ($code!=='') {
          $stmt=$pdo->prepare("SELECT id FROM services WHERE code=? AND id<>? LIMIT 1");
          $stmt->execute([$code,$id]);
          if ($stmt->fetch()) throw new RuntimeException('ასეთი „კოდი“ უკვე არსებობს.');
        } else {
          $stmt=$pdo->prepare("SELECT id FROM services WHERE name=? AND svc_group=? AND id<>? LIMIT 1");
          $stmt->execute([$name,$group,$id]);
          if ($stmt->fetch()) throw new RuntimeException('ასეთი „დასახელება + ჯგუფი“ უკვე არსებობს.');
        }

        if ($id===0) {
          $stmt=$pdo->prepare("
            INSERT INTO services (code,name,svc_group,svc_type,price,calhead,prt,itm_get_prc,description,enabled)
            VALUES (?,?,?,?,?,?,?,?,?,?)
          ");
          $stmt->execute([$code,$name,$group,$type,$price,$calhead,$prt,$itmgetprc,$desc,$enabled]);
          $id=(int)$pdo->lastInsertId();
        } else {
          $stmt=$pdo->prepare("
            UPDATE services
            SET code=?, name=?, svc_group=?, svc_type=?, price=?, calhead=?, prt=?, itm_get_prc=?, description=?, enabled=?
            WHERE id=?
          ");
          $stmt->execute([$code,$name,$group,$type,$price,$calhead,$prt,$itmgetprc,$desc,$enabled,$id]);
        }

        $row = $pdo->prepare("SELECT * FROM services WHERE id=?");
        $row->execute([$id]);
        j(['status'=>'ok','service'=>$row->fetch(PDO::FETCH_ASSOC)]);
      }

      // Safe delete (block if used)
      if ($action === 'delete_service') {
        $id = (int)($data['id'] ?? 0);
        if ($id<=0) throw new RuntimeException('არასწორი ID.');

        // used in patient_services?
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM patient_services WHERE service_id=?");
        $stmt->execute([$id]);
        $inPS = (int)$stmt->fetchColumn();

        // used in invoice_items (via patient_services)
        $stmt=$pdo->prepare("
          SELECT COUNT(*)
          FROM invoice_items ii
          JOIN patient_services ps ON ps.id=ii.patient_service_id
          WHERE ps.service_id=?
        ");
        $stmt->execute([$id]);
        $inII = (int)$stmt->fetchColumn();

        if ($inPS>0 || $inII>0) {
          throw new RuntimeException('სერვისი გამოყენებულია ოპერაციებში/ინვოისებში და წაშლა ბლოკირებულია.');
        }

        $stmt=$pdo->prepare("DELETE FROM services WHERE id=?");
        $stmt->execute([$id]);
        j(['status'=>'ok','deleted_id'=>$id]);
      }

      // Bulk actions
      if ($action === 'bulk') {
        $cmd = $data['cmd'] ?? '';
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || !$ids) throw new RuntimeException('აირჩიე ჩანაწერები.');
        $ids = array_values(array_map('intval',$ids));

        if ($cmd==='enable' || $cmd==='disable') {
          $val = $cmd==='enable' ? 1 : 0;
          $in = implode(',', array_fill(0,count($ids),'?'));
          $stmt=$pdo->prepare("UPDATE services SET enabled=$val WHERE id IN ($in)");
          $stmt->execute($ids);
          j(['status'=>'ok','affected'=>count($ids)]);
        }
        if ($cmd==='delete') {
          // verify none used
          foreach ($ids as $sid) {
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM patient_services WHERE service_id=?");
            $stmt->execute([$sid]);
            if ((int)$stmt->fetchColumn()>0) throw new RuntimeException("ID $sid გამოყენებულია (patient_services).");
            $stmt=$pdo->prepare("
              SELECT COUNT(*) FROM invoice_items ii
              JOIN patient_services ps ON ps.id=ii.patient_service_id
              WHERE ps.service_id=?
            ");
            $stmt->execute([$sid]);
            if ((int)$stmt->fetchColumn()>0) throw new RuntimeException("ID $sid გამოყენებულია (invoice_items).");
          }
          $in = implode(',', array_fill(0,count($ids),'?'));
          $stmt=$pdo->prepare("DELETE FROM services WHERE id IN ($in)");
          $stmt->execute($ids);
          j(['status'=>'ok','deleted'=>count($ids)]);
        }

        throw new RuntimeException('უცნობი Bulk ქმედება.');
      }

      // CSV export (current filters)
      if ($action === 'export_csv') {
        $q      = cleanStr($data['q'] ?? '', 200);
        $grp    = cleanStr($data['group'] ?? '', 128);
        $typ    = cleanStr($data['type'] ?? '', 128);
        $enOnly = $data['enabled'] ?? '';
        $pmin   = $data['pmin'] ?? '';
        $pmax   = $data['pmax'] ?? '';
        $sort   = $data['sort'] ?? 'name';
        $dir    = strtolower($data['dir'] ?? 'asc')==='desc'?'DESC':'ASC';
        $allowed = ['id','code','name','svc_group','svc_type','price','enabled','updated_at','created_at'];
        if (!in_array($sort,$allowed,true)) $sort='name';

        $where=[]; $args=[];
        if ($q!==''){ $where[]="(code LIKE ? OR name LIKE ? OR svc_group LIKE ? OR svc_type LIKE ?)"; $args=["%$q%","%$q%","%$q%","%$q%"]; }
        if ($grp!==''){ $where[]="svc_group=?"; $args[]=$grp; }
        if ($typ!==''){ $where[]="svc_type=?";  $args[]=$typ; }
        if ($enOnly!=='' && ($enOnly==='0'||$enOnly==='1'||$enOnly===0||$enOnly===1)){ $where[]="enabled=?"; $args[]=(int)$enOnly; }
        $pminF = $pmin!=='' ? priceNorm($pmin) : null;
        $pmaxF = $pmax!=='' ? priceNorm($pmax) : null;
        if ($pminF !== null){ $where[]="price>=?"; $args[]=$pminF; }
        if ($pmaxF !== null){ $where[]="price<=?"; $args[]=$pmaxF; }
        $wsql = $where ? 'WHERE '.implode(' AND ',$where) : '';

        $sql = "SELECT id,code,name,svc_group,svc_type,price,calhead,prt,itm_get_prc,enabled,created_at,updated_at FROM services $wsql ORDER BY $sort $dir, id ASC";
        $stmt=$pdo->prepare($sql); $stmt->execute($args);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=services_export_'.date('Ymd_His').'.csv');
        $out = fopen('php://output','w');
        fputcsv($out, array_keys($rows? $rows[0]:[
          'id','code','name','svc_group','svc_type','price','calhead','prt','itm_get_prc','enabled','created_at','updated_at'
        ]));
        foreach ($rows as $r) { fputcsv($out, $r); }
        fclose($out);
        exit;
      }

      j(['status'=>'err','msg'=>'უცნობი მოქმედება']);
    } catch (Throwable $e) {
      j(['status'=>'err','msg'=>$e->getMessage()]);
    }
  }
  exit;
}

/* ===== Initial data for server-rendered table (first page) ===== */
$cur = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
  <meta charset="UTF-8">
  <title>SanMedic – ნომენკლატურა</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <style>
    :root{
      --brand:#21c1a6; --brand-2:#18a591; --bg:#f9f8f2; --panel:#fff; --muted:#6c7a89;
      --b:#e4e4e4; --b2:#cdd5de; --ok:#21c1a6; --err:#e74c3c;
    }
    *{box-sizing:border-box}
    body{font-family:"Noto Sans Georgian",sans-serif;background:var(--bg);color:#222;margin:0}
    .topbar{background:var(--brand);color:#fff;padding:12px 40px;display:flex;justify-content:flex-end}
    .user-menu-wrap{display:flex;align-items:center;gap:16px;position:relative}
    .user-btn{display:flex;align-items:center;gap:8px;color:var(--brand);cursor:pointer;font-size:18px;background:#f9f9f9;border-radius:18px;padding:6px 19px 6px 14px;font-weight:500;border:1.5px solid var(--b)}
    .user-dropdown{position:absolute;top:44px;min-width:140px;background:#fff;border:1.5px solid var(--b);border-radius:10px;box-shadow:0 4px 18px rgba(23,60,84,.07);display:none;flex-direction:column;padding:10px 0;z-index:2222}
    .user-menu-wrap:focus-within .user-dropdown,.user-btn.open + .user-dropdown{display:flex}
    .user-dropdown a{padding:8px 20px 8px 16px;color:#20756b;text-decoration:none;display:flex;align-items:center;gap:9px;font-size:16px}
    .logout-btn{background:#f4f6f7;color:#e74c3c;border:1.5px solid var(--b);border-radius:18px;padding:6px 19px 6px 16px;font-size:15.5px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px}

    .container.tabswrap{max-width:1600px;margin:14px auto 0;padding:0 40px}
    .tabs{list-style:none;margin:0;padding:0;display:flex;gap:8px;border-bottom:2px solid #ddd}
    .tabs li a{display:inline-block;padding:10px 14px;text-decoration:none;border-radius:6px 6px 0 0;font-weight:700;color:var(--brand)}
    .tabs li a.active,.tabs li a:hover{background:var(--brand);color:#fff}

    .container.main{max-width:1600px;margin:18px auto 48px;padding:0 40px}
    .panel{background:#fff;border:1.5px solid var(--b);border-radius:8px;box-shadow:0 2px 10px rgba(31,61,124,0.05);margin-bottom:28px}
    .panel-header{background:#f7f7f5;padding:14px 24px;font-weight:700;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    .panel-body{padding:18px 24px}
    .btn{background:var(--brand);color:#fff;border:none;border-radius:6px;padding:9px 12px;font-weight:600;cursor:pointer}
    .btn:hover{background:var(--brand-2)}
    .btn-danger{background:var(--err)} .btn-danger:hover{background:#c0392b}
    .btn-ghost{background:#fff;border:1px solid var(--b2);color:#2d3e50}

    .filters{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
    .filters input,.filters select{padding:8px 10px;border:1.2px solid var(--b2);border-radius:8px;background:#fff;font-size:14px}
    .filters input:focus,.filters select:focus{border-color:var(--brand);outline:none}
    .filters .split{display:flex;gap:8px;align-items:center}

    .searchbar{display:flex;gap:10px;align-items:center;margin-left:auto}
    .searchbar input{padding:10px 12px;border:1.2px solid var(--b2);border-radius:8px;background:#fafafa;font-size:15px;min-width:260px}
    .searchbar input:focus{border-color:var(--brand);background:#fff;outline:none}
    .searchbar .count{color:#666;font-weight:600}

    .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}

    table{width:100%;border-collapse:collapse;font-size:15px;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 8px rgba(31,61,124,0.04)}
    thead th{background:var(--brand);color:#fff;padding:10px;text-align:left;white-space:nowrap;cursor:pointer}
    tbody td{border-bottom:1px solid #ececec;padding:10px;white-space:nowrap}
    tbody tr:nth-child(odd){background:#f7fdf7}
    .actions button{background:none;border:none;cursor:pointer;color:var(--brand);font-size:18px}
    .toggle-btn{border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-weight:700;color:#fff;min-width:64px}
    .toggle-btn[data-enabled="1"]{background:var(--ok)}
    .toggle-btn[data-enabled="0"]{background:var(--err)}
    .muted{color:var(--muted);font-size:12px}
    .hint{font-size:12px;color:#556}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef;border:1px solid #dde;font-size:12px}

    .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9999;backdrop-filter:blur(5px);align-items:center;justify-content:center}
    .modal .content{background:#fff;border-radius:12px;width:94%;max-width:760px;padding:24px;position:relative;box-shadow:0 10px 25px rgba(0,0,0,.1)}
    .modal .close-x{position:absolute;top:10px;right:14px;font-size:28px;color:#999;background:none;border:none;cursor:pointer}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
    .form-group{display:flex;flex-direction:column}
    .form-group label{font-size:14.5px;margin-bottom:6px;color:#414}
    .form-group input,.form-group select, .form-group textarea{padding:10px;border:1.2px solid var(--b2);border-radius:6px;background:#fff;font-size:15px}
    .form-group textarea{min-height:80px;resize:vertical}
    .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--brand);outline:none}
    .span2{grid-column:span 2}.span3{grid-column:span 3}.span4{grid-column:span 4}.span6{grid-column:span 6}.span8{grid-column:span 8}.span12{grid-column:1/-1}
    .chkline{display:flex;gap:16px;align-items:center;flex-wrap:wrap}

    .pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:10px}
    .pager button{padding:6px 10px;border:1px solid var(--b2);background:#fff;border-radius:6px;cursor:pointer}
    .pager .muted{margin-left:auto}
    .selbox{width:18px;height:18px}
  </style>
  <link rel="stylesheet" href="/css/preclinic-theme.css">
</head>
<body>

<?php
// Optional shared header
$headerIncluded=false;
foreach ([__DIR__.'/header.php',__DIR__.'/partials/header.php',__DIR__.'/../partials/header.php'] as $hdr){
  if (is_file($hdr)) { include_once $hdr; $headerIncluded=true; break; }
}
if(!$headerIncluded):
?>
<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:40px;width:auto;margin-right:12px;background:#fff;padding:4px 8px;border-radius:6px;">
    </a>
  <div class="user-menu-wrap" tabindex="0">
    <div class="user-btn" tabindex="0">
      <i class="fas fa-user-circle"></i>
      <span><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
      <i class="fas fa-chevron-down"></i>
    </div>
    <div class="user-dropdown">
      <a href="profile.php"><i class="fas fa-user"></i> პროფილი</a>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> გამოსვლა</a>
  </div>
</div>

<div class="container tabswrap">
  <ul class="tabs">
    <li><a href="dashboard.php">რეგისტრაცია</a></li>
    <li><a href="patient_hstory.php">პაციენტის ისტორია</a></li>
    <li><a href="nomenklatura.php" class="active">ნომენკლატურა</a></li>
    <li><a href="angarishebi.php">ანგარიშები</a></li>
  </ul>
</div>
<?php endif; ?>

<div class="container main">
  <div class="panel">
    <div class="panel-header">
      <div>
        სერვისების ნუსხა
        <span class="muted" id="metaHint"></span>
      </div>

      <!-- Filters -->
      <div class="filters" id="filters">
        <input type="text" id="f_q" placeholder="ძიება (კოდი/დასახელება/ჯგუფი/ტიპი)">
        <select id="f_group"><option value="">ჯგუფი — ყველა</option></select>
        <select id="f_type"><option value="">ტიპი — ყველა</option></select>
        <div class="split">
          <input type="number" id="f_pmin" step="0.01" placeholder="ფასი ≥">
          <input type="number" id="f_pmax" step="0.01" placeholder="ფასი ≤">
        </div>
        <select id="f_enabled">
          <option value="">სტატუსი — ყველა</option>
          <option value="1">მხოლოდ ჩართული</option>
          <option value="0">მხოლოდ გამორთული</option>
        </select>
        <button class="btn-ghost" id="btnClear">გასუფთავება</button>
      </div>

      <div class="toolbar">
        <button class="btn" id="btnAdd"><i class="fas fa-plus"></i> დამატება</button>
        <button class="btn-ghost" id="btnExport"><i class="fas fa-file-export"></i> Export CSV</button>
        <span class="count" id="qcount"></span>
      </div>
    </div>

    <div class="panel-body" style="overflow-x:auto;">
      <table id="tblServices">
        <thead>
          <tr>
            <th><input type="checkbox" id="selAll" class="selbox" title="ყველას არჩევა"></th>
            <th data-sort="id">ID</th>
            <th data-sort="code">კოდი</th>
            <th data-sort="name">დასახელება</th>
            <th data-sort="svc_group">ჯგუფი</th>
            <th data-sort="svc_type">ტიპი</th>
            <th data-sort="price">ფასი</th>
            <th>კალჰედ</th>
            <th>Prt</th>
            <th>ItmGetPrc</th>
            <th data-sort="enabled">სტატუსი</th>
            <th>გამოყენება</th>
            <th>ქმედებები</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <div class="pager">
        <div class="muted" id="pageInfo"></div>
        <button id="prevPage">წინა</button>
        <span id="curPage" class="tag">1</span>
        <button id="nextPage">შემდეგი</button>
        <select id="pageSize">
          <option value="25">25</option>
          <option value="50" selected>50</option>
          <option value="100">100</option>
          <option value="200">200</option>
        </select>
        <div style="flex:1"></div>
        <select id="bulkCmd">
          <option value="">Bulk ქმედება…</option>
          <option value="enable">ჩართვა</option>
          <option value="disable">გამორთვა</option>
          <option value="delete">წაშლა</option>
        </select>
        <button class="btn-ghost" id="bulkApply">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal" id="svcModal" aria-hidden="true">
  <div class="content">
    <button class="close-x" id="svcClose">×</button>
    <h3 id="svcTitle" style="margin-top:0;">სერვისის დამატება</h3>
    <form id="svcForm" class="grid" style="margin-top:6px;">
      <input type="hidden" id="svc_id">

      <div class="form-group span4">
        <label for="svc_code">კოდი</label>
        <input type="text" id="svc_code" maxlength="64" autocomplete="off">
      </div>
      <div class="form-group span8">
        <label for="svc_name">დასახელება*</label>
        <input type="text" id="svc_name" maxlength="255" required autocomplete="off">
      </div>

      <div class="form-group span6">
        <label for="svc_group">ჯგუფი*</label>
        <select id="svc_group" required></select>
        <span class="hint">არ არის სიაში? פשוט ჩაწერე ახალი მნიშვნელობა ველში *ქვემოთ.*</span>
        <input type="text" id="svc_group_new" placeholder="ან ახალი ჯგუფი…" />
      </div>
      <div class="form-group span6">
        <label for="svc_type">ტიპი*</label>
        <select id="svc_type" required></select>
        <input type="text" id="svc_type_new" placeholder="ან ახალი ტიპი…"/>
      </div>

      <div class="form-group span3">
        <label for="svc_price">ფასი*</label>
        <input type="number" id="svc_price" step="0.01" min="0" value="0.00">
      </div>
      <div class="form-group span9">
        <label>ფლაგები</label>
        <div class="chkline">
          <label><input type="checkbox" id="svc_prt"> Prt</label>
          <label><input type="checkbox" id="svc_calhead"> კალჰედ</label>
          <label><input type="checkbox" id="svc_itmgetprc"> ItmGetPrc</label>
          <label><input type="checkbox" id="svc_enabled" checked> გამოყენებადი (ON/OFF)</label>
        </div>
      </div>

      <div class="form-group span12">
        <label for="svc_desc">აღწერა</label>
        <textarea id="svc_desc" placeholder="მოკლე აღწერა / შენიშვნა (არასავალდებულო)"></textarea>
      </div>

      <div class="span12" style="text-align:right;margin-top:8px;">
        <button type="submit" class="btn" id="svcSaveBtn">შენახვა</button>
      </div>
    </form>
  </div>
</div>

<script>
  const CSRF = <?= json_encode($CSRF) ?>;

  // Dropdown in top bar (only if no shared header)
  const userBtn=document.querySelector('.user-btn'); const userDropdown=document.querySelector('.user-dropdown');
  if(userBtn&&userDropdown){
    document.addEventListener('click',e=>{
      if(userBtn.contains(e.target)) userBtn.classList.toggle('open');
      else if(!userDropdown.contains(e.target)) userBtn.classList.remove('open');
    });
  }

  // ===== State =====
  const state = {
    page: 1, size: 50,
    sort: 'name', dir: 'asc',
    q: '', group: '', type: '', enabled: '', pmin:'', pmax:''
  };

  // ===== Utils =====
  function $(sel, root=document){ return root.querySelector(sel); }
  function $all(sel, root=document){ return Array.from(root.querySelectorAll(sel)); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function money(n){ if(n==null) return ''; const v=parseFloat(n); if(isNaN(v)) return esc(n); return v.toFixed(2); }
  function toast(msg){ alert(msg); } // minimal; plug SweetAlert if you want

  // ===== API =====
  async function api(action, payload={}, opts={}) {
    const res = await fetch('nomenklatura.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF':CSRF},
      body: JSON.stringify({action, ...payload}),
      ...opts
    });
    if (opts.stream) return res; // for CSV
    return res.json();
  }

  // ===== Filters meta =====
  async function loadMeta(){
    const j = await api('filters_meta');
    if(j.status!=='ok') return;

    // Group/type selects for Filters
    const fg = $('#f_group'); const ft = $('#f_type');
    fg.innerHTML = '<option value="">ჯგუფი — ყველა</option>' + (j.groups||[]).map(v=>`<option>${esc(v)}</option>`).join('');
    ft.innerHTML = '<option value="">ტიპი — ყველა</option>' + (j.types||[]).map(v=>`<option>${esc(v)}</option>`).join('');

    // Group/type selects in Modal
    const sg = $('#svc_group'); const st = $('#svc_type');
    sg.innerHTML = '<option value="">-- აირჩიე --</option>' + (j.groups||[]).map(v=>`<option>${esc(v)}</option>`).join('');
    st.innerHTML = '<option value="">-- აირჩიე --</option>' + (j.types||[]).map(v=>`<option>${esc(v)}</option>`).join('');
    // sensible defaults
    if(!st.value){ st.value = (j.types||[]).includes('შიდა') ? 'შიდა' : ''; }

    $('#metaHint').textContent = '— '+(j.groups?.length||0)+' ჯგუფი, '+(j.types?.length||0)+' ტიპი';
  }

  // ===== List/paging =====
  let loading=false;
  async function loadTable(){
    if(loading) return; loading=true;
    const j = await api('list_services', {
      q: state.q, group: state.group, type: state.type, enabled: state.enabled,
      pmin: state.pmin, pmax: state.pmax, page: state.page, size: state.size,
      sort: state.sort, dir: state.dir
    });
    loading=false;
    if(j.status!=='ok'){ toast(j.msg||'ვერ ჩაიტვირთა'); return; }

    const tb = $('#tblServices tbody');
    tb.innerHTML = '';
    if(!j.rows.length){
      tb.innerHTML = `<tr><td colspan="13" style="text-align:center;color:#777;">ჩანაწერი ვერ მოიძებნა</td></tr>`;
    } else {
      tb.innerHTML = j.rows.map(r => {
        const used = (r.used_in_patient_services|0) + (r.used_in_invoice_items|0);
        return `
          <tr data-id="${r.id}">
            <td><input type="checkbox" class="selbox rowSel" value="${r.id}"></td>
            <td>${r.id}</td>
            <td class="s-code">${esc(r.code||'')}</td>
            <td class="s-name">${esc(r.name||'')}</td>
            <td class="s-group">${esc(r.svc_group||'')}</td>
            <td class="s-type">${esc(r.svc_type||'')}</td>
            <td class="s-price">${money(r.price)}</td>
            <td class="s-calhead">${r.calhead? '✓':''}</td>
            <td class="s-prt">${r.prt? '✓':''}</td>
            <td class="s-itmgetprc">${r.itm_get_prc? '✓':''}</td>
            <td class="s-enabled">
              <button class="toggle-btn" data-id="${r.id}" data-enabled="${r.enabled?1:0}">
                ${r.enabled? 'ON':'OFF'}
              </button>
            </td>
            <td>
              <span class="muted">PS:${r.used_in_patient_services|0} · II:${r.used_in_invoice_items|0}</span>
            </td>
            <td class="actions">
              <button title="რედაქტირება" class="act-edit"><i class="fas fa-pen"></i></button>
              <button title="წაშლა" class="act-del"${used? ' disabled style="opacity:.4;cursor:not-allowed"':''}><i class="fas fa-trash-alt"></i></button>
            </td>
          </tr>
        `;
      }).join('');
    }

    // counters & pager
    $('#qcount').textContent = j.total? `სულ: ${j.total}` : '';
    $('#curPage').textContent = j.page;
    $('#pageInfo').textContent = j.total ? `${(j.page-1)*j.size+1}-${Math.min(j.page*j.size, j.total)} / ${j.total}` : '—';
    $('#prevPage').disabled = (j.page<=1);
    $('#nextPage').disabled = (j.page*j.size>=j.total);

    // wire toggles
    $all('.toggle-btn').forEach(b=>{
      b.addEventListener('click', async ()=>{
        const id = b.dataset.id;
        const cur = parseInt(b.dataset.enabled||'0',10);
        const next = cur?0:1;
        const r = await api('toggle_service',{id,enabled:next});
        if(r.status==='ok'){
          b.dataset.enabled = String(next);
          b.textContent = next? 'ON':'OFF';
          b.setAttribute('data-enabled', String(next));
        } else toast(r.msg||'ვერ შეიცვალა');
      });
    });

    // edit
    $all('.act-edit').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr');
        openModal({
          id: tr.dataset.id,
          code: tr.querySelector('.s-code')?.textContent.trim()||'',
          name: tr.querySelector('.s-name')?.textContent.trim()||'',
          group: tr.querySelector('.s-group')?.textContent.trim()||'',
          type: tr.querySelector('.s-type')?.textContent.trim()||'',
          price: tr.querySelector('.s-price')?.textContent.trim()||'',
          calhead: tr.children[7].textContent.trim()==='✓',
          prt:     tr.children[8].textContent.trim()==='✓',
          itmgetprc: tr.children[9].textContent.trim()==='✓',
          enabled: tr.querySelector('.toggle-btn')?.dataset.enabled==='1',
          description: '' // not rendered in the grid, but editable in modal
        }, 'edit');
      });
    });

    // delete
    $all('.act-del').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        if (btn.disabled) { toast('ეს სერვისი გამოყენებულია და წაშლა ბლოკირებულია.'); return; }
        const tr = btn.closest('tr'); const id = tr.dataset.id;
        if (!confirm('წავშალო ეს სერვისი?')) return;
        const r = await api('delete_service',{id});
        if(r.status==='ok'){ tr.remove(); } else { toast(r.msg||'ვერ წაიშალა'); }
      });
    });
  }

  // Sorting by clicking headers
  $all('thead th[data-sort]').forEach(th=>{
    th.addEventListener('click', ()=>{
      const f = th.dataset.sort;
      if (state.sort===f) state.dir = (state.dir==='asc'?'desc':'asc');
      else { state.sort = f; state.dir='asc'; }
      state.page=1; loadTable();
    });
  });

  // Pager controls
  $('#prevPage').addEventListener('click', ()=>{ if(state.page>1){state.page--; loadTable();} });
  $('#nextPage').addEventListener('click', ()=>{ state.page++; loadTable(); });
  $('#pageSize').addEventListener('change', (e)=>{ state.size=parseInt(e.target.value,10); state.page=1; loadTable(); });

  // Filters wiring
  const linkFilter = (id, key)=> $(id).addEventListener('input', debounce(()=>{ state[key]=$(id).value.trim(); state.page=1; loadTable(); }, 200));
  linkFilter('#f_q','q'); linkFilter('#f_group','group'); linkFilter('#f_type','type');
  linkFilter('#f_enabled','enabled'); linkFilter('#f_pmin','pmin'); linkFilter('#f_pmax','pmax');
  $('#btnClear').addEventListener('click', ()=>{
    ['f_q','f_group','f_type','f_enabled','f_pmin','f_pmax'].forEach(i=>$( '#'+i ).value='');
    state.q=''; state.group=''; state.type=''; state.enabled=''; state.pmin=''; state.pmax='';
    state.page=1; loadTable();
  });

  function debounce(fn,ms){ let t=null; return function(){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,arguments),ms); } }

  // ===== Modal =====
  function openModal(data={}, mode='add'){
    $('#svcTitle').textContent = mode==='edit' ? 'სერვისის რედაქტირება' : 'სერვისის დამატება';
    $('#svc_id').value = data.id||'';
    $('#svc_code').value = data.code||'';
    $('#svc_name').value = data.name||'';
    $('#svc_price').value = data.price||'0.00';
    $('#svc_calhead').checked = !!data.calhead;
    $('#svc_prt').checked = !!data.prt;
    $('#svc_itmgetprc').checked = !!data.itmgetprc;
    $('#svc_enabled').checked = (data.enabled!==false);
    $('#svc_desc').value = data.description||'';

    // options might not exist yet if meta not loaded
    const setSel = (sel, val)=>{ const el=$(sel); if(!el) return; if([...el.options].some(o=>o.value===val)) el.value=val; };
    setSel('#svc_group', data.group||'');
    setSel('#svc_type', data.type||'შიდა');

    $('#svc_group_new').value = '';
    $('#svc_type_new').value = '';

    $('#svcModal').style.display='flex';
    $('#svc_name').focus();
  }
  function closeModal(){ $('#svcModal').style.display='none'; }
  $('#svcClose').onclick = closeModal;
  $('#svcModal').addEventListener('click', e=>{ if (e.target.id==='svcModal') closeModal(); });
  document.addEventListener('keydown', e=>{ if (e.key==='Escape') closeModal(); });

  // Add
  $('#btnAdd').addEventListener('click', ()=>openModal({},'add'));

  // Save (add/edit)
  $('#svcForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    // allow quick adding of new group/type via the extra inputs
    const group = $('#svc_group_new').value.trim() || $('#svc_group').value.trim();
    const type  = $('#svc_type_new').value.trim()  || $('#svc_type').value.trim();

    const payload = {
      id: $('#svc_id').value || undefined,
      code: $('#svc_code').value.trim(),
      name: $('#svc_name').value.trim(),
      group, type,
      price: parseFloat($('#svc_price').value || 0),
      calhead: $('#svc_calhead').checked ? 1:0,
      prt: $('#svc_prt').checked ? 1:0,
      itmgetprc: $('#svc_itmgetprc').checked ? 1:0,
      enabled: $('#svc_enabled').checked ? 1:0,
      description: $('#svc_desc').value
    };
    if(!payload.name){ toast('დასახელება სავალდებულოა.'); return; }
    if(!payload.group){ toast('აირჩიე/ჩაწერე ჯგუფი.'); return; }
    if(!payload.type){ toast('აირჩიე/ჩაწერე ტიპი.'); return; }
    if(payload.price<0){ toast('ფასი უარყოფითი ვერ იქნება.'); return; }

    const j = await api('save_service', payload);
    if(j.status==='ok'){ closeModal(); await loadMeta(); await loadTable(); }
    else toast(j.msg||'ვერ შეინახა');
  });

  // Select all
  $('#selAll').addEventListener('change', (e)=>{
    $all('.rowSel').forEach(cb=>cb.checked = e.target.checked);
  });

  // Bulk
  $('#bulkApply').addEventListener('click', async ()=>{
    const cmd = $('#bulkCmd').value;
    if(!cmd){ toast('აირჩიე Bulk ქმედება.'); return; }
    const ids = $all('.rowSel:checked').map(cb=>cb.value);
    if(!ids.length){ toast('არაფერი არჩეულია.'); return; }
    if(cmd==='delete' && !confirm('დარწმუნებული ხარ?')) return;
    const j=await api('bulk',{cmd,ids});
    if(j.status==='ok'){ await loadTable(); } else toast(j.msg||'ვერ შესრულდა');
  });

  // Export CSV (download stream)
  $('#btnExport').addEventListener('click', async ()=>{
    // Create a POST with current state that returns CSV
    const res = await fetch('nomenklatura.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF':CSRF},
      body: JSON.stringify({action:'export_csv',
        q: state.q, group: state.group, type: state.type, enabled: state.enabled,
        pmin: state.pmin, pmax: state.pmax, sort: state.sort, dir: state.dir
      })
    });
    if(!res.ok){ toast('Export შეცდომაა'); return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'services_export.csv';
    a.click();
    URL.revokeObjectURL(url);
  });

  // Init
  (async function init(){
    await loadMeta();
    // set defaults
    $('#pageSize').value = String(state.size);
    await loadTable();
  })();
</script>
</body>
</html>
