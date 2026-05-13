<?php
// make_post.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
// Handle edited media from editor
if (isset($_SESSION['edited_media']) && !empty($_SESSION['edited_media'])) {
    $editedMedia = $_SESSION['edited_media'];
    
    // You can pre-populate the form with edited media
    echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        alert('Edited media ready! You can find your files in the uploads folder.');
        // Here you could auto-populate the media fields
    });
    </script>";
    
    // Clear session after use
    unset($_SESSION['edited_media']);
}
$host = 'localhost';
$port = '5432';
$dbname = 'fbclone';
$user = 'postgres';
$password = 'Gi12,br12';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB error: " . $e->getMessage());
}

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
        header("Location: home.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error creating post: " . addslashes($e->getMessage()) . "');</script>";
    }
}
// Check for edited media from editor.php
if (isset($_SESSION['edited_media']) && !empty($_SESSION['edited_media'])) {
    // You can use these media files in your post
    $editedMedia = $_SESSION['edited_media'];
    // Clear the session after use
    unset($_SESSION['edited_media']);
    
    // You might want to display a message or pre-fill the media
    echo "<script>alert('Edited media ready for posting!');</script>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Create Post - Fbclone</title>
<style>
body { 
    font-family: Arial, sans-serif;
    max-width: 800px; 
    margin: 20px auto; 
    background: #1e1e2f; 
    color: white;
    padding: 20px;
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
    transition: background 0.3s ease;
}

.nav-links a:hover {
    background: #2c2c3d;
}

/* Post Creation Container */
.post-creation-container {
    background: #2c2c3d;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    border: 2px solid #7b68ee;
}

.user-header {
    display: flex;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #444;
}

.user-header img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #7b68ee;
    margin-right: 15px;
}

.user-info h2 {
    margin: 0;
    color: #7b68ee;
    font-size: 20px;
}

.user-info p {
    margin: 5px 0 0 0;
    color: #888;
    font-size: 14px;
}

/* Instagram-style Form */
.instagram-tab-container {
    display: flex;
    border-bottom: 1px solid #444;
    margin-bottom: 25px;
}

.instagram-tab {
    flex: 1;
    text-align: center;
    padding: 15px;
    cursor: pointer;
    font-weight: 600;
    color: #8e8e8e;
    border-bottom: 2px solid transparent;
    transition: all 0.3s ease;
}

.instagram-tab.active {
    color: #7b68ee;
    border-bottom: 2px solid #7b68ee;
}

.instagram-upload-area {
    border: 2px dashed #7b68ee;
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    margin-bottom: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: rgba(123, 104, 238, 0.1);
}

.instagram-upload-area:hover {
    background: rgba(123, 104, 238, 0.2);
    border-color: #9370db;
}

.instagram-upload-icon {
    font-size: 48px;
    color: #7b68ee;
    margin-bottom: 15px;
}

.instagram-upload-area p {
    margin: 0;
    font-size: 16px;
    color: #ccc;
}

.instagram-upload-area .subtext {
    font-size: 14px;
    color: #888;
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
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.instagram-preview-multi {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 15px;
}

.instagram-preview-multi-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
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
    border: 1px solid #444;
    border-radius: 8px;
    font-family: inherit;
    font-size: 18px;
    font-weight: 600;
    background: #1e1e2f;
    color: white;
    border: none;
    border-bottom: 2px solid #7b68ee;
}

.post-header-input:focus {
    outline: none;
    border-bottom-color: #9370db;
}

.post-header-input::placeholder {
    color: #888;
    font-weight: normal;
}

.instagram-form-controls textarea {
    width: 100%;
    padding: 15px;
    border: 1px solid #444;
    border-radius: 8px;
    resize: none;
    margin-bottom: 20px;
    font-family: inherit;
    font-size: 16px;
    background: #1e1e2f;
    color: white;
    min-height: 120px;
}

.instagram-form-controls textarea:focus {
    outline: none;
    border-color: #7b68ee;
}

.instagram-form-controls select {
    width: 100%;
    padding: 12px;
    border: 1px solid #444;
    border-radius: 8px;
    margin-bottom: 20px;
    background: #1e1e2f;
    color: white;
    font-size: 14px;
}

.instagram-form-controls select:focus {
    outline: none;
    border-color: #7b68ee;
}

.instagram-submit-btn {
    background: linear-gradient(45deg, #7b68ee, #9370db);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 15px;
    width: 100%;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.instagram-submit-btn:hover:not(:disabled) {
    background: linear-gradient(45deg, #6a5acd, #7b68ee);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.instagram-submit-btn:disabled {
    background: #555;
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
    border: 1px solid #444;
    border-radius: 8px;
    background: #1e1e2f;
    color: white;
    font-size: 14px;
}

.link-input:focus {
    outline: none;
    border-color: #7b68ee;
}

/* Categories Section */
.categories-section {
    margin-bottom: 25px;
    padding: 20px;
    background: rgba(123, 104, 238, 0.05);
    border-radius: 8px;
    border: 1px solid #444;
}

.categories-section h3 {
    margin: 0 0 15px 0;
    color: #7b68ee;
    font-size: 16px;
}

.categories-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.category-select {
    width: 100%;
    padding: 10px;
    border: 1px solid #444;
    border-radius: 6px;
    background: #1e1e2f;
    color: white;
    font-size: 14px;
}

.category-select:focus {
    outline: none;
    border-color: #7b68ee;
}

.category-note {
    font-size: 12px;
    color: #888;
    margin-top: 10px;
    text-align: center;
}

/* Hashtag Suggestions */
.hashtag-suggestions {
    position: absolute;
    background: #2c2c3d;
    border: 1px solid #444;
    border-radius: 8px;
    max-height: 150px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.hashtag-suggestion {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #444;
    transition: background 0.2s ease;
}

.hashtag-suggestion:hover {
    background: #7b68ee;
    color: white;
}

.hashtag-suggestion:last-child {
    border-bottom: none;
}

/* Back Button */
.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #444;
    color: white;
    border: none;
    border-radius: 20px;
    padding: 10px 20px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.3s ease;
    text-decoration: none;
}

.back-btn:hover {
    background: #555;
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
}

@media (min-width: 768px) {
    .categories-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
    }
}
/* Editor Button Styles */
.editor-btn {
    background: linear-gradient(45deg, #ff6b6b, #ee5a24);
    color: white;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: bold;
    transition: all 0.3s ease;
    display: inline-block;
}

.editor-btn:hover {
    background: linear-gradient(45deg, #ee5a24, #ff6b6b);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 107, 107, 0.4);
}
</style>
</head>
<body>

<!-- Navigation -->
<div class="nav-container">
    <a href="home.php" class="back-btn">← Back to Feed</a>
    <div class="nav-links">
        <a href="profile.php?id=<?= $currentUserId ?>">My Profile</a>
        <a href="home.php">Home</a>
        <a href="video.php">Videos</a>
        
        <a href="editor.php" class="editor-btn">🎬 Advanced Editor</a>
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

    <!-- Instagram-style Post Form -->
    <form method="POST" enctype="multipart/form-data" id="instagramPostForm">
        <input type="hidden" name="action" value="new_post">
        
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
        
        <!-- Video Upload Area -->
        <div id="videoUploadArea" class="instagram-upload-area" style="display: none;">
            <div class="instagram-upload-icon">🎬</div>
            <p>Select videos to share</p>
            <p class="subtext">Click to browse or drag and drop</p>
            <input type="file" name="media_files[]" accept="video/*" multiple style="display: none;" id="videoFileInput">
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
            <input type="text" name="post_header" class="post-header-input" placeholder="Write a catchy post header..." id="postHeader" maxlength="100">
        </div>
        
        <!-- Link Input -->
        <div class="link-input-container">
            <input type="url" name="link" class="link-input" placeholder="Paste a link (optional)">
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
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="category-note">Select up to 3 categories to help users discover your content</p>
        </div>
        
        <!-- Form Controls -->
        <div class="instagram-form-controls">
            <textarea name="content" placeholder="What's on your mind? Use #hashtags to reach more people..." id="postCaption"></textarea>
            
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

// Category selects
const categorySelects = document.querySelectorAll('.category-select');

// Current active tab
let activeTab = 'image';

// Popular hashtags for suggestions (you can fetch these from your database)
const popularHashtags = [
    'trending', 'viral', 'fyp', 'foryou', 'funny', 'comedy',
    'dance', 'music', 'food', 'cooking', 'gaming', 'beauty',
    'fashion', 'diy', 'craft', 'art', 'love', 'life'
];

// Tab switching
imageTab.addEventListener('click', () => switchTab('image'));
videoTab.addEventListener('click', () => switchTab('video'));
textTab.addEventListener('click', () => switchTab('text'));

function switchTab(tab) {
    activeTab = tab;
    
    // Update tab styles
    [imageTab, videoTab, textTab].forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    
    // Show/hide upload areas
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

// Setup hashtag functionality for both header and caption
setupHashtagInput(postHeader);
setupHashtagInput(postCaption);

// Form validation and submission
postForm.addEventListener('submit', (e) => {
    const hasHeader = postHeader.value.trim() !== '';
    const hasContent = postCaption.value.trim() !== '';
    const hasImageFiles = imageFileInput.files && imageFileInput.files.length > 0;
    const hasVideoFiles = videoFileInput.files && videoFileInput.files.length > 0;
    const hasMedia = hasImageFiles || hasVideoFiles;
    
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

// Update submit button state
function updateSubmitButton() {
    const hasHeader = postHeader.value.trim() !== '';
    const hasContent = postCaption.value.trim() !== '';
    const hasImageFiles = imageFileInput.files && imageFileInput.files.length > 0;
    const hasVideoFiles = videoFileInput.files && videoFileInput.files.length > 0;
    const hasMedia = hasImageFiles || hasVideoFiles;
    
    if (activeTab === 'text') {
        submitBtn.disabled = !(hasHeader || hasContent);
    } else {
        submitBtn.disabled = !(hasHeader || hasContent || hasMedia);
    }
}

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

// Auto-focus on header
setTimeout(() => {
    postHeader.focus();
}, 500);
</script>

</body>
</html>