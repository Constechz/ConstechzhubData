<?php
// Data Bundle Hub - Database Connection

class Database {
    private $host = DB_HOST;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $database = DB_NAME;
    private $port = null;
    private $connection;
    
    public function __construct() {
        if (strpos($this->host, ':') !== false) {
            list($host, $port) = explode(':', $this->host, 2);
            $this->host = $host;
            $this->port = (int)$port;
        } elseif (defined('DB_PORT') && DB_PORT !== null) {
            $this->port = (int)DB_PORT;
        }
        $this->connect();
    }
    
    private function connect() {
        try {
            $conn = mysqli_init();
            if (!$conn) {
                throw new Exception("MySQLi init failed.");
            }

            // Tighten connection/read timeouts to avoid long hangs.
            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_READ_TIMEOUT, 8);
            }
            if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
                $conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
            }

            $conn->real_connect($this->host, $this->username, $this->password, $this->database, $this->port);
            $this->connection = $conn;
            
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            // Set charset to utf8
            $this->connection->set_charset("utf8");
            
        } catch (Exception $e) {
            die("Database connection error: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql) {
        return $this->connection->query($sql);
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }
    
    public function lastInsertId() {
        return $this->connection->insert_id;
    }
    
    public function affectedRows() {
        return $this->connection->affected_rows;
    }
    
    public function close() {
        if ($this->connection && $this->connection instanceof mysqli) {
            $this->connection->close();
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}

// Create global database instance
try {
    if (!isset($db)) {
        $db = new Database();
    }
} catch (Exception $e) {
    die("Failed to initialize database: " . $e->getMessage());
}
?>
