<?php
// clear_edited_video.php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['edited_video'])) {
        unset($_SESSION['edited_video']);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
?>