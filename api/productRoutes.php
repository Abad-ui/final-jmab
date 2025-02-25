<?php
require '../model/user.php'; 
require '../vendor/autoload.php';
require '../model/product.php';

header('Content-Type: application/json');

function isAdmin() {
    $userData = authenticateAPI();
    if (isset($userData['user']['roles'])) {
        $roles = is_array($userData['user']['roles']) ? $userData['user']['roles'] : [$userData['user']['roles']];
        return in_array('admin', $roles);
    }
    return false;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['endpoint'] === 'products') {
    $product = new Product();
    $products = $product->getProducts();
    echo json_encode(['success' => true, 'products' => $products]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint']) && $_GET['endpoint'] === 'product' && isset($_GET['id'])) {
    $product = new Product();
    $productInfo = $product->getProductById($_GET['id']);
    if ($productInfo) {
        echo json_encode(['success' => true, 'product' => $productInfo]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'errors' => ['Product not found.']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['endpoint'] === 'create') {
    if (!isAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        echo json_encode(['success' => false, 'errors' => ['You do not have permission to create a product.']]);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $product = new Product();
    $product->name = $data['name'] ?? '';
    $product->description = $data['description'] ?? '';
    $product->category = $data['category'] ?? '';
    $product->subcategory = $data['subcategory'] ?? null;
    $product->price = $data['price'] ?? 0;
    $product->stock = $data['stock'] ?? 0;
    $product->image_url = $data['image_url'] ?? '';
    $product->brand = $data['brand'] ?? '';
    $product->size = $data['size'] ?? null;
    $product->voltage = $data['voltage'] ?? null;
    $product->tags = $data['tags'] ?? [];

    $result = $product->createProduct();

    if ($result['success']) {
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $_GET['endpoint'] === 'update') {
    if (!isAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        echo json_encode(['success' => false, 'errors' => ['You do not have permission to update a product.']]);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['product_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['Product ID is required.']]);
        exit;
    }

    $product = new Product();
    $result = $product->updateProduct($data['product_id'], $data);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $_GET['endpoint'] === 'delete') {
    if (!isAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        echo json_encode(['success' => false, 'errors' => ['You do not have permission to delete a product.']]);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['product_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['Product ID is required.']]);
        exit;
    }

    $product = new Product();
    $result = $product->deleteProduct($data['product_id']);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['endpoint'] === 'search') {
    $filters = [
        'brand' => $_GET['brand'] ?? null,
        'category' => $_GET['category'] ?? null,
        'subcategory' => $_GET['subcategory'] ?? null,
        'name' => $_GET['name'] ?? null,
        'tags' => isset($_GET['tags']) ? explode(',', $_GET['tags']) : null,
    ];

    $product = new Product();
    $results = $product->searchProducts($filters);

    if (!empty($results)) {
        echo json_encode(['success' => true, 'products' => $results]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'errors' => ['No products found.']]);
    }
} else {
    header('HTTP/1.0 404 Not Found');
    echo json_encode(['success' => false, 'errors' => ['Invalid endpoint.']]);
}
?>
