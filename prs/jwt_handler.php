<?php
/**
 * JWT Handler for Pandemic Resilience System (PRS)
 * This file handles JWT token generation and validation
 */

// Set a secure secret key for signing JWT tokens
// In production, this should be stored securely in environment variables
define('JWT_SECRET', 'your_secure_secret_key_for_prs_system');
define('JWT_EXPIRY', 3600); // Token expiry time in seconds (1 hour)

/**
 * Creates a JWT token for the given user ID
 * 
 * @param int $user_id The user ID to include in the token
 * @return string The generated JWT token
 */
function createJWT($user_id) {
    // Create JWT header
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    
    // Create JWT payload
    $payload = [
        'user_id' => $user_id,
        'iat' => time(), // Issued at time
        'exp' => time() + JWT_EXPIRY // Expiration time
    ];
    
    // Encode Header
    $header_encoded = base64UrlEncode(json_encode($header));
    
    // Encode Payload
    $payload_encoded = base64UrlEncode(json_encode($payload));
    
    // Create Signature
    $signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", JWT_SECRET, true);
    $signature_encoded = base64UrlEncode($signature);
    
    // Create JWT Token
    $jwt = "$header_encoded.$payload_encoded.$signature_encoded";
    
    return $jwt;
}

/**
 * Validates a JWT token
 * 
 * @param string $token The JWT token to validate (usually from Authorization header)
 * @return bool|int Returns user_id if valid, false otherwise
 */
function validateJWT($token) {
    // Remove 'Bearer ' prefix if present
    if (strpos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
    
    // Split the token into parts
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false; // Invalid token format
    }
    
    list($header_encoded, $payload_encoded, $signature_encoded) = $parts;
    
    // Verify signature
    $signature = base64UrlDecode($signature_encoded);
    $expected_signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", JWT_SECRET, true);
    
    if (!hash_equals($signature, $expected_signature)) {
        return false; // Invalid signature
    }
    
    // Check payload
    $payload = json_decode(base64UrlDecode($payload_encoded), true);
    
    // Verify token expiration
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return false; // Token expired
    }
    
    // Return user ID from token
    return $payload['user_id'];
}

/**
 * Checks if request is authenticated and returns user ID
 * 
 * @return int|false User ID if authenticated, false otherwise
 */
function getAuthenticatedUser() {
    $headers = getallheaders();
    
    if (!isset($headers['Authorization'])) {
        return false;
    }
    
    return validateJWT($headers['Authorization']);
}

/**
 * Base64Url encode a string
 * 
 * @param string $data The data to encode
 * @return string Base64Url encoded string
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64Url decode a string
 * 
 * @param string $data The data to decode
 * @return string Decoded data
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}
?>