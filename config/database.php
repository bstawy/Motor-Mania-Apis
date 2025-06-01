<?php
require_once __DIR__ . '/config.php';

class Database {
    private $conn;

    // Get the database connection
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // Log error or handle appropriately in production
            // For now, just echo the error message
            echo "Connection error: " . $exception->getMessage();
            // In a real API, you would return a JSON error response
            // http_response_code(500);
            // echo json_encode(["success" => false, "message" => "Database connection error."]);
            // exit();
        }

        return $this->conn;
    }
}
?>
