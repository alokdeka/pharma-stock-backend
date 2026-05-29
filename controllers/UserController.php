<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Profile Endpoints
if (preg_match('#/users/me/password$#', $path) && $method == 'PUT') {
    $user = authenticate();
    $data = json_decode(file_get_contents("php://input"));
    if (!$data || !isset($data->old_password) || !isset($data->new_password)) {
        response(400, false, null, "Incomplete data");
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user->sub]);
    $currentHash = $stmt->fetchColumn();

    if (!password_verify($data->old_password, $currentHash)) {
        response(401, false, null, "Incorrect current password");
    }

    $newHash = password_hash($data->new_password, PASSWORD_BCRYPT);
    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->execute([$newHash, $user->sub]);

    require_once __DIR__ . '/../helpers/log.php';
    logActivity($user->sub, 'Updated own password');

    response(200, true, null, "Password updated successfully");
}

if (preg_match('#/users/me$#', $path)) {
    $user = authenticate();
    global $pdo;
    if ($method == 'GET') {
        $stmt = $pdo->prepare("SELECT id, name, email, role, status, created_at FROM users WHERE id = ?");
        $stmt->execute([$user->sub]);
        response(200, true, $stmt->fetch(PDO::FETCH_ASSOC));
    }
    if ($method == 'PUT') {
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $stmt->execute([$data->name ?? $user->name, $data->email ?? $user->email, $user->sub]);

        require_once __DIR__ . '/../helpers/log.php';
        logActivity($user->sub, 'Updated own profile details');

        response(200, true, null, "Profile updated successfully");
    }
}

// User Activity Logs (Admin Only) - Must be before users/{id} to avoid matching "activity" as user ID
if (preg_match('#/users/activity$#', $path) && $method == 'GET') {
    $user = authenticate(['admin']);
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT a.id, a.user_id, a.action, a.details, a.ip_address, a.created_at, u.name as user_name, u.email as user_email
        FROM activity_logs a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 100
    ");
    response(200, true, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Specific User Updates (Must be placed before generic /users to avoid false matching)
if (preg_match('#/users/(\d+)$#', $path, $matches)) {
    $user = authenticate(['admin']);
    $targetId = $matches[1];
    global $pdo;

    if ($method == 'PUT') {
        $data = json_decode(file_get_contents("php://input"));
        
        // Load original values first to log changes accurately
        $origStmt = $pdo->prepare("SELECT name, email, role, status FROM users WHERE id = ?");
        $origStmt->execute([$targetId]);
        $origUser = $origStmt->fetch();
        if (!$origUser) response(404, false, null, "User not found");

        $query = "UPDATE users SET name = ?, email = ?, role = ?, status = ?";
        $params = [
            $data->name ?? $origUser['name'], 
            $data->email ?? $origUser['email'], 
            $data->role ?? $origUser['role'],
            $data->status ?? $origUser['status']
        ];
        
        if (!empty($data->password)) {
            $query .= ", password = ?";
            $params[] = password_hash($data->password, PASSWORD_BCRYPT);
        }
        $query .= " WHERE id = ?";
        $params[] = $targetId;

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        require_once __DIR__ . '/../helpers/log.php';
        
        // Log suspension or normal edits
        if (isset($data->status) && $data->status !== $origUser['status']) {
            if ($data->status === 'suspended') {
                logActivity($user->sub, "Suspended user account", "Suspended user: {$origUser['name']} ({$origUser['email']})");
            } else {
                logActivity($user->sub, "Activated user account", "Activated user: {$origUser['name']} ({$origUser['email']})");
            }
        } else {
            logActivity($user->sub, "Updated user account details", "Updated user ID: {$targetId} ({$origUser['email']})");
        }

        response(200, true, null, "User updated successfully");
    }

    if ($method == 'DELETE') {
        if ($targetId == $user->sub) response(400, false, null, "Cannot delete yourself");
        
        // Load details for logging
        $origStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $origStmt->execute([$targetId]);
        $origUser = $origStmt->fetch();

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$targetId]);

        require_once __DIR__ . '/../helpers/log.php';
        logActivity($user->sub, "Deleted user account", "Deleted user ID: {$targetId} (" . ($origUser['email'] ?? 'unknown') . ")");

        response(200, true, null, "User deleted successfully");
    }
}

// User Management (Admin Only) - Generic List & Create
if (preg_match('#/users/?$#', $path)) {
    $user = authenticate(['admin']);
    global $pdo;
    
    if ($method == 'GET') {
        $stmt = $pdo->query("SELECT id, name, email, role, status, created_at FROM users");
        response(200, true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    if ($method == 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        if (!$data || !isset($data->name, $data->email, $data->password, $data->role)) {
            response(400, false, null, "Incomplete data");
        }
        $hash = password_hash($data->password, PASSWORD_BCRYPT);
        $status = $data->status ?? 'active';
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
        try {
            $stmt->execute([$data->name, $data->email, $hash, $data->role, $status]);
            $newId = $pdo->lastInsertId();

            require_once __DIR__ . '/../helpers/log.php';
            logActivity($user->sub, "Created new user account", "Created user: {$data->name} ({$data->email}), Role: {$data->role}");

            response(201, true, ["id" => $newId], "User created successfully");
        } catch (Exception $e) {
            response(500, false, null, "Error: Email might already exist");
        }
    }
}

response(404, false, null, "Endpoint not found");
