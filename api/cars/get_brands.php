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
    // Query to get all car brands
    $query = "SELECT id, name, logo_url FROM car_brands ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        // Fetch all brands
        $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the response to match the Dart model
        $formattedBrands = [];
        foreach ($brands as $brand) {
            $formattedBrands[] = [
                'id' => (int)$brand['id'], // Ensure ID is a string to match Dart model
                'name' => $brand['name'],
                'logo_url' => $brand['logo_url']
            ];
        }

        // Return success response with brands
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Car brands retrieved successfully.",
            "data" => $formattedBrands
        ]);
    } else {
        // No brands found
        http_response_code(200); // Still a successful request, just empty result
        echo json_encode([
            "success" => true,
            "message" => "No car brands found.",
            "data" => []
        ]);
    }
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving car brands."
    ]);
}
