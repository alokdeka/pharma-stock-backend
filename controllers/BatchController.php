<?php

class BatchController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function index() {
        authenticate(); // Any
        $stmt = $this->pdo->query("SELECT * FROM batches ORDER BY created_at DESC");
        response(200, true, $stmt->fetchAll(), 'Batches retrieved');
    }

    public function show($id) {
        authenticate(); // Any
        $stmt = $this->pdo->prepare("SELECT * FROM batches WHERE id = ?");
        $stmt->execute([$id]);
        $batch = $stmt->fetch();
        if (!$batch) response(404, false, null, 'Batch not found');
        
        $stmt2 = $this->pdo->prepare("SELECT * FROM transactions WHERE batch_id = ? ORDER BY created_at DESC");
        $stmt2->execute([$id]);
        $batch['transactions'] = $stmt2->fetchAll();

        response(200, true, $batch, 'Batch retrieved');
    }

    public function search($batch_number) {
        authenticate(); // Any
        $stmt = $this->pdo->prepare("SELECT * FROM batches WHERE batch_number = ?");
        $stmt->execute([$batch_number]);
        response(200, true, $stmt->fetchAll(), 'Batches found by number');
    }

    public function store($input) {
        $user = authenticate(['manager']);
        
        $medicine_id = $input['medicine_id'] ?? null;
        $batch_number = $input['batch_number'] ?? null;
        $mfg_date = $input['mfg_date'] ?? null;
        $expiry_date = $input['expiry_date'] ?? null;
        $quantity = $input['quantity'] ?? null;
        $location = $input['location'] ?? 'Main Warehouse';
        $unit_cost = $input['unit_cost'] ?? 0.00;

        if (!$medicine_id || !$batch_number || !$mfg_date || !$expiry_date || $quantity === null) {
            response(400, false, null, 'All fields are required');
        }

        try {
            $this->pdo->beginTransaction();

            // Insert Batch
            $stmt = $this->pdo->prepare("INSERT INTO batches (medicine_id, batch_number, mfg_date, expiry_date, quantity, location, unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$medicine_id, $batch_number, $mfg_date, $expiry_date, $quantity, $location, $unit_cost]);
            $batch_id = $this->pdo->lastInsertId();

            // Record transaction 'in'
            $stmt = $this->pdo->prepare("INSERT INTO transactions (batch_id, type, quantity, reference, created_by) VALUES (?, 'in', ?, ?, ?)");
            $stmt->execute([$batch_id, $quantity, 'INITIAL_STOCK', $user->sub]);

            $this->pdo->commit();
            response(201, true, ['id' => $batch_id], 'Batch created');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            response(500, false, null, 'Error: ' . $e->getMessage());
        }
    }

    public function sell($medicine_id, $input) {
        // According to the guide, FEFO means "When a sale is recorded against a medicine (not a specific batch), the API must auto-select the batch expiring soonest."
        // Wait, the API endpoint is `POST /api/batches/{id}/sell` which implies an ID of a medicine because the payload has `{ "quantity": 50, "reference": "INV-1023" }` and the guide says "When a sale is recorded against a medicine (not a specific batch)". Wait, the route says `/api/batches/{id}/sell`, so is `{id}` the medicine ID or batch ID?
        // Let's assume it's `medicine_id` as per section 7 "sell against a medicine"
        $user = authenticate(['manager']);
        
        $quantity = $input['quantity'] ?? 0;
        $reference = $input['reference'] ?? '';

        if ($quantity <= 0) {
            response(400, false, null, 'Quantity must be greater than zero');
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT id, quantity, location, batch_number FROM batches 
                WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= CURDATE()
                ORDER BY expiry_date ASC
            ");
            $stmt->execute([$medicine_id]);
            $batches = $stmt->fetchAll();

            $remaining_qty = $quantity;
            $soldBatches = [];

            foreach ($batches as $batch) {
                if ($remaining_qty <= 0) break;

                $take = min($batch['quantity'], $remaining_qty);
                $remaining_qty -= $take;

                // Update batch stock
                $updStmt = $this->pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id = ?");
                $updStmt->execute([$take, $batch['id']]);

                // Record transaction
                $txStmt = $this->pdo->prepare("INSERT INTO transactions (batch_id, type, quantity, reference, created_by) VALUES (?, 'out', ?, ?, ?)");
                $txStmt->execute([$batch['id'], $take, $reference, $user->sub]);

                $soldBatches[] = ['batch_id' => $batch['id'], 'batch_number' => $batch['batch_number'], 'quantity' => $take, 'location' => $batch['location']];
            }

            if ($remaining_qty > 0) {
                $this->pdo->rollBack();
                response(400, false, null, 'Not enough non-expired stock available to fulfil the request');
            }

            // Check Low Stock
            $stockStmt = $this->pdo->prepare("
                SELECT SUM(b.quantity) as total_stock, m.reorder_point 
                FROM batches b JOIN medicines m ON b.medicine_id = m.id 
                WHERE m.id = ? GROUP BY m.id
            ");
            $stockStmt->execute([$medicine_id]);
            $stockData = $stockStmt->fetch();

            $this->pdo->commit();

            $alert = ($stockData && $stockData['total_stock'] < $stockData['reorder_point']);

            // Phase 3: Email Notification Engine Trigger
            require_once __DIR__ . '/../helpers/email.php';
            if ($alert) {
                $medStmt = $this->pdo->prepare("SELECT name FROM medicines WHERE id = ?");
                $medStmt->execute([$medicine_id]);
                $medName = $medStmt->fetchColumn();
                sendLowStockEmail('admin@pharma.com', $medName, $stockData['total_stock'], $stockData['reorder_point']);
            }

            response(200, true, ['sold_from_batches' => $soldBatches, 'alert' => $alert], 'Sale recorded successfully');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            response(500, false, null, 'Error: ' . $e->getMessage());
        }
    }

    public function spoil($id, $input) {
        $user = authenticate(['admin', 'manager']);
        $quantity = $input['quantity'] ?? 0;
        $reason = $input['reason'] ?? 'Spoilage/Damage';

        if ($quantity <= 0) response(400, false, null, 'Quantity must be greater than zero');

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT quantity FROM batches WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $batch = $stmt->fetch();

            if (!$batch || $batch['quantity'] < $quantity) {
                $this->pdo->rollBack();
                response(400, false, null, 'Insufficient stock in this batch to mark as spoiled');
                exit; // Need to exit explicitly because response relies on helpers/response.php wait, response() does exit automatically. So this is fine.
            }

            $updStmt = $this->pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id = ?");
            $updStmt->execute([$quantity, $id]);

            $txStmt = $this->pdo->prepare("INSERT INTO transactions (batch_id, type, quantity, reference, created_by) VALUES (?, 'spoilage', ?, ?, ?)");
            $txStmt->execute([$id, $quantity, $reason, $user->sub]);

            $this->pdo->commit();
            response(200, true, null, 'Stock marked as spoiled');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            response(500, false, null, 'Error: ' . $e->getMessage());
        }
    }
}
