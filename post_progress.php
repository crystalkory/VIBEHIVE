<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];

// Fetch boosted posts for the logged-in user
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.profile_pic_url, b.boost_end,
           (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS likes_count,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS comments_count
    FROM boost_posts b
    JOIN posts p ON b.post_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE b.boost_end > NOW() AND p.user_id = :user_id
    ORDER BY b.boost_end DESC
");
$stmt->execute(['user_id' => $userId]);
$boostedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalLikes = 0;
$totalComments = 0;
foreach ($boostedPosts as $post) {
    $totalLikes += (int)$post['likes_count'];
    $totalComments += (int)$post['comments_count'];
}
$totalInteractions = $totalLikes + $totalComments;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Boosted Posts Progress</title>
<style>
body {
    font-family: Arial, sans-serif;
    max-width: 900px;
    margin: 20px auto;
    background: #f8f9fa;
}
h1, h2 {
    color: #0069d9;
}
.post {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 6px #ccc;
    padding: 15px;
    margin-bottom: 20px;
    position: relative;
}
.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}
.post-header img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 10px;
}
.username {
    font-weight: bold;
    color: #007bff;
    cursor: pointer;
}
.post-media {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-gap: 6px;
    margin-top: 10px;
}
.post-media img {
    width: 100%;
    object-fit: cover;
    border-radius: 10px;
    height: 150px;
}
.post-media video {
    max-width: 100%;
    max-height: 150px;
    border-radius: 10px;
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
.boost-end {
    position: absolute;
    top: 15px;
    right: 15px;
    background: #28a745;
    color: white;
    padding: 5px 10px;
    border-radius: 12px;
    font-weight: bold;
    font-size: 12px;
}
.interactions {
    margin-top: 10px;
    font-weight: bold;
}
.comment-btn {
    margin-top: 15px;
    display: inline-block;
    padding: 8px 12px;
    background-color: #0069d9;
    color: white;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
}body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 900px;
    margin: 20px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    min-height: 100vh;
    padding: 20px;
}

h1 {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 10px;
    color: #fff;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    text-align: center;
}

h2 {
    font-size: 24px;
    font-weight: 700;
    margin: 25px 0 10px 0;
    color: #fff;
    text-align: center;
    opacity: 0.9;
}

h3 {
    font-size: 18px;
    font-weight: 600;
    margin: 15px 0 30px 0;
    color: rgba(255, 255, 255, 0.8);
    text-align: center;
    background: rgba(255, 255, 255, 0.1);
    padding: 12px 20px;
    border-radius: 15px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.post {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    padding: 25px;
    margin-bottom: 25px;
    position: relative;
    border: 2px solid rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
    animation: slideInUp 0.6s ease-out;
}

.post:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 40px rgba(123, 104, 238, 0.3);
    border-color: #7b68ee;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.post:nth-child(1) { animation-delay: 0.1s; }
.post:nth-child(2) { animation-delay: 0.2s; }
.post:nth-child(3) { animation-delay: 0.3s; }
.post:nth-child(4) { animation-delay: 0.4s; }

.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.post-header img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 15px;
    border: 3px solid #7b68ee;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    cursor: pointer;
    transition: all 0.3s ease;
}

.post-header img:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.username {
    font-weight: 700;
    color: #7b68ee;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.3s ease;
}

.username:hover {
    color: #6a5acd;
    text-shadow: 0 2px 8px rgba(123, 104, 238, 0.3);
}

.post-content {
    white-space: pre-wrap;
    line-height: 1.6;
    color: #2d3748;
    font-size: 15px;
    margin-bottom: 15px;
    padding: 15px;
    background: rgba(123, 104, 238, 0.05);
    border-radius: 12px;
    border-left: 4px solid #7b68ee;
}

.post-media {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-gap: 8px;
    margin-top: 15px;
    margin-bottom: 15px;
}

.post-media img {
    width: 100%;
    object-fit: cover;
    border-radius: 12px;
    height: 180px;
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid transparent;
}

.post-media img:hover {
    transform: scale(1.05);
    border-color: #7b68ee;
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.3);
}

.post-media video {
    max-width: 100%;
    max-height: 200px;
    border-radius: 12px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.post-media video:hover {
    border-color: #7b68ee;
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.3);
}

.overlay {
    position: absolute;
    top: 0; 
    left: 0;
    width: 100%; 
    height: 180px;
    background: linear-gradient(135deg, rgba(123, 104, 238, 0.8), rgba(106, 90, 205, 0.8));
    color: white;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 24px;
    font-weight: bold;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.overlay:hover {
    background: linear-gradient(135deg, rgba(123, 104, 238, 0.9), rgba(106, 90, 205, 0.9));
    transform: scale(1.02);
}

.boost-end {
    position: absolute;
    top: 20px;
    right: 20px;
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 12px;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
    z-index: 2;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.interactions {
    margin-top: 20px;
    font-weight: 700;
    font-size: 15px;
    color: #2d3748;
    background: rgba(123, 104, 238, 0.1);
    padding: 12px 18px;
    border-radius: 12px;
    display: flex;
    gap: 20px;
    justify-content: center;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

.interactions span {
    color: #7b68ee;
}

.comment-btn {
    margin-top: 20px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    border: none;
    cursor: pointer;
    font-size: 14px;
}

.comment-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
    text-decoration: none;
    color: white;
}

/* Stats Summary */
.stats-summary {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 30px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.stat-item {
    text-align: center;
    padding: 20px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    border-radius: 15px;
    color: white;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.stat-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.4);
}

.stat-number {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 8px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.stat-label {
    font-size: 14px;
    font-weight: 600;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
}

.empty-icon {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.7;
    color: #7b68ee;
}

.empty-title {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 15px;
    color: #2d3748;
}

.empty-text {
    font-size: 16px;
    color: #718096;
    margin-bottom: 30px;
}

.boost-now-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    border: none;
    cursor: pointer;
    font-size: 16px;
}

.boost-now-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.4);
    text-decoration: none;
    color: white;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 10px auto;
    }
    
    h1 {
        font-size: 28px;
    }
    
    h2 {
        font-size: 20px;
    }
    
    h3 {
        font-size: 16px;
        padding: 10px 15px;
    }
    
    .post {
        padding: 20px;
    }
    
    .post-media {
        grid-template-columns: 1fr;
    }
    
    .post-media img, .overlay {
        height: 200px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .interactions {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    .boost-end {
        position: relative;
        top: auto;
        right: auto;
        display: inline-block;
        margin-bottom: 15px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    .post {
        padding: 15px;
    }
    
    .post-header img {
        width: 50px;
        height: 50px;
    }
    
    .username {
        font-size: 16px;
    }
    
    .post-content {
        font-size: 14px;
        padding: 12px;
    }
    
    .empty-state {
        padding: 60px 15px;
    }
    
    .empty-icon {
        font-size: 64px;
    }
    
    .empty-title {
        font-size: 20px;
    }
}
.comment-btn:hover {
    background-color: #004a9f;
}
</style>
</head>
<body>

<h1>My Boosted Posts</h1>
<h2>Total Likes + Comments: <?= $totalInteractions ?></h2>
<h3>Total Likes: <?= $totalLikes ?> | Total Comments: <?= $totalComments ?></h3>

<?php if(count($boostedPosts) === 0): ?>
    <p>You have no active boosted posts.</p>
<?php else: ?>
    <?php foreach ($boostedPosts as $post): ?>
        <div class="post">
            <div class="post-header">
                <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'"><?= htmlspecialchars($post['username']) ?></div>
            </div>
            <div class="post-content" style="white-space: pre-wrap;">
                <?= nl2br(htmlspecialchars($post['content'])) ?>
            </div>

            <!-- Media display: photos or videos -->
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
                                <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                            <?php elseif ($index === 3 && $extraCount > 0): ?>
                                <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                                <div class="overlay" onclick="window.location='image_list.php?post_id=<?= $post['id'] ?>'">
                                    +<?= $extraCount ?>
                                </div>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($image) ?>" alt="Post Image" />
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($post['post_type'] === 'video'): ?>
                <?php foreach (explode(',', $post['media_url']) as $video): ?>
                    <video controls style="max-width: 100%; margin-top: 8px; border-radius: 8px;">
                        <source src="<?= htmlspecialchars(trim($video)) ?>" type="video/mp4" />
                        Your browser does not support the video tag.
                    </video>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="interactions">
                Likes: <?= (int)$post['likes_count'] ?> |
                Comments: <?= (int)$post['comments_count'] ?> |
                Total: <?= ((int)$post['likes_count'] + (int)$post['comments_count']) ?>
            </div>
            <div class="boost-end">
                Expires: <?= date('M j, Y H:i', strtotime($post['boost_end'])) ?>
            </div>
            <a href="comment.php?post_id=<?= $post['id'] ?>" class="comment-btn">View Comments</a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
