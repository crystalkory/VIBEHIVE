<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "config.php";

$currentUserId = $_SESSION['user_id'];

// Process search request
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchQuery = trim($_GET['search']);
    header("Location: search.php?q=" . urlencode($searchQuery));
    exit;
}

// Get user profile info
$userStmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$userStmt->execute([$currentUserId]);
$profilePicUrl = $userStmt->fetchColumn() ?: 'default_profile.png';

// Get unread notifications count
$unreadNotificationsStmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unreadNotificationsStmt->execute([$currentUserId]);
$unreadNotificationsCount = $unreadNotificationsStmt->fetchColumn();

// Get recent notifications
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
$notificationsStmt->execute([$currentUserId]);
$notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get extended followed users (6 degrees)
function getExtendedFollowedUsers($pdo, $userId, $maxDepth = 6) {
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

// Function to get extended friends (5 degrees)
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

// Function to get followed users' friends (5 degrees)
function getFollowedUsersFriends($pdo, $userId, $maxDepth = 5) {
    // First get all users the current user follows
    $followedStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $followedStmt->execute([$userId]);
    $directFollowed = $followedStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    if (empty($directFollowed)) {
        return [];
    }
    
    $allFriendsOfFollowed = [];
    
    foreach ($directFollowed as $followedId) {
        $friendsOfFollowed = getExtendedFriends($pdo, $followedId, $maxDepth);
        $allFriendsOfFollowed = array_merge($allFriendsOfFollowed, $friendsOfFollowed);
    }
    
    return array_unique($allFriendsOfFollowed);
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

// Calculate relationship score between users
function calculateRelationshipScore($pdo, $currentUserId, $targetUserId, $extendedFollowed, $extendedFriends, $followedUsersFriends, $visitedProfiles) {
    if ($currentUserId == $targetUserId) {
        return 10.0;
    }
    
    $score = 0;
    
    // Direct follow (strongest signal)
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :follower_id AND followed_id = :followed_id");
    $stmt->execute(['follower_id' => $currentUserId, 'followed_id' => $targetUserId]);
    $isDirectFollow = (bool)$stmt->fetchColumn();
    
    if ($isDirectFollow) {
        $score += 8.0;
        
        // Mutual follow (even stronger)
        $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = :followed_id AND followed_id = :follower_id");
        $stmt->execute(['follower_id' => $currentUserId, 'followed_id' => $targetUserId]);
        $isMutual = (bool)$stmt->fetchColumn();
        
        if ($isMutual) {
            $score += 4.0;
        }
    }
    
    // Extended network connections with different weights
    if (in_array($targetUserId, $extendedFollowed)) {
        $score += 3.0;
    }
    
    if (in_array($targetUserId, $extendedFriends)) {
        $score += 4.0;
    }
    
    if (in_array($targetUserId, $followedUsersFriends)) {
        $score += 3.5;
    }
    
    // Profile visit boost
    if (in_array($targetUserId, $visitedProfiles)) {
        $score += 2.5;
    }
    
    // Recent interactions boost
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM likes l 
        JOIN posts p ON l.post_id = p.id 
        WHERE ((l.user_id = :user1 AND p.user_id = :user2) OR (l.user_id = :user2 AND p.user_id = :user1))
        AND l.created_at > NOW() - INTERVAL '7 days'
    ");
    $stmt->execute(['user1' => $currentUserId, 'user2' => $targetUserId]);
    $recentInteractions = (int)$stmt->fetchColumn();
    $score += $recentInteractions * 0.5;
    
    return $score;
}

// Helper function to determine network type for display
function getNetworkType($targetUserId, $currentUserId, $directFollowed, $extendedFollowed, $extendedFriends, $followedUsersFriends, $visitedProfiles) {
    if ($targetUserId == $currentUserId) return 'you';
    if (in_array($targetUserId, $directFollowed)) return 'direct_follow';
    if (in_array($targetUserId, $extendedFollowed)) return 'extended_follow';
    if (in_array($targetUserId, $extendedFriends)) return 'extended_friend';
    if (in_array($targetUserId, $followedUsersFriends)) return 'followed_friends';
    if (in_array($targetUserId, $visitedProfiles)) return 'visited_profile';
    return 'suggested';
}

// Get extended networks with error handling
try {
    $extendedFollowedUsers = getExtendedFollowedUsers($pdo, $currentUserId, 6);
    $extendedFriends = getExtendedFriends($pdo, $currentUserId, 5);
    $followedUsersFriends = getFollowedUsersFriends($pdo, $currentUserId, 5);
    $visitedProfiles = getVisitedProfiles($pdo, $currentUserId, 30);
} catch (PDOException $e) {
    $extendedFollowedUsers = [];
    $extendedFriends = [];
    $followedUsersFriends = [];
    $visitedProfiles = [];
}

// Get users the current user is already following
$followingStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$followingStmt->execute([$currentUserId]);
$alreadyFollowing = $followingStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get current user's friends
$friendsStmt = $pdo->prepare("
    SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'
    UNION 
    SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted'
");
$friendsStmt->execute([$currentUserId, $currentUserId]);
$currentUserFriends = $friendsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get pending friend requests
$pendingStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'pending'");
$pendingStmt->execute([$currentUserId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Combine all potential users from extended networks
$allPotentialUsers = array_unique(array_merge(
    $extendedFollowedUsers, 
    $extendedFriends, 
    $followedUsersFriends,
    $visitedProfiles
));

// Remove current user and users already being followed
$filteredUsers = array_diff($allPotentialUsers, [$currentUserId], $alreadyFollowing);

// Get user details for filtered users and calculate scores
$scoredUsers = [];
if (!empty($filteredUsers)) {
    $placeholders = str_repeat('?,', count($filteredUsers) - 1) . '?';
    $usersStmt = $pdo->prepare("
        SELECT u.id, u.username, u.profile_pic_url, u.category1, u.category2, u.bio,
               COUNT(f.follower_id) as follower_count,
               COUNT(p.id) as post_count
        FROM users u 
        LEFT JOIN follows f ON u.id = f.followed_id 
        LEFT JOIN posts p ON u.id = p.user_id AND p.privacy_setting = 'public'
        WHERE u.id IN ($placeholders)
        GROUP BY u.id, u.username, u.profile_pic_url, u.category1, u.category2, u.bio
    ");
    $usersStmt->execute(array_values($filteredUsers));
    $suggestedUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate scores and add network information
    foreach ($suggestedUsers as $user) {
        $userId = $user['id'];
        $relationshipScore = calculateRelationshipScore(
            $pdo, 
            $currentUserId, 
            $userId, 
            $extendedFollowedUsers, 
            $extendedFriends, 
            $followedUsersFriends,
            $visitedProfiles
        );
        
        $networkType = getNetworkType(
            $userId, 
            $currentUserId, 
            $alreadyFollowing, 
            $extendedFollowedUsers, 
            $extendedFriends, 
            $followedUsersFriends,
            $visitedProfiles
        );
        
        // Engagement score based on follower count and post count
        $engagementScore = ($user['follower_count'] * 0.3) + ($user['post_count'] * 0.1);
        
        // Final score calculation (similar to feed algorithm)
        $finalScore = (
            $relationshipScore * 0.6 +
            $engagementScore * 0.3 +
            (in_array($userId, $visitedProfiles) ? 2.0 : 0)
        );
        
        $user['relationship_score'] = $relationshipScore;
        $user['engagement_score'] = $engagementScore;
        $user['final_score'] = $finalScore;
        $user['network_type'] = $networkType;
        $user['network_sources'] = [];
        
        // Add network source information
        if (in_array($userId, $extendedFollowedUsers)) {
            $user['network_sources'][] = 'Follow Network (6°)';
        }
        if (in_array($userId, $extendedFriends)) {
            $user['network_sources'][] = 'Friend Network (5°)';
        }
        if (in_array($userId, $followedUsersFriends)) {
            $user['network_sources'][] = 'Followed Users Friends (5°)';
        }
        if (in_array($userId, $visitedProfiles)) {
            $user['network_sources'][] = 'Visited Profiles';
        }
        
        $scoredUsers[] = $user;
    }
    
    // Sort by final score (highest first)
    usort($scoredUsers, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });
    
    $suggestedUsers = $scoredUsers;
} else {
    $suggestedUsers = [];
}

// AJAX handlers for friend requests and follow functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Friend request handler
    if (isset($_POST['action']) && $_POST['action'] === 'send_friend_request' && isset($_POST['friend_id'])) {
        $friendId = (int)$_POST['friend_id'];
        
        if ($friendId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'Cannot send friend request to yourself']);
            exit;
        }
        
        try {
            // Check if request already exists
            $checkStmt = $pdo->prepare("SELECT status FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
            $checkStmt->execute([$currentUserId, $friendId, $friendId, $currentUserId]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                if ($existing['status'] === 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Friend request already sent']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Already friends']);
                }
                exit;
            }
            
            // Send friend request
            $insertStmt = $pdo->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')");
            $insertStmt->execute([$currentUserId, $friendId]);
            
            echo json_encode(['success' => true, 'message' => 'Friend request sent']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Follow/unfollow handler
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
        $followedId = (int)$_POST['followed_id'];
        
        if ($followedId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'Cannot follow yourself']);
            exit;
        }
        
        try {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$currentUserId, $followedId]);
                
                // Create notification
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message) VALUES (?, 'follow', ?, ?)");
                $message = "started following you";
                $stmt->execute([$followedId, $currentUserId, $message]);
                
                echo json_encode(['success' => true, 'action' => 'followed']);
            } else {
                $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
                $stmt->execute([$currentUserId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'unfollowed']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Format time difference for notifications
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>People You May Know - Smart Recommendations</title>
<style>
  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }
  
  body { 
    font-family: Arial, sans-serif;
    max-width: 1200px; 
    margin: 20px auto; 
    background: #1e1e2f; 
    color: white;
    padding: 0 15px;
  }
  
  /* Navigation */
  .navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 2px solid #7b68ee;
  }
  
  .nav-links {
    display: flex;
    gap: 20px;
    align-items: center;
  }
  
  .nav-links a {
    color: #7b68ee;
    text-decoration: none;
    font-weight: bold;
    padding: 8px 16px;
    border-radius: 20px;
    transition: all 0.3s ease;
  }
  
  .nav-links a:hover {
    background: #7b68ee;
    color: white;
  }
  
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
    background: #2c2c3d;
    color: white;
    font-size: 16px;
  }
  
  .search-box:focus {
    outline: none;
    border-color: #9370db;
  }
  
  .notifications-container {
    position: relative;
    display: inline-block;
  }
  
  .notifications-btn {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    position: relative;
    padding: 8px 16px;
    border-radius: 20px;
    background: #2c2c3d;
    border: 2px solid #7b68ee;
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
  
  /* Header */
  .header {
    text-align: center;
    margin-bottom: 30px;
  }
  
  h2 {
    color: #7b68ee;
    margin-bottom: 10px;
    font-size: 28px;
  }
  
  .subtitle {
    color: #aaa;
    font-size: 16px;
    margin-bottom: 20px;
  }
  
  .stats {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-top: 20px;
    flex-wrap: wrap;
  }
  
  .stat-item {
    background: #2c2c3d;
    padding: 15px 25px;
    border-radius: 10px;
    border: 1px solid #7b68ee;
    text-align: center;
  }
  
  .stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #7b68ee;
  }
  
  .stat-label {
    font-size: 14px;
    color: #aaa;
    margin-top: 5px;
  }
  
  .users-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 25px;
    margin-bottom: 40px;
  }
  
  .user-card {
    background: #2c2c3d;
    border: 2px solid #7b68ee;
    border-radius: 15px;
    padding: 25px 20px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
    position: relative;
  }
  
  .user-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.4);
    border-color: #9370db;
  }
  
  .profile-img-container {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    overflow: hidden;
    border: 3px solid #7b68ee;
    cursor: pointer;
    transition: all 0.3s ease;
  }
  
  .profile-img-container:hover {
    border-color: #9370db;
    transform: scale(1.05);
  }
  
  .profile-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  
  .user-name {
    font-size: 18px;
    font-weight: bold;
    color: #7b68ee;
    cursor: pointer;
    transition: color 0.3s ease;
    margin-top: 5px;
  }
  
  .user-name:hover {
    color: #9370db;
    text-decoration: underline;
  }
  
  .user-bio {
    font-size: 14px;
    color: #ccc;
    line-height: 1.4;
    margin-bottom: 10px;
    max-height: 3em;
    overflow: hidden;
  }
  
  .user-categories {
    font-size: 14px;
    color: #aaa;
    margin-bottom: 5px;
  }
  
  .user-stats {
    display: flex;
    gap: 15px;
    font-size: 12px;
    color: #888;
    margin-bottom: 10px;
  }
  
  .user-stat {
    background: rgba(123, 104, 238, 0.1);
    padding: 4px 8px;
    border-radius: 10px;
  }
  
  .network-sources {
    font-size: 11px;
    color: #7b68ee;
    font-weight: bold;
    margin-bottom: 15px;
    text-align: center;
    line-height: 1.4;
  }
  
  .network-source {
    display: inline-block;
    background: rgba(123, 104, 238, 0.2);
    padding: 3px 8px;
    border-radius: 10px;
    margin: 2px;
    font-size: 10px;
  }
  
  .network-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    font-size: 10px;
    padding: 4px 8px;
    border-radius: 10px;
    font-weight: bold;
  }
  
  .badge-direct { background: #28a745; color: white; }
  .badge-extended { background: #ffc107; color: black; }
  .badge-friend { background: #17a2b8; color: white; }
  .badge-followed-friends { background: #6f42c1; color: white; }
  .badge-visited { background: #e83e8c; color: white; }
  .badge-suggested { background: #6c757d; color: white; }
  
  .action-buttons {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    max-width: 200px;
  }
  
  .add-friend-btn {
    padding: 10px 15px;
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
  }
  
  .add-friend-btn:hover:not(.pending):not(.friends) {
    background: linear-gradient(45deg, #218838, #1e9e8a);
    transform: scale(1.05);
  }
  
  .add-friend-btn.pending {
    background: #ffc107;
    color: #000;
    cursor: not-allowed;
  }
  
  .add-friend-btn.friends {
    background: #6c757d;
    color: white;
    cursor: not-allowed;
  }
  
  .follow-btn {
    padding: 10px 15px;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(45deg, #ff004f, #c972ff);
    color: white;
    transition: all 0.3s ease;
    font-size: 14px;
  }
  
  .follow-btn:hover:not(.following) {
    background: linear-gradient(45deg, #d60040, #a85cd6);
    transform: scale(1.05);
  }
  
  .follow-btn.following {
    background: #6c757d;
    color: #ddd;
    cursor: default;
  }
  
  .no-users {
    text-align: center;
    padding: 60px 40px;
    font-size: 18px;
    color: #aaa;
    background: #2c2c3d;
    border-radius: 15px;
    border: 2px solid #7b68ee;
    grid-column: 1 / -1;
  }
  
  .friend-icon {
    font-size: 16px;
  }
  
  .score-indicator {
    position: absolute;
    top: 15px;
    left: 15px;
    background: rgba(123, 104, 238, 0.3);
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 10px;
    color: #ccc;
  }
  
  /* Message Toast */
  .message-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 6px;
    color: white;
    font-weight: bold;
    z-index: 1000;
    transition: all 0.3s ease;
  }
  
  .message-toast.success {
    background: #28a745;
  }
  
  .message-toast.error {
    background: #dc3545;
  }
  
  /* Responsive Design */
  @media (max-width: 1024px) {
    .users-grid {
      grid-template-columns: repeat(2, 1fr);
    }
  }
  
  @media (max-width: 768px) {
    .users-grid {
      grid-template-columns: 1fr;
      gap: 20px;
    }
    
    body {
      padding: 0 10px;
    }
    
    .navigation {
      flex-direction: column;
      gap: 15px;
    }
    
    .search-container {
      max-width: 100%;
      margin: 0;
    }
    
    h2 {
      font-size: 24px;
    }
    
    .stats {
      gap: 15px;
    }
    
    .stat-item {
      padding: 10px 15px;
    }
    
    .user-card {
      padding: 20px 15px;
    }
    
    .profile-img-container {
      width: 80px;
      height: 80px;
    }
  }
  
  @media (max-width: 480px) {
    .users-grid {
      gap: 15px;
    }
    
    .user-card {
      padding: 15px 12px;
    }
    
    .profile-img-container {
      width: 70px;
      height: 70px;
    }
    
    .user-name {
      font-size: 16px;
    }
    
    .action-buttons {
      max-width: 100%;
    }
    
    .notifications-dropdown {
      width: 300px;
      right: -50px;
    }
  }
</style>
</head>
<body>

<!-- Navigation -->
<div class="navigation">
    <div class="nav-links">
        <a href="home.php">🏠 Home</a>
        <a href="profile.php?id=<?= urlencode($currentUserId) ?>">👤 My Profile</a>
        <a href="relate.php" style="background: #7b68ee; color: white;">🔍 Discover People</a>
    </div>
    
    <!-- Search Bar -->
    <div class="search-container">
        <form method="GET" action="relate.php">
            <input type="text" name="search" class="search-box" placeholder="Search people..." 
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </form>
    </div>

    <!-- Notifications -->
    <div class="notifications-container">
        <button class="notifications-btn" onclick="toggleNotifications()">
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

<!-- Header Section -->
<div class="header">
    <h2>🔍 People You May Know</h2>
    <p class="subtitle">Smart recommendations based on your network and interactions</p>
    
    <div class="stats">
        <div class="stat-item">
            <div class="stat-number"><?= count($extendedFollowedUsers) - 1 ?></div>
            <div class="stat-label">Extended Follow Network</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?= count($extendedFriends) - 1 ?></div>
            <div class="stat-label">Friend Network</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?= count($followedUsersFriends) ?></div>
            <div class="stat-label">Followed Users' Friends</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?= count($suggestedUsers) ?></div>
            <div class="stat-label">Recommended Users</div>
        </div>
    </div>
</div>

<div class="users-grid" id="users-container">
  <?php if (empty($suggestedUsers)): ?>
    <div class="no-users">
      <p>No new people to discover right now.</p>
      <p>Try expanding your network by connecting with more people!</p>
    </div>
  <?php else: ?>
    <?php foreach ($suggestedUsers as $user): 
      $userId = $user['id'];
      
      // Check relationship status
      $isFriend = in_array($userId, $currentUserFriends);
      $isPending = in_array($userId, $pendingRequests);
      
      // Build categories string
      $userCategories = [];
      if ($user['category1']) $userCategories[] = $user['category1'];
      if ($user['category2']) $userCategories[] = $user['category2'];
      $categoriesText = !empty($userCategories) ? implode(', ', $userCategories) : 'No categories specified';
      
      // Get badge class based on network type
      $badgeClass = 'badge-' . str_replace('_', '-', $user['network_type']);
    ?>
      <div class="user-card" data-user-id="<?= $user['id'] ?>">
        <!-- Score Indicator -->
        <div class="score-indicator" title="Recommendation Score: <?= round($user['final_score'], 2) ?>">
          ⭐ <?= round($user['final_score'], 1) ?>
        </div>
        
        <!-- Network Badge -->
        <div class="network-badge <?= $badgeClass ?>">
          <?= 
            $user['network_type'] === 'direct_follow' ? 'Following' :
            ($user['network_type'] === 'extended_follow' ? 'Network' :
            ($user['network_type'] === 'extended_friend' ? 'Friend' :
            ($user['network_type'] === 'followed_friends' ? 'Followed Friends' :
            ($user['network_type'] === 'visited_profile' ? 'Visited' : 'Suggested'))))
          ?>
        </div>
        
        <div class="profile-img-container" onclick="window.location.href='profile.php?id=<?= $user['id'] ?>'">
          <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" 
               alt="Profile" 
               class="profile-img" />
        </div>
        
        <div class="user-name" onclick="window.location.href='profile.php?id=<?= $user['id'] ?>'">
          <?= htmlspecialchars($user['username']) ?>
        </div>
        
        <?php if (!empty($user['bio'])): ?>
          <div class="user-bio"><?= htmlspecialchars($user['bio']) ?></div>
        <?php endif; ?>
        
        <div class="user-categories"><?= htmlspecialchars($categoriesText) ?></div>
        
        <div class="user-stats">
          <div class="user-stat">👥 <?= $user['follower_count'] ?> followers</div>
          <div class="user-stat">📝 <?= $user['post_count'] ?> posts</div>
        </div>
        
        <!-- Network Sources Display -->
        <div class="network-sources">
          <?php foreach ($user['network_sources'] as $source): ?>
            <span class="network-source"><?= htmlspecialchars($source) ?></span>
          <?php endforeach; ?>
        </div>
        
        <div class="action-buttons">
          <?php if (!$isFriend): ?>
            <button class="add-friend-btn <?= $isPending ? 'pending' : '' ?>" 
                    data-user-id="<?= $user['id'] ?>"
                    <?= $isPending ? 'disabled' : '' ?>>
              <span class="friend-icon">👥</span>
              <?= $isPending ? 'Request Sent' : 'Add Friend' ?>
            </button>
          <?php endif; ?>
          
          <button class="follow-btn" data-user-id="<?= $user['id'] ?>">
            Follow
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
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
            const countElement = document.querySelector('.notifications-btn span');
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

// Add Friend functionality
document.querySelectorAll('.add-friend-btn').forEach(button => {
    button.addEventListener('click', async function() {
        const btn = this;
        const userId = btn.getAttribute('data-user-id');
        
        if (btn.disabled) return;

        const formData = new FormData();
        formData.append('action', 'send_friend_request');
        formData.append('friend_id', userId);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                btn.innerHTML = '<span class="friend-icon">👥</span> Request Sent';
                btn.classList.add('pending');
                btn.disabled = true;
                
                showMessage('Friend request sent successfully!', 'success');
            } else {
                showMessage(data.message || 'Failed to send friend request', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showMessage('Error sending friend request', 'error');
        }
    });
});

// Follow functionality
document.querySelectorAll('.follow-btn').forEach(button => {
    button.addEventListener('click', async function() {
        const btn = this;
        const userId = btn.getAttribute('data-user-id');
        
        if (btn.classList.contains('following')) return;

        const action = 'follow';
        const formData = new FormData();
        formData.append('action', action);
        formData.append('followed_id', userId);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                btn.textContent = 'Following';
                btn.classList.add('following');
                
                // Update all follow buttons for this user
                document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(followBtn => {
                    followBtn.textContent = 'Following';
                    followBtn.classList.add('following');
                });
                
                showMessage('Now following ' + btn.closest('.user-card').querySelector('.user-name').textContent, 'success');
            } else {
                showMessage(data.message || 'Failed to follow user', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showMessage('Error following user', 'error');
        }
    });
});

// Message display function
function showMessage(message, type) {
    const existingMessage = document.querySelector('.message-toast');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message-toast ${type}`;
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 6px;
        color: white;
        font-weight: bold;
        z-index: 1000;
        transition: all 0.3s ease;
        ${type === 'success' ? 'background: #28a745;' : 'background: #dc3545;'}
    `;
    
    document.body.appendChild(messageDiv);
    
    setTimeout(() => {
        messageDiv.style.opacity = '0';
        messageDiv.style.transform = 'translateX(100px)';
        setTimeout(() => messageDiv.remove(), 300);
    }, 3000);
}

// Add animation for page load
document.addEventListener('DOMContentLoaded', function() {
    const userCards = document.querySelectorAll('.user-card');
    userCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.6s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});

// Close notifications dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.notifications-container')) {
        document.getElementById('notificationsDropdown').style.display = 'none';
    }
});
</script>

</body>
</html>