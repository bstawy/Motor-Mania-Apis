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

// 3. Get and Validate Input Data from Query Parameter
$productId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($productId === null || $productId === false || $productId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing productId query parameter. Please provide a positive integer."
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
    // Check if the product is in the user's cart
    $checkCartQuery = "SELECT id FROM user_cart WHERE user_id = :user_id AND product_id = :product_id LIMIT 1";
    $checkCartStmt = $db->prepare($checkCartQuery);
    $checkCartStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkCartStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $checkCartStmt->execute();

    if ($checkCartStmt->rowCount() == 0) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Product not found in your cart."
        ]);
        exit();
    }

    // Remove the product from the cart
    $deleteQuery = "DELETE FROM user_cart WHERE user_id = :user_id AND product_id = :product_id";
    $deleteStmt = $db->prepare($deleteQuery);
    $deleteStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $deleteStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() == 1) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Product removed from cart successfully."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to remove product from cart."
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage()
    ]);
}
