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
        $query = 'SELECT 
            o.order_id,
            o.user_id,
            o.payment_method,
            o.payment_status,
            o.status,
            o.reference_number,
            o.paymongo_session_id,
            o.paymongo_payment_id,
            o.created_at,
            o.updated_at,
            oi.quantity,
            p.name AS product_name,
            p.price AS product_price,
            (oi.quantity * p.price) AS total_price,
            p.image_url AS product_image
        FROM ' . $this->orderTable . ' o
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE o.user_id = :user_id';
        
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

    // New method to verify webhook podpis
    public function verifyWebhookSignature($requestBody, $signatureHeader, $webhookSecret) {
        // Split the signature header (e.g., "t=12345,te=abcde")
        $signatureParts = preg_split("/,/", $signatureHeader);
        $timePart = preg_split("/=/", $signatureParts[0]);
        $signaturePart = preg_split("/=/", $signatureParts[1]);

        $timestamp = $timePart[1];
        $receivedSignature = $signaturePart[1];

        // Concatenate timestamp and request body
        $dataToSign = $timestamp . '.' . $requestBody;

        // Compute HMAC SHA256 signature
        $computedSignature = hash_hmac('sha256', $dataToSign, $webhookSecret);

        // Compare signatures
        return hash_equals($computedSignature, $receivedSignature);
    }

    // New method to handle webhook payload and update order status
    public function handleWebhook($eventData) {
        $event = json_decode($eventData, true);
        $eventType = $event['data']['attributes']['type'] ?? null;
        $referenceNumber = $event['data']['attributes']['data']['attributes']['reference_number'] ?? null;
        $paymentId = $event['data']['attributes']['data']['id'] ?? null; // Extract payment ID
    
        if (!$referenceNumber || !$eventType || !$paymentId) {
            return ['success' => false, 'message' => 'Invalid webhook payload'];
        }
    
        // Find the order by reference number
        $query = "SELECT * FROM {$this->orderTable} WHERE reference_number = :reference_number";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':reference_number', $referenceNumber);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$order) {
            return ['success' => false, 'message' => 'Order not found'];
        }
    
        // Verify payment status with Paymongo API
        $apiKey = "sk_test_upzoTe7kRXHL9TGogLFjuaji"; // Use your secret key
        $client = new Client();
        try {
            $response = $client->get("https://api.paymongo.com/v1/payments/{$paymentId}", [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
                    'Accept' => 'application/json',
                ]
            ]);
            $paymentData = json_decode($response->getBody()->getContents(), true);
            $actualStatus = $paymentData['data']['attributes']['status'];
        } catch (RequestException $e) {
            return ['success' => false, 'message' => 'Failed to verify payment status: ' . $e->getMessage()];
        }
    
        // Determine new payment status based on event type and API verification
        $newPaymentStatus = null;
        switch ($eventType) {
            case 'payment.paid':
                if ($actualStatus === 'paid') {
                    $newPaymentStatus = 'paid';
                } else {
                    return ['success' => false, 'message' => 'Payment status mismatch: expected paid, got ' . $actualStatus];
                }
                break;
            case 'payment.failed':
                if ($actualStatus === 'failed') {
                    $newPaymentStatus = 'failed';
                } else {
                    return ['success' => false, 'message' => 'Payment status mismatch: expected failed, got ' . $actualStatus];
                }
                break;
            default:
                return ['success' => false, 'message' => 'Unhandled event type: ' . $eventType];
        }
    
        if ($newPaymentStatus) {
            $query = "UPDATE {$this->orderTable} SET payment_status = :payment_status WHERE reference_number = :reference_number";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':payment_status', $newPaymentStatus);
            $stmt->bindParam(':reference_number', $referenceNumber);
            $stmt->execute();
    
            return ['success' => true, 'message' => "Payment status updated to '$newPaymentStatus'"];
        }
    
        return ['success' => false, 'message' => 'No payment status update required'];
    }

}
?>
