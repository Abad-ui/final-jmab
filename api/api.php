<?php
require_once '../vendor/autoload.php';
require_once '../model/user.php';
require_once '../model/cart.php';
require_once '../model/product.php';
require_once '../model/order.php';
require_once '../controller/userController.php';
require_once '../controller/cartController.php';
require_once '../controller/productController.php';
require_once '../controller/orderController.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';
$path = explode('/', trim($endpoint, '/'));

$resource = $path[0] ?? '';
$subResource = isset($path[1]) ? $path[1] : null;
$resourceId = isset($path[1]) ? $path[1] : null;

$controllers = [
    'users' => new UserController(new User()),
    'carts' => new CartController(new Cart()),
    'products' => new ProductController(new Product()),
    'orders' => new OrderController(new Order()),
    'webhook' => new OrderController(new Order()),
];

try {
    if (!array_key_exists($resource, $controllers)) {
        throw new Exception('Resource not found.', 404);
    }

    $controller = $controllers[$resource];

    switch ($method) {
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if ($resource === 'users' && $subResource === 'register') {
                $response = $controller->register($data);
            } elseif ($resource === 'users' && $subResource === 'login') {
                $response = $controller->login($data);
            } elseif ($resource === 'orders' && $resourceId !== null) {
                $response = $controller->create($resourceId, $data);
            } elseif ($resource === 'products' && $resourceId === null) {
                $response = $controller->create($data);
            } elseif ($resource === 'carts' && $resourceId === null) {
                $response = $controller->create($data);
            } elseif ($resource === 'webhook' && $subResource === 'paymongo') {
                $response = $controller->handleWebhook();
            } else {
                throw new Exception('Invalid endpoint.', 404);
            }
            break;

        case 'GET':
            if ($resource === 'products' && $subResource === 'search') {
                $filters = $_GET;
                unset($filters['endpoint']);
                $response = $controller->search($filters);
            } elseif ($resourceId === null && $subResource === null) {
                $response = $controller->getAll();
            } elseif ($resourceId !== null) {
                $response = $controller->getById($resourceId);
            } else {
                throw new Exception('Invalid endpoint.', 404);
            }
            break;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if ($resourceId !== null) {
                $response = $controller->update($resourceId, $data);
            } else {
                throw new Exception('Resource ID required for update.', 400);
            }
            break;

        case 'DELETE':
            if ($resource === 'users' && isset($path[2]) && $path[2] === 'addresses') {
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $addressIds = $data['address_ids'] ?? [];
                    
                if (empty($addressIds) || !is_array($addressIds)) {
                    throw new Exception('Array of address IDs is required in the request body.', 400);
                }
                    
                $response = $controller->deleteAddresses($resourceId, $addressIds);
            } elseif ($resourceId !== null) {
                $response = $controller->delete($resourceId);
            } else {
                throw new Exception('Resource ID required for deletion.', 400);
            }
            break;

        default:
            throw new Exception('Method not allowed.', 405);
    }

    http_response_code($response['status']);
    echo json_encode($response['body']);
} catch (Exception $e) {
    $statusCode = is_numeric($e->getCode()) ? (int)$e->getCode() : 500;
    $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'errors' => [$e->getMessage()]]);
}
?>