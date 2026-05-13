<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

require_once "config.php";

$searchQueryRaw = trim($_GET['q'] ?? '');
$searchQuery = strtolower($searchQueryRaw);
$tab = $_GET['tab'] ?? 'posts';

if ($searchQuery === '') {
  echo json_encode(['success' => false, 'message' => 'Empty search query']);
  exit;
}

$likeQuery = '%' . $searchQuery . '%';

try {
  switch ($tab) {
    case 'posts':
      // More comprehensive post search including title and content and group names
      $sql = "
        SELECT p.id, 
               COALESCE(p.title, '') AS title, 
               COALESCE(p.content, '') AS content, 
               p.created_at,
               u.username AS author,
               COALESCE(g.name, '') AS group_name
        FROM posts p
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN groups g ON p.group_id = g.id
        WHERE LOWER(p.title) ILIKE :search
           OR LOWER(p.content) ILIKE :search
           OR LOWER(g.name) ILIKE :search
        ORDER BY p.created_at DESC
        LIMIT 50
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute(['search' => $likeQuery]);
      $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Add snippet of content up to 120 chars
      foreach ($posts as &$post) {
        $content = strip_tags($post['content']);
        $post['snippet'] = mb_strlen($content) > 120 ? mb_substr($content, 0, 120) . '...' : $content;
      }

      echo json_encode(['success' => true, 'posts' => $posts]);
      break;

    case 'people':
      $currentUserId = $_SESSION['user_id'];
      $sql = "
        SELECT u.id, u.username, u.profile_pic_url,
          CASE 
            WHEN f.status = 'accepted' THEN 'friends'
            WHEN f.status = 'pending' AND f.user_id = :currentUser THEN 'pending'
            ELSE 'not_friends'
          END AS friend_status,
          CASE 
            WHEN f.status = 'accepted' THEN 'You are friends'
            WHEN f.status = 'pending' AND f.user_id = :currentUser THEN 'Request Pending'
            ELSE 'Add Friend'
          END AS friend_text
        FROM users u
        LEFT JOIN friends f ON (
          (f.user_id = :currentUser AND f.friend_id = u.id)
          OR
          (f.friend_id = :currentUser AND f.user_id = u.id)
        )
        WHERE LOWER(u.username) ILIKE :search
          AND u.id <> :currentUser
        ORDER BY u.username ASC
        LIMIT 30
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute(['currentUser' => $currentUserId, 'search' => $likeQuery]);
      $people = $stmt->fetchAll(PDO::FETCH_ASSOC);

      echo json_encode(['success' => true, 'people' => $people]);
      break;

    case 'groups':
      $currentUserId = $_SESSION['user_id'];
      $sql = "
        SELECT g.id, g.name, g.profile_pic_url, g.cover_pic_url,
          CASE 
            WHEN gm.user_id IS NOT NULL THEN 'joined'
            ELSE 'not_joined'
          END AS join_status
        FROM groups g
        LEFT JOIN group_members gm ON g.id = gm.group_id AND gm.user_id = :currentUser
        WHERE LOWER(g.name) ILIKE :search
        ORDER BY g.name ASC
        LIMIT 30
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute(['search' => $likeQuery, 'currentUser' => $currentUserId]);
      $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

      echo json_encode(['success' => true, 'groups' => $groups]);
      break;

    default:
      echo json_encode(['success' => false, 'message' => 'Invalid tab']);
      break;
  }
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
}
