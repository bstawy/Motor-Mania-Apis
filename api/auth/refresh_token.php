<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Basic validation
if (!isset($data->refresh_token) || empty(trim($data->refresh_token))) {
    http_response_code(400); // Bad Request
    echo json_encode(["success" => false, "message" => "Refresh token is missing."]);
    exit();
}

$refreshToken = trim($data->refresh_token);

// Database connection
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}

// 1. Validate the refresh token structure and signature (if it's a JWT)
try {
    $decodedRefreshToken = JWT::decode($refreshToken, new Key(JWT_SECRET_KEY, 'HS256'));
    $userId = $decodedRefreshToken->sub; // Get user ID from token's subject claim

    // Optional: Check issuer, etc.
    if ($decodedRefreshToken->iss !== JWT_ISSUER) {
        throw new Exception("Invalid token issuer.");
    }

} catch (ExpiredException $e) {
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "Refresh token has expired."]);
    // Optionally: Clean up expired token from DB here or via a cron job
    exit();
} catch (SignatureInvalidException $e) {
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "Refresh token signature verification failed."]);
    exit();
} catch (BeforeValidException $e) {
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "Refresh token is not yet valid."]);
    exit();
} catch (Exception $e) {
    // General error during decoding or validation
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "Invalid refresh token: " . $e->getMessage()]);
    exit();
}

// 2. Verify the refresh token exists in the database and belongs to the user
$query = "SELECT user_id FROM refresh_tokens WHERE token = :token AND user_id = :user_id AND expires_at > NOW() LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(":token", $refreshToken);
$stmt->bindParam(":user_id", $userId);
$stmt->execute();

if ($stmt->rowCount() != 1) {
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "Refresh token not found, invalid, or expired in database."]);
    // Optionally: If the token was valid JWT but not in DB, it might indicate revocation or an issue.
    exit();
}

// 3. Fetch user details
$userQuery = "SELECT id, name, email FROM users WHERE id = :id LIMIT 1";
$userStmt = $db->prepare($userQuery);
$userStmt->bindParam(":id", $userId);
$userStmt->execute();

if ($userStmt->rowCount() != 1) {
    http_response_code(404); // Not Found (or 401)
    echo json_encode(["success" => false, "message" => "User associated with the token not found."]);
    exit();
}
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// 4. Generate a new Access Token
$issuedAt = time();
$accessTokenExpiry = $issuedAt + JWT_ACCESS_TOKEN_EXPIRY;

$accessTokenPayload = [
    'iss' => JWT_ISSUER,
    'aud' => JWT_AUDIENCE,
    'iat' => $issuedAt,
    'nbf' => $issuedAt,
    'exp' => $accessTokenExpiry,
    'data' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email']
    ]
];
$newAccessToken = JWT::encode($accessTokenPayload, JWT_SECRET_KEY, 'HS256');

// 5. Prepare response data (Return new access token and the *same* refresh token)
// Note: For enhanced security, consider implementing refresh token rotation
// (issuing a new refresh token and invalidating the old one).
$responseData = [
    'id' => (string)$user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'tokens' => [
        'access_token' => $newAccessToken,
        'refresh_token' => $refreshToken // Return the same refresh token
    ]
];

http_response_code(200); // OK
echo json_encode([
    "success" => true,
    "message" => "Token refreshed successfully.",
    "data" => $responseData
]);

?>
