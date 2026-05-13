<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";
$userId = $_SESSION['user_id'];

// Track view unique per user per reel if reel_id provided as query param, e.g., when loading a reel or video page
if (isset($_GET['reel_id']) && is_numeric($_GET['reel_id'])) {
    $reelId = (int)$_GET['reel_id'];
    try {
        $stmtView = $pdo->prepare("
            INSERT INTO reel_views (reel_id, user_id) VALUES (:reel_id, :user_id) ON CONFLICT (reel_id, user_id) DO NOTHING
        ");
        $stmtView->execute(['reel_id' => $reelId, 'user_id' => $userId]);
    } catch (PDOException $e) {
        // Optional: log error here
    }
}

// Fetch followed users for follow/unfollow UI state
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch reels with author info
$stmt = $pdo->prepare("
    SELECT r.*, u.username, u.profile_pic_url, u.id AS author_id
    FROM reels r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
    LIMIT 50
");
$stmt->execute();
$reels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function userFollows($followedUsers, $authorId) {
    return in_array($authorId, $followedUsers);
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

function getCommentCount($pdo, $reelId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE reel_id = ?");
    $stmt->execute([$reelId]);
    return (int)$stmt->fetchColumn();
}

function getViewCount($pdo, $reelId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reel_views WHERE reel_id = ?");
    $stmt->execute([$reelId]);
    return (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Reels - Fbclone</title>
<style>
body {margin:0; padding:0; overflow-x:hidden; font-family: Arial, sans-serif;}
#reels-container {
  display: flex;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  height: 100vh; 
  width: 100vw;
  scroll-behavior: smooth;
}
.reel {
  flex: 0 0 100vw;
  height: 100vh;
  scroll-snap-align: start;
  position: relative;
  background: #000;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  color: white;
  padding: 20px;
  box-sizing: border-box;
}
video {
  position: absolute;
  top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;
  z-index: 1;
}
.reel-content {
  position: relative;
  z-index: 2;
  background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);
  padding: 15px;
  height: 30%;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
}
.user-info {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
}
.user-info img {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
  cursor: pointer;
}
.user-info .username {
  margin-left: 10px;
  font-weight: bold;
  cursor: pointer;
}
.actions {
  display: flex;
  gap: 20px;
  margin-top: auto;
  align-items: center;
}
.actions button, .actions span {
  background: transparent;
  border: none;
  color: white;
  cursor: pointer;
  font-size: 18px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.follow-btn {
  background: linear-gradient(45deg, #ff004f, #c972ff);
  border-radius: 40px;
  padding: 4px 14px;
  font-weight: bold;
  cursor: pointer;
  border: none;
  color: white;
  transition: all 0.3s ease;
}
.follow-btn.following {
  background: #888;
  box-shadow: 0 1px 5px #555 inset;
  color: #ddd;
}
.liked {
  font-weight: bold;
  color: #d9534f;
}
</style>
</head>
<body>

<div id="reels-container">
  <?php foreach ($reels as $index => $reel):
    $isFollowing = userFollows($followedUserIds, $reel['author_id']);
    $isLiked = userLikedReel($pdo, $userId, $reel['id']);
    $likeCount = getLikeCount($pdo, $reel['id']);
    $commentCount = getCommentCount($pdo, $reel['id']);
    $viewCount = getViewCount($pdo, $reel['id']);
  ?>
  <div class="reel" data-reel-id="<?= $reel['id'] ?>" onclick="goToComments(<?= $reel['id'] ?>, <?= $index ?>)">
    <video src="<?= htmlspecialchars($reel['video_url']) ?>" autoplay muted loop playsinline></video>
    <div class="reel-content">
      <div class="user-info">
        <img src="<?= htmlspecialchars($reel['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile" onclick="window.location='profile.php?id=<?= $reel['author_id'] ?>'" />
        <div class="username" onclick="window.location='profile.php?id=<?= $reel['author_id'] ?>'"><?= htmlspecialchars($reel['username']) ?></div>
        <?php if ($reel['author_id'] !== $userId): ?>
          <button class="follow-btn <?= $isFollowing ? 'following' : '' ?>" data-user-id="<?= $reel['author_id'] ?>">
            <?= $isFollowing ? 'Following' : 'Follow' ?>
          </button>
        <?php endif; ?>
      </div>
      <p><?= nl2br(htmlspecialchars($reel['description'])) ?></p>
      <div><strong>Views:</strong> <?= $viewCount ?></div>
      <div class="actions">
        <span class="like-btn <?= $isLiked ? 'liked' : '' ?>" data-post-id="<?= $reel['id'] ?>" style="user-select:none;">
          ❤️ <span class="like-count"><?= $likeCount ?></span>
        </span>
        <span class="comment-btn">
          💬 <?= $commentCount ?>
        </span>
        <span class="share-btn" onclick="alert('Share functionality coming soon!')">🔗</span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const reelsContainer = document.getElementById('reels-container');

window.addEventListener('load', () => {
  const scrollPos = sessionStorage.getItem('reelsScrollPos');
  if (scrollPos !== null) {
    reelsContainer.scrollLeft = parseInt(scrollPos, 10);
  }
});

window.addEventListener('beforeunload', () => {
  sessionStorage.setItem('reelsScrollPos', reelsContainer.scrollLeft);
});

// Follow/unfollow button logic
document.querySelectorAll('.follow-btn').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    e.stopPropagation();
    const userId = btn.getAttribute('data-user-id');
    const isFollowing = btn.classList.contains('following');
    const action = isFollowing ? 'unfollow' : 'follow';

    const formData = new FormData();
    formData.append('action', action);
    formData.append('followed_id', userId);

    try {
      const res = await fetch(window.location.href, { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(buttonEl => {
          if (data.action === 'followed') {
            buttonEl.textContent = 'Following';
            buttonEl.classList.add('following');
          } else {
            buttonEl.textContent = 'Follow';
            buttonEl.classList.remove('following');
          }
        });
      }
    } catch {
      alert('Error updating follow status.');
    }
  });
});

// Like/unlike button logic
document.querySelectorAll('.like-btn').forEach(btn => {
  btn.addEventListener('click', async e => {
    e.stopPropagation();
    const postId = btn.getAttribute('data-post-id');
    const isLiked = btn.classList.contains('liked');
    const formData = new FormData();
    formData.append('action', isLiked ? 'unlike' : 'like');
    formData.append('post_id', postId);

    try {
      const response = await fetch('home.php', { method: 'POST', body: formData });
      const data = await response.json();
      if (data.success) {
        const likeCountSpan = btn.querySelector('.like-count');
        if (likeCountSpan) likeCountSpan.textContent = data.likes_count;
        btn.classList.toggle('liked');
      } else {
        alert('Failed to update like status.');
      }
    } catch {
      alert('Error updating like status.');
    }
  });
});

// Navigate to comments page preserving scroll position
function goToComments(reelId, index) {
  sessionStorage.setItem('reelsScrollPos', reelsContainer.scrollLeft);
  window.location.href = `reel_comments.php?reel_id=${reelId}&reel_index=${index}`;
}
</script>

</body>
</html>
