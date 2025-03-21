<?php
require_once '../model/notification.php';
require_once '../config/JWTHandler.php';

class NotificationController {
    private $notificationModel;

    public function __construct() {
        $this->notificationModel = new Notification();
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

        $this->notificationModel->user_id = $data['user_id'] ?? '';
        $this->notificationModel->title = $data['title'] ?? '';
        $this->notificationModel->message = $data['message'] ?? '';
        $this->notificationModel->is_read = $data['is_read'] ?? 0;

        if ($user['roles'] !== 'admin' && $this->notificationModel->user_id != $user['sub']) {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Only admins can create notifications for other users.']]
            ];
        }

        $result = $this->notificationModel->create();
        if ($result['success']) {
            $notificationData = [
                'id' => $result['id'],
                'user_id' => $this->notificationModel->user_id,
                'title' => $this->notificationModel->title,
                'message' => $this->notificationModel->message,
                'is_read' => $this->notificationModel->is_read,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $this->broadcastNotification($notificationData);
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

    public function getAll($page = null, $perPage = null) {
        $user = $this->authenticateAPI();
        if ($user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['Access denied. Admin only.']]
            ];
        }

        $result = $this->notificationModel->getAll($page, $perPage);
        return $result['success'] 
            ? ['status' => 200, 'body' => $result] 
            : ['status' => 500, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function getUserNotifications($userId, $page = null, $perPage = null) {
        $user = $this->authenticateAPI();
        if (empty($userId)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['User ID is required.']]
            ];
        }
        if ($userId != $user['sub'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only view your own notifications unless you are an admin.']]
            ];
        }

        $result = $this->notificationModel->getUserNotifications($userId, $page, $perPage);
        return $result['success'] 
            ? ['status' => 200, 'body' => $result] 
            : ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function getById($id) {
        $user = $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Notification ID is required.']]
            ];
        }

        $result = $this->notificationModel->getNotificationById($id);
        if (!$result['success']) {
            return ['status' => 404, 'body' => ['success' => false, 'errors' => $result['errors']]];
        }
        if ($result['notification']['user_id'] != $user['sub'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only view your own notifications unless you are an admin.']]
            ];
        }

        return ['status' => 200, 'body' => ['success' => true, 'notification' => $result['notification']]];
    }

    public function markAsRead($id) {
        $user = $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Notification ID is required.']]
            ];
        }

        $notification = $this->notificationModel->getNotificationById($id);
        if (!$notification['success']) {
            return ['status' => 404, 'body' => ['success' => false, 'errors' => $notification['errors']]];
        }
        if ($notification['notification']['user_id'] != $user['sub'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only mark your own notifications as read unless you are an admin.']]
            ];
        }

        $result = $this->notificationModel->markAsRead($id);
        if ($result['success']) {
            $notificationData = [
                'id' => $id,
                'user_id' => $notification['notification']['user_id'],
                'title' => $notification['notification']['title'],
                'message' => $notification['notification']['message'],
                'is_read' => 1,
                'created_at' => $notification['notification']['created_at']
            ];
            $this->broadcastNotification($notificationData);
            return ['status' => 200, 'body' => ['success' => true, 'message' => $result['message']]];
        }
        return ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    private function broadcastNotification($notificationData) {
        try {
            $client = new \WebSocket\Client("ws://localhost:8081");
            $payload = [
                'user_id' => $notificationData['user_id'],
                'message' => $notificationData['message'],
                'title' => $notificationData['title'] ?? '',
                'id' => $notificationData['id'] ?? null,
                'is_read' => $notificationData['is_read'] ?? 0,
                'created_at' => $notificationData['created_at'] ?? null
            ];
            $client->text(json_encode($payload));
            $client->close();
            error_log("Notification broadcasted: " . json_encode($payload));
        } catch (\WebSocket\Exception $e) {
            error_log('WebSocket Error: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('General Error: ' . $e->getMessage());
        }
    }

    public function update($id, array $data) {
        $user = $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Notification ID is required.']]
            ];
        }

        $notification = $this->notificationModel->getNotificationById($id);
        if (!$notification['success']) {
            return ['status' => 404, 'body' => ['success' => false, 'errors' => $notification['errors']]];
        }
        if ($notification['notification']['user_id'] != $user['sub'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only update your own notifications unless you are an admin.']]
            ];
        }

        $result = $this->notificationModel->update($id, $data);
        if ($result['success']) {
            $updatedNotification = $this->notificationModel->getNotificationById($id)['notification'];
            $notificationData = [
                'id' => $id,
                'user_id' => $updatedNotification['user_id'],
                'title' => $updatedNotification['title'],
                'message' => $updatedNotification['message'],
                'is_read' => $updatedNotification['is_read'],
                'created_at' => $updatedNotification['created_at']
            ];
            $this->broadcastNotification($notificationData);
            return ['status' => 200, 'body' => ['success' => true, 'message' => $result['message']]];
        }
        return ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }

    public function delete($id) {
        $user = $this->authenticateAPI();
        if (empty($id)) {
            return [
                'status' => 400,
                'body' => ['success' => false, 'errors' => ['Notification ID is required.']]
            ];
        }

        $notification = $this->notificationModel->getNotificationById($id);
        if (!$notification['success']) {
            return ['status' => 404, 'body' => ['success' => false, 'errors' => $notification['errors']]];
        }
        if ($notification['notification']['user_id'] != $user['sub'] && $user['roles'] !== 'admin') {
            return [
                'status' => 403,
                'body' => ['success' => false, 'errors' => ['You can only delete your own notifications unless you are an admin.']]
            ];
        }

        $result = $this->notificationModel->delete($id);
        return $result['success'] 
            ? ['status' => 200, 'body' => ['success' => true, 'message' => $result['message']]] 
            : ['status' => 400, 'body' => ['success' => false, 'errors' => $result['errors']]];
    }
}
?>