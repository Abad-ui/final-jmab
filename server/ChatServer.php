<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->userConnections = []; // Array to hold multiple connections per user
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        error_log("New connection! ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if ($data === null) {
            error_log("Invalid JSON received from {$from->resourceId}: $msg");
            return;
        }
    
        // Assign userId and store connection
        if (isset($data['userId'])) {
            $userId = $data['userId'];
            $from->userId = $userId;
            if (!isset($this->userConnections[$userId])) {
                $this->userConnections[$userId] = new \SplObjectStorage();
            }
            $this->userConnections[$userId]->attach($from);
            error_log("User ID {$userId} connected as {$from->resourceId}. Total connections for user: " . $this->userConnections[$userId]->count());
            return;
        }
    
        // Handle chat messages
        if (isset($data['sender_id']) && isset($data['receiver_id']) && isset($data['message'])) {
            $targetUserId = $data['receiver_id'];
            if (isset($this->userConnections[$targetUserId])) {
                foreach ($this->userConnections[$targetUserId] as $targetConn) {
                    $targetConn->send(json_encode($data));
                    error_log("Sent message to User ID: {$targetUserId} on connection {$targetConn->resourceId}");
                }
            } else {
                error_log("User ID {$targetUserId} is offline.");
            }
    
            // Echo back to sender for confirmation (to all sender's connections)
            if (isset($this->userConnections[$data['sender_id']])) {
                foreach ($this->userConnections[$data['sender_id']] as $senderConn) {
                    $senderConn->send(json_encode($data));
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            if (isset($this->userConnections[$userId])) {
                $this->userConnections[$userId]->detach($conn);
                error_log("User ID {$userId} disconnected from {$conn->resourceId}. Remaining connections: " . $this->userConnections[$userId]->count());

                // Clean up if no connections remain
                if ($this->userConnections[$userId]->count() === 0) {
                    unset($this->userConnections[$userId]);
                    error_log("All connections for User ID {$userId} closed.");
                }
            }
        }
        error_log("Connection closed! ({$conn->resourceId})");
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        error_log("An error occurred: {$e->getMessage()}");
        $conn->close();
    }
}

$server = \Ratchet\Server\IoServer::factory(
    new \Ratchet\Http\HttpServer(
        new \Ratchet\WebSocket\WsServer(
            new ChatServer()
        )
    ),
    8080
);
error_log("WebSocket server started on ws://localhost:8080");
$server->run();