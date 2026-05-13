<?php
require __DIR__ . '/../vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class Chat implements MessageComponentInterface {
    protected $clients;
    protected $users;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data) {
            return;
        }

        switch ($data['type']) {
            case 'register':
                $this->users[$data['user_id']] = $from;
                $from->send(json_encode([
                    'type' => 'status',
                    'message' => 'Registered successfully'
                ]));
                break;

            case 'message':
                // Save message to database
                $messageId = $this->saveMessageToDatabase($data);
                
                // Send to recipient if online
                if (isset($this->users[$data['friend_id']])) {
                    $recipient = $this->users[$data['friend_id']];
                    $recipient->send(json_encode([
                        'type' => 'message',
                        'message' => array_merge($data, ['id' => $messageId])
                    ]));
                }
                
                // Send confirmation to sender
                $from->send(json_encode([
                    'type' => 'message_sent',
                    'message_id' => $messageId
                ]));
                break;

            case 'typing':
                // Forward typing indicator to recipient
                if (isset($this->users[$data['friend_id']])) {
                    $recipient = $this->users[$data['friend_id']];
                    $recipient->send(json_encode([
                        'type' => 'typing',
                        'user_id' => $data['user_id'],
                        'is_typing' => $data['is_typing']
                    ]));
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        
        // Remove user from users array
        $userId = array_search($conn, $this->users, true);
        if ($userId !== false) {
            unset($this->users[$userId]);
        }
        
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    protected function saveMessageToDatabase($data) {
        // You'll need to implement your database connection here
        // This is a simplified example - use your actual database credentials
        
        $host = 'localhost';
        $dbname = 'fbclone';
        $user = 'postgres';
        $password = 'Gi12,br12';
        
        try {
            $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            if (!empty($data['attachments'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO messages (sender_id, receiver_id, message, reply_to_id, attachment_url, attachment_name, attachment_size, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    RETURNING id
                ");
                $stmt->execute([
                    $data['user_id'],
                    $data['friend_id'],
                    $data['message'],
                    $data['reply_to'] ?? null,
                    '/uploads/placeholder.jpg', // Placeholder - implement actual file upload
                    'file.jpg', // Placeholder
                    1024 // Placeholder
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO messages (sender_id, receiver_id, message, reply_to_id, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                    RETURNING id
                ");
                $stmt->execute([
                    $data['user_id'],
                    $data['friend_id'],
                    $data['message'],
                    $data['reply_to'] ?? null
                ]);
            }
            
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return null;
        }
    }
}

// Run the server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat()
        )
    ),
    8080
);

echo "WebSocket server running on port 8080\n";
$server->run();