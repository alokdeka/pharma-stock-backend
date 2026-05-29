<?php
require_once __DIR__ . '/../config/db.php';

if (!function_exists('logActivity')) {
    function logActivity($userId, $action, $details = null) {
        global $pdo;
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            // Support object/array details conversion
            if (is_array($details) || is_object($details)) {
                $details = json_encode($details);
            }
            
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $details, $ip]);
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
}
