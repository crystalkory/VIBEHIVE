<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once "config.php";


$currentUserId = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'send_request') {
    $friendId = (int)($_POST['friend_id'] ?? 0);
    if (!$friendId || $friendId == $currentUserId) {
        echo json_encode(['success' => false, 'message' => 'Invalid friend ID']);
        exit;
    }

    // Check for existing request or friendship
    $stmt = $pdo->prepare("SELECT 1 FROM friends WHERE (user_id = :user AND friend_id = :friend) OR (user_id = :friend AND friend_id = :user)");
    $stmt->execute(['user' => $currentUserId, 'friend' => $friendId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Friend request or friendship already exists']);
        exit;
    }

    // Create friend request
    $stmt = $pdo->prepare("INSERT INTO friends (user_id, friend_id, status, created_at) VALUES (:user, :friend, 'pending', NOW())");
    if ($stmt->execute(['user' => $currentUserId, 'friend' => $friendId])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send friend request']);
    }
    exit;
}

if ($action === 'accept_request') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    if (!$requestId) {
        echo json_encode(['success' => false, 'message' => 'Request ID missing']);
        exit;
    }

    // Verify pending request and ownership
    $stmt = $pdo->prepare("SELECT * FROM friends WHERE id = :id AND friend_id = :currentUser AND status = 'pending'");
    $stmt->execute(['id' => $requestId, 'currentUser' => $currentUserId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Friend request not found']);
        exit;
    }

    // Accept request
    $stmt = $pdo->prepare("UPDATE friends SET status = 'accepted' WHERE id = :id");
    $success = $stmt->execute(['id' => $requestId]);

    if ($success) {
        // Insert reciprocal friendship
        $stmt2 = $pdo->prepare("
            INSERT INTO friends (user_id, friend_id, status, created_at)
            VALUES (:friend, :user, 'accepted', NOW())
            ON CONFLICT (user_id, friend_id) DO NOTHING
        ");
        $stmt2->execute([
            'friend' => $request['friend_id'],
            'user' => $request['user_id'],
        ]);
        echo json_encode(['success' => true, 'message' => 'Friend request accepted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to accept friend request']);
    }
    exit;
}

if ($action === 'delete_request') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    if (!$requestId) {
        echo json_encode(['success' => false, 'message' => 'Request ID missing']);
        exit;
    }

    // Delete request if current user is recipient
    $stmt = $pdo->prepare("DELETE FROM friends WHERE id = :id AND friend_id = :currentUser AND status = 'pending'");
    if ($stmt->execute(['id' => $requestId, 'currentUser' => $currentUserId])) {
        echo json_encode(['success' => true, 'message' => 'Friend request deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete friend request']);
    }
    exit;
}

if ($action === 'get_requests') {
    // Load pending friend requests for current user
    $stmt = $pdo->prepare("SELECT f.id AS request_id, u.id AS user_id, u.username, u.profile_pic_url FROM friends f JOIN users u ON f.user_id = u.id WHERE f.friend_id = ? AND f.status = 'pending' ORDER BY f.created_at DESC");
    $stmt->execute([$currentUserId]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'requests' => $requests]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
