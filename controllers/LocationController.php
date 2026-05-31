<?php
use OpenApi\Attributes as OA;

class LocationController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/locations",
        summary: "Retrieve all warehouse location bins with capacity utilization",
        tags: ["Warehouse Bins"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Warehouse locations list compiled successfully")]
    )]
    public function index() {
        authenticate();

        try {
            // Aggregate utilization: sum batches quantity sharing exact location name
            $stmt = $this->pdo->query("
                SELECT l.id, l.name, l.zone, l.capacity, 
                       CAST(COALESCE(SUM(b.quantity), 0) AS SIGNED) as current_occupancy
                FROM locations l
                LEFT JOIN batches b ON l.name = b.location AND b.quantity > 0
                GROUP BY l.id
                ORDER BY l.name ASC
            ");
            
            response(200, true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Locations directory retrieved');
        } catch (Exception $e) {
            response(500, false, null, 'Error loading locations: ' . $e->getMessage());
        }
    }

    #[OA\Post(
        path: "/locations",
        summary: "Register new warehouse storage bin slot",
        tags: ["Warehouse Bins"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "name", type: "string"),
            new OA\Property(property: "zone", type: "string", example: "aisle-a"),
            new OA\Property(property: "capacity", type: "integer")
        ])),
        responses: [new OA\Response(response: 201, description: "Storage bin registered successfully")]
    )]
    public function store($input) {
        $user = authenticate(['admin', 'manager']);

        $name = trim($input['name'] ?? '');
        $zone = trim($input['zone'] ?? '');
        $capacity = isset($input['capacity']) ? intval($input['capacity']) : 1000;

        if (empty($name) || empty($zone) || $capacity <= 0) {
            response(400, false, null, 'All fields are required and capacity must be positive');
        }

        $allowedZones = ['aisle-a', 'aisle-b', 'cold-storage', 'secured-vault'];
        if (!in_array($zone, $allowedZones)) {
            response(400, false, null, 'Invalid zone category selected');
        }

        try {
            // Check uniqueness
            $check = $this->pdo->prepare("SELECT id FROM locations WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                response(400, false, null, 'Location name already exists');
            }

            $stmt = $this->pdo->prepare("INSERT INTO locations (name, zone, capacity) VALUES (?, ?, ?)");
            $stmt->execute([$name, $zone, $capacity]);
            $newId = $this->pdo->lastInsertId();

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Created new warehouse storage bin', "Bin: $name, Zone: $zone, Cap: $capacity");

            response(201, true, ['id' => $newId], 'Location registered successfully');
        } catch (Exception $e) {
            response(500, false, null, 'Error registering location: ' . $e->getMessage());
        }
    }

    #[OA\Put(
        path: "/locations/{id}",
        summary: "Update warehouse bin metadata and cascade lot changes",
        tags: ["Warehouse Bins"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: "name", type: "string"),
            new OA\Property(property: "zone", type: "string"),
            new OA\Property(property: "capacity", type: "integer")
        ])),
        responses: [new OA\Response(response: 200, description: "Warehouse bin modified successfully")]
    )]
    public function update($id, $input) {
        $user = authenticate(['admin', 'manager']);

        $name = trim($input['name'] ?? '');
        $zone = trim($input['zone'] ?? '');
        $capacity = isset($input['capacity']) ? intval($input['capacity']) : 1000;

        if (empty($name) || empty($zone) || $capacity <= 0) {
            response(400, false, null, 'All fields are required and capacity must be positive');
        }

        $allowedZones = ['aisle-a', 'aisle-b', 'cold-storage', 'secured-vault'];
        if (!in_array($zone, $allowedZones)) {
            response(400, false, null, 'Invalid zone category selected');
        }

        try {
            $this->pdo->beginTransaction();

            // Fetch old location details for cascade update
            $fetchStmt = $this->pdo->prepare("SELECT name FROM locations WHERE id = ?");
            $fetchStmt->execute([$id]);
            $oldName = $fetchStmt->fetchColumn();

            if (!$oldName) {
                $this->pdo->rollBack();
                response(404, false, null, 'Location slot not found');
            }

            // Uniqueness check for renames
            if ($oldName !== $name) {
                $check = $this->pdo->prepare("SELECT id FROM locations WHERE name = ? AND id != ?");
                $check->execute([$name, $id]);
                if ($check->fetch()) {
                    $this->pdo->rollBack();
                    response(400, false, null, 'Location name already registered elsewhere');
                }
            }

            // Update main record
            $updateLoc = $this->pdo->prepare("UPDATE locations SET name = ?, zone = ?, capacity = ? WHERE id = ?");
            $updateLoc->execute([$name, $zone, $capacity, $id]);

            // Cascade rename batches sharing name
            if ($oldName !== $name) {
                $updateBatches = $this->pdo->prepare("UPDATE batches SET location = ? WHERE location = ?");
                $updateBatches->execute([$name, $oldName]);
            }

            $this->pdo->commit();

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Updated warehouse bin slot details', "Bin ID: $id, Renamed from \"$oldName\" to \"$name\", Zone: $zone, Cap: $capacity");

            response(200, true, null, 'Location slot modified successfully');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            response(500, false, null, 'Error updating location: ' . $e->getMessage());
        }
    }

    #[OA\Delete(
        path: "/locations/{id}",
        summary: "Remove retired warehouse bin slot",
        tags: ["Warehouse Bins"],
        security: [["bearerAuth" => []]],
        parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))],
        responses: [new OA\Response(response: 200, description: "Bin removed successfully")]
    )]
    public function delete($id) {
        $user = authenticate(['admin']); // Delete role-locked strictly to Admin

        try {
            // Fetch bin name
            $fetchStmt = $this->pdo->prepare("SELECT name FROM locations WHERE id = ?");
            $fetchStmt->execute([$id]);
            $name = $fetchStmt->fetchColumn();

            if (!$name) {
                response(404, false, null, 'Location slot not found');
            }

            // Safeguard: Reject deletes if the bin contains active drug items
            $checkOccupied = $this->pdo->prepare("SELECT SUM(quantity) as active_units FROM batches WHERE location = ? AND quantity > 0");
            $checkOccupied->execute([$name]);
            $activeUnits = intval($checkOccupied->fetchColumn() ?: 0);

            if ($activeUnits > 0) {
                response(400, false, null, "Cannot delete location bin while it houses {$activeUnits} active units.");
            }

            $stmt = $this->pdo->prepare("DELETE FROM locations WHERE id = ?");
            $stmt->execute([$id]);

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, 'Deleted warehouse bin slot', "Bin: $name (ID: $id)");

            response(200, true, null, 'Location retired successfully');
        } catch (Exception $e) {
            response(500, false, null, 'Error deleting location: ' . $e->getMessage());
        }
    }
}
