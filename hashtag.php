<?php

require_once "config.php";


$tag = $_GET['tag'] ?? '';
$posts = [];

if (!empty($tag)) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url 
        FROM posts p
        JOIN users u ON p.user_id = u.id
        JOIN post_hashtags ph ON p.id = ph.post_id
        JOIN hashtags h ON ph.hashtag_id = h.id
        WHERE h.tag = ? AND p.privacy_setting = 'public'
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$tag]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>#<?= htmlspecialchars($tag) ?></title>
</head>
<body>
    <h1>#<?= htmlspecialchars($tag) ?></h1>
    <p><?= count($posts) ?> posts</p>
    
    <?php foreach ($posts as $post): ?>
        <div style="border: 1px solid #444; padding: 15px; margin: 10px 0;">
            <div><?= nl2br(htmlspecialchars($post['content'])) ?></div>
            <small>By <?= htmlspecialchars($post['username']) ?></small>
        </div>
    <?php endforeach; ?>
</body>
</html>