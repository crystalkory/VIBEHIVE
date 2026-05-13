<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_SESSION['user_id'];
    $description = trim($_POST['description'] ?? '');
    
    $videoFile = $_FILES['video_file'] ?? null;
    $imageFiles = $_FILES['image_files'] ?? null;

    $uploadDir = __DIR__ . '/uploads/reels/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    // Validate either video or images but not both
    $hasVideo = $videoFile && $videoFile['error'] !== UPLOAD_ERR_NO_FILE;
    $hasImages = $imageFiles && isset($imageFiles['name']) && count(array_filter($imageFiles['name'])) > 0;

    if ($hasVideo && $hasImages) {
        $errors[] = "Please upload either a video or images, not both.";
    } elseif (!$hasVideo && !$hasImages) {
        $errors[] = "You must upload either a video or at least one image.";
    } else {
        // Handle video upload if any
        $videoPath = null;
        if ($hasVideo) {
            $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/ogg'];
            if ($videoFile['error'] === UPLOAD_ERR_OK && $videoFile['size'] <= 100 * 1024 * 1024 && in_array($videoFile['type'], $allowedVideoTypes)) {
                $ext = pathinfo($videoFile['name'], PATHINFO_EXTENSION);
                $videoPath = 'uploads/reels/' . uniqid('video_') . '.' . $ext;
                move_uploaded_file($videoFile['tmp_name'], __DIR__ . '/' . $videoPath);
            } else {
                $errors[] = "Invalid video upload (type or size). Max 100MB, mp4/webm/ogg only.";
            }
        }

        // Handle image uploads if any
        $imagePaths = [];
        if ($hasImages) {
            $allowedImageTypes = ['image/jpeg','image/png','image/gif'];
            for ($i=0; $i<count($imageFiles['name']); $i++) {
                if ($imageFiles['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($imageFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
                if (!in_array($imageFiles['type'][$i], $allowedImageTypes)) continue;

                $ext = pathinfo($imageFiles['name'][$i], PATHINFO_EXTENSION);
                $imageName = 'uploads/reels/' . uniqid('img_') . '.' . $ext;
                move_uploaded_file($imageFiles['tmp_name'][$i], __DIR__ . '/' . $imageName);
                $imagePaths[] = $imageName;
            }
            if (empty($imagePaths)) {
                $errors[] = "No valid images uploaded.";
            }
        }

        // Insert in database if no errors
        if (empty($errors)) {
            $insertSQL = "INSERT INTO reels (user_id, video_url, image_urls, description, created_at)
                          VALUES (:user_id, :video_url, :image_urls, :description, NOW())";
            $stmt = $pdo->prepare($insertSQL);
            $imageUrlsStr = $imagePaths ? implode(',', $imagePaths) : null;
            $stmt->execute([
                ':user_id' => $userId,
                ':video_url' => $videoPath,
                ':image_urls' => $imageUrlsStr,
                ':description' => $description,
            ]);
            $success = "Reel uploaded successfully.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Create Reel</title>
<style>
  body {
    font-family: Arial, sans-serif;
    max-width: 600px;
    margin: 30px auto;
    padding: 15px;
    background: #f8f8f8;
  }
  form {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 0 8px rgba(0,0,0,0.1);
  }
  label {
    display: block;
    margin-bottom: 6px;
    font-weight: bold;
  }
  input[type="file"], textarea {
    width: 100%;
    margin-bottom: 15px;
  }
  textarea {
    height: 80px;
    resize: vertical;
    padding: 8px;
  }
  button {
    background-color: #007bff;
    color: white;
    font-weight: 700;
    padding: 12px 18px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    width: 100%;
  }
  button:hover {
    background-color: #0056b3;
  }
  .error {
    color: #dc3545;
    margin-bottom: 10px;
  }
  .success {
    color: #28a745;
    margin-bottom: 10px;
  }
  .note {
    font-size: 0.9em;
    margin-bottom: 15px;
    color: #555;
  }
</style>
</head>
<body>

<h2>Create Reel</h2>

<?php if ($errors): ?>
  <div class="error"><?= implode('<br>', $errors) ?></div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="success"><?= $success ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="reelForm">
  <label for="video_file">Upload Video (Max 100MB, only one):</label>
  <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm,video/ogg">

  <label for="image_files">Upload Images (Multiple allowed, JPEG/PNG/GIF):</label>
  <input type="file" id="image_files" name="image_files[]" accept="image/jpeg,image/png,image/gif" multiple>

  <div class="note">Note: You can only upload either video or images, not both.</div>

  <label for="description">Short Description:</label>
  <textarea id="description" name="description" placeholder="Write a short description about your reel..."></textarea>

  <button type="submit">Submit Reel</button>
</form>

<script>
  const videoInput = document.getElementById('video_file');
  const imageInput = document.getElementById('image_files');

  function toggleUploadFields() {
    if (videoInput.files.length > 0) {
      imageInput.disabled = true;
    } else {
      imageInput.disabled = false;
    }
    if (imageInput.files.length > 0) {
      videoInput.disabled = true;
    } else {
      videoInput.disabled = false;
    }
  }

  videoInput.addEventListener('change', toggleUploadFields);
  imageInput.addEventListener('change', toggleUploadFields);
</script>

</body>
</html>
