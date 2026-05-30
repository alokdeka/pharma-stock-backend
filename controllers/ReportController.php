<?php
use OpenApi\Attributes as OA;

class ReportController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/reports/inventory",
        summary: "Full System Inventory Valuation",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Extracted full multi-query inventory aggregates")]
    )]
    public function inventory() {
        authenticate(['admin', 'manager']);

        $stmt = $this->pdo->query("
            SELECT m.id, m.name, m.category, m.price, m.reorder_point,
                   COALESCE(SUM(b.quantity), 0) as total_stock
            FROM medicines m
            LEFT JOIN batches b ON m.id = b.medicine_id
            GROUP BY m.id
        ");

        response(200, true, $stmt->fetchAll(), 'Inventory report retrieved');
    }

    #[OA\Get(
        path: "/reports/expiry-summary",
        summary: "Heatmap Expiry Distributions",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Totalized aggregate breakdown of Red/Yellow/Green items")]
    )]
    public function expirySummary() {
        authenticate();

        $stmt = $this->pdo->query("
            SELECT DATEDIFF(expiry_date, CURDATE()) as days_to_expiry, quantity
            FROM batches WHERE quantity > 0
        ");
        
        $data = $stmt->fetchAll();
        $summary = ['red' => 0, 'yellow' => 0, 'green' => 0];
        
        foreach ($data as $row) {
            $days = (int) $row['days_to_expiry'];
            if ($days <= 30)      $summary['red']++;
            elseif ($days <= 60)  $summary['yellow']++;
            else                  $summary['green']++;
        }

        response(200, true, $summary, 'Expiry summary retrieved');
    }

    #[OA\Get(
        path: "/reports/batch-sales",
        summary: "Filter Transactions by exact Batch",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "batch_number", in: "query", required: true, schema: new OA\Schema(type: "string"))],
        responses: [new OA\Response(response: 200, description: "Cross-referenced sales analytics for batch")]
    )]
    public function batchSales($batch_number = null) {
        authenticate(['admin', 'manager']);

        if (!$batch_number) {
            $stmt = $this->pdo->prepare("
                SELECT t.*, b.batch_number, u.name as created_by_name 
                FROM transactions t
                JOIN batches b ON t.batch_id = b.id
                LEFT JOIN users u ON t.created_by = u.id
                WHERE t.type = 'out'
                ORDER BY t.created_at DESC
                LIMIT 50
            ");
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare("
                SELECT t.*, b.batch_number, u.name as created_by_name 
                FROM transactions t
                JOIN batches b ON t.batch_id = b.id
                LEFT JOIN users u ON t.created_by = u.id
                WHERE b.batch_number = ? AND t.type = 'out'
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([$batch_number]);
        }

        response(200, true, $stmt->fetchAll(), 'Batch sales retrieved');
    }

    #[OA\Get(
        path: "/reports/transactions",
        summary: "Extract Generic Ledger Period",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "from", in: "query", required: true, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "to", in: "query", required: true, schema: new OA\Schema(type: "string", format: "date"))
        ],
        responses: [new OA\Response(response: 200, description: "Total chronological database transactions")]
    )]
    public function transactions($from, $to) {
        authenticate(['admin', 'manager']);

        if (!$from || !$to) {
            response(400, false, null, 'From and to dates are required');
        }

        $stmt = $this->pdo->prepare("
            SELECT t.*, b.batch_number, m.name as medicine_name, u.name as user_name
            FROM transactions t
            JOIN batches b ON t.batch_id = b.id
            JOIN medicines m ON b.medicine_id = m.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE DATE(t.created_at) >= ? AND DATE(t.created_at) <= ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$from, $to]);

        response(200, true, $stmt->fetchAll(), 'Transactions report retrieved');
    }

    #[OA\Get(
        path: "/reports/sales-trend",
        summary: "Retrieve recent 7-day Sales Volume Graph",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Dynamic Charting vector metrics generated")]
    )]
    public function salesTrend() {
        authenticate();

        $stmt = $this->pdo->query("
            SELECT DATE(created_at) as name, COALESCE(SUM(quantity), 0) as volume
            FROM transactions
            WHERE type = 'out' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) ASC
        ");

        response(200, true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Sales trend retrieved');
    }

    #[OA\Get(
        path: "/reports/financials",
        summary: "Fetch Current Accounting PnL",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Raw computed arrays of Revenue, Loss, COGS")]
    )]
    public function financials() {
        authenticate(['admin', 'manager']);
        
        $stmt = $this->pdo->query("
            SELECT 
                COALESCE(SUM(CASE WHEN t.type = 'out' THEN t.quantity * m.price ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN t.type = 'out' THEN t.quantity * b.unit_cost ELSE 0 END), 0) as cost_of_goods_sold,
                COALESCE(SUM(CASE WHEN t.type = 'spoilage' THEN t.quantity * b.unit_cost ELSE 0 END), 0) as spoilage_loss
            FROM transactions t
            JOIN batches b ON t.batch_id = b.id
            JOIN medicines m ON b.medicine_id = m.id
        ");

        response(200, true, $stmt->fetch(PDO::FETCH_ASSOC), 'Financials retrieved');
    }

    #[OA\Get(
        path: "/reports/calendar-events",
        summary: "Retrieve consolidated WMS calendar events",
        tags: ["Analytics & Reports"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Consolidated events retrieved successfully")]
    )]
    public function calendarEvents() {
        authenticate();

        $events = [];

        // 1. Medicine Expirations (Red)
        $expiryStmt = $this->pdo->query("
            SELECT b.id as id, b.expiry_date as date, 
                   b.batch_number as title, m.name as medicine_name, b.quantity as quantity,
                   b.location as location
            FROM batches b
            JOIN medicines m ON b.medicine_id = m.id
            WHERE b.quantity > 0
        ");
        foreach ($expiryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'exp-' . $row['id'],
                'ref_id' => $row['id'],
                'type' => 'expiry',
                'date' => $row['date'],
                'title' => "Expiry: {$row['medicine_name']} ({$row['title']})",
                'details' => "Batch {$row['title']} of {$row['medicine_name']} expiring on this date. Current quantity: {$row['quantity']} units. Storage: {$row['location']}.",
                'color' => 'var(--status-red)'
            ];
        }

        // 2. Purchase Orders Placed (Purple)
        $poStmt = $this->pdo->query("
            SELECT po.id as id, DATE(po.created_at) as date,
                   m.name as medicine_name, po.quantity as quantity, po.status as status
            FROM purchase_orders po
            JOIN medicines m ON po.medicine_id = m.id
        ");
        foreach ($poStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'po-' . $row['id'],
                'ref_id' => $row['id'],
                'type' => 'po',
                'date' => $row['date'],
                'title' => "PO #{$row['id']}: {$row['medicine_name']}",
                'details' => "Purchase Order generated for {$row['quantity']} units of {$row['medicine_name']}. Current status: " . strtoupper($row['status']) . ".",
                'color' => '#8b5cf6'
            ];
        }

        // 3. Inbound Shipments Intake (Green)
        $inboundStmt = $this->pdo->query("
            SELECT t.id as id, DATE(t.created_at) as date,
                   b.batch_number as batch_number, m.name as medicine_name, t.quantity as quantity,
                   b.location as location, b.id as batch_id
            FROM transactions t
            JOIN batches b ON t.batch_id = b.id
            JOIN medicines m ON b.medicine_id = m.id
            WHERE t.type = 'in'
        ");
        foreach ($inboundStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'in-' . $row['id'],
                'ref_id' => $row['batch_id'],
                'type' => 'inbound',
                'date' => $row['date'],
                'title' => "Intake: {$row['medicine_name']}",
                'details' => "Logged inbound batch {$row['batch_number']} of {$row['medicine_name']} containing {$row['quantity']} units into bin {$row['location']}.",
                'color' => 'var(--status-green)'
            ];
        }

        // 4. Spoilage Loss Logs (Orange)
        $spoilageStmt = $this->pdo->query("
            SELECT t.id as id, DATE(t.created_at) as date,
                   b.batch_number as batch_number, m.name as medicine_name, t.quantity as quantity,
                   t.reference as reason, b.id as batch_id
            FROM transactions t
            JOIN batches b ON t.batch_id = b.id
            JOIN medicines m ON b.medicine_id = m.id
            WHERE t.type = 'spoilage'
        ");
        foreach ($spoilageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'spoil-' . $row['id'],
                'ref_id' => $row['batch_id'],
                'type' => 'spoilage',
                'date' => $row['date'],
                'title' => "Spoilage: {$row['medicine_name']}",
                'details' => "Spoilage logged for {$row['quantity']} units of {$row['medicine_name']} (Batch {$row['batch_number']}). Reason: {$row['reason']}.",
                'color' => '#f59e0b'
            ];
        }

        response(200, true, $events, 'Calendar events compiled successfully');
    }
}
