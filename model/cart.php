<?php
require_once '../config/database.php';

class Cart {
    private $conn;
    private $cartTable = 'cart';
    public $cart_id, $user_id, $product_id, $quantity;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    public function getAllCarts() {
        $stmt = $this->conn->query("SELECT * FROM $this->cartTable");
        $carts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $carts ? ['success' => true, 'data' => $carts] : ['success' => true, 'data' => [], 'message' => 'No carts found.'];
    }

    public function getCartByUserId($user_id) {
        $stmt = $this->conn->prepare("SELECT c.cart_id, c.user_id, c.product_id, c.quantity, p.name AS product_name, p.image_url AS product_image, p.price AS product_price, p.stock AS product_stock, p.brand AS product_brand, p.description AS product_description FROM $this->cartTable c LEFT JOIN products p ON c.product_id = p.product_id WHERE c.user_id = :user_id");
        $stmt->execute([':user_id' => $user_id]);
        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cartItems as $i => &$item) {
            if ($item['product_stock'] === null || ($item['quantity'] > $item['product_stock'] && $item['product_stock'] == 0)) {
                $this->conn->prepare("DELETE FROM $this->cartTable WHERE cart_id = ?")->execute([$item['cart_id']]);
                unset($cartItems[$i]);
            } elseif ($item['quantity'] > $item['product_stock']) {
                $this->conn->prepare("UPDATE $this->cartTable SET quantity = ? WHERE cart_id = ?")->execute([$item['product_stock'], $item['cart_id']]);
                $item['quantity'] = $item['product_stock'];
            }
            if (isset($item['quantity'])) {
                $item['total_price'] = $item['quantity'] * $item['product_price'];
            }
        }
        return array_values($cartItems);
    }

    public function createCart($user_id, $product_id, $quantity) {
        if (empty($user_id) || empty($product_id) || $quantity <= 0) {
            return ['success' => false, 'errors' => ['Invalid input data.']];
        }

        $stmt = $this->conn->prepare("SELECT c.quantity AS cart_quantity, p.stock AS product_stock FROM products p LEFT JOIN $this->cartTable c ON c.product_id = p.product_id AND c.user_id = :user_id WHERE p.product_id = :product_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            error_log("Product not found for product_id: $product_id");
            return ['success' => false, 'errors' => ['Product not found.']];
        }

        $newQuantity = ($result['cart_quantity'] ?? 0) + $quantity;
        if ($newQuantity > $result['product_stock']) {
            return ['success' => false, 'errors' => ['Not enough stock available. Max: ' . $result['product_stock']]];
        }

        try {
            $query = $result['cart_quantity'] 
                ? "UPDATE $this->cartTable SET quantity = :quantity WHERE user_id = :user_id AND product_id = :product_id" 
                : "INSERT INTO $this->cartTable (user_id, product_id, quantity) VALUES (:user_id, :product_id, :quantity)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $newQuantity, PDO::PARAM_INT);
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Cart created successfully.'] 
                : ['success' => false, 'errors' => ['Failed to update cart.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateCart($cart_id, $quantity) {
        $stmt = $this->conn->prepare("SELECT c.product_id, c.quantity AS current_quantity, p.stock AS product_stock FROM $this->cartTable c JOIN products p ON c.product_id = p.product_id WHERE c.cart_id = ?");
        $stmt->execute([$cart_id]);
        $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cartItem) return ['success' => false, 'errors' => ['Cart item not found.']];
        if ($quantity == $cartItem['current_quantity']) return ['success' => false, 'message' => "Quantity is already set to $quantity."];
        if ($quantity > $cartItem['product_stock']) return ['success' => false, 'errors' => ['Not enough stock available. Max: ' . $cartItem['product_stock']]];

        return $this->conn->prepare("UPDATE $this->cartTable SET quantity = ? WHERE cart_id = ?")->execute([$quantity, $cart_id]) 
            ? ['success' => true, 'message' => 'Cart updated successfully.'] 
            : ['success' => false, 'errors' => ['Something went wrong.']];
    }

    public function deleteCart($cart_ids) {
        $cart_ids = (array)$cart_ids;
        $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
        $stmt = $this->conn->prepare("SELECT cart_id FROM $this->cartTable WHERE cart_id IN ($placeholders)");
        $stmt->execute($cart_ids);

        if (empty($stmt->fetchAll(PDO::FETCH_COLUMN))) {
            return ['success' => false, 'errors' => ['Cart item(s) not found.']];
        }

        return $this->conn->prepare("DELETE FROM $this->cartTable WHERE cart_id IN ($placeholders)")->execute($cart_ids) 
            ? ['success' => true, 'message' => count($cart_ids) > 1 ? 'Selected cart items deleted successfully.' : 'Cart item deleted successfully.'] 
            : ['success' => false, 'errors' => ['Something went wrong.']];
    }
}
?>