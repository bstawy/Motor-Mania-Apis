<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Basic validation
if (
    !isset($data->email) || !filter_var($data->email, FILTER_VALIDATE_EMAIL) ||
    !isset($data->password) || empty(trim($data->password))
) {
    http_response_code(400); // Bad Request
    echo json_encode(["success" => false, "message" => "Invalid input. Please provide a valid email and password."]);
    exit();
}

$email = trim($data->email);
$password = trim($data->password);

// Database connection
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}

// Find user by email
$query = "SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(":email", $email);
$stmt->execute();

if ($stmt->rowCount() == 1) {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify password
    if (password_verify($password, $user['password_hash'])) {
        // Password is correct, generate tokens
        $issuedAt = time();
        $accessTokenExpiry = $issuedAt + JWT_ACCESS_TOKEN_EXPIRY;
        $refreshTokenExpirySeconds = JWT_REFRESH_TOKEN_EXPIRY;
        $refreshTokenExpiryTimestamp = $issuedAt + $refreshTokenExpirySeconds;

        // Access Token Payload
        $accessTokenPayload = [
            'iss' => JWT_ISSUER,       // Issuer
            'aud' => JWT_AUDIENCE,     // Audience
            'iat' => $issuedAt,        // Issued at
            'nbf' => $issuedAt,        // Not before
            'exp' => $accessTokenExpiry, // Expiration time
            'data' => [                // User data
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email']
            ]
        ];

        // Generate Access Token
        $accessToken = JWT::encode($accessTokenPayload, JWT_SECRET_KEY, 'HS256');

        // Generate Refresh Token (using a secure random string is often preferred)
        // Here we generate a simple JWT as refresh token for simplicity, but it could be just a random string
        // A separate, longer-lived refresh token allows renewing access tokens without re-authentication.
        $refreshTokenPayload = [
            'iss' => JWT_ISSUER,
            'iat' => $issuedAt,
            'exp' => $refreshTokenExpiryTimestamp,
            'sub' => $user['id'] // Subject (user ID)
            // Add a unique identifier (jti) if needed for revocation
        ];
        $refreshToken = JWT::encode($refreshTokenPayload, JWT_SECRET_KEY, 'HS256'); // Use the same key or a different one

        // Store refresh token in the database
        try {
            $deleteQuery = "DELETE FROM refresh_tokens WHERE user_id = :user_id";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->bindParam(':user_id', $user['id']);
            $deleteStmt->execute();

            $insertQuery = "INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)";
            $insertStmt = $db->prepare($insertQuery);
            $expiresAtFormatted = date('Y-m-d H:i:s', $refreshTokenExpiryTimestamp);
            $insertStmt->bindParam(':user_id', $user['id']);
            $insertStmt->bindParam(':token', $refreshToken); // Store the generated refresh token
            $insertStmt->bindParam(':expires_at', $expiresAtFormatted);
            $insertStmt->execute();

        } catch (PDOException $e) {
            // Log error: $e->getMessage()
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to store refresh token."]);
            exit();
        }

        // Prepare response data according to AuthResponseModel
        $responseData = [
            'id' => (string)$user['id'], // Ensure ID is string as per Dart model
            'name' => $user['name'],
            'email' => $user['email'],
            'tokens' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken
            ]
        ];

        http_response_code(200); // OK
        echo json_encode([
            "success" => true,
            "message" => "Login successful.",
            "data" => $responseData
        ]);

    } else {
        // Incorrect password
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Incorrect email or password."]);
    }
} else {
    // User not found
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "Incorrect email or password."]); // Use generic message for security
}

?>
