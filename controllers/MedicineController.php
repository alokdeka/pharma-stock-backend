<?php
use OpenApi\Attributes as OA;

class MedicineController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/medicines",
        summary: "List all medicines master records",
        tags: ["Medicines"],
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(response: 200, description: "Retrieves array of medicines strictly paired with dynamic current_stock aggregate levels")
        ]
    )]
    public function index() {
        authenticate(); // Any role
        
        $stmt = $this->pdo->query("
            SELECT m.*, COALESCE(SUM(b.quantity), 0) as current_stock 
            FROM medicines m 
            LEFT JOIN batches b ON m.id = b.medicine_id AND b.expiry_date >= CURDATE()
            GROUP BY m.id
        ");
        $medicines = $stmt->fetchAll();
        response(200, true, $medicines, 'Medicines retrieved');
    }

    #[OA\Get(
        path: "/medicines/{id}",
        summary: "Get specific medicine profile",
        tags: ["Medicines"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, description: "Numeric ID of the medicine", schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Extracted singular medicine profile payload"),
            new OA\Response(response: 404, description: "Medicine ID strictly not found")
        ]
    )]
    public function show($id) {
        authenticate(); // Any role
        
        $stmt = $this->pdo->prepare("
            SELECT m.*, COALESCE(SUM(b.quantity), 0) as current_stock 
            FROM medicines m 
            LEFT JOIN batches b ON m.id = b.medicine_id AND b.expiry_date >= CURDATE()
            WHERE m.id = ?
            GROUP BY m.id
        ");
        $stmt->execute([$id]);
        $medicine = $stmt->fetch();

        if ($medicine) {
            response(200, true, $medicine, 'Medicine retrieved');
        } else {
            response(404, false, null, 'Medicine not found');
        }
    }

    #[OA\Post(
        path: "/medicines",
        summary: "Inject a new Medicine configuration",
        tags: ["Medicines"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "price"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Paracetamol 500mg"),
                    new OA\Property(property: "manufacturer", type: "string", example: "GSK"),
                    new OA\Property(property: "category", type: "string", example: "Analgesic"),
                    new OA\Property(property: "price", type: "number", format: "float", example: 10.50),
                    new OA\Property(property: "reorder_point", type: "integer", example: 50)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Medicine structurally initialized"),
            new OA\Response(response: 400, description: "A required variable critically missing")
        ]
    )]
    public function store($input) {
        authenticate(['admin']);
        
        $name = $input['name'] ?? null;
        $manufacturer = $input['manufacturer'] ?? null;
        $category = $input['category'] ?? null;
        $price = $input['price'] ?? null;
        $reorder_point = $input['reorder_point'] ?? 50;

        if (!$name || !$price) {
            response(400, false, null, 'Name and price are required');
        }

        $stmt = $this->pdo->prepare("INSERT INTO medicines (name, manufacturer, category, price, reorder_point) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $manufacturer, $category, $price, $reorder_point]);
        
        response(201, true, ['id' => $this->pdo->lastInsertId()], 'Medicine created');
    }

    #[OA\Put(
        path: "/medicines/{id}",
        summary: "Patch Medicine Attributes",
        tags: ["Medicines"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "price", type: "number", format: "float", example: 15.00),
                    new OA\Property(property: "reorder_point", type: "integer", example: 100)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Medicine successfully hydrated with new attributes"),
            new OA\Response(response: 400, description: "Absolutely no valid fields provided")
        ]
    )]
    public function update($id, $input) {
        authenticate(['admin', 'manager']);
        
        $updates = [];
        $params = [];
        $allowedFields = ['name', 'manufacturer', 'category', 'price', 'reorder_point'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "$field = ?";
                $params[] = $input[$field];
            }
        }

        if (empty($updates)) {
            response(400, false, null, 'No fields to update');
        }

        $params[] = $id;
        $sql = "UPDATE medicines SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        response(200, true, null, 'Medicine updated');
    }

    #[OA\Delete(
        path: "/medicines/{id}",
        summary: "Purge Medicine from System",
        tags: ["Medicines"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Medicine safely and permanently destroyed")
        ]
    )]
    public function destroy($id) {
        authenticate(['admin']);
        
        $stmt = $this->pdo->prepare("DELETE FROM medicines WHERE id = ?");
        $stmt->execute([$id]);

        response(200, true, null, 'Medicine deleted');
    }
}
