<?php
// Simple router mapping

$method = $_SERVER['REQUEST_METHOD'];
// Parse the URI e.g., /api/medicines/1 -> ['api', 'medicines', '1']
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Assuming this could be served from a subfolder or root. We will strip the base if necessary.
// For a built-in PHP server at root, it's just /api/...
// But let's handle the string matching manually based on the guide's endpoints.

// Ensure basic variables are parsed
$path = preg_replace('#^(.*?/api\.php|.*?/api)/#', '', $uri); // Clean prefix
$parts = explode('/', trim($path, '/'));

$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;
$action = $parts[2] ?? null;

// Read JSON input if sent
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($resource) {
    case 'auth':
        require_once __DIR__ . '/../controllers/AuthController.php';
        $controller = new AuthController($pdo);
        if ($method === 'POST' && $id === 'login') {
            $controller->login($input);
        } elseif ($method === 'POST' && $id === 'logout') {
            $controller->logout();
        } elseif ($method === 'POST' && $id === 'forgot-password') {
            $controller->forgotPassword($input);
        } elseif ($method === 'POST' && $id === 'reset-password') {
            $controller->resetPassword($input);
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'medicines':
        require_once __DIR__ . '/../controllers/MedicineController.php';
        $controller = new MedicineController($pdo);
        if ($method === 'GET' && !$id) {
            $controller->index();
        } elseif ($method === 'GET' && $id) {
            $controller->show($id);
        } elseif ($method === 'POST' && !$id) {
            $controller->store($input);
        } elseif ($method === 'PUT' && $id) {
            $controller->update($id, $input);
        } elseif ($method === 'DELETE' && $id) {
            $controller->destroy($id);
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'batches':
        require_once __DIR__ . '/../controllers/BatchController.php';
        $controller = new BatchController($pdo);
        if ($method === 'GET' && $id === 'search') {
            $controller->search($_GET['batch_number'] ?? '');
        } elseif ($method === 'GET' && !$id) {
            $controller->index();
        } elseif ($method === 'GET' && $id && !$action) {
            $controller->show($id);
        } elseif ($method === 'POST' && !$id) {
            $controller->store($input);
        } elseif ($method === 'POST' && $id && $action === 'sell') {
            $controller->sell($id, $input);
        } elseif ($method === 'POST' && $id && $action === 'spoil') {
            $controller->spoil($id, $input);
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'expiry':
        require_once __DIR__ . '/../controllers/ExpiryController.php';
        $controller = new ExpiryController($pdo);
        if ($method === 'GET' && $id === 'dashboard') {
            $controller->dashboard();
        } elseif ($method === 'GET' && $id === 'critical') {
            $controller->critical();
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'stock':
        require_once __DIR__ . '/../controllers/OrderController.php';
        $controller = new OrderController($pdo);
        if ($method === 'GET' && $id === 'low') {
            $controller->lowStock();
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'orders':
        require_once __DIR__ . '/../controllers/OrderController.php';
        $controller = new OrderController($pdo);
        if ($method === 'GET' && !$id) {
            $controller->index();
        } elseif ($method === 'POST' && !$id) {
            $controller->store($input);
        } elseif ($method === 'PUT' && $id && $action === 'status') {
            $controller->updateStatus($id, $input);
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'reports':
        require_once __DIR__ . '/../controllers/ReportController.php';
        $controller = new ReportController($pdo);
        if ($method === 'GET' && $id === 'inventory') {
            $controller->inventory();
        } elseif ($method === 'GET' && $id === 'expiry-summary') {
            $controller->expirySummary();
        } elseif ($method === 'GET' && $id === 'batch-sales') {
            $controller->batchSales($_GET['batch_number'] ?? '');
        } elseif ($method === 'GET' && $id === 'sales-trend') {
            $controller->salesTrend();
        } elseif ($method === 'GET' && $id === 'transactions') {
            $controller->transactions($_GET['from'] ?? '', $_GET['to'] ?? '');
        } elseif ($method === 'GET' && $id === 'financials') {
            $controller->financials();
        } elseif ($method === 'GET' && $id === 'calendar-events') {
            $controller->calendarEvents();
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'users':
        require_once __DIR__ . '/../controllers/UserController.php';
        break;

    case 'settings':
        require_once __DIR__ . '/../controllers/SettingController.php';
        break;

    case 'suppliers':
        require_once __DIR__ . '/../controllers/SupplierController.php';
        break;

    case 'database':
        require_once __DIR__ . '/../controllers/DatabaseController.php';
        $controller = new DatabaseController($pdo);
        if ($method === 'GET' && $id === 'backup') {
            $controller->backup();
        } elseif ($method === 'POST' && $id === 'restore') {
            $controller->restore();
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'notifications':
        require_once __DIR__ . '/../controllers/NotificationController.php';
        $controller = new NotificationController($pdo);
        if ($method === 'GET' && $id === 'smart') {
            $controller->smart();
        } elseif ($method === 'POST' && $id && $action === 'read') {
            $controller->markRead($id);
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    case 'docs':
        if ($method === 'GET' && $id === 'json') {
            try {
                ob_start();
                require_once __DIR__ . '/../vendor/autoload.php';
                
                // Safely load only OOP controllers to avoid procedural scripts returning 404
                $controllersToLoad = [
                    'OpenApiSpec.php', 'AuthController.php', 'MedicineController.php',
                    'BatchController.php', 'ExpiryController.php', 'OrderController.php',
                    'ReportController.php', 'DatabaseController.php', 'NotificationController.php'
                ];
                foreach ($controllersToLoad as $cF) {
                    if (file_exists(__DIR__ . '/../controllers/' . $cF)) {
                        require_once __DIR__ . '/../controllers/' . $cF;
                    }
                }

                $generator = new \OpenApi\Generator();
                $openapi = $generator->generate([__DIR__ . '/../controllers']);
                $debugLogs = ob_get_clean(); // Capture stray PHP notices
                
                header('Content-Type: application/json');
                $json = $openapi ? $openapi->toJson() : '{"error": "Generator completely failed"}';
                echo $json;
                exit;
            } catch (\Throwable $e) {
                ob_get_clean();
                header('Content-Type: application/json');
                echo json_encode([
                    "error" => "OpenAPI Compilation Exception",
                    "message" => $e->getMessage(),
                    "file" => $e->getFile(),
                    "line" => $e->getLine()
                ]);
                exit;
            }
        } elseif ($method === 'GET' && $id === 'ui') {
            header('Content-Type: text/html');
            echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>PharmaStock Swagger Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
<script>
  window.onload = () => {
    window.ui = SwaggerUIBundle({
      url: window.location.pathname.replace('/ui', '/json'),
      dom_id: '#swagger-ui',
    });
  };
</script>
</body>
</html>
HTML;
        } else {
            response(404, false, null, 'Endpoint not found');
        }
        break;

    default:
        response(404, false, null, 'API Endpoint not found');
        break;
}
