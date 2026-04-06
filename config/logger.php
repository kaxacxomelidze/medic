<?php
/**
 * SanMedic Logging System
 * ლოგირების სისტემა
 */

class SanMedicLogger {
    private static $logFile = null;
    private static $userId = null;
    private static $requestId = null;
    
    public static function init() {
        self::$logFile = dirname(__DIR__) . "/logs/app_" . date("Y-m-d") . ".log";
        self::$requestId = substr(md5(uniqid(mt_rand(), true)), 0, 8);
        self::$userId = $_SESSION["user_id"] ?? "guest";
    }
    
    public static function log($level, $message, $context = []) {
        if (!self::$logFile) self::init();
        
        $timestamp = date("Y-m-d H:i:s");
        $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        $uri = $_SERVER["REQUEST_URI"] ?? "-";
        $method = $_SERVER["REQUEST_METHOD"] ?? "-";
        
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : "";
        
        $logLine = sprintf(
            "[%s] [%s] [%s] [user:%s] [ip:%s] [%s %s] %s %s\n",
            $timestamp,
            strtoupper($level),
            self::$requestId,
            self::$userId,
            $ip,
            $method,
            $uri,
            $message,
            $contextStr
        );
        
        error_log($logLine, 3, self::$logFile);
    }
    
    public static function info($message, $context = []) {
        self::log("INFO", $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log("ERROR", $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log("WARNING", $message, $context);
    }
    
    public static function debug($message, $context = []) {
        self::log("DEBUG", $message, $context);
    }
    
    public static function action($action, $details = []) {
        self::log("ACTION", $action, $details);
    }
    
    public static function db($query, $params = [], $duration = null) {
        $context = ["params" => $params];
        if ($duration !== null) $context["duration_ms"] = round($duration * 1000, 2);
        self::log("DB", substr($query, 0, 200), $context);
    }
    
    public static function exception(\Throwable $e) {
        self::log("EXCEPTION", $e->getMessage(), [
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "trace" => array_slice($e->getTrace(), 0, 5)
        ]);
    }
}

// Global error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $levels = [
        E_ERROR => "ERROR",
        E_WARNING => "WARNING",
        E_NOTICE => "NOTICE",
        E_DEPRECATED => "DEPRECATED"
    ];
    $level = $levels[$errno] ?? "ERROR";
    SanMedicLogger::log($level, $errstr, ["file" => $errfile, "line" => $errline]);
    return false; // Continue with normal error handling
});

// Global exception handler
set_exception_handler(function(\Throwable $e) {
    SanMedicLogger::exception($e);
    throw $e;
});

// Alias function for easy use
function slog($message, $context = []) {
    SanMedicLogger::info($message, $context);
}

function slog_error($message, $context = []) {
    SanMedicLogger::error($message, $context);
}

function slog_action($action, $details = []) {
    SanMedicLogger::action($action, $details);
}
