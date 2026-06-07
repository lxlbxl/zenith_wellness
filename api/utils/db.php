<?php
class Database
{
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    public $conn;

    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
        } catch (PDOException $exception) {
            // Fallback to SQLite if MySQL fails (for local testing without MySQL setup)
            try {
                $this->conn = new PDO("sqlite:" . __DIR__ . "/../database/zenith.sqlite");
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                // Ensure tables exist for SQLite
                $this->initializeSQLite();
            } catch (PDOException $e) {
                echo "Connection error: " . $e->getMessage();
            }
        }
        return $this->conn;
    }

    private function initializeSQLite()
    {
        // Quick check if users table exists
        $result = $this->conn->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users'");
        if ($result->fetchColumn() == 0) {
            // Load schema if empty
            $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
            // SQLite compatibility adjustments if needed, or just keep schema simple
            // For now, assume schema.sql is compatible or simple enough
            $this->conn->exec($schema);
        }
    }
}

$database = new Database();
$db = $database->getConnection();
?>