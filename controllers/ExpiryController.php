<?php

class ExpiryController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function dashboard() {
        authenticate(); // Any

        $stmt = $this->pdo->query("
            SELECT b.batch_number, m.name as medicine_name, b.expiry_date, b.quantity,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry
            FROM batches b
            JOIN medicines m ON b.medicine_id = m.id
            WHERE b.quantity > 0
            ORDER BY b.expiry_date ASC
        ");
        
        $data = $stmt->fetchAll();
        foreach ($data as &$row) {
            $days = (int) $row['days_to_expiry'];
            if ($days <= 30)      $status = 'red';
            elseif ($days <= 60)  $status = 'yellow';
            else                  $status = 'green';
            $row['status'] = $status;
        }

        response(200, true, $data, 'Expiry dashboard retrieved');
    }

    public function critical() {
        authenticate(); // Any

        $stmt = $this->pdo->query("
            SELECT b.batch_number, m.name as medicine_name, b.expiry_date, b.quantity,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry
            FROM batches b
            JOIN medicines m ON b.medicine_id = m.id
            WHERE b.quantity > 0 AND DATEDIFF(b.expiry_date, CURDATE()) <= 60
            ORDER BY b.expiry_date ASC
        ");
        
        $data = $stmt->fetchAll();
        foreach ($data as &$row) {
            $days = (int) $row['days_to_expiry'];
            if ($days <= 30)      $status = 'red';
            elseif ($days <= 60)  $status = 'yellow';
            $row['status'] = $status;
        }

        response(200, true, $data, 'Critical expiry batches retrieved');
    }
}
