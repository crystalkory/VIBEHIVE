<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Please login to view comments');
}
require_once "config.php";


// Get post ID or ad ID
if (isset($_GET['post_id'])) {
    $postId = (int)$_GET['post_id'];
    $type = 'post';
} elseif (isset($_GET['ad_id'])) {
    $adId = (int)$_GET['ad_id'];
    $type = 'ad';
} else {
    die('No ID specified');
}

// Function to get post comments
function getPostComments($pdo, $postId, $limit = 50) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.profile_pic_url 
        FROM comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.post_id = ? 
        ORDER BY c.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$postId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get ad comments
function getAdComments($pdo, $adId, $limit = 50) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.profile_pic_url 
        FROM ad_comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.ad_id = ? 
        ORDER BY c.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$adId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get comments based on type
if ($type === 'post') {
    $comments = getPostComments($pdo, $postId);
} else {
    $comments = getAdComments($pdo, $adId);
}

if (empty($comments)) {
    echo '<div class="no-comments">No comments yet. Be the first to comment!</div>';
} else {
    foreach ($comments as $comment) {
        $timeAgo = time_ago($comment['created_at']);
        ?>
        <div class="comment-item">
            <div class="comment-header">
                <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" 
                     class="comment-avatar" alt="<?= htmlspecialchars($comment['username']) ?>">
                <span class="comment-user"><?= htmlspecialchars($comment['username']) ?></span>
                <span class="comment-time"><?= $timeAgo ?></span>
            </div>
            <div class="comment-text">
                <?= nl2br(htmlspecialchars($comment['content'])) ?>
            </div>
        </div>
        <?php
    }
}

// Helper function for time ago
function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}
?>