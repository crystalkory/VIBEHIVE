<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['tab'], $_POST['count'])) {
    $tab = $_POST['tab'];
    $count = intval($_POST['count']);
    
    switch($tab) {
        case 'posts':
            $_SESSION['unread_friend_requests'] = $count;
            break;
        case 'photos':
            $_SESSION['unread_groups'] = $count;
            break;
        case 'message':
            $_SESSION['unread_messages'] = $count;
            break;
        case 'videos':
            $_SESSION['unread_invites'] = $count;
            break;
    }
    
    echo json_encode(['success' => true]);
}
?>