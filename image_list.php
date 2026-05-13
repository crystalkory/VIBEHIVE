<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$postId = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
if ($postId <= 0) {
    die("Invalid post Id.");
}

// Fetch media_url from posts table
$stmt = $pdo->prepare("SELECT media_url FROM posts WHERE id = :post_id");
$stmt->execute(['post_id' => $postId]);
$mediaUrl = $stmt->fetchColumn();

if (!$mediaUrl) {
    die("Post not found or no images available.");
}

$mediaArray = array_filter(array_map('trim', explode(',', $mediaUrl)));

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Image List - Fbclone</title>
<style>
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 720px;
    margin: 20px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
    color: #333;
}

h1 {
    text-align: center;
    margin-bottom: 25px;
    color: white;
    font-size: 28px;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.image-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.image-item {
    cursor: pointer;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 2px solid #7b68ee;
    transition: all 0.3s ease;
}

.image-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
    border-color: #6a5acd;
}

.image-item img {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 13px;
    transition: transform 0.3s ease;
}

.image-item:hover img {
    transform: scale(1.02);
}

.back-link {
    display: inline-flex;
    align-items: center;
    margin: 10px 0 25px 0;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 25px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    transition: all 0.3s ease;
}

.back-link:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2);
}

/* Animation for image items */
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

.image-item {
    animation: fadeInUp 0.6s ease-out;
}

.image-item:nth-child(1) { animation-delay: 0.1s; }
.image-item:nth-child(2) { animation-delay: 0.2s; }
.image-item:nth-child(3) { animation-delay: 0.3s; }
.image-item:nth-child(4) { animation-delay: 0.4s; }
.image-item:nth-child(5) { animation-delay: 0.5s; }

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 10px auto;
    }
    
    h1 {
        font-size: 24px;
        margin-bottom: 20px;
    }
    
    .image-list {
        gap: 15px;
    }
    
    .image-item {
        border-radius: 12px;
    }
    
    .image-item img {
        border-radius: 10px;
    }
    
    .back-link {
        padding: 8px 16px;
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    h1 {
        font-size: 20px;
        margin-bottom: 15px;
    }
    
    .image-list {
        gap: 12px;
    }
    
    .back-link {
        padding: 6px 12px;
        font-size: 13px;
    }
}
</style>
</head>
<body>

<h1>Images of Post #<?= htmlspecialchars($postId) ?></h1>

<a href="home.php" class="back-link">← Back to Home</a>

<div class="image-list">
<?php foreach ($mediaArray as $imgUrl): ?>
    <div class="image-item" onclick="window.location.href='full_image.php?img=<?= urlencode($imgUrl) ?>'">
        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="Post Image" />
    </div>
<?php endforeach; ?>
</div>

</body>
</html>
