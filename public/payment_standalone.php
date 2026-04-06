<?php
session_start();
require __DIR__ . '/../config/config.php'; // must define $pdo (PDO, ERRMODE_EXCEPTION)
require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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
  $st = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE DATE(issued_at)=?");
  $st->execute([$today]);
  $n = (int)$st->fetchColumn() + 1;
  return sprintf('%s-%s-%04d', $prefix, date('Ymd'), $n);
}
$DOMPDF_AVAILABLE = class_exists('\\Dompdf\\Dompdf');
if (!$DOMPDF_AVAILABLE) {
  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (is_file($autoload)) { require_once $autoload; $DOMPDF_AVAILABLE = class_exists('\\Dompdf\\Dompdf'); }
}

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

/* ===================== POST actions (v8) ===================== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  header_remove('X-Powered-By');
  header('Content-Type: application/json; charset=utf-8');
  json_guard_auth();

  $action = $_POST['action'] ?? '';

  /* --- close_opened (unchanged) --- */
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
    $pid    = (int)($_POST['patient_id'] ?? 0);
    $donor  = trim($_POST['donor'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
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

  /* --- INSERT PAYMENT (DB) + donor usage (supports old and new names) --- */
  if ($action==='insert_payment' || $action==='insert_payment_demo') {
    $pid      = (int)($_POST['patient_id'] ?? ($_SESSION['active_patient_id'] ?? 0));
    $paid_at  = trim($_POST['paid_at'] ?? ''); if ($paid_at==='') $paid_at = date('Y-m-d H:i');
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

    $pdo->beginTransaction();
    try{
      // Real payment
      if ($amount > 0){
        $st = $pdo->prepare("INSERT INTO payments (patient_id, paid_at, method, amount, order_no, guarantee_id) VALUES (?,?,?,?,?,NULL)");
        $st->execute([$pid, $paid_at, $method, nf2($amount), $order]);
      }

      // Donor application -> donor payment row + guarantee_usages row
      if ($donor_id > 0 && $don_appl > 0){
        // Available left
        $st = $pdo->prepare("SELECT g.amount - COALESCE((SELECT SUM(u.amount) FROM guarantee_usages u WHERE u.guarantee_id=g.id),0) AS left_amount
                             FROM patient_guarantees g WHERE g.id=? AND g.patient_id=?");
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
    $pid        = (int)($_POST['patient_id'] ?? ($_SESSION['active_patient_id'] ?? 0));
    $ids_raw    = trim($_POST['service_ids'] ?? ''); // e.g. "28@29@31@"
    $service_ids = array_values(array_filter(array_map('intval', explode('@', $ids_raw)), fn($x)=>$x>0));

    if ($pid <= 0) { echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ განისაზღვრა']); exit; }
    if (empty($service_ids)) { echo json_encode(['status'=>'error','message'=>'აირჩიეთ სერვისები ინვოისისთვის']); exit; }

    try {
      // Load detail rows
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

      // Order number (seq per day)
      $order_no = generateOrderSeq($pdo, 'INV');

      $pdo->beginTransaction();

      // Insert invoice
      $ins = $pdo->prepare("
        INSERT INTO invoices (patient_id, order_no, total_amount, issued_at, created_by, notes, donor_guarantee_id)
        VALUES (?,?,?,NOW(),?,'draft',NULL)
      ");
      $ins->execute([$pid, $order_no, nf2($total), (int)($_SESSION['user_id'] ?? null)]);
      $invoice_id = (int)$pdo->lastInsertId();

      // Insert items
      $insItem = $pdo->prepare("
        INSERT INTO invoice_items (invoice_id, patient_service_id, quantity, unit_price, line_total)
        VALUES (?,?,?,?,?)
      ");
      foreach ($rows as $r) {
        $qty  = (float)$r['quantity'];
        $unit = (float)$r['unit_price'];
        $sum  = (float)$r['line_total'];
        $insItem->execute([$invoice_id, (int)$r['id'], nf2($qty), nf2($unit), nf2($sum)]);
      }

      $pdo->commit();

      // Generate doc
      $dir = __DIR__ . '/invoices';
      if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
      if ($DOMPDF_AVAILABLE) {
        ob_start(); ?>
        <html>
          <head>
            <meta charset="utf-8">
            <style>
              body{ font-family: DejaVu Sans, sans-serif; font-size:12px; }
              h1{ font-size:18px; margin:0 0 8px; }
              table{ width:100%; border-collapse:collapse; margin-top:10px; }
              th,td{ border:1px solid #ddd; padding:6px; text-align:left; }
              th{ background:#f4f4f4; }
              .right{ text-align:right; }
            </style>
  <link rel="stylesheet" href="/css/preclinic-theme.css">
</head>
          <body>
            <h1>ინვოისი № <?= h($order_no) ?></h1>
            <div>თარიღი: <?= h(date('Y-m-d H:i')) ?></div>
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>მომსახურება</th>
                  <th class="right">რაოდ.</th>
                  <th class="right">ერთ. ფასი</th>
                  <th class="right">ჯამი</th>
                </tr>
              </thead>
              <tbody>
              <?php $i=0; foreach($rows as $r): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= h($r['service_name'] ?? '–') ?></td>
                  <td class="right"><?= nf2($r['quantity']) ?></td>
                  <td class="right"><?= nf2($r['unit_price']) ?></td>
                  <td class="right"><?= nf2($r['line_total']) ?></td>
                </tr>
              <?php endforeach; ?>
                <tr>
                  <td colspan="4" class="right"><b>საერთო ჯამი</b></td>
                  <td class="right"><b><?= nf2($total) ?></b></td>
                </tr>
              </tbody>
            </table>
          </body>
        </html>
        <?php
        $html = ob_get_clean();

        $opts = new \Dompdf\Options(); $opts->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfAbs = $dir . '/invoice_' . $invoice_id . '.pdf';
        file_put_contents($pdfAbs, $dompdf->output());
        $pdf_url = 'invoices/invoice_' . $invoice_id . '.pdf';

        echo json_encode([
          'status'     => 'ok',
          'invoice_id' => $invoice_id,
          'order_no'   => $order_no,
          'total'      => nf2($total),
          'pdf_url'    => $pdf_url
        ]); exit;
      } else {
        $file = $dir . '/invoice_' . $invoice_id . '.html';
        ob_start(); ?>
        <html><head><meta charset="utf-8"><title><?= h($order_no) ?></title></head><body>
        <h1>ინვოისი № <?= h($order_no) ?></h1>
        <p><b>თარიღი:</b> <?= h(date('Y-m-d H:i')) ?></p>
        <ul><?php foreach($rows as $r): ?><li><?= h($r['service_name']) ?> — <?= nf2($r['line_total']) ?> ₾</li><?php endforeach; ?></ul>
        <p><b>საერთო:</b> <?= nf2($total) ?> ₾</p>
        </body></html>
        <?php
        file_put_contents($file, ob_get_clean());
        echo json_encode([
          'status'=>'ok',
          'invoice_id'=>$invoice_id,
          'order_no'=>$order_no,
          'total'=>nf2($total),
          'pdf_url'=>'invoices/invoice_'.$invoice_id.'.html'
        ]); exit;
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
  }

  /* --- CREATE PAYMENT RECEIPT (PDF/HTML) --- */
  if ($action === 'create_payment_invoice_demo') {
    $pid = (int)($_POST['patient_id'] ?? ($_SESSION['active_patient_id'] ?? 0));
    $ids_raw = trim($_POST['payment_ids'] ?? '');
    $pay_ids = array_values(array_filter(array_map('intval', explode('@', $ids_raw)), fn($x)=>$x>0));
    if ($pid<=0) { echo json_encode(['status'=>'error','message'=>'პაციენტი ვერ განისაზღვრა']); exit; }
    if (!$pay_ids) { echo json_encode(['status'=>'error','message'=>'აირჩიეთ გადახდები']); exit; }

    $in = implode(',', array_fill(0, count($pay_ids), '?'));
    $args = $pay_ids; array_unshift($args, $pid);
    $sql = "SELECT id, paid_at, method, amount, order_no
            FROM payments
            WHERE patient_id = ? AND id IN ($in)
            ORDER BY paid_at ASC, id ASC";
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo json_encode(['status'=>'error','message'=>'არჩეული გადახდები ვერ მოიძებნა']); exit; }

    $total = 0.0; foreach ($rows as $r) $total += (float)$r['amount'];
    $doc_no = generateOrderSeq($pdo, 'PAY');

    $dir = __DIR__ . '/invoices'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    if ($DOMPDF_AVAILABLE) {
      ob_start(); ?>
      <html><head><meta charset="utf-8"><style>
        body{ font-family: DejaVu Sans, sans-serif; font-size:12px; }
        h1{ font-size:18px; margin:0 0 8px; }
        table{ width:100%; border-collapse:collapse; margin-top:10px; }
        th,td{ border:1px solid #ddd; padding:6px; text-align:left; }
        th{ background:#f4f4f4; }
        .right{ text-align:right; }
      </style></head><body>
      <h1>გადახდების ინვოისი № <?= h($doc_no) ?></h1>
      <div>თარიღი: <?= h(date('Y-m-d H:i')) ?></div>
      <table><thead><tr>
        <th>#</th><th>თარიღი</th><th>ტიპი</th><th>ორდერი</th><th class="right">თანხა</th>
      </tr></thead><tbody>
      <?php $i=0; foreach($rows as $r): $i++; ?>
        <tr>
          <td><?= $i ?></td>
          <td><?= h($r['paid_at']) ?></td>
          <td><?= h($r['method']) ?></td>
          <td><?= h($r['order_no'] ?? '') ?></td>
          <td class="right"><?= nf2($r['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="4" class="right"><b>ჯამი</b></td><td class="right"><b><?= nf2($total) ?></b></td></tr>
      </tbody></table></body></html>
      <?php
      $html = ob_get_clean();
      $opts = new \Dompdf\Options(); $opts->set('isRemoteEnabled', true);
      $dompdf = new \Dompdf\Dompdf($opts);
      $dompdf->loadHtml($html, 'UTF-8'); $dompdf->setPaper('A4', 'portrait'); $dompdf->render();

      $fileAbs = $dir . '/payment_invoice_' . $doc_no . '.pdf';
      file_put_contents($fileAbs, $dompdf->output());
      echo json_encode(['status'=>'ok','receipt_id'=>$doc_no,'doc_no'=>$doc_no,'total'=>nf2($total),'pdf_url'=>'invoices/payment_invoice_'.$doc_no.'.pdf']); exit;
    } else {
      $file = $dir . '/payment_invoice_' . $doc_no . '.html';
      ob_start(); ?>
      <html><head><meta charset="utf-8"><title><?= h($doc_no) ?></title></head><body>
      <h1>გადახდების ინვოისი № <?= h($doc_no) ?></h1>
      <p><b>თარიღი:</b> <?= h(date('Y-m-d H:i')) ?></p>
      <ul><?php foreach($rows as $r): ?><li><?= h($r['paid_at']) ?> — <?= h($r['method']) ?> — <?= h($r['order_no'] ?? '') ?> — <?= nf2($r['amount']) ?> ₾</li><?php endforeach; ?></ul>
      <p><b>საერთო:</b> <?= nf2($total) ?> ₾</p>
      </body></html>
      <?php
      file_put_contents($file, ob_get_clean());
      echo json_encode(['status'=>'ok','receipt_id'=>$doc_no,'doc_no'=>$doc_no,'total'=>nf2($total),'pdf_url'=>'invoices/payment_invoice_'.$doc_no.'.html']); exit;
    }
  }

  /* --- UPDATE PATIENT (unchanged) --- */
  if ($action==='update_patient') {
    $pid        = (int)($_POST['patient_id'] ?? 0);
    $personalId = trim($_POST['personal_id'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $birthdate  = trim($_POST['birthdate'] ?? ''); // YYYY-mm-dd

    if ($pid<=0 || $personalId==='' || $firstName==='' || $lastName==='' || $birthdate==='') {
      echo json_encode(['status'=>'error','message'=>'ყველა ველი სავალდებულოა']); exit;
    }

    $stmt = $pdo->prepare("
      UPDATE patients
         SET personal_id = ?, first_name = ?, last_name = ?, birthdate = ?
       WHERE id = ?
    ");
    $ok = $stmt->execute([$personalId, $firstName, $lastName, $birthdate, $pid]);

    echo json_encode(['status'=>$ok?'ok':'error','message'=>$ok?'':'განახლება ვერ შესრულდა']);
    exit;
  }

  /* --- DELETE PATIENT (extended: also clear donor usages) --- */
  if ($action==='delete_patient') {
    $pid = (int)($_POST['patient_id'] ?? 0);
    if ($pid<=0) { echo json_encode(['status'=>'error','message'=>'არასწორი ID']); exit; }

    try {
      $pdo->beginTransaction();

      // delete donor usages first (for this patient's payments)
      $pdo->prepare("DELETE FROM guarantee_usages WHERE payment_id IN (SELECT id FROM payments WHERE patient_id=?)")->execute([$pid]);

      // dependent rows
      $pdo->prepare("DELETE FROM payments WHERE patient_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM patient_services WHERE patient_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM patient_guarantees WHERE patient_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM invoices WHERE patient_id=?")->execute([$pid]); // optional: if desired

      // patient row
      $pdo->prepare("DELETE FROM patients WHERE id=?")->execute([$pid]);

      // session cleanup
      $_SESSION['opened_patients'] = array_values(
        array_filter($_SESSION['opened_patients'] ?? [], fn($x)=>(int)$x !== $pid)
      );
      if ((int)($_SESSION['active_patient_id'] ?? 0) === $pid) {
        $_SESSION['active_patient_id'] = !empty($_SESSION['opened_patients'])
          ? (int)end($_SESSION['opened_patients']) : 0;
      }

      $pdo->commit();
      echo json_encode([
        'status'=>'ok',
        'opened_patients'=>$_SESSION['opened_patients'],
        'active_patient_id'=> (int)($_SESSION['active_patient_id'] ?? 0)
      ]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      echo json_encode(['status'=>'error','message'=>'ვერ წაიშალა: '.$e->getMessage()]);
    }
    exit;
  }

  echo json_encode(['status'=>'error','message'=>'unknown_action']);
  exit;
}

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
    SELECT ps.id, ps.quantity,
           COALESCE(ps.unit_price, s.price) AS unit_price,
           COALESCE(ps.sum, ps.quantity * COALESCE(ps.unit_price, s.price)) AS sum,
           s.name AS service_name, ps.created_at
    FROM patient_services ps
    JOIN services s ON s.id = ps.service_id
    WHERE ps.patient_id = ?
    ORDER BY ps.created_at ASC, ps.id ASC
  ");
  $s->execute([$pid]); $services = $s->fetchAll(PDO::FETCH_ASSOC);

  // Payments (all)
  $p = $pdo->prepare("SELECT id, paid_at, LOWER(method) AS method, amount, order_no FROM payments WHERE patient_id=? ORDER BY paid_at ASC, id ASC");
  $p->execute([$pid]); $payments = $p->fetchAll(PDO::FETCH_ASSOC);

  // Guarantees with live left
  $gq = $pdo->prepare("
    SELECT g.id, g.donor,
           g.amount - COALESCE((SELECT SUM(u.amount) FROM guarantee_usages u WHERE u.guarantee_id=g.id),0) AS left_amount
    FROM patient_guarantees g
    WHERE g.patient_id = ?
    HAVING left_amount > 0
    ORDER BY g.id ASC
  "); $gq->execute([$pid]); $guarantees = $gq->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Aggregates
  $total_services = 0.0; foreach($services as $r) $total_services += (float)$r['sum'];
  $total_paid_real = 0.0; foreach($payments as $r){ if(($r['method']??'')!=='donor') $total_paid_real += (float)$r['amount']; }
  $donor_total_left = 0.0; foreach($guarantees as $g) $donor_total_left += max(0.0,(float)$g['left_amount']);

  // FIFO mark rows as paid using only real payments
  usort($services, fn($a,$b)=> (strcmp((string)$a['created_at'],(string)$b['created_at']) ?: ((int)$a['id']<=> (int)$b['id'])));
  $rem = $total_paid_real;
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

      <span class="sel-badge" style="background:#fff;border:1px solid #cfece6;padding:6px 10px;border-radius:6px"><span>მონიშნულის გადასახდელი:</span> <b id="sel_badge">0.00</b></span>

      <label class="chk"><input type="checkbox" id="chk_debt_all"> დავალიანების მონიშვნა</label>

      <div class="total-badge" style="margin-left:auto;background:#fff;border:1px dashed #21c1a6;padding:6px 10px;border-radius:6px">
        <span>დარჩენილი გადასახდელი:</span>
        <strong id="en3m">0.00</strong>
      </div>
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
            <th class="fdaz1" style="width:110px">სადაზ % <div class="mini-stack" style="display:flex;gap:4px;align-items:center;margin-top:4px"><input type="text" id="dgperg" class="mini" style="width:40px;padding:4px;border:1px solid #bbb;border-radius:4px;text-align:center"><button id="btn_ins_percent_1" class="mini-btn" style="width:26px;height:26px;border:1px solid #bbb;background:#fff;border-radius:4px;cursor:pointer">+</button></div></th>
            <th class="fdaz1" style="width:100px">ზედა ლიმიტი</th>
            <th class="fdaz2 diaz" style="width:0">ქვედა</th>
            <th class="fdaz2 diaz" style="width:0">%</th>
            <th class="fdaz2 diaz" style="width:0">ზედა</th>
            <th class="fdaz3 diaz" style="width:0">ქვედა</th>
            <th class="fdaz3 diaz" style="width:0">%</th>
            <th class="fdaz3 diaz" style="width:0">ზედა</th>
            <th style="width:90px">ფასდა % <div class="mini-stack" style="display:flex;gap:4px;align-items:center;margin-top:4px"><input type="text" id="jjdhrge" class="mini" style="width:40px;padding:4px;border:1px solid #bbb;border-radius:4px;text-align:center"><button id="btn_price_percent" class="mini-btn" style="width:26px;height:26px;border:1px solid #bbb;background:#fff;border-radius:4px;cursor:pointer">+</button></div></th>
            <th style="width:38px"></th>
            <th style="width:86px">გადახდ.</th>
            <th style="width:86px">ჯამი</th>
            <th style="width:86px">გადახდილი</th>
            <th style="width:20px"></th>
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
            <td></td>
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
        <label>ორდერის #
          <input type="text" id="order_no" placeholder="ცარიელი = ავტოგენერაცია" autocomplete="off">
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
            ნაშთი: <b id="donor_left_badge"><?= nf2($donor_total_left) ?></b> ₾
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
              <th style="width:16%"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$payments): ?>
              <tr><td colspan="6" style="text-align:center;color:#777;font-style:italic">ჩანაწერი არ არის.</td></tr>
            <?php else: foreach($payments as $pr): ?>
              <tr id="O<?= (int)$pr['id'] ?>">
                <td><input type="checkbox" class="zl"></td>
                <td><?= h($pr['paid_at']) ?></td>
                <td><b><?= h($pr['method']) ?></b></td>
                <td><?= h($pr['order_no'] ?? '') ?></td>
                <td><?= nf2($pr['amount']) ?></td>
                <td></td>
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

/* ===================== Main grid data ===================== */
$activePatientId = (int)($_SESSION['active_patient_id'] ?? 0);
$openedIds = $_SESSION['opened_patients'];

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
  $st = $pdo->prepare($sql); $st->execute($openedIds);
  $patients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$cur = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="ka">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>პაციენტების მართვა</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
<style>
:root{ --brand:#21c1a6; --brand-2:#14937c; --bg:#f9f8f2; --text:#222; --muted:#888; }
*{box-sizing:border-box}
body{font-family:"Noto Sans Georgian",sans-serif;background:var(--bg);color:var(--text);margin:0}
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
.tabs a{padding:10px 18px;background:var(--brand);color:#fff;border-top-left-radius:7px;border-top-right-radius:7px;text-decoration:none}
.tabs a.active,.tabs a:hover{background:#fff;color:var(--brand)}
.patients-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(31,61,124,.08)}
.patients-table th,.patients-table td{padding:12px;border-bottom:1px solid #eee;text-align:left}
.patients-table th{background:var(--brand);color:#fff}
.patients-table tbody tr:hover:not(.selected){background:#d7f0e9}
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
.pay-topbar .tabs{border:0;margin:0;gap:0}
.pay-topbar .tabs .tab{display:block;padding:6px 10px;border:1px solid #d5d5d5;color:#6f6d6d;background:#fff;text-decoration:none}
.pay-topbar .tabs .tab.active{background:#fff;color:#6f6d6d;border-bottom:2px solid #fff;box-shadow:inset 0 -2px 0 var(--bg)}
.total-badge{margin-left:auto;background:#fff;border:1px dashed #21c1a6;padding:6px 10px;border-radius:6px}
.sel-badge{background:#fff;border:1px solid #cfece6;padding:6px 10px;border-radius:6px}
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
#searchInput{border:1.5px solid #ccc}
</style>
</head>
<body>

<div class="topbar">
    <a href="dashboard.php" class="logo-link" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/img/logo-White.png?v=2" alt="SanMedic" style="height:40px;width:auto;margin-right:12px;background:#fff;padding:4px 8px;border-radius:6px;">
    </a>
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
      <li><a href="dashboard.php" class="<?= $cur=='registration.php'?'active':'' ?>">რეგისტრაცია</a></li>
      <li><a href="patient_hstory.php" class="<?= $cur=='patient_hstory.php'?'active':'' ?>">პაციენტების მართვა</a></li>
      <li><a href="nomenclature.php" class="<?= $cur=='nomenclature.php'?'active':'' ?>">ნომენკლატურა</a></li>
      <li><a href="administration.php" class="<?= $cur=='administration.php'?'active':'' ?>">ადმინისტრირება</a></li>
      <li><a href="forward.php" class="<?= $cur=='forward.php'?'active':'' ?>">ფორვარდი</a></li>
      <li><a href="reports.php" class="<?= $cur=='reports.php'?'active':'' ?>">ანგარიშები</a></li>
    </ul>
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

    <label for="searchInput" style="display:block;margin-bottom:8px;font-weight:600;color:var(--brand)">ძებნა პაციენტებში</label>
    <input id="searchInput" type="text" placeholder="სახელი, გვარი, პ/ნ..." style="width:100%;padding:8px 10px;border:1.5px solid #ccc;border-radius:6px;margin-bottom:14px">

    <h2 style="margin:6px 0 10px">პაციენტების სია</h2>
    <table class="patients-table" id="patientsTable">
      <thead>
        <tr>
          <th>პ/ნ</th><th>სახელი</th><th>გვარი</th><th>დაბ. თარიღი</th>
          <th>#</th><th>ჯამი</th><th>%</th><th>ვალი</th><th>გადახდ.</th><th>ქმედება</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$patients): ?>
          <tr><td colspan="10" style="text-align:center;color:#888;font-style:italic">ჩანაწერი ვერ მოიძებნა</td></tr>
        <?php else: foreach($patients as $p): ?>
          <tr data-id="<?= (int)$p['id'] ?>" data-firstname="<?= h($p['first_name']) ?>" data-lastname="<?= h($p['last_name']) ?>">
            <td class="col-personal"><?= h($p['personal_id']) ?></td>
            <td class="col-first"><?= h($p['first_name']) ?></td>
            <td class="col-last"><?= h($p['last_name']) ?></td>
            <td class="col-birth"><?= h(date('Y-m-d', strtotime($p['birthdate']))) ?></td>
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
  payOverlay.style.display='flex';
  payOverlay.innerHTML='<div class="modal"><button class="close" data-close>&times;</button><div style="padding:30px;text-align:center">იტვირთება...</div></div>';
  fetch(`?action=pay_view&id=${encodeURIComponent(pid)}`)
    .then(r=>r.text())
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

/* ======== Payment modal logic (v8) ======== */
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
    const left = Math.max(0, avail - applied);
    $root.find('#donor_left_badge').text(fmt2(left));
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
      if (debt<=0){ $vt.val('').prop('disabled',true).addClass('disCol'); $k.prop('checked',false); }
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

  // Events
  $root.on('change','#chk_all_rows', e=>{
    const on=$(e.currentTarget).prop('checked');
    $root.find('#fs_amounttypet .srv-chk').each((_,ch)=>{
      const isPaid = parseInt(ch.dataset.fullyPaid||'0',10)===1;
      if (isPaid) ch.checked=false;
      else if (!ch.disabled) ch.checked=on;
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
  $root.on('change','#chk_debt_all', e=>{ if($(e.currentTarget).prop('checked')) activateDebtModeZeros(); else clearDebtMarks(); recalcTotals(); });
  $root.on('change','#pa_dontype', ()=> recalcTotals());

  // Make Invoice (services)
  $root.on('click','#btn_make_invoice', ()=>{
    let s=''; getSelectedRows().each((_,tr)=>{ const id=tr.id; if(id) s+=id.substring(1)+'@'; });
    $root.find('#hd_izptit').val(s);
    alert('აირჩეული სერვისები: '+(s||'(ცარიელი)'));
  });
  $root.on('click','#btn_issue_invoice', async ()=>{
    let serviceIds=''; getSelectedRows().each((_,tr)=>{ const id=tr.id; if(id) serviceIds += id.substring(1)+'@'; });
    if (!serviceIds){ alert('აირჩიეთ მინიმუმ ერთი სერვისი'); return; }
    const fd=new FormData(); fd.append('action','create_invoice_demo'); fd.append('service_ids', serviceIds);
    const r=await fetch('', {method:'POST', body:fd}); const j=await r.json().catch(()=>({}));
    if (j.status==='ok'){ alert('ინვოისი ჩაიწერა. №: '+j.invoice_id+' | ორდერი: '+j.order_no); if (j.pdf_url) window.open(j.pdf_url,'_blank'); }
    else { alert(j.message||'ვერ ჩაიწერა'); }
  });

  // Insert payment (real + donor)
  $root.on('click','#pa_insrt', async (e)=>{
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
    fd.append('paid_at', paid_at || '<?= date('Y-m-d\TH:i') ?>');
    fd.append('method', method);
    fd.append('amount', fmt2(amount));
    fd.append('order_no', orderNo);
    fd.append('donor_id', donor_id);
    fd.append('donor_applied', fmt2(donor_applied));

    const r=await fetch('', {method:'POST', body:fd}); const j=await r.json().catch(()=>({}));
    if (j.status==='ok'){ alert('გადახდა ჩაიწერა. ორდერი: '+(j.order_no||orderNo||'(გენერირებულია)')); openPayModal(pidSel); }
    else { alert(j.message||'შეცდომა'); }
  });

  // Payments table: select all + issue receipt
  $root.on('change','#chk_all_payments', e=>{
    const on=$(e.currentTarget).prop('checked');
    $root.find('#pa_pa .zl').prop('checked', on);
  });
  $root.on('click','#btn_issue_payment_invoice', async ()=>{
    let ids=''; $root.find('#pa_pa .zl:checked').each((_,ch)=>{
      const id=$(ch).closest('tr').attr('id')||''; if (id.startsWith('O')) ids += id.substring(1) + '@';
    });
    if (!ids) { alert('აირჩიეთ მინიმუმ ერთი გადახდა'); return; }

    const fd=new FormData();
    fd.append('action','create_payment_invoice_demo');
    fd.append('payment_ids', ids);

    const r=await fetch('', {method:'POST', body:fd});
    const j=await r.json().catch(()=> ({}));
    if (j.status==='ok'){
      alert('გადახდების ინვოისი ჩაიწერა. №: ' + j.receipt_id + ' | დოკ: ' + j.doc_no);
      if (j.pdf_url) window.open(j.pdf_url, '_blank');
    } else {
      alert(j.message || 'ვერ ჩაიწერა');
    }
  });

  // Init
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
                <input type="text" name="personal_id" value="${(d.personal_id||'')}" required>
              </label>
              <label>დაბადების თარიღი (YYYY-MM-DD)
                <input type="text" name="birthdate" value="${(d.birthdate||'')}" required>
              </label>
              <label>სახელი
                <input type="text" name="first_name" value="${(d.first_name||'')}" required>
              </label>
              <label>გვარი
                <input type="text" name="last_name" value="${(d.last_name||'')}" required>
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
                row.querySelector('.col-birth').textContent = this.birthdate.value;
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
          fullnameDisplay.textContent = 'აირჩიეთ პაციენტი სიისგან, რომ ნახოთ დეტალები.';
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
</script>
</body>
</html>
