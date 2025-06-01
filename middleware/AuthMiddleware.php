<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

function validateAccessToken()
{
    $authHeader = null;

    // Try standard $_SERVER variables first
    if (isset($_SERVER['Authorization'])) {
        $authHeader = $_SERVER['Authorization'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Fallback: Try getallheaders() if available (often works on Apache when $_SERVER fails)
    elseif (function_exists('getallheaders')) {
        $requestHeaders = getallheaders();
        // Server-side fix for bug in old Android versions & case variations
        $requestHeaders = array_change_key_case($requestHeaders, CASE_UPPER);
        if (isset($requestHeaders['AUTHORIZATION'])) {
            $authHeader = trim($requestHeaders['AUTHORIZATION']);
        }
    }

    if (!$authHeader) {
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Access token is missing or not passed correctly by the server."]);
        return null;
    }

    // Extract token from Bearer header
    // Check if the header starts with "Bearer " (case-insensitive)
    if (stripos($authHeader, 'Bearer ') !== 0) {
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Access token format is invalid (must start with Bearer)."]);
        return null;
    }
    $jwt = trim(substr($authHeader, 7)); // Get the token part after "Bearer "

    if (!$jwt) {
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Access token format is invalid (empty token)."]);
        return null;
    }

    try {
        // Decode the token
        $decoded = JWT::decode($jwt, new Key(JWT_SECRET_KEY, 'HS256'));

        // You can add more validation here if needed (e.g., check issuer, audience)
        if ($decoded->iss !== JWT_ISSUER || $decoded->aud !== JWT_AUDIENCE) {
            http_response_code(401); // Unauthorized
            echo json_encode(["success" => false, "message" => "Token issuer or audience invalid."]);
            return null;
        }

        // Token is valid, return decoded data (payload)
        return (array) $decoded->data;
    } catch (ExpiredException $e) {
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Access token has expired."]);
        return null;
    } catch (SignatureInvalidException $e) {
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Access token signature verification failed."]);
        return null;
    } catch (BeforeValidException $e) {
        http_response_code(401); // Unauthorized
        echo json_encode(["success" => false, "message" => "Access token is not yet valid."]);
        return null;
    } catch (Exception $e) {
        // General error during decoding
        http_response_code(401); // Unauthorized
        error_log("JWT Decode Error: " . $e->getMessage()); // Log the actual error server-side
        echo json_encode(["success" => false, "message" => "Invalid access token."]); // Keep generic message for client
        return null;
    }
}

/*
// Example Usage in a protected endpoint:
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

$userData = validateAccessToken();

if ($userData === null) {
    // Validation failed, response already sent by validateAccessToken()
    exit();
}

// Proceed with protected logic, $userData contains [id, name, email]
$userId = $userData['id'];

http_response_code(200);
echo json_encode(["success" => true, "message" => "Access granted.", "data" => $userData]);
*/
