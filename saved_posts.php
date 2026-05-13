<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];

// Handle unsave action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unsave' && isset($_POST['post_id'])) {
    $postId = (int)$_POST['post_id'];
    
    try {
        // Try to delete from post_saves table if it exists
        $stmt = $pdo->prepare("DELETE FROM post_saves WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$userId, $postId]);
        
        // Also remove from session storage if exists
        if (isset($_SESSION['saved_posts'][$postId])) {
            unset($_SESSION['saved_posts'][$postId]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        // Fallback to session storage
        if (isset($_SESSION['saved_posts'][$postId])) {
            unset($_SESSION['saved_posts'][$postId]);
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Failed to unsave post']);
        exit;
    }
}

// Fetch saved posts
try {
    // Try to get saved posts from post_saves table
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.id AS author_id, ps.created_at as saved_at
        FROM post_saves ps
        JOIN posts p ON ps.post_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE ps.user_id = ?
        ORDER BY ps.created_at DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $savedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Fallback to session storage
    $savedPosts = [];
    if (isset($_SESSION['saved_posts']) && !empty($_SESSION['saved_posts'])) {
        $savedPostIds = array_keys($_SESSION['saved_posts']);
        if (!empty($savedPostIds)) {
            $placeholders = implode(',', array_fill(0, count($savedPostIds), '?'));
            $query = "
                SELECT p.*, u.username, u.profile_pic_url, u.id AS author_id, NOW() as saved_at
                FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.id IN ($placeholders)
                ORDER BY p.created_at DESC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($savedPostIds);
            $savedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Helper functions (same as in home.php)
function getLikesCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function formatPostDate($dateString) {
    $postDate = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($postDate);
    
    if ($diff->days === 0) {
        if ($diff->h > 0) return $diff->h . 'h';
        if ($diff->i > 0) return $diff->i . 'm';
        return 'Just now';
    } elseif ($diff->days === 1) {
        return '1d';
    } elseif ($diff->days <= 7) {
        return $diff->days . 'd';
    } else {
        return $postDate->format('M j, Y');
    }
}

// User profile data
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$profilePicUrl = $userData['profile_pic_url'] ?: 'default_profile.png';
$username = $userData['username'];

require_once "header.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Saved Posts - Instagram Clone</title>
<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px; 
    margin: 0 auto; 
    background: #fafafa; 
    color: #262626;
}

.page-header {
    background: white;
    border-bottom: 1px solid #dbdbdb;
    padding: 16px;
    text-align: center;
    position: sticky;
    top: 0;
    z-index: 100;
}

.page-header h1 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #262626;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px;
    background: white;
    border-bottom: 1px solid #dbdbdb;
}

.user-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e1306c;
}

.user-details h2 {
    margin: 0 0 4px 0;
    font-size: 16px;
    font-weight: 600;
}

.user-details p {
    margin: 0;
    color: #8e8e8e;
    font-size: 14px;
}

.saved-posts {
    display: flex;
    flex-direction: column;
    gap: 15px;
    padding: 16px;
}

.post {
    background: white;
    border: 1px solid #dbdbdb;
    border-radius: 8px;
    overflow: hidden;
}

.post-header {
    display: flex;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid #efefef;
}

.post-header img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    border: 1px solid #dbdbdb;
}

.post-header .username {
    margin-left: 12px;
    font-weight: 600;
    cursor: pointer;
    color: #262626;
    flex-grow: 1;
    font-size: 14px;
}

.post-date {
    color: #8e8e8e;
    font-size: 12px;
}

.post-media {
    width: 100%;
    background: #000;
}

.post-media img, .post-media video {
    width: 100%;
    height: auto;
    display: block;
}

.instagram-actions {
    padding: 8px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #efefef;
}

.action-buttons {
    display: flex;
    gap: 16px;
}

.action-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
}

.action-btn.liked { color: #ed4956; }
.action-btn.saved { color: #262626; }
.action-btn.saved.active { color: #262626; }

.unsave-btn {
    background: none;
    border: none;
    color: #ed4956;
    font-size: 20px;
    cursor: pointer;
    padding: 0;
    margin-left: auto;
}

.likes-count {
    font-weight: 600;
    font-size: 14px;
    padding: 0 16px 8px;
}

.post-content {
    padding: 0 16px 8px;
    font-size: 14px;
    line-height: 1.4;
}

.username-in-content {
    font-weight: 600;
    margin-right: 4px;
}

.post-caption {
    margin-bottom: 8px;
}

.view-comments {
    color: #8e8e8e;
    font-size: 14px;
    cursor: pointer;
    margin-bottom: 8px;
}

.post-time {
    color: #8e8e8e;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.saved-time {
    color: #0095f6;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #8e8e8e;
    background: white;
    border-radius: 8px;
    margin: 20px;
    border: 1px solid #dbdbdb;
}

.empty-state h3 {
    color: #262626;
    margin-bottom: 12px;
}

.empty-state p {
    margin-bottom: 20px;
    line-height: 1.5;
}

.back-to-feed {
    background: #0095f6;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.back-to-feed:hover {
    background: #0081d6;
}

.follow-btn {
    background: #0095f6;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.follow-btn.following {
    background: #efefef;
    color: #262626;
}

.loading {
    text-align: center;
    padding: 20px;
    color: #8e8e8e;
    display: none;
}

@media (max-width: 600px) {
    .saved-posts {
        padding: 8px;
    }
    
    .user-info {
        padding: 16px;
    }
}
</style>
</head>
<body>

<!-- Page Header -->
<div class="page-header">
    <h1>Saved Posts</h1>
</div>

<!-- User Info -->
<div class="user-info">
    <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="Your Profile" class="user-avatar" 
         onclick="window.location='profile.php?id=<?= $currentUserId ?>'">
    <div class="user-details">
        <h2><?= htmlspecialchars($username) ?></h2>
        <p><?= count($savedPosts) ?> saved post<?= count($savedPosts) !== 1 ? 's' : '' ?></p>
    </div>
</div>

<!-- Saved Posts Container -->
<div class="saved-posts" id="savedPostsContainer">
    <?php if (empty($savedPosts)): ?>
        <div class="empty-state">
            <h3>No saved posts yet</h3>
            <p>When you save posts, they will appear here for easy access later.</p>
            <button class="back-to-feed" onclick="window.location='home.php'">
                Back to Feed
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($savedPosts as $post): ?>
            <?php displaySavedPost($post, $pdo, $userId); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Event listeners for like, unsave, and follow buttons
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('like-btn')) {
            handleLikeClick(e.target);
        }
        if (e.target.classList.contains('unsave-btn')) {
            handleUnsaveClick(e.target);
        }
        if (e.target.classList.contains('follow-btn')) {
            handleFollowClick(e.target);
        }
    });
});

async function handleLikeClick(likeBtn) {
    const postId = likeBtn.dataset.postId;
    const isLiked = likeBtn.classList.contains('liked');
    
    try {
        const formData = new FormData();
        formData.append('action', isLiked ? 'unlike' : 'like');
        formData.append('post_id', postId);
        
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.like-btn[data-post-id="${postId}"]`).forEach(btn => {
                btn.classList.toggle('liked');
                btn.innerHTML = btn.classList.contains('liked') ? '❤️' : '🤍';
            });
            
            // Update likes count
            document.querySelectorAll(`[data-post-id="${postId}"] .likes-count`).forEach(el => {
                el.textContent = data.likes_count + ' likes';
            });
        }
    } catch (error) {
        console.error('Error updating like:', error);
    }
}

async function handleUnsaveClick(unsaveBtn) {
    const postId = unsaveBtn.dataset.postId;
    
    if (!confirm('Are you sure you want to remove this post from your saved items?')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'unsave');
        formData.append('post_id', postId);
        
        const response = await fetch('saved_posts.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Remove the post from the DOM
            const postElement = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (postElement) {
                postElement.style.opacity = '0';
                postElement.style.transform = 'translateX(-100px)';
                setTimeout(() => {
                    postElement.remove();
                    
                    // Update saved posts count
                    const remainingPosts = document.querySelectorAll('.post').length;
                    const countElement = document.querySelector('.user-details p');
                    if (countElement) {
                        countElement.textContent = remainingPosts + ' saved post' + (remainingPosts !== 1 ? 's' : '');
                    }
                    
                    // Show empty state if no posts left
                    if (remainingPosts === 0) {
                        document.getElementById('savedPostsContainer').innerHTML = `
                            <div class="empty-state">
                                <h3>No saved posts yet</h3>
                                <p>When you save posts, they will appear here for easy access later.</p>
                                <button class="back-to-feed" onclick="window.location='home.php'">
                                    Back to Feed
                                </button>
                            </div>
                        `;
                    }
                }, 300);
            }
        } else {
            alert('Failed to unsave post. Please try again.');
        }
    } catch (error) {
        console.error('Error unsaving post:', error);
        alert('Error unsaving post. Please try again.');
    }
}

async function handleFollowClick(followBtn) {
    const userId = followBtn.dataset.userId;
    const isFollowing = followBtn.classList.contains('following');
    
    try {
        const formData = new FormData();
        formData.append('action', isFollowing ? 'unfollow' : 'follow');
        formData.append('followed_id', userId);
        
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(btn => {
                if (data.action === 'followed') {
                    btn.textContent = 'Following';
                    btn.classList.add('following');
                } else {
                    btn.textContent = 'Follow';
                    btn.classList.remove('following');
                }
            });
        }
    } catch (error) {
        console.error('Error updating follow status:', error);
    }
}

function sharePost(postId) {
    if (navigator.share) {
        navigator.share({
            title: 'Check out this post!',
            url: window.location.origin + '/post.php?id=' + postId
        });
    } else {
        alert('Share functionality coming soon! Post ID: ' + postId);
    }
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

</body>
</html>

<?php
// Function to display a saved post
function displaySavedPost($post, $pdo, $userId) {
    $isLiked = userLikedPost($pdo, $userId, $post['id']);
    $likesCount = getLikesCount($pdo, $post['id']);
    $commentsCount = getCommentCount($pdo, $post['id']);
    $savedTime = formatPostDate($post['saved_at']);
    
    // Check if following
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?");
    $stmt->execute([$userId, $post['author_id']]);
    $isFollowing = (bool)$stmt->fetchColumn();
    ?>
    <div class="post" data-post-id="<?= $post['id'] ?>">
        <div class="post-header">
            <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                 alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
            <div class="username"
                 onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                <?= htmlspecialchars($post['username']) ?>
            </div>
            
            <?php if ($post['author_id'] !== $userId): ?>
                <button class="follow-btn <?= $isFollowing ? 'following' : '' ?>" 
                        data-user-id="<?= $post['author_id'] ?>">
                    <?= $isFollowing ? 'Following' : 'Follow' ?>
                </button>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($post['media_url'])): ?>
            <div class="post-media">
                <?php
                $mediaArray = explode(',', $post['media_url']);
                $firstMedia = trim($mediaArray[0]);
                if ($post['post_type'] === 'photo'): ?>
                    <img src="<?= htmlspecialchars($firstMedia) ?>" alt="Post image"
                         onclick="window.location='full_image.php?img=<?= urlencode($firstMedia) ?>'">
                <?php elseif ($post['post_type'] === 'video'): ?>
                    <video controls>
                        <source src="<?= htmlspecialchars($firstMedia) ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="instagram-actions">
            <div class="action-buttons">
                <button class="action-btn like-btn <?= $isLiked ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                    <?= $isLiked ? '❤️' : '🤍' ?>
                </button>
                <button class="action-btn comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                    💬
                </button>
                <button class="action-btn share-btn" onclick="sharePost(<?= $post['id'] ?>)">
                    🔄
                </button>
            </div>
            <button class="unsave-btn" data-post-id="<?= $post['id'] ?>" title="Remove from saved">
                📕
            </button>
        </div>
        
        <div class="likes-count"><?= $likesCount ?> likes</div>
        
        <div class="post-content">
            <div class="post-caption">
                <span class="username-in-content"><?= htmlspecialchars($post['username']) ?></span>
                <?= htmlspecialchars($post['content']) ?>
            </div>
            
            <?php if ($commentsCount > 0): ?>
                <div class="view-comments" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                    View all <?= $commentsCount ?> comments
                </div>
            <?php endif; ?>
            
            <div class="post-time">
                <span><?= htmlspecialchars(formatPostDate($post['created_at'])) ?></span>
                <span class="saved-time">Saved <?= htmlspecialchars($savedTime) ?></span>
            </div>
        </div>
    </div>
    <?php
}
?>