<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$postId = $_POST['post_id'] ?? null;
$content = trim($_POST['content'] ?? '');
$parentCommentId = $_POST['parent_comment_id'] ?? null;

if (!$postId || $content === '') {
    echo json_encode(['success' => false, 'message' => 'Post ID and comment content are required.']);
    exit;
}

require_once "config.php";
try {
    $sql = "INSERT INTO post_comments (post_id, user_id, content, parent_comment_id, created_at, updated_at) 
            VALUES (:post_id, :user_id, :content, :parent_comment_id, NOW(), NOW()) RETURNING id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':post_id' => $postId,
        ':user_id' => $userId,
        ':content' => $content,
        ':parent_comment_id' => $parentCommentId ? $parentCommentId : null
    ]);
    $commentId = $stmt->fetchColumn();

    echo json_encode(['success' => true, 'message' => 'Comment added successfully.', 'comment_id' => $commentId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to add comment: ' . $e->getMessage()]);
}
