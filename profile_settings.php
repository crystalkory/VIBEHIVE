<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "footer.php";
require_once "config.php";

$currentUserId = $_SESSION['user_id'];

// Get current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $currentUserId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    die("User not found");
}

// Handle profile picture and cover photo uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    // Process profile picture if uploaded
    if (
        isset($_FILES['profile_pic']) 
        && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK 
        && in_array($_FILES['profile_pic']['type'], $allowedTypes)
    ) {
        $tmpName = $_FILES['profile_pic']['tmp_name'];
        $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $newName = uniqid('profile_') . '.' . $ext;
        $dest = $uploadDir . $newName;
        if (move_uploaded_file($tmpName, $dest)) {
            $stmt = $pdo->prepare("UPDATE users SET profile_pic_url = :pic WHERE id = :id");
            $stmt->execute(['pic' => 'uploads/' . $newName, 'id' => $currentUserId]);
            $currentUser['profile_pic_url'] = 'uploads/' . $newName;
            $successMessage = "Profile picture updated successfully!";
        }
    }

    // Process cover picture if uploaded
    if (
        isset($_FILES['cover_pic']) 
        && $_FILES['cover_pic']['error'] === UPLOAD_ERR_OK 
        && in_array($_FILES['cover_pic']['type'], $allowedTypes)
    ) {
        $tmpName = $_FILES['cover_pic']['tmp_name'];
        $ext = pathinfo($_FILES['cover_pic']['name'], PATHINFO_EXTENSION);
        $newName = uniqid('cover_') . '.' . $ext;
        $dest = $uploadDir . $newName;
        if (move_uploaded_file($tmpName, $dest)) {
            $stmt = $pdo->prepare("UPDATE users SET cover_pic_url = :pic WHERE id = :id");
            $stmt->execute(['pic' => 'uploads/' . $newName, 'id' => $currentUserId]);
            $currentUser['cover_pic_url'] = 'uploads/' . $newName;
            $successMessage = "Cover photo updated successfully!";
        }
    }
}

require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Profile Settings</title>
<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #333;
  line-height: 1.6;
  min-height: 100vh;
  padding: 20px;
}

.container {
  max-width: 800px;
  margin: 0 auto;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border-radius: 15px;
  padding: 30px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.header {
  text-align: center;
  margin-bottom: 30px;
}

.header h1 {
  color: #7b68ee;
  font-size: 32px;
  margin-bottom: 10px;
}

.header p {
  color: #666;
  font-size: 16px;
}

.settings-section {
  margin-bottom: 40px;
}

.settings-section h2 {
  color: #7b68ee;
  margin-bottom: 20px;
  font-size: 24px;
  border-bottom: 2px solid #7b68ee;
  padding-bottom: 10px;
}

.upload-container {
  display: flex;
  gap: 30px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.upload-item {
  flex: 1;
  min-width: 300px;
  text-align: center;
  padding: 20px;
  background: rgba(123, 104, 238, 0.1);
  border-radius: 15px;
  border: 2px dashed rgba(123, 104, 238, 0.3);
}

.current-image {
  width: 150px;
  height: 150px;
  border-radius: 50%;
  object-fit: cover;
  margin-bottom: 15px;
  border: 3px solid #7b68ee;
}

.cover-preview {
  width: 100%;
  height: 200px;
  object-fit: cover;
  border-radius: 10px;
  margin-bottom: 15px;
  border: 2px solid #7b68ee;
}

.upload-btn {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.3s ease;
  display: inline-block;
  margin-top: 10px;
}

.upload-btn:hover {
  background: linear-gradient(135deg, #6a5acd, #5d4fbb);
  transform: translateY(-2px);
}

input[type="file"] {
  display: none;
}

.success-message {
  background: linear-gradient(135deg, #48bb78, #38a169);
  color: white;
  padding: 15px;
  border-radius: 10px;
  margin-bottom: 20px;
  text-align: center;
  font-weight: 600;
}

.back-btn {
  display: inline-block;
  background: linear-gradient(135deg, #718096, #4a5568);
  color: white;
  padding: 12px 24px;
  border-radius: 8px;
  text-decoration: none;
  font-weight: 600;
  transition: all 0.3s ease;
  margin-top: 20px;
}

.back-btn:hover {
  background: linear-gradient(135deg, #4a5568, #2d3748);
  transform: translateY(-2px);
}

@media (max-width: 768px) {
  .container {
    padding: 20px;
  }
  
  .upload-container {
    flex-direction: column;
  }
  
  .upload-item {
    min-width: auto;
  }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Profile Settings</h1>
        <p>Manage your profile picture and cover photo</p>
    </div>

    <?php if (isset($successMessage)): ?>
        <div class="success-message">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="settings-section">
            <h2>Profile Picture</h2>
            <div class="upload-container">
                <div class="upload-item">
                    <h3>Current Profile Picture</h3>
                    <?php if ($currentUser['profile_pic_url']): ?>
                        <img src="<?= htmlspecialchars($currentUser['profile_pic_url']) ?>" alt="Current Profile Picture" class="current-image">
                    <?php else: ?>
                        <div style="width:150px; height:150px; background:#ddd; border-radius:50%; margin:0 auto 15px;"></div>
                    <?php endif; ?>
                    <label for="profile-pic" class="upload-btn">Choose New Profile Picture</label>
                    <input type="file" id="profile-pic" name="profile_pic" accept="image/*">
                </div>
            </div>
        </div>

        <div class="settings-section">
            <h2>Cover Photo</h2>
            <div class="upload-container">
                <div class="upload-item">
                    <h3>Current Cover Photo</h3>
                    <?php if ($currentUser['cover_pic_url']): ?>
                        <img src="<?= htmlspecialchars($currentUser['cover_pic_url']) ?>" alt="Current Cover Photo" class="cover-preview">
                    <?php else: ?>
                        <div style="width:100%; height:200px; background:#ddd; border-radius:10px; margin-bottom:15px;"></div>
                    <?php endif; ?>
                    <label for="cover-pic" class="upload-btn">Choose New Cover Photo</label>
                    <input type="file" id="cover-pic" name="cover_pic" accept="image/*">
                </div>
            </div>
        </div>

        <button type="submit" class="upload-btn" style="width: 100%; padding: 15px; font-size: 16px;">
            Save Changes
        </button>
    </form>

    <a href="profile.php?id=<?= $currentUserId ?>" class="back-btn">Back to Profile</a>
</div>

<script>
// Show file name when selected
document.getElementById('profile-pic').addEventListener('change', function(e) {
    const label = this.previousElementSibling;
    if (this.files.length > 0) {
        label.textContent = this.files[0].name;
    }
});

document.getElementById('cover-pic').addEventListener('change', function(e) {
    const label = this.previousElementSibling;
    if (this.files.length > 0) {
        label.textContent = this.files[0].name;
    }
});
</script>
</body>
</html>