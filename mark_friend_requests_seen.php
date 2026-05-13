<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];

// Mark friend requests as seen in session
$_SESSION['friend_requests_visited'] = true;
$_SESSION['friend_requests_visited_time'] = time();

// If you add a 'seen' column to your friends table, uncomment this:
/*
$stmt = $pdo->prepare("UPDATE friends SET seen = true WHERE friend_id = ? AND status = 'pending'");
$stmt->execute([$userId]);
*/

echo json_encode([
    'success' => true,
    'message' => 'Friend requests marked as seen'
]);
?>