<?php

class SanMedicLogger
{
    protected static string $logDir;
    protected static string $logFile;

    public static function init(): void
    {
        self::$logDir = dirname(__DIR__) . '/logs';

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0777, true);
        }

        self::$logFile = self::$logDir . '/app_' . date('Y-m-d') . '.log';
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        if (empty(self::$logFile)) {
            self::init();
        }

        $line = sprintf(
            "[%s] [%s] %s %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        error_log($line, 3, self::$logFile);
    }
}

if (!function_exists('slog_action')) {
    function slog_action(string $message, array $context = []): void
    {
        SanMedicLogger::write('info', $message, $context);
    }
}

if (!function_exists('slog_error')) {
    function slog_error(string $message, array $context = []): void
    {
        SanMedicLogger::write('error', $message, $context);
    }
}