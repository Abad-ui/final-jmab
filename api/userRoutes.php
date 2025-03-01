<?php
require_once '../model/user.php';
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

    $result = User::validateJWT($token);
    if (!$result['success']) {
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
        exit;
    }

    return $result['user'];
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['endpoint'] === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);

    $user = new User();
    $user->first_name = $data['first_name'] ?? '';
    $user->last_name = $data['last_name'] ?? '';
    $user->email = $data['email'] ?? '';
    $user->password = $data['password'] ?? '';

    $result = $user->register();

    if ($result['success']) {
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['endpoint'] === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);

    $user = new User();
    $result = $user->login($data['email'], $data['password']);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => $result['message'], 'token' => $result['token'], 'user' => $result['user']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['endpoint'] === 'users') {
    authenticateAPI(); 
    $user = new User();
    $users = $user->getUsers();
    
    echo json_encode(['success' => true, 'users' => $users]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint']) && $_GET['endpoint'] === 'user' && isset($_GET['id'])) {
    authenticateAPI();
    $user = new User();
    $userInfo = $user->getUserById($_GET['id']);
    
    if ($userInfo) {
        echo json_encode(['success' => true, 'user' => $userInfo]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'errors' => ['User not found.']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $_GET['endpoint'] === 'update') {
    authenticateAPI();

    $data = json_decode(file_get_contents('php://input'), true);
 
    if (empty($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['User ID is required.']]);
        exit;
    }

    $user = new User();

    $result = $user->update($data['id'], $data);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $_GET['endpoint'] === 'delete') {
    authenticateAPI();
    $data = json_decode(file_get_contents('php://input'), true);

    $user = new User();
    $id = $data['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['User ID is required.']]);
        exit;
    }

    $userToDelete = $user->getUserById($id);
    if ($userToDelete['roles'] === 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'errors' => ['Admin users cannot be deleted.']]);
        exit;
    }

    $result = $user->delete($id);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        http_response_code(400);  
        echo json_encode(['success' => false, 'errors' => $result['errors']]);
    }
}

else {
    header('HTTP/1.0 404 Not Found');
    echo json_encode(['success' => false, 'errors' => ['Invalid endpoint.']]);
}

?>
