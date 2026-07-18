<?php
// Data Bundle Hub - Database Connection

class Database {
    private $host = DB_HOST;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $database = DB_NAME;
    private $port = DB_PORT;
    private $connection;
    
    public function __construct() {
        $this->connect();
    }
    
    private function connect() {
        try {
            if ($this->connection && $this->connection instanceof mysqli) {
                @$this->connection->close();
            }

            $conn = mysqli_init();
            if (!$conn) {
                throw new Exception("MySQLi init failed.");
            }

            // Tighten connection/read timeouts to avoid long hangs.
            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_READ_TIMEOUT, 8);
            }
            if (defined('MYSQLI_OPT_RECONNECT')) {
                $conn->options(MYSQLI_OPT_RECONNECT, true);
            }
            if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
                $conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
            }

            $conn->real_connect($this->host, $this->username, $this->password, $this->database, (int) $this->port);
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

    private function ensureConnection() {
        if (!$this->connection || !($this->connection instanceof mysqli)) {
            $this->connect();
            return;
        }

        try {
            $ok = $this->connection->ping();
        } catch (mysqli_sql_exception $e) {
            $ok = false;
        }

        if (!$ok) {
            $this->connect();
        }
    }
    
    public function getConnection() {
        $this->ensureConnection();
        return $this->connection;
    }
    
    public function query($sql) {
        $this->ensureConnection();
        try {
            return $this->connection->query($sql);
        } catch (mysqli_sql_exception $e) {
            $code = (int) $e->getCode();
            // Retry once on lost connection.
            if ($code === 2006 || $code === 2013) {
                $this->connect();
                return $this->connection->query($sql);
            }
            throw $e;
        }
    }
    
    public function prepare($sql) {
        $this->ensureConnection();
        try {
            return $this->connection->prepare($sql);
        } catch (mysqli_sql_exception $e) {
            $code = (int) $e->getCode();
            // Retry once on lost connection.
            if ($code === 2006 || $code === 2013) {
                $this->connect();
                return $this->connection->prepare($sql);
            }
            throw $e;
        }
    }
    
    public function escape($string) {
        $this->ensureConnection();
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
