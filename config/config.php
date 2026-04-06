<?php
// config/config.php
declare(strict_types=1);

/**
 * Global app config for SanMedic.
 * - Defines $pdo (PDO MySQL connection)
 * - Sets timezone, encoding, error reporting
 * - Provides $ORG info and a has_permission() helper
 */

// ===== App mode & basics =====
if (!defined('APP_DEBUG')) {
    $debugEnv = getenv('APP_DEBUG');
    define('APP_DEBUG', ($debugEnv !== false)
        ? in_array(strtolower((string)$debugEnv), ['1','true','yes','on'], true)
        : true // default true while setting up; set to false in prod
    );
}

date_default_timezone_set('Asia/Tbilisi');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

error_reporting(APP_DEBUG ? E_ALL : E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');

// Optional composer autoload (ignore if you don't use Composer)
foreach ([
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
] as $auto) {
    if (is_file($auto)) { require_once $auto; break; }
}

// ===== Database config (env overrides, else defaults) =====
$host    = getenv('DB_HOST') ?: 'localhost';
$db      = getenv('DB_NAME') ?: 'sanmedic_clinic';
$user    = getenv('DB_USER') ?: 'sanmedic_lid';
$pass    = getenv('DB_PASS') ?: 'TAFG955EQ417S7Q41';
$charset = 'utf8mb4';

if (!extension_loaded('pdo_mysql')) {
    http_response_code(500);
    exit('The pdo_mysql extension is not loaded.');
}

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // use native prepares
    PDO::ATTR_TIMEOUT            => 5,                      // connection timeout
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Ensure charset/collation each request
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (PDOException $e) {
    if (APP_DEBUG) {
        http_response_code(500);
        exit('DB connection failed: ' . $e->getMessage());
    }
    http_response_code(500);
    exit('Database connection failed.');
}

// ===== Paths (optional constants) =====
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__)); // project root (folder above /config)
}
if (!defined('APP_PUBLIC')) {
    define('APP_PUBLIC', APP_ROOT); // adjust if you have a separate public/ dir
}

// ===== Organization info used in PDFs (customize as needed) =====
$ORG = [
    'title'       => '„სანმედი“',
    'legal_name'  => 'ისნის რაიონის გამგეობა',
    'tax_id'      => '405695323',
    'address_1'   => 'ერთიანობისთვის მებრძოლთა',
    'address_2'   => 'ქუჩა N55',
    'phones'      => '555550845 / 558291614',
    'bank_name'   => 'სს "ბანკი"',
    'bank_code'   => 'BAGAGE22',
    'iban'        => 'GE02BG0000000589324177',
    'contact'     => '',
];

// ===== Simple permission helper =====
if (!function_exists('has_permission')) {
    function has_permission(string $permission, PDO $pdo, int $user_id): bool {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user && ($user['role'] ?? '') === 'admin') {
            return true;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND permission = ?");
        $stmt->execute([$user_id, $permission]);
        return (bool)$stmt->fetchColumn();
    }
}

// ===== Load Logger =====
require_once __DIR__ . "/logger.php";
SanMedicLogger::init();

