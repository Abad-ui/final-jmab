<?php
require_once '../config/database.php';

class Cart {
    public $conn;
    private $cartTable = 'cart';
    public $cart_id, $user_id, $variant_id, $quantity; // Changed product_id to variant_id

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    public function getAllCarts() {
        $query = "SELECT c.*, 
                 pv.price AS variant_price, 
                 pv.stock AS variant_stock, 
                 pv.size AS variant_size,
                 p.product_id, 
                 p.name AS product_name, 
                 p.image_url AS product_image, 
                 p.brand AS product_brand, 
                 p.model AS product_model, 
                 p.description AS product_description 
                 FROM $this->cartTable c
                 LEFT JOIN product_variants pv ON c.variant_id = pv.variant_id
                 LEFT JOIN products p ON pv.product_id = p.product_id";
        
        $stmt = $this->conn->query($query);
        $carts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $carts ? ['success' => true, 'data' => $carts] : ['success' => true, 'data' => [], 'message' => 'No carts found.'];
    }

    public function getCartByUserId($user_id) {
        $query = "SELECT c.cart_id, 
                 c.user_id, 
                 c.variant_id, 
                 c.quantity, 
                 pv.price AS variant_price, 
                 pv.stock AS variant_stock, 
                 pv.size AS variant_size,
                 p.product_id,
                 p.name AS product_name, 
                 p.image_url AS product_image, 
                 p.brand AS product_brand, 
                 p.model AS product_model, 
                 p.description AS product_description 
                 FROM $this->cartTable c 
                 LEFT JOIN product_variants pv ON c.variant_id = pv.variant_id
                 LEFT JOIN products p ON pv.product_id = p.product_id 
                 WHERE c.user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':user_id' => $user_id]);
        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cartItems as $i => &$item) {
            if ($item['variant_stock'] === null || ($item['quantity'] > $item['variant_stock'] && $item['variant_stock'] == 0)) {
                $this->conn->prepare("DELETE FROM $this->cartTable WHERE cart_id = ?")->execute([$item['cart_id']]);
                unset($cartItems[$i]);
            } elseif ($item['quantity'] > $item['variant_stock']) {
                $this->conn->prepare("UPDATE $this->cartTable SET quantity = ? WHERE cart_id = ?")->execute([$item['variant_stock'], $item['cart_id']]);
                $item['quantity'] = $item['variant_stock'];
            }
            if (isset($item['quantity'])) {
                $item['total_price'] = $item['quantity'] * $item['variant_price'];
            }
        }
        return array_values($cartItems);
    }

    public function createCart($user_id, $variant_id, $quantity) {
        if (empty($user_id) || empty($variant_id) || $quantity <= 0) {
            return ['success' => false, 'errors' => ['Invalid input data.']];
        }

        $stmt = $this->conn->prepare("SELECT c.quantity AS cart_quantity, pv.stock AS variant_stock 
                                    FROM product_variants pv 
                                    LEFT JOIN $this->cartTable c ON c.variant_id = pv.variant_id 
                                    AND c.user_id = :user_id 
                                    WHERE pv.variant_id = :variant_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':variant_id', $variant_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            error_log("Variant not found for variant_id: $variant_id");
            return ['success' => false, 'errors' => ['Product variant not found.']];
        }

        $newQuantity = ($result['cart_quantity'] ?? 0) + $quantity;
        if ($newQuantity > $result['variant_stock']) {
            return ['success' => false, 'errors' => ['Not enough stock available. Max: ' . $result['variant_stock']]];
        }

        try {
            $query = $result['cart_quantity'] 
                ? "UPDATE $this->cartTable SET quantity = :quantity WHERE user_id = :user_id AND variant_id = :variant_id" 
                : "INSERT INTO $this->cartTable (user_id, variant_id, quantity) VALUES (:user_id, :variant_id, :quantity)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':variant_id', $variant_id, PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $newQuantity, PDO::PARAM_INT);
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Item added to cart successfully.'] 
                : ['success' => false, 'errors' => ['Failed to update cart.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateCart($cart_id, $quantity) {
        $stmt = $this->conn->prepare("SELECT c.variant_id, c.quantity AS current_quantity, pv.stock AS variant_stock 
                                    FROM $this->cartTable c 
                                    JOIN product_variants pv ON c.variant_id = pv.variant_id 
                                    WHERE c.cart_id = ?");
        $stmt->execute([$cart_id]);
        $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cartItem) return ['success' => false, 'errors' => ['Cart item not found.']];
        if ($quantity == $cartItem['current_quantity']) return ['success' => false, 'message' => "Quantity is already set to $quantity."];
        if ($quantity > $cartItem['variant_stock']) return ['success' => false, 'errors' => ['Not enough stock available. Max: ' . $cartItem['variant_stock']]];

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