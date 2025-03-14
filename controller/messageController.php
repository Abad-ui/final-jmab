<?php
require_once '../model/message.php';
require_once '../config/JWTHandler.php';

class MessageController {
    private $messageModel;

    public function __construct() {
        $this->messageModel = new Message();
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

    public function create(array $data) {
        $user = $this->authenticateAPI();

        $this->messageModel->sender_id = $data['sender_id'] ?? '';
        $this->messageModel->receiver_id = $data['receiver_id'] ?? '';
        $this->messageModel->message = $data['message'] ?? '';
        $this->messageModel->status = $data['status'] ?? 'sent'; // Note: Model overrides to 'delivered'
        $this->messageModel->is_read = $data['is_read'] ?? 0;

        if ($this->messageModel->sender_id != $user['sub']) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only send messages as yourself.']]
            ];
        }

        $result = $this->messageModel->create();
        if ($result['success']) {
            $messageData = [
                'id' => $result['id'],
                'sender_id' => $this->messageModel->sender_id,
                'receiver_id' => $this->messageModel->receiver_id,
                'message' => $this->messageModel->message,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'delivered',
                'is_read' => $this->messageModel->is_read
            ];
            $this->broadcastMessage($messageData);
            return [
                'status' => 201,
                'body' => ['success' => true, 'message' => $result['message'], 'id' => $result['id']]
            ];
        }
        return [
            'status' => 400,
            'body' => ['success' => false, 'errors' => $result['errors']]
        ];
    }

    public function getAll($page = 1, $perPage = 20) {
        $user = $this->authenticateAPI();
        if ($user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Access denied. Admin only.']]
            ];
        }

        $result = $this->messageModel->getAll($page, $perPage);
        return $result['success'] 
            ? [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'messages' => $result['messages'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage']
                ]
            ] 
            : ['status' => 500, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function getMessages($userId, $page = 1, $perPage = 20) {
        $this->authenticateAPI();
        if (empty($userId)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['User ID is required.']]
            ];
        }

        error_log("getMessages - userId: '$userId' (Type: " . gettype($userId) . ")");
        $result = $this->messageModel->getMessages($userId, $page, $perPage);
        return $result['success'] 
            ? [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'messages' => $result['messages'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage']
                ]
            ] 
            : ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function getConversation($userId, $otherUserId, $page = 1, $perPage = 20) {
        $user = $this->authenticateAPI();
        if (empty($userId) || empty($otherUserId)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Both User IDs are required.']]
            ];
        }
        if ($userId != $user['sub'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only view your own conversations unless you are an admin.']]
            ];
        }

        $result = $this->messageModel->getConversation($userId, $otherUserId, $page, $perPage);
        return $result['success'] 
            ? [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'messages' => $result['messages'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage']
                ]
            ] 
            : ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function getById($id) {
        $user = $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Message ID is required.']]
            ];
        }

        $result = $this->messageModel->getMessageById($id, $user['sub']);
        return $result['success'] 
            ? ['status' => 200, 'body' => ['success' => true, 'message' => $result['message']]] 
            : ['status' => 404, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    private function broadcastMessage($messageData) {
        try {
            $client = new \WebSocket\Client("ws://localhost:8080");
            $client->text(json_encode($messageData));
            $client->close();
            error_log("Message broadcasted: " . json_encode($messageData));
        } catch (\WebSocket\Exception $e) {
            error_log('WebSocket Error: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('General Error: ' . $e->getMessage());
        }
    }

    public function update($id, array $data) {
        $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Message ID is required.']]
            ];
        }

        $result = $this->messageModel->update($id, $data);
        return $result['success'] 
            ? ['status' => 200, 'body' => ['success' => true, 'message' => $result['message']]] 
            : ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function delete($id) {
        $user = $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Message ID is required.']]
            ];
        }

        // Access control check
        $message = $this->messageModel->getMessageById($id)['message'];
        if ($message && $user['sub'] != $message['sender_id'] && $user['sub'] != $message['receiver_id'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only delete your own messages.']]
            ];
        }

        $result = $this->messageModel->delete($id);
        return $result['success'] 
            ? ['status' => 200, 'body' => ['success' => true, 'message' => 'Message soft-deleted successfully.']] 
            : ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }
}
?>