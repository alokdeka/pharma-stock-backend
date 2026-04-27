<?php
use Firebase\JWT\JWT;
use OpenApi\Attributes as OA;

class AuthController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Post(
        path: "/auth/login",
        summary: "Authenticate User",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email", example: "admin@pharma.com"),
                    new OA\Property(property: "password", type: "string", format: "password", example: "admin123")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Successful Authentication returning JWT payload"),
            new OA\Response(response: 400, description: "Missing email or password"),
            new OA\Response(response: 401, description: "Invalid Credentials")
        ]
    )]
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

    #[OA\Post(
        path: "/auth/logout",
        summary: "Destroy Session",
        tags: ["Authentication"],
        responses: [
            new OA\Response(response: 200, description: "Successfully purged token")
        ]
    )]
    public function logout() {
        // Stateless JWT logout implies the client should drop the token. 
        // We just return a success message here.
        authenticate(); // ensure user is logged in even though we just return success
        response(200, true, null, 'Logged out successfully');
    }

    #[OA\Post(
        path: "/auth/forgot-password",
        summary: "Dispatch Password Reset Link",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email", example: "manager@pharma.com")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Email successfully configured for recovery format"),
            new OA\Response(response: 404, description: "Email does not natively exist inside the system")
        ]
    )]
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

    #[OA\Post(
        path: "/auth/reset-password",
        summary: "Finalize Password Overwrite",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["token", "password"],
                properties: [
                    new OA\Property(property: "token", type: "string", example: "e10fb7..."),
                    new OA\Property(property: "password", type: "string", format: "password", example: "manager123")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Password safely bound & token entirely evaporated"),
            new OA\Response(response: 400, description: "Temporal Hash invalidly timed out or corrupted")
        ]
    )]
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
