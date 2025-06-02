<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
// Note: This endpoint might not require authentication depending on your app logic
// require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

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

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Please use GET."
    ]);
    exit();
}

// Get and validate product_id from query parameter
$productId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($productId === null || $productId === false || $productId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing product ID query parameter ('id'). Please provide a positive integer."
    ]);
    exit();
}

// Database Connection
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
    // 1. Query to get the specific product
    $productQuery = "SELECT 
                        p.id, p.name, p.description, p.image_url, 
                        p.old_price, p.current_price, p.discount_percentage, 
                        p.amount, p.rating, p.reviews_count, 
                        p.new_product, p.free_delivery, p.shipping_information
                     FROM products p
                     WHERE p.id = :product_id
                     LIMIT 1";
    $productStmt = $db->prepare($productQuery);
    $productStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
    $productStmt->execute();

    if ($productStmt->rowCount() == 1) {
        $productRow = $productStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Fetch compatible cars for this product
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
        $carStmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
        $carStmt->execute();
        $compatibleCarsResult = $carStmt->fetchAll(PDO::FETCH_ASSOC);

        $compatibleCarsList = [];
        foreach ($compatibleCarsResult as $carRow) {
            // Construct the image URL for the compatible car
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

        // 3. Format the product data according to ProductModel
        $formattedProduct = [
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

        // 4. Return success response
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Product retrieved successfully.",
            "data" => $formattedProduct
        ]);
    } else {
        // Product not found
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Product not found.",
            "data" => null
        ]);
    }
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving the product: " . $e->getMessage()
        // "message" => "An internal error occurred while retrieving the product."
    ]);
}
