<?php
session_start();
require_once 'db_config.php'; // Your database config

if (isset($_SESSION['user_id']) && isset($_POST['ad_id'])) {
    $adId = (int)$_POST['ad_id'];
    $userId = $_SESSION['user_id'];
    
    // Track video view
    $stmt = $pdo->prepare("INSERT INTO ad_video_views (ad_id, user_id) VALUES (?, ?)");
    $stmt->execute([$adId, $userId]);
}
echo 'OK';
?>