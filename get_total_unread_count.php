<?php
// get_total_unread_count.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$totalUnread = 0;

try {
    // Friend requests
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE friend_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $totalUnread += (int)$stmt->fetchColumn();
    
    // Unread messages
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    $totalUnread += (int)$stmt->fetchColumn();
    
    // Recent group posts
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) 
        FROM posts p
        JOIN group_members gm ON p.group_id = gm.group_id
        WHERE gm.user_id = ? AND gm.status = 'approved'
        AND p.user_id != ? AND p.created_at > NOW() - INTERVAL '1 day'
    ");
    $stmt->execute([$userId, $userId]);
    $totalUnread += (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // Continue with partial count
}

echo json_encode([
    'success' => true,
    'total_unread' => $totalUnread,
    'timestamp' => time()
]);
?>