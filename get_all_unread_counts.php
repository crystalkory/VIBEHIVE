<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];
$result = [
    'success' => true,
    'friend_requests' => 0,
    'messages' => 0,
    'group_posts' => 0
];

// 1. Get unread friend requests count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM friends 
    WHERE friend_id = ? AND status = 'pending' AND seen = false
");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$result['friend_requests'] = $row ? (int)$row['count'] : 0;

// 2. Get unread messages count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM messages 
    WHERE receiver_id = ? AND read_at IS NULL
");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$result['messages'] = $row ? (int)$row['count'] : 0;

// 3. Get unread group posts count
$stmt = $pdo->prepare("
    SELECT g.id FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'
");
$stmt->execute(['user_id' => $userId]);
$userGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($userGroups)) {
    // Get the last time user visited each group
    $userLastVisits = [];
    $stmtVisits = $pdo->prepare("
        SELECT group_id, last_visited FROM group_visits 
        WHERE user_id = :user_id
    ");
    $stmtVisits->execute(['user_id' => $userId]);
    $visits = $stmtVisits->fetchAll(PDO::FETCH_ASSOC);
    foreach ($visits as $visit) {
        $userLastVisits[$visit['group_id']] = $visit['last_visited'];
    }
    
    $totalUnread = 0;
    foreach ($userGroups as $group) {
        $groupId = $group['id'];
        $lastVisited = isset($userLastVisits[$groupId]) ? $userLastVisits[$groupId] : null;
        
        if ($lastVisited) {
            $stmtUnread = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM posts 
                WHERE group_id = :group_id 
                AND created_at > :last_visited
                AND user_id != :user_id
            ");
            $stmtUnread->execute([
                'group_id' => $groupId,
                'last_visited' => $lastVisited,
                'user_id' => $userId
            ]);
        } else {
            $stmtUnread = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM posts 
                WHERE group_id = :group_id 
                AND user_id != :user_id
            ");
            $stmtUnread->execute([
                'group_id' => $groupId,
                'user_id' => $userId
            ]);
        }
        
        $row = $stmtUnread->fetch(PDO::FETCH_ASSOC);
        $totalUnread += $row ? (int)$row['count'] : 0;
    }
    
    $result['group_posts'] = $totalUnread;
}

echo json_encode($result);
?>