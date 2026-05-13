<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "footer.php";

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId;

if (!$currentUserId) {
    header('Location: signup.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: profile.php?id=' . $currentUserId);
    exit;
}

$profileUserId = (int)$_GET['id'];

// ========== PAGINATION VARIABLES ==========
$postsPerPage = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $postsPerPage;

// Check if this is an AJAX request for loading more posts
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

// ========== AD ENGAGEMENT FUNCTIONS (FROM PEOPLE.PHP) ==========

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

// ========== ADS TARGETING FUNCTIONS (FROM PEOPLE.PHP) ==========

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
    $_SESSION['profile_shuffled_ads'] = $targetedAds;
    
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

// ========== BOOSTED POSTS LOGIC (FROM PEOPLE.PHP) ==========
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

// TRACK PROFILE VISITS - ADD THIS SECTION
if ($profileUserId !== $currentUserId) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO profile_visits (visitor_id, target_user_id) 
            VALUES (?, ?) 
            ON CONFLICT (visitor_id, target_user_id) 
            DO UPDATE SET visited_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$currentUserId, $profileUserId]);
    } catch (PDOException $e) {
        // Log error but don't break the page
        error_log("Profile visit tracking error: " . $e->getMessage());
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $profileUserId]);
$profileUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profileUser) {
    die("User not found");
}

// ========== FRIEND REQUEST FUNCTIONALITY ==========

// Fetch friend requests and accepted friends info
$pendingStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'pending'");
$pendingStmt->execute([$currentUserId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);

$friendsStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'");
$friendsStmt->execute([$currentUserId]);
$acceptedFriends = $friendsStmt->fetchAll(PDO::FETCH_COLUMN);

$isFriend = false;
if ($currentUserId !== $profileUserId) {
    $friendCheck = $pdo->prepare("SELECT 1 FROM friends WHERE ((user_id = :viewer AND friend_id = :profile) OR (user_id = :profile AND friend_id = :viewer)) AND status = 'accepted'");
    $friendCheck->execute(['viewer' => $currentUserId, 'profile' => $profileUserId]);
    $isFriend = (bool)$friendCheck->fetchColumn();
}

// AJAX handlers for FOLLOW/UNFOLLOW, POST, LIKE, UNLIKE, FRIEND REQUESTS, DELETE POST
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

    // --- FRIEND REQUEST HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'send_request' && isset($_POST['friend_id'])) {
        $friendId = (int)$_POST['friend_id'];
        
        // Check if already friends
        $checkFriendStmt = $pdo->prepare("SELECT 1 FROM friends WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) AND status = 'accepted'");
        $checkFriendStmt->execute([$currentUserId, $friendId, $friendId, $currentUserId]);
        $alreadyFriends = (bool)$checkFriendStmt->fetchColumn();
        
        if ($alreadyFriends) {
            echo json_encode(['success' => false, 'message' => 'You are already friends with this user.']);
            exit;
        }
        
        // Check if request already sent
        $checkRequestStmt = $pdo->prepare("SELECT 1 FROM friends WHERE user_id = ? AND friend_id = ? AND status = 'pending'");
        $checkRequestStmt->execute([$currentUserId, $friendId]);
        $requestExists = (bool)$checkRequestStmt->fetchColumn();
        
        if ($requestExists) {
            echo json_encode(['success' => false, 'message' => 'Friend request already sent.']);
            exit;
        }
        
        // Send friend request
        $stmt = $pdo->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$currentUserId, $friendId]);
        
        echo json_encode(['success' => true, 'message' => 'Friend request sent successfully.']);
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

    // --- DELETE POST HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_post' && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        
        // Verify the post belongs to the current user
        $checkStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
        $checkStmt->execute([$postId]);
        $postOwnerId = $checkStmt->fetchColumn();
        
        if ($postOwnerId == $currentUserId) {
            // Delete the post and related data
            $pdo->beginTransaction();
            try {
                // Delete likes
                $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ?");
                $stmt->execute([$postId]);
                
                // Delete comments
                $stmt = $pdo->prepare("DELETE FROM comments WHERE post_id = ?");
                $stmt->execute([$postId]);
                
                // Delete shares
                $stmt = $pdo->prepare("DELETE FROM shares WHERE post_id = ?");
                $stmt->execute([$postId]);
                
                // Delete the post itself
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
                $stmt->execute([$postId]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Post deleted successfully.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error deleting post.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'You are not authorized to delete this post.']);
        }
        exit;
    }
}

// Fetch if logged in user already follows the profile user
$isFollowing = false;
if ($currentUserId && $profileUserId && $currentUserId !== $profileUserId) {
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ? LIMIT 1");
    $stmt->execute([$currentUserId, $profileUserId]);
    $isFollowing = (bool)$stmt->fetchColumn();
}

$profileLocked = $profileUser['is_profile_locked'] ?? false;
$showFullProfile = !$profileLocked || $isFriend || $currentUserId === $profileUserId;

function fetchUserPosts($pdo, $userId, $postType = null, $limit = null, $offset = 0) {
    $sql = "SELECT * FROM posts WHERE user_id = :user_id";
    $params = ['user_id' => $userId];
    if ($postType !== null) {
        $sql .= " AND post_type = :post_type";
        $params['post_type'] = $postType;
    }
    $sql .= " ORDER BY created_at DESC";
    if ($limit !== null) {
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;
    }
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === 'limit' || $key === 'offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function getLikeCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

function getShareCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shares WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Fetch posts with pagination
$allPosts = fetchUserPosts($pdo, $profileUserId);
$totalPosts = count($allPosts);
$totalPages = ceil($totalPosts / $postsPerPage);
$posts = fetchUserPosts($pdo, $profileUserId, null, $postsPerPage, $offset);

// Fetch videos with pagination
$allVideos = fetchUserPosts($pdo, $profileUserId, 'video');
$totalVideos = count($allVideos);
$totalVideoPages = ceil($totalVideos / $postsPerPage);
$videos = fetchUserPosts($pdo, $profileUserId, 'video', $postsPerPage, $offset);

// If this is an AJAX request, only output the posts and exit
if ($isAjaxRequest) {
    $contentType = $_GET['content_type'] ?? 'posts';
    
    if ($contentType === 'videos') {
        if (empty($videos)) {
            echo '<div class="no-results">No more videos to load.</div>';
            exit;
        }
        
        // Output the videos HTML
        ob_start();
        includeProfileVideos($videos, $pdo, $currentUserId, $profileUser, $targetedAds, $boostedPosts, $page, $showFullProfile);
        $videosHtml = ob_get_clean();
        echo $videosHtml;
    } else {
        if (empty($posts)) {
            echo '<div class="no-results">No more posts to load.</div>';
            exit;
        }
        
        // Output the posts HTML
        ob_start();
        includeProfilePosts($posts, $pdo, $currentUserId, $profileUser, $targetedAds, $boostedPosts, $page, $showFullProfile);
        $postsHtml = ob_get_clean();
        echo $postsHtml;
    }
    exit;
}

$photos = fetchUserPosts($pdo, $profileUserId, 'photo');
$reelsStmt = $pdo->prepare("SELECT * FROM reels WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 50");
$reelsStmt->execute(['user_id' => $profileUserId]);
$reels = $reelsStmt->fetchAll(PDO::FETCH_ASSOC);

$likesStmt = $pdo->prepare("SELECT COUNT(*) FROM likes JOIN posts ON likes.post_id = posts.id WHERE posts.user_id = :profile");
$likesStmt->execute(['profile' => $profileUserId]);
$likesCount = (int)$likesStmt->fetchColumn();

// Handle profile or cover picture upload if logged-in user owns profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUserId === $profileUserId) {
    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    // Process profile picture if uploaded
    if (
        isset($_FILES['profile_pic']) 
        && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK 
        && in_array($_FILES['profile_pic']['type'], $allowedTypes)
    ) {
        $tmpName = $_FILES['profile_pic']['tmp_name'];
        $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $newName = uniqid('profile_') . '.' . $ext;
        $dest = $uploadDir . $newName;
        if (move_uploaded_file($tmpName, $dest)) {
            $stmt = $pdo->prepare("UPDATE users SET profile_pic_url = :pic WHERE id = :id");
            $stmt->execute(['pic' => 'uploads/' . $newName, 'id' => $profileUserId]);
            $profileUser['profile_pic_url'] = 'uploads/' . $newName;
        }
    }

    // Process cover picture if uploaded
    if (
        isset($_FILES['cover_pic']) 
        && $_FILES['cover_pic']['error'] === UPLOAD_ERR_OK 
        && in_array($_FILES['cover_pic']['type'], $allowedTypes)
    ) {
        $tmpName = $_FILES['cover_pic']['tmp_name'];
        $ext = pathinfo($_FILES['cover_pic']['name'], PATHINFO_EXTENSION);
        $newName = uniqid('cover_') . '.' . $ext;
        $dest = $uploadDir . $newName;
        if (move_uploaded_file($tmpName, $dest)) {
            $stmt = $pdo->prepare("UPDATE users SET cover_pic_url = :pic WHERE id = :id");
            $stmt->execute(['pic' => 'uploads/' . $newName, 'id' => $profileUserId]);
            $profileUser['cover_pic_url'] = 'uploads/' . $newName;
        }
    }
}

// Get followers and following counts
$followersCountStmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE followed_id = :user_id");
$followersCountStmt->execute(['user_id' => $profileUserId]);
$followersCount = $followersCountStmt->fetchColumn();

$followingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = :user_id");
$followingCountStmt->execute(['user_id' => $profileUserId]);
$followingCount = $followingCountStmt->fetchColumn();

// Function to output posts HTML (for both initial load and AJAX)
function includeProfilePosts($posts, $pdo, $currentUserId, $profileUser, $targetedAds, $boostedPosts, $currentPage = 1, $showFullProfile = true) {
    $postCounter = ($currentPage - 1) * 8; // Start counter from appropriate position
    $adCounter = 0;
    $boostCounter = 0;
    
    // SHUFFLE ADS AND BOOSTED POSTS FOR RANDOM DISPLAY
    $allAds = $targetedAds;
    shuffle($allAds);
    
    if (!$showFullProfile): ?>
        <p>Profile is locked. Only friends can view posts.</p>
    <?php else:
        foreach ($posts as $post):
            $postCounter++;
            ?>
            <div class="post" data-post-id="<?= $post['id'] ?>">
                <div class="post-header">
                    <img src="<?= htmlspecialchars($profileUser['profile_pic_url'] ?: 'default_profile.png') ?>"  alt="Profile Pic" />
                    <div class="username"><?= htmlspecialchars($profileUser['username']) ?></div>
                    <?php if ($currentUserId === $profileUser['id']): ?>
                        <div class="post-actions" style="margin-left: auto;">
                            <button class="delete-post-btn" data-post-id="<?= $post['id'] ?>" title="Delete Post">🗑️</button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- ADD POST HEADER TITLE HERE -->
                <?php if (!empty($post['post_header'])): ?>
                    <div class="post-header-title">
                        <h3><?= htmlspecialchars($post['post_header']) ?></h3>
                    </div>
                <?php endif; ?>
                
                <div class="post-content" id="post-content-<?= $post['id'] ?>">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
                <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
                <?php endif; ?>
                <?php if ($post['post_type'] === 'photo' && !empty($post['media_url'])):
                    $mediaArray = explode(',', $post['media_url']);
                    $mediaCount = count($mediaArray);
                    $firstFour = array_slice($mediaArray, 0, 4);
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
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Image" />
                                    <?php if ($index === 3 && $extraCount > 0): ?>
                                        <div class="overlay">+<?= $extraCount ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    $firstVideo = trim($mediaArray[0]);
                    ?>
                    <!-- REGULAR VIDEO POST - USING PEOPLE.PHP STYLE WITH FULLSCREEN -->
                    <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($post['media_url']) ?>', <?= $post['id'] ?>)">
                        <video loop playsinline preload="metadata" class="people-video" muted>
                            <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                        <div class="video-controls">
                            <button class="play-pause">▶️</button>
                        </div>
                    </div>
                <?php elseif ($post['post_type'] === 'link' && !empty($post['media_url'])): ?>
                    <div><a href="<?= htmlspecialchars($post['media_url']) ?>" target="_blank"><?= htmlspecialchars($post['media_url']) ?></a></div>
                <?php endif; ?>
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $currentUserId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                        Like (<span class="like-count"><?= getLikeCount($pdo, $post['id']) ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                        Comment (<?= getCommentCount($pdo, $post['id']) ?>)
                    </span>
                    <span class="share-btn" onclick="sharePost(<?= $post['id'] ?>)">
                        Share (<?= getShareCount($pdo, $post['id']) ?>)
                    </span>
                    <?php if ($profileUser['is_business_account'] ?? false): ?>
                        <button class="boost-btn" onclick="alert('Boost post feature coming soon!')">Boost Post</button>
                    <?php endif; ?>
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
          <div class="post boosted-post" data-post-id="<?= $boostPost['id'] ?>">
            <div class="sponsored-label">Sponsored</div>
            
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
            
            <!-- Boosted post header title -->
            <?php if (!empty($boostPost['post_header'])): ?>
                <div class="post-header-title">
                    <h3><?= htmlspecialchars($boostPost['post_header']) ?></h3>
                </div>
            <?php endif; ?>
            
            <div class="post-content">
              <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
            </div>
            
            <!-- Boosted post media -->
            <?php if ($boostPost['post_type'] === 'video' && !empty($boostPost['media_url'])): ?>
              <?php
              $mediaFiles = explode(',', $boostPost['media_url']);
              $firstVideo = trim($mediaFiles[0]);
              ?>
              <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($boostPost['media_url']) ?>', <?= $boostPost['id'] ?>)">
                <video loop playsinline preload="metadata" class="people-video" muted>
                  <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                  Your browser does not support the video tag.
                </video>
                <div class="video-controls">
                  <button class="play-pause">▶️</button>
                </div>
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
            <div class="actions">
              <button class="like-btn <?= userLikedPost($pdo, $currentUserId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                Like (<span class="like-count"><?= getLikeCount($pdo, $boostPost['id']) ?></span>)
              </button>
              
              <button class="comment-btn" onclick="location.href='comment.php?post_id=<?= $boostPost['id'] ?>'">
                Comment (<?= getCommentCount($pdo, $boostPost['id']) ?>)
              </button>
              
              <button class="share-btn" onclick="sharePost(<?= $boostPost['id'] ?>)">
                Share (<?= getShareCount($pdo, $boostPost['id']) ?>)
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
          <div class="post ad-post" data-ad-id="<?= $ad['id'] ?>">
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
                  <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($ad['media_path']) ?>', 'ad-<?= $ad['id'] ?>')">
                    <video loop playsinline preload="metadata" class="people-video" muted>
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
        
    <?php 
        endforeach; 
    endif; 
}

// Function to output videos HTML with all features
function includeProfileVideos($videos, $pdo, $currentUserId, $profileUser, $targetedAds, $boostedPosts, $currentPage = 1, $showFullProfile = true) {
    $videoCounter = ($currentPage - 1) * 8;
    $adCounter = 0;
    $boostCounter = 0;
    
    // SHUFFLE ADS AND BOOSTED POSTS FOR RANDOM DISPLAY
    $allAds = $targetedAds;
    shuffle($allAds);
    
    if (!$showFullProfile): ?>
        <p>Profile is locked. Only friends can view videos.</p>
    <?php else:
        foreach ($videos as $video):
            $videoCounter++;
            $mediaFiles = explode(',', $video['media_url']);
            $firstVideo = trim($mediaFiles[0]);
            ?>
            <div class="post" data-post-id="<?= $video['id'] ?>">
                <div class="post-header">
                    <img src="<?= htmlspecialchars($profileUser['profile_pic_url'] ?: 'default_profile.png') ?>"  alt="Profile Pic" />
                    <div class="username"><?= htmlspecialchars($profileUser['username']) ?></div>
                    <?php if ($currentUserId === $profileUser['id']): ?>
                        <div class="post-actions" style="margin-left: auto;">
                            <button class="delete-post-btn" data-post-id="<?= $video['id'] ?>" title="Delete Post">🗑️</button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- VIDEO POST HEADER TITLE -->
                <?php if (!empty($video['post_header'])): ?>
                    <div class="post-header-title">
                        <h3><?= htmlspecialchars($video['post_header']) ?></h3>
                    </div>
                <?php endif; ?>
                
                <div class="post-content" id="post-content-<?= $video['id'] ?>">
                    <?= nl2br(htmlspecialchars($video['content'])) ?>
                </div>
                <?php if (mb_strlen(strip_tags($video['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="<?= $video['id'] ?>">Show More</button>
                <?php endif; ?>
                
                <!-- REGULAR VIDEO POST - USING PEOPLE.PHP STYLE WITH FULLSCREEN -->
                <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($video['media_url']) ?>', <?= $video['id'] ?>)">
                    <video loop playsinline preload="metadata" class="people-video" muted>
                        <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                    <div class="video-controls">
                        <button class="play-pause">▶️</button>
                    </div>
                </div>
                
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $currentUserId, $video['id']) ? 'liked' : '' ?>" data-post-id="<?= $video['id'] ?>">
                        Like (<span class="like-count"><?= getLikeCount($pdo, $video['id']) ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $video['id'] ?>'">
                        Comment (<?= getCommentCount($pdo, $video['id']) ?>)
                    </span>
                    <span class="share-btn" onclick="sharePost(<?= $video['id'] ?>)">
                        Share (<?= getShareCount($pdo, $video['id']) ?>)
                    </span>
                    <?php if ($profileUser['is_business_account'] ?? false): ?>
                        <button class="boost-btn" onclick="alert('Boost video feature coming soon!')">Boost Post</button>
                    <?php endif; ?>
                </div>
            </div>

        <?php
        // Insert boosted posts every 6 videos
        if ($videoCounter % 6 === 0 && count($boostedPosts) > 0):
          $randomBoostIndex = array_rand($boostedPosts);
          $boostPost = $boostedPosts[$randomBoostIndex];
          $boostCounter++;
        ?>
          <!-- BOOSTED POST IN VIDEOS TAB -->
          <div class="post boosted-post" data-post-id="<?= $boostPost['id'] ?>">
            <div class="sponsored-label">Sponsored</div>
            
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
            
            <?php if (!empty($boostPost['post_header'])): ?>
                <div class="post-header-title">
                    <h3><?= htmlspecialchars($boostPost['post_header']) ?></h3>
                </div>
            <?php endif; ?>
            
            <div class="post-content">
              <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
            </div>
            
            <?php if ($boostPost['post_type'] === 'video' && !empty($boostPost['media_url'])): ?>
              <?php
              $mediaFiles = explode(',', $boostPost['media_url']);
              $firstVideo = trim($mediaFiles[0]);
              ?>
              <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($boostPost['media_url']) ?>', <?= $boostPost['id'] ?>)">
                <video loop playsinline preload="metadata" class="people-video" muted>
                  <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                  Your browser does not support the video tag.
                </video>
                <div class="video-controls">
                  <button class="play-pause">▶️</button>
                </div>
              </div>
            <?php endif; ?>

            <div class="actions">
              <button class="like-btn <?= userLikedPost($pdo, $currentUserId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                Like (<span class="like-count"><?= getLikeCount($pdo, $boostPost['id']) ?></span>)
              </button>
              
              <button class="comment-btn" onclick="location.href='comment.php?post_id=<?= $boostPost['id'] ?>'">
                Comment (<?= getCommentCount($pdo, $boostPost['id']) ?>)
              </button>
              
              <button class="share-btn" onclick="sharePost(<?= $boostPost['id'] ?>)">
                Share (<?= getShareCount($pdo, $boostPost['id']) ?>)
              </button>
            </div>
          </div>
        <?php endif; ?>

        <?php
        // Insert ads every 4 videos
        if ($videoCounter % 4 === 0 && count($allAds) > 0):
          $randomAdIndex = array_rand($allAds);
          $ad = $allAds[$randomAdIndex];
          $adCounter++;
        ?>
          <!-- ADVERTISEMENT IN VIDEOS TAB -->
          <div class="post ad-post" data-ad-id="<?= $ad['id'] ?>">
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

            <?php if (!empty($ad['media_path'])): ?>
              <div class="ad-media-container">
                <?php if ($ad['ad_type'] === 'video'): ?>
                  <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($ad['media_path']) ?>', 'ad-<?= $ad['id'] ?>')">
                    <video loop playsinline preload="metadata" class="people-video" muted>
                      <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                      Your browser does not support the video tag.
                    </video>
                    <div class="video-controls">
                      <button class="play-pause">▶️</button>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($ad['url']) ?>" target="_blank" class="cta-button">
              <?= htmlspecialchars($ad['cta_button']) ?>
            </a>

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
        
    <?php 
        endforeach; 
    endif; 
}

require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($profileUser['username']) ?>'s Profile</title>
<style>
/* Add the pagination styles from people.php */
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

/* Delete Post Button Styles */
.delete-post-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 18px;
    padding: 5px;
    border-radius: 50%;
    transition: all 0.3s ease;
    color: #ff4757;
}

.delete-post-btn:hover {
    background: rgba(255, 71, 87, 0.1);
    transform: scale(1.1);
}

.post-actions {
    display: flex;
    gap: 10px;
}

/* Profile Settings Button */
.profile-settings-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 25px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
    margin-left: 10px;
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
}

.profile-settings-btn:hover {
    background: linear-gradient(135deg, #ee5a52, #e74c3c);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(255, 107, 107, 0.6);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #333;
  line-height: 1.6;
  min-height: 100vh;
  max-width: 900px;
  margin: 20px auto;
  padding: 20px;
}

/* ========== FULLSCREEN VIDEO STYLES FROM PEOPLE.PHP ========== */
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

/* Video posts styling - UPDATED for people.php style */
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

/* Responsive adjustments */
@media (max-width: 600px) {
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
    
    .video-post-container video {
        max-height: 400px;
    }
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

.cover-photo {
  width: 100%;
  height: 250px;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(10px);
  position: relative;
  margin-top: 50px;
  border-radius: 15px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.cover-photo img {
  width: 100%;
  height: 250px;
  object-fit: cover;
  border-radius: 15px;
}

.profile-pic-container {
  position: relative;
  width: 150px;
  height: 150px;
  margin: -75px 20px 20px 20px;
  border: 5px solid #7b68ee;
  border-radius: 50%;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(10px);
  display: inline-block;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.profile-pic-container img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.username {
  font-size: 28px;
  font-weight: bold;
  margin-left: 20px;
  vertical-align: top;
  display: inline-block;
  padding-top: 40px;
  color: #7b68ee;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.stats {
  margin-left: 20px;
  margin-top: 10px;
  color: #ffd700;
}

.stats span {
  margin-right: 20px;
  font-size: 14px;
  color: #ffd700;
  font-weight: 500;
}

.profile-stats {
  margin-left: 20px;
  margin-top: 15px;
}

.stat-item {
  text-decoration: none;
  color: #7b68ee;
  margin-right: 25px;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.3s ease;
  padding: 8px 16px;
  border-radius: 20px;
  background: rgba(123, 104, 238, 0.1);
  border: 1px solid rgba(123, 104, 238, 0.2);
}

.stat-item:hover {
  background: rgba(123, 104, 238, 0.2);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.stat-item strong {
  color: #7b68ee;
  font-size: 16px;
}

nav.tabs {
  margin: 25px 0;
  border-bottom: 2px solid rgba(123, 104, 238, 0.3);
  display: flex;
  gap: 15px;
  flex-wrap: wrap;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(10px);
  padding: 15px;
  border-radius: 15px;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

nav.tabs button {
  background: none;
  border: none;
  padding: 12px 24px;
  font-size: 16px;
  cursor: pointer;
  color: #7b68ee;
  border-radius: 10px;
  transition: all 0.3s ease;
  font-weight: 500;
}

nav.tabs button:hover {
  background: rgba(123, 104, 238, 0.1);
  transform: translateY(-2px);
}

nav.tabs button.active {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  color: white;
  font-weight: 600;
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.tab-panel {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  padding: 25px;
  border-radius: 15px;
  min-height: 300px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.post {
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(10px);
  border-radius: 15px;
  box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
  padding: 20px;
  margin-bottom: 25px;
  overflow-wrap: break-word;
  word-wrap: break-word;
  word-break: break-word;
  color: #2d3748;
  border: 1px solid rgba(255, 255, 255, 0.2);
  transition: all 0.3s ease;
}

.post:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.post-header {
  display: flex;
  align-items: center;
  margin-bottom: 15px;
}

.post-header img {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  object-fit: cover;
  cursor: pointer;
  border: 2px solid #7b68ee;
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
}

.post-header .username {
  margin-left: 12px;
  font-weight: bold;
  cursor: pointer;
  color: #7b68ee;
  flex-grow: 1;
  font-size: 16px;
  padding-top: 0;
}

.post-content {
  white-space: pre-wrap;
  max-height: 4.5em;
  overflow: hidden;
  position: relative;
  transition: max-height 0.3s ease;
  margin-bottom: 15px;
  line-height: 1.5;
  color: #4a5568;
}

.post-content.expanded {
  max-height: none;
}

.show-more-btn {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  border: none;
  color: white;
  cursor: pointer;
  font-size: 14px;
  padding: 8px 16px;
  margin-bottom: 15px;
  user-select: none;
  border-radius: 8px;
  font-weight: 500;
  transition: all 0.3s ease;
}

.show-more-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.post-media {
  margin-bottom: 15px;
}

.post-media .image-count {
  margin-bottom: 12px;
  font-weight: 600;
  font-size: 14px;
  color: #7b68ee;
}

.post-media-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

.post-media-grid div {
  position: relative;
  cursor: pointer;
  border-radius: 12px;
  overflow: hidden;
  height: 160px;
  transition: all 0.3s ease;
  border: 2px solid rgba(255, 255, 255, 0.8);
}

.post-media-grid div:hover {
  transform: scale(1.05);
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
}

.post-media-grid img {
  width: 100%;
  height: 160px;
  object-fit: cover;
  display: block;
  border-radius: 10px;
}

.overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 160px;
  background: linear-gradient(135deg, rgba(123, 104, 238, 0.8), rgba(106, 90, 205, 0.8));
  color: white;
  font-size: 24px;
  font-weight: bold;
  text-align: center;
  line-height: 160px;
  border-radius: 10px;
}

.actions {
  display: flex;
  gap: 20px;
  font-size: 14px;
  align-items: center;
  padding-top: 15px;
  border-top: 1px solid rgba(123, 104, 238, 0.1);
}

.actions span, .actions button {
  cursor: pointer;
  color: #7b68ee;
  user-select: none;
  font-weight: 500;
  transition: all 0.3s ease;
  padding: 8px 16px;
  border-radius: 8px;
  background: rgba(123, 104, 238, 0.1);
  border: 1px solid rgba(123, 104, 238, 0.2);
}

.actions span:hover, .actions button:hover {
  background: rgba(123, 104, 238, 0.2);
  transform: translateY(-2px);
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
}

.liked {
  font-weight: bold;
  color: #ff4757;
  background: rgba(255, 71, 87, 0.1) !important;
  border-color: rgba(255, 71, 87, 0.3) !important;
}

.boost-btn {
  background: linear-gradient(135deg, #48bb78, #38a169);
  color: white;
  padding: 8px 18px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 3px 10px rgba(72, 187, 120, 0.3);
}

.boost-btn:hover {
  background: linear-gradient(135deg, #38a169, #2f855a);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

.video-reel-container {
  position: relative;
  width: 100%;
  margin-bottom: 15px;
  overflow: hidden;
  border-radius: 15px;
  background: rgba(0, 0, 0, 0.1);
  border: 2px solid rgba(255, 255, 255, 0.3);
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
  max-height: 500px;
  object-fit: contain;
  background: #000;
  border-radius: 12px;
}

.video-controls {
  position: absolute;
  bottom: 20px;
  right: 20px;
  z-index: 10;
}

.sound-toggle {
  background: rgba(123, 104, 238, 0.8);
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
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
}

.sound-toggle:hover {
  background: rgba(123, 104, 238, 1);
  transform: scale(1.1);
}

.video-count-indicator {
  position: absolute;
  top: 15px;
  right: 15px;
  background: rgba(123, 104, 238, 0.8);
  color: white;
  padding: 6px 12px;
  border-radius: 15px;
  font-size: 12px;
  font-weight: bold;
  backdrop-filter: blur(10px);
}

.video-pagination {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-top: 15px;
  padding: 10px;
}

.video-pagination-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: rgba(123, 104, 238, 0.3);
  cursor: pointer;
  transition: all 0.3s ease;
  border: 2px solid rgba(123, 104, 238, 0.5);
}

.video-pagination-dot.active {
  background: #7b68ee;
  transform: scale(1.2);
  box-shadow: 0 0 10px rgba(123, 104, 238, 0.6);
}

.follow-btn {
  padding: 10px 24px;
  border-radius: 25px;
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  color: white;
  border: none;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.3s ease;
  user-select: none;
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
  font-size: 14px;
  margin-right: 10px;
}

.follow-btn:hover:not(.following) {
  background: linear-gradient(135deg, #6a5acd, #5d4fbb);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

.follow-btn.following {
  background: linear-gradient(135deg, #718096, #4a5568);
  box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.2);
  color: #e2e8f0;
  cursor: default;
}

/* Add Friend Button Styles */
.add-friend-btn {
  padding: 10px 20px;
  border: none;
  border-radius: 20px;
  cursor: pointer;
  font-size: 14px;
  white-space: nowrap;
  font-weight: 600;
  transition: all 0.3s ease;
  margin-right: 10px;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
}

.add-friend-btn:not(.pending):not(.already-friends) {
  background: linear-gradient(135deg, #48bb78, #38a169);
  color: white;
}

.add-friend-btn:not(.pending):not(.already-friends):hover {
  background: linear-gradient(135deg, #38a169, #2f855a);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

.add-friend-btn.pending {
  background: linear-gradient(135deg, #ed8936, #dd6b20);
  color: white;
  cursor: default;
}

.add-friend-btn.already-friends {
  background: linear-gradient(135deg, #718096, #4a5568);
  color: white;
  cursor: default;
}

.btn {
  padding: 10px 20px;
  border-radius: 10px;
  color: white;
  text-decoration: none;
  display: inline-block;
  font-weight: 600;
  transition: all 0.3s ease;
  border: none;
  cursor: pointer;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
}

.btn-primary {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
}

.btn-primary:hover {
  background: linear-gradient(135deg, #6a5acd, #5d4fbb);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.btn-secondary {
  background: linear-gradient(135deg, #718096, #4a5568);
  margin-left: 10px;
}

.btn-secondary:hover {
  background: linear-gradient(135deg, #4a5568, #2d3748);
  transform: translateY(-2px);
}

.user-categories {
  margin-left: 20px;
  margin-top: 10px;
}

.user-categories span {
  background: linear-gradient(135deg, #7b68ee, #9370db);
  color: white;
  padding: 8px 18px;
  border-radius: 25px;
  font-size: 13px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: inline-block;
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
  border: 2px solid rgba(255, 255, 255, 0.3);
}

form {
  margin: 25px 0;
  padding: 25px;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(10px);
  border-radius: 15px;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

form div {
  margin-bottom: 20px;
}

label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #7b68ee;
  font-size: 14px;
}

input[type="file"] {
  width: 100%;
  padding: 12px;
  border: 2px dashed rgba(123, 104, 238, 0.3);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.8);
  transition: all 0.3s ease;
}

input[type="file"]:focus {
  outline: none;
  border-color: #7b68ee;
  box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
}

form button[type="submit"] {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

form button[type="submit"]:hover {
  background: linear-gradient(135deg, #6a5acd, #5d4fbb);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

.follow-feedback {
  position: fixed;
  top: 20px;
  right: 20px;
  background: linear-gradient(135deg, #48bb78, #38a169);
  color: white;
  padding: 15px 25px;
  border-radius: 10px;
  z-index: 10000;
  font-weight: bold;
  box-shadow: 0 8px 25px rgba(72, 187, 120, 0.4);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  transition: all 0.3s ease;
}

.friend-request-feedback {
  position: fixed;
  top: 20px;
  right: 20px;
  background: linear-gradient(135deg, #007bff, #0056b3);
  color: white;
  padding: 15px 25px;
  border-radius: 10px;
  z-index: 10000;
  font-weight: bold;
  box-shadow: 0 8px 25px rgba(0, 123, 255, 0.4);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  transition: all 0.3s ease;
}

.delete-feedback {
  position: fixed;
  top: 20px;
  right: 20px;
  background: linear-gradient(135deg, #ff4757, #ff3838);
  color: white;
  padding: 15px 25px;
  border-radius: 10px;
  z-index: 10000;
  font-weight: bold;
  box-shadow: 0 8px 25px rgba(255, 71, 87, 0.4);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  transition: all 0.3s ease;
}

/* Post Header Title Styling */
.post-header-title {
    margin: 15px 0 12px 0;
    padding: 0 5px;
    border-left: 4px solid #7b68ee;
    background: rgba(123, 104, 238, 0.05);
    border-radius: 0 8px 8px 0;
    padding: 12px 15px;
}

.post-header-title h3 {
    font-size: 20px;
    font-weight: 700;
    color: #2d3748;
    margin: 0;
    line-height: 1.3;
    word-wrap: break-word;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

/* For boosted posts - slightly different styling */
.boosted-post .post-header-title h3 {
    color: #1a365d;
    font-size: 22px;
}

@media (max-width: 768px) {
  body {
    padding: 15px;
    margin: 10px auto;
  }
  
  .cover-photo {
    height: 200px;
    margin-top: 40px;
  }
  
  .cover-photo img {
    height: 200px;
  }
  
  .profile-pic-container {
    width: 120px;
    height: 120px;
    margin: -60px 15px 15px 15px;
  }
  
  .username {
    font-size: 24px;
    padding-top: 30px;
    margin-left: 15px;
  }
  
  nav.tabs {
    padding: 12px;
    gap: 10px;
  }
  
  nav.tabs button {
    padding: 10px 18px;
    font-size: 14px;
  }
  
  .tab-panel {
    padding: 20px;
  }
  
  .post {
    padding: 15px;
  }
  
  .post-media-grid {
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }
  
  .post-media-grid div, .overlay {
    height: 140px;
    line-height: 140px;
  }
  
  .video-reel-item video {
    max-height: 400px;
  }
  
  .actions {
    gap: 15px;
    flex-wrap: wrap;
  }
  
  .actions span, .actions button {
    padding: 6px 12px;
    font-size: 13px;
  }
  
  .follow-btn, .add-friend-btn {
    padding: 8px 16px;
    font-size: 13px;
    margin-right: 8px;
  }
  
  .post-header-title {
      margin: 12px 0 10px 0;
      padding: 10px 12px;
  }
  
  .post-header-title h3 {
      font-size: 18px;
  }
  
  .boosted-post .post-header-title h3 {
      font-size: 20px;
  }
}

@media (max-width: 480px) {
  body {
    padding: 10px;
  }
  
  .cover-photo {
    height: 180px;
    margin-top: 30px;
  }
  
  .cover-photo img {
    height: 180px;
  }
  
  .profile-pic-container {
    width: 100px;
    height: 100px;
    margin: -50px 10px 10px 10px;
  }
  
  .username {
    font-size: 20px;
    padding-top: 25px;
    margin-left: 10px;
  }
  
  .stats span {
    margin-right: 15px;
    font-size: 13px;
  }
  
  .stat-item {
    margin-right: 15px;
    padding: 6px 12px;
    font-size: 12px;
  }
  
  nav.tabs {
    padding: 10px;
    gap: 8px;
  }
  
  nav.tabs button {
    padding: 8px 14px;
    font-size: 13px;
  }
  
  .tab-panel {
    padding: 15px;
  }
  
  .post {
    padding: 12px;
  }
  
  .post-media-grid div, .overlay {
    height: 120px;
    line-height: 120px;
  }
  
  .video-reel-item video {
    max-height: 350px;
  }
  
  .follow-btn, .add-friend-btn, .btn {
    padding: 8px 16px;
    font-size: 13px;
  }
  
  form {
    padding: 20px;
  }
  
  .post-header-title {
      margin: 10px 0 8px 0;
      padding: 8px 10px;
      border-left-width: 3px;
  }
  
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

<!-- Full Screen Video Overlay - FROM PEOPLE.PHP -->
<div class="fullscreen-video-overlay" id="fullscreenVideoOverlay">
    <button class="fullscreen-close-btn" onclick="closeFullscreenVideo()">✕</button>
    <div class="fullscreen-video-container">
        <video class="fullscreen-video" id="fullscreenVideo" controls></video>
        <div class="video-counter" id="videoCounter">1/1</div>
        <button class="fullscreen-nav-btn fullscreen-prev-btn" onclick="navigateVideo(-1)">❮</button>
        <button class="fullscreen-nav-btn fullscreen-next-btn" onclick="navigateVideo(1)">❯</button>
    </div>
</div>

<div class="cover-photo">
    <?php if ($profileUser['cover_pic_url']): ?>
        <img src="<?= htmlspecialchars($profileUser['cover_pic_url']) ?>" alt="Cover Photo" />
    <?php else: ?>
        <div style="width:100%; height:100%; background:#2c2c3d;"></div>
    <?php endif; ?>
</div>

<div class="profile-pic-container">
    <?php if ($profileUser['profile_pic_url']): ?>
        <img src="<?= htmlspecialchars($profileUser['profile_pic_url']) ?>" alt="Profile Picture" />
    <?php else: ?>
        <div style="width:100%; height:100%; background:#2c2c3d;"></div>
    <?php endif; ?>
</div>

<div class="username"><?= htmlspecialchars($profileUser['username']) ?></div>

<div class="profile-stats">
  <a href="followers.php?id=<?= htmlspecialchars($profileUserId) ?>" class="stat-item">
    <strong><?= htmlspecialchars($followersCount) ?></strong> Followers
  </a>
  <a href="following.php?id=<?= htmlspecialchars($profileUserId) ?>" class="stat-item">
    <strong><?= htmlspecialchars($followingCount) ?></strong> Following
  </a>
</div>

<div class="stats">
    <span>Likes: <?= htmlspecialchars($likesCount ?? 0) ?></span>
</div>

<?php if ($currentUserId === $profileUserId): ?>
    <!-- Profile Settings Button - Only visible to profile owner -->
    <a href="profile_settings.php" class="profile-settings-btn">Profile Settings</a>
<?php endif; ?>

<?php if ($currentUserId !== $profileUserId): ?>
    <!-- Message Button -->
    <a href="send_direct_message.php?id=<?= $profileUserId ?>" id="specialButton" style="padding:8px 15px; background:#007bff; color:#fff; border:none; border-radius:5px; cursor:pointer; text-decoration: none; display: inline-block; margin-right: 10px;">
        Message
    </a>    
    
    <!-- Follow Button -->
    <?php if ($currentUserId && $profileUserId && $currentUserId !== $profileUserId): ?>
        <button id="followBtn" class="follow-btn <?= $isFollowing ? 'following' : '' ?>"
            data-profile-id="<?= $profileUserId ?>"
            data-action="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
            <?= $isFollowing ? 'Following' : 'Follow' ?>
        </button>
    <?php endif; ?>
    
    <!-- Add Friend Button -->
    <?php
    $userIdToCheck = $profileUserId;
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
    ?>
    <button class="<?= $btnClass ?>" <?= $disabled ?> data-user-id="<?= $profileUserId ?>">
        <?= $btnText ?>
    </button>

<!-- Bio Button -->
<a href="bio.php?id=<?= htmlspecialchars($profileUserId) ?>" 
   class="btn btn-primary"
   style="padding:8px 16px; border-radius:6px; background-color:#007bff; color:white; text-decoration:none; display:inline-block;">
  View Bio
</a>

<?php if ($currentUserId === $profileUserId): ?>
  <a href="bio.php?id=<?= htmlspecialchars($profileUserId) ?>#edit" 
     class="btn btn-secondary"
     style="padding:8px 16px; border-radius:6px; background-color:#6c757d; color:white; text-decoration:none; margin-left:10px; display:inline-block;">
    Edit Bio
  </a>
<?php endif; ?>
   
<!-- ADD USER CATEGORY SECTION HERE -->
<?php
// Build categories string from category1 and category2
$userCategories = [];
if (!empty($profileUser['category1'])) {
    $userCategories[] = $profileUser['category1'];
}
if (!empty($profileUser['category2'])) {
    $userCategories[] = $profileUser['category2'];
}

$categoriesText = !empty($userCategories) ? implode(', ', $userCategories) : 'No categories';
?>

<!-- Display User Category -->
<div class="user-categories" style="margin-left: 20px; margin-top: 5px;">
    <span style="
        background: linear-gradient(45deg, #7b68ee, #9370db);
        color: white;
        padding: 6px 15px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
        box-shadow: 0 2px 8px rgba(123, 104, 238, 0.3);
    ">
        📊 <?= htmlspecialchars($categoriesText) ?>
    </span>
</div>

<?php endif; ?>
<?php if ($currentUserId === $profileUserId): ?>
<form method="POST" enctype="multipart/form-data" style="margin: 20px 0;">
    <div>
        <label style="color: #7b68ee;"><strong>Update Profile Picture</strong></label><br/>
        <input type="file" name="profile_pic" accept="image/*" />
    </div>
    <div style="margin-top: 10px;">
        <label style="color: #7b68ee;"><strong>Update Cover Photo</strong></label><br/>
        <input type="file" name="cover_pic" accept="image/*" />
    </div>
    <button type="submit" style="margin-top: 10px; padding: 8px 16px; background:#007bff; color:#fff; border:none; border-radius:6px; cursor:pointer;">Upload</button>
</form>
<?php endif; ?>

<nav class="tabs">
    <button id="posts-btn" onclick="showTab('posts')" class="active">Posts</button>
    <button id="photos-btn" onclick="showTab('photos')">Photos</button>
    <button id="videos-btn" onclick="showTab('videos')">Videos</button>
    <button id="reels-btn" onclick="showTab('reels')">Reels</button>
</nav>

<div id="posts" class="tab-panel">
    <div id="posts-list">
        <?php includeProfilePosts($posts, $pdo, $currentUserId, $profileUser, $targetedAds, $boostedPosts, $page, $showFullProfile); ?>
    </div>
    
    <!-- Loading Spinner -->
    <div id="loading-spinner" class="loading-spinner">
        <div class="spinner"></div>
        <p>Loading more posts...</p>
    </div>
    
    <!-- End of Posts Message -->
    <div id="end-of-posts" class="no-results" style="display: <?= $page >= $totalPages ? 'block' : 'none' ?>;">
        <p>No more posts to load.</p>
    </div>
</div>

<div id="photos" class="tab-panel" style="display:none;">
    <?php if (!$showFullProfile): ?>
        <p>Photos are hidden due to profile privacy.</p>
    <?php else:
        if (empty($photos)) echo "<p>No photos found.</p>";
        foreach ($photos as $photo):
            $mediaFiles = explode(',', $photo['media_url']);
            $mediaCount = count($mediaFiles);
            $firstFour = array_slice($mediaFiles, 0, 4);
            $extraCount = $mediaCount - 4;
            ?>
            <div class="post">
                <div class="image-count"><?= $mediaCount ?> image<?= $mediaCount > 1 ? 's' : '' ?></div>
                <div class="post-media-grid">
                    <?php foreach ($firstFour as $index => $image):
                        $image = trim($image);
                        ?>
                        <div onclick="<?php
                            if ($index < 3) {
                                echo "window.location='full_image.php?img=" . urlencode($image) . "'";
                            } elseif ($extraCount > 0 && $index === 3) {
                                echo "window.location='image_list.php?post_id=" . $photo['id'] . "'";
                            } else {
                                echo "window.location='full_image.php?img=" . urlencode($image) . "'";
                            }
                        ?>">
                            <img src="<?= htmlspecialchars($image) ?>" alt="Photo" />
                            <?php if ($index === 3 && $extraCount > 0): ?>
                                <div class="overlay">+<?= $extraCount ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($profileUser['is_business_account'] ?? false): ?>
                    <button class="boost-btn" onclick="alert('Boost photo feature coming soon!')">Boost Post</button>
                <?php endif; ?>
            </div>
    <?php 
        endforeach;
    endif; ?>
</div>

<div id="videos" class="tab-panel" style="display:none;">
    <div id="videos-list">
        <?php includeProfileVideos($videos, $pdo, $currentUserId, $profileUser, $targetedAds, $boostedPosts, $page, $showFullProfile); ?>
    </div>
    
    <!-- Loading Spinner for Videos -->
    <div id="loading-spinner-videos" class="loading-spinner">
        <div class="spinner"></div>
        <p>Loading more videos...</p>
    </div>
    
    <!-- End of Videos Message -->
    <div id="end-of-videos" class="no-results" style="display: <?= $page >= $totalVideoPages ? 'block' : 'none' ?>;">
        <p>No more videos to load.</p>
    </div>
</div>

<div id="reels" class="tab-panel" style="display:none;">
    <?php if (!$showFullProfile): ?>
        <p>Reels are hidden due to profile privacy.</p>
    <?php else:
        if (empty($reels)) echo "<p>No reels found.</p>";
        foreach ($reels as $reel): ?>
            <div class="post">
                <!-- REELS VIDEO - USING PEOPLE.PHP STYLE WITH FULLSCREEN -->
                <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($reel['media_url']) ?>', 'reel-<?= $reel['id'] ?>')">
                    <video loop playsinline preload="metadata" class="people-video" muted>
                        <source src="<?= htmlspecialchars($reel['media_url']) ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                    <div class="video-controls">
                        <button class="play-pause">▶️</button>
                    </div>
                </div>
                <div class="post-content"><?= nl2br(htmlspecialchars($reel['description'])) ?></div>
                <div class="actions">
                    <span>❤️ <?= getLikeCount($pdo, $reel['id']) ?></span>
                    <?php if ($profileUser['is_business_account'] ?? false): ?>
                        <button class="boost-btn" onclick="alert('Boost reel feature coming soon!')">Boost Post</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; 
    endif; ?>
</div>

<script>
// ========== PAGINATION VARIABLES ==========
let currentPage = <?= $page ?>;
let currentVideoPage = <?= $page ?>;
let isLoading = false;
let isLoadingVideos = false;
let hasMorePosts = <?= $page < $totalPages ? 'true' : 'false' ?>;
let hasMoreVideos = <?= $page < $totalVideoPages ? 'true' : 'false' ?>;
const postsPerPage = 8;
let currentTab = 'posts';

// ========== INFINITE SCROLL FUNCTIONALITY ==========
function initInfiniteScroll() {
    window.addEventListener('scroll', handleScroll);
}

function handleScroll() {
    if (isLoading || isLoadingVideos) return;
    
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const windowHeight = window.innerHeight;
    const documentHeight = document.documentElement.scrollHeight;
    
    // Load more when 100px from bottom
    if (scrollTop + windowHeight >= documentHeight - 100) {
        if (currentTab === 'posts' && hasMorePosts) {
            loadMorePosts();
        } else if (currentTab === 'videos' && hasMoreVideos) {
            loadMoreVideos();
        }
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
        
        const response = await fetch(`profile.php?${urlParams.toString()}`, {
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

async function loadMoreVideos() {
    if (isLoadingVideos || !hasMoreVideos) return;
    
    isLoadingVideos = true;
    const loadingSpinner = document.getElementById('loading-spinner-videos');
    const endOfVideos = document.getElementById('end-of-videos');
    
    // Show loading spinner
    loadingSpinner.style.display = 'block';
    
    try {
        currentVideoPage++;
        
        // Create URL with current parameters
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', currentVideoPage);
        urlParams.set('content_type', 'videos');
        
        const response = await fetch(`profile.php?${urlParams.toString()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const newVideosHtml = await response.text();
        
        if (newVideosHtml.includes('no-results') || newVideosHtml.trim() === '') {
            // No more videos to load
            hasMoreVideos = false;
            endOfVideos.style.display = 'block';
        } else {
            // Append new videos
            const videosList = document.getElementById('videos-list');
            videosList.insertAdjacentHTML('beforeend', newVideosHtml);
            
            // Re-attach event listeners to new videos
            attachEventListenersToNewPosts();
        }
    } catch (error) {
        console.error('Error loading more videos:', error);
        currentVideoPage--; // Revert page on error
    } finally {
        isLoadingVideos = false;
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
    
    // Attach show more button listeners to new posts
    document.querySelectorAll('.show-more-btn').forEach(btn => {
        if (!btn.hasAttribute('data-listener-attached')) {
            btn.setAttribute('data-listener-attached', 'true');
            btn.addEventListener('click', function() {
                const postId = this.dataset.postId;
                const contentDiv = document.getElementById('post-content-' + postId);
                if(contentDiv.classList.contains('expanded')) {
                    contentDiv.classList.remove('expanded');
                    this.textContent = 'Show More';
                } else {
                    contentDiv.classList.add('expanded');
                    this.textContent = 'Show Less';
                }
            });
        }
    });
    
// Attach ad like button listeners with the new optimistic approach
document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
    if (!button.hasAttribute('data-listener-attached')) {
        button.setAttribute('data-listener-attached', 'true');
        button.addEventListener('click', handleAdLike);
    }
});
    
    // Attach delete button listeners to new posts
    document.querySelectorAll('.delete-post-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleDeletePost);
        }
    });
    
    // Initialize video controls for new posts
    initVideoControls();
}

// ========== DELETE POST FUNCTIONALITY ==========
async function handleDeletePost(e) {
    e.stopPropagation(); // Prevent triggering other click events
    
    const btn = e.currentTarget;
    const postId = btn.getAttribute('data-post-id');
    if (!postId) return;

    // Confirm deletion
    if (!confirm('Are you sure you want to delete this post? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_post');
    formData.append('post_id', postId);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Remove the post from DOM
            const postElement = btn.closest('.post');
            if (postElement) {
                postElement.style.opacity = '0';
                postElement.style.transform = 'translateX(-100px)';
                setTimeout(() => {
                    postElement.remove();
                    showDeleteFeedback('Post deleted successfully!');
                }, 300);
            }
        } else {
            alert(data.message || 'Failed to delete post.');
        }
    } catch (err) {
        alert('Error deleting post.');
    }
}

function showDeleteFeedback(message) {
    // Remove existing feedback if any
    const existingFeedback = document.querySelector('.delete-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    
    // Create feedback element
    const feedback = document.createElement('div');
    feedback.textContent = message;
    feedback.className = 'delete-feedback';
    feedback.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: linear-gradient(135deg, #ff4757, #ff3838);
        color: white;
        padding: 12px 20px;
        border-radius: 5px;
        z-index: 10000;
        font-weight: bold;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        transition: all 0.3s ease;
        opacity: 0;
        transform: translateY(-20px);
    `;
    
    document.body.appendChild(feedback);
    
    // Animate in
    setTimeout(() => {
        feedback.style.opacity = '1';
        feedback.style.transform = 'translateY(0)';
    }, 10);
    
    // Remove after 3 seconds
    setTimeout(() => {
        feedback.style.opacity = '0';
        feedback.style.transform = 'translateY(-20px)';
        setTimeout(() => {
            if (feedback.parentNode) {
                feedback.parentNode.removeChild(feedback);
            }
        }, 300);
    }, 3000);
}

// ========== FULLSCREEN VIDEO FUNCTIONALITY FROM PEOPLE.PHP ==========

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
        
        // Add click event to play/pause button
        const playPauseBtn = video.closest('.video-post-container').querySelector('.play-pause');
        if (playPauseBtn) {
            playPauseBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent triggering the fullscreen
                toggleVideoPlayPause(video.closest('.video-post-container'));
            });
        }
    });
}

function showTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(el => el.style.display = 'none');
    document.getElementById(tab).style.display = 'block';
    document.querySelectorAll('nav.tabs button').forEach(btn => btn.classList.remove('active'));
    document.getElementById(tab + '-btn').classList.add('active');
    
    currentTab = tab;
    
    // Initialize infinite scroll when posts or videos tab is activated
    if (tab === 'posts' || tab === 'videos') {
        initInfiniteScroll();
    }
}

// Like button functionality - SIMPLE VERSION (always update UI)
async function handleLikeClick() {
    const btn = this;
    const postId = btn.getAttribute('data-post-id');
    if (!postId) return;

    const isLiked = btn.classList.contains('liked');
    const action = isLiked ? 'unlike' : 'like';

    // ALWAYS update UI immediately
    const countSpan = btn.querySelector('.like-count');
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

// Follow/Unfollow Toggle functionality - SIMPLE VERSION (always update UI)
document.addEventListener('DOMContentLoaded', () => {
    const followBtn = document.getElementById('followBtn');
    if (!followBtn) return;

    followBtn.addEventListener('click', async function() {
        const profileId = this.getAttribute('data-profile-id');
        const currentAction = this.getAttribute('data-action');
        
        if (!profileId || !currentAction) return;

        // ALWAYS update UI immediately
        if (currentAction === 'follow') {
            // Change to "Following" state immediately
            this.textContent = 'Following';
            this.classList.add('following');
            this.setAttribute('data-action', 'unfollow');
            updateFollowersCount(1);
        } else {
            // Change to "Follow" state immediately
            this.textContent = 'Follow';
            this.classList.remove('following');
            this.setAttribute('data-action', 'follow');
            updateFollowersCount(-1);
        }

        // Visual feedback
        this.style.transform = 'scale(1.05)';
        setTimeout(() => {
            this.style.transform = 'scale(1)';
        }, 200);

        // Send to server in background (fire and forget)
        const formData = new FormData();
        formData.append('action', currentAction);
        formData.append('followed_id', profileId);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).catch(err => {
            console.error('Follow action failed in background:', err);
            // Don't revert - keep the optimistic UI update
        });
    });
});
// Add Friend Button Functionality - SIMPLE VERSION (always update UI)
document.addEventListener('DOMContentLoaded', () => {
    const addFriendBtn = document.querySelector('.add-friend-btn:not(.pending):not(.already-friends)');
    if (!addFriendBtn) return;

    addFriendBtn.addEventListener('click', async function() {
        const userId = this.getAttribute('data-user-id');
        if (!userId) return;

        // ALWAYS update UI immediately
        this.textContent = 'Pending';
        this.classList.add('pending');
        this.disabled = true;

        // Visual feedback
        this.style.transform = 'scale(1.05)';
        setTimeout(() => {
            this.style.transform = 'scale(1)';
        }, 200);

        // Send to server in background (fire and forget)
        const formData = new FormData();
        formData.append('action', 'send_request');
        formData.append('friend_id', userId);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then(response => {
            if (response.ok) {
                showFriendRequestFeedback('Friend request sent successfully!');
            }
        }).catch(err => {
            console.error('Friend request failed in background:', err);
            // Don't revert - keep the optimistic UI update
        });
    });
});

// Function to update followers count dynamically
function updateFollowersCount(change) {
    const followersLinks = document.querySelectorAll('a[href*="followers.php"]');
    followersLinks.forEach(link => {
        const strongTag = link.querySelector('strong');
        if (strongTag) {
            const currentCount = parseInt(strongTag.textContent) || 0;
            const newCount = Math.max(0, currentCount + change);
            strongTag.textContent = newCount;
        }
    });
}

// Function to show temporary feedback for follow actions
function showFeedback(message) {
    // Remove existing feedback if any
    const existingFeedback = document.querySelector('.follow-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    
    // Create feedback element
    const feedback = document.createElement('div');
    feedback.textContent = message;
    feedback.className = 'follow-feedback';
    feedback.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #28a745;
        color: white;
        padding: 12px 20px;
        border-radius: 5px;
        z-index: 10000;
        font-weight: bold;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        transition: all 0.3s ease;
        opacity: 0;
        transform: translateY(-20px);
    `;
    
    document.body.appendChild(feedback);
    
    // Animate in
    setTimeout(() => {
        feedback.style.opacity = '1';
        feedback.style.transform = 'translateY(0)';
    }, 10);
    
    // Remove after 3 seconds
    setTimeout(() => {
        feedback.style.opacity = '0';
        feedback.style.transform = 'translateY(-20px)';
        setTimeout(() => {
            if (feedback.parentNode) {
                feedback.parentNode.removeChild(feedback);
            }
        }, 300);
    }, 3000);
}

// Function to show temporary feedback for friend requests
function showFriendRequestFeedback(message) {
    // Remove existing feedback if any
    const existingFeedback = document.querySelector('.friend-request-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    
    // Create feedback element
    const feedback = document.createElement('div');
    feedback.textContent = message;
    feedback.className = 'friend-request-feedback';
    feedback.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #007bff;
        color: white;
        padding: 12px 20px;
        border-radius: 5px;
        z-index: 10000;
        font-weight: bold;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        transition: all 0.3s ease;
        opacity: 0;
        transform: translateY(-20px);
    `;
    
    document.body.appendChild(feedback);
    
    // Animate in
    setTimeout(() => {
        feedback.style.opacity = '1';
        feedback.style.transform = 'translateY(0)';
    }, 10);
    
    // Remove after 3 seconds
    setTimeout(() => {
        feedback.style.opacity = '0';
        feedback.style.transform = 'translateY(-20px)';
        setTimeout(() => {
            if (feedback.parentNode) {
                feedback.parentNode.removeChild(feedback);
            }
        }, 300);
    }, 3000);
}

// Ad Like button functionality - WITH SUBTLE ERROR FEEDBACK
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

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    }).then(response => {
        if (!response.ok) {
            console.warn('Ad like action failed on server side');
            // Optional: Add subtle visual cue for failure
            btn.style.opacity = '0.7';
            setTimeout(() => { btn.style.opacity = '1'; }, 1000);
        }
    }).catch(err => {
        console.error('Ad like action failed in background:', err);
        // Optional: Add subtle visual cue for network error
        btn.style.opacity = '0.7';
        setTimeout(() => { btn.style.opacity = '1'; }, 1000);
    });
}

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
            
            await fetch('profile.php', {
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
        
        await fetch('profile.php', {
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
        
        await fetch('profile.php', {
            method: 'POST',
            body: formData
        });
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    showTab('posts');
    
    // Initialize video controls for people videos
    initVideoControls();
    
    // Initialize infinite scroll for posts tab
    initInfiniteScroll();
    
    // Attach event listeners to existing posts
    attachEventListenersToNewPosts();
    
    // Attach ad like button listeners
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
        button.addEventListener('click', handleAdLike);
    });
    
    // Attach delete button listeners
    document.querySelectorAll('.delete-post-btn').forEach(button => {
        button.addEventListener('click', handleDeletePost);
    });
});
</script>
<?php
require_once "reload.php";
?>
</body>
</html>