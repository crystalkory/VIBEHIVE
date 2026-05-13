<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "back.php";

require_once "config.php";


// Handle follow/unfollow actions via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['user_id'])) {
        $followerId = $_SESSION['user_id'];
        $followedId = (int)$_POST['user_id'];
        
        if ($followerId && $followedId && $followerId !== $followedId) {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$followerId, $followedId]);
                
                // Create notification
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message) VALUES (?, 'follow', ?, ?)");
                $message = "started following you";
                $stmt->execute([$followedId, $followerId, $message]);
                
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
}

// Get the target user ID from URL parameter
$targetUserId = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
$currentUserId = $_SESSION['user_id'];

// Get target user's profile info
$stmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    die("User not found");
}

// Get current user's profile info for comparison
$stmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

// Get users who are following the target user
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url 
    FROM users u 
    INNER JOIN follows f ON u.id = f.follower_id 
    WHERE f.followed_id = ? 
    ORDER BY u.username ASC
");
$stmt->execute([$targetUserId]);
$followers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get users that current user is following (for follow button status)
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$currentUserId]);
$followingIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
$followingMap = array_flip($followingIds); // For quick lookup

// Get follower count for target user
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE followed_id = ?");
$stmt->execute([$targetUserId]);
$followerCount = $stmt->fetchColumn();

// Get following count for target user
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
$stmt->execute([$targetUserId]);
$followingCount = $stmt->fetchColumn();

// Check if current user is viewing their own followers
$isOwnProfile = ($targetUserId == $currentUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isOwnProfile ? 'My' : htmlspecialchars($targetUser['username']) . "'s" ?> Followers - FbClone</title>
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
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(123, 104, 238, 0.3);
}

.profile-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-bottom: 20px;
}

.profile-pic {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
}

.username {
    font-size: 24px;
    font-weight: bold;
    color: white;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.stats {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-top: 15px;
}

.stat {
    text-align: center;
}

.stat-number {
    font-size: 20px;
    font-weight: bold;
    color: white;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.stat-label {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
}

.navigation {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-bottom: 30px;
}

.nav-btn {
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 2px solid #7b68ee;
    border-radius: 25px;
    color: #7b68ee;
    text-decoration: none;
    font-weight: bold;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.nav-btn:hover {
    background: #7b68ee;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.nav-btn.active {
    background: #7b68ee;
    color: white;
}

.followers-list {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 20px;
    border: 2px solid #7b68ee;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.section-title {
    font-size: 20px;
    margin-bottom: 20px;
    color: #7b68ee;
    text-align: center;
    font-weight: 700;
}

.follower-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px;
    border-bottom: 1px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
    border-radius: 10px;
    margin-bottom: 8px;
}

.follower-item:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
}

.follower-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.follower-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.follower-pic {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #7b68ee;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: transform 0.3s ease;
}

.follower-pic:hover {
    transform: scale(1.1);
}

.follower-username {
    font-weight: bold;
    color: #2d3748;
    cursor: pointer;
    transition: color 0.3s ease;
    font-weight: 600;
}

.follower-username:hover {
    color: #7b68ee;
}

.follow-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.3s ease;
    min-width: 100px;
    text-align: center;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.follow-btn.follow {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
}

.follow-btn.following {
    background: linear-gradient(135deg, #a0aec0, #718096);
    color: #f7fafc;
}

.follow-btn:hover:not(.following) {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.4);
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #718096;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #7b68ee;
}

.back-btn {
    display: inline-block;
    margin-bottom: 20px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 2px solid #7b68ee;
    border-radius: 25px;
    color: #7b68ee;
    text-decoration: none;
    font-weight: bold;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.back-btn:hover {
    background: #7b68ee;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.profile-owner-note {
    text-align: center;
    margin-bottom: 15px;
    color: rgba(255, 255, 255, 0.9);
    font-style: italic;
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

.follower-item {
    animation: fadeInUp 0.6s ease-out;
}

.followers-list {
    animation: fadeInUp 0.8s ease-out;
}

/* Responsive Design */
@media (max-width: 600px) {
    .container {
        padding: 15px;
    }

    .profile-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }

    .stats {
        gap: 20px;
    }

    .follower-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
        padding: 12px;
    }

    .follow-btn {
        align-self: flex-end;
        width: 100%;
        max-width: 120px;
    }

    .navigation {
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }

    .nav-btn {
        width: 100%;
        max-width: 200px;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .profile-pic {
        width: 60px;
        height: 60px;
    }
    
    .username {
        font-size: 20px;
    }
    
    .follower-pic {
        width: 40px;
        height: 40px;
    }
    
    .followers-list {
        padding: 15px;
    }
    
    .section-title {
        font-size: 18px;
    }
}
    </style>
</head>
<body>
    <div class="container">


        <!-- Header Section -->
        <div class="header">
            <div class="profile-header">
                <img src="<?= htmlspecialchars($targetUser['profile_pic_url'] ?: 'default_profile.png') ?>" 
                     alt="Profile Picture" class="profile-pic">
                <div>
                    <div class="username"><?= htmlspecialchars($targetUser['username']) ?></div>
                    <?php if (!$isOwnProfile): ?>
                        <div class="profile-owner-note">Viewing <?= htmlspecialchars($targetUser['username']) ?>'s followers</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-number"><?= $followerCount ?></div>
                    <div class="stat-label">Followers</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?= $followingCount ?></div>
                    <div class="stat-label">Following</div>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="navigation">
            <a href="followers.php?id=<?= $targetUserId ?>" class="nav-btn active">Followers</a>
            <a href="following.php?id=<?= $targetUserId ?>" class="nav-btn">Following</a>
        </div>

        <!-- Followers List -->
        <div class="followers-list">
            <h2 class="section-title">
                <?= $isOwnProfile ? 'People Following You' : 'People Following ' . htmlspecialchars($targetUser['username']) ?>
            </h2>
            
            <?php if (empty($followers)): ?>
                <div class="empty-state">
                    <div>👥</div>
                    <h3>No followers yet</h3>
                    <p><?= $isOwnProfile ? 'When people follow you, they\'ll appear here.' : htmlspecialchars($targetUser['username']) . ' doesn\'t have any followers yet.' ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($followers as $follower): ?>
                    <div class="follower-item" data-user-id="<?= $follower['id'] ?>">
                        <div class="follower-info">
                            <img src="<?= htmlspecialchars($follower['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                 alt="<?= htmlspecialchars($follower['username']) ?>" 
                                 class="follower-pic"
                                 onclick="window.location='profile.php?id=<?= $follower['id'] ?>'">
                            <span class="follower-username" 
                                  onclick="window.location='profile.php?id=<?= $follower['id'] ?>'">
                                <?= htmlspecialchars($follower['username']) ?>
                            </span>
                        </div>
                        
                        <?php if ($follower['id'] != $currentUserId): ?>
                            <button class="follow-btn <?= isset($followingMap[$follower['id']]) ? 'following' : 'follow' ?>" 
                                    data-user-id="<?= $follower['id'] ?>">
                                <?= isset($followingMap[$follower['id']]) ? 'Following' : 'Follow' ?>
                            </button>
                        <?php else: ?>
                            <span style="color: #888; padding: 8px 20px;">You</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Follow/Unfollow functionality
        document.querySelectorAll('.follow-btn').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.stopPropagation();
                const userId = button.getAttribute('data-user-id');
                if (!userId) return;

                const isFollowing = button.classList.contains('following');
                const action = isFollowing ? 'unfollow' : 'follow';

                const formData = new FormData();
                formData.append('action', action);
                formData.append('user_id', userId);

                try {
                    const res = await fetch('followers.php?id=<?= $targetUserId ?>', { 
                        method: 'POST', 
                        body: formData 
                    });
                    const data = await res.json();

                    if (data.success) {
                        // Update all buttons for this user
                        document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(btn => {
                            if (data.action === 'followed') {
                                btn.textContent = 'Following';
                                btn.classList.remove('follow');
                                btn.classList.add('following');
                            } else {
                                btn.textContent = 'Follow';
                                btn.classList.remove('following');
                                btn.classList.add('follow');
                            }
                        });
                    } else {
                        alert('Failed to update follow status: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error updating follow status. Please check your connection.');
                }
            });
        });

        // Profile picture and username click handlers
        document.querySelectorAll('.follower-pic, .follower-username').forEach(element => {
            element.addEventListener('click', function() {
                const userId = this.closest('.follower-item').getAttribute('data-user-id');
                if (userId) {
                    window.location.href = 'profile.php?id=' + userId;
                }
            });
        });

        // Add hover effects for follower items
        document.querySelectorAll('.follower-item').forEach(item => {
            item.addEventListener('click', (e) => {
                // Only navigate if the click wasn't on the follow button
                if (!e.target.closest('.follow-btn')) {
                    const userId = item.getAttribute('data-user-id');
                    window.location.href = 'profile.php?id=' + userId;
                }
            });
        });
    </script>
</body>
</html>