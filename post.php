<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Get post ID from URL
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($postId <= 0) {
    header('Location: home.php');
    exit;
}

$userId = $_SESSION['user_id'];

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Follow/Unfollow handler
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
        $followerId = $_SESSION['user_id'];
        $followedId = (int)$_POST['followed_id'];
        if ($followerId && $followedId && $followerId !== $followedId) {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$followerId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'followed']);
                exit;
            } else {
                $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
                $stmt->execute([$followerId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'unfollowed']);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid operation']);
        exit;
    }

    // Save/Unsave handler
    if (isset($_POST['action']) && in_array($_POST['action'], ['save', 'unsave']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        try {
            if ($_POST['action'] === 'save') {
                $stmt = $pdo->prepare("INSERT INTO post_saves (user_id, post_id, created_at) VALUES (?, ?, NOW()) ON CONFLICT DO NOTHING");
                $stmt->execute([$userId, $postId]);
                echo json_encode(['success' => true, 'action' => 'saved']);
            } else {
                $stmt = $pdo->prepare("DELETE FROM post_saves WHERE user_id = ? AND post_id = ?");
                $stmt->execute([$userId, $postId]);
                echo json_encode(['success' => true, 'action' => 'unsaved']);
            }
        } catch (Exception $e) {
            if (!isset($_SESSION['saved_posts'])) {
                $_SESSION['saved_posts'] = [];
            }
            
            if ($_POST['action'] === 'save') {
                $_SESSION['saved_posts'][$postId] = true;
                echo json_encode(['success' => true, 'action' => 'saved']);
            } else {
                unset($_SESSION['saved_posts'][$postId]);
                echo json_encode(['success' => true, 'action' => 'unsaved']);
            }
        }
        exit;
    }

    // Like/Unlike handler
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid post id']);
            exit;
        }
        if ($_POST['action'] === 'like') {
            $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (:post_id, :user_id) ON CONFLICT DO NOTHING");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = :post_id AND user_id = :user_id");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
        $stmt->execute(['post_id' => $postId]);
        $likesCount = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'likes_count' => $likesCount]);
        exit;
    }

    // Comment handler
    if (isset($_POST['action']) && $_POST['action'] === 'add_comment' && isset($_POST['post_id']) && isset($_POST['comment_text'])) {
        $postId = (int)$_POST['post_id'];
        $commentText = trim($_POST['comment_text']);
        
        if ($postId <= 0 || empty($commentText)) {
            echo json_encode(['success' => false, 'message' => 'Invalid comment']);
            exit;
        }
        
        $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, comment_text) VALUES (?, ?, ?)");
        $stmt->execute([$postId, $userId, $commentText]);
        
        $commentStmt = $pdo->prepare("
            SELECT c.*, u.username, u.profile_pic_url 
            FROM comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.id = ?
        ");
        $commentStmt->execute([$pdo->lastInsertId()]);
        $newComment = $commentStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'comment' => $newComment,
            'comments_count' => getCommentCount($pdo, $postId)
        ]);
        exit;
    }
}

// Fetch the specific post
$postStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, u.id AS author_id
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = :user_id AND gm.status = 'approved'
    WHERE p.id = :post_id
    AND (
        p.privacy_setting = 'public'
        OR (p.privacy_setting = 'friends' AND EXISTS (
            SELECT 1 FROM friends f WHERE 
            ((f.user_id = :user_id AND f.friend_id = p.user_id) 
             OR (f.friend_id = :user_id AND f.user_id = p.user_id)) 
             AND f.status = 'accepted'))
        OR (p.privacy_setting = 'private' AND p.user_id = :user_id)
        OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
    )
");
$postStmt->execute(['post_id' => $postId, 'user_id' => $userId]);
$post = $postStmt->fetch(PDO::FETCH_ASSOC);

// If post doesn't exist or user doesn't have permission
if (!$post) {
    header('Location: home.php');
    exit;
}

// Check if current user follows the post author
$followStmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?");
$followStmt->execute([$userId, $post['author_id']]);
$post['is_following'] = (bool)$followStmt->fetchColumn();

// Check if user saved post
$post['is_saved'] = userSavedPost($pdo, $userId, $post['id']);

// Fetch comments for this post
$commentsStmt = $pdo->prepare("
    SELECT c.*, u.username, u.profile_pic_url 
    FROM comments c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.post_id = ? 
    ORDER BY c.created_at ASC
");
$commentsStmt->execute([$postId]);
$comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
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

function userSavedPost($pdo, $userId, $postId) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM post_saves WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return isset($_SESSION['saved_posts'][$postId]);
    }
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
        return $postDate->format('M j');
    }
}

function formatCommentDate($dateString) {
    $commentDate = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($commentDate);
    
    if ($diff->days === 0) {
        if ($diff->h > 0) return $diff->h . 'h';
        if ($diff->i > 0) return $diff->i . 'm';
        return 'Just now';
    } elseif ($diff->days === 1) {
        return '1d';
    } elseif ($diff->days <= 7) {
        return $diff->days . 'd';
    } else {
        return $commentDate->format('M j');
    }
}

// User profile data
$currentUserId = $_SESSION['user_id'];
$userStmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$userStmt->execute([$currentUserId]);
$profilePicUrl = $userStmt->fetchColumn() ?: 'default_profile.png';

require_once "header.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Post - <?= htmlspecialchars($post['username']) ?></title>
<style>
/* INSTAGRAM-STYLE CSS */
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px; 
    margin: 0 auto; 
    background: #fafafa; 
    color: #262626;
}

.post-container {
    margin-top: 10px;
}

.post {
    background: white;
    border: 1px solid #dbdbdb;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 20px;
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

.save-btn { margin-left: auto; }

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

/* Comments Section */
.comments-section {
    margin-top: 20px;
}

.comments-header {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 15px;
    color: #262626;
    padding: 0 16px;
}

.comment {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
    padding: 0 16px;
}

.comment-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    flex-shrink: 0;
}

.comment-content {
    flex-grow: 1;
}

.comment-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.comment-username {
    font-weight: 600;
    color: #262626;
    cursor: pointer;
    font-size: 14px;
}

.comment-date {
    color: #8e8e8e;
    font-size: 12px;
}

.comment-text {
    font-size: 14px;
    line-height: 1.4;
    color: #262626;
}

.no-comments {
    text-align: center;
    color: #8e8e8e;
    font-style: italic;
    padding: 40px 20px;
}

/* Add Comment Form */
.add-comment {
    display: flex;
    gap: 12px;
    padding: 16px;
    border-top: 1px solid #efefef;
    background: white;
    position: sticky;
    bottom: 0;
}

.comment-input {
    flex-grow: 1;
    padding: 12px;
    border: 1px solid #dbdbdb;
    border-radius: 20px;
    background: #fafafa;
    color: #262626;
    font-size: 14px;
    resize: none;
    height: 40px;
    font-family: inherit;
}

.comment-input:focus {
    outline: none;
    border-color: #a8a8a8;
}

.comment-submit {
    padding: 8px 16px;
    border: none;
    border-radius: 20px;
    background: #0095f6;
    color: white;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.3s ease;
}

.comment-submit:hover:not(:disabled) {
    background: #0081d6;
}

.comment-submit:disabled {
    background: #b2dffc;
    cursor: not-allowed;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: #0095f6;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    margin: 16px;
    transition: background 0.3s ease;
}

.back-btn:hover {
    background: #0081d6;
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
    font-size: 16px;
    font-weight: 600;
    color: #262626;
}

@media (max-width: 600px) {
    body {
        padding: 0;
    }
    
    .post-container {
        margin-top: 0;
    }
    
    .add-comment {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
    }
    
    .comments-section {
        margin-bottom: 80px;
    }
}
</style>
</head>
<body>

<!-- Page Header -->
<div class="page-header">
    <h1>Post</h1>
</div>

<!-- Back Button -->
<a href="home.php" class="back-btn">← Back to Feed</a>

<div class="post-container">
    <div class="post" data-post-id="<?= $post['id'] ?>">
        <div class="post-header">
            <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                 alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
            <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                <?= htmlspecialchars($post['username']) ?>
            </div>
            <div class="post-date"><?= formatPostDate($post['created_at']) ?></div>
            
            <?php if ($post['author_id'] !== $userId): ?>
                <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
                    <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                </button>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($post['media_url'])): ?>
            <div class="post-media">
                <?php
                $mediaArray = explode(',', $post['media_url']);
                $firstMedia = trim($mediaArray[0]);
                if ($post['post_type'] === 'photo'): ?>
                    <img src="<?= htmlspecialchars($firstMedia) ?>" alt="Post image">
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
                <button class="action-btn like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                    <?= userLikedPost($pdo, $userId, $post['id']) ? '❤️' : '🤍' ?>
                </button>
                <button class="action-btn comment-btn">
                    💬
                </button>
                <button class="action-btn share-btn" onclick="sharePost(<?= $post['id'] ?>)">
                    🔄
                </button>
            </div>
            <button class="action-btn save-btn <?= $post['is_saved'] ? 'active' : '' ?>" data-post-id="<?= $post['id'] ?>">
                <?= $post['is_saved'] ? '📕' : '📖' ?>
            </button>
        </div>
        
        <div class="likes-count"><?= getLikesCount($pdo, $post['id']) ?> likes</div>
        
        <div class="post-content">
            <div class="post-caption">
                <span class="username-in-content"><?= htmlspecialchars($post['username']) ?></span>
                <?= htmlspecialchars($post['content']) ?>
            </div>
            
            <div class="post-time"><?= formatPostDate($post['created_at']) ?></div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section">
            <div class="comments-header">
                Comments (<span class="comment-count"><?= count($comments) ?></span>)
            </div>
            
            <div class="comments-list" id="commentsList">
                <?php if (empty($comments)): ?>
                    <div class="no-comments">No comments yet. Be the first to comment!</div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment" data-comment-id="<?= $comment['id'] ?>">
                            <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                 alt="Profile" class="comment-avatar"
                                 onclick="window.location='profile.php?id=<?= $comment['user_id'] ?>'">
                            <div class="comment-content">
                                <div class="comment-header">
                                    <span class="comment-username" onclick="window.location='profile.php?id=<?= $comment['user_id'] ?>'">
                                        <?= htmlspecialchars($comment['username']) ?>
                                    </span>
                                    <span class="comment-date"><?= formatCommentDate($comment['created_at']) ?></span>
                                </div>
                                <div class="comment-text"><?= htmlspecialchars($comment['comment_text']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Comment Form -->
<div class="add-comment">
    <textarea class="comment-input" id="commentInput" placeholder="Add a comment..." rows="1"></textarea>
    <button class="comment-submit" id="commentSubmit" disabled>Post</button>
</div>

<script>
// Like functionality
document.querySelector('.like-btn').addEventListener('click', async function() {
    const likeBtn = this;
    const postId = likeBtn.dataset.postId;
    const isLiked = likeBtn.classList.contains('liked');
    
    try {
        const formData = new FormData();
        formData.append('action', isLiked ? 'unlike' : 'like');
        formData.append('post_id', postId);
        
        const response = await fetch('post.php?id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            likeBtn.classList.toggle('liked');
            likeBtn.innerHTML = likeBtn.classList.contains('liked') ? '❤️' : '🤍';
            document.querySelector('.likes-count').textContent = data.likes_count + ' likes';
        }
    } catch (error) {
        console.error('Error updating like:', error);
    }
});

// Save functionality
document.querySelector('.save-btn').addEventListener('click', async function() {
    const saveBtn = this;
    const postId = saveBtn.dataset.postId;
    const isSaved = saveBtn.classList.contains('active');
    
    try {
        const formData = new FormData();
        formData.append('action', isSaved ? 'unsave' : 'save');
        formData.append('post_id', postId);
        
        const response = await fetch('post.php?id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            saveBtn.classList.toggle('active');
            saveBtn.innerHTML = saveBtn.classList.contains('active') ? '📕' : '📖';
        }
    } catch (error) {
        console.error('Error updating save:', error);
    }
});

// Follow functionality
document.querySelector('.follow-btn')?.addEventListener('click', async function() {
    const followBtn = this;
    const userId = followBtn.dataset.userId;
    const isFollowing = followBtn.classList.contains('following');
    
    try {
        const formData = new FormData();
        formData.append('action', isFollowing ? 'unfollow' : 'follow');
        formData.append('followed_id', userId);
        
        const response = await fetch('post.php?id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (data.action === 'followed') {
                followBtn.textContent = 'Following';
                followBtn.classList.add('following');
            } else {
                followBtn.textContent = 'Follow';
                followBtn.classList.remove('following');
            }
        }
    } catch (error) {
        console.error('Error updating follow status:', error);
    }
});

// Comment functionality
const commentInput = document.getElementById('commentInput');
const commentSubmit = document.getElementById('commentSubmit');
const commentsList = document.getElementById('commentsList');
const commentCountElements = document.querySelectorAll('.comment-count');

commentInput.addEventListener('input', function() {
    commentSubmit.disabled = this.value.trim() === '';
});

commentInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!commentSubmit.disabled) {
            addComment();
        }
    }
});

commentSubmit.addEventListener('click', addComment);

async function addComment() {
    const commentText = commentInput.value.trim();
    if (!commentText) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'add_comment');
        formData.append('post_id', <?= $postId ?>);
        formData.append('comment_text', commentText);
        
        const response = await fetch('post.php?id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Clear input
            commentInput.value = '';
            commentSubmit.disabled = true;
            
            // Add new comment to list
            const comment = data.comment;
            const commentElement = createCommentElement(comment);
            
            // Remove "no comments" message if it exists
            const noComments = commentsList.querySelector('.no-comments');
            if (noComments) {
                noComments.remove();
            }
            
            commentsList.appendChild(commentElement);
            
            // Update comment counts
            commentCountElements.forEach(el => {
                el.textContent = data.comments_count;
            });
            
            // Scroll to new comment
            commentElement.scrollIntoView({ behavior: 'smooth' });
        }
    } catch (error) {
        console.error('Error adding comment:', error);
    }
}

function createCommentElement(comment) {
    const div = document.createElement('div');
    div.className = 'comment';
    div.dataset.commentId = comment.id;
    
    div.innerHTML = `
        <img src="${escapeHtml(comment.profile_pic_url || 'default_profile.png')}" 
             alt="Profile" class="comment-avatar"
             onclick="window.location='profile.php?id=${comment.user_id}'">
        <div class="comment-content">
            <div class="comment-header">
                <span class="comment-username" onclick="window.location='profile.php?id=${comment.user_id}'">
                    ${escapeHtml(comment.username)}
                </span>
                <span class="comment-date">Just now</span>
            </div>
            <div class="comment-text">${escapeHtml(comment.comment_text)}</div>
        </div>
    `;
    
    return div;
}

function sharePost(postId) {
    const postUrl = window.location.origin + '/post.php?id=' + postId;
    if (navigator.share) {
        navigator.share({
            title: 'Check out this post!',
            url: postUrl
        });
    } else {
        navigator.clipboard.writeText(postUrl).then(() => {
            alert('Post link copied to clipboard!');
        }).catch(() => {
            alert('Share URL: ' + postUrl);
        });
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

// Auto-resize textarea
commentInput.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
});
</script>

</body>
</html>