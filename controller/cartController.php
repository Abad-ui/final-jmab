<?php
require_once '../model/cart.php';
require_once '../config/JWTHandler.php';

class CartController {
    private $cartModel;

    public function __construct() {
        $this->cartModel = new Cart();
    }

    private function authenticateAPI() {
        $token = str_replace('Bearer ', '', getallheaders()['Authorization'] ?? '');
        if (!$token || !($result = JWTHandler::validateJWT($token))['success']) {
            throw new Exception($token ? implode(', ', $result['errors']) : 'Authorization token is required.', 401);
        }
        return $result['user'];
    }

    public function getAll() {
        $this->authenticateAPI();
        $carts = $this->cartModel->getAllCarts();
        
        return $carts['data'] === null 
            ? ['status' => 404, 'body' => ['success' => false, 'errors' => ['No carts found.']]] 
            : ['status' => 200, 'body' => ['success' => true, 'cart' => $carts]];
    }

    public function getById($userId) {
        $this->authenticateAPI();
        $cartInfo = $this->cartModel->getCartByUserId($userId);
        
        return $cartInfo 
            ? ['status' => 200, 'body' => ['success' => true, 'cart' => $cartInfo]] 
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['Cart not found.']]];
    }

    public function create(array $data) {
        $this->authenticateAPI();
        $userId = $data['user_id'] ?? null;
        $productId = $data['product_id'] ?? null;
        $quantity = $data['quantity'] ?? null;

        if (!$userId || !$productId || !$quantity) {
            return ['status' => 400, 'body' => ['success' => false, 'errors' => ['User ID, Product ID, and Quantity are required.']]];
        }

        $result = $this->cartModel->createCart($userId, $productId, $quantity);
        return ['status' => $result['success'] ? 201 : 400, 'body' => $result];
    }

    public function update($cartId, array $data) {
        $this->authenticateAPI();
        $quantity = $data['quantity'] ?? null;

        if ($quantity === null) {
            return ['status' => 400, 'body' => ['success' => false, 'errors' => ['Quantity is required.']]];
        }

        $result = $this->cartModel->updateCart((int)$cartId, $quantity);
        return ['status' => $result['success'] ? 200 : 400, 'body' => $result];
    }

    public function delete($cartIds) {
        $this->authenticateAPI();
        $cartIdsArray = is_array($cartIds) ? $cartIds : array_filter(explode(',', $cartIds), 'is_numeric');

        if (empty($cartIdsArray)) {
            return ['status' => 400, 'body' => ['success' => false, 'errors' => ['Invalid cart ID(s) provided.']]];
        }

        $result = $this->cartModel->deleteCart($cartIdsArray);
        return ['status' => $result['success'] ? 200 : 400, 'body' => $result];
    }
}
?>