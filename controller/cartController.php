<?php
require_once '../model/cart.php';
require_once '../model/user.php';

class CartController {
    private $cartModel;

    // Dependency injection via constructor
    public function __construct(Cart $cartModel) {
        $this->cartModel = $cartModel;
    }

    // Authentication middleware
    private function authenticateAPI() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Authorization token is required.', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $result = User::validateJWT($token);
        if (!$result['success']) {
            throw new Exception(implode(', ', $result['errors']), 401);
        }
        return $result['user'];
    }

    // Get all carts (e.g., for an admin view, if applicable)
    public function getAll() {
        $this->authenticateAPI();
        // Assuming Cart model has a method to fetch all carts (optional feature)
        $carts = $this->cartModel->getAllCarts(); // You’d need to implement this in Cart.php if desired
        if ($carts === null) {
            return [
                'status' => 404,
                'body' => ['success' => false, 'errors' => ['No carts found.']]
            ];
        }
        return [
            'status' => 200,
            'body' => ['success' => true, 'data' => $carts]
        ];
    }

    // Get a cart by user ID (adjusted to fit RESTful pattern)
    public function getById($userId) {
        $this->authenticateAPI();
        $cartInfo = $this->cartModel->getCartByUserId($userId);
        if (!empty($cartInfo)) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => $cartInfo]
            ];
        }
        return [
            'status' => 404,
            'body' => ['success' => false, 'errors' => ['Cart not found.']]
        ];
    }

    // Create a new cart item
    public function create(array $data) {
        $this->authenticateAPI();
        $userId = $data['user_id'] ?? null;
        $productId = $data['product_id'] ?? null;
        $quantity = $data['quantity'] ?? null;

        if (!$userId || !$productId || !$quantity) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['User ID, Product ID, and Quantity are required.']]
            ];
        }

        $result = $this->cartModel->createCart($userId, $productId, $quantity);
        if ($result['success']) {
            return [
                'status' => 201,
                'body' => ['success' => true, 'message' => $result['message']]
            ];
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }

    // Update a cart item by cart ID
    public function update($cartId, array $data) {
        $this->authenticateAPI();
        $quantity = $data['quantity'] ?? null;

        if ($quantity === null) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Quantity is required.']]
            ];
        }

        $result = $this->cartModel->updateCart((int)$cartId, $quantity);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }

    // Delete one or more cart items by ID
    public function delete($cartIds) {
        $this->authenticateAPI();

        // Handle comma-separated IDs (e.g., "1,2,3") or single ID
        $cartIdsArray = is_array($cartIds) ? $cartIds : array_filter(explode(',', $cartIds), 'is_numeric');
        if (empty($cartIdsArray)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Invalid cart ID(s) provided.']]
            ];
        }

        $result = $this->cartModel->deleteCart($cartIdsArray);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }
}
?>