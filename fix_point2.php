<?php
session_start();
// Only allow admin access - remove this if you're running directly
// if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != ADMIN_ID) {
//     die('Access denied');
// }

require_once "config.php";


// Option 1: Reset all points to 0
$resetStmt = $pdo->prepare("UPDATE user_points SET total_points = 0");
$resetStmt->execute();

// Option 2: Subtract 1500 from everyone (minimum 0)
// $resetStmt = $pdo->prepare("UPDATE user_points SET total_points = GREATEST(0, total_points - 1500)");
// $resetStmt->execute();

// Option 3: Set points based on actual user activity
// You'll need to implement your actual points calculation logic here

echo "Points have been reset successfully!";
?>