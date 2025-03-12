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

        // Validate required fields
        if (!isset($data['receiver_id']) || !isset($data['sender_id'])) {
            error_log("Missing receiver_id or sender_id in message: $msg");
            return;
        }

        // Broadcast to the specific receiver
        foreach ($this->clients as $client) {
            if (isset($client->userId) && $client->userId == $data['receiver_id']) {
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