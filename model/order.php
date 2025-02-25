<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php'; // Load Guzzle

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Order {
    private $conn;
    private $orderTable = 'orders';
    private $cartTable = 'cart';
    private $orderItemTable = 'order_items';
    private $userTable = 'users';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->connect();
    }

    // Fetch all orders
    public function getAllOrders() {
        $query = 'SELECT * FROM ' . $this->orderTable;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch order by user ID
    public function getOrderById($user_id) {
        $query = 'SELECT * FROM ' . $this->orderTable . ' WHERE user_id = :user_id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Create PayMongo Payment Link
    public function createPaymentLink($amount, $description) {
        $client = new Client();
        $api_key = ''; // Replace with your actual secret key
    
        try {
            $response = $client->request('POST', 'https://api.paymongo.com/v1/links', [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Basic ' . base64_encode($api_key),
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'data' => [
                        'attributes' => [
                            'amount' => $amount * 100, // Convert PHP to centavos
                            'description' => $description,
                        ]
                    ]
                ]
            ]);
    
            $body = json_decode($response->getBody(), true);
    
            return $body;
    
        } catch (RequestException $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function checkout($user_id, $cart_ids, $payment_method) {
        if (empty($cart_ids) || !is_array($cart_ids)) {
            return ['success' => false, 'errors' => ['No items selected for checkout.']];
        }
    
        if (!$this->conn->beginTransaction()) {
            return ['success' => false, 'errors' => ['Failed to start transaction.']];
        }
    
        try {
            // Fetch selected cart items
            $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
            $query = "SELECT c.cart_id, c.product_id, c.quantity, 
                             p.name AS product_name, p.image_url AS product_image, 
                             p.price AS product_price, p.stock AS product_stock,
                             (c.quantity * p.price) AS total_price
                      FROM {$this->cartTable} c
                      JOIN products p ON c.product_id = p.product_id
                      WHERE c.user_id = ? AND c.cart_id IN ($placeholders)";
    
            $stmt = $this->conn->prepare($query);
            $stmt->execute(array_merge([$user_id], $cart_ids));
            $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            if (empty($cartItems)) {
                throw new Exception("Selected cart items not found.");
            }
    
            $totalAmount = array_sum(array_column($cartItems, 'total_price'));
    
            // Insert order
            $query = "INSERT INTO {$this->orderTable} (user_id, total_price, payment_method, status) 
                      VALUES (?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            $order_status = ($payment_method === 'cod') ? 'pending' : 'processing';
            $stmt->execute([$user_id, $totalAmount, $payment_method, $order_status]);
            $order_id = $this->conn->lastInsertId();
    
            // Prepare queries outside the loop
            $orderItemQuery = "INSERT INTO {$this->orderItemTable} (order_id, product_id, quantity, price) 
                               VALUES (?, ?, ?, ?)";
            $orderItemStmt = $this->conn->prepare($orderItemQuery);
    
            $updateStockQuery = "UPDATE products SET stock = stock - ? WHERE product_id = ?";
            $updateStockStmt = $this->conn->prepare($updateStockQuery);
    
            // Process order items
            foreach ($cartItems as $item) {
                if ($item['quantity'] > $item['product_stock']) {
                    throw new Exception("Not enough stock for " . $item['product_name']);
                }
    
                $orderItemStmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['product_price']]);
                $updateStockStmt->execute([$item['quantity'], $item['product_id']]);
            }
    
            // Delete selected cart items
            $query = "DELETE FROM {$this->cartTable} WHERE cart_id IN ($placeholders)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($cart_ids);
    
            // Commit the transaction
            $this->conn->commit();
    
            if ($payment_method === 'gcash') {
                $query = "SELECT first_name, last_name FROM {$this->userTable} WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $first_name = $user['first_name'];
                    $last_name = $user['last_name'];
                    $description = "Payment for Order #$order_id (User: $first_name $last_name)";
                } else {
                    $description = "Payment for Order #$order_id (User: Unknown)";
                }

                $paymentLinkResponse = $this->createPaymentLink($totalAmount, $description);
    
                if (isset($paymentLinkResponse['error'])) {
                    throw new Exception("Failed to create payment link: " . $paymentLinkResponse['message']);
                }
    
                return [
                    'success' => true,
                    'message' => 'Checkout successful. Please complete payment via GCash.',
                    'payment_link' => $paymentLinkResponse['data']['attributes']['checkout_url']
                ];
            } else {
                // For COD, return a success message without a payment link
                return [
                    'success' => true,
                    'message' => 'Checkout successful. Your order has been placed with Cash on Delivery.'
                ];
            }
    
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {  
                $this->conn->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }
    
    
    
    
    
    
    
}
?>
