<?php
class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function getConnection()
    {
        $this->conn = null;

        // Check for DATABASE_URL (common in Postres/Render/Heroku deployments)
        $databaseUrl = getenv('DATABASE_URL');
        $dbConnection = getenv('DB_CONNECTION') ?: 'mysql'; // Default to mysql if not specified, or sqlite if local

        if ($databaseUrl) {
            // PostgreSQL Production Connection
            $dbopts = parse_url($databaseUrl);

            $this->host = $dbopts["host"];
            $this->db_name = ltrim($dbopts["path"], '/');
            $this->username = $dbopts["user"];
            $this->password = $dbopts["pass"];
            $port = $dbopts["port"] ?? 5432;

            try {
                $dsn = "pgsql:host=" . $this->host . ";port=" . $port . ";dbname=" . $this->db_name;
                $this->conn = new PDO($dsn, $this->username, $this->password);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $exception) {
                echo "Connection error: " . $exception->getMessage();
            }
        } elseif (getenv('DB_HOST') && $dbConnection === 'mysql') {
            // MySQL Connection
            $this->host = getenv('DB_HOST');
            $this->db_name = getenv('DB_NAME');
            $this->username = getenv('DB_USER');
            $this->password = getenv('DB_PASS');
            $port = getenv('DB_PORT') ?: 3306;

            try {
                $dsn = "mysql:host=" . $this->host . ";port=" . $port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
                $this->conn = new PDO($dsn, $this->username, $this->password);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $exception) {
                echo "Connection error: " . $exception->getMessage();
            }
        } else {
            // SQLite Local Fallback
            // Ensure the directory exists
            $dbPath = __DIR__ . '/../../database.sqlite';

            try {
                $this->conn = new PDO("sqlite:" . $dbPath);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $exception) {
                echo "Connection error: " . $exception->getMessage();
            }
        }

        return $this->conn;
    }
}
?>