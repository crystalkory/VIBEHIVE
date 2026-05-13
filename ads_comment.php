<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$adId = isset($_GET['ad_id']) ? (int)$_GET['ad_id'] : 0;

// ========== AJAX HANDLERS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // --- ADD AD COMMENT (WITH REPLIES) ---
    if ($_POST['action'] === 'add_comment') {
        $content = trim($_POST['content'] ?? '');
        $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
        
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
            
            // Insert comment with parent_comment_id support
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
    
    // --- DELETE AD COMMENT ---
    if ($_POST['action'] === 'delete_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        
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
    
    // --- GET AD COMMENT REPLIES ---
    if ($_POST['action'] === 'get_replies') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        
        try {
            // Fetch replies for this ad comment
            $repliesStmt = $pdo->prepare("
                SELECT ac.*, u.username, u.profile_pic_url
                FROM ad_comments ac 
                JOIN users u ON ac.user_id = u.id 
                WHERE ac.parent_comment_id = ?
                ORDER BY ac.created_at ASC
            ");
            $repliesStmt->execute([$commentId]);
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
    
    // --- EDIT AD COMMENT ---
    if ($_POST['action'] === 'edit_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $newContent = trim($_POST['content'] ?? '');
        
        if (empty($newContent)) {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }
        
        try {
            // Update only if comment belongs to current user
            $updateStmt = $pdo->prepare("
                UPDATE ad_comments 
                SET content = ?, updated_at = NOW() 
                WHERE id = ? AND user_id = ?
            ");
            $updateStmt->execute([$newContent, $commentId, $currentUserId]);
            
            if ($updateStmt->rowCount() > 0) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Comment not found or you don\'t have permission']);
            }
            exit;
            
        } catch (PDOException $e) {
            error_log("Edit ad comment error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
}

// Fetch ad details
$adStmt = $pdo->prepare("
    SELECT a.*, u.username, u.profile_pic_url, u.id as advertiser_id
    FROM ads a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.id = ? AND a.status = 'active' AND a.ends_at > CURRENT_TIMESTAMP
");
$adStmt->execute([$adId]);
$ad = $adStmt->fetch(PDO::FETCH_ASSOC);

if (!$ad) {
    die("Advertisement not found or expired.");
}

// Fetch main comments (where parent_comment_id is NULL)
function fetchMainAdComments($pdo, $adId) {
    $stmt = $pdo->prepare("
        SELECT ac.*, u.username, u.profile_pic_url,
               (SELECT COUNT(*) FROM ad_comments r WHERE r.parent_comment_id = ac.id) as reply_count
        FROM ad_comments ac 
        JOIN users u ON ac.user_id = u.id 
        WHERE ac.ad_id = ? AND ac.parent_comment_id IS NULL
        ORDER BY ac.created_at ASC
    ");
    $stmt->execute([$adId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$comments = fetchMainAdComments($pdo, $adId);

// Get like count for this ad
$likeStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
$likeStmt->execute([$adId]);
$likeCount = $likeStmt->fetchColumn();

// Check if current user liked this ad
$userLikeStmt = $pdo->prepare("SELECT 1 FROM ad_likes WHERE ad_id = ? AND user_id = ?");
$userLikeStmt->execute([$adId, $currentUserId]);
$userLiked = (bool)$userLikeStmt->fetchColumn();

// Get share count for this ad
$shareStmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
$shareStmt->execute([$adId]);
$shareCount = $shareStmt->fetchColumn();

// Get TOTAL comment count (including replies)
$totalCommentStmt = $pdo->prepare("SELECT COUNT(*) FROM ad_comments WHERE ad_id = ?");
$totalCommentStmt->execute([$adId]);
$totalCommentCount = (int)$totalCommentStmt->fetchColumn();

// Get current user profile pic
$userStmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$userStmt->execute([$currentUserId]);
$userProfilePic = $userStmt->fetchColumn() ?: 'default_profile.png';

// Format time difference function
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

// Helper function to generate comment HTML
function generateAdCommentHTML($comment, $currentUserId, $advertiserId, $isReply = false) {
    if (!$comment) return '';
    
    $canDelete = $comment['user_id'] == $currentUserId || $advertiserId == $currentUserId;
    $commentTime = timeAgo($comment['created_at']);
    $replyCount = $comment['reply_count'] || 0;
    
    return '
    <div class="comment" id="comment-' . $comment['id'] . '" style="' . ($isReply ? 'margin-left: 60px; background: rgba(255,255,255,0.7); padding: 15px; border-radius: 8px; margin-top: 10px;' : '') . '">
        <img src="' . htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') . '" 
             alt="' . htmlspecialchars($comment['username'] ?: 'User') . '" 
             class="comment-user-img">
        <div class="comment-content">
            <div class="comment-header">
                <span class="comment-username">
                    ' . htmlspecialchars($comment['username'] ?: 'User') . '
                </span>
                <span class="comment-time">
                    ' . $commentTime . '
                </span>
            </div>
            <div class="comment-text">
                ' . nl2br(htmlspecialchars($comment['content'] ?: '')) . '
            </div>
            <div class="comment-actions">
                <button type="button" 
                        class="comment-action reply"
                        onclick="showReplyForm(' . $comment['id'] . ')">
                    Reply
                </button>
                ' . ($canDelete ? '
                <button type="button" 
                        class="comment-action delete"
                        onclick="deleteAdComment(' . $comment['id'] . ')">
                    Delete
                </button>
                ' : '') . '
            </div>
            
            <!-- Reply Form (Hidden by Default) -->
            <div id="reply-form-' . $comment['id'] . '" style="display:none; margin-top:10px;">
                <form onsubmit="submitAdComment(event, ' . $comment['ad_id'] . ', ' . $comment['id'] . ')">
                    <textarea name="content" 
                              class="comment-input" 
                              placeholder="Write your reply..." 
                              required
                              style="width:100%; padding:10px; border-radius:8px; border:1px solid #667eea;"></textarea>
                    <div style="margin-top:5px;">
                        <button type="submit" class="comment-submit" style="padding:5px 15px; font-size:12px;">Post Reply</button>
                        <button type="button" 
                                onclick="hideReplyForm(' . $comment['id'] . ')"
                                style="background:#718096; color:white; border:none; padding:5px 15px; border-radius:5px; margin-left:10px; font-size:12px;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Replies Section -->
            <div id="replies-container-' . $comment['id'] . '">
                ' . ($replyCount > 0 ? '
                    <div id="replies-toggle-' . $comment['id'] . '">
                        <button onclick="loadReplies(' . $comment['id'] . ')" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
                            ▼ Show ' . $replyCount . ' ' . ($replyCount === 1 ? 'reply' : 'replies') . '
                        </button>
                    </div>
                    <div id="replies-list-' . $comment['id'] . '" style="display:none;"></div>
                ' : '') . '
            </div>
        </div>
    </div>
    ';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comments - <?= htmlspecialchars($ad['header']) ?></title>
    <style>
      * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    line-height: 1.6;
    min-height: 100vh;
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.back-btn {
    background: rgba(255, 255, 255, 0.95);
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

.back-btn:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    color: #764ba2;
    text-decoration: none;
}

.header h1 {
    font-size: 28px;
    font-weight: 800;
    background: linear-gradient(135deg, #ffffff, #e2e8f0);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.ad-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.ad-container:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.ad-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.ad-header img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    border: 3px solid rgba(102, 126, 234, 0.3);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.ad-user-info {
    flex: 1;
}

.ad-username {
    font-weight: 800;
    color: #2d3748;
    font-size: 18px;
    margin-bottom: 5px;
}

.ad-label {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    color: #000;
    padding: 6px 15px;
    border-radius: 20px;
    font-weight: 800;
    font-size: 12px;
    display: inline-block;
    margin-bottom: 8px;
    box-shadow: 0 2px 8px rgba(255, 215, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.ad-content {
    margin-bottom: 20px;
}

.ad-title {
    font-size: 24px;
    font-weight: 800;
    color: #2d3748;
    margin-bottom: 15px;
    line-height: 1.3;
}

.ad-description {
    color: #4a5568;
    line-height: 1.6;
    margin-bottom: 20px;
    font-size: 16px;
}

.ad-media {
    margin: 20px 0;
    text-align: center;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.ad-media img, .ad-media video {
    max-width: 100%;
    max-height: 400px;
    border-radius: 15px;
    display: block;
}

.ad-actions {
    display: flex;
    gap: 25px;
    padding-top: 20px;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
}

.action-btn {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 0, 0, 0.1);
    color: #4a5568;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.action-btn:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    color: #667eea;
}

.action-btn.liked {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

.action-btn.liked:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
}

.comments-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.comments-title {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 25px;
    color: #2d3748;
    text-align: center;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.comment-form {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    align-items: flex-start;
}

.comment-user-img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(102, 126, 234, 0.3);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    flex-shrink: 0;
}

.comment-input-container {
    flex: 1;
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.comment-input {
    flex: 1;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 25px;
    padding: 15px 20px;
    color: #2d3748;
    font-size: 15px;
    resize: none;
    min-height: 50px;
    max-height: 120px;
    font-family: inherit;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.comment-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    background: white;
}

.comment-input::placeholder {
    color: #a0aec0;
}

.comment-submit {
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
    white-space: nowrap;
    flex-shrink: 0;
}

.comment-submit:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.comment-submit:disabled {
    background: #a0aec0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.comments-list {
    max-height: 500px;
    overflow-y: auto;
    padding-right: 10px;
}

.comment {
    display: flex;
    gap: 15px;
    padding: 20px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.comment:hover {
    background: rgba(102, 126, 234, 0.03);
    border-radius: 12px;
    padding: 20px 15px;
    margin: 0 -15px;
}

.comment:last-child {
    border-bottom: none;
}

.comment-content {
    flex: 1;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.comment-username {
    font-weight: 700;
    color: #2d3748;
    font-size: 15px;
}

.comment-time {
    color: #718096;
    font-size: 12px;
    font-weight: 600;
}

.comment-text {
    color: #4a5568;
    line-height: 1.5;
    word-wrap: break-word;
    font-size: 14px;
}

.comment-actions {
    display: flex;
    gap: 15px;
    margin-top: 10px;
}

.comment-action {
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

.comment-action:hover {
    color: #667eea;
    background: rgba(102, 126, 234, 0.1);
}

.comment-action.delete {
    color: #e53e3e;
}

.comment-action.delete:hover {
    color: #c53030;
    background: rgba(229, 62, 62, 0.1);
}

.no-comments {
    text-align: center;
    color: #718096;
    padding: 50px 0;
    font-style: italic;
    font-size: 16px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 15px;
    margin: 20px 0;
}

.error-message {
    background: rgba(229, 62, 62, 0.1);
    color: #e53e3e;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
    border: 1px solid rgba(229, 62, 62, 0.2);
    backdrop-filter: blur(10px);
}

.success-message {
    background: rgba(72, 187, 120, 0.1);
    color: #38a169;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
    border: 1px solid rgba(72, 187, 120, 0.2);
    backdrop-filter: blur(10px);
}

.cta-button {
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
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.cta-button:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    color: white;
    text-decoration: none;
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

.container > * {
    animation: fadeInUp 0.6s ease-out;
}

.container > *:nth-child(1) { animation-delay: 0.1s; }
.container > *:nth-child(2) { animation-delay: 0.2s; }
.container > *:nth-child(3) { animation-delay: 0.3s; }
.container > *:nth-child(4) { animation-delay: 0.4s; }

.comment {
    animation: fadeInUp 0.4s ease-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .header h1 {
        font-size: 24px;
    }
    
    .ad-header {
        flex-direction: column;
        text-align: center;
    }
    
    .ad-header img {
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .ad-container, .comments-section {
        padding: 20px;
    }
    
    .comment-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .comment-user-img {
        align-self: center;
        margin-bottom: 10px;
    }
    
    .comment-input-container {
        flex-direction: column;
    }
    
    .comment-submit {
        align-self: flex-end;
        margin-top: 10px;
    }
    
    .ad-media img, .ad-media video {
        max-height: 300px;
    }
    
    .ad-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .action-btn {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .ad-container, .comments-section {
        padding: 15px;
    }
    
    .ad-title {
        font-size: 20px;
    }
    
    .comments-title {
        font-size: 20px;
    }
    
    .comment {
        padding: 15px 0;
    }
    
    .comment:hover {
        padding: 15px 10px;
        margin: 0 -10px;
    }
    
    .cta-button {
        padding: 10px 20px;
        font-size: 14px;
    }
}

/* Custom scrollbar */
.comments-list::-webkit-scrollbar {
    width: 6px;
}

.comments-list::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
}

.comments-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 3px;
}

.comments-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
}

/* Loading state for buttons */
.action-btn:active, .comment-submit:active {
    transform: scale(0.95);
    transition: transform 0.1s ease;
}

/* Focus styles for accessibility */
.action-btn:focus, .comment-submit:focus, .cta-button:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

/* Text selection */
::selection {
    background: rgba(102, 126, 234, 0.3);
    color: #2d3748;
}

/* New styles for reply system */
.show-replies-btn {
    color: #667eea;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 0;
    margin-top: 5px;
}

.show-replies-btn:hover {
    text-decoration: underline;
}

.reply-form-container {
    margin-top: 10px;
}

.reply-input-container {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

.reply-input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
}

.reply-submit-btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}

.reply-cancel-btn {
    background: #718096;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}

/* Edit comment styles */
.edit-comment-form {
    margin-top: 10px;
}

.edit-comment-input {
    width: 100%;
    padding: 10px;
    border: 1px solid #667eea;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 10px;
}

.edit-comment-buttons {
    display: flex;
    gap: 10px;
}

.edit-save-btn {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}

.edit-cancel-btn {
    background: #718096;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="home.php" class="back-btn">← Back to Feed</a>
            <h1>Advertisement Comments</h1>
        </div>

        <!-- Advertisement Display -->
        <div class="ad-container">
            <div class="ad-header">
                <img src="<?= htmlspecialchars($ad['profile_pic_url'] ?: 'default_profile.png') ?>" 
                     alt="<?= htmlspecialchars($ad['username']) ?>">
                <div class="ad-user-info">
                    <div class="ad-label">Sponsored</div>
                    <div class="ad-username"><?= htmlspecialchars($ad['username']) ?></div>
                </div>
            </div>
            
            <div class="ad-content">
                <div class="ad-title"><?= htmlspecialchars($ad['header']) ?></div>
                <div class="ad-description"><?= nl2br(htmlspecialchars($ad['description'])) ?></div>
                
                <?php if (!empty($ad['media_path'])): ?>
                    <div class="ad-media">
                        <?php if ($ad['ad_type'] === 'video'): ?>
                            <video controls style="width: 100%; max-height: 400px; border-radius: 8px;">
                                <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars($ad['media_path']) ?>" 
                                 alt="Ad Image" 
                                 style="max-width: 100%; max-height: 400px; border-radius: 8px;">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($ad['url'])): ?>
                    <a href="<?= htmlspecialchars($ad['url']) ?>" target="_blank" class="cta-button">
                        <?= htmlspecialchars($ad['cta_button'] ?: 'Learn More') ?>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="ad-actions">
                <button class="action-btn <?= $userLiked ? 'liked' : '' ?>" 
                        onclick="likeAd(<?= $adId ?>)">
                    👍 Like (<span id="likeCount"><?= $likeCount ?></span>)
                </button>
                <button class="action-btn" onclick="scrollToComments()">
                    💬 Comment (<span id="commentCount"><?= $totalCommentCount ?></span>)
                </button>
                <button class="action-btn" onclick="shareAd(<?= $adId ?>)">
                    🔄 Share (<span id="shareCount"><?= $shareCount ?></span>)
                </button>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section" id="commentsSection">
            <h2 class="comments-title">Comments (<?= $totalCommentCount ?>)</h2>
            
            <!-- Comment Form -->
            <form class="comment-form" onsubmit="submitAdComment(event, <?= $adId ?>, null)">
                <img src="<?= htmlspecialchars($userProfilePic) ?>" 
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
            <div class="comments-list" id="commentsList">
                <?php if (empty($comments)): ?>
                    <div class="no-comments">
                        No comments yet. Be the first to comment!
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <?= generateAdCommentHTML($comment, $currentUserId, $ad['advertiser_id']) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ========== HELPER FUNCTIONS ==========
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function timeAgo(dateString) {
            if (!dateString) return 'Recently';
            
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return 'Recently';
            
            const now = new Date();
            const diff = now - date;
            
            if (diff < 60000) {
                return 'just now';
            } else if (diff < 3600000) {
                const mins = Math.floor(diff / 60000);
                return `${mins} min${mins > 1 ? 's' : ''} ago`;
            } else if (diff < 86400000) {
                const hours = Math.floor(diff / 3600000);
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else if (diff < 604800000) {
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

        function generateCommentHTML(comment, currentUserId, advertiserId, isReply = false) {
            if (!comment) return '';
            
            const canDelete = comment.user_id == currentUserId || advertiserId == currentUserId;
            const commentTime = timeAgo(comment.created_at);
            const replyCount = comment.reply_count || 0;
            
            return `
            <div class="comment" id="comment-${comment.id}" style="${isReply ? 'margin-left: 60px; background: rgba(255,255,255,0.7); padding: 15px; border-radius: 8px; margin-top: 10px;' : ''}">
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
                                onclick="showReplyForm(${comment.id})">
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
                    <div id="reply-form-${comment.id}" style="display:none; margin-top:10px;">
                        <form onsubmit="submitAdComment(event, ${comment.ad_id}, ${comment.id})">
                            <textarea name="content" 
                                      class="comment-input" 
                                      placeholder="Write your reply..." 
                                      required
                                      style="width:100%; padding:10px; border-radius:8px; border:1px solid #667eea;"></textarea>
                            <div style="margin-top:5px;">
                                <button type="submit" class="comment-submit" style="padding:5px 15px; font-size:12px;">Post Reply</button>
                                <button type="button" 
                                        onclick="hideReplyForm(${comment.id})"
                                        style="background:#718096; color:white; border:none; padding:5px 15px; border-radius:5px; margin-left:10px; font-size:12px;">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Replies Section -->
                    <div id="replies-container-${comment.id}">
                        ${replyCount > 0 ? `
                            <div id="replies-toggle-${comment.id}">
                                <button onclick="loadReplies(${comment.id})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
                                    ▼ Show ${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}
                                </button>
                            </div>
                            <div id="replies-list-${comment.id}" style="display:none;"></div>
                        ` : ''}
                    </div>
                </div>
            </div>
            `;
        }

        // ========== COMMENT FUNCTIONS ==========
        async function submitAdComment(event, adId, parentCommentId = null) {
            event.preventDefault();
            
            const form = event.target;
            const content = form.querySelector('textarea[name="content"]').value.trim();
            
            if (!content) {
                alert('Comment cannot be empty.');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_comment');
            formData.append('content', content);
            formData.append('ad_id', adId);
            if (parentCommentId) {
                formData.append('parent_comment_id', parentCommentId);
            }
            
            try {
                const response = await fetch('ads_comment.php?ad_id=<?= $adId ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // If it's a reply and we're showing replies, refresh the replies
                    if (parentCommentId && data.is_reply) {
                        // Refresh the replies section
                        loadReplies(parentCommentId);
                        // Hide the reply form
                        hideReplyForm(parentCommentId);
                    } else {
                        // Refresh all comments for main comment
                        location.reload();
                    }
                    
                    // Clear the input
                    form.querySelector('textarea[name="content"]').value = '';
                    
                    // Update comment count
                    const commentCountEl = document.getElementById('commentCount');
                    if (commentCountEl) {
                        const current = parseInt(commentCountEl.textContent) || 0;
                        commentCountEl.textContent = current + 1;
                    }
                } else {
                    alert(data.error || 'Error adding comment.');
                }
            } catch (error) {
                console.error('Error adding comment:', error);
                alert('Network error. Please try again.');
            }
        }

        function showReplyForm(commentId) {
            const replyForm = document.getElementById(`reply-form-${commentId}`);
            if (replyForm) {
                replyForm.style.display = 'block';
                replyForm.querySelector('textarea').focus();
            }
        }

        function hideReplyForm(commentId) {
            const replyForm = document.getElementById(`reply-form-${commentId}`);
            if (replyForm) {
                replyForm.style.display = 'none';
                replyForm.querySelector('textarea').value = '';
            }
        }

        async function loadReplies(commentId) {
            const repliesList = document.getElementById(`replies-list-${commentId}`);
            const toggleBtn = document.getElementById(`replies-toggle-${commentId}`);
            
            if (!repliesList || !toggleBtn) return;
            
            // If already loaded, just toggle visibility
            if (repliesList.innerHTML && repliesList.innerHTML.trim() !== '') {
                if (repliesList.style.display === 'none') {
                    repliesList.style.display = 'block';
                    toggleBtn.innerHTML = `<button onclick="loadReplies(${commentId})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
                        ▲ Hide replies
                    </button>`;
                } else {
                    repliesList.style.display = 'none';
                    toggleBtn.innerHTML = `<button onclick="loadReplies(${commentId})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
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
                formData.append('action', 'get_replies');
                formData.append('comment_id', commentId);
                
                const response = await fetch('ads_comment.php?ad_id=<?= $adId ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success && data.replies && data.replies.length > 0) {
                    let repliesHTML = '';
                    data.replies.forEach(reply => {
                        repliesHTML += generateCommentHTML(reply, data.currentUserId, <?= $ad['advertiser_id'] ?>, true);
                    });
                    repliesList.innerHTML = repliesHTML;
                    
                    // Update toggle button
                    toggleBtn.innerHTML = `<button onclick="loadReplies(${commentId})" class="show-replies-btn" style="color:#667eea; background:none; border:none; cursor:pointer; padding:5px 0; margin-top:5px;">
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

        async function deleteAdComment(commentId) {
            if (!confirm('Are you sure you want to delete this comment?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_comment');
            formData.append('comment_id', commentId);
            
            try {
                const response = await fetch('ads_comment.php?ad_id=<?= $adId ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Remove the comment from the DOM
                    const commentElement = document.getElementById(`comment-${commentId}`);
                    if (commentElement) {
                        commentElement.remove();
                    }
                    
                    // Update comment count
                    const commentCountEl = document.getElementById('commentCount');
                    const commentsTitle = document.querySelector('.comments-title');
                    
                    if (commentCountEl) {
                        const current = parseInt(commentCountEl.textContent) || 0;
                        commentCountEl.textContent = Math.max(0, current - 1);
                    }
                    
                    if (commentsTitle) {
                        const currentText = commentsTitle.textContent;
                        const match = currentText.match(/Comments \((\d+)\)/);
                        if (match) {
                            const currentCount = parseInt(match[1]);
                            commentsTitle.textContent = `Comments (${Math.max(0, currentCount - 1)})`;
                        }
                    }
                } else {
                    alert(data.error || 'Error deleting comment');
                }
            } catch (error) {
                console.error('Error deleting comment:', error);
                alert('Network error. Please try again.');
            }
        }

        // ========== LIKE/SHARE FUNCTIONS ==========
        async function likeAd(adId) {
            const likeBtn = document.querySelector('.action-btn');
            const isLiked = likeBtn.classList.contains('liked');
            const action = isLiked ? 'unlike' : 'like';
            
            // Optimistic UI update
            const countSpan = document.getElementById('likeCount');
            const currentCount = parseInt(countSpan.textContent) || 0;
            
            if (isLiked) {
                likeBtn.classList.remove('liked');
                countSpan.textContent = Math.max(0, currentCount - 1);
            } else {
                likeBtn.classList.add('liked');
                countSpan.textContent = currentCount + 1;
            }
            
            // Send request
            const formData = new FormData();
            formData.append('action', action);
            formData.append('ad_id', adId);
            
            try {
                const response = await fetch('people.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    // Revert optimistic update on error
                    if (isLiked) {
                        likeBtn.classList.add('liked');
                        countSpan.textContent = currentCount;
                    } else {
                        likeBtn.classList.remove('liked');
                        countSpan.textContent = currentCount;
                    }
                }
            } catch (error) {
                console.error('Error toggling like:', error);
                // Revert optimistic update on error
                if (isLiked) {
                    likeBtn.classList.add('liked');
                    countSpan.textContent = currentCount;
                } else {
                    likeBtn.classList.remove('liked');
                    countSpan.textContent = currentCount;
                }
            }
        }

        async function shareAd(adId) {
            const adUrl = `${window.location.origin}/ads_comment.php?ad_id=${adId}`;
            
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
                    
                    await fetch('people.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    // Update share count
                    const shareCountEl = document.getElementById('shareCount');
                    if (shareCountEl) {
                        const current = parseInt(shareCountEl.textContent) || 0;
                        shareCountEl.textContent = current + 1;
                    }
                    
                } catch (err) {
                    console.log('Error sharing:', err);
                    copyToClipboard(adUrl, adId);
                }
            } else {
                copyToClipboard(adUrl, adId);
            }
        }

        async function copyToClipboard(text, adId) {
            try {
                await navigator.clipboard.writeText(text);
                alert('Advertisement link copied to clipboard!');
                
                // Record the share
                const formData = new FormData();
                formData.append('action', 'share_ad');
                formData.append('ad_id', adId);
                
                await fetch('people.php', {
                    method: 'POST',
                    body: formData
                });
                
                // Update share count
                const shareCountEl = document.getElementById('shareCount');
                if (shareCountEl) {
                    const current = parseInt(shareCountEl.textContent) || 0;
                    shareCountEl.textContent = current + 1;
                }
                
            } catch (err) {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Advertisement link copied to clipboard!');
                
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

        // ========== UTILITY FUNCTIONS ==========
        // Auto-resize textarea
        document.querySelector('.comment-input').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Scroll to comments section
        function scrollToComments() {
            document.getElementById('commentsSection').scrollIntoView({ 
                behavior: 'smooth' 
            });
            document.querySelector('.comment-input').focus();
        }

        // Focus comment input when page loads if there's a hash
        window.addEventListener('load', function() {
            if (window.location.hash === '#comment') {
                document.querySelector('.comment-input').focus();
            }
            
            // Auto-resize all comment textareas
            document.querySelectorAll('.comment-input').forEach(textarea => {
                textarea.style.height = 'auto';
                textarea.style.height = (textarea.scrollHeight) + 'px';
            });
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter to submit main comment
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const activeElement = document.activeElement;
                if (activeElement && activeElement.classList.contains('comment-input')) {
                    const form = activeElement.closest('form');
                    if (form) {
                        e.preventDefault();
                        form.dispatchEvent(new Event('submit'));
                    }
                }
            }
            
            // Escape to close reply forms
            if (e.key === 'Escape') {
                document.querySelectorAll('[id^="reply-form-"]').forEach(form => {
                    if (form.style.display === 'block') {
                        const commentId = form.id.replace('reply-form-', '');
                        hideReplyForm(commentId);
                    }
                });
            }
        });
    </script>
</body>
</html>