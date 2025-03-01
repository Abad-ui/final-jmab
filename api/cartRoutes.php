<?php
require_once '../model/cart.php';
require '../model/user.php';
require '../vendor/autoload.php';

header('Content-Type: application/json');

function authenticateAPI() {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode(['success' => false, 'errors' => ['Authorization token is required.']]);
        exit;
    }

    $authHeader = $headers['Authorization'];
    $token = str_replace('Bearer ', '', $authHeader);

    $result = User::validateJWT($token);
    if (!$result['success']) {
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
        exit;
    }

    return $result['user'];
}

$method = $_SERVER['REQUEST_METHOD'];
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : null;

if ($method === 'GET' && $endpoint === 'cart' && isset($_GET['user_id'])) {
    authenticateAPI();
    $cart = new Cart();
    $cartInfo = $cart->getCartByUserId($_GET['user_id']);
    
    if (!empty($cartInfo)) {
        echo json_encode(['success' => true, 'cart' => $cartInfo]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'errors' => ['Cart not found.']]);
    }
    exit;
}

if ($method === 'POST' && $endpoint === 'createCart') {
    authenticateAPI();
    $data = json_decode(file_get_contents('php://input'), true);
    $cart = new Cart();
    $result = $cart->createCart($data['user_id'] ?? null, $data['product_id'] ?? null, $data['quantity'] ?? null);
    
    if ($result['success']) {
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
    exit;
}

if ($method === 'PUT' && $endpoint === 'updateCart' && isset($_GET['cart_id'])) {
    authenticateAPI();
    $data = json_decode(file_get_contents('php://input'), true);
    $cart = new Cart();
    $result = $cart->updateCart((int)$_GET['cart_id'], $data['quantity'] ?? null);
    
    echo json_encode($result);
    exit;
}

if ($method === 'DELETE' && $endpoint === 'deleteCart' && isset($_GET['cart_id'])) {
    authenticateAPI();
    $cart = new Cart();

    // Convert comma-separated cart IDs into an array
    $cart_ids = explode(',', $_GET['cart_id']);
    
    // Validate IDs: Remove non-numeric values
    $cart_ids = array_filter($cart_ids, 'is_numeric');
    
    if (empty($cart_ids)) {
        echo json_encode(['success' => false, 'errors' => ['Invalid cart ID(s) provided.']]);
        exit;
    }

    // Call deleteCart function with the array of IDs
    $result = $cart->deleteCart($cart_ids);

    echo json_encode($result);
    exit;
}


http_response_code(404);
echo json_encode(['success' => false, 'errors' => ['Invalid request.']]);
?>