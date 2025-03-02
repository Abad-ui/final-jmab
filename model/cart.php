<?php
require_once '../config/database.php';

class Cart {
    private $conn;
    private $cartTable = 'cart';

    public $cart_id;
    public $user_id;
    public $product_id;
    public $quantity;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->connect();
    }


    public function getAllCarts() {
        try {
            $query = "SELECT * FROM $this->cartTable";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();

            // Fetch all rows as an associative array
            $carts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($carts === false || empty($carts)) {
                return [
                    'success' => true, // Still successful, just no data
                    'data' => [],
                    'message' => 'No carts found.'
                ];
            }

            return [
                'success' => true,
                'data' => $carts
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'errors' => ['Database error: ' . $e->getMessage()]
            ];
        }
    }
    
    public function getCartByUserId($user_id) {
            $query = 'SELECT c.cart_id, c.user_id, c.product_id, c.quantity, 
            p.name AS product_name, p.image_url AS product_image, 
            p.price AS product_price, p.stock AS product_stock,
            p.brand AS product_brand, p.description AS product_description
    FROM ' . $this->cartTable . ' c
    LEFT JOIN products p ON c.product_id = p.product_id
    WHERE c.user_id = :user_id';

    
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
    
        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        for ($i = 0; $i < count($cartItems); $i++) {
            // Skip items where the product no longer exists (NULL stock)
            if ($cartItems[$i]['product_stock'] === null) {
                // Remove the item from the cart if the product is deleted
                $deleteQuery = 'DELETE FROM ' . $this->cartTable . ' WHERE cart_id = :cart_id';
                $deleteStmt = $this->conn->prepare($deleteQuery);
                $deleteStmt->bindParam(':cart_id', $cartItems[$i]['cart_id'], PDO::PARAM_INT);
                $deleteStmt->execute();
    
                // Remove from array
                unset($cartItems[$i]);
                continue;
            }
    
            // Ensure quantity does not exceed stock
            if ($cartItems[$i]['quantity'] > $cartItems[$i]['product_stock']) {
                if ($cartItems[$i]['product_stock'] > 0) {
                    // Update cart in the database
                    $updateQuery = 'UPDATE ' . $this->cartTable . ' SET quantity = :new_quantity WHERE cart_id = :cart_id';
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->bindParam(':new_quantity', $cartItems[$i]['product_stock'], PDO::PARAM_INT);
                    $updateStmt->bindParam(':cart_id', $cartItems[$i]['cart_id'], PDO::PARAM_INT);
                    $updateStmt->execute();
    
                    // Adjust quantity in returned data
                    $cartItems[$i]['quantity'] = $cartItems[$i]['product_stock'];
                } else {
                    // If stock is 0, remove the item from the cart
                    $deleteQuery = 'DELETE FROM ' . $this->cartTable . ' WHERE cart_id = :cart_id';
                    $deleteStmt = $this->conn->prepare($deleteQuery);
                    $deleteStmt->bindParam(':cart_id', $cartItems[$i]['cart_id'], PDO::PARAM_INT);
                    $deleteStmt->execute();
    
                    // Remove from array
                    unset($cartItems[$i]);
                    continue;
                }
            }
    
            // Calculate total price
            $cartItems[$i]['total_price'] = $cartItems[$i]['quantity'] * $cartItems[$i]['product_price'];
        }
    
        // Reindex array after unset operations
        return array_values($cartItems);
    }
    
    
    
    public function createCart($user_id, $product_id, $quantity) {
        // Fetch current cart quantity and product stock in a single query
        $query = 'SELECT c.quantity AS cart_quantity, p.stock AS product_stock 
                  FROM products p 
                  LEFT JOIN ' . $this->cartTable . ' c 
                  ON c.product_id = p.product_id AND c.user_id = :user_id 
                  WHERE p.product_id = :product_id';
    
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
        // Product not found
        if (!$result) {
            return ['success' => false, 'errors' => ['Product not found.']];
        }
    
        $currentCartQuantity = $result['cart_quantity'] ?? 0; // Existing cart quantity
        $productStock = $result['product_stock']; // Available stock
    
        $newQuantity = $currentCartQuantity + $quantity;
    
        // Check if requested quantity exceeds available stock
        if ($newQuantity > $productStock) {
            return ['success' => false, 'errors' => ['Not enough stock available. Max: ' . $productStock]];
        }
    
        try {
            if ($currentCartQuantity > 0) {
                // Update existing cart item
                $query = 'UPDATE ' . $this->cartTable . ' 
                          SET quantity = :quantity 
                          WHERE user_id = :user_id AND product_id = :product_id';
            } else {
                // Insert new cart item
                $query = 'INSERT INTO ' . $this->cartTable . ' (user_id, product_id, quantity) 
                          VALUES (:user_id, :product_id, :quantity)';
            }
    
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $newQuantity, PDO::PARAM_INT);
    
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Cart created successfully.'];
            }
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    
        return ['success' => false, 'errors' => ['Unknown error occurred.']];
    }
    

    public function updateCart($cart_id, $quantity) {
        // Get product stock and existing cart quantity in one query
        $query = 'SELECT c.product_id, c.quantity AS current_quantity, p.stock AS product_stock 
                  FROM ' . $this->cartTable . ' c
                  JOIN products p ON c.product_id = p.product_id 
                  WHERE c.cart_id = :cart_id';
    
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cart_id', $cart_id, PDO::PARAM_INT);
        $stmt->execute();
        $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
        // Check if cart item exists
        if (!$cartItem) {
            return ['success' => false, 'errors' => ['Cart item not found.']];
        }
    
        $productStock = $cartItem['product_stock'];
        $currentQuantity = $cartItem['current_quantity'];
    
        // Prevent updating if the quantity hasn't changed
        if ($quantity == $currentQuantity) {
            return ['success' => false, 'message' => 'Quantity is already set to ' . $quantity . '.'];
        }
    
        // Check if requested quantity exceeds available stock
        if ($quantity > $productStock) {
            return ['success' => false, 'errors' => ['Not enough stock available. Max: ' . $productStock]];
        }
    
        try {
            $query = 'UPDATE ' . $this->cartTable . ' 
                      SET quantity = :quantity 
                      WHERE cart_id = :cart_id';
    
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
            $stmt->bindParam(':cart_id', $cart_id, PDO::PARAM_INT);
    
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Cart updated successfully.'];
            }
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    
        return ['success' => false, 'errors' => ['Unknown error occurred.']];
    }
    
    
    public function deleteCart($cart_ids) {
        // Ensure input is an array (to handle both single and multiple deletions)
        if (!is_array($cart_ids)) {
            $cart_ids = [$cart_ids]; // Convert single ID into an array
        }
    
        // Convert IDs to a comma-separated list for SQL IN clause
        $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
    
        // Check if cart items exist before attempting deletion
        $query = 'SELECT cart_id FROM ' . $this->cartTable . ' WHERE cart_id IN (' . $placeholders . ')';
        $stmt = $this->conn->prepare($query);
        $stmt->execute($cart_ids);
        $existingCarts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
        // If no matching cart IDs are found, return an error
        if (empty($existingCarts)) {
            return ['success' => false, 'errors' => ['Cart item(s) not found.']];
        }
    
        try {
            // Perform deletion for the found cart items
            $query = 'DELETE FROM ' . $this->cartTable . ' WHERE cart_id IN (' . $placeholders . ')';
            $stmt = $this->conn->prepare($query);
            
            if ($stmt->execute($cart_ids)) {
                return [
                    'success' => true,
                    'message' => count($cart_ids) > 1 
                        ? 'Selected cart items deleted successfully.' 
                        : 'Cart item deleted successfully.'
                ];
            }
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    
        return ['success' => false, 'errors' => ['Unknown error occurred.']];
    }
    
    
}
?>