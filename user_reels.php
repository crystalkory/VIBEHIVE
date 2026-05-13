<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: auth.php');
  exit;
}

require_once "config.php";
$userId = $_SESSION['user_id'];

function getCommentCount($pdo, $reelId) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE reel_id = ?");
  $stmt->execute([$reelId]);
  return (int)$stmt->fetchColumn();
}

function getLikeCount($pdo, $reelId) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE reel_id = ?");
  $stmt->execute([$reelId]);
  return (int)$stmt->fetchColumn();
}

function userLikedReel($pdo, $userId, $reelId) {
  $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE reel_id = ? AND user_id = ?");
  $stmt->execute([$reelId, $userId]);
  return (bool)$stmt->fetchColumn();
}

// Fetch all reels posts of the logged-in user
$stmt = $pdo->prepare("
  SELECT r.*, u.username, u.profile_pic_url
  FROM reels r
  JOIN users u ON r.user_id = u.id
  WHERE r.user_id = :user_id
  ORDER BY r.created_at DESC
");
$stmt->execute(['user_id' => $userId]);
$reels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>My Reels - Fbclone</title>
<style>
body {
  font-family: Arial, sans-serif;
  max-width: 720px;
  margin: 20px auto;
  background: #f9f9f9;
}
h1 {
  text-align: center;
  margin-bottom: 25px;
}
.reel-post {
  background: white;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 20px;
  box-shadow: 0 3px 6px rgba(0,0,0,0.1);
}
.reel-header {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
}
.reel-header img {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  cursor: pointer;
}
.reel-header .username {
  margin-left: 12px;
  font-weight: bold;
  color: #007bff;
  cursor: pointer;
}
.reel-description {
  white-space: pre-wrap;
  max-height: 4.5em;
  overflow: hidden;
  transition: max-height 0.3s ease;
  margin-bottom: 10px;
}
.reel-description.expanded {
  max-height: none;
}
.show-more-btn {
  background: none;
  border: none;
  color: #007bff;
  cursor: pointer;
  font-size: 14px;
  user-select: none;
  padding: 0;
  margin-bottom: 12px;
}
.reel-video {
  width: 100%;
  max-height: 360px;
  border-radius: 8px;
  margin-bottom: 10px;
}
.actions {
  display: flex;
  gap: 25px;
  font-size: 15px;
}
.actions span, .actions button {
  cursor: pointer;
  color: #007bff;
  user-select: none;
}
.liked {
  font-weight: bold;
  color: #d9534f;
}
.boost-btn {
  background-color: #28a745;
  color: white;
  padding: 6px 14px;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.2s ease;
}
.boost-btn:hover {
  background-color: #218838;
}
</style>
</head>
<body>

<h1>My Reels</h1>

<?php if (empty($reels)): ?>
  <p>You have no reels posted yet.</p>
<?php else: ?>
  <?php foreach ($reels as $reel): ?>
  <div class="reel-post" data-reel-id="<?= $reel['id'] ?>">
    <div class="reel-header">
      <img src="<?= htmlspecialchars($reel['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" onclick="window.location='profile.php?id=<?= $reel['user_id'] ?>'" />
      <div class="username" onclick="window.location='profile.php?id=<?= $reel['user_id'] ?>'"><?= htmlspecialchars($reel['username']) ?></div>
    </div>
    <div class="reel-description" id="reel-description-<?= $reel['id'] ?>">
      <?= nl2br(htmlspecialchars($reel['description'])) ?>
    </div>
    <?php if (mb_strlen(strip_tags($reel['description'])) > 100): ?>
      <button class="show-more-btn" data-reel-id="<?= $reel['id'] ?>">Show More</button>
    <?php endif; ?>
    <video controls class="reel-video">
      <source src="<?= htmlspecialchars($reel['media_url']) ?>" type="video/mp4" />
      Your browser does not support the video tag.
    </video>
    <div class="actions">
      <span class="like-btn <?= userLikedReel($pdo, $userId, $reel['id']) ? 'liked' : '' ?>" data-post-id="<?= $reel['id'] ?>">
        ❤️ Like (<span class="like-count"><?= getLikeCount($pdo, $reel['id']) ?></span>)
      </span>
      <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $reel['id'] ?>'">
        💬 Comment (<?= getCommentCount($pdo, $reel['id']) ?>)
      </span>
      <button class="boost-btn" onclick="alert('Boost feature coming soon!')">Boost</button>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
// Toggle Show More / Show Less for reel description
document.querySelectorAll('.show-more-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const reelId = btn.dataset.reelId;
    const descDiv = document.getElementById('reel-description-' + reelId);
    if (descDiv.classList.contains('expanded')) {
      descDiv.classList.remove('expanded');
      btn.textContent = 'Show More';
    } else {
      descDiv.classList.add('expanded');
      btn.textContent = 'Show Less';
    }
  });
});

// Like/unlike AJAX handler
document.querySelectorAll('.like-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const postId = btn.dataset.postId;
    const isLiked = btn.classList.contains('liked');
    const formData = new FormData();
    formData.append('action', isLiked ? 'unlike' : 'like');
    formData.append('post_id', postId);
    try {
      const response = await fetch('home.php', {
        method: 'POST',
        body: formData
      });
      const data = await response.json();
      if (data.success) {
        const likeCountSpan = btn.querySelector('.like-count');
        likeCountSpan.textContent = data.likes_count;
        btn.classList.toggle('liked');
      } else {
        alert('Failed to update like status.');
      }
    } catch {
      alert('Error updating like status.');
    }
  });
});
</script>

</body>
</html>
