<?php
session_start();
require_once 'db_config.php'; // Your database config

if (isset($_SESSION['user_id']) && isset($_POST['ad_id'])) {
    $adId = (int)$_POST['ad_id'];
    $userId = $_SESSION['user_id'];
    
    trackAdClick($pdo, $adId, $userId);
}
echo 'OK';
?>