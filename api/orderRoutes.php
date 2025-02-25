<?php
require_once '../model/order.php';
require '../model/user.php';
require '../vendor/autoload.php';

function authenticateAPI() {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode(['success' => false, 'errors' => ['Authorization token is required.']]);
        exit;
    }

    $authHeader = $headers['Authorization'];
    $token = str_replace('Bearer ', '', $authHeader);
    $userData = User::validateJWT($token);
    
    if (!$userData) {
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode(['success' => false, 'errors' => ['Invalid or expired token.']]);
        exit;
    }

    return $userData;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? null;

if ($method === 'GET' && $endpoint === 'orders') {  // Fetch all orders
    $userData = authenticateAPI();
    $order = new Order();
    $orders = $order->getAllOrders();

    if ($orders) {
        echo json_encode(['success' => true, 'orders' => $orders]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'errors' => ['No orders found.']]);
    }
    exit;
}

if ($method === 'GET' && $endpoint === 'order' && isset($_GET['id'])) { // Fetch order for specific user
    $userData = authenticateAPI();
    $order = new Order();
    $orders = $order->getOrderById($_GET['id']);
    
    if ($orders) {
        echo json_encode(['success' => true, 'orders' => $orders]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'errors' => ['No orders found for this user.']]);
    }
    exit;
}

if ($method === 'POST' && $endpoint === 'checkout') {
    $userData = authenticateAPI(); // Ensure the user is authenticated
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['cart_ids']) || !is_array($data['cart_ids'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['Cart IDs are required and must be an array.']]);
        exit;
    }

    if (empty($data['payment_method']) || !in_array($data['payment_method'], ['gcash', 'cod'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['Invalid payment method. Choose either "gcash" or "cod".']]);
        exit;
    }

    $order = new Order();
    
    if ($data['payment_method'] === 'gcash') {
        // Process GCash payment
        $result = $order->checkout($userData['user']['sub'], $data['cart_ids'], 'gcash');

        if ($result['success']) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'payment_link' => $result['payment_link']
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $result['errors']]);
        }
    } elseif ($data['payment_method'] === 'cod') {
        // Process COD order
        $result = $order->checkout($userData['user']['sub'], $data['cart_ids'], 'cod');

        if ($result['success']) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Order placed successfully with Cash on Delivery.'
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $result['errors']]);
        }
    }

    exit;
}


?>