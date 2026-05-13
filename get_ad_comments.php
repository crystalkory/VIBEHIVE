<?php
session_start();
require_once "db_connection.php"; // Your DB connection file

$adId = filter_input(INPUT_GET, 'ad_id', FILTER_VALIDATE_INT);
$userId = $_SESSION['user_id'];

// Fetch ad info
$stmt = $pdo->prepare("SELECT a.*, u.username, u.profile_pic_url FROM ads a JOIN users u ON a.user_id = u.id WHERE a.id = ? AND a.status = 'active'");
$stmt->execute([$adId]);
$ad = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch comments
$stmt = $pdo->prepare("SELECT ac.*, u.username, u.profile_pic_url FROM ad_comments ac JOIN users u ON ac.user_id = u.id WHERE ac.ad_id = ? ORDER BY ac.created_at ASC");
$stmt->execute([$adId]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get like count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
$stmt->execute([$adId]);
$likeCount = $stmt->fetchColumn();

// Get share count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
$stmt->execute([$adId]);
$shareCount = $stmt->fetchColumn();

// Check if user liked
$stmt = $pdo->prepare("SELECT 1 FROM ad_likes WHERE ad_id = ? AND user_id = ?");
$stmt->execute([$adId, $userId]);
$userLiked = (bool)$stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode([
    'success' => true, 
    'ad' => $ad, 
    'comments' => $comments,
    'likeCount' => $likeCount,
    'shareCount' => $shareCount,
    'userLiked' => $userLiked
]);
?>