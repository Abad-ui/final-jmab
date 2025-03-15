<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class NotificationServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->userConnections = []; // Array to hold multiple connections per user
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

        // Register user ID and store connection
        if (isset($data['userId'])) {
            $userId = $data['userId'];
            $from->userId = $userId;

            // Initialize array for userId if it doesn't exist
            if (!isset($this->userConnections[$userId])) {
                $this->userConnections[$userId] = new \SplObjectStorage();
            }

            // Add the connection to the user's connection pool
            $this->userConnections[$userId]->attach($from);
            error_log("User ID {$userId} connected to notification server as {$from->resourceId}. Total connections for user: " . $this->userConnections[$userId]->count());
            return;
        }

        // Handle notification broadcast
        if (isset($data['user_id']) && isset($data['message'])) {
            $targetUserId = $data['user_id'];

            // Send notification to all connections of the target user if online
            if (isset($this->userConnections[$targetUserId])) {
                foreach ($this->userConnections[$targetUserId] as $targetConn) {
                    $targetConn->send(json_encode($data));
                    error_log("Sent notification to User ID: {$targetUserId} on connection {$targetConn->resourceId}");
                }
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
            $userId = $conn->userId;
            if (isset($this->userConnections[$userId])) {
                $this->userConnections[$userId]->detach($conn);
                error_log("User ID {$userId} disconnected from notifications on {$conn->resourceId}. Remaining connections: " . $this->userConnections[$userId]->count());

                // Clean up if no connections remain
                if ($this->userConnections[$userId]->count() === 0) {
                    unset($this->userConnections[$userId]);
                    error_log("All notification connections for User ID {$userId} closed.");
                }
            }
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