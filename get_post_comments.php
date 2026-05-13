<?php
session_start();
require_once "db_connection.php"; // Your DB connection file

$postId = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT);

// Fetch post info
$stmt = $pdo->prepare("SELECT p.*, u.username, u.profile_pic_url FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch comments
$stmt = $pdo->prepare("SELECT c.*, u.username, u.profile_pic_url FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY c.created_at ASC");
$stmt->execute([$postId]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'post' => $post, 'comments' => $comments]);
?>