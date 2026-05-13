<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

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

    // Post handler
    if (isset($_POST['action']) && $_POST['action'] === 'new_post') {
        $userId = $_SESSION['user_id'];
        $content = trim($_POST['content'] ?? '');
        $privacy = $_POST['privacy'] ?? 'public';
        $groupId = ($privacy === 'groups-only' && !empty($_POST['group_id'])) ? (int)$_POST['group_id'] : null;
        $postType = 'text';
        $mediaUrls = [];

        $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/ogg'];
        $allowedPhotoTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxFileSize = 30 * 1024 * 1024;

        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        $hasImages = false;
        $hasVideos = false;
        
        if (!empty($_FILES['media_files']) && is_array($_FILES['media_files']['name'])) {
            for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
                $type = $_FILES['media_files']['type'][$i];
                if (in_array($type, $allowedPhotoTypes)) $hasImages = true;
                if (in_array($type, $allowedVideoTypes)) $hasVideos = true;
            }
            
            if ($hasImages && $hasVideos) {
                echo json_encode(['success' => false, 'message' => 'Cannot upload both images and videos in the same post']);
                exit;
            }
            
            for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
                $name = $_FILES['media_files']['name'][$i];
                $tmpName = $_FILES['media_files']['tmp_name'][$i];
                $type = $_FILES['media_files']['type'][$i];
                $size = $_FILES['media_files']['size'][$i];
                if ($size > $maxFileSize) continue;
                if (in_array($type, array_merge($allowedPhotoTypes, $allowedVideoTypes))) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $filename = uniqid('media_') . '.' . $ext;
                    $destination = $uploadDir . $filename;
                    if (move_uploaded_file($tmpName, $destination)) {
                        $mediaUrls[] = 'uploads/' . $filename;
                        $postType = in_array($type, $allowedVideoTypes) ? 'video' : 'photo';
                    }
                }
            }
        }

        $link = trim($_POST['link'] ?? '');
        if ($link !== '') $postType = 'link';
        $mediaUrlStr = count($mediaUrls) ? implode(',', $mediaUrls) : null;

        $insertPost = $pdo->prepare("
            INSERT INTO posts (user_id, content, post_type, media_url, privacy_setting, group_id)
            VALUES (:user_id, :content, :post_type, :media_url, :privacy, :group_id)
        ");
        $insertPost->execute([
            ':user_id' => $userId,
            ':content' => $content,
            ':post_type' => $postType,
            ':media_url' => $mediaUrlStr,
            ':privacy' => $privacy,
            ':group_id' => $groupId,
        ]);
        header("Location: home.php");
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

    // Infinite scroll handler
    if (isset($_POST['action']) && $_POST['action'] === 'load_more_posts') {
        $userId = $_SESSION['user_id'];
        $offset = (int)($_POST['offset'] ?? 0);
        $stage = $_POST['stage'] ?? 'friends'; // friends, caught_up, popular
        
        $posts = [];
        $nextStage = $stage;
        $hasMore = true;
        
        switch ($stage) {
            case 'friends':
                $posts = getFriendNetworkPosts($pdo, $userId, 10, $offset);
                if (count($posts) < 10) {
                    $nextStage = 'caught_up';
                }
                break;
                
            case 'caught_up':
                // Show "You're caught up" message
                if ($offset === 0) {
                    $posts = [['type' => 'caught_up']];
                }
                $nextStage = 'popular';
                break;
                
            case 'popular':
                $posts = getPopularUsersPosts($pdo, $userId, 10, $offset);
                $hasMore = true; // Always more popular posts
                break;
        }
        
        echo json_encode([
            'success' => true,
            'posts' => $posts,
            'next_stage' => $nextStage,
            'has_more' => $hasMore
        ]);
        exit;
    }
}

// Get friend network posts (up to 4 degrees)
function getFriendNetworkPosts($pdo, $userId, $limit = 10, $offset = 0) {
    $friendIds = getFriendNetworkIds($pdo, $userId);
    
    if (empty($friendIds)) {
        return [];
    }
    
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
    $params = array_merge($friendIds, [$limit, $offset]);
    
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.user_id IN ($placeholders)
        AND p.group_id IS NULL
        AND p.created_at >= NOW() - INTERVAL '3 days'
        AND (p.privacy_setting = 'public' OR p.privacy_setting = 'friends')
        ORDER BY RANDOM()
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return enhancePosts($pdo, $userId, $posts);
}

// Get friend network IDs (up to 4 degrees)
function getFriendNetworkIds($pdo, $userId) {
    $query = "
        WITH RECURSIVE friend_network AS (
            -- Direct friends
            SELECT friend_id, 1 as degree
            FROM friends 
            WHERE user_id = :user_id AND status = 'accepted'
            UNION
            SELECT user_id, 1 as degree
            FROM friends 
            WHERE friend_id = :user_id AND status = 'accepted'
            
            UNION
            
            -- Friends of friends (degree 2-4)
            SELECT 
                CASE WHEN f.user_id = fn.friend_id THEN f.friend_id ELSE f.user_id END as friend_id,
                fn.degree + 1 as degree
            FROM friends f
            JOIN friend_network fn ON (
                (f.user_id = fn.friend_id AND f.status = 'accepted') OR 
                (f.friend_id = fn.friend_id AND f.status = 'accepted')
            )
            WHERE fn.degree < 4 AND 
                  CASE WHEN f.user_id = fn.friend_id THEN f.friend_id ELSE f.user_id END != :user_id
        )
        SELECT DISTINCT friend_id as user_id FROM friend_network
        UNION
        SELECT followed_id as user_id FROM follows WHERE follower_id = :user_id
        UNION
        SELECT :user_id as user_id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get popular users posts (most followed to least)
function getPopularUsersPosts($pdo, $userId, $limit = 10, $offset = 0) {
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id,
               COUNT(f.follower_id) as follower_count
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN follows f ON u.id = f.followed_id
        WHERE p.group_id IS NULL
        AND p.created_at >= NOW() - INTERVAL '30 days'  -- Wider time range for popular posts
        AND (p.privacy_setting = 'public' OR p.privacy_setting = 'friends')
        GROUP BY p.id, u.id
        ORDER BY follower_count DESC, RANDOM()
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$limit, $offset]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return enhancePosts($pdo, $userId, $posts);
}

// Enhance posts with additional data
function enhancePosts($pdo, $userId, $posts) {
    foreach ($posts as &$post) {
        $post['formatted_date'] = formatPostDate($post['created_at']);
        $post['likes_count'] = getLikesCount($pdo, $post['id']);
        $post['comments_count'] = getCommentCount($pdo, $post['id']);
        $post['is_liked'] = userLikedPost($pdo, $userId, $post['id']);
        
        $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?");
        $stmt->execute([$userId, $post['author_id']]);
        $post['is_following'] = (bool)$stmt->fetchColumn();
    }
    
    return $posts;
}

// Format post date
function formatPostDate($dateString) {
    $postDate = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($postDate);
    
    if ($diff->days === 0) {
        if ($diff->h > 0) return $diff->h . 'h';
        if ($diff->i > 0) return $diff->i . 'm';
        return 'Just now';
    } elseif ($diff->days <= 3) {
        return $diff->days . 'd';
    } else {
        return $postDate->format('M j, Y');
    }
}

// Helper functions
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

function getLikesCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Get initial data
$userId = $_SESSION['user_id'];
$currentUserId = $_SESSION['user_id'];

// Get initial posts
$initialPosts = getFriendNetworkPosts($pdo, $userId, 15, 0);

// User profile data
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

// Boosted posts
$boostedPostsStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id, b.boost_end
    FROM boost_posts b
    JOIN posts p ON b.post_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE b.boost_end > NOW()
    ORDER BY random()
    LIMIT 50
");
$boostedPostsStmt->execute();
$boostedPosts = $boostedPostsStmt->fetchAll(PDO::FETCH_ASSOC);

// Add this function to enhance the friend network algorithm
function getEnhancedFriendNetworkIds($pdo, $userId) {
    $query = "
        WITH RECURSIVE friend_network AS (
            -- Direct friends (degree 1)
            SELECT 
                CASE WHEN f.user_id = :user_id THEN f.friend_id ELSE f.user_id END as friend_id,
                1 as degree,
                f.created_at as connection_date
            FROM friends f
            WHERE (f.user_id = :user_id OR f.friend_id = :user_id) 
            AND f.status = 'accepted'
            
            UNION ALL
            
            -- Friends of friends (degree 2-4) with connection strength
            SELECT 
                CASE WHEN f.user_id = fn.friend_id THEN f.friend_id ELSE f.user_id END as friend_id,
                fn.degree + 1 as degree,
                GREATEST(f.created_at, fn.connection_date) as connection_date
            FROM friends f
            JOIN friend_network fn ON (
                (f.user_id = fn.friend_id AND f.status = 'accepted') OR 
                (f.friend_id = fn.friend_id AND f.status = 'accepted')
            )
            WHERE fn.degree < 4 
            AND CASE WHEN f.user_id = fn.friend_id THEN f.friend_id ELSE f.user_id END != :user_id
            AND NOT EXISTS (
                SELECT 1 FROM friend_network fn2 
                WHERE fn2.friend_id = CASE WHEN f.user_id = fn.friend_id THEN f.friend_id ELSE f.user_id END
            )
        )
        SELECT DISTINCT ON (friend_id) 
            friend_id as user_id,
            degree,
            connection_date
        FROM friend_network
        ORDER BY friend_id, degree ASC, connection_date DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Enhanced post scoring system
function getScoredPosts($pdo, $userId, $limit = 10, $offset = 0) {
    $friendNetwork = getEnhancedFriendNetworkIds($pdo, $userId);
    
    if (empty($friendNetwork)) {
        return getPopularUsersPosts($pdo, $userId, $limit, $offset);
    }
    
    $userIds = array_column($friendNetwork, 'user_id');
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    
    // Score posts based on: friend degree, post age, likes count, and user engagement
    $query = "
        SELECT 
            p.*, 
            u.username, 
            u.profile_pic_url, 
            u.is_business_account, 
            u.id AS author_id,
            fn.degree as friend_degree,
            fn.connection_date,
            COUNT(l.id) as likes_count,
            COUNT(c.id) as comments_count,
            -- Scoring formula: higher score = better ranking
            (
                (CASE WHEN fn.degree = 1 THEN 1000 ELSE 1000 / fn.degree END) + -- Friend proximity
                (EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 < 24 THEN 500 ELSE 100) + -- Recency
                (COUNT(l.id) * 10) + -- Likes boost
                (COUNT(c.id) * 5) -- Comments boost
            ) as post_score
        FROM posts p
        JOIN users u ON p.user_id = u.id
        JOIN (VALUES " . implode(',', array_map(function($i) use ($friendNetwork) {
            return "(?, ?, ?)";
        }, array_keys($friendNetwork))) . ") AS fn(user_id, degree, connection_date) 
            ON p.user_id = fn.user_id
        LEFT JOIN likes l ON p.id = l.post_id
        LEFT JOIN comments c ON p.id = c.post_id
        WHERE p.user_id IN ($placeholders)
        AND p.group_id IS NULL
        AND p.created_at >= NOW() - INTERVAL '3 days'
        AND (p.privacy_setting = 'public' OR p.privacy_setting = 'friends')
        GROUP BY p.id, u.id, fn.degree, fn.connection_date
        ORDER BY post_score DESC, p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params = [];
    foreach ($friendNetwork as $friend) {
        $params[] = $friend['user_id'];
        $params[] = $friend['degree'];
        $params[] = $friend['connection_date'];
    }
    $params = array_merge($params, [$limit, $offset]);
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return enhancePosts($pdo, $userId, $posts);
}
require_once "header.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Home</title>
<style>
body { 
    font-family: Arial,sans-serif;
    max-width:900px; 
    margin:20px auto; 
    background: #1e1e2f; 
}

.posts {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.post {
    background: #2c2c3d;
    border-radius: 8px;
    box-shadow: 0 2px 6px #ccc;
    padding: 15px;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    color: white;
}
.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.post-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.post-header .username {
    margin-left: 10px;
    font-weight: bold;
    cursor: pointer;
    color: #7b68ee;
    flex-grow: 1;
}
.post-date {
    color: #888;
    font-size: 12px;
    margin-left: 10px;
}
.post-content {
    white-space: pre-wrap;
    max-height: 4.5em;
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
    margin-bottom: 10px;
}
.post-content.expanded {
    max-height: none;
}
.show-more-btn {
    background: none;
    border: none;
    color: #007bff;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    margin: 0 0 8px 0;
    user-select: none;
}
.post-media {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-gap: 6px;
    margin-bottom: 10px;
}
.post-media img, .post-media video {
    width: 100%;
    object-fit: cover;
    border-radius: 10px;
    cursor: pointer;
    height: 150px;
    position: relative;
}
.overlay {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 150px;
    background: rgba(0,0,0,0.6);
    color: white;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 24px;
    font-weight: bold;
    border-radius: 10px;
    cursor: pointer;
}
.actions {
    display: flex;
    gap: 20px;
    font-size: 14px;
    align-items: center;
}
.actions span, .actions button {
    cursor: pointer;
    color: #007bff;
    user-select: none;
}
.liked {
    font-weight: bold;
    color: #d9534f;
}
.boost-btn {
    background-color: #28a745;
    color: white;
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.2s ease;
}
.sponsored-label {
    background: linear-gradient(45deg, #ffd700, #ff8c00);
    color: #000;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: bold;
    font-size: 12px;
    margin-bottom: 10px;
    display: inline-block;
}
.refresh-btn {
    background: linear-gradient(45deg, #7b68ee, #5d4fcf);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    margin: 10px auto;
    display: block;
    transition: transform 0.2s;
}
.refresh-btn:hover {
    transform: scale(1.05);
}
.refresh-btn:active {
    transform: scale(0.95);
}
.loading {
    text-align: center;
    padding: 20px;
    color: #888;
    display: none;
}
.caught-up {
    text-align: center;
    padding: 30px;
    color: #888;
    font-style: italic;
    border: 1px dashed #555;
    border-radius: 8px;
    margin: 20px 0;
}
.hidden {
    display: none;
}

/* Video styles */
.video-reel-container {
    position: relative;
    width: 100%;
    margin-bottom: 10px;
    overflow: hidden;
    border-radius: 10px;
}
.video-reel-scroller {
    display: flex;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
}
.video-reel-item {
    position: relative;
    flex: 0 0 auto;
    width: 100%;
    scroll-snap-align: start;
}
.video-reel-item video {
    width: 100%;
    height: auto;
    max-height: 500px;
    object-fit: contain;
    background: #000;
    border-radius: 10px;
}

.follow-btn {
    margin-left: 10px;
    padding: 6px 14px;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(45deg, #ff004f, #c972ff);
    color: white;
    transition: all 0.3s ease;
}
.follow-btn.following {
    background: #888;
    color: #ddd;
}
</style>
</head>
<body>

<!-- Refresh Button -->
<button class="refresh-btn" onclick="refreshPage()">🔄 Refresh</button>

<!-- User Profile Header -->
<div style="text-align: center; padding: 15px; background: #2c2c3d;margin-top: 50px; box-shadow: 0 2px 6px #ccc;border: 3px solid #7b68ee;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
</div>

<!-- Create Post Trigger -->
<div id="openPostModalBtn" style="margin-top: 20px; cursor:pointer;">
    <input type="text" style="background-color: #1e1e2f;border: 3px solid #7b68ee;width: 100%;height: 30px;border-radius: 60px; color: white; padding: 0 15px;" 
           placeholder="What's on your mind?" readonly>
</div>

<!-- Posts Container -->
<div class="posts" id="postsContainer">
    <?php 
    $postCount = 0;
    foreach ($initialPosts as $post): 
        // Insert boosted post every 3 normal posts
        if ($postCount > 0 && $postCount % 3 === 0 && !empty($boostedPosts)) {
            $boostPost = $boostedPosts[($postCount / 3 - 1) % count($boostedPosts)];
            displayPost($boostPost, $pdo, $userId, true);
        }
        
        displayPost($post, $pdo, $userId, false);
        $postCount++;
    endforeach; 
    ?>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading">Loading more posts...</div>

<script>
let currentOffset = <?= count($initialPosts) ?>;
let currentStage = 'friends';
let isLoading = false;
let hasMorePosts = true;

// Refresh function
function refreshPage() {
    // Show subtle loading effect
    const refreshBtn = document.querySelector('.refresh-btn');
    refreshBtn.innerHTML = '⏳ Refreshing...';
    refreshBtn.disabled = true;
    
    // Reload page after short delay to show feedback
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Infinite scroll
window.addEventListener('scroll', () => {
    if (isLoading || !hasMorePosts) return;
    
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    
    // Load more when 200px from bottom
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
        formData.append('stage', currentStage);
        
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            await appendPosts(data.posts);
            currentOffset += data.posts.length;
            currentStage = data.next_stage;
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
            if (post.type === 'caught_up') {
                // Add caught up message
                const caughtUpDiv = document.createElement('div');
                caughtUpDiv.className = 'caught-up';
                caughtUpDiv.innerHTML = '🎉 You\'re all caught up! Showing popular posts from the community...';
                postsContainer.appendChild(caughtUpDiv);
            } else {
                const postElement = createPostElement(post);
                postsContainer.appendChild(postElement);
            }
        });
        
        // Reinitialize video observers for new posts
        initVideoObservers();
        resolve();
    });
}

function createPostElement(post) {
    const div = document.createElement('div');
    div.className = 'post';
    div.setAttribute('data-post-id', post.id);
    
    const isLiked = post.is_liked || false;
    const likeClass = isLiked ? 'liked' : '';
    
    // Media content
    let mediaHTML = '';
    if (post.media_url) {
        const mediaArray = post.media_url.split(',');
        if (post.post_type === 'photo') {
            mediaHTML = createImageMediaHTML(mediaArray);
        } else if (post.post_type === 'video') {
            mediaHTML = createVideoMediaHTML(mediaArray);
        }
    }
    
    div.innerHTML = `
        ${post.boost_end ? '<div class="sponsored-label">Sponsored</div>' : ''}
        
        <div class="post-header">
            <img src="${escapeHtml(post.profile_pic_url || 'default_profile.png')}"
                 alt="Profile" onclick="window.location='profile.php?id=${post.user_id}'" />
            <div class="username" onclick="window.location='profile.php?id=${post.user_id}'">
                ${escapeHtml(post.username)}
            </div>
            <div class="post-date">${escapeHtml(post.formatted_date)}</div>
            ${post.author_id != <?= $userId ?> ? `
                <button class="follow-btn ${post.is_following ? 'following' : ''}" data-user-id="${post.author_id}">
                    ${post.is_following ? 'Following' : 'Follow'}
                </button>
            ` : ''}
        </div>
        
        <div class="post-content" id="post-content-${post.id}">
            ${escapeHtml(post.content || '').replace(/\n/g, '<br>')}
        </div>
        
        ${post.content && post.content.length > 100 ? `
            <button class="show-more-btn" data-post-id="${post.id}">Show More</button>
        ` : ''}
        
        ${mediaHTML}
        
        <div class="actions">
            <span class="like-btn ${likeClass}" data-post-id="${post.id}">
                👍 Like (<span class="like-count">${post.likes_count || 0}</span>)
            </span>
            <span class="comment-btn" onclick="window.location='comment.php?post_id=${post.id}'">
                💬 Comment (<span class="comment-count">${post.comments_count || 0}</span>)
            </span>
            <span class="share-btn" onclick="sharePost(${post.id})">
                🔄 Share
            </span>
        </div>
    `;
    
    return div;
}

function createImageMediaHTML(mediaArray) {
    if (mediaArray.length === 1) {
        return `<div class="post-media"><img src="${escapeHtml(mediaArray[0])}" alt="Post image" style="height: 300px;"></div>`;
    } else {
        let html = '<div class="post-media">';
        mediaArray.slice(0, 4).forEach((img, index) => {
            const style = mediaArray.length > 1 ? 'height: 150px;' : 'height: 300px;';
            html += `<img src="${escapeHtml(img)}" alt="Post image" style="${style}">`;
        });
        html += '</div>';
        return html;
    }
}

function createVideoMediaHTML(mediaArray) {
    if (mediaArray.length === 1) {
        return `
            <div class="video-reel-container">
                <video controls style="width: 100%; height: 400px;">
                    <source src="${escapeHtml(mediaArray[0])}" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        `;
    } else {
        let html = '<div class="video-reel-container"><div class="video-reel-scroller">';
        mediaArray.forEach(video => {
            html += `
                <div class="video-reel-item">
                    <video controls style="width: 100%; height: 400px;">
                        <source src="${escapeHtml(video)}" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
            `;
        });
        html += '</div></div>';
        return html;
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

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('show-more-btn')) {
            togglePostContent(e.target);
        }
        if (e.target.classList.contains('like-btn')) {
            handleLikeClick(e.target);
        }
        if (e.target.classList.contains('follow-btn')) {
            handleFollowClick(e.target);
        }
    });
});

function togglePostContent(button) {
    const postId = button.dataset.postId;
    const content = document.getElementById('post-content-' + postId);
    if (content) {
        if (content.classList.contains('expanded')) {
            content.classList.remove('expanded');
            button.textContent = 'Show More';
        } else {
            content.classList.add('expanded');
            button.textContent = 'Show Less';
        }
    }
}

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
                btn.querySelector('.like-count').textContent = data.likes_count;
            });
        }
    } catch (error) {
        console.error('Error updating like:', error);
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
    alert('Share functionality coming soon! Post ID: ' + postId);
}

function initVideoObservers() {
    // Initialize video players
    document.querySelectorAll('video').forEach(video => {
        video.addEventListener('click', function() {
            if (this.paused) {
                this.play();
            } else {
                this.pause();
            }
        });
    });
}

// Initialize
initVideoObservers();
</script>

</body>
</html>

<?php
// Function to display a post
function displayPost($post, $pdo, $userId, $isBoosted = false) {
    $isLiked = userLikedPost($pdo, $userId, $post['id']);
    $likeClass = $isLiked ? 'liked' : '';
    $likesCount = getLikesCount($pdo, $post['id']);
    $commentsCount = getCommentCount($pdo, $post['id']);
    ?>
    <div class="post" data-post-id="<?= $post['id'] ?>">
        <?php if ($isBoosted): ?>
            <div class="sponsored-label">Sponsored</div>
        <?php endif; ?>
        
        <div class="post-header">
            <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                 alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
            <div class="username"
                 onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                <?= htmlspecialchars($post['username']) ?>
            </div>
            <div class="post-date"><?= htmlspecialchars($post['formatted_date']) ?></div>
            
            <?php if ($post['author_id'] !== $userId): ?>
                <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" 
                        data-user-id="<?= $post['author_id'] ?>">
                    <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                </button>
            <?php endif; ?>
        </div>
        
        <div class="post-content" id="post-content-<?= $post['id'] ?>">
            <?= nl2br(htmlspecialchars($post['content'])) ?>
        </div>
        
        <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
            <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
        <?php endif; ?>

        <!-- Media display -->
        <?php if (!empty($post['media_url'])): ?>
            <?php if ($post['post_type'] === 'photo'): ?>
                <div class="post-media">
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    foreach ($mediaArray as $index => $media): 
                        $media = trim($media);
                    ?>
                        <img src="<?= htmlspecialchars($media) ?>" alt="Post image" 
                             onclick="window.open('full_image.php?img=<?= urlencode($media) ?>', '_blank')">
                    <?php endforeach; ?>
                </div>
            <?php elseif ($post['post_type'] === 'video'): ?>
                <div class="video-reel-container">
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    foreach ($mediaArray as $index => $media): 
                        $media = trim($media);
                    ?>
                        <video controls style="width: 100%; height: 400px;">
                            <source src="<?= htmlspecialchars($media) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="actions">
            <span class="like-btn <?= $likeClass ?>" data-post-id="<?= $post['id'] ?>">
                👍 Like (<span class="like-count"><?= $likesCount ?></span>)
            </span>
            <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                💬 Comment (<span class="comment-count"><?= $commentsCount ?></span>)
            </span>
            <span class="share-btn" onclick="alert('Share is coming soon!')">
                🔄 Share
            </span>
        </div>
    </div>
    <?php
}
?>

<script>
// Enhanced infinite scroll with throttle
let scrollThrottle = false;
const SCROLL_THROTTLE_MS = 100;

window.addEventListener('scroll', () => {
    if (scrollThrottle) return;
    scrollThrottle = true;
    
    setTimeout(() => {
        scrollThrottle = false;
        
        if (isLoading || !hasMorePosts) return;
        
        const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
        const scrollPercentage = (scrollTop + clientHeight) / scrollHeight;
        
        // Load more when 90% scrolled
        if (scrollPercentage > 0.9) {
            loadMorePosts();
        }
    }, SCROLL_THROTTLE_MS);
});

// Add post engagement tracking
function trackPostEngagement(postId, action) {
    // Send analytics about user interactions
    const engagementData = {
        post_id: postId,
        action: action,
        timestamp: Date.now(),
        user_id: <?= $userId ?>
    };
    
    // You can send this to an analytics endpoint
    console.log('Post engagement:', engagementData);
}

// Enhanced like function with tracking
async function handleLikeClick(likeBtn) {
    const postId = likeBtn.dataset.postId;
    const isLiked = likeBtn.classList.contains('liked');
    const action = isLiked ? 'unlike' : 'like';
    
    // Track the engagement
    trackPostEngagement(postId, action);
    
    try {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('post_id', postId);
        
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.like-btn[data-post-id="${postId}"]`).forEach(btn => {
                btn.classList.toggle('liked');
                btn.querySelector('.like-count').textContent = data.likes_count;
            });
            
            // Add visual feedback
            likeBtn.style.transform = 'scale(1.2)';
            setTimeout(() => {
                likeBtn.style.transform = 'scale(1)';
            }, 200);
        }
    } catch (error) {
        console.error('Error updating like:', error);
    }
}

// Add pull-to-refresh functionality
let touchStartY = 0;
let touchEndY = 0;

document.addEventListener('touchstart', e => {
    touchStartY = e.touches[0].clientY;
});

document.addEventListener('touchend', e => {
    touchEndY = e.changedTouches[0].clientY;
    handleSwipe();
});

function handleSwipe() {
    const swipeDistance = touchStartY - touchEndY;
    
    // If swiped down more than 100px from top
    if (swipeDistance > 100 && window.scrollY === 0) {
        refreshPage();
    }
}

// Enhanced video handling
function initVideoObservers() {
    const videoObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const video = entry.target;
            
            if (entry.isIntersecting) {
                // Video is in viewport
                if (video.paused) {
                    video.play().catch(e => {
                        console.log('Autoplay prevented:', e);
                    });
                }
            } else {
                // Video is out of viewport
                if (!video.paused) {
                    video.pause();
                }
            }
        });
    }, { threshold: 0.5 });
    
    document.querySelectorAll('video').forEach(video => {
        videoObserver.observe(video);
        
        video.addEventListener('click', function() {
            if (this.paused) {
                this.play();
            } else {
                this.pause();
            }
        });
        
        // Track video engagement
        video.addEventListener('play', () => {
            trackPostEngagement(video.closest('.post').dataset.postId, 'video_play');
        });
        
        video.addEventListener('ended', () => {
            trackPostEngagement(video.closest('.post').dataset.postId, 'video_completed');
        });
    });
}

// Add offline capability detection
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
        .then(registration => {
            console.log('SW registered: ', registration);
        })
        .catch(registrationError => {
            console.log('SW registration failed: ', registrationError);
        });
}

// Cache posts for offline viewing
function cachePosts(posts) {
    if ('caches' in window) {
        caches.open('posts-cache').then(cache => {
            posts.forEach(post => {
                const url = `/api/posts/${post.id}`;
                const response = new Response(JSON.stringify(post), {
                    headers: { 'Content-Type': 'application/json' }
                });
                cache.put(url, response);
            });
        });
    }
}
</script>

<style>
/* Add smooth animations */
.post {
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.post:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* Loading skeleton for better UX */
.skeleton {
    background: linear-gradient(90deg, #2c2c3d 25%, #3a3a4d 50%, #2c2c3d 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Better responsive design */
@media (max-width: 768px) {
    .post-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .follow-btn {
        margin-left: 0;
        margin-top: 10px;
        width: 100%;
    }
    
    .post-media {
        grid-template-columns: 1fr;
    }
}

/* Dark mode enhancements */
@media (prefers-color-scheme: dark) {
    body {
        background: #0f0f1a;
    }
    
    .post {
        background: #1a1a2e;
        border: 1px solid #2a2a3e;
    }
}
</style>