<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once '../config/config.php';

class Message {
    public $conn;
    private $table = 'messages';

    public $id, $sender_id, $receiver_id, $message, $timestamp, $status, $is_read;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->sender_id)) $errors[] = 'Sender ID is required.';
        if (empty($this->receiver_id)) $errors[] = 'Receiver ID is required.';
        if (empty($this->message)) $errors[] = 'Message content is required.';
        if (!is_numeric($this->sender_id) || !is_numeric($this->receiver_id)) $errors[] = 'Sender and Receiver IDs must be numeric.';
        return $errors;
    }

    private function isUserExists($userId) {
        $stmt = $this->conn->prepare('SELECT id FROM users WHERE id = :id');
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function create() {
        $errors = $this->validateInput();
        if (!empty($errors)) return ['success' => false, 'errors' => $errors];

        if (!$this->isUserExists($this->sender_id) || !$this->isUserExists($this->receiver_id)) {
            return ['success' => false, 'errors' => ['Sender or Receiver does not exist.']];
        }

        $query = 'INSERT INTO ' . $this->table . ' (sender_id, receiver_id, message, timestamp, status, is_read) 
                  VALUES (:sender_id, :receiver_id, :message, NOW(), :status, :is_read)';
        $stmt = $this->conn->prepare($query);
        $this->status = 'delivered'; // Auto-set to 'delivered' upon server receipt
        $this->is_read = 0;

        $stmt->bindParam(':sender_id', $this->sender_id, PDO::PARAM_INT);
        $stmt->bindParam(':receiver_id', $this->receiver_id, PDO::PARAM_INT);
        $stmt->bindParam(':message', $this->message);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':is_read', $this->is_read, PDO::PARAM_INT);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Message sent successfully.', 'id' => $this->conn->lastInsertId()] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function getMessages($userId, $page = 1, $perPage = 20) {
        if (!is_numeric($userId)) return ['success' => false, 'errors' => ['User ID must be numeric.']];

        $offset = ($page - 1) * $perPage;
        $query = 'SELECT id, sender_id, receiver_id, message, timestamp, status, is_read 
                  FROM ' . $this->table . ' 
                  WHERE (sender_id = :user_id OR receiver_id = :user_id) AND deleted_at IS NULL 
                  ORDER BY timestamp DESC 
                  LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Auto-update 'read' status for receiver's messages
        foreach ($messages as $message) {
            if ($message['receiver_id'] == $userId && !$message['is_read']) {
                $this->updateReadStatus($message['id']);
            }
        }

        return ['success' => true, 'messages' => $messages, 'page' => $page, 'perPage' => $perPage];
    }

    public function getConversation($userId, $otherUserId, $page = 1, $perPage = 20) {
        if (!is_numeric($userId) || !is_numeric($otherUserId)) {
            return ['success' => false, 'errors' => ['User IDs must be numeric.']];
        }

        $offset = ($page - 1) * $perPage;
        $query = 'SELECT id, sender_id, receiver_id, message, timestamp, status, is_read 
                  FROM ' . $this->table . ' 
                  WHERE ((sender_id = :user_id AND receiver_id = :other_user_id) 
                      OR (sender_id = :other_user_id AND receiver_id = :user_id)) 
                      AND deleted_at IS NULL 
                  ORDER BY timestamp DESC 
                  LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':other_user_id', $otherUserId, PDO::PARAM_INT);
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Auto-update 'read' status for receiver's messages
        foreach ($messages as $message) {
            if ($message['receiver_id'] == $userId && !$message['is_read']) {
                $this->updateReadStatus($message['id']);
            }
        }

        return ['success' => true, 'messages' => $messages, 'page' => $page, 'perPage' => $perPage];
    }

    public function getMessageById($id, $userId = null) {
        $stmt = $this->conn->prepare('SELECT id, sender_id, receiver_id, message, timestamp, status, is_read 
                                      FROM ' . $this->table . ' WHERE id = :id AND deleted_at IS NULL');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($message && $userId && $message['receiver_id'] == $userId && !$message['is_read']) {
            $this->updateReadStatus($id);
            $message['is_read'] = 1;
            $message['status'] = 'read';
        }

        return $message ? ['success' => true, 'message' => $message] : ['success' => false, 'errors' => ['Message not found or deleted.']];
    }

    public function getAll($page = 1, $perPage = 20) {
        try {
            $offset = ($page - 1) * $perPage;
            $stmt = $this->conn->prepare('SELECT id, sender_id, receiver_id, message, timestamp, status, is_read 
                                          FROM ' . $this->table . ' WHERE deleted_at IS NULL 
                                          ORDER BY timestamp DESC LIMIT :perPage OFFSET :offset');
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'perPage' => $perPage];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    private function updateReadStatus($id) {
        $stmt = $this->conn->prepare('UPDATE ' . $this->table . ' SET status = :status, is_read = :is_read WHERE id = :id');
        $stmt->bindValue(':status', 'read');
        $stmt->bindValue(':is_read', 1, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function update($id, $data) {
        $messageExists = $this->getMessageById($id);
        if (!$messageExists['success']) return ['success' => false, 'errors' => ['Message not found or deleted.']];

        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['status', 'is_read'];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'status' && !in_array($value, ['sent', 'delivered', 'read'])) {
                    return ['success' => false, 'errors' => ['Invalid status value.']];
                }
                if ($key === 'is_read' && !in_array($value, [0, 1])) {
                    return ['success' => false, 'errors' => ['is_read must be 0 or 1.']];
                }
                $updates[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($updates)) return ['success' => false, 'errors' => ['No valid fields to update.']];

        $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Message updated successfully.'] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function delete($id) {
        $stmt = $this->conn->prepare('UPDATE ' . $this->table . ' SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount() > 0 
                ? ['success' => true, 'message' => 'Message soft-deleted successfully.'] 
                : ['success' => false, 'errors' => ['Message not found or already deleted.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }
}
?>