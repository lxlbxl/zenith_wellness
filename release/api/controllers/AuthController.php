<?php
class AuthController
{
    private $db;
    private $users_table = "users";
    private $stats_table = "user_stats";

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function register()
    {
        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->name) && !empty($data->email) && !empty($data->password)) {

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

            // UUID for ID (simple random string for now or proper UUID if possible, keeping it simple as uniqid)
            $userId = uniqid();

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

            if ($stmt->execute()) {
                // Initialize Stats
                $statsQuery = "INSERT INTO " . $this->stats_table . " (user_id, focus_minutes, mood_history, macros) VALUES (:uid, 0, '[]', :macros)";
                $statsStmt = $this->db->prepare($statsQuery);
                $defaultMacros = json_encode(['protein' => 0, 'carbs' => 0, 'fats' => 0, 'calories' => 0]);
                $statsStmt->bindParam(':uid', $userId);
                $statsStmt->bindParam(':macros', $defaultMacros);
                $statsStmt->execute();

                http_response_code(201);
                echo json_encode(["message" => "User registered successfully."]);
            } else {
                http_response_code(503);
                echo json_encode(["message" => "Unable to register user."]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["message" => "Incomplete data."]);
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
        // Get user ID from AuthMiddleware (passed globally or via headers? The middleware returns user data but index.php flow needs to pass it)
        // Actually, AuthMiddleware in index.php verifies token but doesn't inject it into Controller unless we modify index.php to pass $userData
        // Let's check headers for now or assume index.php handles Auth.
        // Waiting for index.php refactor check. 
        // For now, let's assume we decode token here or trust caller.
        // Ideally, index.php should pass the authenticated user ID.

        // Quick fix: Re-verify or get from input if we trust (bad).
        // Best: index.php stores auth user in global $currentUser or passes to method.
        // Let's update index.php to pass user to controller methods if auth is required.

        // Reading input
        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->persona)) {
            // In a real app we need the User ID.
            // For this prototype phase, let's grab the token from header again or fix index.php
            // Let's assume we can get the user ID from the token in the header.
            $headers = getallheaders();
            $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
            // ... extract token ... 
            // BETTER: Let's assume index.php will provide context.
            // But for now, I'll rely on the client sending user_id? No, security risk.

            // I will implement a helper or just re-parse token here for Phase 1 speed.
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token_parts = explode('.', $matches[1]);
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1])), true);
                $userId = $payload['data']['id'];

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
                http_response_code(401);
                echo json_encode(["message" => "Unauthorized."]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["message" => "Missing data."]);
        }
    }
}
?>