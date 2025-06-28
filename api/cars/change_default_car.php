<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

// Set response headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Adjust for production
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

// 2. Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Please use GET."
    ]);
    exit();
}

// 3. Get and Validate Input Data from Query Parameter
$nextParam = isset($_GET['next']) ? filter_var($_GET['next'], FILTER_VALIDATE_INT) : null;

if ($nextParam === null || ($nextParam !== 0 && $nextParam !== 1)) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing 'next' query parameter. Must be 0 (previous) or 1 (next)."
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

// Helper function to sanitize names for image URLs
function sanitizeNameForUrl($name)
{
    return strtolower(str_replace([' ', '-', '.'], '_', $name));
}

try {
    $db->beginTransaction();

    // Get all user's cars, ordered by ID to ensure consistent indexing
    $queryCars = "
        SELECT
            uc.id AS user_car_id,
            uc.is_default,
            cm.id AS car_model_id,
            cm.name AS model_name,
            cm.year AS model_year,
            cb.name AS brand_name
        FROM
            user_cars uc
        JOIN
            car_models cm ON uc.car_model_id = cm.id
        JOIN
            car_brands cb ON cm.brand_id = cb.id
        WHERE
            uc.user_id = :user_id
        ORDER BY
            uc.id ASC
    ";
    $stmtCars = $db->prepare($queryCars);
    $stmtCars->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $stmtCars->execute();
    $userCars = $stmtCars->fetchAll(PDO::FETCH_ASSOC);

    if (empty($userCars)) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "No cars found in your garage."
        ]);
        exit();
    }

    $currentDefaultIndex = -1;
    $newDefaultIndex = -1;

    // Find the current default car's index
    foreach ($userCars as $index => $car) {
        if ($car['is_default']) {
            $currentDefaultIndex = $index;
            break;
        }
    }

    // Determine the new default car's index
    if ($currentDefaultIndex === -1) {
        // No default car set, pick the first one as default
        $newDefaultIndex = 0;
    } else {
        if ($nextParam == 1) {
            // Move to next car
            $newDefaultIndex = ($currentDefaultIndex + 1) % count($userCars);
        } else {
            // Move to previous car
            $newDefaultIndex = ($currentDefaultIndex - 1 + count($userCars)) % count($userCars);
        }
    }

    $newDefaultCarId = $userCars[$newDefaultIndex]['user_car_id'];

    // Set all cars for this user to is_default = false
    $updateAllQuery = "UPDATE user_cars SET is_default = 0 WHERE user_id = :user_id";
    $updateAllStmt = $db->prepare($updateAllQuery);
    $updateAllStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $updateAllStmt->execute();

    // Set the specified car to is_default = true
    $updateTargetQuery = "UPDATE user_cars SET is_default = 1 WHERE id = :user_car_id AND user_id = :user_id";
    $updateTargetStmt = $db->prepare($updateTargetQuery);
    $updateTargetStmt->bindParam(":user_car_id", $newDefaultCarId, PDO::PARAM_INT);
    $updateTargetStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $updateTargetStmt->execute();

    if ($updateTargetStmt->rowCount() == 1) {
        $db->commit();

        // Fetch details of the newly set default car to return in response
        $newDefaultCarDetails = $userCars[$newDefaultIndex];
        $brandSanitized = sanitizeNameForUrl($newDefaultCarDetails['brand_name']);
        $modelSanitized = sanitizeNameForUrl($newDefaultCarDetails['model_name']);
        $year = $newDefaultCarDetails['model_year'];
        $imageUrl = "/images/cars/{$brandSanitized}_{$modelSanitized}_{$year}.png";

        $responseCar = [
            "id" => (int)$newDefaultCarDetails['user_car_id'],
            "brand" => $newDefaultCarDetails['brand_name'],
            "model" => $newDefaultCarDetails['model_name'],
            "year" => (int)$newDefaultCarDetails['model_year'],
            "imageUrl" => $imageUrl
        ];

        http_response_code(200); // OK
        echo json_encode([
            "success" => true,
            "message" => "Default car changed successfully.",
            "data" => $responseCar
        ]);
    } else {
        $db->rollBack();
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to change the default car."
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
