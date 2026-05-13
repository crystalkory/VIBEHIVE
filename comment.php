<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

// Fetch post id from query
$postId = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT);
if (!$postId) {
    die("Invalid post.");
}

// ========== AJAX HANDLERS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // --- ADD COMMENT (WITH REPLIES) ---
    if ($_POST['action'] === 'add_comment') {
        $commentText = trim($_POST['comment'] ?? '');
        $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
        
        if ($commentText === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }

        // Check if post exists and get post owner
        $checkStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
        $checkStmt->execute([$postId]);
        $post = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$post) {
            echo json_encode(['success' => false, 'error' => 'Post not found']);
            exit;
        }

        // Insert comment with parent_comment_id support
        $insertStmt = $pdo->prepare("
            INSERT INTO comments (post_id, user_id, content, parent_comment_id, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([
            $postId,
            $userId,
            $commentText,
            $parentCommentId
        ]);
        $newCommentId = $pdo->lastInsertId();

        // Create notification for post owner if not commenting on own post
        if ($post['user_id'] != $userId) {
            $message = $parentCommentId ? "replied to your comment" : "commented on your post";
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, source_user_id, post_id, message, created_at) 
                VALUES (?, 'comment', ?, ?, ?, NOW())
            ");
            $stmt->execute([$post['user_id'], $userId, $postId, $message]);
        }

        // If this is a reply, also notify the parent comment author if different
        if ($parentCommentId && $parentCommentId > 0) {
            $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
            $stmt->execute([$parentCommentId]);
            $parentCommentUserId = $stmt->fetchColumn();
            
            if ($parentCommentUserId && $parentCommentUserId != $userId && $parentCommentUserId != $post['user_id']) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, source_user_id, post_id, message, created_at) 
                    VALUES (?, 'comment', ?, ?, ?, NOW())
                ");
                $message = "replied to your comment";
                $stmt->execute([$parentCommentUserId, $userId, $postId, $message]);
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

    // --- DELETE COMMENT ---
    if ($_POST['action'] === 'delete_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        
        // Verify ownership before deletion (comment owner OR post owner)
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
        if ($commentData['user_id'] == $userId || $commentData['post_owner_id'] == $userId) {
            $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? OR parent_comment_id = ?");
            $stmt->execute([$commentId, $commentId]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'You don\'t have permission to delete this comment']);
        }
        exit;
    }

    // --- EDIT COMMENT ---
    if ($_POST['action'] === 'edit_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $newContent = trim($_POST['content'] ?? '');
        
        if ($newContent === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }
        
        // update only if comment belongs to current user
        $updateStmt = $pdo->prepare("
            UPDATE comments 
            SET content = ?, updated_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $updateStmt->execute([$newContent, $commentId, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- GET COMMENT REPLIES ---
    if ($_POST['action'] === 'get_replies') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        
        try {
            // Fetch replies for this comment
            $repliesStmt = $pdo->prepare("
                SELECT c.*, u.username, u.profile_pic_url
                FROM comments c
                JOIN users u ON c.user_id = u.id
                WHERE c.parent_comment_id = ?
                ORDER BY c.created_at ASC
            ");
            $repliesStmt->execute([$commentId]);
            $replies = $repliesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'replies' => $replies,
                'currentUserId' => $userId
            ]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Get replies error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }
}

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
    die("Post not found.");
}

// Fetch main comments (where parent_comment_id is NULL)
function fetchMainComments($pdo, $postId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.content, c.user_id, c.created_at, c.updated_at, 
               u.username, u.profile_pic_url, c.parent_comment_id,
               (SELECT COUNT(*) FROM comments r WHERE r.parent_comment_id = c.id) as reply_count
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = :post_id AND c.parent_comment_id IS NULL
        ORDER BY c.created_at ASC
    ");
    $stmt->execute(['post_id' => $postId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$comments = fetchMainComments($pdo, $postId);

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

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Comments on Post</title>
<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; 
    max-width: 700px; 
    margin: 20px auto; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    color: #333;
    padding: 20px;
}
header { 
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #2d3748; 
    padding: 20px; 
    margin-bottom: 20px;
    margin-top: 20px;
    border-radius: 15px;
    border: 2px solid #7b68ee;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}
.post-header, .comment {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 12px; 
    padding: 15px 20px; 
    margin-bottom: 15px;
    border: 2px solid #7b68ee;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}
.post-header:hover, .comment:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.2);
}
.post-header img, .comment img { 
    width: 40px; 
    height: 40px; 
    border-radius: 50%; 
    object-fit: cover; 
    vertical-align: middle; 
    border: 2px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
}
.post-header .username, .comment .username { 
    font-weight: bold; 
    cursor: pointer; 
    color: #7b68ee; 
    margin-left: 10px; 
    vertical-align: middle;
    font-weight: 700;
    transition: color 0.3s ease;
}
.post-header .username:hover, .comment .username:hover { 
    color: #6a5acd;
}
.comment .comment-meta { 
    font-size: 12px; 
    color: #718096; 
    margin-left: 50px; 
    margin-top: -10px; 
    margin-bottom: 6px; 
    font-weight: 500;
}
.comment-content { 
    margin-left: 50px; 
    color: #2d3748;
    line-height: 1.4;
}
.reply-comment {
    margin-left: 60px;
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(10px);
}
.reply-btn {
    color: #7b68ee;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    margin-left: 10px;
}
.reply-btn:hover {
    text-decoration: underline;
}
.show-replies-btn {
    color: #7b68ee;
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
textarea, input[type=text] { 
    width: 100%; 
    padding: 12px 15px; 
    margin-bottom: 15px; 
    resize: vertical; 
    border: 2px solid #7b68ee;
    border-radius: 8px;
    background: white;
    font-size: 14px;
    transition: all 0.3s ease;
    font-family: inherit;
}
textarea:focus, input[type=text]:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}
button { 
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white; 
    border: none; 
    border-radius: 8px; 
    padding: 12px 20px; 
    cursor: pointer; 
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}
button:hover { 
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}
.edit-area { 
    width: 100%; 
    height: 80px; 
    margin-top: 10px;
}
.small-button { 
    font-size: 12px; 
    background: linear-gradient(135deg, #a0aec0, #718096);
    margin-left: 10px; 
    padding: 6px 12px;
}
.small-button:hover { 
    background: linear-gradient(135deg, #718096, #4a5568);
}

/* Section styling */
#comments-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    border: 2px solid #7b68ee;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    margin-bottom: 20px;
}

#comments-section h3 {
    color: #7b68ee;
    margin-bottom: 20px;
    font-weight: 700;
    border-bottom: 2px solid rgba(123, 104, 238, 0.3);
    padding-bottom: 10px;
}

/* Post media styling */
.post-header img[style*="max-width:100%"] {
    border-radius: 10px;
    border: 2px solid #7b68ee;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.post-header video {
    border-radius: 10px;
    border: 2px solid #7b68ee;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

/* Message styling */
#addCommentMessage {
    color: #ff6b6b;
    margin-top: 10px;
    font-weight: 600;
    padding: 8px 12px;
    background: rgba(255, 107, 107, 0.1);
    border-radius: 6px;
    border: 1px solid #ff6b6b;
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

.post-header, .comment, #comments-section {
    animation: fadeInUp 0.6s ease-out;
}

/* Edit controls specific styling */
.edit-controls button {
    margin-right: 10px;
    padding: 8px 16px;
    font-size: 14px;
}

.edit-controls button:last-child {
    background: linear-gradient(135deg, #a0aec0, #718096);
}

.edit-controls button:last-child:hover {
    background: linear-gradient(135deg, #718096, #4a5568);
}

/* Header link styling */
header h2 span {
    color: #7b68ee;
    transition: color 0.3s ease;
}

header h2 span:hover {
    color: #6a5acd;
}

/* Like button styling */
.like-btn {
    background: linear-gradient(135deg, #ff6b6b, #ff4757);
    margin-right: 10px;
}

.like-btn.liked {
    background: linear-gradient(135deg, #ff4757, #ff3838);
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 10px auto;
    }
    
    .post-header, .comment {
        padding: 12px 15px;
    }
    
    .comment-content, .reply-comment {
        margin-left: 0;
        margin-top: 10px;
    }
    
    .comment .comment-meta {
        margin-left: 0;
    }
    
    #comments-section {
        padding: 20px;
    }
    
    header {
        padding: 15px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    .post-header img, .comment img {
        width: 35px;
        height: 35px;
    }
    
    button {
        padding: 10px 16px;
        font-size: 14px;
    }
    
    .small-button {
        font-size: 11px;
        padding: 5px 10px;
    }
    
    #comments-section {
        padding: 15px;
    }
    
    header h2 {
        font-size: 18px;
    }
}

/* Scrollbar styling for comments list */
#comments-list {
    max-height: 500px;
    overflow-y: auto;
}

#comments-list::-webkit-scrollbar {
    width: 6px;
}

#comments-list::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 3px;
}

#comments-list::-webkit-scrollbar-thumb {
    background: #7b68ee;
    border-radius: 3px;
}

#comments-list::-webkit-scrollbar-thumb:hover {
    background: #6a5acd;
}

/* Post actions */
.post-actions {
    display: flex;
    gap: 15px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
}

.post-action-btn {
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
}

.post-action-btn:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
}

.post-action-btn.liked {
    color: #ff004f;
    font-weight: bold;
}

.post-action-btn .icon {
    font-size: 16px;
}

.post-action-btn .count {
    font-size: 12px;
    color: #718096;
}
</style>
</head>
<body>

<header>
<h2>Post by <span onclick="window.location='profile.php?id=<?= $post['author_id'] ?>'" style="cursor:pointer;"><?= htmlspecialchars($post['username']) ?></span></h2>
<div class="post-header">
    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile" />
    <span class="username" onclick="window.location='profile.php?id=<?= $post['author_id'] ?>'"><?= htmlspecialchars($post['username']) ?></span>
    <p><?= nl2br(htmlspecialchars($post['content'])) ?></p>
    <?php if ($post['post_type'] === 'photo' || $post['post_type'] === 'video'): 
        $mediaArray = explode(',', $post['media_url']);
        foreach ($mediaArray as $media):
            if ($post['post_type'] === 'photo'): ?>
                <img src="<?= htmlspecialchars($media) ?>" alt="Post Image" style="max-width:100%; margin-top:10px; border-radius:8px;" />
            <?php else: ?>
                <video controls style="max-width:100%; margin-top:10px; border-radius:8px;">
                    <source src="<?= htmlspecialchars($media) ?>" type="video/mp4" />
                    Your browser does not support the video tag.
                </video>
            <?php endif;
        endforeach;
    elseif ($post['post_type'] === 'link' && !empty($post['media_url'])): ?>
        <p><a href="<?= htmlspecialchars($post['media_url']) ?>" target="_blank"><?= htmlspecialchars($post['media_url']) ?></a></p>
    <?php endif; ?>
    
    <!-- Post Actions -->
    <div class="post-actions">
        <button class="post-action-btn like-btn <?= $userLiked ? 'liked' : '' ?>" onclick="toggleLike(<?= $postId ?>)">
            <span class="icon">❤️</span>
            Like <span class="count">(<?= $likeCount ?>)</span>
        </button>
    </div>
</div>
</header>

<section id="comments-section">
<h3>Comments (<?= $totalCommentCount ?>)</h3>

<div id="comments-list">
<?php foreach ($comments as $comment): ?>
    <?php 
    $isCurrentUser = $comment['user_id'] == $userId;
    $commentTime = date('Y-m-d H:i', strtotime($comment['created_at']));
    $replyCount = $comment['reply_count'] || 0;
    ?>
    <div class="comment" data-comment-id="<?= $comment['id'] ?>">
        <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" alt="User" />
        <span class="username" onclick="window.location='profile.php?id=<?= $comment['user_id'] ?>'"><?= htmlspecialchars($comment['username']) ?></span>
        <div class="comment-meta">
            <?= $commentTime ?>
            <?php if ($isCurrentUser): ?>
                <button class="small-button" onclick="editComment(<?= $comment['id'] ?>)">Edit</button>
                <button class="small-button" onclick="deleteComment(<?= $comment['id'] ?>, <?= $postId ?>)">Delete</button>
            <?php endif; ?>
            <button class="small-button reply-btn" onclick="showReplyForm(<?= $comment['id'] ?>)">Reply</button>
        </div>
        <div class="comment-content" id="comment-content-<?= $comment['id'] ?>"><?= nl2br(htmlspecialchars($comment['content'])) ?></div>
        <textarea class="edit-area" id="edit-area-<?= $comment['id'] ?>" style="display:none;"></textarea>
        <div id="edit-controls-<?= $comment['id'] ?>" style="display:none; margin-left:50px; margin-bottom:10px;" class="edit-controls">
            <button onclick="saveComment(<?= $comment['id'] ?>)">Save</button>
            <button onclick="cancelEdit(<?= $comment['id'] ?>)">Cancel</button>
        </div>
        
        <!-- Reply Form (Hidden by Default) -->
        <div id="reply-form-<?= $comment['id'] ?>" style="display:none; margin-top:10px; margin-left:50px;">
            <textarea id="reply-text-<?= $comment['id'] ?>" placeholder="Write your reply..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #7b68ee;"></textarea>
            <div style="margin-top:5px;">
                <button onclick="addComment(<?= $postId ?>, <?= $comment['id'] ?>)" style="background:#7b68ee; color:white; border:none; padding:5px 15px; border-radius:5px;">Post Reply</button>
                <button onclick="hideReplyForm(<?= $comment['id'] ?>)" style="background:#718096; color:white; border:none; padding:5px 15px; border-radius:5px; margin-left:10px;">Cancel</button>
            </div>
        </div>
        
        <!-- Replies Section -->
        <div id="replies-container-<?= $comment['id'] ?>">
            <?php if ($replyCount > 0): ?>
                <div id="replies-toggle-<?= $comment['id'] ?>">
                    <button onclick="loadReplies(<?= $comment['id'] ?>)" class="show-replies-btn">
                        ▼ Show <?= $replyCount ?> <?= $replyCount === 1 ? 'reply' : 'replies' ?>
                    </button>
                </div>
                <div id="replies-list-<?= $comment['id'] ?>" style="display:none;"></div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<h3>Add a comment</h3>
<textarea id="newCommentText" placeholder="Write your comment here..."></textarea>
<button onclick="addComment(<?= $postId ?>, null)">Post Comment</button>
<div id="addCommentMessage" style="color:red; margin-top:5px;"></div>
</section>

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

// ========== COMMENT FUNCTIONS ==========
async function addComment(postId, parentCommentId = null) {
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
    formData.append('action', 'add_comment');
    formData.append('comment', text);
    formData.append('post_id', postId);
    if (parentCommentId) {
        formData.append('parent_comment_id', parentCommentId);
    }
    
    try {
        const response = await fetch('comment.php?post_id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // If it's a reply and we're showing replies, add it to the replies list
            if (parentCommentId && data.is_reply) {
                const repliesList = document.getElementById(`replies-list-${parentCommentId}`);
                if (repliesList && repliesList.style.display !== 'none') {
                    // Add the new reply to the replies list
                    const replyHTML = generateCommentHTML(data.comment, <?= $userId ?>, true);
                    repliesList.insertAdjacentHTML('beforeend', replyHTML);
                }
                
                // Update reply count
                const toggleBtn = document.getElementById(`replies-toggle-${parentCommentId}`);
                if (toggleBtn) {
                    const button = toggleBtn.querySelector('button');
                    const currentText = button.textContent;
                    const match = currentText.match(/Show (\d+) (?:reply|replies)/);
                    if (match) {
                        const currentCount = parseInt(match[1]);
                        button.textContent = `▼ Show ${currentCount + 1} ${currentCount + 1 === 1 ? 'reply' : 'replies'}`;
                    }
                }
                
                // Hide the reply form
                hideReplyForm(parentCommentId);
            } else {
                // For main comment, reload the page to show new comment
                location.reload();
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

function generateCommentHTML(comment, currentUserId, isReply = false) {
    if (!comment) return '';
    
    const isCurrentUser = comment.user_id == currentUserId;
    const commentTime = comment.created_at ? timeAgo(comment.created_at) : 'Recently';
    
    return `
    <div class="comment ${isReply ? 'reply-comment' : ''}" data-comment-id="${comment.id}">
        <img src="${escapeHtml(comment.profile_pic_url || 'default_profile.png')}" alt="User" />
        <span class="username" onclick="window.location='profile.php?id=${comment.user_id}'">${escapeHtml(comment.username)}</span>
        <div class="comment-meta">
            ${commentTime}
            ${isCurrentUser ? `
                <button class="small-button" onclick="editComment(${comment.id})">Edit</button>
                <button class="small-button" onclick="deleteComment(${comment.id}, <?= $postId ?>)">Delete</button>
            ` : ''}
            <button class="small-button reply-btn" onclick="showReplyForm(${comment.id})">Reply</button>
        </div>
        <div class="comment-content" id="comment-content-${comment.id}">${escapeHtml(comment.content || '').replace(/\n/g, '<br>')}</div>
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

async function loadReplies(commentId) {
    const repliesList = document.getElementById(`replies-list-${commentId}`);
    const toggleBtn = document.getElementById(`replies-toggle-${commentId}`);
    
    if (!repliesList || !toggleBtn) return;
    
    // If already loaded, just toggle visibility
    if (repliesList.innerHTML && repliesList.innerHTML.trim() !== '') {
        if (repliesList.style.display === 'none') {
            repliesList.style.display = 'block';
            toggleBtn.innerHTML = `<button onclick="loadReplies(${commentId})" class="show-replies-btn">
                ▲ Hide replies
            </button>`;
        } else {
            repliesList.style.display = 'none';
            toggleBtn.innerHTML = `<button onclick="loadReplies(${commentId})" class="show-replies-btn">
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
        
        const response = await fetch('comment.php?post_id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.replies && data.replies.length > 0) {
            let repliesHTML = '';
            data.replies.forEach(reply => {
                repliesHTML += generateCommentHTML(reply, data.currentUserId, true);
            });
            repliesList.innerHTML = repliesHTML;
            
            // Update toggle button
            toggleBtn.innerHTML = `<button onclick="loadReplies(${commentId})" class="show-replies-btn">
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

async function deleteComment(commentId, postId) {
    if (!confirm('Are you sure you want to delete this comment?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_comment');
    formData.append('comment_id', commentId);
    
    try {
        const response = await fetch('comment.php?post_id=<?= $postId ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Remove the comment from the DOM
            const commentElement = document.querySelector(`.comment[data-comment-id="${commentId}"]`);
            if (commentElement) {
                commentElement.remove();
            }
            
            // Update comment count
            const commentCountElement = document.querySelector('#comments-section h3');
            if (commentCountElement) {
                const currentText = commentCountElement.textContent;
                const match = currentText.match(/Comments \((\d+)\)/);
                if (match) {
                    const currentCount = parseInt(match[1]);
                    commentCountElement.textContent = `Comments (${Math.max(0, currentCount - 1)})`;
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

function editComment(commentId) {
    document.getElementById('comment-content-' + commentId).style.display = 'none';
    const editArea = document.getElementById('edit-area-' + commentId);
    editArea.style.display = 'block';
    editArea.value = document.getElementById('comment-content-' + commentId).textContent.trim();
    document.getElementById('edit-controls-' + commentId).style.display = 'block';
}

function cancelEdit(commentId) {
    document.getElementById('comment-content-' + commentId).style.display = 'block';
    document.getElementById('edit-area-' + commentId).style.display = 'none';
    document.getElementById('edit-controls-' + commentId).style.display = 'none';
}

async function saveComment(commentId) {
    const newText = document.getElementById('edit-area-' + commentId).value.trim();
    if (!newText) {
        alert('Comment cannot be empty.');
        return;
    }
    const formData = new FormData();
    formData.append('action', 'edit_comment');
    formData.append('comment_id', commentId);
    formData.append('content', newText);

    const response = await fetch('comment.php?post_id=<?= $postId ?>', {
        method: 'POST',
        body: formData
    });
    const data = await response.json();

    if (data.success) {
        document.getElementById('comment-content-' + commentId).textContent = newText;
        cancelEdit(commentId);
    } else {
        alert(data.error || 'Error updating comment.');
    }
}

// ========== LIKE FUNCTIONALITY ==========
async function toggleLike(postId) {
    const likeBtn = document.querySelector('.like-btn');
    const isLiked = likeBtn.classList.contains('liked');
    const action = isLiked ? 'unlike' : 'like';
    
    // Optimistic UI update
    const countSpan = likeBtn.querySelector('.count');
    const currentCount = parseInt(countSpan.textContent.replace(/[()]/g, '')) || 0;
    
    if (isLiked) {
        likeBtn.classList.remove('liked');
        countSpan.textContent = `(${Math.max(0, currentCount - 1)})`;
    } else {
        likeBtn.classList.add('liked');
        countSpan.textContent = `(${currentCount + 1})`;
    }
    
    // Send request
    const formData = new FormData();
    formData.append('action', action);
    formData.append('post_id', postId);
    
    try {
        await fetch('people.php', {
            method: 'POST',
            body: formData
        });
    } catch (error) {
        console.error('Error toggling like:', error);
        // Revert optimistic update on error
        if (isLiked) {
            likeBtn.classList.add('liked');
            countSpan.textContent = `(${currentCount})`;
        } else {
            likeBtn.classList.remove('liked');
            countSpan.textContent = `(${currentCount})`;
        }
    }
}

// ========== KEYBOARD SHORTCUTS ==========
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + Enter to submit new comment
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const activeElement = document.activeElement;
        if (activeElement && activeElement.id === 'newCommentText') {
            e.preventDefault();
            addComment(<?= $postId ?>, null);
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

// Focus on comment textarea when page loads
document.addEventListener('DOMContentLoaded', function() {
    const commentTextarea = document.getElementById('newCommentText');
    if (commentTextarea) {
        commentTextarea.focus();
    }
});
</script>

</body>
</html>