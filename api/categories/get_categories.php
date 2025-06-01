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
    // Query to get all categories
    $query = "SELECT id, name, image_url, dark_image_url FROM categories ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        // Fetch all categories
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the response to match the Dart model
        $formattedCategories = [];
        foreach ($categories as $category) {
            $formattedCategories[] = [
                'id' => (int)$category['id'], // Ensure ID is an int to match Dart model
                'name' => $category['name'],
                'image_url' => $category['image_url'],
                'dark_image_url' => $category['dark_image_url']
            ];
        }

        // Return success response with categories
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Categories retrieved successfully.",
            "data" => $formattedCategories
        ]);
    } else {
        // No categories found
        http_response_code(200); // Still a successful request, just empty result
        echo json_encode([
            "success" => true,
            "message" => "No categories found.",
            "data" => []
        ]);
    }
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving categories."
    ]);
}
