<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$groupId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($groupId <= 0) {
    die("Invalid group ID.");
}

// ========== PAGINATION VARIABLES ==========
$postsPerPage = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $postsPerPage;

// Check if this is an AJAX request for loading more posts
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

// Fetch group info
$stmt = $pdo->prepare("SELECT * FROM groups WHERE id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    die("Group not found.");
}

// Check membership status
$stmtMember = $pdo->prepare("SELECT status FROM group_members WHERE group_id = :group_id AND user_id = :user_id");
$stmtMember->execute(['group_id' => $groupId, 'user_id' => $userId]);
$membershipStatus = $stmtMember->fetchColumn();
$isMember = ($membershipStatus === 'approved');
$isAdmin = ($group['creator_id'] == $userId);

// ========== LIKE/COMMENT/SHARE FUNCTIONS ==========

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

// Function to check if user liked a post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// Function to check if user has already reported a post
function userAlreadyReported($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM post_reports WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// ========== ADS AND BOOSTED POSTS FUNCTIONS ==========

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

// Continent definitions (same as feed.php)
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
        $matchingCountries = array_intersect($continentCountries, $adLocations);
        if (!empty($matchingCountries) && in_array($userCountry, $continentCountries)) {
            return true;
        }
    }
    
    return false;
}

// Get targeted ads for current user
function getTargetedAds($pdo, $userId, $limit = 15) {
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
    $_SESSION['group_shuffled_ads'] = $targetedAds;
    
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

// Get boosted posts for group page
function getGroupBoostedPosts($pdo, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id, b.boost_end
        FROM boost_posts b
        JOIN posts p ON b.post_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE b.boost_end > NOW()
        ORDER BY random()
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $boostedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    shuffle($boostedPosts);
    return $boostedPosts;
}

// ========== END OF ADS AND BOOSTED POSTS FUNCTIONS ==========

if ($group['privacy_setting'] === 'private' && !$isMember && !$isAdmin) {
    $posts = [];
    $accessDenied = true;
} else {
    // Fetch posts with hashtags - MODIFIED for pagination
    $stmtPosts = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url 
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.group_id = :group_id
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmtPosts->bindValue(':group_id', $groupId, PDO::PARAM_INT);
    $stmtPosts->bindValue(':limit', $postsPerPage, PDO::PARAM_INT);
    $stmtPosts->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtPosts->execute();
    $posts = $stmtPosts->fetchAll(PDO::FETCH_ASSOC);
    $accessDenied = false;
    
    // Get total posts count for pagination
    $stmtTotalPosts = $pdo->prepare("
        SELECT COUNT(*) FROM posts WHERE group_id = :group_id
    ");
    $stmtTotalPosts->execute(['group_id' => $groupId]);
    $totalPosts = $stmtTotalPosts->fetchColumn();
    $totalPages = ceil($totalPosts / $postsPerPage);
    
    // Get targeted ads and boosted posts only if user has access
    $targetedAds = getTargetedAds($pdo, $userId, 20);
    $boostedPosts = getGroupBoostedPosts($pdo, 15);
    
    // SHUFFLE ADS FOR RANDOM DISPLAY
    shuffle($targetedAds);
    $allAds = $targetedAds; // Set allAds for use in the loop
    
    // If this is an AJAX request, output posts and exit
    if ($isAjaxRequest) {
        if (empty($posts)) {
            echo '<div class="no-results">No more posts to load.</div>';
            exit;
        }
        
        // Output the posts HTML
        ob_start();
        includePosts($posts, $pdo, $userId, $allAds, $boostedPosts, $page);
        $postsHtml = ob_get_clean();
        echo $postsHtml;
        exit;
    }
}

// Fetch group members
$stmtMembers = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url 
    FROM group_members gm
    JOIN users u ON gm.user_id = u.id
    WHERE gm.group_id = :group_id AND gm.status = 'approved'
    ORDER BY u.username ASC
");
$stmtMembers->execute(['group_id' => $groupId]);
$members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

// Check voice call settings and status
$stmtSettings = $pdo->prepare("SELECT only_admin_can_call FROM group_settings WHERE group_id = :group_id");
$stmtSettings->execute(['group_id' => $groupId]);
$settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
$onlyAdminCanCall = $settings ? $settings['only_admin_can_call'] : false;

// Check if there's an active voice call
$stmtActiveCall = $pdo->prepare("SELECT * FROM voice_calls WHERE group_id = :group_id AND is_active = true");
$stmtActiveCall->execute(['group_id' => $groupId]);
$activeCall = $stmtActiveCall->fetch(PDO::FETCH_ASSOC);

// Check if current user is in the call
$inCall = false;
if ($activeCall) {
    $stmtInCall = $pdo->prepare("SELECT 1 FROM voice_call_participants WHERE call_id = :call_id AND user_id = :user_id");
    $stmtInCall->execute(['call_id' => $activeCall['id'], 'user_id' => $userId]);
    $inCall = (bool)$stmtInCall->fetchColumn();
}

// Helper functions
function getPostHashtags($pdo, $postId) {
    $stmt = $pdo->prepare("
        SELECT h.tag FROM hashtags h
        JOIN post_hashtags ph ON h.id = ph.hashtag_id
        WHERE ph.post_id = :post_id
    ");
    $stmt->execute(['post_id' => $postId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
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

// Function to output posts HTML (for both initial load and AJAX)
function includePosts($posts, $pdo, $userId, $allAds, $boostedPosts, $currentPage = 1) {
    $postCounter = ($currentPage - 1) * 8; // Start counter from appropriate position
    $adCounter = 0;
    $boostCounter = 0;
    
    foreach ($posts as $post): 
        $postCounter++;
        $hashtags = getPostHashtags($pdo, $post['id']);
        $shareCount = getShareCount($pdo, $post['id']);
        $likeCount = getLikeCount($pdo, $post['id']);
        $commentCount = getCommentCount($pdo, $post['id']);
        $isLiked = userLikedPost($pdo, $userId, $post['id']);
        $isReported = userAlreadyReported($pdo, $userId, $post['id']);
    ?>
        <div class="post" data-post-id="<?= $post['id'] ?>">
            <div class="post-header">
                <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" 
                     alt="User Profile" 
                     onclick="window.location.href='profile.php?id=<?= $post['user_id'] ?>'" />
                <div class="post-header-info">
                    <div class="username" onclick="window.location.href='profile.php?id=<?= $post['user_id'] ?>'">
                        <?= htmlspecialchars($post['username']) ?>
                    </div>
                    <div class="timestamp"><?= timeAgo($post['created_at']) ?></div>
                </div>
                
                <button class="report-btn <?= $isReported ? 'reported' : '' ?>" 
                        onclick="openReportModal(<?= $post['id'] ?>)" 
                        <?= $isReported ? 'disabled' : '' ?>>
                    <?= $isReported ? 'Reported' : 'Report' ?>
                </button>
            </div>

            <?php if (!empty($post['post_header'])): ?>
                <div class="post-content" style="font-weight: bold; font-size: 16px; margin-bottom: 10px;">
                    <?= htmlspecialchars($post['post_header']) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($post['content'])): ?>
                <div class="post-content">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
            <?php endif; ?>

            <!-- Hashtags -->
            <?php if (!empty($hashtags)): ?>
                <div class="post-hashtags">
                    <?php foreach ($hashtags as $tag): ?>
                        <span class="hashtag">#<?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Media Display - UPDATED: Using feed.php video display method -->
            <?php if ($post['post_type'] === 'photo' && !empty($post['media_url'])):
                $mediaArray = explode(',', $post['media_url']);
                $mediaCount = count($mediaArray);
                $firstFour = array_slice($mediaArray, 0, 4);
                $extraCount = $mediaCount - 4;
                ?>
                <div class="post-media">
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
                <!-- VIDEO POST - USING FEED.PHP STYLE -->
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

            <!-- Actions - UPDATED: Using people.php like button style -->
            <div class="post-actions">
                <button class="action-btn like-btn <?= $isLiked ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                    <span class="icon">❤️</span>
                    Like <span class="count">(<?= $likeCount ?>)</span>
                </button>
                
                <button class="action-btn comment-btn" onclick="openCommentModal(<?= $post['id'] ?>, 'post')">
                    <span class="icon">💬</span>
                    Comment <span class="count">(<?= $commentCount ?>)</span>
                </button>
                
                <button class="action-btn share-btn" onclick="shareGroupPost(<?= $post['id'] ?>, <?= $post['group_id'] ?? 'null' ?>)">
                    <span class="icon">↗️</span>
                    Share <span class="count">(<?= $shareCount ?>)</span>
                </button>
            </div>
        </div>

        <?php
        // Insert boosted posts every 6 posts
        if ($postCounter % 6 === 0 && count($boostedPosts) > 0):
            $randomBoostIndex = array_rand($boostedPosts);
            $boostPost = $boostedPosts[$randomBoostIndex];
            $boostedHashtags = getPostHashtags($pdo, $boostPost['id']);
            $boostLikeCount = getLikeCount($pdo, $boostPost['id']);
            $boostCommentCount = getCommentCount($pdo, $boostPost['id']);
            $boostShareCount = getShareCount($pdo, $boostPost['id']);
            $boostIsLiked = userLikedPost($pdo, $userId, $boostPost['id']);
            $boostIsReported = userAlreadyReported($pdo, $userId, $boostPost['id']);
        ?>
            <!-- BOOSTED POST -->
            <div class="post boosted-post" data-post-id="<?= $boostPost['id'] ?>">
                <div class="sponsored-label">Sponsored</div>
                <div class="post-header">
                    <img src="<?= htmlspecialchars($boostPost['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'" />
                    <div class="post-header-info">
                        <div class="username" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'">
                            <?= htmlspecialchars($boostPost['username']) ?>
                        </div>
                        <div class="timestamp"><?= timeAgo($boostPost['created_at']) ?></div>
                    </div>
                    
                    <button class="report-btn <?= $boostIsReported ? 'reported' : '' ?>" 
                            onclick="openReportModal(<?= $boostPost['id'] ?>)" 
                            <?= $boostIsReported ? 'disabled' : '' ?>>
                        <?= $boostIsReported ? 'Reported' : 'Report' ?>
                    </button>
                </div>
                
                <div class="post-content">
                    <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
                </div>
                
                <!-- Boosted post hashtags -->
                <?php if (!empty($boostedHashtags)): ?>
                    <div class="post-hashtags">
                        <?php foreach ($boostedHashtags as $tag): ?>
                            <span class="hashtag">#<?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Boosted post media -->
                <?php if ($boostPost['post_type'] === 'video' && !empty($boostPost['media_url'])): ?>
                    <?php
                    $mediaArray = explode(',', $boostPost['media_url']);
                    $firstVideo = trim($mediaArray[0]);
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
                    $mediaArray = explode(',', $boostPost['media_url']);
                    $mediaCount = count($mediaArray);
                    $firstFour = array_slice($mediaArray, 0, 4);
                    $extraCount = $mediaCount - 4;
                    ?>
                    <div class="post-media">
                        <div class="post-media-grid">
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
                    </div>
                <?php endif; ?>

                <!-- Actions for boosted post - UPDATED: Using people.php like button style -->
                <div class="post-actions">
                    <button class="action-btn like-btn <?= $boostIsLiked ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                        <span class="icon">❤️</span>
                        Like <span class="count">(<?= $boostLikeCount ?>)</span>
                    </button>
                    
                    <button class="action-btn comment-btn" onclick="openCommentModal(<?= $boostPost['id'] ?>, 'post')">
                        <span class="icon">💬</span>
                        Comment <span class="count">(<?= $boostCommentCount ?>)</span>
                    </button>
                    
                    <button class="action-btn share-btn" onclick="shareGroupPost(<?= $boostPost['id'] ?>, null)">
                        <span class="icon">↗️</span>
                        Share <span class="count">(<?= $boostShareCount ?>)</span>
                    </button>
                </div>
                
                <div style="text-align: center; margin-top: 10px; font-size: 12px; color: #218838;">
                    Boost expires at: <?= date('M j, Y H:i', strtotime($boostPost['boost_end'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Insert ads every 4 posts
        if ($postCounter % 4 === 0 && count($allAds) > 0):
            $randomAdIndex = array_rand($allAds);
            $ad = $allAds[$randomAdIndex];
        ?>
            <!-- REGULAR ADVERTISEMENT -->
            <div class="post ad-post" data-ad-id="<?= $ad['id'] ?>">
                <div class="ad-label">Sponsored</div>
                
                <div class="post-header">
                    <img src="<?= htmlspecialchars($ad['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Advertiser" />
                    <div class="post-header-info">
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
                <?php if (!empty($ad['url'])): ?>
                    <a href="<?= htmlspecialchars($ad['url']) ?>" target="_blank" class="cta-button">
                        <?= htmlspecialchars($ad['cta_button'] ?: 'Learn More') ?>
                    </a>
                <?php endif; ?>

                <!-- Ad Engagement Buttons - UPDATED: Using people.php style -->
                <div class="ad-engagement">
                    <button class="ad-action-btn like-btn" data-ad-id="<?= $ad['id'] ?>">
                        <span class="icon">❤️</span>
                        Like <span class="count">(0)</span>
                    </button>
                    
                    <button class="ad-action-btn comment-btn" onclick="openCommentModal(<?= $ad['id'] ?>, 'ad')">
                        <span class="icon">💬</span>
                        Comment <span class="count">(0)</span>
                    </button>
                    
                    <button class="ad-action-btn share-btn" onclick="shareAd(<?= $ad['id'] ?>)">
                        <span class="icon">↗️</span>
                        Share <span class="count">(0)</span>
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

$isMember = ($membershipStatus === 'approved');
$isPending = ($membershipStatus === 'pending');
$isAdmin = ($group['creator_id'] == $userId);

// AJAX handlers for LIKE, UNLIKE, etc.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- LIKE/UNLIKE POST HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
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
            $stmt->execute([$adId, $userId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM ad_likes WHERE ad_id = ? AND user_id = ?");
            $stmt->execute([$adId, $userId]);
        }
        
        // Get new like count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
        $stmt->execute([$adId]);
        $newLikeCount = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
        exit;
    }

    // --- AD SHARE HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'share_ad' && isset($_POST['ad_id'])) {
        $adId = (int)$_POST['ad_id'];
        
        // Check if already shared
        $checkStmt = $pdo->prepare("SELECT 1 FROM shared_ads WHERE original_ad_id = ? AND user_id = ?");
        $checkStmt->execute([$adId, $userId]);
        $alreadyShared = (bool)$checkStmt->fetchColumn();
        
        if (!$alreadyShared) {
            $stmt = $pdo->prepare("INSERT INTO shared_ads (original_ad_id, user_id) VALUES (?, ?)");
            $stmt->execute([$adId, $userId]);
        }
        
        // Get new share count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
        $stmt->execute([$adId]);
        $newShareCount = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'shares_count' => $newShareCount]);
        exit;
    }
    
// --- REPORT POST HANDLER ---
if (isset($_POST['action']) && $_POST['action'] === 'report_post' && isset($_POST['post_id']) && isset($_POST['report_reason'])) {
    $postId = (int)$_POST['post_id'];
    $reportReason = trim($_POST['report_reason']);
    
    // Validate report reason
    if (empty($reportReason)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a reason for reporting.']);
        exit;
    }
    
    // Check if user has already reported this post
    $checkStmt = $pdo->prepare("SELECT 1 FROM post_reports WHERE post_id = ? AND user_id = ?");
    $checkStmt->execute([$postId, $userId]);
    $alreadyReported = (bool)$checkStmt->fetchColumn();
    
    if ($alreadyReported) {
        echo json_encode(['success' => false, 'message' => 'You have already reported this post.']);
        exit;
    }
    
    // Verify the post exists
    $checkPostStmt = $pdo->prepare("SELECT id, group_id FROM posts WHERE id = ?");
    $checkPostStmt->execute([$postId]);
    $post = $checkPostStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Post not found.']);
        exit;
    }
    
    // Verify the post belongs to the current group (security check)
    if ($post['group_id'] != $groupId) {
        echo json_encode(['success' => false, 'message' => 'Invalid post for this group.']);
        exit;
    }
    
    try {
        // Insert the report
        $stmt = $pdo->prepare("INSERT INTO post_reports (post_id, user_id, report_reason, created_at) VALUES (?, ?, ?, NOW())");
        $result = $stmt->execute([$postId, $userId, $reportReason]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Post reported successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to report post.']);
        }
        exit;
    } catch (PDOException $e) {
        error_log("Report error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        exit;
    } catch (Exception $e) {
        error_log("Report error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error reporting post. Please try again.']);
        exit;
    }
}
}

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($group['name']) ?> - Fbclone Group</title>
<style>
/* All existing CSS styles remain the same, just updating the button styles */

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

.no-results {
    text-align: center;
    padding: 40px;
    color: #718096;
    font-style: italic;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    backdrop-filter: blur(10px);
    margin: 20px 0;
}

/* ========== COMMENT MODAL STYLES ========== */
.comment-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    z-index: 10001;
    display: none;
    justify-content: center;
    align-items: center;
    overflow-y: auto;
    padding: 20px;
}

.comment-modal {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    width: 100%;
    max-width: 700px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    border: 2px solid rgba(123, 104, 238, 0.3);
    animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(50px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.comment-modal-header {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid rgba(255, 255, 255, 0.2);
}

.comment-modal-header h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.comment-close-btn {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    font-size: 24px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.comment-close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.comment-modal-content {
    max-height: calc(90vh - 80px);
    overflow-y: auto;
    padding: 0;
}

.comment-modal-content::-webkit-scrollbar {
    width: 6px;
}

.comment-modal-content::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 3px;
}

.comment-modal-content::-webkit-scrollbar-thumb {
    background: #7b68ee;
    border-radius: 3px;
}

.comment-modal-content::-webkit-scrollbar-thumb:hover {
    background: #6a5acd;
}

/* ========== UPDATED BUTTON STYLES FROM PEOPLE.PHP ========== */

/* Post Actions Styling */
.post-actions {
    display: flex;
    gap: 15px;
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

/* Processing state */
.processing {
    pointer-events: none !important;
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
}

.cover-photo { 
    width: 100%; 
    height: 300px; 
    overflow: hidden; 
    border-radius: 15px; 
    margin-bottom: 20px;
    margin-top: 60px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(123, 104, 238, 0.3);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.cover-photo img { 
    width: 100%; 
    height: 300px; 
    object-fit: cover; 
    border-radius: 15px;
}

.members-container { 
    display: flex; 
    overflow-x: auto; 
    gap: 15px; 
    margin-bottom: 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 15px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border: 2px solid rgba(123, 104, 238, 0.3);
} 

.member { 
    text-align: center; 
    cursor: pointer; 
    flex: 0 0 auto; 
    transition: all 0.3s ease;
}

.member:hover {
    transform: translateY(-5px);
}

.member img { 
    width: 70px; 
    height: 70px; 
    border-radius: 50%; 
    border: 2px solid #7b68ee; 
    object-fit: cover; 
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.member .username { 
    margin-top: 6px; 
    font-weight: 600; 
    color: #2d3748; 
    font-size: 14px; 
    white-space: nowrap;
    text-decoration: none;
    font-weight: 700;
}

.member .admin-badge { 
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white; 
    padding: 2px 6px; 
    font-size: 10px; 
    border-radius: 4px; 
    margin-left: 6px; 
    font-weight: 600;
}

.group-setting-btn { 
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white; 
    padding: 12px 20px; 
    border: none; 
    border-radius: 8px; 
    font-weight: bold; 
    cursor: pointer; 
    margin-bottom: 20px; 
    margin-right: 10px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
    font-weight: 600;
}

.group-setting-btn:hover { 
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.group-post-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 25px;
    font-weight: bold;
    cursor: pointer;
    margin: 15px 0;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
    font-weight: 600;
}

.group-post-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.posts { 
    margin-top: 10px; 
}

.post { 
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px; 
    padding: 0;
    margin-bottom: 25px; 
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    overflow: hidden;
    border: 2px solid rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.post:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
}

.post-header { 
    display: flex; 
    align-items: center; 
    margin-bottom: 12px; 
    padding: 15px;
}

.post-header img { 
    width: 45px; 
    height: 45px; 
    border-radius: 50%; 
    object-fit: cover; 
    cursor: pointer; 
    border: 2px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.post-header .username { 
    margin-left: 12px; 
    font-weight: bold; 
    cursor: pointer; 
    color: #2d3748; 
    flex-grow: 1;
    font-weight: 700;
}

.post-header .timestamp {
    font-size: 12px;
    color: #718096;
    margin-top: 3px;
}

.post-content { 
    margin-bottom: 10px; 
    white-space: pre-wrap; 
    padding: 0 15px;
    line-height: 1.4;
    color: #2d3748;
}

.post-media { 
    margin-bottom: 10px; 
}

.post-media-grid { 
    display: grid; 
    grid-template-columns: repeat(2, 1fr); 
    gap: 8px; 
    padding: 0 15px;
}

.post-media-grid div { 
    position: relative; 
    cursor: pointer; 
    border-radius: 10px; 
    overflow: hidden; 
    height: 200px; 
    transition: transform 0.3s ease;
}

.post-media-grid div:hover {
    transform: scale(1.05);
}

.post-media-grid img,
.post-media-grid video { 
    width: 100%; 
    height: 200px; 
    object-fit: cover; 
}

.overlay { 
    position: absolute; 
    top: 0; 
    left: 0; 
    width: 100%; 
    height: 200px; 
    background: rgba(0,0,0,0.6); 
    color: white; 
    font-size: 28px; 
    font-weight: bold; 
    text-align: center; 
    line-height: 200px; 
    border-radius: 10px; 
}

.post-hashtags {
    margin: 10px 15px;
    padding: 10px 0;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
}

.hashtag {
    color: #7b68ee;
    cursor: pointer;
    margin-right: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.hashtag:hover {
    text-decoration: underline;
    color: #6a5acd;
}

.post-header-info {
    flex-grow: 1;
}

/* ========== FULLSCREEN VIDEO STYLES FROM FEED.PHP ========== */
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
    transition: all 0.3s ease;
}

.fullscreen-close-btn:hover {
    background: rgba(0, 0, 0, 0.9);
    transform: scale(1.1);
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
    transition: all 0.3s ease;
}

.fullscreen-nav-btn:hover {
    background: rgba(0, 0, 0, 0.9);
    transform: translateY(-50%) scale(1.1);
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

/* Video posts styling - UPDATED for feed.php style */
.video-post-container {
    position: relative;
    width: 100%;
    margin-bottom: 10px;
    overflow: hidden;
    border-radius: 10px;
    background: #000;
    cursor: pointer;
    transition: all 0.3s ease;
}

.video-post-container:hover {
    transform: scale(1.02);
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

/* Add styles for ads and boosted posts from feed.php */
.ad-post {
    border: 2px solid #ffd700;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
    position: relative;
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
    font-weight: 600;
}

.cta-button:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
}

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

/* Center boosted posts like in feed.php */
.boosted-post {
    border: 2px solid #28a745;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
    text-align: center;
    backdrop-filter: blur(10px);
}

.boosted-post .post-header {
    justify-content: center;
    text-align: center;
}

.boosted-post .post-header-info {
    text-align: center;
}

.boosted-post .username {
    text-align: center;
    display: block;
}

.boosted-post .post-content {
    text-align: center;
}

.boosted-post .post-hashtags {
    text-align: center;
}

.boosted-post .post-actions {
    justify-content: center;
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

/* Video container styles */
.video-container {
    position: relative;
    width: 100%;
    background: #000;
    cursor: pointer;
}

.video-container video {
    width: 100%;
    height: auto;
    max-height: 600px;
    object-fit: contain;
    display: block;
}

.video-content {
    padding: 0;
    position: relative;
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

.post, .members-container, .cover-photo {
    animation: fadeInUp 0.6s ease-out;
}

/* Scrollbar styling */
.members-container::-webkit-scrollbar {
    height: 6px;
}

.members-container::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 3px;
}

.members-container::-webkit-scrollbar-thumb {
    background: #7b68ee;
    border-radius: 3px;
}

.members-container::-webkit-scrollbar-thumb:hover {
    background: #6a5acd;
}

/* Responsive design */
@media (max-width: 600px) {
    body {
        padding: 0 10px;
    }
    
    .post-media-grid {
        grid-template-columns: 1fr 1fr;
        grid-gap: 4px;
    }
    
    .post-media-grid img, .overlay {
        height: 120px;
    }
    
    .group-setting-btn {
        padding: 10px 16px;
        font-size: 14px;
        margin-bottom: 10px;
        margin-right: 5px;
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
    
    .video-post-container video {
        max-height: 400px;
    }
    
    .post-actions {
        gap: 10px;
        font-size: 12px;
    }
    
    .action-btn {
        padding: 6px 8px;
        font-size: 12px;
    }
    
    .member img {
        width: 60px;
        height: 60px;
    }
    
    .cover-photo {
        height: 200px;
        margin-top: 50px;
    }
    
    .cover-photo img {
        height: 200px;
    }
    
    .comment-modal {
        width: 95%;
        margin: 10px;
    }
}

@media (max-width: 480px) {
    .post-media-grid {
        grid-template-columns: 1fr;
    }
    
    .post-media-grid img, .overlay {
        height: 200px;
    }
    
    .group-setting-btn {
        width: 100%;
        margin-right: 0;
        text-align: center;
    }
    
    .post-actions {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .member {
        min-width: 80px;
    }
    
    .member img {
        width: 50px;
        height: 50px;
    }
    
    .post-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .post-header img {
        margin-left: 0;
    }
}

/* Additional group-specific styles */
h1 {
    color: white;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    margin-bottom: 10px;
}

p {
    color: white;
    margin-bottom: 20px;
    line-height: 1.5;
}

.group-action-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 20px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.group-action-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.group-action-btn.pending {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    cursor: not-allowed;
}

.group-action-btn.visited {
    background: linear-gradient(135deg, #48bb78, #38a169);
}

.report-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
}

.report-btn:hover {
    background: linear-gradient(135deg, #ee5a52, #dd4941);
    transform: translateY(-2px);
}

.report-btn:disabled, .report-btn.reported {
    background: linear-gradient(135deg, #718096, #4a5568);
    cursor: not-allowed;
    transform: none;
}

/* Report Modal Styles */
.report-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.report-modal-content {
    background: white;
    padding: 25px;
    border-radius: 15px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.report-modal h3 {
    margin-bottom: 15px;
    color: #2d3748;
    text-align: center;
}

.report-modal textarea {
    width: 100%;
    height: 120px;
    padding: 12px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    resize: vertical;
    font-family: inherit;
    margin-bottom: 15px;
}

.report-modal textarea:focus {
    outline: none;
    border-color: #7b68ee;
}

.report-modal-buttons {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.report-modal-buttons button {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.report-cancel-btn {
    background: #e2e8f0;
    color: #4a5568;
}

.report-cancel-btn:hover {
    background: #cbd5e0;
}

.report-send-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
}

.report-send-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
}

/* Copy Link Button */
.copy-link-btn {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
    font-weight: 600;
    margin-left: 10px;
}

.copy-link-btn:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
}

.copy-link-btn.copied {
    background: linear-gradient(135deg, #38a169, #2f855a);
}

/* Admin Report Link */
.admin-report-link {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 20px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(237, 137, 54, 0.3);
    text-decoration: none;
    display: inline-block;
}

.admin-report-link:hover {
    background: linear-gradient(135deg, #dd6b20, #c05621);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(237, 137, 54, 0.4);
}
/* Global text wrapping */
h1, h2, h3, h4, h5, h6,
p, .post-content, .ad-header, .ad-description,
.group-description, .username {
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
}
</style>
</head>
<body>

<!-- Full Screen Video Overlay - FROM FEED.PHP -->
<div class="fullscreen-video-overlay" id="fullscreenVideoOverlay">
    <button class="fullscreen-close-btn" onclick="closeFullscreenVideo()">✕</button>
    <div class="fullscreen-video-container">
        <video class="fullscreen-video" id="fullscreenVideo" controls></video>
        <div class="video-counter" id="videoCounter">1/1</div>
        <button class="fullscreen-nav-btn fullscreen-prev-btn" onclick="navigateVideo(-1)">❮</button>
        <button class="fullscreen-nav-btn fullscreen-next-btn" onclick="navigateVideo(1)">❯</button>
    </div>
</div>

<!-- Report Modal -->
<div class="report-modal" id="reportModal">
    <div class="report-modal-content">
        <h3>Report Post</h3>
        <textarea id="reportReason" placeholder="Please provide a reason for reporting this post..."></textarea>
        <div class="report-modal-buttons">
            <button class="report-cancel-btn" onclick="closeReportModal()">Cancel</button>
            <button class="report-send-btn" onclick="sendReport()">Send Report</button>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="comment-modal-overlay" id="commentModalOverlay">
    <div class="comment-modal">
        <div class="comment-modal-header">
            <h3>💬 Comments</h3>
            <button class="comment-close-btn" onclick="closeCommentModal()">×</button>
        </div>
        <div class="comment-modal-content" id="commentModalContent">
            <!-- Comment content will be loaded here -->
        </div>
    </div>
</div>

<div class="cover-photo">
    <?php if ($group['cover_pic_url']): ?>
        <img src="<?= htmlspecialchars($group['cover_pic_url']) ?>" alt="Group Cover" />
    <?php else: ?>
        <div style="width:100%; height:300px; background:#2c2c3d; display:flex; align-items:center; justify-content:center; color:#7b68ee; font-size:24px;">
            <?= htmlspecialchars($group['name']) ?>
        </div>
    <?php endif; ?>
</div>

<div class="members-container" tabindex="0">
    <?php foreach ($members as $member): ?>
        <div class="member" onclick="window.location.href='profile.php?id=<?= $member['id'] ?>'" title="<?= htmlspecialchars($member['username']) ?>">
            <img src="<?= htmlspecialchars($member['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" />
            <div class="username">
                <?= htmlspecialchars($member['username']) ?>
                <?php if ($member['id'] === $group['creator_id']): ?>
                    <span class="admin-badge">Admin</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<h1 style="color: #7b68ee;"><?= htmlspecialchars($group['name']) ?></h1>
<p style="color: #7b68ee; margin-bottom: 20px;"><?= nl2br(htmlspecialchars($group['description'])) ?></p>

<!-- Copy Group Link Button -->
<button class="copy-link-btn" onclick="copyGroupLink()">
    📋 Copy Group Link
</button>

<!-- Group Action Buttons -->
<?php if ($isAdmin): ?>
    <button class="group-setting-btn" onclick="window.location.href='group_request.php?group_id=<?= $groupId ?>'">Group Join Requests</button>
    <button class="group-setting-btn" onclick="window.location.href='group_voice_call.php?group_id=<?= $groupId ?>'">Create Voice Call</button>
    <button class="group-setting-btn" onclick="window.location.href='group_setting.php?group_id=<?= $groupId ?>'">Group Settings</button>
    <a href="group_report.php?group_id=<?= $groupId ?>" class="admin-report-link">View Reported Posts</a>
<?php endif; ?>

<?php if ($isMember || $isAdmin): ?>
    <button class="group-setting-btn" onclick="window.location.href='group_chat.php?group_id=<?= $groupId ?>'">Group Chat</button>
<?php endif; ?>

<!-- Group Post Button - Only for members -->
<?php if ($isMember || $isAdmin): ?>
    <button class="group-post-btn" onclick="window.location.href='group_editor_video.php?group_id=<?= $groupId ?>'">
        📝 Create Group Post
    </button>
<?php endif; ?>

<!-- Membership Status -->
<?php if (!$isMember && !$isAdmin): ?>
    <?php if ($isPending): ?>
        <button id="joinBtn" class="group-action-btn pending" disabled>Pending Approval</button>
    <?php else: ?>
        <button id="joinBtn" class="group-action-btn" onclick="window.location.href='group.php?id=<?= $groupId ?>'">Join Group</button>
    <?php endif; ?>
<?php elseif ($isMember || $isAdmin): ?>
    <button class="group-action-btn visited" onclick="window.location.href='group.php?id=<?= $groupId ?>'">Member</button>
<?php endif; ?>

<!-- Voice Call Section -->
<?php if ($onlyAdminCanCall): ?>
    <?php if ($isAdmin): ?>
        <?php if ($activeCall): ?>
            <button class="group-setting-btn" style="background-color: #28a745;" onclick="window.location.href='voice_call.php?group_id=<?= $groupId ?>'">
                Join Voice Call (Active)
            </button>
        <?php else: ?>
            <button class="group-setting-btn" onclick="window.location.href='voice_call.php?group_id=<?= $groupId ?>&create=true'">
                Start Voice Call
            </button>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($activeCall): ?>
            <button class="group-setting-btn" style="background-color: #007bff;" onclick="window.location.href='voice_call.php?group_id=<?= $groupId ?>'">
                Join Voice Call
            </button>
        <?php else: ?>
            <p style="color: #666; font-style: italic;">Only admin can start voice calls</p>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
    <?php if ($activeCall): ?>
        <button class="group-setting-btn" style="background-color: #28a745;" onclick="window.location.href='voice_call.php?group_id=<?= $groupId ?>'">
            <?= $inCall ? 'Rejoin Voice Call' : 'Join Voice Call' ?>
        </button>
    <?php else: ?>
        <button class="group-setting-btn" onclick="window.location.href='voice_call.php?group_id=<?= $groupId ?>&create=true'">
            Start Voice Call
        </button>
    <?php endif; ?>
<?php endif; ?>

<!-- Posts Section -->
<?php if ($accessDenied): ?>
    <p style="color: #7b68ee; text-align: center; padding: 40px;">
        You are not a member of this private group. Please join to view posts.
    </p>
<?php else: ?>
<div class="posts">
    <?php if (empty($posts) && empty($boostedPosts) && empty($targetedAds)): ?>
        <div style="text-align: center; padding: 40px; color: #888;">
            <h3>No posts yet</h3>
            <p>Be the first to create a post in this group!</p>
        </div>
    <?php else: ?>
        <?php includePosts($posts, $pdo, $userId, $allAds, $boostedPosts, $page); ?>
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
<?php endif; ?>

<script>
// ========== PAGINATION VARIABLES ==========
let currentPage = <?= $page ?>;
let isLoading = false;
let hasMorePosts = true;
const postsPerPage = 8;

// ========== REPORT MODAL VARIABLES ==========
let currentReportPostId = null;

// ========== COMMENT MODAL VARIABLES ==========
let currentCommentId = null;
let currentCommentType = null; // 'post' or 'ad'

// ========== COMMENT MODAL FUNCTIONALITY ==========

function openCommentModal(itemId, type) {
    currentCommentId = itemId;
    currentCommentType = type;
    
    const modal = document.getElementById('commentModalOverlay');
    const content = document.getElementById('commentModalContent');
    
    // Show loading
    content.innerHTML = `
        <div style="padding: 40px; text-align: center;">
            <div class="spinner"></div>
            <p>Loading comments...</p>
        </div>
    `;
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Load the appropriate content
    if (type === 'post') {
        loadPostComments(itemId);
    } else if (type === 'ad') {
        loadAdComments(itemId);
    }
}

function loadPostComments(postId) {
    const content = document.getElementById('commentModalContent');
    
    // Create an iframe to load comment.php content
    content.innerHTML = `
        <iframe 
            src="comment.php?post_id=${postId}&popup=true" 
            style="width: 100%; height: calc(90vh - 80px); border: none;"
            onload="resizeIframe(this)"
        ></iframe>
    `;
}

function loadAdComments(adId) {
    const content = document.getElementById('commentModalContent');
    
    // Create an iframe to load ads_comment.php content
    content.innerHTML = `
        <iframe 
            src="ads_comment.php?ad_id=${adId}&popup=true" 
            style="width: 100%; height: calc(90vh - 80px); border: none;"
            onload="resizeIframe(this)"
        ></iframe>
    `;
}

function resizeIframe(iframe) {
    try {
        iframe.style.height = iframe.contentWindow.document.body.scrollHeight + 'px';
    } catch (e) {
        console.log('Error resizing iframe:', e);
    }
}

function closeCommentModal() {
    const modal = document.getElementById('commentModalOverlay');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset variables
    currentCommentId = null;
    currentCommentType = null;
    
    // Clear content
    document.getElementById('commentModalContent').innerHTML = '';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCommentModal();
        closeFullscreenVideo();
        closeReportModal();
    }
});

// Close modal when clicking outside
document.getElementById('commentModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCommentModal();
    }
});

// Function to update comment count after posting a comment
function updateCommentCount(itemId, type, increment = true) {
    let selector;
    if (type === 'post') {
        selector = `.post[data-post-id="${itemId}"] .comment-btn .count`;
    } else if (type === 'ad') {
        selector = `.ad-post[data-ad-id="${itemId}"] .comment-btn .count`;
    }
    
    const countElement = document.querySelector(selector);
    if (countElement) {
        const currentText = countElement.textContent;
        const match = currentText.match(/\((\d+)\)/);
        if (match) {
            const currentCount = parseInt(match[1]);
            countElement.textContent = `(${increment ? currentCount + 1 : Math.max(0, currentCount - 1)})`;
        }
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
        
        const response = await fetch(`group.php?${urlParams.toString()}`, {
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
            const postsContainer = document.querySelector('.posts');
            postsContainer.insertAdjacentHTML('beforeend', newPostsHtml);
            
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
    
    // Attach ad like button listeners to new posts
    document.querySelectorAll('.ad-action-btn.like-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', handleAdLike);
        }
    });
    
    // Attach report button listeners to new posts
    document.querySelectorAll('.report-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', function() {
                const postId = this.closest('.post').getAttribute('data-post-id');
                openReportModal(postId);
            });
        }
    });
    
    // Attach comment button listeners to new posts
    document.querySelectorAll('.action-btn.comment-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', function() {
                const postElement = this.closest('.post');
                if (postElement.classList.contains('ad-post')) {
                    const adId = postElement.getAttribute('data-ad-id');
                    openCommentModal(adId, 'ad');
                } else {
                    const postId = postElement.getAttribute('data-post-id');
                    openCommentModal(postId, 'post');
                }
            });
        }
    });
    
    // Attach ad comment button listeners to new posts
    document.querySelectorAll('.ad-action-btn.comment-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', function() {
                const adId = this.closest('.ad-post').getAttribute('data-ad-id');
                openCommentModal(adId, 'ad');
            });
        }
    });
    
    // Initialize video controls for new posts
    initVideoControls();
}

// ========== REPORT MODAL FUNCTIONALITY ==========

function openReportModal(postId) {
    currentReportPostId = postId;
    const modal = document.getElementById('reportModal');
    modal.style.display = 'flex';
    document.getElementById('reportReason').value = '';
}

function closeReportModal() {
    const modal = document.getElementById('reportModal');
    modal.style.display = 'none';
    currentReportPostId = null;
}

async function sendReport() {
    const reason = document.getElementById('reportReason').value.trim();
    const sendBtn = document.querySelector('.report-send-btn');
    
    if (!reason) {
        alert('Please provide a reason for reporting this post.');
        return;
    }
    
    if (!currentReportPostId) {
        alert('Error: No post selected for reporting.');
        return;
    }
    
    // Disable button and show loading state
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=report_post&post_id=${currentReportPostId}&report_reason=${encodeURIComponent(reason)}`
        });
        
        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', text);
            throw new Error('Invalid response from server');
        }
        
        if (data.success) {
            alert(data.message);
            // Update the report button for this post
            const reportBtn = document.querySelector(`.post[data-post-id="${currentReportPostId}"] .report-btn`);
            if (reportBtn) {
                reportBtn.textContent = 'Reported';
                reportBtn.classList.add('reported');
                reportBtn.disabled = true;
            }
            closeReportModal();
        } else {
            alert(data.message || 'Error reporting post.');
        }
    } catch (error) {
        console.error('Error reporting post:', error);
        
        // More specific error messages
        if (error.message.includes('Failed to fetch')) {
            alert('Network error: Please check your internet connection and try again.');
        } else if (error.message.includes('HTTP error')) {
            alert('Server error: Please try again later.');
        } else {
            alert('Error: ' + error.message);
        }
    } finally {
        // Re-enable button
        sendBtn.disabled = false;
        sendBtn.textContent = 'Send Report';
    }
}
// ========== COPY GROUP LINK FUNCTIONALITY ==========

function copyGroupLink() {
    const groupUrl = window.location.href;
    
    navigator.clipboard.writeText(groupUrl).then(() => {
        const copyBtn = document.querySelector('.copy-link-btn');
        const originalText = copyBtn.innerHTML;
        
        copyBtn.innerHTML = '✓ Copied!';
        copyBtn.classList.add('copied');
        
        setTimeout(() => {
            copyBtn.innerHTML = originalText;
            copyBtn.classList.remove('copied');
        }, 2000);
    }).catch(err => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = groupUrl;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        
        const copyBtn = document.querySelector('.copy-link-btn');
        const originalText = copyBtn.innerHTML;
        
        copyBtn.innerHTML = '✓ Copied!';
        copyBtn.classList.add('copied');
        
        setTimeout(() => {
            copyBtn.innerHTML = originalText;
            copyBtn.classList.remove('copied');
        }, 2000);
    });
}

// ========== FULLSCREEN VIDEO FUNCTIONALITY FROM FEED.PHP ==========

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
        closeReportModal();
        closeCommentModal();
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

// Video Controls for Group Videos (regular view - no autoplay)
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

// Initialize video controls
function initVideoControls() {
    // Set all group videos to muted and paused by default in regular view
    document.querySelectorAll('.group-video').forEach(video => {
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

// ========== LIKE BUTTON HANDLERS FROM PEOPLE.PHP ==========

// Like button functionality for posts - PURE OPTIMISTIC
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
    countSpan.textContent = `(${isLiked ? Math.max(0, currentCount - 1) : currentCount + 1})`;

    // Visual feedback
    btn.style.transform = 'scale(1.1)';
    setTimeout(() => {
        btn.style.transform = 'scale(1)';
    }, 200);

    // Send to server in background (completely fire and forget)
    const formData = new FormData();
    formData.append('action', action);
    formData.append('post_id', postId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Like action failed in background:', err);
    });
}

// Ad like button functionality - PURE OPTIMISTIC
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
    countSpan.textContent = `(${isLiked ? Math.max(0, currentCount - 1) : currentCount + 1})`;

    // Visual feedback
    btn.style.transform = 'scale(1.1)';
    setTimeout(() => {
        btn.style.transform = 'scale(1)';
    }, 200);

    // Send to server in background (completely fire and forget)
    const formData = new FormData();
    formData.append('action', action);
    formData.append('ad_id', adId);

    fetch('group.php', {
        method: 'POST',
        body: formData
    }).catch(err => {
        console.error('Ad like action failed in background:', err);
    });
}
// ========== SHARE FUNCTIONALITY ==========

function shareGroupPost(postId, groupId) {
    let postUrl;
    if (groupId) {
        postUrl = `${window.location.origin}/group.php?id=${groupId}&post=${postId}`;
    } else {
        postUrl = `${window.location.origin}/comment.php?post_id=${postId}`;
    }
    
    if (navigator.share) {
        navigator.share({
            title: 'Check out this post',
            url: postUrl
        }).catch(err => {
            copyToClipboard(postUrl);
        });
    } else {
        copyToClipboard(postUrl);
    }
}

async function shareAd(adId) {
    const adUrl = `${window.location.origin}/ad_view.php?id=${adId}`;
    
    if (navigator.share) {
        try {
            await navigator.share({
                title: 'Check out this ad',
                url: adUrl
            });
            
            // Record the share
            const formData = new FormData();
            formData.append('action', 'share_ad');
            formData.append('ad_id', adId);
            
            await fetch('group.php', {
                method: 'POST',
                body: formData
            });
            
        } catch (err) {
            copyAdToClipboard(adUrl, adId);
        }
    } else {
        copyAdToClipboard(adUrl, adId);
    }
}

async function copyAdToClipboard(text, adId) {
    try {
        await navigator.clipboard.writeText(text);
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share_ad');
        formData.append('ad_id', adId);
        
        await fetch('group.php', {
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
        
        // Record the share
        const formData = new FormData();
        formData.append('action', 'share_ad');
        formData.append('ad_id', adId);
        
        await fetch('group.php', {
            method: 'POST',
            body: formData
        });
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Success
    }).catch(err => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    });
}

// ========== JOIN GROUP FUNCTIONALITY ==========

const joinBtn = document.getElementById('joinBtn');
if (joinBtn && !joinBtn.disabled) {
    joinBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        
        // Store original state
        const originalText = joinBtn.textContent;
        const originalClasses = joinBtn.className;
        
        try {
            const formData = new FormData();
            formData.append('action', 'join_group');
            formData.append('group_id', '<?= $groupId ?>');
            
            const response = await fetch('join_group.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                if (data.pending) {
                    joinBtn.textContent = 'Pending';
                    joinBtn.className = originalClasses + ' pending';
                    joinBtn.disabled = true;
                    
                    // Visual feedback
                    joinBtn.style.backgroundColor = '#ed8936';
                    setTimeout(() => {
                        joinBtn.style.backgroundColor = '';
                    }, 1000);
                    
                    // Reload the page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    joinBtn.textContent = 'Member';
                    joinBtn.className = originalClasses + ' visited';
                    
                    // Visual feedback
                    joinBtn.style.backgroundColor = '#48bb78';
                    setTimeout(() => {
                        joinBtn.style.backgroundColor = '';
                    }, 1000);
                    
                    // Reload the page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            } else {
                // On failure, keep the button as "Join" but show brief error color
                joinBtn.style.backgroundColor = '#e53e3e';
                setTimeout(() => {
                    joinBtn.style.backgroundColor = '';
                }, 1000);
            }
        } catch (err) {
            // On error, keep the button as "Join" but show brief error color
            joinBtn.style.backgroundColor = '#e53e3e';
            setTimeout(() => {
                joinBtn.style.backgroundColor = '';
            }, 1000);
        }
    });
}

// Hashtag search
document.querySelectorAll('.hashtag').forEach(tag => {
    tag.addEventListener('click', function() {
        const hashtag = this.textContent.replace('#', '');
        window.location.href = 'search.php?q=' + encodeURIComponent('#' + hashtag);
    });
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Initialize video controls
    initVideoControls();
    
    // Initialize infinite scroll
    initInfiniteScroll();
    
    // Attach initial event listeners
    attachEventListenersToNewPosts();
    
    // Add CSS for processing state
    const style = document.createElement('style');
    style.textContent = `
        .processing {
            pointer-events: none !important;
        }
        .action-btn, .ad-action-btn, .group-action-btn {
            transition: all 0.3s ease !important;
        }
    `;
    document.head.appendChild(style);
});
</script>
</body>
</html>