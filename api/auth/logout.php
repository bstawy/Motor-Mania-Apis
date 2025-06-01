<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

// First validate the access token
$userData = validateAccessToken();

if ($userData === null) {
    // Token validation failed, response already sent by validateAccessToken()
    exit();
}

// Get the authorization header to extract the token
$authHeader = null;
if (isset($_SERVER['Authorization'])) {
    $authHeader = $_SERVER['Authorization'];
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $requestHeaders = apache_request_headers();
    if (isset($requestHeaders['Authorization'])) {
        $authHeader = $requestHeaders['Authorization'];
    }
}

if (!$authHeader) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Authorization header is missing."]);
    exit();
}

// Extract token from Bearer header
list($jwt) = sscanf($authHeader, 'Bearer %s');

if (!$jwt) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid token format."]);
    exit();
}

// Database connection
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}

// Get user ID from the validated token data
$userId = $userData['id'];

try {
    // Delete the refresh token from the database
    // This effectively logs the user out by invalidating their refresh token
    $query = "DELETE FROM refresh_tokens WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Logout successful. User session terminated."
    ]);
    
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "An error occurred during logout."
    ]);
}
?>
