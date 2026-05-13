<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// AJAX handlers for FOLLOW/UNFOLLOW and LIKE/UNLIKE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- FOLLOW/UNFOLLOW HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
        $followerId = $_SESSION['user_id'];
        $followedId = (int)$_POST['followed_id'];
        if ($followerId && $followedId && $followerId !== $followedId) {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$followerId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'followed']);
                exit;
            } else {
                $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
                $stmt->execute([$followerId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'unfollowed']);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid operation']);
        exit;
    }

    // --- LIKE/UNLIKE HANDLER ---
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid post id']);
            exit;
        }
        if ($_POST['action'] === 'like') {
            $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (:post_id, :user_id) ON CONFLICT DO NOTHING");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = :post_id AND user_id = :user_id");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
        $stmt->execute(['post_id' => $postId]);
        $likesCount = (int)$stmt->fetchColumn();
        echo json_encode(['success' => true, 'likes_count' => $likesCount]);
        exit;
    }
}

// Fetch user's followed accounts
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// NEW: Algorithmic Feed Logic - FIXED VERSION
$postStmt = $pdo->prepare("
    SELECT 
        p.*, 
        u.username, 
        u.profile_pic_url, 
        u.is_business_account, 
        u.id AS author_id,
        -- Engagement metrics
        COALESCE(l.likes_count, 0) as likes_count,
        COALESCE(c.comments_count, 0) as comments_count,
        COALESCE(s.shares_count, 0) as shares_count,
        COALESCE(v.views_count, 0) as views_count,
        -- User relationship signals
        CASE WHEN f.follower_id IS NOT NULL THEN 1 ELSE 0 END as is_following_author,
        CASE WHEN ul.user_id IS NOT NULL THEN 1 ELSE 0 END as user_liked_post,
        CASE WHEN p.user_id = :user_id THEN 1 ELSE 0 END as is_own_post,
        -- Time decay factor (more recent posts get higher score)
        EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 as hours_ago
        
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = :user_id AND gm.status = 'approved'
    
    -- Engagement metrics subqueries
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
    
    LEFT JOIN (
        SELECT original_post_id, COUNT(*) as shares_count 
        FROM posts 
        WHERE original_post_id IS NOT NULL 
        GROUP BY original_post_id
    ) s ON p.id = s.original_post_id
    
    LEFT JOIN (
        SELECT post_id, COUNT(*) as views_count 
        FROM post_views 
        GROUP BY post_id
    ) v ON p.id = v.post_id
    
    -- User relationship subqueries
    LEFT JOIN follows f ON (f.follower_id = :user_id AND f.followed_id = p.user_id)
    LEFT JOIN likes ul ON (ul.post_id = p.id AND ul.user_id = :user_id)
    
    WHERE p.post_type = 'video'
      AND (
        (p.privacy_setting = 'public')
        OR (p.privacy_setting = 'friends' AND EXISTS (
              SELECT 1 FROM friends f WHERE 
                ((f.user_id = :user_id AND f.friend_id = p.user_id) 
                 OR (f.friend_id = :user_id AND f.user_id = p.user_id)) 
                 AND f.status = 'accepted'))
        OR (p.privacy_setting = 'private' AND p.user_id = :user_id)
        OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
      )
    ORDER BY 
        -- Algorithmic scoring instead of simple chronological
        (
          -- Base engagement score (weighted)
          (COALESCE(l.likes_count, 0) * 0.3) +
          (COALESCE(c.comments_count, 0) * 0.4) +  -- Comments are more valuable
          (COALESCE(s.shares_count, 0) * 0.5) +    -- Shares are most valuable
          (COALESCE(v.views_count, 0) * 0.1) +
          
          -- Relationship boost
          (CASE WHEN f.follower_id IS NOT NULL THEN 2.0 ELSE 0 END) + -- Following author
          (CASE WHEN ul.user_id IS NOT NULL THEN 0.5 ELSE 0 END) +    -- User liked this post
          
          -- Time decay (recent posts get boost)
          (GREATEST(0, (72 - EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600) / 72) * 2) +
          
          -- Content type bonus (videos get preference in reels)
          (CASE WHEN p.post_type = 'video' THEN 1.5 ELSE 1.0 END)
        ) DESC,
        -- Fallback to recent posts as tiebreaker
        p.created_at DESC
    LIMIT 50
");
$postStmt->execute(['user_id' => $userId]);
$posts = $postStmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare posts for display
foreach ($posts as &$post) {
    $post['is_following'] = in_array($post['author_id'], $followedUserIds);
    
    // Calculate engagement score for display (optional)
    $post['engagement_score'] = 
        ($post['likes_count'] * 0.3) + 
        ($post['comments_count'] * 0.4) + 
        ($post['shares_count'] * 0.5) + 
        ($post['views_count'] * 0.1);
}
unset($post);

// Helper function to get comment count
function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Check if current user liked a post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';

require_once "header.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Reels</title>
<style>
header {
    background: #1e1e2f;
    color: #fff;
    padding: 10px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}
header h1 {
    margin: 0;
    font-size: 22px;
    flex-grow: 1;
}
header nav a {
    color: #fff;
    background: #0053ba;
    border: none;
    padding: 8px 14px;
    border-radius: 4px;
    text-decoration: none;
    margin: 5px 3px;
    display: inline-block;
    cursor: pointer;
}
.reels-container {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    background-color: #1e1e2f;
}
.reel {
    background: #2c2c3d;
    border-radius: 8px;
    box-shadow: 0 2px 6px #ccc;
    padding: 15px;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    position: relative;
}
.reel-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}
.reel-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.reel-header .username {
    margin-left: 10px;
    font-weight: bold;
    cursor: pointer;
    color: #007bff;
    flex-grow: 1;
}
.reel-content {
    white-space: pre-wrap;
    max-height: 4.5em;
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
    margin-bottom: 10px;
}
.reel-content.expanded {
    max-height: none;
}
.show-more-btn {
    background: none;
    border: none;
    color: #007bff;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    margin: 0 0 8px 0;
    user-select: none;
}

/* Video reel styling */
.video-reel-container {
  position: relative;
  width: 100%;
  margin-bottom: 10px;
  overflow: hidden;
  border-radius: 10px;
}
.video-reel-scroller {
  display: flex;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none; /* Firefox */
}
.video-reel-scroller::-webkit-scrollbar {
  display: none; /* Chrome, Safari, Edge */
}
.video-reel-item {
  position: relative;
  flex: 0 0 auto;
  width: 100%;
  scroll-snap-align: start;
}
.video-reel-item video {
  width: 100%;
  height: auto;
  max-height: 600px;
  object-fit: contain;
  background: #000;
  border-radius: 10px;
}
.video-controls {
  position: absolute;
  bottom: 15px;
  right: 15px;
  z-index: 10;
  display: flex;
  gap: 10px;
}
.sound-toggle, .download-btn {
  background: rgba(0, 0, 0, 0.5);
  color: white;
  border: none;
  border-radius: 50%;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 18px;
}
.download-btn {
  background: rgba(0, 0, 0, 0.7);
}
.video-count-indicator {
  position: absolute;
  top: 15px;
  right: 15px;
  background: rgba(0, 0, 0, 0.5);
  color: white;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
}
.video-pagination {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 10px;
}
.video-pagination-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ccc;
  cursor: pointer;
  transition: background 0.3s ease;
}
.video-pagination-dot.active {
  background: #0095f6;
}

.actions {
    display: flex;
    gap: 20px;
    font-size: 14px;
    align-items: center;
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

.follow-btn {
  margin-left: 10px;
  padding: 6px 14px;
  border: none;
  border-radius: 20px;
  cursor: pointer;
  font-weight: bold;
  background: linear-gradient(45deg, #ff004f, #c972ff);
  color: white;
  user-select: none;
  transition: all 0.3s ease;
}
.follow-btn.following {
  background: #888;
  box-shadow: inset 0 1px 5px #555;
  color: #ddd;
}
.follow-btn:hover:not(.following) {
  background-color: #d60040;
}

/* Algorithm indicator */
.algorithm-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(0, 149, 246, 0.9);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: bold;
}

/* Download progress indicator */
.download-progress {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(0, 0, 0, 0.8);
  color: white;
  padding: 15px 20px;
  border-radius: 8px;
  z-index: 10000;
  display: none;
}

@media (max-width: 600px) {
  .video-reel-item video {
    max-height: 500px;
  }
}
</style>
</head>
<body>

<div style="text-align: center; padding: 15px; background: #1e1e2f; border-bottom: 1px solid #ccc;margin-top: 50px;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
    <h2 style="color: #7b68ee;">Video Reels</h2>
    <p style="color: #ccc; font-size: 12px;">Personalized feed based on your interests and engagement</p>
</div>

<div class="reels-container" id="reelsContainer">
    <?php if (empty($posts)): ?>
        <div class="reel" style="text-align: center; padding: 40px;">
            <h3>No videos found</h3>
            <p>There are no videos to display. Upload some videos to see them here!</p>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="reel" data-post-id="<?= $post['id'] ?>">
                <div class="algorithm-indicator" title="Engagement Score: <?= number_format($post['engagement_score'], 2) ?>">
                    🔥 <?= number_format($post['engagement_score'], 1) ?>
                </div>
                
                <div class="reel-header">
                    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                    <div class="username"
                         onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'"><?= htmlspecialchars($post['username']) ?></div>
                    <?php if ($post['author_id'] !== $userId): ?>
                        <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
                            <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="reel-content" id="reel-content-<?= $post['id'] ?>">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
                <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
                <?php endif; ?>

                <!-- Video display -->
                <?php if ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    $videoCount = count($mediaArray);
                    ?>
                    <div class="video-reel-container" data-post-id="<?= $post['id'] ?>">
                        <div class="video-reel-scroller">
                            <?php foreach ($mediaArray as $index => $video): 
                                $video = trim($video);
                            ?>
                                <div class="video-reel-item" data-video-index="<?= $index ?>">
                                    <video <?= $index === 0 ? 'autoplay loop' : 'preload="none"' ?>>
                                        <source src="<?= htmlspecialchars($video) ?>" type="video/mp4" />
                                        Your browser does not support the video tag.
                                    </video>
                                    <div class="video-controls">
                                        <button class="sound-toggle" data-muted="false">🔊</button>
                                        <button class="download-btn" data-video-url="<?= htmlspecialchars($video) ?>" title="Download Video">💾</button>
                                    </div>
                                    <?php if ($videoCount > 1): ?>
                                        <div class="video-count-indicator"><?= ($index + 1) . '/' . $videoCount ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($videoCount > 1): ?>
                            <div class="video-pagination">
                                <?php for ($i = 0; $i < $videoCount; $i++): ?>
                                    <div class="video-pagination-dot <?= $i === 0 ? 'active' : '' ?>" data-video-index="<?= $i ?>"></div>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                      Like (<span class="like-count"><?= $post['likes_count'] ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                      Comment (<?= $post['comments_count'] ?>)
                    </span>
                    <span class="share-btn" onclick="alert('Share is coming soon!')">Share (<?= $post['shares_count'] ?>)</span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Download progress indicator -->
<div class="download-progress" id="downloadProgress">
    Downloading video...
</div>

<script>
// Show More / Show Less Toggle
document.querySelectorAll('.show-more-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const postId = btn.dataset.postId;
        const content = document.getElementById('reel-content-' + postId);
        if (content.classList.contains('expanded')) {
            content.classList.remove('expanded');
            btn.textContent = 'Show More';
        } else {
            content.classList.add('expanded');
            btn.textContent = 'Show Less';
        }
    });
});

// Like / Unlike Ajax
document.querySelectorAll('.like-btn').forEach(btn => {
   btn.addEventListener('click', async () => {
       const postId = btn.dataset.postId;
       const isLiked = btn.classList.contains('liked');
       const formData = new FormData();
       formData.append('action', isLiked ? 'unlike' : 'like');
       formData.append('post_id', postId);
       try {
           const response = await fetch('reel.php', {
               method: 'POST',
               body: formData
           });
           const data = await response.json();
           if (data.success) {
               const likeCountSpan = btn.querySelector('.like-count');
               likeCountSpan.textContent = data.likes_count;
               btn.classList.toggle('liked');
               
               // Update engagement score indicator
               const indicator = document.querySelector(`.reel[data-post-id="${postId}"] .algorithm-indicator`);
               if (indicator) {
                   const currentScore = parseFloat(indicator.textContent.match(/[\d.]+/)[0]);
                   const newScore = isLiked ? currentScore - 0.3 : currentScore + 0.3;
                   indicator.textContent = `🔥 ${newScore.toFixed(1)}`;
                   indicator.title = `Engagement Score: ${newScore.toFixed(2)}`;
               }
           } else {
               alert('Failed to update like status.');
           }
       } catch {
           alert('Error updating like status.');
       }
   });
});

// Follow/unfollow handlers
document.querySelectorAll('.follow-btn').forEach(button => {
    button.addEventListener('click', async (e) => {
        e.stopPropagation();
        const userId = button.getAttribute('data-user-id');
        if (!userId) return;

        const isFollowing = button.classList.contains('following');
        const action = isFollowing ? 'unfollow' : 'follow';

        const formData = new FormData();
        formData.append('action', action);
        formData.append('followed_id', userId);

        try {
            const res = await fetch('reel.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(btn => {
                    if (data.action === 'followed') {
                        btn.textContent = 'Following';
                        btn.classList.add('following');
                    } else {
                        btn.textContent = 'Follow';
                        btn.classList.remove('following');
                    }
                });
            } else {
                alert('Failed to update follow status.');
            }
        } catch {
            alert('Error updating follow status.');
        }
    });
});

// Video Reel functionality
document.querySelectorAll('.video-reel-container').forEach(container => {
    const videos = container.querySelectorAll('video');
    const videoItems = container.querySelectorAll('.video-reel-item');
    const paginationDots = container.querySelectorAll('.video-pagination-dot');
    const scroller = container.querySelector('.video-reel-scroller');
    
    // Set the width of each video item to match the container
    videoItems.forEach(item => {
        item.style.width = container.offsetWidth + 'px';
    });
    
    // Initialize first video - set to unmuted by default
    if (videos.length > 0) {
        const firstVideo = videos[0];
        firstVideo.muted = false; // Unmute by default
        
        firstVideo.addEventListener('loadedmetadata', () => {
            // Adjust container height based on video aspect ratio
            const aspectRatio = firstVideo.videoHeight / firstVideo.videoWidth;
            const containerWidth = container.offsetWidth;
            container.style.height = (containerWidth * aspectRatio) + 'px';
        });
        
        // Play the first video
        firstVideo.play().catch(e => console.log('Autoplay prevented:', e));
    }
    
    // Handle scroll to change active video
    let isScrolling = false;
    scroller.addEventListener('scroll', () => {
        if (isScrolling) return;
        
        isScrolling = true;
        setTimeout(() => {
            isScrolling = false;
        }, 100);
        
        const scrollPos = scroller.scrollLeft;
        const containerWidth = scroller.offsetWidth;
        const currentIndex = Math.round(scrollPos / containerWidth);
        
        // Update active video and pagination
        videoItems.forEach((item, index) => {
            if (index === currentIndex) {
                const video = item.querySelector('video');
                if (video) {
                    video.play().catch(e => console.log('Autoplay prevented:', e));
                }
                if (paginationDots[index]) {
                    paginationDots[index].classList.add('active');
                }
            } else {
                const video = item.querySelector('video');
                if (video) {
                    video.pause();
                    video.currentTime = 0;
                }
                if (paginationDots[index]) {
                    paginationDots[index].classList.remove('active');
                }
            }
        });
    });
    
    // Handle click on pagination dots
    paginationDots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            const containerWidth = scroller.offsetWidth;
            scroller.scrollTo({
                left: containerWidth * index,
                behavior: 'smooth'
            });
        });
    });
    
    // Handle video click to play/pause
    videos.forEach(video => {
        video.addEventListener('click', (e) => {
            e.stopPropagation();
            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        });
    });
    
    // Handle sound toggle - videos start unmuted by default
    container.querySelectorAll('.sound-toggle').forEach(button => {
        // Initialize button state based on video muted status
        const video = button.closest('.video-reel-item').querySelector('video');
        if (video) {
            button.setAttribute('data-muted', video.muted);
            button.textContent = video.muted ? '🔇' : '🔊';
        }
        
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const video = button.closest('.video-reel-item').querySelector('video');
            if (video) {
                video.muted = !video.muted;
                button.setAttribute('data-muted', video.muted);
                button.textContent = video.muted ? '🔇' : '🔊';
            }
        });
    });
    
    // Handle window resize to adjust video widths
    window.addEventListener('resize', () => {
        videoItems.forEach(item => {
            item.style.width = container.offsetWidth + 'px';
        });
        
        // Adjust container height based on current video aspect ratio
        const currentIndex = Math.round(scroller.scrollLeft / container.offsetWidth);
        const currentVideo = videos[currentIndex];
        if (currentVideo) {
            const aspectRatio = currentVideo.videoHeight / currentVideo.videoWidth;
            const containerWidth = container.offsetWidth;
            container.style.height = (containerWidth * aspectRatio) + 'px';
        }
    });
});

// Video download functionality
document.querySelectorAll('.download-btn').forEach(button => {
    button.addEventListener('click', async (e) => {
        e.stopPropagation();
        const videoUrl = button.getAttribute('data-video-url');
        
        if (!videoUrl) {
            alert('Video URL not found');
            return;
        }
        
        // Show download progress
        const progress = document.getElementById('downloadProgress');
        progress.style.display = 'block';
        
        try {
            // Fetch the video file
            const response = await fetch(videoUrl);
            if (!response.ok) {
                throw new Error('Failed to fetch video');
            }
            
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            
            // Create a temporary anchor element to trigger download
            const a = document.createElement('a');
            a.href = blobUrl;
            
            // Extract filename from URL or generate one
            const filename = videoUrl.split('/').pop() || 'video_' + Date.now() + '.mp4';
            a.download = filename;
            
            // Trigger download
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            // Clean up
            URL.revokeObjectURL(blobUrl);
            
            // Hide progress indicator
            progress.style.display = 'none';
            
        } catch (error) {
            console.error('Download failed:', error);
            alert('Failed to download video. Please try again.');
            progress.style.display = 'none';
        }
    });
});

// Video pause on scroll away functionality
function initVideoObservers() {
    // Get all video containers
    const videoContainers = document.querySelectorAll('.video-reel-container');
    
    videoContainers.forEach(container => {
        const videos = container.querySelectorAll('video');
        
        // Create Intersection Observer for each video container
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const containerVideos = entry.target.querySelectorAll('video');
                
                if (!entry.isIntersecting) {
                    // Pause all videos in this container when scrolled out of view
                    containerVideos.forEach(v => {
                        if (!v.paused) {
                            v.pause();
                        }
                    });
                } else {
                    // Play the current video when scrolled into view
                    const scroller = entry.target.querySelector('.video-reel-scroller');
                    if (scroller) {
                        const scrollPos = scroller.scrollLeft;
                        const containerWidth = scroller.offsetWidth;
                        const currentIndex = Math.round(scrollPos / containerWidth);
                        const currentVideo = containerVideos[currentIndex];
                        if (currentVideo && currentVideo.paused) {
                            currentVideo.play().catch(e => console.log('Autoplay prevented:', e));
                        }
                    }
                }
            });
        }, { 
            threshold: 0.5, // Trigger when 50% of container is visible
            rootMargin: '0px' // No margin around the viewport
        });
        
        observer.observe(container);
    });
}

// Initialize video observers when page loads
document.addEventListener('DOMContentLoaded', initVideoObservers);

// Reinitialize observers when reels container changes (for dynamic content)
const reelsContainer = document.getElementById('reelsContainer');
if (reelsContainer) {
    const observer = new MutationObserver(initVideoObservers);
    observer.observe(reelsContainer, { childList: true, subtree: true });
}

// Also reinitialize on window resize
window.addEventListener('resize', initVideoObservers);
</script>

</body>
</html>