<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

// Set response headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Adjust for production
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 1. Validate Access Token - User must be authenticated to add favorites
$userData = validateAccessToken();
if ($userData === null) {
    // Error response already sent by middleware
    exit();
}
$userId = $userData['id'];

// 2. Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Please use POST."
    ]);
    exit();
}

// 3. Get and Validate product_id from query parameter named 'id'
$productId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($productId === null || $productId === false || $productId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing product id query parameter. Please provide a positive integer."
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
    // 5. Check if product_id exists
    $checkProductQuery = "SELECT id FROM products WHERE id = :product_id LIMIT 1";
    $checkProductStmt = $db->prepare($checkProductQuery);
    $checkProductStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $checkProductStmt->execute();
    if ($checkProductStmt->rowCount() == 0) {
        http_response_code(404); // Not Found
        echo json_encode([
            "success" => false,
            "message" => "Specified product does not exist."
        ]);
        exit();
    }

    // 6. Check if user already has this product in favorites
    $checkFavoriteQuery = "SELECT id FROM user_favorites WHERE user_id = :user_id AND product_id = :product_id LIMIT 1";
    $checkFavoriteStmt = $db->prepare($checkFavoriteQuery);
    $checkFavoriteStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkFavoriteStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $checkFavoriteStmt->execute();
    if ($checkFavoriteStmt->rowCount() > 0) {
        http_response_code(409); // Conflict
        echo json_encode([
            "success" => false,
            "message" => "This product is already in your favorites."
        ]);
        exit();
    }

    // 7. Insert the product into user's favorites
    $insertQuery = "INSERT INTO user_favorites (user_id, product_id) VALUES (:user_id, :product_id)";
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $insertStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);

    if ($insertStmt->execute()) {
        $favoriteId = $db->lastInsertId();
        http_response_code(201); // Created
        echo json_encode([
            "success" => true,
            "message" => "Product added to favorites successfully.",
            "data" => [
                "favorite_id" => (string)$favoriteId,
                "product_id" => (string)$productId
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to add product to favorites."
        ]);
    }

} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    // Check for unique constraint violation (duplicate entry)
    if ($e->getCode() == 23000) { // SQLSTATE 23000: Integrity constraint violation
         http_response_code(409); // Conflict
         echo json_encode([
            "success" => false,
            "message" => "This product is already in your favorites (Constraint Violation)."
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "An error occurred: " . $e->getMessage() // Provide more details in dev mode
            // "message" => "An internal error occurred while adding to favorites."
        ]);
    }
}
?>
