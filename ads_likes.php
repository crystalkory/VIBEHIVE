<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$currentUserId = $_SESSION['user_id'];
$adId = isset($_GET['ad_id']) ? (int)$_GET['ad_id'] : 0;

// Fetch ad details and verify ownership
$adStmt = $pdo->prepare("
    SELECT a.*, u.username as advertiser_name 
    FROM ads a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.id = ? AND a.user_id = ?
");
$adStmt->execute([$adId, $currentUserId]);
$ad = $adStmt->fetch(PDO::FETCH_ASSOC);

if (!$ad) {
    die("Advertisement not found or you don't have permission to view this page.");
}

// Fetch users who liked this ad
$likesStmt = $pdo->prepare("
    SELECT al.*, u.id as user_id, u.username, u.profile_pic_url, u.created_at as user_joined
    FROM ad_likes al 
    JOIN users u ON al.user_id = u.id 
    WHERE al.ad_id = ? 
    ORDER BY al.created_at DESC
");
$likesStmt->execute([$adId]);
$likes = $likesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get total like count
$totalLikes = count($likes);

// Format time difference function
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

// Get current user profile pic
$userStmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$userStmt->execute([$currentUserId]);
$userProfilePic = $userStmt->fetchColumn() ?: 'default_profile.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ad Likes - <?= htmlspecialchars($ad['header']) ?></title>
    <style>
      * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    line-height: 1.6;
    min-height: 100vh;
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.back-btn {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #667eea;
    border: none;
    padding: 12px 24px;
    border-radius: 25px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.back-btn:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    color: #764ba2;
    text-decoration: none;
}

.page-title {
    font-size: 28px;
    font-weight: 800;
    background: linear-gradient(135deg, #ffffff, #e2e8f0);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.ad-info {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 25px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-left: 5px solid #ffd700;
    transition: all 0.3s ease;
}

.ad-info:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.ad-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    flex-wrap: wrap;
    gap: 10px;
}

.ad-label {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    color: #000;
    padding: 6px 15px;
    border-radius: 20px;
    font-weight: 800;
    font-size: 12px;
    box-shadow: 0 2px 8px rgba(255, 215, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.3);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ad-title {
    font-size: 20px;
    font-weight: 800;
    color: #2d3748;
    line-height: 1.3;
}

.ad-info p {
    color: #4a5568;
    line-height: 1.5;
    font-size: 15px;
}

.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 25px 0;
}

.stat-item {
    text-align: center;
    padding: 25px 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.stat-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    background: white;
}

.stat-number {
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 8px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.stat-label {
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.likes-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.section-title {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 25px;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.likes-list {
    max-height: 600px;
    overflow-y: auto;
    padding-right: 10px;
}

.like-item {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border-radius: 15px;
    margin-bottom: 10px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
}

.like-item:hover {
    background: rgba(255, 255, 255, 0.95);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    border-bottom-color: transparent;
}

.like-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.user-avatar {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(102, 126, 234, 0.3);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.user-avatar:hover {
    transform: scale(1.1);
    border-color: #667eea;
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
}

.user-info {
    flex: 1;
}

.user-name {
    font-weight: 700;
    color: #2d3748;
    font-size: 17px;
    margin-bottom: 8px;
    text-decoration: none;
    display: block;
    transition: all 0.2s ease;
}

.user-name:hover {
    color: #667eea;
    text-decoration: underline;
}

.like-time {
    color: #718096;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: 600;
}

.user-stats {
    display: flex;
    gap: 20px;
    margin-top: 8px;
}

.user-stat {
    font-size: 12px;
    color: #718096;
    font-weight: 600;
    padding: 4px 8px;
    background: rgba(102, 126, 234, 0.1);
    border-radius: 8px;
}

.no-likes {
    text-align: center;
    padding: 60px 40px;
    color: #718096;
    font-style: italic;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 15px;
    margin: 20px 0;
}

.no-likes h3 {
    color: #2d3748;
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 10px;
}

.no-likes p {
    font-size: 15px;
    line-height: 1.5;
    margin-bottom: 8px;
}

.like-count-badge {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(245, 101, 101, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.filter-options {
    display: flex;
    gap: 12px;
    margin-bottom: 25px;
    flex-wrap: wrap;
}

.filter-btn {
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    color: #666;
    border: 1px solid rgba(0, 0, 0, 0.1);
    padding: 10px 20px;
    border-radius: 20px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.filter-btn.active {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.filter-btn:hover {
    background: rgba(255, 255, 255, 0.9);
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    color: #667eea;
}

.filter-btn.active:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
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

.container > * {
    animation: fadeInUp 0.6s ease-out;
}

.container > *:nth-child(1) { animation-delay: 0.1s; }
.container > *:nth-child(2) { animation-delay: 0.2s; }
.container > *:nth-child(3) { animation-delay: 0.3s; }
.container > *:nth-child(4) { animation-delay: 0.4s; }

.like-item {
    animation: fadeInUp 0.5s ease-out;
}

.like-item:nth-child(1) { animation-delay: 0.5s; }
.like-item:nth-child(2) { animation-delay: 0.6s; }
.like-item:nth-child(3) { animation-delay: 0.7s; }
.like-item:nth-child(4) { animation-delay: 0.8s; }
.like-item:nth-child(5) { animation-delay: 0.9s; }

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .page-title {
        font-size: 24px;
    }
    
    .stats-summary {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .stat-item {
        padding: 20px 15px;
    }
    
    .stat-number {
        font-size: 32px;
    }
    
    .like-item {
        flex-direction: column;
        text-align: center;
        gap: 15px;
        padding: 25px 20px;
    }
    
    .user-info {
        text-align: center;
    }
    
    .user-stats {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .filter-options {
        justify-content: center;
    }
    
    .likes-section {
        padding: 25px 20px;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .page-title {
        font-size: 22px;
    }
    
    .ad-info {
        padding: 20px;
    }
    
    .ad-title {
        font-size: 18px;
    }
    
    .stats-summary {
        gap: 12px;
    }
    
    .stat-item {
        padding: 18px 15px;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .likes-section {
        padding: 20px 15px;
    }
    
    .like-item {
        padding: 20px 15px;
    }
    
    .user-avatar {
        width: 60px;
        height: 60px;
    }
    
    .filter-btn {
        padding: 8px 16px;
        font-size: 12px;
    }
}

/* Custom scrollbar */
.likes-list::-webkit-scrollbar {
    width: 6px;
}

.likes-list::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
}

.likes-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 3px;
}

.likes-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
}

/* Loading state for buttons */
.filter-btn:active {
    transform: scale(0.95);
    transition: transform 0.1s ease;
}

/* Focus styles for accessibility */
.filter-btn:focus, .back-btn:focus, .user-name:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

/* Text selection */
::selection {
    background: rgba(102, 126, 234, 0.3);
    color: #2d3748;
}

/* Additional utility classes */
.user-engagement {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: rgba(102, 126, 234, 0.1);
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    color: #667eea;
}

/* Hover effects for interactive elements */
.user-name, .back-btn, .filter-btn {
    position: relative;
    overflow: hidden;
}

.user-name::after, .back-btn::after, .filter-btn::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 0;
    height: 2px;
    background: currentColor;
    transition: width 0.3s ease;
}

.user-name:hover::after, .back-btn:hover::after, .filter-btn:hover::after {
    width: 100%;
}

/* Remove underline from back button */
.back-btn::after {
    display: none;
}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="active_ads_countdown.php" class="back-btn">← Back to Ads</a>
            <h1 class="page-title">Ad Likes</h1>
        </div>

        <!-- Ad Information -->
        <div class="ad-info">
            <div class="ad-header">
                <span class="ad-label">Sponsored</span>
                <h2 class="ad-title"><?= htmlspecialchars($ad['header']) ?></h2>
            </div>
            <p style="color: #ccc; margin-top: 10px;"><?= htmlspecialchars($ad['description']) ?></p>
        </div>

        <!-- Stats Summary -->
        <div class="stats-summary">
            <div class="stat-item">
                <div class="stat-number"><?= $totalLikes ?></div>
                <div class="stat-label">Total Likes</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= count(array_unique(array_column($likes, 'user_id'))) ?></div>
                <div class="stat-label">Unique Users</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">
                    <?= $totalLikes > 0 ? timeAgo($likes[0]['created_at']) : 'N/A' ?>
                </div>
                <div class="stat-label">Last Like</div>
            </div>
        </div>

        <!-- Likes Section -->
        <div class="likes-section">
            <h2 class="section-title">
                Users Who Liked This Ad
                <span class="like-count-badge"><?= $totalLikes ?></span>
            </h2>

            <?php if (empty($likes)): ?>
                <div class="no-likes">
                    <h3>No Likes Yet</h3>
                    <p>This advertisement hasn't received any likes yet.</p>
                    <p style="margin-top: 10px; font-size: 14px;">Likes will appear here when users engage with your ad.</p>
                </div>
            <?php else: ?>
                <!-- Filter Options -->
                <div class="filter-options">
                    <button class="filter-btn active" onclick="filterLikes('all')">All Likes</button>
                    <button class="filter-btn" onclick="filterLikes('recent')">Recent First</button>
                    <button class="filter-btn" onclick="filterLikes('oldest')">Oldest First</button>
                </div>

                <div class="likes-list" id="likesList">
                    <?php foreach ($likes as $like): ?>
                        <div class="like-item" data-created-at="<?= strtotime($like['created_at']) ?>">
                            <a href="profile.php?id=<?= $like['user_id'] ?>">
                                <img src="<?= htmlspecialchars($like['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                     alt="<?= htmlspecialchars($like['username']) ?>" 
                                     class="user-avatar">
                            </a>
                            <div class="user-info">
                                <a href="profile.php?id=<?= $like['user_id'] ?>" class="user-name">
                                    <?= htmlspecialchars($like['username']) ?>
                                </a>
                                <div class="like-time">
                                    <span>❤️ Liked <?= timeAgo($like['created_at']) ?></span>
                                </div>
                                <div class="user-stats">
                                    <span class="user-stat">Joined: <?= date('M Y', strtotime($like['user_joined'])) ?></span>
                                    <span class="user-stat">User ID: <?= $like['user_id'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Filter likes functionality
        function filterLikes(filterType) {
            const likesList = document.getElementById('likesList');
            const likeItems = Array.from(likesList.getElementsByClassName('like-item'));
            const filterBtns = document.querySelectorAll('.filter-btn');
            
            // Update active button
            filterBtns.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Sort like items based on filter
            if (filterType === 'recent') {
                likeItems.sort((a, b) => {
                    return parseInt(b.dataset.createdAt) - parseInt(a.dataset.createdAt);
                });
            } else if (filterType === 'oldest') {
                likeItems.sort((a, b) => {
                    return parseInt(a.dataset.createdAt) - parseInt(b.dataset.createdAt);
                });
            }
            // For 'all', we keep the original order (already sorted by recent in PHP)
            
            // Clear and re-append sorted items
            likesList.innerHTML = '';
            likeItems.forEach(item => likesList.appendChild(item));
        }

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const likeItems = document.querySelectorAll('.like-item');
            likeItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    item.style.transition = 'all 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // Search functionality (could be enhanced)
        function searchLikes() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const likeItems = document.querySelectorAll('.like-item');
            
            likeItems.forEach(item => {
                const userName = item.querySelector('.user-name').textContent.toLowerCase();
                if (userName.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Export likes data (placeholder functionality)
        function exportLikes() {
            alert('Export functionality would download a CSV file with all like data.\nThis feature can be implemented to export user data for analysis.');
        }

        // Refresh data
        function refreshLikes() {
            location.reload();
        }
    </script>
</body>
</html>