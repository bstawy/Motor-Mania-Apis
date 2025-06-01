<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Autoload Composer dependencies

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Basic validation
if (
    !isset($data->name) || empty(trim($data->name)) ||
    !isset($data->email) || !filter_var($data->email, FILTER_VALIDATE_EMAIL) ||
    !isset($data->password) || empty(trim($data->password))
) {
    http_response_code(400); // Bad Request
    echo json_encode(["success" => false, "message" => "Invalid input. Please provide name, valid email, and password."]);
    exit();
}

$name = trim($data->name);
$email = trim($data->email);
$password = trim($data->password);

// Database connection
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}

// Check if email already exists
$query = "SELECT id FROM users WHERE email = :email LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(":email", $email);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    http_response_code(409); // Conflict
    echo json_encode(["success" => false, "message" => "Email already exists."]);
    exit();
}

// Hash the password
$password_hash = password_hash($password, PASSWORD_BCRYPT);

// Insert user into database
$query = "INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)";
$stmt = $db->prepare($query);

// Sanitize
$name = htmlspecialchars(strip_tags($name));
$email = htmlspecialchars(strip_tags($email));

// Bind parameters
$stmt->bindParam(":name", $name);
$stmt->bindParam(":email", $email);
$stmt->bindParam(":password_hash", $password_hash);

try {
    if ($stmt->execute()) {
        http_response_code(201); // Created
        echo json_encode(["success" => true, "message" => "User registered successfully."]);
    } else {
        // Log detailed error: $stmt->errorInfo()
        http_response_code(500); // Internal Server Error
        echo json_encode(["success" => false, "message" => "Failed to register user. Please try again."]);
    }
} catch (PDOException $e) {
    // Log detailed error: $e->getMessage()
    http_response_code(500); // Internal Server Error
    echo json_encode(["success" => false, "message" => "An internal error occurred during registration."]);
}

?>
