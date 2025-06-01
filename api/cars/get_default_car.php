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

// 3. Database Connection
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

// Helper function to sanitize string for filename (same as in get_garage.php)
function sanitize_for_filename($string)
{
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9]+/', '_', $string);
    $string = trim($string, '_');
    return $string;
}

try {
    // 4. Query to get the user's default car
    $query = "SELECT 
                uc.id as user_car_id, 
                cm.name as model_name, 
                cm.year as model_year, 
                cb.name as brand_name 
              FROM 
                user_cars uc
              JOIN 
                car_models cm ON uc.car_model_id = cm.id
              JOIN 
                car_brands cb ON cm.brand_id = cb.id
              WHERE 
                uc.user_id = :user_id AND uc.is_default = 1
              LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $stmt->execute();

    $defaultCar = null;
    if ($stmt->rowCount() == 1) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Construct the image URL
        $sanitizedBrand = sanitize_for_filename($row['brand_name']);
        $sanitizedModel = sanitize_for_filename($row['model_name']);
        $year = $row['model_year'];
        $imageUrl = "images/cars/{$sanitizedBrand}_{$sanitizedModel}_{$year}.png";

        // Map to the CarModel structure
        $defaultCar = [
            'id' => (int)$row['user_car_id'],
            'brand' => $row['brand_name'],
            'model' => (string)$row['model_name'],
            'year' => (int)$row['model_year'],
            'imageUrl' => $imageUrl
        ];
    }

    // 5. Return success response
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => $defaultCar ? "Default car retrieved successfully." : "No default car set.",
        "data" => $defaultCar
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving the default car: " . $e->getMessage()
    ]);
}
