<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Define available categories
$categories = [
    'All' => 'All Videos',
    'Entertainment' => 'Entertainment',
    'Dance' => 'Dance',
    'Lip-Sync' => 'Lip-Sync',
    'Comedy' => 'Comedy',
    'Music' => 'Music',
    'Beauty and Fashion' => 'Beauty and Fashion',
    'Food and Cooking' => 'Food and Cooking',
    'DIY and Crafting' => 'DIY and Crafting',
    'Gaming' => 'Gaming'
];

// Get selected category from URL or default to 'All'
$selectedCategory = $_GET['category'] ?? 'All';
if (!array_key_exists($selectedCategory, $categories)) {
    $selectedCategory = 'All';
}

// AJAX handlers for FOLLOW/UNFOLLOW and LIKE/UNLIKE
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
}

// Fetch user's followed accounts and interaction history
$userId = $_SESSION['user_id'];

// Get user's watched videos for completion rate tracking (create this table if needed)
try {
    $watchedVideosStmt = $pdo->prepare("
        SELECT post_id, watch_duration, completed 
        FROM video_views 
        WHERE user_id = ? AND watched_at > NOW() - INTERVAL '30 days'
    ");
    $watchedVideosStmt->execute([$userId]);
    $userWatchHistory = $watchedVideosStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userWatchHistory = [];
}

// Get user's liked categories
try {
    $likedCategoriesStmt = $pdo->prepare("
        SELECT DISTINCT p.category1, p.category2, p.category3
        FROM likes l
        JOIN posts p ON l.post_id = p.id
        WHERE l.user_id = ? AND p.post_type = 'video'
        AND l.created_at > NOW() - INTERVAL '30 days'
    ");
    $likedCategoriesStmt->execute([$userId]);
    $userLikedCategories = $likedCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userLikedCategories = [];
}

// Extract preferred categories from liked videos
$preferredCategories = [];
foreach ($userLikedCategories as $row) {
    if (!empty($row['category1'])) $preferredCategories[] = $row['category1'];
    if (!empty($row['category2'])) $preferredCategories[] = $row['category2'];
    if (!empty($row['category3'])) $preferredCategories[] = $row['category3'];
}
$preferredCategories = array_count_values($preferredCategories);
arsort($preferredCategories);

// Get user's followed accounts for follow buttons
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Build the SQL query based on selected category
$sqlParams = ['user_id' => $userId];
$categoryCondition = "";

if ($selectedCategory !== 'All') {
    $categoryCondition = "AND (p.category1 = :category OR p.category2 = :category OR p.category3 = :category)";
    $sqlParams['category'] = $selectedCategory;
}

// Get all potential videos first with engagement metrics
$basePostStmt = $pdo->prepare("
    SELECT 
        p.*, 
        u.username, 
        u.profile_pic_url, 
        u.is_business_account, 
        u.id AS author_id,
        -- Engagement metrics
        COALESCE(l.likes_count, 0) as likes_count,
        COALESCE(c.comments_count, 0) as comments_count,
        COALESCE(s.shares_count, 0) as shares_count,
        COALESCE(v.views_count, 0) as views_count,
        -- User relationship signals
        CASE WHEN f.follower_id IS NOT NULL THEN 1 ELSE 0 END as is_following_author,
        CASE WHEN ul.user_id IS NOT NULL THEN 1 ELSE 0 END as user_liked_post,
        CASE WHEN p.user_id = :user_id THEN 1 ELSE 0 END as is_own_post,
        -- Categories for scoring
        p.category1, p.category2, p.category3,
        -- Time factors
        EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 as hours_ago
        
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = :user_id AND gm.status = 'approved'
    
    -- Engagement metrics subqueries
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
    
    -- User relationship subqueries
    LEFT JOIN follows f ON (f.follower_id = :user_id AND f.followed_id = p.user_id)
    LEFT JOIN likes ul ON (ul.post_id = p.id AND ul.user_id = :user_id)
    
    WHERE p.post_type = 'video'
      AND (
        (p.privacy_setting = 'public')
        OR (p.privacy_setting = 'friends' AND EXISTS (
              SELECT 1 FROM friends f WHERE 
                ((f.user_id = :user_id AND f.friend_id = p.user_id) 
                 OR (f.friend_id = :user_id AND f.user_id = p.user_id)) 
                 AND f.status = 'accepted'))
        OR (p.privacy_setting = 'private' AND p.user_id = :user_id)
        OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
      )
      $categoryCondition
");
$basePostStmt->execute($sqlParams);
$allPosts = $basePostStmt->fetchAll(PDO::FETCH_ASSOC);

// TIKTOK-STYLE ALGORITHMIC SCORING
$scoredPosts = [];

foreach ($allPosts as $post) {
    $score = 0;
    
    // 1. ENGAGEMENT METRICS (40% weight - TikTok prioritizes engagement)
    $engagementScore = (
        ($post['likes_count'] * 0.8) +        // Likes are good
        ($post['comments_count'] * 1.5) +     // Comments are better (more valuable)
        ($post['shares_count'] * 2.0) +       // Shares are best (highest value)
        ($post['views_count'] * 0.3)          // Views are baseline
    ) * 40 / 100;
    $score += $engagementScore;
    
    // 2. USER RELATIONSHIP (25% weight - Personalization)
    $relationshipScore = 0;
    if ($post['is_following_author']) {
        $relationshipScore += 15; // Strong signal - following creator
    }
    if ($post['user_liked_post']) {
        $relationshipScore += 8; // Previously liked this post
    }
    if ($post['is_own_post']) {
        $relationshipScore += 2; // User's own content
    }
    $score += $relationshipScore;
    
    // 3. CONTENT RELEVANCE (15% weight - Category matching)
    $relevanceScore = 0;
    $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
    
    foreach ($postCategories as $category) {
        if (isset($preferredCategories[$category])) {
            $relevanceScore += min($preferredCategories[$category] * 3, 10);
        }
    }
    // Boost if matches current selected category
    if ($selectedCategory !== 'All' && in_array($selectedCategory, $postCategories)) {
        $relevanceScore += 5;
    }
    $score += $relevanceScore;
    
    // 4. TIME DECAY (12% weight - Recency boost)
    $timeDecay = max(0, (72 - min($post['hours_ago'], 72)) / 72); // 3-day strong decay
    $score += ($timeDecay * 12);
    
    // 5. VIRAL POTENTIAL (8% weight - Overall popularity)
    $viralScore = 0;
    $totalEngagement = $post['likes_count'] + $post['comments_count'] + $post['shares_count'];
    if ($totalEngagement > 100) $viralScore += 4;
    if ($totalEngagement > 500) $viralScore += 2;
    if ($totalEngagement > 1000) $viralScore += 2;
    $score += $viralScore;
    
    // Store the calculated score
    $post['tiktok_score'] = round($score, 2);
    $post['score_breakdown'] = [
        'engagement' => round($engagementScore, 2),
        'relationship' => round($relationshipScore, 2),
        'relevance' => round($relevanceScore, 2),
        'recency' => round($timeDecay * 12, 2),
        'viral' => round($viralScore, 2)
    ];
    
    $scoredPosts[] = $post;
}

// Sort by TikTok score (highest first)
usort($scoredPosts, function($a, $b) {
    return $b['tiktok_score'] <=> $a['tiktok_score'];
});

// Take top posts for display
$posts = array_slice($scoredPosts, 0, 50);

// Prepare posts for display
foreach ($posts as &$post) {
    $post['is_following'] = in_array($post['author_id'], $followedUserIds);
    
    // Get post categories for display
    $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
    $post['display_categories'] = !empty($postCategories) ? implode(', ', $postCategories) : 'Uncategorized';
    
    // Calculate engagement rate for display
    $post['engagement_rate'] = $post['views_count'] > 0 
        ? round(($post['likes_count'] + $post['comments_count'] + $post['shares_count']) / $post['views_count'] * 100, 1)
        : 0;
}
unset($post);

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
<title>Fbclone Reels - <?= htmlspecialchars($categories[$selectedCategory]) ?></title>
<style>
header {
    background: #1e1e2f;
    color: #fff;
    padding: 10px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}
header h1 {
    margin: 0;
    font-size: 22px;
    flex-grow: 1;
}
header nav a {
    color: #fff;
    background: #0053ba;
    border: none;
    padding: 8px 14px;
    border-radius: 4px;
    text-decoration: none;
    margin: 5px 3px;
    display: inline-block;
    cursor: pointer;
}

/* Category Filter Styles */
.category-filter {
    background: #2c2c3d;
    padding: 20px;
    margin: 20px 0;
    border-radius: 12px;
    border: 2px solid #7b68ee;
}

.category-filter h3 {
    margin: 0 0 15px 0;
    color: #7b68ee;
    font-size: 18px;
    text-align: center;
}

.category-select-container {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
}

.category-select {
    padding: 12px 20px;
    border: 2px solid #7b68ee;
    border-radius: 25px;
    background: #1e1e2f;
    color: white;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 200px;
}

.category-select:focus {
    outline: none;
    border-color: #9370db;
    box-shadow: 0 0 10px rgba(123, 104, 238, 0.5);
}

.category-select:hover {
    background: #2c2c3d;
}

.filter-btn {
    background: linear-gradient(45deg, #7b68ee, #9370db);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 12px 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-btn:hover {
    background: linear-gradient(45deg, #6a5acd, #7b68ee);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.current-category {
    text-align: center;
    margin-top: 15px;
    font-size: 14px;
    color: #ccc;
}

.current-category .category-name {
    color: #7b68ee;
    font-weight: bold;
    background: rgba(123, 104, 238, 0.1);
    padding: 4px 12px;
    border-radius: 15px;
    margin-left: 8px;
}

.reels-container {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    background-color: #1e1e2f;
}
.reel {
    background: #2c2c3d;
    border-radius: 8px;
    box-shadow: 0 2px 6px #ccc;
    padding: 15px;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    position: relative;
}
.reel-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}
.reel-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.reel-header .username {
    margin-left: 10px;
    font-weight: bold;
    cursor: pointer;
    color: #007bff;
    flex-grow: 1;
}
.reel-content {
    white-space: pre-wrap;
    max-height: 4.5em;
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
    margin-bottom: 10px;
}
.reel-content.expanded {
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

/* TikTok Algorithm Indicators */
.tiktok-score {
    position: absolute;
    top: 10px;
    left: 10px;
    background: linear-gradient(45deg, #FF0050, #00F2EA);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: bold;
    z-index: 100;
    cursor: help;
}

.score-breakdown {
    display: none;
    position: absolute;
    top: 30px;
    left: 10px;
    background: rgba(0, 0, 0, 0.95);
    color: white;
    padding: 12px;
    border-radius: 8px;
    font-size: 11px;
    z-index: 1000;
    min-width: 220px;
    border: 1px solid #333;
    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
}

.tiktok-score:hover + .score-breakdown,
.score-breakdown:hover {
    display: block;
}

.score-item {
    display: flex;
    justify-content: space-between;
    margin: 4px 0;
    padding: 2px 0;
}

.score-label {
    color: #ccc;
    font-size: 10px;
}

.score-value {
    color: #fff;
    font-weight: bold;
    font-size: 10px;
}

.score-bar {
    width: 100%;
    height: 4px;
    background: #333;
    border-radius: 2px;
    margin-top: 2px;
    overflow: hidden;
}

.score-fill {
    height: 100%;
    background: linear-gradient(45deg, #FF0050, #00F2EA);
    border-radius: 2px;
    transition: width 0.3s ease;
}

.algorithm-info {
    text-align: center;
    margin: 10px 0;
    font-size: 12px;
    color: #888;
}

.algorithm-info .highlight {
    color: #FF0050;
    font-weight: bold;
}

/* Video reel styling */
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
  display: flex;
  gap: 10px;
}
.sound-toggle, .download-btn {
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
.download-btn {
  background: rgba(0, 0, 0, 0.7);
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

.follow-btn {
  margin-left: 10px;
  padding: 6px 14px;
  border: none;
  border-radius: 20px;
  cursor: pointer;
  font-weight: bold;
  background: linear-gradient(45deg, #ff004f, #c972ff);
  color: white;
  user-select: none;
  transition: all 0.3s ease;
}
.follow-btn.following {
  background: #888;
  box-shadow: inset 0 1px 5px #555;
  color: #ddd;
}
.follow-btn:hover:not(.following) {
  background-color: #d60040;
}

/* Category badges */
.category-badges {
    margin: 8px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.category-badge {
    background: rgba(123, 104, 238, 0.2);
    color: #7b68ee;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    border: 1px solid #7b68ee;
}

.engagement-rate {
    font-size: 11px;
    color: #888;
    margin-left: 8px;
}

/* Download progress indicator */
.download-progress {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(0, 0, 0, 0.8);
  color: white;
  padding: 15px 20px;
  border-radius: 8px;
  z-index: 10000;
  display: none;
}

.no-videos {
    text-align: center;
    padding: 40px;
    background: #2c2c3d;
    border-radius: 12px;
    margin: 20px 0;
}

.no-videos h3 {
    color: #7b68ee;
    margin-bottom: 15px;
}

.no-videos p {
    color: #ccc;
    margin-bottom: 20px;
}

.upload-btn {
    background: linear-gradient(45deg, #7b68ee, #9370db);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 25px;
    font-weight: bold;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.upload-btn:hover {
    background: linear-gradient(45deg, #6a5acd, #7b68ee);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

@media (max-width: 600px) {
  .video-reel-item video {
    max-height: 500px;
  }
  
  .category-select-container {
    flex-direction: column;
  }
  
  .category-select {
    min-width: 100%;
  }
  
  .filter-btn {
    width: 100%;
  }
  
  .score-breakdown {
    left: 5px;
    right: 5px;
    min-width: auto;
  }
}
</style>
</head>
<body>

<div style="text-align: center; padding: 15px; background: #1e1e2f; border-bottom: 1px solid #ccc;margin-top: 50px;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
    <h2 style="color: #7b68ee;">Video Reels</h2>
    <p style="color: #ccc; font-size: 12px;">TikTok-style algorithm showing videos you'll love</p>
</div>

<!-- Algorithm Info -->
<div class="algorithm-info">
    🔥 Powered by <span class="highlight">TikTok-style algorithm</span> - Showing most engaging content first
</div>

<!-- Category Filter Section -->
<div class="category-filter">
    <h3>🎯 Filter Videos by Category</h3>
    <form method="GET" action="video.php" class="category-select-container">
        <select name="category" class="category-select" onchange="this.form.submit()">
            <?php foreach ($categories as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>" <?= $selectedCategory === $value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="filter-btn">Apply Filter</button>
    </form>
    <div class="current-category">
        Currently viewing: <span class="category-name"><?= htmlspecialchars($categories[$selectedCategory]) ?></span>
        <?php if ($selectedCategory !== 'All'): ?>
            <span style="color: #888; margin-left: 10px;">(<?= count($posts) ?> videos found)</span>
        <?php endif; ?>
    </div>
</div>

<div class="reels-container" id="reelsContainer">
    <?php if (empty($posts)): ?>
        <div class="no-videos">
            <h3>No videos found</h3>
            <p>
                <?php if ($selectedCategory === 'All'): ?>
                    There are no videos to display. Upload some videos to see them here!
                <?php else: ?>
                    No videos found in the <strong><?= htmlspecialchars($categories[$selectedCategory]) ?></strong> category.
                    <?php if ($selectedCategory !== 'All'): ?>
                        <br>Try selecting a different category or upload videos with this category.
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <a href="make_post.php" class="upload-btn">📹 Upload Video</a>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="reel" data-post-id="<?= $post['id'] ?>">
                <!-- TikTok Algorithm Score -->
                <div class="tiktok-score" title="Algorithm Score - Higher is better">
                    ⚡ <?= $post['tiktok_score'] ?>
                </div>
                <div class="score-breakdown">
                    <div class="score-item">
                        <span class="score-label">Engagement:</span>
                        <span class="score-value"><?= $post['score_breakdown']['engagement'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['engagement'] / 40) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Relationship:</span>
                        <span class="score-value"><?= $post['score_breakdown']['relationship'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['relationship'] / 25) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Relevance:</span>
                        <span class="score-value"><?= $post['score_breakdown']['relevance'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['relevance'] / 15) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Recency:</span>
                        <span class="score-value"><?= $post['score_breakdown']['recency'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['recency'] / 12) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Viral Potential:</span>
                        <span class="score-value"><?= $post['score_breakdown']['viral'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['viral'] / 8) * 100 ?>%"></div></div>
                </div>
                
                <div class="reel-header">
                    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                    <div class="username"
                         onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                        <?= htmlspecialchars($post['username']) ?>
                        <span class="engagement-rate">(<?= $post['engagement_rate'] ?>% engagement)</span>
                    </div>
                    <?php if ($post['author_id'] !== $userId): ?>
                        <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
                            <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Category Badges -->
                <?php if (!empty($post['category1']) || !empty($post['category2']) || !empty($post['category3'])): ?>
                    <div class="category-badges">
                        <?php if (!empty($post['category1'])): ?>
                            <span class="category-badge"><?= htmlspecialchars($post['category1']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($post['category2'])): ?>
                            <span class="category-badge"><?= htmlspecialchars($post['category2']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($post['category3'])): ?>
                            <span class="category-badge"><?= htmlspecialchars($post['category3']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="reel-content" id="reel-content-<?= $post['id'] ?>">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
                <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
                <?php endif; ?>

                <!-- Video display -->
                <?php if ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
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
                                        <button class="download-btn" data-video-url="<?= htmlspecialchars($video) ?>" title="Download Video">💾</button>
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

                <!-- Actions -->
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                      Like (<span class="like-count"><?= $post['likes_count'] ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                      Comment (<?= $post['comments_count'] ?>)
                    </span>
                    <span class="share-btn" onclick="alert('Share is coming soon!')">Share (<?= $post['shares_count'] ?>)</span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Download progress indicator -->
<div class="download-progress" id="downloadProgress">
    Downloading video...
</div>

<script>
// Show More / Show Less Toggle
document.querySelectorAll('.show-more-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const postId = btn.dataset.postId;
        const content = document.getElementById('reel-content-' + postId);
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
       const formData = new FormData();
       formData.append('action', isLiked ? 'unlike' : 'like');
       formData.append('post_id', postId);
       try {
           const response = await fetch('video.php', {
               method: 'POST',
               body: formData
           });
           const data = await response.json();
           if (data.success) {
               const likeCountSpan = btn.querySelector('.like-count');
               likeCountSpan.textContent = data.likes_count;
               btn.classList.toggle('liked');
               
               // Update engagement rate display
               const engagementSpan = document.querySelector(`.reel[data-post-id="${postId}"] .engagement-rate`);
               if (engagementSpan) {
                   // Simple update - in real implementation, you'd recalculate the rate
                const currentViews = parseInt(engagementSpan.closest('.reel').querySelector('.like-btn').getAttribute('data-views') || '1');
engagementSpan.textContent = `(${Math.round((data.likes_count / currentViews) * 100)}% engagement)`;
               }
           } else {
               alert('Failed to update like status.');
           }
       } catch {
           alert('Error updating like status.');
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
            const res = await fetch('video.php', { method: 'POST', body: formData });
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
    
    // Set the width of each video item to match the container
    videoItems.forEach(item => {
        item.style.width = container.offsetWidth + 'px';
    });
    
    // Initialize first video - set to unmuted by default
    if (videos.length > 0) {
        const firstVideo = videos[0];
        firstVideo.muted = false; // Unmute by default
        
        firstVideo.addEventListener('loadedmetadata', () => {
            // Adjust container height based on video aspect ratio
            const aspectRatio = firstVideo.videoHeight / firstVideo.videoWidth;
            const containerWidth = container.offsetWidth;
            container.style.height = (containerWidth * aspectRatio) + 'px';
        });
        
        // Play the first video
        firstVideo.play().catch(e => console.log('Autoplay prevented:', e));
    }
    
    // Handle scroll to change active video
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
        
        // Update active video and pagination
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
    
    // Handle click on pagination dots
    paginationDots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            const containerWidth = scroller.offsetWidth;
            scroller.scrollTo({
                left: containerWidth * index,
                behavior: 'smooth'
            });
        });
    });
    
    // Handle video click to play/pause
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
    
    // Handle sound toggle - videos start unmuted by default
    container.querySelectorAll('.sound-toggle').forEach(button => {
        // Initialize button state based on video muted status
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
    
    // Handle window resize to adjust video widths
    window.addEventListener('resize', () => {
        videoItems.forEach(item => {
            item.style.width = container.offsetWidth + 'px';
        });
        
        // Adjust container height based on current video aspect ratio
        const currentIndex = Math.round(scroller.scrollLeft / container.offsetWidth);
        const currentVideo = videos[currentIndex];
        if (currentVideo) {
            const aspectRatio = currentVideo.videoHeight / currentVideo.videoWidth;
            const containerWidth = container.offsetWidth;
            container.style.height = (containerWidth * aspectRatio) + 'px';
        }
    });
});

// Video download functionality
document.querySelectorAll('.download-btn').forEach(button => {
    button.addEventListener('click', async (e) => {
        e.stopPropagation();
        const videoUrl = button.getAttribute('data-video-url');
        
        if (!videoUrl) {
            alert('Video URL not found');
            return;
        }
        
        // Show download progress
        const progress = document.getElementById('downloadProgress');
        progress.style.display = 'block';
        
        try {
            // Fetch the video file
            const response = await fetch(videoUrl);
            if (!response.ok) {
                throw new Error('Failed to fetch video');
            }
            
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            
            // Create a temporary anchor element to trigger download
            const a = document.createElement('a');
            a.href = blobUrl;
            
            // Extract filename from URL or generate one
            const filename = videoUrl.split('/').pop() || 'video_' + Date.now() + '.mp4';
            a.download = filename;
            
            // Trigger download
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            // Clean up
            URL.revokeObjectURL(blobUrl);
            
            // Hide progress indicator
            progress.style.display = 'none';
            
        } catch (error) {
            console.error('Download failed:', error);
            alert('Failed to download video. Please try again.');
            progress.style.display = 'none';
        }
    });
});

// Video pause on scroll away functionality
function initVideoObservers() {
    // Get all video containers
    const videoContainers = document.querySelectorAll('.video-reel-container');
    
    videoContainers.forEach(container => {
        const videos = container.querySelectorAll('video');
        
        // Create Intersection Observer for each video container
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const containerVideos = entry.target.querySelectorAll('video');
                
                if (!entry.isIntersecting) {
                    // Pause all videos in this container when scrolled out of view
                    containerVideos.forEach(v => {
                        if (!v.paused) {
                            v.pause();
                        }
                    });
                } else {
                    // Play the current video when scrolled into view
                    const scroller = entry.target.querySelector('.video-reel-scroller');
                    if (scroller) {
                        const scrollPos = scroller.scrollLeft;
                        const containerWidth = scroller.offsetWidth;
                        const currentIndex = Math.round(scrollPos / containerWidth);
                        const currentVideo = containerVideos[currentIndex];
                        if (currentVideo && currentVideo.paused) {
                            currentVideo.play().catch(e => console.log('Autoplay prevented:', e));
                        }
                    }
                }
            });
        }, { 
            threshold: 0.5,
            rootMargin: '0px'
        });
        
        observer.observe(container);
    });
}

// Initialize video observers when page loads
document.addEventListener('DOMContentLoaded', initVideoObservers);

// Reinitialize observers when reels container changes
const reelsContainer = document.getElementById('reelsContainer');
if (reelsContainer) {
    const observer = new MutationObserver(initVideoObservers);
    observer.observe(reelsContainer, { childList: true, subtree: true });
}

// Also reinitialize on window resize
window.addEventListener('resize', initVideoObservers);
</script>

</body>
</html>