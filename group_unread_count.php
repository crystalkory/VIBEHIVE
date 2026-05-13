<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once 'db_connection.php'; // Your DB connection file

$userId = $_SESSION['user_id'];
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

if ($groupId <= 0) {
    echo json_encode(['success' => false, 'unread_count' => 0]);
    exit;
}

// Get the last time user read messages in this group
$stmtLastRead = $pdo->prepare("
    SELECT last_read_at 
    FROM group_members 
    WHERE group_id = :group_id AND user_id = :user_id
");
$stmtLastRead->execute(['group_id' => $groupId, 'user_id' => $userId]);
$lastReadAt = $stmtLastRead->fetchColumn();

$unreadCount = 0;

if ($lastReadAt) {
    // Count unread messages (messages sent by others after user's last read time)
    $stmtUnread = $pdo->prepare("
        SELECT COUNT(*) 
        FROM group_messages 
        WHERE group_id = :group_id 
        AND user_id != :user_id 
        AND created_at > :last_read_at
    ");
    $stmtUnread->execute([
        'group_id' => $groupId,
        'user_id' => $userId,
        'last_read_at' => $lastReadAt
    ]);
    $unreadCount = (int)$stmtUnread->fetchColumn();
} else {
    // If user has never read, count all messages from others
    $stmtUnread = $pdo->prepare("
        SELECT COUNT(*) 
        FROM group_messages 
        WHERE group_id = :group_id 
        AND user_id != :user_id
    ");
    $stmtUnread->execute([
        'group_id' => $groupId,
        'user_id' => $userId
    ]);
    $unreadCount = (int)$stmtUnread->fetchColumn();
}

echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
?>