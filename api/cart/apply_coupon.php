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

// 3. Get and Validate Input Data
$data = json_decode(file_get_contents("php://input"), true);
$couponCode = isset($data['coupon_code']) ? trim($data['coupon_code']) : null;
$cartTotal = isset($data['cart_total']) ? filter_var($data['cart_total'], FILTER_VALIDATE_FLOAT) : null;

if (empty($couponCode)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Coupon code is required."
    ]);
    exit();
}

if ($cartTotal === null || $cartTotal === false || $cartTotal < 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid cart total is required."
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

try {
    // Fetch coupon details
    $couponQuery = "SELECT * FROM coupons WHERE code = :code AND is_active = TRUE LIMIT 1";
    $couponStmt = $db->prepare($couponQuery);
    $couponStmt->bindParam(":code", $couponCode, PDO::PARAM_STR);
    $couponStmt->execute();
    $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Coupon not found or is not active."
        ]);
        exit();
    }

    // Check expiration date
    if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Coupon has expired."
        ]);
        exit();
    }

    // Check minimum cart value
    if ($coupon['min_cart_value'] !== null && $cartTotal < $coupon['min_cart_value']) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Cart total does not meet the minimum requirement for this coupon (" . $coupon['min_cart_value'] . ")."
        ]);
        exit();
    }

    // Check usage limit per coupon (total uses)
    if ($coupon['usage_limit_per_coupon'] !== null) {
        $totalUsedQuery = "SELECT SUM(usage_count) AS total_used FROM user_coupon_usage WHERE coupon_id = :coupon_id";
        $totalUsedStmt = $db->prepare($totalUsedQuery);
        $totalUsedStmt->bindParam(":coupon_id", $coupon['id'], PDO::PARAM_INT);
        $totalUsedStmt->execute();
        $totalUsed = $totalUsedStmt->fetch(PDO::FETCH_ASSOC)['total_used'] ?? 0;

        if ($totalUsed >= $coupon['usage_limit_per_coupon']) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Coupon has reached its total usage limit."
            ]);
            exit();
        }
    }

    // Check usage limit per user
    if ($coupon['usage_limit_per_user'] !== null) {
        $userUsedQuery = "SELECT usage_count FROM user_coupon_usage WHERE user_id = :user_id AND coupon_id = :coupon_id LIMIT 1";
        $userUsedStmt = $db->prepare($userUsedQuery);
        $userUsedStmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
        $userUsedStmt->bindParam(":coupon_id", $coupon['id'], PDO::PARAM_INT);
        $userUsedStmt->execute();
        $userUsed = $userUsedStmt->fetch(PDO::FETCH_ASSOC)['usage_count'] ?? 0;

        if ($userUsed >= $coupon['usage_limit_per_user']) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "You have already used this coupon the maximum number of times."
            ]);
            exit();
        }
    }

    // Calculate discount amount
    $discountAmount = 0;
    switch ($coupon['type']) {
        case 'percentage':
            $discountAmount = $cartTotal * ($coupon['value'] / 100);
            if ($coupon['max_discount_value'] !== null && $discountAmount > $coupon['max_discount_value']) {
                $discountAmount = $coupon['max_discount_value'];
            }
            break;
        case 'fixed_amount':
            $discountAmount = $coupon['value'];
            break;
        case 'free_shipping':
            // For free shipping, discount amount can be 0 or a specific shipping cost
            // For now, we'll just indicate free shipping without a monetary discount here
            $discountAmount = 0; // Or a predefined shipping cost if you have one
            break;
    }

    // Ensure discount doesn't exceed cart total
    $discountAmount = min($discountAmount, $cartTotal);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Coupon applied successfully.",
        "data" => [
            "coupon_code" => $couponCode,
            "discount_amount" => round($discountAmount, 2),
            "coupon_type" => $coupon['type'],
            "coupon_value" => (float)$coupon['value'],
            "free_shipping" => ($coupon['type'] === 'free_shipping')
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage()
    ]);
}
