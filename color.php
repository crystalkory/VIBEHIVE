<?php
// color.php - Dark Mode Transformation System for Family Page

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if dark mode is enabled for current user
 */
function isDarkModeEnabled($pdo = null) {
    // If no database connection provided, try to create one
    if ($pdo === null) {
        $host = 'localhost';
        $port = '5432';
        $dbname = 'fbclone';
        $user = 'postgres';
        $password = 'Gi12,br12';
        
        try {
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            // If we can't connect to database, return false
            error_log("Database connection failed in color.php: " . $e->getMessage());
            return false;
        }
    }
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    // Check if we already have it in session
    if (isset($_SESSION['color_accessibility'])) {
        return $_SESSION['color_accessibility'];
    }
    
    // Get from database
    $user_id = $_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT color_accessibility FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        $enabled = $result && $result['color_accessibility'] === true;
        $_SESSION['color_accessibility'] = $enabled;
        
        return $enabled;
    } catch (Exception $e) {
        error_log("Error checking dark mode: " . $e->getMessage());
        return false;
    }
}

/**
 * Transform colors from light mode to dark mode in CSS content for family page
 */
function transformColorsToDarkMode($css) {
    // Primary brand colors - light to dark (brighter for better contrast)
    $css = preg_replace('/#7b68ee/i', '#8a7bf0', $css);
    $css = preg_replace('/#6a5acd/i', '#7a6ae0', $css);
    
    // Background gradients - light to dark
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*#667eea\s*0%\s*,\s*#764ba2\s*100%\s*\)/i', 'linear-gradient(135deg, #1a1a2e 0%, #16213e 100%)', $css);
    
    // White backgrounds to dark
    $css = preg_replace('/#ffffff|#fff|white/i', '#121212', $css);
    $css = preg_replace('/rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.95\s*\)/i', 'rgba(40, 40, 60, 0.95)', $css);
    $css = preg_replace('/rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.9\s*\)/i', 'rgba(45, 45, 65, 0.9)', $css);
    $css = preg_replace('/rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*[^)]*\)/i', 'rgba(40, 40, 60, 0.95)', $css);
    
    // Light backgrounds to dark
    $css = preg_replace('/#f7fafc|#f8f9fa|#f5f5f5/i', '#1a1a2e', $css);
    $css = preg_replace('/#e2e8f0|#e5e5e5/i', '#2d3748', $css);
    $css = preg_replace('/#cbd5e0|#d1d1d1/i', '#4a5568', $css);
    
    // Text colors - dark to light
    $css = preg_replace('/#333333|#2d3748|#1a202c/i', '#e0e0e0', $css);
    $css = preg_replace('/#4a5568|#718096/i', '#b0b0b0', $css);
    $css = preg_replace('/#a0aec0|#9e9e9e/i', '#909090', $css);
    
    // Border colors
    $css = preg_replace('/#e2e8f0|#e0e0e0/i', 'rgba(138, 123, 240, 0.3)', $css);
    $css = preg_replace('/#cbd5e0|#cccccc/i', 'rgba(138, 123, 240, 0.5)', $css);
    
    // Specific component backgrounds for family page
    $css = preg_replace('/rgba\s*\(\s*247\s*,\s*248\s*,\s*250\s*,\s*[^)]*\)/i', 'rgba(50, 50, 70, 0.8)', $css);
    $css = preg_replace('/rgba\s*\(\s*248\s*,\s*249\s*,\s*250\s*,\s*[^)]*\)/i', 'rgba(50, 50, 70, 0.8)', $css);
    
    // Button gradients - keep brand colors but adjust for dark mode
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*#7b68ee\s*,\s*#6a5acd\s*\)/i', 'linear-gradient(135deg, #8a7bf0, #7a6ae0)', $css);
    
    // Following state button
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*#a0aec0\s*,\s*#718096\s*\)/i', 'linear-gradient(135deg, #606070, #505060)', $css);
    
    // Secondary button
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*#e2e8f0\s*,\s*#cbd5e0\s*\)/i', 'linear-gradient(135deg, #4a5568, #2d3748)', $css);
    
    // Shadow adjustments for dark mode
    $css = preg_replace('/rgba\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\.1\s*\)/i', 'rgba(0, 0, 0, 0.3)', $css);
    $css = preg_replace('/rgba\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\.15\s*\)/i', 'rgba(0, 0, 0, 0.4)', $css);
    $css = preg_replace('/rgba\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\.2\s*\)/i', 'rgba(0, 0, 0, 0.5)', $css);
    
    // Input backgrounds
    $css = preg_replace('/rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.8\s*\)/i', 'rgba(60, 60, 80, 0.8)', $css);
    
    // Search box specific
    $css = preg_replace('/color:\s*#2d3748/i', 'color: #e0e0e0', $css);
    
    // Like button color
    $css = preg_replace('/#ff004f/i', '#ff3366', $css);
    
    // Page header gradient
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*#7b68ee\s*,\s*#6a5acd\s*\)/i', 'linear-gradient(135deg, #8a7bf0, #7a6ae0)', $css);
    
    // Ad post backgrounds
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.95\s*\)\s*0%\s*,\s*rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.9\s*\)\s*100%\s*\)/i', 'linear-gradient(135deg, rgba(50, 50, 70, 0.95) 0%, rgba(45, 45, 65, 0.9) 100%)', $css);
    
    // Boosted post backgrounds
    $css = preg_replace('/linear-gradient\s*\(\s*135deg\s*,\s*rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.95\s*\)\s*0%\s*,\s*rgba\s*\(\s*255\s*,\s*255\s*,\s*255\s*,\s*0\.9\s*\)\s*100%\s*\)/i', 'linear-gradient(135deg, rgba(50, 50, 70, 0.95) 0%, rgba(45, 45, 65, 0.9) 100%)', $css);
    
    return $css;
}

/**
 * Apply dark mode transformations to HTML content
 */
function applyDarkModeTransformations($html) {
    // Transform inline styles
    $html = preg_replace_callback('/style="([^"]*)"/i', function($matches) {
        $transformedStyles = transformColorsToDarkMode($matches[1]);
        return 'style="' . $transformedStyles . '"';
    }, $html);
    
    // Transform style blocks
    $html = preg_replace_callback('/<style[^>]*>([\s\S]*?)<\/style>/i', function($matches) {
        $transformedCSS = transformColorsToDarkMode($matches[1]);
        return '<style>' . $transformedCSS . '</style>';
    }, $html);
    
    // Transform style attributes in SVG
    $html = preg_replace_callback('/style=\'([^\']*)\'/i', function($matches) {
        $transformedStyles = transformColorsToDarkMode($matches[1]);
        return 'style=\'' . $transformedStyles . '\'';
    }, $html);
    
    return $html;
}

/**
 * Initialize dark mode system for family page
 */
function initDarkMode($pdo = null) {
    if (isDarkModeEnabled($pdo)) {
        // Start output buffering to transform the entire page
        if (!ob_get_level()) {
            ob_start(function($buffer) {
                return applyDarkModeTransformations($buffer);
            });
        }
        
        // Add additional CSS for dark mode enhancements specific to family page
        echo '<style>
            /* Dark Mode Overrides for Family Page */
            .dark-mode-enabled {
                /* Additional dark mode styles */
            }
            
            /* Force dark backgrounds for common elements */
            .dark-mode-enabled body {
                background-color: #121212 !important;
                color: #e0e0e0 !important;
            }
            
            /* Navigation in dark mode */
            .dark-mode-enabled .nav-container {
                background: rgba(40, 40, 60, 0.95) !important;
                border-color: #8a7bf0 !important;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3) !important;
            }
            
            .dark-mode-enabled .nav-link {
                color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .nav-link:hover,
            .dark-mode-enabled .nav-link.active {
                background: #8a7bf0 !important;
                color: #121212 !important;
            }
            
            /* Search bar in dark mode */
            .dark-mode-enabled .search-box {
                background: rgba(60, 60, 80, 0.8) !important;
                color: #e0e0e0 !important;
                border-color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .search-box::placeholder {
                color: #a0a0a0 !important;
            }
            
            .dark-mode-enabled .search-box:focus {
                border-color: #7a6ae0 !important;
                box-shadow: 0 4px 12px rgba(138, 123, 240, 0.3) !important;
            }
            
            /* Posts in dark mode */
            .dark-mode-enabled .post {
                background: rgba(40, 40, 60, 0.95) !important;
                border-color: #8a7bf0 !important;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3) !important;
            }
            
            .dark-mode-enabled .post:hover {
                box-shadow: 0 12px 35px rgba(0, 0, 0, 0.4) !important;
            }
            
            .dark-mode-enabled .post-content {
                color: #e0e0e0 !important;
            }
            
            .dark-mode-enabled .username {
                color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .timestamp {
                color: #b0b0b0 !important;
            }
            
            .dark-mode-enabled .hashtag {
                color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .actions span,
            .dark-mode-enabled .actions button {
                color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .actions span:hover,
            .dark-mode-enabled .actions button:hover {
                color: #7a6ae0 !important;
            }
            
            /* Follow button in dark mode */
            .dark-mode-enabled .follow-btn {
                background: linear-gradient(135deg, #8a7bf0, #7a6ae0) !important;
                color: #121212 !important;
                box-shadow: 0 2px 8px rgba(138, 123, 240, 0.4) !important;
            }
            
            .dark-mode-enabled .follow-btn.following {
                background: linear-gradient(135deg, #606070, #505060) !important;
                color: #e0e0e0 !important;
            }
            
            .dark-mode-enabled .follow-btn:hover:not(.following) {
                background: linear-gradient(135deg, #7a6ae0, #6a5ad0) !important;
            }
            
            /* No videos message in dark mode */
            .dark-mode-enabled .no-videos {
                background: rgba(40, 40, 60, 0.95) !important;
                border-color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .no-videos h3 {
                color: #8a7bf0 !important;
            }
            
            .dark-mode-enabled .no-videos p {
                color: #b0b0b0 !important;
            }
            
            .dark-mode-enabled .explore-btn {
                background: linear-gradient(135deg, #8a7bf0, #7a6ae0) !important;
                color: #121212 !important;
                box-shadow: 0 4px 12px rgba(138, 123, 240, 0.4) !important;
            }
            
            .dark-mode-enabled .explore-btn:hover {
                background: linear-gradient(135deg, #7a6ae0, #6a5ad0) !important;
            }
            
            /* Ad posts in dark mode */
            .dark-mode-enabled .ad-post {
                background: linear-gradient(135deg, rgba(50, 50, 70, 0.95) 0%, rgba(45, 45, 65, 0.9) 100%) !important;
                border-color: #ffd700 !important;
            }
            
            .dark-mode-enabled .ad-header {
                color: #e0e0e0 !important;
            }
            
            .dark-mode-enabled .ad-description {
                color: #c0c0c0 !important;
            }
            
            .dark-mode-enabled .cta-button {
                background: linear-gradient(135deg, #8a7bf0, #7a6ae0) !important;
                color: #121212 !important;
                box-shadow: 0 4px 12px rgba(138, 123, 240, 0.4) !important;
            }
            
            .dark-mode-enabled .cta-button:hover {
                background: linear-gradient(135deg, #7a6ae0, #6a5ad0) !important;
            }
            
            /* Boosted posts in dark mode */
            .dark-mode-enabled .boosted-post {
                background: linear-gradient(135deg, rgba(50, 50, 70, 0.95) 0%, rgba(45, 45, 65, 0.9) 100%) !important;
                border-color: #28a745 !important;
            }
            
            /* Fullscreen video overlay adjustments */
            .dark-mode-enabled .fullscreen-close-btn,
            .dark-mode-enabled .fullscreen-nav-btn {
                background: rgba(60, 60, 80, 0.9) !important;
                color: #e0e0e0 !important;
            }
            
            .dark-mode-enabled .fullscreen-close-btn:hover,
            .dark-mode-enabled .fullscreen-nav-btn:hover {
                background: rgba(80, 80, 100, 0.9) !important;
            }
            
            .dark-mode-enabled .video-counter {
                background: rgba(60, 60, 80, 0.9) !important;
                color: #e0e0e0 !important;
            }
        </style>';
        
        // Add JavaScript for dynamic content transformation
        echo '<script>
            (function() {
                function initializeDarkMode() {
                    document.body.classList.add("dark-mode-enabled");
                    
                    // Transform existing inline styles
                    function transformExistingStyles() {
                        const elements = document.querySelectorAll("*");
                        elements.forEach(element => {
                            if (element.style) {
                                transformElementStyles(element);
                            }
                        });
                    }
                    
                    function transformElementStyles(element) {
                        const style = element.style;
                        
                        // Transform background colors
                        if (style.backgroundColor) {
                            style.backgroundColor = transformColorToDarkMode(style.backgroundColor);
                        }
                        
                        // Transform text colors
                        if (style.color) {
                            style.color = transformColorToDarkMode(style.color, true);
                        }
                        
                        // Transform border colors
                        if (style.borderColor) {
                            style.borderColor = transformColorToDarkMode(style.borderColor);
                        }
                        
                        // Transform background images (gradients)
                        if (style.backgroundImage) {
                            style.backgroundImage = transformGradientToDarkMode(style.backgroundImage);
                        }
                    }
                    
                    function transformColorToDarkMode(color, isText = false) {
                        color = color.toLowerCase().trim();
                        
                        // White to dark backgrounds
                        if (color === "white" || color === "#ffffff" || color === "#fff") {
                            return isText ? "#e0e0e0" : "#121212";
                        }
                        
                        // Light backgrounds to dark
                        if (color.includes("rgb(247, 248, 250)") || 
                            color.includes("rgb(248, 249, 250)") ||
                            color.includes("#f7fafc") ||
                            color.includes("#f8f9fa")) {
                            return "#1a1a2e";
                        }
                        
                        // Light gray to dark gray
                        if (color.includes("rgb(226, 232, 240)") || 
                            color.includes("#e2e8f0")) {
                            return "#2d3748";
                        }
                        
                        // Dark text to light text
                        if (isText && (color === "#333333" || color === "#2d3748" || color === "#1a202c")) {
                            return "#e0e0e0";
                        }
                        
                        // Medium gray text to lighter gray
                        if (isText && (color === "#4a5568" || color === "#718096")) {
                            return "#b0b0b0";
                        }
                        
                        return color;
                    }
                    
                    function transformGradientToDarkMode(gradient) {
                        gradient = gradient.toLowerCase();
                        
                        // Transform specific light gradient to dark
                        if (gradient.includes("667eea") && gradient.includes("764ba2")) {
                            return "linear-gradient(135deg, #1a1a2e 0%, #16213e 100%)";
                        }
                        
                        // Transform white gradients to dark
                        if (gradient.includes("rgba(255, 255, 255") || gradient.includes("#ffffff")) {
                            return gradient.replace(/rgba\\(255,\\s*255,\\s*255[^)]*\\)|#ffffff/gi, "rgba(40, 40, 60, 0.95)")
                                          .replace(/rgba\\(248,\\s*249,\\s*250[^)]*\\)/gi, "rgba(50, 50, 70, 0.8)");
                        }
                        
                        return gradient;
                    }
                    
                    // Transform existing content
                    transformExistingStyles();
                    
                    // Force body background and text color
                    document.body.style.backgroundColor = "#121212";
                    document.body.style.color = "#e0e0e0";
                    
                    // Transform dynamically loaded content
                    const observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) { // Element node
                                    transformElementStyles(node);
                                    if (node.querySelectorAll) {
                                        node.querySelectorAll("*").forEach(child => {
                                            transformElementStyles(child);
                                        });
                                    }
                                }
                            });
                        });
                    });
                    
                    observer.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                }
                
                // Initialize when DOM is ready
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", initializeDarkMode);
                } else {
                    initializeDarkMode();
                }
            })();
        </script>';
    }
}

/**
 * Utility function to check if dark mode is enabled (for conditional logic)
 */
function darkModeEnabled($pdo = null) {
    return isDarkModeEnabled($pdo);
}

/**
 * Manual transformation function for specific content
 */
function transformContentToDarkMode($content) {
    return applyDarkModeTransformations($content);
}

/**
 * Get current dark mode status
 */
function getDarkModeStatus($pdo = null) {
    return isDarkModeEnabled($pdo);
}

// Auto-initialize if session is active and no output has been sent
if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
    initDarkMode();
}
?>