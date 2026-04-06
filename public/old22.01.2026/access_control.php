<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF Token Generation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// Role (for display in user dropdown)
$role = $_SESSION['role'] ?? 'user';

// Dynamic Navigation
function render_navigation(PDO $pdo, $user_id, $current_page) {
    $nav = [
        ['href' => 'index.php', 'label' => 'მთავარი', 'active' => $current_page === 'index.php'],
        ['href' => 'patient_hstory.php', 'label' => 'ისტორია', 'active' => $current_page === 'patient_hstory.php']
    ];
    if (has_permission('view_history', $pdo, $user_id)) {
        $nav[] = ['href' => 'my-patient.php', 'label' => 'ჩემი პაციენტები', 'active' => $current_page === 'my-patient.php'];
    }
    return $nav;
}

// CSRF Validation for POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        exit;
    }
}

// Basic Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>