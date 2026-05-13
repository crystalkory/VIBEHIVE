<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}
$userId = $_SESSION['user_id'];

$host = 'localhost'; $port = '5432'; $dbname = 'fbclone'; $user = 'postgres'; $password = 'Gi12,br12';
try {
  $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'error' => 'DB error']);
  exit;
}

$groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$message = trim($_POST['message'] ?? '');
$replyTo = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

if ($groupId <= 0 || empty($message)) {
  echo json_encode(['success' => false, 'error' => 'Invalid data']);
  exit;
}

$stmt = $pdo->prepare("SELECT status FROM group_members WHERE user_id = :user_id AND group_id = :group_id");
$stmt->execute(['user_id' => $userId, 'group_id' => $groupId]);
$status = $stmt->fetchColumn();
if ($status !== 'approved') {
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

if ($replyTo) {
  $stmtCheck = $pdo->prepare("SELECT 1 FROM group_chat WHERE id = :reply_to AND group_id = :group_id");
  $stmtCheck->execute(['reply_to' => $replyTo, 'group_id' => $groupId]);
  if (!$stmtCheck->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Invalid reply target']);
    exit;
  }
}

try {
  $stmtInsert = $pdo->prepare("INSERT INTO group_chat (group_id, user_id, message, reply_to, created_at) VALUES (:group_id, :user_id, :message, :reply_to, NOW())");
  $stmtInsert->execute([
    ':group_id' => $groupId,
    ':user_id' => $userId,
    ':message' => $message,
    ':reply_to' => $replyTo
  ]);
  echo json_encode(['success' => true]);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'error' => 'Database error']);
}
