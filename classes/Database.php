<?php
/**
 * Database Class
 * Handles database connections and queries
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $host;
    private $port;
    private $dbname;
    private $username;
    private $password;
    private $charset;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->port = getenv('DB_PORT') ?: '5432';
        $this->dbname = getenv('DB_DATABASE') ?: 'Inventory_DB';
        $this->username = getenv('DB_USERNAME') ?: 'postgres';

        $password = getenv('DB_PASSWORD');
        if ($password === false || $password === '') {
            throw new RuntimeException(
                'DB_PASSWORD environment variable is not set. Refusing to fall back to a hardcoded default.'
            );
        }
        $this->password = $password;

        $this->charset = 'utf8';

        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Connect to database
     */
    private function connect() {
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};options='--client_encoding={$this->charset}'";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false
        ];
        
        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            $this->pdo->exec("SET search_path TO public");
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Run a query
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Fetch one row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Fetch column
     */
    public function fetchColumn($sql, $params = [], $column = 0) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn($column);
    }
    
    /**
     * Get row count
     */
    public function rowCount($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Insert record
     */
    public function insert($table, $data) {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
        
        $this->query($sql, array_values($data));
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Update record
     */
    public function update($table, $data, $where, $whereParams = []) {
        $sets = [];
        foreach (array_keys($data) as $key) {
            $sets[] = "{$key} = ?";
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$where}";
        
        return $this->query($sql, array_merge(array_values($data), $whereParams));
    }
    
    /**
     * Delete record
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params);
    }
    
    /**
     * Get last inserted ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Get database version
     */
    public function getVersion() {
        return $this->fetchColumn("SELECT version()");
    }
    
    /**
     * Get table list
     */
    public function getTables() {
        return $this->fetchAll("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ");
    }
    
    /**
     * Get table row count
     */
    public function getTableCount($table) {
        return $this->fetchColumn("SELECT COUNT(*) FROM {$table}");
    }
    
    /**
     * Check if table exists
     */
    public function tableExists($table) {
        $result = $this->fetchOne("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public'
                AND table_name = ?
            )
        ", [$table]);
        
        return $result['exists'] ?? false;
    }
    
    /**
     * Get table columns
     */
    public function getTableColumns($table) {
        return $this->fetchAll("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = 'public'
            AND table_name = ?
            ORDER BY ordinal_position
        ", [$table]);
    }
    
    /**
     * Execute raw SQL
     */
    public function exec($sql) {
        return $this->pdo->exec($sql);
    }
    
    /**
     * Get database size[[ "$TERM_PROGRAM" == "vscode" ]] && . "$(code --locate-shell-integration-path bash)"

     */
    public function getDatabaseSize() {
        $result = $this->fetchOne("
            SELECT pg_size_pretty(pg_database_size(current_database())) as size
        ");
        return $result['size'] ?? 'Unknown';
    }
    
    /**
     * Vacuum/Optimize database
     */
    public function vacuum() {
        return $this->exec("VACUUM ANALYZE");
    }
    
    /**
     * Clone method (prevent cloning)
     */
    private function __clone() {}
    
    /**
     * Wakeup method (prevent unserialization)
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database instance
 */
function db() {
    return Database::getInstance();
}
