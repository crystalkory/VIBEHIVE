<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: auth.php');
  exit;
}

require_once "config.php";
$userId = $_SESSION['user_id'];

function getCommentCount($pdo, $postId) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_comments WHERE post_id = :post_id");
  $stmt->execute(['post_id' => $postId]);
  return (int)$stmt->fetchColumn();
}

function getLikeCount($pdo, $postId) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
  $stmt->execute(['post_id' => $postId]);
  return (int)$stmt->fetchColumn();
}

function userLikedPost($pdo, $userId, $postId) {
  $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
  $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
  return (bool)$stmt->fetchColumn();
}

$stmt = $pdo->prepare("
  SELECT p.*, u.username, u.profile_pic_url
  FROM posts p
  JOIN users u ON p.user_id = u.id
  WHERE p.user_id = :user_id
  ORDER BY p.created_at DESC
");
$stmt->execute(['user_id' => $userId]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>My Posts - Fbclone</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 720px; margin: 20px auto; background: #f9f9f9; }
  h1 { text-align: center; margin-bottom: 25px; }
  .post {
    background: white;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 3px 6px rgba(0,0,0,0.1);
  }
  .post-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
  }
  .post-header img {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
  }
  .post-header .username {
    margin-left: 12px;
    font-weight: bold;
    color: #007bff;
    cursor: pointer;
  }
  .post-content {
    margin-bottom: 10px;
    white-space: pre-wrap;
    max-height: 4.5em; /* about 3 lines */
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
  }
  .post-content.expanded {
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
    margin-bottom: 10px;
  }
  .post-media img, .post-media video {
    max-width: 100%;
    max-height: 300px;
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

<h1>My Posts</h1>

<?php if (empty($posts)): ?>
  <p>You have not made any posts yet.</p>
<?php else: ?>
  <?php foreach ($posts as $post): ?>
  <div class="post" data-post-id="<?= $post['id'] ?>">
    <div class="post-header">
      <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
      <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'"><?= htmlspecialchars($post['username']) ?></div>
    </div>
    <div class="post-content" id="post-content-<?= $post['id'] ?>">
      <?= nl2br(htmlspecialchars($post['content'])) ?>
    </div>
    <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
      <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
    <?php endif; ?>
    <div class="post-media">
      <?php
      $mediaFiles = explode(',', $post['media_url']);
      foreach ($mediaFiles as $media):
        $media = trim($media);
        if (!$media) continue;
        $ext = pathinfo($media, PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
          <img src="<?= htmlspecialchars($media) ?>" alt="Post Image" />
        <?php elseif (in_array(strtolower($ext), ['mp4', 'webm', 'ogg'])): ?>
          <video controls>
            <source src="<?= htmlspecialchars($media) ?>" type="video/<?= htmlspecialchars($ext) ?>" />
            Your browser does not support the video tag.
          </video>
        <?php endif;
      endforeach;
      ?>
    </div>
    <div class="actions">
      <span class="like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
        ❤️ Like (<span class="like-count"><?= getLikeCount($pdo, $post['id']) ?></span>)
      </span>
      <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
        💬 Comment (<?= getCommentCount($pdo, $post['id']) ?>)
      </span>
      <button class="boost-btn" onclick="alert('Boost feature coming soon!')">Boost</button>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
// Show More / Show Less toggle
document.querySelectorAll('.show-more-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const postId = btn.dataset.postId;
    const contentDiv = document.getElementById('post-content-' + postId);
    if (contentDiv.classList.contains('expanded')) {
      contentDiv.classList.remove('expanded');
      btn.textContent = 'Show More';
    } else {
      contentDiv.classList.add('expanded');
      btn.textContent = 'Show Less';
    }
  });
});

// Like/unlike AJAX
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
