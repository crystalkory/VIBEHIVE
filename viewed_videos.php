<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];

// Get user's viewed videos with details
$viewedVideosStmt = $pdo->prepare("
    SELECT 
        p.*,
        u.username,
        u.profile_pic_url,
        ubt.interaction_type,
        ubt.completion_rate,
        ubt.watch_time,
        ubt.created_at as viewed_at,
        COALESCE(l.likes_count, 0) as likes_count,
        COALESCE(c.comments_count, 0) as comments_count
    FROM user_behavior_tracking ubt
    JOIN posts p ON ubt.post_id = p.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN (
        SELECT post_id, COUNT(*) as likes_count 
        FROM likes 
        GROUP BY post_id
    ) l ON p.id = l.post_id
    LEFT JOIN (
        SELECT post_id, COUNT(*) as comments_count 
        FROM comments 
        GROUP BY post_id
    ) c ON p.id = c.post_id
    WHERE ubt.user_id = ? 
    AND ubt.interaction_type IN ('view', 'progress', 'completion', 'scroll_past')
    ORDER BY ubt.created_at DESC
");
$viewedVideosStmt->execute([$userId]);
$viewedVideos = $viewedVideosStmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's profile picture
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

// Helper functions
function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// Get user's followed accounts
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Viewed Videos - Fbclone</title>
    <style>
        body {
            background: #1e1e2f;
            color: #fff;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        .header {
            background: #2c2c3d;
            padding: 20px;
            text-align: center;
            border-bottom: 2px solid #7b68ee;
        }
        .header h1 {
            margin: 0;
            color: #7b68ee;
        }
        .back-btn {
            background: #7b68ee;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            text-decoration: none;
            display: inline-block;
            margin: 10px;
            cursor: pointer;
        }
        .stats {
            background: #2c2c3d;
            padding: 15px;
            margin: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stats-grid {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 15px;
        }
        .stat-item {
            text-align: center;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #7b68ee;
        }
        .stat-label {
            font-size: 12px;
            color: #ccc;
        }
        .videos-container {
            margin: 20px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .video-card {
            background: #2c2c3d;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .video-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .video-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        .video-info {
            flex-grow: 1;
        }
        .username {
            font-weight: bold;
            color: #7b68ee;
        }
        .view-info {
            font-size: 12px;
            color: #ccc;
        }
        .interaction-badge {
            background: #7b68ee;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 10px;
        }
        .video-content {
            margin: 10px 0;
            color: #ccc;
        }
        .video-player {
            width: 100%;
            max-height: 400px;
            border-radius: 8px;
            background: #000;
        }
        .video-stats {
            display: flex;
            gap: 15px;
            margin: 10px 0;
            font-size: 12px;
            color: #888;
        }
        .actions {
            display: flex;
            gap: 15px;
        }
        .action-btn {
            background: none;
            border: none;
            color: #7b68ee;
            cursor: pointer;
            font-size: 14px;
        }
        .action-btn.liked {
            color: #ff6b6b;
            font-weight: bold;
        }
        .completion-bar {
            width: 100%;
            height: 4px;
            background: #333;
            border-radius: 2px;
            margin: 5px 0;
        }
        .completion-fill {
            height: 100%;
            background: #7b68ee;
            border-radius: 2px;
        }
        .no-videos {
            text-align: center;
            padding: 40px;
            color: #ccc;
        }
        .category-badges {
            display: flex;
            gap: 5px;
            margin: 8px 0;
            flex-wrap: wrap;
        }
        .category-badge {
            background: rgba(123, 104, 238, 0.2);
            color: #7b68ee;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            border: 1px solid #7b68ee;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="video.php" class="back-btn">← Back to Videos</a>
        <h1>📺 Your Viewed Videos</h1>
        <p>Videos you've watched, scrolled past, or interacted with</p>
    </div>

    <div class="stats">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value"><?= count($viewedVideos) ?></div>
                <div class="stat-label">Total Viewed</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?= count(array_filter($viewedVideos, function($video) { 
                        return $video['interaction_type'] === 'completion'; 
                    })) ?>
                </div>
                <div class="stat-label">Fully Watched</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?= count(array_filter($viewedVideos, function($video) { 
                        return $video['interaction_type'] === 'scroll_past'; 
                    })) ?>
                </div>
                <div class="stat-label">Scrolled Past</div>
            </div>
        </div>
    </div>

    <div class="videos-container">
        <?php if (empty($viewedVideos)): ?>
            <div class="no-videos">
                <h3>No viewed videos yet</h3>
                <p>Start watching videos on the main video page to see them here!</p>
                <a href="video.php" class="back-btn">Browse Videos</a>
            </div>
        <?php else: ?>
            <?php foreach ($viewedVideos as $video): ?>
                <div class="video-card" data-post-id="<?= $video['id'] ?>">
                    <div class="video-header">
                        <img src="<?= htmlspecialchars($video['profile_pic_url'] ?: 'default_profile.png') ?>" 
                             alt="Profile" onclick="window.location='profile.php?id=<?= $video['user_id'] ?>'">
                        <div class="video-info">
                            <div class="username" onclick="window.location='profile.php?id=<?= $video['user_id'] ?>'">
                                <?= htmlspecialchars($video['username']) ?>
                            </div>
                            <div class="view-info">
                                Viewed <?= date('M j, Y g:i A', strtotime($video['viewed_at'])) ?>
                                <span class="interaction-badge">
                                    <?php 
                                    $interactionLabels = [
                                        'view' => 'Viewed',
                                        'progress' => 'Partially Watched',
                                        'completion' => 'Fully Watched', 
                                        'scroll_past' => 'Scrolled Past'
                                    ];
                                    echo $interactionLabels[$video['interaction_type']] ?? 'Viewed';
                                    ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($video['user_id'] !== $userId && !in_array($video['user_id'], $followedUserIds)): ?>
                            <button class="follow-btn" data-user-id="<?= $video['user_id'] ?>">Follow</button>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($video['category1']) || !empty($video['category2']) || !empty($video['category3'])): ?>
                        <div class="category-badges">
                            <?php if (!empty($video['category1'])): ?>
                                <span class="category-badge"><?= htmlspecialchars($video['category1']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($video['category2'])): ?>
                                <span class="category-badge"><?= htmlspecialchars($video['category2']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($video['category3'])): ?>
                                <span class="category-badge"><?= htmlspecialchars($video['category3']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="video-content">
                        <?= nl2br(htmlspecialchars($video['content'])) ?>
                    </div>

                    <?php if ($video['post_type'] === 'video' && !empty($video['media_url'])): ?>
                        <?php $videoUrl = explode(',', $video['media_url'])[0]; ?>
                        <video class="video-player" controls preload="metadata">
                            <source src="<?= htmlspecialchars(trim($videoUrl)) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php endif; ?>

                    <?php if ($video['interaction_type'] === 'progress' || $video['interaction_type'] === 'completion'): ?>
                        <div class="completion-bar">
                            <div class="completion-fill" style="width: <?= ($video['completion_rate'] ?? 0) * 100 ?>%"></div>
                        </div>
                        <div class="view-info">
                            Watched <?= round(($video['completion_rate'] ?? 0) * 100) ?>% 
                            (<?= round($video['watch_time'] ?? 0) ?> seconds)
                        </div>
                    <?php endif; ?>

                    <div class="video-stats">
                        <span>❤️ <?= $video['likes_count'] ?> likes</span>
                        <span>💬 <?= $video['comments_count'] ?> comments</span>
                    </div>

                    <div class="actions">
                        <button class="action-btn like-btn <?= userLikedPost($pdo, $userId, $video['id']) ? 'liked' : '' ?>" 
                                data-post-id="<?= $video['id'] ?>">
                            Like (<span class="like-count"><?= $video['likes_count'] ?></span>)
                        </button>
                        <button class="action-btn comment-btn" 
                                onclick="window.location='comment.php?post_id=<?= $video['id'] ?>'">
                            Comment
                        </button>
                        <button class="action-btn share-btn" onclick="alert('Share feature coming soon!')">
                            Share
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
    // Follow button functionality
    document.querySelectorAll('.follow-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            const formData = new FormData();
            formData.append('action', 'follow');
            formData.append('followed_id', userId);
            
            fetch('video.php', {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      this.textContent = 'Following';
                      this.style.background = '#888';
                  }
              })
              .catch(error => console.error('Follow error:', error));
        });
    });

    // Like button functionality
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.getAttribute('data-post-id');
            const isLiked = this.classList.contains('liked');
            const action = isLiked ? 'unlike' : 'like';
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('post_id', postId);
            
            fetch('video.php', {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      const likeCount = this.querySelector('.like-count');
                      if (action === 'like') {
                          this.classList.add('liked');
                          likeCount.textContent = data.likes_count;
                      } else {
                          this.classList.remove('liked');
                          likeCount.textContent = data.likes_count;
                      }
                  }
              })
              .catch(error => console.error('Like error:', error));
        });
    });
    </script>
</body>
</html>