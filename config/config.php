<?php

// Database Configuration
define('DB_HOST', 'localhost'); // Replace with your database host
define('DB_USER', 'root'); // Replace with your database username
define('DB_PASS', ''); // Replace with your database password
define('DB_NAME', 'motor_mania'); // Replace with your database name

// JWT Configuration
define('JWT_SECRET_KEY', 'p7sH9T3d4fM2bZqW8R5jG1xV6yC2oU4'); // Replace with a strong, random secret key
define('JWT_ISSUER', 'motormania.com'); // Replace with your domain/issuer
define('JWT_AUDIENCE', 'motormania.com'); // Replace with your audience
define('JWT_ACCESS_TOKEN_EXPIRY', 86400); // Access token expiry time in seconds (1 hour)
define('JWT_REFRESH_TOKEN_EXPIRY', 604800); // Refresh token expiry time in seconds (7 days)

// Response Headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Adjust for production environments
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
