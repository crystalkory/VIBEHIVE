<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Mark messages as visited in this session
$_SESSION['messages_visited'] = true;
$_SESSION['messages_visited_time'] = time();

echo json_encode([
    'success' => true,
    'message' => 'Messages marked as visited'
]);
?>