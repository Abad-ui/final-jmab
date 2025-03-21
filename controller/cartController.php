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
        
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'cart' => $carts['data'],
                'message' => $carts['message'] ?? null
            ]
        ];
    }

    public function getById($userId) {
        $this->authenticateAPI();
        $cartInfo = $this->cartModel->getCartByUserId($userId);
    
        // Corrected query with a named placeholder
        $query = 'SELECT first_name FROM users WHERE id = :id';
        $stmt = $this->cartModel->conn->prepare($query);
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $first_name = $stmt->fetchColumn();
    
        return $cartInfo 
            ? ['status' => 200, 'body' => ['success' => true, 'cart' => $cartInfo]] 
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['There are no carts for ' . $first_name]]];
    }
    

    public function create(array $data) {
        $userData = $this->authenticateAPI();
        $userId = $data['user_id'] ?? null;
        $variantId = $data['variant_id'] ?? null; // Changed from product_id to variant_id
        $quantity = $data['quantity'] ?? null;

        if (!$userId || !$variantId || !$quantity) {
            return ['status' => 400, 'body' => ['success' => false, 'errors' => ['User ID, Variant ID, and Quantity are required.']]];
        }

        // Verify user making request matches the cart user
        if ($userData['sub'] != $userId) {
            return ['status' => 403, 'body' => ['success' => false, 'errors' => ['You can only modify your own cart.']]];
        }

        $result = $this->cartModel->createCart($userId, $variantId, $quantity);
        return ['status' => $result['success'] ? 201 : 400, 'body' => $result];
    }

    public function update($cartId, array $data) {
        $userData = $this->authenticateAPI();
        $quantity = $data['quantity'] ?? null;

        if ($quantity === null) {
            return ['status' => 400, 'body' => ['success' => false, 'errors' => ['Quantity is required.']]];
        }

        // Verify cart belongs to user
        $cartStmt = $this->cartModel->conn->prepare("SELECT user_id FROM cart WHERE cart_id = ?");
        $cartStmt->execute([$cartId]);
        $cartUserId = $cartStmt->fetchColumn();
        
        if ($cartUserId === false || $cartUserId != $userData['sub']) { // Changed to sub for JWT consistency
            return ['status' => 403, 'body' => ['success' => false, 'errors' => ['You can only modify your own cart.']]];
        }

        $result = $this->cartModel->updateCart((int)$cartId, $quantity);
        return ['status' => $result['success'] ? 200 : 400, 'body' => $result];
    }

    public function delete($cartIds) {
        $userData = $this->authenticateAPI();
        $cartIdsArray = is_array($cartIds) ? $cartIds : array_filter(explode(',', $cartIds), 'is_numeric');

        if (empty($cartIdsArray)) {
            return ['status' => 400, 'body' => ['success' => false, 'errors' => ['Invalid cart ID(s) provided.']]];
        }

        // Verify all carts belong to user
        $placeholders = implode(',', array_fill(0, count($cartIdsArray), '?'));
        $stmt = $this->cartModel->conn->prepare("SELECT COUNT(*) FROM cart WHERE cart_id IN ($placeholders) AND user_id != ?");
        $stmt->execute([...$cartIdsArray, $userData['sub']]); // Changed to sub
        
        if ($stmt->fetchColumn() > 0) {
            return ['status' => 403, 'body' => ['success' => false, 'errors' => ['You can only delete your own cart items.']]];
        }

        $result = $this->cartModel->deleteCart($cartIdsArray);
        return ['status' => $result['success'] ? 200 : 400, 'body' => $result];
    }
}
?>