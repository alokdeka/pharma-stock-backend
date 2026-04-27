<?php

class MedicineController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function index() {
        authenticate(); // Any role
        
        $stmt = $this->pdo->query("
            SELECT m.*, COALESCE(SUM(b.quantity), 0) as current_stock 
            FROM medicines m 
            LEFT JOIN batches b ON m.id = b.medicine_id 
            GROUP BY m.id
        ");
        $medicines = $stmt->fetchAll();
        response(200, true, $medicines, 'Medicines retrieved');
    }

    public function show($id) {
        authenticate(); // Any role
        
        $stmt = $this->pdo->prepare("
            SELECT m.*, COALESCE(SUM(b.quantity), 0) as current_stock 
            FROM medicines m 
            LEFT JOIN batches b ON m.id = b.medicine_id 
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

    public function destroy($id) {
        authenticate(['admin']);
        
        $stmt = $this->pdo->prepare("DELETE FROM medicines WHERE id = ?");
        $stmt->execute([$id]);

        response(200, true, null, 'Medicine deleted');
    }
}
