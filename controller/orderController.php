<?php
require_once '../model/order.php';
require_once '../config/JWTHandler.php';

class OrderController {
    private $orderModel;

    public function __construct() {
        $this->orderModel = new Order();
    }

    private function authenticateAPI() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Authorization token is required.', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $result = JWTHandler::validateJWT($token);
        if (!$result['success']) {
            throw new Exception(implode(', ', $result['errors']), 401);
        }
        return $result['user'];
    }

    private function isAdmin($userData) {
        $roles = isset($userData['roles']) ? (is_array($userData['roles']) ? $userData['roles'] : [$userData['roles']]) : [];
        return in_array('admin', $roles);
    }

    public function getAll() {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to view all orders.']]
            ];
        }
        $orders = $this->orderModel->getAllOrders();
        return $orders 
            ? ['status' => 200, 'body' => ['success' => true, 'orders' => $orders]] 
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['No orders found.']]];
    }

    public function getById($id) {
        $userData = $this->authenticateAPI();
        if ($userData['sub'] != $id && !$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only view your own orders.']]
            ];
        }
        $orders = $this->orderModel->getOrderById($id);
        return $orders 
            ? ['status' => 200, 'body' => ['success' => true, 'orders' => $orders]] 
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['No orders found for this user.']]];
    }

    public function create($userId, array $data) {
        $userData = $this->authenticateAPI();
        if ($userData['sub'] != $userId) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only create orders for yourself.']]
            ];
        }
        if (empty($userId) || !is_numeric($userId)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Valid User ID is required in the URL.']]
            ];
        }

        $cartIds = $data['cart_ids'] ?? null;
        $paymentMethod = $data['payment_method'] ?? null;
        $addressId = $data['address_id'] ?? null;

        if (empty($cartIds) || !is_array($cartIds)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Cart IDs are required and must be an array.']]
            ];
        }
        if (empty($paymentMethod) || !in_array($paymentMethod, ['gcash', 'cod'])) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Invalid payment method. Choose either "gcash" or "cod".']]
            ];
        }
        if (empty($addressId) || !is_numeric($addressId)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Valid address ID is required in the request body.']]
            ];
        }

        $result = $this->orderModel->checkout($userId, $cartIds, $paymentMethod, $addressId);
        if ($result['success']) {
            $responseBody = [
                'success' => true,
                'message' => $result['message'],
                'order_id' => $result['order_id']
            ];
            if ($paymentMethod === 'gcash' && isset($result['payment_link'])) {
                $responseBody['payment_link'] = $result['payment_link'];
            }
            return ['status' => 201, 'body' => $responseBody];
        }
        return ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function handleWebhook() {
        $requestBody = file_get_contents('php://input');
        $signatureHeader = getallheaders()['Paymongo-Signature'] ?? null;

        if (!$signatureHeader) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Missing Paymongo-Signature header']]
            ];
        }

        $webhookSecret = "whsk_xZ82GHDCgoFP9Tfbw2AR1kVB";
        if (!$this->orderModel->verifyWebhookSignature($requestBody, $signatureHeader, $webhookSecret)) {
            return [
                'status' => 401,
                'body' => ['success' => false, 'errors' => ['Invalid webhook signature']]
            ];
        }

        $result = $this->orderModel->handleWebhook($requestBody);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result['success'] 
                ? ['success' => true, 'message' => $result['message']] 
                : ['success' => false, 'errors' => [$result['message']]]
        ];
    }

    public function update($id, array $data) {
        $userData = $this->authenticateAPI();
        /*if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Only admins can update order status.']]
            ];
        }*/
        
        if (empty($id) || !is_numeric($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Valid Order ID is required in the URL.']]
            ];
        }

        $new_status = strtolower($data['status'] ?? '');
        $valid_statuses = ['processing', 'out for delivery', 'delivered', 'failed delivery', 'cancelled'];
        
        if (empty($new_status) || !in_array($new_status, $valid_statuses)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Valid status is required. Allowed values: ' . implode(', ', $valid_statuses)]]
            ];
        }

        $result = $this->orderModel->updateOrderStatus($id, $new_status);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result['success'] 
                ? ['success' => true, 'message' => $result['message']] 
                : ['success' => false, 'errors' => [$result['message']]]
        ];
    }

    public function delete($id) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Only admins can delete orders.']]
            ];
        }
        return [
            'status' => 501,
            'body' => ['success' => false, 'errors' => ['Order deletion not implemented.']]
        ];
    }

    public function refund($id, array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Only admins can refund orders.']]
            ];
        }
    
        if (empty($id) || !is_numeric($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Valid Order ID is required in the URL.']]
            ];
        }
    
        // Valid Paymongo refund reasons
        $validReasons = ['duplicate', 'fraudulent', 'requested_by_customer', 'others'];
        $reason = isset($data['reason']) ? strtolower(trim($data['reason'])) : 'requested_by_customer';
    
        // Map common user inputs to valid Paymongo reasons
        $reasonMap = [
            'customer requested refund' => 'requested_by_customer',
            'requested by customer' => 'requested_by_customer',
            'duplicate payment' => 'duplicate',
            'fraud' => 'fraudulent',
            'other' => 'others'
        ];
        
        // Use mapped reason if it exists, otherwise fallback to input or default
        $reason = $reasonMap[$reason] ?? (in_array($reason, $validReasons) ? $reason : 'requested_by_customer');
    
        $result = $this->orderModel->refundOrder($id, $reason);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result['success']
                ? ['success' => true, 'message' => $result['message'], 'refund_id' => $result['refund_id'] ?? null]
                : ['success' => false, 'errors' => [$result['message']]]
        ];
    }
}
?>