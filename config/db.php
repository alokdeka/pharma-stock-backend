<?php
$host   = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'pharma_stock';
$user   = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$pass   = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    try {
        $tzStmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'timezone'");
        $globalTz = $tzStmt->fetchColumn();
        if ($globalTz) {
            date_default_timezone_set($globalTz);
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        }
    } catch (Exception $e) { /* DB not migrated yet */ }

} catch (PDOException $e) {
    if (function_exists('response')) {
        response(500, false, null, 'DB connection failed: ' . $e->getMessage());
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    }
    exit;
}
