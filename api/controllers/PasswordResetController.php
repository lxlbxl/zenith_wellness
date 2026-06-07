<?php
class PasswordResetController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function handleRequest($action)
    {
        switch ($action) {
            case 'request':
                $this->requestReset();
                break;
            case 'verify':
                $this->verifyToken();
                break;
            case 'reset':
                $this->resetPassword();
                break;
            default:
                http_response_code(404);
                echo json_encode(["message" => "Action not found"]);
        }
    }

    /**
     * Request password reset - generates token and would send email
     * POST /api/password/request
     * Body: { "email": "user@example.com" }
     */
    private function requestReset()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->email)) {
            http_response_code(400);
            echo json_encode(["message" => "Email is required"]);
            return;
        }

        // Find user by email
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE email = :email");
        $stmt->execute([':email' => $data->email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always return success to prevent email enumeration attacks
        if (!$user) {
            echo json_encode(["message" => "If an account exists with that email, a reset link has been sent."]);
            return;
        }

        // Invalidate any existing tokens for this user
        $invalidate = $this->db->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND used_at IS NULL");
        $invalidate->execute([':uid' => $user['id']]);

        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Save token
        $stmt = $this->db->prepare("INSERT INTO password_resets (id, user_id, token, expires_at) VALUES (:id, :uid, :token, :expires)");
        $stmt->execute([
            ':id' => uniqid('pr_'),
            ':uid' => $user['id'],
            ':token' => hash('sha256', $token), // Store hashed token
            ':expires' => $expiresAt
        ]);

        // Send email
        require_once __DIR__ . '/../services/EmailService.php';
        $emailService = new EmailService($this->db);

        $resetLink = (getenv('APP_URL') ?: 'http://localhost:3000') . "/reset-password?token=$token";

        $emailService->sendTemplate($data->email, 'reset_password', [
            'link' => $resetLink,
            'year' => date('Y')
        ]);

        $response = [
            "message" => "If an account exists with that email, a reset link has been sent."
        ];

        // In development, still return token for easy testing without SMTP
        if (!getenv('PRODUCTION')) {
            $response["dev_token"] = $token;
            $response["dev_note"] = "Email sent (check logs). Token provided for dev testing.";
        }

        echo json_encode($response);
    }

    /**
     * Verify reset token is valid
     * POST /api/password/verify
     * Body: { "token": "abc123..." }
     */
    private function verifyToken()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->token)) {
            http_response_code(400);
            echo json_encode(["message" => "Token is required"]);
            return;
        }

        $tokenHash = hash('sha256', $data->token);

        $stmt = $this->db->prepare("
            SELECT pr.id, pr.user_id, u.email 
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = :token 
            AND pr.used_at IS NULL 
            AND pr.expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute([':token' => $tokenHash]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            http_response_code(400);
            echo json_encode(["valid" => false, "message" => "Invalid or expired token"]);
            return;
        }

        echo json_encode([
            "valid" => true,
            "email" => $this->maskEmail($reset['email'])
        ]);
    }

    /**
     * Reset password with valid token
     * POST /api/password/reset
     * Body: { "token": "abc123...", "password": "newpassword", "password_confirm": "newpassword" }
     */
    private function resetPassword()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->token) || empty($data->password)) {
            http_response_code(400);
            echo json_encode(["message" => "Token and password are required"]);
            return;
        }

        if ($data->password !== ($data->password_confirm ?? $data->password)) {
            http_response_code(400);
            echo json_encode(["message" => "Passwords do not match"]);
            return;
        }

        if (strlen($data->password) < 6) {
            http_response_code(400);
            echo json_encode(["message" => "Password must be at least 6 characters"]);
            return;
        }

        $tokenHash = hash('sha256', $data->token);

        // Find valid token
        $stmt = $this->db->prepare("
            SELECT id, user_id 
            FROM password_resets 
            WHERE token = :token 
            AND used_at IS NULL 
            AND expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute([':token' => $tokenHash]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid or expired token"]);
            return;
        }

        // Update password
        $passwordHash = password_hash($data->password, PASSWORD_BCRYPT);
        $updateStmt = $this->db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateStmt->execute([':hash' => $passwordHash, ':id' => $reset['user_id']]);

        // Mark token as used
        $usedStmt = $this->db->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = :id");
        $usedStmt->execute([':id' => $reset['id']]);

        echo json_encode([
            "message" => "Password has been reset successfully. You can now log in."
        ]);
    }

    /**
     * Mask email for privacy (j***@example.com)
     */
    private function maskEmail($email)
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];

        if (strlen($name) > 2) {
            $masked = substr($name, 0, 1) . str_repeat('*', strlen($name) - 2) . substr($name, -1);
        } else {
            $masked = $name[0] . '*';
        }

        return $masked . '@' . $domain;
    }
}
?>