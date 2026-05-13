<?php


// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
require_once "config.php";

// Get user data if logged in
if ($isLoggedIn) {
    $host = 'localhost';
    $port = '5432';
    $dbname = 'fbclone';
    $user = 'postgres';
    $password = 'Gi12,br12';
    
    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $userId = $_SESSION['user_id'];
        $userStmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        $username = $userData['username'] ?? 'User';
        $profilePic = $userData['profile_pic_url'] ?? 'default_profile.png';
    } catch (PDOException $e) {
        // If DB connection fails, set default values
        $username = 'User';
        $profilePic = 'default_profile.png';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Back Header Styles */
        .back-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 1000;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }

        .back-header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .back-header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        /* Back Button */
        .back-button {
            background: none;
            border: none;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            color: #4a5568;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            text-decoration: none;
        }

        .back-button:hover {
            background: rgba(123, 104, 238, 0.1);
            color: #7b68ee;
            transform: translateX(-2px);
        }

        .back-button:active {
            transform: translateX(-2px) scale(0.95);
        }

        /* Logo and Brand */
        .brand-container {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .logo-img {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
        }

        .website-name {
            font-size: 18px;
            font-weight: 800;
            color: #2d3748;
            letter-spacing: -0.5px;
        }

        /* User Profile Icon */
        .profile-icon {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            text-decoration: none;
            color: #4a5568;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .profile-icon:hover {
            background: rgba(123, 104, 238, 0.1);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
        }

        .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #7b68ee;
            transition: all 0.3s ease;
        }

        .profile-icon:hover .user-avatar-small {
            transform: scale(1.05);
            border-color: #6a5acd;
        }

        .username-short {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        /* Center alignment for pages without profile */
        .back-header-center {
            display: flex;
            align-items: center;
            gap: 12px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        /* Page Title (optional) */
        .page-title {
            font-size: 16px;
            font-weight: 600;
            color: #4a5568;
            margin-left: 10px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .back-header {
                padding: 0 15px;
                height: 56px;
            }

            .website-name {
                font-size: 16px;
            }

            .back-button {
                width: 36px;
                height: 36px;
                font-size: 18px;
            }

            .user-avatar-small {
                width: 28px;
                height: 28px;
            }

            .username-short {
                display: none;
            }

            .profile-icon {
                padding: 8px;
            }

            .page-title {
                font-size: 14px;
                margin-left: 5px;
            }
        }

        @media (max-width: 480px) {
            .back-header {
                padding: 0 12px;
            }

            .website-name {
                font-size: 15px;
            }

            .logo-img {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }

            .back-header-left {
                gap: 10px;
            }
        }

        /* Body padding to account for fixed header */
        body {
            padding-top: 60px;
        }

        @media (max-width: 768px) {
            body {
                padding-top: 56px;
            }
        }

        /* Animation for back button */
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .back-button {
            animation: slideInLeft 0.3s ease-out;
        }

        /* Animation for profile icon */
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .profile-icon {
            animation: slideInRight 0.3s ease-out 0.1s both;
        }
    </style>

    <script>
        // JavaScript for back functionality
        function goBack() {
            if (document.referrer && document.referrer.includes(window.location.hostname)) {
                window.history.back();
            } else {
                window.location.href = 'feed.php';
            }
        }

        // Enhanced back button with smooth transition
        document.addEventListener('DOMContentLoaded', function() {
            const backButton = document.querySelector('.back-button');
            if (backButton) {
                backButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Add click animation
                    this.style.transform = 'scale(0.9)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                    
                    // Navigate back
                    setTimeout(() => {
                        if (document.referrer && document.referrer.includes(window.location.hostname)) {
                            window.history.back();
                        } else {
                            window.location.href = 'feed.php';
                        }
                    }, 200);
                });
            }

            // Add hover effect to profile icon
            const profileIcon = document.querySelector('.profile-icon');
            if (profileIcon) {
                profileIcon.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                profileIcon.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            }
        });

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function() {
            // Force a small delay for smooth transition
            setTimeout(() => {
                window.location.reload();
            }, 50);
        });
    </script>
</head>
<body>
    <!-- Back Header -->
    <header class="back-header">
        <!-- Left Section: Back Button and Brand -->
        <div class="back-header-left">
            <!-- Back Button -->
            <a  class="back-button" onclick="history.back()" title="Go Back">
                <span>←</span>
            </a>

            <!-- Logo and Website Name -->
            <a href="video.php" class="brand-container">
                <div class="logo-img"><img src="images/IMG-20251018-WA0017 (2).jpg" alt="" style="width: 50px;height: 50px;border-radius: 25px;"></div>
                <span class="website-name">Vibehive</span>
            </a>

            <!-- Optional: Page Title -->
            <?php if (isset($pageTitle)): ?>
                <span class="page-title"><?= htmlspecialchars($pageTitle) ?></span>
            <?php endif; ?>
        </div>

        <!-- Right Section: User Profile -->
        <div class="back-header-right">
            <?php if ($isLoggedIn): ?>
                <!-- User Profile Icon -->
                <a href="profile.php?id=<?= $_SESSION['user_id'] ?>" class="profile-icon" title="My Profile">
                    <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile" class="user-avatar-small" 
                         onerror="this.src='default_profile.png'">
                    <span class="username-short"><?= htmlspecialchars($username) ?></span>
                </a>
            <?php else: ?>
                <!-- Login button for non-logged in users -->
                <a href="auth.php" class="profile-icon" title="Login">
                    <div class="user-avatar-small" style="background: #7b68ee; color: white; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                        👤
                    </div>
                    <span class="username-short">Login</span>
                </a>
            <?php endif; ?>
        </div>
    </header>
</body>
</html>