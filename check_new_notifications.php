<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$hasNewNotifications = false;
$count = 0;

// Get the last time messages were marked as visited
$lastVisitTime = $_SESSION['messages_visited_time'] ?? 0;

if ($lastVisitTime > 0) {
    $lastVisitDate = date('Y-m-d H:i:s', $lastVisitTime);
    
    // Count new friend requests since last visit
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM friends 
        WHERE friend_id = ? AND status = 'pending' AND created_at > ?
    ");
    $stmt->execute([$userId, $lastVisitDate]);
    $count += (int)$stmt->fetchColumn();
    
    // Count new messages since last visit
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM messages 
        WHERE receiver_id = ? AND created_at > ?
    ");
    $stmt->execute([$userId, $lastVisitDate]);
    $count += (int)$stmt->fetchColumn();
    
    // Count new group posts since last visit
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) 
        FROM posts p
        JOIN group_members gm ON p.group_id = gm.group_id
        WHERE gm.user_id = ? AND gm.status = 'approved'
        AND p.user_id != ? AND p.created_at > ?
    ");
    $stmt->execute([$userId, $userId, $lastVisitDate]);
    $count += (int)$stmt->fetchColumn();
    
    $hasNewNotifications = ($count > 0);
    
    // If there are new notifications, reset the visited flag
    if ($hasNewNotifications) {
        unset($_SESSION['messages_visited']);
        unset($_SESSION['messages_visited_time']);
    }
} else {
    // If never visited, there are "new" notifications
    $hasNewNotifications = true;
    
    // Count all current notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE friend_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $count += (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    $count += (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) 
        FROM posts p
        JOIN group_members gm ON p.group_id = gm.group_id
        WHERE gm.user_id = ? AND gm.status = 'approved'
        AND p.user_id != ? AND p.created_at > NOW() - INTERVAL '1 day'
    ");
    $stmt->execute([$userId, $userId]);
    $count += (int)$stmt->fetchColumn();
}

echo json_encode([
    'success' => true,
    'has_new_notifications' => $hasNewNotifications,
    'count' => $count,
    'last_visit_time' => $lastVisitTime
]);
?>