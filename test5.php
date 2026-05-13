<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Instagram-style session tracking
if (!isset($_SESSION['last_refresh'])) {
    $_SESSION['last_refresh'] = time();
    $_SESSION['viewed_posts'] = [];
}

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

    // Save/Unsave handler (using likes table for simplicity)
    if (isset($_POST['action']) && in_array($_POST['action'], ['save', 'unsave']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        // For now, we'll use a separate table or reuse likes with a type field
        // Let's create a simple bookmark system using the existing structure
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
            // If table doesn't exist, fall back to session storage
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

    // Record post view for algorithm
    if (isset($_POST['action']) && $_POST['action'] === 'record_view' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        // Record view if not already viewed in this session
        if (!in_array($postId, $_SESSION['viewed_posts'])) {
            $_SESSION['viewed_posts'][] = $postId;
        }
        echo json_encode(['success' => true]);
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
        
        // Get updated counts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
        $stmt->execute(['post_id' => $postId]);
        $likesCount = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'likes_count' => $likesCount]);
        exit;
    }

    // Infinite scroll with Instagram algorithm
    if (isset($_POST['action']) && $_POST['action'] === 'load_more_posts') {
        $userId = $_SESSION['user_id'];
        $offset = (int)($_POST['offset'] ?? 0);
        $limit = 10;
        
        $posts = getInstagramStyleFeed($pdo, $userId, $limit, $offset);
        $hasMore = count($posts) >= $limit;
        
        echo json_encode([
            'success' => true,
            'posts' => $posts,
            'has_more' => $hasMore
        ]);
        exit;
    }
}

// INSTAGRAM-STYLE FEED ALGORITHM (Using only your existing tables)
function getInstagramStyleFeed($pdo, $userId, $limit = 10, $offset = 0) {
    /**
     * Instagram-style algorithm using your existing tables:
     * - follows, friends, likes, comments, posts, users
     */
    
    $query = "
        WITH user_engagement AS (
            -- Calculate user's engagement with other users
            SELECT 
                p.user_id,
                COUNT(DISTINCT l.id) * 2 as like_score,
                COUNT(DISTINCT c.id) * 3 as comment_score,
                COUNT(DISTINCT CASE WHEN f.follower_id = ? THEN f.followed_id END) as follow_score
            FROM posts p
            LEFT JOIN likes l ON p.id = l.post_id AND l.user_id = ?
            LEFT JOIN comments c ON p.id = c.post_id AND c.user_id = ?
            LEFT JOIN follows f ON p.user_id = f.followed_id AND f.follower_id = ?
            GROUP BY p.user_id
        ),
        
        post_metrics AS (
            -- Calculate post engagement metrics
            SELECT 
                p.id as post_id,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT c.id) as comment_count,
                CASE 
                    WHEN p.user_id = ? THEN 1000  -- Boost own posts slightly
                    ELSE 0
                END as own_post_boost
            FROM posts p
            LEFT JOIN likes l ON p.id = l.post_id
            LEFT JOIN comments c ON p.id = c.post_id
            GROUP BY p.id, p.user_id
        ),
        
        relationship_strength AS (
            -- Calculate relationship strength
            SELECT 
                ue.user_id,
                (
                    COALESCE(ue.like_score, 0) + 
                    COALESCE(ue.comment_score, 0) + 
                    COALESCE(ue.follow_score, 0) * 10 +
                    -- Friend bonus (high weight)
                    CASE WHEN EXISTS (
                        SELECT 1 FROM friends f 
                        WHERE ((f.user_id = ? AND f.friend_id = ue.user_id) OR 
                              (f.friend_id = ? AND f.user_id = ue.user_id))
                        AND f.status = 'accepted'
                    ) THEN 50 ELSE 0 END +
                    -- Follow bonus
                    CASE WHEN EXISTS (
                        SELECT 1 FROM follows f 
                        WHERE f.follower_id = ? AND f.followed_id = ue.user_id
                    ) THEN 30 ELSE 0 END
                ) as relationship_score
            FROM user_engagement ue
        )
        
        SELECT 
            p.*,
            u.username,
            u.profile_pic_url,
            u.id AS author_id,
            pm.like_count,
            pm.comment_count,
            COALESCE(rs.relationship_score, 0) as relationship_score,
            
            -- Timeliness score (recent posts get higher score)
            GREATEST(10, 100 - (EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600)) as timeliness_score,
            
            -- Engagement rate (likes + comments)
            (COALESCE(pm.like_count, 0) + COALESCE(pm.comment_count, 0)) as engagement_score,
            
            -- Final relevance score (Instagram-style weighting)
            (
                (COALESCE(rs.relationship_score, 0) * 0.5) +      -- Relationship: 50%
                (GREATEST(10, 100 - (EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600)) * 0.3) +  -- Timeliness: 30%
                (LOG(1 + COALESCE(pm.like_count, 0) + COALESCE(pm.comment_count, 0)) * 20 * 0.2)  -- Engagement: 20%
            ) as relevance_score

        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN post_metrics pm ON p.id = pm.post_id
        LEFT JOIN relationship_strength rs ON p.user_id = rs.user_id
        
        WHERE p.privacy_setting IN ('public', 'friends')
            AND p.group_id IS NULL
            AND (
                -- Show posts from: 
                -- 1. Users the current user follows
                EXISTS (SELECT 1 FROM follows f WHERE f.follower_id = ? AND f.followed_id = p.user_id)
                OR 
                -- 2. Users who are friends with current user
                EXISTS (
                    SELECT 1 FROM friends f 
                    WHERE ((f.user_id = ? AND f.friend_id = p.user_id) OR 
                          (f.friend_id = ? AND f.user_id = p.user_id))
                    AND f.status = 'accepted'
                )
                OR
                -- 3. Popular posts from last 24 hours (discovery)
                (p.created_at >= NOW() - INTERVAL '24 hours' 
                 AND (pm.like_count + pm.comment_count) >= 5)
                OR
                -- 4. User's own posts
                p.user_id = ?
            )
        
        ORDER BY relevance_score DESC, p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        // User engagement params
        $userId, $userId, $userId, $userId,
        // Own post boost
        $userId,
        // Relationship strength params
        $userId, $userId, $userId,
        // Final WHERE clause params
        $userId, $userId, $userId, $userId,
        // Limit/Offset
        $limit, $offset
    ]);
    
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return enhancePosts($pdo, $userId, $posts);
}

// Enhanced post data
function enhancePosts($pdo, $userId, $posts) {
    foreach ($posts as &$post) {
        $post['formatted_date'] = formatPostDate($post['created_at']);
        $post['likes_count'] = $post['like_count'] ?? getLikesCount($pdo, $post['id']);
        $post['comments_count'] = $post['comment_count'] ?? getCommentCount($pdo, $post['id']);
        $post['is_liked'] = userLikedPost($pdo, $userId, $post['id']);
        $post['is_saved'] = userSavedPost($pdo, $userId, $post['id']);
        
        // Check if following
        $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?");
        $stmt->execute([$userId, $post['author_id']]);
        $post['is_following'] = (bool)$stmt->fetchColumn();
        
        // Check if friend
        $stmt = $pdo->prepare("
            SELECT 1 FROM friends 
            WHERE ((user_id = ? AND friend_id = ?) OR (friend_id = ? AND user_id = ?))
            AND status = 'accepted'
        ");
        $stmt->execute([$userId, $post['author_id'], $userId, $post['author_id']]);
        $post['is_friend'] = (bool)$stmt->fetchColumn();
    }
    
    return $posts;
}

// Check if user saved post (session-based fallback)
function userSavedPost($pdo, $userId, $postId) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM post_saves WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Fallback to session storage if table doesn't exist
        return isset($_SESSION['saved_posts'][$postId]);
    }
}

// Keep your existing helper functions
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
        return $postDate->format('M j');
    }
}

// Get initial posts
$userId = $_SESSION['user_id'];
$currentUserId = $_SESSION['user_id'];

// Get initial Instagram-style feed
$initialPosts = getInstagramStyleFeed($pdo, $userId, 15, 0);

// User profile data
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

require_once "header.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Instagram-Style Feed</title>
<style>
/* INSTAGRAM-STYLE CSS */
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px; 
    margin: 0 auto; 
    background: #fafafa; 
    color: #262626;
}

.posts {
    margin-top: 10px;
    display: flex;
    flex-direction: column;
    gap: 15px;
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

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #8e8e8e;
}

.empty-state h3 {
    color: #262626;
    margin-bottom: 12px;
}

.explore-users-btn {
    background: #0095f6;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 16px;
}

.loading {
    text-align: center;
    padding: 20px;
    color: #8e8e8e;
    display: none;
}

.refresh-btn {
    background: #0095f6;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    font-weight: 600;
    cursor: pointer;
    margin: 10px auto;
    display: block;
}
</style>
</head>
<body>

<!-- Refresh Button -->
<button class="refresh-btn" onclick="refreshPage()">🔄 Refresh Feed</button>

<!-- User Profile Header -->
<div style="text-align: center; padding: 15px; background: white; border-bottom: 1px solid #dbdbdb;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid #e1306c;" />
    </a>
</div>

<!-- Create Post Trigger -->
<div id="openPostModalBtn" style="margin: 15px; cursor:pointer;">
    <input type="text" style="background-color: #fafafa; border: 1px solid #dbdbdb; width: 100%; height: 40px; border-radius: 8px; color: #8e8e8e; padding: 0 15px; font-size: 14px;" 
           placeholder="What's on your mind?" readonly>
</div>

<!-- Posts Container -->
<div class="posts" id="postsContainer">
    <?php if (empty($initialPosts)): ?>
        <div class="empty-state">
            <h3>Welcome! 👋</h3>
            <p>Follow some users to see posts in your feed, or create your first post!</p>
            <button class="explore-users-btn" onclick="window.location='explore.php'">
                Explore Users
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($initialPosts as $post): ?>
            <?php displayInstagramPost($post, $pdo, $userId); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading">Loading more posts...</div>

<script>
let currentOffset = <?= count($initialPosts) ?>;
let isLoading = false;
let hasMorePosts = true;

// Refresh function
function refreshPage() {
    const refreshBtn = document.querySelector('.refresh-btn');
    refreshBtn.innerHTML = '⏳ Refreshing...';
    refreshBtn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Infinite scroll
window.addEventListener('scroll', () => {
    if (isLoading || !hasMorePosts) return;
    
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    
    if (scrollTop + clientHeight >= scrollHeight - 200) {
        loadMorePosts();
    }
});

async function loadMorePosts() {
    if (isLoading) return;
    
    isLoading = true;
    const loadingIndicator = document.getElementById('loadingIndicator');
    loadingIndicator.style.display = 'block';
    
    try {
        const formData = new FormData();
        formData.append('action', 'load_more_posts');
        formData.append('offset', currentOffset);
        
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            await appendPosts(data.posts);
            currentOffset += data.posts.length;
            hasMorePosts = data.has_more;
        }
    } catch (error) {
        console.error('Error loading posts:', error);
    } finally {
        isLoading = false;
        loadingIndicator.style.display = 'none';
    }
}

function appendPosts(posts) {
    return new Promise((resolve) => {
        const postsContainer = document.getElementById('postsContainer');
        
        posts.forEach((post) => {
            const postElement = createPostElement(post);
            postsContainer.appendChild(postElement);
        });
        
        resolve();
    });
}

function createPostElement(post) {
    const div = document.createElement('div');
    div.className = 'post';
    div.setAttribute('data-post-id', post.id);
    
    const isLiked = post.is_liked || false;
    const isSaved = post.is_saved || false;
    
    // Media content
    let mediaHTML = '';
    if (post.media_url) {
        const mediaArray = post.media_url.split(',');
        if (post.post_type === 'photo') {
            mediaHTML = `<div class="post-media"><img src="${escapeHtml(mediaArray[0])}" alt="Post image"></div>`;
        } else if (post.post_type === 'video') {
            mediaHTML = `<div class="post-media"><video controls><source src="${escapeHtml(mediaArray[0])}" type="video/mp4"></video></div>`;
        }
    }
    
    div.innerHTML = `        
        <div class="post-header">
            <img src="${escapeHtml(post.profile_pic_url || 'default_profile.png')}"
                 alt="Profile" onclick="window.location='profile.php?id=${post.user_id}'" />
            <div class="username" onclick="window.location='profile.php?id=${post.user_id}'">
                ${escapeHtml(post.username)}
            </div>
            ${post.author_id != <?= $userId ?> ? `
                <button class="follow-btn ${post.is_following ? 'following' : ''}" data-user-id="${post.author_id}">
                    ${post.is_following ? 'Following' : 'Follow'}
                </button>
            ` : ''}
        </div>
        
        ${mediaHTML}
        
        <div class="instagram-actions">
            <div class="action-buttons">
                <button class="action-btn like-btn ${isLiked ? 'liked' : ''}" data-post-id="${post.id}">
                    ${isLiked ? '❤️' : '🤍'}
                </button>
                <button class="action-btn comment-btn" onclick="window.location='comment.php?post_id=${post.id}'">
                    💬
                </button>
                <button class="action-btn share-btn" onclick="sharePost(${post.id})">
                    🔄
                </button>
            </div>
            <button class="action-btn save-btn ${isSaved ? 'active' : ''}" data-post-id="${post.id}">
                ${isSaved ? '📕' : '📖'}
            </button>
        </div>
        
        <div class="likes-count">${post.likes_count || 0} likes</div>
        
        <div class="post-content">
            <div class="post-caption">
                <span class="username-in-content">${escapeHtml(post.username)}</span>
                ${escapeHtml(post.content || '')}
            </div>
            
            ${post.comments_count > 0 ? `
                <div class="view-comments" onclick="window.location='comment.php?post_id=${post.id}'">
                    View all ${post.comments_count} comments
                </div>
            ` : ''}
            
            <div class="post-time">${escapeHtml(post.formatted_date)}</div>
        </div>
    `;
    
    return div;
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

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('like-btn')) {
            handleLikeClick(e.target);
        }
        if (e.target.classList.contains('save-btn')) {
            handleSaveClick(e.target);
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

async function handleSaveClick(saveBtn) {
    const postId = saveBtn.dataset.postId;
    const isSaved = saveBtn.classList.contains('active');
    
    try {
        const formData = new FormData();
        formData.append('action', isSaved ? 'unsave' : 'save');
        formData.append('post_id', postId);
        
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.save-btn[data-post-id="${postId}"]`).forEach(btn => {
                btn.classList.toggle('active');
                btn.innerHTML = btn.classList.contains('active') ? '📕' : '📖';
            });
        }
    } catch (error) {
        console.error('Error updating save:', error);
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
</script>

</body>
</html>

<?php
// Function to display Instagram-style post
function displayInstagramPost($post, $pdo, $userId) {
    $isLiked = userLikedPost($pdo, $userId, $post['id']);
    $isSaved = userSavedPost($pdo, $userId, $post['id']);
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
                <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" 
                        data-user-id="<?= $post['author_id'] ?>">
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
            <button class="action-btn save-btn <?= $isSaved ? 'active' : '' ?>" data-post-id="<?= $post['id'] ?>">
                <?= $isSaved ? '📕' : '📖' ?>
            </button>
        </div>
        
        <div class="likes-count"><?= $post['likes_count'] ?> likes</div>
        
        <div class="post-content">
            <div class="post-caption">
                <span class="username-in-content"><?= htmlspecialchars($post['username']) ?></span>
                <?= htmlspecialchars($post['content']) ?>
            </div>
            
            <?php if ($post['comments_count'] > 0): ?>
                <div class="view-comments" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                    View all <?= $post['comments_count'] ?> comments
                </div>
            <?php endif; ?>
            
            <div class="post-time"><?= htmlspecialchars($post['formatted_date']) ?></div>
        </div>
    </div>
    <?php
}
?>