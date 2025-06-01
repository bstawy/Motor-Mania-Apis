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
    exit();
}
$userId = $userData['id'];

// 2. Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Please use POST."
    ]);
    exit();
}

// 3. Get and Validate Input Data from Query Parameter
$userCarId = isset($_GET['carId']) ? filter_var($_GET['carId'], FILTER_VALIDATE_INT) : null;

if ($userCarId === null || $userCarId === false || $userCarId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing carId query parameter. Please provide a positive integer."
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

// 5. Perform Update within a Transaction
try {
    $db->beginTransaction();

    // Verify the user owns the car
    $checkOwnerQuery = "SELECT id FROM user_cars WHERE id = :user_car_id AND user_id = :user_id LIMIT 1";
    $checkOwnerStmt = $db->prepare($checkOwnerQuery);
    $checkOwnerStmt->bindParam(":user_car_id", $userCarId, PDO::PARAM_INT);
    $checkOwnerStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkOwnerStmt->execute();

    if ($checkOwnerStmt->rowCount() == 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Car not found in your garage or access denied."
        ]);
        exit();
    }

    // Set all cars for this user to is_default = false
    $updateAllQuery = "UPDATE user_cars SET is_default = 0 WHERE user_id = :user_id";
    $updateAllStmt = $db->prepare($updateAllQuery);
    $updateAllStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $updateAllStmt->execute();

    // Set the specified car to is_default = true
    $updateTargetQuery = "UPDATE user_cars SET is_default = 1 WHERE id = :user_car_id AND user_id = :user_id";
    $updateTargetStmt = $db->prepare($updateTargetQuery);
    $updateTargetStmt->bindParam(":user_car_id", $userCarId, PDO::PARAM_INT);
    $updateTargetStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $updateTargetStmt->execute();

    if ($updateTargetStmt->rowCount() == 1) {
        $db->commit();
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Default car updated successfully."
        ]);
    } else {
        $db->rollBack();
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to update the specified car as default."
        ]);
    }
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
