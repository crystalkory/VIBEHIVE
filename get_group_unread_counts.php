<?php
// get_group_unread_counts.php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

// Fetch user's groups
$stmt = $pdo->prepare("
    SELECT g.id FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'
");
$stmt->execute(['user_id' => $userId]);
$userGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

$unreadCounts = [];

foreach ($userGroups as $group) {
    $groupId = $group['id'];
    $lastVisited = isset($userLastVisits[$groupId]) ? $userLastVisits[$groupId] : null;
    
    if ($lastVisited) {
        // Count posts created after user's last visit
        $stmtUnread = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
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
        // If never visited, count all posts except user's own
        $stmtUnread = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM posts 
            WHERE group_id = :group_id 
            AND user_id != :user_id
        ");
        $stmtUnread->execute([
            'group_id' => $groupId,
            'user_id' => $userId
        ]);
    }
    
    $result = $stmtUnread->fetch(PDO::FETCH_ASSOC);
    $unreadCount = $result ? (int)$result['unread_count'] : 0;
    
    $unreadCounts[] = [
        'group_id' => $groupId,
        'unread_count' => $unreadCount
    ];
}

echo json_encode([
    'success' => true,
    'counts' => $unreadCounts
]);