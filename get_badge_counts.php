<?php
session_start();
require_once "back.php";

function getUnreadCounts($userId) {
    global $con;
    
    $counts = [
        'friend_requests' => 0,
        'groups' => 0,
        'messages' => 0,
        'invites' => 0
    ];
    
    // Friend requests count
    $stmt = $con->prepare("SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending' AND is_read = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $counts['friend_requests'] = $row['count'];
    }
    
    // Add similar queries for groups, messages, and invites
    
    return $counts;
}

if (isset($_SESSION['user_id'])) {
    $counts = getUnreadCounts($_SESSION['user_id']);
    echo json_encode(array_merge(['success' => true], $counts));
} else {
    echo json_encode(['success' => false]);
}
?>