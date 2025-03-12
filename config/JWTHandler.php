<?php
require_once '../vendor/autoload.php';
require_once 'config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTHandler {
    private static function getSecretKey() {
        if (!defined('JWT_SECRET_KEY') || empty(JWT_SECRET_KEY)) {
            throw new Exception('JWT Secret Key is not configured.');
        }
        return JWT_SECRET_KEY;
    }

    public static function generateJWT(array $user): string {
        $secretKey = self::getSecretKey();
        $issuedAt = time();
        $expirationTime = $issuedAt + 604800; // Token expires in 7 days

        $payload = [
            'iat' => $issuedAt,         // Issued at
            'exp' => $expirationTime,   // Expiration time
            'sub' => $user['id'],       // Subject (user ID)
            'email' => $user['email'],  // User email
            'roles' => $user['roles']   // User roles
        ];

        return JWT::encode($payload, $secretKey, 'HS256');
    }

    public static function validateJWT(string $token): array {
        $secretKey = self::getSecretKey();
        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            $currentTime = time();
            if ($decoded->exp < $currentTime) {
                return ['success' => false, 'errors' => ['Token has expired.']];
            }
            return ['success' => true, 'user' => (array) $decoded];
        } catch (Exception $e) {
            return ['success' => false, 'errors' => ['Invalid token: ' . $e->getMessage()]];
        }
    }
}
?>