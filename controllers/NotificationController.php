<?php
use OpenApi\Attributes as OA;

class NotificationController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/notifications/smart",
        summary: "Pull Active User Alerts",
        tags: ["Notifications"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Found Dynamic and Persistent Alerts")]
    )]
    public function smart() {
        $user = authenticate();
        $alerts = [];
        $smartIdCounter = -1; // Negative IDs represent dynamic smart alerts so they don't collide with DB IDs

        // 1. DYNAMIC: Low Stock Alerts
        $stockStmt = $this->pdo->query("
            SELECT m.name, m.reorder_point, COALESCE(SUM(b.quantity), 0) as total_stock
            FROM medicines m LEFT JOIN batches b ON m.id = b.medicine_id AND b.expiry_date >= CURDATE()
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
        $expireStmt = $this->pdo->query("
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
            $inboxStmt = $this->pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC");
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

    #[OA\Post(
        path: "/notifications/{id}/read",
        summary: "Mark Notification as Read",
        tags: ["Notifications"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))],
        responses: [new OA\Response(response: 200, description: "Notification marked as read")]
    )]
    public function markRead($id) {
        $user = authenticate();
        try {
            $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user->sub]);
            response(200, true, null, "Notification marked as read");
        } catch (Exception $e) {
            response(500, false, null, "Error marking notification as read");
        }
    }
}
