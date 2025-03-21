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
                         SUM(oi.quantity) AS total_quantity, 
                         COUNT(DISTINCT o.user_id) AS total_customers, 
                         oi.product_id AS product_id,
                         GROUP_CONCAT(DISTINCT CONCAT(
                             p.name, " - ", 
                             COALESCE(p.model, p.name), " - ", 
                             pv.size, " (x", oi.quantity, ")"
                         ) ORDER BY p.name ASC SEPARATOR ", ") AS product_details
                  FROM ' . $this->orderTable . ' o
                  LEFT JOIN users u ON o.user_id = u.id
                  LEFT JOIN ' . $this->orderItemTable . ' oi ON o.order_id = oi.order_id
                  LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                  LEFT JOIN products p ON pv.product_id = p.product_id
                  GROUP BY o.order_id'; 
    
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
    
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getOrderById($user_id) {
        $query = 'SELECT o.order_id, o.user_id, o.payment_method, o.payment_status, o.status, 
                         o.reference_number, o.paymongo_session_id, o.paymongo_payment_id, 
                         o.home_address, o.barangay, o.city, o.created_at, o.updated_at,
                         oi.quantity, p.name AS product_name, pv.price AS variant_price, 
                         pv.size AS variant_size, pv.variant_id,
                         oi.product_id AS product_id,
                         (oi.quantity * pv.price) AS total_price, 
                         p.image_url AS product_image, p.brand AS product_brand, 
                         p.model AS product_model
                  FROM ' . $this->orderTable . ' o
                  LEFT JOIN ' . $this->orderItemTable . ' oi ON o.order_id = oi.order_id
                  LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                  LEFT JOIN products p ON pv.product_id = p.product_id
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
                'amount' => $item['variant_price'] * 100, // Use variant_price
                'name' => $item['product_name'] . ' - ' . $item['product_model'] . ' - ' . $item['variant_size'], // Include model
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
            $query = "SELECT c.cart_id, c.variant_id, c.quantity, 
                             p.product_id, p.name AS product_name, p.model AS product_model, 
                             pv.price AS variant_price, pv.size AS variant_size, 
                             (c.quantity * pv.price) AS total_price 
                      FROM {$this->cartTable} c 
                      JOIN product_variants pv ON c.variant_id = pv.variant_id
                      JOIN products p ON pv.product_id = p.product_id 
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
                $stmt = $this->conn->prepare("INSERT INTO {$this->orderItemTable} (order_id, product_id, variant_id, product_name, quantity, price) 
                                            VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $item['product_id'], $item['variant_id'], $item['product_name'] . ' - ' . $item['product_model'] . ' - ' . $item['variant_size'], $item['quantity'], $item['variant_price']]);
                
                $stmt = $this->conn->prepare("UPDATE product_variants SET stock = stock - ? WHERE variant_id = ?");
                $stmt->execute([$item['quantity'], $item['variant_id']]);
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
                    'payment_link' => $paymentLink,
                    'order_id' => $order_id
                ];
            }
            return [
                'success' => true,
                'message' => 'Checkout successful. Your order has been placed with Cash on Delivery.',
                'order_id' => $order_id
            ];
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log('Checkout Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }

    public function revertFailedOrder($order_id) {
        try {
            $this->conn->beginTransaction();
    
            $query = "SELECT o.order_id, o.status, o.payment_status 
                      FROM {$this->orderTable} o 
                      WHERE o.order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$order) throw new Exception("Order with ID $order_id not found.");
            if ($order['status'] === 'cancelled') throw new Exception("Order $order_id is already cancelled.");
    
            $query = "SELECT variant_id, quantity 
                      FROM {$this->orderItemTable} 
                      WHERE order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            if (empty($orderItems)) throw new Exception("No items found for order $order_id.");
    
            foreach ($orderItems as $item) {
                $updateStockQuery = "UPDATE product_variants 
                                    SET stock = stock + ? 
                                    WHERE variant_id = ?";
                $stockStmt = $this->conn->prepare($updateStockQuery);
                $stockStmt->execute([$item['quantity'], $item['variant_id']]);
            }
    
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
        error_log("Webhook received: " . $eventData, 3, '../api/webhook_debug.log');
        $event = json_decode($eventData, true);
        $eventType = $event['data']['attributes']['type'] ?? null;
        $paymentId = $event['data']['attributes']['data']['id'] ?? null;
    
        if (!$eventType || !$paymentId) {
            error_log("Invalid webhook payload: " . $eventData, 3, '../api/webhook_debug.log');
            return ['success' => false, 'message' => 'Invalid webhook payload - missing event type or payment ID'];
        }
    
        $newPaymentStatus = match ($eventType) {
            'payment.paid' => 'paid',
            'payment.failed' => 'failed',
            'payment.refunded' => 'refunded',
            'payment.refund.updated' => 'refunded',
            default => null
        };
    
        if (!$newPaymentStatus) {
            error_log("Unhandled event type: $eventType", 3, '../api/webhook_debug.log');
            return ['success' => false, 'message' => "Unhandled event type: $eventType"];
        }
    
        $paymentIntentId = $event['data']['attributes']['data']['attributes']['payment_intent_id'] ?? null;
    
        $stmt = $this->conn->prepare("SELECT * FROM {$this->orderTable} WHERE paymongo_payment_id = :payment_id OR paymongo_session_id = :session_id");
        $stmt->execute([':payment_id' => $paymentId, ':session_id' => $paymentIntentId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$order) {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->orderTable} WHERE payment_method = 'gcash' AND status = 'processing' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) return ['success' => false, 'message' => 'Order not found for payment ID: ' . $paymentId];
        }
    
        if ($newPaymentStatus === 'failed') {
            $this->conn->beginTransaction();
            try {
                $stmt = $this->conn->prepare("UPDATE {$this->orderTable} SET payment_status = :payment_status, paymongo_payment_id = :payment_id, status = 'cancelled' WHERE order_id = :order_id");
                $stmt->execute([
                    ':payment_status' => $newPaymentStatus,
                    ':payment_id' => $paymentId,
                    ':order_id' => $order['order_id']
                ]);
    
                $query = "SELECT variant_id, quantity 
                          FROM {$this->orderItemTable} 
                          WHERE order_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$order['order_id']]);
                $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                foreach ($orderItems as $item) {
                    $updateStockQuery = "UPDATE product_variants 
                                        SET stock = stock + ? 
                                        WHERE variant_id = ?";
                    $stockStmt = $this->conn->prepare($updateStockQuery);
                    $stockStmt->execute([$item['quantity'], $item['variant_id']]);
                }
    
                $this->conn->commit();
                error_log("Webhook payload after failed transaction (order_id={$order['order_id']}): " . $eventData, 3, '../api/webhook_debug.log');
                return ['success' => true, 'message' => "Payment status updated to 'failed' and order cancelled with stock reverted"];
            } catch (Exception $e) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => "Failed to update order: " . $e->getMessage()];
            }
        } elseif ($newPaymentStatus === 'refunded') {
            $this->conn->beginTransaction();
            try {
                // Determine new order status based on current status
                $newOrderStatus = in_array($order['status'], ['out for delivery', 'delivered', 'failed delivery']) 
                    ? $order['status'] // Keep fulfillment status if already in transit or delivered
                    : 'cancelled';     // Cancel if still pending or processing
    
                $stmt = $this->conn->prepare("UPDATE {$this->orderTable} SET payment_status = :payment_status, paymongo_payment_id = :payment_id, status = :status WHERE order_id = :order_id");
                $stmt->execute([
                    ':payment_status' => $newPaymentStatus, // 'refunded'
                    ':payment_id' => $paymentId,
                    ':status' => $newOrderStatus,
                    ':order_id' => $order['order_id']
                ]);
    
                // Revert stock only if order is cancelled (pre-fulfillment refund)
                if ($newOrderStatus === 'cancelled') {
                    $query = "SELECT variant_id, quantity 
                              FROM {$this->orderItemTable} 
                              WHERE order_id = ?";
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([$order['order_id']]);
                    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                    foreach ($orderItems as $item) {
                        $updateStockQuery = "UPDATE product_variants 
                                            SET stock = stock + ? 
                                            WHERE variant_id = ?";
                        $stockStmt = $this->conn->prepare($updateStockQuery);
                        $stockStmt->execute([$item['quantity'], $item['variant_id']]);
                    }
                }
    
                $this->conn->commit();
                error_log("Webhook payload after refund transaction (order_id={$order['order_id']}): " . $eventData, 3, '../api/webhook_debug.log');
                return ['success' => true, 'message' => "Payment status updated to 'refunded' and order status set to '$newOrderStatus'"];
            } catch (Exception $e) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => "Failed to process refund: " . $e->getMessage()];
            }
        } else {
            // Handle 'paid' status
            $stmt = $this->conn->prepare("UPDATE {$this->orderTable} SET payment_status = :payment_status, paymongo_payment_id = :payment_id WHERE order_id = :order_id");
            $stmt->execute([
                ':payment_status' => $newPaymentStatus,
                ':payment_id' => $paymentId,
                ':order_id' => $order['order_id']
            ]);
            error_log("Webhook payload after paid transaction (order_id={$order['order_id']}): " . $eventData, 3, '../api/webhook_debug.log');
            return ['success' => true, 'message' => "Payment status updated to '$newPaymentStatus'"];
        }
    }

    public function updateOrderStatus($order_id, $new_status) {
        try {
            $this->conn->beginTransaction();
    
            $query = "SELECT status, payment_method FROM {$this->orderTable} WHERE order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $current_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$current_order) throw new Exception("Order with ID $order_id not found.");
    
            $current_status = $current_order['status'];
            $payment_method = $current_order['payment_method'];
            
            $allowed_transitions = [
                'pending' => ['processing', 'out for delivery', 'cancelled'],
                'processing' => ['out for delivery', 'cancelled'],
                'out for delivery' => ['delivered', 'failed delivery'],
                'failed delivery' => ['out for delivery', 'cancelled']
            ];
    
            if (!isset($allowed_transitions[$current_status]) || 
                !in_array($new_status, $allowed_transitions[$current_status])) {
                throw new Exception("Invalid status transition from '$current_status' to '$new_status'");
            }
    
            $payment_status_update = "";
            if ($new_status === 'cancelled') {
                $payment_status_update = ", payment_status = 'failed'";
            } elseif ($new_status === 'delivered' && $payment_method === 'cod') {
                $payment_status_update = ", payment_status = 'paid'";
            }
    
            if ($new_status === 'cancelled') {
                $query = "SELECT variant_id, quantity 
                        FROM {$this->orderItemTable} 
                        WHERE order_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$order_id]);
                $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($orderItems)) throw new Exception("No items found for order $order_id.");
                
                foreach ($orderItems as $item) {
                    $updateStockQuery = "UPDATE product_variants 
                                        SET stock = stock + ? 
                                        WHERE variant_id = ?";
                    $stockStmt = $this->conn->prepare($updateStockQuery);
                    $stockStmt->execute([$item['quantity'], $item['variant_id']]);
                }
            }
    
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
        } catch(Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => "Failed to update order status: " . $e->getMessage()
            ];
        }
    }

    public function refundOrder($order_id, $reason = "requested_by_customer") {
        try {
            $this->conn->beginTransaction();
    
            // Fetch order details
            $query = "SELECT paymongo_payment_id, payment_status, status, total_price 
                      FROM {$this->orderTable} 
                      WHERE order_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$order) {
                throw new Exception("Order with ID $order_id not found.");
            }
    
            if (empty($order['paymongo_payment_id'])) {
                throw new Exception("No Paymongo payment ID found for order $order_id. Refund must be processed manually.");
            }
    
            if ($order['payment_status'] !== 'paid') {
                throw new Exception("Order $order_id cannot be refunded. Current payment status: {$order['payment_status']}");
            }
    
            // Paymongo Refund API details
            $api_url = "https://api.paymongo.com/v1/refunds";
            $api_key = "sk_test_upzoTe7kRXHL9TGogLFjuaji"; // Replace with your secret key
            $client = new Client();
    
            $refund_data = [
                'data' => [
                    'attributes' => [
                        'amount' => (int)($order['total_price'] * 100), // Convert to centavos
                        'payment_id' => $order['paymongo_payment_id'],
                        'reason' => $reason, // Use the validated reason
                        'notes' => "Refund for order #$order_id"
                    ]
                ]
            ];
    
            // Log the refund request for debugging
            error_log("Refund request for order $order_id with reason: $reason");
    
            // Send refund request to Paymongo
            $response = $client->post($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $refund_data
            ]);
    
            $refund_response = json_decode($response->getBody()->getContents(), true);
            $refund_id = $refund_response['data']['id'] ?? null;
    
            if (!$refund_id) {
                throw new Exception("Failed to initiate refund with Paymongo.");
            }
    
            // ... (rest of the method remains unchanged)
    
            $this->conn->commit();
    
            return [
                'success' => true,
                'message' => "Order $order_id refunded successfully via Paymongo. Refund ID: $refund_id",
                'refund_id' => $refund_id
            ];
        } catch (RequestException $e) {
            $this->conn->rollBack();
            $error_message = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            error_log("Refund error for order $order_id: $error_message");
            return [
                'success' => false,
                'message' => "Refund failed: " . $error_message
            ];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => "Refund failed: " . $e->getMessage()
            ];
        }
    }
    
}
?>