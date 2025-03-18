<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';

class Receipt {
    public $conn;
    public $receiptTable = 'order_receipts';
    public $receipt_id, $order_id, $user_id, $order_reference, $total_amount, $payment_method, $payment_status;

    public function __construct(){ 
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->order_id)) $errors[] = 'Order ID is required.';
        if (empty($this->user_id)) $errors[] = 'User ID is required.';
        if (empty($this->order_reference)) $errors[] = 'Order reference is required.';
        if (empty($this->total_amount) || !is_numeric($this->total_amount)) $errors[] = 'Valid total amount is required.';
        if (empty($this->payment_method)) $errors[] = 'Payment method is required.';
        if (empty($this->payment_status)) $errors[] = 'Payment status is required.';
        return $errors;
    }

    public function getReceipts($page = null, $perPage = null) {
        $query = 'SELECT * FROM ' . $this->receiptTable . ' ORDER BY receipt_id DESC';
        
        $countStmt = null;
        $totalReceipts = null;
        if ($page !== null && $perPage !== null) {
            $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $this->receiptTable);
            $countStmt->execute();
            $totalReceipts = $countStmt->fetchColumn();
            
            $offset = ($page - 1) * $perPage;
            $query .= ' LIMIT :perPage OFFSET :offset';
        }

        $stmt = $this->conn->prepare($query);
        if ($page !== null && $perPage !== null) {
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'success' => true,
            'receipts' => $receipts
        ];
        
        if ($page !== null && $perPage !== null) {
            $result['page'] = $page;
            $result['perPage'] = $perPage;
            $result['totalReceipts'] = $totalReceipts;
            $result['totalPages'] = ceil($totalReceipts / $perPage);
        }
        
        return $result;
    }

    public function getReceiptById($receipt_id) {
        $stmt = $this->conn->prepare('SELECT * FROM ' . $this->receiptTable . ' WHERE receipt_id = :receipt_id');
        $stmt->bindParam(':receipt_id', $receipt_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createReceipt() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        $query = 'INSERT INTO ' . $this->receiptTable . ' (order_id, user_id, order_reference, total_amount, payment_method, payment_status) 
                  VALUES (:order_id, :user_id, :order_reference, :total_amount, :payment_method, :payment_status)';
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':order_id', $this->order_id);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':order_reference', $this->order_reference);
        $stmt->bindParam(':total_amount', $this->total_amount);
        $stmt->bindParam(':payment_method', $this->payment_method);
        $stmt->bindParam(':payment_status', $this->payment_status);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Receipt created successfully.']
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function updateReceipt($receipt_id, $data) {
        if (!$this->getReceiptById($receipt_id)) {
            return ['success' => false, 'errors' => ['Receipt not found.']];
        }

        $updates = [];
        $params = [':receipt_id' => $receipt_id];

        foreach (['order_id', 'user_id', 'order_reference', 'total_amount', 'payment_method', 'payment_status'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'errors' => ['No valid fields to update.']];
        }

        $query = 'UPDATE ' . $this->receiptTable . ' SET ' . implode(', ', $updates) . ' WHERE receipt_id = :receipt_id';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Receipt updated successfully.']
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function deleteReceipt($receipt_id) {
        $stmt = $this->conn->prepare('DELETE FROM ' . $this->receiptTable . ' WHERE receipt_id = :receipt_id');
        $stmt->bindParam(':receipt_id', $receipt_id, PDO::PARAM_INT);

        try {
            return $stmt->execute()
                ? ['success' => true, 'message' => 'Receipt deleted successfully.']
                : ['success' => false, 'message' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }
}
?>