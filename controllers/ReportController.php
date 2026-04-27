<?php

class ReportController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

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

    public function batchSales($batch_number) {
        authenticate(['admin', 'manager']);

        if (!$batch_number) {
            response(400, false, null, 'Batch number is required');
        }

        $stmt = $this->pdo->prepare("
            SELECT t.*, u.name as created_by_name 
            FROM transactions t
            JOIN batches b ON t.batch_id = b.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE b.batch_number = ? AND t.type = 'out'
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$batch_number]);

        response(200, true, $stmt->fetchAll(), 'Batch sales retrieved');
    }

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
}
