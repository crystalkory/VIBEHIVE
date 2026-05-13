<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

require_once "config.php";


$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? 0;

if (!in_array($type, ['post', 'ad']) || !$id) {
    die("Invalid request");
}

// FIX: Pass the parameters via URL query string
if ($type === 'post') {
    // Create a new GET array with post_id parameter
    $_GET = ['post_id' => $id];
    // Also set it in the query string
    $_SERVER['QUERY_STRING'] = 'post_id=' . $id;
    
    require_once "comment.php";
} else {
    // Create a new GET array with ad_id parameter
    $_GET = ['ad_id' => $id];
    // Also set it in the query string
    $_SERVER['QUERY_STRING'] = 'ad_id=' . $id;
    
    require_once "ads_comment.php";
}
?>