<?php
use Firebase\JWT\JWT;

class AuthController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function login($input) {
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            response(400, false, null, 'Email and password are required');
        }

        $stmt = $this->pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
            $payload = [
                'sub' => $user['id'],
                'role' => $user['role'],
                'name' => $user['name'],
                'iat' => time(),
                'exp' => time() + (86400 * 30), // 30 days
            ];

            $token = JWT::encode($payload, $secret, 'HS256');

            response(200, true, [
                'token' => $token,
                'role'  => $user['role'],
            ], 'Login successful');
        } else {
            response(401, false, null, 'Invalid email or password');
        }
    }

    public function logout() {
        // Stateless JWT logout implies the client should drop the token. 
        // We just return a success message here.
        authenticate(); // ensure user is logged in even though we just return success
        response(200, true, null, 'Logged out successfully');
    }

    public function forgotPassword($input) {
        $email = $input['email'] ?? '';
        if (!$email) response(400, false, null, 'Email address is required');

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            response(404, false, null, 'Email address not found in the system.');
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $updStmt = $this->pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $updStmt->execute([$token, $expires, $email]);

        require_once __DIR__ . '/../helpers/email.php';
        sendPasswordResetEmail($email, $token);

        response(200, true, null, 'A recovery link has been dispatched to your email address.');
    }

    public function resetPassword($input) {
        $token = $input['token'] ?? '';
        $password = $input['password'] ?? '';

        if (!$token || !$password) response(400, false, null, 'Token and new password are required');

        $stmt = $this->pdo->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            response(400, false, null, 'Invalid or expired recovery token.');
        }

        if (strtotime($user['reset_expires']) < time()) {
            response(400, false, null, 'This recovery token has expired.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $updStmt = $this->pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $updStmt->execute([$hash, $user['id']]);

        response(200, true, null, 'Password successfully reset.');
    }
}
