<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authenticate(array $allowed_roles = []) {
    $headers = getallheaders();
    $token   = $headers['Authorization'] ?? '';
    // Also try checking $_SERVER['HTTP_AUTHORIZATION'] if getallheaders() fails or differs
    if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    $token = str_replace('Bearer ', '', $token);
    
    $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');

    if (empty($token)) {
        response(401, false, null, 'Unauthorised: Token missing');
        exit;
    }

    try {
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        if (!empty($allowed_roles) && !in_array($decoded->role, $allowed_roles)) {
            response(403, false, null, 'Forbidden');
            exit;
        }
        return $decoded;
    } catch (Exception $e) {
        response(401, false, null, 'Unauthorised: ' . $e->getMessage());
        exit;
    }
}
