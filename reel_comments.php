<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];
$reelId = filter_input(INPUT_GET, 'reel_id', FILTER_VALIDATE_INT);
if (!$reelId) {
    die('Invalid reel ID');
}

// Handle comment submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['comment'])) {
        $comment = trim($_POST['comment']);
        if ($comment === '') {
            $error = "Comment cannot be empty.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO comments (reel_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$reelId, $userId, $comment]);
            header("Location: reel_comments.php?reel_id=$reelId");
            exit;
        }
    } else {
        $error = "Comment is missing.";
    }
}

// Fetch reel info
$stmt = $pdo->prepare("SELECT r.*, u.username, u.profile_pic_url FROM reels r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
$stmt->execute([$reelId]);
$reel = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reel) {
    die('Reel not found');
}

// Fetch comments
$stmt = $pdo->prepare("SELECT c.*, u.username, u.profile_pic_url FROM comments c JOIN users u ON c.user_id = u.id WHERE c.reel_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$reelId]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get reel_index from query string for scroll restoration on reels page
$reelIndex = filter_input(INPUT_GET, 'reel_index', FILTER_VALIDATE_INT);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Comments - Reel <?= htmlspecialchars($reel['id']) ?></title>
<style>
body { font-family: Arial, sans-serif; max-width: 600px; margin: 30px auto; background: #f8f9fa; }
header { margin-bottom: 20px; }
.reel-video {
    width: 100%;
    height: 320px;
    background: black;
}
.comment {
    border-bottom: 1px solid #ddd;
    padding: 10px 0;
    display: flex;
    align-items: flex-start;
}
.comment img {
    width: 40px; height: 40px; border-radius: 50%; margin-right: 10px;
}
.comment-content {
    flex-grow: 1;
}
.comment-username {
    font-weight: bold;
    cursor: pointer;
}
.comment-text {
    margin-top: 5px;
}
form textarea {
    width: 100%; height: 80px; resize: none;
}
form button {
    margin-top: 10px; padding: 8px 16px; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 4px;
}
.error {
    color: red;
}
</style>
</head>
<body>

<header>
    <h2>Comments for Reel #<?= $reel['id'] ?></h2>
    <video src="<?= htmlspecialchars($reel['video_url']) ?>" controls width="100%"></video>
    <p><strong>By:</strong> <a href="profile.php?id=<?= $reel['user_id'] ?>"><?= htmlspecialchars($reel['username']) ?></a></p>
    <p><?= nl2br(htmlspecialchars($reel['description'])) ?></p>
</header>

<section>
    <h3>Leave a Comment</h3>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="POST">
        <textarea name="comment" placeholder="Write your comment here..." required></textarea>
        <button type="submit">Post Comment</button>
    </form>
</section>

<section>
    <h3>Comments (<?= count($comments) ?>)</h3>
    <?php if (!$comments): ?>
        <p>No comments yet. Be the first to comment!</p>
    <?php else: ?>
        <?php foreach ($comments as $comment): ?>
            <div class="comment">
                <img src="<?= htmlspecialchars($comment['profile_pic_url'] ?: 'default_profile.png') ?>" alt="User pic" />
                <div class="comment-content">
                    <div>
                        <a href="profile.php?id=<?= $comment['user_id'] ?>" class="comment-username"><?= htmlspecialchars($comment['username']) ?></a>
                        <small><?= htmlspecialchars($comment['created_at']) ?></small>
                    </div>
                    <div class="comment-text"><?= nl2br(htmlspecialchars($comment['content'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<a href="#" id="back-to-reels">Back to Reels</a>

<script>
document.getElementById('back-to-reels').addEventListener('click', function(event) {
    event.preventDefault();
    // Save scroll position before navigating back (optional, if needed)
    window.location.href = `reels.php`;
});
</script>

</body>
</html>
