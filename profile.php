<?php
// Start output buffering at the VERY TOP
ob_start();

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

// ========== HANDLE ALL AJAX POST REQUESTS FIRST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Always set JSON header for POST requests (since all POSTs are AJAX)
    header('Content-Type: application/json');
    error_reporting(0);
    
    $currentUserId = $_SESSION['user_id'] ?? null;
    
    if (!$currentUserId) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    try {
        $host = 'localhost';
        $port = '5432';
        $dbname = 'fbclone';
        $user = 'postgres';
        $password = 'Gi12,br12';
        
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Handle AJAX actions
        $action = $_POST['action'] ?? '';
        
        // --- GET POST DATA FOR COMMENTS POPUP ---
        if ($action === 'get_post_data' && isset($_POST['post_id'])) {
            $postId = (int)$_POST['post_id'];
            $userId = $currentUserId;
            
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
        }
        
        // --- GET POST COMMENT REPLIES ---
        if ($action === 'get_post_replies' && isset($_POST['comment_id'])) {
            $commentId = (int)$_POST['comment_id'];
            $userId = $currentUserId;
            
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
        }
        
        // --- GET AD DATA FOR COMMENTS POPUP ---
        if ($action === 'get_ad_data' && isset($_POST['ad_id'])) {
            $adId = (int)$_POST['ad_id'];
            $currentUserId = $_SESSION['user_id'];
            
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
        }
        
        // --- GET AD COMMENT REPLIES ---
        if ($action === 'get_ad_replies' && isset($_POST['comment_id'])) {
            $commentId = (int)$_POST['comment_id'];
            $currentUserId = $_SESSION['user_id'];
            
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
        }
        
        // --- ADD POST COMMENT HANDLER ---
        if ($action === 'add_post_comment' && isset($_POST['post_id'])) {
            $postId = (int)$_POST['post_id'];
            $content = trim($_POST['content'] ?? '');
            $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
            $currentUserId = $_SESSION['user_id'];
            
            if (empty($content)) {
                echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
                exit;
            }
            
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
        }
        
        // --- ADD AD COMMENT HANDLER ---
        if ($action === 'add_ad_comment' && isset($_POST['ad_id'])) {
            $adId = (int)$_POST['ad_id'];
            $content = trim($_POST['content'] ?? '');
            $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
            $currentUserId = $_SESSION['user_id'];
            
            if (empty($content)) {
                echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
                exit;
            }
            
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
        }
        
        // --- DELETE POST COMMENT HANDLER ---
        if ($action === 'delete_post_comment' && isset($_POST['comment_id'])) {
            $commentId = (int)$_POST['comment_id'];
            $postId = (int)($_POST['post_id'] ?? 0);
            $currentUserId = $_SESSION['user_id'];
            
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
        }
        
        // --- DELETE AD COMMENT HANDLER ---
        if ($action === 'delete_ad_comment' && isset($_POST['comment_id'])) {
            $commentId = (int)$_POST['comment_id'];
            $adId = (int)($_POST['ad_id'] ?? 0);
            $currentUserId = $_SESSION['user_id'];
            
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
        }

        // --- FOLLOW/UNFOLLOW HANDLER ---
        if (in_array($action, ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
            $followerId = $_SESSION['user_id'];
            $followedId = (int)$_POST['followed_id'];
            
            if ($followerId && $followedId && $followerId !== $followedId) {
                if ($action === 'follow') {
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
        if ($action === 'send_request' && isset($_POST['friend_id'])) {
            $friendId = (int)$_POST['friend_id'];
            $currentUserId = $_SESSION['user_id'];
            
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
        if (in_array($action, ['like', 'unlike']) && isset($_POST['post_id'])) {
            $userId = $_SESSION['user_id'];
            $postId = (int)$_POST['post_id'];
            
            if ($action === 'like') {
                $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$postId, $userId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
                $stmt->execute([$postId, $userId]);
            }
            
            // Get updated like count
            $likeStmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
            $likeStmt->execute([$postId]);
            $newLikeCount = (int)$likeStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
            exit;
        }

        // --- AD LIKE/UNLIKE HANDLER ---
        if (in_array($action, ['like_ad', 'unlike_ad']) && isset($_POST['ad_id'])) {
            $adId = (int)$_POST['ad_id'];
            $currentUserId = $_SESSION['user_id'];
            
            if ($action === 'like_ad') {
                $stmt = $pdo->prepare("INSERT INTO ad_likes (ad_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$adId, $currentUserId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM ad_likes WHERE ad_id = ? AND user_id = ?");
                $stmt->execute([$adId, $currentUserId]);
            }
            
            // Get updated like count
            $likeStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
            $likeStmt->execute([$adId]);
            $newLikeCount = (int)$likeStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'likes_count' => $newLikeCount]);
            exit;
        }

        // --- AD SHARE HANDLER ---
        if ($action === 'share_ad' && isset($_POST['ad_id'])) {
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
            
            // Get updated share count
            $shareStmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
            $shareStmt->execute([$adId]);
            $newShareCount = (int)$shareStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'shares_count' => $newShareCount]);
            exit;
        }

        // --- DELETE POST HANDLER ---
        if ($action === 'delete_post' && isset($_POST['post_id'])) {
            $postId = (int)$_POST['post_id'];
            $currentUserId = $_SESSION['user_id'];
            
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

        // If we reach here, no valid action was found
        echo json_encode(['success' => false, 'error' => 'Invalid action or missing parameters']);
        exit;
        
    } catch (PDOException $e) {
        error_log("Database error in AJAX handler: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit;
    }
    
    // If no action was processed
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

// ========== REGULAR PAGE LOAD CONTINUES BELOW ==========

// Check if this is an AJAX GET request (for pagination)
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                 strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// For AJAX GET requests, we don't need to load the full HTML
if (!$isAjaxRequest) {
    require_once "footer.php";
}

$host = 'localhost';
$port = '5432';
$dbname = 'fbclone';
$user = 'postgres';
$password = 'Gi12,br12';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

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

// If this is an AJAX GET request (for pagination), only output the posts and exit
if ($isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'GET') {
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
                    <span class="comment-btn" onclick="openPostComments(<?= $post['id'] ?>)">
                        Comment (<span class="comment-count"><?= getCommentCount($pdo, $post['id']) ?></span>)
                    </span>
                    <span class="share-btn" onclick="sharePost(<?= $post['id'] ?>)">
                        Share (<span class="share-count"><?= getShareCount($pdo, $post['id']) ?></span>)
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
              
              <button class="comment-btn" onclick="openPostComments(<?= $boostPost['id'] ?>)">
                Comment (<span class="comment-count"><?= getCommentCount($pdo, $boostPost['id']) ?></span>)
              </button>
              
              <button class="share-btn" onclick="sharePost(<?= $boostPost['id'] ?>)">
                Share (<span class="share-count"><?= getShareCount($pdo, $boostPost['id']) ?></span>)
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
                    <span class="comment-btn" onclick="openPostComments(<?= $video['id'] ?>)">
                        Comment (<span class="comment-count"><?= getCommentCount($pdo, $video['id']) ?></span>)
                    </span>
                    <span class="share-btn" onclick="sharePost(<?= $video['id'] ?>)">
                        Share (<span class="share-count"><?= getShareCount($pdo, $video['id']) ?></span>)
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
              
              <button class="comment-btn" onclick="openPostComments(<?= $boostPost['id'] ?>)">
                Comment (<span class="comment-count"><?= getCommentCount($pdo, $boostPost['id']) ?></span>)
              </button>
              
              <button class="share-btn" onclick="sharePost(<?= $boostPost['id'] ?>)">
                Share (<span class="share-count"><?= getShareCount($pdo, $boostPost['id']) ?></span>)
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
        
    <?php 
        endforeach; 
    endif; 
}

// Only output the buffer for non-AJAX requests
if (!$isAjaxRequest) {
    require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($profileUser['username']) ?>'s Profile</title>
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

/* Add the pagination styles from people.php */
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
<?php endif; ?>

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
    
    // Attach comment button listeners to new posts
    document.querySelectorAll('.comment-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', function() {
                const postId = this.closest('.post').dataset.postId;
                if (postId) {
                    openPostComments(postId);
                }
            });
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
    
    // Attach ad comment button listeners
    document.querySelectorAll('.ad-action-btn.comment-btn').forEach(button => {
        if (!button.hasAttribute('data-listener-attached')) {
            button.setAttribute('data-listener-attached', 'true');
            button.addEventListener('click', function() {
                const adId = this.closest('.ad-post').dataset.adId;
                if (adId) {
                    openAdComments(adId);
                }
            });
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

// ========== POST COMMENTS POPUP FUNCTIONS ==========
async function openPostComments(postId) {
    console.log("DEBUG: Opening post comments for ID:", postId);
    
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
        
        const response = await fetch('profile.php', {
            method: 'POST',
            body: formData
        });
        
        // First, check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get response as text first to debug
        const responseText = await response.text();
        console.log("DEBUG: Raw response:", responseText.substring(0, 200));
        
        // Try to parse as JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.error("DEBUG: JSON parse error:", jsonError);
            console.error("DEBUG: Response text:", responseText);
            
            content.innerHTML = `
                <div style="color:white; text-align:center; padding:50px;">
                    <h3>Server Error</h3>
                    <p>Invalid JSON response from server.</p>
                    <p>Response: ${escapeHtml(responseText.substring(0, 100))}</p>
                    <button onclick="closePostComments()" style="margin-top:20px; padding:10px 20px; background:#7b68ee; color:white; border:none; border-radius:5px; cursor:pointer;">
                        Close
                    </button>
                </div>
            `;
            return;
        }
        
        if (data.success) {
            content.innerHTML = generatePostCommentsHTML(data.post, data.comments, data.currentUserId);
            attachPostCommentListeners(postId);
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
            ${comments && comments.length > 0 ? comments.map(comment => generatePostCommentHTML(comment, currentUserId, post.id, false)).join('') : '<div class="no-comments" style="text-align:center; padding:20px; color:#718096;">No comments yet. Be the first to comment!</div>'}
            </div>

            <h3>Add a comment</h3>
            <textarea id="newCommentText" placeholder="Write your comment here..."></textarea>
            <button onclick="addPostComment(${post.id}, null)">Post Comment</button>
            <div id="addCommentMessage" style="color:red; margin-top:5px;"></div>
        </section>
    </div>
    `;
}

function generatePostCommentHTML(comment, currentUserId, postId, isReply = false) {
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
            <button class="small-button" onclick="deletePostComment(${comment.id}, ${postId})">Delete</button>
            ` : ''}
            <button class="small-button reply-btn" onclick="showReplyForm(${comment.id})" style="color: #7b68ee;">Reply</button>
        </div>
        <div class="comment-content" id="comment-content-${comment.id}">${escapeHtml(comment.content || '').replace(/\n/g, '<br>')}</div>
        <textarea class="edit-area" id="edit-area-${comment.id}" style="display:none;"></textarea>
        <div id="edit-controls-${comment.id}" style="display:none; margin-left:60px; margin-bottom:10px;">
            <button onclick="savePostComment(${comment.id}, ${postId})">Save</button>
            <button onclick="cancelPostEdit(${comment.id})">Cancel</button>
        </div>
        
        <!-- Reply Form -->
        <div id="reply-form-${comment.id}" style="display:none; margin-top:10px; margin-left:60px;">
            <textarea id="reply-text-${comment.id}" placeholder="Write your reply..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #7b68ee;"></textarea>
            <div style="margin-top:5px;">
            <button onclick="addPostComment(${postId}, ${comment.id})" style="background:#7b68ee; color:white; border:none; padding:5px 15px; border-radius:5px;">Post Reply</button>
            <button onclick="hideReplyForm(${comment.id})" style="background:#718096; color:white; border:none; padding:5px 15px; border-radius:5px; margin-left:10px;">Cancel</button>
            </div>
        </div>
        
        <!-- Replies Section -->
        <div id="replies-container-${comment.id}">
            ${replyCount > 0 ? `
            <div id="replies-toggle-${comment.id}">
                <button onclick="loadPostReplies(${comment.id}, ${postId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
                ▼ Show ${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}
                </button>
            </div>
            <div id="replies-list-${comment.id}" style="display:none;"></div>
            ` : ''}
        </div>
    </div>
    `;
}

let currentCommentPostId = null;

function attachPostCommentListeners(postId) {
    currentCommentPostId = postId;
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

async function loadPostReplies(commentId, postId) {
    const repliesList = document.getElementById(`replies-list-${commentId}`);
    const toggleBtn = document.getElementById(`replies-toggle-${commentId}`);
    
    if (!repliesList || !toggleBtn) return;
    
    // If already loaded, just toggle visibility
    if (repliesList.innerHTML && repliesList.innerHTML.trim() !== '') {
        if (repliesList.style.display === 'none') {
            repliesList.style.display = 'block';
            toggleBtn.innerHTML = `<button onclick="loadPostReplies(${commentId}, ${postId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
            ▲ Hide replies
            </button>`;
        } else {
            repliesList.style.display = 'none';
            toggleBtn.innerHTML = `<button onclick="loadPostReplies(${commentId}, ${postId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
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
        
        const response = await fetch('profile.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.replies && data.replies.length > 0) {
            let repliesHTML = '';
            data.replies.forEach(reply => {
                repliesHTML += generatePostCommentHTML(reply, data.currentUserId, postId, true);
            });
            repliesList.innerHTML = repliesHTML;
            
            // Update toggle button
            toggleBtn.innerHTML = `<button onclick="loadPostReplies(${commentId}, ${postId})" class="show-replies-btn" style="color:#7b68ee; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
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
        const response = await fetch('profile.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // If it's a reply and we're showing replies, refresh the replies
            if (parentCommentId && data.is_reply) {
                loadPostReplies(parentCommentId, postId);
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
        const response = await fetch('profile.php', {
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

function closePostComments() {
    document.getElementById('fullscreenPostComments').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentCommentPostId = null;
}

// ========== AD COMMENTS POPUP FUNCTIONS ==========
async function openAdComments(adId) {
    console.log("DEBUG: Opening ad comments for ID:", adId);
    
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
        
        const response = await fetch('profile.php', {
            method: 'POST',
            body: formData
        });
        
        // First, check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get response as text first to debug
        const responseText = await response.text();
        console.log("DEBUG: Raw response:", responseText.substring(0, 200));
        
        // Try to parse as JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.error("DEBUG: JSON parse error:", jsonError);
            console.error("DEBUG: Response text:", responseText);
            
            content.innerHTML = `
                <div style="color:white; text-align:center; padding:50px;">
                    <h3>Server Error</h3>
                    <p>Invalid JSON response from server.</p>
                    <p>Response: ${escapeHtml(responseText.substring(0, 100))}</p>
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
            <form onsubmit="submitAdComment(event, ${adId}, ${comment.id})">
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
        
        const response = await fetch('profile.php', {
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
        const response = await fetch('profile.php', {
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
        const response = await fetch('profile.php', {
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

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    showTab('posts');
    
    // Initialize video controls for people videos
    initVideoControls();
    
    // Initialize infinite scroll for posts tab
    initInfiniteScroll();
    
    // Attach event listeners to existing posts
    attachEventListenersToNewPosts();
});
</script>
<?php
require_once "reload.php";
?>
</body>
</html>
<?php
    ob_end_flush();
} else {
    // For AJAX GET requests, clean the buffer without outputting
    ob_end_clean();
}
?>