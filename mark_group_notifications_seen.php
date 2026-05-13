<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Mark group notifications as seen in session
$_SESSION['group_notifications_visited'] = true;
$_SESSION['group_notifications_visited_time'] = time();

echo json_encode([
    'success' => true,
    'message' => 'Group notifications marked as seen'
]);
?>