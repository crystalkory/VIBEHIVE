<?php
// search_result3.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";
 
// Get search query
$searchQuery = $_GET['q'] ?? '';
$searchType = 'all';
$activeTab = $_GET['tab'] ?? 'videos';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$itemsPerPage = 12;

// Determine search type
if (str_starts_with($searchQuery, '#')) {
    $searchType = 'hashtag';
    $cleanQuery = ltrim($searchQuery, '#');
} else {
    $cleanQuery = $searchQuery;
}

// Fetch user profile info
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$profilePicUrl = $userData['profile_pic_url'] ?: 'default_profile.png';
$username = $userData['username'];

// ========== AD ENGAGEMENT FUNCTIONS ==========
function getAdLikeCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

function getAdCommentCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_comments WHERE ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

function getAdShareCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

function userLikedAd($pdo, $userId, $adId) {
    $stmt = $pdo->prepare("SELECT 1 FROM ad_likes WHERE ad_id = ? AND user_id = ?");
    $stmt->execute([$adId, $userId]);
    return (bool)$stmt->fetchColumn();
}

// ========== ADS TARGETING FUNCTIONS ==========
function getUserCountry($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT country FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function getUserGender($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT gender FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

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

function userMatchesAdTargeting($pdo, $userId, $adLocations, $adGender) {
    $userCountry = getUserCountry($pdo, $userId);
    $userGender = getUserGender($pdo, $userId);
    
    if (!empty($adGender) && $adGender !== $userGender && $adGender !== '') {
        return false;
    }
    
    if (count($adLocations) > 100) {
        return true;
    }
    
    if (in_array($userCountry, $adLocations)) {
        return true;
    }
    
    global $continents;
    foreach ($continents as $continentData) {
        $continentCountries = $continentData['countries'];
        $matchingCountries = array_intersect($continentCountries, $adLocations);
        if (!empty($matchingCountries) && in_array($userCountry, $continentCountries)) {
            return true;
        }
    }
    
    return false;
}

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
    
    if (empty($targetedAds)) {
        return [];
    }
    
    shuffle($targetedAds);
    
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

// ========== BOOSTED POSTS ==========
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
shuffle($boostedPosts);

// Get targeted ads with engagement counts
$targetedAds = getTargetedAds($pdo, $currentUserId, 30);
$ads_with_engagement = [];
foreach ($targetedAds as $ad) {
    $ad['like_count'] = getAdLikeCount($pdo, $ad['id']);
    $ad['comment_count'] = getAdCommentCount($pdo, $ad['id']);
    $ad['share_count'] = getAdShareCount($pdo, $ad['id']);
    $ad['user_liked'] = userLikedAd($pdo, $currentUserId, $ad['id']);
    $ads_with_engagement[] = $ad;
}
$targetedAds = $ads_with_engagement;

// ========== SEARCH FUNCTIONALITY WITH PAGINATION ==========
// Initialize all result variables
$allResults = [];
$hashtagResults = [];
$userResults = [];
$hashtagList = [];
$results = [];
$totalVideos = 0;
$totalUsers = 0;
$totalHashtags = 0;
$totalPagesVideos = 1;
$totalPagesUsers = 1;
$totalPagesHashtags = 1;

// ========== GROUP SEARCH FUNCTIONALITY ==========
$totalGroups = 0;
$totalPagesGroups = 1;
$groupResults = [];

try {
    $offset = ($page - 1) * $itemsPerPage;
    
    if ($searchType === 'hashtag' || empty($cleanQuery)) {
        // Search by hashtag
        $hashtagStmt = $pdo->prepare("
            SELECT DISTINCT p.*, u.username, u.profile_pic_url,
                   COALESCE(l.likes_count, 0) as likes_count,
                   COALESCE(c.comments_count, 0) as comments_count,
                   COALESCE(s.shares_count, 0) as shares_count
            FROM posts p
            JOIN users u ON p.user_id = u.id
            JOIN post_hashtags ph ON p.id = ph.post_id
            JOIN hashtags h ON ph.hashtag_id = h.id
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
                SELECT post_id, COUNT(*) as shares_count 
                FROM shares 
                GROUP BY post_id
            ) s ON p.id = s.post_id
            WHERE h.tag ILIKE ? AND p.post_type = 'video'
            ORDER BY p.created_at DESC
        ");
        $hashtagStmt->execute([$cleanQuery . '%']);
        $hashtagResults = $hashtagStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get hashtag list for hashtags tab
        $hashtagListStmt = $pdo->prepare("
            SELECT h.tag, COUNT(ph.post_id) as post_count
            FROM hashtags h
            JOIN post_hashtags ph ON h.id = ph.hashtag_id
            WHERE h.tag ILIKE ?
            GROUP BY h.tag
            ORDER BY post_count DESC
        ");
        $hashtagListStmt->execute([$cleanQuery . '%']);
        $hashtagList = $hashtagListStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalHashtags = count($hashtagList);
        $totalPagesHashtags = ceil($totalHashtags / $itemsPerPage);
        $hashtagList = array_slice($hashtagList, $offset, $itemsPerPage);
    }

    if ($searchType === 'all' && !empty($cleanQuery)) {
        // Search in post headers and content - with pagination
        $postStmt = $pdo->prepare("
            SELECT DISTINCT p.*, u.username, u.profile_pic_url,
                   COALESCE(l.likes_count, 0) as likes_count,
                   COALESCE(c.comments_count, 0) as comments_count,
                   COALESCE(s.shares_count, 0) as shares_count
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
                SELECT post_id, COUNT(*) as shares_count 
                FROM shares 
                GROUP BY post_id
            ) s ON p.id = s.post_id
            WHERE (p.post_header ILIKE ? OR p.content ILIKE ?) 
               AND p.post_type = 'video'
            ORDER BY p.created_at DESC
        ");
        $postStmt->execute(['%' . $cleanQuery . '%', '%' . $cleanQuery . '%']);
        $results = $postStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalVideos = count($results);
        $totalPagesVideos = ceil($totalVideos / $itemsPerPage);
        $results = array_slice($results, $offset, $itemsPerPage);
        
        // Search for users with pagination
        $userStmt = $pdo->prepare("
            SELECT id, username, profile_pic_url, is_business_account,
                   (SELECT COUNT(*) FROM follows WHERE followed_id = users.id) as follower_count
            FROM users
            WHERE username ILIKE ?
            ORDER BY username
        ");
        $userStmt->execute([$cleanQuery . '%']);
        $userResults = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalUsers = count($userResults);
        $totalPagesUsers = ceil($totalUsers / $itemsPerPage);
        $userResults = array_slice($userResults, $offset, $itemsPerPage);
        
        // Get hashtag list for general search
        $hashtagListStmt = $pdo->prepare("
            SELECT h.tag, COUNT(ph.post_id) as post_count
            FROM hashtags h
            JOIN post_hashtags ph ON h.id = ph.hashtag_id
            WHERE h.tag ILIKE ?
            GROUP BY h.tag
            ORDER BY post_count DESC
        ");
        $hashtagListStmt->execute([$cleanQuery . '%']);
        $hashtagList = $hashtagListStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalHashtags = count($hashtagList);
        $totalPagesHashtags = ceil($totalHashtags / $itemsPerPage);
        $hashtagList = array_slice($hashtagList, $offset, $itemsPerPage);
        
        // ========== GROUP SEARCH ==========
        if (!empty($cleanQuery)) {
            // Search for groups (public groups only)
            $groupStmt = $pdo->prepare("
                SELECT g.*, 
                       (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.status = 'approved') AS member_count,
                       (SELECT status FROM group_members gm WHERE gm.group_id = g.id AND gm.user_id = :current_user LIMIT 1) AS membership_status
                FROM groups g
                WHERE g.privacy_setting != 'private'
                AND (LOWER(g.name) ILIKE :search 
                    OR LOWER(g.description) ILIKE :search
                    OR LOWER(g.category1) ILIKE :search
                    OR LOWER(g.category2) ILIKE :search
                    OR LOWER(g.category3) ILIKE :search)
                ORDER BY member_count DESC, g.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            
            $groupStmt->bindValue(':search', '%' . strtolower($cleanQuery) . '%', PDO::PARAM_STR);
            $groupStmt->bindValue(':current_user', $currentUserId, PDO::PARAM_INT);
            $groupStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
            $groupStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $groupStmt->execute();
            $groupResults = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $groupCountStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM groups g
                WHERE g.privacy_setting != 'private'
                AND (LOWER(g.name) ILIKE :search 
                    OR LOWER(g.description) ILIKE :search
                    OR LOWER(g.category1) ILIKE :search
                    OR LOWER(g.category2) ILIKE :search
                    OR LOWER(g.category3) ILIKE :search)
            ");
            $groupCountStmt->bindValue(':search', '%' . strtolower($cleanQuery) . '%', PDO::PARAM_STR);
            $groupCountStmt->execute();
            $totalGroups = (int)$groupCountStmt->fetchColumn();
            $totalPagesGroups = ceil($totalGroups / $itemsPerPage);
        }
    }

} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
}

// Ensure all arrays are initialized
if (!isset($hashtagResults)) $hashtagResults = [];
if (!isset($results)) $results = [];
if (!isset($userResults)) $userResults = [];
if (!isset($hashtagList)) $hashtagList = [];
if (!isset($groupResults)) $groupResults = [];

// Combine results - prioritize hashtag results
$allResults = array_merge($hashtagResults, $results);
$totalVideos = count($allResults);
$totalPagesVideos = ceil($totalVideos / $itemsPerPage);
$allResults = array_slice($allResults, $offset, $itemsPerPage);

$totalResults = count($allResults) + count($userResults) + count($hashtagList) + count($groupResults);

// Check if user liked each post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$postId, $userId]);
    return (bool)$stmt->fetchColumn();
}

// ========== FOLLOW STATUS CHECK ==========
// Fetch list of users the current user follows
$followStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$followStmt->execute([$currentUserId]);
$followingUserIds = $followStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch friend requests and accepted friends info
$pendingStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'pending'");
$pendingStmt->execute([$currentUserId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);

$friendsStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'");
$friendsStmt->execute([$currentUserId]);
$acceptedFriends = $friendsStmt->fetchAll(PDO::FETCH_COLUMN);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- JOIN GROUP HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'join_group' && isset($_POST['group_id'])) {
        $groupId = (int)$_POST['group_id'];
        
        // Check if already a member
        $checkStmt = $pdo->prepare("SELECT status FROM group_members WHERE group_id = ? AND user_id = ?");
        $checkStmt->execute([$groupId, $currentUserId]);
        $existingMember = $checkStmt->fetch();
        
        if ($existingMember) {
            if ($existingMember['status'] === 'pending') {
                echo json_encode(['success' => true, 'pending' => true]);
            } else {
                echo json_encode(['success' => true, 'pending' => false]);
            }
            exit;
        }
        
        // Check if group requires admin approval
        $groupStmt = $pdo->prepare("SELECT admin_approval_required FROM groups WHERE id = ?");
        $groupStmt->execute([$groupId]);
        $group = $groupStmt->fetch();
        
        $requiresApproval = $group && $group['admin_approval_required'] === true;
        
        if ($requiresApproval) {
            // Insert as pending member
            $insertStmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, joined_at) VALUES (?, ?, 'pending', NOW())");
            $insertStmt->execute([$groupId, $currentUserId]);
            
            echo json_encode(['success' => true, 'pending' => true]);
        } else {
            // Insert as approved member directly
            $insertStmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, joined_at) VALUES (?, ?, 'approved', NOW())");
            $insertStmt->execute([$groupId, $currentUserId]);
            
            echo json_encode(['success' => true, 'pending' => false]);
        }
        exit;
    }
    
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
        
        // Get updated like count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
        $stmt->execute([$postId]);
        $newLikeCount = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
        exit;
    }
    
    // Handle share functionality
    if (isset($_POST['action']) && $_POST['action'] === 'share' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        // Check if already shared
        $checkStmt = $pdo->prepare("SELECT 1 FROM shares WHERE post_id = ? AND user_id = ?");
        $checkStmt->execute([$postId, $userId]);
        $alreadyShared = (bool)$checkStmt->fetchColumn();
        
        if (!$alreadyShared) {
            $stmt = $pdo->prepare("INSERT INTO shares (post_id, user_id) VALUES (?, ?)");
            $stmt->execute([$postId, $userId]);
        }
        
        // Get updated share count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shares WHERE post_id = ?");
        $stmt->execute([$postId]);
        $newShareCount = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'shares_count' => $newShareCount]);
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
    
    // Ad like/unlike
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
    
    // Ad share
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
    
    // ========== AJAX HANDLERS FOR COMMENTS ==========
    
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
}

// Function to generate pagination links
function generatePagination($currentPage, $totalPages, $baseUrl) {
    if ($totalPages <= 1) return '';
    
    $pagination = '<div class="pagination">';
    
    // Previous button
    if ($currentPage > 1) {
        $pagination .= '<a href="' . $baseUrl . '&page=' . ($currentPage - 1) . '" class="page-btn">← Previous</a>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $start + 4);
    
    if ($start > 1) {
        $pagination .= '<a href="' . $baseUrl . '&page=1" class="page-num">1</a>';
        if ($start > 2) $pagination .= '<span class="page-dots">...</span>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $pagination .= '<span class="page-num active">' . $i . '</span>';
        } else {
            $pagination .= '<a href="' . $baseUrl . '&page=' . $i . '" class="page-num">' . $i . '</a>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $pagination .= '<span class="page-dots">...</span>';
        $pagination .= '<a href="' . $baseUrl . '&page=' . $totalPages . '" class="page-num">' . $totalPages . '</a>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $pagination .= '<a href="' . $baseUrl . '&page=' . ($currentPage + 1) . '" class="page-btn">Next →</a>';
    }
    
    $pagination .= '</div>';
    return $pagination;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Search Results - Fbclone</title>
<style>
/* All existing CSS styles remain the same... */

/* ========== FULLSCREEN COMMENT OVERLAY STYLES (FROM PEOPLE.PHP) ========== */
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

/* Update the comment button styles in video right controls */
.tiktok-action-btn.comment-btn {
    color: #4ecdc4;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
}

.tiktok-action-btn.comment-btn:hover {
    text-decoration: none;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    line-height: 1.4;
    min-height: 100vh;
}

/* ========== PAGINATION STYLES ========== */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin: 30px 0;
    padding: 0 20px;
    flex-wrap: wrap;
}

.page-btn, .page-num {
    background: rgba(255, 255, 255, 0.15);
    border: 2px solid rgba(123, 104, 238, 0.3);
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    cursor: pointer;
    min-width: 40px;
    text-align: center;
}

.page-btn:hover, .page-num:hover {
    background: rgba(123, 104, 238, 0.3);
    border-color: #7b68ee;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
}

.page-num.active {
    background: rgba(123, 104, 238, 0.5);
    border-color: #7b68ee;
    font-weight: 700;
    transform: scale(1.1);
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.4);
}

.page-dots {
    color: rgba(255, 255, 255, 0.7);
    font-weight: 700;
    padding: 0 5px;
}

/* AD POST STYLES */
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

/* AD ENGAGEMENT BUTTONS */
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

/* BOOSTED POSTS */
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

/* ========== FULLSCREEN VIDEO STYLES ========== */
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
/* VIDEO NAVIGATION BUTTONS (FOR VIDEOS WITHIN A POST) - Positioned at bottom */
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

/* POST NAVIGATION BUTTONS (FOR NAVIGATING BETWEEN DIFFERENT POSTS) - Positioned at top */
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

/* Video posts styling */
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
    object-fit: contain !important;
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

/* Post Actions */
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

.action-btn.comment-btn {
    color: #4ecdc4;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
}

.action-btn.comment-btn:hover {
    text-decoration: none;
    color: #4ecdc4;
}

.action-btn.share-btn {
    color: #45b7d1;
}

/* Header */
.search-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(123, 104, 238, 0.3);
    padding: 12px 16px;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 12px;
}

.back-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    padding: 10px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.back-btn:hover {
    background: rgba(123, 104, 238, 0.3);
    transform: scale(1.1);
}

.search-container {
    flex: 1;
    position: relative;
}

.search-input {
    width: 100%;
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(123, 104, 238, 0.3);
    border-radius: 25px;
    padding: 14px 20px;
    color: #fff;
    font-size: 16px;
    outline: none;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.search-input::placeholder {
    color: rgba(255, 255, 255, 0.6);
}

.search-input:focus {
    background: rgba(255, 255, 255, 0.15);
    border-color: #7b68ee;
    box-shadow: 0 4px 20px rgba(123, 104, 238, 0.3);
}

/* Tabs */
.results-tabs {
    position: fixed;
    top: 68px;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(123, 104, 238, 0.3);
    z-index: 999;
    display: flex;
}

.tab {
    flex: 1;
    text-align: center;
    padding: 18px 16px;
    font-size: 15px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.tab::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(123, 104, 238, 0.2), transparent);
    transition: left 0.5s ease;
}

.tab:hover::before {
    left: 100%;
}

.tab:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(123, 104, 238, 0.1);
}

.tab.active {
    color: #fff;
    border-bottom-color: #7b68ee;
    background: rgba(123, 104, 238, 0.15);
}

/* Main Content */
.main-content {
    margin-top: 130px;
    padding-bottom: 80px;
}

.tab-content {
    display: none;
    animation: fadeIn 0.5s ease-in-out;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Results Grid */
.results-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3px;
    padding: 3px;
}

.video-item {
    position: relative;
    aspect-ratio: 9/16;
    background: rgba(255, 255, 255, 0.1);
    cursor: pointer;
    overflow: hidden;
    border-radius: 8px;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.video-item:hover {
    transform: scale(1.02);
    border-color: rgba(123, 104, 238, 0.5);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.3);
}

.video-item video {
    width: 100%;
    height: 100%;
    object-fit: contain !important;
    border-radius: 6px;
}

.video-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 16px 12px;
    background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
    color: #fff;
    border-radius: 0 0 6px 6px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.video-item:hover .video-overlay {
    opacity: 1;
}

.video-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 12px;
    margin-top: 6px;
}

.video-stat {
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(0, 0, 0, 0.6);
    padding: 4px 8px;
    border-radius: 12px;
    backdrop-filter: blur(10px);
}

/* User Results */
.user-results {
    padding: 16px;
}

.user-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    margin-bottom: 12px;
    border: 2px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
    cursor: pointer;
    color: #2d3748;
}

.user-item:hover {
    transform: translateX(10px);
    border-color: #7b68ee;
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.2);
}

.user-item:last-child {
    margin-bottom: 0;
}

.user-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #7b68ee;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.user-item:hover .user-avatar {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.user-info {
    flex: 1;
}

.user-username {
    font-weight: 700;
    margin-bottom: 4px;
    color: #2d3748;
    font-size: 16px;
}

.user-followers {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
}

.follow-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    border: none;
    border-radius: 20px;
    color: #fff;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
}

.follow-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.follow-btn.following {
    background: linear-gradient(135deg, #a0aec0, #718096);
    color: #f7fafc;
}

/* Hashtag Results */
.hashtag-results {
    padding: 16px;
}

.hashtag-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    margin-bottom: 12px;
    border: 2px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
    cursor: pointer;
    color: #2d3748;
}

.hashtag-item:hover {
    transform: translateX(10px);
    border-color: #7b68ee;
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.2);
}

.hashtag-icon {
    font-size: 24px;
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    border-radius: 50%;
    color: white;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
}

.hashtag-info {
    flex: 1;
}

.hashtag-tag {
    font-weight: 700;
    margin-bottom: 4px;
    color: #2d3748;
    font-size: 18px;
}

.hashtag-count {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
}

/* Post Card for Full View */
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

.post-content {
    margin: 12px 0;
    white-space: pre-wrap;
    line-height: 1.4;
    word-wrap: break-word;
    color: #2d3748;
}

.post-date {
    font-size: 12px;
    color: #718096;
    text-align: right;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: rgba(255, 255, 255, 0.8);
}

.empty-icon {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.7;
    filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
}

.empty-title {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 12px;
    color: #fff;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.empty-text {
    font-size: 16px;
    margin-bottom: 30px;
    color: rgba(255, 255, 255, 0.7);
}

.search-again-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    border: none;
    border-radius: 25px;
    color: #fff;
    padding: 14px 32px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
}

.search-again-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.4);
}

/* Loading */
.loading {
    text-align: center;
    padding: 60px 20px;
    color: rgba(255, 255, 255, 0.8);
}

.loading-dots {
    display: inline-flex;
    gap: 6px;
    margin-bottom: 20px;
}

.loading-dots span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #7b68ee;
    animation: bounce 1.4s infinite ease-in-out both;
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.5);
}

.loading-dots span:nth-child(1) { animation-delay: -0.32s; }
.loading-dots span:nth-child(2) { animation-delay: -0.16s; }

@keyframes bounce {
    0%, 80%, 100% { 
        transform: scale(0);
        opacity: 0.5;
    }
    40% { 
        transform: scale(1);
        opacity: 1;
    }
}

.loading-text {
    font-size: 16px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
}

/* Results Counter */
.results-counter {
    padding: 16px 20px;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
    background: rgba(0, 0, 0, 0.3);
    border-bottom: 1px solid rgba(123, 104, 238, 0.2);
    font-weight: 600;
}

.results-counter strong {
    color: #7b68ee;
}

/* Animation for grid items */
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.video-item, .user-item, .hashtag-item {
    animation: slideUp 0.6s ease-out;
}

.video-item:nth-child(1) { animation-delay: 0.1s; }
.video-item:nth-child(2) { animation-delay: 0.2s; }
.video-item:nth-child(3) { animation-delay: 0.3s; }
.video-item:nth-child(4) { animation-delay: 0.4s; }
.video-item:nth-child(5) { animation-delay: 0.5s; }
.video-item:nth-child(6) { animation-delay: 0.6s; }

.user-item:nth-child(1) { animation-delay: 0.2s; }
.user-item:nth-child(2) { animation-delay: 0.3s; }
.user-item:nth-child(3) { animation-delay: 0.4s; }

.hashtag-item:nth-child(1) { animation-delay: 0.2s; }
.hashtag-item:nth-child(2) { animation-delay: 0.3s; }
.hashtag-item:nth-child(3) { animation-delay: 0.4s; }

/* Responsive */
@media (max-width: 768px) {
    .results-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .search-header {
        padding: 10px 12px;
    }
    
    .search-input {
        padding: 12px 16px;
        font-size: 15px;
    }
    
    .main-content {
        margin-top: 120px;
    }
    
    .results-tabs {
        top: 64px;
    }
    
    .tab {
        padding: 16px 12px;
        font-size: 14px;
    }
    
    /* Fullscreen video responsive */
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
    
    .pagination {
        gap: 5px;
        padding: 0 10px;
    }
    
    .page-btn, .page-num {
        padding: 8px 15px;
        font-size: 12px;
    }
}

@media (max-width: 480px) {
    .results-grid {
        grid-template-columns: 1fr;
    }
    
    .video-item {
        aspect-ratio: 16/9;
    }
    
    .main-content {
        margin-top: 110px;
    }
    
    .user-item, .hashtag-item {
        padding: 16px;
        gap: 12px;
    }
    
    .user-avatar, .hashtag-icon {
        width: 48px;
        height: 48px;
    }
    
    .empty-state {
        padding: 60px 16px;
    }
    
    .empty-icon {
        font-size: 64px;
    }
    
    .empty-title {
        font-size: 20px;
    }
    
    .back-btn {
        width: 36px;
        height: 36px;
        font-size: 16px;
    }
    
    .post-actions {
        flex-direction: column;
    }
    
    .action-btn {
        width: 100%;
        justify-content: flex-start;
    }
    
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
    
    .pagination {
        flex-direction: column;
        gap: 10px;
    }
    
    .page-btn, .page-num {
        width: 100%;
    }
}
/* Group Results */
.groups-results {
    padding: 16px;
}

.group-item {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    margin-bottom: 16px;
    overflow: hidden;
    border: 2px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
    cursor: pointer;
    color: #2d3748;
}

.group-item:hover {
    transform: translateX(10px);
    border-color: #7b68ee;
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.2);
}

.group-header {
    position: relative;
    height: 120px;
    overflow: hidden;
}

.group-cover {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.group-profile {
    position: absolute;
    bottom: -25px;
    left: 20px;
    width: 70px;
    height: 70px;
    border-radius: 50%;
    border: 3px solid white;
    object-fit: cover;
    background: white;
}

.group-info {
    padding: 40px 20px 20px 20px;
}

.group-name {
    font-weight: 700;
    font-size: 18px;
    margin-bottom: 8px;
    color: #2d3748;
}

.group-members {
    font-size: 14px;
    color: #718096;
    margin-bottom: 8px;
}

.group-categories {
    font-size: 12px;
    color: #7b68ee;
    margin-bottom: 8px;
    font-weight: 600;
}

.group-description {
    font-size: 14px;
    color: #4a5568;
    line-height: 1.4;
    margin-bottom: 10px;
}

.group-actions {
    padding: 0 20px 20px 20px;
    text-align: right;
}

.group-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    border-radius: 20px;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    min-width: 100px;
}

.group-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.group-btn.visited {
    background: linear-gradient(135deg, #48bb78, #38a169);
}

.group-btn.pending {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    cursor: default;
}

/* Admin approval indicator */
.admin-approval-indicator {
    font-size: 11px;
    color: #ed8936;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
}

.admin-approval-indicator::before {
    content: "⚠️";
    font-size: 10px;
}
</style>
</head>
<body>

<!-- Full Screen Video Overlay -->
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

<!-- Header -->
<div class="search-header">
    <button class="back-btn" onclick="history.back()">←</button>
    <div class="search-container">
        <input type="text" class="search-input" value="<?= htmlspecialchars($searchQuery) ?>" 
               placeholder="Search..." onkeypress="handleSearchEnter(event)">
    </div>
</div>

<!-- Tabs -->
<!-- Tabs -->
<div class="results-tabs">
    <div class="tab <?= $activeTab === 'videos' ? 'active' : '' ?>" onclick="switchTab('videos')">Videos</div>
    <div class="tab <?= $activeTab === 'users' ? 'active' : '' ?>" onclick="switchTab('users')">Users</div>
    <div class="tab <?= $activeTab === 'groups' ? 'active' : '' ?>" onclick="switchTab('groups')">Groups</div>
    <div class="tab <?= $activeTab === 'hashtags' ? 'active' : '' ?>" onclick="switchTab('hashtags')">Hashtags</div>
</div>

<!-- Main Content -->
<div class="main-content">
    <?php if (empty($cleanQuery)): ?>
        
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <div class="empty-title">Enter a search term</div>
            <div class="empty-text">Search for videos, users, or hashtags</div>
        </div>
        
    <?php elseif ($totalResults === 0): ?>
        
        <div class="empty-state">
            <div class="empty-icon">😕</div>
            <div class="empty-title">No results found</div>
            <div class="empty-text">Try searching with different keywords</div>
            <button class="search-again-btn" onclick="goBackToSearch()">Search Again</button>
        </div>
        
    <?php else: ?>
        
        <!-- Videos Tab Content -->
        <div id="videos-tab" class="tab-content <?= $activeTab === 'videos' ? 'active' : '' ?>">
            <?php if (!empty($allResults)): ?>
                <div class="results-grid" id="videoGrid">
                    <?php 
                    $postCounter = 0;
                    $adCounter = 0;
                    $boostCounter = 0;
                    $shuffledAds = $targetedAds;
                    shuffle($shuffledAds);
                    
                    foreach ($allResults as $post): 
                        $postCounter++;
                        $isLiked = userLikedPost($pdo, $currentUserId, $post['id']);
                        $mediaArray = !empty($post['media_url']) ? explode(',', $post['media_url']) : [];
                        $totalVideosInPost = count($mediaArray);
                    ?>
                        <div class="video-item" 
                             data-post-id="<?= $post['id'] ?>" 
                             data-media-url="<?= htmlspecialchars($post['media_url']) ?>"
                             data-likes="<?= $post['likes_count'] ?>"
                             data-comments="<?= $post['comments_count'] ?>"
                             data-shares="<?= $post['shares_count'] ?>"
                             data-liked="<?= $isLiked ? 'true' : 'false' ?>"
                             data-username="<?= htmlspecialchars($post['username']) ?>"
                             data-profile-pic="<?= htmlspecialchars($post['profile_pic_url']) ?>"
                             data-description="<?= htmlspecialchars($post['content'] ?? '') ?>"
                             onclick="openVideoFullscreen(this, <?= $postCounter ?>)">
                            <?php if (!empty($post['media_url'])): 
                                $firstVideo = trim($mediaArray[0]);
                            ?>
                                <video preload="metadata" muted loop>
                                    <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                                </video>
                                <?php if ($totalVideosInPost > 1): ?>
                                    <div class="multi-video-indicator">
                                        <span class="arrow">⇆</span> <?= $totalVideosInPost ?> videos
                                    </div>
                                <?php endif; ?>
                                <div class="video-overlay">
                                    <div class="video-stats">
                                        <div class="video-stat">❤️ <?= $post['likes_count'] ?></div>
                                        <div class="video-stat">💬 <?= $post['comments_count'] ?></div>
                                        <div class="video-stat">↗️ <?= $post['shares_count'] ?></div>
                                        <?php if ($totalVideosInPost > 1): ?>
                                            <div class="video-stat">🎥 <?= $totalVideosInPost ?> videos</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="width:100%;height:100%;background:#2f2f2f;display:flex;align-items:center;justify-content:center;">
                                    <span>No video</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php
                        // Insert boosted posts every 6 posts
                        if ($postCounter % 6 === 0 && count($boostedPosts) > 0):
                            $randomBoostIndex = array_rand($boostedPosts);
                            $boostPost = $boostedPosts[$randomBoostIndex];
                            $boostCounter++;
                            $boostMediaArray = !empty($boostPost['media_url']) ? explode(',', $boostPost['media_url']) : [];
                            $totalBoostVideos = count($boostMediaArray);
                        ?>
                            <!-- BOOSTED POST -->
                            <div class="video-item boosted-post" 
                                 data-post-id="<?= $boostPost['id'] ?>" 
                                 data-media-url="<?= htmlspecialchars($boostPost['media_url']) ?>"
                                 data-likes="<?= getAdLikeCount($pdo, $boostPost['id']) ?>"
                                 data-comments="<?= getAdCommentCount($pdo, $boostPost['id']) ?>"
                                 data-shares="<?= getAdShareCount($pdo, $boostPost['id']) ?>"
                                 data-liked="<?= userLikedPost($pdo, $currentUserId, $boostPost['id']) ? 'true' : 'false' ?>"
                                 data-username="<?= htmlspecialchars($boostPost['username']) ?>"
                                 data-profile-pic="<?= htmlspecialchars($boostPost['profile_pic_url']) ?>"
                                 data-description="<?= htmlspecialchars($boostPost['content'] ?? '') ?>"
                                 onclick="openVideoFullscreen(this, <?= $postCounter ?>)">
                                <?php if (!empty($boostPost['media_url'])): 
                                    $firstVideo = trim($boostMediaArray[0]);
                                ?>
                                    <video preload="metadata" muted loop>
                                        <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                                    </video>
                                    <?php if ($totalBoostVideos > 1): ?>
                                        <div class="multi-video-indicator">
                                            <span class="arrow">⇆</span> <?= $totalBoostVideos ?> videos
                                        </div>
                                    <?php endif; ?>
                                    <div class="video-overlay">
                                        <div style="position: absolute; top: 10px; left: 10px; background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: bold;">
                                            Sponsored
                                        </div>
                                        <div class="video-stats">
                                            <div class="video-stat">❤️ <?= getAdLikeCount($pdo, $boostPost['id']) ?></div>
                                            <div class="video-stat">💬 <?= getAdCommentCount($pdo, $boostPost['id']) ?></div>
                                            <div class="video-stat">↗️ <?= getAdShareCount($pdo, $boostPost['id']) ?></div>
                                            <?php if ($totalBoostVideos > 1): ?>
                                                <div class="video-stat">🎥 <?= $totalBoostVideos ?> videos</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php
                        // Insert ads every 4 posts
                        if ($postCounter % 4 === 0 && count($shuffledAds) > 0):
                            $randomAdIndex = array_rand($shuffledAds);
                            $ad = $shuffledAds[$randomAdIndex];
                            $adCounter++;
                            $adMediaArray = !empty($ad['media_path']) ? explode(',', $ad['media_path']) : [];
                            $totalAdVideos = count($adMediaArray);
                        ?>
                            <!-- ADVERTISEMENT -->
                            <div class="video-item ad-post" 
                                 data-ad-id="<?= $ad['id'] ?>" 
                                 data-media-url="<?= htmlspecialchars($ad['media_path']) ?>"
                                 data-likes="<?= $ad['like_count'] ?>"
                                 data-comments="<?= $ad['comment_count'] ?>"
                                 data-shares="<?= $ad['share_count'] ?>"
                                 data-liked="<?= $ad['user_liked'] ? 'true' : 'false' ?>"
                                 data-username="<?= htmlspecialchars($ad['username']) ?>"
                                 data-profile-pic="<?= htmlspecialchars($ad['profile_pic_url']) ?>"
                                 data-description="<?= htmlspecialchars($ad['description'] ?? '') ?>"
                                 onclick="openVideoFullscreen(this, <?= $postCounter ?>)">
                                <?php if (!empty($ad['media_path'])): 
                                    $firstMedia = trim($adMediaArray[0]);
                                    if ($ad['ad_type'] === 'video'):
                                ?>
                                    <video preload="metadata" muted loop>
                                        <source src="<?= htmlspecialchars($firstMedia) ?>" type="video/mp4">
                                    </video>
                                    <?php if ($totalAdVideos > 1): ?>
                                        <div class="multi-video-indicator">
                                            <span class="arrow">⇆</span> <?= $totalAdVideos ?> videos
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($firstMedia) ?>" alt="Ad" style="width:100%;height:100%;object-fit:contain;">
                                <?php endif; ?>
                                    <div class="video-overlay">
                                        <div style="position: absolute; top: 10px; left: 10px; background: linear-gradient(135deg, #ffd700, #ff8c00); color: black; padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: bold;">
                                            Sponsored Ad
                                        </div>
                                        <div class="video-stats">
                                            <div class="video-stat">❤️ <?= $ad['like_count'] ?></div>
                                            <div class="video-stat">💬 <?= $ad['comment_count'] ?></div>
                                            <div class="video-stat">↗️ <?= $ad['share_count'] ?></div>
                                            <?php if ($totalAdVideos > 1): ?>
                                                <div class="video-stat">🎥 <?= $totalAdVideos ?> videos</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination for Videos -->
                <?php if ($totalPagesVideos > 1): ?>
                    <?php 
                    $baseUrlVideos = "search_result3.php?q=" . urlencode($searchQuery) . "&tab=videos";
                    echo generatePagination($page, $totalPagesVideos, $baseUrlVideos);
                    ?>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">🎥</div>
                    <div class="empty-title">No videos found</div>
                    <div class="empty-text">Try different search terms</div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Users Tab Content -->
        <div id="users-tab" class="tab-content <?= $activeTab === 'users' ? 'active' : '' ?>">
            <?php if (!empty($userResults)): ?>
                <div class="user-results">
<?php foreach ($userResults as $user): 
    $userIdToCheck = $user['id'];
    $isFollowing = in_array($userIdToCheck, $followingUserIds);
    

?>
    <div class="user-item" data-user-id="<?= $user['id'] ?>">
        <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" 
             alt="<?= htmlspecialchars($user['username']) ?>" 
             class="user-avatar"
             onclick="location.href='profile.php?id=<?= $user['id'] ?>'">
        <div class="user-info" onclick="location.href='profile.php?id=<?= $user['id'] ?>'">
            <div class="user-username"><?= htmlspecialchars($user['username']) ?></div>
            <div class="user-followers"><?= $user['follower_count'] ?> followers</div>
        </div>
        <div class="user-actions" style="display: flex; flex-direction: column; gap: 5px;">
            <button class="follow-btn <?= $isFollowing ? 'following' : '' ?>" 
                    data-user-id="<?= $user['id'] ?>"
                    style="padding: 8px 12px; font-size: 12px; border-radius: 15px; margin: 0;">
                <?= $isFollowing ? 'Following' : 'Follow' ?>
            </button>
        </div>
    </div>
<?php endforeach; ?>
                
                <!-- Pagination for Users -->
                <?php if ($totalPagesUsers > 1): ?>
                    <?php 
                    $baseUrlUsers = "search_result3.php?q=" . urlencode($searchQuery) . "&tab=users";
                    echo generatePagination($page, $totalPagesUsers, $baseUrlUsers);
                    ?>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">👤</div>
                    <div class="empty-title">No users found</div>
                    <div class="empty-text">Try different search terms</div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Hashtags Tab Content -->
        <div id="hashtags-tab" class="tab-content <?= $activeTab === 'hashtags' ? 'active' : '' ?>">
            <?php if (!empty($hashtagList)): ?>
                <div class="hashtag-results">
                    <?php foreach ($hashtagList as $hashtag): ?>
                        <div class="hashtag-item" onclick="searchHashtag('<?= htmlspecialchars($hashtag['tag']) ?>')">
                            <div class="hashtag-icon">#</div>
                            <div class="hashtag-info">
                                <div class="hashtag-tag">#<?= htmlspecialchars($hashtag['tag']) ?></div>
                                <div class="hashtag-count"><?= $hashtag['post_count'] ?> posts</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination for Hashtags -->
                <?php if ($totalPagesHashtags > 1): ?>
                    <?php 
                    $baseUrlHashtags = "search_result3.php?q=" . urlencode($searchQuery) . "&tab=hashtags";
                    echo generatePagination($page, $totalPagesHashtags, $baseUrlHashtags);
                    ?>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">#️⃣</div>
                    <div class="empty-title">Hashtag Search</div>
                    <div class="empty-text">
                        <?php if ($searchType === 'hashtag'): ?>
                            Search for hashtags like "#<?= htmlspecialchars($cleanQuery) ?>"
                        <?php else: ?>
                            Use # before your search term to find hashtags
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
    <?php endif; ?>
</div>
        <!-- Groups Tab Content -->
        <div id="groups-tab" class="tab-content <?= $activeTab === 'groups' ? 'active' : '' ?>">
            <?php if (!empty($groupResults)): ?>
                <div class="groups-results" id="groupsResults">
                    <?php foreach ($groupResults as $group): 
                        $isMember = ($group['membership_status'] === 'approved');
                        $isPending = ($group['membership_status'] === 'pending');
                        
                        // Build categories string
                        $groupCategories = [];
                        if ($group['category1']) $groupCategories[] = $group['category1'];
                        if ($group['category2']) $groupCategories[] = $group['category2'];
                        if ($group['category3']) $groupCategories[] = $group['category3'];
                        $categoriesText = !empty($groupCategories) ? implode(', ', $groupCategories) : 'No categories';
                        
                        // Check if group requires admin approval
                        $requiresApproval = $group['admin_approval_required'] === true;
                    ?>
                        <div class="group-item" data-group-id="<?= htmlspecialchars($group['id']) ?>" data-requires-approval="<?= $requiresApproval ? 'true' : 'false' ?>">
                            <div class="group-header">
                                <img src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_cover.jpg') ?>" 
                                     alt="Group Cover" 
                                     class="group-cover"
                                     onclick="location.href='group.php?id=<?= $group['id'] ?>'">
                                <img src="<?= htmlspecialchars($group['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                     alt="Group Profile" 
                                     class="group-profile"
                                     onclick="location.href='group.php?id=<?= $group['id'] ?>'">
                            </div>
                            <div class="group-info" onclick="location.href='group.php?id=<?= $group['id'] ?>'">
                                <div class="group-name"><?= htmlspecialchars($group['name']) ?></div>
                                <div class="group-members"><?= $group['member_count'] ?> member<?= $group['member_count'] != 1 ? 's' : '' ?></div>
                                <div class="group-categories"><?= htmlspecialchars($categoriesText) ?></div>
                                <div class="group-description"><?= htmlspecialchars(substr($group['description'] ?? '', 0, 100)) ?>...</div>
                                
                                <?php if ($requiresApproval): ?>
                                    <div class="admin-approval-indicator">Requires admin approval</div>
                                <?php endif; ?>
                            </div>
                            <div class="group-actions">
                                <?php if ($isMember): ?>
                                    <button class="group-btn visited" onclick="location.href='group.php?id=<?= $group['id'] ?>'">Visit</button>
                                <?php elseif ($isPending): ?>
                                    <button class="group-btn pending" disabled>Pending</button>
                                <?php else: ?>
                                    <button class="group-btn join-btn" data-group-id="<?= $group['id'] ?>">Join</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination for Groups -->
                <?php if ($totalPagesGroups > 1): ?>
                    <?php 
                    $baseUrlGroups = "search_result3.php?q=" . urlencode($searchQuery) . "&tab=groups";
                    echo generatePagination($page, $totalPagesGroups, $baseUrlGroups);
                    ?>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">👥</div>
                    <div class="empty-title">No groups found</div>
                    <div class="empty-text">Try different search terms or create a new group</div>
                    <button class="search-again-btn" onclick="location.href='create_group.php'">Create Group</button>
                </div>
            <?php endif; ?>
        </div>

<!-- Results Counter -->
<?php if (!empty($cleanQuery) && $totalResults > 0): ?>
<div class="results-counter">
    Found <strong>
    <?php 
    switch($activeTab) {
        case 'videos': echo $totalVideos . " videos"; break;
        case 'users': echo $totalUsers . " users"; break;
        case 'groups': echo $totalGroups . " groups"; break;
        case 'hashtags': echo $totalHashtags . " hashtags"; break;
        default: echo $totalResults . " results";
    }
    ?>
    </strong> for "<?= htmlspecialchars($searchQuery) ?>"
</div>
<?php endif; ?>

<script>
// ========== COMMENT POPUP VARIABLES ==========
let currentCommentPostId = null;
let currentCommentAdId = null;

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
        
        const response = await fetch('search_result3.php', {
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
            ``
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
        
        const response = await fetch('search_result3.php', {
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
        const response = await fetch('search_result3.php', {
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
        const response = await fetch('search_result3.php', {
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
        
        const response = await fetch('search_result3.php', {
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
        
        const response = await fetch('search_result3.php', {
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
        const response = await fetch('search_result3.php', {
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
        const response = await fetch('search_result3.php', {
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

// ========== FULLSCREEN VIDEO FUNCTIONALITY ==========
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

// ========== FOLLOW BUTTON FUNCTIONALITY ==========
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

    fetch('search_result3.php', {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Follow action failed in background:', err);
        // Don't revert - keep the optimistic UI update
    });
}

// ========== GROUP JOIN FUNCTIONALITY ==========
async function handleGroupJoin(e) {
    e.stopPropagation();
    const button = e.currentTarget;
    const groupCard = button.closest('.group-item');
    const groupId = groupCard.dataset.groupId;
    const currentText = button.textContent.toLowerCase();
    
    // Only process if button says "Join"
    if(currentText !== 'join') return;
    
    // Store original state for reload
    const originalText = button.textContent;
    const originalClasses = button.className;
    
    try {
        const formData = new FormData();
        formData.append('action', 'join_group');
        formData.append('group_id', groupId);
        
        const response = await fetch('search_result3.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if(data.success) {
            if(data.pending) {
                // Set to pending state
                button.textContent = 'Pending';
                button.className = 'group-btn pending';
                button.disabled = true;
            } else {
                // Set to visited state
                button.textContent = 'Visit';
                button.className = 'group-btn visited';
                button.onclick = function() {
                    location.href = `group.php?id=${groupId}`;
                };
            }
        } else {
            // Reset to original state
            button.textContent = originalText;
            button.className = originalClasses;
            button.disabled = false;
        }
    } catch (err) {
        // Reset to original state
        button.textContent = originalText;
        button.className = originalClasses;
        button.disabled = false;
    }
}

// Attach follow button listeners
function attachFollowButtonListeners() {
    document.querySelectorAll('.follow-btn').forEach(button => {
        // Remove existing listeners to prevent duplicates
        button.removeEventListener('click', handleFollowClick);
        // Add new listener
        button.addEventListener('click', handleFollowClick);
    });
}

// Attach group join button listeners
function attachGroupJoinListeners() {
    document.querySelectorAll('.join-btn').forEach(button => {
        // Remove existing listeners to prevent duplicates
        button.removeEventListener('click', handleGroupJoin);
        // Add new listener
        button.addEventListener('click', handleGroupJoin);
    });
}

// Collect all video items
function collectVideoItems() {
    videoItems = Array.from(document.querySelectorAll('.video-item'));
    return videoItems;
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

// Open fullscreen video with TikTok-style controls
function openVideoFullscreen(videoItem, postIndex) {
    const videoGrid = document.getElementById('videoGrid');
    if (!videoGrid) {
        console.error('Video grid not found');
        return;
    }
    
    // Collect all video items if not already collected
    if (videoItems.length === 0) {
        collectVideoItems();
    }
    
    // Store current post index
    currentPostIndex = postIndex - 1; // Convert to 0-based index
    
    const videoItemData = {
        postId: videoItem.getAttribute('data-post-id'),
        adId: videoItem.getAttribute('data-ad-id'),
        mediaUrl: videoItem.getAttribute('data-media-url'),
        likes: parseInt(videoItem.getAttribute('data-likes')) || 0,
        comments: parseInt(videoItem.getAttribute('data-comments')) || 0,
        shares: parseInt(videoItem.getAttribute('data-shares')) || 0,
        liked: videoItem.getAttribute('data-liked') === 'true',
        username: videoItem.getAttribute('data-username'),
        profilePic: videoItem.getAttribute('data-profile-pic'),
        description: videoItem.getAttribute('data-description')
    };
    
    // Set current video data
    currentPostId = videoItemData.postId;
    currentAdId = videoItemData.adId;
    
    // Parse media URLs
    const mediaUrls = videoItemData.mediaUrl ? videoItemData.mediaUrl.split(',').map(url => url.trim()) : [];
    currentVideoList = mediaUrls;
    currentVideoIndexInPost = 0; // Start with first video in post
    
    // Store post/ad data for engagement buttons
    if (currentPostId) {
        currentPostData = {
            likesCount: videoItemData.likes,
            commentsCount: videoItemData.comments,
            sharesCount: videoItemData.shares,
            isLiked: videoItemData.liked,
            username: videoItemData.username,
            profilePic: videoItemData.profilePic,
            description: videoItemData.description
        };
        currentAdData = null;
    } else if (currentAdId) {
        currentAdData = {
            likesCount: videoItemData.likes,
            commentsCount: videoItemData.comments,
            sharesCount: videoItemData.shares,
            isLiked: videoItemData.liked,
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
            <button class="tiktok-action-btn like-btn ${currentPostData.isLiked ? 'liked' : ''}" 
                    onclick="handleFullscreenLike(${currentPostId})">
                <div class="icon">${currentPostData.isLiked ? '❤️' : '🤍'}</div>
                <span class="count">${currentPostData.likesCount}</span>
            </button>
            
            <button class="tiktok-action-btn comment-btn" onclick="openPostComments(${currentPostId})">
                <div class="icon">💬</div>
                <span class="count">${currentPostData.commentsCount}</span>
            </button>
            
            <button class="tiktok-action-btn share-btn" onclick="sharePost(${currentPostId})">
                <div class="icon">↗️</div>
                <span class="count">${currentPostData.sharesCount}</span>
            </button>
            
            <button class="tiktok-action-btn" onclick="toggleMute()" id="muteBtn">
                <div class="icon">🔊</div>
                <span class="count">Sound</span>
            </button>
        `;
    } else if (currentAdId) {
        videoRightControls.innerHTML = `
            <button class="tiktok-action-btn like-btn ${currentAdData.isLiked ? 'liked' : ''}" 
                    onclick="handleFullscreenAdLike(${currentAdId})">
                <div class="icon">${currentAdData.isLiked ? '❤️' : '🤍'}</div>
                <span class="count">${currentAdData.likesCount}</span>
            </button>
            
            <button class="tiktok-action-btn comment-btn" onclick="openAdComments(${currentAdId})">
                <div class="icon">💬</div>
                <span class="count">${currentAdData.commentsCount}</span>
            </button>
            
            <button class="tiktok-action-btn share-btn" onclick="shareAd(${currentAdId})">
                <div class="icon">↗️</div>
                <span class="count">${currentAdData.sharesCount}</span>
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
    
    const videoItemData = {
        postId: newVideoItem.getAttribute('data-post-id'),
        adId: newVideoItem.getAttribute('data-ad-id'),
        mediaUrl: newVideoItem.getAttribute('data-media-url'),
        likes: parseInt(newVideoItem.getAttribute('data-likes')) || 0,
        comments: parseInt(newVideoItem.getAttribute('data-comments')) || 0,
        shares: parseInt(newVideoItem.getAttribute('data-shares')) || 0,
        liked: newVideoItem.getAttribute('data-liked') === 'true',
        username: newVideoItem.getAttribute('data-username'),
        profilePic: newVideoItem.getAttribute('data-profile-pic'),
        description: newVideoItem.getAttribute('data-description')
    };
    
    // Set current video data
    currentPostId = videoItemData.postId;
    currentAdId = videoItemData.adId;
    
    // Parse media URLs
    const mediaUrls = videoItemData.mediaUrl ? videoItemData.mediaUrl.split(',').map(url => url.trim()) : [];
    currentVideoList = mediaUrls;
    currentVideoIndexInPost = 0; // Reset to first video in new post
    
    // Store post/ad data for engagement buttons
    if (currentPostId) {
        currentPostData = {
            likesCount: videoItemData.likes,
            commentsCount: videoItemData.comments,
            sharesCount: videoItemData.shares,
            isLiked: videoItemData.liked,
            username: videoItemData.username,
            profilePic: videoItemData.profilePic,
            description: videoItemData.description
        };
        currentAdData = null;
    } else if (currentAdId) {
        currentAdData = {
            likesCount: videoItemData.likes,
            commentsCount: videoItemData.comments,
            sharesCount: videoItemData.shares,
            isLiked: videoItemData.liked,
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
            <button class="tiktok-action-btn like-btn ${currentPostData.isLiked ? 'liked' : ''}" 
                    onclick="handleFullscreenLike(${currentPostId})">
                <div class="icon">${currentPostData.isLiked ? '❤️' : '🤍'}</div>
                <span class="count">${currentPostData.likesCount}</span>
            </button>
            
            <button class="tiktok-action-btn comment-btn" onclick="openPostComments(${currentPostId})">
                <div class="icon">💬</div>
                <span class="count">${currentPostData.commentsCount}</span>
            </button>
            
            <button class="tiktok-action-btn share-btn" onclick="sharePost(${currentPostId})">
                <div class="icon">↗️</div>
                <span class="count">${currentPostData.sharesCount}</span>
            </button>
            
            <button class="tiktok-action-btn" onclick="toggleMute()" id="muteBtn">
                <div class="icon">🔊</div>
                <span class="count">Sound</span>
            </button>
        `;
    } else if (currentAdId) {
        videoRightControls.innerHTML = `
            <button class="tiktok-action-btn like-btn ${currentAdData.isLiked ? 'liked' : ''}" 
                    onclick="handleFullscreenAdLike(${currentAdId})">
                <div class="icon">${currentAdData.isLiked ? '❤️' : '🤍'}</div>
                <span class="count">${currentAdData.likesCount}</span>
            </button>
            
            <button class="tiktok-action-btn comment-btn" onclick="openAdComments(${currentAdId})">
                <div class="icon">💬</div>
                <span class="count">${currentAdData.commentsCount}</span>
            </button>
            
            <button class="tiktok-action-btn share-btn" onclick="shareAd(${currentAdId})">
                <div class="icon">↗️</div>
                <span class="count">${currentAdData.sharesCount}</span>
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
        const response = await fetch('search_result3.php', {
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
        const response = await fetch('search_result3.php', {
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

// Share ad
async function shareAd(adId) {
    const adUrl = `${window.location.origin}/ad_view.php?id=${adId}`;
    
    if (navigator.share) {
        try {
            await navigator.share({
                title: 'Check out this advertisement',
                url: adUrl
            });
            
            // Record the share
            const formData = new FormData();
            formData.append('action', 'share_ad');
            formData.append('ad_id', adId);
            
            await fetch('search_result3.php', {
                method: 'POST',
                body: formData
            });
            
            // Update share count
            updateAdShareCount(adId);
            
        } catch (err) {
            console.log('Error sharing:', err);
            copyAdToClipboard(adUrl, adId);
        }
    } else {
        copyAdToClipboard(adUrl, adId);
    }
}

// Copy ad to clipboard
async function copyAdToClipboard(text, adId) {
    try {
        await navigator.clipboard.writeText(text);
        alert('Ad link copied to clipboard!');
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share_ad');
        formData.append('ad_id', adId);
        
        await fetch('search_result3.php', {
            method: 'POST',
            body: formData
        });
        
        // Update share count
        updateAdShareCount(adId);
        
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
        
        await fetch('search_result3.php', {
            method: 'POST',
            body: formData
        });
        
        updateAdShareCount(adId);
    }
}

// Update ad share count
function updateAdShareCount(adId) {
    // Update TikTok-style controls
    const tiktokShareBtn = document.querySelector('#videoRightControls .share-btn');
    if (tiktokShareBtn && currentAdData) {
        const countSpan = tiktokShareBtn.querySelector('.count');
        if (countSpan) {
            const currentCount = parseInt(countSpan.textContent) || 0;
            countSpan.textContent = currentCount + 1;
            currentAdData.sharesCount = currentCount + 1;
        }
    }
}

// Close fullscreen on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFullscreenVideo();
        closePostComments();
        closeAdComments();
    }
    
    // Keyboard navigation
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

// ========== SHARE FUNCTIONALITY ==========
async function sharePost(postId) {
    const postUrl = `${window.location.origin}/view_video.php?id=${postId}`;
    
    if (navigator.share) {
        // Use Web Share API if available
        try {
            await navigator.share({
                title: 'Check out this video',
                url: postUrl
            });
            
            // Record the share
            const formData = new FormData();
            formData.append('action', 'share');
            formData.append('post_id', postId);
            
            await fetch('search_result3.php', {
                method: 'POST',
                body: formData
            });
            
        } catch (err) {
            console.log('Error sharing:', err);
            copyToClipboard(postUrl, postId);
        }
    } else {
        // Fallback to clipboard
        copyToClipboard(postUrl, postId);
    }
}

// Copy to clipboard and record share
async function copyToClipboard(text, postId) {
    try {
        await navigator.clipboard.writeText(text);
        alert('Video link copied to clipboard!');
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share');
        formData.append('post_id', postId);
        
        await fetch('search_result3.php', {
            method: 'POST',
            body: formData
        });
        
    } catch (err) {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Video link copied to clipboard!');
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share');
        formData.append('post_id', postId);
        
        await fetch('search_result3.php', {
            method: 'POST',
            body: formData
        });
    }
}

// ========== EXISTING FUNCTIONALITY ==========
let activeTab = '<?= $activeTab ?>';

function switchTab(tabName) {
    activeTab = tabName;
    
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    url.searchParams.set('page', '1'); // Reset to page 1 when switching tabs
    window.history.pushState({}, '', url);
    
    // Update tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Find the clicked tab and activate it
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(tab => {
        if (tab.textContent.toLowerCase().includes(tabName)) {
            tab.classList.add('active');
        }
    });
    
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Re-collect video items when switching to videos tab
    if (tabName === 'videos') {
        setTimeout(() => {
            collectVideoItems();
        }, 100);
    }
    
    // Re-attach follow button listeners when switching to users tab
    if (tabName === 'users') {
        setTimeout(() => {
            attachFollowButtonListeners();
        }, 100);
    }
    
    // Re-attach group join listeners when switching to groups tab
    if (tabName === 'groups') {
        setTimeout(() => {
            attachGroupJoinListeners();
        }, 100);
    }
}

function handleSearchEnter(event) {
    if (event.key === 'Enter') {
        const query = event.target.value.trim();
        if (query) {
            window.location.href = `search_result3.php?q=${encodeURIComponent(query)}&tab=${activeTab}`;
        }
    }
}

function searchHashtag(hashtag) {
    window.location.href = `search_result3.php?q=%23${encodeURIComponent(hashtag)}&tab=videos`;
}

function goBackToSearch() {
    window.location.href = 'search3.php';
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Collect video items
    collectVideoItems();
    
    // Initialize follow buttons
    attachFollowButtonListeners();
    
    // Initialize group join buttons
    attachGroupJoinListeners();
    
    // Like button functionality for grid items
    document.querySelectorAll('.like-btn').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.stopPropagation(); // Prevent triggering video open
            const btn = this;
            const postId = btn.getAttribute('data-post-id');
            if (!postId) return;

            const isLiked = btn.classList.contains('liked');
            const action = isLiked ? 'unlike' : 'like';

            const formData = new FormData();
            formData.append('action', action);
            formData.append('post_id', postId);

            try {
                const response = await fetch('search_result3.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Update like count
                    const countSpan = btn.querySelector('.count');
                    if (countSpan) {
                        countSpan.textContent = `(${data.likes_count})`;
                    }
                    
                    // Toggle liked class
                    btn.classList.toggle('liked');
                    
                    // Add animation effect
                    btn.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        btn.style.transform = 'scale(1)';
                    }, 200);
                } else {
                    alert('Failed to update like status.');
                }
            } catch (err) {
                alert('Error updating like status.');
            }
        });
    });
    
    // Initialize video hover play
    const videoItems = document.querySelectorAll('.video-item');
    
    videoItems.forEach(item => {
        const video = item.querySelector('video');
        if (video) {
            item.addEventListener('mouseenter', () => {
                video.play().catch(e => console.log('Autoplay prevented'));
            });
            
            item.addEventListener('mouseleave', () => {
                video.pause();
                video.currentTime = 0;
            });
            
            item.addEventListener('touchstart', () => {
                video.play().catch(e => console.log('Autoplay prevented'));
            });
        }
    });
});
</script>

</body>
</html>