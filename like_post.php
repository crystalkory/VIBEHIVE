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

try {
  $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
} catch (PDOException $e) {
  error_log("DB connect error in like_post.php: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => 'Database connection error']);
  exit;
}

try {
  $stmt = $pdo->prepare("SELECT 1 FROM post_likes WHERE user_id = ? AND post_id = ?");
  $stmt->execute([$userId, $postId]);
  $exists = $stmt->fetchColumn();

  if ($exists) {
    $stmt = $pdo->prepare("DELETE FROM post_likes WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$userId, $postId]);
    $liked = false;
  } else {
    $stmt = $pdo->prepare("INSERT INTO post_likes (user_id, post_id, liked_at) VALUES (?, ?, NOW())");
    $stmt->execute([$userId, $postId]);
    $liked = true;
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ?");
  $stmt->execute([$postId]);
  $likeCount = (int) $stmt->fetchColumn();

  echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $likeCount]);
} catch (Exception $e) {
  error_log("Error in like_post.php: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => 'Error processing like']);
}
