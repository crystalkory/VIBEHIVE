<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$showBadge = true;

// Check if user has visited messages page in this session
if (isset($_SESSION['messages_visited']) && $_SESSION['messages_visited']) {
    $showBadge = false;
}

echo json_encode([
    'success' => true,
    'show_badge' => $showBadge,
    'messages_visited' => $_SESSION['messages_visited'] ?? false
]);
?>