<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class ChatAndNotificationServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->userConnections = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        error_log("New connection! ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if ($data === null) {
            error_log("Invalid JSON from {$from->resourceId}: $msg");
            return;
        }

        // Register user ID
        if (isset($data['userId'])) {
            $userId = $data['userId'];
            $from->userId = $userId;
            if (!isset($this->userConnections[$userId])) {
                $this->userConnections[$userId] = new \SplObjectStorage();
            }
            $this->userConnections[$userId]->attach($from);
            error_log("User ID {$userId} connected as {$from->resourceId}");
            return;
        }

        // Handle chat messages
        if (isset($data['sender_id']) && isset($data['receiver_id']) && isset($data['message'])) {
            $targetUserId = $data['receiver_id'];
            if (isset($this->userConnections[$targetUserId])) {
                foreach ($this->userConnections[$targetUserId] as $targetConn) {
                    $targetConn->send(json_encode($data));
                    error_log("Sent message to User ID: {$targetUserId} on {$targetConn->resourceId}");
                }
            }
            // Echo back to sender
            if (isset($this->userConnections[$data['sender_id']])) {
                foreach ($this->userConnections[$data['sender_id']] as $senderConn) {
                    $senderConn->send(json_encode($data));
                }
            }
        }
        // Handle notifications
        elseif (isset($data['user_id']) && isset($data['message'])) {
            $targetUserId = $data['user_id'];
            if (isset($this->userConnections[$targetUserId])) {
                foreach ($this->userConnections[$targetUserId] as $targetConn) {
                    $targetConn->send(json_encode($data));
                    error_log("Sent notification to User ID: {$targetUserId} on {$targetConn->resourceId}");
                }
            } else {
                error_log("User ID {$targetUserId} is offline");
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            if (isset($this->userConnections[$userId])) {
                $this->userConnections[$userId]->detach($conn);
                if ($this->userConnections[$userId]->count() === 0) {
                    unset($this->userConnections[$userId]);
                }
            }
        }
        error_log("Connection closed! ({$conn->resourceId})");
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        error_log("Error: {$e->getMessage()}");
        $conn->close();
    }
}

$server = \Ratchet\Server\IoServer::factory(
    new \Ratchet\Http\HttpServer(
        new \Ratchet\WebSocket\WsServer(
            new ChatAndNotificationServer()
        )
    ),
    8080
);
error_log("Chat & Notification WebSocket server started on ws://0.0.0.0:8080");
$server->run();