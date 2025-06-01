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

// Check if user is authenticated (has a valid token)
$isAuthenticated = false;
$authHeader = null;

if (isset($_SERVER['Authorization'])) {
    $authHeader = $_SERVER['Authorization'];
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $requestHeaders = apache_request_headers();
    if (isset($requestHeaders['Authorization'])) {
        $authHeader = $requestHeaders['Authorization'];
    }
}

// If Authorization header exists, user is considered authenticated
// In a real app, you would validate the token here
if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
    $isAuthenticated = true;
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
    // Query to get all active offers
    $query = "SELECT id, guest_image_url, user_image_url FROM offers WHERE is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        // Fetch all offers
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the response to match the Dart model
        $formattedOffers = [];
        foreach ($offers as $offer) {
            // Select image URL based on authentication status
            // If authenticated, use user_image_url, otherwise use guest_image_url
            $imageUrl = $isAuthenticated ? $offer['user_image_url'] : $offer['guest_image_url'];

            $formattedOffers[] = [
                'id' => (int)$offer['id'], // Ensure ID is an int to match Dart model
                'guest_image_url' => $offer['guest_image_url'],
                'user_image_url' => $offer['user_image_url']
                // The Dart model will select the appropriate image URL based on availability
            ];
        }

        // Return success response with offers
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Offers retrieved successfully.",
            "data" => $formattedOffers
        ]);
    } else {
        // No offers found
        http_response_code(200); // Still a successful request, just empty result
        echo json_encode([
            "success" => true,
            "message" => "No offers found.",
            "data" => []
        ]);
    }
} catch (PDOException $e) {
    // Log error: $e->getMessage()
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while retrieving offers."
    ]);
}
