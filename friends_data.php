<?php
session_start();
header('message-Type: application/json');

// Enable error reporting (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once "config.php";


$currentUserId = $_SESSION['user_id'];

$sql = "
SELECT DISTINCT 
    u.id, 
    u.username, 
    u.profile_pic_url,
    (
        SELECT m.message
        FROM messages m
        WHERE 
            (m.sender_id = u.id AND m.receiver_id = :currentUser)
            OR (m.receiver_id = u.id AND m.sender_id = :currentUser)
        ORDER BY m.created_at DESC
        LIMIT 1
    ) AS last_message,
    (
        SELECT COUNT(*)
        FROM messages m2
        WHERE
            m2.sender_id = u.id 
            AND m2.receiver_id = :currentUser
            AND m2.read_at IS NULL
    ) AS unread_count
FROM users u
JOIN friends f ON (
    (f.user_id = :currentUser AND u.id = f.friend_id)
    OR
    (f.friend_id = :currentUser AND u.id = f.user_id)
)
WHERE f.status = 'accepted'
ORDER BY last_message DESC NULLS LAST, u.username ASC
";
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['currentUser' => $currentUserId]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'friends' => $friends]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
}
