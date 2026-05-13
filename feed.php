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
    // Clear any cached ad positions or regenerate session data for ads
    if (isset($_SESSION['ad_positions'])) {
        unset($_SESSION['ad_positions']);
    }
    if (isset($_SESSION['shuffled_ads'])) {
        unset($_SESSION['shuffled_ads']);
    }
    // Redirect to same page without refresh parameter
    header("Location: home.php");
    exit;
}

// AJAX handlers for all actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // --- POST HANDLER ---
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

        // Check if both images and videos were uploaded
        $hasImages = false;
        $hasVideos = false;
        
        if (!empty($_FILES['media_files']) && is_array($_FILES['media_files']['name'])) {
            for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
                $type = $_FILES['media_files']['type'][$i];
                if (in_array($type, $allowedPhotoTypes)) $hasImages = true;
                if (in_array($type, $allowedVideoTypes)) $hasVideos = true;
            }
            
            // Prevent mixed uploads
            if ($hasImages && $hasVideos) {
                echo json_encode(['success' => false, 'message' => 'Cannot upload both images and videos in the same post']);
                exit;
            }
            
            // Process uploads
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

        try {
            $pdo->beginTransaction();
            
            // Insert post
            $insertPost = $pdo->prepare("
                INSERT INTO posts (user_id, content, post_type, media_url, privacy_setting, group_id)
                VALUES (:user_id, :content, :post_type, :media_url, :privacy, :group_id)
                RETURNING id
            ");
            $insertPost->execute([
                ':user_id' => $userId,
                ':content' => $content,
                ':post_type' => $postType,
                ':media_url' => $mediaUrlStr,
                ':privacy' => $privacy,
                ':group_id' => $groupId,
            ]);
            $postId = $insertPost->fetchColumn();
            
            // Process hashtags
            preg_match_all('/#(\w+)/', $content, $matches);
            $hashtags = array_unique($matches[1]);
            
            foreach ($hashtags as $tag) {
                if (strlen($tag) > 2) {
                    // Insert or update hashtag
                    $stmt = $pdo->prepare("
                        INSERT INTO hashtags (tag) VALUES (?) 
                        ON CONFLICT (tag) DO UPDATE SET usage_count = hashtags.usage_count + 1
                        RETURNING id
                    ");
                    $stmt->execute([strtolower($tag)]);
                    $hashtagId = $stmt->fetchColumn();
                    
                    // Link hashtag to post
                    $stmt = $pdo->prepare("INSERT INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)");
                    $stmt->execute([$postId, $hashtagId]);
                }
            }
            
            $pdo->commit();
            header("Location: home.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Error creating post: " . $e->getMessage());
        }
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

    // --- SHARE POST HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'share_post' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        $shareContent = trim($_POST['share_content'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Insert shared post
            $stmt = $pdo->prepare("
                INSERT INTO shared_posts (original_post_id, shared_by_user_id, shared_content) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$postId, $userId, $shareContent]);
            
            // Create notification for original post owner
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, source_user_id, post_id, message) 
                SELECT p.user_id, 'share', ?, ?, ?
                FROM posts p WHERE p.id = ?
            ");
            $message = "shared your post";
            $stmt->execute([$userId, $postId, $message, $postId]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Post shared successfully']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error sharing post: ' . $e->getMessage()]);
        }
        exit;
    }

    // --- REPORT POST HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'report_post' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        $reason = trim($_POST['reason'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Please select a reason']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO reported_posts (post_id, reported_by_user_id, reason, description) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$postId, $userId, $reason, $description]);
            
            echo json_encode(['success' => true, 'message' => 'Post reported successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error reporting post: ' . $e->getMessage()]);
        }
        exit;
    }

    // --- MARK NOTIFICATION AS READ ---
    if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
        $userId = $_SESSION['user_id'];
        $notificationId = (int)$_POST['notification_id'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE notifications SET is_read = TRUE 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $userId]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating notification']);
        }
        exit;
    }
}

// REMOVED PAGINATION SETUP

// Fetch groups user belongs to
$groupsStmt = $pdo->prepare("
    SELECT g.id, g.name FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'");
$groupsStmt->execute(['user_id' => $_SESSION['user_id']]);
$userGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Fetch recent notifications
$notificationsStmt = $pdo->prepare("
    SELECT n.*, u.username as source_username, u.profile_pic_url as source_profile_pic,
           p.content as post_content, p.id as post_id
    FROM notifications n
    LEFT JOIN users u ON n.source_user_id = u.id
    LEFT JOIN posts p ON n.post_id = p.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 20
");
$notificationsStmt->execute([$_SESSION['user_id']]);
$notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get 5-degree followed users
function getExtendedFollowedUsers($pdo, $userId, $maxDepth = 5) {
    $allFollowed = [$userId];
    
    $stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $stmt->execute([$userId]);
    $firstDegree = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $allFollowed = array_merge($allFollowed, $firstDegree);
    
    $currentLevel = $firstDegree;
    
    for ($depth = 2; $depth <= $maxDepth; $depth++) {
        if (empty($currentLevel)) break;
        
        $placeholders = str_repeat('?,', count($currentLevel) - 1) . '?';
        $stmt = $pdo->prepare("SELECT DISTINCT followed_id FROM follows WHERE follower_id IN ($placeholders)");
        $stmt->execute($currentLevel);
        $nextLevel = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        $newUsers = array_diff($nextLevel, $allFollowed);
        if (empty($newUsers)) break;
        
        $allFollowed = array_merge($allFollowed, $newUsers);
        $currentLevel = $newUsers;
    }
    
    return array_unique($allFollowed);
}

// Get 5-degree friends
function getExtendedFriends($pdo, $userId, $maxDepth = 5) {
    $allFriends = [$userId];
    
    $stmt = $pdo->prepare("
        SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'
        UNION 
        SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$userId, $userId]);
    $firstDegree = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $allFriends = array_merge($allFriends, $firstDegree);
    
    $currentLevel = $firstDegree;
    
    for ($depth = 2; $depth <= $maxDepth; $depth++) {
        if (empty($currentLevel)) break;
        
        $placeholders = str_repeat('?,', count($currentLevel) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT DISTINCT friend_id FROM friends WHERE user_id IN ($placeholders) AND status = 'accepted'
            UNION 
            SELECT DISTINCT user_id FROM friends WHERE friend_id IN ($placeholders) AND status = 'accepted'
        ");
        $params = array_merge($currentLevel, $currentLevel);
        $stmt->execute($params);
        $nextLevel = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        $newUsers = array_diff($nextLevel, $allFriends);
        if (empty($newUsers)) break;
        
        $allFriends = array_merge($allFriends, $newUsers);
        $currentLevel = $newUsers;
    }
    
    return array_unique($allFriends);
}

// Get users whose profiles were visited by current user
function getVisitedProfiles($pdo, $userId, $limit = 50) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT target_user_id 
            FROM profile_visits 
            WHERE visitor_id = ? 
            AND visited_at > NOW() - INTERVAL '30 days'
            ORDER BY visited_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {
        return [];
    }
}

// Calculate post engagement score
function calculateEngagementScore($pdo, $postId, $currentUserId) {
    $weights = [
        'likes' => 1.0,
        'comments' => 1.5,
        'saves' => 2.0,
        'shares' => 1.8,
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
        $score *= 1.3;
    }
    
    return $score;
}

// Calculate relationship score between users
function calculateRelationshipScore($pdo, $currentUserId, $authorId, $extendedFollowed, $extendedFriends, $visitedProfiles) {
    if ($currentUserId == $authorId) {
        return 10.0;
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
    
    // Extended network connections
    if (in_array($authorId, $extendedFollowed)) {
        $score += 3.0;
    }
    
    if (in_array($authorId, $extendedFriends)) {
        $score += 4.0;
    }
    
    // Profile visit boost
    if (in_array($authorId, $visitedProfiles)) {
        $score += 2.5;
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
    $score += $recentInteractions * 0.5;
    
    return $score;
}

// Helper function to determine network type for display
function getNetworkType($authorId, $currentUserId, $directFollowed, $extendedFollowed, $extendedFriends, $visitedProfiles) {
    if ($authorId == $currentUserId) return 'you';
    if (in_array($authorId, $directFollowed)) return 'direct_follow';
    if (in_array($authorId, $extendedFollowed)) return 'extended_follow';
    if (in_array($authorId, $extendedFriends)) return 'extended_friend';
    if (in_array($authorId, $visitedProfiles)) return 'visited_profile';
    return 'suggested';
}

// ========== NEW: ADS TARGETING FUNCTIONS ==========

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

// Continent definitions (must match run_ads.php)
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
    
    // Store original ads in session for repetition
    if (!isset($_SESSION['original_ads'])) {
        $_SESSION['original_ads'] = $targetedAds;
    }
    
    // Check if we need to shuffle ads
    if (!isset($_SESSION['shuffled_ads']) || isset($_GET['refresh'])) {
        // Shuffle the ads randomly
        shuffle($targetedAds);
        $_SESSION['shuffled_ads'] = $targetedAds;
    } else {
        // Use previously shuffled ads
        $targetedAds = $_SESSION['shuffled_ads'];
    }
    
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

// ========== END OF NEW ADS FUNCTIONS ==========

$userId = $_SESSION['user_id'];

// Get extended networks
try {
    $extendedFollowedUsers = getExtendedFollowedUsers($pdo, $userId, 3);
    $extendedFriends = getExtendedFriends($pdo, $userId, 3);
    $visitedProfiles = getVisitedProfiles($pdo, $userId, 30);
} catch (Exception $e) {
    $stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $stmt->execute([$userId]);
    $extendedFollowedUsers = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $extendedFollowedUsers[] = $userId;
    
    $stmt = $pdo->prepare("
        SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'
        UNION 
        SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$userId, $userId]);
    $extendedFriends = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $extendedFriends[] = $userId;
    
    $visitedProfiles = [];
}

// Get direct followed users for follow buttons
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$directFollowedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// SIMPLIFIED FEED QUERY - Fixed parameter binding
$allNetworkUsers = array_unique(array_merge($extendedFollowedUsers, $extendedFriends));
$allNetworkUsers = array_filter($allNetworkUsers);

if (empty($allNetworkUsers)) {
    $allNetworkUsers = [$userId];
}

// Build placeholders for the IN clause
$placeholders = str_repeat('?,', count($allNetworkUsers) - 1) . '?';

// Get posts from extended network WITHOUT PAGINATION
$postStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = ? AND gm.status = 'approved'
    WHERE 
      (p.privacy_setting = 'public' AND p.user_id IN ($placeholders))
      OR (p.privacy_setting = 'friends' AND p.user_id IN ($placeholders))
      OR (p.privacy_setting = 'private' AND p.user_id = ?)
      OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
    ORDER BY p.created_at DESC
    LIMIT 100  -- Increased limit to show more posts at once
");

// Build parameters array
$params = array_merge(
    [$userId],
    $allNetworkUsers,
    $allNetworkUsers,  
    [$userId]
);

$postStmt->execute($params);
$allPosts = $postStmt->fetchAll(PDO::FETCH_ASSOC);

// REMOVED PAGINATION COUNT QUERY

// ========== NEW: GET TARGETED ADS ==========
$targetedAds = getTargetedAds($pdo, $userId, 50); // Increased limit for repetition
$adCount = count($targetedAds);
// ========== END NEW ADS CODE ==========

// INSTAGRAM-STYLE FEED ALGORITHM
$feedPosts = [];

// Calculate scores for each post
foreach ($allPosts as $post) {
    $engagementScore = calculateEngagementScore($pdo, $post['id'], $userId);
    $relationshipScore = calculateRelationshipScore($pdo, $userId, $post['author_id'], $extendedFollowedUsers, $extendedFriends, $visitedProfiles);
    
    // Time decay factor (newer posts get boost)
    $postAge = time() - strtotime($post['created_at']);
    $timeDecay = max(0.1, 1 - ($postAge / (7 * 24 * 60 * 60)));
    
    // Content type preference
    $contentWeights = [
        'video' => 1.3,
        'photo' => 1.1,
        'text' => 1.0,
        'link' => 1.0
    ];
    $contentWeight = $contentWeights[$post['post_type']] ?? 1.0;
    
    // Network distance penalty/gain
    $networkWeight = 1.0;
    if (in_array($post['author_id'], $directFollowedUserIds)) {
        $networkWeight = 1.2;
    } elseif (in_array($post['author_id'], $extendedFollowedUsers) || in_array($post['author_id'], $extendedFriends)) {
        $networkWeight = 1.1;
    }
    
    // Final score calculation
    $finalScore = (
        $engagementScore * 0.35 +
        $relationshipScore * 0.45 +
        $timeDecay * 0.1 +
        (in_array($post['author_id'], $visitedProfiles) ? 2.0 : 0)
    ) * $contentWeight * $networkWeight;
    
    $post['feed_score'] = $finalScore;
    $post['network_type'] = getNetworkType($post['author_id'], $userId, $directFollowedUserIds, $extendedFollowedUsers, $extendedFriends, $visitedProfiles);
    $feedPosts[] = $post;
}

// Sort by feed score (highest first)
usort($feedPosts, function($a, $b) {
    return $b['feed_score'] <=> $a['feed_score'];
});

// Take top posts for display
$posts = array_slice($feedPosts, 0, 50);

// Add following status and additional data to posts
foreach ($posts as &$post) {
    $post['is_following'] = in_array($post['author_id'], $directFollowedUserIds);
    $post['hashtags'] = getPostHashtags($pdo, $post['id']);
    $post['share_count'] = getShareCount($pdo, $post['id']);
}
unset($post);

// Count unread messages
$unreadCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = :user_id AND read_at IS NULL");
    $stmt->execute(['user_id' => $userId]);
    $unreadCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // log error or ignore
}

$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';
$userId = $_SESSION['user_id'] ?? null;

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

$postCount = 0;
$boostCount = count($boostedPosts);
require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Home - Smart Feed</title>
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

/* Notifications */
.notifications-container {
    position: relative;
    display: inline-block;
}

.notifications-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    width: 350px;
    background: #2c2c3d;
    border: 1px solid #444;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 12px;
    border-bottom: 1px solid #444;
    cursor: pointer;
    transition: background 0.2s;
}

.notification-item:hover {
    background: #3c3c4d;
}

.notification-item.unread {
    background: #2a3c5a;
}

.notification-content {
    display: flex;
    align-items: center;
    gap: 10px;
}

.notification-profile-pic {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.notification-text {
    flex: 1;
    font-size: 14px;
}

.notification-time {
    font-size: 12px;
    color: #888;
}

/* Trending Hashtags */
.trending-sidebar {
    background: #2c2c3d;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.trending-title {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 15px;
    color: #7b68ee;
}

.trending-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #444;
}

.trending-tag {
    color: #7b68ee;
    cursor: pointer;
}

.trending-count {
    color: #888;
    font-size: 12px;
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

/* Share button */
.share-btn {
    cursor: pointer;
    color: #28a745;
}

.share-btn:hover {
    color: #218838;
}

/* Report button */
.report-btn {
    cursor: pointer;
    color: #dc3545;
    font-size: 12px;
    margin-left: auto;
}

.report-btn:hover {
    color: #c82333;
}

/* Boost Button */
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

/* Network type badges */
.network-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 8px;
    font-weight: normal;
}
.badge-you { background: #007bff; color: white; }
.badge-direct { background: #28a745; color: white; }
.badge-extended { background: #ffc107; color: black; }
.badge-friend { background: #17a2b8; color: white; }
.badge-visited { background: #6f42c1; color: white; }
.badge-suggested { background: #6c757d; color: white; }

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

/* Instagram-style modal */
.instagram-modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0; top: 0; width: 100vw; height: 100vh;
  overflow: auto;
  background-color: rgba(0,0,0,0.8);
}
.instagram-modal-content {
  background-color: #fff;
  margin: 50px auto;
  padding: 0;
  border-radius: 12px;
  max-width: 500px;
  box-shadow: 0 5px 15px rgba(0,0,0,0.3);
  position: relative;
  overflow: hidden;
}
.instagram-modal-header {
  padding: 15px;
  border-bottom: 1px solid #dbdbdb;
  text-align: center;
  position: relative;
  font-weight: 600;
}
.instagram-close-btn {
  position: absolute;
  right: 15px;
  top: 15px;
  font-size: 24px;
  border: none;
  background: none;
  cursor: pointer;
}
.instagram-modal-body {
  padding: 20px;
}
.instagram-tab-container {
  display: flex;
  border-bottom: 1px solid #dbdbdb;
  margin-bottom: 15px;
}
.instagram-tab {
  flex: 1;
    text-align: center;
    padding: 10px;
    cursor: pointer;
    font-weight: 600;
    color: #8e8e8e;
}
.instagram-tab.active {
  color: #0095f6;
  border-bottom: 2px solid #0095f6;
}
.instagram-upload-area {
  border: 2px dashed #dbdbdb;
  border-radius: 8px;
  padding: 30px;
  text-align: center;
  margin-bottom: 15px;
  cursor: pointer;
}
.instagram-upload-icon {
  font-size: 40px;
  color: #0095f6;
  margin-bottom: 10px;
}
.instagram-preview {
  display: none;
  margin-bottom: 15px;
  text-align: center;
}
.instagram-preview img, .instagram-preview video {
  max-width: 100%;
  max-height: 300px;
  border-radius: 8px;
}
.instagram-preview-multi {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 15px;
}
.instagram-preview-multi-item {
  position: relative;
  border-radius: 8px;
  overflow: hidden;
}
.instagram-preview-multi-item img, .instagram-preview-multi-item video {
  width: 100%;
  height: 120px;
  object-fit: cover;
}
.instagram-preview-count {
  position: absolute;
  top: 5px;
  right: 5px;
  background: rgba(0,0,0,0.7);
  color: white;
  border-radius: 50%;
  width: 25px;
  height: 25px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}
.instagram-form-controls {
  margin-top: 15px;
}
.instagram-form-controls textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid #dbdbdb;
  border-radius: 8px;
  resize: none;
  margin-bottom: 10px;
  font-family: inherit;
}
.instagram-form-controls select {
  width: 100%;
  padding: 8px;
  border: 1px solid #dbdbdb;
  border-radius: 8px;
  margin-bottom: 10px;
}
.instagram-submit-btn {
  background: #0095f6;
  color: white;
  border: none;
  border-radius: 8px;
  padding: 10px;
  width: 100%;
  font-weight: 600;
  cursor: pointer;
}
.instagram-submit-btn:disabled {
  background: #b2dffc;
  cursor: not-allowed;
}

/* Share Modal */
.share-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.8);
}

.share-modal-content {
    background: #2c2c3d;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
}

.share-textarea {
    width: 100%;
    height: 100px;
    background: #1e1e2f;
    border: 1px solid #444;
    border-radius: 4px;
    color: white;
    padding: 10px;
    margin-bottom: 15px;
    resize: vertical;
}

/* Report Modal */
.report-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.8);
}

.report-modal-content {
    background: #2c2c3d;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
}

.report-reason {
    width: 100%;
    padding: 10px;
    background: #1e1e2f;
    border: 1px solid #444;
    border-radius: 4px;
    color: white;
    margin-bottom: 15px;
}

.report-description {
    width: 100%;
    height: 100px;
    background: #1e1e2f;
    border: 1px solid #444;
    border-radius: 4px;
    color: white;
    padding: 10px;
    resize: vertical;
}

/* REMOVED PAGINATION STYLES */

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

/* Related Users Section */
.related-users-section {
    margin: 40px 0;
    padding: 25px 0;
    background: linear-gradient(135deg, rgba(123, 104, 238, 0.1) 0%, rgba(44, 44, 61, 0.8) 100%);
    border-radius: 15px;
    border: 1px solid #7b68ee;
    position: relative;
    overflow: hidden;
}

.related-users-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #7b68ee, #9370db, #7b68ee);
}

.related-users-header {
    text-align: center;
    margin-bottom: 25px;
    padding: 0 20px;
}

.related-users-title {
    color: #7b68ee;
    font-size: 22px;
    font-weight: bold;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.related-users-subtitle {
    color: #aaa;
    font-size: 14px;
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

/* New Styles for Ads */
.ad-post {
    border: 2px solid #ffd700;
    background: linear-gradient(135deg, #2c2c3d 0%, #3a3a4d 100%);
    position: relative;
}

.ad-label {
    background: linear-gradient(45deg, #ffd700, #ff8c00);
    color: #000;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: bold;
    font-size: 12px;
    margin-bottom: 10px;
    display: inline-block;
}

.cta-button {
    background: linear-gradient(45deg, #007bff, #0056b3);
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
}

.cta-button:hover {
    background: linear-gradient(45deg, #0056b3, #004085);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.ad-header {
    font-size: 18px;
    font-weight: bold;
    color: #ffd700;
    margin-bottom: 8px;
}

.ad-description {
    color: #ccc;
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
    max-height: 400px;
    object-fit: contain;
    border-radius: 8px;
}

.ad-media-container video {
    width: 100%;
    max-height: 400px;
    border-radius: 8px;
}

@media (max-width: 600px) {
  .post-media {
    grid-template-columns: 1fr 1fr;
    grid-gap: 4px;
  }
  .post-media img, .overlay {
    height: 120px;
  }
  .instagram-modal-content {
    margin: 20px 10px;
    width: auto;
  }
  .video-reel-item video {
    max-height: 500px;
  }
  .follow-btn {
    padding: 4px 10px;
    font-size: 11px;
  }
  .notifications-dropdown {
    width: 300px;
    right: -50px;
  }
  .related-users-section {
    margin: 30px 0;
    padding: 20px 0;
  }
  .related-users-title {
    font-size: 18px;
  }
  .ad-media-container img,
  .ad-media-container video {
    max-height: 300px;
  }
  .refresh-btn {
    padding: 8px 16px;
    font-size: 14px;
    margin-left: 5px;
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

    <!-- Refresh Button -->
    <a href="home.php?refresh=true" class="refresh-btn">🔄 Refresh Ads</a>

    <!-- Notifications -->
    <div class="notifications-container">
        <button onclick="toggleNotifications()" style="background: none; border: none; color: white; cursor: pointer; position: relative; padding: 8px 16px; border-radius: 20px; background: #2c2c3d;">
            🔔 Notifications
            <?php if ($unreadNotificationsCount > 0): ?>
                <span style="background: red; color: white; border-radius: 50%; padding: 2px 6px; font-size: 12px; position: absolute; top: -8px; right: -8px;">
                    <?= $unreadNotificationsCount ?>
                </span>
            <?php endif; ?>
        </button>
        <div class="notifications-dropdown" id="notificationsDropdown">
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?= !$notification['is_read'] ? 'unread' : '' ?>" 
                     data-id="<?= $notification['id'] ?>"
                     onclick="handleNotificationClick(<?= $notification['id'] ?>, <?= $notification['post_id'] ?? 'null' ?>)">
                    <div class="notification-content">
                        <img src="<?= htmlspecialchars($notification['source_profile_pic'] ?? 'default_profile.png') ?>" 
                             class="notification-profile-pic" alt="Profile">
                        <div class="notification-text">
                            <strong><?= htmlspecialchars($notification['source_username'] ?? 'Someone') ?></strong> 
                            <?= htmlspecialchars($notification['message']) ?>
                        </div>
                        <div class="notification-time">
                            <?= timeAgo($notification['created_at']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($notifications)): ?>
                <div class="notification-item">
                    <div class="notification-text" style="text-align: center; color: #888;">No notifications yet</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Profile Section -->
<div style="text-align: center; padding: 15px; background: #2c2c3d; margin-top: 20px; box-shadow: 0 2px 6px #ccc; border: 3px solid #7b68ee; border-radius: 10px;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
</div>

<!-- Create Post Trigger -->
<div id="openPostModalBtn" style="margin-top: 20px; cursor:pointer;">
    <input type="text" style="background-color: #1e1e2f;border: 3px solid #7b68ee;width: 100%;height: 30px;border-radius: 60px; color: white; padding: 0 15px;" placeholder="What on your mind?" readonly>
</div>

<!-- Trending Hashtags Sidebar -->
<div class="trending-sidebar">
    <div class="trending-title">🔥 Trending Hashtags</div>
    <?php foreach ($trendingHashtags as $hashtag): ?>
        <div class="trending-item">
            <span class="trending-tag" onclick="searchHashtag('<?= $hashtag['tag'] ?>')">
                #<?= htmlspecialchars($hashtag['tag']) ?>
            </span>
            <span class="trending-count"><?= $hashtag['recent_posts'] ?> posts</span>
        </div>
    <?php endforeach; ?>
    <?php if (empty($trendingHashtags)): ?>
        <div style="color: #888; text-align: center;">No trending hashtags yet</div>
    <?php endif; ?>
</div>

<!-- Instagram-style Post Modal -->
<div id="instagramModal" class="instagram-modal">
  <div class="instagram-modal-content">
    <div class="instagram-modal-header">
      Create new post
      <button class="instagram-close-btn" id="closeInstagramModalBtn">&times;</button>
    </div>
    <div class="instagram-modal-body">
      <div class="instagram-tab-container">
        <div class="instagram-tab active" data-tab="image">Image</div>
        <div class="instagram-tab" data-tab="video">Video</div>
      </div>
      
      <form method="POST" enctype="multipart/form-data" id="instagramPostForm">
        <input type="hidden" name="action" value="new_post">
        
        <div id="imageUploadArea" class="instagram-upload-area">
          <div class="instagram-upload-icon">📷</div>
          <p>Select photos to share</p>
          <input type="file" name="media_files[]" accept="image/*" multiple style="display: none;" id="imageFileInput">
        </div>
        
        <div id="videoUploadArea" class="instagram-upload-area" style="display: none;">
          <div class="instagram-upload-icon">🎬</div>
          <p>Select videos to share</p>
          <input type="file" name="media_files[]" accept="video/*" multiple style="display: none;" id="videoFileInput">
        </div>
        
        <div id="imagePreview" class="instagram-preview"></div>
        <div id="videoPreview" class="instagram-preview"></div>
        
        <div class="instagram-form-controls">
          <textarea name="content" placeholder="Write a caption..." id="postCaption"></textarea>
          
          <select name="privacy" id="privacySelect" required>
            <option value="public">Public (Everyone)</option>
            <option value="friends">Friends Only</option>
            <option value="private">Private (Only Me)</option>
            <option value="groups-only">Groups Only</option>
          </select>
          
          <select name="group_id" id="groupSelect" class="group-select" style="display: none;">
            <option value="">-- Select Group --</option>
            <?php foreach ($userGroups as $group): ?>
              <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
            <?php endforeach; ?>
          </select>
          
          <button type="submit" class="instagram-submit-btn" id="instagramSubmitBtn" disabled>Share</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Share Modal -->
<div id="shareModal" class="share-modal">
    <div class="share-modal-content">
        <h3 style="margin-top: 0; color: white;">Share Post</h3>
        <form id="shareForm">
            <input type="hidden" id="sharePostId" name="post_id">
            <textarea class="share-textarea" name="share_content" placeholder="Add a comment to your share..."></textarea>
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="closeShareModal()" style="flex: 1; padding: 10px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="submit" style="flex: 1; padding: 10px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">Share</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Modal -->
<div id="reportModal" class="report-modal">
    <div class="report-modal-content">
        <h3 style="margin-top: 0; color: white;">Report Post</h3>
        <form id="reportForm">
            <input type="hidden" id="reportPostId" name="post_id">
            <select class="report-reason" name="reason" required>
                <option value="">Select a reason</option>
                <option value="spam">Spam</option>
                <option value="harassment">Harassment</option>
                <option value="hate_speech">Hate Speech</option>
                <option value="violence">Violence</option>
                <option value="false_information">False Information</option>
                <option value="other">Other</option>
            </select>
            <textarea class="report-description" name="description" placeholder="Please provide more details..."></textarea>
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="closeReportModal()" style="flex: 1; padding: 10px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="submit" style="flex: 1; padding: 10px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Report</button>
            </div>
        </form>
    </div>
</div>

<div class="posts" id="postsContainer">
    <?php 
    $postCounter = 0;
    $adCounter = 0;
    
    foreach ($posts as $post): 
        $postCounter++;
    ?>
        <!-- Regular Post -->
        <div class="post" data-post-id="<?= $post['id'] ?>">
            <!-- Debug: Uncomment to see scores and network types -->
            <!-- <div class="post-score">Score: <?= round($post['feed_score'], 2) ?> | <?= $post['network_type'] ?></div> -->
            
            <div class="post-header">
                <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                     alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                <div class="user-info">
                    <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                        <?= htmlspecialchars($post['username']) ?>
                        <span class="network-badge badge-<?= 
                            $post['network_type'] === 'you' ? 'you' :
                            ($post['network_type'] === 'direct_follow' ? 'direct' :
                            ($post['network_type'] === 'extended_follow' ? 'extended' :
                            ($post['network_type'] === 'extended_friend' ? 'friend' :
                            ($post['network_type'] === 'visited_profile' ? 'visited' : 'suggested'))))
                        ?>">
                            <?= 
                                $post['network_type'] === 'you' ? 'You' :
                                ($post['network_type'] === 'direct_follow' ? 'Following' :
                                ($post['network_type'] === 'extended_follow' ? 'Network' :
                                ($post['network_type'] === 'extended_friend' ? 'Friend' :
                                ($post['network_type'] === 'visited_profile' ? 'Visited' : 'Suggested'))))
                            ?>
                        </span>
                    </div>
                    <div class="timestamp"><?= timeAgo($post['created_at']) ?></div>
                </div>
            
                <?php if ($post['author_id'] !== $userId): ?>
                    <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
                        <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                    </button>
                <?php endif; ?>
                
                <!-- Report Button -->
                <button class="report-btn" onclick="openReportModal(<?= $post['id'] ?>)">Report</button>
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
                <div><a href="<?= htmlspecialchars($post['media_url']) ?>" target="_blank" style="color: #7b68ee;"><?= htmlspecialchars($post['media_url']) ?></a></div>
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
                <span class="share-btn" onclick="openShareModal(<?= $post['id'] ?>)">
                  Share (<?= $post['share_count'] ?>)
                </span>
            </div>
        </div>

        <?php
        // Insert ads every 4 posts (changed from 5 to 4 as requested)
        if ($postCounter % 4 === 0 && $adCount > 0):
            $adIndex = $adCounter % $adCount;
            $ad = $targetedAds[$adIndex];
            $adCounter++;
        ?>
            <!-- ADVERTISEMENT -->
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
                            <video class="ad-video" muted loop playsinline preload="auto" style="width: 100%; max-height: 400px; border-radius: 8px;">
                                <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        <?php else: ?>
                            <?php
                            $adImages = explode(',', $ad['media_path']);
                            $adImageCount = count($adImages);
                            $adFirstFour = array_slice($adImages, 0, 4);
                            $adExtraCount = $adImageCount - 4;
                            ?>
                            <div class="post-media">
                                <?php foreach ($adFirstFour as $index => $image):
                                    $image = trim($image);
                                ?>
                                    <div style="position:relative;">
                                        <?php if ($index < 3): ?>
                                            <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                        <?php elseif ($index === 3 && $adExtraCount > 0): ?>
                                            <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                            <div class="overlay">
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

                <div class="actions">
                    <span style="color: #888; font-size: 12px;">Advertisement</span>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Insert relate2.php every 10 posts
        if ($postCounter % 9 === 0 && $postCounter < count($posts)):
        ?>
            <!-- People You May Know Section -->
            <div class="related-users-section">
                <div class="related-users-header">
                    <div class="related-users-title">
                        <span>🔍</span>
                        Discover More People
                    </div>
                    <p class="related-users-subtitle">Connect with people from your extended network</p>
                </div>
                <?php require_once 'relate2.php'; ?>
            </div>
        <?php endif; ?>

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
                        <button class="follow-btn <?= in_array($boostPost['author_id'], $directFollowedUserIds) ? 'following' : '' ?>" data-user-id="<?= $boostPost['author_id'] ?>">
                            <?= in_array($boostPost['author_id'], $directFollowedUserIds) ? 'Following' : 'Follow' ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="post-content" id="post-content-boost-<?= $boostPost['id'] ?>">
                    <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
                </div>
                
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
                    <span class="share-btn" onclick="openShareModal(<?= $boostPost['id'] ?>)">
                      Share (<?= getShareCount($pdo, $boostPost['id']) ?>)
                    </span>
                </div>
            </div>
            <?php
        }
        ?>
    <?php endforeach; ?>
    
    <?php if (empty($posts) && empty($targetedAds)): ?>
        <div class="post" style="text-align: center; padding: 40px;">
            <h3>No posts yet</h3>
            <p>Start following people or create your first post!</p>
        </div>
    <?php endif; ?>
</div>

<!-- REMOVED PAGINATION SECTION -->

<script>
// Show More / Show Less Toggle
document.querySelectorAll('.show-more-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const postId = btn.dataset.postId;
        const content = document.getElementById('post-content-' + postId.replace('boost-', ''));
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
            const res = await fetch(window.location.href, { method: 'POST', body: formData });
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

// Search functionality
function searchHashtag(tag) {
    window.location.href = 'search.php?q=' + encodeURIComponent('#' + tag);
}

// Notifications functionality
function toggleNotifications() {
    const dropdown = document.getElementById('notificationsDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

async function handleNotificationClick(notificationId, postId) {
    // Mark as read
    await markNotificationAsRead(notificationId);
    
    // Redirect to post if available
    if (postId) {
        window.location.href = 'comment.php?post_id=' + postId;
    }
}

async function markNotificationAsRead(notificationId) {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_notification_read');
        formData.append('notification_id', notificationId);
        
        await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        // Update UI
        const notificationItem = document.querySelector(`[data-id="${notificationId}"]`);
        if (notificationItem) {
            notificationItem.classList.remove('unread');
            // Update notification count
            const countElement = document.querySelector('.notifications-container span');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent);
                if (currentCount > 1) {
                    countElement.textContent = currentCount - 1;
                } else {
                    countElement.remove();
                }
            }
        }
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
}

// Share functionality
function openShareModal(postId) {
    document.getElementById('sharePostId').value = postId;
    document.getElementById('shareModal').style.display = 'block';
}

function closeShareModal() {
    document.getElementById('shareModal').style.display = 'none';
    document.getElementById('shareForm').reset();
}

document.getElementById('shareForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'share_post');
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Post shared successfully!');
            closeShareModal();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        alert('Error sharing post');
    }
});

// Report functionality
function openReportModal(postId) {
    document.getElementById('reportPostId').value = postId;
    document.getElementById('reportModal').style.display = 'block';
}

function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
    document.getElementById('reportForm').reset();
}

document.getElementById('reportForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'report_post');
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Post reported successfully! Our team will review it.');
            closeReportModal();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        alert('Error reporting post');
    }
});

// Instagram-style modal scripting
const instagramModal = document.getElementById('instagramModal');
const openBtn = document.getElementById('openPostModalBtn');
const closeBtn = document.getElementById('closeInstagramModalBtn');
const privacySelect = document.getElementById('privacySelect');
const groupSelect = document.getElementById('groupSelect');
const imageTab = document.querySelector('[data-tab="image"]');
const videoTab = document.querySelector('[data-tab="video"]');
const imageUploadArea = document.getElementById('imageUploadArea');
const videoUploadArea = document.getElementById('videoUploadArea');
const imageFileInput = document.getElementById('imageFileInput');
const videoFileInput = document.getElementById('videoFileInput');
const imagePreview = document.getElementById('imagePreview');
const videoPreview = document.getElementById('videoPreview');
const submitBtn = document.getElementById('instagramSubmitBtn');
const postForm = document.getElementById('instagramPostForm');
const postCaption = document.getElementById('postCaption');

// Open modal
openBtn.addEventListener('click', () => {
  instagramModal.style.display = 'block';
  resetForm();
});

// Close modal
closeBtn.addEventListener('click', () => {
  instagramModal.style.display = 'none';
});

window.addEventListener('click', (event) => {
  if (event.target === instagramModal) {
    instagramModal.style.display = 'none';
  }
  if (event.target === document.getElementById('shareModal')) {
    closeShareModal();
  }
  if (event.target === document.getElementById('reportModal')) {
    closeReportModal();
  }
  if (!event.target.closest('.notifications-container')) {
    document.getElementById('notificationsDropdown').style.display = 'none';
  }
});

// Tab switching
imageTab.addEventListener('click', () => {
  imageTab.classList.add('active');
  videoTab.classList.remove('active');
  imageUploadArea.style.display = 'block';
  videoUploadArea.style.display = 'none';
  imagePreview.style.display = 'block';
  videoPreview.style.display = 'none';
});

videoTab.addEventListener('click', () => {
  videoTab.classList.add('active');
  imageTab.classList.remove('active');
  imageUploadArea.style.display = 'none';
  videoUploadArea.style.display = 'block';
  imagePreview.style.display = 'none';
  videoPreview.style.display = 'block';
});

// File upload handling
imageUploadArea.addEventListener('click', () => {
  imageFileInput.click();
});

videoUploadArea.addEventListener('click', () => {
  videoFileInput.click();
});

imageFileInput.addEventListener('change', (e) => {
  handleFileSelection(e.target.files, 'image');
});

videoFileInput.addEventListener('change', (e) => {
  handleFileSelection(e.target.files, 'video');
});

function handleFileSelection(files, type) {
  if (files.length === 0) return;
  
  const previewArea = type === 'image' ? imagePreview : videoPreview;
  previewArea.innerHTML = '';
  
  if (type === 'image') {
    for (let i = 0; i < files.length; i++) {
      if (!files[i].type.startsWith('image/')) {
        alert('Please select only images');
        resetForm();
        return;
      }
    }
  } else {
    for (let i = 0; i < files.length; i++) {
      if (!files[i].type.startsWith('video/')) {
        alert('Please select only videos');
        resetForm();
        return;
      }
    }
  }
  
  if (files.length === 1) {
    const file = files[0];
    const url = URL.createObjectURL(file);
    
    if (type === 'image') {
      previewArea.innerHTML = `<img src="${url}" alt="Preview">`;
    } else {
      previewArea.innerHTML = `<video controls autoplay muted><source src="${url}" type="${file.type}"></video>`;
    }
  } else {
    previewArea.innerHTML = '<div class="instagram-preview-multi"></div>';
    const multiContainer = previewArea.querySelector('.instagram-preview-multi');
    
    for (let i = 0; i < Math.min(files.length, 4); i++) {
      const file = files[i];
      const url = URL.createObjectURL(file);
      
      const item = document.createElement('div');
      item.className = 'instagram-preview-multi-item';
      
      if (type === 'image') {
        item.innerHTML = `<img src="${url}" alt="Preview">`;
      } else {
        item.innerHTML = `<video muted><source src="${url}" type="${file.type}"></video>`;
      }
      
      if (i === 3 && files.length > 4) {
        item.innerHTML += `<div class="instagram-preview-count">+${files.length - 4}</div>`;
      }
      
      multiContainer.appendChild(item);
    }
  }
  
  previewArea.style.display = 'block';
  submitBtn.disabled = false;
}

// Privacy setting change
privacySelect.addEventListener('change', () => {
  if(privacySelect.value === 'groups-only') {
    groupSelect.style.display = 'block';
  } else {
    groupSelect.style.display = 'none';
    groupSelect.value = '';
  }
});

// Form submission
postForm.addEventListener('submit', (e) => {
  if (postCaption.value.trim() === '' && 
      (!imageFileInput.files || imageFileInput.files.length === 0) && 
      (!videoFileInput.files || videoFileInput.files.length === 0)) {
    e.preventDefault();
    alert('Please add a caption or media to your post');
    return;
  }
});

function resetForm() {
  imageFileInput.value = '';
  videoFileInput.value = '';
  imagePreview.innerHTML = '';
  videoPreview.innerHTML = '';
  imagePreview.style.display = 'none';
  videoPreview.style.display = 'none';
  postCaption.value = '';
  submitBtn.disabled = true;
  imageTab.classList.add('active');
  videoTab.classList.remove('active');
  imageUploadArea.style.display = 'block';
  videoUploadArea.style.display = 'none';
  groupSelect.style.display = 'none';
  groupSelect.value = '';
  privacySelect.value = 'public';
}

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

// Enhanced Video pause on scroll away functionality for both regular videos and ads
function initVideoObservers() {
    // Regular video containers
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

    // Ad video containers
    const adVideos = document.querySelectorAll('.ad-video');
    
    adVideos.forEach(video => {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Video ad is in view - play it
                    video.play().catch(e => {
                        console.log('Ad video autoplay prevented:', e);
                        // If autoplay is blocked, show play button or handle accordingly
                    });
                } else {
                    // Video ad is out of view - pause it
                    if (!video.paused) {
                        video.pause();
                    }
                }
            });
        }, { 
            threshold: 0.5, // 50% of the ad video must be visible
            rootMargin: '0px'
        });
        
        observer.observe(video);
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

// Close notifications dropdown when clicking notification item
document.addEventListener('click', function(event) {
    if (!event.target.closest('.notifications-container')) {
        document.getElementById('notificationsDropdown').style.display = 'none';
    }
});

// Additional function to handle ad video click for sound
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('ad-video')) {
        const video = event.target;
        video.muted = !video.muted;
    }
});a

// Refresh button functionality
document.querySelector('.refresh-btn').addEventListener('click', function(e) {
    // The refresh is handled server-side via the href link
    // This is just for visual feedback
    this.style.transform = 'rotate(180deg)';
    setTimeout(() => {
        this.style.transform = 'rotate(0deg)';
    }, 500);
});
</script>

</body>
</html>