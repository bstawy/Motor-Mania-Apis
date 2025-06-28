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

// 3. Get and Validate Input Data (Brand Name, Model Name, Year)
$data = json_decode(file_get_contents("php://input"));

if (
    !isset($data->brand) || empty(trim($data->brand)) ||
    !isset($data->model) || empty(trim($data->model)) ||
    !isset($data->year) || !filter_var($data->year, FILTER_VALIDATE_INT) ||
    $data->year <= 1900 || $data->year > (int)date("Y") + 1 // Basic year validation
) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid input. Please provide brand (string), model (string), and a valid year (integer)."
    ]);
    exit();
}

$brandName = trim($data->brand);
$modelName = trim($data->model);
$modelYear = (int)$data->year;

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
    // 5. Find car_model_id based on brand name, model name, and year
    $findModelQuery = "SELECT cm.id 
                       FROM car_models cm
                       JOIN car_brands cb ON cm.brand_id = cb.id
                       WHERE LOWER(cb.name) = LOWER(:brand_name)
                       AND LOWER(cm.name) = LOWER(:model_name)
                       AND cm.year = :model_year 
                       LIMIT 1";
    $findModelStmt = $db->prepare($findModelQuery);
    $findModelStmt->bindParam(":brand_name", $brandName);
    $findModelStmt->bindParam(":model_name", $modelName);
    $findModelStmt->bindParam(":model_year", $modelYear, PDO::PARAM_INT);
    $findModelStmt->execute();

    $carModelResult = $findModelStmt->fetch(PDO::FETCH_ASSOC);

    if (!$carModelResult) {
        http_response_code(404); // Not Found
        echo json_encode([
            "success" => false,
            "message" => "The specified car (Brand: {$brandName}, Model: {$modelName}, Year: {$modelYear}) was not found in our database."
        ]);
        exit();
    }
    $carModelId = $carModelResult['id'];

    // 6. Check if user already has this car model
    $checkUserCarQuery = "SELECT id FROM user_cars WHERE user_id = :user_id AND car_model_id = :car_model_id LIMIT 1";
    $checkUserCarStmt = $db->prepare($checkUserCarQuery);
    $checkUserCarStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkUserCarStmt->bindParam(":car_model_id", $carModelId, PDO::PARAM_INT);
    $checkUserCarStmt->execute();
    if ($checkUserCarStmt->rowCount() > 0) {
        http_response_code(409); // Conflict
        echo json_encode([
            "success" => false,
            "message" => "This car model is already in your garage."
        ]);
        exit();
    }

    // 7. Insert the car into the user's garage
    // Check if this is the first car being added for the user
    $checkFirstCarQuery = "SELECT COUNT(*) as car_count FROM user_cars WHERE user_id = :user_id";
    $checkFirstCarStmt = $db->prepare($checkFirstCarQuery);
    $checkFirstCarStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $checkFirstCarStmt->execute();
    $result = $checkFirstCarStmt->fetch(PDO::FETCH_ASSOC);
    $isFirstCar = ($result['car_count'] == 0);

    // Set is_default to true if it's the first car, otherwise false
    $isDefault = $isFirstCar ? 1 : 0;

    $insertQuery = "INSERT INTO user_cars (user_id, car_model_id, is_default) VALUES (:user_id, :car_model_id, :is_default)";
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $insertStmt->bindParam(":car_model_id", $carModelId, PDO::PARAM_INT);
    $insertStmt->bindParam(":is_default", $isDefault, PDO::PARAM_BOOL);

    if ($insertStmt->execute()) {
        $newUserCarId = $db->lastInsertId();
        http_response_code(201); // Created
        echo json_encode([
            "success" => true,
            "message" => "Car added to your garage successfully.",
            "data" => [
                "user_car_id" => (string)$newUserCarId,
                "is_default" => (bool)$isDefault
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to add car to garage."
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
            "message" => "This car model is already in your garage (Constraint Violation)."
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "An error occurred: " . $e->getMessage()
        ]);
    }
}
