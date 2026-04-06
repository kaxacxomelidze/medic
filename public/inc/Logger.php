<?php
/**
 * Sanmedic Logger - ყველა მნიშვნელოვანი მოქმედების ლოგირება
 * 
 * გამოყენება:
 * Logger::log('delete', 'patient_service', $id, ['patient_id' => $pid, 'service_name' => $name]);
 */

class Logger {
    private static $logDir = null;
    
    /**
     * ლოგის დირექტორიის მიღება
     */
    private static function getLogDir(): string {
        if (self::$logDir === null) {
            self::$logDir = dirname(__DIR__, 2) . '/logs';
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
        return self::$logDir;
    }
    
    /**
     * მთავარი ლოგირების ფუნქცია
     * 
     * @param string $action - მოქმედება (create, update, delete, login, error)
     * @param string $entity - ობიექტის ტიპი (patient, patient_service, payment, invoice, etc.)
     * @param int|string $entityId - ობიექტის ID
     * @param array $data - დამატებითი მონაცემები
     * @param string|null $message - შეტყობინება
     */
    public static function log(
        string $action, 
        string $entity, 
        $entityId = null, 
        array $data = [], 
        ?string $message = null
    ): void {
        try {
            $logFile = self::getLogDir() . '/actions_' . date('Y-m') . '.log';
            
            // მომხმარებლის ინფორმაცია
            $userId = $_SESSION['userdata']['id'] ?? 0;
            $userName = trim(($_SESSION['userdata']['first_name'] ?? '') . ' ' . ($_SESSION['userdata']['last_name'] ?? ''));
            if (empty($userName)) {
                $userName = $_SESSION['userdata']['email'] ?? 'unknown';
            }
            
            // IP მისამართი
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // ლოგის ჩანაწერი
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => strtoupper($action),
                'entity' => $entity,
                'entity_id' => $entityId,
                'user_id' => $userId,
                'user_name' => $userName,
                'ip' => $ip,
                'data' => $data,
                'message' => $message,
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? ''
            ];
            
            // JSON ფორმატით ჩაწერა
            $line = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            
            // DELETE ოპერაციები ცალკე ფაილშიც
            if (strtolower($action) === 'delete') {
                $deleteLog = self::getLogDir() . '/deletes_' . date('Y-m') . '.log';
                file_put_contents($deleteLog, $line, FILE_APPEND | LOCK_EX);
            }
            
            // შეცდომები ცალკე ფაილში
            if (strtolower($action) === 'error') {
                $errorLog = self::getLogDir() . '/errors_' . date('Y-m') . '.log';
                file_put_contents($errorLog, $line, FILE_APPEND | LOCK_EX);
            }
            
        } catch (Throwable $e) {
            // ლოგირების შეცდომა არ უნდა გააჩეროს აპლიკაცია
            error_log("Logger error: " . $e->getMessage());
        }
    }
    
    /**
     * DELETE ოპერაციის ლოგირება
     */
    public static function logDelete(string $entity, $entityId, array $data = [], ?string $message = null): void {
        self::log('delete', $entity, $entityId, $data, $message);
    }
    
    /**
     * CREATE ოპერაციის ლოგირება
     */
    public static function logCreate(string $entity, $entityId, array $data = [], ?string $message = null): void {
        self::log('create', $entity, $entityId, $data, $message);
    }
    
    /**
     * UPDATE ოპერაციის ლოგირება
     */
    public static function logUpdate(string $entity, $entityId, array $data = [], ?string $message = null): void {
        self::log('update', $entity, $entityId, $data, $message);
    }
    
    /**
     * ERROR ლოგირება
     */
    public static function logError(string $entity, $entityId, array $data = [], ?string $message = null): void {
        self::log('error', $entity, $entityId, $data, $message);
    }
    
    /**
     * ლოგების წაკითხვა (ადმინისთვის)
     */
    public static function getRecentLogs(int $limit = 100, ?string $action = null, ?string $entity = null): array {
        $logFile = self::getLogDir() . '/actions_' . date('Y-m') . '.log';
        if (!file_exists($logFile)) {
            return [];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];
        
        // ბოლოდან წავიკითხოთ
        $lines = array_reverse($lines);
        
        foreach ($lines as $line) {
            if (count($logs) >= $limit) break;
            
            $entry = json_decode($line, true);
            if (!$entry) continue;
            
            // ფილტრაცია
            if ($action && strtoupper($entry['action']) !== strtoupper($action)) continue;
            if ($entity && $entry['entity'] !== $entity) continue;
            
            $logs[] = $entry;
        }
        
        return $logs;
    }
    
    /**
     * წაშლილი ჩანაწერების ისტორია
     */
    public static function getDeleteHistory(int $limit = 50): array {
        return self::getRecentLogs($limit, 'delete');
    }
}
