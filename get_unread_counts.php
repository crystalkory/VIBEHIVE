<?php
// get_unread_counts.php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$pdo = new PDO("pgsql:host=localhost;dbname=fbclone", "postgres", "Gi12,br12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = $_SESSION['user_id'];

// Get all pending friendship users first
$stmt = $pdo->prepare("
    SELECT u.id
    FROM users u
    JOIN friends f ON (
        (f.user_id = :user AND f.friend_id = u.id) OR 
        (f.friend_id = :user AND f.user_id = u.id)
    )
    WHERE f.status != 'accepted'
");
$stmt->execute(['user' => $userId]);
$pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unreadCounts = [];

if (!empty($pendingUsers)) {
    $pendingUserIds = array_column($pendingUsers, 'id');
    $placeholders = implode(',', array_fill(0, count($pendingUserIds), '?'));

    // Get unread messages count for each user
    $unreadStmt = $pdo->prepare("
        SELECT sender_id, COUNT(*) AS unread_count
        FROM messages
        WHERE receiver_id = ? AND read_at IS NULL AND sender_id IN ($placeholders)
        GROUP BY sender_id
    ");
    $paramsUnread = array_merge([$userId], $pendingUserIds);
    $unreadStmt->execute($paramsUnread);
    $unreadResults = $unreadStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unreadResults as $row) {
        $unreadCounts[] = [
            'user_id' => $row['sender_id'],
            'unread_count' => (int)$row['unread_count']
        ];
    }
    
    // Also include users with 0 unread messages for completeness
    foreach ($pendingUserIds as $pid) {
        $found = false;
        foreach ($unreadCounts as $count) {
            if ($count['user_id'] == $pid) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $unreadCounts[] = [
                'user_id' => $pid,
                'unread_count' => 0
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'counts' => $unreadCounts
]);