<?php
// make_post.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Process search request
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchQuery = trim($_GET['search']);
    header("Location: search.php?q=" . urlencode($searchQuery));
    exit;
}

// Fetch groups user belongs to
$groupsStmt = $pdo->prepare("
    SELECT g.id, g.name FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'");
$groupsStmt->execute(['user_id' => $_SESSION['user_id']]);
$userGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user profile info
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$profilePicUrl = $userData['profile_pic_url'] ?: 'default_profile.png';
$username = $userData['username'];

// Define available categories
$categories = [
    'Entertainment',
    'Dance',
    'Lip-Sync',
    'Comedy',
    'Music',
    'Beauty and Fashion',
    'Food and Cooking',
    'DIY and Crafting',
    'Gaming'
];

// Initialize edited videos array in session if not exists
if (!isset($_SESSION['edited_videos'])) {
    $_SESSION['edited_videos'] = [];
}

// Handle video editing actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit_video') {
        // Set the video to be edited in editor_video.php
        $videoIndex = $_POST['video_index'];
        if (isset($_SESSION['edited_videos'][$videoIndex])) {
            $_SESSION['current_edit_video'] = $_SESSION['edited_videos'][$videoIndex];
            header("Location: editor_video.php");
            exit;
        }
    } elseif ($_POST['action'] === 'remove_video') {
        $videoIndex = $_POST['video_index'];
        if (isset($_SESSION['edited_videos'][$videoIndex])) {
            array_splice($_SESSION['edited_videos'], $videoIndex, 1);
            echo json_encode(['success' => true]);
            exit;
        }
    } elseif ($_POST['action'] === 'clear_all_videos') {
        $_SESSION['edited_videos'] = [];
        echo json_encode(['success' => true]);
        exit;
    }
}

// Check for newly edited video from editor
if (isset($_SESSION['edited_video']) && !empty($_SESSION['edited_video'])) {
    $newEditedVideo = $_SESSION['edited_video'];
    
    // Check if we already have this video (by filename)
    $videoExists = false;
    foreach ($_SESSION['edited_videos'] as $video) {
        if ($video['filename'] === $newEditedVideo['filename']) {
            $videoExists = true;
            break;
        }
    }
    
    // Add to edited videos array if not exists and limit to 5 videos
    if (!$videoExists) {
        if (count($_SESSION['edited_videos']) >= 5) {
            // Remove the oldest video (first in array)
            array_shift($_SESSION['edited_videos']);
        }
        $_SESSION['edited_videos'][] = $newEditedVideo;
    }
    
    // Clear the single edited video session
    unset($_SESSION['edited_video']);
    unset($_SESSION['current_edit_video']);
}

// POST HANDLER for new posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_post') {
    $userId = $_SESSION['user_id'];
    $postHeader = trim($_POST['post_header'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $privacy = $_POST['privacy'] ?? 'public';
    $groupId = ($privacy === 'groups-only' && !empty($_POST['group_id'])) ? (int)$_POST['group_id'] : null;
    $postType = 'text';
    $mediaUrls = [];

    // Get categories from form
    $category1 = !empty($_POST['category1']) ? $_POST['category1'] : null;
    $category2 = !empty($_POST['category2']) ? $_POST['category2'] : null;
    $category3 = !empty($_POST['category3']) ? $_POST['category3'] : null;

    $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/ogg'];
    $allowedPhotoTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxFileSize = 30 * 1024 * 1024;

    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    // Check for selected edited videos
    if (!empty($_POST['selected_videos']) && is_array($_POST['selected_videos'])) {
        foreach ($_POST['selected_videos'] as $videoPath) {
            // Validate that the video exists in our session
            foreach ($_SESSION['edited_videos'] as $video) {
                if ($video['path'] === $videoPath) {
                    $mediaUrls[] = $videoPath;
                    $postType = 'video';
                    break;
                }
            }
        }
    }

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
            echo "<script>alert('Cannot upload both images and videos in the same post');</script>";
        } else {
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
    }

    $link = trim($_POST['link'] ?? '');
    if ($link !== '') $postType = 'link';
    $mediaUrlStr = count($mediaUrls) ? implode(',', $mediaUrls) : null;

    try {
        $pdo->beginTransaction();
        
        // Insert post
        $insertPost = $pdo->prepare("
            INSERT INTO posts (user_id, post_header, content, post_type, media_url, privacy_setting, group_id, category1, category2, category3)
            VALUES (:user_id, :post_header, :content, :post_type, :media_url, :privacy, :group_id, :category1, :category2, :category3)
            RETURNING id
        ");
        $insertPost->execute([
            ':user_id' => $userId,
            ':post_header' => $postHeader,
            ':content' => $content,
            ':post_type' => $postType,
            ':media_url' => $mediaUrlStr,
            ':privacy' => $privacy,
            ':group_id' => $groupId,
            ':category1' => $category1,
            ':category2' => $category2,
            ':category3' => $category3
        ]);
        $postId = $insertPost->fetchColumn();
        
        // Process hashtags from both post header and content
        $combinedText = $postHeader . ' ' . $content;
        preg_match_all('/#(\w+)/', $combinedText, $matches);
        $hashtags = array_unique($matches[1]);
        
        foreach ($hashtags as $tag) {
            if (strlen($tag) > 2) {
                // Insert or update hashtag
                $stmt = $pdo->prepare("
                    INSERT INTO hashtags (tag) VALUES (?) 
                    ON CONFLICT (tag) DO UPDATE SET usage_count = hashtags.usage_count + 1
                    RETURNING id
                ");
                $stmt->execute([strtolower($tag)]);
                $hashtagId = $stmt->fetchColumn();
                
                // Link hashtag to post
                $stmt = $pdo->prepare("INSERT INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)");
                $stmt->execute([$postId, $hashtagId]);
            }
        }
        
        $pdo->commit();
        
        // Clear selected videos after successful post
        if (!empty($_POST['selected_videos'])) {
            foreach ($_POST['selected_videos'] as $videoPath) {
                // Remove from session
                foreach ($_SESSION['edited_videos'] as $index => $video) {
                    if ($video['path'] === $videoPath) {
                        array_splice($_SESSION['edited_videos'], $index, 1);
                        break;
                    }
                }
            }
        }
        
        header("Location: video.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error creating post: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Create Post - Fbclone</title>
<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 800px; 
    margin: 20px auto; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    padding: 20px;
    min-height: 100vh;
}

/* Navigation */
.nav-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid #7b68ee;
}

.nav-links {
    display: flex;
    gap: 20px;
}

.nav-links a {
    color: #7b68ee;
    text-decoration: none;
    font-weight: bold;
    padding: 8px 16px;
    border-radius: 20px;
    transition: all 0.3s ease;
    background: rgba(255, 255, 255, 0.9);
}

.nav-links a:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
}

/* Post Creation Container */
.post-creation-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.user-header {
    display: flex;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(123, 104, 238, 0.2);
}

.user-header img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #7b68ee;
    margin-right: 15px;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.user-info h2 {
    margin: 0;
    color: #2d3748;
    font-size: 20px;
    font-weight: 700;
}

.user-info p {
    margin: 5px 0 0 0;
    color: #718096;
    font-size: 14px;
}

/* Edited Videos Section */
.edited-videos-section {
    margin-bottom: 25px;
    padding: 20px;
    background: rgba(123, 104, 238, 0.05);
    border-radius: 15px;
    border: 1px solid rgba(123, 104, 238, 0.2);
    backdrop-filter: blur(10px);
}

.edited-videos-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.edited-videos-header h3 {
    margin: 0;
    color: #2d3748;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.edited-videos-count {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.3);
}

.clear-all-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(255, 107, 107, 0.3);
}

.clear-all-btn:hover {
    background: linear-gradient(135deg, #ee5a52, #dd4941);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
}

.edited-videos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.edited-video-item {
    background: rgba(255, 255, 255, 0.9);
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid transparent;
    transition: all 0.3s ease;
    position: relative;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.edited-video-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.edited-video-item.selected {
    border-color: #48bb78;
    box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.3);
}

.edited-video-preview {
    position: relative;
    height: 120px;
    overflow: hidden;
}

.edited-video-preview video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.edited-video-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.edited-video-item:hover .edited-video-overlay {
    opacity: 1;
}

.edited-video-actions {
    display: flex;
    gap: 8px;
}

.edit-video-btn, .remove-video-btn {
    background: rgba(255,255,255,0.9);
    border: none;
    border-radius: 6px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 11px;
    font-weight: bold;
    transition: all 0.3s ease;
}

.edit-video-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
}

.edit-video-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
}

.remove-video-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}

.remove-video-btn:hover {
    background: linear-gradient(135deg, #ee5a52, #dd4941);
    transform: translateY(-2px);
}

.edited-video-info {
    padding: 12px;
}

.edited-video-info h4 {
    margin: 0 0 5px 0;
    font-size: 12px;
    color: #2d3748;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 600;
}

.edited-video-meta {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: #718096;
}

.edited-video-checkbox {
    position: absolute;
    top: 8px;
    left: 8px;
    width: 20px;
    height: 20px;
    border-radius: 4px;
    border: 2px solid #fff;
    background: rgba(0,0,0,0.5);
    cursor: pointer;
    z-index: 2;
    transition: all 0.3s ease;
}

.edited-video-item.selected .edited-video-checkbox {
    background: #48bb78;
    border-color: #48bb78;
}

.edited-video-item.selected .edited-video-checkbox::after {
    content: '✓';
    color: white;
    font-size: 12px;
    font-weight: bold;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

.no-videos-message {
    text-align: center;
    padding: 30px;
    color: #718096;
    font-style: italic;
}

.video-selection-info {
    font-size: 12px;
    color: #718096;
    text-align: center;
    margin-top: 10px;
}

/* Instagram-style Form */
.instagram-tab-container {
    display: flex;
    border-bottom: 1px solid rgba(123, 104, 238, 0.2);
    margin-bottom: 25px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    padding: 5px;
}

.instagram-tab {
    flex: 1;
    text-align: center;
    padding: 15px;
    cursor: pointer;
    font-weight: 600;
    color: #718096;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.instagram-tab.active {
    color: #7b68ee;
    background: rgba(123, 104, 238, 0.1);
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.2);
}

.instagram-upload-area {
    border: 2px dashed #7b68ee;
    border-radius: 15px;
    padding: 40px 20px;
    text-align: center;
    margin-bottom: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: rgba(123, 104, 238, 0.05);
    backdrop-filter: blur(10px);
}

.instagram-upload-area:hover {
    background: rgba(123, 104, 238, 0.1);
    border-color: #6a5acd;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.2);
}

.instagram-upload-icon {
    font-size: 48px;
    color: #7b68ee;
    margin-bottom: 15px;
}

.instagram-upload-area p {
    margin: 0;
    font-size: 16px;
    color: #2d3748;
    font-weight: 600;
}

.instagram-upload-area .subtext {
    font-size: 14px;
    color: #718096;
    margin-top: 8px;
}

.instagram-preview {
    display: none;
    margin-bottom: 25px;
    text-align: center;
}

.instagram-preview img, .instagram-preview video {
    max-width: 100%;
    max-height: 400px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.instagram-preview-multi {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 15px;
}

.instagram-preview-multi-item {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
}

.instagram-preview-multi-item:hover {
    transform: scale(1.05);
}

.instagram-preview-multi-item img, .instagram-preview-multi-item video {
    width: 100%;
    height: 150px;
    object-fit: cover;
}

.instagram-preview-count {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(0,0,0,0.7);
    color: white;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
}

.instagram-form-controls {
    margin-top: 25px;
}

/* Post Header Input */
.post-header-container {
    margin-bottom: 20px;
}

.post-header-input {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 12px;
    font-family: inherit;
    font-size: 18px;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    border-bottom: 2px solid #7b68ee;
    transition: all 0.3s ease;
}

.post-header-input:focus {
    outline: none;
    border-bottom-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

.post-header-input::placeholder {
    color: #a0aec0;
    font-weight: normal;
}

.instagram-form-controls textarea {
    width: 100%;
    padding: 15px;
    border: 1px solid rgba(123, 104, 238, 0.3);
    border-radius: 12px;
    resize: none;
    margin-bottom: 20px;
    font-family: inherit;
    font-size: 16px;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    min-height: 120px;
    transition: all 0.3s ease;
}

.instagram-form-controls textarea:focus {
    outline: none;
    border-color: #7b68ee;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

.instagram-form-controls select {
    width: 100%;
    padding: 12px;
    border: 1px solid rgba(123, 104, 238, 0.3);
    border-radius: 12px;
    margin-bottom: 20px;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    font-size: 14px;
    transition: all 0.3s ease;
}

.instagram-form-controls select:focus {
    outline: none;
    border-color: #7b68ee;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

.instagram-submit-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    border-radius: 12px;
    padding: 15px;
    width: 100%;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.instagram-submit-btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

.instagram-submit-btn:disabled {
    background: #a0aec0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Link Input */
.link-input-container {
    margin-bottom: 20px;
}

.link-input {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid rgba(123, 104, 238, 0.3);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    font-size: 14px;
    transition: all 0.3s ease;
}

.link-input:focus {
    outline: none;
    border-color: #7b68ee;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

/* Categories Section */
.categories-section {
    margin-bottom: 25px;
    padding: 20px;
    background: rgba(123, 104, 238, 0.05);
    border-radius: 15px;
    border: 1px solid rgba(123, 104, 238, 0.2);
    backdrop-filter: blur(10px);
}

.categories-section h3 {
    margin: 0 0 15px 0;
    color: #2d3748;
    font-size: 16px;
    font-weight: 600;
}

.categories-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.category-select {
    width: 100%;
    padding: 12px;
    border: 1px solid rgba(123, 104, 238, 0.3);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    font-size: 14px;
    transition: all 0.3s ease;
}

.category-select:focus {
    outline: none;
    border-color: #7b68ee;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

.category-note {
    font-size: 12px;
    color: #718096;
    margin-top: 10px;
    text-align: center;
}

/* Hashtag Suggestions */
.hashtag-suggestions {
    position: absolute;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(123, 104, 238, 0.3);
    border-radius: 12px;
    max-height: 150px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.hashtag-suggestion {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid rgba(123, 104, 238, 0.1);
    transition: all 0.3s ease;
    color: #2d3748;
}

.hashtag-suggestion:hover {
    background: rgba(123, 104, 238, 0.1);
    color: #7b68ee;
}

.hashtag-suggestion:last-child {
    border-bottom: none;
}

/* Back Button */
.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    border: none;
    border-radius: 20px;
    padding: 10px 20px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.back-btn:hover {
    background: rgba(123, 104, 238, 0.1);
    color: #7b68ee;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
}

/* Success Toast */
.success-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    padding: 15px 20px;
    border-radius: 12px;
    box-shadow: 0 8px 25px rgba(72, 187, 120, 0.4);
    z-index: 1000;
    transform: translateX(150%);
    transition: transform 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 300px;
    backdrop-filter: blur(10px);
}

.success-toast.show {
    transform: translateX(0);
}

.success-toast i {
    font-size: 20px;
}

/* Editor Button Styles */
.editor-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a24);
    color: white;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: bold;
    transition: all 0.3s ease;
    display: inline-block;
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
}

.editor-btn:hover {
    background: linear-gradient(135deg, #ee5a24, #dd4941);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(255, 107, 107, 0.6);
}

/* Animation for page load */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.post-creation-container {
    animation: fadeInUp 0.6s ease-out;
}

/* Responsive */
@media (max-width: 600px) {
    body {
        padding: 10px;
        margin: 10px auto;
    }
    
    .post-creation-container {
        padding: 20px;
    }
    
    .nav-container {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .user-header {
        flex-direction: column;
        text-align: center;
    }
    
    .user-header img {
        margin-right: 0;
        margin-bottom: 10px;
    }
    
    .instagram-preview-multi {
        grid-template-columns: 1fr;
    }
    
    .instagram-preview-multi-item img, 
    .instagram-preview-multi-item video {
        height: 200px;
    }
    
    .categories-grid {
        grid-template-columns: 1fr;
    }
    
    .post-header-input {
        font-size: 16px;
        padding: 12px;
    }
    
    .edited-videos-grid {
        grid-template-columns: 1fr;
    }
    
    .instagram-tab-container {
        flex-direction: column;
        gap: 5px;
    }
}

@media (min-width: 768px) {
    .categories-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
    }
    
    .edited-videos-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1024px) {
    .edited-videos-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<!-- Success Toast -->
<div class="success-toast" id="successToast">
    <i class="fas fa-check-circle"></i>
    <span id="toastMessage">Edited video loaded successfully!</span>
</div>

<!-- Navigation -->
<div class="nav-container">
    <a href="video.php" class="back-btn">← Back to Feed</a>
    <div class="nav-links">
        <a href="profile.php?id=<?= $currentUserId ?>">My Profile</a>
        <a href="video.php">Home</a>
        <a href="video.php">Videos</a>
        <a href="editor_video.php" class="editor-btn">🎬 Advanced Editor</a>
    </div>
</div>

<!-- Post Creation Container -->
<div class="post-creation-container">
    <!-- User Header -->
    <div class="user-header">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="Profile Picture">
        <div class="user-info">
            <h2><?= htmlspecialchars($username) ?></h2>
            <p>Create a new post</p>
        </div>
    </div>

    <!-- Edited Videos Section -->
    <div class="edited-videos-section">
        <div class="edited-videos-header">
            <h3>
                <i class="fas fa-video"></i>
                Your Edited Videos
                <span class="edited-videos-count" id="videoCount"><?= count($_SESSION['edited_videos']) ?></span>
            </h3>
            <?php if (!empty($_SESSION['edited_videos'])): ?>
            <button type="button" class="clear-all-btn" onclick="clearAllVideos()">
                <i class="fas fa-trash"></i> Clear All
            </button>
            <?php endif; ?>
        </div>
        
        <div class="edited-videos-grid" id="editedVideosGrid">
            <?php if (empty($_SESSION['edited_videos'])): ?>
                <div class="no-videos-message">
                    <i class="fas fa-video-slash" style="font-size: 32px; margin-bottom: 10px;"></i>
                    <p>No edited videos yet</p>
                    <p style="font-size: 12px; margin-top: 5px;">Use the Advanced Editor to create amazing videos!</p>
                </div>
            <?php else: ?>
                <?php foreach ($_SESSION['edited_videos'] as $index => $video): ?>
                <div class="edited-video-item" data-video-index="<?= $index ?>">
                    <div class="edited-video-checkbox" onclick="toggleVideoSelection(this)"></div>
                    <div class="edited-video-preview">
                        <video muted>
                            <source src="<?= htmlspecialchars($video['path']) ?>" type="video/mp4">
                        </video>
                        <div class="edited-video-overlay">
                            <div class="edited-video-actions">
                                <button type="button" class="edit-video-btn" onclick="editVideo(<?= $index ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="remove-video-btn" onclick="removeVideo(<?= $index ?>)">
                                    <i class="fas fa-times"></i> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="edited-video-info">
                        <h4><?= htmlspecialchars($video['filename']) ?></h4>
                        <div class="edited-video-meta">
                            <span>Edited Video</span>
                            <span><?= date('M j, g:i A', strtotime($video['created_at'] ?? 'now')) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($_SESSION['edited_videos'])): ?>
        <p class="video-selection-info">
            <i class="fas fa-info-circle"></i>
            Select videos to include in your post (click the checkbox)
        </p>
        <?php endif; ?>
    </div>

    <!-- Instagram-style Post Form -->
    <form method="POST" enctype="multipart/form-data" id="instagramPostForm">
        <input type="hidden" name="action" value="new_post">
        
        <!-- Hidden inputs for selected videos -->
        <div id="selectedVideosInputs"></div>
        
        <div class="instagram-tab-container">
            <div class="instagram-tab active" data-tab="image">📷 Photos</div>
            <div class="instagram-tab" data-tab="video">🎬 Videos</div>
            <div class="instagram-tab" data-tab="text">📝 Text Only</div>
        </div>
        
        <!-- Image Upload Area -->
        <div id="imageUploadArea" class="instagram-upload-area">
            <div class="instagram-upload-icon">📷</div>
            <p>Select photos to share</p>
            <p class="subtext">Click to browse or drag and drop</p>
            <input type="file" name="media_files[]" accept="image/*" multiple style="display: none;" id="imageFileInput">
        </div>
        
        <!-- Text Only Area -->
        <div id="textOnlyArea" class="instagram-upload-area" style="display: none;">
            <div class="instagram-upload-icon">📝</div>
            <p>Create a text post</p>
            <p class="subtext">Share your thoughts with the community</p>
        </div>
        
        <!-- Media Previews -->
        <div id="imagePreview" class="instagram-preview"></div>
        <div id="videoPreview" class="instagram-preview"></div>
        
        <!-- Post Header Input -->
        <div class="post-header-container">
            <input type="text" name="post_header" class="post-header-input" placeholder="Write a catchy post header..." id="postHeader" maxlength="50">
        </div>
        

        
        <!-- Categories Section -->
        <div class="categories-section">
            <h3>🎯 Post Categories (Optional)</h3>
            <div class="categories-grid">
                <select name="category1" class="category-select">
                    <option value="">Select Category 1</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="category2" class="category-select">
                    <option value="">Select Category 2</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="category3" class="category-select">
                    <option value="">Select Category 3</option>

                </select>
            </div>
            <p class="category-note">Select up to 3 categories to help users discover your content</p>
        </div>
        
        <!-- Form Controls -->
        <div class="instagram-form-controls">
            <textarea name="content" placeholder="What's on your mind? Use #hashtags to reach more people..." id="postCaption" maxlength="100"></textarea>
            
            <select name="privacy" id="privacySelect" required>
                <option value="public">🌍 Public (Everyone)</option>
                <option value="friends">👥 Friends Only</option>
                <option value="private">🔒 Private (Only Me)</option>
                <option value="groups-only">👪 Groups Only</option>
            </select>
            
            <select name="group_id" id="groupSelect" class="group-select" style="display: none;">
                <option value="">-- Select Group --</option>
                <?php foreach ($userGroups as $group): ?>
                    <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="instagram-submit-btn" id="instagramSubmitBtn">Share Post</button>
        </div>
    </form>
</div>

<!-- Hashtag Suggestions Container -->
<div id="hashtagSuggestions" class="hashtag-suggestions"></div>

<script>
// DOM Elements
const imageTab = document.querySelector('[data-tab="image"]');
const videoTab = document.querySelector('[data-tab="video"]');
const textTab = document.querySelector('[data-tab="text"]');
const imageUploadArea = document.getElementById('imageUploadArea');
const videoUploadArea = document.getElementById('videoUploadArea');
const textOnlyArea = document.getElementById('textOnlyArea');
const imageFileInput = document.getElementById('imageFileInput');
const videoFileInput = document.getElementById('videoFileInput');
const imagePreview = document.getElementById('imagePreview');
const videoPreview = document.getElementById('videoPreview');
const submitBtn = document.getElementById('instagramSubmitBtn');
const postForm = document.getElementById('instagramPostForm');
const postHeader = document.getElementById('postHeader');
const postCaption = document.getElementById('postCaption');
const privacySelect = document.getElementById('privacySelect');
const groupSelect = document.getElementById('groupSelect');
const hashtagSuggestions = document.getElementById('hashtagSuggestions');
const successToast = document.getElementById('successToast');
const toastMessage = document.getElementById('toastMessage');
const editedVideosGrid = document.getElementById('editedVideosGrid');
const videoCount = document.getElementById('videoCount');
const selectedVideosInputs = document.getElementById('selectedVideosInputs');

// Current active tab
let activeTab = 'image';
let selectedVideos = new Set();

// Show success toast if we have videos
if (<?= !empty($_SESSION['edited_videos']) ? 'true' : 'false'; ?>) {
    setTimeout(() => {
        showSuccessToast('Your edited videos are ready to share!');
    }, 500);
}

function showSuccessToast(message) {
    if (successToast && toastMessage) {
        toastMessage.textContent = message;
        successToast.classList.add('show');
        setTimeout(() => {
            successToast.classList.remove('show');
        }, 3000);
    }
}

// Tab switching - FIXED VERSION
imageTab.addEventListener('click', () => switchTab('image'));
videoTab.addEventListener('click', () => switchTab('video'));
textTab.addEventListener('click', () => switchTab('text'));

function switchTab(tab) {
    activeTab = tab;
    
    // Update tab styles
    [imageTab, videoTab, textTab].forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    
    // Show/hide upload areas - FIXED: Properly show/hide all areas
    imageUploadArea.style.display = tab === 'image' ? 'block' : 'none';
    videoUploadArea.style.display = tab === 'video' ? 'block' : 'none';
    textOnlyArea.style.display = tab === 'text' ? 'block' : 'none';
    
    // Clear previews when switching away
    if (tab !== 'image') {
        imagePreview.style.display = 'none';
        imagePreview.innerHTML = '';
        imageFileInput.value = '';
    }
    if (tab !== 'video') {
        videoPreview.style.display = 'none';
        videoPreview.innerHTML = '';
        videoFileInput.value = '';
    }
    
    updateSubmitButton();
}

// Video Management Functions
function toggleVideoSelection(checkbox) {
    const videoItem = checkbox.closest('.edited-video-item');
    const videoIndex = videoItem.dataset.videoIndex;
    
    if (videoItem.classList.contains('selected')) {
        videoItem.classList.remove('selected');
        selectedVideos.delete(videoIndex);
    } else {
        videoItem.classList.add('selected');
        selectedVideos.add(videoIndex);
    }
    
    updateSelectedVideosInputs();
    updateSubmitButton();
}

function updateSelectedVideosInputs() {
    selectedVideosInputs.innerHTML = '';
    selectedVideos.forEach(videoIndex => {
        const video = <?= json_encode($_SESSION['edited_videos'] ?? []) ?>[videoIndex];
        if (video) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_videos[]';
            input.value = video.path;
            selectedVideosInputs.appendChild(input);
        }
    });
}

function editVideo(videoIndex) {
    const formData = new FormData();
    formData.append('action', 'edit_video');
    formData.append('video_index', videoIndex);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            window.location.href = 'editor_video.php';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error opening video for editing');
    });
}

function removeVideo(videoIndex) {
    if (!confirm('Are you sure you want to remove this edited video?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'remove_video');
    formData.append('video_index', videoIndex);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove from UI
            const videoItem = document.querySelector(`[data-video-index="${videoIndex}"]`);
            if (videoItem) {
                videoItem.remove();
            }
            
            // Update count
            updateVideoCount();
            selectedVideos.delete(videoIndex.toString());
            updateSelectedVideosInputs();
            updateSubmitButton();
            
            showSuccessToast('Video removed successfully');
            
            // Show empty message if no videos left
            if (editedVideosGrid.children.length === 0) {
                editedVideosGrid.innerHTML = `
                    <div class="no-videos-message">
                        <i class="fas fa-video-slash" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <p>No edited videos yet</p>
                        <p style="font-size: 12px; margin-top: 5px;">Use the Advanced Editor to create amazing videos!</p>
                    </div>
                `;
            }
        } else {
            alert('Error removing video');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error removing video');
    });
}

function clearAllVideos() {
    if (!confirm('Are you sure you want to remove all edited videos? This action cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'clear_all_videos');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Clear UI
            editedVideosGrid.innerHTML = `
                <div class="no-videos-message">
                    <i class="fas fa-video-slash" style="font-size: 32px; margin-bottom: 10px;"></i>
                    <p>No edited videos yet</p>
                    <p style="font-size: 12px; margin-top: 5px;">Use the Advanced Editor to create amazing videos!</p>
                </div>
            `;
            
            // Update count
            updateVideoCount();
            selectedVideos.clear();
            updateSelectedVideosInputs();
            updateSubmitButton();
            
            showSuccessToast('All videos cleared successfully');
        } else {
            alert('Error clearing videos');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error clearing videos');
    });
}

function updateVideoCount() {
    const videoItems = document.querySelectorAll('.edited-video-item');
    const actualCount = videoItems.length;
    videoCount.textContent = actualCount;
}

// File upload handling
imageUploadArea.addEventListener('click', () => imageFileInput.click());
videoUploadArea.addEventListener('click', () => videoFileInput.click());

imageFileInput.addEventListener('change', (e) => handleFileSelection(e.target.files, 'image'));
videoFileInput.addEventListener('change', (e) => handleFileSelection(e.target.files, 'video'));

function handleFileSelection(files, type) {
    if (files.length === 0) return;
    
    const previewArea = type === 'image' ? imagePreview : videoPreview;
    previewArea.innerHTML = '';
    
    // Validate file types
    for (let i = 0; i < files.length; i++) {
        if (type === 'image' && !files[i].type.startsWith('image/')) {
            alert('Please select only images');
            resetForm();
            return;
        }
        if (type === 'video' && !files[i].type.startsWith('video/')) {
            alert('Please select only videos');
            resetForm();
            return;
        }
    }
    
    // Handle single file
    if (files.length === 1) {
        const file = files[0];
        const url = URL.createObjectURL(file);
        
        if (type === 'image') {
            previewArea.innerHTML = `<img src="${url}" alt="Preview">`;
        } else {
            previewArea.innerHTML = `<video controls autoplay muted><source src="${url}" type="${file.type}"></video>`;
        }
    } 
    // Handle multiple files
    else {
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
    updateSubmitButton();
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

// Category validation - prevent duplicate categories
const categorySelects = document.querySelectorAll('.category-select');
categorySelects.forEach((select, index) => {
    select.addEventListener('change', () => {
        const selectedValues = Array.from(categorySelects).map(s => s.value).filter(v => v);
        const duplicates = selectedValues.filter((value, i, arr) => arr.indexOf(value) !== i);
        
        if (duplicates.length > 0) {
            alert('Please select different categories for each field.');
            select.value = '';
        }
    });
});

// Hashtag functionality
function setupHashtagInput(inputElement) {
    inputElement.addEventListener('input', (e) => {
        const cursorPosition = e.target.selectionStart;
        const text = e.target.value;
        const textBeforeCursor = text.substring(0, cursorPosition);
        
        // Find the last # symbol before cursor
        const lastHashIndex = textBeforeCursor.lastIndexOf('#');
        
        if (lastHashIndex !== -1) {
            const textAfterHash = textBeforeCursor.substring(lastHashIndex + 1);
            
            // Check if we're still typing the hashtag (no space after #)
            if (!textAfterHash.includes(' ')) {
                showHashtagSuggestions(textAfterHash, inputElement, lastHashIndex);
                return;
            }
        }
        
        // Hide suggestions if not typing a hashtag
        hideHashtagSuggestions();
    });
    
    // Hide suggestions when clicking away
    inputElement.addEventListener('blur', () => {
        setTimeout(hideHashtagSuggestions, 200);
    });
}

function showHashtagSuggestions(currentText, inputElement, hashPosition) {
    if (!currentText) {
        // Show popular hashtags when just # is typed
        displaySuggestions(popularHashtags, inputElement, hashPosition);
        return;
    }
    
    // Filter hashtags based on current input
    const filteredHashtags = popularHashtags.filter(tag => 
        tag.toLowerCase().startsWith(currentText.toLowerCase())
    );
    
    if (filteredHashtags.length > 0) {
        displaySuggestions(filteredHashtags, inputElement, hashPosition);
    } else {
        hideHashtagSuggestions();
    }
}

function displaySuggestions(hashtags, inputElement, hashPosition) {
    hashtagSuggestions.innerHTML = '';
    
    hashtags.slice(0, 5).forEach(tag => {
        const suggestion = document.createElement('div');
        suggestion.className = 'hashtag-suggestion';
        suggestion.textContent = `#${tag}`;
        suggestion.addEventListener('click', () => {
            insertHashtag(tag, inputElement, hashPosition);
        });
        hashtagSuggestions.appendChild(suggestion);
    });
    
    // Position the suggestions
    const rect = inputElement.getBoundingClientRect();
    hashtagSuggestions.style.display = 'block';
    hashtagSuggestions.style.top = `${rect.bottom + window.scrollY}px`;
    hashtagSuggestions.style.left = `${rect.left + window.scrollX}px`;
    hashtagSuggestions.style.width = `${rect.width}px`;
}

function hideHashtagSuggestions() {
    hashtagSuggestions.style.display = 'none';
}

function insertHashtag(tag, inputElement, hashPosition) {
    const currentValue = inputElement.value;
    const textBeforeHash = currentValue.substring(0, hashPosition);
    const textAfterInsertion = currentValue.substring(hashPosition).replace(/^#\w*/, `#${tag} `);
    
    inputElement.value = textBeforeHash + textAfterInsertion;
    hideHashtagSuggestions();
    inputElement.focus();
    
    // Set cursor position after the inserted hashtag
    const newCursorPosition = hashPosition + tag.length + 2;
    inputElement.setSelectionRange(newCursorPosition, newCursorPosition);
}

// Popular hashtags for suggestions
const popularHashtags = [
    'trending', 'viral', 'fyp', 'foryou', 'funny', 'comedy',
    'dance', 'music', 'food', 'cooking', 'gaming', 'beauty',
    'fashion', 'diy', 'craft', 'art', 'love', 'life'
];

// Setup hashtag functionality for both header and caption
setupHashtagInput(postHeader);
setupHashtagInput(postCaption);

// Update submit button state
function updateSubmitButton() {
    const hasHeader = postHeader.value.trim() !== '';
    const hasContent = postCaption.value.trim() !== '';
    const hasImageFiles = imageFileInput.files && imageFileInput.files.length > 0;
    const hasVideoFiles = videoFileInput.files && videoFileInput.files.length > 0;
    const hasSelectedVideos = selectedVideos.size > 0;
    const hasMedia = hasImageFiles || hasVideoFiles || hasSelectedVideos;
    
    if (activeTab === 'text') {
        submitBtn.disabled = !(hasHeader || hasContent);
    } else {
        submitBtn.disabled = !(hasHeader || hasContent || hasMedia);
    }
}

// Form validation and submission
postForm.addEventListener('submit', (e) => {
    const hasHeader = postHeader.value.trim() !== '';
    const hasContent = postCaption.value.trim() !== '';
    const hasImageFiles = imageFileInput.files && imageFileInput.files.length > 0;
    const hasVideoFiles = videoFileInput.files && videoFileInput.files.length > 0;
    const hasSelectedVideos = selectedVideos.size > 0;
    const hasMedia = hasImageFiles || hasVideoFiles || hasSelectedVideos;
    
    if (!hasHeader && !hasContent && !hasMedia) {
        e.preventDefault();
        alert('Please add a header, caption, or media to your post');
        return;
    }
    
    if (activeTab === 'image' && hasVideoFiles) {
        e.preventDefault();
        alert('Please switch to video tab for video uploads');
        return;
    }
    
    if (activeTab === 'video' && hasImageFiles) {
        e.preventDefault();
        alert('Please switch to image tab for image uploads');
        return;
    }
    
    // Validate categories - no duplicates
    const selectedCategories = Array.from(categorySelects)
        .map(select => select.value)
        .filter(value => value !== '');
    
    const uniqueCategories = [...new Set(selectedCategories)];
    if (selectedCategories.length !== uniqueCategories.length) {
        e.preventDefault();
        alert('Please select different categories for each field.');
        return;
    }
    
    // Show loading state
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sharing...';
});

// Real-time validation
postHeader.addEventListener('input', updateSubmitButton);
postCaption.addEventListener('input', updateSubmitButton);

// Drag and drop functionality
function setupDragAndDrop(area, input) {
    area.addEventListener('dragover', (e) => {
        e.preventDefault();
        area.style.background = 'rgba(123, 104, 238, 0.3)';
        area.style.borderColor = '#9370db';
    });
    
    area.addEventListener('dragleave', (e) => {
        e.preventDefault();
        area.style.background = 'rgba(123, 104, 238, 0.1)';
        area.style.borderColor = '#7b68ee';
    });
    
    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.style.background = 'rgba(123, 104, 238, 0.1)';
        area.style.borderColor = '#7b68ee';
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            input.files = files;
            const event = new Event('change');
            input.dispatchEvent(event);
        }
    });
}

// Initialize drag and drop
setupDragAndDrop(imageUploadArea, imageFileInput);
setupDragAndDrop(videoUploadArea, videoFileInput);

// Reset form function
function resetForm() {
    imageFileInput.value = '';
    videoFileInput.value = '';
    imagePreview.innerHTML = '';
    videoPreview.innerHTML = '';
    imagePreview.style.display = 'none';
    videoPreview.style.display = 'none';
    postHeader.value = '';
    postCaption.value = '';
    groupSelect.style.display = 'none';
    groupSelect.value = '';
    privacySelect.value = 'public';
    categorySelects.forEach(select => select.value = '');
    hideHashtagSuggestions();
    switchTab('image');
    updateSubmitButton();
}

// Initialize form
updateSubmitButton();
updateVideoCount();

// Auto-focus on header
setTimeout(() => {
    postHeader.focus();
}, 500);

// Clear edited video session when leaving page
window.addEventListener('beforeunload', function() {
    if (selectedVideos.size > 0) {
        // Send a request to clear the session if user leaves without posting
        fetch('clear_edited_video.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            keepalive: true // Ensure the request completes even if page is unloading
        });
    }
});
</script>

</body>
</html>