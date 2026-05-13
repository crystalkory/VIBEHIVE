<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


// AJAX handlers for FOLLOW/UNFOLLOW, LIKE, UNLIKE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- FOLLOW/UNFOLLOW HANDLER ---
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

    // --- LIKE/UNLIKE HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid post id']);
            exit;
        }
        
        try {
            if ($_POST['action'] === 'like') {
                $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (:post_id, :user_id) ON CONFLICT DO NOTHING");
                $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = :post_id AND user_id = :user_id");
                $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
            }
            
            // Get updated likes count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
            $stmt->execute(['post_id' => $postId]);
            $likesCount = (int)$stmt->fetchColumn();
            
            // Check if user currently likes this post
            $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
            $isLiked = (bool)$stmt->fetchColumn();
            
            echo json_encode([
                'success' => true, 
                'likes_count' => $likesCount,
                'is_liked' => $isLiked
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Helper function to get comment count
function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Check if current user liked a post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// Format time difference for post timestamp
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

$userId = $_SESSION['user_id'];

// Get direct followed users
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get friends (mutual follows)
$stmt = $pdo->prepare("
    SELECT f.followed_id 
    FROM follows f 
    WHERE f.follower_id = ? 
    AND EXISTS (
        SELECT 1 FROM follows f2 
        WHERE f2.follower_id = f.followed_id 
        AND f2.followed_id = ?
    )
");
$stmt->execute([$userId, $userId]);
$friendUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Combine followed users and friends (remove duplicates)
$allowedUserIds = array_unique(array_merge($followedUserIds, $friendUserIds, [$userId]));

// If no followed users or friends, show empty message
if (empty($allowedUserIds)) {
    $posts = [];
} else {
    // Build placeholders for the IN clause
    $placeholders = str_repeat('?,', count($allowedUserIds) - 1) . '?';
    
    // Get posts only from followed users and friends
    $postStmt = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = ? AND gm.status = 'approved'
        WHERE 
          (p.user_id IN ($placeholders))
          AND (
            p.privacy_setting = 'public'
            OR (p.privacy_setting = 'friends' AND EXISTS (
                SELECT 1 FROM friends f WHERE 
                ((f.user_id = ? AND f.friend_id = p.user_id) 
                 OR (f.friend_id = ? AND f.user_id = p.user_id)) 
                 AND f.status = 'accepted'
            ))
            OR (p.privacy_setting = 'private' AND p.user_id = ?)
            OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
          )
        ORDER BY p.created_at DESC
        LIMIT 50
    ");

    // Build parameters array
    $params = array_merge(
        [$userId],                    // gm.user_id
        $allowedUserIds,              // user_id IN clause
        [$userId, $userId, $userId]   // friend checks and private posts
    );

    $postStmt->execute($params);
    $posts = $postStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Add following status to posts
foreach ($posts as &$post) {
    $post['is_following'] = in_array($post['author_id'], $followedUserIds);
    $post['is_friend'] = in_array($post['author_id'], $friendUserIds);
}
unset($post);

// Get current user info for profile
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

// Fetch all active boosted posts globally, in random order
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

$postCount = 0;
$boostCount = count($boostedPosts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone - Following Feed</title>
<style>
body { 
    font-family: Arial,sans-serif;
    max-width:900px; 
    margin:20px auto; 
    background: #1e1e2f; 
    color: white;
}

.navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 20px;
    background: #2c2c3d;
    border-radius: 8px;
    margin-bottom: 20px;
}

.nav-links {
    display: flex;
    gap: 20px;
}

.nav-links a {
    color: #7b68ee;
    text-decoration: none;
    font-weight: bold;
    padding: 8px 16px;
    border-radius: 20px;
    transition: background 0.3s ease;
}

.nav-links a:hover, .nav-links a.active {
    background: #7b68ee;
    color: white;
}

.page-title {
    text-align: center;
    font-size: 24px;
    font-weight: bold;
    color: #7b68ee;
    margin: 20px 0;
}

.empty-message {
    text-align: center;
    padding: 40px;
    background: #2c2c3d;
    border-radius: 8px;
    margin: 20px 0;
}

.empty-message h3 {
    color: #7b68ee;
    margin-bottom: 10px;
}

.empty-message p {
    color: #888;
    margin-bottom: 20px;
}

.discover-link {
    display: inline-block;
    padding: 10px 20px;
    background: linear-gradient(45deg, #ff004f, #c972ff);
    color: white;
    text-decoration: none;
    border-radius: 20px;
    font-weight: bold;
    transition: transform 0.3s ease;
}

.discover-link:hover {
    transform: translateY(-2px);
}

.posts {
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
}
.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    position: relative;
}
.post-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.post-header .user-info {
    margin-left: 10px;
    flex-grow: 1;
}
.post-header .username {
    font-weight: bold;
    cursor: pointer;
    color: #7b68ee;
    display: block;
    font-size: 14px;
}
.post-header .timestamp {
    font-size: 12px;
    color: #888;
    margin-top: 2px;
}
.post-content {
    white-space: pre-wrap;
    max-height: 4.5em;
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
    margin-bottom: 10px;
    line-height: 1.4;
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
.post-media img {
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
    border-top: 1px solid #444;
    padding-top: 10px;
    margin-top: 10px;
}
.actions span, .actions button {
    cursor: pointer;
    color: #007bff;
    user-select: none;
    transition: color 0.2s ease;
    background: none;
    border: none;
    padding: 0;
}
.actions span:hover, .actions button:hover {
    color: #0056b3;
}
.liked {
    font-weight: bold;
    color: #d9534f !important;
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
.boost-btn:hover {
    background-color: #218838;
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

/* Relationship badges */
.relationship-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 8px;
    font-weight: normal;
}
.badge-following { background: #28a745; color: white; }
.badge-friend { background: #17a2b8; color: white; }
.badge-you { background: #007bff; color: white; }

/* Follow Button Styles */
.follow-btn {
    padding: 6px 14px;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(45deg, #ff004f, #c972ff);
    color: white;
    user-select: none;
    transition: all 0.3s ease;
    font-size: 12px;
    margin-left: auto;
}
.follow-btn.following {
    background: #888;
    box-shadow: inset 0 1px 5px #555;
    color: #ddd;
}
.follow-btn:hover:not(.following) {
    background: linear-gradient(45deg, #d60040, #a85cd6);
    transform: translateY(-1px);
}

/* Instagram-style video reels */
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
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.video-reel-scroller::-webkit-scrollbar {
  display: none;
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
  max-height: 600px;
  object-fit: contain;
  background: #000;
  border-radius: 10px;
}
.video-controls {
  position: absolute;
  bottom: 15px;
  right: 15px;
  z-index: 10;
}
.sound-toggle {
  background: rgba(0, 0, 0, 0.5);
  color: white;
  border: none;
  border-radius: 50%;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 18px;
}
.video-count-indicator {
  position: absolute;
  top: 15px;
  right: 15px;
  background: rgba(0, 0, 0, 0.5);
  color: white;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
}
.video-pagination {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 10px;
}
.video-pagination-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ccc;
  cursor: pointer;
  transition: background 0.3s ease;
}
.video-pagination-dot.active {
  background: #0095f6;
}

@media (max-width: 600px) {
  .post-media {
    grid-template-columns: 1fr 1fr;
    grid-gap: 4px;
  }
  .post-media img, .overlay {
    height: 120px;
  }
  .video-reel-item video {
    max-height: 500px;
  }
  .follow-btn {
    padding: 4px 10px;
    font-size: 11px;
  }
  .navigation {
    flex-direction: column;
    gap: 10px;
  }
  .nav-links {
    flex-wrap: wrap;
    justify-content: center;
  }
}
</style>
</head>
<body>

<!-- Navigation -->
<div class="navigation">
    <div class="nav-links">
        <a href="home.php">Home</a>
        <a href="follows.php" class="active">Following</a>
        <a href="profile.php?id=<?= urlencode($userId) ?>">My Profile</a>
    </div>
    <div>
        <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
            <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
                 style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
        </a>
    </div>
</div>

<div class="page-title">
    Following Feed
</div>

<div class="posts" id="postsContainer">
    <?php if (empty($posts) && empty($boostedPosts)): ?>
        <div class="empty-message">
            <h3>No posts to show yet</h3>
            <p>You're not following anyone or your followed users haven't posted anything.</p>
            <p>Start following people to see their posts here!</p>
            <a href="home.php" class="discover-link">Discover People to Follow</a>
        </div>
    <?php elseif (empty($posts) && !empty($boostedPosts)): ?>
        <div class="empty-message">
            <h3>No posts from followed users</h3>
            <p>You're not following anyone or your followed users haven't posted anything.</p>
            <p>Start following people to see their posts here!</p>
            <a href="home.php" class="discover-link">Discover People to Follow</a>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <!-- Regular Post -->
            <div class="post" data-post-id="<?= $post['id'] ?>">
                <div class="post-header">
                    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                    <div class="user-info">
                        <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                            <?= htmlspecialchars($post['username']) ?>
                            <span class="relationship-badge <?= 
                                $post['author_id'] == $userId ? 'badge-you' :
                                ($post['is_friend'] ? 'badge-friend' : 'badge-following')
                            ?>">
                                <?= 
                                    $post['author_id'] == $userId ? 'You' :
                                    ($post['is_friend'] ? 'Friend' : 'Following')
                                ?>
                            </span>
                        </div>
                        <div class="timestamp"><?= timeAgo($post['created_at']) ?></div>
                    </div>
                
                    <?php if ($post['author_id'] !== $userId && !$post['is_friend']): ?>
                        <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
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
                <?php if ($post['post_type'] === 'photo' && !empty($post['media_url'])):
                    $mediaArray = explode(',', $post['media_url']);
                    $mediaCount = count($mediaArray);
                    $firstFour = array_slice($mediaArray, 0, 4);
                    $extraCount = $mediaCount - 4;
                    ?>
                    <div class="post-media">
                        <?php foreach ($firstFour as $index => $image):
                            $image = trim($image);
                        ?>
                            <div style="position:relative;">
                                <?php if ($index < 3): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                                <?php elseif ($index === 3 && $extraCount > 0): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                                    <div class="overlay" onclick="window.location='image_list.php?post_id=<?= $post['id'] ?>'">
                                        +<?= $extraCount ?>
                                    </div>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    $videoCount = count($mediaArray);
                    ?>
                    <div class="video-reel-container" data-post-id="<?= $post['id'] ?>">
                        <div class="video-reel-scroller">
                            <?php foreach ($mediaArray as $index => $video): 
                                $video = trim($video);
                            ?>
                                <div class="video-reel-item" data-video-index="<?= $index ?>">
                                    <video <?= $index === 0 ? 'autoplay loop' : 'preload="none"' ?>>
                                        <source src="<?= htmlspecialchars($video) ?>" type="video/mp4" />
                                        Your browser does not support the video tag.
                                    </video>
                                    <div class="video-controls">
                                        <button class="sound-toggle" data-muted="false">🔊</button>
                                    </div>
                                    <?php if ($videoCount > 1): ?>
                                        <div class="video-count-indicator"><?= ($index + 1) . '/' . $videoCount ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($videoCount > 1): ?>
                            <div class="video-pagination">
                                <?php for ($i = 0; $i < $videoCount; $i++): ?>
                                    <div class="video-pagination-dot <?= $i === 0 ? 'active' : '' ?>" data-video-index="<?= $i ?>"></div>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($post['post_type'] === 'link' && !empty($post['media_url'])): ?>
                    <div><a href="<?= htmlspecialchars($post['media_url']) ?>" target="_blank"><?= htmlspecialchars($post['media_url']) ?></a></div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                      Like (<span class="like-count"><?php 
                      $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
                      $stmt->execute(['post_id' => $post['id']]);
                      echo (int)$stmt->fetchColumn();
                      ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                      Comment (<?= getCommentCount($pdo, $post['id']) ?>)
                    </span>
                    <span class="share-btn" onclick="alert('Share is coming soon!')">Share</span>
                </div>
            </div>

            <?php
            $postCount++;

            // Insert boosted post every 3 normal posts
            if ($postCount % 3 === 0 && $boostCount > 0) {
                $boostPost = $boostedPosts[(int)(($postCount / 3 - 1) % $boostCount)];
                ?>
                <div class="post boosted-post" data-post-id="<?= $boostPost['id'] ?>">
                    <div class="sponsored-label">Sponsored</div>
                    <div class="post-header">
                        <img src="<?= htmlspecialchars($boostPost['profile_pic_url'] ?: 'default_profile.png') ?>"
                             alt="Profile" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'" />
                        <div class="user-info">
                            <div class="username" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'">
                                <?= htmlspecialchars($boostPost['username']) ?> (Boosted)
                            </div>
                            <div class="timestamp"><?= timeAgo($boostPost['created_at']) ?></div>
                        </div>
                        <div style="margin-left:auto; font-size:12px; color:#218838;">Boost expires at: <?= date('M j, Y H:i', strtotime($boostPost['boost_end'])) ?></div>
                        
                        <?php if ($boostPost['author_id'] !== $userId): ?>
                            <button class="follow-btn <?= in_array($boostPost['author_id'], $followedUserIds) ? 'following' : '' ?>" data-user-id="<?= $boostPost['author_id'] ?>">
                                <?= in_array($boostPost['author_id'], $followedUserIds) ? 'Following' : 'Follow' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="post-content" id="post-content-boost-<?= $boostPost['id'] ?>">
                        <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
                    </div>
                    <?php if (mb_strlen(strip_tags($boostPost['content'])) > 100): ?>
                        <button class="show-more-btn" data-post-id="boost-<?= $boostPost['id'] ?>">Show More</button>
                    <?php endif; ?>

                    <!-- Boosted post media -->
                    <?php if ($boostPost['post_type'] === 'photo' && !empty($boostPost['media_url'])):
                        $mediaArray = explode(',', $boostPost['media_url']);
                        $mediaCount = count($mediaArray);
                        $firstFour = array_slice($mediaArray, 0, 4);
                        $extraCount = $mediaCount - 4;
                        ?>
                        <div class="post-media">
                            <?php foreach ($firstFour as $index => $image):
                                $image = trim($image);
                            ?>
                                <div style="position:relative;">
                                    <?php if ($index < 3): ?>
                                        <img src="<?= htmlspecialchars($image) ?>" alt="Boosted Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                                    <?php elseif ($index === 3 && $extraCount > 0): ?>
                                        <img src="<?= htmlspecialchars($image) ?>" alt="Boosted Image" />
                                        <div class="overlay" onclick="window.location='image_list.php?post_id=<?= $boostPost['id'] ?>'">
                                            +<?= $extraCount ?>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?= htmlspecialchars($image) ?>" alt="Boosted Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($boostPost['post_type'] === 'video' && !empty($boostPost['media_url'])): ?>
                        <?php
                        $mediaArray = explode(',', $boostPost['media_url']);
                        $videoCount = count($mediaArray);
                        ?>
                        <div class="video-reel-container" data-post-id="<?= $boostPost['id'] ?>">
                            <div class="video-reel-scroller">
                                <?php foreach ($mediaArray as $index => $video): 
                                    $video = trim($video);
                                ?>
                                    <div class="video-reel-item" data-video-index="<?= $index ?>">
                                        <video <?= $index === 0 ? 'autoplay loop' : 'preload="none"' ?>>
                                            <source src="<?= htmlspecialchars($video) ?>" type="video/mp4" />
                                            Your browser does not support the video tag.
                                        </video>
                                        <div class="video-controls">
                                            <button class="sound-toggle" data-muted="false">🔊</button>
                                        </div>
                                        <?php if ($videoCount > 1): ?>
                                            <div class="video-count-indicator"><?= ($index + 1) . '/' . $videoCount ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($videoCount > 1): ?>
                                <div class="video-pagination">
                                    <?php for ($i = 0; $i < $videoCount; $i++): ?>
                                        <div class="video-pagination-dot <?= $i === 0 ? 'active' : '' ?>" data-video-index="<?= $i ?>"></div>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Actions for boosted post -->
                    <div class="actions">
                        <span class="like-btn <?= userLikedPost($pdo, $userId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                          Like (<span class="like-count"><?php 
                          $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
                          $stmt->execute(['post_id' => $boostPost['id']]);
                          echo (int)$stmt->fetchColumn();
                          ?></span>)
                        </span>
                        <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $boostPost['id'] ?>'">
                          Comment (<?= getCommentCount($pdo, $boostPost['id']) ?>)
                        </span>
                        <span class="share-btn" onclick="alert('Share is coming soon!')">Share</span>
                    </div>
                </div>
                <?php
            }
            ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Show More / Show Less Toggle
document.querySelectorAll('.show-more-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const postId = btn.dataset.postId;
        const content = document.getElementById('post-content-' + postId);
        if (content.classList.contains('expanded')) {
            content.classList.remove('expanded');
            btn.textContent = 'Show More';
        } else {
            content.classList.add('expanded');
            btn.textContent = 'Show Less';
        }
    });
});

// Like / Unlike Ajax
document.querySelectorAll('.like-btn').forEach(btn => {
   btn.addEventListener('click', async () => {
       const postId = btn.dataset.postId;
       const isLiked = btn.classList.contains('liked');
       const action = isLiked ? 'unlike' : 'like';
       
       const formData = new FormData();
       formData.append('action', action);
       formData.append('post_id', postId);
       
       try {
           const response = await fetch('follows.php', {
               method: 'POST',
               body: formData
           });
           
           const data = await response.json();
           
           if (data.success) {
               // Update like count
               const likeCountSpan = btn.querySelector('.like-count');
               if (likeCountSpan) {
                   likeCountSpan.textContent = data.likes_count;
               }
               
               // Update like button state
               if (data.is_liked) {
                   btn.classList.add('liked');
               } else {
                   btn.classList.remove('liked');
               }
               
               // Update all like buttons for the same post
               document.querySelectorAll(`.like-btn[data-post-id="${postId}"]`).forEach(likeBtn => {
                   const otherLikeCountSpan = likeBtn.querySelector('.like-count');
                   if (otherLikeCountSpan) {
                       otherLikeCountSpan.textContent = data.likes_count;
                   }
                   if (data.is_liked) {
                       likeBtn.classList.add('liked');
                   } else {
                       likeBtn.classList.remove('liked');
                   }
               });
               
           } else {
               alert('Failed to update like status: ' + (data.message || 'Unknown error'));
           }
       } catch (error) {
           alert('Error updating like status. Please check your connection.');
       }
   });
});

// Follow/unfollow handlers
document.querySelectorAll('.follow-btn').forEach(button => {
    button.addEventListener('click', async (e) => {
        e.stopPropagation();
        const userId = button.getAttribute('data-user-id');
        if (!userId) return;

        const isFollowing = button.classList.contains('following');
        const action = isFollowing ? 'unfollow' : 'follow';

        const formData = new FormData();
        formData.append('action', action);
        formData.append('followed_id', userId);

        try {
            const res = await fetch('follows.php', { method: 'POST', body: formData });
            const data = await res.json();

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
            } else {
                alert('Failed to update follow status.');
            }
        } catch {
            alert('Error updating follow status.');
        }
    });
});

// Video Reel functionality
document.querySelectorAll('.video-reel-container').forEach(container => {
    const videos = container.querySelectorAll('video');
    const videoItems = container.querySelectorAll('.video-reel-item');
    const paginationDots = container.querySelectorAll('.video-pagination-dot');
    const scroller = container.querySelector('.video-reel-scroller');
    
    videoItems.forEach(item => {
        item.style.width = container.offsetWidth + 'px';
    });
    
    if (videos.length > 0) {
        const firstVideo = videos[0];
        firstVideo.muted = false;
        
        firstVideo.addEventListener('loadedmetadata', () => {
            const aspectRatio = firstVideo.videoHeight / firstVideo.videoWidth;
            const containerWidth = container.offsetWidth;
            container.style.height = (containerWidth * aspectRatio) + 'px';
        });
        
        firstVideo.play().catch(e => console.log('Autoplay prevented:', e));
    }
    
    let isScrolling = false;
    scroller.addEventListener('scroll', () => {
        if (isScrolling) return;
        
        isScrolling = true;
        setTimeout(() => {
            isScrolling = false;
        }, 100);
        
        const scrollPos = scroller.scrollLeft;
        const containerWidth = scroller.offsetWidth;
        const currentIndex = Math.round(scrollPos / containerWidth);
        
        videoItems.forEach((item, index) => {
            if (index === currentIndex) {
                const video = item.querySelector('video');
                if (video) {
                    video.play().catch(e => console.log('Autoplay prevented:', e));
                }
                if (paginationDots[index]) {
                    paginationDots[index].classList.add('active');
                }
            } else {
                const video = item.querySelector('video');
                if (video) {
                    video.pause();
                    video.currentTime = 0;
                }
                if (paginationDots[index]) {
                    paginationDots[index].classList.remove('active');
                }
            }
        });
    });
    
    paginationDots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            const containerWidth = scroller.offsetWidth;
            scroller.scrollTo({
                left: containerWidth * index,
                behavior: 'smooth'
            });
        });
    });
    
    videos.forEach(video => {
        video.addEventListener('click', (e) => {
            e.stopPropagation();
            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        });
    });
    
    container.querySelectorAll('.sound-toggle').forEach(button => {
        const video = button.closest('.video-reel-item').querySelector('video');
        if (video) {
            button.setAttribute('data-muted', video.muted);
            button.textContent = video.muted ? '🔇' : '🔊';
        }
        
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const video = button.closest('.video-reel-item').querySelector('video');
            if (video) {
                video.muted = !video.muted;
                button.setAttribute('data-muted', video.muted);
                button.textContent = video.muted ? '🔇' : '🔊';
            }
        });
    });
    
    window.addEventListener('resize', () => {
        videoItems.forEach(item => {
            item.style.width = container.offsetWidth + 'px';
        });
        
        const currentIndex = Math.round(scroller.scrollLeft / container.offsetWidth);
        const currentVideo = videos[currentIndex];
        if (currentVideo) {
            const aspectRatio = currentVideo.videoHeight / currentVideo.videoWidth;
            const containerWidth = container.offsetWidth;
            container.style.height = (containerWidth * aspectRatio) + 'px';
        }
    });
});

// Enhanced Video pause on scroll away functionality
function initVideoObservers() {
    const videoContainers = document.querySelectorAll('.video-reel-container');
    
    videoContainers.forEach(container => {
        const videos = container.querySelectorAll('video');
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const containerVideos = entry.target.querySelectorAll('video');
                
                if (!entry.isIntersecting) {
                    containerVideos.forEach(v => {
                        if (!v.paused) {
                            v.pause();
                        }
                    });
                } else {
                    const scroller = entry.target.querySelector('.video-reel-scroller');
                    if (scroller) {
                        const scrollPos = scroller.scrollLeft;
                        const containerWidth = scroller.offsetWidth;
                        const currentIndex = Math.round(scrollPos / containerWidth);
                        const currentVideo = videos[currentIndex];
                        if (currentVideo && currentVideo.paused) {
                            currentVideo.play().catch(e => console.log('Autoplay prevented:', e));
                        }
                    }
                }
            });
        }, { 
            threshold: 0.3,
            rootMargin: '0px'
        });
        
        observer.observe(container);
    });
}

// Initialize video observers when page loads
document.addEventListener('DOMContentLoaded', initVideoObservers);

// Reinitialize observers when posts container changes
const postsContainer = document.getElementById('postsContainer');
if (postsContainer) {
    const observer = new MutationObserver(initVideoObservers);
    observer.observe(postsContainer, { childList: true, subtree: true });
}

// Also reinitialize on window resize
window.addEventListener('resize', initVideoObservers);
</script>

</body>
</html>