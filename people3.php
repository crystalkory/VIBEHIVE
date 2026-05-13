<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];

// ========== PAGINATION VARIABLES ==========
$postsPerPage = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $postsPerPage;

// Check if this is an AJAX request for loading more posts
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

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

// ========== ADS TARGETING FUNCTIONS (FROM FEED.PHP) ==========

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

// Continent definitions (must match home.php)
$continents = [
    'Africa' => [
        'countries' => ['DZ', 'AO', 'BJ', 'BW', 'BF', 'BI', 'CV', 'CM', 'CF', 'TD', 'KM', 'CG', 'CD', 'DJ', 'EG', 'GQ', 'ER', 'SZ', 'ET', 'GA', 'GM', 'GH', 'GN', 'GW', 'KE', 'LS', 'LR', 'LY', 'MG', 'MW', 'ML', 'MR', 'MU', 'MA', 'MZ', 'NA', 'NE', 'NG', 'RW', 'ST', 'SN', 'SC', 'SL', 'SO', 'ZA', 'SS', 'SD', 'TZ', 'TG', 'TN', 'UG', 'ZM', 'ZW']
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
    if (count($adLocations) > 100) { // Assuming "all countries" means many countries
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

// Get targeted ads for current user
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
    $_SESSION['people_shuffled_ads'] = $targetedAds;
    
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

// ========== BOOSTED POSTS LOGIC (FROM FEED.PHP) ==========
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
$targetedAds = getTargetedAds($pdo, $currentUserId, 30);

// Add engagement counts to all ads
$ads_with_engagement = [];
foreach ($targetedAds as $ad) {
    $ad['like_count'] = getAdLikeCount($pdo, $ad['id']);
    $ad['comment_count'] = getAdCommentCount($pdo, $ad['id']);
    $ad['share_count'] = getAdShareCount($pdo, $ad['id']);
    $ad['user_liked'] = userLikedAd($pdo, $currentUserId, $ad['id']);
    $ads_with_engagement[] = $ad;
}

$targetedAds = $ads_with_engagement;
$adCount = count($targetedAds);
$boostCount = count($boostedPosts);

// ========== EXISTING PEOPLE.PHP LOGIC ==========

// Define categories and post types
$categories = ['All', 'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'];
$postTypes = ['All Posts', 'Video Post', 'Image Post', 'Text Post'];

// Get selected category and post type from GET parameters
$selectedCategory = $_GET['category'] ?? 'All';
$selectedPostType = $_GET['post_type'] ?? 'All Posts';
$activeTab = $_GET['tab'] ?? 'people';
$searchFilter = trim($_GET['q'] ?? '');
$postSearchFilter = trim($_GET['post_search'] ?? ''); // New: Separate post search filter

// Prepare base query for users
$sqlBase = "SELECT u.id, u.username, u.profile_pic_url, u.category1, u.category2, 
                   COUNT(f.follower_id) as follower_count
            FROM users u 
            LEFT JOIN follows f ON u.id = f.followed_id 
            WHERE u.id != :currentUser";

$params = ['currentUser' => $currentUserId];

// Apply category filter if not 'All'
if ($selectedCategory !== 'All') {
    $sqlBase .= " AND (u.category1 = :category OR u.category2 = :category)";
    $params['category'] = $selectedCategory;
}

// Apply search filter
if ($searchFilter !== '') {
    $sqlBase .= " AND LOWER(u.username) LIKE :search";
    $params['search'] = '%' . strtolower($searchFilter) . '%';
}

$sqlBase .= " GROUP BY u.id ORDER BY follower_count DESC, u.username ASC";

$stmt = $pdo->prepare($sqlBase);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get like count
function getLikeCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Function to get comment count
function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Function to get share count
function getShareCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shares WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Function to calculate post points
function calculatePostPoints($pdo, $postId) {
    $likeCount = getLikeCount($pdo, $postId);
    $commentCount = getCommentCount($pdo, $postId);
    $shareCount = getShareCount($pdo, $postId);
    
    // Calculate points: 0.3 per like, 0.7 per comment, 1 per share
    $points = ($likeCount * 0.3) + ($commentCount * 0.7) + ($shareCount * 1);
    return round($points, 2);
}

// Fetch posts for posts tab - apply search filter to posts as well
$postsSql = "SELECT p.*, u.username, u.profile_pic_url, u.category1, u.category2 
             FROM posts p 
             JOIN users u ON p.user_id = u.id 
             WHERE 1=1";

$postsParams = [];

// Apply category filter for posts
if ($selectedCategory !== 'All') {
    $postsSql .= " AND (u.category1 = :category OR u.category2 = :category)";
    $postsParams['category'] = $selectedCategory;
}

// Apply post type filter
if ($selectedPostType !== 'All Posts') {
    $postTypeMap = [
        'Video Post' => 'video',
        'Image Post' => 'photo', 
        'Text Post' => 'text'
    ];
    $postsSql .= " AND p.post_type = :post_type";
    $postsParams['post_type'] = $postTypeMap[$selectedPostType];
}

// Apply search filter to posts (search in content and username)
if ($searchFilter !== '') {
    $postsSql .= " AND (LOWER(p.content) LIKE :search OR LOWER(u.username) LIKE :search)";
    $postsParams['search'] = '%' . strtolower($searchFilter) . '%';
}

// Apply dedicated post search filter (NEW)
if ($postSearchFilter !== '') {
    $postsSql .= " AND (LOWER(p.content) LIKE :post_search OR LOWER(u.username) LIKE :post_search)";
    $postsParams['post_search'] = '%' . strtolower($postSearchFilter) . '%';
}

// Remove the old ORDER BY and we'll sort by points after fetching
$postsStmt = $pdo->prepare($postsSql);
$postsStmt->execute($postsParams);
$allPosts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate points for each post and add to array
foreach ($allPosts as &$post) {
    $post['points'] = calculatePostPoints($pdo, $post['id']);
    $post['like_count'] = getLikeCount($pdo, $post['id']);
    $post['comment_count'] = getCommentCount($pdo, $post['id']);
    $post['share_count'] = getShareCount($pdo, $post['id']);
}
unset($post); // Break the reference

// Sort posts by points in descending order (highest points first)
usort($allPosts, function($a, $b) {
    return $b['points'] <=> $a['points'];
});

// Paginate posts - only get the posts for current page
$totalPosts = count($allPosts);
$totalPages = ceil($totalPosts / $postsPerPage);
$posts = array_slice($allPosts, $offset, $postsPerPage);

// If this is an AJAX request, only output the posts and exit
if ($isAjaxRequest && $activeTab === 'posts') {
    if (empty($posts)) {
        echo '<div class="no-results">No more posts to load.</div>';
        exit;
    }
    
    // Output the posts HTML
    ob_start();
    includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $page);
    $postsHtml = ob_get_clean();
    echo $postsHtml;
    exit;
}

// Fetch friend requests and accepted friends info
$pendingStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'pending'");
$pendingStmt->execute([$currentUserId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);

$friendsStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'");
$friendsStmt->execute([$currentUserId]);
$acceptedFriends = $friendsStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch list of users the current user follows
$followStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$followStmt->execute([$currentUserId]);
$followingUserIds = $followStmt->fetchAll(PDO::FETCH_COLUMN);

// Function to check if user liked a post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// ========== AJAX HANDLERS FOR COMMENTS (FROM FAMILY.PHP) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- GET POST DATA FOR COMMENTS POPUP ---
    if (isset($_POST['action']) && $_POST['action'] === 'get_post_data' && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        $userId = $_SESSION['user_id'];
        
        try {
            // Fetch post info
            $postStmt = $pdo->prepare("
                SELECT p.*, u.username, u.profile_pic_url, u.id as author_id
                FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.id = :post_id
            ");
            $postStmt->execute(['post_id' => $postId]);
            $post = $postStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$post) {
                echo json_encode(['success' => false, 'error' => 'Post not found']);
                exit;
            }
            
            // Fetch main comments (where parent_comment_id is NULL)
            $commentsStmt = $pdo->prepare("
                SELECT c.id, c.content, c.user_id, c.created_at, c.updated_at, 
                       u.username, u.profile_pic_url,
                       (SELECT COUNT(*) FROM comments r WHERE r.parent_comment_id = c.id) as reply_count
                FROM comments c
                JOIN users u ON c.user_id = u.id
                WHERE c.post_id = :post_id AND c.parent_comment_id IS NULL
                ORDER BY c.created_at ASC
            ");
            $commentsStmt->execute(['post_id' => $postId]);
            $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get like count for the post
            $likeStmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
            $likeStmt->execute([$postId]);
            $likeCount = $likeStmt->fetchColumn();
            
            // Check if current user liked this post
            $userLikeStmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = ? AND user_id = ?");
            $userLikeStmt->execute([$postId, $userId]);
            $userLiked = (bool)$userLikeStmt->fetchColumn();
            
            // Get TOTAL comment count (including replies)
            $totalCommentStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = ?");
            $totalCommentStmt->execute([$postId]);
            $totalCommentCount = (int)$totalCommentStmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'post' => $post,
                'comments' => $comments,
                'likeCount' => (int)$likeCount,
                'commentCount' => $totalCommentCount,
                'userLiked' => $userLiked,
                'currentUserId' => $userId
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Get post data error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- GET POST COMMENT REPLIES ---
    if (isset($_POST['action']) && $_POST['action'] === 'get_post_replies' && isset($_POST['comment_id'])) {
        $commentId = (int)$_POST['comment_id'];
        $userId = $_SESSION['user_id'];
        
        try {
            // Fetch replies for this comment
            $repliesStmt = $pdo->prepare("
                SELECT c.id, c.content, c.user_id, c.created_at, c.updated_at, 
                       u.username, u.profile_pic_url
                FROM comments c
                JOIN users u ON c.user_id = u.id
                WHERE c.parent_comment_id = :comment_id
                ORDER BY c.created_at ASC
            ");
            $repliesStmt->execute(['comment_id' => $commentId]);
            $replies = $repliesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'replies' => $replies,
                'currentUserId' => $userId
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Get post replies error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- GET AD DATA FOR COMMENTS POPUP ---
    if (isset($_POST['action']) && $_POST['action'] === 'get_ad_data' && isset($_POST['ad_id'])) {
        $adId = (int)$_POST['ad_id'];
        $currentUserId = $_SESSION['user_id'];
        
        try {
            // Fetch ad info
            $adStmt = $pdo->prepare("
                SELECT a.*, u.username, u.profile_pic_url, u.id as advertiser_id
                FROM ads a 
                JOIN users u ON a.user_id = u.id 
                WHERE a.id = ? AND a.status = 'active' AND a.ends_at > CURRENT_TIMESTAMP
            ");
            $adStmt->execute([$adId]);
            $ad = $adStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ad) {
                echo json_encode(['success' => false, 'error' => 'Advertisement not found or expired']);
                exit;
            }
            
            // Fetch main comments (where parent_comment_id is NULL)
            $commentsStmt = $pdo->prepare("
                SELECT ac.*, u.username, u.profile_pic_url,
                       (SELECT COUNT(*) FROM ad_comments r WHERE r.parent_comment_id = ac.id) as reply_count
                FROM ad_comments ac 
                JOIN users u ON ac.user_id = u.id 
                WHERE ac.ad_id = ? AND ac.parent_comment_id IS NULL
                ORDER BY ac.created_at ASC
            ");
            $commentsStmt->execute([$adId]);
            $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get like count
            $likeStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
            $likeStmt->execute([$adId]);
            $likeCount = $likeStmt->fetchColumn();
            
            // Get share count
            $shareStmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
            $shareStmt->execute([$adId]);
            $shareCount = $shareStmt->fetchColumn();
            
            // Check if current user liked this ad
            $userLikeStmt = $pdo->prepare("SELECT 1 FROM ad_likes WHERE ad_id = ? AND user_id = ?");
            $userLikeStmt->execute([$adId, $currentUserId]);
            $userLiked = (bool)$userLikeStmt->fetchColumn();
            
            // Get current user profile pic
            $userStmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
            $userStmt->execute([$currentUserId]);
            $userProfilePic = $userStmt->fetchColumn() ?: 'default_profile.png';
            
            // Get TOTAL comment count (including replies)
            $totalCommentStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_comments WHERE ad_id = ?");
            $totalCommentStmt->execute([$adId]);
            $totalCommentCount = (int)$totalCommentStmt->fetchColumn();
            
            // Add advertiser_id to ad data for easier access
            $ad['advertiser_id'] = $ad['user_id']; // This is the advertiser's user ID
            
            echo json_encode([
                'success' => true,
                'ad' => $ad,
                'comments' => $comments,
                'likeCount' => (int)$likeCount,
                'shareCount' => (int)$shareCount,
                'userLiked' => $userLiked,
                'userProfilePic' => $userProfilePic,
                'currentUserId' => $currentUserId,
                'commentCount' => $totalCommentCount
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Ad data error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- GET AD COMMENT REPLIES ---
    if (isset($_POST['action']) && $_POST['action'] === 'get_ad_replies' && isset($_POST['comment_id'])) {
        $commentId = (int)$_POST['comment_id'];
        $currentUserId = $_SESSION['user_id'];
        
        try {
            // Fetch replies for this ad comment
            $repliesStmt = $pdo->prepare("
                SELECT ac.*, u.username, u.profile_pic_url
                FROM ad_comments ac 
                JOIN users u ON ac.user_id = u.id 
                WHERE ac.parent_comment_id = :comment_id
                ORDER BY ac.created_at ASC
            ");
            $repliesStmt->execute(['comment_id' => $commentId]);
            $replies = $repliesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'replies' => $replies,
                'currentUserId' => $currentUserId
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Get ad replies error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- ADD POST COMMENT HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'add_post_comment' && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        $content = trim($_POST['content'] ?? '');
        $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
        $currentUserId = $_SESSION['user_id'];
        
        if (empty($content)) {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }
        
        try {
            // Check if post exists
            $checkStmt = $pdo->prepare("SELECT id, user_id FROM posts WHERE id = ?");
            $checkStmt->execute([$postId]);
            $post = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$post) {
                echo json_encode(['success' => false, 'error' => 'Post not found']);
                exit;
            }
            
            // Insert comment
            $stmt = $pdo->prepare("
                INSERT INTO comments (post_id, user_id, content, parent_comment_id, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$postId, $currentUserId, $content, $parentCommentId]);
            $newCommentId = $pdo->lastInsertId();
            
            // Create notification for post owner if not commenting on own post
            if ($post['user_id'] != $currentUserId) {
                $message = $parentCommentId ? "replied to your comment" : "commented on your post";
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, source_user_id, post_id, message, created_at) 
                    VALUES (?, 'comment', ?, ?, ?, NOW())
                ");
                $stmt->execute([$post['user_id'], $currentUserId, $postId, $message]);
            }
            
            // If this is a reply, also notify the parent comment author if different
            if ($parentCommentId && $parentCommentId > 0) {
                $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
                $stmt->execute([$parentCommentId]);
                $parentCommentUserId = $stmt->fetchColumn();
                
                if ($parentCommentUserId && $parentCommentUserId != $currentUserId && $parentCommentUserId != $post['user_id']) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, source_user_id, post_id, message, created_at) 
                        VALUES (?, 'comment', ?, ?, ?, NOW())
                    ");
                    $message = "replied to your comment";
                    $stmt->execute([$parentCommentUserId, $currentUserId, $postId, $message]);
                }
            }
            
            // Get the new comment with user info
            $stmt = $pdo->prepare("
                SELECT c.*, u.username, u.profile_pic_url 
                FROM comments c 
                JOIN users u ON c.user_id = u.id 
                WHERE c.id = ?
            ");
            $stmt->execute([$newCommentId]);
            $newComment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Add reply_count to the comment if it's a main comment
            if (!$parentCommentId) {
                $newComment['reply_count'] = 0;
            }
            
            echo json_encode([
                'success' => true,
                'comment' => $newComment,
                'is_reply' => ($parentCommentId !== null)
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Add post comment error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- ADD AD COMMENT HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'add_ad_comment' && isset($_POST['ad_id'])) {
        $adId = (int)$_POST['ad_id'];
        $content = trim($_POST['content'] ?? '');
        $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
        $currentUserId = $_SESSION['user_id'];
        
        if (empty($content)) {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }
        
        try {
            // Check if ad exists
            $checkStmt = $pdo->prepare("SELECT id, user_id FROM ads WHERE id = ? AND status = 'active'");
            $checkStmt->execute([$adId]);
            $ad = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ad) {
                echo json_encode(['success' => false, 'error' => 'Advertisement not found']);
                exit;
            }
            
            // Insert comment
            $stmt = $pdo->prepare("
                INSERT INTO ad_comments (ad_id, user_id, content, parent_comment_id, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$adId, $currentUserId, $content, $parentCommentId]);
            $newCommentId = $pdo->lastInsertId();
            
            // Create notification for ad owner if not commenting on own ad
            if ($ad['user_id'] != $currentUserId) {
                $message = $parentCommentId ? "replied to your comment" : "commented on your advertisement";
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, source_user_id, message, created_at) 
                    VALUES (?, 'comment', ?, ?, NOW())
                ");
                $stmt->execute([$ad['user_id'], $currentUserId, $message]);
            }
            
            // If this is a reply, also notify the parent comment author if different
            if ($parentCommentId && $parentCommentId > 0) {
                $stmt = $pdo->prepare("SELECT user_id FROM ad_comments WHERE id = ?");
                $stmt->execute([$parentCommentId]);
                $parentCommentUserId = $stmt->fetchColumn();
                
                if ($parentCommentUserId && $parentCommentUserId != $currentUserId && $parentCommentUserId != $ad['user_id']) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, source_user_id, message, created_at) 
                        VALUES (?, 'comment', ?, ?, NOW())
                    ");
                    $message = "replied to your comment";
                    $stmt->execute([$parentCommentUserId, $currentUserId, $message]);
                }
            }
            
            // Get the new comment with user info
            $stmt = $pdo->prepare("
                SELECT ac.*, u.username, u.profile_pic_url 
                FROM ad_comments ac 
                JOIN users u ON ac.user_id = u.id 
                WHERE ac.id = ?
            ");
            $stmt->execute([$newCommentId]);
            $newComment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Add reply_count to the comment if it's a main comment
            if (!$parentCommentId) {
                $newComment['reply_count'] = 0;
            }
            
            echo json_encode([
                'success' => true,
                'comment' => $newComment,
                'is_reply' => ($parentCommentId !== null)
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Add ad comment error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- DELETE POST COMMENT HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_post_comment' && isset($_POST['comment_id'])) {
        $commentId = (int)$_POST['comment_id'];
        $postId = (int)($_POST['post_id'] ?? 0);
        $currentUserId = $_SESSION['user_id'];
        
        try {
            // Verify ownership before deletion
            $stmt = $pdo->prepare("
                SELECT c.user_id, p.user_id as post_owner_id 
                FROM comments c 
                JOIN posts p ON c.post_id = p.id 
                WHERE c.id = ?
            ");
            $stmt->execute([$commentId]);
            $commentData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$commentData) {
                echo json_encode(['success' => false, 'error' => 'Comment not found']);
                exit;
            }
            
            // Check if user has permission (comment owner or post owner)
            if ($commentData['user_id'] == $currentUserId || $commentData['post_owner_id'] == $currentUserId) {
                $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? OR parent_comment_id = ?");
                $stmt->execute([$commentId, $commentId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'You don\'t have permission to delete this comment']);
            }
            exit;
            
        } catch (PDOException $e) {
            error_log("Delete post comment error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
    // --- DELETE AD COMMENT HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_ad_comment' && isset($_POST['comment_id'])) {
        $commentId = (int)$_POST['comment_id'];
        $adId = (int)($_POST['ad_id'] ?? 0);
        $currentUserId = $_SESSION['user_id'];
        
        try {
            // Verify ownership before deletion
            $stmt = $pdo->prepare("
                SELECT ac.user_id, a.user_id as advertiser_id 
                FROM ad_comments ac 
                JOIN ads a ON ac.ad_id = a.id 
                WHERE ac.id = ?
            ");
            $stmt->execute([$commentId]);
            $commentData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$commentData) {
                echo json_encode(['success' => false, 'error' => 'Comment not found']);
                exit;
            }
            
            // Check if user has permission (comment owner or ad owner)
            if ($commentData['user_id'] == $currentUserId || $commentData['advertiser_id'] == $currentUserId) {
                $stmt = $pdo->prepare("DELETE FROM ad_comments WHERE id = ? OR parent_comment_id = ?");
                $stmt->execute([$commentId, $commentId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'You don\'t have permission to delete this comment']);
            }
            exit;
            
        } catch (PDOException $e) {
            error_log("Delete ad comment error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
    
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
        $postId = (int)$_POST['post_id'];
        
        if ($_POST['action'] === 'like') {
            $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $stmt->execute([$postId, $userId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$postId, $userId]);
        }
        
        $newLikeCount = getLikeCount($pdo, $postId);
        echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
        exit;
    }

    // --- AD LIKE/UNLIKE HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like_ad', 'unlike_ad']) && isset($_POST['ad_id'])) {
        $adId = (int)$_POST['ad_id'];
        
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

// Function to output posts HTML (for both initial load and AJAX)
function includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $currentPage = 1) {
    $postCounter = ($currentPage - 1) * 8; // Start counter from appropriate position
    $adCounter = 0;
    $boostCounter = 0;
    
    // SHUFFLE ADS AND BOOSTED POSTS FOR RANDOM DISPLAY
    $allAds = $targetedAds;
    shuffle($allAds);
    
    foreach ($posts as $post): 
        $postCounter++;
        // Build categories string for post author
        $userCategories = [];
        if ($post['category1']) $userCategories[] = $post['category1'];
        if ($post['category2']) $userCategories[] = $post['category2'];
        $categoriesText = !empty($userCategories) ? implode(', ', $userCategories) : 'No categories';
        
        // Get post type for display
        $postTypeDisplay = ucfirst($post['post_type'] ?? 'text');
        
        // Get like, comment, and share counts
        $likeCount = $post['like_count'];
        $commentCount = $post['comment_count'];
        $shareCount = $post['share_count'];
        $isLiked = userLikedPost($pdo, $currentUserId, $post['id']);
        
        // Calculate points breakdown for display
        $likePoints = $likeCount * 0.3;
        $commentPoints = $commentCount * 0.7;
        $sharePoints = $shareCount * 1;
        $totalPoints = $post['points'];
    ?>
        <div class="post-card" data-post-id="<?= $post['id'] ?>">
          <!-- Points Badge -->
          <div class="post-points" title="Points: Likes (<?= $likePoints ?>pts) + Comments (<?= $commentPoints ?>pts) + Shares (<?= $sharePoints ?>pts)">
            ⭐ <?= $totalPoints ?> pts
          </div>
          
          <div class="post-header">
            <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile" 
                 onclick="location.href='profile.php?id=<?= $post['user_id'] ?>'" />
            <div class="post-user-info">
              <div class="post-username" onclick="location.href='profile.php?id=<?= $post['user_id'] ?>'">
                <?= htmlspecialchars($post['username']) ?>
              </div>
              <div class="post-categories">
                <?= htmlspecialchars($categoriesText) ?> • <?= htmlspecialchars($postTypeDisplay) ?>
              </div>
            </div>
          </div>
          
          <?php if (!empty($post['content'])): ?>
            <div class="post-content">
              <?= nl2br(htmlspecialchars($post['content'])) ?>
            </div>
          <?php endif; ?>
          
          <?php 
          // Handle different post types - UPDATED: Using feed.php video display method
          $postType = $post['post_type'] ?? 'text';
          $mediaUrl = $post['media_url'] ?? '';
          
          if ($postType === 'photo' && !empty($mediaUrl)): 
            $mediaFiles = explode(',', $mediaUrl);
            $mediaCount = count($mediaFiles);
            $firstFour = array_slice($mediaFiles, 0, 4);
            $extraCount = $mediaCount - 4;
          ?>
            <div class="post-media">
              <div class="image-count"><?= $mediaCount ?> image<?= $mediaCount > 1 ? 's' : '' ?></div>
              <div class="post-media-grid">
                <?php foreach ($firstFour as $index => $image):
                  $image = trim($image);
                ?>
                  <div onclick="<?php
                    if ($index < 3) {
                      echo "window.location='full_image.php?img=" . urlencode($image) . "'";
                    } elseif ($extraCount > 0 && $index === 3) {
                      echo "window.location='image_list.php?post_id=" . $post['id'] . "'";
                    } else {
                      echo "window.location='full_image.php?img=" . urlencode($image) . "'";
                    }
                  ?>">
                    <img src="<?= htmlspecialchars($image) ?>" alt="Post image" />
                    <?php if ($index === 3 && $extraCount > 0): ?>
                      <div class="overlay">+<?= $extraCount ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php elseif ($postType === 'video' && !empty($mediaUrl)): 
            $mediaFiles = explode(',', $mediaUrl);
            $firstVideo = trim($mediaFiles[0]);
          ?>
            <!-- VIDEO POST - USING FEED.PHP STYLE -->
            <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($post['media_url']) ?>', <?= $post['id'] ?>, this)">
              <video loop playsinline preload="metadata" class="people-video" muted>
                <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                Your browser does not support the video tag.
              </video>
              <div class="video-controls">
                <button class="play-pause">▶️</button>
              </div>
              <?php if (count($mediaFiles) > 1): ?>
                <div class="multi-video-indicator">
                  <span class="arrow">⇆</span> <?= count($mediaFiles) ?> videos
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          
          <!-- Post Actions - Like, Comment, Share -->
          <div class="post-actions">
            <button class="action-btn like-btn <?= $isLiked ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
              <span class="icon">❤️</span>
              Like <span class="count">(<?= $likeCount ?>)</span>
            </button>
            
            <!-- UPDATED: Changed from link to button with onclick -->
            <button class="action-btn comment-btn" onclick="openPostComments(<?= $post['id'] ?>)">
              <span class="icon">💬</span>
              Comment <span class="count">(<?= $commentCount ?>)</span>
            </button>
            
            <button class="action-btn share-btn" onclick="sharePost(<?= $post['id'] ?>)">
              <span class="icon">↗️</span>
              Share <span class="count">(<?= $shareCount ?>)</span>
            </button>
          </div>
          
          <div class="post-date">
            <?= date('M j, Y g:i A', strtotime($post['created_at'])) ?>
          </div>
        </div>

        <?php
        // Insert boosted posts every 6 posts
        if ($postCounter % 6 === 0 && count($boostedPosts) > 0):
          // USE RANDOM BOOSTED POST INSTEAD OF SEQUENTIAL
          $randomBoostIndex = array_rand($boostedPosts);
          $boostPost = $boostedPosts[$randomBoostIndex];
          $boostCounter++;
        ?>
          <!-- BOOSTED POST -->
          <div class="post-card boosted-post" data-post-id="<?= $boostPost['id'] ?>">
            <div class="sponsored-label">Sponsored</div>
            <div class="post-points">⭐ Boosted</div>
            
            <div class="post-header">
              <img src="<?= htmlspecialchars($boostPost['profile_pic_url'] ?: 'default_profile.png') ?>"
                   alt="Profile" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'" />
              <div class="post-user-info">
                <div class="post-username" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'">
                  <?= htmlspecialchars($boostPost['username']) ?> (Boosted)
                </div>
                <div class="post-categories"><?= date('M j, Y g:i A', strtotime($boostPost['created_at'])) ?></div>
              </div>
              <div style="margin-left:auto; font-size:12px; color:#218838;">Boost expires at: <?= date('M j, Y H:i', strtotime($boostPost['boost_end'])) ?></div>
            </div>
            
            <div class="post-content">
              <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
            </div>
            
            <!-- Boosted post media -->
            <?php if ($boostPost['post_type'] === 'video' && !empty($boostPost['media_url'])): ?>
              <?php
              $mediaFiles = explode(',', $boostPost['media_url']);
              $firstVideo = trim($mediaFiles[0]);
              ?>
              <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($boostPost['media_url']) ?>', 'boost-<?= $boostPost['id'] ?>', this)">
                <video loop playsinline preload="metadata" class="people-video" muted>
                  <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                  Your browser does not support the video tag.
                </video>
                <div class="video-controls">
                  <button class="play-pause">▶️</button>
                </div>
                <?php if (count($mediaFiles) > 1): ?>
                  <div class="multi-video-indicator">
                    <span class="arrow">⇆</span> <?= count($mediaFiles) ?> videos
                  </div>
                <?php endif; ?>
              </div>
            <?php elseif ($boostPost['post_type'] === 'photo' && !empty($boostPost['media_url'])): ?>
              <?php
              $mediaFiles = explode(',', $boostPost['media_url']);
              $mediaCount = count($mediaFiles);
              $firstFour = array_slice($mediaFiles, 0, 4);
              $extraCount = $mediaCount - 4;
              ?>
              <div class="post-media">
                <div class="image-count"><?= $mediaCount ?> image<?= $mediaCount > 1 ? 's' : '' ?></div>
                <div class="post-media-grid">
                  <?php foreach ($firstFour as $index => $image):
                    $image = trim($image);
                  ?>
                    <div onclick="<?php
                      if ($index < 3) {
                        echo "window.location='full_image.php?img=" . urlencode($image) . "'";
                      } elseif ($extraCount > 0 && $index === 3) {
                        echo "window.location='image_list.php?post_id=" . $boostPost['id'] . "'";
                      } else {
                        echo "window.location='full_image.php?img=" . urlencode($image) . "'";
                      }
                    ?>">
                      <img src="<?= htmlspecialchars($image) ?>" alt="Boosted image" />
                      <?php if ($index === 3 && $extraCount > 0): ?>
                        <div class="overlay">+<?= $extraCount ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <!-- Actions for boosted post -->
            <div class="post-actions">
              <button class="action-btn like-btn <?= userLikedPost($pdo, $currentUserId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                <span class="icon">❤️</span>
                Like <span class="count">(<?= getLikeCount($pdo, $boostPost['id']) ?>)</span>
              </button>
              
              <!-- UPDATED: Changed from link to button with onclick -->
              <button class="action-btn comment-btn" onclick="openPostComments(<?= $boostPost['id'] ?>)">
                <span class="icon">💬</span>
                Comment <span class="count">(<?= getCommentCount($pdo, $boostPost['id']) ?>)</span>
              </button>
              
              <button class="action-btn share-btn" onclick="sharePost(<?= $boostPost['id'] ?>)">
                <span class="icon">↗️</span>
                Share <span class="count">(<?= getShareCount($pdo, $boostPost['id']) ?>)</span>
              </button>
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
          <div class="post-card ad-post" data-ad-id="<?= $ad['id'] ?>">
            <div class="ad-label">Sponsored</div>
            
            <div class="post-header">
              <img src="<?= htmlspecialchars($ad['profile_pic_url'] ?: 'default_profile.png') ?>"
                   alt="Advertiser" />
              <div class="post-user-info">
                <div class="post-username"><?= htmlspecialchars($ad['username']) ?></div>
                <div class="post-categories">Sponsored Ad</div>
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
                  <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($ad['media_path']) ?>', 'ad-<?= $ad['id'] ?>', this)">
                    <video loop playsinline preload="metadata" class="people-video" muted>
                      <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                      Your browser does not support the video tag.
                    </video>
                    <div class="video-controls">
                      <button class="play-pause">▶️</button>
                    </div>
                    <?php 
                    $adMediaFiles = explode(',', $ad['media_path']);
                    if (count($adMediaFiles) > 1): ?>
                      <div class="multi-video-indicator">
                        <span class="arrow">⇆</span> <?= count($adMediaFiles) ?> videos
                      </div>
                    <?php endif; ?>
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
              
              <!-- UPDATED: Changed from link to button with onclick -->
              <button class="ad-action-btn comment-btn" onclick="openAdComments(<?= $ad['id'] ?>)">
                <span class="icon">💬</span>
                Comment <span class="count">(<?= $ad['comment_count'] ?>)</span>
              </button>
              
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>People - Discover Users & Posts</title>
<style>
 /* ========== FULLSCREEN VIDEO STYLES FROM SEARCH_RESULT3.PHP ========== */
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
    overflow: hidden;
    padding: 0;
    margin: 0;
}

.fullscreen-video-container {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: hidden;
}

.fullscreen-video {
    width: 100%;
    height: 100%;
    object-fit: contain !important;
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
}

/* TIKTOK-STYLE CONTROLS */
.fullscreen-controls {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 10000;
    display: flex;
    pointer-events: none;
}

/* Left side: User info and description */
.video-left-controls {
    position: absolute;
    bottom: 100px;
    left: 20px;
    color: white;
    text-shadow: 0 2px 4px rgba(0,0,0,0.5);
    pointer-events: auto;
    max-width: 70%;
}

.video-username {
    font-weight: bold;
    font-size: 18px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.video-username img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid white;
}

.video-description {
    font-size: 16px;
    margin-bottom: 10px;
    line-height: 1.3;
}

.video-hashtags {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
}

.video-hashtag {
    color: #7b68ee;
    font-weight: bold;
}

/* Right side: Engagement buttons */
.video-right-controls {
    position: absolute;
    bottom: 100px;
    right: 20px;
    display: flex;
    flex-direction: column;
    gap: 25px;
    align-items: center;
    pointer-events: auto;
}

.tiktok-action-btn {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    padding: 10px;
    border-radius: 50%;
    transition: all 0.3s ease;
    position: relative;
}

.tiktok-action-btn:hover {
    transform: scale(1.1);
}

.tiktok-action-btn .icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 50%;
    font-size: 24px;
    backdrop-filter: blur(5px);
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.tiktok-action-btn.liked .icon {
    background: rgba(255, 0, 79, 0.3);
    border-color: #ff004f;
    color: #ff004f;
}

.tiktok-action-btn .count {
    font-size: 13px;
    font-weight: bold;
    text-shadow: 0 1px 3px rgba(0,0,0,0.8);
}

/* Bottom controls */
.video-bottom-controls {
    position: absolute;
    bottom: 20px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 20px;
    pointer-events: auto;
    padding: 0 20px;
}

.progress-bar {
    flex: 1;
    max-width: 400px;
    height: 3px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #7b68ee;
    width: 0%;
    transition: width 0.1s linear;
}

.video-time {
    color: white;
    font-size: 14px;
    font-weight: 500;
    min-width: 100px;
    text-align: center;
    text-shadow: 0 1px 3px rgba(0,0,0,0.8);
}

/* Play/Pause button */
.play-pause-btn {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0, 0, 0, 0.5);
    border: none;
    border-radius: 50%;
    width: 80px;
    height: 80px;
    color: white;
    font-size: 36px;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(10px);
    z-index: 10001;
}

.fullscreen-video-container:hover .play-pause-btn {
    opacity: 1;
}

.fullscreen-close-btn {
    position: fixed;
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
    z-index: 10002;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    pointer-events: auto;
}

.fullscreen-close-btn:hover {
    background: rgba(255, 0, 0, 0.7);
    transform: scale(1.1);
}

/* Video counter */
.video-counter {
    position: absolute;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 14px;
    z-index: 10001;
    backdrop-filter: blur(10px);
    pointer-events: auto;
}

/* VIDEO NAVIGATION BUTTONS (FOR VIDEOS WITHIN A POST) */
.video-nav-btn {
    position: absolute;
    bottom: 20px;
    transform: translateY(0);
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    font-size: 20px;
    cursor: pointer;
    z-index: 10001;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    pointer-events: auto;
    display: flex;
    align-items: center;
    justify-content: center;
}

.video-nav-btn:hover {
    background: rgba(123, 104, 238, 0.7);
    transform: scale(1.1);
}

.video-prev-btn {
    left: 20px;
}

.video-next-btn {
    right: 20px;
}

/* POST NAVIGATION BUTTONS (FOR NAVIGATING BETWEEN DIFFERENT POSTS) */
.post-nav-btn {
    position: absolute;
    top: 80px;
    transform: translateY(0);
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 10001;
}

.post-nav-btn:hover {
    background: rgba(123, 104, 238, 0.7);
    transform: scale(1.05);
}

.post-nav-prev {
    left: 20px;
}

.post-nav-next {
    right: 20px;
}

/* DIRECTIONAL INDICATOR FOR POSTS WITH MULTIPLE VIDEOS */
.multi-video-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 6px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: bold;
    z-index: 5;
    backdrop-filter: blur(5px);
    display: flex;
    align-items: center;
    gap: 5px;
    pointer-events: none;
}

.multi-video-indicator .arrow {
    font-size: 14px;
}

/* Music/sound indicator */
.music-indicator {
    position: absolute;
    bottom: 180px;
    left: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: white;
    font-size: 14px;
    text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    pointer-events: auto;
}

.music-icon {
    width: 30px;
    height: 30px;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(5px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

/* Follow button */
.follow-btn-tiktok {
    background: #ff004f;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 6px 15px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 10px;
    transition: all 0.3s ease;
}

.follow-btn-tiktok:hover {
    background: #ff3366;
    transform: scale(1.05);
}

/* ========== FULLSCREEN COMMENT OVERLAY STYLES ========== */
.fullscreen-comment-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.95);
    z-index: 10000;
    display: none;
    flex-direction: column;
}

.fullscreen-comment-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    padding: 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.fullscreen-comment-header h2 {
    color: #2d3748;
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}

.fullscreen-close-comment-btn {
    background: rgba(0, 0, 0, 0.1);
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 24px;
    color: #2d3748;
    transition: all 0.3s ease;
}

.fullscreen-close-comment-btn:hover {
    background: rgba(0, 0, 0, 0.2);
    transform: rotate(90deg);
}

.fullscreen-comment-content {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}

/* Styles for regular post comments popup */
.post-comments-container {
    max-width: 800px;
    margin: 0 auto;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.post-comments-container header {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 25px;
    margin-bottom: 25px;
    border-radius: 12px;
}

.post-comments-container .post-header {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border-radius: 12px; 
    padding: 20px; 
    margin-bottom: 25px;
    border: 2px solid #7b68ee;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.post-comments-container .post-header img { 
    width: 50px; 
    height: 50px; 
    border-radius: 50%; 
    object-fit: cover; 
    vertical-align: middle; 
    border: 2px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
}

.post-comments-container .post-header .username { 
    font-weight: bold; 
    cursor: pointer; 
    color: #7b68ee; 
    margin-left: 15px; 
    vertical-align: middle;
    font-weight: 700;
    font-size: 16px;
}

.post-comments-container .post-header .username:hover { 
    color: #6a5acd;
}

.post-comments-container #comments-section {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(20px);
    padding: 25px;
    border-radius: 12px;
    border: 2px solid #7b68ee;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.post-comments-container #comments-section h3 {
    color: #7b68ee;
    margin-bottom: 20px;
    font-weight: 700;
    border-bottom: 2px solid rgba(123, 104, 238, 0.3);
    padding-bottom: 10px;
}

.post-comments-container .comment {
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 12px; 
    padding: 20px; 
    margin-bottom: 15px;
    border: 1px solid rgba(123, 104, 238, 0.3);
}

.post-comments-container .comment img { 
    width: 45px; 
    height: 45px; 
    border-radius: 50%; 
    object-fit: cover; 
    vertical-align: middle; 
    border: 2px solid #7b68ee;
}

.post-comments-container .comment .username { 
    font-weight: bold; 
    cursor: pointer; 
    color: #7b68ee; 
    margin-left: 15px; 
    vertical-align: middle;
    font-weight: 700;
}

.post-comments-container .comment .comment-meta { 
    font-size: 13px; 
    color: #718096; 
    margin-left: 60px; 
    margin-top: -15px; 
    margin-bottom: 8px; 
    font-weight: 500;
}

.post-comments-container .comment-content { 
    margin-left: 60px; 
    color: #2d3748;
    line-height: 1.5;
}

.post-comments-container .reply-comment {
    margin-left: 60px;
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(10px);
}

.post-comments-container .reply-btn {
    color: #7b68ee;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    margin-left: 10px;
}

.post-comments-container .reply-btn:hover {
    text-decoration: underline;
}

.post-comments-container .show-replies-btn {
    color: #7b68ee;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 0;
    margin-top: 5px;
}

.post-comments-container .show-replies-btn:hover {
    text-decoration: underline;
}

.post-comments-container textarea, .post-comments-container input[type=text] { 
    width: 100%; 
    padding: 15px; 
    margin-bottom: 15px; 
    resize: vertical; 
    border: 2px solid #7b68ee;
    border-radius: 10px;
    background: white;
    font-size: 15px;
    transition: all 0.3s ease;
}

.post-comments-container textarea:focus, .post-comments-container input[type=text]:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
}

.post-comments-container button { 
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white; 
    border: none; 
    border-radius: 10px; 
    padding: 12px 25px; 
    cursor: pointer; 
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.post-comments-container button:hover { 
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.post-comments-container .small-button { 
    font-size: 13px; 
    background: linear-gradient(135deg, #a0aec0, #718096);
    margin-left: 10px; 
    padding: 6px 12px;
}

.post-comments-container .small-button:hover { 
    background: linear-gradient(135deg, #718096, #4a5568);
}

.post-comments-container .edit-area { 
    width: 100%; 
    height: 100px; 
    margin-top: 10px;
}

.post-comments-container #addCommentMessage {
    color: #ff6b6b;
    margin-top: 10px;
    font-weight: 600;
    padding: 10px;
    background: rgba(255, 107, 107, 0.1);
    border-radius: 8px;
    border: 1px solid #ff6b6b;
}

/* Styles for ad comments popup */
.ad-comments-container {
    max-width: 800px;
    margin: 0 auto;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.ad-comments-container .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.ad-comments-container .back-btn {
    background: rgba(255, 255, 255, 0.9);
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

.ad-comments-container .header h1 {
    font-size: 24px;
    font-weight: 800;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.ad-comments-container .ad-container {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.ad-comments-container .ad-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.ad-comments-container .ad-header img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    border: 3px solid rgba(102, 126, 234, 0.3);
}

.ad-comments-container .ad-user-info {
    flex: 1;
}

.ad-comments-container .ad-username {
    font-weight: 800;
    color: #2d3748;
    font-size: 18px;
    margin-bottom: 5px;
}

.ad-comments-container .ad-label {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    color: #000;
    padding: 6px 15px;
    border-radius: 20px;
    font-weight: 800;
    font-size: 12px;
    display: inline-block;
    margin-bottom: 8px;
}

.ad-comments-container .ad-content {
    margin-bottom: 20px;
}

.ad-comments-container .ad-title {
    font-size: 22px;
    font-weight: 800;
    color: #2d3748;
    margin-bottom: 15px;
}

.ad-comments-container .ad-description {
    color: #4a5568;
    line-height: 1.6;
    margin-bottom: 20px;
}

.ad-comments-container .ad-media {
    margin: 20px 0;
    text-align: center;
    border-radius: 15px;
    overflow: hidden;
}

.ad-comments-container .ad-media img, .ad-comments-container .ad-media video {
    max-width: 100%;
    max-height: 300px;
    border-radius: 15px;
}

.ad-comments-container .cta-button {
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
}

.ad-comments-container .comments-section {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.ad-comments-container .comments-title {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 25px;
    color: #2d3748;
    text-align: center;
}

.ad-comments-container .comment-form {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    align-items: flex-start;
}

.ad-comments-container .comment-user-img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(102, 126, 234, 0.3);
}

.ad-comments-container .comment-input-container {
    flex: 1;
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.ad-comments-container .comment-input {
    flex: 1;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 25px;
    padding: 15px 20px;
    color: #2d3748;
    font-size: 15px;
    resize: none;
    min-height: 50px;
    max-height: 120px;
}

.ad-comments-container .comment-submit {
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
}

.ad-comments-container .comments-list {
    max-height: 400px;
    overflow-y: auto;
    padding-right: 10px;
}

.ad-comments-container .comment {
    display: flex;
    gap: 15px;
    padding: 20px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.ad-comments-container .comment-content {
    flex: 1;
}

.ad-comments-container .comment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.ad-comments-container .comment-username {
    font-weight: 700;
    color: #2d3748;
    font-size: 15px;
}

.ad-comments-container .comment-time {
    color: #718096;
    font-size: 12px;
    font-weight: 600;
}

.ad-comments-container .comment-text {
    color: #4a5568;
    line-height: 1.5;
}

.ad-comments-container .comment-actions {
    display: flex;
    gap: 15px;
    margin-top: 10px;
}

.ad-comments-container .comment-action {
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

.ad-comments-container .comment-action.reply {
    color: #667eea;
}

.ad-comments-container .comment-action.delete {
    color: #e53e3e;
}

.ad-comments-container .comment-action:hover {
    background: rgba(0, 0, 0, 0.05);
}

.ad-comments-container .show-replies-btn {
    color: #667eea;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 0;
    margin-top: 5px;
}

.ad-comments-container .show-replies-btn:hover {
    text-decoration: underline;
}

.ad-comments-container .no-comments {
    text-align: center;
    color: #718096;
    padding: 50px 0;
    font-style: italic;
}

.ad-comments-container .error-message {
    background: rgba(229, 62, 62, 0.1);
    color: #e53e3e;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
}

/* Scrollbar styling for popups */
.fullscreen-comment-content::-webkit-scrollbar {
    width: 8px;
}

.fullscreen-comment-content::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
}

.fullscreen-comment-content::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.5);
    border-radius: 4px;
}

.fullscreen-comment-content::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.7);
}

/* Video posts styling - UPDATED for feed.php style */
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

/* Base responsive styles */
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 900px; 
    margin: 0 auto; 
    padding: 15px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
}

h2 {
    text-align: center;
    margin-bottom: 20px;
    color: white;
    font-size: clamp(1.5rem, 4vw, 2rem);
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

/* User card responsive styles */
.user-card {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
    padding: 15px;
    border: 2px solid #7b68ee;
    border-radius: 15px;
    width: 100%;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    flex-wrap: wrap;
    transition: all 0.3s ease;
}

.user-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
}

.user-card img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    color: #7b68ee;
    flex-shrink: 0;
    border: 2px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.user-info {
    flex: 1;
    min-width: 150px;
}

.user-name {
    font-weight: bold;
    cursor: pointer;
    color: #2d3748;
    font-size: clamp(14px, 3vw, 16px);
    margin-bottom: 4px;
    font-weight: 700;
}

.user-categories {
    font-size: 12px;
    color: #718096;
}

.user-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-left: auto;
}

.add-friend-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-size: 12px;
    white-space: nowrap;
    font-weight: 600;
    transition: all 0.3s ease;
}

.add-friend-btn.pending {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    color: white;
    cursor: default;
}

.add-friend-btn.already-friends {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    cursor: default;
}

/* Search and Filter responsive styling */
.search-section {
    margin-bottom: 20px;
}

#search-container, #posts-search-container {
    position: relative;
    width: 100%;
    margin-bottom: 15px;
}

#search-input, #posts-search-input {
    width: 100%;
    padding: 12px 15px;
    font-size: 16px;
    border: 2px solid #7b68ee;
    border-radius: 25px;
    background: rgba(255, 255, 255, 0.95);
    color: #2d3748;
    transition: all 0.3s ease;
}

#search-input:focus, #posts-search-input:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

#posts-search-input {
    border-color: #00ff88;
}

.filter-container {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.filter-select {
    padding: 10px 15px;
    border: 2px solid #7b68ee;
    border-radius: 25px;
    background: rgba(255, 255, 255, 0.95);
    color: #2d3748;
    flex: 1;
    min-width: 140px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.filter-select:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

/* Tab responsive styling */
.tabs {
    display: flex;
    margin-bottom: 20px;
    border-bottom: 2px solid #7b68ee;
    flex-wrap: wrap;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    padding: 5px;
}

.tab {
    padding: 12px 20px;
    cursor: pointer;
    background: none;
    border: none;
    color: #718096;
    font-size: 16px;
    flex: 1;
    min-width: 120px;
    text-align: center;
    border-radius: 8px;
    transition: all 0.3s ease;
    font-weight: 600;
}

.tab.active {
    background: rgba(123, 104, 238, 0.1);
    color: #7b68ee;
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.2);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Post card responsive styling */
.post-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 2px solid #7b68ee;
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    position: relative;
    transition: all 0.3s ease;
}

.post-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
}

.post-header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
}

.post-header img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    border: 2px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.post-user-info {
    flex: 1;
}

.post-username {
    font-weight: bold;
    color: #2d3748;
    cursor: pointer;
    font-size: 16px;
    margin-bottom: 4px;
    font-weight: 700;
}

.post-categories {
    font-size: 12px;
    color: #718096;
}

.post-content {
    margin: 12px 0;
    white-space: pre-wrap;
    line-height: 1.4;
    word-wrap: break-word;
    color: #2d3748;
}

.post-media {
    max-width: 100%;
    margin: 12px 0;
}

.post-media img, .post-media video {
    max-width: 100%;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.post-date {
    font-size: 12px;
    color: #718096;
    text-align: right;
}

/* Post points badge */
.post-points {
    position: absolute;
    top: 15px;
    right: 15px;
    background: linear-gradient(135deg, #ffd700, #ffa500);
    color: #000;
    padding: 6px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

/* Image grid responsive styling */
.post-media-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

.post-media-grid div {
    position: relative;
    cursor: pointer;
    border-radius: 8px;
    overflow: hidden;
    height: 150px;
    transition: transform 0.3s ease;
}

.post-media-grid div:hover {
    transform: scale(1.05);
}

.post-media-grid img {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.overlay {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 150px;
    background: rgba(0,0,0,0.6);
    color: white;
    font-size: 24px;
    font-weight: bold;
    text-align: center;
    line-height: 150px;
    border-radius: 8px;
}

.image-count {
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
    color: #718096;
}

/* Post actions responsive styling */
.post-actions {
    display: flex;
    gap: 15px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
    flex-wrap: wrap;
}

.action-btn {
    background: none;
    border: none;
    color: #7b68ee;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 8px 12px;
    border-radius: 20px;
    transition: all 0.3s ease;
    flex: 1;
    min-width: 100px;
    justify-content: center;
    font-weight: 600;
}

.action-btn:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
}

.action-btn.liked {
    color: #ff004f;
    font-weight: bold;
}

.action-btn .icon {
    font-size: 16px;
}

.action-btn .count {
    font-size: 12px;
    color: #718096;
}

/* Search info styling */
.search-info {
    margin-bottom: 15px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 8px;
    border-left: 4px solid #7b68ee;
    color: #2d3748;
}

.posts-search-info {
    margin-bottom: 15px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 8px;
    border-left: 4px solid #00ff88;
    color: #2d3748;
}

/* Follow button responsive styling */
.follow-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    user-select: none;
    transition: all 0.3s ease;
    font-size: 12px;
    white-space: nowrap;
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

/* ========== ADS AND BOOSTED POSTS STYLES ========== */

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
    background: linear-gradient(135deg, #007bff, #0056b3);
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
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.cta-button:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
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

/* No results styling */
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

.user-card, .post-card {
    animation: fadeInUp 0.6s ease-out;
}

/* Loading spinner */
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

/* Mobile-first responsive design */
@media (max-width: 768px) {
    body {
        padding: 10px;
    }
    
    .user-card {
        padding: 12px;
        gap: 12px;
    }
    
    .user-card img {
        width: 50px;
        height: 50px;
    }
    
    .user-actions {
        width: 100%;
        margin-top: 10px;
        margin-left: 0;
        justify-content: space-between;
    }
    
    .add-friend-btn, .follow-btn {
        flex: 1;
        margin: 2px;
        padding: 8px 12px;
        font-size: 11px;
    }
    
    .filter-container {
        gap: 8px;
    }
    
    .filter-select {
        min-width: calc(50% - 8px);
        font-size: 13px;
        padding: 8px 12px;
    }
    
    .tabs {
        flex-direction: column;
    }
    
    .tab {
        min-width: 100%;
        margin-bottom: 2px;
        border-radius: 6px;
    }
    
    .tab.active {
        border-radius: 6px;
    }
    
    .post-card {
        padding: 12px;
        margin-bottom: 15px;
    }
    
    .post-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .post-header img {
        margin-bottom: 8px;
    }
    
    .post-points {
        position: relative;
        top: auto;
        right: auto;
        margin-bottom: 10px;
        display: inline-block;
    }
    
    .post-actions {
        gap: 8px;
    }
    
    .action-btn {
        min-width: 80px;
        font-size: 12px;
        padding: 6px 10px;
    }
    
    .post-media-grid {
        grid-template-columns: 1fr;
    }
    
    .post-media-grid div {
        height: 200px;
    }
    
    .post-media-grid img {
        height: 200px;
    }
    
    .overlay {
        height: 200px;
        line-height: 200px;
    }
    
    .video-play-button {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .video-controls {
        bottom: 8px;
        right: 8px;
    }
    
    .play-pause {
        width: 35px;
        height: 35px;
        font-size: 14px;
    }
    
    .cta-button {
        padding: 10px 20px;
        font-size: 14px;
    }
    
    /* Fullscreen video responsive adjustments */
    .video-nav-btn {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .fullscreen-close-btn {
        width: 40px;
        height: 40px;
        font-size: 20px;
        top: 10px;
        right: 10px;
    }
    
    .video-left-controls {
        bottom: 80px;
        left: 10px;
        max-width: 65%;
    }
    
    .video-right-controls {
        bottom: 80px;
        right: 10px;
        gap: 20px;
    }
    
    .tiktok-action-btn .icon {
        width: 40px;
        height: 40px;
        font-size: 20px;
    }
    
    .video-username {
        font-size: 16px;
    }
    
    .video-description {
        font-size: 14px;
    }
    
    .post-nav-btn {
        padding: 10px 15px;
        font-size: 12px;
    }
    
    .music-indicator {
        bottom: 150px;
        left: 10px;
    }
}

@media (max-width: 480px) {
    .user-card {
        flex-direction: column;
        text-align: center;
    }
    
    .user-info {
        width: 100%;
    }
    
    .user-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .add-friend-btn, .follow-btn {
        width: 100%;
        margin: 4px 0;
    }
    
    .filter-select {
        min-width: 100%;
    }
    
    .post-actions {
        flex-direction: column;
    }
    
    .action-btn {
        width: 100%;
        justify-content: flex-start;
    }
    
    .ad-post-media {
        grid-template-columns: 1fr;
    }
    
    .video-post-container video {
        max-height: 400px;
    }
    
    /* Fullscreen video mobile adjustments */
    .video-left-controls {
        bottom: 70px;
        max-width: 60%;
    }
    
    .video-right-controls {
        bottom: 70px;
        gap: 15px;
    }
    
    .tiktok-action-btn .icon {
        width: 36px;
        height: 36px;
        font-size: 18px;
    }
    
    .video-bottom-controls {
        bottom: 10px;
    }
    
    .progress-bar {
        max-width: 250px;
    }
}

@media (min-width: 1200px) {
    body {
        max-width: 1000px;
    }
}
/* Post Header Title Styling */
.post-header-title {
    margin: 15px 0 12px 0;
    padding: 0 5px;
}

.post-header-title h3 {
    font-size: 20px;
    font-weight: 700;
    color: #2d3748;
    margin: 0;
    line-height: 1.3;
    word-wrap: break-word;
}

/* For boosted posts - slightly different styling */
.boosted-post .post-header-title h3 {
    color: #1a365d;
    font-size: 22px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .post-header-title h3 {
        font-size: 18px;
    }
    
    .boosted-post .post-header-title h3 {
        font-size: 20px;
    }
}

@media (max-width: 480px) {
    .post-header-title h3 {
        font-size: 16px;
    }
    
    .boosted-post .post-header-title h3 {
        font-size: 18px;
    }
}
</style>
</head>
<body>

<!-- Full Screen Video Overlay - FROM SEARCH_RESULT3.PHP -->
<div class="fullscreen-video-overlay" id="fullscreenVideoOverlay">
    <button class="fullscreen-close-btn" onclick="closeFullscreenVideo()">✕</button>
    <div class="fullscreen-video-container">
        <video class="fullscreen-video" id="fullscreenVideo"></video>
        
        <!-- Play/Pause Button -->
        <button class="play-pause-btn" id="playPauseBtn" onclick="togglePlayPause()">
            <span id="playPauseIcon">▶️</span>
        </button>
        
        <!-- TikTok-style Controls -->
        <div class="fullscreen-controls">
            <div class="video-left-controls" id="videoLeftControls">
                <div class="video-username" id="videoUsername">
                    <!-- Username and profile pic will be added here -->
                </div>
                <div class="video-description" id="videoDescription"></div>
                <div class="video-hashtags" id="videoHashtags"></div>
                <div class="music-indicator" id="musicIndicator">
                    <div class="music-icon">🎵</div>
                    <span>Original Sound</span>
                </div>
            </div>
            
            <div class="video-right-controls" id="videoRightControls">
                <!-- Engagement buttons will be dynamically added here -->
            </div>
            
            <div class="video-bottom-controls">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="video-time" id="videoTime">0:00 / 0:00</div>
            </div>
            
            <!-- Video Navigation Buttons (for videos within a post) -->
            <button class="video-nav-btn video-prev-btn" onclick="navigateVideoInPost(-1)">❮</button>
            <button class="video-nav-btn video-next-btn" onclick="navigateVideoInPost(1)">❯</button>
            
            <!-- Post Navigation Buttons (for navigating between different posts) -->
            <button class="post-nav-btn post-nav-prev" onclick="navigateToPost(-1)">
                <span>←</span> 
            </button>
            <button class="post-nav-btn post-nav-next" onclick="navigateToPost(1)">
                 <span>→</span>
            </button>
            
            <div class="video-counter" id="videoCounter">Video 1/1</div>
        </div>
    </div>
</div>

<!-- Full Screen Comment Overlay (for regular posts) -->
<div class="fullscreen-comment-overlay" id="fullscreenPostComments">
    <div class="fullscreen-comment-header">
        <h2>Post Comments</h2>
        <button class="fullscreen-close-comment-btn" onclick="closePostComments()">✕</button>
    </div>
    <div class="fullscreen-comment-content" id="postCommentsContent">
        <!-- Regular post comments content will be loaded here -->
    </div>
</div>

<!-- Full Screen Comment Overlay (for ads) -->
<div class="fullscreen-comment-overlay" id="fullscreenAdComments">
    <div class="fullscreen-comment-header">
        <h2>Advertisement Comments</h2>
        <button class="fullscreen-close-comment-btn" onclick="closeAdComments()">✕</button>
    </div>
    <div class="fullscreen-comment-content" id="adCommentsContent">
        <!-- Ad comments content will be loaded here -->
    </div>
</div>

<h2>Discover</h2>

<!-- Tabs -->
<div class="tabs">
  <button class="tab <?= $activeTab === 'people' ? 'active' : '' ?>" data-tab="people">People</button>
  <button class="tab <?= $activeTab === 'posts' ? 'active' : '' ?>" data-tab="posts">Posts</button>
</div>

<!-- Search Section -->
<div class="search-section">
  <!-- Main Search Bar (for both tabs) -->
  <div id="search-container">
    <input type="text" id="search-input" placeholder="Search people or posts..." autocomplete="off" 
           value="<?= htmlspecialchars($searchFilter) ?>" />
  </div>

  <!-- Posts Tab Search Bar -->
  <div id="posts-search-container">
    <input type="text" id="posts-search-input" placeholder="Search posts by content or username..." autocomplete="off" 
           value="<?= htmlspecialchars($postSearchFilter) ?>" />
  </div>
</div>

<!-- Filters -->
<div class="filter-container">
  <select id="category-filter" class="filter-select">
    <?php foreach ($categories as $category): ?>
      <option value="<?= htmlspecialchars($category) ?>" 
              <?= $selectedCategory === $category ? 'selected' : '' ?>>
        <?= htmlspecialchars($category) ?>
      </option>
    <?php endforeach; ?>
  </select>
  
  <select id="post-type-filter" class="filter-select" style="<?= $activeTab === 'people' ? 'display:none;' : '' ?>">
    <?php foreach ($postTypes as $type): ?>
      <option value="<?= htmlspecialchars($type) ?>" 
              <?= $selectedPostType === $type ? 'selected' : '' ?>>
        <?= htmlspecialchars($type) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<!-- People Tab Content -->
<div id="people-tab" class="tab-content <?= $activeTab === 'people' ? 'active' : '' ?>">
  <h3>Discover People</h3>
  
  <?php if ($searchFilter !== ''): ?>
    <div class="search-info">
      Showing results for: "<strong><?= htmlspecialchars($searchFilter) ?></strong>"
    </div>
  <?php endif; ?>
  
  <div id="users-list">
    <?php if (empty($users)): ?>
      <div class="no-results">
        <p>No users found matching your criteria.</p>
      </div>
    <?php else: ?>
      <?php foreach ($users as $user): 
        $userIdToCheck = $user['id'];
        $isPending = in_array($userIdToCheck, $pendingRequests);
        $isFriend = in_array($userIdToCheck, $acceptedFriends);
        if ($isFriend) {
          $btnClass = 'add-friend-btn already-friends';
          $btnText = 'You are already friends';
          $disabled = 'disabled';
        } elseif ($isPending) {
          $btnClass = 'add-friend-btn pending';
          $btnText = 'Pending';
          $disabled = 'disabled';
        } else {
          $btnClass = 'add-friend-btn';
          $btnText = 'Add Friend';
          $disabled = '';
        }
        
        // Build categories string
        $userCategories = [];
        if ($user['category1']) $userCategories[] = $user['category1'];
        if ($user['category2']) $userCategories[] = $user['category2'];
        $categoriesText = !empty($userCategories) ? implode(', ', $userCategories) : 'No categories';
      ?>
        <div class="user-card" data-user-id="<?= $user['id'] ?>">
          <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile" onclick="location.href='profile.php?id=<?= $user['id'] ?>'" />
          <div class="user-info">
            <div class="user-name" onclick="location.href='profile.php?id=<?= $user['id'] ?>'"><?= htmlspecialchars($user['username']) ?></div>
            <div class="user-categories"><?= htmlspecialchars($categoriesText) ?></div>
          </div>
          <div class="user-actions">
            <button class="<?= $btnClass ?>" <?= $disabled ?>><?= $btnText ?></button>
            <button class="follow-btn <?= in_array($user['id'], $followingUserIds) ? 'following' : '' ?>" data-user-id="<?= $user['id'] ?>">
              <?= in_array($user['id'], $followingUserIds) ? 'Following' : 'Follow' ?>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Posts Tab Content -->
<div id="posts-tab" class="tab-content <?= $activeTab === 'posts' ? 'active' : '' ?>">
  <h3>Discover Posts (Ranked by Points)</h3>
  
  <?php if ($searchFilter !== ''): ?>
    <div class="search-info">
      Showing posts related to: "<strong><?= htmlspecialchars($searchFilter) ?></strong>"
    </div>
  <?php endif; ?>
  
  <?php if ($postSearchFilter !== ''): ?>
    <div class="posts-search-info">
      Showing posts matching: "<strong><?= htmlspecialchars($postSearchFilter) ?></strong>"
    </div>
  <?php endif; ?>
  
  <div id="posts-list">
    <?php if (empty($posts) && empty($boostedPosts) && empty($targetedAds)): ?>
      <div class="no-results">
        <p>No posts found matching your criteria.</p>
      </div>
    <?php else: ?>
      <?php includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $page); ?>
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
</div>
<script>
  // ========== PAGINATION VARIABLES ==========
  let currentPage = <?= $page ?>;
  let isLoading = false;
  let hasMorePosts = true;
  const postsPerPage = 8;

  // ========== COMMENT POPUP VARIABLES ==========
  let currentCommentPostId = null;
  let currentCommentAdId = null;

  // ========== FULLSCREEN VIDEO VARIABLES ==========
  let currentVideoIndexInPost = 0;
  let currentVideoList = [];
  let currentPostId = null;
  let currentPostData = null;
  let currentAdId = null;
  let currentAdData = null;
  let currentPostIndex = 0;
  let videoItems = []; // Array to store all video items in the grid
  let isVideoPlaying = true;
  let videoUpdateInterval = null;

  // ========== HELPER FUNCTIONS ==========
  function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
  }

  function formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleString('en-US', {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
      });
  }

  function timeAgo(dateString) {
      if (!dateString) return 'Recently';
      
      const date = new Date(dateString);
      if (isNaN(date.getTime())) return 'Recently';
      
      const now = new Date();
      const diff = now - date;
      
      if (diff < 60000) { // less than 1 minute
          return 'just now';
      } else if (diff < 3600000) { // less than 1 hour
          const mins = Math.floor(diff / 60000);
          return `${mins} min${mins > 1 ? 's' : ''} ago`;
      } else if (diff < 86400000) { // less than 1 day
          const hours = Math.floor(diff / 3600000);
          return `${hours} hour${hours > 1 ? 's' : ''} ago`;
      } else if (diff < 604800000) { // less than 1 week
          const days = Math.floor(diff / 86400000);
          return `${days} day${days > 1 ? 's' : ''} ago`;
      } else {
          return date.toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric'
          });
      }
  }

  // Format time as MM:SS
  function formatTime(seconds) {
      const mins = Math.floor(seconds / 60);
      const secs = Math.floor(seconds % 60);
      return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
  }

  // Update progress bar and time
  function updateVideoProgress() {
      const video = document.getElementById('fullscreenVideo');
      const progressFill = document.getElementById('progressFill');
      const videoTime = document.getElementById('videoTime');
      
      if (video && video.duration) {
          const progress = (video.currentTime / video.duration) * 100;
          progressFill.style.width = `${progress}%`;
          videoTime.textContent = `${formatTime(video.currentTime)} / ${formatTime(video.duration)}`;
      }
  }

  // Toggle play/pause
  function togglePlayPause() {
      const video = document.getElementById('fullscreenVideo');
      const playPauseIcon = document.getElementById('playPauseIcon');
      
      if (video.paused) {
          video.play();
          playPauseIcon.textContent = '⏸️';
          isVideoPlaying = true;
      } else {
          video.pause();
          playPauseIcon.textContent = '▶️';
          isVideoPlaying = false;
      }
  }

  // Toggle mute
  function toggleMute() {
      const video = document.getElementById('fullscreenVideo');
      const muteBtn = document.getElementById('muteBtn');
      
      if (video) {
          video.muted = !video.muted;
          const icon = muteBtn.querySelector('.icon');
          icon.textContent = video.muted ? '🔇' : '🔊';
      }
  }

  // ========== FULLSCREEN VIDEO FUNCTIONALITY ==========

  // Collect all video items
  function collectVideoItems() {
      videoItems = Array.from(document.querySelectorAll('.video-post-container'));
      return videoItems;
  }

  // Open fullscreen video with TikTok-style controls
  function openFullscreenVideo(mediaUrls, postId, videoElement) {
      // Collect all video items if not already collected
      if (videoItems.length === 0) {
          collectVideoItems();
      }
      
      // Find the index of the clicked video
      currentPostIndex = videoItems.indexOf(videoElement);
      if (currentPostIndex === -1) {
          currentPostIndex = 0;
      }
      
      const videoContainer = videoElement.closest('.post-card');
      const videoItemData = {
          postId: videoContainer?.getAttribute('data-post-id'),
          adId: videoContainer?.getAttribute('data-ad-id'),
          mediaUrl: mediaUrls,
          username: videoContainer?.querySelector('.post-username')?.textContent || 'User',
          profilePic: videoContainer?.querySelector('.post-header img')?.src || 'default_profile.png',
          description: videoContainer?.querySelector('.post-content')?.textContent || ''
      };
      
      // Set current video data
      currentPostId = videoItemData.postId;
      currentAdId = videoItemData.adId;
      
      // Parse media URLs
      const mediaUrlsArray = mediaUrls ? mediaUrls.split(',').map(url => url.trim()) : [];
      currentVideoList = mediaUrlsArray;
      currentVideoIndexInPost = 0; // Start with first video in post
      
      // Store post/ad data for engagement buttons
      if (currentPostId) {
          currentPostData = {
              username: videoItemData.username,
              profilePic: videoItemData.profilePic,
              description: videoItemData.description
          };
          currentAdData = null;
      } else if (currentAdId) {
          currentAdData = {
              username: videoItemData.username,
              profilePic: videoItemData.profilePic,
              description: videoItemData.description
          };
          currentPostData = null;
      }
      
      const fullscreenVideo = document.getElementById('fullscreenVideo');
      const overlay = document.getElementById('fullscreenVideoOverlay');
      const videoCounter = document.getElementById('videoCounter');
      const videoLeftControls = document.getElementById('videoLeftControls');
      const videoRightControls = document.getElementById('videoRightControls');
      const videoUsername = document.getElementById('videoUsername');
      const videoDescription = document.getElementById('videoDescription');
      
      // Set video source and properties
      if (currentVideoList.length > 0) {
          fullscreenVideo.src = currentVideoList[currentVideoIndexInPost];
          fullscreenVideo.muted = false;
          fullscreenVideo.autoplay = true;
          fullscreenVideo.controls = false;
          fullscreenVideo.style.objectFit = 'contain';
      }
      
      // Update video counter - Show only videos in current post
      videoCounter.textContent = `Video ${currentVideoIndexInPost + 1}/${currentVideoList.length}`;
      
      // Show/hide video navigation buttons based on number of videos in post
      const videoPrevBtn = document.querySelector('.video-prev-btn');
      const videoNextBtn = document.querySelector('.video-next-btn');
      if (currentVideoList.length > 1) {
          videoPrevBtn.style.display = 'block';
          videoNextBtn.style.display = 'block';
      } else {
          videoPrevBtn.style.display = 'none';
          videoNextBtn.style.display = 'none';
      }
      
      // Show/hide post navigation buttons based on number of posts
      const postPrevBtn = document.querySelector('.post-nav-prev');
      const postNextBtn = document.querySelector('.post-nav-next');
      if (videoItems.length > 1) {
          postPrevBtn.style.display = 'flex';
          postNextBtn.style.display = 'flex';
      } else {
          postPrevBtn.style.display = 'none';
          postNextBtn.style.display = 'none';
      }
      
      // Update left controls (user info and description)
      if (currentPostData) {
          videoUsername.innerHTML = `
              <img src="${currentPostData.profilePic || 'default_profile.png'}" alt="${currentPostData.username}">
              <span>${currentPostData.username}</span>
          `;
          videoDescription.textContent = currentPostData.description || '';
          
          // Extract hashtags from description
          const hashtags = (currentPostData.description || '').match(/#\w+/g) || [];
          const hashtagsHtml = hashtags.map(tag => 
              `<span class="video-hashtag">${tag}</span>`
          ).join(' ');
          document.getElementById('videoHashtags').innerHTML = hashtagsHtml;
      } else if (currentAdData) {
          videoUsername.innerHTML = `
              <img src="${currentAdData.profilePic || 'default_profile.png'}" alt="${currentAdData.username}">
              <span>${currentAdData.username}</span>
              <span style="background: #ffd700; color: black; padding: 2px 6px; border-radius: 4px; font-size: 10px;">Ad</span>
          `;
          videoDescription.textContent = currentAdData.description || 'Sponsored content';
      }
      
      // Create TikTok-style engagement buttons on right side
      if (currentPostId) {
          videoRightControls.innerHTML = `
              <button class="tiktok-action-btn like-btn" onclick="handleFullscreenLike(${currentPostId})">
                  <div class="icon">🤍</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn comment-btn" onclick="openPostComments(${currentPostId})">
                  <div class="icon">💬</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn share-btn" onclick="sharePost(${currentPostId})">
                  <div class="icon">↗️</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn" onclick="toggleMute()" id="muteBtn">
                  <div class="icon">🔊</div>
                  <span class="count">Sound</span>
              </button>
          `;
      } else if (currentAdId) {
          // Extract numeric ad ID from string like "ad-123"
          const numericAdId = currentAdId.replace('ad-', '');
          videoRightControls.innerHTML = `
              <button class="tiktok-action-btn like-btn" onclick="handleFullscreenAdLike(${numericAdId})">
                  <div class="icon">🤍</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn comment-btn" onclick="openAdComments(${numericAdId})">
                  <div class="icon">💬</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn share-btn" onclick="shareAd(${numericAdId})">
                  <div class="icon">↗️</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn" onclick="toggleMute()" id="muteBtn">
                  <div class="icon">🔊</div>
                  <span class="count">Sound</span>
              </button>
          `;
      }
      
      // Show overlay
      overlay.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      
      // Try to play the video
      if (currentVideoList.length > 0) {
          fullscreenVideo.play().then(() => {
              isVideoPlaying = true;
              document.getElementById('playPauseIcon').textContent = '⏸️';
              
              // Start updating progress bar
              clearInterval(videoUpdateInterval);
              videoUpdateInterval = setInterval(updateVideoProgress, 100);
          }).catch(e => {
              console.log('Fullscreen video autoplay prevented:', e);
              isVideoPlaying = false;
              document.getElementById('playPauseIcon').textContent = '▶️';
          });
      }
      
      // Add event listeners for video
      fullscreenVideo.addEventListener('click', function(e) {
          e.stopPropagation();
          togglePlayPause();
      });
      
      fullscreenVideo.addEventListener('timeupdate', updateVideoProgress);
      
      // Click on video container to toggle play/pause
      document.querySelector('.fullscreen-video-container').addEventListener('click', function(e) {
          if (e.target === this || e.target.classList.contains('fullscreen-controls')) {
              togglePlayPause();
          }
      });
  }

  // Navigate between videos within the same post (using directional icons)
  function navigateVideoInPost(direction) {
      if (currentVideoList.length <= 1) return;
      
      const fullscreenVideo = document.getElementById('fullscreenVideo');
      const videoCounter = document.getElementById('videoCounter');
      
      // Calculate new index
      currentVideoIndexInPost += direction;
      
      // Wrap around if at boundaries
      if (currentVideoIndexInPost < 0) {
          currentVideoIndexInPost = currentVideoList.length - 1;
      } else if (currentVideoIndexInPost >= currentVideoList.length) {
          currentVideoIndexInPost = 0;
      }
      
      // Update video source
      fullscreenVideo.src = currentVideoList[currentVideoIndexInPost];
      fullscreenVideo.muted = false;
      fullscreenVideo.autoplay = true;
      
      // Update counter - Show only videos in current post
      videoCounter.textContent = `Video ${currentVideoIndexInPost + 1}/${currentVideoList.length}`;
      
      // Try to play the video
      fullscreenVideo.play().then(() => {
          isVideoPlaying = true;
          document.getElementById('playPauseIcon').textContent = '⏸️';
      }).catch(e => {
          console.log('Video navigation autoplay prevented:', e);
          isVideoPlaying = false;
          document.getElementById('playPauseIcon').textContent = '▶️';
      });
  }

  // Navigate to different post
  function navigateToPost(direction) {
      if (videoItems.length <= 1) return;
      
      // Calculate new post index
      let newPostIndex = currentPostIndex + direction;
      
      // Wrap around if at boundaries
      if (newPostIndex < 0) {
          newPostIndex = videoItems.length - 1;
      } else if (newPostIndex >= videoItems.length) {
          newPostIndex = 0;
      }
      
      // Update to new post
      currentPostIndex = newPostIndex;
      const newVideoItem = videoItems[currentPostIndex];
      
      if (!newVideoItem) {
          console.error('Video item not found at index:', newPostIndex);
          return;
      }
      
      const videoContainer = newVideoItem.closest('.post-card');
      const mediaUrl = newVideoItem.getAttribute('onclick')?.match(/'([^']+)'/)?.[1] || '';
      
      const videoItemData = {
          postId: videoContainer?.getAttribute('data-post-id'),
          adId: videoContainer?.getAttribute('data-ad-id'),
          mediaUrl: mediaUrl,
          username: videoContainer?.querySelector('.post-username')?.textContent || 'User',
          profilePic: videoContainer?.querySelector('.post-header img')?.src || 'default_profile.png',
          description: videoContainer?.querySelector('.post-content')?.textContent || ''
      };
      
      // Set current video data
      currentPostId = videoItemData.postId;
      currentAdId = videoItemData.adId;
      
      // Parse media URLs
      const mediaUrlsArray = videoItemData.mediaUrl ? videoItemData.mediaUrl.split(',').map(url => url.trim()) : [];
      currentVideoList = mediaUrlsArray;
      currentVideoIndexInPost = 0; // Reset to first video in new post
      
      // Store post/ad data for engagement buttons
      if (currentPostId) {
          currentPostData = {
              username: videoItemData.username,
              profilePic: videoItemData.profilePic,
              description: videoItemData.description
          };
          currentAdData = null;
      } else if (currentAdId) {
          currentAdData = {
              username: videoItemData.username,
              profilePic: videoItemData.profilePic,
              description: videoItemData.description
          };
          currentPostData = null;
      }
      
      const fullscreenVideo = document.getElementById('fullscreenVideo');
      const videoCounter = document.getElementById('videoCounter');
      const videoUsername = document.getElementById('videoUsername');
      const videoDescription = document.getElementById('videoDescription');
      const videoRightControls = document.getElementById('videoRightControls');
      
      // Update video source
      if (currentVideoList.length > 0) {
          fullscreenVideo.src = currentVideoList[currentVideoIndexInPost];
          fullscreenVideo.muted = false;
          fullscreenVideo.autoplay = true;
          fullscreenVideo.controls = false;
          fullscreenVideo.style.objectFit = 'contain';
      }
      
      // Update counter - Show only videos in current post
      videoCounter.textContent = `Video ${currentVideoIndexInPost + 1}/${currentVideoList.length}`;
      
      // Show/hide video navigation buttons based on number of videos in post
      const videoPrevBtn = document.querySelector('.video-prev-btn');
      const videoNextBtn = document.querySelector('.video-next-btn');
      if (currentVideoList.length > 1) {
          videoPrevBtn.style.display = 'block';
          videoNextBtn.style.display = 'block';
      } else {
          videoPrevBtn.style.display = 'none';
          videoNextBtn.style.display = 'none';
      }
      
      // Update left controls (user info and description)
      if (currentPostData) {
          videoUsername.innerHTML = `
              <img src="${currentPostData.profilePic || 'default_profile.png'}" alt="${currentPostData.username}">
              <span>${currentPostData.username}</span>
          `;
          videoDescription.textContent = currentPostData.description || '';
          
          // Extract hashtags from description
          const hashtags = (currentPostData.description || '').match(/#\w+/g) || [];
          const hashtagsHtml = hashtags.map(tag => 
              `<span class="video-hashtag">${tag}</span>`
          ).join(' ');
          document.getElementById('videoHashtags').innerHTML = hashtagsHtml;
      } else if (currentAdData) {
          videoUsername.innerHTML = `
              <img src="${currentAdData.profilePic || 'default_profile.png'}" alt="${currentAdData.username}">
              <span>${currentAdData.username}</span>
              <span style="background: #ffd700; color: black; padding: 2px 6px; border-radius: 4px; font-size: 10px;">Ad</span>
          `;
          videoDescription.textContent = currentAdData.description || 'Sponsored content';
      }
      
      // Update TikTok-style engagement buttons
      if (currentPostId) {
          videoRightControls.innerHTML = `
              <button class="tiktok-action-btn like-btn" onclick="handleFullscreenLike(${currentPostId})">
                  <div class="icon">🤍</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn comment-btn" onclick="openPostComments(${currentPostId})">
                  <div class="icon">💬</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn share-btn" onclick="sharePost(${currentPostId})">
                  <div class="icon">↗️</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn" onclick="toggleMute()" id="muteBtn">
                  <div class="icon">🔊</div>
                  <span class="count">Sound</span>
              </button>
          `;
      } else if (currentAdId) {
          // Extract numeric ad ID from string like "ad-123"
          const numericAdId = currentAdId.replace('ad-', '').replace('boost-', '');
          videoRightControls.innerHTML = `
              <button class="tiktok-action-btn like-btn" onclick="handleFullscreenAdLike(${numericAdId})">
                  <div class="icon">🤍</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn comment-btn" onclick="openAdComments(${numericAdId})">
                  <div class="icon">💬</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn share-btn" onclick="shareAd(${numericAdId})">
                  <div class="icon">↗️</div>
                  <span class="count">0</span>
              </button>
              
              <button class="tiktok-action-btn" onclick="toggleMute()" id="muteBtn">
                  <div class="icon">🔊</div>
                  <span class="count">Sound</span>
              </button>
          `;
      }
      
      // Try to play the video
      if (currentVideoList.length > 0) {
          fullscreenVideo.play().then(() => {
              isVideoPlaying = true;
              document.getElementById('playPauseIcon').textContent = '⏸️';
          }).catch(e => {
              console.log('Post navigation autoplay prevented:', e);
              isVideoPlaying = false;
              document.getElementById('playPauseIcon').textContent = '▶️';
          });
      }
      
      // Add smooth transition effect
      const overlay = document.getElementById('fullscreenVideoOverlay');
      overlay.style.opacity = '0.8';
      setTimeout(() => {
          overlay.style.opacity = '1';
      }, 300);
  }

  // Close fullscreen video
  function closeFullscreenVideo() {
      const fullscreenVideo = document.getElementById('fullscreenVideo');
      const overlay = document.getElementById('fullscreenVideoOverlay');
      
      // Pause video and clear interval
      fullscreenVideo.pause();
      clearInterval(videoUpdateInterval);
      
      // Reset video source
      fullscreenVideo.src = '';
      
      // Hide overlay
      overlay.style.display = 'none';
      document.body.style.overflow = 'auto';
      
      // Reset variables
      currentVideoList = [];
      currentVideoIndexInPost = 0;
      currentPostId = null;
      currentPostData = null;
      currentAdId = null;
      currentAdData = null;
      currentPostIndex = 0;
      videoItems = [];
      isVideoPlaying = false;
  }

  // Handle like in fullscreen for post
  async function handleFullscreenLike(postId) {
      const likeBtn = document.querySelector('#videoRightControls .like-btn');
      if (!likeBtn) return;

      const isLiked = likeBtn.classList.contains('liked');
      const action = isLiked ? 'unlike' : 'like';

      const formData = new FormData();
      formData.append('action', action);
      formData.append('post_id', postId);

      try {
          const response = await fetch('people.php', {
              method: 'POST',
              body: formData
          });

          const data = await response.json();

          if (data.success) {
              // Update like count
              const countSpan = likeBtn.querySelector('.count');
              if (countSpan) {
                  countSpan.textContent = data.likes_count;
              }
              
              // Toggle liked class and icon
              likeBtn.classList.toggle('liked');
              const icon = likeBtn.querySelector('.icon');
              icon.textContent = isLiked ? '🤍' : '❤️';
              
              // Update post data
              if (currentPostData) {
                  currentPostData.likesCount = data.likes_count;
                  currentPostData.isLiked = !isLiked;
              }
              
              // Add animation effect
              likeBtn.style.transform = 'scale(1.2)';
              setTimeout(() => {
                  likeBtn.style.transform = 'scale(1)';
              }, 200);
          } else {
              alert('Failed to update like status.');
          }
      } catch (err) {
          alert('Error updating like status.');
      }
  }

  // Handle like in fullscreen for ad
  async function handleFullscreenAdLike(adId) {
      const likeBtn = document.querySelector('#videoRightControls .like-btn');
      if (!likeBtn) return;

      const isLiked = likeBtn.classList.contains('liked');
      const action = isLiked ? 'unlike_ad' : 'like_ad';

      const formData = new FormData();
      formData.append('action', action);
      formData.append('ad_id', adId);

      try {
          const response = await fetch('people.php', {
              method: 'POST',
              body: formData
          });

          const data = await response.json();

          if (data.success) {
              // Update like count
              const countSpan = likeBtn.querySelector('.count');
              if (countSpan) {
                  countSpan.textContent = data.likes_count;
              }
              
              // Toggle liked class and icon
              likeBtn.classList.toggle('liked');
              const icon = likeBtn.querySelector('.icon');
              icon.textContent = isLiked ? '🤍' : '❤️';
              
              // Update ad data
              if (currentAdData) {
                  currentAdData.likesCount = data.likes_count;
                  currentAdData.isLiked = !isLiked;
              }
              
              // Add animation effect
              likeBtn.style.transform = 'scale(1.2)';
              setTimeout(() => {
                  likeBtn.style.transform = 'scale(1)';
              }, 200);
          } else {
              alert('Failed to update like status.');
          }
      } catch (err) {
          alert('Error updating like status.');
      }
  }

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
      urlParams.set('tab', 'posts'); // Ensure we're loading posts
      
      const response = await fetch(`people.php?${urlParams.toString()}`, {
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
        const postsList = document.getElementById('posts-list');
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
    document.querySelectorAll('.action-btn.like-btn').forEach(button => {
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
    
    // Attach comment button listeners to new posts
    document.querySelectorAll('.action-btn.comment-btn').forEach(button => {
      if (!button.hasAttribute('data-listener-attached')) {
        button.setAttribute('data-listener-attached', 'true');
        if (button.closest('.ad-post')) {
          button.addEventListener('click', function() {
            const adId = this.closest('.ad-post').dataset.adId;
            openAdComments(adId);
          });
        } else {
          button.addEventListener('click', function() {
            const postId = this.closest('.post-card').dataset.postId;
            openPostComments(postId);
          });
        }
      }
    });
    
    // Attach ad comment button listeners
    document.querySelectorAll('.ad-action-btn.comment-btn').forEach(button => {
      if (!button.hasAttribute('data-listener-attached')) {
        button.setAttribute('data-listener-attached', 'true');
        button.addEventListener('click', function() {
          const adId = this.closest('.ad-post').dataset.adId;
          openAdComments(adId);
        });
      }
    });
    
    // Initialize video controls for new posts
    initVideoControls();
    // Re-collect video items
    collectVideoItems();
  }

  // ========== POST COMMENTS POPUP FUNCTIONS WITH REPLY SYSTEM ==========

  async function openPostComments(postId) {
    currentCommentPostId = postId;
    
    const overlay = document.getElementById('fullscreenPostComments');
    const content = document.getElementById('postCommentsContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align:center; padding:50px; color:white;">Loading comments...</div>';
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    try {
      // Fetch post data and comments using AJAX
      const formData = new FormData();
      formData.append('action', 'get_post_data');
      formData.append('post_id', postId);
      
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Generate the comment.php HTML structure
        content.innerHTML = generatePostCommentsHTML(data.post, data.comments, data.currentUserId);
        
        // Attach event listeners to the new content
        attachPostCommentListeners();
      } else {
        content.innerHTML = `<div style="color:white; text-align:center; padding:50px;">Error: ${data.error || 'Unknown error'}</div>`;
      }
    } catch (error) {
      console.error('Error loading comments:', error);
      content.innerHTML = '<div style="color:white; text-align:center; padding:50px;">Error loading comments. Please try again.</div>';
    }
  }

  function generatePostCommentsHTML(post, comments, currentUserId) {
    if (!post) {
      return '<div style="color:white; text-align:center; padding:50px;">Error: Post data not available</div>';
    }
    
    return `
    <div class="post-comments-container">
      <header>
        <h2>Post by <span onclick="window.open('profile.php?id=${post.author_id}', '_blank')" style="cursor:pointer;">${escapeHtml(post.username)}</span></h2>
      </header>
      
      <div class="post-header">
        <img src="${escapeHtml(post.profile_pic_url || 'default_profile.png')}" alt="Profile" />
        <span class="username" onclick="window.open('profile.php?id=${post.author_id}', '_blank')">${escapeHtml(post.username)}</span>
        <p>${escapeHtml(post.content || '').replace(/\n/g, '<br>')}</p>
        ${post.media_url ? generatePostMediaHTML(post) : ''}
      </div>

      <section id="comments-section">
        <h3>Comments (${comments ? comments.length : 0})</h3>

        <div id="comments-list">
          ${comments && comments.length > 0 ? comments.map(comment => generatePostCommentHTML(comment, currentUserId, false)).join('') : '<div class="no-comments" style="text-align:center; padding:20px; color:#718096;">No comments yet. Be the first to comment!</div>'}
        </div>

        <h3>Add a comment</h3>
        <textarea id="newCommentText" placeholder="Write your comment here..."></textarea>
        <button onclick="addPostComment(${post.id}, null)">Post Comment</button>
        <div id="addCommentMessage" style="color:red; margin-top:5px;"></div>
      </section>
    </div>
    `;
  }

  function generatePostMediaHTML(post) {
    if (post.post_type === 'photo') {
      const mediaArray = post.media_url ? post.media_url.split(',') : [];
      return mediaArray.map(media => 
        `<img src="${escapeHtml(media)}" alt="Post Image" style="max-width:100%; margin-top:10px; border-radius:8px;" />`
      ).join('');
    } else if (post.post_type === 'video') {
      const mediaArray = post.media_url ? post.media_url.split(',') : [];
      return mediaArray.map(media => 
        `<video controls style="max-width:100%; margin-top:10px; border-radius:8px;">
          <source src="${escapeHtml(media)}" type="video/mp4" />
          Your browser does not support the video tag.
        </video>`
      ).join('');
    } else if (post.post_type === 'link' && post.media_url) {
      return `<p><a href="${escapeHtml(post.media_url)}" target="_blank">${escapeHtml(post.media_url)}</a></p>`;
    }
    return '';
  }

  function generatePostCommentHTML(comment, currentUserId, isReply = false) {
    if (!comment) return '';
    
    const isCurrentUser = comment.user_id == currentUserId;
    const commentTime = comment.created_at ? formatDate(comment.created_at) : 'Recently';
    const replyCount = comment.reply_count || 0;
    
    return `
    <div class="comment ${isReply ? 'reply-comment' : ''}" data-comment-id="${comment.id}" style="${isReply ? 'margin-left: 60px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-top: 10px;' : ''}">
      <img src="${escapeHtml(comment.profile_pic_url || 'default_profile.png')}" alt="User" />
      <span class="username" onclick="window.open('profile.php?id=${comment.user_id}', '_blank')">${escapeHtml(comment.username)}</span>
      <div class="comment-meta">
        ${commentTime}
        ${isCurrentUser ? `
          <button class="small-button" onclick="editPostComment(${comment.id})">Edit</button>
          <button class="small-button" onclick="deletePostComment(${comment.id}, ${currentCommentPostId})">Delete</button>
        ` : ''}
        <button class="small-button reply-btn" onclick="showReplyForm(${comment.id})" style="color: #7b68ee;">Reply</button>
      </div>
      <div class="comment-content" id="comment-content-${comment.id}">${escapeHtml(comment.content || '').replace(/\n/g, '<br>')}</div>
      <textarea class="edit-area" id="edit-area-${comment.id}" style="display:none;"></textarea>
      <div id="edit-controls-${comment.id}" style="display:none; margin-left:60px; margin-bottom:10px;">
        <button onclick="savePostComment(${comment.id}, ${currentCommentPostId})">Save</button>
        <button onclick="cancelPostEdit(${comment.id})">Cancel</button>
      </div>
      
      <!-- Reply Form (Hidden by Default) -->
      <div id="reply-form-${comment.id}" style="display:none; margin-top:10px; margin-left:60px;">
        <textarea id="reply-text-${comment.id}" placeholder="Write your reply..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #7b68ee;"></textarea>
        <div style="margin-top:5px;">
          <button onclick="addPostComment(${currentCommentPostId}, ${comment.id})" style="background:#7b68ee; color:white; border:none; padding:5px 15px; border-radius:5px;">Post Reply</button>
          <button onclick="hideReplyForm(${comment.id})" style="background:#718096; color:white; border:none; padding:5px 15px; border-radius:5px; margin-left:10px;">Cancel</button>
        </div>
      </div>
      
      <!-- Replies Section -->
      <div id="replies-container-${comment.id}">
        ${replyCount > 0 ? `
          <div id="replies-toggle-${comment.id}">
            <button onclick="loadPostReplies(${comment.id})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
              ▼ Show ${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}
            </button>
          </div>
          <div id="replies-list-${comment.id}" style="display:none;"></div>
        ` : ''}
      </div>
    </div>
    `;
  }

  function showReplyForm(commentId) {
    const replyForm = document.getElementById(`reply-form-${commentId}`);
    if (replyForm) {
      replyForm.style.display = 'block';
      document.getElementById(`reply-text-${commentId}`).focus();
    }
  }

  function hideReplyForm(commentId) {
    const replyForm = document.getElementById(`reply-form-${commentId}`);
    if (replyForm) {
      replyForm.style.display = 'none';
      document.getElementById(`reply-text-${commentId}`).value = '';
    }
  }

  async function loadPostReplies(commentId) {
    const repliesList = document.getElementById(`replies-list-${commentId}`);
    const toggleBtn = document.getElementById(`replies-toggle-${commentId}`);
    
    if (!repliesList || !toggleBtn) return;
    
    // If already loaded, just toggle visibility
    if (repliesList.innerHTML && repliesList.innerHTML.trim() !== '') {
      if (repliesList.style.display === 'none') {
        repliesList.style.display = 'block';
        toggleBtn.innerHTML = `<button onclick="loadPostReplies(${commentId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
          ▲ Hide replies
        </button>`;
      } else {
        repliesList.style.display = 'none';
        toggleBtn.innerHTML = `<button onclick="loadPostReplies(${commentId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
          ▼ Show replies
        </button>`;
      }
      return;
    }
    
    // Show loading
    repliesList.innerHTML = '<div style="color:#718096; padding:10px; text-align:center;">Loading replies...</div>';
    repliesList.style.display = 'block';
    
    try {
      const formData = new FormData();
      formData.append('action', 'get_post_replies');
      formData.append('comment_id', commentId);
      
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success && data.replies && data.replies.length > 0) {
        let repliesHTML = '';
        data.replies.forEach(reply => {
          repliesHTML += generatePostCommentHTML(reply, data.currentUserId, true);
        });
        repliesList.innerHTML = repliesHTML;
        
        // Update toggle button
        toggleBtn.innerHTML = `<button onclick="loadPostReplies(${commentId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
          ▲ Hide replies
        </button>`;
      } else {
        repliesList.innerHTML = '<div style="color:#718096; padding:10px; text-align:center;">No replies yet.</div>';
      }
    } catch (error) {
      console.error('Error loading replies:', error);
      repliesList.innerHTML = '<div style="color:#ff6b6b; padding:10px; text-align:center;">Error loading replies.</div>';
    }
  }

  async function addPostComment(postId, parentCommentId = null) {
    let text;
    if (parentCommentId) {
      text = document.getElementById(`reply-text-${parentCommentId}`).value.trim();
    } else {
      text = document.getElementById('newCommentText').value.trim();
    }
    
    const msgDiv = document.getElementById('addCommentMessage');
    msgDiv.textContent = '';
    
    if (!text) {
      msgDiv.textContent = 'Comment cannot be empty.';
      return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_post_comment');
    formData.append('content', text);
    formData.append('post_id', postId);
    if (parentCommentId) {
      formData.append('parent_comment_id', parentCommentId);
    }
    
    try {
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        // If it's a reply and we're showing replies, refresh the replies
        if (parentCommentId && data.is_reply) {
          // Refresh the replies section
          loadPostReplies(parentCommentId);
          // Hide the reply form
          hideReplyForm(parentCommentId);
        } else {
          // Refresh all comments for main comment
          openPostComments(postId);
        }
        
        // Clear the input
        if (!parentCommentId) {
          document.getElementById('newCommentText').value = '';
        }
      } else {
        msgDiv.textContent = data.error || 'Error adding comment.';
      }
    } catch (error) {
      console.error('Error adding comment:', error);
      msgDiv.textContent = 'Network error. Please try again.';
    }
  }

  async function deletePostComment(commentId, postId) {
    if (!confirm('Are you sure you want to delete this comment?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_post_comment');
    formData.append('comment_id', commentId);
    formData.append('post_id', postId);
    
    try {
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Refresh the comments
        openPostComments(postId);
      } else {
        alert(data.error || 'Error deleting comment');
      }
    } catch (error) {
      console.error('Error deleting comment:', error);
      alert('Network error. Please try again.');
    }
  }

  function editPostComment(commentId) {
    const contentEl = document.getElementById(`comment-content-${commentId}`);
    const editArea = document.getElementById(`edit-area-${commentId}`);
    const editControls = document.getElementById(`edit-controls-${commentId}`);
    
    if (contentEl && editArea && editControls) {
      contentEl.style.display = 'none';
      editArea.style.display = 'block';
      editArea.value = contentEl.textContent.trim();
      editControls.style.display = 'block';
    }
  }

  function cancelPostEdit(commentId) {
    const contentEl = document.getElementById(`comment-content-${commentId}`);
    const editArea = document.getElementById(`edit-area-${commentId}`);
    const editControls = document.getElementById(`edit-controls-${commentId}`);
    
    if (contentEl && editArea && editControls) {
      contentEl.style.display = 'block';
      editArea.style.display = 'none';
      editControls.style.display = 'none';
    }
  }

  async function savePostComment(commentId, postId) {
    const editArea = document.getElementById(`edit-area-${commentId}`);
    if (!editArea) return;
    
    const newText = editArea.value.trim();
    if (!newText) {
      alert('Comment cannot be empty.');
      return;
    }
    
    // For now, just update locally
    const contentEl = document.getElementById(`comment-content-${commentId}`);
    if (contentEl) {
      contentEl.textContent = newText;
    }
    
    cancelPostEdit(commentId);
    
    // In a real implementation, you would send this to the server
    alert('Edit functionality will be implemented in the next update');
  }

  function attachPostCommentListeners() {
    // No additional listeners needed for now
  }

  function closePostComments() {
    document.getElementById('fullscreenPostComments').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentCommentPostId = null;
  }

  // ========== AD COMMENTS POPUP FUNCTIONS WITH REPLY SYSTEM ==========

  async function openAdComments(adId) {
    currentCommentAdId = adId;
    
    const overlay = document.getElementById('fullscreenAdComments');
    const content = document.getElementById('adCommentsContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align:center; padding:50px; color:white;">Loading comments...</div>';
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    try {
      // Fetch ad data and comments using AJAX
      const formData = new FormData();
      formData.append('action', 'get_ad_data');
      formData.append('ad_id', adId);
      
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Generate the ads_comment.php HTML structure
        content.innerHTML = generateAdCommentsHTML(data.ad, data.comments, data.likeCount, data.shareCount, data.userLiked, data.currentUserId, data.commentCount);
        
        // Attach event listeners to the new content
        attachAdCommentListeners();
      } else {
        content.innerHTML = `<div style="color:white; text-align:center; padding:50px;">Error: ${data.error || 'Unknown error'}</div>`;
      }
    } catch (error) {
      console.error('Error loading comments:', error);
      content.innerHTML = '<div style="color:white; text-align:center; padding:50px;">Error loading comments. Please try again.</div>';
    }
  }

  function generateAdCommentsHTML(ad, comments, likeCount, shareCount, userLiked, currentUserId, commentCount) {
    if (!ad) {
      return '<div style="color:white; text-align:center; padding:50px;">Error: Ad data not available</div>';
    }
    
    // Get advertiser ID (use user_id or advertiser_id)
    const advertiserId = ad.user_id || ad.advertiser_id;
    const userProfilePic = 'default_profile.png'; // You should get this from session
    
    return `
    <div class="ad-comments-container">
      <div class="header">
        <button class="back-btn" onclick="closeAdComments()">← Back</button>
        <h1>Comments - ${escapeHtml(ad.header || 'Advertisement')}</h1>
      </div>

      <!-- Comments Section -->
      <div class="comments-section" id="commentsSection">
        <h2 class="comments-title">Comments (${commentCount || 0})</h2>
        
        <!-- Comment Form -->
        <form class="comment-form" onsubmit="submitAdComment(event, ${ad.id}, null)">
          <img src="${escapeHtml(userProfilePic)}" 
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
          ${comments && comments.length > 0 ? comments.map(comment => generateAdCommentHTML(comment, currentUserId, advertiserId, false)).join('') : '<div class="no-comments">No comments yet. Be the first to comment!</div>'}
        </div>
      </div>
    </div>
    `;
  }

  function generateAdMediaHTML(ad) {
    if (ad.ad_type === 'video') {
      return `
      <div class="ad-media">
        <video controls style="width: 100%; max-height: 300px; border-radius: 8px;">
          <source src="${escapeHtml(ad.media_path)}" type="video/mp4">
          Your browser does not support the video tag.
        </video>
      </div>
      `;
    } else {
      const images = ad.media_path ? ad.media_path.split(',') : [];
      if (images.length > 0) {
        return `
        <div class="ad-media">
          <img src="${escapeHtml(images[0])}" 
                alt="Ad Image" 
                style="max-width: 100%; max-height: 300px; border-radius: 8px;">
        </div>
        `;
      }
    }
    return '';
  }

  function generateAdCommentHTML(comment, currentUserId, advertiserId, isReply = false) {
    if (!comment) return '';
    
    const canDelete = comment.user_id == currentUserId || advertiserId == currentUserId;
    const commentTime = comment.created_at ? timeAgo(comment.created_at) : 'Recently';
    const replyCount = comment.reply_count || 0;
    
    return `
    <div class="comment" id="comment-${comment.id}" style="${isReply ? 'margin-left: 60px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-top: 10px;' : ''}">
      <img src="${escapeHtml(comment.profile_pic_url || 'default_profile.png')}" 
            alt="${escapeHtml(comment.username || 'User')}" 
            class="comment-user-img">
      <div class="comment-content">
        <div class="comment-header">
          <span class="comment-username">
            ${escapeHtml(comment.username || 'User')}
          </span>
          <span class="comment-time">
            ${commentTime}
          </span>
        </div>
        <div class="comment-text">
          ${escapeHtml(comment.content || '').replace(/\n/g, '<br>')}
        </div>
        <div class="comment-actions">
          <button type="button" 
                  class="comment-action reply"
                  onclick="showAdReplyForm(${comment.id})">
            Reply
          </button>
          ${canDelete ? `
          <button type="button" 
                  class="comment-action delete"
                  onclick="deleteAdComment(${comment.id})">
            Delete
          </button>
          ` : ''}
        </div>
        
        <!-- Reply Form (Hidden by Default) -->
        <div id="ad-reply-form-${comment.id}" style="display:none; margin-top:10px;">
          <form onsubmit="submitAdComment(event, ${currentCommentAdId}, ${comment.id})">
            <textarea name="content" 
                      class="comment-input" 
                      placeholder="Write your reply..." 
                      required
                      style="width:100%; padding:10px; border-radius:8px; border:1px solid #667eea;"></textarea>
            <div style="margin-top:5px;">
              <button type="submit" class="comment-submit" style="padding:5px 15px; font-size:12px;">Post Reply</button>
              <button type="button" 
                      onclick="hideAdReplyForm(${comment.id})"
                      style="background:#718096; color:white; border:none; padding:5px 15px; border-radius:5px; margin-left:10px; font-size:12px;">
                Cancel
              </button>
            </div>
          </form>
        </div>
        
        <!-- Replies Section -->
        <div id="ad-replies-container-${comment.id}">
          ${replyCount > 0 ? `
            <div id="ad-replies-toggle-${comment.id}">
              <button onclick="loadAdReplies(${comment.id})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
                ▼ Show ${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}
              </button>
            </div>
            <div id="ad-replies-list-${comment.id}" style="display:none;"></div>
          ` : ''}
        </div>
      </div>
    </div>
    `;
  }

  function showAdReplyForm(commentId) {
    const replyForm = document.getElementById(`ad-reply-form-${commentId}`);
    if (replyForm) {
      replyForm.style.display = 'block';
      replyForm.querySelector('textarea').focus();
    }
  }

  function hideAdReplyForm(commentId) {
    const replyForm = document.getElementById(`ad-reply-form-${commentId}`);
    if (replyForm) {
      replyForm.style.display = 'none';
      replyForm.querySelector('textarea').value = '';
    }
  }

  async function loadAdReplies(commentId) {
    const repliesList = document.getElementById(`ad-replies-list-${commentId}`);
    const toggleBtn = document.getElementById(`ad-replies-toggle-${commentId}`);
    
    if (!repliesList || !toggleBtn) return;
    
    // If already loaded, just toggle visibility
    if (repliesList.innerHTML && repliesList.innerHTML.trim() !== '') {
      if (repliesList.style.display === 'none') {
        repliesList.style.display = 'block';
        toggleBtn.innerHTML = `<button onclick="loadAdReplies(${commentId})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
          ▲ Hide replies
        </button>`;
      } else {
        repliesList.style.display = 'none';
        toggleBtn.innerHTML = `<button onclick="loadAdReplies(${commentId})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
          ▼ Show replies
        </button>`;
      }
      return;
    }
    
    // Show loading
    repliesList.innerHTML = '<div style="color:#718096; padding:10px; text-align:center;">Loading replies...</div>';
    repliesList.style.display = 'block';
    
    try {
      const formData = new FormData();
      formData.append('action', 'get_ad_replies');
      formData.append('comment_id', commentId);
      
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success && data.replies && data.replies.length > 0) {
        let repliesHTML = '';
        data.replies.forEach(reply => {
          repliesHTML += generateAdCommentHTML(reply, data.currentUserId, null, true);
        });
        repliesList.innerHTML = repliesHTML;
        
        // Update toggle button
        toggleBtn.innerHTML = `<button onclick="loadAdReplies(${commentId})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
          ▲ Hide replies
        </button>`;
      } else {
        repliesList.innerHTML = '<div style="color:#718096; padding:10px; text-align:center;">No replies yet.</div>';
      }
    } catch (error) {
      console.error('Error loading replies:', error);
      repliesList.innerHTML = '<div style="color:#ff6b6b; padding:10px; text-align:center;">Error loading replies.</div>';
    }
  }

  async function submitAdComment(event, adId, parentCommentId = null) {
    event.preventDefault();
    
    const form = event.target;
    const content = form.querySelector('textarea[name="content"]').value.trim();
    
    if (!content) {
      alert('Comment cannot be empty.');
      return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_ad_comment');
    formData.append('content', content);
    formData.append('ad_id', adId);
    if (parentCommentId) {
      formData.append('parent_comment_id', parentCommentId);
    }
    
    try {
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        // If it's a reply and we're showing replies, refresh the replies
        if (parentCommentId && data.is_reply) {
          // Refresh the replies section
          loadAdReplies(parentCommentId);
          // Hide the reply form
          hideAdReplyForm(parentCommentId);
        } else {
          // Refresh all comments for main comment
          openAdComments(adId);
        }
        
        // Clear the input
        form.querySelector('textarea[name="content"]').value = '';
      } else {
        alert(data.error || 'Error adding comment.');
      }
    } catch (error) {
      console.error('Error adding comment:', error);
      alert('Network error. Please try again.');
    }
  }

  async function deleteAdComment(commentId) {
    if (!confirm('Are you sure you want to delete this comment?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_ad_comment');
    formData.append('comment_id', commentId);
    formData.append('ad_id', currentCommentAdId);
    
    try {
      const response = await fetch('people.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Refresh the comments
        openAdComments(currentCommentAdId);
      } else {
        alert(data.error || 'Error deleting comment');
      }
    } catch (error) {
      console.error('Error deleting comment:', error);
      alert('Network error. Please try again.');
    }
  }

  function attachAdCommentListeners() {
    // Form submission is handled by onsubmit attribute
  }

  function closeAdComments() {
    document.getElementById('fullscreenAdComments').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentCommentAdId = null;
  }

  // Close fullscreen on escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeFullscreenVideo();
      closePostComments();
      closeAdComments();
    }
    
    // Keyboard navigation for fullscreen video
    if (document.getElementById('fullscreenVideoOverlay').style.display === 'flex') {
      if (e.key === 'ArrowLeft') {
        navigateVideoInPost(-1); // Previous video in post
      } else if (e.key === 'ArrowRight') {
        navigateVideoInPost(1); // Next video in post
      } else if (e.key === 'ArrowUp') {
        navigateToPost(-1); // Previous post
      } else if (e.key === 'ArrowDown') {
        navigateToPost(1); // Next post
      } else if (e.key === ' ' || e.key === 'Spacebar') {
        togglePlayPause(); // Spacebar to play/pause
        e.preventDefault();
      } else if (e.key === 'm' || e.key === 'M') {
        toggleMute(); // M to mute/unmute
      }
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
        // Swipe left - next video in post
        navigateVideoInPost(1);
      } else {
        // Swipe right - previous video in post
        navigateVideoInPost(-1);
      }
    }
  }

  // Video Controls for People Videos (regular view - no autoplay)
  function toggleVideoPlayPause(videoContainer) {
    const video = videoContainer.querySelector('.people-video');
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

  // Initialize video controls
  function initVideoControls() {
    // Set all people videos to muted and paused by default in regular view
    document.querySelectorAll('.people-video').forEach(video => {
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
    
    // Attach click handlers to play/pause buttons
    document.querySelectorAll('.play-pause').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const videoContainer = this.closest('.video-post-container');
        toggleVideoPlayPause(videoContainer);
      });
    });
  }

  const searchInput = document.getElementById('search-input');
  const postsSearchInput = document.getElementById('posts-search-input');
  const usersList = document.getElementById('users-list');
  const postsList = document.getElementById('posts-list');
  const categoryFilter = document.getElementById('category-filter');
  const postTypeFilter = document.getElementById('post-type-filter');
  const tabs = document.querySelectorAll('.tab');
  const tabContents = document.querySelectorAll('.tab-content');
  const searchContainer = document.getElementById('search-container');
  const postsSearchContainer = document.getElementById('posts-search-container');

  let searchTimeout;
  let postsSearchTimeout;
  
  // Real-time search filtering for main search
  searchInput.addEventListener('input', function() {
    const searchValue = this.value.trim();
    
    // Clear previous timeout
    clearTimeout(searchTimeout);
    
    // Set new timeout to avoid too many requests
    searchTimeout = setTimeout(() => {
      if (searchValue.length >= 1 || searchValue.length === 0) {
        // Update URL with search parameter and reload
        const params = new URLSearchParams(window.location.search);
        if (searchValue) {
          params.set('q', searchValue);
        } else {
          params.delete('q');
        }
        // Clear post search when using main search
        params.delete('post_search');
        window.location.href = `people.php?${params.toString()}`;
      }
    }, 500); // 500ms delay
  });

  // NEW: Real-time search filtering for posts search
  postsSearchInput.addEventListener('input', function() {
    const searchValue = this.value.trim();
    
    // Clear previous timeout
    clearTimeout(postsSearchTimeout);
    
    // Set new timeout to avoid too many requests
    postsSearchTimeout = setTimeout(() => {
      if (searchValue.length >= 1 || searchValue.length === 0) {
        // Update URL with post_search parameter and reload
        const params = new URLSearchParams(window.location.search);
        if (searchValue) {
          params.set('post_search', searchValue);
        } else {
          params.delete('post_search');
        }
        // Set tab to posts if not already
        params.set('tab', 'posts');
        window.location.href = `people.php?${params.toString()}`;
      }
    }, 500); // 500ms delay
  });

  // Tab switching
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const tabName = tab.getAttribute('data-tab');
      
      // Update active tab
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      
      // Update active content
      tabContents.forEach(content => content.classList.remove('active'));
      document.getElementById(`${tabName}-tab`).classList.add('active');
      
      // Show/hide post type filter and search bars
      if (tabName === 'posts') {
        postTypeFilter.style.display = 'block';
        searchContainer.style.display = 'none';
        postsSearchContainer.style.display = 'block';
        // Initialize infinite scroll when posts tab is activated
        initInfiniteScroll();
      } else {
        postTypeFilter.style.display = 'none';
        searchContainer.style.display = 'block';
        postsSearchContainer.style.display = 'none';
      }
      
      // Update URL without reload
      updateURL({ tab: tabName });
    });
  });

  // Handle ad like functionality - SIMPLE VERSION (always update UI)
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

      fetch('people.php', {
          method: 'POST',
          body: formData
      }).catch(err => {
          console.error('Ad like action failed in background:', err);
          // Don't revert - keep the optimistic UI update
      });
  }
  // Filter change handlers
  categoryFilter.addEventListener('change', updateFilters);
  postTypeFilter.addEventListener('change', updateFilters);

  function updateFilters() {
    const params = new URLSearchParams(window.location.search);
    params.set('category', categoryFilter.value);
    
    if (document.querySelector('.tab.active').getAttribute('data-tab') === 'posts') {
      params.set('post_type', postTypeFilter.value);
    }
    
    params.set('tab', document.querySelector('.tab.active').getAttribute('data-tab'));
    
    window.location.href = `people.php?${params.toString()}`;
  }

  function updateURL(newParams) {
    const params = new URLSearchParams(window.location.search);
    
    Object.keys(newParams).forEach(key => {
      params.set(key, newParams[key]);
    });
    
    // Don't reload page, just update URL
    window.history.replaceState({}, '', `people.php?${params.toString()}`);
  }

  // Initialize tab-specific search bars on page load
  document.addEventListener('DOMContentLoaded', () => {
    const activeTab = document.querySelector('.tab.active').getAttribute('data-tab');
    if (activeTab === 'posts') {
      searchContainer.style.display = 'none';
      postsSearchContainer.style.display = 'block';
      // Initialize infinite scroll for posts tab
      initInfiniteScroll();
    } else {
      searchContainer.style.display = 'block';
      postsSearchContainer.style.display = 'none';
    }
    
    // Initialize video controls
    initVideoControls();
    
    // Collect video items
    collectVideoItems();
    
    // Attach action button listeners
    attachActionButtonListeners();
  });

  // Friend request button click listener
  function attachFriendButtonListeners() {
    document.querySelectorAll('.add-friend-btn').forEach(btn => {
      btn.removeEventListener('click', handleFriendBtnClick);
      btn.addEventListener('click', handleFriendBtnClick);
    });
  }

  function handleFriendBtnClick(e) {
      const btn = e.currentTarget;
      if (btn.disabled) return;

      const userCard = btn.closest('.user-card');
      const friendId = userCard.getAttribute('data-user-id');

      // ALWAYS update UI immediately
      btn.textContent = 'Pending';
      btn.classList.add('pending');
      btn.disabled = true;

      // Visual feedback
      btn.style.transform = 'scale(1.05)';
      setTimeout(() => {
          btn.style.transform = 'scale(1)';
      }, 200);

      // Send to server in background (fire and forget)
      const formData = new FormData();
      formData.append('action', 'send_request');
      formData.append('friend_id', friendId);

      fetch('friend_request.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
      }).catch(err => {
          console.error('Friend request failed in background:', err);
          // Don't revert - keep the optimistic UI update
      });
  }

  // Follow button functionality - SIMPLE VERSION (always update UI)
  async function handleFollowClick() {
      const btn = this;
      const userId = btn.getAttribute('data-user-id');
      if (!userId) return;

      const isFollowing = btn.classList.contains('following');
      const action = isFollowing ? 'unfollow' : 'follow';

      // ALWAYS update UI immediately
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

      // Send to server in background (fire and forget)
      const formData = new FormData();
      formData.append('action', action);
      formData.append('followed_id', userId);

      fetch(window.location.href, {
          method: 'POST',
          body: formData
      }).catch(err => {
          console.error('Follow action failed in background:', err);
          // Don't revert - keep the optimistic UI update
      });
  }

  // Like button functionality - SIMPLE VERSION (always update UI)
  async function handleLikeClick() {
      const btn = this;
      const postId = btn.getAttribute('data-post-id');
      if (!postId) return;

      const isLiked = btn.classList.contains('liked');
      const action = isLiked ? 'unlike' : 'like';

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
      formData.append('post_id', postId);

      fetch(window.location.href, {
          method: 'POST',
          body: formData
      }).catch(err => {
          console.error('Like action failed in background:', err);
          // Don't revert - keep the optimistic UI update
      });
  }
  // Share button functionality - FIXED (no page reload)
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

  // Ad engagement functionality
  document.addEventListener('DOMContentLoaded', function() {
    // Ad like button functionality
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
        button.addEventListener('click', handleAdLike);
    });
  });

  // Share ad function
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
            
            await fetch('people.php', {
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
        
        await fetch('people.php', {
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
        
        await fetch('people.php', {
            method: 'POST',
            body: formData
        });
    }
  }
  
  // Attach action button listeners
  function attachActionButtonListeners() {
    attachFriendButtonListeners();
    
    // Attach like button listeners
    document.querySelectorAll('.action-btn.like-btn').forEach(button => {
      button.addEventListener('click', handleLikeClick);
    });
    
    // Attach follow button listeners
    document.querySelectorAll('.follow-btn').forEach(button => {
      button.addEventListener('click', handleFollowClick);
    });
    
    // Attach ad like button listeners
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
      button.addEventListener('click', handleAdLike);
    });
    
    // Attach comment button listeners
    document.querySelectorAll('.action-btn.comment-btn').forEach(button => {
      button.addEventListener('click', function() {
        if (this.closest('.ad-post')) {
          const adId = this.closest('.ad-post').dataset.adId;
          openAdComments(adId);
        } else {
          const postId = this.closest('.post-card').dataset.postId;
          openPostComments(postId);
        }
      });
    });
    
    // Attach ad comment button listeners
    document.querySelectorAll('.ad-action-btn.comment-btn').forEach(button => {
      button.addEventListener('click', function() {
        const adId = this.closest('.ad-post').dataset.adId;
        openAdComments(adId);
      });
    });
  }
  
</script>
<?php
require_once "reload.php";
?>
</body>
</html>