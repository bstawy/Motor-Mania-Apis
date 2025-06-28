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

// 1. Validate Access Token
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

// 3. Get and Validate Input Data
$data = json_decode(file_get_contents("php://input"), true);
$productId = isset($data['id']) ? filter_var($data['id'], FILTER_VALIDATE_INT) : null;
$quantity = isset($data['quantity']) ? filter_var($data['quantity'], FILTER_VALIDATE_INT) : null;

if ($productId === null || $productId === false || $productId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing id."
    ]);
    exit();
}

if ($quantity === null || $quantity === false || $quantity <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing quantity. Quantity must be a positive integer."
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
    $db->beginTransaction();

    // Check if product exists
    $productCheckQuery = "SELECT id FROM products WHERE id = :product_id LIMIT 1";
    $productCheckStmt = $db->prepare($productCheckQuery);
    $productCheckStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $productCheckStmt->execute();

    if ($productCheckStmt->rowCount() == 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Product not found."
        ]);
        exit();
    }

    // Check if the product is already in the user's cart
    $checkCartQuery = "SELECT id, quantity FROM user_cart WHERE user_id = :user_id AND product_id = :product_id LIMIT 1";
    $checkCartStmt = $db->prepare($checkCartQuery);
    $checkCartStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkCartStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $checkCartStmt->execute();
    $cartItem = $checkCartStmt->fetch(PDO::FETCH_ASSOC);

    if ($cartItem) {
        // Product already in cart, update quantity
        $newQuantity = $cartItem['quantity'] + $quantity;
        $updateQuery = "UPDATE user_cart SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(":quantity", $newQuantity, PDO::PARAM_INT);
        $updateStmt->bindParam(":id", $cartItem['id'], PDO::PARAM_INT);
        $updateStmt->execute();
        $message = "Product quantity updated in cart.";
    } else {
        // Product not in cart, add new entry
        $insertQuery = "INSERT INTO user_cart (user_id, product_id, quantity) VALUES (:user_id, :product_id, :quantity)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
        $insertStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
        $insertStmt->bindParam(":quantity", $quantity, PDO::PARAM_INT);
        $insertStmt->execute();
        $message = "Product added to cart.";
    }

    $db->commit();
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => $message
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage()
    ]);
}
