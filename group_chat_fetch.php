<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['user_id'];

$host = 'localhost'; $port = '5432'; $dbname = 'fbclone'; $dbUser = 'postgres'; $dbPass = 'Gi12,br12';
try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed: ' . $e->getMessage()]);
    exit;
}

// Validate input
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($groupId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
    exit;
}

// Check if user is approved member
$stmt = $pdo->prepare("SELECT status FROM group_members WHERE user_id=:user_id AND group_id=:group_id");
$stmt->execute(['user_id' => $userId, 'group_id' => $groupId]);
$status = $stmt->fetchColumn();

if ($status !== 'approved') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Fetch messages
try {
    if ($lastId > 0) {
        // Fetch only new messages
        $stmt = $pdo->prepare("
            SELECT 
                gm.id, 
                gm.user_id, 
                u.username, 
                gm.message, 
                gm.created_at, 
                gm.reply_to, 
                gm.attachment_url, 
                gm.attachment_name, 
                gm.attachment_size,
                gm.read_at
            FROM group_messages gm
            JOIN users u ON gm.user_id = u.id
            WHERE gm.group_id = :group_id AND gm.id > :last_id
            ORDER BY gm.created_at ASC
        ");
        $stmt->execute(['group_id' => $groupId, 'last_id' => $lastId]);
    } else {
        // Fetch all messages (initial load)
        $stmt = $pdo->prepare("
            SELECT 
                gm.id, 
                gm.user_id, 
                u.username, 
                gm.message, 
                gm.created_at, 
                gm.reply_to, 
                gm.attachment_url, 
                gm.attachment_name, 
                gm.attachment_size,
                gm.read_at
            FROM group_messages gm
            JOIN users u ON gm.user_id = u.id
            WHERE gm.group_id = :group_id
            ORDER BY gm.created_at ASC
            LIMIT 50
        ");
        $stmt->execute(['group_id' => $groupId]);
    }
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark messages as read if they're from other users
    if (!empty($messages)) {
        $messageIds = array_column($messages, 'id');
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        
        // Update read status for messages not sent by current user
        $stmt = $pdo->prepare("
            UPDATE group_messages 
            SET read_at = NOW() 
            WHERE id IN ($placeholders) 
            AND user_id != ? 
            AND read_at IS NULL
        ");
        
        $params = array_merge($messageIds, [$userId]);
        $stmt->execute($params);
    }
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch messages: ' . $e->getMessage()]);
}
?>