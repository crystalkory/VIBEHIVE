<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);

// Category definitions
$categories = [
    'All' => 'All Videos',
    'Entertainment' => 'Entertainment',
    'Dance' => 'Dance',
    'Lip-Sync' => 'Lip-Sync',
    'Comedy' => 'Comedy',
    'Music' => 'Music',
    'Beauty and Fashion' => 'Beauty and Fashion',
    'Food and Cooking' => 'Food and Cooking',
    'DIY and Crafting' => 'DIY and Crafting',
    'Gaming' => 'Gaming'
];

// Get selected category from URL or default to 'All'
$selectedCategory = $_GET['category'] ?? 'All';
if (!array_key_exists($selectedCategory, $categories)) {
    $selectedCategory = 'All';
}

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
        /* Header Styles */
        .system-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 1000;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-container {
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
            font-size: 16px;
        }

        .website-name {
            font-size: 18px;
            font-weight: 800;
            color: #2d3748;
            letter-spacing: -0.5px;
        }

        .nav-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 20px;
        }

        .nav-button {
            background: transparent;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .nav-button:hover {
            background: rgba(123, 104, 238, 0.1);
            color: #7b68ee;
            transform: translateY(-1px);
        }

        .nav-button.active {
            background: rgba(123, 104, 238, 0.15);
            color: #7b68ee;
        }

        .nav-button .icon {
            font-size: 16px;
        }

        .more-button {
            position: relative;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(0, 0, 0, 0.1);
            min-width: 200px;
            padding: 8px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
        }

        .more-button:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(5px);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: #4a5568;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }

        .dropdown-item:hover {
            background: rgba(123, 104, 238, 0.1);
            color: #7b68ee;
        }

        .dropdown-item .icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            text-decoration: none;
            color: #4a5568;
            transition: all 0.2s ease;
        }

        .user-profile:hover {
            background: rgba(123, 104, 238, 0.1);
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #7b68ee;
        }

        .username {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        .menu-icon {
            background: none;
            border: none;
            padding: 8px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            color: #4a5568;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .menu-icon:hover {
            background: rgba(123, 104, 238, 0.1);
            color: #7b68ee;
        }

        /* Category Filter Styles for Header */
        .category-filter-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 20px;
        }

        .category-select-header {
            padding: 8px 16px;
            border: 2px solid #7b68ee;
            border-radius: 20px;
            background: rgba(123, 104, 238, 0.1);
            color: #7b68ee;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 160px;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'><path fill='%237b68ee' d='M2 0L0 2h4zm0 5L0 3h4z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
        }

        .category-select-header:focus {
            outline: none;
            border-color: #9370db;
            box-shadow: 0 0 10px rgba(123, 104, 238, 0.3);
            background: rgba(123, 104, 238, 0.15);
        }

        .category-select-header:hover {
            background: rgba(123, 104, 238, 0.15);
            transform: translateY(-1px);
        }

        .current-category-badge {
            background: linear-gradient(45deg, #7b68ee, #9370db);
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
            white-space: nowrap;
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .category-filter-header {
                margin-left: 10px;
            }
            
            .category-select-header {
                min-width: 140px;
                font-size: 13px;
                padding: 6px 12px;
            }
        }

        @media (max-width: 768px) {
            .system-header {
                padding: 0 15px;
            }

            .website-name {
                font-size: 16px;
            }

            .nav-buttons {
                display: none;
            }

            .username {
                display: none;
            }

            .user-profile {
                padding: 6px;
            }

            
            .mobile-category-filter {
                display: block;
                padding: 15px;
                border-bottom: 1px solid rgba(0,0,0,0.1);
            }
            
            .category-select-mobile {
                width: 100%;
                padding: 12px 16px;
                border: 2px solid #7b68ee;
                border-radius: 12px;
                background: rgba(123, 104, 238, 0.1);
                color: #7b68ee;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
            }
        }

        @media (max-width: 480px) {
            .header-left {
                gap: 8px;
            }

            .website-name {
                font-size: 14px;
            }

            .logo-img {
                width: 28px;
                height: 28px;
                font-size: 14px;
            }
        }

        /* Body padding to account for fixed header */
        body {
            padding-top: 60px;
            margin: 0;
        }

        /* Notification badge */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .nav-button.notification {
            position: relative;
        }

        /* Mobile menu styles */
        .mobile-menu {
            display: none;
        }

        @media (max-width: 768px) {
            .mobile-menu {
                display: flex;
                align-items: center;
            }
            
            .mobile-menu-button {
                background: none;
                border: none;
                padding: 8px;
                border-radius: 8px;
                cursor: pointer;
                font-size: 20px;
                color: #4a5568;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .mobile-menu-button:hover {
                background: rgba(123, 104, 238, 0.1);
                color: #7b68ee;
            }
        }
    </style>
</head>
<body>
    <!-- System Style Header -->
<header class="system-header">
    <!-- Left Section: Logo and Website Name -->
    <div class="header-left">
        <!-- Logo and Website Name -->
        <a href="video.php" class="logo-container">
            <div class="logo-img"><img src="images/IMG-20251018-WA0017 (2).jpg" alt="" style="width: 50px;height: 50px;border-radius: 25px;"></div>
            <span class="website-name">Vibehive</span>
        </a>
        
        <!-- Category Filter for Desktop -->
        <?php if ($isLoggedIn && basename($_SERVER['PHP_SELF']) == 'video.php'): ?>
        <div class="category-filter-header">
            <form method="GET" action="video.php" id="categoryForm">
                <select name="category" class="category-select-header" onchange="document.getElementById('categoryForm').submit()">
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $selectedCategory === $value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Section: User Menu and Mobile Menu -->
    <div class="header-right">
        <?php if ($isLoggedIn): ?>
            <!-- Desktop Navigation (hidden on mobile) -->
            <nav class="nav-buttons">
                <a href="video.php" class="nav-button <?= basename($_SERVER['PHP_SELF']) == 'video.php' ? 'active' : '' ?>">
                    <span class="icon">🎬</span>
                    <span class="text">Reels</span>
                </a>

                <a href="search3.php" class="nav-button <?= basename($_SERVER['PHP_SELF']) == 'search3.php' ? 'active' : '' ?>">
                    <span class="icon">🔍</span>
                    <span class="text">Explore</span>
                </a>

                <a href="family.php" class="nav-button <?= basename($_SERVER['PHP_SELF']) == 'family.php' ? 'active' : '' ?>">
                    <span class="icon">👥</span>
                    <span class="text">Follows</span>
                </a>

                <a href="editor_video.php" class="nav-button <?= basename($_SERVER['PHP_SELF']) == 'editor_video.php' ? 'active' : '' ?>">
                    <span class="icon">➕</span>
                    <span class="text">Create</span>
                </a>

                <a href="notification.php" class="nav-button notification <?= basename($_SERVER['PHP_SELF']) == 'notification.php' ? 'active' : '' ?>">
                    <span class="icon">🔔</span>
                    <span class="text">Notifications</span>
                    <div class="notification-badge">3</div>
                </a>

                <a href="friend.php" class="nav-button <?= basename($_SERVER['PHP_SELF']) == 'friend.php' ? 'active' : '' ?>">
                    <span class="icon">💬</span>
                    <span class="text">Messages</span>
                </a>

                <!-- More Button with Dropdown -->
                <div class="nav-button more-button">
                    <span class="icon">⋯</span>
                    <span class="text">More</span>
                    
                    <!-- Dropdown Menu -->
                    <div class="dropdown-menu">
                        <a href="create_group.php" class="dropdown-item">
                            <span class="icon">👥</span>
                            <span>Create Group</span>
                        </a>
                        
                        <a href="user_group.php" class="dropdown-item">
                            <span class="icon">🏢</span>
                            <span>My Groups</span>
                        </a>

                        <a href="earned_point.php" class="dropdown-item">
                            <span class="icon">⭐</span>
                            <span>Earned Points</span>
                        </a>
                        
                        <a href="account_settings.php" class="dropdown-item">
                            <span class="icon">⚙️</span>
                            <span>Settings</span>
                        </a>
                    <a href="dashboard.php" class="dropdown-item">
                            <span class="icon">⭐</span>
                            <span>Promote Post</span>
                        </a>
                        
                        <a href="help.php" class="dropdown-item">
                            <span class="icon">❓</span>
                            <span>Help & Support</span>
                        </a>
                        
                        <a href="logout.php" class="dropdown-item">
                            <span class="icon">🚪</span>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </nav>

            <!-- User Profile (hidden on mobile) -->
            <a href="profile.php?id=<?= $_SESSION['user_id'] ?>" class="user-profile">
                <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile" class="user-avatar" 
                     onerror="this.src='default_profile.png'">
                <span class="username"><?= htmlspecialchars($username) ?></span>
            </a>

            <!-- Mobile Menu Button (visible only on mobile) -->
            <div class="mobile-menu">
                <a href="menu.php">
                    <span>☰</span>
                </a>
            </div>

        <?php else: ?>
            <!-- Login/Signup buttons for non-logged in users -->
            <a href="auth.php" class="nav-button">
                <span class="icon">🔐</span>
                <span class="text">Login</span>
            </a>
        <?php endif; ?>
    </div>
</header>

<!-- Mobile Menu Dropdown -->
<div id="mobileMenu" style="display: none; position: fixed; top: 60px; left: 0; right: 0; background: white; border-bottom: 1px solid rgba(0,0,0,0.1); padding: 15px; z-index: 999; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
    <!-- Category Filter for Mobile -->
    <?php if ($isLoggedIn && basename($_SERVER['PHP_SELF']) == 'video.php'): ?>
    <div class="mobile-category-filter">
        <form method="GET" action="video.php" id="categoryFormMobile">
            <select name="category" class="category-select-mobile" onchange="document.getElementById('categoryFormMobile').submit()">
                <?php foreach ($categories as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= $selectedCategory === $value ? 'selected' : '' ?>>
                        📹 <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>
    
    <div style="display: flex; flex-direction: column; gap: 10px;">
        <a href="video.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px; background: <?= basename($_SERVER['PHP_SELF']) == 'video.php' ? 'rgba(123, 104, 238, 0.1)' : 'transparent' ?>;">
            <span>🎬</span>
            <span>Reels</span>
        </a>
        <a href="search3.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px; background: <?= basename($_SERVER['PHP_SELF']) == 'search3.php' ? 'rgba(123, 104, 238, 0.1)' : 'transparent' ?>;">
            <span>🔍</span>
            <span>Explore</span>
        </a>
        <a href="family.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px; background: <?= basename($_SERVER['PHP_SELF']) == 'family.php' ? 'rgba(123, 104, 238, 0.1)' : 'transparent' ?>;">
            <span>👥</span>
            <span>Follows</span>
        </a>
        <a href="editor_video.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px; background: <?= basename($_SERVER['PHP_SELF']) == 'editor_video.php' ? 'rgba(123, 104, 238, 0.1)' : 'transparent' ?>;">
            <span>➕</span>
            <span>Create</span>
        </a>
        <a href="notification.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px; background: <?= basename($_SERVER['PHP_SELF']) == 'notification.php' ? 'rgba(123, 104, 238, 0.1)' : 'transparent' ?>; position: relative;">
            <span>🔔</span>
            <span>Notifications</span>
            <div style="position: absolute; right: 15px; background: #ff4757; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; font-weight: bold;">3</div>
        </a>
        <a href="friend.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px; background: <?= basename($_SERVER['PHP_SELF']) == 'friend.php' ? 'rgba(123, 104, 238, 0.1)' : 'transparent' ?>;">
            <span>💬</span>
            <span>Messages</span>
        </a>
        <a href="profile.php?id=<?= $_SESSION['user_id'] ?>" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover; border: 2px solid #7b68ee;" onerror="this.src='default_profile.png'">
            <span>Profile</span>
        </a>
        <a href="create_group.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <span>👥</span>
            <span>Create Group</span>
        </a>
        <a href="user_group.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <span>🏢</span>
            <span>My Groups</span>
        </a>
        <a href="earned_point.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <span>⭐</span>
            <span>Earned Points</span>
        </a>
        <a href="account_settings.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <span>⚙️</span>
            <span>Settings</span>
        </a>
        <a href="help.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <span>❓</span>
            <span>Help & Support</span>
        </a>
        <a href="logout.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; text-decoration: none; color: #4a5568; border-radius: 8px;">
            <span>🚪</span>
            <span>Logout</span>
        </a>
    </div>
</div>

    <script>
        // Add active state to current page
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
            const navButtons = document.querySelectorAll('.nav-button');
            
            navButtons.forEach(button => {
                if (button.href && button.href.includes(currentPage)) {
                    button.classList.add('active');
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                const dropdowns = document.querySelectorAll('.dropdown-menu');
                dropdowns.forEach(dropdown => {
                    if (!dropdown.parentElement.contains(e.target)) {
                        dropdown.style.opacity = '0';
                        dropdown.style.visibility = 'hidden';
                        dropdown.style.transform = 'translateY(-10px)';
                    }
                });
            });

            // Smooth hover effects
            const moreButton = document.querySelector('.more-button');
            if (moreButton) {
                moreButton.addEventListener('mouseenter', function() {
                    const dropdown = this.querySelector('.dropdown-menu');
                    dropdown.style.opacity = '1';
                    dropdown.style.visibility = 'visible';
                    dropdown.style.transform = 'translateY(5px)';
                });

                moreButton.addEventListener('mouseleave', function(e) {
                    // Check if mouse is leaving to outside the dropdown
                    if (!this.contains(e.relatedTarget)) {
                        const dropdown = this.querySelector('.dropdown-menu');
                        dropdown.style.opacity = '0';
                        dropdown.style.visibility = 'hidden';
                        dropdown.style.transform = 'translateY(-10px)';
                    }
                });
            }

            // Category filter functionality
            const categorySelects = document.querySelectorAll('select[name="category"]');
            categorySelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            // Add active state to category filter when on video page
            if (window.location.pathname.includes('video.php')) {
                const categoryFilter = document.querySelector('.category-filter-header');
                if (categoryFilter) {
                    categoryFilter.style.background = 'rgba(123, 104, 238, 0.05)';
                    categoryFilter.style.padding = '4px 8px';
                    categoryFilter.style.borderRadius = '20px';
                }
            }
        });

        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobileMenu');
            if (mobileMenu.style.display === 'none' || mobileMenu.style.display === '') {
                mobileMenu.style.display = 'block';
                // Scroll to top of mobile menu
                mobileMenu.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                mobileMenu.style.display = 'none';
            }
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(e) {
            const mobileMenu = document.getElementById('mobileMenu');
            const mobileMenuButton = document.querySelector('.mobile-menu-button');
            
            if (mobileMenu && mobileMenu.style.display === 'block' && 
                !mobileMenu.contains(e.target) && 
                !mobileMenuButton.contains(e.target)) {
                mobileMenu.style.display = 'none';
            }
        });

        // Add loading state to buttons
        document.querySelectorAll('.nav-button').forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.href && !this.classList.contains('more-button')) {
                    this.style.opacity = '0.7';
                    this.style.pointerEvents = 'none';
                    
                    setTimeout(() => {
                        this.style.opacity = '1';
                        this.style.pointerEvents = 'auto';
                    }, 1000);
                }
            });
        });
    </script>
</body>
</html>