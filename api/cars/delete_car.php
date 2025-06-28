<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

// Set response headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Adjust for production
header("Access-Control-Allow-Methods: DELETE, OPTIONS"); // Allow DELETE method
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
$userCarIdToDelete = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($userCarIdToDelete === null || $userCarIdToDelete === false || $userCarIdToDelete <= 0) {
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

// 5. Perform Deletion within a Transaction
try {
    $db->beginTransaction();

    // Check total number of cars for the user
    $countQuery = "SELECT COUNT(id) AS total_cars FROM user_cars WHERE user_id = :user_id";
    $countStmt = $db->prepare($countQuery);
    $countStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $countStmt->execute();
    $totalCars = $countStmt->fetch(PDO::FETCH_ASSOC)['total_cars'];

    // First, verify the user owns the car they are trying to delete and check if it's the default
    $checkOwnerQuery = "SELECT id, is_default FROM user_cars WHERE id = :user_car_id AND user_id = :user_id LIMIT 1";
    $checkOwnerStmt = $db->prepare($checkOwnerQuery);
    $checkOwnerStmt->bindParam(":user_car_id", $userCarIdToDelete, PDO::PARAM_INT);
    $checkOwnerStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkOwnerStmt->execute();
    $carToDelete = $checkOwnerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$carToDelete) {
        // Car not found or doesn't belong to the user
        $db->rollBack();
        http_response_code(404); // Not Found (or 403 Forbidden)
        echo json_encode([
            "success" => false,
            "message" => "Car not found in your garage or access denied."
        ]);
        exit();
    }

    // Prevent deletion if it's the only car
    if ($totalCars == 1) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "You cannot delete the only car in your garage."
        ]);
        exit();
    }

    $isDeletingDefault = (bool)$carToDelete['is_default'];

    // Delete the specified car
    $deleteQuery = "DELETE FROM user_cars WHERE id = :user_car_id AND user_id = :user_id";
    $deleteStmt = $db->prepare($deleteQuery);
    $deleteStmt->bindParam(":user_car_id", $userCarIdToDelete, PDO::PARAM_INT);
    $deleteStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $deleteSuccess = $deleteStmt->execute();

    if ($deleteSuccess && $deleteStmt->rowCount() == 1) {
        // If the deleted car was the default, assign a new default if other cars exist
        if ($isDeletingDefault) {
            // Find any other car to set as new default (e.g., the first one found)
            $checkRemainingQuery = "SELECT id FROM user_cars WHERE user_id = :user_id ORDER BY id ASC LIMIT 1";
            $checkRemainingStmt = $db->prepare($checkRemainingQuery);
            $checkRemainingStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
            $checkRemainingStmt->execute();
            $newDefaultCar = $checkRemainingStmt->fetch(PDO::FETCH_ASSOC);

            if ($newDefaultCar) {
                // Set the new default car
                $setNewDefaultQuery = "UPDATE user_cars SET is_default = 1 WHERE id = :new_default_id AND user_id = :user_id";
                $setNewDefaultStmt = $db->prepare($setNewDefaultQuery);
                $setNewDefaultStmt->bindParam(":new_default_id", $newDefaultCar['id'], PDO::PARAM_INT);
                $setNewDefaultStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
                $setNewDefaultStmt->execute();
            }
        }

        // Commit transaction
        $db->commit();
        http_response_code(200); // OK
        echo json_encode([
            "success" => true,
            "message" => "Car deleted from garage successfully."
        ]);
    } else {
        // Deletion failed
        $db->rollBack();
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to delete the specified car."
        ]);
    }
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage() // Provide more details in dev mode
        // "message" => "An internal error occurred while deleting the car."
    ]);
}
