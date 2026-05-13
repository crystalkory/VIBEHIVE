<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "color.php";

require_once "config.php";


// Get user data
$userId = $_SESSION['user_id'];
$userStmt = $pdo->prepare("SELECT username, profile_pic_url, email FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

$username = $userData['username'] ?? 'User';
$profilePic = $userData['profile_pic_url'] ?? 'default_profile.png';
$email = $userData['email'] ?? '';

// Get user stats
$statsStmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM posts WHERE user_id = ?) as post_count,
        (SELECT COUNT(*) FROM follows WHERE follower_id = ?) as following_count,
        (SELECT COUNT(*) FROM follows WHERE followed_id = ?) as followers_count,
        (SELECT COUNT(*) FROM likes WHERE user_id = ?) as likes_count
");
$statsStmt->execute([$userId, $userId, $userId, $userId]);
$userStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu - FbClone</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .menu-container {
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
            min-height: 100vh;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-top: 20px;
        }

        .profile-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .profile-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .profile-pic {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #7b68ee;
            box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
        }

        .user-details {
            flex: 1;
        }

        .username {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .email {
            font-size: 14px;
            color: #718096;
            margin-bottom: 10px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            text-align: center;
        }

        .stat-item {
            background: rgba(123, 104, 238, 0.1);
            padding: 12px;
            border-radius: 12px;
            border: 1px solid rgba(123, 104, 238, 0.2);
        }

        .stat-number {
            font-size: 16px;
            font-weight: 700;
            color: #7b68ee;
            display: block;
        }

        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 2px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .menu-button {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .menu-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 1);
        }

        .menu-button:active {
            transform: translateY(-1px);
        }

        .button-icon {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .button-text {
            font-size: 13px;
            font-weight: 600;
            color: #2d3748;
            line-height: 1.3;
        }

        .logout-section {
            text-align: center;
            margin-top: 30px;
        }

        .logout-button {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            border: none;
            border-radius: 25px;
            padding: 15px 30px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
            width: 100%;
            max-width: 200px;
        }

        .logout-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.6);
        }

        .app-title {
            color: white;
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 30px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .menu-section-title {
            color: white;
            font-size: 18px;
            font-weight: 600;
            margin: 25px 0 15px 0;
            text-align: left;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        /* Special button styles */
        .button-primary {
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            color: white;
        }

        .button-primary .button-text {
            color: white;
        }

        .button-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
        }

        .button-success .button-text {
            color: white;
        }

        .button-warning {
            background: linear-gradient(135deg, #ed8936, #dd6b20);
            color: white;
        }

        .button-warning .button-text {
            color: white;
        }

        .button-info {
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
        }

        .button-info .button-text {
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .menu-container {
                padding: 15px;
            }

            .profile-section {
                padding: 20px;
            }

            .profile-pic {
                width: 60px;
                height: 60px;
            }

            .username {
                font-size: 16px;
            }

            .menu-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .menu-button {
                padding: 18px 12px;
            }

            .button-icon {
                font-size: 22px;
            }

            .button-text {
                font-size: 12px;
            }
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

        .menu-container > * {
            animation: fadeInUp 0.6s ease-out;
        }

        .menu-container > *:nth-child(2) { animation-delay: 0.1s; }
        .menu-container > *:nth-child(3) { animation-delay: 0.2s; }
        .menu-container > *:nth-child(4) { animation-delay: 0.3s; }

        /* Back button */
        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            font-size: 20px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            transform: scale(1.1);
            background: white;
        }
    </style>
</head>
<body>
    <!-- Back Button -->
    <a  class="back-button" onclick="history.back()">←</a>

    <div class="menu-container">
        <!-- App Title -->
        <div class="header">
            <img src="images/IMG-20251018-WA0017 (2).jpg" alt="" style="width: 50px;height: 50px;border-radius: 25px;">
            <h1 class="app-title">FbClone</h1>
        </div>

        <!-- Profile Section -->
        <div class="profile-section">
            <div class="profile-info">
                <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile" class="profile-pic" 
                     onerror="this.src='default_profile.png'">
                <div class="user-details">
                    <div class="username"><?= htmlspecialchars($username) ?></div>
                    <div class="email"><?= htmlspecialchars($email) ?></div>
                </div>
            </div>
            <div class="stats">
                <div class="stat-item">
                    <span class="stat-number"><?= $userStats['post_count'] ?? 0 ?></span>
                    <span class="stat-label">Posts</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= $userStats['followers_count'] ?? 0 ?></span>
                    <span class="stat-label">Followers</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= $userStats['following_count'] ?? 0 ?></span>
                    <span class="stat-label">Following</span>
                </div>
            </div>
        </div>

<!-- Main Menu Buttons -->
<h3 class="menu-section-title">Main Features</h3>
<div class="menu-grid">
    <a href="video.php" class="menu-button button-primary">
        <div class="button-icon">🎬</div>
        <div class="button-text">Reels Feed</div>
    </a>

    <a href="profile.php?id=<?= $userId ?>" class="menu-button">
        <div class="button-icon">👤</div>
        <div class="button-text">My Profile</div>
    </a>

    <a href="editor_video.php" class="menu-button button-success">
        <div class="button-icon">➕</div>
        <div class="button-text">Create Post</div>
    </a>

    <a href="search3.php" class="menu-button button-info">
        <div class="button-icon">🔍</div>
        <div class="button-text">Explore</div>
    </a>

    <a href="notification.php" class="menu-button">
        <div class="button-icon">🔔</div>
        <div class="button-text">Notifications</div>
    </a>

    <a href="friend.php" class="menu-button">
        <div class="button-icon">💬</div>
        <div class="button-text">Messages</div>
    </a>

    <a href="earned_point.php" class="menu-button">
        <div class="button-icon">⭐</div>
        <div class="button-text">Earned Points</div>
    </a>
    
    <a href="create_group.php" class="menu-button">
        <div class="button-icon">👥</div>
        <div class="button-text">Create Group</div>
    </a>
    
    <a href="user_group.php" class="menu-button">
        <div class="button-icon">🏢</div>
        <div class="button-text">My Groups</div>
    </a>
     <a href="Dashboard.php" class="menu-button">
        <div class="button-icon">⭐</div>
        <div class="button-text">Promote Post</div>
    </a>
</div>

<!-- Settings & More -->
<h3 class="menu-section-title">Settings & More</h3>
<div class="menu-grid">
    <a href="account_settings.php" class="menu-button">
        <div class="button-icon">⚙️</div>
        <div class="button-text">Settings</div>
    </a>

    <a href="help.php" class="menu-button">
        <div class="button-icon">❓</div>
        <div class="button-text">Help & Support</div>
    </a>

    <a href="about.php" class="menu-button">
        <div class="button-icon">ℹ️</div>
        <div class="button-text">About</div>
    </a>
</div>

<!-- Logout Section -->
<div class="logout-section">
    <form action="logout.php" method="POST">
        <button type="submit" class="logout-button">
            🚪 Logout
        </button>
    </form>
</div>

    <script>
        // Add click effects
        document.addEventListener('DOMContentLoaded', function() {
            const buttons = document.querySelectorAll('.menu-button');
            
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    // Add ripple effect
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        position: absolute;
                        border-radius: 50%;
                        background: rgba(255, 255, 255, 0.6);
                        transform: scale(0);
                        animation: ripple 0.6s linear;
                        width: ${size}px;
                        height: ${size}px;
                        left: ${x}px;
                        top: ${y}px;
                        pointer-events: none;
                    `;
                    
                    this.style.position = 'relative';
                    this.style.overflow = 'hidden';
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
        });

        // Add ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>