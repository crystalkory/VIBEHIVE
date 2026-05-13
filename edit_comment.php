<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$commentId = $_POST['comment_id'] ?? null;
$newContent = trim($_POST['content'] ?? '');

if (!$commentId || $newContent === '') {
    echo json_encode(['success' => false, 'message' => 'Comment ID and new content are required.']);
    exit;
}

require_once "config.php";

try {
    // Verify that comment belongs to current user
    $stmt = $pdo->prepare("SELECT user_id FROM post_comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $ownerId = $stmt->fetchColumn();

    if (!$ownerId || $ownerId != $userId) {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to edit this comment.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE post_comments SET content = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newContent, $commentId]);

    echo json_encode(['success' => true, 'message' => 'Comment updated successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to update comment: ' . $e->getMessage()]);
}
