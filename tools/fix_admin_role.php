<?php
require_once 'api/config/Database.php';

$database = new Database();
$db = $database->getConnection();

$email = 'test@zenith.com';

// Check user
$stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "User $email not found. Creating...\n";
    // Create Default Admin
    $id = uniqid();
    $pass = password_hash('password', PASSWORD_DEFAULT);
    $role = 'admin';
    $name = 'Test Admin';

    $stmt = $db->prepare("INSERT INTO users (id, name, email, password_hash, role) VALUES (:id, :name, :email, :pass, :role)");
    $stmt->execute([':id' => $id, ':name' => $name, ':email' => $email, ':pass' => $pass, ':role' => $role]);
    echo "Admin user created.\n";
} else {
    echo "User found: " . $user['name'] . " (" . $user['role'] . ")\n";
    if ($user['role'] !== 'admin') {
        echo "Promoting to admin...\n";
        $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE id = :id");
        $stmt->execute([':id' => $user['id']]);
        echo "Promoted to admin.\n";
    } else {
        echo "Already admin.\n";
    }
}
?>