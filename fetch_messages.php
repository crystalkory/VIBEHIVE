<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once "config.php";


$currentUserId = $_SESSION['user_id'];
$friendId = filter_input(INPUT_GET, 'friend_id', FILTER_VALIDATE_INT);

if (!$friendId) {
    echo json_encode(['success' => false, 'message' => 'Friend ID required']);
    exit;
}

// Mark messages from friend to user as read (update read_at)
$updateStmt = $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL");
$updateStmt->execute([$friendId, $currentUserId]);

// Fetch messages between user and friend with reply information
$stmt = $pdo->prepare("
    SELECT 
        m.id,
        m.sender_id,
        m.message,
        m.created_at,
        m.read_at,
        m.reply_to_id,
        m.attachment_url,
        m.attachment_name,
        m.attachment_size,
        rm.message AS replied_message,
        rm.sender_id AS replied_message_sender_id,
        ru.username AS replied_message_sender_username,
        u.username AS sender_username
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    LEFT JOIN messages rm ON m.reply_to_id = rm.id
    LEFT JOIN users ru ON rm.sender_id = ru.id
    WHERE (m.sender_id = :current AND m.receiver_id = :friend) 
       OR (m.sender_id = :friend AND m.receiver_id = :current)
    ORDER BY m.created_at ASC
");
$stmt->execute(['current' => $currentUserId, 'friend' => $friendId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'messages' => $messages]);