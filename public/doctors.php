<?php
/**
 * public/doctors.php — HR (Doctors) + user access
 * - Add/Edit doctor; optional user account (username/password -> users.role=doctor)
 * - Link one Branch & Department (doctor_department)
 * - Soft archive/restore via status; hard delete (double confirm)
 * - Sort/Pagination
 * - Excel (.xls) export (HTML table + proper headers, UTF-8)
 * - Auto-patch: adds doctors.user_id if missing + FK (best-effort)
 */

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__ . '/../config/config.php'; // must define $pdo (PDO::ERRMODE_EXCEPTION)

// ---------- AUTH ----------
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

// ---------- SECURITY HEADERS ----------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: interest-cohort=()');

try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) { /* ignore */ }

// ---------- CSRF ----------
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf'];

// ---------- HELPERS ----------
define('GE_PERSONAL_ID_LEN', 11);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post_trim($k,$d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function is_valid_date($d){ return $d && preg_match('/^\d{4}-\d{2}-\d{2}$/',$d); }
function only_digits($s){ return $s!=='' && preg_match('/^\d+$/',(string)$s); }
function valid_email_or_empty($s){ $s=trim((string)$s); return $s===''? '': (filter_var($s,FILTER_VALIDATE_EMAIL)?$s:''); }
function normalize_personal_id($s){ return preg_replace('/\s+/', '', (string)$s); }

// ---------- FLASH ----------
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$error = '';

// ---------- AUTO-SCHEMA PATCH ----------
try {
  // ensure doctors.user_id exists (existing behavior)
  $cols=[]; $st=$pdo->query("SHOW COLUMNS FROM doctors");
  foreach($st as $c){ $cols[strtolower($c['Field'])]=1; }
  if(empty($cols['user_id'])){
    $pdo->exec("ALTER TABLE doctors ADD COLUMN user_id INT NULL AFTER id");
    $pdo->exec("CREATE INDEX idx_doctor_user_id ON doctors(user_id)");
    try {
      $pdo->exec("ALTER TABLE doctors ADD CONSTRAINT fk_doctor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch(Throwable $e){/* ignore */}
  }

  // harmonize doctors.personal_id -> VARCHAR(11) NULL, empty -> NULL, unique index
  if(!empty($cols['personal_id'])){
    $col = $pdo->query("SHOW COLUMNS FROM doctors LIKE 'personal_id'")->fetch(PDO::FETCH_ASSOC);
    if(!$col || !isset($col['Type']) || !preg_match('/^varchar\(11\)/i', $col['Type'])){
      $pdo->exec("ALTER TABLE doctors MODIFY personal_id VARCHAR(11) NULL");
    }
    // convert '' to NULL to avoid duplicate '' on UNIQUE
    $pdo->exec("UPDATE doctors SET personal_id=NULL WHERE personal_id=''");
    // add UNIQUE index if missing
    $idx = $pdo->query("SHOW INDEX FROM doctors WHERE Key_name='ux_doctors_personal_id'")->fetch(PDO::FETCH_ASSOC);
    if(!$idx){
      try { $pdo->exec("CREATE UNIQUE INDEX ux_doctors_personal_id ON doctors(personal_id)"); } catch(Throwable $e){ /* ignore */ }
    }
  }
} catch(Throwable $e){ /* ignore schema patch errors silently */ }

// ---------- PRELOAD Branches / Departments ----------
$branches = []; $departments = [];
try { $branches = $pdo->query("SELECT id,name FROM branches WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){ $branches=[]; }
try { $departments = $pdo->query("SELECT id,branch_id,name FROM departments WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){ $departments=[]; }

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD']==='POST'){
  // CSRF
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $_SESSION['flash']='უსაფრთხოების შეცდომა (CSRF). სცადეთ თავიდან.'; header('Location: doctors.php'); exit;
  }

  $action = $_POST['action'] ?? '';

  // ---------- ADD DOCTOR ----------
  if ($action==='add_doctor'){
    $personal_id     = post_trim('personal_id');
    $personal_id     = normalize_personal_id($personal_id);

    $first_name      = post_trim('first_name');
    $last_name       = post_trim('last_name');
    $father_name     = post_trim('father_name');

    $birthdate_in = trim($_POST['birthdate'] ?? '');
    $birthdate    = is_valid_date($birthdate_in) ? $birthdate_in : null; // ცარიელი/არასწორი -> NULL

    $gender          = $_POST['gender'] ?? '';
    $address         = post_trim('address');
    $mobile          = post_trim('mobile');
    $telephone       = post_trim('telephone');
    $email           = valid_email_or_empty($_POST['email'] ?? '');
    $family_status   = post_trim('family_status');
    $accounting_code = post_trim('accounting_code');
    $bank_account    = post_trim('bank_account');
    $status          = post_trim('status','აქტიური');

    // Access fields
    $grant_access    = isset($_POST['grant_access']) && $_POST['grant_access']==='1';
    $username        = post_trim('username');
    $password1       = $_POST['password1'] ?? '';
    $password2       = $_POST['password2'] ?? '';
    $branch_id       = (int)($_POST['branch_id'] ?? 0);
    $department_id   = (int)($_POST['department_id'] ?? 0);

    // Validation
    $errs=[];
    if(!$first_name || !$last_name){ $errs[]='სახელი და გვარი სავალდებულოა.'; }
    if(!$gender){ $errs[]='სქესი სავალდებულოა.'; }

    if($personal_id!==''){
      if(!only_digits($personal_id) || strlen($personal_id)!==GE_PERSONAL_ID_LEN){
        $errs[]='პირადი № უნდა იყოს ზუსტად 11 ციფრი.';
      }
    }
    if(($_POST['email'] ?? '')!=='' && $email===''){ $errs[]='იმეილის ფორმატი არასწორია.'; }

    if($grant_access){
      if(strlen($username)<3){ $errs[]='Username უნდა იყოს მინ. 3 სიმბოლო.'; }
      if(strlen($password1)<8){ $errs[]='პაროლი უნდა იყოს მინ. 8 სიმბოლო.'; }
      if($password1!==$password2){ $errs[]='პაროლები არ ემთხვევა.'; }
    } else {
      if(($username!=='' || $password1!=='' || $password2!=='') && !$grant_access){
        $errs[]='ჩართე „სისტემაში დაშვება“ თუ გინდა მომხმარებლის შექმნა.';
      }
    }

    if($errs){ $_SESSION['flash']=implode(' ',$errs); header('Location: doctors.php'); exit; }

    try{
      $pdo->beginTransaction();

      if($personal_id!==''){
        $c=$pdo->prepare("SELECT id FROM doctors WHERE personal_id=? LIMIT 1");
        $c->execute([$personal_id]);
        if($c->fetch()){ throw new RuntimeException('ასეთი პირადი № უკვე არსებობს.'); }
      }

      $user_id = null;
      if($grant_access){
        $cu=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $cu->execute([$username]);
        if($cu->fetch()){ throw new RuntimeException('ასეთი username უკვე არსებობს.'); }
        $hash = password_hash($password1, PASSWORD_BCRYPT, ['cost'=>12]);
        $iu=$pdo->prepare("INSERT INTO users(username,password_hash,role) VALUES(?,?,?)");
        $iu->execute([$username,$hash,'doctor']);
        $user_id = (int)$pdo->lastInsertId();
      }

      $personal_id_db = ($personal_id==='') ? null : $personal_id;

      $idStmt=$pdo->prepare("
        INSERT INTO doctors (
          personal_id, first_name, last_name, father_name, birthdate, gender,
          address, mobile, telephone, email, family_status, accounting_code, bank_account, status, user_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $idStmt->execute([
        $personal_id_db, $first_name, $last_name, $father_name, $birthdate, $gender,
        $address, $mobile, $telephone, $email, $family_status, $accounting_code, $bank_account, $status, $user_id
      ]);
      $doctor_id = (int)$pdo->lastInsertId();

      if($branch_id>0 && $department_id>0){
        $check=$pdo->prepare("SELECT 1 FROM departments WHERE id=? AND branch_id=?");
        $check->execute([$department_id,$branch_id]);
        if($check->fetch()){
          $pdo->prepare("INSERT INTO doctor_department(doctor_id,branch_id,department_id) VALUES(?,?,?) 
                         ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id), department_id=VALUES(department_id)")
              ->execute([$doctor_id,$branch_id,$department_id]);
        }
      }

      $pdo->commit();
      $_SESSION['flash']='ექიმი წარმატებით დაემატა'.($grant_access?' და მომხმარებელი შეიქმნა.':'.');
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash']='დამატების შეცდომა: '.$e->getMessage();
    }
    header('Location: doctors.php'); exit;
  }

  // ---------- EDIT DOCTOR ----------
  if ($action==='edit_doctor'){
    $id              = (int)($_POST['edit_id'] ?? 0);
    $personal_id     = post_trim('personal_id');
    $personal_id     = normalize_personal_id($personal_id);

    $first_name      = post_trim('first_name');
    $last_name       = post_trim('last_name');
    $father_name     = post_trim('father_name');

    $birthdate_in    = trim($_POST['birthdate'] ?? '');
    $birthdate       = is_valid_date($birthdate_in) ? $birthdate_in : null;

    $gender          = $_POST['gender'] ?? '';
    $address         = post_trim('address');
    $mobile          = post_trim('mobile');
    $telephone       = post_trim('telephone');
    $email           = valid_email_or_empty($_POST['email'] ?? '');
    $family_status   = post_trim('family_status');
    $accounting_code = post_trim('accounting_code');
    $bank_account    = post_trim('bank_account');
    $status          = post_trim('status','აქტიური');

    $branch_id       = (int)($_POST['branch_id'] ?? 0);
    $department_id   = (int)($_POST['department_id'] ?? 0);

    $current_user_id = (int)($_POST['user_id'] ?? 0);
    $username        = post_trim('username');
    $new_pass1       = $_POST['new_password1'] ?? '';
    $new_pass2       = $_POST['new_password2'] ?? '';
    $want_access     = isset($_POST['grant_access']) && $_POST['grant_access']==='1';

    $errs=[];
    if($id<=0){ $errs[]='არასწორი ID.'; }
    if(!$first_name || !$last_name){ $errs[]='სახელი და გვარი სავალდებულოა.'; }
    if(!$gender){ $errs[]='სქესი სავალდებულოა.'; }
    if($personal_id!==''){
      if(!only_digits($personal_id) || strlen($personal_id)!==GE_PERSONAL_ID_LEN){
        $errs[]='პირადი № უნდა იყოს ზუსტად 11 ციფრი.';
      }
    }
    if(($_POST['email'] ?? '')!=='' && $email===''){ $errs[]='იმეილის ფორმატი არასწორია.'; }

    if($want_access){
      if(strlen($username)<3){ $errs[]='Username უნდა იყოს მინ. 3 სიმბოლო.'; }
      if(($new_pass1!=='' || $new_pass2!=='') && strlen($new_pass1)<8){ $errs[]='ახალი პაროლი მინ. 8 სიმბოლო.'; }
      if($new_pass1!==$new_pass2){ $errs[]='ახალი პაროლები არ ემთხვევა.'; }
    }

    if($errs){ $_SESSION['flash']=implode(' ',$errs); header('Location: doctors.php'); exit; }

    try{
      $pdo->beginTransaction();

      if($personal_id!==''){
        $c=$pdo->prepare("SELECT id FROM doctors WHERE personal_id=? AND id<>? LIMIT 1");
        $c->execute([$personal_id,$id]);
        if($c->fetch()){ throw new RuntimeException('ასეთი პირადი № უკვე მიბმულია სხვა ჩანაწერზე.'); }
      }

      $user_id = $current_user_id ?: null;
      if($want_access){
        if($user_id){
          $cu=$pdo->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
          $cu->execute([$username,$user_id]);
          if($cu->fetch()){ throw new RuntimeException('ასეთი username უკვე არსებობს.'); }
          $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username,$user_id]);
          if($new_pass1!==''){
            $hash=password_hash($new_pass1,PASSWORD_BCRYPT,['cost'=>12]);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$user_id]);
          }
        } else {
          $cu=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
          $cu->execute([$username]);
          if($cu->fetch()){ throw new RuntimeException('ასეთი username უკვე არსებობს.'); }
          $gen = ($new_pass1!=='') ? $new_pass1 : bin2hex(random_bytes(6));
          $hash=password_hash($gen, PASSWORD_BCRYPT, ['cost'=>12]);
          $pdo->prepare("INSERT INTO users(username,password_hash,role) VALUES(?,?,?)")->execute([$username,$hash,'doctor']);
          $user_id = (int)$pdo->lastInsertId();
        }
      } else {
        $user_id = null; // just unlink
      }

      $personal_id_db = ($personal_id==='') ? null : $personal_id;

      $u=$pdo->prepare("
        UPDATE doctors SET
          personal_id=?, first_name=?, last_name=?, father_name=?, birthdate=?, gender=?,
          address=?, mobile=?, telephone=?, email=?,
          family_status=?, accounting_code=?, bank_account=?, status=?, user_id=?
        WHERE id=?
      ");
      $u->execute([
        $personal_id_db,$first_name,$last_name,$father_name,$birthdate,$gender,
        $address,$mobile,$telephone,$email,
        $family_status,$accounting_code,$bank_account,$status,$user_id,$id
      ]);

      $pdo->prepare("DELETE FROM doctor_department WHERE doctor_id=?")->execute([$id]);
      if($branch_id>0 && $department_id>0){
        $check=$pdo->prepare("SELECT 1 FROM departments WHERE id=? AND branch_id=?");
        $check->execute([$department_id,$branch_id]);
        if($check->fetch()){
          $pdo->prepare("INSERT INTO doctor_department(doctor_id,branch_id,department_id) VALUES(?,?,?)")
              ->execute([$id,$branch_id,$department_id]);
        }
      }

      $pdo->commit();
      $_SESSION['flash']='ცვლილებები შენახულია.';
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash']='განახლების შეცდომა: '.$e->getMessage();
    }
    header('Location: doctors.php'); exit;
  }

  // ---------- ARCHIVE/RESTORE ----------
  if($action==='toggle_archive'){
    $id=(int)($_POST['id']??0);
    $to = ($_POST['to']??'')==='წაშლილი' ? 'წაშლილი' : 'აქტიური';
    if($id>0){
      try{
        $pdo->prepare("UPDATE doctors SET status=? WHERE id=?")->execute([$to,$id]);
        $_SESSION['flash']= ($to==='წაშლილი')?'ჩანაწერი დაარქივდა.':'ჩანაწერი აღდგა.';
      }catch(Throwable $e){ $_SESSION['flash']='სტატუსის შეცვლის შეცდომა: '.$e->getMessage(); }
    }
    header('Location: doctors.php'); exit;
  }

  // ---------- HARD DELETE ----------
  if($action==='delete_doctor'){
    $id=(int)($_POST['delete_id']??0);
    if($id){
      try {
        $pdo->prepare("DELETE FROM doctors WHERE id=?")->execute([$id]);
        $_SESSION['flash']='ჩანაწერი წაიშალა.';
      } catch(Throwable $e){ $_SESSION['flash']='წაშლლის შეცდომა: '.$e->getMessage(); }
    }
    header('Location: doctors.php'); exit;
  }

  // ---------- EXPORT EXCEL ----------
  if($action==='export_xls'){
    header('Location: doctors.php?export=1&'.http_build_query([
      'q'=>$_POST['q']??'','status'=>$_POST['status']??'','gender'=>$_POST['gender']??'',
      'bd_from'=>$_POST['bd_from']??'','bd_to'=>$_POST['bd_to']??'',
      'sort'=>$_POST['sort']??'id','dir'=>$_POST['dir']??'desc','per'=>$_POST['per']??'20','page'=>$_POST['page']??'1'
    ]));
    exit;
  }
}

// ---------- FILTERS / SORT / PAGINATION ----------
$q       = trim((string)($_GET['q'] ?? ''));
$statusF = trim((string)($_GET['status'] ?? ''));
$genderF = trim((string)($_GET['gender'] ?? ''));
$bd_from = trim((string)($_GET['bd_from'] ?? ''));
$bd_to   = trim((string)($_GET['bd_to'] ?? ''));

$allowedSort = [
  'id'=>'d.id','first_name'=>'d.first_name','last_name'=>'d.last_name','personal_id'=>'d.personal_id',
  'birthdate'=>'d.birthdate','gender'=>'d.gender','status'=>'d.status','username'=>'u.username'
];
$sortKey = $_GET['sort'] ?? 'id';
$sort = $allowedSort[$sortKey] ?? 'd.id';
$dir  = (strtolower($_GET['dir'] ?? 'desc')==='asc') ? 'ASC':'DESC';

$page = max(1,(int)($_GET['page'] ?? 1));
$perPage=(int)($_GET['per'] ?? 20);
if($perPage<10) $perPage=10; if($perPage>100) $perPage=100;
$offset = ($page-1)*$perPage;

// WHERE
$where=[]; $args=[];
if($q!==''){
  $where[]="(d.first_name LIKE ? OR d.last_name LIKE ? OR d.personal_id LIKE ? OR d.mobile LIKE ? OR d.telephone LIKE ? OR d.email LIKE ? OR u.username LIKE ?)";
  $like="%$q%"; array_push($args,$like,$like,$like,$like,$like,$like,$like);
}
if($statusF!==''){ $where[]="d.status=?"; $args[]=$statusF; }
if($genderF!==''){ $where[]="d.gender=?"; $args[]=$genderF; }
if(is_valid_date($bd_from)){ $where[]="d.birthdate>=?"; $args[]=$bd_from; }
if(is_valid_date($bd_to)){ $where[]="d.birthdate<=?"; $args[]=$bd_to; }

$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// COUNT
$total=0;
try{
  $st=$pdo->prepare("SELECT COUNT(*) 
                     FROM doctors d 
                     LEFT JOIN users u ON u.id=d.user_id
                     $whereSql");
  $st->execute($args); $total=(int)$st->fetchColumn();
}catch(Throwable $e){ $error='მონაცემების კითხვა ვერ მოხერხდა: '.$e->getMessage(); }

// ---------- EXPORT (Excel .xls) ----------
if(isset($_GET['export']) && $_GET['export']=='1'){
  try{
    $sql="SELECT d.id,d.personal_id,d.first_name,d.last_name,d.father_name,d.birthdate,d.gender,
                 d.address,d.mobile,d.telephone,d.email,d.family_status,d.accounting_code,d.bank_account,
                 d.status,u.username,
                 (SELECT CONCAT(b.name,' / ',dep.name) 
                    FROM doctor_department dd
                    JOIN branches b ON b.id=dd.branch_id
                    JOIN departments dep ON dep.id=dd.department_id
                   WHERE dd.doctor_id=d.id LIMIT 1) AS dept_path
          FROM doctors d 
          LEFT JOIN users u ON u.id=d.user_id
          $whereSql
          ORDER BY $sort $dir";
    $st=$pdo->prepare($sql); $st->execute($args);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header('Content-Disposition: attachment; filename="doctors_'.date('Ymd_His').'.xls"');
    echo "\xEF\xBB\xBF"; // BOM
    echo "<table border='1'>";
    if($rows){
      echo "<tr>";
      foreach(array_keys($rows[0]) as $col){ echo "<th>".h($col)."</th>"; }
      echo "</tr>";
      foreach($rows as $r){
        echo "<tr>";
        foreach($r as $v){ echo "<td>".h($v)."</td>"; }
        echo "</tr>";
      }
    } else {
      echo "<tr><td>ჩანაწერები ვერ მოიძებნა</td></tr>";
    }
    echo "</table>";
    exit;
  }catch(Throwable $e){
    $error='Excel ექსპორტის შეცდომა: '.$e->getMessage();
  }
}

// LIST PAGE
$doctors=[];
try{
  $sql="SELECT 
           d.*,
           u.username, u.role AS user_role,
           (SELECT dd.branch_id FROM doctor_department dd WHERE dd.doctor_id=d.id LIMIT 1) AS branch_id,
           (SELECT dd.department_id FROM doctor_department dd WHERE dd.doctor_id=d.id LIMIT 1) AS department_id,
           (SELECT CONCAT(b.name,' / ',dep.name) 
              FROM doctor_department dd
              JOIN branches b ON b.id=dd.branch_id
              JOIN departments dep ON dep.id=dd.department_id
             WHERE dd.doctor_id=d.id LIMIT 1) AS dept_path
        FROM doctors d
        LEFT JOIN users u ON u.id=d.user_id
        $whereSql
        ORDER BY $sort $dir
        LIMIT $perPage OFFSET $offset";
  $st=$pdo->prepare($sql); $st->execute($args);
  $doctors=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){ $error='მონაცემების კითხვა ვერ მოხერხდა: '.$e->getMessage(); }

$pages = max(1,(int)ceil($total/$perPage));
$cur = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ka">
<head>
<meta charset="UTF-8">
<title>SanMedic – HR (Doctors)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- Google Fonts - Noto Sans Georgian -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
<style>
/* CORE / NAV / LAYOUT */
body{font-family:"Noto Sans Georgian",sans-serif;background:#f9f8f2;color:#222;margin:0;min-height:100vh;}
.topbar{background:#21c1a6;color:#fff;padding:10px 40px;display:flex;justify-content:flex-end;align-items:center;font-size:15px;}
.user-menu-wrap{display:flex;align-items:center;gap:12px;position:relative;}
.user-btn{display:flex;align-items:center;gap:8px;color:#21c1a6;cursor:pointer;font-size:16px;background:#f9f9f9;border-radius:18px;padding:6px 18px;border:1.5px solid #e0e0e0;}
.user-btn:hover,.user-btn.open{background:#eafcf8;}
.user-dropdown{position:absolute;top:44px;min-width:140px;background:#fff;border:1.5px solid #e0e0e0;border-radius:10px;box-shadow:0 4px 18px rgba(23,60,84,.07);display:none;flex-direction:column;padding:10px 0;z-index:2222;}
.user-dropdown a{padding:8px 16px;color:#20756b;text-decoration:none;display:flex;align-items:center;gap:9px;font-size:15px;}
.user-dropdown a:hover{background:#f7fdf7;color:#21c1a6;}
.logout-btn{background:#f4f6f7;color:#e74c3c;border:1.5px solid #e0e0e0;border-radius:18px;padding:6px 16px;font-size:14.5px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;}
.logout-btn:hover{background:#ffeaea;color:#c0392b;border-color:#e3b4b1;}
#upnav.upnav{margin-top:10px;display:flex;gap:12px;border-bottom:2px solid #ddd;padding:6px 40px;}
#upnav.upnav a{text-decoration:none;color:#21c1a6;padding:6px 12პx;border-radius:4px;font-weight:600;}
#upnav.upnav a.active,#upnav.upnav a:hover,#upnav.upnav a:focus{background:#21c1a6;color:#fff;outline:none;}
.container{max-width:1650px;margin:28px auto 48px auto;padding:0 40px;}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:700;border:1px solid #e4e4e4;background:#fff;}
.badge.active{color:#0b8a76;border-color:#bdebe4;background:#eafcf8;}
.badge.arch{color:#8a5a0b;border-color:#f1d7a3;background:#fff9ea;}
.flash{margin:8px 0 0 0;color:#0ბ8ა76;font-weight:700}
.error{color:#e74c3c;font-size:15px;font-weight:600;margin:8px 0;}
.panel{background:#fff;border:1.5px solid #e4e4e4;border-radius:8px;margin-bottom:22px;box-shadow:0 2px 10px rgba(31,61,124,0.05);}
.panel-header{background:#f7f7f5;padding:14px 24px;font-size:1.02em;font-weight:700;border-bottom:1px solid #ededed;display:flex;justify-content:space-between;align-items:center;}
.panel-body{padding:16px 24px;}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.form-group{display:flex;flex-direction:column;min-width:170px;max-width:300px;}
.form-group label{color:#414;font-size:13.5px;margin-bottom:5px;font-weight:600;}
.form-group input,.form-group select{width:100%;padding:9px 11px;border:1.2px solid #cdd5de;border-radius:5px;font-size:14px;background:#f9f9f9;outline:none;}
.form-group input:focus,.form-group select:focus{border:1.7px solid #21c1a6;background:#fff;}
.btn-main{padding:10px 20px;background:#21c1a6;color:#fff;border:none;border-radius:5px;font-size:15px;font-weight:700;cursor:pointer;}
.btn-main:hover{background:#18a591;}
.btn-light{padding:9px 14px;background:#f5f7f8;border:1px solid #e2e6ea;border-radius:5px;cursor:pointer;}
.btn-light:hover{background:#eef2f4;}
.table-wrap{overflow-x:auto;}
.doctors-table{width:100%;border-collapse:collapse;font-size:14.5px;border-radius:6px;overflow:hidden;background:#fff;box-shadow:0 1px 8px rgba(31,61,124,0.04);min-width:1050px;}
.doctors-table th,.doctors-table td{border-bottom:1px solid #ececec;padding:10px;text-align:left;}
.doctors-table th{background:#21c1a6;color:#fff;font-size:14.5px;font-weight:800;}
.doctors-table tbody tr:nth-child(odd){background:#f7fdf7;}
.doctors-table tbody tr:hover{background:#e6fbf3;}
.actions{white-space:nowrap;display:flex;gap:6px;align-items:center;}
.actions button{background:none;border:none;cursor:pointer;padding:4px 6px;color:#21c1a6;font-size:17px;}
.actions button:hover{color:#e67e22;}
.actions .danger:hover{color:#e74c3c;}
.pagination{display:flex;gap:6px;ფlex-wrap:wrap;align-items:center;margin:12px 0;}
.page-link{display:inline-block;padding:6px 10px;border:1px solid #e0e0e0;border-radius:5px;text-decoration:none;color:#20756b;background:#fff;}
.page-link.active{background:#21c1a6;color:#fff;border-color:#21c1a6;}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;justify-content:center;align-items:center;z-index:10000;backdrop-filter:blur(4px);}
.modal-content{background:#fff;border-radius:12px;max-width:900px;width:92%;padding:24px;box-shadow:0 10px 25px rgba(0,0,0,.1);position:relative;overflow-y:auto;max-height:90vh;}
.close-x{position:absolute;top:10px;right:12px;background:none;border:none;font-size:28px;color:#aaa;cursor:pointer;}
.close-x:hover{color:#555;}
.Ctable{width:100%;border-collapse:collapse;font-size:14.5px;}
.Ctable th,.Ctable td{border:1px solid #ddd;padding:8px 10px;text-align:left;}
.Ctable tr:nth-child(even){background:#f9f9f9;}
.hrline{height:1px;background:#eee;margin:8px 0 4px}
small.muted{color:#777}
@media(max-width:700px){ .row{gap:10px}.form-group{max-width:100%}.table-wrap{margin:0 -8px} }
</style>
  <link rel="stylesheet" href="/css/preclinic-theme.css">
</head>
<body>

<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:40px;width:auto;margin-right:12px;background:#fff;padding:4px 8px;border-radius:6px;">
    </a>
  <div class="user-menu-wrap" tabindex="0">
    <div class="user-btn" tabindex="0">
      <i class="fas fa-user-circle"></i>
      <span><?=h($_SESSION['username'] ?? '')?></span>
      <i class="fas fa-chevron-down"></i>
    </div>
    <div class="user-dropdown" tabindex="-1">
      <a href="#" id="telLink"><i class="fas fa-phone"></i> ტელ</a>
      <a href="profile.php"><i class="fas fa-user"></i> პროფილი</a>
    </div>
    <a href="logout.php" class="logout-btn" title="გამოსვლა"><i class="fas fa-sign-out-alt"></i> გამოსვლა</a>
  </div>
</div>

<nav id="upnav" class="upnav">
  <a href="dashboard.php" class="<?= $cur==='dashboard.php' ? 'active' : '' ?>">მთავარი</a>
  <a href="doctors.php"   class="<?= $cur==='doctors.php'   ? 'active' : '' ?>">HR</a>
  <a href="journal.php"   class="<?= $cur==='journal.php'   ? 'active' : '' ?>">რეპორტი</a>
      <a href="test.php"   class="<?= $cur=='test.php'   ? 'active' : '' ?>">გრაფიკი</a>
</nav>

<div class="container">

  <?php if ($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
  <?php if ($error): ?><div class="error"><?=h($error)?></div><?php endif; ?>

  <!-- QUICK ACTION BAR (Export only) -->
  <div class="panel">
    <div class="panel-header">
      <div>ექიმები</div>
      <form method="post" style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="hidden" name="csrf" value="<?=$CSRF?>">
        <input type="hidden" name="action" value="export_xls">
        <input type="hidden" name="q" value="<?=h($q)?>">
        <input type="hidden" name="status" value="<?=h($statusF)?>">
        <input type="hidden" name="gender" value="<?=h($genderF)?>">
        <input type="hidden" name="bd_from" value="<?=h($bd_from)?>">
        <input type="hidden" name="bd_to" value="<?=h($bd_to)?>">
        <input type="hidden" name="sort" value="<?=h($sortKey)?>">
        <input type="hidden" name="dir" value="<?=strtolower($dir)==='asc'?'asc':'desc'?>">
        <input type="hidden" name="per" value="<?=h($perPage)?>">
        <input type="hidden" name="page" value="<?=h($page)?>">
        <button class="btn-light" type="submit" title="Excel ექსპორტი"><i class="fa-solid fa-file-excel"></i> Excel</button>
      </form>
    </div>
    <div class="panel-body" style="display:none;"></div>
  </div>

  <!-- ADD DOCTOR -->
  <div id="add-panel" class="panel">
    <div class="panel-header">ექიმის დამატება</div>
    <form method="post" class="panel-body">
      <input type="hidden" name="csrf" value="<?=$CSRF?>">
      <input type="hidden" name="action" value="add_doctor">
      <div class="row">
        <div class="form-group"><label>სახელი*</label><input type="text" name="first_name" required></div>
        <div class="form-group"><label>გვარი*</label><input type="text" name="last_name" required></div>
        <div class="form-group">
          <label>პირადი №</label>
          <input type="text" name="personal_id" maxlength="11" inputmode="numeric" pattern="\d{11}">
        </div>
        <div class="form-group"><label>დაბადების თარიღი</label><input type="date" name="birthdate"></div>
        <div class="form-group">
          <label>სქესი*</label>
          <select name="gender" required>
            <option value="">–</option>
            <option value="მამრობითი">მამრობითი</option>
            <option value="მდედრობითი">მდედრობითი</option>
          </select>
        </div>
        <div class="form-group"><label>მისამართი</label><input type="text" name="address"></div>
        <div class="form-group"><label>მამის სახელი</label><input type="text" name="father_name"></div>
        <div class="form-group"><label>მობილური</label><input type="tel" name="mobile" placeholder="555 12 34 56"></div>
        <div class="form-group"><label>ტელეფონი</label><input type="tel" name="telephone"></div>
        <div class="form-group"><label>იმეილი</label><input type="email" name="email"></div>
        <div class="form-group">
          <label>ოჯახი</label>
          <select name="family_status">
            <option value=""></option>
            <option value="დასაოჯახებელი">დასაოჯახებელი</option>
            <option value="დაოჯახებული">დაოჯახებული</option>
            <option value="განქორწინებული">განქორწინებული</option>
            <option value="ქვრივი">ქვრივი</option>
            <option value="თანაცხოვრებაში მყოფი">თანაცხოვრებაში მყოფი</option>
          </select>
        </div>
        <div class="form-group"><label>საბუღალტრო კოდი</label><input type="text" name="accounting_code"></div>
        <div class="form-group"><label>საბანკო ანგარიში</label><input type="text" name="bank_account"></div>
        <div class="form-group">
          <label>სტატუსი</label>
          <select name="status">
            <option value="აქტიური" selected>აქტიური</option>
            <option value="წაშლილი">წაშლილი</option>
          </select>
        </div>
      </div>

      <div class="hrline"></div>
      <div class="row">
        <div class="form-group" style="min-width:220px;">
          <label>ფილიალი (Branch)</label>
          <select name="branch_id" id="add_branch_id">
            <option value="0">— არ არის —</option>
            <?php foreach($branches as $b): ?>
              <option value="<?=$b['id']?>"><?=h($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="min-width:260px;">
          <label>დეპარტამენტი</label>
          <select name="department_id" id="add_department_id">
            <option value="0">— აირჩიე ფილიალი —</option>
            <?php foreach($departments as $d): ?>
              <option data-branch="<?=$d['branch_id']?>" value="<?=$d['id']?>"><?=h($d['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="hrline"></div>
      <div class="row">
        <div class="form-group" style="min-width:220px;">
          <label><input type="checkbox" id="grant_access" name="grant_access" value="1"> სისტემაში დაშვება</label>
          <small class="muted">ჩართე, თუ გინდა ექიმისთვის შეიქმნას მომხმარებელი.</small>
        </div>
        <div class="form-group access-field"><label>Username</label><input type="text" name="username" id="username" disabled></div>
        <div class="form-group access-field"><label>პაროლი</label><input type="password" name="password1" id="password1" minlength="8" disabled></div>
        <div class="form-group access-field"><label>გაიმეორე პაროლი</label><input type="password" name="password2" id="password2" minlength="8" disabled></div>
        <div class="form-group access-field" style="max-width:170px;">
          <label>&nbsp;</label>
          <button type="button" class="btn-light" id="genPwdBtn" disabled>Generate</button>
        </div>
      </div>

      <div style="margin-top:10px"><button class="btn-main" type="submit"><i class="fas fa-user-plus"></i> დამატება</button></div>
    </form>
  </div>

  <!-- LIST -->
  <div class="panel">
    <div class="panel-header">
      <div>სია <span class="badge <?= $statusF==='წაშლილი'?'arch':'active'?>"><?=h($total)?> ჩანაწერი</span></div>
      <div>
        დალაგება:
        <?php
          $newDir = ($dir==='ASC')?'desc':'asc';
          $mk=function($field)use($q,$statusF,$genderF,$bd_from,$bd_to,$perPage,$page,$newDir){
            return 'doctors.php?'.http_build_query([
              'q'=>$q,'status'=>$statusF,'gender'=>$genderF,'bd_from'=>$bd_from,'bd_to'=>$bd_to,
              'per'=>$perPage,'page'=>$page,'sort'=>$field,'dir'=>$newDir
            ]);
          };
        ?>
        <a class="page-link" href="<?=$mk('id')?>">ID</a>
        <a class="page-link" href="<?=$mk('last_name')?>">გვარი</a>
        <a class="page-link" href="<?=$mk('first_name')?>">სახელი</a>
        <a class="page-link" href="<?=$mk('personal_id')?>">პირადი №</a>
        <a class="page-link" href="<?=$mk('username')?>">Username</a>
        <a class="page-link" href="<?=$mk('birthdate')?>">დაბ. თარიღი</a>
        <a class="page-link" href="<?=$mk('status')?>">სტატუსი</a>
      </div>
    </div>

    <div class="panel-body table-wrap">
      <table class="doctors-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>პირადი №</th>
            <th>სახელი</th>
            <th>გვარი</th>
            <th>დაბ. თარიღი</th>
            <th>სქესი</th>
            <th>ტელეფონი</th>
            <th>იმეილი</th>
            <th>Username</th>
            <th>ფილიალი/დეპარტ.</th>
            <th>სტატუსი</th>
            <th>მოქმედებები</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($doctors): foreach($doctors as $d): ?>
          <tr>
            <td><?=h($d['id'])?></td>
            <td><?=h($d['personal_id'])?></td>
            <td><?=h($d['first_name'])?></td>
            <td><?=h($d['last_name'])?></td>
            <td><?=h($d['birthdate'])?></td>
            <td><?=h($d['gender'])?></td>
            <td><?=h(trim($d['mobile'] ?? '') ?: ($d['telephone'] ?? ''))?></td>
            <td><?=h($d['email'])?></td>
            <td><?=h($d['username'] ?? '')?></td>
            <td><?=h($d['dept_path'] ?? '')?></td>
            <td><?=h($d['status'])?></td>
            <td class="actions" onclick="event.stopPropagation();">
              <button title="რედაქტირება"
                onclick='openEditModal(<?=json_encode($d, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>)'>
                <i class="fas fa-pen"></i>
              </button>

              <!-- Archive/Restore -->
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?=$CSRF?>">
                <input type="hidden" name="action" value="toggle_archive">
                <input type="hidden" name="id" value="<?=h($d['id'])?>">
                <?php if (($d['status'] ?? '') === 'წაშლილი'): ?>
                  <input type="hidden" name="to" value="აქტიური">
                  <button type="submit" title="აღდგენა"><i class="fa-solid fa-rotate-left"></i></button>
                <?php else: ?>
                  <input type="hidden" name="to" value="წაშლილი">
                  <button type="submit" title="დაარქივება"><i class="fa-regular fa-folder"></i></button>
                <?php endif; ?>
              </form>

              <!-- Hard delete -->
              <form method="post" style="display:inline;" onsubmit="return hardDeleteConfirm();">
                <input type="hidden" name="csrf" value="<?=$CSRF?>">
                <input type="hidden" name="action" value="delete_doctor">
                <input type="hidden" name="delete_id" value="<?=h($d['id'])?>">
                <button class="danger" type="submit" title="წაშლა"><i class="fas fa-times-circle"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="12" style="text-align:center;color:#888;padding:18px;">ჩანაწერი ვერ მოიძებნა</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <div class="pagination">
        <?php
          $link=function($p)use($q,$statusF,$genderF,$bd_from,$bd_to,$perPage,$sortKey,$dir){
            return 'doctors.php?'.http_build_query([
              'q'=>$q,'status'=>$statusF,'gender'=>$genderF,'bd_from'=>$bd_from,'bd_to'=>$bd_to,
              'per'=>$perPage,'page'=>$p,'sort'=>$sortKey,'dir'=>strtolower($dir)
            ]);
          };
          if($page>1) echo '<a class="page-link" href="'.h($link(1)).'">&laquo; პირველი</a>';
          if($page>1) echo '<a class="page-link" href="'.h($link($page-1)).'">&lsaquo; უკან</a>';
          for($p=max(1,$page-3);$p<=min($pages,$page+3);$p++){
            $cls=$p==$page?'page-link active':'page-link';
            echo '<a class="'.$cls.'" href="'.h($link($p)).'">'.h($p).'</a>';
          }
          if($page<$pages) echo '<a class="page-link" href="'.h($link($page+1)).'">შემდეგ &rsaquo;</a>';
          if($page<$pages) echo '<a class="page-link" href="'.h($link($pages)).'">ბოლო &raquo;</a>';
        ?>
      </div>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="overlay" aria-hidden="true">
  <div class="modal-content">
    <button class="close-x" onclick="closeEditModal()" title="დახურვა">×</button>
    <h2 style="margin:0 0 14px;color:#21c1a6">ექიმის რედაქტირება</h2>
    <form id="editForm" method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:14px 22px;">
      <input type="hidden" name="csrf" value="<?=$CSRF?>">
      <input type="hidden" name="action" value="edit_doctor">
      <input type="hidden" name="edit_id" id="edit_id">
      <input type="hidden" name="user_id" id="user_id">

      <div class="form-group"><label>ID</label><input type="text" id="id_readonly" readonly></div>
      <div class="form-group">
        <label>პირადი №</label>
        <input type="text" name="personal_id" id="personal_id" maxlength="11" inputmode="numeric" pattern="\d{11}">
      </div>
      <div class="form-group"><label>სახელი*</label><input type="text" name="first_name" id="first_name" required></div>
      <div class="form-group"><label>გვარი*</label><input type="text" name="last_name" id="last_name" required></div>
      <div class="form-group"><label>მამის სახელი</label><input type="text" name="father_name" id="father_name"></div>
      <div class="form-group"><label>დაბადების თარიღი</label><input type="date" name="birthdate" id="birthdate"></div>
      <div class="form-group"><label>სქესი*</label>
        <select name="gender" id="gender" required>
          <option value="">–</option>
          <option value="მამრობითი">მამრობითი</option>
          <option value="მდედრობითი">მდედრობითი</option>
        </select>
      </div>
      <div class="form-group"><label>მისამართი</label><input type="text" name="address" id="address"></div>
      <div class="form-group"><label>მობილური</label><input type="tel" name="mobile" id="mobile"></div>
      <div class="form-group"><label>ტელეფონი</label><input type="tel" name="telephone" id="telephone"></div>
      <div class="form-group"><label>იმეილი</label><input type="email" name="email" id="email"></div>
      <div class="form-group"><label>ოჯახი</label>
        <select name="family_status" id="family_status">
          <option value=""></option>
          <option value="დასაოჯახებელი">დასაოჯახებელი</option>
          <option value="დაოჯახებული">დაოჯახებული</option>
          <option value="განქორწინებული">განქორწინებული</option>
          <option value="ქვრივი">ქვრივი</option>
          <option value="თანაცხოვრებაში მყოფი">თანაცხოვრებაში მყოფი</option>
        </select>
      </div>
      <div class="form-group"><label>საბუღალტრო კოდი</label><input type="text" name="accounting_code" id="accounting_code"></div>
      <div class="form-group"><label>საბანკო ანგარიში</label><input type="text" name="bank_account" id="bank_account"></div>

      <div class="form-group"><label>ფილიალი</label>
        <select name="branch_id" id="edit_branch_id">
          <option value="0">— არ არის —</option>
          <?php foreach($branches as $b): ?>
            <option value="<?=$b['id']?>"><?=h($b['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>დეპარტამენტი</label>
        <select name="department_id" id="edit_department_id">
          <option value="0">— აირჩიე ფილიალი —</option>
          <?php foreach($departments as $d): ?>
            <option data-branch="<?=$d['branch_id']?>" value="<?=$d['id']?>"><?=h($d['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group"><label>სტატუსი</label>
        <select name="status" id="status">
          <option value="აქტიური">აქტიური</option>
          <option value="წაშლილი">წაშლილი</option>
        </select>
      </div>

      <div class="form-group" style="grid-column:1/3"><div class="hrline"></div></div>

      <!-- ACCESS -->
      <div class="form-group"><label>
        <input type="checkbox" name="grant_access" id="edit_grant_access" value="1"> სისტემაში დაშვება
      </label>
      <small class="muted">ჩართე, თუ გინდა ექიმს ჰქონდეს შესვლა. გათიშვისას არსებული ბმა მოიხსნება.</small>
      </div>
      <div class="form-group"><label>Username</label><input type="text" name="username" id="username_edit"></div>
      <div class="form-group"><label>ახალი პაროლი</label><input type="password" name="new_password1" id="new_password1" minlength="8"></div>
      <div class="form-group"><label>გაიმეორე</label><input type="password" name="new_password2" id="new_password2" minlength="8"></div>
      <div class="form-group" style="max-width:170px;">
        <label>&nbsp;</label>
        <button type="button" class="btn-light" id="genPwdBtnEdit">Generate</button>
      </div>

      <div style="grid-column:1/3;text-align:right;">
        <button type="submit" class="btn-main">შენახვა</button>
      </div>
    </form>
  </div>
</div>

<!-- TEL MODAL -->
<div id="telModal" class="overlay" style="display:none;">
  <div class="modal-content" style="max-width:900px;">
    <button class="close-x" onclick="closeTelModal()" title="დახურვა">×</button>
    <h2 style="margin:0 0 14px;color:#21ჩ1ა6">ტელეფონები</h2>
    <table class="Ctable">
      <thead><tr><th width="25%">გვარი</th><th width="25%">სახელი</th><th width="50%">ტელეფონი</th></tr></thead>
      <tbody>
      <?php foreach(($pdo->query("SELECT last_name,first_name,TRIM(BOTH ' /' FROM CONCAT_WS(' / ',NULLIF(mobile,''),NULLIF(telephone,''))) AS tel FROM doctors ORDER BY last_name,first_name")->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r): ?>
        <tr><td><?=h($r['last_name'])?></td><td><?=h($r['first_name'])?></td><td><?=h($r['tel'])?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// USER MENU
const userBtn=document.querySelector('.user-btn'); const userDropdown=document.querySelector('.user-dropdown');
document.addEventListener('click',e=>{ if(userBtn.contains(e.target)){ userDropdown.style.display = userDropdown.style.display==='flex'?'none':'flex'; userBtn.classList.toggle('open'); } else if(!userDropdown.contains(e.target)){ userDropdown.style.display='none'; userBtn.classList.remove('open'); } });
document.getElementById('telLink')?.addEventListener('click',e=>{ e.preventDefault(); openTelModal(); });
function openTelModal(){ document.getElementById('telModal').style.display='ფlex'; }
function closeTelModal(){ document.getElementById('telModal').style.display='none'; }

// ESC to close modals
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeEditModal(); closeTelModal(); } });

// Hard delete confirm (double)
function hardDeleteConfirm(){ if(!confirm('დარწმუნებული ხარ, რომ ნამდვილად წაშალო ჩანაწერი?')) return false; return confirm('დასტური №2: წაშლა არის შეუქცევადი. გავაგრძელოთ?'); }

// Branch->Dept dependent selects
function filterDeptOptions(branchSel, deptSel){
  const b = branchSel.value;
  [...deptSel.options].forEach(opt=>{
    if(opt.value==='0'){ opt.hidden=false; return; }
    const br = opt.getAttribute('data-branch');
    opt.hidden = (b==='0' || br!==b);
  });
  if(deptSel.selectedOptions.length && deptSel.selectedOptions[0].hidden){ deptSel.value='0'; }
}
const addBranch=document.getElementById('add_branch_id'), addDept=document.getElementById('add_department_id');
if(addBranch && addDept){ addBranch.addEventListener('change',()=>filterDeptOptions(addBranch, addDept)); filterDeptOptions(addBranch, addDept); }
const editBranch=document.getElementById('edit_branch_id'), editDept=document.getElementById('edit_department_id');
if(editBranch && editDept){ editBranch.addEventListener('change',()=>filterDeptOptions(editBranch, editDept)); }

// Password generators
function genPass(len=12){
  const chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
  let s=''; for(let i=0;i<len;i++){ s+=chars[Math.floor(Math.random()*chars.length)]; } return s;
}
const grantChk=document.getElementById('grant_access');
const accessFields=[document.getElementById('username'),document.getElementById('password1'),document.getElementById('password2'),document.getElementById('genPwdBtn')];
function toggleAccessFields(){
  const on = grantChk && grantChk.checked;
  accessFields.forEach(el=>{ if(!el) return; el.disabled=!on; if(!on){ if(el.tagName==='INPUT') el.value=''; } });
}
if(grantChk){ grantChk.addEventListener('change',toggleAccessFields); toggleAccessFields(); }
document.getElementById('genPwdBtn')?.addEventListener('click',()=>{ const p=genPass(12); const f1=document.getElementById('password1'); const f2=document.getElementById('password2'); if(f1&&f2){ f1.value=p; f2.value=p; } });

// EDIT MODAL handlers
function openEditModal(d){
  const map={
    'id_readonly': d.id, 'edit_id': d.id, 'user_id': d.user_id||'',
    'personal_id': d.personal_id||'','first_name': d.first_name||'','last_name': d.last_name||'',
    'father_name': d.father_name||'','birthdate': d.birthdate||'','gender': d.gender||'',
    'address': d.address||'','mobile': d.mobile||'','telephone': d.telephone||'','email': d.email||'',
    'family_status': d.family_status||'','accounting_code': d.accounting_code||'','bank_account': d.bank_account||'',
    'status': d.status||''
  };
  Object.keys(map).forEach(id=>{ const el=document.getElementById(id); if(el) el.value=map[id]; });

  const grant=document.getElementById('edit_grant_access');
  const un=document.getElementById('username_edit');
  const p1=document.getElementById('new_password1');
  const p2=document.getElementById('new_password2');
  const genBtn=document.getElementById('genPwdBtnEdit');

  if(d.username){
    grant.checked=true;
    [un,p1,p2,genBtn].forEach(el=>{ if(el){ el.disabled=false; } });
    if(un) un.value=d.username;
  } else {
    grant.checked=false;
    if(un) un.value='';
    [un,p1,p2,genBtn].forEach(el=>{ if(el){ el.disabled=true; if(el.tagName==='INPUT') el.value=''; } });
  }
  grant.addEventListener('change',()=>{ const on=grant.checked; [un,p1,p2,genBtn].forEach(el=>{ if(el){ el.disabled=!on; if(!on && el.tagName==='INPUT'){ el.value=''; } } }); });
  genBtn?.addEventListener('click',()=>{ const p=genPass(12); if(p1&&p2){ p1.value=p; p2.value=p; } });

  const editBranch=document.getElementById('edit_branch_id');
  const editDept=document.getElementById('edit_department_id');
  if(editBranch && editDept){
    editBranch.value = d.branch_id || '0';
    filterDeptOptions(editBranch, editDept);
    editDept.value = d.department_id || '0';
    if(editDept.selectedOptions.length && editDept.selectedOptions[0].hidden) editDept.value='0';
  }

  document.getElementById('editModal').style.display='flex';
  window.scrollTo(0,0);
}
function closeEditModal(){ document.getElementById('editModal').style.display='none'; }

// Backdrop click close
['editModal','telModal'].forEach(mid=>{
  const m=document.getElementById(mid); if(!m) return;
  m.addEventListener('click',e=>{ if(e.target===m){ if(mid==='editModal') closeEditModal(); if(mid==='telModal') closeTelModal(); }});
});
</script>
</body>
</html>
