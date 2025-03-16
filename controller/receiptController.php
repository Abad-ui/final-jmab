<?php
require_once '../model/receipt.php';
require_once '../vendor/autoload.php';

class ReceiptController {
    private $receiptModel;

    public function __construct() {
        $this->receiptModel = new Receipt();
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

    public function getAll($page = 1, $perPage = 20) {
        $this->authenticateAPI();
        $result = $this->receiptModel->getReceipts($page, $perPage);
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'receipts' => $result['receipts'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalReceipts' => $result['totalReceipts'],
                'totalPages' => $result['totalPages']
            ]
        ];
    }

    public function getById($id) {
        $this->authenticateAPI();
        $receiptInfo = $this->receiptModel->getReceiptById($id);
        return $receiptInfo 
            ? ['status' => 200, 'body' => ['success' => true, 'receipt' => $receiptInfo]]
            : ['status' => 404, 'body' => ['success' => false, 'errors' => ['Receipt not found.']]];
    }

    public function create(array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to create a receipt.']]
            ];
        }

        $this->receiptModel->order_id = $data['order_id'] ?? '';
        $this->receiptModel->user_id = $data['user_id'] ?? '';
        $this->receiptModel->order_reference = $data['order_reference'] ?? '';
        $this->receiptModel->total_amount = $data['total_amount'] ?? 0;
        $this->receiptModel->payment_method = $data['payment_method'] ?? '';
        $this->receiptModel->payment_status = $data['payment_status'] ?? '';

        $result = $this->receiptModel->createReceipt();
        return [
            'status' => $result['success'] ? 201 : 400,
            'body' => $result
        ];
    }

    public function update($id, array $data) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to update a receipt.']]
            ];
        }
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Receipt ID is required.']]
            ];
        }

        $result = $this->receiptModel->updateReceipt($id, $data);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }

    public function delete($id) {
        $userData = $this->authenticateAPI();
        if (!$this->isAdmin($userData)) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You do not have permission to delete a receipt.']]
            ];
        }
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Receipt ID is required.']]
            ];
        }

        $result = $this->receiptModel->deleteReceipt($id);
        return [
            'status' => $result['success'] ? 200 : 400,
            'body' => $result
        ];
    }
}
?>