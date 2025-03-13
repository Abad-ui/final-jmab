<?php
// Handle OPTIONS request (preflight request)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');  // Adjust the origin if needed
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('HTTP/1.1 200 OK');
    exit();
}

require_once '../vendor/autoload.php';
require_once '../model/user.php';
require_once '../model/cart.php';
require_once '../model/product.php';
require_once '../model/order.php';
require_once '../model/message.php';
require_once '../model/notification.php';
require_once '../controller/userController.php';
require_once '../controller/cartController.php';
require_once '../controller/productController.php';
require_once '../controller/orderController.php';
require_once '../controller/messageController.php';
require_once '../controller/notificationController.php';

// Set CORS headers for actual API requests
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');  // You can replace '*' with a specific origin URL if needed

$method = $_SERVER['REQUEST_METHOD'];
$endpoint = isset($_GET['endpoint']) 
    ? $_GET['endpoint'] 
    : (strpos($_SERVER['REQUEST_URI'], '/api/') === 0 
        ? substr($_SERVER['REQUEST_URI'], 5, strpos($_SERVER['REQUEST_URI'], '?') ?: strlen($_SERVER['REQUEST_URI'])) 
        : '');
$path = explode('/', trim($endpoint, '/'));

$resource = $path[0] ?? '';
$subResource = $path[1] ?? null;
$resourceId = null;
$page = max(1, (int)($_GET['page'] ?? 1)); // Default from query params
$perPage = max(1, min(100, (int)($_GET['perPage'] ?? 20))); // Default from query params, max 100

// Parse pagination from path if present
if (in_array('page', $path) && in_array('perPage', $path)) {
    $pageIndex = array_search('page', $path);
    $perPageIndex = array_search('perPage', $path);
    if ($pageIndex !== false && $perPageIndex !== false && isset($path[$pageIndex + 1]) && isset($path[$perPageIndex + 1])) {
        $page = max(1, (int)$path[$pageIndex + 1]); // Override with path value
        $perPage = max(1, min(100, (int)$path[$perPageIndex + 1])); // Override with path value
    }
} elseif (in_array('page', $path)) {
    $pageIndex = array_search('page', $path);
    if ($pageIndex !== false && isset($path[$pageIndex + 1])) {
        $page = max(1, (int)$path[$pageIndex + 1]); // Override with path value
    }
}

// Determine resourceId and specific sub-resources
if ($resource === 'messages' && $subResource === 'user' && isset($path[2])) {
    $resourceId = $path[2];
} elseif ($resource === 'messages' && $subResource === 'conversation' && isset($path[2]) && isset($path[3])) {
    $userId = $path[2];
    $otherUserId = $path[3];
} elseif ($resource === 'notifications' && $subResource === 'user' && isset($path[2])) {
    $resourceId = $path[2];
} elseif ($resource === 'notifications' && $subResource === 'read' && isset($path[2])) {
    $resourceId = $path[2];  // For marking notification as read
} elseif ($resource === 'orders' && $subResource === 'status' && isset($path[2])) {
    $resourceId = $path[2];  // For updating order status
} elseif (!in_array('page', $path) && !in_array('perPage', $path) && isset($path[1])) {
    $resourceId = $path[1];
}

error_log("Raw endpoint: $endpoint");
error_log("Parsed - Resource: '$resource', SubResource: '$subResource', ResourceId: '$resourceId', Page: '$page', PerPage: '$perPage', Method: $method");

$controllers = [
    'users' => new UserController(new User()),
    'carts' => new CartController(new Cart()),
    'products' => new ProductController(new Product()),
    'orders' => new OrderController(new Order()),
    'webhook' => new OrderController(new Order()),
    'messages' => new MessageController(new Message()),
    'notifications' => new NotificationController(new Notification()),
];

try {
    if (!array_key_exists($resource, $controllers)) {
        throw new Exception('Resource not found: ' . $resource, 404);
    }

    $controller = $controllers[$resource];

    switch ($method) {
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if ($resource === 'users' && $subResource === 'register') $response = $controller->register($data);
            elseif ($resource === 'users' && $subResource === 'login') $response = $controller->login($data);
            elseif ($resource === 'orders' && $resourceId !== null) $response = $controller->create($resourceId, $data);
            elseif ($resource === 'products' && $resourceId === null) $response = $controller->create($data);
            elseif ($resource === 'carts' && $resourceId === null) $response = $controller->create($data);
            elseif ($resource === 'webhook' && $subResource === 'paymongo') $response = $controller->handleWebhook();
            elseif ($resource === 'messages' && $resourceId === null) $response = $controller->create($data);
            elseif ($resource === 'notifications' && $resourceId === null) $response = $controller->create($data);
            else throw new Exception('Invalid endpoint.', 404);
            break;

        case 'GET':
            if ($resource === 'products' && $subResource === 'search') {
                $filters = $_GET;
                unset($filters['endpoint'], $filters['page'], $filters['perPage']);
                $response = $controller->search($filters, $page, $perPage);
            } elseif ($resource === 'messages' && $subResource === 'user' && $resourceId !== null) {
                $response = $controller->getMessages($resourceId, $page, $perPage);
            } elseif ($resource === 'messages' && $subResource === 'conversation' && isset($userId) && isset($otherUserId)) {
                $response = $controller->getConversation($userId, $otherUserId, $page, $perPage);
            } elseif ($resource === 'notifications' && $subResource === 'user' && $resourceId !== null) {
                $response = $controller->getUserNotifications($resourceId, $page, $perPage);
            } elseif ($resourceId === null && $subResource === null) {
                $response = $resource === 'messages' || $resource === 'notifications'
                    ? $controller->getAll($page, $perPage)
                    : $controller->getAll($page, $perPage);
            } elseif ($resourceId !== null) {
                $response = $controller->getById($resourceId);
            } else {
                throw new Exception('Invalid endpoint.', 404);
            }
            break;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if ($resource === 'notifications' && $subResource === 'read' && $resourceId !== null) {
                $response = $controller->markAsRead($resourceId);
            } elseif ($resource === 'orders' && $subResource === 'status' && $resourceId !== null) {
                $response = $controller->update($resourceId, $data);
            } elseif ($resourceId !== null) {
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
    $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599 ? (int)$e->getCode() : 500;
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'errors' => [$e->getMessage()]]);
}
?>