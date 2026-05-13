<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

$userId = $_SESSION['user_id'];
$bio = trim($_POST['bio'] ?? '');

if (strlen($bio) > 1000) { // limit bio length optionally
  echo json_encode(['success' => false, 'message' => 'Bio too long (max 1000 chars)']);
  exit;
}

require_once "config.php";
// You can adjust table and column names — assume users has 'bio' column
try {
  $stmt = $pdo->prepare("UPDATE users SET bio = :bio WHERE id = :id");
  $stmt->execute([':bio' => $bio, ':id' => $userId]);
  echo json_encode(['success' => true]);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'Database error saving bio']);
}
