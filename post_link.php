<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

if (!isset($_GET['post_id']) || !is_numeric($_GET['post_id'])) {
    die("Invalid post ID.");
}

$postId = (int)$_GET['post_id'];
require_once "config.php";
// Fetch post and related group and user info
$stmt = $pdo->prepare("
    SELECT p.id, p.content, p.created_at,
           g.id AS group_id, g.name AS group_name, g.cover_pic_url, g.is_private,
           u.id AS user_id, u.username, u.profile_pic_url
    FROM posts p
    JOIN groups g ON p.group_id = g.id
    JOIN users u ON p.user_id = u.id
    WHERE p.id = :post_id
");
$stmt->execute(['post_id' => $postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    die("Post not found.");
}

if ($post['is_private']) {
    die("Post belongs to a private group and cannot be shown.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?= htmlspecialchars($post['username']) ?>'s Post</title>
<style>
body {
    font-family: Arial, sans-serif;
    max-width: 700px;
    margin: 30px auto;
    background: #fafafa;
    padding: 20px;
}
.post-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}
.group-cover {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
}
.author-pic {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.author-name {
    font-weight: bold;
    font-size: 1.2em;
    color: #1877f2;
    cursor: pointer;
}
.group-name {
    margin-left: auto;
    font-weight: 600;
    font-size: 1.1em;
    color: #555;
    cursor: pointer;
}
.post-content {
    font-size: 1.1em;
    line-height: 1.4;
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 1px 6px rgba(0,0,0,0.1);
}
</style>
<script>
function goToGroup() {
    window.location.href = 'group.php?id=<?= $post['group_id'] ?>';
}
function goToProfile() {
    window.location.href = 'profile.php?id=<?= $post['user_id'] ?>';
}
</script>
</head>
<body>

<div class="post-header">
    <img src="<?= htmlspecialchars($post['cover_pic_url'] ?: 'default_group_cover.png') ?>" alt="Group Cover" class="group-cover" onclick="goToGroup()" title="Go to Group: <?= htmlspecialchars($post['group_name']) ?>" />
    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Author Pic" class="author-pic" onclick="goToProfile()" title="Go to Profile: <?= htmlspecialchars($post['username']) ?>" />
    <div class="author-name" onclick="goToProfile()"><?= htmlspecialchars($post['username']) ?></div>
    <div class="group-name" onclick="goToGroup()"><?= htmlspecialchars($post['group_name']) ?></div>
</div>

<div class="post-content">
    <?= nl2br(htmlspecialchars($post['content'])) ?>
</div>

</body>
</html>
