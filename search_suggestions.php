<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
  echo json_encode(['success' => false, 'message' => 'Empty query']);
  exit;
}

require_once "config.php";
// Prepare search term for LIKE query
$searchTerm = '%' . strtolower($q) . '%';

// Search related words (you can customize your words source table or any keywords)
$words = ['chat', 'friend', 'group', 'post', 'photo', 'video']; // Example words
$relatedWords = array_filter($words, fn($word) => strpos(strtolower($word), strtolower($q)) !== false);
$relatedWords = array_slice($relatedWords, 0, 10);

// Search users by username
$stmtUsers = $pdo->prepare("SELECT id, username, profile_pic_url FROM users WHERE LOWER(username) LIKE :search LIMIT 10");
$stmtUsers->execute(['search' => $searchTerm]);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'success' => true,
  'words' => array_values($relatedWords),
  'profiles' => $users
]);
