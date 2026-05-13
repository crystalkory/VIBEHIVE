<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread_count' => 0]);
    exit;
}
$userId = $_SESSION['user_id'];

require_once "config.php";

// Make sure to update 'receiver_id' and 'read_at' to your actual column names
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = :user_id AND read_at IS NULL");
$stmt->execute(['user_id' => $userId]);
$unreadCount = (int) $stmt->fetchColumn();

echo json_encode(['unread_count' => $unreadCount]);
