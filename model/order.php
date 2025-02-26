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

    public function createGcashCheckoutSession($cartItems, $first_name, $last_name, $success_url, $cancel_url, $referenceNumber) {
        $api_url = "https://api.paymongo.com/v1/checkout_sessions";
        $api_key = "sk_test_upzoTe7kRXHL9TGogLFjuaji";
    
        $lineItems = array_map(function ($item) {
            return [
                "currency" => "PHP",
                "amount" => $item['product_price'] * 100, // Convert to cents
                "name" => $item['product_name'],
                "quantity" => $item['quantity'],
                "description" => "Item in Order"
            ];
        }, $cartItems);
    
        $full_name = trim("$first_name $last_name");
    
        $data = [
            "data" => [
                "attributes" => [
                    "billing" => ["name" => $full_name],
                    "line_items" => $lineItems,
                    "payment_method_types" => ["gcash"],
                    "reference_number" => $referenceNumber,
                    "success_url" => $success_url,
                    "cancel_url" => $cancel_url
                ]
            ]
        ];
    
        try {
            $client = new Client();
            $response = $client->post($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $data
            ]);
    
            $body = json_decode($response->getBody()->getContents(), true);
            return $body['data']['attributes']['checkout_url'] ?? null;
        } catch (RequestException $e) {
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }
    
    public function checkout($user_id, $cart_ids, $payment_method) {
        if (empty($cart_ids) || !is_array($cart_ids)) {
            return ['success' => false, 'errors' => ['No items selected for checkout.']];
        }
    
        try {
            $this->conn->beginTransaction();
    
            $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
    
            $query = "SELECT c.cart_id, c.product_id, c.quantity, p.name AS product_name, p.price AS product_price, (c.quantity * p.price) AS total_price 
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
            $order_status = ($payment_method === 'cod') ? 'pending' : 'processing';
            $referenceNumber = uniqid("order_");
    
            $query = "INSERT INTO {$this->orderTable} (user_id, total_price, payment_method, status, reference_number) 
                      VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id, $totalAmount, $payment_method, $order_status, $referenceNumber]);
    
            $order_id = $this->conn->lastInsertId();
    
            foreach ($cartItems as $item) {
                $query = "INSERT INTO {$this->orderItemTable} (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['product_price']]);
            }
    
            $query = "DELETE FROM {$this->cartTable} WHERE cart_id IN ($placeholders)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($cart_ids);
    
            $this->conn->commit();
    
            if ($payment_method === 'gcash') {
                $query = "SELECT first_name, last_name FROM {$this->userTable} WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
                if (!$user) {
                    throw new Exception("User not found.");
                }
    
                $paymentLink = $this->createGcashCheckoutSession($cartItems, $user['first_name'], $user['last_name'], "https://yourwebsite.com/success", "https://yourwebsite.com/cancel", $referenceNumber);
    
                if (!is_string($paymentLink)) {
                    throw new Exception("Failed to create GCash checkout session.");
                }
    
                return [
                    'success' => true,
                    'message' => 'Checkout successful. Please complete payment via GCash.',
                    'payment_link' => $paymentLink
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'Checkout successful. Your order has been placed with Cash on Delivery.'
                ];
            }
    
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }
}
?>
