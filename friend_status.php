<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

// Database connection
require_once "config.php";


$loggedInUserId = $_SESSION['user_id'];

// Fetch friend IDs (both directions for friendship)
$stmtFriends = $pdo->prepare("
   SELECT 
        CASE WHEN user_id::text = :uid THEN friend_id ELSE user_id END AS friend_id
    FROM friends
    WHERE (user_id::text = :uid OR friend_id::text = :uid) AND status = 1

");
$stmtFriends->execute(['uid' => $loggedInUserId]);
$friendIds = $stmtFriends->fetchAll(PDO::FETCH_COLUMN);

if (count($friendIds) === 0) {
    $friendIds[] = 0; // No friends, so no posts
}

// Fetch posts from friends randomly
// Join users table to get username etc for display
$inQuery = implode(',', array_fill(0, count($friendIds), '?'));
$sql = "
    SELECT p.id, p.user_id, p.content, p.created_at, u.username,
        (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS total_likes,
        EXISTS(
            SELECT 1 FROM likes l2 WHERE l2.post_id = p.id AND l2.user_id = ?
        ) AS liked_by_user
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE p.user_id IN ($inQuery)
    ORDER BY random()
    LIMIT 20
";
$params = $friendIds;
$params[] = $loggedInUserId; // For liked_by_user check
$stmtPosts = $pdo->prepare($sql);
$stmtPosts->execute($params);
$posts = $stmtPosts->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Friend Posts - Fbclone</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 700px; margin: 20px auto; background: #f9f9f9; }
    .post { background: white; border-radius: 10px; padding: 15px; margin-bottom: 20px; box-shadow: 0 3px 8px rgba(0,0,0,0.1); }
    .post-header { font-weight: bold; margin-bottom: 10px; color: #007bff; cursor: pointer; }
    .post-content { font-size: 16px; margin-bottom: 12px; }
    .like-btn {
        color: #007bff;
        cursor: pointer;
        font-weight: 600;
        border: none;
        background: none;
        font-size: 14px;
        user-select: none;
    }
    .like-btn.liked { color: #d9534f; }
    .like-count { margin-left: 6px; font-weight: normal; color: #555; }
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const postId = btn.dataset.postId;
            const isLiked = btn.classList.contains('liked');
            try {
                const formData = new FormData();
                formData.append('post_id', postId);
                formData.append('action', isLiked ? 'unlike' : 'like');
                const response = await fetch('friend_status_like_handler.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    btn.classList.toggle('liked');
                    btn.querySelector('.like-count').textContent = data.total_likes;
                    btn.textContent = isLiked ? 'Like' : 'Unlike';
                    btn.appendChild(document.createElement('span')).classList.add('like-count');
                    btn.querySelector('.like-count').textContent = data.total_likes;
                } else {
                    alert(data.message || 'Failed to update like status');
                }
            } catch {
                alert('Error processing request');
            }
        });
    });
});
</script>
</head>
<body>

<h1>Friend Posts</h1>

<?php if (count($posts) === 0): ?>
    <p>No posts from your friends yet.</p>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <div class="post">
            <div class="post-header" onclick="window.location.href='profile.php?id=<?= htmlspecialchars($post['user_id']) ?>'">
                <?= htmlspecialchars($post['username']) ?>
            </div>
            <div class="post-content"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
            <button class="like-btn <?= $post['liked_by_user'] ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                <?= $post['liked_by_user'] ? 'Unlike' : 'Like' ?>
                <span class="like-count"><?= $post['total_likes'] ?></span>
            </button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
