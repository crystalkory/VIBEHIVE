<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$result = [
    'success' => true,
    'request_count' => 0,
    'group_count' => 0,
    'message_count' => 0,
    'invite_count' => 0
];

try {
    // 1. Friend requests count (only show if not visited)
    if (!isset($_SESSION['friend_requests_visited']) || !$_SESSION['friend_requests_visited']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE friend_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $result['request_count'] = (int)$stmt->fetchColumn();
    }
    
    // 2. Group notifications count (only show if not visited)
    if (!isset($_SESSION['group_notifications_visited']) || !$_SESSION['group_notifications_visited']) {
        // Add your group notification logic here
        // Example: SELECT COUNT(*) FROM group_join_requests WHERE group_admin_id = ? AND status = 'pending'
        $result['group_count'] = 0;
    }
    
    // 3. Unread messages count (only show if not visited)
    if (!isset($_SESSION['messages_visited']) || !$_SESSION['messages_visited']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL");
        $stmt->execute([$userId]);
        $result['message_count'] = (int)$stmt->fetchColumn();
    }
    
    // 4. Invites count (only show if not visited)
    if (!isset($_SESSION['invites_visited']) || !$_SESSION['invites_visited']) {
        // Add your invite logic here
        // Example: SELECT COUNT(*) FROM event_invites WHERE user_id = ? AND status = 'pending'
        $result['invite_count'] = 0;
    }
} catch (Exception $e) {
    // Continue with partial results
}

echo json_encode($result);
?>