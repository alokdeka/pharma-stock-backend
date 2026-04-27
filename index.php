<?php
// Entry point
require_once __DIR__ . '/vendor/autoload.php';

// Load .env variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load Helpers
require_once __DIR__ . '/helpers/response.php';

// Load Configuration (Connects DB, returns $pdo variable)
require_once __DIR__ . '/config/db.php';

// Load Middleware
require_once __DIR__ . '/middleware/auth.php';

// Load Routes
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// If it starts with /api (or /pharma-stock-api/api) send it to api routes
if (strpos($uri, '/api') !== false) {
    require_once __DIR__ . '/routes/api.php';
} else {
    response(200, true, null, 'Welcome to Pharma-Stock API. Use /api/ endpoints.');
}
