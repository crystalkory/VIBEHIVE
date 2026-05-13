<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';
$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;

if (!$postId || !in_array($action, ['like', 'unlike'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    if ($action === 'like') {
        // Check if already liked
        $stmtCheck = $pdo->prepare("SELECT 1 FROM likes WHERE user_id = :user_id AND post_id = :post_id");
        $stmtCheck->execute(['user_id' => $userId, 'post_id' => $postId]);
        if (!$stmtCheck->fetch()) {
            // Insert like
            $stmtInsert = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (:user_id, :post_id)");
            $stmtInsert->execute(['user_id' => $userId, 'post_id' => $postId]);
        }
    } elseif ($action === 'unlike') {
        // Delete like
        $stmtDelete = $pdo->prepare("DELETE FROM likes WHERE user_id = :user_id AND post_id = :post_id");
        $stmtDelete->execute(['user_id' => $userId, 'post_id' => $postId]);
    }

    // Return current like count
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmtCount->execute(['post_id' => $postId]);
    $likesCount = (int)$stmtCount->fetchColumn();

    echo json_encode(['success' => true, 'likes_count' => $likesCount]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
// --- AD LIKE/UNLIKE HANDLER ---
if (isset($_POST['action']) && in_array($_POST['action'], ['like_ad', 'unlike_ad']) && isset($_POST['ad_id'])) {
    $adId = (int)$_POST['ad_id'];
    
    if ($_POST['action'] === 'like_ad') {
        $stmt = $pdo->prepare("INSERT INTO ad_likes (ad_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
        $stmt->execute([$adId, $userId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM ad_likes WHERE ad_id = ? AND user_id = ?");
        $stmt->execute([$adId, $userId]);
    }
    
    $newLikeCount = getAdLikeCount($pdo, $adId);
    echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
    exit;
}

// --- AD SHARE HANDLER ---
if (isset($_POST['action']) && $_POST['action'] === 'share_ad' && isset($_POST['ad_id'])) {
    $adId = (int)$_POST['ad_id'];
    
    // Check if already shared
    $checkStmt = $pdo->prepare("SELECT 1 FROM shared_ads WHERE original_ad_id = ? AND user_id = ?");
    $checkStmt->execute([$adId, $userId]);
    $alreadyShared = (bool)$checkStmt->fetchColumn();
    
    if (!$alreadyShared) {
        $stmt = $pdo->prepare("INSERT INTO shared_ads (original_ad_id, user_id) VALUES (?, ?)");
        $stmt->execute([$adId, $userId]);
    }
    
    $newShareCount = getAdShareCount($pdo, $adId);
    echo json_encode(['success' => true, 'shares_count' => $newShareCount]);
    exit;
}