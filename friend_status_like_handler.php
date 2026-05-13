<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

require_once "config.php";


$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$action = $_POST['action'] ?? '';

if (!$postId || ($action !== 'like' && $action !== 'unlike')) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Check if post belongs to a friend or user himself - optional security measure (can add if needed)

try {
    if ($action === 'like') {
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (:user_id, :post_id) ON CONFLICT DO NOTHING");
        $stmt->execute(['user_id' => $userId, 'post_id' => $postId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = :user_id AND post_id = :post_id");
        $stmt->execute(['user_id' => $userId, 'post_id' => $postId]);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $countStmt->execute(['post_id' => $postId]);
    $totalLikes = (int)$countStmt->fetchColumn();

    echo json_encode(['success' => true, 'total_likes' => $totalLikes]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
