<?php

require_once "config.php";
$userId = $_SESSION['user_id'];

// Count total followed users
$stmtTotal = $pdo->prepare("
    SELECT COUNT(*) 
    FROM follows 
    WHERE follower_id = :user_id
");
$stmtTotal->execute(['user_id' => $userId]);
$totalFollowed = (int)$stmtTotal->fetchColumn();

// Fetch followed users info (profile_pic_url, username, id)
$stmtFollowed = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url 
    FROM follows f
    JOIN users u ON f.followed_id = u.id
    WHERE f.follower_id = :user_id
    ORDER BY u.username ASC
");
$stmtFollowed->execute(['user_id' => $userId]);
$followedUsers = $stmtFollowed->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Users You Follow - Fbclone</title>
<style>
body {
    font-family: Arial, sans-serif;
    max-width: 700px;
    margin: 20px auto;
    background: #f9f9f9;
    color: #333;
    padding: 0 15px;
}
h1 {
    text-align: center;
    margin-bottom: 20px;
}
.followed-count {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 20px;
    text-align: center;
}
.followed-list {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    justify-content: center;
}
.followed-item {
    width: 140px;
    text-align: center;
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: box-shadow 0.3s ease;
}
.followed-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.followed-item img {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    margin-bottom: 10px;
    border: 2px solid #007bff;
}
.followed-item a {
    color: #007bff;
    text-decoration: none;
    font-weight: 600;
    font-size: 16px;
}
.followed-item a:hover {
    text-decoration: underline;
}
@media (max-width: 768px) {
    .followed-item {
        width: 120px;
        padding: 12px;
    }
    .followed-item img {
        width: 70px;
        height: 70px;
    }
    .followed-item a {
        font-size: 15px;
    }
}
@media (max-width: 480px) {
    body {
        margin: 15px 10px;
    }
    .followed-count {
        font-size: 16px;
        margin-bottom: 15px;
    }
    .followed-list {
        gap: 12px;
    }
    .followed-item {
        width: 100px;
        padding: 10px;
    }
    .followed-item img {
        width: 60px;
        height: 60px;
        margin-bottom: 8px;
    }
    .followed-item a {
        font-size: 14px;
    }
}
</style>
</head>
<body>

<h1>Users You Follow</h1>

<div class="followed-count">
    You follow <?= $totalFollowed ?> user<?= $totalFollowed !== 1 ? 's' : '' ?>.
</div>

<?php if (empty($followedUsers)): ?>
    <p style="text-align:center;">You are not following anyone yet.</p>
<?php else: ?>
<div class="followed-list">
    <?php foreach ($followedUsers as $user): ?>
    <div class="followed-item" title="<?= htmlspecialchars($user['username']) ?>">
        <a href="profile.php?id=<?= htmlspecialchars($user['id']) ?>">
            <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" alt="<?= htmlspecialchars($user['username']) ?>'s Profile Picture" />
            <div><?= htmlspecialchars($user['username']) ?></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</body>
</html>
