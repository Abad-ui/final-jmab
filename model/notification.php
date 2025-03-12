<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once '../config/config.php';

class Notification {
    public $conn;
    private $table = 'notifications';

    public $id, $user_id, $title, $message, $is_read, $created_at;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    private function validateInput() {
        $errors = [];
        if (empty($this->user_id)) $errors[] = 'User ID is required.';
        if (empty($this->title)) $errors[] = 'Title is required.';
        if (empty($this->message)) $errors[] = 'Message content is required.';
        if (!is_numeric($this->user_id)) $errors[] = 'User ID must be numeric.';
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

        if (!$this->isUserExists($this->user_id)) {
            return ['success' => false, 'errors' => ['User does not exist.']];
        }

        $query = 'INSERT INTO ' . $this->table . ' (user_id, title, message, is_read, created_at) 
                  VALUES (:user_id, :title, :message, :is_read, NOW())';
        $stmt = $this->conn->prepare($query);
        $this->is_read = 0; // Default to unread

        $stmt->bindParam(':user_id', $this->user_id, PDO::PARAM_INT);
        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':message', $this->message);
        $stmt->bindParam(':is_read', $this->is_read, PDO::PARAM_INT);

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Notification created successfully.', 'id' => $this->conn->lastInsertId()] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function getUserNotifications($userId, $page = 1, $perPage = 20) {
        if (!is_numeric($userId)) return ['success' => false, 'errors' => ['User ID must be numeric.']];

        $offset = ($page - 1) * $perPage;
        $query = 'SELECT id, user_id, title, message, is_read, created_at 
                  FROM ' . $this->table . ' 
                  WHERE user_id = :user_id 
                  ORDER BY created_at DESC 
                  LIMIT :perPage OFFSET :offset';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['success' => true, 'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'perPage' => $perPage];
    }

    public function getNotificationById($id) {
        $stmt = $this->conn->prepare('SELECT id, user_id, title, message, is_read, created_at 
                                      FROM ' . $this->table . ' WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);

        return $notification ? ['success' => true, 'notification' => $notification] : ['success' => false, 'errors' => ['Notification not found.']];
    }

    public function getAll($page = 1, $perPage = 20) {
        try {
            $offset = ($page - 1) * $perPage;
            $stmt = $this->conn->prepare('SELECT id, user_id, title, message, is_read, created_at 
                                          FROM ' . $this->table . ' 
                                          ORDER BY created_at DESC 
                                          LIMIT :perPage OFFSET :offset');
            $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => true, 'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'perPage' => $perPage];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function markAsRead($id) {
        $stmt = $this->conn->prepare('UPDATE ' . $this->table . ' SET is_read = 1 WHERE id = :id AND is_read = 0');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        try {
            $stmt->execute();
            return $stmt->rowCount() > 0 
                ? ['success' => true, 'message' => 'Notification marked as read.'] 
                : ['success' => false, 'errors' => ['Notification not found or already read.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function update($id, $data) {
        $notificationExists = $this->getNotificationById($id);
        if (!$notificationExists['success']) return ['success' => false, 'errors' => ['Notification not found.']];

        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['title', 'message', 'is_read'];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'is_read' && !in_array($value, [0, 1])) {
                    return ['success' => false, 'errors' => ['is_read must be 0 or 1.']];
                }
                $updates[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($updates)) return ['success' => false, 'errors' => ['No valid fields to update.']];

        $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            return $stmt->execute() 
                ? ['success' => true, 'message' => 'Notification updated successfully.'] 
                : ['success' => false, 'errors' => ['Unknown error occurred.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }

    public function delete($id) {
        $stmt = $this->conn->prepare('DELETE FROM ' . $this->table . ' WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount() > 0 
                ? ['success' => true, 'message' => 'Notification deleted successfully.'] 
                : ['success' => false, 'errors' => ['Notification not found.']];
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Something went wrong. Please try again.']];
        }
    }
}
?>