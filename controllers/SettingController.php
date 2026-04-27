<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/settings/?$#', $path)) {
    $user = authenticate(['admin', 'manager', 'distributor']);
    global $pdo;

    if ($method == 'GET') {
        // Table might not exist yet if migration isn't run, so handle gracefully
        try {
            $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
            response(200, true, $settings);
        } catch (Exception $e) {
            // Return empty settings object if table isn't migrated
            response(200, true, []);
        }
    }

    if ($method == 'PUT') { 
        $user = authenticate(['admin']);
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (is_array($data)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
                foreach ($data as $key => $value) {
                    $stmt->execute([$key, $value, $value]);
                }
                response(200, true, null, "Settings saved successfully");
            } catch (Exception $e) {
                response(500, false, null, "Error saving settings. Did you run the database migration?");
            }
        }
    }
}

response(404, false, null, "Endpoint not found");
