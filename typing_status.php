<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$friendId = filter_input(INPUT_GET, 'friend_id', FILTER_VALIDATE_INT);
$isTyping = filter_input(INPUT_POST, 'is_typing', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // User is updating their typing status

    if (!$friendId || $isTyping === null) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit;
    }

    // Insert or update typing status record
    $stmt = $pdo->prepare("INSERT INTO typing_status (user_id, friend_id, is_typing, last_updated)
                           VALUES (:user_id, :friend_id, :is_typing, NOW())
                           ON CONFLICT (user_id, friend_id)
                           DO UPDATE SET is_typing = :is_typing, last_updated = NOW()");
    $success = $stmt->execute([
        'user_id' => $currentUserId,
        'friend_id' => $friendId,
        'is_typing' => $isTyping,
    ]);

    echo json_encode(['success' => $success]);
    exit;
} else {
    // GET request to fetch friend's typing status

    if (!$friendId) {
        echo json_encode(['success' => false, 'message' => 'Missing friend ID']);
        exit;
    }

    // Select typing status where friend is the user and current user is the "friend"
    $stmt = $pdo->prepare("SELECT t.is_typing, u.username FROM typing_status t JOIN users u ON t.user_id = u.id
                           WHERE t.user_id = :friend_id AND t.friend_id = :current_user
                           AND t.last_updated > NOW() - INTERVAL '10 seconds'");
    $stmt->execute([
        'friend_id' => $friendId,
        'current_user' => $currentUserId,
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['is_typing']) {
        echo json_encode([
            'success' => true,
            'is_typing' => true,
            'username' => $result['username'],
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'is_typing' => false,
        ]);
    }
    exit;
}
