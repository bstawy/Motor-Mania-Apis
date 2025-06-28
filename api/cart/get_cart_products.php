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

// Helper function to sanitize names for image URLs
function sanitizeNameForUrl($name)
{
    return strtolower(str_replace([' ', '-', '.'], '_', $name));
}

try {
    // Query to get products in the user's cart along with their details and compatible cars
    $query = "
        SELECT
            uc.quantity,
            p.id AS product_id,
            p.name AS product_name,
            p.description,
            p.image_url AS product_image_url,
            p.old_price,
            p.current_price AS price,
            p.discount_percentage,
            p.amount,
            p.rating,
            p.reviews_count,
            p.new_product,
            p.free_delivery,
            p.shipping_information,
            GROUP_CONCAT(DISTINCT
                JSON_OBJECT(
                    'id', cm.id,
                    'brand', cb.name,
                    'model', cm.name,
                    'year', cm.year
                )
                ORDER BY cm.id
                SEPARATOR ';'
            ) AS compatible_cars_json
        FROM
            user_cart uc
        JOIN
            products p ON uc.product_id = p.id
        LEFT JOIN
            product_compatibility pc ON p.id = pc.product_id
        LEFT JOIN
            car_models cm ON pc.car_model_id = cm.id
        LEFT JOIN
            car_brands cb ON cm.brand_id = cb.id
        WHERE
            uc.user_id = :user_id
        GROUP BY
            uc.id, p.id
        ORDER BY
            uc.created_at DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
    $stmt->execute();

    $cartProducts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $compatibleCars = [];
        if (!empty($row['compatible_cars_json'])) {
            $carsData = explode(';', $row['compatible_cars_json']);
            foreach ($carsData as $carJson) {
                $car = json_decode($carJson, true);
                if ($car) {
                    $brandSanitized = sanitizeNameForUrl($car['brand']);
                    $modelSanitized = sanitizeNameForUrl($car['model']);
                    $year = $car['year'];
                    $car['imageUrl'] = "/images/cars/{$brandSanitized}_{$modelSanitized}_{$year}.png";
                    $compatibleCars[] = $car;
                }
            }
        }

        $product = [
            "id" => (int)$row['product_id'],
            "name" => $row['product_name'],
            "description" => $row['description'],
            "imageUrl" => $row['product_image_url'],
            "oldPrice" => (float)$row['old_price'],
            "price" => (float)$row['price'],
            "discountPercentage" => (float)$row['discount_percentage'],
            "amount" => (int)$row['amount'],
            "rating" => (float)$row['rating'],
            "reviewsCount" => (int)$row['reviews_count'],
            "newProduct" => (bool)$row['new_product'],
            "freeDelivery" => (bool)$row['free_delivery'],
            "shippingInformation" => $row['shipping_information'],
            "compatibleCars" => $compatibleCars
        ];

        $cartProducts[] = [
            "quantity" => (int)$row['quantity'],
            "product" => $product
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Cart products retrieved successfully.",
        "data" => $cartProducts
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage()
    ]);
}
