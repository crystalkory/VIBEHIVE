<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$postId = $_POST['post_id'] ?? null;
if (!$postId) {
    echo json_encode(['success' => false, 'message' => 'Post ID required']);
    exit;
}

require_once "config.php";

try {
    $stmt = $pdo->prepare("INSERT INTO post_shares (post_id, user_id, shared_at) VALUES (?, ?, NOW())");
    $stmt->execute([$postId, $userId]);

    echo json_encode(['success' => true, 'message' => 'Post shared successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
}
