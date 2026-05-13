<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];

$q = trim(strtolower($_GET['q'] ?? ''));
$fetchAll = isset($_GET['all']) && $_GET['all'] == '1';

if ($q === '') {
  echo json_encode(['success' => false, 'message' => 'Empty query']);
  exit;
}

$likeQuery = '%' . $q . '%';

try {
  if ($fetchAll) {
    // Return full list for search results listing
    $stmt = $pdo->prepare("
      SELECT u.id, u.username, u.profile_pic_url,
        CASE 
          WHEN f.status = 'accepted' THEN 'friends'
          WHEN f.status = 'pending' AND f.user_id = :currentUser THEN 'pending'
          ELSE 'not_friends'
        END AS friend_status,
        CASE 
          WHEN f.status = 'accepted' THEN 'You are friends'
          WHEN f.status = 'pending' AND f.user_id = :currentUser THEN 'Pending'
          ELSE 'Add Friend'
        END AS friend_text
      FROM users u
      LEFT JOIN friends f ON (
        (f.user_id = :currentUser AND f.friend_id = u.id)
        OR
        (f.friend_id = :currentUser AND f.user_id = u.id)
      )
      WHERE LOWER(u.username) LIKE :search
        AND u.id <> :currentUser
      ORDER BY u.username ASC
      LIMIT 100
    ");
    $stmt->execute(['search' => $likeQuery, 'currentUser' => $currentUserId]);
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'profiles' => $profiles]);
  } else {
    // Return limited list for autocomplete suggestions (max 10)
    $stmt = $pdo->prepare("
      SELECT u.id, u.username, u.profile_pic_url
      FROM users u
      WHERE LOWER(u.username) LIKE :search
        AND u.id <> :currentUser
      ORDER BY u.username ASC
      LIMIT 10
    ");
    $stmt->execute(['search' => $likeQuery, 'currentUser' => $currentUserId]);
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'profiles' => $profiles]);
  }
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
}
