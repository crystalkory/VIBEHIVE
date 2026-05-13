<?php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Process search request
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchQuery = trim($_GET['search']);
    header("Location: search.php?q=" . urlencode($searchQuery));
    exit;
}

// Handle refresh button
if (isset($_GET['refresh'])) {
    // Clear algorithmic feed session data
    if (isset($_SESSION['feed_posts'])) {
        unset($_SESSION['feed_posts']);
    }
    header("Location: home.php");
    exit;
}

// AJAX handlers for core interactions only
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- LIKE/UNLIKE HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid post id']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            if ($_POST['action'] === 'like') {
                $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (:post_id, :user_id) ON CONFLICT DO NOTHING");
                $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
                
                // Create notification if not liking own post
                $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
                $stmt->execute([$postId]);
                $postOwnerId = $stmt->fetchColumn();
                
                if ($postOwnerId != $userId) {
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, post_id, message) VALUES (?, 'like', ?, ?, ?)");
                    $message = "liked your post";
                    $stmt->execute([$postOwnerId, $userId, $postId, $message]);
                }
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
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'likes_count' => $likesCount,
                'is_liked' => $isLiked
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Fetch user data
$userId = $_SESSION['user_id'];

// Fetch trending hashtags (for content analysis)
$trendingStmt = $pdo->prepare("
    SELECT h.tag, h.usage_count, COUNT(ph.post_id) as recent_posts
    FROM hashtags h
    JOIN post_hashtags ph ON h.id = ph.hashtag_id
    JOIN posts p ON ph.post_id = p.id
    WHERE p.created_at > NOW() - INTERVAL '7 days'
    GROUP BY h.id, h.tag, h.usage_count
    ORDER BY recent_posts DESC, h.usage_count DESC
    LIMIT 10
");
$trendingStmt->execute();
$trendingHashtags = $trendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch unread notifications count
$unreadNotificationsStmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unreadNotificationsStmt->execute([$_SESSION['user_id']]);
$unreadNotificationsCount = $unreadNotificationsStmt->fetchColumn();

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

// Get share count for a post
function getShareCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_posts WHERE original_post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Get post hashtags
function getPostHashtags($pdo, $postId) {
    $stmt = $pdo->prepare("
        SELECT h.tag FROM hashtags h
        JOIN post_hashtags ph ON h.id = ph.hashtag_id
        WHERE ph.post_id = :post_id
    ");
    $stmt->execute(['post_id' => $postId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// ========== INSTAGRAM-STYLE ALGORITHM FUNCTIONS ==========

// 1. Calculate post engagement score (Popularity signals)
function calculateEngagementScore($pdo, $postId, $currentUserId) {
    $weights = [
        'likes' => 1.0,
        'comments' => 1.5,
        'saves' => 2.0,
        'shares' => 1.8,
        'views' => 0.5, // For videos/Reels
    ];
    
    $score = 0;
    
    // Get likes count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    $likes = (int)$stmt->fetchColumn();
    $score += $likes * $weights['likes'];
    
    // Get comments count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    $comments = (int)$stmt->fetchColumn();
    $score += $comments * $weights['comments'];
    
    // Get shares count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_posts WHERE original_post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    $shares = (int)$stmt->fetchColumn();
    $score += $shares * $weights['shares'];
    
    // Get saves count (if you have a saves table)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM saved_posts WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    $saves = (int)$stmt->fetchColumn();
    $score += $saves * $weights['saves'];
    
    // Check if current user has interacted with this post before (User behavior signal)
    $stmt = $pdo->prepare("
        SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id
        UNION ALL
        SELECT 1 FROM comments WHERE post_id = :post_id AND user_id = :user_id
        UNION ALL
        SELECT 1 FROM shared_posts WHERE original_post_id = :post_id AND shared_by_user_id = :user_id
        UNION ALL
        SELECT 1 FROM saved_posts WHERE post_id = :post_id AND user_id = :user_id
    ");
    $stmt->execute(['post_id' => $postId, 'user_id' => $currentUserId]);
    $userInteracted = (bool)$stmt->fetchColumn();
    
    // Boost score if user has interacted before (strong signal)
    if ($userInteracted) {
        $score *= 1.3;
    }
    
    return $score;
}

// 2. Calculate user relationship score (Relationship signals)
function calculateRelationshipScore($pdo, $currentUserId, $authorId) {
    if ($currentUserId == $authorId) {
        return 10.0; // User's own posts get highest priority
    }
    
    $score = 0;
    
    // Direct follow (strongest signal)
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :follower_id AND followed_id = :followed_id");
    $stmt->execute(['follower_id' => $currentUserId, 'followed_id' => $authorId]);
    $isDirectFollow = (bool)$stmt->fetchColumn();
    
    if ($isDirectFollow) {
        $score += 8.0;
        
        // Mutual follow (even stronger)
        $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :followed_id AND followed_id = :follower_id");
        $stmt->execute(['follower_id' => $currentUserId, 'followed_id' => $authorId]);
        $isMutual = (bool)$stmt->fetchColumn();
        
        if ($isMutual) {
            $score += 4.0;
        }
    }
    
    // Recent interactions boost (User behavior signal)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM likes l 
        JOIN posts p ON l.post_id = p.id 
        WHERE ((l.user_id = :user1 AND p.user_id = :user2) OR (l.user_id = :user2 AND p.user_id = :user1))
        AND l.created_at > NOW() - INTERVAL '7 days'
    ");
    $stmt->execute(['user1' => $currentUserId, 'user2' => $authorId]);
    $recentInteractions = (int)$stmt->fetchColumn();
    $score += $recentInteractions * 0.8;
    
    // Profile visits (User behavior signal)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM profile_visits 
        WHERE visitor_id = :visitor_id AND target_user_id = :target_id
        AND visited_at > NOW() - INTERVAL '30 days'
    ");
    $stmt->execute(['visitor_id' => $currentUserId, 'target_id' => $authorId]);
    $profileVisits = (int)$stmt->fetchColumn();
    $score += $profileVisits * 0.5;
    
    return $score;
}

// 3. Calculate content preference score (Content type and user interests)
function calculateContentPreferenceScore($pdo, $currentUserId, $postType, $postContent) {
    $score = 1.0; // Base score
    
    // Content type preference (User behavior signal)
    $contentWeights = [
        'video' => 1.3,
        'photo' => 1.1,
        'text' => 1.0,
        'link' => 1.0
    ];
    $contentWeight = $contentWeights[$postType] ?? 1.0;
    $score *= $contentWeight;
    
    // Topic/interests matching (User interests signal)
    preg_match_all('/#(\w+)/', $postContent, $matches);
    $postHashtags = array_unique($matches[1]);
    
    if (!empty($postHashtags)) {
        // Get user's engaged hashtags
        $stmt = $pdo->prepare("
            SELECT DISTINCT h.tag 
            FROM hashtags h
            JOIN post_hashtags ph ON h.id = ph.hashtag_id
            JOIN posts p ON ph.post_id = p.id
            JOIN likes l ON p.id = l.post_id
            WHERE l.user_id = :user_id
            AND l.created_at > NOW() - INTERVAL '30 days'
            LIMIT 20
        ");
        $stmt->execute(['user_id' => $currentUserId]);
        $userEngagedHashtags = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Check for matching interests
        $matchingHashtags = array_intersect($postHashtags, $userEngagedHashtags);
        if (!empty($matchingHashtags)) {
            $score *= (1 + (count($matchingHashtags) * 0.2));
        }
    }
    
    return $score;
}

// 4. Calculate time decay factor (Recency signal)
function calculateTimeDecay($postCreatedAt) {
    $postAge = time() - strtotime($postCreatedAt);
    // Posts from last 24 hours get full weight, then decay over 7 days
    $timeDecay = max(0.1, 1 - ($postAge / (7 * 24 * 60 * 60)));
    return $timeDecay;
}

// 5. Main algorithm: Calculate final relevancy score
function calculateRelevancyScore($pdo, $post, $currentUserId) {
    // Signal 1: Post engagement and popularity
    $engagementScore = calculateEngagementScore($pdo, $post['id'], $currentUserId);
    
    // Signal 2: User relationship with poster
    $relationshipScore = calculateRelationshipScore($pdo, $currentUserId, $post['user_id']);
    
    // Signal 3: Content type and user interests
    $contentPreferenceScore = calculateContentPreferenceScore($pdo, $currentUserId, $post['post_type'], $post['content']);
    
    // Signal 4: Time decay (recency)
    $timeDecay = calculateTimeDecay($post['created_at']);
    
    // Signal 5: Poster's general engagement rate (if available)
    $posterEngagementRate = 1.0; // Default
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_posts, AVG(like_count) as avg_likes
        FROM (
            SELECT p.id, COUNT(l.id) as like_count
            FROM posts p
            LEFT JOIN likes l ON p.id = l.post_id
            WHERE p.user_id = :user_id
            GROUP BY p.id
        ) post_stats
    ");
    $stmt->execute(['user_id' => $post['user_id']]);
    $posterStats = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($posterStats && $posterStats['avg_likes'] > 10) {
        $posterEngagementRate = min(1.2, 1 + ($posterStats['avg_likes'] / 100));
    }
    
    // Final weighted score calculation (Instagram-style weighting)
    $finalScore = (
        $engagementScore * 0.35 +      // Post popularity: 35%
        $relationshipScore * 0.40 +    // User relationship: 40%
        $contentPreferenceScore * 0.15 + // Content preferences: 15%
        $timeDecay * 0.10              // Recency: 10%
    ) * $posterEngagementRate;
    
    return $finalScore;
}

// ========== FEED GENERATION ==========

// Get extended network for feed (simplified)
function getExtendedNetwork($pdo, $userId) {
    // Direct follows
    $stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $stmt->execute([$userId]);
    $directFollows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Friends (mutual follows)
    $stmt = $pdo->prepare("
        SELECT f1.followed_id 
        FROM follows f1
        JOIN follows f2 ON f1.follower_id = f2.followed_id AND f1.followed_id = f2.follower_id
        WHERE f1.follower_id = ?
    ");
    $stmt->execute([$userId]);
    $friends = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Combine and add self
    $network = array_unique(array_merge($directFollows, $friends, [$userId]));
    return $network;
}

// Generate algorithmic feed
$userId = $_SESSION['user_id'];

// Check if we have cached feed posts
if (!isset($_SESSION['feed_posts']) || isset($_GET['refresh'])) {
    // Get user's network
    $networkUsers = getExtendedNetwork($pdo, $userId);
    
    if (empty($networkUsers)) {
        $networkUsers = [$userId];
    }
    
    // Build placeholders for the IN clause
    $placeholders = str_repeat('?,', count($networkUsers) - 1) . '?';
    
    // Get posts from network
    $postStmt = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url, u.id AS author_id
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.user_id IN ($placeholders)
        AND p.privacy_setting IN ('public', 'friends')
        ORDER BY p.created_at DESC
        LIMIT 200
    ");
    $postStmt->execute($networkUsers);
    $allPosts = $postStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate scores and prepare feed
    $feedPosts = [];
    foreach ($allPosts as $post) {
        $relevancyScore = calculateRelevancyScore($pdo, $post, $userId);
        $post['relevancy_score'] = $relevancyScore;
        $post['hashtags'] = getPostHashtags($pdo, $post['id']);
        $post['share_count'] = getShareCount($pdo, $post['id']);
        $post['is_liked'] = userLikedPost($pdo, $userId, $post['id']);
        $feedPosts[] = $post;
    }
    
    // Sort by relevancy score (highest first)
    usort($feedPosts, function($a, $b) {
        return $b['relevancy_score'] <=> $a['relevancy_score'];
    });
    
    // Store in session for consistency during browsing
    $_SESSION['feed_posts'] = $feedPosts;
} else {
    $feedPosts = $_SESSION['feed_posts'];
}

// Take top posts for display
$posts = array_slice($feedPosts, 0, 50);

// Get current user profile pic
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Home - Algorithmic Feed</title>
<style>
body { 
    font-family: Arial,sans-serif;
    max-width:800px; 
    margin:20px auto; 
    background: #1e1e2f; 
    color: white;
}

/* Search Bar */
.search-container {
    margin: 20px 0;
    position: relative;
}

.search-box {
    width: 100%;
    padding: 12px 20px;
    border: 2px solid #7b68ee;
    border-radius: 25px;
    background: #2c2c3d;
    color: white;
    font-size: 16px;
}

.search-box:focus {
    outline: none;
    border-color: #9370db;
}

/* Refresh Button */
.refresh-btn {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 25px;
    cursor: pointer;
    font-weight: bold;
    margin-left: 10px;
    transition: all 0.3s ease;
}

.refresh-btn:hover {
    background: linear-gradient(45deg, #218838, #1e9e8a);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
}

/* Posts */
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

/* Hashtags in posts */
.post-hashtags {
    margin: 10px 0;
}

.hashtag {
    color: #7b68ee;
    cursor: pointer;
    margin-right: 8px;
    font-size: 14px;
}

.hashtag:hover {
    text-decoration: underline;
}

/* Algorithm debug info */
.post-score {
    font-size: 10px;
    color: #666;
    float: right;
    margin-right: 10px;
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 10px;
}

@media (max-width: 600px) {
  .post-media {
    grid-template-columns: 1fr 1fr;
    grid-gap: 4px;
  }
  .post-media img {
    height: 120px;
  }
}
</style>
</head>
<body>

<!-- Navigation -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <a href="profile.php?id=<?= urlencode($userId) ?>" style="color: #7b68ee; text-decoration: none; font-weight: bold;">My Profile</a>
    
    <!-- Search Bar -->
    <div class="search-container" style="flex: 1; max-width: 400px; margin: 0 20px;">
        <form method="GET" action="home.php">
            <input type="text" name="search" class="search-box" placeholder="Search posts, people, hashtags..." 
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </form>
    </div>
    
    <!-- Refresh Feed Button -->
    <a href="home.php?refresh=true" class="refresh-btn" id="refreshFeedBtn">🔄 Refresh Feed</a>

    <!-- Notifications -->
    <div style="position: relative;">
        <button onclick="window.location='notifications.php'" style="background: none; border: none; color: white; cursor: pointer; position: relative; padding: 8px 16px; border-radius: 20px; background: #2c2c3d;">
            🔔 Notifications
            <?php if ($unreadNotificationsCount > 0): ?>
                <span style="background: red; color: white; border-radius: 50%; padding: 2px 6px; font-size: 12px; position: absolute; top: -8px; right: -8px;">
                    <?= $unreadNotificationsCount ?>
                </span>
            <?php endif; ?>
        </button>
    </div>
</div>

<!-- Profile Section -->
<div style="text-align: center; padding: 15px; background: #2c2c3d; margin-top: 20px; box-shadow: 0 2px 6px #ccc; border: 3px solid #7b68ee; border-radius: 10px;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
</div>

<!-- Trending Hashtags -->
<div style="background: #2c2c3d; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
    <div style="font-size: 18px; font-weight: bold; margin-bottom: 15px; color: #7b68ee;">🔥 Trending Hashtags</div>
    <?php foreach ($trendingHashtags as $hashtag): ?>
        <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #444;">
            <span style="color: #7b68ee; cursor: pointer;" onclick="searchHashtag('<?= $hashtag['tag'] ?>')">
                #<?= htmlspecialchars($hashtag['tag']) ?>
            </span>
            <span style="color: #888; font-size: 12px;"><?= $hashtag['recent_posts'] ?> posts</span>
        </div>
    <?php endforeach; ?>
</div>

<!-- Algorithmic Feed Posts -->
<div class="posts" id="postsContainer">
    <?php if (empty($posts)): ?>
        <div class="post" style="text-align: center; padding: 40px;">
            <h3>No posts in your feed yet</h3>
            <p>Start following people to see posts here!</p>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="post" data-post-id="<?= $post['id'] ?>">
                <!-- Debug: Uncomment to see algorithm scores -->
                <!-- <div class="post-score">Relevancy: <?= round($post['relevancy_score'], 2) ?></div> -->
                
                <div class="post-header">
                    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                    <div class="user-info">
                        <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                            <?= htmlspecialchars($post['username']) ?>
                        </div>
                        <div class="timestamp"><?= timeAgo($post['created_at']) ?></div>
                    </div>
                </div>

                <div class="post-content" id="post-content-<?= $post['id'] ?>">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
                
                <!-- Hashtags -->
                <?php if (!empty($post['hashtags'])): ?>
                    <div class="post-hashtags">
                        <?php foreach ($post['hashtags'] as $tag): ?>
                            <span class="hashtag" onclick="searchHashtag('<?= $tag ?>')">#<?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

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
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                                <?php elseif ($index === 3 && $extraCount > 0): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                                    <div style="position: absolute; top: 0; left: 0; width: 100%; height: 150px; background: rgba(0,0,0,0.6); color: white; display: flex; justify-content: center; align-items: center; font-size: 24px; font-weight: bold; border-radius: 10px; cursor: pointer;">
                                        +<?= $extraCount ?>
                                    </div>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
                    <div style="margin-bottom: 10px;">
                        <video controls style="width: 100%; max-height: 400px; border-radius: 8px;">
                            <source src="<?= htmlspecialchars(trim(explode(',', $post['media_url'])[0])) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="actions">
                    <span class="like-btn <?= $post['is_liked'] ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                      Like (<span class="like-count"><?php 
                      $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
                      $stmt->execute(['post_id' => $post['id']]);
                      echo (int)$stmt->fetchColumn();
                      ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                      Comment (<?= getCommentCount($pdo, $post['id']) ?>)
                    </span>
                    <span style="color: #888;">
                      Share (<?= $post['share_count'] ?>)
                    </span>
                </div>
            </div>
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
           const response = await fetch('home.php', {
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
               console.error('Server error:', data.message);
               alert('Failed to update like status: ' + (data.message || 'Unknown error'));
           }
       } catch (error) {
           console.error('Network error:', error);
           alert('Error updating like status. Please check your connection.');
       }
   });
});

// Search functionality
function searchHashtag(tag) {
    window.location.href = 'search.php?q=' + encodeURIComponent('#' + tag);
}

// Refresh button functionality
document.addEventListener('DOMContentLoaded', function() {
    const refreshBtn = document.getElementById('refreshFeedBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function(e) {
            // Add visual feedback
            this.style.transform = 'rotate(180deg)';
            this.style.opacity = '0.7';
            this.textContent = '🔄 Refreshing...';
            
            setTimeout(() => {
                this.style.transform = 'rotate(0deg)';
                this.style.opacity = '1';
                this.textContent = '🔄 Refresh Feed';
            }, 1000);
        });
    }
});

// Algorithm explanation tooltip
document.addEventListener('DOMContentLoaded', function() {
    const postsContainer = document.getElementById('postsContainer');
    if (postsContainer) {
        const infoDiv = document.createElement('div');
        infoDiv.style.cssText = `
            background: #2c2c3d;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #7b68ee;
            font-size: 14px;
        `;
        infoDiv.innerHTML = `
            <strong>🤖 Smart Feed Algorithm Active</strong><br>
            <small>Posts are ranked by: Engagement (35%), Your Relationships (40%), Content Preferences (15%), and Recency (10%)</small>
        `;
        postsContainer.parentNode.insertBefore(infoDiv, postsContainer);
    }
});
</script>

</body>
</html>