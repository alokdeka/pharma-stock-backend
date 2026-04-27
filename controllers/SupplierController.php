<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/suppliers/(\d+)$#', $path, $matches)) {
    authenticate(['admin', 'manager']);
    $targetId = $matches[1];
    global $pdo;

    if ($method == 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$targetId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier) response(200, true, $supplier);
        else response(404, false, null, "Supplier not found");
    }

    if ($method == 'PUT') {
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $pdo->prepare("UPDATE suppliers SET name=?, email=?, phone=?, contact=?, address=? WHERE id=?");
        $stmt->execute([$data->name, $data->email ?? null, $data->phone ?? null, $data->contact ?? null, $data->address ?? null, $targetId]);
        response(200, true, null, "Supplier updated");
    }

    if ($method == 'DELETE') {
        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
        try {
            $stmt->execute([$targetId]);
            response(200, true, null, "Supplier deleted");
        } catch (Exception $e) {
            response(400, false, null, "Cannot delete supplier linked to active Purchase Orders");
        }
    }
}

if (preg_match('#/suppliers/?$#', $path)) {
    authenticate(['admin', 'manager', 'distributor']);
    global $pdo;

    if ($method == 'GET') {
        $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC");
        response(200, true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method == 'POST') {
        authenticate(['admin', 'manager']); // Dist. can only read
        $data = json_decode(file_get_contents("php://input"));
        if (empty($data->name)) {
            response(400, false, null, "Supplier name is required");
        }
        $stmt = $pdo->prepare("INSERT INTO suppliers (name, email, phone, contact, address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$data->name, $data->email ?? null, $data->phone ?? null, $data->contact ?? null, $data->address ?? null]);
        response(201, true, ['id' => $pdo->lastInsertId()], "Supplier added");
    }
}

response(404, false, null, "Endpoint not found");
