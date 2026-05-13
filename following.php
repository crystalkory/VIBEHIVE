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

// Get users that the target user is following
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url 
    FROM users u 
    INNER JOIN follows f ON u.id = f.followed_id 
    WHERE f.follower_id = ? 
    ORDER BY u.username ASC
");
$stmt->execute([$targetUserId]);
$following = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Check if current user is viewing their own following
$isOwnProfile = ($targetUserId == $currentUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isOwnProfile ? 'My' : htmlspecialchars($targetUser['username']) . "'s" ?> Following - FbClone</title>
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

.following-list {
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

.following-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px;
    border-bottom: 1px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
    border-radius: 10px;
    margin-bottom: 8px;
}

.following-item:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
}

.following-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.following-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.following-pic {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #7b68ee;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: transform 0.3s ease;
}

.following-pic:hover {
    transform: scale(1.1);
}

.following-username {
    font-weight: bold;
    color: #2d3748;
    cursor: pointer;
    transition: color 0.3s ease;
    font-weight: 600;
}

.following-username:hover {
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

.follow-btn.unfollow {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
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

.mutual-followers {
    font-size: 12px;
    color: #7b68ee;
    margin-top: 5px;
    font-weight: 600;
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

.following-item {
    animation: fadeInUp 0.6s ease-out;
}

.following-list {
    animation: fadeInUp 0.8s ease-out;
}

/* Hover effects for unfollow button */
.follow-btn.following:hover {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
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

    .following-item {
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
    
    .following-pic {
        width: 40px;
        height: 40px;
    }
    
    .following-list {
        padding: 15px;
    }
    
    .section-title {
        font-size: 18px;
    }
    
    .follow-btn {
        min-width: 80px;
        padding: 6px 15px;
        font-size: 14px;
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
                        <div class="profile-owner-note">Viewing <?= htmlspecialchars($targetUser['username']) ?>'s following</div>
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
            <a href="followers.php?id=<?= $targetUserId ?>" class="nav-btn">Followers</a>
            <a href="following.php?id=<?= $targetUserId ?>" class="nav-btn active">Following</a>
        </div>

        <!-- Following List -->
        <div class="following-list">
            <h2 class="section-title">
                <?= $isOwnProfile ? 'People You\'re Following' : 'People ' . htmlspecialchars($targetUser['username']) . ' is Following' ?>
            </h2>
            
            <?php if (empty($following)): ?>
                <div class="empty-state">
                    <div>👤</div>
                    <h3><?= $isOwnProfile ? 'Not following anyone yet' : htmlspecialchars($targetUser['username']) . ' is not following anyone yet' ?></h3>
                    <p><?= $isOwnProfile ? 'When you follow people, they\'ll appear here.' : '' ?></p>
                    <?php if ($isOwnProfile): ?>
                        <p style="margin-top: 10px;"><a href="home.php" style="color: #7b68ee;">Discover people to follow</a></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($following as $user): ?>
                    <div class="following-item" data-user-id="<?= $user['id'] ?>">
                        <div class="following-info">
                            <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                 alt="<?= htmlspecialchars($user['username']) ?>" 
                                 class="following-pic"
                                 onclick="window.location='profile.php?id=<?= $user['id'] ?>'">
                            <div>
                                <div class="following-username" 
                                      onclick="window.location='profile.php?id=<?= $user['id'] ?>'">
                                    <?= htmlspecialchars($user['username']) ?>
                                </div>
                                <!-- Show mutual followers info -->
                                <?php
                                // Check if this user also follows the target user (mutual follow)
                                $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?");
                                $stmt->execute([$user['id'], $targetUserId]);
                                $isMutual = $stmt->fetchColumn();
                                
                                if ($isMutual): ?>
                                    <div class="mutual-followers">Follows <?= $isOwnProfile ? 'you' : htmlspecialchars($targetUser['username']) ?> back</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($user['id'] != $currentUserId): ?>
                            <button class="follow-btn <?= isset($followingMap[$user['id']]) ? 'following' : 'follow' ?> <?= isset($followingMap[$user['id']]) ? 'unfollow' : '' ?>" 
                                    data-user-id="<?= $user['id'] ?>"
                                    <?= isset($followingMap[$user['id']]) ? 'title="Click to unfollow"' : '' ?>>
                                <?= isset($followingMap[$user['id']]) ? 'Following' : 'Follow' ?>
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

                // Confirm unfollow
                if (action === 'unfollow') {
                    const confirmed = confirm('Are you sure you want to unfollow this user?');
                    if (!confirmed) {
                        return;
                    }
                }

                const formData = new FormData();
                formData.append('action', action);
                formData.append('user_id', userId);

                try {
                    const res = await fetch('following.php?id=<?= $targetUserId ?>', { 
                        method: 'POST', 
                        body: formData 
                    });
                    const data = await res.json();

                    if (data.success) {
                        if (data.action === 'unfollowed') {
                            // Remove the item from the list
                            const item = button.closest('.following-item');
                            item.style.opacity = '0';
                            setTimeout(() => {
                                item.remove();
                                
                                // Update following count
                                const followingCountElement = document.querySelector('.stat-number:last-child');
                                if (followingCountElement) {
                                    const currentCount = parseInt(followingCountElement.textContent);
                                    followingCountElement.textContent = currentCount - 1;
                                }
                                
                                // Show empty state if no more following
                                if (document.querySelectorAll('.following-item').length === 0) {
                                    location.reload(); // Reload to show empty state
                                }
                            }, 300);
                        } else if (data.action === 'followed') {
                            // Update button to show "Following"
                            button.textContent = 'Following';
                            button.classList.add('following', 'unfollow');
                            button.classList.remove('follow');
                            button.setAttribute('title', 'Click to unfollow');
                        }
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
        document.querySelectorAll('.following-pic, .following-username').forEach(element => {
            element.addEventListener('click', function() {
                const userId = this.closest('.following-item').getAttribute('data-user-id');
                if (userId) {
                    window.location.href = 'profile.php?id=' + userId;
                }
            });
        });

        // Add hover effects for following items
        document.querySelectorAll('.following-item').forEach(item => {
            item.addEventListener('click', (e) => {
                // Only navigate if the click wasn't on the follow button
                if (!e.target.closest('.follow-btn')) {
                    const userId = item.getAttribute('data-user-id');
                    window.location.href = 'profile.php?id=' + userId;
                }
            });
        });

        // Update button text on hover for unfollow
        document.querySelectorAll('.follow-btn.following').forEach(button => {
            button.addEventListener('mouseenter', function() {
                if (this.textContent === 'Following') {
                    this.textContent = 'Unfollow';
                }
            });
            
            button.addEventListener('mouseleave', function() {
                if (this.textContent === 'Unfollow') {
                    this.textContent = 'Following';
                }
            });
        });
    </script>
</body>
</html>