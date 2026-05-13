<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

// Security improvement: Move credentials to environment variables in production
require_once "config.php";

// Twitter-style session tracking
if (!isset($_SESSION['last_refresh'])) {
    $_SESSION['last_refresh'] = time();
    $_SESSION['viewed_tweets'] = [];
}

// Pagination configuration
define('TWEETS_PER_PAGE', 20); // Load 20 tweets at a time
define('MAX_TOTAL_TWEETS', 200); // Maximum tweets to load

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

    // Load more tweets with pagination
    if (isset($_POST['action']) && $_POST['action'] === 'load_more_tweets') {
        $userId = $_SESSION['user_id'];
        $page = (int)($_POST['page'] ?? 1);
        $limit = TWEETS_PER_PAGE;
        $offset = ($page - 1) * $limit;
        
        // Check if we've reached the maximum
        if ($offset >= MAX_TOTAL_TWEETS) {
            echo json_encode([
                'success' => true,
                'tweets' => [],
                'has_more' => false,
                'message' => 'No more tweets to load'
            ]);
            exit;
        }
        
        $tweets = getTwitterStyleFeed($pdo, $userId, $limit, $offset);
        $hasMore = count($tweets) >= $limit && ($offset + count($tweets)) < MAX_TOTAL_TWEETS;
        
        echo json_encode([
            'success' => true,
            'tweets' => $tweets,
            'has_more' => $hasMore,
            'next_page' => $page + 1,
            'current_count' => $offset + count($tweets)
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

// Get initial tweets (first batch)
$userId = $_SESSION['user_id'];
$currentUserId = $_SESSION['user_id'];

// Get initial Twitter-style feed with 30-degree connections + popular posts
try {
    $initialTweets = getTwitterStyleFeed($pdo, $userId, TWEETS_PER_PAGE, 0);
} catch (Exception $e) {
    error_log("Initial feed error: " . $e->getMessage());
    $initialTweets = getSimpleFeed($pdo, $userId, TWEETS_PER_PAGE, 0);
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
}

.tweet-username {
    color: var(--twitter-text-light);
    font-size: 15px;
    margin-right: 4px;
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

.load-more-container {
    text-align: center;
    padding: 20px;
}

.load-more-btn {
    background: var(--twitter-blue);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 24px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.load-more-btn:hover {
    background: #1a8cd8;
}

.load-more-btn:disabled {
    background: var(--twitter-text-light);
    cursor: not-allowed;
}

.pagination-info {
    text-align: center;
    padding: 10px;
    color: var(--twitter-text-light);
    font-size: 14px;
    border-bottom: 1px solid var(--twitter-border);
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

<!-- Pagination Info -->
<div class="pagination-info" id="paginationInfo">
    Showing <?= count($initialTweets) ?> tweets
</div>

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

<!-- Load More Button -->
<div class="load-more-container" id="loadMoreContainer">
    <button class="load-more-btn" id="loadMoreBtn" onclick="loadMoreTweets()">
        Load More Tweets
    </button>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading">Loading more tweets...</div>

<script>
let currentPage = 1;
let isLoading = false;
let hasMoreTweets = true;
let totalTweetsLoaded = <?= count($initialTweets) ?>;

// Tweet composer functionality
const tweetTextarea = document.querySelector('.tweet-composer-text');
const characterCount = document.querySelector('.character-count');
const submitButton = document.querySelector('.tweet-composer-submit');
const loadMoreBtn = document.getElementById('loadMoreBtn');
const paginationInfo = document.getElementById('paginationInfo');

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
            
            // Update tweet count
            totalTweetsLoaded++;
            updatePaginationInfo();
            
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

// Update pagination info
function updatePaginationInfo() {
    paginationInfo.textContent = `Showing ${totalTweetsLoaded} tweets`;
}

// Load more tweets with pagination
async function loadMoreTweets() {
    if (isLoading || !hasMoreTweets) return;
    
    isLoading = true;
    const loadingIndicator = document.getElementById('loadingIndicator');
    const loadMoreContainer = document.getElementById('loadMoreContainer');
    
    loadMoreContainer.style.display = 'none';
    loadingIndicator.style.display = 'block';
    
    try {
        const formData = new FormData();
        formData.append('action', 'load_more_tweets');
        formData.append('page', currentPage + 1);
        
        const response = await fetch('feed.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            await appendTweets(data.tweets);
            currentPage = data.next_page || currentPage + 1;
            hasMoreTweets = data.has_more;
            totalTweetsLoaded = data.current_count || totalTweetsLoaded + data.tweets.length;
            
            updatePaginationInfo();
            
            if (!hasMoreTweets) {
                loadMoreContainer.innerHTML = '<p style="color: var(--twitter-text-light);">No more tweets to load</p>';
            }
        }
    } catch (error) {
        console.error('Error loading tweets:', error);
        loadMoreContainer.style.display = 'block';
    } finally {
        isLoading = false;
        loadingIndicator.style.display = 'none';
        if (hasMoreTweets) {
            loadMoreContainer.style.display = 'block';
        }
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
    
    // Media content
    let mediaHTML = '';
    if (tweet.media_url) {
        const mediaArray = tweet.media_url.split(',');
        if (tweet.post_type === 'photo') {
            mediaHTML = `<div class="tweet-media"><img src="${escapeHtml(mediaArray[0])}" alt="Tweet image"></div>`;
        } else if (tweet.post_type === 'video') {
            mediaHTML = `<div class="tweet-media"><video controls><source src="${escapeHtml(mediaArray[0])}" type="video/mp4"></video></div>`;
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
                    <div class="tweet-display-name">${escapeHtml(tweet.username)}</div>
                    <div class="tweet-username">@${escapeHtml(tweet.username)}</div>
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
        if (!e.target.closest('.tweet-action') && !e.target.closest('.follow-btn')) {
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

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
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
    ?>
    <div class="tweet" data-tweet-id="<?= $tweet['id'] ?>">
        <div class="tweet-header">
            <img src="<?= htmlspecialchars($tweet['profile_pic_url'] ?: 'default_profile.png') ?>"
                 alt="Profile" class="tweet-avatar"
                 onclick="window.location='profile.php?id=<?= $tweet['user_id'] ?>'" />
            <div class="tweet-content">
                <div class="tweet-user-info">
                    <div class="tweet-display-name"><?= htmlspecialchars($tweet['username']) ?></div>
                    <div class="tweet-username">@<?= htmlspecialchars($tweet['username']) ?></div>
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
                
                <?php if (!empty($tweet['media_url'])): ?>
                    <div class="tweet-media">
                        <?php
                        $mediaArray = explode(',', $tweet['media_url']);
                        $firstMedia = trim($mediaArray[0]);
                        if ($tweet['post_type'] === 'photo'): ?>
                            <img src="<?= htmlspecialchars($firstMedia) ?>" alt="Tweet image">
                        <?php elseif ($tweet['post_type'] === 'video'): ?>
                            <video controls>
                                <source src="<?= htmlspecialchars($firstMedia) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
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
                if (!e.target.closest('.tweet-action') && !e.target.closest('.follow-btn')) {
                    window.location.href = `comment.php?post_id=<?= $tweet['id'] ?>`;
                }
            });
        }
    });
    </script>
    <?php
}
?>