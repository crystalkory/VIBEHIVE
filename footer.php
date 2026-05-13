<?php

$isLoggedIn = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Mobile Footer Styles */
        .mobile-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            z-index: 999;
            box-shadow: 0 -2px 20px rgba(0, 0, 0, 0.1);
        }

        .footer-nav {
            display: flex;
            height: 100%;
            align-items: center;
            justify-content: space-around;
            padding: 0 10px;
        }

        .footer-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            padding: 8px 12px;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            color: #6b7280;
            transition: all 0.3s ease;
            flex: 1;
            max-width: 70px;
            position: relative;
        }

        .footer-button:hover {
            color: #7b68ee;
            transform: translateY(-2px);
        }

        .footer-button.active {
            color: #7b68ee;
        }

        .footer-button.active::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background: #7b68ee;
            border-radius: 50%;
        }

        .footer-icon {
            font-size: 20px;
            margin-bottom: 2px;
            transition: all 0.3s ease;
        }

        .footer-button.active .footer-icon {
            transform: scale(1.1);
        }

        .footer-label {
            font-size: 10px;
            font-weight: 600;
            text-align: center;
            line-height: 1.2;
        }

        /* Create Post Button - Special Style */
        .footer-button.create-post {
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            color: white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            margin-top: -20px;
            box-shadow: 0 4px 20px rgba(123, 104, 238, 0.4);
            position: relative;
            z-index: 1000;
        }

        .footer-button.create-post .footer-icon {
            font-size: 22px;
            margin-bottom: 0;
        }

        .footer-button.create-post .footer-label {
            display: none;
        }

        .footer-button.create-post:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 6px 25px rgba(123, 104, 238, 0.6);
        }

        /* Notification Badge */
        .notification-badge {
            position: absolute;
            top: 5px;
            right: 8px;
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            font-size: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid white;
        }

        /* Show only on mobile devices */
        @media (max-width: 768px) {
            .mobile-footer {
                display: block;
            }

            /* Add body padding to account for fixed footer */
            body {
                padding-bottom: 60px;
            }
        }

        /* Adjust for very small screens */
        @media (max-width: 360px) {
            .mobile-footer {
                height: 55px;
            }

            .footer-icon {
                font-size: 18px;
            }

            .footer-label {
                font-size: 9px;
            }

            .footer-button {
                padding: 6px 8px;
            }

            body {
                padding-bottom: 55px;
            }
        }

        /* Landscape mode adjustments */
        @media (max-width: 768px) and (orientation: landscape) {
            .mobile-footer {
                height: 50px;
            }

            .footer-icon {
                font-size: 18px;
                margin-bottom: 0;
            }

            .footer-label {
                display: none;
            }

            body {
                padding-bottom: 50px;
            }
        }

        /* Animation for footer appearance */
        @keyframes slideUpFooter {
            from {
                transform: translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .mobile-footer {
            animation: slideUpFooter 0.4s ease-out;
        }

        /* Hover effects for non-touch devices */
        @media (hover: hover) and (pointer: fine) {
            .footer-button:hover .footer-icon {
                transform: scale(1.1);
            }

            .footer-button:hover .footer-label {
                color: #7b68ee;
            }
        }

        /* Active state animation */
        .footer-button:active {
            transform: scale(0.95);
        }

        .footer-button.create-post:active {
            transform: scale(0.9);
        }
    </style>

    <script>
        // JavaScript for footer functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Set active state based on current page
            const currentPage = window.location.pathname.split('/').pop();
            const footerButtons = document.querySelectorAll('.footer-button');
            
            footerButtons.forEach(button => {
                const page = button.getAttribute('data-page');
                if (page && currentPage.includes(page)) {
                    button.classList.add('active');
                }
            });

            // Add click animations
            footerButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    // Remove active class from all buttons
                    footerButtons.forEach(btn => btn.classList.remove('active'));
                    
                    // Add active class to clicked button (except for create post)
                    if (!this.classList.contains('create-post')) {
                        this.classList.add('active');
                    }
                    
                    // Add ripple effect
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        position: absolute;
                        border-radius: 50%;
                        background: rgba(123, 104, 238, 0.3);
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

            // Handle create post button specially
            const createPostBtn = document.querySelector('.footer-button.create-post');
            if (createPostBtn) {
                createPostBtn.addEventListener('click', function() {
                    this.style.transform = 'scale(0.9)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 200);
                });
            }

            // Add ripple animation
            if (!document.querySelector('#footer-ripple-style')) {
                const style = document.createElement('style');
                style.id = 'footer-ripple-style';
                style.textContent = `
                    @keyframes ripple {
                        to {
                            transform: scale(4);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        });

        // Hide footer when keyboard appears on mobile
        window.addEventListener('resize', function() {
            const footer = document.querySelector('.mobile-footer');
            if (window.innerHeight < 500) { // Keyboard is probably open
                footer.style.display = 'none';
            } else {
                footer.style.display = 'block';
            }
        });

        // Prevent footer from hiding content when focused
        document.addEventListener('focusin', function() {
            setTimeout(() => {
                const activeElement = document.activeElement;
                if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA')) {
                    activeElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 100);
        });
    </script>
</head>
<body>
    <!-- Mobile Footer Navigation -->
    <footer class="mobile-footer">
        <nav class="footer-nav">
            <!-- Reels Button -->
            <a href="video.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'video.php' ? 'active' : '' ?>" data-page="video.php">
                <div class="footer-icon">🎬</div>
                <div class="footer-label">Reels</div>
            </a>

            <!-- Search/Explore Button -->
            <a href="search3.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'search3.php' ? 'active' : '' ?>" data-page="search3.php">
                <div class="footer-icon">🔍</div>
                <div class="footer-label">Explore</div>
            </a>

            <!-- Messages Button -->
            <a href="friend.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'friend.php' ? 'active' : '' ?>" data-page="friend.php">
                <div class="footer-icon">💬</div>
                <div class="footer-label">Messages</div>
            </a>

            <!-- Create Post Button (Center - Special) -->
            <a href="editor_video.php" class="footer-button create-post" data-page="editor_video.php">
                <div class="footer-icon">➕</div>
            </a>

            <!-- Following/Follows Button -->
            <?php if ($isLoggedIn): ?>
                <a href="family.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'family.php' ? 'active' : '' ?>" data-page="family.php">
                    <div class="footer-icon">👥</div>
                    <div class="footer-label">Following</div>
                </a>

            <!-- Notifications Button -->
            <a href="notification.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'notification.php' ? 'active' : '' ?>" data-page="notification.php">
                <div class="footer-icon">🔔</div>
                <div class="footer-label">Notifications</div>
            </a>

            <!-- Settings Button -->
            <a href="account_settings.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'account_settings.php' ? 'active' : '' ?>" data-page="account_settings.php">
                <div class="footer-icon">⚙️</div>
                <div class="footer-label">Settings</div>
            </a>

            <?php else: ?>
                <!-- Login Button -->
                <a href="auth.php" class="footer-button <?= basename($_SERVER['PHP_SELF']) == 'auth.php' ? 'active' : '' ?>" data-page="auth.php">
                    <div class="footer-icon">🔐</div>
                    <div class="footer-label">Login</div>
                </a>
            <?php endif; ?>
        </nav>
    </footer>
</body>
</html>