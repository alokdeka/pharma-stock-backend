<?php

class OrderController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function lowStock() {
        authenticate(); // Any

        $stmt = $this->pdo->query("
            SELECT m.id as medicine_id, m.name, m.reorder_point,
                   COALESCE(SUM(b.quantity), 0) as current_stock
            FROM medicines m
            LEFT JOIN batches b ON m.id = b.medicine_id
            GROUP BY m.id
            HAVING current_stock < m.reorder_point
        ");
        
        $data = $stmt->fetchAll();
        foreach ($data as &$row) {
            $row['shortage'] = $row['reorder_point'] - $row['current_stock'];
        }

        response(200, true, $data, 'Low stock medicines retrieved');
    }

    public function index() {
        authenticate(['admin', 'manager']);
        $stmt = $this->pdo->query("SELECT * FROM purchase_orders ORDER BY created_at DESC");
        response(200, true, $stmt->fetchAll(), 'Purchase orders retrieved');
    }

    public function store($input) {
        $user = authenticate(['manager']);
        
        $medicine_id = $input['medicine_id'] ?? null;
        $quantity = $input['quantity'] ?? null;
        $supplier_id = $input['supplier_id'] ?? null;

        if (!$medicine_id || !$quantity) {
            response(400, false, null, 'Medicine ID and quantity are required');
        }

        $stmt = $this->pdo->prepare("INSERT INTO purchase_orders (medicine_id, quantity, supplier_id, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$medicine_id, $quantity, $supplier_id, $user->sub]);

        response(201, true, ['id' => $this->pdo->lastInsertId()], 'Purchase order generated');
    }

    public function updateStatus($id, $input) {
        authenticate(['admin']);
        
        $status = $input['status'] ?? null;
        $allowedStatuses = ['pending', 'approved', 'received'];

        if (!$status || !in_array($status, $allowedStatuses)) {
            response(400, false, null, 'Invalid status');
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            if ($status === 'approved') {
                $poStmt = $this->pdo->prepare("SELECT created_by, medicine_id FROM purchase_orders WHERE id = ?");
                $poStmt->execute([$id]);
                $poInfo = $poStmt->fetch();
                if ($poInfo && $poInfo['created_by']) {
                    $medStmt = $this->pdo->prepare("SELECT name FROM medicines WHERE id = ?");
                    $medStmt->execute([$poInfo['medicine_id']]);
                    $medName = $medStmt->fetchColumn();

                    try {
                        $notifStmt = $this->pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'PO Approved', ?, 'success')");
                        $notifStmt->execute([$poInfo['created_by'], "Your purchase order for \"$medName\" has been approved!"]);
                    } catch (Exception $e) { /* Ignore if migration not run */ }
                }
            }

            response(200, true, null, 'Status updated successfully');
        } catch (Exception $e) {
            response(500, false, null, 'Failed to update status');
        }
    }
}
