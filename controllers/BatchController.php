<?php
use OpenApi\Attributes as OA;

class BatchController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/batches",
        summary: "List all active batches",
        tags: ["Batches"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Returns dynamic list of batches globally")]
    )]
    public function index() {
        authenticate(); // Any
        $stmt = $this->pdo->query("SELECT * FROM batches ORDER BY created_at DESC");
        response(200, true, $stmt->fetchAll(), 'Batches retrieved');
    }

    #[OA\Get(
        path: "/batches/{id}",
        summary: "Trace Batch Details & History",
        tags: ["Batches"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))],
        responses: [new OA\Response(response: 200, description: "Returns individual batch specs and aggregated historical transactions")]
    )]
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

    #[OA\Get(
        path: "/batches/search",
        summary: "Query Exact Batch Numbers",
        tags: ["Batches"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "batch_number", in: "query", required: true, schema: new OA\Schema(type: "string"))],
        responses: [new OA\Response(response: 200, description: "Found items matching lookup parameters")]
    )]
    public function search($batch_number) {
        authenticate(); // Any
        $stmt = $this->pdo->prepare("SELECT * FROM batches WHERE batch_number = ?");
        $stmt->execute([$batch_number]);
        response(200, true, $stmt->fetchAll(), 'Batches found by number');
    }

    #[OA\Post(
        path: "/batches",
        summary: "Store new inbound Batch",
        tags: ["Batches"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "medicine_id", type: "integer"),
            new OA\Property(property: "batch_number", type: "string"),
            new OA\Property(property: "mfg_date", type: "string", format: "date"),
            new OA\Property(property: "expiry_date", type: "string", format: "date"),
            new OA\Property(property: "quantity", type: "integer"),
            new OA\Property(property: "location", type: "string"),
            new OA\Property(property: "unit_cost", type: "number"),
            new OA\Property(property: "po_id", type: "integer", nullable: true)
        ])),
        responses: [new OA\Response(response: 201, description: "Inbound inventory actively added properly")]
    )]
    public function store($input) {
        $user = authenticate(['admin', 'manager']);
        
        $medicine_id = $input['medicine_id'] ?? null;
        $batch_number = $input['batch_number'] ?? null;
        $mfg_date = $input['mfg_date'] ?? null;
        $expiry_date = $input['expiry_date'] ?? null;
        $quantity = $input['quantity'] ?? null;
        $location = $input['location'] ?? 'Main Warehouse';
        $unit_cost = $input['unit_cost'] ?? 0.00;
        $po_id = $input['po_id'] ?? null;

        if (!$medicine_id || !$batch_number || !$mfg_date || !$expiry_date || $quantity === null) {
            response(400, false, null, 'All fields are required');
        }

        try {
            $this->pdo->beginTransaction();

            // Insert Batch
            $stmt = $this->pdo->prepare("INSERT INTO batches (medicine_id, batch_number, mfg_date, expiry_date, quantity, location, unit_cost, po_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$medicine_id, $batch_number, $mfg_date, $expiry_date, $quantity, $location, $unit_cost, $po_id]);
            $batch_id = $this->pdo->lastInsertId();

            // Record transaction 'in'
            $reference = $po_id ? "PO_INGESTION_#{$po_id}" : "INITIAL_STOCK";
            $stmt = $this->pdo->prepare("INSERT INTO transactions (batch_id, type, quantity, reference, created_by) VALUES (?, 'in', ?, ?, ?)");
            $stmt->execute([$batch_id, $quantity, $reference, $user->sub]);

            // Atomically update purchase order status if po_id is provided
            if ($po_id) {
                $poStmt = $this->pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
                $poStmt->execute([$po_id]);
                $poStatus = $poStmt->fetchColumn();
                if ($poStatus) {
                    $updateStmt = $this->pdo->prepare("UPDATE purchase_orders SET status = 'received' WHERE id = ?");
                    $updateStmt->execute([$po_id]);

                    require_once __DIR__ . '/../helpers/log.php';
                    logActivity($user->sub, 'Updated Purchase Order status via Batch Ingestion', "PO ID: $po_id, New Status: received");
                }
            }

            $this->pdo->commit();

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Recorded inbound medicine batch', "Batch: $batch_number, Medicine ID: $medicine_id, Qty: $quantity, Cost: ₹$unit_cost" . ($po_id ? " (PO #$po_id)" : ""));

            response(201, true, ['id' => $batch_id], 'Batch created');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            response(500, false, null, 'Error: ' . $e->getMessage());
        }
    }

    #[OA\Post(
        path: "/batches/{id}/sell",
        summary: "Record FEFO Medicine Consumption",
        tags: ["Batches"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, description: "MEDICINE ID target", schema: new OA\Schema(type: "integer"))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "quantity", type: "integer"),
            new OA\Property(property: "reference", type: "string")
        ])),
        responses: [new OA\Response(response: 200, description: "Chronologically withdrew batch payload safely")]
    )]
    public function sell($medicine_id, $input) {
        // According to the guide, FEFO means "When a sale is recorded against a medicine (not a specific batch), the API must auto-select the batch expiring soonest."
        // Wait, the API endpoint is `POST /api/batches/{id}/sell` which implies an ID of a medicine because the payload has `{ "quantity": 50, "reference": "INV-1023" }` and the guide says "When a sale is recorded against a medicine (not a specific batch)". Wait, the route says `/api/batches/{id}/sell`, so is `{id}` the medicine ID or batch ID?
        // Let's assume it's `medicine_id` as per section 7 "sell against a medicine"
        $user = authenticate(['admin', 'manager']);
        
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
                SELECT COALESCE(SUM(b.quantity), 0) as total_stock, m.reorder_point 
                FROM medicines m 
                LEFT JOIN batches b ON m.id = b.medicine_id AND b.expiry_date >= CURDATE()
                WHERE m.id = ? GROUP BY m.id
            ");
            $stockStmt->execute([$medicine_id]);
            $stockData = $stockStmt->fetch();

            $this->pdo->commit();

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Recorded medicine sale (FEFO)', "Medicine ID: $medicine_id, Total Qty: $quantity, Sold from: " . json_encode($soldBatches));

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

    #[OA\Post(
        path: "/batches/{id}/spoil",
        summary: "Quarantine Spoilage Defectives",
        tags: ["Batches"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, description: "BATCH ID target", schema: new OA\Schema(type: "integer"))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "quantity", type: "integer"),
            new OA\Property(property: "reason", type: "string", example: "Spillage/Expired")
        ])),
        responses: [new OA\Response(response: 200, description: "Quantity physically extracted into a loss vector endpoint")]
    )]
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

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Recorded stock spoilage write-off', "Batch ID: $id, Qty: $quantity, Reason: $reason");

            response(200, true, null, 'Stock marked as spoiled');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            response(500, false, null, 'Error: ' . $e->getMessage());
        }
    }
}
