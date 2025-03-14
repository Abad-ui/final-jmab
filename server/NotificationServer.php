<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class NotificationServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->userConnections = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        error_log("New notification connection! ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if ($data === null) {
            error_log("Invalid JSON received from {$from->resourceId}: $msg");
            return;
        }

        // Register user ID
        if (isset($data['userId'])) {
            $from->userId = $data['userId'];
            $this->userConnections[$data['userId']] = $from;
            error_log("User ID {$data['userId']} connected to notification server as {$from->resourceId}");
            return;
        }

        // Handle notification broadcast
        if (isset($data['user_id']) && isset($data['message'])) {
            $targetUserId = $data['user_id'];
            if (isset($this->userConnections[$targetUserId])) {
                $this->userConnections[$targetUserId]->send(json_encode($data));
                error_log("Sent notification to User ID: {$targetUserId}");
            } else {
                error_log("User ID {$targetUserId} is offline for notifications.");
            }
        } else {
            error_log("Invalid notification format from {$from->resourceId}");
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->userId)) {
            unset($this->userConnections[$conn->userId]);
            error_log("User ID {$conn->userId} disconnected from notifications.");
        }
        error_log("Notification connection closed! ({$conn->resourceId})");
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        error_log("Notification server error: {$e->getMessage()}");
        $conn->close();
    }
}

$server = \Ratchet\Server\IoServer::factory(
    new \Ratchet\Http\HttpServer(
        new \Ratchet\WebSocket\WsServer(
            new NotificationServer()
        )
    ),
    8081 // Different port from ChatServer
);
error_log("Notification WebSocket server started on ws://localhost:8081");
$server->run();