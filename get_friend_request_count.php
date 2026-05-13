<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

// Get friend request count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE friend_id = ? AND status = 'pending'");
$stmt->execute([$userId]);
$count = (int)$stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'count' => $count,
    'timestamp' => time()
]);
?>