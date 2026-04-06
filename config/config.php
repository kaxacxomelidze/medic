<?php
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
    define(
        'APP_DEBUG',
        ($debugEnv !== false)
            ? in_array(strtolower((string)$debugEnv), ['1', 'true', 'yes', 'on'], true)
            : true
    );
}

date_default_timezone_set('Asia/Tbilisi');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

error_reporting(APP_DEBUG ? E_ALL : E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');

// Optional composer autoload
foreach ([
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
] as $auto) {
    if (is_file($auto)) {
        require_once $auto;
        break;
    }
}

// ===== Small env helper =====
if (!function_exists('env_or')) {
    function env_or(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }
}

// ===== Database config =====
// თუ სხვა სახელი აქვს ბაზას, აქ შეცვალე მხოლოდ ეს მნიშვნელობა
if (!defined('DB_HOST')) define('DB_HOST', env_or('DB_HOST', '127.0.0.1'));
if (!defined('DB_NAME')) define('DB_NAME', env_or('DB_NAME', 'test'));
if (!defined('DB_USER')) define('DB_USER', env_or('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', env_or('DB_PASS', ''));
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

if (!extension_loaded('pdo_mysql')) {
    http_response_code(500);
    exit('The pdo_mysql extension is not loaded.');
}

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (PDOException $e) {
    http_response_code(500);
    exit(APP_DEBUG ? ('DB connection failed: ' . $e->getMessage()) : 'Database connection failed.');
}

// ===== Paths =====
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_PUBLIC')) {
    define('APP_PUBLIC', APP_ROOT);
}

// ===== Organization info =====
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
    function has_permission(string $permission, PDO $pdo, int $user_id): bool
    {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user && (($user['role'] ?? '') === 'admin')) {
            return true;
        }

        $stmt = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND permission = ? LIMIT 1");
        $stmt->execute([$user_id, $permission]);

        return (bool) $stmt->fetchColumn();
    }
}

// ===== Load Logger =====
$loggerFile = __DIR__ . '/logger.php';
if (is_file($loggerFile)) {
    require_once $loggerFile;
    if (class_exists('SanMedicLogger')) {
        SanMedicLogger::init();
    }
}