<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class ChatServer implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
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

        error_log("Received message: $msg");

        // Set user ID for the connection
        if (isset($data['userId'])) {
            $from->userId = $data['userId'];
            error_log("User ID set for {$from->resourceId}: {$data['userId']}");
            return;
        }

        // Handle different types of messages
        $targetUserId = null;
        if (isset($data['receiver_id'])) {
            // This is a message
            $targetUserId = $data['receiver_id'];
        } elseif (isset($data['user_id'])) {
            // This is a notification
            $targetUserId = $data['user_id'];
        } else {
            error_log("Missing receiver_id or user_id in message: $msg");
            return;
        }

        // Broadcast to the specific target user
        foreach ($this->clients as $client) {
            if (isset($client->userId) && $client->userId == $targetUserId) {
                $client->send(json_encode($data));
                error_log("Sent to {$client->resourceId} (User ID: {$client->userId})");
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
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