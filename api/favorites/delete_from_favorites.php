<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

// Set response headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Adjust for production
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 1. Validate Access Token
$userData = validateAccessToken();
if ($userData === null) {
    // Error response already sent by middleware
    exit();
}
$userId = $userData['id'];

// 2. Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Please use DELETE."
    ]);
    exit();
}

// 3. Get and validate product_id from query parameter named 'id'
$productId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($productId === null || $productId === false || $productId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing id query parameter. Please provide a positive integer."
    ]);
    exit();
}

// 4. Database Connection
$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);
    exit();
}

try {
    // 5. Check if the favorite entry exists for this user and product
    $checkQuery = "SELECT id FROM user_favorites WHERE user_id = :user_id AND product_id = :product_id LIMIT 1";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() == 0) {
        http_response_code(404); // Not Found
        echo json_encode([
            "success" => false,
            "message" => "Product not found in your favorites."
        ]);
        exit();
    }

    // 6. Delete the favorite entry
    $deleteQuery = "DELETE FROM user_favorites WHERE user_id = :user_id AND product_id = :product_id";
    $deleteStmt = $db->prepare($deleteQuery);
    $deleteStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $deleteStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    
    if ($deleteStmt->execute()) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Product removed from favorites successfully.",
            "data" => [
                "product_id" => (string)$productId
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to remove product from favorites."
        ]);
    }

} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while removing from favorites: " . $e->getMessage()
    ]);
}
?>
