<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class ChatServer implements MessageComponentInterface {
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
            error_log("Invalid JSON received from {$from->resourceId}: $msg");
            return;
        }

        // Assign userId when received
        if (isset($data['userId'])) {
            $from->userId = $data['userId'];
            $this->userConnections[$data['userId']] = $from;
            error_log("User ID {$data['userId']} connected as {$from->resourceId}");
            return;
        }

        // Handle chat messages
        if (isset($data['sender_id']) && isset($data['receiver_id']) && isset($data['message'])) {
            $targetUserId = $data['receiver_id'];

            // Send message to the receiver if online
            if (isset($this->userConnections[$targetUserId])) {
                $this->userConnections[$targetUserId]->send(json_encode($data));
                error_log("Sent message to User ID: {$targetUserId}");
            } else {
                error_log("User ID {$targetUserId} is offline.");
            }

            // Echo back to sender for confirmation
            $from->send(json_encode($data));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->userId)) {
            unset($this->userConnections[$conn->userId]);
            error_log("User ID {$conn->userId} disconnected.");
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