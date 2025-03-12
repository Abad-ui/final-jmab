<?php
//Place this in /api/index.php
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api/';

if (strpos($requestUri, $basePath) === 0) {
    $endpoint = substr($requestUri, strlen($basePath));
    // Remove query string if present
    if (($pos = strpos($endpoint, '?')) !== false) {
        $endpoint = substr($endpoint, 0, $pos);
    }
    
    // Redirect to the API script with the endpoint parameter
    include_once 'api.php';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}