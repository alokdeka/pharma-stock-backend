<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/notifications/(\d+)/read$#', $path, $matches)) {
    $user = authenticate();
    $notifId = $matches[1];
    
    global $pdo;
    if ($method == 'POST') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $user->sub]);
        response(200, true, null, "Notification marked as read");
    }
}

if (preg_match('#/notifications/smart?$#', $path)) {
    $user = authenticate();
    global $pdo;

    if ($method == 'GET') {
        $alerts = [];
        $smartIdCounter = -1; // Negative IDs represent dynamic smart alerts so they don't collide with DB IDs

        // 1. DYNAMIC: Low Stock Alerts
        $stockStmt = $pdo->query("
            SELECT m.name, m.reorder_point, COALESCE(SUM(b.quantity), 0) as total_stock
            FROM medicines m LEFT JOIN batches b ON m.id = b.medicine_id
            GROUP BY m.id HAVING total_stock <= m.reorder_point
        ");
        while ($row = $stockStmt->fetch(PDO::FETCH_ASSOC)) {
            $alerts[] = [
                'id' => $smartIdCounter--,
                'title' => 'Critical Low Stock',
                'message' => "{$row['name']} is running low. Only {$row['total_stock']} units remain (Limit: {$row['reorder_point']}).",
                'type' => 'error',
                'is_read' => false,
                'is_smart' => true,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // 2. DYNAMIC: Expiry Alerts
        $expireStmt = $pdo->query("
            SELECT b.batch_number, m.name, DATEDIFF(b.expiry_date, CURDATE()) as days
            FROM batches b JOIN medicines m ON b.medicine_id = m.id
            WHERE b.quantity > 0 AND DATEDIFF(b.expiry_date, CURDATE()) <= 30
        ");
        while ($row = $expireStmt->fetch(PDO::FETCH_ASSOC)) {
            $alerts[] = [
                'id' => $smartIdCounter--,
                'title' => 'Approaching Expiration',
                'message' => "Batch {$row['batch_number']} ({$row['name']}) expires in {$row['days']} days!",
                'type' => 'warning',
                'is_read' => false,
                'is_smart' => true,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // 3. PERSISTENT: Database Inbox Messages
        try {
            $inboxStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC");
            $inboxStmt->execute([$user->sub]);
            while ($row = $inboxStmt->fetch(PDO::FETCH_ASSOC)) {
                $row['is_smart'] = false;
                $alerts[] = $row;
            }
        } catch (Exception $e) {
            // Fails cleanly if migration hasn't run yet
        }

        response(200, true, $alerts);
    }
}

response(404, false, null, "Endpoint not found");
