<?php

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$adId = isset($_GET['ad_id']) ? (int)$_GET['ad_id'] : 0;

// Fetch ad details
$adStmt = $pdo->prepare("
    SELECT a.*, u.username, u.profile_pic_url 
    FROM ads a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.id = ? AND a.status = 'active' AND a.ends_at > CURRENT_TIMESTAMP
");
$adStmt->execute([$adId]);
$ad = $adStmt->fetch(PDO::FETCH_ASSOC);

if (!$ad) {
    die("Advertisement not found or expired.");
}

// Handle new comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    $content = trim($_POST['content'] ?? '');
    
    if (!empty($content)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ad_comments (ad_id, user_id, content) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$adId, $currentUserId, $content]);
            
            // Create notification for ad owner if not commenting on own ad
            if ($ad['user_id'] != $currentUserId) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, source_user_id, message) 
                    VALUES (?, 'comment', ?, ?)
                ");
                $message = "commented on your advertisement";
                $stmt->execute([$ad['user_id'], $currentUserId, $message]);
            }
            
            header("Location: ads_comment.php?ad_id=" . $adId);
            exit;
        } catch (PDOException $e) {
            $error = "Error adding comment: " . $e->getMessage();
        }
    } else {
        $error = "Comment cannot be empty.";
    }
}

// Handle comment deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    
    if ($commentId > 0) {
        try {
            // Verify ownership before deletion
            $stmt = $pdo->prepare("SELECT user_id FROM ad_comments WHERE id = ?");
            $stmt->execute([$commentId]);
            $comment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($comment && ($comment['user_id'] == $currentUserId || $ad['user_id'] == $currentUserId)) {
                $stmt = $pdo->prepare("DELETE FROM ad_comments WHERE id = ?");
                $stmt->execute([$commentId]);
                
                header("Location: ads_comment.php?ad_id=" . $adId);
                exit;
            } else {
                $error = "You don't have permission to delete this comment.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting comment: " . $e->getMessage();
        }
    }
}

// Fetch comments for this ad
$commentsStmt = $pdo->prepare("
    SELECT ac.*, u.username, u.profile_pic_url 
    FROM ad_comments ac 
    JOIN users u ON ac.user_id = u.id 
    WHERE ac.ad_id = ? 
    ORDER BY ac.created_at ASC
");
$commentsStmt->execute([$adId]);
$comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get like count for this ad
$likeStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
$likeStmt->execute([$adId]);
$likeCount = $likeStmt->fetchColumn();

// Check if current user liked this ad
$userLikeStmt = $pdo->prepare("SELECT 1 FROM ad_likes WHERE ad_id = ? AND user_id = ?");
$userLikeStmt->execute([$adId, $currentUserId]);
$userLiked = (bool)$userLikeStmt->fetchColumn();

// Get share count for this ad
$shareStmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
$shareStmt->execute([$adId]);
$shareCount = $shareStmt->fetchColumn();

// Format time difference function
function timeAgo($datetime) {
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

// Get current user profile pic
$userStmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$userStmt->execute([$currentUserId]);
$userProfilePic = $userStmt->fetchColumn() ?: 'default_profile.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comments - <?= htmlspecialchars($ad['header']) ?></title>
    <style>
      * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    line-height: 1.6;
    min-height: 100vh;
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.back-btn {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #667eea;
    border: none;
    padding: 12px 24px;
    border-radius: 25px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.back-btn:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    color: #764ba2;
    text-decoration: none;
}

.header h1 {
    font-size: 28px;
    font-weight: 800;
    background: linear-gradient(135deg, #ffffff, #e2e8f0);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.ad-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.ad-container:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.ad-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.ad-header img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    border: 3px solid rgba(102, 126, 234, 0.3);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.ad-user-info {
    flex: 1;
}

.ad-username {
    font-weight: 800;
    color: #2d3748;
    font-size: 18px;
    margin-bottom: 5px;
}

.ad-label {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    color: #000;
    padding: 6px 15px;
    border-radius: 20px;
    font-weight: 800;
    font-size: 12px;
    display: inline-block;
    margin-bottom: 8px;
    box-shadow: 0 2px 8px rgba(255, 215, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.ad-content {
    margin-bottom: 20px;
}

.ad-title {
    font-size: 24px;
    font-weight: 800;
    color: #2d3748;
    margin-bottom: 15px;
    line-height: 1.3;
}

.ad-description {
    color: #4a5568;
    line-height: 1.6;
    margin-bottom: 20px;
    font-size: 16px;
}

.ad-media {
    margin: 20px 0;
    text-align: center;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.ad-media img, .ad-media video {
    max-width: 100%;
    max-height: 400px;
    border-radius: 15px;
    display: block;
}

.ad-actions {
    display: flex;
    gap: 25px;
    padding-top: 20px;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
}

.action-btn {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 0, 0, 0.1);
    color: #4a5568;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.action-btn:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    color: #667eea;
}

.action-btn.liked {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

.action-btn.liked:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
}

.comments-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.comments-title {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 25px;
    color: #2d3748;
    text-align: center;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.comment-form {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    align-items: flex-start;
}

.comment-user-img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(102, 126, 234, 0.3);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    flex-shrink: 0;
}

.comment-input-container {
    flex: 1;
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.comment-input {
    flex: 1;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 25px;
    padding: 15px 20px;
    color: #2d3748;
    font-size: 15px;
    resize: none;
    min-height: 50px;
    max-height: 120px;
    font-family: inherit;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.comment-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    background: white;
}

.comment-input::placeholder {
    color: #a0aec0;
}

.comment-submit {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 12px 25px;
    cursor: pointer;
    font-weight: 700;
    font-size: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    white-space: nowrap;
    flex-shrink: 0;
}

.comment-submit:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.comment-submit:disabled {
    background: #a0aec0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.comments-list {
    max-height: 500px;
    overflow-y: auto;
    padding-right: 10px;
}

.comment {
    display: flex;
    gap: 15px;
    padding: 20px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.comment:hover {
    background: rgba(102, 126, 234, 0.03);
    border-radius: 12px;
    padding: 20px 15px;
    margin: 0 -15px;
}

.comment:last-child {
    border-bottom: none;
}

.comment-content {
    flex: 1;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.comment-username {
    font-weight: 700;
    color: #2d3748;
    font-size: 15px;
}

.comment-time {
    color: #718096;
    font-size: 12px;
    font-weight: 600;
}

.comment-text {
    color: #4a5568;
    line-height: 1.5;
    word-wrap: break-word;
    font-size: 14px;
}

.comment-actions {
    display: flex;
    gap: 15px;
    margin-top: 10px;
}

.comment-action {
    background: none;
    border: none;
    color: #718096;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
    padding: 4px 8px;
    border-radius: 8px;
}

.comment-action:hover {
    color: #667eea;
    background: rgba(102, 126, 234, 0.1);
}

.comment-action.delete {
    color: #e53e3e;
}

.comment-action.delete:hover {
    color: #c53030;
    background: rgba(229, 62, 62, 0.1);
}

.no-comments {
    text-align: center;
    color: #718096;
    padding: 50px 0;
    font-style: italic;
    font-size: 16px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 15px;
    margin: 20px 0;
}

.error-message {
    background: rgba(229, 62, 62, 0.1);
    color: #e53e3e;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
    border: 1px solid rgba(229, 62, 62, 0.2);
    backdrop-filter: blur(10px);
}

.success-message {
    background: rgba(72, 187, 120, 0.1);
    color: #38a169;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
    border: 1px solid rgba(72, 187, 120, 0.2);
    backdrop-filter: blur(10px);
}

.cta-button {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 12px 30px;
    border: none;
    border-radius: 25px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin: 15px 0;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.cta-button:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    color: white;
    text-decoration: none;
}

/* Animation for page load */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.container > * {
    animation: fadeInUp 0.6s ease-out;
}

.container > *:nth-child(1) { animation-delay: 0.1s; }
.container > *:nth-child(2) { animation-delay: 0.2s; }
.container > *:nth-child(3) { animation-delay: 0.3s; }
.container > *:nth-child(4) { animation-delay: 0.4s; }

.comment {
    animation: fadeInUp 0.4s ease-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .header h1 {
        font-size: 24px;
    }
    
    .ad-header {
        flex-direction: column;
        text-align: center;
    }
    
    .ad-header img {
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .ad-container, .comments-section {
        padding: 20px;
    }
    
    .comment-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .comment-user-img {
        align-self: center;
        margin-bottom: 10px;
    }
    
    .comment-input-container {
        flex-direction: column;
    }
    
    .comment-submit {
        align-self: flex-end;
        margin-top: 10px;
    }
    
    .ad-media img, .ad-media video {
        max-height: 300px;
    }
    
    .ad-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .action-btn {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .ad-container, .comments-section {
        padding: 15px;
    }
    
    .ad-title {
        font-size: 20px;
    }
    
    .comments-title {
        font-size: 20px;
    }
    
    .comment {
        padding: 15px 0;
    }
    
    .comment:hover {
        padding: 15px 10px;
        margin: 0 -10px;
    }
    
    .cta-button {
        padding: 10px 20px;
        font-size: 14px;
    }
}

/* Custom scrollbar */
.comments-list::-webkit-scrollbar {
    width: 6px;
}

.comments-list::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
}

.comments-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 3px;
}

.comments-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
}

/* Loading state for buttons */
.action-btn:active, .comment-submit:active {
    transform: scale(0.95);
    transition: transform 0.1s ease;
}

/* Focus styles for accessibility */
.action-btn:focus, .comment-submit:focus, .cta-button:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

/* Text selection */
::selection {
    background: rgba(102, 126, 234, 0.3);
    color: #2d3748;
}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="home.php" class="back-btn">← Back to Feed</a>
            <h1>Advertisement Comments</h1>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">Comment posted successfully!</div>
        <?php endif; ?>

        <!-- Advertisement Display -->
        <div class="ad-container">
            <div class="ad-header">
                <img src="<?= htmlspecialchars($ad['profile_pic_url'] ?: 'default_profile.png') ?>" 
                     alt="<?= htmlspecialchars($ad['username']) ?>">
                <div class="ad-user-info">
                    <div class="ad-label">Sponsored</div>
                    <div class="ad-username"><?= htmlspecialchars($ad['username']) ?></div>
                </div>
            </div>
            
            <div class="ad-content">
                <div class="ad-title"><?= htmlspecialchars($ad['header']) ?></div>
                <div class="ad-description"><?= nl2br(htmlspecialchars($ad['description'])) ?></div>
                
                <?php if (!empty($ad['media_path'])): ?>
                    <div class="ad-media">
                        <?php if ($ad['ad_type'] === 'video'): ?>
                            <video controls style="width: 100%; max-height: 400px; border-radius: 8px;">
                                <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars($ad['media_path']) ?>" 
                                 alt="Ad Image" 
                                 style="max-width: 100%; max-height: 400px; border-radius: 8px;">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($ad['url'])): ?>
                    <a href="<?= htmlspecialchars($ad['url']) ?>" target="_blank" class="cta-button">
                        <?= htmlspecialchars($ad['cta_button'] ?: 'Learn More') ?>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="ad-actions">
                <button class="action-btn <?= $userLiked ? 'liked' : '' ?>" 
                        onclick="likeAd(<?= $adId ?>)">
                    👍 Like (<span id="likeCount"><?= $likeCount ?></span>)
                </button>
                <button class="action-btn" onclick="scrollToComments()">
                    💬 Comment (<span id="commentCount"><?= count($comments) ?></span>)
                </button>
                <button class="action-btn" onclick="shareAd(<?= $adId ?>)">
                    🔄 Share (<span id="shareCount"><?= $shareCount ?></span>)
                </button>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section" id="commentsSection">
            <h2 class="comments-title">Comments (<?= count($comments) ?>)</h2>
            
            <!-- Comment Form -->
            <form method="POST" class="comment-form">
                <input type="hidden" name="action" value="add_comment">
                <img src="<?= htmlspecialchars($userProfilePic) ?>" 
                     alt="Your Profile" 
                     class="comment-user-img">
                <div class="comment-input-container">
                    <textarea name="content" 
                              class="comment-input" 
                              placeholder="Write a comment..." 
                              required></textarea>
                    <button type="submit" class="comment-submit">Post</button>
                </div>
            </form>

            <!-- Comments List -->
            <div class="comments-list">
                <?php if (empty($comments)): ?>
                    <div class="no-comments">
                        No comments yet. Be the first to comment!
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment" id="comment-<?= $comment['id'] ?>">
                            <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                 alt="<?= htmlspecialchars($comment['username']) ?>" 
                                 class="comment-user-img">
                            <div class="comment-content">
                                <div class="comment-header">
                                    <span class="comment-username">
                                        <?= htmlspecialchars($comment['username']) ?>
                                    </span>
                                    <span class="comment-time">
                                        <?= timeAgo($comment['created_at']) ?>
                                    </span>
                                </div>
                                <div class="comment-text">
                                    <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                </div>
                                <div class="comment-actions">
                                    <?php if ($comment['user_id'] == $currentUserId || $ad['user_id'] == $currentUserId): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                            <button type="submit" 
                                                    class="comment-action delete"
                                                    onclick="return confirm('Are you sure you want to delete this comment?')">
                                                Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-resize textarea
        document.querySelector('.comment-input').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Scroll to comments section
        function scrollToComments() {
            document.getElementById('commentsSection').scrollIntoView({ 
                behavior: 'smooth' 
            });
            document.querySelector('.comment-input').focus();
        }

        // Like ad functionality
        async function likeAd(adId) {
            const likeBtn = document.querySelector('.action-btn');
            const isLiked = likeBtn.classList.contains('liked');
            const action = isLiked ? 'unlike' : 'like';
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('post_id', adId);
            formData.append('is_ad', 'true');
            
            try {
                const response = await fetch('home.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update like count
                    document.getElementById('likeCount').textContent = data.likes_count;
                    
                    // Update like button state
                    if (data.is_liked) {
                        likeBtn.classList.add('liked');
                    } else {
                        likeBtn.classList.remove('liked');
                    }
                } else {
                    alert('Failed to update like: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error updating like. Please check your connection.');
            }
        }

        // Share ad functionality
        function shareAd(adId) {
            const shareContent = prompt('Add a comment to your share (optional):');
            if (shareContent !== null) {
                shareAdRequest(adId, shareContent);
            }
        }

        async function shareAdRequest(adId, shareContent) {
            const formData = new FormData();
            formData.append('action', 'share_post');
            formData.append('post_id', adId);
            formData.append('is_ad', 'true');
            formData.append('share_content', shareContent);
            
            try {
                const response = await fetch('home.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Advertisement shared successfully!');
                    // Update share count
                    const currentCount = parseInt(document.getElementById('shareCount').textContent);
                    document.getElementById('shareCount').textContent = currentCount + 1;
                } else {
                    alert('Error sharing: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error sharing advertisement. Please check your connection.');
            }
        }

        // Focus comment input when page loads if there's a hash
        window.addEventListener('load', function() {
            if (window.location.hash === '#comment') {
                document.querySelector('.comment-input').focus();
            }
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>