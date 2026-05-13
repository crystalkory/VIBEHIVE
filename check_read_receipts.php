<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once "config.php";


// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['message_ids']) || !isset($data['friend_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$messageIds = $data['message_ids'];
$friendId = $data['friend_id'];
$currentUserId = $_SESSION['user_id'];

// Convert message IDs to a comma-separated string for the SQL query
$messageIdsStr = implode(',', array_map('intval', $messageIds));

// Check which messages have been read by the friend
$stmt = $pdo->prepare("
    SELECT id FROM messages 
    WHERE id IN ($messageIdsStr) 
    AND sender_id = :current_user_id 
    AND receiver_id = :friend_id 
    AND read_at IS NOT NULL
");
$stmt->execute([
    'current_user_id' => $currentUserId,
    'friend_id' => $friendId
]);

$readMessages = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'read_messages' => $readMessages
]);