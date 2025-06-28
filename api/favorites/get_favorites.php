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

// Helper function to sanitize string for filename
function sanitize_for_filename($string)
{
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9]+/', '_', $string);
    $string = trim($string, '_');
    return $string;
}

try {
    // 4. Query to get user's favorite products with details
    $productQuery = "SELECT 
                        p.id, p.name, p.description, p.image_url, 
                        p.old_price, p.current_price, p.discount_percentage, 
                        p.amount, p.rating, p.reviews_count, 
                        p.new_product, p.free_delivery, p.shipping_information
                     FROM user_favorites uf
                     JOIN products p ON uf.product_id = p.id
                     WHERE uf.user_id = :user_id
                     ORDER BY uf.created_at DESC";

    $productStmt = $db->prepare($productQuery);
    $productStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $productStmt->execute();

    $favoriteProducts = [];
    if ($productStmt->rowCount() > 0) {
        $productResults = $productStmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare query for compatible cars (to be executed inside the loop)
        $carQuery = "SELECT 
                        cm.id as car_model_id, 
                        cm.name as model_name, 
                        cm.year as model_year, 
                        cb.name as brand_name
                     FROM product_compatibility pc
                     JOIN car_models cm ON pc.car_model_id = cm.id
                     JOIN car_brands cb ON cm.brand_id = cb.id
                     WHERE pc.product_id = :product_id
                     ORDER BY cb.name, cm.name, cm.year";
        $carStmt = $db->prepare($carQuery);

        foreach ($productResults as $productRow) {
            $productId = $productRow['id'];

            // Fetch compatible cars for the current product
            $carStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
            $carStmt->execute();
            $compatibleCarsResult = $carStmt->fetchAll(PDO::FETCH_ASSOC);

            $compatibleCarsList = [];
            foreach ($compatibleCarsResult as $carRow) {
                $sanitizedBrand = sanitize_for_filename($carRow['brand_name']);
                $sanitizedModel = sanitize_for_filename($carRow['model_name']);
                $year = $carRow['model_year'];
                $carImageUrl = "images/cars/{$sanitizedBrand}_{$sanitizedModel}_{$year}.png";

                $compatibleCarsList[] = [
                    'id' => (int)$carRow['car_model_id'],
                    'brand' => $carRow['brand_name'],
                    'model' => (string)$carRow['model_name'],
                    'year' => (int)$carRow['model_year'],
                    'imageUrl' => $carImageUrl
                ];
            }

            // Format the product data according to ProductModel
            $favoriteProducts[] = [
                'id' => (int)$productRow['id'],
                'name' => $productRow['name'],
                'description' => $productRow['description'],
                'imageUrl' => $productRow['image_url'],
                'oldPrice' => $productRow['old_price'] ? (float)$productRow['old_price'] : null,
                'price' => (float)$productRow['current_price'],
                'discountPercentage' => (float)$productRow['discount_percentage'],
                'amount' => (int)$productRow['amount'],
                'rating' => (float)$productRow['rating'],
                'reviewsCount' => (int)$productRow['reviews_count'],
                'newProduct' => (bool)$productRow['new_product'],
                'freeDelivery' => (bool)$productRow['free_delivery'],
                'shippingInformation' => $productRow['shipping_information'],
                'compatibleCars' => $compatibleCarsList
            ];
        }
    }

    // 5. Return success response
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Favorites retrieved successfully.",
        "data" => $favoriteProducts
    ]);
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving favorites: " . $e->getMessage()
    ]);
}
