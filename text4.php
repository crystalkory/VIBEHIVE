<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";
// AJAX handlers for FOLLOW/UNFOLLOW, POST, LIKE, UNLIKE
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

    // --- POST HANDLER ---
    if (isset($_POST['action']) && $_POST['action'] === 'new_post') {
        $userId = $_SESSION['user_id'];
        $content = trim($_POST['content'] ?? '');
        $privacy = $_POST['privacy'] ?? 'public';
        $groupId = ($privacy === 'groups-only' && !empty($_POST['group_id'])) ? (int)$_POST['group_id'] : null;
        $postType = 'text';
        $mediaUrls = [];

        $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/ogg'];
        $allowedPhotoTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxFileSize = 30 * 1024 * 1024;

        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        // Check if both images and videos were uploaded
        $hasImages = false;
        $hasVideos = false;
        
        if (!empty($_FILES['media_files']) && is_array($_FILES['media_files']['name'])) {
            for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
                $type = $_FILES['media_files']['type'][$i];
                if (in_array($type, $allowedPhotoTypes)) $hasImages = true;
                if (in_array($type, $allowedVideoTypes)) $hasVideos = true;
            }
            
            // Prevent mixed uploads
            if ($hasImages && $hasVideos) {
                echo json_encode(['success' => false, 'message' => 'Cannot upload both images and videos in the same post']);
                exit;
            }
            
            // Process uploads
            for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
                $name = $_FILES['media_files']['name'][$i];
                $tmpName = $_FILES['media_files']['tmp_name'][$i];
                $type = $_FILES['media_files']['type'][$i];
                $size = $_FILES['media_files']['size'][$i];
                if ($size > $maxFileSize) continue;
                if (in_array($type, array_merge($allowedPhotoTypes, $allowedVideoTypes))) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $filename = uniqid('media_') . '.' . $ext;
                    $destination = $uploadDir . $filename;
                    if (move_uploaded_file($tmpName, $destination)) {
                        $mediaUrls[] = 'uploads/' . $filename;
                        $postType = in_array($type, $allowedVideoTypes) ? 'video' : 'photo';
                    }
                }
            }
        }

        $link = trim($_POST['link'] ?? '');
        if ($link !== '') $postType = 'link';
        $mediaUrlStr = count($mediaUrls) ? implode(',', $mediaUrls) : null;

        $insertPost = $pdo->prepare("
            INSERT INTO posts (user_id, content, post_type, media_url, privacy_setting, group_id)
            VALUES (:user_id, :content, :post_type, :media_url, :privacy, :group_id)
        ");
        $insertPost->execute([
            ':user_id' => $userId,
            ':content' => $content,
            ':post_type' => $postType,
            ':media_url' => $mediaUrlStr,
            ':privacy' => $privacy,
            ':group_id' => $groupId,
        ]);
        header("Location: home.php"); // Redirect to avoid form resubmission
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

// Fetch groups user belongs to
$groupsStmt = $pdo->prepare("
    SELECT g.id, g.name FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'");
$groupsStmt->execute(['user_id' => $_SESSION['user_id']]);
$userGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

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

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

$postStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = :user_id AND gm.status = 'approved'
    WHERE 
      (p.privacy_setting = 'public')
      OR (p.privacy_setting = 'friends' AND EXISTS (
            SELECT 1 FROM friends f WHERE 
              ((f.user_id = :user_id AND f.friend_id = p.user_id) 
               OR (f.friend_id = :user_id AND f.user_id = p.user_id)) 
               AND f.status = 'accepted'))
      OR (p.privacy_setting = 'private' AND p.user_id = :user_id)
      OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
    ORDER BY p.created_at DESC
    LIMIT 50
");
$postStmt->execute(['user_id' => $userId]);
$posts = $postStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($posts as &$post) {
    $post['is_following'] = in_array($post['author_id'], $followedUserIds);
}
unset($post);

// Count unread messages
$unreadCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = :user_id AND read_at IS NULL");
    $stmt->execute(['user_id' => $userId]);
    $unreadCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // log error or ignore
}

$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';
$userId = $_SESSION['user_id'] ?? null;

// Fetch all active boosted posts globally, in random order
$boostedPostsStmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id, b.boost_end
    FROM boost_posts b
    JOIN posts p ON b.post_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE b.boost_end > NOW()
    ORDER BY random()
    LIMIT 50
");
$boostedPostsStmt->execute();
$boostedPosts = $boostedPostsStmt->fetchAll(PDO::FETCH_ASSOC);

$postCount = 0;
$boostCount = count($boostedPosts);
require_once "header.php";
require_once "footer.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Home</title>
<style>
header {
    background: #0069d9;
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
.posts {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.post {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 6px #ccc;
    padding: 15px;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
}
.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}
.post-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.post-header .username {
    margin-left: 10px;
    font-weight: bold;
    cursor: pointer;
    color: #007bff;
    flex-grow: 1;
}
.post-content {
    white-space: pre-wrap;
    max-height: 4.5em;
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
    margin-bottom: 10px;
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
    padding: 0;
    margin: 0 0 8px 0;
    user-select: none;
}
.post-media {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-gap: 6px;
    margin-bottom: 10px;
}
.post-media img {
    width: 100%;
    object-fit: cover;
    border-radius: 10px;
    cursor: pointer;
    height: 150px;
    position: relative;
}
.overlay {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 150px;
    background: rgba(0,0,0,0.6);
    color: white;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 24px;
    font-weight: bold;
    border-radius: 10px;
    cursor: pointer;
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
.sponsored-label {
    background: linear-gradient(45deg, #ffd700, #ff8c00);
    color: #000;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: bold;
    font-size: 12px;
    margin-bottom: 10px;
    display: inline-block;
}

/* Instagram-style modal */
.instagram-modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0; top: 0; width: 100vw; height: 100vh;
  overflow: auto;
  background-color: rgba(0,0,0,0.8);
}
.instagram-modal-content {
  background-color: #fff;
  margin: 50px auto;
  padding: 0;
  border-radius: 12px;
  max-width: 500px;
  box-shadow: 0 5px 15px rgba(0,0,0,0.3);
  position: relative;
  overflow: hidden;
}
.instagram-modal-header {
  padding: 15px;
  border-bottom: 1px solid #dbdbdb;
  text-align: center;
  position: relative;
  font-weight: 600;
}
.instagram-close-btn {
  position: absolute;
  right: 15px;
  top: 15px;
  font-size: 24px;
  border: none;
  background: none;
  cursor: pointer;
}
.instagram-modal-body {
  padding: 20px;
}
.instagram-tab-container {
  display: flex;
  border-bottom: 1px solid #dbdbdb;
  margin-bottom: 15px;
}
.instagram-tab {
  flex: 1;
    text-align: center;
    padding: 10px;
    cursor: pointer;
    font-weight: 600;
    color: #8e8e8e;
}
.instagram-tab.active {
  color: #0095f6;
  border-bottom: 2px solid #0095f6;
}
.instagram-upload-area {
  border: 2px dashed #dbdbdb;
  border-radius: 8px;
  padding: 30px;
  text-align: center;
  margin-bottom: 15px;
  cursor: pointer;
}
.instagram-upload-icon {
  font-size: 40px;
  color: #0095f6;
  margin-bottom: 10px;
}
.instagram-preview {
  display: none;
  margin-bottom: 15px;
  text-align: center;
}
.instagram-preview img, .instagram-preview video {
  max-width: 100%;
  max-height: 300px;
  border-radius: 8px;
}
.instagram-preview-multi {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 15px;
}
.instagram-preview-multi-item {
  position: relative;
  border-radius: 8px;
  overflow: hidden;
}
.instagram-preview-multi-item img, .instagram-preview-multi-item video {
  width: 100%;
  height: 120px;
  object-fit: cover;
}
.instagram-preview-count {
  position: absolute;
  top: 5px;
  right: 5px;
  background: rgba(0,0,0,0.7);
  color: white;
  border-radius: 50%;
  width: 25px;
  height: 25px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}
.instagram-form-controls {
  margin-top: 15px;
}
.instagram-form-controls textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid #dbdbdb;
  border-radius: 8px;
  resize: none;
  margin-bottom: 10px;
  font-family: inherit;
}
.instagram-form-controls select {
  width: 100%;
  padding: 8px;
  border: 1px solid #dbdbdb;
  border-radius: 8px;
  margin-bottom: 10px;
}
.instagram-submit-btn {
  background: #0095f6;
  color: white;
  border: none;
  border-radius: 8px;
  padding: 10px;
  width: 100%;
  font-weight: 600;
  cursor: pointer;
}
.instagram-submit-btn:disabled {
  background: #b2dffc;
  cursor: not-allowed;
}

/* Instagram-style video reels */
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
}
.sound-toggle {
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

@media (max-width: 600px) {
  .post-media {
    grid-template-columns: 1fr 1fr;
    grid-gap: 4px;
  }
  .post-media img, .overlay {
    height: 120px;
  }
  .instagram-modal-content {
    margin: 20px 10px;
    width: auto;
  }
  .video-reel-item video {
    max-height: 500px;
  }
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

</style>
</head>
<body>

<!-- Example link to logged-in user profile -->
<a href="profile.php?id=<?= urlencode($userId) ?>">My Profile</a>

<div style="text-align: center; padding: 15px; background: #fff; border-bottom: 1px solid #ccc;margin-top: 50px;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
</div>

<!-- Example link to logged-in user profile -->
<a href="profile.php?id=<?= urlencode($userId) ?>">My Profile</a>
<div id="openPostModalBtn" style="margin-top: 20px; cursor:pointer;"><input type="text" style="background-color: #ecececff;border: none;width: 100%;height: 30px;border-radius: 60px;" placeholder="What on your mind?"></div>
</div>
<div></div>
</div>

<!-- Instagram-style Post Modal -->
<div id="instagramModal" class="instagram-modal">
  <div class="instagram-modal-content">
    <div class="instagram-modal-header">
      Create new post
      <button class="instagram-close-btn" id="closeInstagramModalBtn">&times;</button>
    </div>
    <div class="instagram-modal-body">
      <div class="instagram-tab-container">
        <div class="instagram-tab active" data-tab="image">Image</div>
        <div class="instagram-tab" data-tab="video">Video</div>
      </div>
      
      <form method="POST" enctype="multipart/form-data" id="instagramPostForm">
        <input type="hidden" name="action" value="new_post">
        
        <div id="imageUploadArea" class="instagram-upload-area">
          <div class="instagram-upload-icon">📷</div>
          <p>Select photos to share</p>
          <input type="file" name="media_files[]" accept="image/*" multiple style="display: none;" id="imageFileInput">
        </div>
        
        <div id="videoUploadArea" class="instagram-upload-area" style="display: none;">
          <div class="instagram-upload-icon">🎬</div>
          <p>Select videos to share</p>
          <input type="file" name="media_files[]" accept="video/*" multiple style="display: none;" id="videoFileInput">
        </div>
        
        <div id="imagePreview" class="instagram-preview"></div>
        <div id="videoPreview" class="instagram-preview"></div>
        
        <div class="instagram-form-controls">
          <textarea name="content" placeholder="Write a caption..." id="postCaption"></textarea>
          
          <select name="privacy" id="privacySelect" required>
            <option value="public">Public (Everyone)</option>
            <option value="friends">Friends Only</option>
            <option value="private">Private (Only Me)</option>
            <option value="groups-only">Groups Only</option>
          </select>
          
          <select name="group_id" id="groupSelect" class="group-select">
            <option value="">-- Select Group --</option>
            <?php foreach ($userGroups as $group): ?>
              <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
            <?php endforeach; ?>
          </select>
          
          <button type="submit" class="instagram-submit-btn" id="instagramSubmitBtn" disabled>Share</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="posts" id="postsContainer">
    <?php foreach ($posts as $post): ?>
        <!-- Regular Post -->
        <div class="post" data-post-id="<?= $post['id'] ?>">
            <div class="post-header">
                <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                     alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                <div class="username"
                     onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'"><?= htmlspecialchars($post['username']) ?></div>
            </div>
                <?php if ($post['author_id'] !== $userId): ?>
        <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
            <?= $post['is_following'] ? 'Following' : 'Follow' ?>
        </button>
    <?php endif; ?>
            <div class="post-content" id="post-content-<?= $post['id'] ?>">
                <?= nl2br(htmlspecialchars($post['content'])) ?>
            </div>
            <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
                <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
            <?php endif; ?>

            <!-- Show all images/videos normal posts -->
            <?php if ($post['post_type'] === 'photo' && !empty($post['media_url'])):
                $mediaArray = explode(',', $post['media_url']);
                $mediaCount = count($mediaArray);
                $firstFour = array_slice($mediaArray, 0, 4);
                $extraCount = $mediaCount - 4;
                ?>
                <div class="post-media">
                    <?php foreach ($firstFour as $index => $image):
                        $image = trim($image);
                    ?>
                        <div style="position:relative;">
                            <?php if ($index < 3): ?>
                                <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                            <?php elseif ($index === 3 && $extraCount > 0): ?>
                                <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                                <div class="overlay" onclick="window.location='image_list.php?post_id=<?= $post['id'] ?>'">
                                    +<?= $extraCount ?>
                                </div>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
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
                                <video <?= $index === 0 ? 'muted loop' : 'preload="none"' ?>>
                                    <source src="<?= htmlspecialchars($video) ?>" type="video/mp4" />
                                    Your browser does not support the video tag.
                                </video>
                                <div class="video-controls">
                                    <button class="sound-toggle" data-muted="true">🔇</button>
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
            <?php elseif ($post['post_type'] === 'link' && !empty($post['media_url'])): ?>
                <div><a href="<?= htmlspecialchars($post['media_url']) ?>" target="_blank"><?= htmlspecialchars($post['media_url']) ?></a></div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="actions">
                <span class="like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                  Like (<span class="like-count"></span>)
                </span>
                <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                  Comment (<?= getCommentCount($pdo, $post['id']) ?>)
                </span>
                <span class="share-btn" onclick="alert('Share is coming soon!')">Share</span>
            </div>
        </div>

        <?php
        $postCount++;

        // Insert boosted post every 2 normal posts, cycling if needed
        if ($postCount % 2 === 0 && $boostCount > 0) {
            $boostPost = $boostedPosts[(int)(($postCount / 2 - 1) % $boostCount)];
            ?>
                        <div class="post boosted-post" data-post-id="<?= $boostPost['id'] ?>">
                <div class="sponsored-label">Sponsored</div>
                <div class="post-header">
                    <img src="<?= htmlspecialchars($boostPost['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'" />
                    <div class="username" onclick="window.location='profile.php?id=<?= $boostPost['user_id'] ?>'"><?= htmlspecialchars($boostPost['username']) ?> (Boosted)</div>
                    <div style="margin-left:auto; font-size:12px; color:#218838;">Boost expires at: <?= date('M j, Y H:i', strtotime($boostPost['boost_end'])) ?></div>
                    
                    <!-- FOLLOW BUTTON FOR BOOSTED POSTS -->
                    <?php if ($boostPost['author_id'] !== $userId): ?>
                        <button class="follow-btn <?= in_array($boostPost['author_id'], $followedUserIds) ? 'following' : '' ?>" data-user-id="<?= $boostPost['author_id'] ?>">
                            <?= in_array($boostPost['author_id'], $followedUserIds) ? 'Following' : 'Follow' ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="post-content" id="post-content-boost-<?= $boostPost['id'] ?>">
                    <?= nl2br(htmlspecialchars($boostPost['content'])) ?>
                </div>
                <?php if (mb_strlen(strip_tags($boostPost['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="boost-<?= $boostPost['id'] ?>">Show More</button>
                <?php endif; ?>

                <!-- Show all media in boosted posts (not just first one) -->
                <?php if ($boostPost['post_type'] === 'photo' && !empty($boostPost['media_url'])):
                    $mediaArray = explode(',', $boostPost['media_url']);
                    $mediaCount = count($mediaArray);
                    $firstFour = array_slice($mediaArray, 0, 4);
                    $extraCount = $mediaCount - 4;
                    ?>
                    <div class="post-media">
                        <?php foreach ($firstFour as $index => $image):
                            $image = trim($image);
                        ?>
                            <div style="position:relative;">
                                <?php if ($index < 3): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Boosted Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                                <?php elseif ($index === 3 && $extraCount > 0): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Boosted Image" />
                                    <div class="overlay" onclick="window.location='image_list.php?post_id=<?= $boostPost['id'] ?>'">
                                        +<?= $extraCount ?>
                                    </div>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Boosted Image" onclick="window.location='full_image.php?img=<?= urlencode($image) ?>'" />
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($boostPost['post_type'] === 'video' && !empty($boostPost['media_url'])): ?>
                    <?php
                    $mediaArray = explode(',', $boostPost['media_url']);
                    $videoCount = count($mediaArray);
                    ?>
                    <div class="video-reel-container" data-post-id="<?= $boostPost['id'] ?>">
                        <div class="video-reel-scroller">
                            <?php foreach ($mediaArray as $index => $video): 
                                $video = trim($video);
                            ?>
                                <div class="video-reel-item" data-video-index="<?= $index ?>">
                                    <video <?= $index === 0 ? 'muted loop' : 'preload="none"' ?>>
                                        <source src="<?= htmlspecialchars($video) ?>" type="video/mp4" />
                                        Your browser does not support the video tag.
                                    </video>
                                    <div class="video-controls">
                                        <button class="sound-toggle" data-muted="true">🔇</button>
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

                <!-- Actions for boosted post -->
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $userId, $boostPost['id']) ? 'liked' : '' ?>" data-post-id="<?= $boostPost['id'] ?>">
                      Like (<span class="like-count"></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $boostPost['id'] ?>'">
                      Comment (<?= getCommentCount($pdo, $boostPost['id']) ?>)
                    </span>
                    <span class="share-btn" onclick="alert('Share is coming soon!')">Share</span>
                </div>
            </div>            <?php
        }
        ?>
    <?php endforeach; ?>
</div>

<script>
// Show More / Show Less Toggle
document.querySelectorAll('.show-more-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const postId = btn.dataset.postId;
        const content = document.getElementById('post-content-' + postId);
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

// Click image to open full_image.php
document.querySelectorAll('.post-image').forEach(img => {
  img.style.cursor = 'pointer';
  img.addEventListener('click', () => {
    const fullImage = img.getAttribute('data-full-image');
    window.open(`full_image.php?img=${encodeURIComponent(fullImage)}`, '_blank');
  });
});

// Instagram-style modal scripting
const instagramModal = document.getElementById('instagramModal');
const openBtn = document.getElementById('openPostModalBtn');
const closeBtn = document.getElementById('closeInstagramModalBtn');
const privacySelect = document.getElementById('privacySelect');
const groupSelect = document.getElementById('groupSelect');
const imageTab = document.querySelector('[data-tab="image"]');
const videoTab = document.querySelector('[data-tab="video"]');
const imageUploadArea = document.getElementById('imageUploadArea');
const videoUploadArea = document.getElementById('videoUploadArea');
const imageFileInput = document.getElementById('imageFileInput');
const videoFileInput = document.getElementById('videoFileInput');
const imagePreview = document.getElementById('imagePreview');
const videoPreview = document.getElementById('videoPreview');
const submitBtn = document.getElementById('instagramSubmitBtn');
const postForm = document.getElementById('instagramPostForm');
const postCaption = document.getElementById('postCaption');

// Open modal
openBtn.addEventListener('click', () => {
  instagramModal.style.display = 'block';
  resetForm();
});

// Close modal
closeBtn.addEventListener('click', () => {
  instagramModal.style.display = 'none';
});

window.addEventListener('click', (event) => {
  if (event.target === instagramModal) {
    instagramModal.style.display = 'none';
  }
});

// Tab switching
imageTab.addEventListener('click', () => {
  imageTab.classList.add('active');
  videoTab.classList.remove('active');
  imageUploadArea.style.display = 'block';
  videoUploadArea.style.display = 'none';
  imagePreview.style.display = 'block';
  videoPreview.style.display = 'none';
});

videoTab.addEventListener('click', () => {
  videoTab.classList.add('active');
  imageTab.classList.remove('active');
  imageUploadArea.style.display = 'none';
  videoUploadArea.style.display = 'block';
  imagePreview.style.display = 'none';
  videoPreview.style.display = 'block';
});

// File upload handling
imageUploadArea.addEventListener('click', () => {
  imageFileInput.click();
});

videoUploadArea.addEventListener('click', () => {
  videoFileInput.click();
});

imageFileInput.addEventListener('change', (e) => {
  handleFileSelection(e.target.files, 'image');
});

videoFileInput.addEventListener('change', (e) => {
  handleFileSelection(e.target.files, 'video');
});

function handleFileSelection(files, type) {
  if (files.length === 0) return;
  
  // Clear previous preview
  const previewArea = type === 'image' ? imagePreview : videoPreview;
  previewArea.innerHTML = '';
  
  // Check if mixed content (shouldn't happen with separate inputs but just in case)
  if (type === 'image') {
    for (let i = 0; i < files.length; i++) {
      if (!files[i].type.startsWith('image/')) {
        alert('Please select only images');
        resetForm();
        return;
      }
    }
  } else {
    for (let i = 0; i < files.length; i++) {
      if (!files[i].type.startsWith('video/')) {
        alert('Please select only videos');
        resetForm();
        return;
      }
    }
  }
  
  // Show preview
  if (files.length === 1) {
    // Single file preview
    const file = files[0];
    const url = URL.createObjectURL(file);
    
    if (type === 'image') {
      previewArea.innerHTML = `<img src="${url}" alt="Preview">`;
    } else {
      previewArea.innerHTML = `<video controls autoplay muted><source src="${url}" type="${file.type}"></video>`;
    }
  } else {
    // Multiple files preview
    previewArea.innerHTML = '<div class="instagram-preview-multi"></div>';
    const multiContainer = previewArea.querySelector('.instagram-preview-multi');
    
    for (let i = 0; i < Math.min(files.length, 4); i++) {
      const file = files[i];
      const url = URL.createObjectURL(file);
      
      const item = document.createElement('div');
      item.className = 'instagram-preview-multi-item';
      
      if (type === 'image') {
        item.innerHTML = `<img src="${url}" alt="Preview">`;
      } else {
        item.innerHTML = `<video muted><source src="${url}" type="${file.type}"></video>`;
      }
      
      if (i === 3 && files.length > 4) {
        item.innerHTML += `<div class="instagram-preview-count">+${files.length - 4}</div>`;
      }
      
      multiContainer.appendChild(item);
    }
  }
  
  previewArea.style.display = 'block';
  submitBtn.disabled = false;
}

// Privacy setting change
privacySelect.addEventListener('change', () => {
  if(privacySelect.value === 'groups-only') {
    groupSelect.style.display = 'block';
  } else {
    groupSelect.style.display = 'none';
    groupSelect.value = '';
  }
});

// Form submission
postForm.addEventListener('submit', (e) => {
  // Additional validation if needed
  if (postCaption.value.trim() === '' && 
      (!imageFileInput.files || imageFileInput.files.length === 0) && 
      (!videoFileInput.files || videoFileInput.files.length === 0)) {
    e.preventDefault();
    alert('Please add a caption or media to your post');
    return;
  }
});

function resetForm() {
  imageFileInput.value = '';
  videoFileInput.value = '';
  imagePreview.innerHTML = '';
  videoPreview.innerHTML = '';
  imagePreview.style.display = 'none';
  videoPreview.style.display = 'none';
  postCaption.value = '';
  submitBtn.disabled = true;
  imageTab.classList.add('active');
  videoTab.classList.remove('active');
  imageUploadArea.style.display = 'block';
  videoUploadArea.style.display = 'none';
  groupSelect.style.display = 'none';
  groupSelect.value = '';
  privacySelect.value = 'public';
}

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
  
  // Initialize first video
  if (videos.length > 0) {
    const firstVideo = videos[0];
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
  
  // Handle sound toggle
  container.querySelectorAll('.sound-toggle').forEach(button => {
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
            const res = await fetch(window.location.href, { method: 'POST', body: formData });
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

function updateUnreadMessageCount() {
  fetch('fetch_unread_messages.php')
    .then(response => response.json())
    .then(data => {
      const badge = document.getElementById('message-unread-badge');
      if (data.unread_count && data.unread_count > 0) {
        badge.textContent = '+' + data.unread_count;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    })
    .catch(err => {
      console.error('Failed to fetch unread message count', err);
    });
}

// Call once on page load
updateUnreadMessageCount();

// Optionally poll every 10 seconds
setInterval(updateUnreadMessageCount, 10000);

// Video pause on scroll away functionality
let videoElements = [];
let videoObservers = [];

function initVideoObservers() {
    // Clear existing observers
    videoObservers.forEach(observer => observer.disconnect());
    videoObservers = [];
    videoElements = [];
    
    // Get all video containers
    const videoContainers = document.querySelectorAll('.video-reel-container');
    
    videoContainers.forEach(container => {
        const videos = container.querySelectorAll('video');
        videos.forEach(video => {
            videoElements.push(video);
        });
        
        // Create Intersection Observer for each video container
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) {
                    // Pause all videos in this container when scrolled out of view
                    const containerVideos = entry.target.querySelectorAll('video');
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
        }, { threshold: 0.5 }); // Trigger when 50% of container is visible
        
        observer.observe(container);
        videoObservers.push(observer);
    });
}

// Initialize video observers when page loads and when new content is added
document.addEventListener('DOMContentLoaded', initVideoObservers);

// Reinitialize observers when posts container changes (for dynamic content)
const postsContainer = document.getElementById('postsContainer');
if (postsContainer) {
    const observer = new MutationObserver(initVideoObservers);
    observer.observe(postsContainer, { childList: true, subtree: true });
}
</script>

</body>
</html>