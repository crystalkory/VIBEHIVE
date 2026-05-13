<?php
// Start output buffering at the very beginning
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

// Check if this is an AJAX request for loading more items
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

require_once "footer.php";
require_once "config.php";

$currentUserId = $_SESSION['user_id'];

// ========== PAGINATION VARIABLES ==========
$itemsPerPage = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $itemsPerPage;

// Define categories and post types
$categories = ['All', 'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'];
$postTypes = ['All Posts', 'Video Post', 'Image Post', 'Text Post'];

// Get selected category and post type from GET parameters
$selectedCategory = $_GET['category'] ?? 'All';
$selectedPostType = $_GET['post_type'] ?? 'All Posts';
$activeTab = $_GET['tab'] ?? 'groups';
$searchFilter = trim($_GET['q'] ?? '');

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
    $_SESSION['join_group_shuffled_ads'] = $targetedAds;
    
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

// ========== EXISTING JOIN GROUP LOGIC ==========

// Prepare base query for groups
$sqlBase = "SELECT g.*, 
           (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.status = 'approved') AS member_count,
           (SELECT status FROM group_members gm WHERE gm.group_id = g.id AND gm.user_id = :user_id LIMIT 1) AS membership_status
    FROM groups g
    WHERE g.privacy_setting != 'private'";

$params = ['user_id' => $currentUserId];

// Apply category filter if not 'All'
if ($selectedCategory !== 'All') {
    $sqlBase .= " AND (g.category1 = :category OR g.category2 = :category OR g.category3 = :category)";
    $params['category'] = $selectedCategory;
}

// Apply search filter
if ($searchFilter !== '') {
    $sqlBase .= " AND LOWER(g.name) LIKE :search";
    $params['search'] = '%' . strtolower($searchFilter) . '%';
}

$sqlBase .= " ORDER BY member_count DESC, g.created_at DESC";

$stmt = $pdo->prepare($sqlBase);
$stmt->execute($params);
$allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Paginate groups
$totalGroups = count($allGroups);
$totalGroupPages = ceil($totalGroups / $itemsPerPage);
$groups = array_slice($allGroups, $offset, $itemsPerPage);

// If this is an AJAX request for groups tab, output groups and exit
if ($isAjaxRequest && $activeTab === 'groups') {
    // Clean output buffer and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if (empty($groups)) {
        echo '<div class="no-results">No more groups to load.</div>';
        exit;
    }
    
    // Output the groups HTML
    includeGroups($groups, $targetedAds, $page);
    exit;
}

// Function to get like count for posts
function getLikeCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Function to get comment count for posts
function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Function to get share count for posts
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

// Fetch group posts for posts tab
$postsSql = "SELECT p.*, g.name as group_name, g.profile_pic_url as group_profile_pic, 
                    g.category1, g.category2, g.category3,
                    u.username as author_username, u.id as author_id,
                    (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.status = 'approved') AS member_count
             FROM posts p 
             JOIN groups g ON p.group_id = g.id 
             JOIN users u ON p.user_id = u.id
             WHERE g.privacy_setting != 'private' AND p.group_id IS NOT NULL";

$postsParams = [];

// Apply category filter for posts
if ($selectedCategory !== 'All') {
    $postsSql .= " AND (g.category1 = :category OR g.category2 = :category OR g.category3 = :category)";
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

// Apply search filter to posts tab
if ($searchFilter !== '') {
    $postsSql .= " AND (LOWER(g.name) LIKE :search OR LOWER(p.content) LIKE :search)";
    $postsParams['search'] = '%' . strtolower($searchFilter) . '%';
}

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

// Sort posts by points in descending order
usort($allPosts, function($a, $b) {
    return $b['points'] <=> $a['points'];
});

// Paginate posts
$totalPosts = count($allPosts);
$totalPostPages = ceil($totalPosts / $itemsPerPage);
$posts = array_slice($allPosts, $offset, $itemsPerPage);

// If this is an AJAX request for posts tab, output posts and exit
if ($isAjaxRequest && $activeTab === 'posts') {
    // Clean output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if (empty($posts)) {
        echo '<div class="no-results">No more posts to load.</div>';
        exit;
    }
    
    // Output the posts HTML
    includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $page);
    exit;
}

// Function to check if user liked a post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// Function to output groups HTML
function includeGroups($groups, $targetedAds, $currentPage = 1) {
    $groupCounter = ($currentPage - 1) * 8;
    
    foreach ($groups as $group): 
        $groupCounter++;
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
        <div class="group-card" data-group-id="<?= htmlspecialchars($group['id']) ?>" data-requires-approval="<?= $requiresApproval ? 'true' : 'false' ?>">
            <div class="group-cover">
                <img src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_cover.jpg') ?>" alt="Cover Picture" />
            </div>
            <img class="group-profile" src="<?= htmlspecialchars($group['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" />
            <div class="group-info">
                <div class="group-name"><?= htmlspecialchars($group['name']) ?></div>
                <div class="group-members"><?= $group['member_count'] ?> member<?= $group['member_count'] != 1 ? 's' : '' ?></div>
                <div class="group-categories"><?= htmlspecialchars($categoriesText) ?></div>
                
                <?php if ($requiresApproval): ?>
                    <div class="admin-approval-indicator">Requires admin approval</div>
                <?php endif; ?>
                
                <?php if ($isMember): ?>
                    <button class="join-btn visited">Visit</button>
                <?php elseif ($isPending): ?>
                    <button class="join-btn pending" disabled>Pending</button>
                <?php else: ?>
                    <button class="join-btn">Join</button>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Insert ads every 6 groups
        if ($groupCounter % 6 === 0 && count($targetedAds) > 0):
            $randomAdIndex = array_rand($targetedAds);
            $ad = $targetedAds[$randomAdIndex];
        ?>
            <!-- REGULAR ADVERTISEMENT -->
            <div class="post ad-post" data-ad-id="<?= $ad['id'] ?>" style="width: 100%; max-width: 600px; margin: 20px auto;">
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
                                <video loop playsinline preload="metadata" class="group-video" muted>
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

// Function to output posts HTML
function includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $currentPage = 1) {
    $postCounter = ($currentPage - 1) * 8;
    $adCounter = 0;
    $boostCounter = 0;
    
    // SHUFFLE ADS AND BOOSTED POSTS FOR RANDOM DISPLAY
    $allAds = $targetedAds;
    shuffle($allAds);
    
    foreach ($posts as $post): 
        $postCounter++;
    ?>
        <div class="post-card" data-post-id="<?= $post['id'] ?>">
            <!-- Points Badge -->
            <div class="post-points" title="Points: Likes (<?= $post['like_count'] * 0.3 ?>pts) + Comments (<?= $post['comment_count'] * 0.7 ?>pts) + Shares (<?= $post['share_count'] ?>pts)">
                ⭐ <?= $post['points'] ?> pts
            </div>
            
            <div class="post-header">
                <img src="<?= htmlspecialchars($post['group_profile_pic'] ?: 'default_profile.png') ?>" alt="Group Profile" 
                     onclick="location.href='group.php?id=<?= $post['group_id'] ?>'" />
                <div>
                    <div class="post-group-name" onclick="location.href='group.php?id=<?= $post['group_id'] ?>'">
                        <?= htmlspecialchars($post['group_name']) ?>
                    </div>
                    <div class="post-author">
                        by <a href="profile.php?id=<?= $post['author_id'] ?>"><?= htmlspecialchars($post['author_username']) ?></a>
                    </div>
                    <div class="post-categories">
                        <?php
                        $groupCategories = [];
                        if ($post['category1']) $groupCategories[] = $post['category1'];
                        if ($post['category2']) $groupCategories[] = $post['category2'];
                        if ($post['category3']) $groupCategories[] = $post['category3'];
                        $categoriesText = !empty($groupCategories) ? implode(', ', $groupCategories) : 'No categories';
                        ?>
                        <?= htmlspecialchars($categoriesText) ?> • <?= $post['member_count'] ?> members
                    </div>
                </div>
            </div>
            
            <?php if (!empty($post['content'])): ?>
                <div class="post-content">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
            <?php endif; ?>
            
            <?php 
            // Handle different post types
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
                $videoCount = count($mediaFiles);
                $firstVideo = trim($mediaFiles[0]);
            ?>
                <!-- VIDEO POST -->
                <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($post['media_url']) ?>', <?= $post['id'] ?>)">
                    <video loop playsinline preload="metadata" class="group-video" muted>
                        <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                    <div class="video-controls">
                        <button class="play-pause">▶️</button>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Post Actions -->
            <div class="post-actions">
                <button class="action-btn like-btn <?= userLikedPost($pdo, $currentUserId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                    <span class="icon">❤️</span>
                    Like <span class="count">(<?= $post['like_count'] ?>)</span>
                </button>
                
                <button class="action-btn comment-btn" onclick="openPostComments(<?= $post['id'] ?>)">
                    <span class="icon">💬</span>
                    Comment <span class="count">(<?= $post['comment_count'] ?>)</span>
                </button>
                
                <button class="action-btn share-btn" onclick="shareGroupPost(<?= $post['id'] ?>, <?= $post['group_id'] ?>)">
                    <span class="icon">↗️</span>
                    Share <span class="count">(<?= $post['share_count'] ?>)</span>
                </button>
            </div>
            
            <div class="post-date">
                <?= date('M j, Y g:i A', strtotime($post['created_at'])) ?>
            </div>
        </div>

        <?php
        // Insert boosted posts every 6 posts
        if ($postCounter % 6 === 0 && count($boostedPosts) > 0):
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
                    <div class="user-info">
                        <div class="username" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'">
                            <?= htmlspecialchars($boostPost['username']) ?> (Boosted)
                        </div>
                        <div class="timestamp"><?= date('M j, Y g:i A', strtotime($boostPost['created_at'])) ?></div>
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
                    <div class="video-post-container" onclick="openFullscreenVideo('<?= htmlspecialchars($boostPost['media_url']) ?>', <?= $boostPost['id'] ?>)">
                        <video loop playsinline preload="metadata" class="group-video" muted>
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
                <div class="post-actions">
                    <button class="action-btn like-btn <?= userLikedPost($pdo, $currentUserId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                        <span class="icon">❤️</span>
                        Like <span class="count">(<?= getLikeCount($pdo, $boostPost['id']) ?>)</span>
                    </button>
                    
                    <button class="action-btn comment-btn" onclick="openPostComments(<?= $boostPost['id'] ?>)">
                        <span class="icon">💬</span>
                        Comment <span class="count">(<?= getCommentCount($pdo, $boostPost['id']) ?>)</span>
                    </button>
                    
                    <button class="action-btn share-btn" onclick="shareGroupPost(<?= $boostPost['id'] ?>, null)">
                        <span class="icon">↗️</span>
                        Share <span class="count">(<?= getShareCount($pdo, $boostPost['id']) ?>)</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Insert ads every 4 posts
        if ($postCounter % 4 === 0 && count($allAds) > 0):
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
                                <video loop playsinline preload="metadata" class="group-video" muted>
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

// ========== AJAX HANDLERS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clean output buffer before sending JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // --- GET POST DATA FOR COMMENTS POPUP ---
    if (isset($_POST['action']) && $_POST['action'] === 'get_post_data' && isset($_POST['post_id'])) {
        header('Content-Type: application/json');
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
            
            // Fetch main comments
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
            
            // Get like count
            $likeStmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
            $likeStmt->execute([$postId]);
            $likeCount = $likeStmt->fetchColumn();
            
            // Check if current user liked this post
            $userLikeStmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = ? AND user_id = ?");
            $userLikeStmt->execute([$postId, $userId]);
            $userLiked = (bool)$userLikeStmt->fetchColumn();
            
            // Get TOTAL comment count
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
            
            // Fetch main comments
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
            
            // Get TOTAL comment count
            $totalCommentStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_comments WHERE ad_id = ?");
            $totalCommentStmt->execute([$adId]);
            $totalCommentCount = (int)$totalCommentStmt->fetchColumn();
            
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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

    // --- JOIN GROUP HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'join_group' && isset($_POST['group_id'])) {
        header('Content-Type: application/json');
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

    // --- LIKE/UNLIKE POST HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
        header('Content-Type: application/json');
        $postId = (int)$_POST['post_id'];
        
        if ($_POST['action'] === 'like') {
            $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $stmt->execute([$postId, $currentUserId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$postId, $currentUserId]);
        }
        
        $newLikeCount = getLikeCount($pdo, $postId);
        echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
        exit;
    }

    // --- AD LIKE/UNLIKE HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like_ad', 'unlike_ad']) && isset($_POST['ad_id'])) {
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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

// ========== OUTPUT HTML (ONLY IF NOT AJAX REQUEST) ==========
if (!$isAjaxRequest && !isset($_POST['action'])) {
    // Clean output buffer and output HTML
    ob_end_clean();
    
    require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Join Groups - Fbclone</title>
<style>
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

/* Ad Posts */
.ad-post {
    border: 2px solid #ffd700;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
    position: relative;
    margin-bottom: 20px;
    backdrop-filter: blur(10px);
}

.ad-label {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    color: #000;
    padding: 4px 10px;
    border-radius: 4px;
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
    padding: 10px 20px;
    border: none;
    border-radius: 25px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin: 10px 0;
    transition: all 0.3s ease;
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
    grid-gap: 6px;
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
    font-weight: 600;
}

.ad-action-btn:hover {
    background: rgba(123, 104, 238, 0.1);
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

/* Boosted Posts */
.boosted-post {
    border: 2px solid #28a745;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
    margin-bottom: 20px;
    backdrop-filter: blur(10px);
}

.sponsored-label {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: bold;
    font-size: 12px;
    margin-bottom: 10px;
    display: inline-block;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
}

/* All other existing CSS styles remain exactly the same */
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; 
    max-width: 900px; 
    margin: 20px auto; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
    padding: 15px;
}

h1 { 
    text-align: center; 
    margin-bottom: 25px; 
    color: white;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

/* Tab styling */
.tabs {
    display: flex;
    margin-bottom: 20px;
    border-bottom: 2px solid #7b68ee;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    padding: 5px;
}

.tab {
    padding: 10px 20px;
    cursor: pointer;
    background: none;
    border: none;
    color: #718096;
    font-size: 16px;
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

/* Search and Filter styling */
.filter-container {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.filter-select {
    padding: 8px 12px;
    border: 2px solid #7b68ee;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.95);
    color: #2d3748;
    min-width: 150px;
    transition: all 0.3s ease;
}

.filter-select:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

#search-input {
    padding: 8px 12px;
    border: 2px solid #7b68ee;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.95);
    color: #2d3748;
    min-width: 200px;
    transition: all 0.3s ease;
}

#search-input:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

/* Group list styling */
.group-list { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 20px; 
    justify-content: center; 
}

.group-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    width: 280px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    border: 2px solid #7b68ee;
}

.group-card:hover { 
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(123, 104, 238, 0.3); 
}

.group-cover { 
    height: 140px; 
    overflow: hidden;
    background: rgba(123, 104, 238, 0.1);
}

.group-cover img { 
    width: 100%; 
    height: 140px; 
    object-fit: cover; 
}

.group-profile {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    margin: -35px 20px 10px 20px;
    border: 3px solid #7b68ee;
    object-fit: cover;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.group-info {
    padding: 0 20px 20px 20px;
    flex-grow: 1;
}

.group-name { 
    font-weight: bold; 
    font-size: 18px; 
    margin-bottom: 5px; 
    color: #2d3748;
    font-weight: 700;
}

.group-members { 
    font-size: 14px; 
    color: #718096; 
    margin-bottom: 10px; 
}

.group-categories {
    font-size: 12px;
    color: #718096;
    margin-bottom: 10px;
}

.join-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 8px 18px;
    font-weight: 600;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    align-self: flex-start;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.3);
}

.join-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.4);
}

.join-btn.visited { 
    background: linear-gradient(135deg, #48bb78, #38a169);
    cursor: pointer; 
}

.join-btn.pending {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    cursor: default;
    pointer-events: none;
}

.join-btn:disabled { 
    cursor: default;
    opacity: 0.7; 
}

/* Post styling */
.post-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 2px solid #7b68ee;
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 15px;
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
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.post-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.post-group-name {
    font-weight: bold;
    color: #2d3748;
    cursor: pointer;
    font-weight: 700;
}

.post-author {
    font-size: 12px;
    color: #718096;
}

.post-author a {
    color: #00ff88;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
}

.post-author a:hover {
    text-decoration: underline;
    color: #00cc66;
}

.post-categories {
    font-size: 12px;
    color: #718096;
}

.post-content {
    margin: 10px 0;
    white-space: pre-wrap;
    color: #2d3748;
    line-height: 1.4;
}

.post-media {
    max-width: 100%;
    margin: 10px 0;
}

.post-media img, .post-media video {
    max-width: 100%;
    border-radius: 6px;
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
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

/* Image grid styling */
.post-media-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

.post-media-grid div {
    position: relative;
    cursor: pointer;
    border-radius: 10px;
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
    font-size: 28px;
    font-weight: bold;
    text-align: center;
    line-height: 150px;
    border-radius: 10px;
}

.image-count {
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
    color: #718096;
}

/* Post actions styling */
.post-actions {
    display: flex;
    gap: 20px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
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
    border-radius: 6px;
    transition: all 0.3s ease;
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

.search-info {
    margin-bottom: 15px;
    padding: 10px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 6px;
    border-left: 4px solid #7b68ee;
    color: #2d3748;
}

.no-results {
    text-align: center;
    padding: 40px;
    color: #718096;
    font-style: italic;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    backdrop-filter: blur(10px);
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

.group-card, .post-card {
    animation: fadeInUp 0.6s ease-out;
}

/* Responsive */
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

    body {
        padding: 10px;
    }

    .filter-container {
        flex-direction: column;
        gap: 10px;
    }

    .filter-select, #search-input {
        min-width: 100%;
    }

    .group-list {
        gap: 15px;
    }

    .group-card {
        width: 100%;
        max-width: 350px;
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

    .post-actions {
        flex-direction: column;
        gap: 10px;
    }

    .action-btn {
        width: 100%;
        justify-content: flex-start;
    }
}

@media (max-width: 480px) {
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

    .ad-post-media {
        grid-template-columns: 1fr;
    }

    .group-profile {
        width: 60px;
        height: 60px;
        margin: -30px 15px 10px 15px;
    }

    .group-info {
        padding: 0 15px 15px 15px;
    }
}

@media (min-width: 1200px) {
    body {
        max-width: 1000px;
    }
}
</style>
</head>
<body>

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

<h1 style="margin-top: 50px; color: #7b68ee;">Discover Groups <a href="create_group.php" style="text-decoration: none;color: white;">Create Group</a></h1>

<!-- Tabs -->
<div class="tabs">
    <button class="tab <?= $activeTab === 'groups' ? 'active' : '' ?>" data-tab="groups">Groups</button>
    <button class="tab <?= $activeTab === 'posts' ? 'active' : '' ?>" data-tab="posts">Posts</button>
</div>

<!-- Search and Filters -->
<div class="filter-container">
    <input type="text" id="search-input" placeholder="Search groups or posts..." value="<?= htmlspecialchars($searchFilter) ?>" />
    
    <select id="category-filter" class="filter-select">
        <?php foreach ($categories as $category): ?>
            <option value="<?= htmlspecialchars($category) ?>" 
                    <?= $selectedCategory === $category ? 'selected' : '' ?>>
                <?= htmlspecialchars($category) ?>
            </option>
        <?php endforeach; ?>
    </select>
    
    <select id="post-type-filter" class="filter-select" style="<?= $activeTab === 'groups' ? 'display:none;' : '' ?>">
        <?php foreach ($postTypes as $type): ?>
            <option value="<?= htmlspecialchars($type) ?>" 
                    <?= $selectedPostType === $type ? 'selected' : '' ?>>
                <?= htmlspecialchars($type) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Groups Tab Content -->
<div id="groups-tab" class="tab-content <?= $activeTab === 'groups' ? 'active' : '' ?>">
    <h3>Discover Groups</h3>
    
    <?php if ($searchFilter !== ''): ?>
        <div class="search-info">
            Showing groups for: "<strong><?= htmlspecialchars($searchFilter) ?></strong>"
        </div>
    <?php endif; ?>
    
    <div class="group-list" id="groups-list">
    <?php if (empty($groups)): ?>
        <div class="no-results">
            <?php if ($searchFilter !== ''): ?>
                <p>No groups found matching "<strong><?= htmlspecialchars($searchFilter) ?></strong>"</p>
            <?php else: ?>
                <p>No groups found matching your criteria.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php includeGroups($groups, $targetedAds, $page); ?>
    <?php endif; ?>
    </div>
    
    <!-- Loading Spinner for Groups -->
    <div id="groups-loading-spinner" class="loading-spinner">
        <div class="spinner"></div>
        <p>Loading more groups...</p>
    </div>
    
    <!-- End of Groups Message -->
    <div id="end-of-groups" class="no-results" style="display: none;">
        <p>No more groups to load.</p>
    </div>
</div>

<!-- Posts Tab Content -->
<div id="posts-tab" class="tab-content <?= $activeTab === 'posts' ? 'active' : '' ?>">
    <h3>Group Posts (Ranked by Points)</h3>
    
    <?php if ($searchFilter !== ''): ?>
        <div class="search-info">
            Showing posts from groups and content related to: "<strong><?= htmlspecialchars($searchFilter) ?></strong>"
        </div>
    <?php endif; ?>
    
    <div id="posts-list">
        <?php if (empty($posts) && empty($boostedPosts) && empty($targetedAds)): ?>
            <div class="no-results">
                <?php if ($searchFilter !== ''): ?>
                    <p>No posts found matching "<strong><?= htmlspecialchars($searchFilter) ?></strong>"</p>
                    <p>Try searching for different keywords or browse all groups.</p>
                <?php else: ?>
                    <p>No posts found matching your criteria.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php includePosts($posts, $pdo, $currentUserId, $targetedAds, $boostedPosts, $page); ?>
        <?php endif; ?>
    </div>
    
    <!-- Loading Spinner for Posts -->
    <div id="posts-loading-spinner" class="loading-spinner">
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
let currentTab = '<?= $activeTab ?>';
let isLoading = false;
let hasMoreGroups = true;
let hasMorePosts = true;
const itemsPerPage = 8;

// ========== FULLSCREEN VIDEO VARIABLES ==========
let currentVideoIndex = 0;
let currentVideoList = [];
let currentPostId = null;

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

// ========== INFINITE SCROLL FUNCTIONALITY ==========
function initInfiniteScroll() {
    window.addEventListener('scroll', handleScroll);
}

function handleScroll() {
    if (isLoading) return;
    
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const windowHeight = window.innerHeight;
    const documentHeight = document.documentElement.scrollHeight;
    
    // Load more when 100px from bottom
    if (scrollTop + windowHeight >= documentHeight - 100) {
        if (currentTab === 'groups' && hasMoreGroups) {
            loadMoreGroups();
        } else if (currentTab === 'posts' && hasMorePosts) {
            loadMorePosts();
        }
    }
}

async function loadMoreGroups() {
    if (isLoading || !hasMoreGroups) return;
    
    isLoading = true;
    const loadingSpinner = document.getElementById('groups-loading-spinner');
    const endOfGroups = document.getElementById('end-of-groups');
    
    // Show loading spinner
    loadingSpinner.style.display = 'block';
    
    try {
        currentPage++;
        
        // Create URL with current parameters
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', currentPage);
        urlParams.set('tab', 'groups');
        
        const response = await fetch(`join_group.php?${urlParams.toString()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const newGroupsHtml = await response.text();
        
        if (newGroupsHtml.includes('no-results') || newGroupsHtml.trim() === '') {
            // No more groups to load
            hasMoreGroups = false;
            endOfGroups.style.display = 'block';
        } else {
            // Append new groups
            const groupsList = document.getElementById('groups-list');
            groupsList.insertAdjacentHTML('beforeend', newGroupsHtml);
            
            // Re-attach event listeners to new groups
            attachEventListenersToNewGroups();
        }
    } catch (error) {
        console.error('Error loading more groups:', error);
        currentPage--; // Revert page on error
    } finally {
        isLoading = false;
        loadingSpinner.style.display = 'none';
    }
}

async function loadMorePosts() {
    if (isLoading || !hasMorePosts) return;
    
    isLoading = true;
    const loadingSpinner = document.getElementById('posts-loading-spinner');
    const endOfPosts = document.getElementById('end-of-posts');
    
    // Show loading spinner
    loadingSpinner.style.display = 'block';
    
    try {
        currentPage++;
        
        // Create URL with current parameters
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', currentPage);
        urlParams.set('tab', 'posts');
        
        const response = await fetch(`join_group.php?${urlParams.toString()}`, {
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

// ========== EVENT LISTENER ATTACHMENT FUNCTIONS ==========
function attachEventListenersToNewGroups() {
    // Attach group card click listeners
    document.querySelectorAll('.group-card').forEach(card => {
        if (!card.hasAttribute('data-listener-attached')) {
            card.setAttribute('data-listener-attached', 'true');
            card.addEventListener('click', (e) => {
                if(e.target.tagName.toLowerCase() === 'button') return;
                const joinButton = card.querySelector('button.join-btn');
                const groupId = card.dataset.groupId;
                if (!joinButton || joinButton.disabled) return;
                const btnText = joinButton.textContent.toLowerCase();
                if(['join', 'pending', 'visit'].includes(btnText)) {
                    window.location.href = `group.php?id=${groupId}`;
                }
            });
        }
    });

    // Attach join button listeners
    document.querySelectorAll('.join-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleJoinClick);
        }
    });
    
    // Attach ad like button listeners
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleAdLike);
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
}

function attachEventListenersToNewPosts() {
    // Attach like button listeners to new posts
    document.querySelectorAll('.action-btn.like-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleLikeClick);
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
            button.addEventListener('click', function() {
                const postId = this.closest('.post-card').dataset.postId;
                openPostComments(postId);
            });
        }
    });
    
    // Attach ad comment button listeners to new posts
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
}

// ========== POST COMMENTS POPUP FUNCTIONS ==========
async function openPostComments(postId) {
    console.log("DEBUG: Opening post comments for ID:", postId);
    currentCommentPostId = postId;
    
    const overlay = document.getElementById('fullscreenPostComments');
    const content = document.getElementById('postCommentsContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align:center; padding:50px; color:white;">Loading comments...</div>';
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_post_data');
        formData.append('post_id', postId);
        
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.error("DEBUG: JSON parse error:", jsonError);
            content.innerHTML = `
                <div style="color:white; text-align:center; padding:50px;">
                    <h3>Server Error</h3>
                    <p>Received invalid response from server.</p>
                    <p>Check browser console for details.</p>
                    <button onclick="closePostComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                        Close
                    </button>
                </div>
            `;
            return;
        }
        
        if (data.success) {
            content.innerHTML = generatePostCommentsHTML(data.post, data.comments, data.currentUserId);
            attachPostCommentListeners();
        } else {
            content.innerHTML = `
                <div style="color:white; text-align:center; padding:50px;">
                    <h3>Error: ${data.error || 'Unknown error'}</h3>
                    <button onclick="closePostComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                        Close
                    </button>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading comments:', error);
        content.innerHTML = `
            <div style="color:white; text-align:center; padding:50px;">
                <h3>Error loading comments</h3>
                <p>${error.message || 'Please try again.'}</p>
                <button onclick="closePostComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                    Close
                </button>
            </div>
        `;
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
      
      <!-- Reply Form -->
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
        
        const response = await fetch('join_group.php', {
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
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // If it's a reply and we're showing replies, refresh the replies
            if (parentCommentId && data.is_reply) {
                loadPostReplies(parentCommentId);
                hideReplyForm(parentCommentId);
            } else {
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
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
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
    
    const contentEl = document.getElementById(`comment-content-${commentId}`);
    if (contentEl) {
        contentEl.textContent = newText;
    }
    
    cancelPostEdit(commentId);
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

// ========== AD COMMENTS POPUP FUNCTIONS ==========
async function openAdComments(adId) {
    console.log("DEBUG: Opening ad comments for ID:", adId);
    currentCommentAdId = adId;
    
    const overlay = document.getElementById('fullscreenAdComments');
    const content = document.getElementById('adCommentsContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align:center; padding:50px; color:white;">Loading comments...</div>';
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_ad_data');
        formData.append('ad_id', adId);
        
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.error("DEBUG: JSON parse error:", jsonError);
            content.innerHTML = `
                <div style="color:white; text-align:center; padding:50px;">
                    <h3>Server Error</h3>
                    <p>Received invalid response from server.</p>
                    <p>Check browser console for details.</p>
                    <button onclick="closeAdComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                        Close
                    </button>
                </div>
            `;
            return;
        }
        
        if (data.success) {
            content.innerHTML = generateAdCommentsHTML(data.ad, data.comments, data.likeCount, data.shareCount, data.userLiked, data.currentUserId, data.commentCount);
            attachAdCommentListeners();
        } else {
            content.innerHTML = `
                <div style="color:white; text-align:center; padding:50px;">
                    <h3>Error: ${data.error || 'Unknown error'}</h3>
                    <button onclick="closeAdComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                        Close
                    </button>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading ad comments:', error);
        content.innerHTML = `
            <div style="color:white; text-align:center; padding:50px;">
                <h3>Error loading comments</h3>
                <p>${error.message || 'Please try again.'}</p>
                <button onclick="closeAdComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                    Close
                </button>
            </div>
        `;
    }
}

function generateAdCommentsHTML(ad, comments, likeCount, shareCount, userLiked, currentUserId, commentCount) {
    if (!ad) {
        return '<div style="color:white; text-align:center; padding:50px;">Error: Ad data not available</div>';
    }
    
    const advertiserId = ad.user_id || ad.advertiser_id;
    const userProfilePic = 'default_profile.png';
    
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
        
        <!-- Reply Form -->
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
        
        const response = await fetch('join_group.php', {
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
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (parentCommentId && data.is_reply) {
                loadAdReplies(parentCommentId);
                hideAdReplyForm(parentCommentId);
            } else {
                openAdComments(adId);
            }
            
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
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
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
    fullscreenVideo.muted = false;
    fullscreenVideo.autoplay = true;
    fullscreenVideo.controls = true;
    
    // Update video counter
    videoCounter.textContent = `${currentVideoIndex + 1}/${currentVideoList.length}`;
    
    // Show/hide navigation buttons
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
    fullscreenVideo.muted = false;
    fullscreenVideo.autoplay = true;
    
    // Update counter
    videoCounter.textContent = `${currentVideoIndex + 1}/${currentVideoList.length}`;
    
    // Try to play the video
    fullscreenVideo.play().catch(e => {
        console.log('Video navigation autoplay prevented:', e);
    });
}

// Video Controls for Group Videos
function toggleVideoPlayPause(videoContainer) {
    const video = videoContainer.querySelector('.group-video');
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

function initVideoControls() {
    // Set all group videos to muted and paused by default in regular view
    document.querySelectorAll('.group-video').forEach(video => {
        video.muted = true;
        video.playsInline = true;
        video.autoplay = false;
        video.pause();
        
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

// ========== JOIN BUTTON FUNCTIONALITY ==========
async function handleJoinClick(e) {
    e.stopPropagation();
    const button = e.currentTarget;
    const groupCard = button.closest('.group-card');
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
        
        const response = await fetch('join_group.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if(data.success) {
            if(data.pending) {
                // Set to pending state
                button.textContent = 'Pending';
                button.className = originalClasses + ' pending';
                button.disabled = true;
            } else {
                // Set to visited state
                button.textContent = 'Visit';
                button.className = originalClasses + ' visited';
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

// ========== LIKE BUTTON FUNCTIONALITY ==========
async function handleLikeClick(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const btn = e.currentTarget;
    const postId = btn.getAttribute('data-post-id');
    if (!postId) return;

    const isLiked = btn.classList.contains('liked');
    const action = isLiked ? 'unlike' : 'like';

    // ALWAYS update UI immediately
    const countSpan = btn.querySelector('.count');
    const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
    
    // Toggle visual state immediately
    btn.classList.toggle('liked');
    
    // Update count immediately
    countSpan.textContent = `(${isLiked ? Math.max(0, currentCount - 1) : currentCount + 1})`;

    // Visual feedback
    btn.style.transform = 'scale(1.1)';
    setTimeout(() => {
        btn.style.transform = 'scale(1)';
    }, 200);

    // Send to server in background
    const formData = new FormData();
    formData.append('action', action);
    formData.append('post_id', postId);

    fetch('join_group.php', {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Like action failed in background:', err);
    });
}

// ========== AD LIKE FUNCTIONALITY ==========
async function handleAdLike(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const btn = e.currentTarget;
    const adId = btn.getAttribute('data-ad-id');
    if (!adId) return;

    const isLiked = btn.classList.contains('liked');
    const action = isLiked ? 'unlike_ad' : 'like_ad';

    // ALWAYS update UI immediately
    const countSpan = btn.querySelector('.count');
    const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
    
    // Toggle visual state immediately
    btn.classList.toggle('liked');
    
    // Update count immediately
    countSpan.textContent = `(${isLiked ? Math.max(0, currentCount - 1) : currentCount + 1})`;

    // Visual feedback
    btn.style.transform = 'scale(1.1)';
    setTimeout(() => {
        btn.style.transform = 'scale(1)';
    }, 200);

    // Send to server in background
    const formData = new FormData();
    formData.append('action', action);
    formData.append('ad_id', adId);

    fetch('join_group.php', {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Ad like action failed in background:', err);
    });
}

// ========== SHARE FUNCTIONALITY ==========
async function shareAd(adId) {
    const adUrl = `${window.location.origin}/ad_view.php?id=${adId}`;
    const shareBtn = document.querySelector(`.ad-action-btn.share-btn[onclick="shareAd(${adId})"]`);
    
    if (!shareBtn) return;
    
    try {
        if (navigator.share) {
            await navigator.share({
                title: 'Check out this ad',
                url: adUrl
            });
        } else {
            // Fallback to clipboard
            await navigator.clipboard.writeText(adUrl);
        }
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share_ad');
        formData.append('ad_id', adId);
        
        await fetch('join_group.php', {
            method: 'POST',
            body: formData
        });
        
        // Update share count
        const countSpan = shareBtn.querySelector('.count');
        if (countSpan) {
            const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
            countSpan.textContent = `(${currentCount + 1})`;
        }
    } catch (err) {
        // Still update visual state
        const countSpan = shareBtn.querySelector('.count');
        if (countSpan) {
            const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
            countSpan.textContent = `(${currentCount + 1})`;
        }
    }
}

function shareGroupPost(postId, groupId) {
    let postUrl;
    if (groupId) {
        postUrl = `${window.location.origin}/group.php?id=${groupId}&post=${postId}`;
    } else {
        postUrl = `${window.location.origin}/comment.php?post_id=${postId}`;
    }
    
    const shareBtn = document.querySelector(`.action-btn.share-btn[onclick="shareGroupPost(${postId}, ${groupId})"]`);
    
    if (!shareBtn) return;
    
    setTimeout(async () => {
        try {
            if (navigator.share) {
                await navigator.share({
                    title: 'Check out this post',
                    url: postUrl
                });
            } else {
                // Fallback to clipboard
                await navigator.clipboard.writeText(postUrl);
            }
            
            // Update share count visually
            const countSpan = shareBtn.querySelector('.count');
            if (countSpan) {
                const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
                countSpan.textContent = `(${currentCount + 1})`;
            }
        } catch (err) {
            // Still update visual state
            const countSpan = shareBtn.querySelector('.count');
            if (countSpan) {
                const currentCount = parseInt(countSpan.textContent.match(/\d+/)[0]) || 0;
                countSpan.textContent = `(${currentCount + 1})`;
            }
        }
    }, 500);
}

// ========== FILTER AND SEARCH FUNCTIONALITY ==========
const searchInput = document.getElementById('search-input');
const categoryFilter = document.getElementById('category-filter');
const postTypeFilter = document.getElementById('post-type-filter');
const tabs = document.querySelectorAll('.tab');
const tabContents = document.querySelectorAll('.tab-content');

let searchTimeout;

// Real-time search filtering
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
            // Keep the current tab active
            params.set('tab', document.querySelector('.tab.active').getAttribute('data-tab'));
            window.location.href = `join_group.php?${params.toString()}`;
        }
    }, 500); // 500ms delay
});

// Tab switching
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        const tabName = tab.getAttribute('data-tab');
        currentTab = tabName;
        
        // Update active tab
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        
        // Update active content
        tabContents.forEach(content => content.classList.remove('active'));
        document.getElementById(`${tabName}-tab`).classList.add('active');
        
        // Show/hide post type filter
        postTypeFilter.style.display = tabName === 'posts' ? 'block' : 'none';
        
        // Reset pagination for new tab
        currentPage = 1;
        if (tabName === 'groups') {
            hasMoreGroups = true;
            hasMorePosts = false;
        } else {
            hasMoreGroups = false;
            hasMorePosts = true;
        }
        
        // Update URL without reload
        const params = new URLSearchParams(window.location.search);
        params.set('tab', tabName);
        
        // Update URL without reloading page
        window.history.replaceState({}, '', `join_group.php?${params.toString()}`);
    });
});

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
    
    window.location.href = `join_group.php?${params.toString()}`;
}

// ========== KEYBOARD AND TOUCH EVENT HANDLERS ==========
// Close fullscreen on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFullscreenVideo();
        closePostComments();
        closeAdComments();
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

// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', () => {
    // Initialize video controls
    initVideoControls();
    
    // Initialize infinite scroll based on current tab
    if (currentTab === 'groups') {
        hasMoreGroups = true;
        hasMorePosts = false;
    } else {
        hasMoreGroups = false;
        hasMorePosts = true;
    }
    initInfiniteScroll();
    
    // Attach initial event listeners
    attachEventListenersToNewGroups();
    attachEventListenersToNewPosts();
    
    console.log("DEBUG: Join Group page initialized successfully");
});
</script>
<?php
require_once "reload.php";
?>
</body>
</html>
<?php } ?>

