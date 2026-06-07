<?php
class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    /**
     * Returns the connection driver being used: 'mysql', 'pgsql', or 'sqlite'.
     */
    public function getDriver(): string
    {
        $databaseUrl = getenv('DATABASE_URL');
        $dbConnection = getenv('DB_CONNECTION') ?: 'mysql';

        if ($databaseUrl) {
            return 'pgsql';
        } elseif (getenv('DB_HOST') && $dbConnection === 'mysql') {
            return 'mysql';
        } else {
            return 'sqlite';
        }
    }

    /**
     * Runs the migration runner if tables are missing.
     * @return bool true if migration succeeded or was not needed
     */
    public function autoMigrate(): bool
    {
        $migrateScript = __DIR__ . '/../migrations/migrate.php';

        if (!file_exists($migrateScript)) {
            error_log('[Database] Migration script not found at: ' . $migrateScript);
            return false;
        }

        // Check if any core tables are missing
        try {
            $db = $this->getConnection();
            if (!$db) {
                return false;
            }

            $driver = $this->getDriver();
            if ($driver === 'sqlite') {
                $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
                $hasUsers = (bool) $stmt->fetch();
            } elseif ($driver === 'pgsql') {
                $stmt = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users')");
                $hasUsers = (bool) $stmt->fetchColumn();
            } else {
                $stmt = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'");
                $hasUsers = $stmt->fetchColumn() > 0;
            }

            if (!$hasUsers) {
                // Core table missing, run full migration
                error_log('[Database] Core tables missing, running auto-migration...');
                $output = [];
                $exitCode = 0;
                exec('php ' . escapeshellarg($migrateScript) . ' 2>&1', $output, $exitCode);
                error_log('[Database] Migration exit code: ' . $exitCode);
                error_log('[Database] Migration output: ' . implode("\n", $output));
                return $exitCode === 0;
            }

            return true;
        } catch (\Exception $e) {
            error_log('[Database] Migration check failed: ' . $e->getMessage());
            return false;
        }
    }

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