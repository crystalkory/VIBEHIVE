<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\App;

require __DIR__ . '/../vendor/autoload.php';

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $pdo;
    protected $connectionsByUserId;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->connectionsByUserId = [];

        $host = 'localhost';
        $dbname = 'fbclone';
        $user = 'postgres';
        $password = 'Gi12,br12';
        $this->pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        echo "Chat server started\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->send(json_encode(['type' => 'request_auth', 'message' => 'Please send your userId']));
        echo "New connection (ID {$conn->resourceId}), awaiting auth\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "Received message from connection {$from->resourceId}: $msg\n";

        $data = json_decode($msg, true);
        if (!$data) {
            $from->send(json_encode(['type'=>'error','message'=>'Invalid JSON']));
            return;
        }

        if (isset($data['type']) && $data['type'] === 'auth' && isset($data['userId'])) {
            $userId = (int)$data['userId'];
            $this->connectionsByUserId[$userId] = $from;
            $from->userId = $userId;
            $from->send(json_encode(['type'=>'auth_success', 'message'=>'Authentication successful']));
            echo "Connection {$from->resourceId} authenticated as user $userId\n";
            return;
        }

        if (!isset($from->userId)) {
            $from->send(json_encode(['type'=>'error', 'message'=>'Unauthorized']));
            return;
        }

        if ($data['type'] === 'chat_message') {
            $senderId = (int)$from->userId;
            $receiverId = (int)$data['receiverId'];
            $message = trim($data['message'] ?? '');
            $mediaUrls = isset($data['mediaUrls']) && is_array($data['mediaUrls']) ? $data['mediaUrls'] : [];

            $stmt = $this->pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, media_urls, created_at) VALUES (?, ?, ?, ?, NOW())");
            $mediaUrlsStr = $mediaUrls ? implode(',', $mediaUrls) : null;
            $stmt->execute([$senderId, $receiverId, $message, $mediaUrlsStr]);

            $messageId = $this->pdo->lastInsertId();

            $payload = json_encode([
                'type' => 'chat_message',
                'messageId' => $messageId,
                'senderId' => $senderId,
                'receiverId' => $receiverId,
                'message' => $message,
                'mediaUrls' => $mediaUrls,
                'timestamp' => time() * 1000
            ]);

            foreach ([$senderId, $receiverId] as $uid) {
                if (isset($this->connectionsByUserId[$uid])) {
                    $conn = $this->connectionsByUserId[$uid];
                    $conn->send($payload);
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->userId)) {
            unset($this->connectionsByUserId[$conn->userId]);
        }
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

$app = new App('localhost', 8080);
$app->route('/chat', new ChatServer, ['*']);
$app->run();
