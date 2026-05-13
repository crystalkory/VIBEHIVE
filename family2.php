<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "color.php";

require_once "config.php";


// ========== PAGINATION VARIABLES ==========
$postsPerPage = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $postsPerPage;

// Check if this is an AJAX request for loading more posts
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

// Process search request
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchQuery = trim($_GET['search']);
    header("Location: search.php?q=" . urlencode($searchQuery));
    exit;
}

// ========== AD ENGAGEMENT FUNCTIONS ==========

// Function to get like count for an ad
function getAdLikeCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

// Function to get comment count for an ad
function getAdCommentCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_comments WHERE ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

// Function to get share count for an ad
function getAdShareCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

// Function to check if user liked an ad
function userLikedAd($pdo, $userId, $adId) {
    $stmt = $pdo->prepare("SELECT 1 FROM ad_likes WHERE ad_id = ? AND user_id = ?");
    $stmt->execute([$adId, $userId]);
    return (bool)$stmt->fetchColumn();
}

// ========== ADS TARGETING FUNCTIONS ==========

// Get user's country from their profile
function getUserCountry($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT country FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

// Get user's gender from their profile
function getUserGender($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT gender FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

// Continent definitions
$continents = [
    'Africa' => [
        'countries' => ['DZ', 'AO', 'BJ', 'BW', 'BF', 'BI', 'CV', 'CM', 'CF', 'TD', 'KM', 'CG', 'CD', 'DJ', 'EG', 'GQ', 'ER', 'SZ', 'ET', 'GA', 'GM', 'GH', 'GN', 'GW', 'KE', 'LS', 'LR', 'LY', 'MG', 'MW', 'ML', 'MR', 'MU', 'MA', 'MZ', 'NA', 'NE', 'NG', 'RW', 'ST', 'SN', 'SC', 'SL', 'SO', 'SA', 'SS', 'SD', 'TZ', 'TG', 'TN', 'UG', 'ZM', 'ZW']
    ],
    'Asia' => [
        'countries' => ['AF', 'AM', 'AZ', 'BH', 'BD', 'BT', 'BN', 'KH', 'CN', 'CY', 'GE', 'IN', 'ID', 'IR', 'IQ', 'IL', 'JP', 'JO', 'KZ', 'KW', 'KG', 'LA', 'LB', 'MY', 'MV', 'MN', 'MM', 'NP', 'KP', 'OM', 'PK', 'PH', 'QA', 'RU', 'SA', 'SG', 'KR', 'LK', 'SY', 'TW', 'TJ', 'TH', 'TR', 'TM', 'AE', 'UZ', 'VN', 'YE']
    ],
    'Europe' => [
        'countries' => ['AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'XK', 'LV', 'LI', 'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL', 'PT', 'RO', 'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'UA', 'GB', 'VA']
    ],
    'North America' => [
        'countries' => ['AG', 'BS', 'BB', 'BZ', 'CA', 'CR', 'CU', 'DM', 'DO', 'SV', 'GD', 'GT', 'HT', 'HN', 'JM', 'MX', 'NI', 'PA', 'KN', 'LC', 'VC', 'TT', 'US']
    ],
    'South America' => [
        'countries' => ['AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'GY', 'PY', 'PE', 'SR', 'UY', 'VE']
    ],
    'Oceania' => [
        'countries' => ['AU', 'FJ', 'KI', 'MH', 'FM', 'NR', 'NZ', 'PW', 'PG', 'WS', 'SB', 'TO', 'TV', 'VU']
    ]
];

// Check if user's country matches ad targeting
function userMatchesAdTargeting($pdo, $userId, $adLocations, $adGender) {
    // Get user's country and gender
    $userCountry = getUserCountry($pdo, $userId);
    $userGender = getUserGender($pdo, $userId);
    
    // Check gender targeting
    if (!empty($adGender) && $adGender !== $userGender && $adGender !== '') {
        return false;
    }
    
    // Check if ad targets all countries
    if (count($adLocations) > 100) {
        return true;
    }
    
    // Check if user's country is directly targeted
    if (in_array($userCountry, $adLocations)) {
        return true;
    }
    
    // Check continent targeting
    global $continents;
    foreach ($continents as $continentData) {
        $continentCountries = $continentData['countries'];
        // Check if any of the continent countries are in ad locations
        $matchingCountries = array_intersect($continentCountries, $adLocations);
        if (!empty($matchingCountries) && in_array($userCountry, $continentCountries)) {
            return true;
        }
    }
    
    return false;
}

// Get targeted ads for current user with shuffling and repetition
function getTargetedAds($pdo, $userId, $limit = 20) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username, u.profile_pic_url 
        FROM ads a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.status = 'active' 
        AND a.ends_at > CURRENT_TIMESTAMP
        ORDER BY a.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $allAds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $targetedAds = [];
    foreach ($allAds as $ad) {
        $locations = json_decode($ad['locations'], true) ?: [];
        if (userMatchesAdTargeting($pdo, $userId, $locations, $ad['gender_target'])) {
            $targetedAds[] = $ad;
        }
    }
    
    // If no targeted ads, return empty array
    if (empty($targetedAds)) {
        return [];
    }
    
    // Always shuffle ads on page load
    shuffle($targetedAds);
    
    // Store shuffled ads in session for consistency during this session
    $_SESSION['feed_shuffled_ads'] = $targetedAds;
    
    // If we need more ads than available, repeat the shuffled ads
    $availableAds = count($targetedAds);
    if ($availableAds > 0 && $limit > $availableAds) {
        $repeatedAds = [];
        $fullCycles = floor($limit / $availableAds);
        $remainder = $limit % $availableAds;
        
        for ($i = 0; $i < $fullCycles; $i++) {
            $repeatedAds = array_merge($repeatedAds, $targetedAds);
        }
        
        if ($remainder > 0) {
            $repeatedAds = array_merge($repeatedAds, array_slice($targetedAds, 0, $remainder));
        }
        
        return $repeatedAds;
    }
    
    return array_slice($targetedAds, 0, $limit);
}

// Get targeted ads for family page
function getFamilyTargetedAds($pdo, $userId, $limit = 15) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username, u.profile_pic_url 
        FROM ads a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.status = 'active' 
        AND a.ends_at > CURRENT_TIMESTAMP
        ORDER BY a.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $allAds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $targetedAds = [];
    foreach ($allAds as $ad) {
        $locations = json_decode($ad['locations'], true) ?: [];
        if (userMatchesAdTargeting($pdo, $userId, $locations, $ad['gender_target'])) {
            $targetedAds[] = $ad;
        }
    }
    
    // Always shuffle ads on page load
    shuffle($targetedAds);
    
    // Store shuffled ads in session for consistency during this session
    $_SESSION['family_shuffled_ads'] = $targetedAds;
    
    return array_slice($targetedAds, 0, $limit);
}

// ========== END OF ADS FUNCTIONS ==========

// AJAX handlers for like/unlike and follow/unfollow
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

    // --- FOLLOW/UNFOLLOW HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
        $followerId = $_SESSION['user_id'];
        $followedId = (int)$_POST['followed_id'];
        if ($followerId && $followedId && $followerId !== $followedId) {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$followerId, $followedId]);
                
                // Create notification
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message) VALUES (?, 'follow', ?, ?)");
                $message = "started following you";
                $stmt->execute([$followedId, $followerId, $message]);
                
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

    // --- AD LIKE/UNLIKE HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like_ad', 'unlike_ad']) && isset($_POST['ad_id'])) {
        $adId = (int)$_POST['ad_id'];
        $currentUserId = $_SESSION['user_id'];
        
        if ($_POST['action'] === 'like_ad') {
            $stmt = $pdo->prepare("INSERT INTO ad_likes (ad_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $stmt->execute([$adId, $currentUserId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM ad_likes WHERE ad_id = ? AND user_id = ?");
            $stmt->execute([$adId, $currentUserId]);
        }
        
        $newLikeCount = getAdLikeCount($pdo, $adId);
        echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
        exit;
    }

    // --- AD SHARE HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'share_ad' && isset($_POST['ad_id'])) {
        $adId = (int)$_POST['ad_id'];
        $currentUserId = $_SESSION['user_id'];
        
        // Check if already shared
        $checkStmt = $pdo->prepare("SELECT 1 FROM shared_ads WHERE original_ad_id = ? AND user_id = ?");
        $checkStmt->execute([$adId, $currentUserId]);
        $alreadyShared = (bool)$checkStmt->fetchColumn();
        
        if (!$alreadyShared) {
            $stmt = $pdo->prepare("INSERT INTO shared_ads (original_ad_id, user_id) VALUES (?, ?)");
            $stmt->execute([$adId, $currentUserId]);
        }
        
        $newShareCount = getAdShareCount($pdo, $adId);
        echo json_encode(['success' => true, 'shares_count' => $newShareCount]);
        exit;
    }
}

// Fetch user data
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

// Fetch trending hashtags
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

function getShareCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_posts WHERE original_post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

function getPostHashtags($pdo, $postId) {
    $stmt = $pdo->prepare("
        SELECT h.tag FROM hashtags h
        JOIN post_hashtags ph ON h.id = ph.hashtag_id
        WHERE ph.post_id = :post_id
    ");
    $stmt->execute(['post_id' => $postId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get user's friends and followed users
function getFamilyUsers($pdo, $userId) {
    $familyUsers = [$userId]; // Include self
    
    // Get friends (both directions)
    $stmt = $pdo->prepare("
        SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'
        UNION 
        SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$userId, $userId]);
    $friends = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $familyUsers = array_merge($familyUsers, $friends);
    
    // Get followed users
    $stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $stmt->execute([$userId]);
    $followed = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $familyUsers = array_merge($familyUsers, $followed);
    
    return array_unique($familyUsers);
}

// Calculate post engagement score (Instagram-style algorithm)
function calculatePostEngagementScore($pdo, $postId, $currentUserId) {
    $weights = [
        'likes' => 1.0,
        'comments' => 1.5,
        'saves' => 2.0,
        'shares' => 1.8,
        'video_views' => 0.8,
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
    
    // Check if current user has interacted with this post before
    $stmt = $pdo->prepare("
        SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id
        UNION ALL
        SELECT 1 FROM comments WHERE post_id = :post_id AND user_id = :user_id
        UNION ALL
        SELECT 1 FROM shared_posts WHERE original_post_id = :post_id AND shared_by_user_id = :user_id
    ");
    $stmt->execute(['post_id' => $postId, 'user_id' => $currentUserId]);
    $userInteracted = (bool)$stmt->fetchColumn();
    
    // Boost score if user has interacted before
    if ($userInteracted) {
        $score *= 1.5;
    }
    
    return $score;
}

// Calculate relationship strength
function calculateRelationshipStrength($pdo, $currentUserId, $authorId) {
    if ($currentUserId == $authorId) {
        return 10.0; // Highest score for own content
    }
    
    $score = 0;
    
    // Check if direct friend
    $stmt = $pdo->prepare("
        SELECT 1 FROM friends WHERE 
        ((user_id = :user1 AND friend_id = :user2) OR (user_id = :user2 AND friend_id = :user1)) 
        AND status = 'accepted'
    ");
    $stmt->execute(['user1' => $currentUserId, 'user2' => $authorId]);
    $isFriend = (bool)$stmt->fetchColumn();
    
    if ($isFriend) {
        $score += 8.0;
    }
    
    // Check if following
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :follower_id AND followed_id = :followed_id");
    $stmt->execute(['follower_id' => $currentUserId, 'followed_id' => $authorId]);
    $isFollowing = (bool)$stmt->fetchColumn();
    
    if ($isFollowing) {
        $score += 6.0;
        
        // Check if mutual follow
        $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :followed_id AND followed_id = :follower_id");
        $stmt->execute(['follower_id' => $currentUserId, 'followed_id' => $authorId]);
        $isMutual = (bool)$stmt->fetchColumn();
        
        if ($isMutual) {
            $score += 3.0;
        }
    }
    
    // Recent interactions boost
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM likes l 
        JOIN posts p ON l.post_id = p.id 
        WHERE ((l.user_id = :user1 AND p.user_id = :user2) OR (l.user_id = :user2 AND p.user_id = :user1))
        AND l.created_at > NOW() - INTERVAL '7 days'
    ");
    $stmt->execute(['user1' => $currentUserId, 'user2' => $authorId]);
    $recentInteractions = (int)$stmt->fetchColumn();
    $score += $recentInteractions * 0.8;
    
    return $score;
}

// Get ALL posts from family (friends + followed users) - INCLUDING IMAGES AND VIDEOS
$familyUsers = getFamilyUsers($pdo, $userId);

if (empty($familyUsers)) {
    $familyUsers = [$userId];
}

$placeholders = str_repeat('?,', count($familyUsers) - 1) . '?';

// Fetch ALL posts from family network (including images and videos)
$postStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE p.user_id IN ($placeholders)
    AND p.media_url IS NOT NULL
    AND (p.privacy_setting = 'public' OR p.privacy_setting = 'friends' OR p.user_id = ?)
    ORDER BY p.created_at DESC
    LIMIT 100
");

$params = array_merge($familyUsers, [$userId]);
$postStmt->execute($params);
$allPosts = $postStmt->fetchAll(PDO::FETCH_ASSOC);

// Get direct followed users for follow buttons
$followStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$followStmt->execute([$userId]);
$directFollowedUserIds = $followStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// INSTAGRAM-STYLE FEED ALGORITHM FOR ALL POST TYPES
$feedPosts = [];

// Calculate scores for each post
foreach ($allPosts as $post) {
    $engagementScore = calculatePostEngagementScore($pdo, $post['id'], $userId);
    $relationshipScore = calculateRelationshipStrength($pdo, $userId, $post['author_id']);
    
    // Time decay factor (newer posts get boost)
    $postAge = time() - strtotime($post['created_at']);
    $timeDecay = max(0.1, 1 - ($postAge / (3 * 24 * 60 * 60))); // 3-day decay
    
    // Post-type specific boosts
    $postTypeBoost = 1.0;
    if ($post['post_type'] === 'video') {
        $postTypeBoost = 1.5; // Videos get higher priority
    } elseif ($post['post_type'] === 'photo') {
        $postTypeBoost = 1.2; // Images get moderate boost
    }
    
    // Check media quality (simplified)
    $mediaQuality = 1.0;
    if (!empty($post['media_url'])) {
        $mediaCount = count(explode(',', $post['media_url']));
        if ($mediaCount > 1) {
            $mediaQuality = 1.2;
        }
    }
    
    // Final score calculation (Instagram-style)
    $finalScore = (
        $engagementScore * 0.4 +      // Engagement is very important
        $relationshipScore * 0.35 +    // Relationship strength
        $timeDecay * 0.15 +           // Recency
        (rand(1, 10) * 0.1)           // Small random factor for variety
    ) * $postTypeBoost * $mediaQuality;
    
    $post['feed_score'] = $finalScore;
    $post['is_following'] = in_array($post['author_id'], $directFollowedUserIds);
    $post['hashtags'] = getPostHashtags($pdo, $post['id']);
    $post['share_count'] = getShareCount($pdo, $post['id']);
    
    $feedPosts[] = $post;
}

// Sort by feed score (highest first)
usort($feedPosts, function($a, $b) {
    return $b['feed_score'] <=> $a['feed_score'];
});

// Take top posts for display - APPLY PAGINATION
$totalPosts = count($feedPosts);
$totalPages = ceil($totalPosts / $postsPerPage);
$posts = array_slice($feedPosts, $offset, $postsPerPage);

// ========== BOOSTED POSTS LOGIC ==========
// Fetch all active boosted posts globally
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

// SHUFFLE BOOSTED POSTS ON EVERY PAGE LOAD
shuffle($boostedPosts);

// Get targeted ads with engagement counts
$targetedAds = getFamilyTargetedAds($pdo, $userId, 30);

// Add engagement counts to all ads
$ads_with_engagement = [];
foreach ($targetedAds as $ad) {
    $ad['like_count'] = getAdLikeCount($pdo, $ad['id']);
    $ad['comment_count'] = getAdCommentCount($pdo, $ad['id']);
    $ad['share_count'] = getAdShareCount($pdo, $ad['id']);
    $ad['user_liked'] = userLikedAd($pdo, $userId, $ad['id']);
    $ads_with_engagement[] = $ad;
}

$targetedAds = $ads_with_engagement;
$adCount = count($targetedAds);

$postCount = 0;
$boostCount = count($boostedPosts);

// Count unread messages
$unreadCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = :user_id AND read_at IS NULL");
    $stmt->execute(['user_id' => $userId]);
    $unreadCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // log error or ignore
}

// If this is an AJAX request, only output the posts and exit
if ($isAjaxRequest) {
    if (empty($posts)) {
        echo '<div class="no-results">No more posts to load.</div>';
        exit;
    }
    
    // Output the posts HTML
    ob_start();
    includePosts($posts, $pdo, $userId, $targetedAds, $boostedPosts, $page);
    $postsHtml = ob_get_clean();
    echo $postsHtml;
    exit;
}

// Function to output posts HTML (for both initial load and AJAX)
function includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $currentPage = 1) {
    $postCounter = ($currentPage - 1) * 8; // Start counter from appropriate position
    $adCounter = 0;
    $boostCounter = 0;
    
    // SHUFFLE ADS AND BOOSTED POSTS FOR RANDOM DISPLAY
    $allAds = $targetedAds ?: [];
    $allBoostedPosts = $boostedPosts ?: [];
    
    shuffle($allAds);
    shuffle($allBoostedPosts);
    
    // Get direct followed users for follow buttons
    $followStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $followStmt->execute([$currentUserId]);
    $directFollowedUserIds = $followStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    foreach ($posts as $post): 
        $postCounter++;
        $postType = $post['post_type'] ?? 'text';
    ?>
        <!-- Regular Post -->
        <div class="post" data-post-id="<?= $post['id'] ?>">
            <div class="post-header">
                <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                     alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                <div class="user-info">
                    <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                        <?= htmlspecialchars($post['username']) ?>
                    </div>
                    <div class="timestamp"><?= timeAgo($post['created_at']) ?></div>
                </div>
            
                <?php if ($post['author_id'] !== $currentUserId): ?>
                    <button class="follow-btn <?= in_array($post['author_id'], $directFollowedUserIds) ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
                        <?= in_array($post['author_id'], $directFollowedUserIds) ? 'Following' : 'Follow' ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($post['content'])): ?>
                <div class="post-content" id="post-content-<?= $post['id'] ?>">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
                
                <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Media Display - HANDLES BOTH IMAGES AND VIDEOS -->
            <?php if (!empty($post['media_url'])): ?>
                <?php if ($postType === 'photo'): ?>
                    <?php
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
                <?php elseif ($postType === 'video'): ?>
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    $firstVideo = trim($mediaArray[0]);
                    ?>
                    <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($post['media_url']) ?>', <?= $post['id'] ?>)">
                        <video loop playsinline preload="metadata" class="family-video" muted>
                            <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                        <div class="video-controls">
                            <button class="play-pause">▶️</button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Hashtags -->
            <?php if (!empty($post['hashtags'])): ?>
                <div class="post-hashtags">
                    <?php foreach ($post['hashtags'] as $tag): ?>
                        <span class="hashtag" onclick="searchHashtag('<?= $tag ?>')">#<?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="actions">
                <span class="like-btn <?= userLikedPost($pdo, $currentUserId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                    ❤️ Like (<span class="like-count"><?php 
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
                    $stmt->execute(['post_id' => $post['id']]);
                    echo (int)$stmt->fetchColumn();
                    ?></span>)
                </span>
                <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                    💬 Comment (<?= getCommentCount($pdo, $post['id']) ?>)
                </span>
                <span class="share-btn" onclick="sharePost(<?= $post['id'] ?>)">
                    🔄 Share (<?= $post['share_count'] ?>)
                </span>
            </div>
        </div>

        <?php
        // Insert boosted posts every 6 posts
        if ($postCounter % 6 === 0 && count($allBoostedPosts) > 0):
            // USE RANDOM BOOSTED POST INSTEAD OF SEQUENTIAL
            $randomBoostIndex = array_rand($allBoostedPosts);
            $boostPost = $allBoostedPosts[$randomBoostIndex];
            $boostCounter++;
            $boostPostType = $boostPost['post_type'] ?? 'text';
        ?>
            <!-- BOOSTED POST -->
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
                    
                    <?php if ($boostPost['author_id'] !== $currentUserId): ?>
                        <button class="follow-btn <?= in_array($boostPost['author_id'], $directFollowedUserIds) ? 'following' : '' ?>" data-user-id="<?= $boostPost['author_id'] ?>">
                            <?= in_array($boostPost['author_id'], $directFollowedUserIds) ? 'Following' : 'Follow' ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($boostPost['content'])): ?>
                    <div class="post-content" id="post-content-boost-<?= $boostPost['id'] ?>">
                        <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
                    </div>
                    
                    <?php if (mb_strlen(strip_tags($boostPost['content'])) > 100): ?>
                        <button class="show-more-btn" data-post-id="boost-<?= $boostPost['id'] ?>">Show More</button>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Boosted post hashtags -->
                <?php 
                $boostedHashtags = getPostHashtags($pdo, $boostPost['id']);
                if (!empty($boostedHashtags)): ?>
                    <div class="post-hashtags">
                        <?php foreach ($boostedHashtags as $tag): ?>
                            <span class="hashtag" onclick="searchHashtag('<?= $tag ?>')">#<?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Boosted post media - HANDLES BOTH IMAGES AND VIDEOS -->
                <?php if (!empty($boostPost['media_url'])): ?>
                    <?php if ($boostPostType === 'photo'): ?>
                        <?php
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
                    <?php elseif ($boostPostType === 'video'): ?>
                        <?php
                        $mediaArray = explode(',', $boostPost['media_url']);
                        $firstVideo = trim($mediaArray[0]);
                        ?>
                        <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($boostPost['media_url']) ?>', <?= $boostPost['id'] ?>)">
                            <video loop playsinline preload="metadata" class="family-video" muted>
                                <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                            <div class="video-controls">
                                <button class="play-pause">▶️</button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Actions for boosted post -->
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $currentUserId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                        ❤️ Like (<span class="like-count"><?php 
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
                        $stmt->execute(['post_id' => $boostPost['id']]);
                        echo (int)$stmt->fetchColumn();
                        ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $boostPost['id'] ?>'">
                        💬 Comment (<?= getCommentCount($pdo, $boostPost['id']) ?>)
                    </span>
                    <span class="share-btn" onclick="sharePost(<?= $boostPost['id'] ?>)">
                        🔄 Share (<?= getShareCount($pdo, $boostPost['id']) ?>)
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Insert ads every 4 posts - USE SHUFFLED ADS
        if ($postCounter % 4 === 0 && count($allAds) > 0):
            // USE RANDOM AD INSTEAD OF SEQUENTIAL
            $randomAdIndex = array_rand($allAds);
            $ad = $allAds[$randomAdIndex];
            $adCounter++;
        ?>
            <!-- REGULAR ADVERTISEMENT -->
            <div class="post ad-post" data-ad-id="<?= $ad['id'] ?>">
                <div class="ad-label">Sponsored</div>
                
                <div class="post-header">
                    <img src="<?= htmlspecialchars($ad['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Advertiser" />
                    <div class="user-info">
                        <div class="username"><?= htmlspecialchars($ad['username']) ?></div>
                        <div class="timestamp">Sponsored Ad</div>
                    </div>
                </div>

                <div class="ad-header"><?= htmlspecialchars($ad['header']) ?></div>
                
                <div class="ad-description">
                    <?= nl2br(htmlspecialchars($ad['description'])) ?>
                </div>

                <!-- Ad Media -->
                <?php if (!empty($ad['media_path'])): ?>
                    <div class="ad-media-container">
                        <?php if ($ad['ad_type'] === 'video'): ?>
                            <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($ad['media_path']) ?>', 'ad-<?= $ad['id'] ?>')">
                                <video loop playsinline preload="metadata" class="ad-video" muted>
                                    <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                                    Your browser does not support the video tag.
                                </video>
                                <div class="video-controls">
                                    <button class="play-pause">▶️</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php
                            $adImages = explode(',', $ad['media_path']);
                            $adImageCount = count($adImages);
                            $adFirstFour = array_slice($adImages, 0, 4);
                            $adExtraCount = $adImageCount - 4;
                            ?>
                            <div class="ad-post-media">
                                <?php foreach ($adFirstFour as $index => $image):
                                    $image = trim($image);
                                ?>
                                    <div style="position:relative;">
                                        <?php if ($index < 3): ?>
                                            <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                        <?php elseif ($index === 3 && $adExtraCount > 0): ?>
                                            <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                            <div class="ad-overlay">
                                                +<?= $adExtraCount ?>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Call to Action Button -->
                <a href="<?= htmlspecialchars($ad['url']) ?>" target="_blank" class="cta-button">
                    <?= htmlspecialchars($ad['cta_button']) ?>
                </a>

                <!-- AD ENGAGEMENT BUTTONS -->
                <div class="ad-engagement">
                    <button class="ad-action-btn like-btn <?= $ad['user_liked'] ? 'liked' : '' ?>" 
                            data-ad-id="<?= $ad['id'] ?>">
                        <span class="icon">❤️</span>
                        Like <span class="count">(<?= $ad['like_count'] ?>)</span>
                    </button>
                    
                    <a href="ads_comment.php?ad_id=<?= $ad['id'] ?>" class="ad-action-btn comment-btn">
                        <span class="icon">💬</span>
                        Comment <span class="count">(<?= $ad['comment_count'] ?>)</span>
                    </a>
                    
                    <button class="ad-action-btn share-btn" onclick="shareAd(<?= $ad['id'] ?>)">
                        <span class="icon">↗️</span>
                        Share <span class="count">(<?= $ad['share_count'] ?>)</span>
                    </button>
                </div>

                <div style="text-align: center; margin-top: 10px;">
                    <span style="color: #888; font-size: 12px;">Advertisement</span>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php
}

require_once "back.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Family - Feed</title>
<style>
/* Reuse most styles from home.php, but customize for mixed content layout */
body { 
    font-family: Arial,sans-serif;
    max-width:800px; 
    margin:20px auto; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
}

/* Navigation */
.nav-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    border: 2px solid #7b68ee;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.nav-link {
    color: #7b68ee;
    text-decoration: none;
    font-weight: bold;
    padding: 8px 16px;
    border-radius: 20px;
    transition: all 0.3s ease;
}

.nav-link:hover {
    background: #7b68ee;
    color: white;
}

.nav-link.active {
    background: #7b68ee;
    color: white;
}

/* Search Bar */
.search-container {
    flex: 1;
    max-width: 400px;
    margin: 0 20px;
}

.search-box {
    width: 100%;
    padding: 12px 20px;
    border: 2px solid #7b68ee;
    border-radius: 25px;
    background: rgba(255, 255, 255, 0.95);
    color: #2d3748;
    font-size: 16px;
    transition: all 0.3s ease;
}

.search-box:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

/* Page Header */
.page-header {
    text-align: center;
    margin: 30px 0;
    padding: 20px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    border-radius: 15px;
    color: white;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.page-title {
    font-size: 28px;
    font-weight: bold;
    margin-bottom: 10px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.page-subtitle {
    font-size: 16px;
    opacity: 0.9;
}

/* Posts Container */
.posts {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.post {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    padding: 15px;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    border: 2px solid #7b68ee;
    transition: all 0.3s ease;
}

.post:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
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
    border: 2px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
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
    font-weight: 700;
}

.post-header .timestamp {
    font-size: 12px;
    color: #718096;
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
    color: #2d3748;
}

.post-content.expanded {
    max-height: none;
}

.show-more-btn {
    background: none;
    border: none;
    color: #7b68ee;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    margin: 0 0 8px 0;
    user-select: none;
    font-weight: 600;
}

/* Media display - UPDATED FOR IMAGES AND VIDEOS */
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
    transition: transform 0.3s ease;
}

.post-media img:hover {
    transform: scale(1.05);
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

/* Video Posts */
.video-post-container {
    position: relative;
    width: 100%;
    margin-bottom: 10px;
    overflow: hidden;
    border-radius: 10px;
    background: #000;
    cursor: pointer;
}

.video-post-container video {
    width: 100%;
    height: auto;
    max-height: 600px;
    object-fit: contain;
    display: block;
}

.video-controls {
    position: absolute;
    bottom: 15px;
    right: 15px;
    z-index: 10;
    display: flex;
    gap: 10px;
}

.play-pause {
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.3s ease;
}

.play-pause:hover {
    background: rgba(0, 0, 0, 0.9);
    transform: scale(1.1);
}

/* Hashtags */
.post-hashtags {
    margin: 10px 0;
}

.hashtag {
    color: #7b68ee;
    cursor: pointer;
    margin-right: 8px;
    font-size: 14px;
    font-weight: 600;
}

.hashtag:hover {
    text-decoration: underline;
}

/* Actions */
.actions {
    display: flex;
    gap: 20px;
    font-size: 14px;
    align-items: center;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
    padding-top: 10px;
    margin-top: 10px;
}

.actions span, .actions button {
    cursor: pointer;
    color: #7b68ee;
    user-select: none;
    transition: all 0.2s ease;
    background: none;
    border: none;
    padding: 0;
    font-weight: 600;
}

.actions span:hover, .actions button:hover {
    color: #6a5acd;
}

.liked {
    font-weight: bold;
    color: #ff004f !important;
}

/* Follow Button */
.follow-btn {
    padding: 6px 14px;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    user-select: none;
    transition: all 0.3s ease;
    font-size: 12px;
    margin-left: auto;
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.3);
}

.follow-btn.following {
    background: linear-gradient(135deg, #a0aec0, #718096);
    color: #f7fafc;
}

.follow-btn:hover:not(.following) {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.4);
}

/* Ad Posts */
.ad-post {
    border: 2px solid #ffd700;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
    position: relative;
}

.ad-label {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    color: #000;
    padding: 6px 12px;
    border-radius: 15px;
    font-weight: bold;
    font-size: 12px;
    margin-bottom: 10px;
    display: inline-block;
    box-shadow: 0 2px 8px rgba(255, 215, 0, 0.3);
}

.ad-header {
    font-size: 18px;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 8px;
}

.ad-description {
    color: #4a5568;
    margin-bottom: 15px;
    line-height: 1.4;
}

.ad-media-container {
    margin: 15px 0;
    border-radius: 10px;
    overflow: hidden;
}

.ad-media-container img {
    width: 100%;
    height: auto;
    max-height: 400px;
    border-radius: 8px;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.ad-media-container video {
    width: 100%;
    max-height: 400px;
    border-radius: 8px;
}

.cta-button {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 25px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin: 10px 0;
    transition: all 0.3s ease;
    text-align: center;
    width: 100%;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.cta-button:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

/* Image grid for ad media */
.ad-post-media {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-gap: 8px;
    margin-bottom: 15px;
}

.ad-post-media img {
    width: 100%;
    height: 150px;
    object-fit: cover;
    border-radius: 8px;
    transition: transform 0.3s ease;
}

.ad-post-media img:hover {
    transform: scale(1.05);
}

.ad-overlay {
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
    border-radius: 8px;
    cursor: pointer;
}

/* Boosted Posts */
.boosted-post {
    border: 2px solid #28a745;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
}

.sponsored-label {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    padding: 6px 12px;
    border-radius: 15px;
    font-weight: bold;
    font-size: 12px;
    margin-bottom: 10px;
    display: inline-block;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
}

/* Full Screen Video Styles */
.fullscreen-video-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.95);
    z-index: 9999;
    display: none;
    justify-content: center;
    align-items: center;
}

.fullscreen-video-container {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
}

.fullscreen-video {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.fullscreen-video-controls {
    position: absolute;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 15px;
    z-index: 10000;
}

.fullscreen-close-btn {
    position: absolute;
    top: 20px;
    right: 20px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    font-size: 24px;
    cursor: pointer;
    z-index: 10000;
}

.fullscreen-nav-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    font-size: 20px;
    cursor: pointer;
    z-index: 10000;
}

.fullscreen-prev-btn {
    left: 20px;
}

.fullscreen-next-btn {
    right: 20px;
}

.video-counter {
    position: absolute;
    top: 20px;
    left: 20px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 14px;
    z-index: 10000;
}

/* No Posts Message */
.no-posts {
    text-align: center;
    padding: 60px 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    margin: 40px 0;
    border: 2px solid #7b68ee;
}

.no-posts-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.7;
}

.no-posts h3 {
    color: #7b68ee;
    margin-bottom: 15px;
    font-weight: 700;
}

.no-posts p {
    color: #718096;
    margin-bottom: 25px;
    line-height: 1.6;
}

.explore-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 12px 30px;
    border: none;
    border-radius: 25px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.explore-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
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

.post, .no-posts {
    animation: fadeInUp 0.6s ease-out;
}

/* Ad Engagement Buttons */
.ad-engagement {
    display: flex;
    gap: 15px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
}

.ad-action-btn {
    background: none;
    border: none;
    color: #ffd700;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 8px 12px;
    border-radius: 6px;
    transition: all 0.3s ease;
    flex: 1;
    justify-content: center;
}

.ad-action-btn:hover {
    background-color: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
}

.ad-action-btn.liked {
    color: #ff004f;
    font-weight: bold;
}

.ad-action-btn .icon {
    font-size: 16px;
}

.ad-action-btn .count {
    font-size: 12px;
    color: #718096;
}

.ad-action-btn.like-btn {
    color: #ff6b6b;
}

.ad-action-btn.comment-btn {
    color: #4ecdc4;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ad-action-btn.share-btn {
    color: #45b7d1;
}

.ad-action-btn.like-btn.liked {
    color: #ff004f;
    background: rgba(255, 0, 79, 0.1);
}

.ad-action-btn.comment-btn:hover {
    text-decoration: none;
}

/* Loading Spinner */
.loading-spinner {
    text-align: center;
    padding: 20px;
    display: none;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #7b68ee;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* No Results */
.no-results {
    text-align: center;
    padding: 40px 20px;
    color: #718096;
    font-style: italic;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    margin: 20px 0;
    backdrop-filter: blur(10px);
}

/* Responsive */
@media (max-width: 600px) {
    .nav-container {
        flex-direction: column;
        gap: 15px;
        padding: 12px;
    }
    
    .search-container {
        max-width: 100%;
        margin: 0;
    }
    
    .video-post-container video {
        max-height: 400px;
    }
    
    .actions {
        gap: 15px;
        font-size: 13px;
    }
    
    .page-title {
        font-size: 22px;
    }
    
    .post-media {
        grid-template-columns: 1fr 1fr;
        grid-gap: 4px;
    }
    
    .post-media img, .overlay {
        height: 120px;
    }
    
    .ad-post-media {
        grid-template-columns: 1fr;
    }
    
    .ad-post-media img {
        height: 200px;
    }
    
    .fullscreen-nav-btn {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .fullscreen-close-btn {
        width: 40px;
        height: 40px;
        font-size: 20px;
    }
    
    .post {
        padding: 12px;
        margin-bottom: 15px;
    }
    
    .follow-btn {
        padding: 6px 12px;
        font-size: 11px;
    }
    
    .ad-engagement {
        gap: 8px;
    }
    
    .ad-action-btn {
        font-size: 12px;
        padding: 6px 8px;
    }
}
</style>
</head>
<body>

<!-- Navigation -->
<div class="nav-container">
    <a href="home.php" class="nav-link">Home Feed</a>
    <a href="family.php" class="nav-link active">Family Feed</a>
    <a href="profile.php?id=<?= urlencode($userId) ?>" class="nav-link">My Profile</a>
    
    <!-- Search Bar -->
    <div class="search-container">
        <form method="GET" action="family.php">
            <input type="text" name="search" class="search-box" placeholder="Search posts, people, hashtags..." 
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </form>
    </div>
</div>

<!-- Page Header -->
<div class="page-header">
    <div class="page-title">👨‍👩‍👧‍👦 Family Feed</div>
    <div class="page-subtitle">Posts from your friends and people you follow</div>
</div>

<!-- Full Screen Video Overlay -->
<div class="fullscreen-video-overlay" id="fullscreenVideoOverlay">
    <button class="fullscreen-close-btn" onclick="closeFullscreenVideo()">✕</button>
    <div class="fullscreen-video-container">
        <video class="fullscreen-video" id="fullscreenVideo" controls></video>
        <div class="video-counter" id="videoCounter">1/1</div>
        <button class="fullscreen-nav-btn fullscreen-prev-btn" onclick="navigateVideo(-1)">❮</button>
        <button class="fullscreen-nav-btn fullscreen-next-btn" onclick="navigateVideo(1)">❯</button>
    </div>
</div>

<!-- Posts Container -->
<div class="posts" id="postsContainer">
    <?php if (empty($posts) && empty($targetedAds) && empty($boostedPosts)): ?>
        <div class="no-posts">
            <div class="no-posts-icon">📱</div>
            <h3>No Posts Yet</h3>
            <p>You haven't followed anyone or your friends haven't posted anything yet.<br>
               Start following people to see their posts here!</p>
            <a href="home.php" class="explore-btn">Explore Home Feed</a>
        </div>
    <?php else: ?>
        <?php includePosts($posts, $pdo, $userId, $targetedAds, $boostedPosts, $page); ?>
    <?php endif; ?>
</div>

<!-- Loading Spinner -->
<div id="loading-spinner" class="loading-spinner">
    <div class="spinner"></div>
    <p>Loading more posts...</p>
</div>

<!-- End of Posts Message -->
<div id="end-of-posts" class="no-results" style="display: none;">
    <p>No more posts to load.</p>
</div>

<script>
// ========== PAGINATION VARIABLES ==========
let currentPage = <?= $page ?>;
let isLoading = false;
let hasMorePosts = true;
const postsPerPage = 8;

// ========== INFINITE SCROLL FUNCTIONALITY ==========
function initInfiniteScroll() {
    window.addEventListener('scroll', handleScroll);
}

function handleScroll() {
    if (isLoading || !hasMorePosts) return;
    
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const windowHeight = window.innerHeight;
    const documentHeight = document.documentElement.scrollHeight;
    
    // Load more when 100px from bottom
    if (scrollTop + windowHeight >= documentHeight - 100) {
        loadMorePosts();
    }
}

async function loadMorePosts() {
    if (isLoading || !hasMorePosts) return;
    
    isLoading = true;
    const loadingSpinner = document.getElementById('loading-spinner');
    const endOfPosts = document.getElementById('end-of-posts');
    
    // Show loading spinner
    loadingSpinner.style.display = 'block';
    
    try {
        currentPage++;
        
        // Create URL with current parameters
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', currentPage);
        
        const response = await fetch(`family.php?${urlParams.toString()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const newPostsHtml = await response.text();
        
        if (newPostsHtml.includes('no-results') || newPostsHtml.trim() === '') {
            // No more posts to load
            hasMorePosts = false;
            endOfPosts.style.display = 'block';
        } else {
            // Append new posts
            const postsList = document.getElementById('postsContainer');
            postsList.insertAdjacentHTML('beforeend', newPostsHtml);
            
            // Re-attach event listeners to new posts
            attachEventListenersToNewPosts();
        }
    } catch (error) {
        console.error('Error loading more posts:', error);
        currentPage--; // Revert page on error
    } finally {
        isLoading = false;
        loadingSpinner.style.display = 'none';
    }
}

function attachEventListenersToNewPosts() {
    // Attach like button listeners to new posts
    document.querySelectorAll('.like-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleLikeClick);
        }
    });
    
    // Attach follow button listeners to new posts
    document.querySelectorAll('.follow-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleFollowClick);
        }
    });
    
    // Attach ad like button listeners to new posts
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleAdLike);
        }
    });
    
    // Initialize video controls for new posts
    initVideoControls();
    
    // Attach show more buttons
    document.querySelectorAll('.show-more-btn').forEach(btn => {
        if (!btn.hasAttribute('data-listener-attached')) {
            btn.setAttribute('data-listener-attached', 'true');
            btn.addEventListener('click', handleShowMore);
        }
    });
}

// Fullscreen Video Variables
let currentVideoIndex = 0;
let currentVideoList = [];
let currentPostId = null;

// Open fullscreen video with autoplay and unmuted
function openFullscreenVideo(mediaUrls, postId) {
    const videoArray = mediaUrls.split(',').map(url => url.trim());
    currentVideoList = videoArray;
    currentVideoIndex = 0;
    currentPostId = postId;
    
    const fullscreenVideo = document.getElementById('fullscreenVideo');
    const overlay = document.getElementById('fullscreenVideoOverlay');
    const videoCounter = document.getElementById('videoCounter');
    
    // Set video source and properties
    fullscreenVideo.src = currentVideoList[currentVideoIndex];
    fullscreenVideo.muted = false; // Unmute by default in fullscreen
    fullscreenVideo.autoplay = true; // Autoplay in fullscreen
    fullscreenVideo.controls = true;
    
    // Update video counter
    videoCounter.textContent = `${currentVideoIndex + 1}/${currentVideoList.length}`;
    
    // Show/hide navigation buttons based on video count
    document.querySelector('.fullscreen-prev-btn').style.display = currentVideoList.length > 1 ? 'block' : 'none';
    document.querySelector('.fullscreen-next-btn').style.display = currentVideoList.length > 1 ? 'block' : 'none';
    
    // Show overlay
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Try to play the video
    fullscreenVideo.play().catch(e => {
        console.log('Fullscreen video autoplay prevented:', e);
    });
}

// Close fullscreen video
function closeFullscreenVideo() {
    const fullscreenVideo = document.getElementById('fullscreenVideo');
    const overlay = document.getElementById('fullscreenVideoOverlay');
    
    // Pause video
    fullscreenVideo.pause();
    fullscreenVideo.src = '';
    
    // Hide overlay
    overlay.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset variables
    currentVideoList = [];
    currentVideoIndex = 0;
    currentPostId = null;
}

// Navigate between videos in fullscreen
function navigateVideo(direction) {
    if (currentVideoList.length <= 1) return;
    
    const fullscreenVideo = document.getElementById('fullscreenVideo');
    const videoCounter = document.getElementById('videoCounter');
    
    // Calculate new index
    currentVideoIndex += direction;
    if (currentVideoIndex < 0) {
        currentVideoIndex = currentVideoList.length - 1;
    } else if (currentVideoIndex >= currentVideoList.length) {
        currentVideoIndex = 0;
    }
    
    // Update video source
    fullscreenVideo.src = currentVideoList[currentVideoIndex];
    fullscreenVideo.muted = false; // Ensure unmuted
    fullscreenVideo.autoplay = true; // Autoplay when navigating
    
    // Update counter
    videoCounter.textContent = `${currentVideoIndex + 1}/${currentVideoList.length}`;
    
    // Try to play the video
    fullscreenVideo.play().catch(e => {
        console.log('Video navigation autoplay prevented:', e);
    });
}

// Close fullscreen on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFullscreenVideo();
    }
});

// Swipe functionality for fullscreen videos
let touchStartX = 0;
let touchEndX = 0;

document.getElementById('fullscreenVideoOverlay').addEventListener('touchstart', function(e) {
    touchStartX = e.changedTouches[0].screenX;
});

document.getElementById('fullscreenVideoOverlay').addEventListener('touchend', function(e) {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
});

function handleSwipe() {
    const swipeThreshold = 50;
    const diff = touchStartX - touchEndX;
    
    if (Math.abs(diff) > swipeThreshold) {
        if (diff > 0) {
            // Swipe left - next video
            navigateVideo(1);
        } else {
            // Swipe right - previous video
            navigateVideo(-1);
        }
    }
}

// Video Controls for Family Videos (regular view - no autoplay)
function toggleVideoPlayPause(videoContainer) {
    const video = videoContainer.querySelector('.family-video');
    const playPauseBtn = videoContainer.querySelector('.play-pause');
    
    if (video.paused) {
        video.play().then(() => {
            if (playPauseBtn) playPauseBtn.textContent = '⏸️';
        }).catch(e => {
            console.log('Video play prevented:', e);
        });
    } else {
        video.pause();
        if (playPauseBtn) playPauseBtn.textContent = '▶️';
    }
}

// Video Controls for Ads (regular view - no autoplay)
function toggleAdVideoPlayPause(videoContainer) {
    const video = videoContainer.querySelector('.ad-video');
    const playPauseBtn = videoContainer.querySelector('.play-pause');
    
    if (video.paused) {
        video.play().then(() => {
            if (playPauseBtn) playPauseBtn.textContent = '⏸️';
        }).catch(e => {
            console.log('Ad video play prevented:', e);
        });
    } else {
        video.pause();
        if (playPauseBtn) playPauseBtn.textContent = '▶️';
    }
}

// Show More / Show Less Toggle
function handleShowMore() {
    const postId = this.dataset.postId;
    const content = document.getElementById('post-content-' + postId.replace('boost-', ''));
    if (content.classList.contains('expanded')) {
        content.classList.remove('expanded');
        this.textContent = 'Show More';
    } else {
        content.classList.add('expanded');
        this.textContent = 'Show Less';
    }
}

// Like functionality - PURE OPTIMISTIC
function handleLikeClick() {
    const postId = this.dataset.postId;
    const isLiked = this.classList.contains('liked');
    const action = isLiked ? 'unlike' : 'like';
    
    // ALWAYS update UI immediately for all matching buttons
    const likeCountSpan = this.querySelector('.like-count');
    const currentCount = parseInt(likeCountSpan.textContent) || 0;
    
    document.querySelectorAll(`.like-btn[data-post-id="${postId}"]`).forEach(likeBtn => {
        const otherLikeCountSpan = likeBtn.querySelector('.like-count');
        
        // Toggle state immediately
        if (action === 'like') {
            likeBtn.classList.add('liked');
            if (otherLikeCountSpan) {
                otherLikeCountSpan.textContent = currentCount + 1;
            }
        } else {
            likeBtn.classList.remove('liked');
            if (otherLikeCountSpan) {
                otherLikeCountSpan.textContent = Math.max(0, currentCount - 1);
            }
        }
        
        // Visual feedback
        likeBtn.style.transform = 'scale(1.1)';
        setTimeout(() => {
            likeBtn.style.transform = 'scale(1)';
        }, 200);
    });

    // Send to server in background (completely fire and forget)
    const formData = new FormData();
    formData.append('action', action);
    formData.append('post_id', postId);
    
    fetch('family.php', {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Like action failed in background:', err);
    });
}

// Follow/unfollow functionality - PURE OPTIMISTIC
function handleFollowClick() {
    const userId = this.getAttribute('data-user-id');
    if (!userId) return;

    const isFollowing = this.classList.contains('following');
    const action = isFollowing ? 'unfollow' : 'follow';

    // ALWAYS update UI immediately for all matching buttons
    document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(btn => {
        // Toggle state immediately
        if (action === 'follow') {
            btn.textContent = 'Following';
            btn.classList.add('following');
        } else {
            btn.textContent = 'Follow';
            btn.classList.remove('following');
        }
        
        // Visual feedback
        btn.style.transform = 'scale(1.05)';
        setTimeout(() => {
            btn.style.transform = 'scale(1)';
        }, 200);
    });

    // Send to server in background (completely fire and forget)
    const formData = new FormData();
    formData.append('action', action);
    formData.append('followed_id', userId);

    fetch('family.php', { 
        method: 'POST', 
        body: formData 
    }).catch(err => {
        console.error('Follow action failed in background:', err);
    });
}

// Ad like functionality - SIMPLE VERSION (always update UI)
async function handleAdLike(e) {
    const btn = e.currentTarget;
    const adId = btn.getAttribute('data-ad-id');
    if (!adId) return;

    const isLiked = btn.classList.contains('liked');
    const action = isLiked ? 'unlike_ad' : 'like_ad';

    // ALWAYS update UI immediately
    const countSpan = btn.querySelector('.count');
    const currentCount = parseInt(countSpan.textContent.replace(/[()]/g, '')) || 0;
    
    // Toggle visual state immediately
    btn.classList.toggle('liked');
    
    // Update count immediately
    if (isLiked) {
        countSpan.textContent = `(${Math.max(0, currentCount - 1)})`;
    } else {
        countSpan.textContent = `(${currentCount + 1})`;
    }

    // Visual feedback
    btn.style.transform = 'scale(1.1)';
    setTimeout(() => {
        btn.style.transform = 'scale(1)';
    }, 200);

    // Send to server in background (fire and forget)
    const formData = new FormData();
    formData.append('action', action);
    formData.append('ad_id', adId);

    fetch('family.php', {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Ad like action failed in background:', err);
        // Don't revert - keep the optimistic UI update
    });
}

// Search functionality
function searchHashtag(tag) {
    window.location.href = 'search.php?q=' + encodeURIComponent('#' + tag);
}

// Share functionality for posts
function sharePost(postId) {
    const postUrl = `${window.location.origin}/profile.php?post=${postId}`;
    
    if (navigator.share) {
        navigator.share({
            title: 'Check out this post',
            url: postUrl
        }).catch(err => {
            console.log('Error sharing:', err);
            copyToClipboard(postUrl);
        });
    } else {
        copyToClipboard(postUrl);
    }
}

// Share functionality for ads
async function shareAd(adId) {
    const adUrl = `${window.location.origin}/ad_view.php?id=${adId}`;
    
    if (navigator.share) {
        // Use Web Share API if available
        try {
            await navigator.share({
                title: 'Check out this ad',
                url: adUrl
            });
            
            // Record the share
            const formData = new FormData();
            formData.append('action', 'share_ad');
            formData.append('ad_id', adId);
            
            await fetch('family.php', {
                method: 'POST',
                body: formData
            });
            
        } catch (err) {
            console.log('Error sharing:', err);
            copyAdToClipboard(adUrl, adId);
        }
    } else {
        // Fallback to clipboard
        copyAdToClipboard(adUrl, adId);
    }
}

// Copy ad to clipboard and record share
async function copyAdToClipboard(text, adId) {
    try {
        await navigator.clipboard.writeText(text);
        alert('Ad link copied to clipboard!');
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share_ad');
        formData.append('ad_id', adId);
        
        await fetch('family.php', {
            method: 'POST',
            body: formData
        });
        
        // Update share count visually
        const shareBtn = document.querySelector(`.ad-action-btn.share-btn[data-ad-id="${adId}"]`);
        if (shareBtn) {
            const countSpan = shareBtn.querySelector('.count');
            if (countSpan) {
                const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
                countSpan.textContent = `(${currentCount + 1})`;
            }
        }
        
    } catch (err) {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Ad link copied to clipboard!');
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share_ad');
        formData.append('ad_id', adId);
        
        await fetch('family.php', {
            method: 'POST',
            body: formData
        });
    }
}

// Copy to clipboard function
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Post link copied to clipboard!');
    }).catch(err => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Post link copied to clipboard!');
    });
}

// Initialize video controls
function initVideoControls() {
    // Set all videos to muted and paused by default in regular view
    document.querySelectorAll('.family-video, .ad-video').forEach(video => {
        video.muted = true; // Muted in regular view
        video.playsInline = true;
        video.autoplay = false; // No autoplay in regular view
        video.pause(); // Ensure all videos are paused initially
        
        // Update play/pause buttons based on video state
        video.addEventListener('play', () => {
            const playPauseBtn = video.closest('.video-post-container').querySelector('.play-pause');
            if (playPauseBtn) playPauseBtn.textContent = '⏸️';
        });
        
        video.addEventListener('pause', () => {
            const playPauseBtn = video.closest('.video-post-container').querySelector('.play-pause');
            if (playPauseBtn) playPauseBtn.textContent = '▶️';
        });
    });
}

// Initialize everything when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initialize video controls
    initVideoControls();
    
    // Initialize infinite scroll
    initInfiniteScroll();
    
    // Attach event listeners to existing elements
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', handleLikeClick);
    });
    
    document.querySelectorAll('.follow-btn').forEach(btn => {
        btn.addEventListener('click', handleFollowClick);
    });
    
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(btn => {
        btn.addEventListener('click', handleAdLike);
    });
    
    document.querySelectorAll('.show-more-btn').forEach(btn => {
        btn.addEventListener('click', handleShowMore);
    });
});
</script>

</body>
</html>