<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Set response headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests for this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Please use GET."
    ]);
    exit();
}

// Get brand_id from query parameter
$brand_id = isset($_GET['brand_id']) ? filter_var($_GET['brand_id'], FILTER_VALIDATE_INT) : null;

if ($brand_id === null || $brand_id === false || $brand_id <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing brand_id parameter. Please provide a positive integer."
    ]);
    exit();
}

// Database connection
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
    // Query to get car models for the specified brand_id
    $query = "SELECT id, name, year FROM car_models WHERE brand_id = :brand_id ORDER BY year DESC, name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":brand_id", $brand_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        // Fetch all models
        $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the response to match the Dart model
        $formattedModels = [];
        foreach ($models as $model) {
            $formattedModels[] = [
                'id' => (string)$model['id'],    // Ensure ID is a string
                'name' => (string)$model['name'], // Ensure name is a string
                'year' => (int)$model['year']     // Ensure year is an integer
            ];
        }

        // Return success response with models
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Car models retrieved successfully.",
            "data" => $formattedModels
        ]);
    } else {
        // No models found for this brand
        http_response_code(200); // Still a successful request, just empty result
        echo json_encode([
            "success" => true,
            "message" => "No car models found for the specified brand.",
            "data" => []
        ]);
    }
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving car models."
    ]);
}
