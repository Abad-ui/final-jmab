<?php
require_once '../model/order.php';
require_once '../model/user.php';

class OrderController {
    private $orderModel;

    public function __construct(Order $orderModel) {
        $this->orderModel = $orderModel;
    }

    private function authenticateAPI() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Authorization token is required.', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $result = User::validateJWT($token);
        if (!$result['success']) {
            throw new Exception(implode(', ', $result['errors']), 401);
        }
        return $result['user'];
    }

    public function getAll() {
        $this->authenticateAPI();
        $orders = $this->orderModel->getAllOrders();
        if ($orders) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'orders' => $orders]
            ];
        }
        return [
            'status' => 404,
            'body' => ['success' => false, 'errors' => ['No orders found.']]
        ];
    }

    public function getById($id) {
        $this->authenticateAPI();
        $orders = $this->orderModel->getOrderById($id);
        if ($orders) {
            return [
                'status' => 200,
                'body' => ['success' => true, 'orders' => $orders]
            ];
        }
        return [
            'status' => 404,
            'body' => ['success' => false, 'errors' => ['No orders found for this ID.']]
        ];
    }

    // Create an order (checkout) with user_id from URL
    public function create($userId, array $data) {
        $this->authenticateAPI();
    
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
            $responseBody = ['success' => true, 'message' => $result['message']];
            if ($paymentMethod === 'gcash' && isset($result['payment_link'])) {
                $responseBody['payment_link'] = $result['payment_link'];
            }
            return [
                'status' => 200,
                'body' => $responseBody
            ];
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }


    // New method to handle Paymongo webhook
    public function handleWebhook() {
        $requestBody = file_get_contents('php://input');
        $headers = getallheaders();
        $signatureHeader = $headers['Paymongo-Signature'] ?? null;
    
        if (!$signatureHeader) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Missing Paymongo-Signature header']]
            ];
        }
    
        $webhookSecret = "whsk_WxZav2Xx9FgFDwRvqLAk5qDo"; // Ensure this matches Paymongo Dashboard
    
        $isValid = $this->orderModel->verifyWebhookSignature($requestBody, $signatureHeader, $webhookSecret);
    
        if (!$isValid) {
            return [
                'status' => 401,
                'body' => ['success' => false, 'errors' => ['Invalid webhook signature']]
            ];
        }
    
        $result = $this->orderModel->handleWebhook($requestBody);
    
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result['success'] ? ['success' => true, 'message' => $result['message']] : ['success' => false, 'errors' => [$result['message']]]
        ];
    }


    public function update($id, array $data) {
        $this->authenticateAPI();
        return [
            'status' => 501,
            'body' => ['success' => false, 'errors' => ['Order update not implemented.']]
        ];
    }

    public function delete($id) {
        $this->authenticateAPI();
        return [
            'status' => 501,
            'body' => ['success' => false, 'errors' => ['Order deletion not implemented.']]
        ];
    }
}
?>