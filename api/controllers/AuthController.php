<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class AuthController
{
    private $db;
    private $users_table = "users";
    private $stats_table = "user_stats";

    public function __construct($db)
    {
        $this->db = $db;
    }

    private function generateUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function validatePassword($password)
    {
        if (strlen($password) < 8) {
            return "Password must be at least 8 characters long";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return "Password must contain at least one uppercase letter";
        }
        if (!preg_match('/[a-z]/', $password)) {
            return "Password must contain at least one lowercase letter";
        }
        if (!preg_match('/[0-9]/', $password)) {
            return "Password must contain at least one number";
        }
        return null;
    }

    private function validateEmail($email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "Invalid email format";
        }
        return null;
    }

    public function register()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->name) || empty($data->email) || empty($data->password)) {
            http_response_code(400);
            echo json_encode(["message" => "Name, email, and password are required."]);
            return;
        }

        $emailError = $this->validateEmail($data->email);
        if ($emailError) {
            http_response_code(400);
            echo json_encode(["message" => $emailError]);
            return;
        }

        $passwordError = $this->validatePassword($data->password);
        if ($passwordError) {
            http_response_code(400);
            echo json_encode(["message" => $passwordError]);
            return;
        }

        // Check if email exists
        $check_query = "SELECT id FROM " . $this->users_table . " WHERE email = :email LIMIT 1";
        $stmt = $this->db->prepare($check_query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(["message" => "Email already exists."]);
            return;
        }

        $userId = $this->generateUUID();

        $this->db->beginTransaction();
        try {
            // Create User
            $query = "INSERT INTO " . $this->users_table . "
                    (id, name, email, password_hash, persona)
                    VALUES (:id, :name, :email, :password, :persona)";

            $stmt = $this->db->prepare($query);

            $name = htmlspecialchars(strip_tags($data->name));
            $email = htmlspecialchars(strip_tags($data->email));
            $password_hash = password_hash($data->password, PASSWORD_BCRYPT);
            $persona = isset($data->persona) ? $data->persona : 'newbie';

            $stmt->bindParam(':id', $userId);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $password_hash);
            $stmt->bindParam(':persona', $persona);

            if (!$stmt->execute()) {
                throw new Exception("Unable to create user");
            }

            // Initialize Stats
            $statsQuery = "INSERT INTO " . $this->stats_table . " (user_id, focus_minutes, mood_history, macros) VALUES (:uid, 0, '[]', :macros)";
            $statsStmt = $this->db->prepare($statsQuery);
            $defaultMacros = json_encode(['protein' => 0, 'carbs' => 0, 'fats' => 0, 'calories' => 0]);
            $statsStmt->bindParam(':uid', $userId);
            $statsStmt->bindParam(':macros', $defaultMacros);
            $statsStmt->execute();

            $this->db->commit();

            http_response_code(201);
            echo json_encode(["message" => "User registered successfully."]);
        } catch (Exception $e) {
            $this->db->rollBack();
            http_response_code(503);
            echo json_encode(["message" => "Unable to register user."]);
        }
    }

    public function login()
    {
        // Rate Limit: 5 requests per minute
        global $db; // or pass in constructor more cleanly if possible, but middleware is usually global. 
        // Actually, we can instantiate middleware here or use the global one if it tracked route specific.
        // For simplicity:
        require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';
        (new RateLimitMiddleware($this->db))->handle(5, 60);

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->email) && !empty($data->password)) {
            $query = "SELECT id, name, password_hash, persona, role FROM " . $this->users_table . " WHERE email = :email LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':email', $data->email);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (password_verify($data->password, $row['password_hash'])) {

                    // Generate Token
                    $token = array(
                        "iss" => "zenith_wellness",
                        "aud" => "zenith_users",
                        "iat" => time(),
                        "exp" => time() + (60 * 60 * 24), // 24 hours
                        "data" => array(
                            "id" => $row['id'],
                            "name" => $row['name'],
                            "email" => $data->email,
                            "persona" => $row['persona'],
                            "role" => $row['role'] ?? 'user'
                        )
                    );

                    $jwt = $this->generateJWT($token);

                    http_response_code(200);
                    echo json_encode([
                        "message" => "Login successful.",
                        "token" => $jwt,
                        "user" => [
                            "id" => $row['id'],
                            "name" => $row['name'],
                            "email" => $data->email,
                            "persona" => $row['persona'],
                            "role" => $row['role'] ?? 'user'
                        ]
                    ]);

                    // Log Activity
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                    $logStmt = $this->db->prepare("INSERT INTO user_activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'login', 'User logged in', ?, ?)");
                    $logStmt->execute([$row['id'], $ip, $ua]);
                } else {
                    error_log("Failed Login (Pwd): " . $data->email . " IP: " . $_SERVER['REMOTE_ADDR']);
                    http_response_code(401);
                    echo json_encode(["message" => "Invalid credentials."]);
                }
            } else {
                error_log("Failed Login (User): " . $data->email . " IP: " . $_SERVER['REMOTE_ADDR']);
                http_response_code(401);
                echo json_encode(["message" => "Invalid credentials."]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["message" => "Incomplete data."]);
        }
    }

    private function generateJWT($payload)
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public function updatePersona()
    {
        try {
            $userData = AuthMiddleware::authenticate();
            $userId = $userData['id'];
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized."]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->persona)) {
            $query = "UPDATE " . $this->users_table . " SET persona = :persona WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':persona', $data->persona);
            $stmt->bindParam(':id', $userId);

            if ($stmt->execute()) {
                http_response_code(200);
                echo json_encode(["message" => "Persona updated."]);
            } else {
                http_response_code(503);
                echo json_encode(["message" => "Unable to update persona."]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["message" => "Missing data."]);
        }
    }
}
?>