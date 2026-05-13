<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


// Twitter-style session tracking
if (!isset($_SESSION['last_refresh'])) {
    $_SESSION['last_refresh'] = time();
    $_SESSION['viewed_tweets'] = [];
}

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Follow/Unfollow handler
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

    // Create new tweet
    if (isset($_POST['action']) && $_POST['action'] === 'create_tweet' && isset($_POST['content'])) {
        $userId = $_SESSION['user_id'];
        $content = trim($_POST['content']);
        $mediaUrls = $_POST['media_urls'] ?? '';
        
        if (empty($content) && empty($mediaUrls)) {
            echo json_encode(['success' => false, 'message' => 'Tweet cannot be empty']);
            exit;
        }
        
        if (strlen($content) > 280) {
            echo json_encode(['success' => false, 'message' => 'Tweet exceeds 280 characters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO posts (user_id, content, media_url, post_type, privacy_setting, created_at) 
                VALUES (?, ?, ?, 'tweet', 'public', NOW())
                RETURNING id, created_at
            ");
            $stmt->execute([$userId, $content, $mediaUrls]);
            $newTweet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get user info for the response
            $userStmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'tweet' => [
                    'id' => $newTweet['id'],
                    'content' => $content,
                    'media_url' => $mediaUrls,
                    'created_at' => $newTweet['created_at'],
                    'formatted_date' => 'Just now',
                    'username' => $user['username'],
                    'profile_pic_url' => $user['profile_pic_url'],
                    'likes_count' => 0,
                    'comments_count' => 0,
                    'is_liked' => false,
                    'is_saved' => false,
                    'author_id' => $userId
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to create tweet']);
        }
        exit;
    }

    // Save/Unsave handler
    if (isset($_POST['action']) && in_array($_POST['action'], ['save', 'unsave']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        try {
            if ($_POST['action'] === 'save') {
                $stmt = $pdo->prepare("INSERT INTO post_saves (user_id, post_id, created_at) VALUES (?, ?, NOW()) ON CONFLICT DO NOTHING");
                $stmt->execute([$userId, $postId]);
                echo json_encode(['success' => true, 'action' => 'saved']);
            } else {
                $stmt = $pdo->prepare("DELETE FROM post_saves WHERE user_id = ? AND post_id = ?");
                $stmt->execute([$userId, $postId]);
                echo json_encode(['success' => true, 'action' => 'unsaved']);
            }
        } catch (Exception $e) {
            if (!isset($_SESSION['saved_posts'])) {
                $_SESSION['saved_posts'] = [];
            }
            
            if ($_POST['action'] === 'save') {
                $_SESSION['saved_posts'][$postId] = true;
                echo json_encode(['success' => true, 'action' => 'saved']);
            } else {
                unset($_SESSION['saved_posts'][$postId]);
                echo json_encode(['success' => true, 'action' => 'unsaved']);
            }
        }
        exit;
    }

    // Record tweet view for algorithm
    if (isset($_POST['action']) && $_POST['action'] === 'record_view' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        if (!in_array($postId, $_SESSION['viewed_tweets'])) {
            $_SESSION['viewed_tweets'][] = $postId;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // Like/Unlike handler
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

    // Retweet handler
    if (isset($_POST['action']) && $_POST['action'] === 'retweet' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        try {
            // Check if already retweeted
            $stmt = $pdo->prepare("SELECT 1 FROM retweets WHERE user_id = ? AND post_id = ?");
            $stmt->execute([$userId, $postId]);
            
            if ($stmt->fetchColumn()) {
                // Undo retweet
                $stmt = $pdo->prepare("DELETE FROM retweets WHERE user_id = ? AND post_id = ?");
                $stmt->execute([$userId, $postId]);
                echo json_encode(['success' => true, 'action' => 'unretweeted']);
            } else {
                // Retweet
                $stmt = $pdo->prepare("INSERT INTO retweets (user_id, post_id, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$userId, $postId]);
                echo json_encode(['success' => true, 'action' => 'retweeted']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Retweet failed']);
        }
        exit;
    }

    // Infinite scroll with Twitter algorithm + 30-degree connections
    if (isset($_POST['action']) && $_POST['action'] === 'load_more_tweets') {
        $userId = $_SESSION['user_id'];
        $offset = (int)($_POST['offset'] ?? 0);
        $limit = 10;
        
        $tweets = getTwitterStyleFeed($pdo, $userId, $limit, $offset);
        $hasMore = count($tweets) >= $limit;
        
        echo json_encode([
            'success' => true,
            'tweets' => $tweets,
            'has_more' => $hasMore
        ]);
        exit;
    }
}

// Function to get all connected users up to 30 degrees (both follows and friends)
function getAllConnectedUsers($pdo, $userId) {
    $connectedUsers = [$userId => 0]; // Start with self (degree 0)
    
    // Get direct follows and friends (degree 1)
    $stmt = $pdo->prepare("
        SELECT followed_id as user_id, 1 as degree, 'follow' as type 
        FROM follows 
        WHERE follower_id = ?
        
        UNION
        
        SELECT 
            CASE 
                WHEN user_id = ? THEN friend_id 
                ELSE user_id 
            END as user_id, 
            1 as degree, 
            'friend' as type
        FROM friends 
        WHERE (user_id = ? OR friend_id = ?) 
        AND status = 'accepted'
    ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $directConnections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($directConnections as $connection) {
        $connectedUsers[$connection['user_id']] = 1;
    }
    
    // Expand connections up to 30 degrees
    for ($degree = 2; $degree <= 30; $degree++) {
        $previousDegreeUsers = array_keys(array_filter($connectedUsers, function($d) use ($degree) {
            return $d == $degree - 1;
        }));
        
        if (empty($previousDegreeUsers)) break;
        
        // Limit the number of users to process to prevent memory issues
        $usersToProcess = array_slice($previousDegreeUsers, 0, 1000);
        
        if (empty($usersToProcess)) break;
        
        $placeholders = str_repeat('?,', count($usersToProcess) - 1) . '?';
        $excludeUsers = array_merge([$userId], array_keys($connectedUsers));
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT followed_id as user_id, ? as degree, 'follow' as type
            FROM follows 
            WHERE follower_id IN ($placeholders)
            AND followed_id NOT IN (" . str_repeat('?,', count($excludeUsers) - 1) . "?)
        ");
        
        $params = array_merge(
            [$degree],
            $usersToProcess,
            $excludeUsers
        );
        
        $stmt->execute($params);
        $newFollowConnections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get friend connections
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                CASE 
                    WHEN user_id IN ($placeholders) THEN friend_id 
                    ELSE user_id 
                END as user_id, 
                ? as degree, 
                'friend' as type
            FROM friends 
            WHERE (user_id IN ($placeholders) OR friend_id IN ($placeholders))
            AND status = 'accepted'
            AND CASE 
                WHEN user_id IN ($placeholders) THEN friend_id 
                ELSE user_id 
            END NOT IN (" . str_repeat('?,', count($excludeUsers) - 1) . "?)
        ");
        
        $params = array_merge(
            [$degree],
            $usersToProcess,
            $usersToProcess,
            $usersToProcess,
            $excludeUsers
        );
        
        $stmt->execute($params);
        $newFriendConnections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $newConnections = array_merge($newFollowConnections, $newFriendConnections);
        
        foreach ($newConnections as $connection) {
            if (!isset($connectedUsers[$connection['user_id']])) {
                $connectedUsers[$connection['user_id']] = $degree;
            }
        }
        
        // Stop if no new connections found for 3 consecutive degrees
        if (empty($newConnections)) {
            if ($degree >= 5) { // Only break if we've reached at least degree 5
                $emptyDegrees = 0;
                for ($checkDegree = max(2, $degree - 2); $checkDegree <= $degree; $checkDegree++) {
                    $checkUsers = array_keys(array_filter($connectedUsers, function($d) use ($checkDegree) {
                        return $d == $checkDegree;
                    }));
                    if (empty($checkUsers)) {
                        $emptyDegrees++;
                    }
                }
                if ($emptyDegrees >= 2) {
                    break;
                }
            }
        }
        
        // Prevent infinite loops with large networks
        if (count($connectedUsers) > 10000) {
            break;
        }
    }
    
    return $connectedUsers;
}

// TWITTER-STYLE FEED ALGORITHM WITH 30-DEGREE CONNECTIONS + POPULAR FALLBACK
function getTwitterStyleFeed($pdo, $userId, $limit = 10, $offset = 0) {
    try {
        // Get all connected users up to 30 degrees
        $connectedUsers = getAllConnectedUsers($pdo, $userId);
        
        $userIds = array_keys($connectedUsers);
        
        // First, try to get posts from network
        if (!empty($userIds)) {
            $networkPosts = getNetworkPosts($pdo, $userIds, $limit, $offset, $connectedUsers);
            
            // If we got enough posts from network, return them
            if (count($networkPosts) >= $limit) {
                return $networkPosts;
            }
            
            // If we need more posts, get popular posts to fill the gap
            $remainingLimit = $limit - count($networkPosts);
            $popularPosts = getPopularPosts($pdo, $userIds, $remainingLimit, 0);
            
            return array_merge($networkPosts, $popularPosts);
        } else {
            // If no network connections, just get popular posts
            return getPopularPosts($pdo, [], $limit, $offset);
        }
        
    } catch (Exception $e) {
        error_log("Feed query error: " . $e->getMessage());
        return getSimpleFeed($pdo, $userId, $limit, $offset);
    }
}

// Get posts from user's network
function getNetworkPosts($pdo, $userIds, $limit, $offset, $connectedUsers) {
    $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
    
    $query = "
        WITH tweet_metrics AS (
            SELECT 
                p.id as post_id,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT c.id) as comment_count,
                COUNT(DISTINCT r.id) as retweet_count
            FROM posts p
            LEFT JOIN likes l ON p.id = l.post_id
            LEFT JOIN comments c ON p.id = c.post_id
            LEFT JOIN retweets r ON p.id = r.post_id
            GROUP BY p.id
        )
        
        SELECT 
            p.*,
            u.username,
            u.profile_pic_url,
            u.id AS author_id,
            COALESCE(tm.like_count, 0) as like_count,
            COALESCE(tm.comment_count, 0) as comment_count,
            COALESCE(tm.retweet_count, 0) as retweet_count,
            RANDOM() as random_order
        
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN tweet_metrics tm ON p.id = tm.post_id
        
        WHERE p.privacy_setting = 'public'
        AND p.created_at >= NOW() - INTERVAL '3 days'
        AND p.user_id IN ($placeholders)
        
        ORDER BY random_order DESC
        LIMIT ? OFFSET ?
    ";
    
    $params = array_merge($userIds, [$limit, $offset]);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    $tweets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add connection degree info
    foreach ($tweets as &$tweet) {
        $tweet['connection_degree'] = $connectedUsers[$tweet['user_id']] ?? 99;
        $tweet['is_network_post'] = true;
    }
    
    return enhanceTweets($pdo, $GLOBALS['userId'], $tweets);
}

// Get popular posts from outside user's network
function getPopularPosts($pdo, $excludeUserIds, $limit, $offset) {
    $excludeCondition = "";
    $params = [];
    
    if (!empty($excludeUserIds)) {
        $placeholders = str_repeat('?,', count($excludeUserIds) - 1) . '?';
        $excludeCondition = "AND p.user_id NOT IN ($placeholders)";
        $params = $excludeUserIds;
    }
    
    $query = "
        WITH tweet_metrics AS (
            SELECT 
                p.id as post_id,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT c.id) as comment_count,
                COUNT(DISTINCT r.id) as retweet_count,
                (COUNT(DISTINCT l.id) * 2 + COUNT(DISTINCT c.id) * 3 + COUNT(DISTINCT r.id) * 4) as engagement_score
            FROM posts p
            LEFT JOIN likes l ON p.id = l.post_id
            LEFT JOIN comments c ON p.id = c.post_id
            LEFT JOIN retweets r ON p.id = r.post_id
            GROUP BY p.id
        )
        
        SELECT 
            p.*,
            u.username,
            u.profile_pic_url,
            u.id AS author_id,
            COALESCE(tm.like_count, 0) as like_count,
            COALESCE(tm.comment_count, 0) as comment_count,
            COALESCE(tm.retweet_count, 0) as retweet_count,
            COALESCE(tm.engagement_score, 0) as engagement_score,
            RANDOM() as random_order
        
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN tweet_metrics tm ON p.id = tm.post_id
        
        WHERE p.privacy_setting = 'public'
        AND p.created_at >= NOW() - INTERVAL '3 days'
        $excludeCondition
        AND COALESCE(tm.engagement_score, 0) >= 5  -- Only show posts with some engagement
        
        ORDER BY tm.engagement_score DESC, random_order DESC
        LIMIT ? OFFSET ?
    ";
    
    $params = array_merge($params, [$limit, $offset]);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    $tweets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark as popular posts
    foreach ($tweets as &$tweet) {
        $tweet['connection_degree'] = 999; // Special code for popular posts
        $tweet['is_network_post'] = false;
    }
    
    return enhanceTweets($pdo, $GLOBALS['userId'], $tweets);
}

// Fallback simple feed function
function getSimpleFeed($pdo, $userId, $limit = 10, $offset = 0) {
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.id AS author_id,
               COUNT(DISTINCT l.id) as like_count,
               COUNT(DISTINCT c.id) as comment_count,
               COUNT(DISTINCT r.id) as retweet_count,
               RANDOM() as random_order
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN likes l ON p.id = l.post_id
        LEFT JOIN comments c ON p.id = c.post_id
        LEFT JOIN retweets r ON p.id = r.post_id
        WHERE p.privacy_setting = 'public'
        AND p.created_at >= NOW() - INTERVAL '3 days'
        AND (p.user_id = ? OR p.user_id IN (
            SELECT followed_id FROM follows WHERE follower_id = ?
        ) OR p.user_id IN (
            SELECT CASE 
                WHEN user_id = ? THEN friend_id 
                ELSE user_id 
            END
            FROM friends 
            WHERE (user_id = ? OR friend_id = ?) 
            AND status = 'accepted'
        ))
        GROUP BY p.id, u.id, u.username, u.profile_pic_url
        ORDER BY random_order DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $limit, $offset]);
    $tweets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add default connection info
    foreach ($tweets as &$tweet) {
        $tweet['connection_degree'] = ($tweet['user_id'] == $userId) ? 0 : 1;
        $tweet['is_network_post'] = true;
    }
    
    return enhanceTweets($pdo, $userId, $tweets);
}

// Enhanced tweet data
function enhanceTweets($pdo, $userId, $tweets) {
    foreach ($tweets as &$tweet) {
        $tweet['formatted_date'] = formatTweetDate($tweet['created_at']);
        $tweet['likes_count'] = $tweet['like_count'] ?? getLikesCount($pdo, $tweet['id']);
        $tweet['comments_count'] = $tweet['comment_count'] ?? getCommentCount($pdo, $tweet['id']);
        $tweet['is_liked'] = userLikedPost($pdo, $userId, $tweet['id']);
        $tweet['is_saved'] = userSavedPost($pdo, $userId, $tweet['id']);
        $tweet['is_retweeted'] = userRetweeted($pdo, $userId, $tweet['id']);
        $tweet['retweets_count'] = getRetweetCount($pdo, $tweet['id']);
        
        // Check if following
        $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?");
        $stmt->execute([$userId, $tweet['author_id']]);
        $tweet['is_following'] = (bool)$stmt->fetchColumn();
        
        // Add connection degree info for UI
        $tweet['connection_info'] = getConnectionInfo($tweet['connection_degree'], $tweet['is_network_post'] ?? true);
    }
    
    return $tweets;
}

// Helper function to get connection info text
function getConnectionInfo($degree, $isNetworkPost = true) {
    if (!$isNetworkPost) {
        return "Popular post";
    }
    
    switch($degree) {
        case 0:
            return "You";
        case 1:
            return "Direct connection";
        case 2:
        case 3:
        case 4:
        case 5:
            return "Close network";
        case 6:
        case 7:
        case 8:
        case 9:
        case 10:
            return "Extended network";
        case 11:
        case 12:
        case 13:
        case 14:
        case 15:
            return "Wide network";
        case 16:
        case 17:
        case 18:
        case 19:
        case 20:
            return "Extended circle";
        case 21:
        case 22:
        case 23:
        case 24:
        case 25:
            return "Distant network";
        case 26:
        case 27:
        case 28:
        case 29:
        case 30:
            return "Global network";
        case 999:
            return "Popular post";
        default:
            return "Suggested";
    }
}

// Check if user retweeted
function userRetweeted($pdo, $userId, $postId) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM retweets WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

// Get retweet count
function getRetweetCount($pdo, $postId) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM retweets WHERE post_id = :post_id");
        $stmt->execute(['post_id' => $postId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Twitter-style date formatting
function formatTweetDate($dateString) {
    $postDate = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($postDate);
    
    if ($diff->days === 0) {
        if ($diff->h > 0) return $diff->h . 'h';
        if ($diff->i > 0) return $diff->i . 'm';
        return 'Just now';
    } elseif ($diff->days === 1) {
        return '1d';
    } elseif ($diff->days <= 7) {
        return $diff->days . 'd';
    } else {
        return $postDate->format('M j');
    }
}

// Keep your existing helper functions
function userSavedPost($pdo, $userId, $postId) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM post_saves WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return isset($_SESSION['saved_posts'][$postId]);
    }
}

function getLikesCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
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

// Get initial tweets
$userId = $_SESSION['user_id'];
$currentUserId = $_SESSION['user_id'];

// Get initial Twitter-style feed with 30-degree connections + popular posts
try {
    $initialTweets = getTwitterStyleFeed($pdo, $userId, 15, 0);
} catch (Exception $e) {
    error_log("Initial feed error: " . $e->getMessage());
    $initialTweets = getSimpleFeed($pdo, $userId, 15, 0);
}

// User profile data
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Twitter-Style Feed with Extended Network</title>
<style>
:root {
    --twitter-blue: #1d9bf0;
    --twitter-dark: #0f1419;
    --twitter-light-gray: #f7f9fa;
    --twitter-border: #eff3f4;
    --twitter-text: #0f1419;
    --twitter-text-light: #536471;
}

body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px; 
    margin: 0 auto; 
    background: white; 
    color: var(--twitter-text);
    border-left: 1px solid var(--twitter-border);
    border-right: 1px solid var(--twitter-border);
}

.tweets {
    margin-top: 0;
}

.tweet {
    padding: 12px 16px;
    border-bottom: 1px solid var(--twitter-border);
    transition: background-color 0.2s;
    cursor: pointer;
    position: relative;
}

.tweet:hover {
    background-color: var(--twitter-light-gray);
}

.tweet-header {
    display: flex;
    align-items: flex-start;
    margin-bottom: 2px;
}

.tweet-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
    flex-shrink: 0;
    cursor: pointer;
}

.tweet-content {
    flex-grow: 1;
    min-width: 0;
}

.tweet-user-info {
    display: flex;
    align-items: center;
    margin-bottom: 2px;
    flex-wrap: wrap;
}

.tweet-display-name {
    font-weight: 700;
    font-size: 15px;
    margin-right: 4px;
    cursor: pointer;
    color: var(--twitter-text);
}

.tweet-display-name:hover {
    text-decoration: underline;
}

.tweet-username {
    color: var(--twitter-text-light);
    font-size: 15px;
    margin-right: 4px;
    cursor: pointer;
}

.tweet-username:hover {
    text-decoration: underline;
}

.tweet-date {
    color: var(--twitter-text-light);
    font-size: 15px;
}

.connection-badge {
    font-size: 12px;
    color: var(--twitter-blue);
    margin-left: 8px;
    padding: 2px 6px;
    background: rgba(29, 155, 240, 0.1);
    border-radius: 12px;
    font-weight: 500;
}

.popular-badge {
    font-size: 12px;
    color: #f91880;
    margin-left: 8px;
    padding: 2px 6px;
    background: rgba(249, 24, 128, 0.1);
    border-radius: 12px;
    font-weight: 500;
}

.tweet-text {
    font-size: 15px;
    line-height: 1.4;
    margin-bottom: 12px;
    word-wrap: break-word;
}

.tweet-media {
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 12px;
    border: 1px solid var(--twitter-border);
}

.tweet-media img, .tweet-media video {
    width: 100%;
    height: auto;
    display: block;
}

/* Multiple image grid styles from profile.php */
.post-media {
  margin-bottom: 10px;
}
.post-media .image-count {
  margin-bottom: 8px;
  font-weight: bold;
  font-size: 14px;
  color: #666;
}
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

/* Multiple video styles from profile.php */
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
  scrollbar-width: none; /* Firefox */
}
.video-reel-scroller::-webkit-scrollbar {
  display: none; /* Chrome, Safari, Edge */
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
  background: #2c2c3d;
  cursor: pointer;
  transition: background 0.3s ease;
}
.video-pagination-dot.active {
  background: #0095f6;
}

.tweet-actions {
    display: flex;
    justify-content: space-between;
    max-width: 425px;
    margin-left: -8px;
}

.tweet-action {
    display: flex;
    align-items: center;
    color: var(--twitter-text-light);
    background: none;
    border: none;
    padding: 8px;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
}

.tweet-action:hover {
    background-color: rgba(29, 155, 240, 0.1);
}

.tweet-action.like:hover {
    color: #f91880;
    background-color: rgba(249, 24, 128, 0.1);
}

.tweet-action.retweet:hover {
    color: #00ba7c;
    background-color: rgba(0, 186, 124, 0.1);
}

.tweet-action.comment:hover {
    color: var(--twitter-blue);
    background-color: rgba(29, 155, 240, 0.1);
}

.tweet-action.save:hover {
    color: var(--twitter-blue);
    background-color: rgba(29, 155, 240, 0.1);
}

.tweet-action.active {
    color: var(--twitter-blue);
}

.tweet-action.like.active {
    color: #f91880;
}

.tweet-action.retweet.active {
    color: #00ba7c;
}

.tweet-action-count {
    margin-left: 4px;
    font-size: 13px;
    min-width: 36px;
    text-align: left;
}

.follow-btn {
    background: var(--twitter-text);
    color: white;
    border: none;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.2s;
}

.follow-btn:hover {
    background: #272c30;
}

.follow-btn.following {
    background: transparent;
    color: var(--twitter-text);
    border: 1px solid #cfd9de;
}

.follow-btn.following:hover {
    background: rgba(244, 33, 46, 0.1);
    color: #f4212e;
    border: 1px solid #f4212e;
}

.tweet-composer {
    padding: 12px 16px;
    border-bottom: 1px solid var(--twitter-border);
    display: flex;
    gap: 12px;
}

.tweet-composer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.tweet-composer-content {
    flex-grow: 1;
}

.tweet-composer-text {
    width: 100%;
    border: none;
    resize: none;
    font-size: 20px;
    font-family: inherit;
    margin-bottom: 12px;
    min-height: 56px;
}

.tweet-composer-text::placeholder {
    color: #8b98a5;
}

.tweet-composer-text:focus {
    outline: none;
}

.tweet-composer-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tweet-composer-submit {
    background: var(--twitter-blue);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    opacity: 0.5;
    transition: background-color 0.2s;
}

.tweet-composer-submit.active {
    opacity: 1;
}

.tweet-composer-submit.active:hover {
    background: #1a8cd8;
}

.character-count {
    color: var(--twitter-text-light);
    font-size: 14px;
    margin-right: 12px;
}

.character-count.warning {
    color: #f4212e;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--twitter-text-light);
}

.empty-state h3 {
    color: var(--twitter-text);
    margin-bottom: 12px;
}

.explore-users-btn {
    background: var(--twitter-blue);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 16px;
}

.loading {
    text-align: center;
    padding: 20px;
    color: var(--twitter-text-light);
    display: none;
}

.refresh-btn {
    background: var(--twitter-blue);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 700;
    cursor: pointer;
    margin: 10px auto;
    display: block;
}

.navigation {
    display: flex;
    border-bottom: 1px solid var(--twitter-border);
    position: sticky;
    top: 0;
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(12px);
    z-index: 100;
}

.nav-item {
    flex: 1;
    text-align: center;
    padding: 16px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: background-color 0.2s;
    color: var(--twitter-text-light);
}

.nav-item.active {
    color: var(--twitter-text);
}

.nav-item:hover {
    background-color: var(--twitter-light-gray);
}

.network-info {
    font-size: 12px;
    color: var(--twitter-text-light);
    margin-top: 4px;
}

@media (max-width: 600px) {
  .post-media-grid {
    grid-template-columns: 1fr 1fr;
    gap: 6px;
  }
  .post-media-grid div, .overlay {
    height: 120px;
    line-height: 120px;
  }
  .video-reel-item video {
    max-height: 500px;
  }
}
</style>
</head>
<body>

<!-- Navigation -->
<div class="navigation">
    <div class="nav-item active">For you</div>
    <div class="nav-item">Following</div>
</div>

<!-- Tweet Composer -->
<div class="tweet-composer">
    <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="Profile" class="tweet-composer-avatar">
    <div class="tweet-composer-content">
        <textarea class="tweet-composer-text" placeholder="What is happening?!" maxlength="280"></textarea>
        <div class="tweet-composer-actions">
            <div>
                <!-- Media upload buttons can be added here -->
            </div>
            <div style="display: flex; align-items: center;">
                <span class="character-count">280</span>
                <button class="tweet-composer-submit">Post</button>
            </div>
        </div>
    </div>
</div>

<!-- Refresh Button -->
<button class="refresh-btn" onclick="refreshPage()">🔄 Refresh</button>

<!-- Tweets Container -->
<div class="tweets" id="tweetsContainer">
    <?php if (empty($initialTweets)): ?>
        <div class="empty-state">
            <h3>Welcome to Twitter! 🐦</h3>
            <p>Follow some users to see tweets in your network feed!</p>
            <button class="explore-users-btn" onclick="window.location='explore.php'">
                Explore Users
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($initialTweets as $tweet): ?>
            <?php displayTwitterTweet($tweet, $pdo, $userId); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading">Loading more tweets...</div>

<script>
let currentOffset = <?= count($initialTweets) ?>;
let isLoading = false;
let hasMoreTweets = true;

// Tweet composer functionality
const tweetTextarea = document.querySelector('.tweet-composer-text');
const characterCount = document.querySelector('.character-count');
const submitButton = document.querySelector('.tweet-composer-submit');

tweetTextarea.addEventListener('input', function() {
    const length = this.value.length;
    const remaining = 280 - length;
    
    characterCount.textContent = remaining;
    characterCount.className = 'character-count' + (remaining < 20 ? ' warning' : '');
    submitButton.className = 'tweet-composer-submit' + (length > 0 ? ' active' : '');
});

submitButton.addEventListener('click', async function() {
    if (!submitButton.classList.contains('active')) return;
    
    await createTweet(tweetTextarea.value);
});

async function createTweet(content) {
    try {
        const formData = new FormData();
        formData.append('action', 'create_tweet');
        formData.append('content', content);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Add new tweet to top of feed
            const tweetsContainer = document.getElementById('tweetsContainer');
            const tweetElement = createTweetElement(data.tweet);
            tweetsContainer.insertBefore(tweetElement, tweetsContainer.firstChild);
            
            // Reset composer
            tweetTextarea.value = '';
            characterCount.textContent = '280';
            characterCount.className = 'character-count';
            submitButton.className = 'tweet-composer-submit';
        } else {
            alert(data.message || 'Failed to create tweet');
        }
    } catch (error) {
        console.error('Error creating tweet:', error);
        alert('Failed to create tweet');
    }
}

// Refresh function
function refreshPage() {
    const refreshBtn = document.querySelector('.refresh-btn');
    refreshBtn.innerHTML = '⏳ Refreshing...';
    refreshBtn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Infinite scroll
window.addEventListener('scroll', () => {
    if (isLoading || !hasMoreTweets) return;
    
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    
    if (scrollTop + clientHeight >= scrollHeight - 200) {
        loadMoreTweets();
    }
});

async function loadMoreTweets() {
    if (isLoading) return;
    
    isLoading = true;
    const loadingIndicator = document.getElementById('loadingIndicator');
    loadingIndicator.style.display = 'block';
    
    try {
        const formData = new FormData();
        formData.append('action', 'load_more_tweets');
        formData.append('offset', currentOffset);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            await appendTweets(data.tweets);
            currentOffset += data.tweets.length;
            hasMoreTweets = data.has_more;
        }
    } catch (error) {
        console.error('Error loading tweets:', error);
    } finally {
        isLoading = false;
        loadingIndicator.style.display = 'none';
    }
}

function appendTweets(tweets) {
    return new Promise((resolve) => {
        const tweetsContainer = document.getElementById('tweetsContainer');
        
        tweets.forEach((tweet) => {
            const tweetElement = createTweetElement(tweet);
            tweetsContainer.appendChild(tweetElement);
        });
        
        resolve();
    });
}

function createTweetElement(tweet) {
    const div = document.createElement('div');
    div.className = 'tweet';
    div.setAttribute('data-tweet-id', tweet.id);
    
    const isLiked = tweet.is_liked || false;
    const isSaved = tweet.is_saved || false;
    const isRetweeted = tweet.is_retweeted || false;
    
    // Media content - handle multiple images and videos
    let mediaHTML = '';
    if (tweet.media_url) {
        const mediaArray = tweet.media_url.split(',');
        const mediaCount = mediaArray.length;
        
        if (tweet.post_type === 'photo') {
            if (mediaCount === 1) {
                // Single image - use original style
                mediaHTML = `<div class="tweet-media"><img src="${escapeHtml(mediaArray[0])}" alt="Tweet image"></div>`;
            } else {
                // Multiple images - use grid style from profile.php
                const firstFour = mediaArray.slice(0, 4);
                const extraCount = mediaCount - 4;
                
                let gridHTML = '';
                firstFour.forEach((image, index) => {
                    const trimmedImage = image.trim();
                    let onClick = '';
                    
                    if (index < 3) {
                        onClick = `onclick="window.location='full_image.php?img=${encodeURIComponent(trimmedImage)}'"`;
                    } else if (extraCount > 0 && index === 3) {
                        onClick = `onclick="window.location='image_list.php?post_id=${tweet.id}'"`;
                    } else {
                        onClick = `onclick="window.location='full_image.php?img=${encodeURIComponent(trimmedImage)}'"`;
                    }
                    
                    gridHTML += `
                        <div ${onClick}>
                            <img src="${escapeHtml(trimmedImage)}" alt="Image" />
                            ${(index === 3 && extraCount > 0) ? `<div class="overlay">+${extraCount}</div>` : ''}
                        </div>
                    `;
                });
                
                mediaHTML = `
                    <div class="post-media">
                        <div class="image-count">${mediaCount} image${mediaCount > 1 ? 's' : ''}</div>
                        <div class="post-media-grid">
                            ${gridHTML}
                        </div>
                    </div>
                `;
            }
        } else if (tweet.post_type === 'video') {
            if (mediaCount === 1) {
                // Single video - use original style
                mediaHTML = `<div class="tweet-media"><video controls><source src="${escapeHtml(mediaArray[0])}" type="video/mp4"></video></div>`;
            } else {
                // Multiple videos - use reel style from profile.php
                let videoItemsHTML = '';
                mediaArray.forEach((video, index) => {
                    const trimmedVideo = video.trim();
                    videoItemsHTML += `
                        <div class="video-reel-item" data-video-index="${index}">
                            <video ${index === 0 ? 'muted loop' : 'preload="none"'} controls>
                                <source src="${escapeHtml(trimmedVideo)}" type="video/mp4" />
                                Your browser does not support the video tag.
                            </video>
                            <div class="video-controls">
                                <button class="sound-toggle" data-muted="true">🔇</button>
                            </div>
                            ${mediaCount > 1 ? `<div class="video-count-indicator">${index + 1}/${mediaCount}</div>` : ''}
                        </div>
                    `;
                });
                
                let paginationHTML = '';
                if (mediaCount > 1) {
                    paginationHTML = `
                        <div class="video-pagination">
                            ${Array.from({length: mediaCount}, (_, i) => `
                                <div class="video-pagination-dot ${i === 0 ? 'active' : ''}" data-video-index="${i}"></div>
                            `).join('')}
                        </div>
                    `;
                }
                
                mediaHTML = `
                    <div class="video-reel-container" data-post-id="${tweet.id}">
                        <div class="video-reel-scroller">
                            ${videoItemsHTML}
                        </div>
                        ${paginationHTML}
                    </div>
                `;
            }
        }
    }
    
    // Connection badge - different style for popular posts
    const isPopular = tweet.connection_degree === 999;
    const badgeClass = isPopular ? 'popular-badge' : 'connection-badge';
    const connectionBadge = (tweet.connection_degree > 1 || isPopular) ? 
        `<span class="${badgeClass}">${escapeHtml(tweet.connection_info || '')}</span>` : '';
    
    div.innerHTML = `        
        <div class="tweet-header">
            <img src="${escapeHtml(tweet.profile_pic_url || 'default_profile.png')}"
                 alt="Profile" class="tweet-avatar" 
                 onclick="window.location='profile.php?id=${tweet.user_id}'" />
            <div class="tweet-content">
                <div class="tweet-user-info">
                    <div class="tweet-display-name" onclick="window.location='profile.php?id=${tweet.user_id}'">${escapeHtml(tweet.username)}</div>
                    <div class="tweet-username" onclick="window.location='profile.php?id=${tweet.user_id}'">@${escapeHtml(tweet.username)}</div>
                    <div class="tweet-date">· ${escapeHtml(tweet.formatted_date)}</div>
                    ${connectionBadge}
                    ${tweet.author_id != <?= $userId ?> ? `
                        <div style="margin-left: auto;">
                            <button class="follow-btn ${tweet.is_following ? 'following' : ''}" 
                                    data-user-id="${tweet.author_id}">
                                ${tweet.is_following ? 'Following' : 'Follow'}
                            </button>
                        </div>
                    ` : ''}
                </div>
                
                <div class="tweet-text">${escapeHtml(tweet.content || '')}</div>
                
                ${mediaHTML}
                
                <div class="tweet-actions">
                    <button class="tweet-action comment" onclick="event.stopPropagation(); window.location='comment.php?post_id=${tweet.id}'">
                        💬
                        <span class="tweet-action-count">${tweet.comments_count || 0}</span>
                    </button>
                    
                    <button class="tweet-action retweet ${isRetweeted ? 'active' : ''}" data-tweet-id="${tweet.id}">
                        🔄
                        <span class="tweet-action-count">${tweet.retweets_count || 0}</span>
                    </button>
                    
                    <button class="tweet-action like ${isLiked ? 'active' : ''}" data-tweet-id="${tweet.id}">
                        ${isLiked ? '❤️' : '🤍'}
                        <span class="tweet-action-count">${tweet.likes_count || 0}</span>
                    </button>
                    
                    <button class="tweet-action save ${isSaved ? 'active' : ''}" data-tweet-id="${tweet.id}">
                        ${isSaved ? '📕' : '📖'}
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Add click handler for the whole tweet
    div.addEventListener('click', function(e) {
        if (!e.target.closest('.tweet-action') && !e.target.closest('.follow-btn') && !e.target.closest('.post-media-grid div') && !e.target.closest('.tweet-display-name') && !e.target.closest('.tweet-username') && !e.target.closest('.tweet-avatar') && !e.target.closest('.video-controls') && !e.target.closest('.video-pagination-dot')) {
            window.location.href = `comment.php?post_id=${tweet.id}`;
        }
    });
    
    return div;
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Video Reel functionality from profile.php
function initVideoReels() {
    document.querySelectorAll('.video-reel-container').forEach(container => {
        const videos = container.querySelectorAll('video');
        const videoItems = container.querySelectorAll('.video-reel-item');
        const paginationDots = container.querySelectorAll('.video-pagination-dot');
        const scroller = container.querySelector('.video-reel-scroller');
        
        // Set the width of each video item to match the container
        videoItems.forEach(item => {
            item.style.width = container.offsetWidth + 'px';
        });
        
        // Initialize first video
        if (videos.length > 0) {
            const firstVideo = videos[0];
            firstVideo.addEventListener('loadedmetadata', () => {
                // Adjust container height based on video aspect ratio
                const aspectRatio = firstVideo.videoHeight / firstVideo.videoWidth;
                const containerWidth = container.offsetWidth;
                container.style.height = (containerWidth * aspectRatio) + 'px';
            });
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
                    if (paginationDots[index]) {
                        paginationDots[index].classList.add('active');
                    }
                } else {
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
        
        // Handle sound toggle
        container.querySelectorAll('.sound-toggle').forEach(button => {
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
        });
    });
}

// Initialize video reels when new tweets are loaded
function initVideoObservers() {
    initVideoReels();
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    initVideoReels();
    
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('like') || e.target.closest('.like')) {
            const likeBtn = e.target.classList.contains('like') ? e.target : e.target.closest('.like');
            handleLikeClick(likeBtn);
        }
        if (e.target.classList.contains('save') || e.target.closest('.save')) {
            const saveBtn = e.target.classList.contains('save') ? e.target : e.target.closest('.save');
            handleSaveClick(saveBtn);
        }
        if (e.target.classList.contains('follow-btn')) {
            handleFollowClick(e.target);
        }
        if (e.target.classList.contains('retweet') || e.target.closest('.retweet')) {
            const retweetBtn = e.target.classList.contains('retweet') ? e.target : e.target.closest('.retweet');
            handleRetweetClick(retweetBtn);
        }
    });
});

async function handleLikeClick(likeBtn) {
    const tweetId = likeBtn.dataset.tweetId;
    const isLiked = likeBtn.classList.contains('active');
    
    try {
        const formData = new FormData();
        formData.append('action', isLiked ? 'unlike' : 'like');
        formData.append('post_id', tweetId);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.like[data-tweet-id="${tweetId}"]`).forEach(btn => {
                btn.classList.toggle('active');
                const countSpan = btn.querySelector('.tweet-action-count');
                const currentCount = parseInt(countSpan.textContent) || 0;
                countSpan.textContent = isLiked ? currentCount - 1 : currentCount + 1;
                btn.innerHTML = (isLiked ? '🤍' : '❤️') + `<span class="tweet-action-count">${isLiked ? currentCount - 1 : currentCount + 1}</span>`;
            });
        }
    } catch (error) {
        console.error('Error updating like:', error);
    }
}

async function handleSaveClick(saveBtn) {
    const tweetId = saveBtn.dataset.tweetId;
    const isSaved = saveBtn.classList.contains('active');
    
    try {
        const formData = new FormData();
        formData.append('action', isSaved ? 'unsave' : 'save');
        formData.append('post_id', tweetId);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.save[data-tweet-id="${tweetId}"]`).forEach(btn => {
                btn.classList.toggle('active');
                btn.innerHTML = btn.classList.contains('active') ? '📕' : '📖';
            });
        }
    } catch (error) {
        console.error('Error updating save:', error);
    }
}

async function handleRetweetClick(retweetBtn) {
    const tweetId = retweetBtn.dataset.tweetId;
    
    try {
        const formData = new FormData();
        formData.append('action', 'retweet');
        formData.append('post_id', tweetId);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.querySelectorAll(`.retweet[data-tweet-id="${tweetId}"]`).forEach(btn => {
                const isNowRetweeted = data.action === 'retweeted';
                btn.classList.toggle('active', isNowRetweeted);
                const countSpan = btn.querySelector('.tweet-action-count');
                const currentCount = parseInt(countSpan.textContent) || 0;
                countSpan.textContent = isNowRetweeted ? currentCount + 1 : currentCount - 1;
            });
        }
    } catch (error) {
        console.error('Error updating retweet:', error);
    }
}

async function handleFollowClick(followBtn) {
    const userId = followBtn.dataset.userId;
    const isFollowing = followBtn.classList.contains('following');
    
    try {
        const formData = new FormData();
        formData.append('action', isFollowing ? 'unfollow' : 'follow');
        formData.append('followed_id', userId);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
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
        }
    } catch (error) {
        console.error('Error updating follow status:', error);
    }
}
</script>

</body>
</html>

<?php
// Function to display Twitter-style tweet
function displayTwitterTweet($tweet, $pdo, $userId) {
    $isLiked = userLikedPost($pdo, $userId, $tweet['id']);
    $isSaved = userSavedPost($pdo, $userId, $tweet['id']);
    $isRetweeted = userRetweeted($pdo, $userId, $tweet['id']);
    
    // Connection badge - different style for popular posts
    $isPopular = $tweet['connection_degree'] === 999;
    $badgeClass = $isPopular ? 'popular-badge' : 'connection-badge';
    $connectionBadge = '';
    if ($tweet['connection_degree'] > 1 || $isPopular) {
        $connectionBadge = '<span class="' . $badgeClass . '">' . htmlspecialchars($tweet['connection_info']) . '</span>';
    }
    
    // Media content - handle multiple images and videos
    $mediaHTML = '';
    if (!empty($tweet['media_url'])) {
        $mediaArray = explode(',', $tweet['media_url']);
        $mediaCount = count($mediaArray);
        
        if ($tweet['post_type'] === 'photo') {
            if ($mediaCount === 1) {
                // Single image - use original style
                $firstMedia = trim($mediaArray[0]);
                $mediaHTML = '<div class="tweet-media"><img src="' . htmlspecialchars($firstMedia) . '" alt="Tweet image"></div>';
            } else {
                // Multiple images - use grid style from profile.php
                $firstFour = array_slice($mediaArray, 0, 4);
                $extraCount = $mediaCount - 4;
                
                $gridHTML = '';
                foreach ($firstFour as $index => $image) {
                    $image = trim($image);
                    $onClick = '';
                    
                    if ($index < 3) {
                        $onClick = 'onclick="window.location=\'full_image.php?img=' . urlencode($image) . '\'"';
                    } elseif ($extraCount > 0 && $index === 3) {
                        $onClick = 'onclick="window.location=\'image_list.php?post_id=' . $tweet['id'] . '\'"';
                    } else {
                        $onClick = 'onclick="window.location=\'full_image.php?img=' . urlencode($image) . '\'"';
                    }
                    
                    $overlayHTML = ($index === 3 && $extraCount > 0) ? '<div class="overlay">+' . $extraCount . '</div>' : '';
                    
                    $gridHTML .= '
                        <div ' . $onClick . '>
                            <img src="' . htmlspecialchars($image) . '" alt="Image" />
                            ' . $overlayHTML . '
                        </div>
                    ';
                }
                
                $mediaHTML = '
                    <div class="post-media">
                        <div class="image-count">' . $mediaCount . ' image' . ($mediaCount > 1 ? 's' : '') . '</div>
                        <div class="post-media-grid">
                            ' . $gridHTML . '
                        </div>
                    </div>
                ';
            }
        } elseif ($tweet['post_type'] === 'video') {
            if ($mediaCount === 1) {
                // Single video - use original style
                $firstMedia = trim($mediaArray[0]);
                $mediaHTML = '<div class="tweet-media"><video controls><source src="' . htmlspecialchars($firstMedia) . '" type="video/mp4"></video></div>';
            } else {
                // Multiple videos - use reel style from profile.php
                $videoItemsHTML = '';
                foreach ($mediaArray as $index => $video) {
                    $video = trim($video);
                    $videoItemsHTML .= '
                        <div class="video-reel-item" data-video-index="' . $index . '">
                            <video ' . ($index === 0 ? 'muted loop' : 'preload="none"') . ' controls>
                                <source src="' . htmlspecialchars($video) . '" type="video/mp4" />
                                Your browser does not support the video tag.
                            </video>
                            <div class="video-controls">
                                <button class="sound-toggle" data-muted="true">🔇</button>
                            </div>
                            ' . ($mediaCount > 1 ? '<div class="video-count-indicator">' . ($index + 1) . '/' . $mediaCount . '</div>' : '') . '
                        </div>
                    ';
                }
                
                $paginationHTML = '';
                if ($mediaCount > 1) {
                    $paginationHTML = '
                        <div class="video-pagination">';
                    for ($i = 0; $i < $mediaCount; $i++) {
                        $paginationHTML .= '<div class="video-pagination-dot ' . ($i === 0 ? 'active' : '') . '" data-video-index="' . $i . '"></div>';
                    }
                    $paginationHTML .= '
                        </div>
                    ';
                }
                
                $mediaHTML = '
                    <div class="video-reel-container" data-post-id="' . $tweet['id'] . '">
                        <div class="video-reel-scroller">
                            ' . $videoItemsHTML . '
                        </div>
                        ' . $paginationHTML . '
                    </div>
                ';
            }
        }
    }
    ?>
    <div class="tweet" data-tweet-id="<?= $tweet['id'] ?>">
        <div class="tweet-header">
            <img src="<?= htmlspecialchars($tweet['profile_pic_url'] ?: 'default_profile.png') ?>"
                 alt="Profile" class="tweet-avatar"
                 onclick="window.location='profile.php?id=<?= $tweet['user_id'] ?>'" />
            <div class="tweet-content">
                <div class="tweet-user-info">
                    <div class="tweet-display-name" onclick="window.location='profile.php?id=<?= $tweet['user_id'] ?>'"><?= htmlspecialchars($tweet['username']) ?></div>
                    <div class="tweet-username" onclick="window.location='profile.php?id=<?= $tweet['user_id'] ?>'">@<?= htmlspecialchars($tweet['username']) ?></div>
                    <div class="tweet-date">· <?= htmlspecialchars($tweet['formatted_date']) ?></div>
                    <?= $connectionBadge ?>
                    
                    <?php if ($tweet['author_id'] !== $userId): ?>
                        <div style="margin-left: auto;">
                            <button class="follow-btn <?= $tweet['is_following'] ? 'following' : '' ?>" 
                                    data-user-id="<?= $tweet['author_id'] ?>">
                                <?= $tweet['is_following'] ? 'Following' : 'Follow' ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="tweet-text"><?= htmlspecialchars($tweet['content']) ?></div>
                
                <?= $mediaHTML ?>
                
                <div class="tweet-actions">
                    <button class="tweet-action comment" onclick="event.stopPropagation(); window.location='comment.php?post_id=<?= $tweet['id'] ?>'">
                        💬
                        <span class="tweet-action-count"><?= $tweet['comments_count'] ?></span>
                    </button>
                    
                    <button class="tweet-action retweet <?= $isRetweeted ? 'active' : '' ?>" data-tweet-id="<?= $tweet['id'] ?>">
                        🔄
                        <span class="tweet-action-count"><?= $tweet['retweets_count'] ?></span>
                    </button>
                    
                    <button class="tweet-action like <?= $isLiked ? 'active' : '' ?>" data-tweet-id="<?= $tweet['id'] ?>">
                        <?= $isLiked ? '❤️' : '🤍' ?>
                        <span class="tweet-action-count"><?= $tweet['likes_count'] ?></span>
                    </button>
                    
                    <button class="tweet-action save <?= $isSaved ? 'active' : '' ?>" data-tweet-id="<?= $tweet['id'] ?>">
                        <?= $isSaved ? '📕' : '📖' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tweet = document.querySelector('[data-tweet-id="<?= $tweet['id'] ?>"]');
        if (tweet) {
            tweet.addEventListener('click', function(e) {
                if (!e.target.closest('.tweet-action') && !e.target.closest('.follow-btn') && !e.target.closest('.post-media-grid div') && !e.target.closest('.tweet-display-name') && !e.target.closest('.tweet-username') && !e.target.closest('.tweet-avatar') && !e.target.closest('.video-controls') && !e.target.closest('.video-pagination-dot')) {
                    window.location.href = `comment.php?post_id=<?= $tweet['id'] ?>`;
                }
            });
        }
    });
    </script>
    <?php
}
?>