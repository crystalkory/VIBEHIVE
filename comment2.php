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

// Handle new comment submission (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'add_comment') {
        $commentText = trim($_POST['comment'] ?? '');
        if ($commentText === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }

        $insertStmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, created_at) VALUES (:post_id, :user_id, :content, NOW())");
        $insertStmt->execute([
            ':post_id' => $postId,
            ':user_id' => $userId,
            ':content' => $commentText
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'delete_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        // delete only if comment belongs to current user
        $delStmt = $pdo->prepare("DELETE FROM comments WHERE id = :id AND user_id = :user_id");
        $delStmt->execute([':id' => $commentId, ':user_id' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'edit_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $newContent = trim($_POST['content'] ?? '');
        if ($newContent === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }
        // update only if comment belongs to current user
        $updateStmt = $pdo->prepare("UPDATE comments SET content = :content, updated_at = NOW() WHERE id = :id AND user_id = :user_id");
        $updateStmt->execute([':content' => $newContent, ':id' => $commentId, ':user_id' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// Fetch post info
$postStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url 
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = :post_id
");
$postStmt->execute(['post_id' => $postId]);
$post = $postStmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    die("Post not found.");
}

// Fetch comments
function fetchComments($pdo, $postId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.content, c.user_id, c.created_at, c.updated_at, u.username, u.profile_pic_url
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = :post_id
        ORDER BY c.created_at ASC
    ");
    $stmt->execute(['post_id' => $postId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$comments = fetchComments($pdo, $postId);
 
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
.controls { 
    margin-left: 50px; 
    margin-top: 10px; 
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
#edit-controls-<?= $comment['id'] ?> button {
    margin-right: 10px;
    padding: 8px 16px;
    font-size: 14px;
}

#edit-controls-<?= $comment['id'] ?> button:last-child {
    background: linear-gradient(135deg, #a0aec0, #718096);
}

#edit-controls-<?= $comment['id'] ?> button:last-child:hover {
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

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 10px auto;
    }
    
    .post-header, .comment {
        padding: 12px 15px;
    }
    
    .comment-content {
        margin-left: 0;
        margin-top: 10px;
    }
    
    .comment .comment-meta {
        margin-left: 0;
    }
    
    .controls {
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
</style>
</head>
<body>

<header>
<h2>Post by <span onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" style="cursor:pointer;"><?= htmlspecialchars($post['username']) ?></span></h2>
<div class="post-header">
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
</div>
</header>

<section id="comments-section">
<h3>Comments (<?= count($comments) ?>)</h3>

<div id="comments-list">
<?php foreach ($comments as $comment): ?>
    <div class="comment" data-comment-id="<?= $comment['id'] ?>">
        <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" alt="User" />
        <span class="username" onclick="window.location='profile.php?id=<?= $comment['user_id'] ?>'"><?= htmlspecialchars($comment['username']) ?></span>
        <div class="comment-meta">
            <?= date('Y-m-d H:i', strtotime($comment['created_at'])) ?>
            <?php if ($comment['user_id'] == $userId): ?>
                <button class="small-button" onclick="editComment(<?= $comment['id'] ?>)">Edit</button>
                <button class="small-button" onclick="deleteComment(<?= $comment['id'] ?>)">Delete</button>
            <?php endif; ?>
        </div>
        <div class="comment-content" id="comment-content-<?= $comment['id'] ?>"><?= nl2br(htmlspecialchars($comment['content'])) ?></div>
        <textarea class="edit-area" id="edit-area-<?= $comment['id'] ?>" style="display:none;"></textarea>
        <div id="edit-controls-<?= $comment['id'] ?>" style="display:none; margin-left:50px; margin-bottom:10px;">
            <button onclick="saveComment(<?= $comment['id'] ?>)">Save</button>
            <button onclick="cancelEdit(<?= $comment['id'] ?>)">Cancel</button>
        </div>
    </div>
<?php endforeach; ?>
</div>

<h3>Add a comment</h3>
<textarea id="newCommentText" placeholder="Write your comment here..."></textarea>
<button onclick="addComment()">Post Comment</button>
<div id="addCommentMessage" style="color:red; margin-top:5px;"></div>
</section>

<script>
async function addComment() {
    const text = document.getElementById('newCommentText').value.trim();
    const msgDiv = document.getElementById('addCommentMessage');
    msgDiv.textContent = '';
    if (!text) {
        msgDiv.textContent = 'Comment cannot be empty.';
        return;
    }
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('comment', text);

    const response = await fetch('comment.php?post_id=<?= $postId ?>', {
        method: 'POST',
        body: formData
    });
    const data = await response.json();

    if (data.success) {
        location.reload(); // Reload to show new comment (can be enhanced to dynamically add)
    } else {
        msgDiv.textContent = data.error || 'Error adding comment.';
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

async function deleteComment(commentId) {
    if (!confirm('Are you sure you want to delete this comment?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_comment');
    formData.append('comment_id', commentId);

    const response = await fetch('comment.php?post_id=<?= $postId ?>', {
        method: 'POST',
        body: formData
    });
    const data = await response.json();

    if (data.success) {
        location.reload();
    } else {
        alert('Error deleting comment.');
    }
}
</script>

</body>
</html>
