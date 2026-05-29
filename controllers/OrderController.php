<?php
use OpenApi\Attributes as OA;

class OrderController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/stock/low",
        summary: "Get Medicines Below Reorder Point",
        tags: ["Orders & Stock"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "List of items actively experiencing low-stock shortages")]
    )]
    public function lowStock() {
        authenticate(); // Any

        $stmt = $this->pdo->query("
            SELECT m.id as medicine_id, m.name, m.reorder_point,
                   COALESCE(SUM(b.quantity), 0) as current_stock
            FROM medicines m
            LEFT JOIN batches b ON m.id = b.medicine_id AND b.expiry_date >= CURDATE()
            GROUP BY m.id
            HAVING current_stock < m.reorder_point
        ");
        
        $data = $stmt->fetchAll();
        foreach ($data as &$row) {
            $row['shortage'] = $row['reorder_point'] - $row['current_stock'];
        }

        response(200, true, $data, 'Low stock medicines retrieved');
    }

    #[OA\Get(
        path: "/orders",
        summary: "Get Purchase Orders",
        tags: ["Orders & Stock"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Extracts all chronological PO records natively")]
    )]
    public function index() {
        authenticate(['admin', 'manager']);
        $stmt = $this->pdo->query("SELECT * FROM purchase_orders ORDER BY created_at DESC");
        response(200, true, $stmt->fetchAll(), 'Purchase orders retrieved');
    }

    #[OA\Post(
        path: "/orders",
        summary: "Generate Purchase Order",
        tags: ["Orders & Stock"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "medicine_id", type: "integer"),
            new OA\Property(property: "quantity", type: "integer"),
            new OA\Property(property: "supplier_id", type: "integer")
        ])),
        responses: [new OA\Response(response: 201, description: "Successfully established remote purchase intent algorithm")]
    )]
    public function store($input) {
        $user = authenticate(['admin', 'manager']);
        
        $medicine_id = $input['medicine_id'] ?? null;
        $quantity = $input['quantity'] ?? null;
        $supplier_id = $input['supplier_id'] ?? null;

        if (!$medicine_id || !$quantity) {
            response(400, false, null, 'Medicine ID and quantity are required');
        }

        $stmt = $this->pdo->prepare("INSERT INTO purchase_orders (medicine_id, quantity, supplier_id, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$medicine_id, $quantity, $supplier_id, $user->sub]);
        $newId = $this->pdo->lastInsertId();

        require_once __DIR__ . '/../helpers/log.php';
        logActivity($user->sub, 'Drafted Purchase Order', "PO ID: $newId, Medicine ID: $medicine_id, Qty: $quantity");

        response(201, true, ['id' => $newId], 'Purchase order generated');
    }

    #[OA\Put(
        path: "/orders/{id}/status",
        summary: "Update Generic Order Status",
        tags: ["Orders & Stock"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "status", type: "string", example: "approved")
        ])),
        responses: [new OA\Response(response: 200, description: "Successfully integrated workflow adjustment parameters")]
    )]
    public function updateStatus($id, $input) {
        $user = authenticate(['admin']);
        
        $status = $input['status'] ?? null;
        $allowedStatuses = ['pending', 'approved', 'received'];

        if (!$status || !in_array($status, $allowedStatuses)) {
            response(400, false, null, 'Invalid status');
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Updated Purchase Order status', "PO ID: $id, New Status: $status");
            
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
