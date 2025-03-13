<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Order {
    private $conn;
    private $orderTable = 'orders';
    private $cartTable = 'cart';
    private $orderItemTable = 'order_items';
    private $userTable = 'users';

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    public function getAllOrders() {
        $query = 'SELECT o.*, u.first_name, u.last_name, 
                         GROUP_CONCAT(DISTINCT CONCAT(p.name, " (x", oi.quantity, ")") ORDER BY p.name ASC SEPARATOR ", ") AS product_details
                  FROM ' . $this->orderTable . ' o
                  LEFT JOIN users u ON o.user_id = u.id
                  LEFT JOIN order_items oi ON o.order_id = oi.order_id
                  LEFT JOIN products p ON oi.product_id = p.product_id
                  GROUP BY o.order_id';
    
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
    
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getOrderById($user_id) {
        $query = 'SELECT o.order_id, o.user_id, o.payment_method, o.payment_status, o.status, 
                         o.reference_number, o.paymongo_session_id, o.paymongo_payment_id, 
                         o.home_address, o.barangay, o.city, o.created_at, o.updated_at,
                         oi.quantity, p.name AS product_name, p.price AS product_price, 
                         (oi.quantity * p.price) AS total_price, p.image_url AS product_image, 
                         p.brand AS product_brand
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
                'currency' => 'PHP',
                'amount' => $item['product_price'] * 100,
                'name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'description' => 'Item in Order'
            ];
        }, $cartItems);

        $data = [
            'data' => [
                'attributes' => [
                    'billing' => ['name' => trim("$first_name $last_name")],
                    'line_items' => $lineItems,
                    'payment_method_types' => ['gcash'],
                    'reference_number' => $referenceNumber,
                    'success_url' => $success_url,
                    'cancel_url' => $cancel_url,
                    'metadata' => ['reference_number' => $referenceNumber]
                ]
            ]
        ];

        try {
            $client = new Client();
            $response = $client->post($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $data
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            // Add this code right here, after getting the response
        $sessionId = $body['data']['id'] ?? null;
        if ($sessionId) {
            $stmt = $this->conn->prepare("UPDATE {$this->orderTable} SET paymongo_session_id = ? WHERE reference_number = ?");
            $stmt->execute([$sessionId, $referenceNumber]);
        }

            return $body['data']['attributes']['checkout_url'] ?? null;
        } catch (RequestException $e) {
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    public function checkout($user_id, $cart_ids, $payment_method, $address_id) {
        if (empty($cart_ids) || !is_array($cart_ids)) return ['success' => false, 'errors' => ['No items selected for checkout.']];
        if (empty($address_id) || !is_numeric($address_id)) return ['success' => false, 'errors' => ['Valid address ID is required.']];
    
        try {
            $this->conn->beginTransaction();
    
            $stmt = $this->conn->prepare("SELECT home_address, barangay, city FROM user_addresses WHERE id = ? AND user_id = ?");
            $stmt->execute([$address_id, $user_id]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$address) throw new Exception('Invalid or unauthorized address ID.');
    
            $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
            $query = "SELECT c.cart_id, c.product_id, c.quantity, p.name AS product_name, p.price AS product_price, (c.quantity * p.price) AS total_price 
                      FROM {$this->cartTable} c 
                      JOIN products p ON c.product_id = p.product_id 
                      WHERE c.user_id = ? AND c.cart_id IN ($placeholders)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute(array_merge([$user_id], $cart_ids));
            $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($cartItems)) throw new Exception('Selected cart items not found.');
    
            $totalAmount = array_sum(array_column($cartItems, 'total_price'));
            $order_status = $payment_method === 'cod' ? 'pending' : 'processing';
            $referenceNumber = uniqid('order_');
    
            $query = "INSERT INTO {$this->orderTable} (user_id, total_price, payment_method, status, reference_number, home_address, barangay, city) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id, $totalAmount, $payment_method, $order_status, $referenceNumber, $address['home_address'], $address['barangay'], $address['city']]);
            $order_id = $this->conn->lastInsertId();
    
            foreach ($cartItems as $item) {
                $stmt = $this->conn->prepare("INSERT INTO {$this->orderItemTable} (order_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $item['product_id'], $item['product_name'], $item['quantity'], $item['product_price']]);
                
                $stmt = $this->conn->prepare("UPDATE products SET stock = stock - ? WHERE product_id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
    
            $stmt = $this->conn->prepare("DELETE FROM {$this->cartTable} WHERE cart_id IN ($placeholders)");
            $stmt->execute($cart_ids);
    
            $this->conn->commit();
    
            if ($payment_method === 'gcash') {
                $stmt = $this->conn->prepare("SELECT first_name, last_name FROM {$this->userTable} WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception('User not found.');
    
                $paymentLink = $this->createGcashCheckoutSession(
                    $cartItems,
                    $user['first_name'],
                    $user['last_name'],
                    'http://localhost/JMAB/J-Mab/HTML/home.html',
                    'http://localhost/JMAB/J-Mab/HTML/home.html',
                    $referenceNumber
                );
                if (!is_string($paymentLink)) throw new Exception('Failed to create GCash checkout session.');
    
                return [
                    'success' => true,
                    'message' => 'Checkout successful. Please complete payment via GCash.',
                    'payment_link' => $paymentLink
                ];
            }
            return [
                'success' => true,
                'message' => 'Checkout successful. Your order has been placed with Cash on Delivery.'
            ];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }
    

    public function revertFailedOrder($order_id) {
        try {
            $this->conn->beginTransaction();
    
            // First, get the order details and verify it exists
            $query = "SELECT o.order_id, o.status, o.payment_status 
                      FROM {$this->orderTable} o 
                      WHERE o.order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$order) {
                throw new Exception("Order with ID $order_id not found.");
            }
    
            // Check if order is already cancelled to avoid duplicate processing
            if ($order['status'] === 'cancelled') {
                throw new Exception("Order $order_id is already cancelled.");
            }
    
            // Get all items in the order
            $query = "SELECT product_id, quantity 
                      FROM {$this->orderItemTable} 
                      WHERE order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            if (empty($orderItems)) {
                throw new Exception("No items found for order $order_id.");
            }
    
            // Return stock to products table
            foreach ($orderItems as $item) {
                $updateStockQuery = "UPDATE products 
                                    SET stock = stock + ? 
                                    WHERE product_id = ?";
                $stockStmt = $this->conn->prepare($updateStockQuery);
                $stockStmt->execute([$item['quantity'], $item['product_id']]);
            }
    
            // Update order status to cancelled and payment_status to failed (if not already set)
            $updateOrderQuery = "UPDATE {$this->orderTable} 
                                SET status = 'cancelled',
                                    payment_status = IF(payment_status IS NULL OR payment_status != 'failed', 'failed', payment_status),
                                    updated_at = NOW()
                                WHERE order_id = ?";
            $orderStmt = $this->conn->prepare($updateOrderQuery);
            $orderStmt->execute([$order_id]);
    
            $this->conn->commit();
    
            return [
                'success' => true,
                'message' => "Order $order_id cancelled and stock reverted successfully."
            ];
    
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => "Failed to revert order: " . $e->getMessage()
            ];
        }
    }

    public function verifyWebhookSignature($requestBody, $signatureHeader, $webhookSecret) {
        $signatureParts = preg_split('/,/', $signatureHeader);
        $timestamp = explode('=', $signatureParts[0])[1];
        $receivedSignature = explode('=', $signatureParts[1])[1];

        $dataToSign = "$timestamp.$requestBody";
        $computedSignature = hash_hmac('sha256', $dataToSign, $webhookSecret);
        return hash_equals($computedSignature, $receivedSignature);
    }

    public function handleWebhook($eventData) {
        $event = json_decode($eventData, true);
        $eventType = $event['data']['attributes']['type'] ?? null;
        $paymentId = $event['data']['attributes']['data']['id'] ?? null;
        
        // Debug the full event structure
        file_put_contents('webhook_debug.log', "Event Data: $eventData\nEvent Type: $eventType\nPayment ID: $paymentId\n", FILE_APPEND);
        
        if (!$eventType || !$paymentId) {
            return ['success' => false, 'message' => 'Invalid webhook payload - missing event type or payment ID'];
        }
        
        // First check if we need to handle this event type
        $newPaymentStatus = match ($eventType) {
            'payment.paid' => 'paid',
            'payment.failed' => 'failed',
            default => null
        };
        
        if (!$newPaymentStatus) {
            return ['success' => false, 'message' => "Unhandled event type: $eventType"];
        }
        
        // For payment.failed events, we need to look up the order using the payment_intent_id
        // This is available in the event data
        $paymentIntentId = $event['data']['attributes']['data']['attributes']['payment_intent_id'] ?? null;
        
        if (!$paymentIntentId && !$paymentId) {
            return ['success' => false, 'message' => 'Missing both payment_intent_id and payment_id'];
        }
        
        // First try to find the order by paymongo_payment_id
        $stmt = $this->conn->prepare("SELECT * FROM {$this->orderTable} WHERE paymongo_payment_id = :payment_id");
        $stmt->bindParam(':payment_id', $paymentId);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found by payment_id, try to find by paymongo_session_id (which might contain payment_intent_id)
        if (!$order && $paymentIntentId) {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->orderTable} WHERE paymongo_session_id = :session_id");
            $stmt->bindParam(':session_id', $paymentIntentId);
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // If still not found, check for any order with payment_method='gcash' and status='processing'
        // This is a fallback mechanism
        if (!$order) {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->orderTable} WHERE payment_method = 'gcash' AND status = 'processing' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return ['success' => false, 'message' => 'Order not found for payment ID: ' . $paymentId];
            }
        }
        
        // For failed payments, update both payment_status and status
        if ($newPaymentStatus === 'failed') {
            // Begin a transaction for the update and stock reversion
            $this->conn->beginTransaction();
            try {
                // Update order with payment status and payment ID, and set order status to cancelled
                $stmt = $this->conn->prepare("UPDATE {$this->orderTable} SET payment_status = :payment_status, paymongo_payment_id = :payment_id, status = 'cancelled' WHERE order_id = :order_id");
                $stmt->execute([
                    ':payment_status' => $newPaymentStatus, 
                    ':payment_id' => $paymentId,
                    ':order_id' => $order['order_id']
                ]);
                
                // Get all items in the order to revert stock
                $query = "SELECT product_id, quantity 
                          FROM {$this->orderItemTable} 
                          WHERE order_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$order['order_id']]);
                $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($orderItems)) {
                    // Return stock to products table
                    foreach ($orderItems as $item) {
                        $updateStockQuery = "UPDATE products 
                                         SET stock = stock + ? 
                                         WHERE product_id = ?";
                        $stockStmt = $this->conn->prepare($updateStockQuery);
                        $stockStmt->execute([$item['quantity'], $item['product_id']]);
                    }
                }
                
                $this->conn->commit();
                
                file_put_contents('webhook_debug.log', "Updated order ID: {$order['order_id']} to status: cancelled and payment_status: failed with Payment ID: $paymentId\n", FILE_APPEND);
                return ['success' => true, 'message' => "Payment status updated to 'failed' and order cancelled with stock reverted"];
            } catch (Exception $e) {
                $this->conn->rollBack();
                file_put_contents('webhook_debug.log', "Failed to update order {$order['order_id']}: " . $e->getMessage() . "\n", FILE_APPEND);
                return ['success' => false, 'message' => "Failed to update order: " . $e->getMessage()];
            }
        } else {
            // For successful payments, just update the payment status and ID
            $stmt = $this->conn->prepare("UPDATE {$this->orderTable} SET payment_status = :payment_status, paymongo_payment_id = :payment_id WHERE order_id = :order_id");
            $stmt->execute([
                ':payment_status' => $newPaymentStatus, 
                ':payment_id' => $paymentId,
                ':order_id' => $order['order_id']
            ]);
            
            file_put_contents('webhook_debug.log', "Updated order ID: {$order['order_id']} to payment_status: $newPaymentStatus with Payment ID: $paymentId\n", FILE_APPEND);
            return ['success' => true, 'message' => "Payment status updated to '$newPaymentStatus'"];
        }
    }

    public function updateOrderStatus($order_id, $new_status) {
        try {
            $this->conn->beginTransaction();
    
            // Get current status
            $query = "SELECT status, payment_method FROM {$this->orderTable} WHERE order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $current_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$current_order) {
                throw new Exception("Order with ID $order_id not found.");
            }
    
            $current_status = $current_order['status'];
            $payment_method = $current_order['payment_method'];
            
            $allowed_transitions = [
                'pending' => ['processing', 'out for delivery', 'cancelled'],
                'processing' => ['out for delivery', 'cancelled'],
                'out for delivery' => ['delivered', 'failed delivery'],
                'failed delivery' => ['out for delivery', 'cancelled']
            ];
    
            // Validate status transition
            if (!isset($allowed_transitions[$current_status]) || 
                !in_array($new_status, $allowed_transitions[$current_status])) {
                throw new Exception("Invalid status transition from '$current_status' to '$new_status'");
            }
    
            // Determine payment_status update based on new order status
            $payment_status_update = "";
            
            if ($new_status === 'cancelled') {
                $payment_status_update = ", payment_status = 'failed'";
            } elseif ($new_status === 'delivered') {
                // For COD orders, set to paid when delivered
                if ($payment_method === 'cod') {
                    $payment_status_update = ", payment_status = 'paid'";
                }
                // For other payment methods (like gcash), we don't change payment_status
                // as it should already be set by the payment gateway
            }
    
            // If cancelling, revert stock
            if ($new_status === 'cancelled') {
                // Get all items in the order
                $query = "SELECT product_id, quantity 
                        FROM {$this->orderItemTable} 
                        WHERE order_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$order_id]);
                $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($orderItems)) {
                    throw new Exception("No items found for order $order_id.");
                }
                
                // Return stock to products table
                foreach ($orderItems as $item) {
                    $updateStockQuery = "UPDATE products 
                                        SET stock = stock + ? 
                                        WHERE product_id = ?";
                    $stockStmt = $this->conn->prepare($updateStockQuery);
                    $stockStmt->execute([$item['quantity'], $item['product_id']]);
                }
            }
    
            // Update status and payment_status if needed
            $query = "UPDATE {$this->orderTable} 
                    SET status = ? 
                    {$payment_status_update},
                    updated_at = NOW() 
                    WHERE order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$new_status, $order_id]);
    
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => "Order $order_id status updated to '$new_status' successfully"
            ];
    
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => "Failed to update order status: " . $e->getMessage()
            ];
        }
    }
    
}
?>