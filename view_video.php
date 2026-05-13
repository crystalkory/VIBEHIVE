<?php
// view_video.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Get video ID from URL
$videoId = $_GET['id'] ?? 0;
if (!$videoId) {
    header('Location: video.php');
    exit;
}

// Fetch video data
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.username,
            u.profile_pic_url,
            u.id as author_id,
            u.is_business_account,
            COALESCE(l.likes_count, 0) as likes_count,
            COALESCE(c.comments_count, 0) as comments_count,
            COALESCE(s.shares_count, 0) as shares_count,
            COALESCE(v.views_count, 0) as views_count,
            EXISTS(SELECT 1 FROM likes WHERE post_id = p.id AND user_id = ?) as user_liked,
            EXISTS(SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = p.user_id) as user_following
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN (
            SELECT post_id, COUNT(*) as likes_count 
            FROM likes 
            GROUP BY post_id
        ) l ON p.id = l.post_id
        LEFT JOIN (
            SELECT post_id, COUNT(*) as comments_count 
            FROM comments 
            GROUP BY post_id
        ) c ON p.id = c.post_id
        LEFT JOIN (
            SELECT original_post_id, COUNT(*) as shares_count 
            FROM shared_posts 
            GROUP BY original_post_id
        ) s ON p.id = s.original_post_id
        LEFT JOIN (
            SELECT post_id, COUNT(*) as views_count 
            FROM video_views 
            GROUP BY post_id
        ) v ON p.id = v.post_id
        WHERE p.id = ? AND p.post_type = 'video'
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $videoId]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$video) {
        header('Location: video.php');
        exit;
    }
    
} catch (Exception $e) {
    die("Error fetching video: " . $e->getMessage());
}

// Fetch user profile info
$currentUserId = $_SESSION['user_id'];
$userStmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$userStmt->execute([$currentUserId]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);
$profilePicUrl = $userData['profile_pic_url'] ?: 'default_profile.png';
$username = $userData['username'];

// Record video view
try {
    $viewStmt = $pdo->prepare("
        INSERT INTO video_views (user_id, post_id, watched_at) 
        VALUES (?, ?, NOW())
        ON CONFLICT DO NOTHING
    ");
    $viewStmt->execute([$currentUserId, $videoId]);
} catch (Exception $e) {
    error_log("Video view tracking error: " . $e->getMessage());
}

// Fetch related videos
try {
    $relatedStmt = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url,
               COALESCE(l.likes_count, 0) as likes_count
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN (
            SELECT post_id, COUNT(*) as likes_count 
            FROM likes 
            GROUP BY post_id
        ) l ON p.id = l.post_id
        WHERE p.post_type = 'video' 
          AND p.id != ?
          AND (p.category1 = ? OR p.category2 = ? OR p.category3 = ?)
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $relatedStmt->execute([
        $videoId, 
        $video['category1'], 
        $video['category2'], 
        $video['category3']
    ]);
    $relatedVideos = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $relatedVideos = [];
    error_log("Related videos error: " . $e->getMessage());
}

// Handle like/unlike
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'like') {
        try {
            $likeStmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $likeStmt->execute([$videoId, $currentUserId]);
            
            // Update likes count
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
            $countStmt->execute([$videoId]);
            $likesCount = $countStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'likes_count' => $likesCount, 'action' => 'liked']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
        
    } elseif ($_POST['action'] === 'unlike') {
        try {
            $unlikeStmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
            $unlikeStmt->execute([$videoId, $currentUserId]);
            
            // Update likes count
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
            $countStmt->execute([$videoId]);
            $likesCount = $countStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'likes_count' => $likesCount, 'action' => 'unliked']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
        
    } elseif ($_POST['action'] === 'follow') {
        $authorId = $video['author_id'];
        try {
            $followStmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $followStmt->execute([$currentUserId, $authorId]);
            echo json_encode(['success' => true, 'action' => 'followed']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
        
    } elseif ($_POST['action'] === 'unfollow') {
        $authorId = $video['author_id'];
        try {
            $unfollowStmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
            $unfollowStmt->execute([$currentUserId, $authorId]);
            echo json_encode(['success' => true, 'action' => 'unfollowed']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch comments
try {
    $commentsStmt = $pdo->prepare("
        SELECT c.*, u.username, u.profile_pic_url,
               EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as user_liked
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = ?
        ORDER BY c.created_at DESC
        LIMIT 50
    ");
    $commentsStmt->execute([$currentUserId, $videoId]);
    $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $comments = [];
    error_log("Comments fetch error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($video['post_header'] ?? 'Video') ?> - Fbclone</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #000;
    color: #fff;
    line-height: 1.4;
    overflow-x: hidden;
}

/* Header */
.video-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    background: transparent;
    padding: 12px 16px;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background 0.3s;
}

.video-header.scrolled {
    background: rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(10px);
}

.back-btn {
    background: rgba(0, 0, 0, 0.5);
    border: none;
    border-radius: 50%;
    color: #fff;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 18px;
}

.video-title {
    font-size: 16px;
    font-weight: 600;
    text-align: center;
    flex: 1;
    margin: 0 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.more-btn {
    background: rgba(0, 0, 0, 0.5);
    border: none;
    border-radius: 50%;
    color: #fff;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 18px;
}

/* Video Container */
.video-container {
    position: relative;
    width: 100%;
    height: 100vh;
    background: #000;
    overflow: hidden;
}

.video-player {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Video Controls Overlay */
.video-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 80px 16px 120px;
    background: linear-gradient(
        to bottom,
        rgba(0, 0, 0, 0.3) 0%,
        transparent 20%,
        transparent 80%,
        rgba(0, 0, 0, 0.3) 100%
    );
    z-index: 100;
}

/* Right Action Buttons */
.action-buttons {
    position: absolute;
    right: 16px;
    bottom: 120px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    z-index: 200;
}

.action-button {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    color: #fff;
    cursor: pointer;
    transition: transform 0.2s;
}

.action-button:active {
    transform: scale(0.9);
}

.action-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(10px);
}

.action-count {
    font-size: 12px;
    font-weight: 500;
}

.action-button.liked .action-icon {
    background: rgba(254, 44, 85, 0.2);
    color: #fe2c55;
}

/* Video Info */
.video-info {
    position: absolute;
    left: 16px;
    bottom: 120px;
    max-width: 70%;
    z-index: 200;
}

.author-info {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.author-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #fff;
}

.author-details {
    flex: 1;
}

.author-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.follow-btn {
    background: #fe2c55;
    border: none;
    border-radius: 4px;
    color: #fff;
    padding: 6px 16px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
}

.follow-btn.following {
    background: rgba(255, 255, 255, 0.2);
    color: #fff;
}

.video-description {
    margin-bottom: 12px;
    line-height: 1.4;
}

.video-music {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
}

.music-icon {
    font-size: 16px;
}

/* Progress Bar */
.progress-container {
    position: absolute;
    bottom: 80px;
    left: 0;
    right: 0;
    padding: 0 16px;
    z-index: 200;
}

.progress-bar {
    width: 100%;
    height: 2px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 1px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #fe2c55;
    width: 0%;
    transition: width 0.1s linear;
}

/* Bottom Navigation */
.bottom-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #000;
    border-top: 1px solid #2f2f2f;
    padding: 12px 16px;
    z-index: 1000;
    display: flex;
    justify-content: space-around;
}

.nav-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 24px;
    cursor: pointer;
    padding: 8px;
}

/* Comments Panel */
.comments-panel {
    position: fixed;
    top: 0;
    right: -100%;
    width: 100%;
    height: 100%;
    background: #000;
    z-index: 2000;
    transition: right 0.3s ease;
    display: flex;
    flex-direction: column;
}

.comments-panel.open {
    right: 0;
}

.comments-header {
    padding: 12px 16px;
    border-bottom: 1px solid #2f2f2f;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.comments-title {
    font-size: 16px;
    font-weight: 600;
}

.close-comments {
    background: none;
    border: none;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
}

.comments-list {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}

.comment-item {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.comment-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
}

.comment-content {
    flex: 1;
}

.comment-author {
    font-weight: 600;
    margin-bottom: 4px;
}

.comment-text {
    margin-bottom: 4px;
    line-height: 1.4;
}

.comment-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.6);
}

.comment-action {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
}

.comment-form {
    padding: 16px;
    border-top: 1px solid #2f2f2f;
    display: flex;
    gap: 12px;
    align-items: center;
}

.comment-input {
    flex: 1;
    background: #2f2f2f;
    border: none;
    border-radius: 20px;
    padding: 12px 16px;
    color: #fff;
    font-size: 14px;
    outline: none;
}

.comment-input::placeholder {
    color: rgba(255, 255, 255, 0.6);
}

.send-comment {
    background: #fe2c55;
    border: none;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    cursor: pointer;
    font-size: 16px;
}

/* Related Videos */
.related-videos {
    padding: 20px 16px;
    background: #000;
}

.related-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 16px;
}

.related-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.related-item {
    border-radius: 8px;
    overflow: hidden;
    background: #1a1a1a;
    cursor: pointer;
}

.related-item video {
    width: 100%;
    height: 200px;
    object-fit: cover;
}

.related-info {
    padding: 12px;
}

.related-author {
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 4px;
}

.related-stats {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.6);
}

/* Loading */
.loading {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #fff;
    font-size: 16px;
}

/* Responsive */
@media (min-width: 768px) {
    .video-container {
        max-width: 414px;
        margin: 0 auto;
        height: 100vh;
    }
    
    .comments-panel {
        width: 414px;
    }
    
    .related-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Animation */
@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.pulse {
    animation: pulse 0.5s ease;
}
</style>
</head>
<body>

<!-- Header -->
<div class="video-header" id="videoHeader">
    <button class="back-btn" onclick="goBack()">←</button>
    <div class="video-title"><?= htmlspecialchars($video['post_header'] ?? 'Video') ?></div>
    <button class="more-btn">⋯</button>
</div>

<!-- Video Container -->
<div class="video-container">
    <?php if (!empty($video['media_url'])): 
        $mediaArray = explode(',', $video['media_url']);
        $videoUrl = trim($mediaArray[0]);
    ?>
        <video class="video-player" id="videoPlayer" loop playsinline webkit-playsinline>
            <source src="<?= htmlspecialchars($videoUrl) ?>" type="video/mp4">
            Your browser does not support the video tag.
        </video>
        
        <!-- Video Overlay -->
        <div class="video-overlay" id="videoOverlay">
            <!-- Right Action Buttons -->
            <div class="action-buttons">
                <button class="action-button <?= $video['user_liked'] ? 'liked' : '' ?>" id="likeButton" onclick="toggleLike()">
                    <div class="action-icon">❤️</div>
                    <div class="action-count" id="likeCount"><?= $video['likes_count'] ?></div>
                </button>
                
                <button class="action-button" onclick="openComments()">
                    <div class="action-icon">💬</div>
                    <div class="action-count" id="commentCount"><?= $video['comments_count'] ?></div>
                </button>
                
                <button class="action-button" onclick="shareVideo()">
                    <div class="action-icon">↗️</div>
                    <div class="action-count" id="shareCount"><?= $video['shares_count'] ?></div>
                </button>
            </div>
            
            <!-- Video Info -->
            <div class="video-info">
                <div class="author-info">
                    <img src="<?= htmlspecialchars($video['profile_pic_url'] ?: 'default_profile.png') ?>" 
                         alt="<?= htmlspecialchars($video['username']) ?>" class="author-avatar">
                    <div class="author-details">
                        <div class="author-name">@<?= htmlspecialchars($video['username']) ?></div>
                        <button class="follow-btn <?= $video['user_following'] ? 'following' : '' ?>" 
                                id="followButton" onclick="toggleFollow()">
                            <?= $video['user_following'] ? 'Following' : 'Follow' ?>
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($video['post_header'])): ?>
                    <div class="video-description"><?= htmlspecialchars($video['post_header']) ?></div>
                <?php endif; ?>
                
                <div class="video-music">
                    <span class="music-icon">🎵</span>
                    <span>Original Sound</span>
                </div>
            </div>
        </div>
        
        <!-- Progress Bar -->
        <div class="progress-container">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
        </div>
        
    <?php else: ?>
        <div class="loading">Video not found</div>
    <?php endif; ?>
</div>

<!-- Bottom Navigation -->
<div class="bottom-nav">
    <button class="nav-btn" onclick="goBack()">←</button>
    <button class="nav-btn" onclick="togglePlay()">⏯️</button>
    <button class="nav-btn" onclick="toggleMute()">🔊</button>
    <button class="nav-btn" onclick="openComments()">💬</button>
</div>

<!-- Comments Panel -->
<div class="comments-panel" id="commentsPanel">
    <div class="comments-header">
        <div class="comments-title">Comments (<?= $video['comments_count'] ?>)</div>
        <button class="close-comments" onclick="closeComments()">×</button>
    </div>
    
    <div class="comments-list" id="commentsList">
        <?php if (!empty($comments)): ?>
            <?php foreach ($comments as $comment): ?>
                <div class="comment-item">
                    <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" 
                         alt="<?= htmlspecialchars($comment['username']) ?>" class="comment-avatar">
                    <div class="comment-content">
                        <div class="comment-author">@<?= htmlspecialchars($comment['username']) ?></div>
                        <div class="comment-text"><?= htmlspecialchars($comment['content']) ?></div>
                        <div class="comment-actions">
                            <button class="comment-action <?= $comment['user_liked'] ? 'liked' : '' ?>" 
                                    onclick="toggleCommentLike(<?= $comment['id'] ?>)">
                                ❤️ <?= $comment['likes_count'] ?? 0 ?>
                            </button>
                            <span><?= date('M j', strtotime($comment['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px 20px; color: #666;">
                No comments yet. Be the first to comment!
            </div>
        <?php endif; ?>
    </div>
    
    <form class="comment-form" id="commentForm">
        <input type="text" class="comment-input" placeholder="Add a comment..." id="commentInput" required>
        <button type="submit" class="send-comment">↑</button>
    </form>
</div>

<!-- Related Videos -->
<?php if (!empty($relatedVideos)): ?>
<div class="related-videos">
    <div class="related-title">More Videos</div>
    <div class="related-grid">
        <?php foreach ($relatedVideos as $related): ?>
            <div class="related-item" onclick="openVideo(<?= $related['id'] ?>)">
                <?php if (!empty($related['media_url'])): 
                    $relatedMedia = explode(',', $related['media_url']);
                    $relatedVideo = trim($relatedMedia[0]);
                ?>
                    <video muted preload="metadata">
                        <source src="<?= htmlspecialchars($relatedVideo) ?>" type="video/mp4">
                    </video>
                <?php endif; ?>
                <div class="related-info">
                    <div class="related-author">@<?= htmlspecialchars($related['username']) ?></div>
                    <div class="related-stats">❤️ <?= $related['likes_count'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
class VideoPlayer {
    constructor() {
        this.video = document.getElementById('videoPlayer');
        this.progressFill = document.getElementById('progressFill');
        this.isPlaying = false;
        this.isMuted = false;
        
        this.init();
    }
    
    init() {
        // Auto play video
        this.video.play().catch(e => {
            console.log('Autoplay prevented:', e);
        });
        
        // Progress update
        this.video.addEventListener('timeupdate', () => {
            this.updateProgress();
        });
        
        // Click to play/pause
        this.video.addEventListener('click', () => {
            this.togglePlay();
        });
        
        // Double click to like
        this.video.addEventListener('dblclick', () => {
            this.handleDoubleClick();
        });
    }
    
    togglePlay() {
        if (this.video.paused) {
            this.video.play();
            this.isPlaying = true;
        } else {
            this.video.pause();
            this.isPlaying = false;
        }
    }
    
    toggleMute() {
        this.video.muted = !this.video.muted;
        this.isMuted = this.video.muted;
        return this.isMuted;
    }
    
    updateProgress() {
        const progress = (this.video.currentTime / this.video.duration) * 100;
        this.progressFill.style.width = progress + '%';
    }
    
    handleDoubleClick() {
        // Show like animation
        const likeBtn = document.getElementById('likeButton');
        likeBtn.classList.add('pulse');
        setTimeout(() => likeBtn.classList.remove('pulse'), 500);
        
        // Like the video if not already liked
        if (!likeBtn.classList.contains('liked')) {
            toggleLike();
        }
    }
}

// Global variables
const videoPlayer = new VideoPlayer();
let isCommentsOpen = false;

// Navigation
function goBack() {
    window.history.back();
}

function openVideo(videoId) {
    window.location.href = `view_video.php?id=${videoId}`;
}

// Like functionality
async function toggleLike() {
    const likeBtn = document.getElementById('likeButton');
    const likeCount = document.getElementById('likeCount');
    const isLiked = likeBtn.classList.contains('liked');
    
    try {
        const formData = new FormData();
        formData.append('action', isLiked ? 'unlike' : 'like');
        
        const response = await fetch(`view_video.php?id=<?= $videoId ?>`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            likeBtn.classList.toggle('liked');
            likeCount.textContent = result.likes_count;
            
            // Show animation
            likeBtn.classList.add('pulse');
            setTimeout(() => likeBtn.classList.remove('pulse'), 500);
        }
    } catch (error) {
        console.error('Like error:', error);
    }
}

// Follow functionality
async function toggleFollow() {
    const followBtn = document.getElementById('followButton');
    const isFollowing = followBtn.classList.contains('following');
    
    try {
        const formData = new FormData();
        formData.append('action', isFollowing ? 'unfollow' : 'follow');
        
        const response = await fetch(`view_video.php?id=<?= $videoId ?>`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            followBtn.classList.toggle('following');
            followBtn.textContent = isFollowing ? 'Follow' : 'Following';
        }
    } catch (error) {
        console.error('Follow error:', error);
    }
}

// Comments functionality
function openComments() {
    const commentsPanel = document.getElementById('commentsPanel');
    commentsPanel.classList.add('open');
    isCommentsOpen = true;
    document.getElementById('commentInput').focus();
}

function closeComments() {
    const commentsPanel = document.getElementById('commentsPanel');
    commentsPanel.classList.remove('open');
    isCommentsOpen = false;
}

// Comment form submission
document.getElementById('commentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const commentInput = document.getElementById('commentInput');
    const commentText = commentInput.value.trim();
    
    if (!commentText) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'add_comment');
        formData.append('comment', commentText);
        
        // You would need to implement the comment addition endpoint
        // For now, just add it locally
        addCommentLocally(commentText);
        commentInput.value = '';
        
    } catch (error) {
        console.error('Comment error:', error);
    }
});

function addCommentLocally(commentText) {
    const commentsList = document.getElementById('commentsList');
    const commentCount = document.getElementById('commentCount');
    
    const commentItem = document.createElement('div');
    commentItem.className = 'comment-item';
    commentItem.innerHTML = `
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="<?= htmlspecialchars($username) ?>" class="comment-avatar">
        <div class="comment-content">
            <div class="comment-author">@<?= htmlspecialchars($username) ?></div>
            <div class="comment-text">${commentText}</div>
            <div class="comment-actions">
                <button class="comment-action">❤️ 0</button>
                <span>Just now</span>
            </div>
        </div>
    `;
    
    commentsList.insertBefore(commentItem, commentsList.firstChild);
    
    // Update comment count
    const currentCount = parseInt(commentCount.textContent);
    commentCount.textContent = currentCount + 1;
}

// Share functionality
function shareVideo() {
    if (navigator.share) {
        navigator.share({
            title: '<?= htmlspecialchars($video['post_header'] ?? 'Check out this video') ?>',
            text: '<?= htmlspecialchars($video['post_header'] ?? 'Amazing video on Fbclone') ?>',
            url: window.location.href
        });
    } else {
        // Fallback: copy to clipboard
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Link copied to clipboard!');
        });
    }
}

// Header scroll effect
window.addEventListener('scroll', () => {
    const header = document.getElementById('videoHeader');
    if (window.scrollY > 50) {
        header.classList.add('scrolled');
    } else {
        header.classList.remove('scrolled');
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    if (isCommentsOpen) return;
    
    switch(e.key) {
        case ' ':
        case 'k':
            e.preventDefault();
            videoPlayer.togglePlay();
            break;
        case 'm':
            e.preventDefault();
            videoPlayer.toggleMute();
            break;
        case 'ArrowLeft':
            e.preventDefault();
            videoPlayer.video.currentTime -= 5;
            break;
        case 'ArrowRight':
            e.preventDefault();
            videoPlayer.video.currentTime += 5;
            break;
    }
});

// Initialize video hover for related videos
document.addEventListener('DOMContentLoaded', () => {
    const relatedVideos = document.querySelectorAll('.related-item video');
    
    relatedVideos.forEach(video => {
        video.parentElement.addEventListener('mouseenter', () => {
            video.play().catch(e => console.log('Autoplay prevented'));
        });
        
        video.parentElement.addEventListener('mouseleave', () => {
            video.pause();
            video.currentTime = 0;
        });
    });
});
</script>

</body>
</html>