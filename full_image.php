<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$imageUrl = $_GET['img'] ?? '';
$imageUrl = filter_var($imageUrl, FILTER_SANITIZE_URL);

// Validate URL is local upload to prevent security issues
// Adjust path check according to your uploads folder configuration
if (!$imageUrl || strpos($imageUrl, 'uploads/') !== 0) {
    die('Invalid image URL.');
}

// Optionally verify file existence if run on same server
$localPath = __DIR__ . '/' . $imageUrl;
if (!file_exists($localPath)) {
    die('Image not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Full Image View</title>
<style>
body {
    margin: 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    position: relative;
    overflow: hidden;
}

body::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 1;
}

img.full-image {
    max-width: 95vw;
    max-height: 95vh;
    object-fit: contain;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    border-radius: 15px;
    border: 3px solid #7b68ee;
    position: relative;
    z-index: 2;
    transition: all 0.3s ease;
    animation: imageFadeIn 0.6s ease-out;
}

img.full-image:hover {
    transform: scale(1.02);
    box-shadow: 0 25px 60px rgba(123, 104, 238, 0.4);
    border-color: #6a5acd;
}

@keyframes imageFadeIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

/* Close button style */
.close-btn {
    position: fixed;
    top: 20px;
    right: 20px;
    background: rgba(123, 104, 238, 0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    font-size: 24px;
    cursor: pointer;
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.close-btn:hover {
    background: rgba(106, 90, 205, 0.9);
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.5);
}

/* Download button */
.download-btn {
    position: fixed;
    top: 20px;
    left: 20px;
    background: rgba(123, 104, 238, 0.9);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 12px 24px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    z-index: 3;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    text-decoration: none;
}

.download-btn:hover {
    background: rgba(106, 90, 205, 0.9);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.5);
}

/* Image info */
.image-info {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    font-size: 14px;
    z-index: 3;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(123, 104, 238, 0.3);
}

/* Navigation arrows for multiple images (if applicable) */
.nav-arrow {
    position: fixed;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(123, 104, 238, 0.8);
    color: white;
    border: none;
    border-radius: 50%;
    width: 60px;
    height: 60px;
    font-size: 24px;
    cursor: pointer;
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.nav-arrow:hover {
    background: rgba(106, 90, 205, 0.9);
    transform: translateY(-50%) scale(1.1);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.5);
}

.nav-arrow.prev {
    left: 20px;
}

.nav-arrow.next {
    right: 20px;
}

/* Responsive Design */
@media (max-width: 768px) {
    img.full-image {
        max-width: 98vw;
        max-height: 98vh;
        border-radius: 10px;
        border-width: 2px;
    }
    
    .close-btn {
        width: 45px;
        height: 45px;
        font-size: 20px;
        top: 15px;
        right: 15px;
    }
    
    .download-btn {
        padding: 10px 20px;
        font-size: 14px;
        top: 15px;
        left: 15px;
    }
    
    .nav-arrow {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .image-info {
        font-size: 12px;
        padding: 8px 16px;
        bottom: 15px;
    }
}

@media (max-width: 480px) {
    img.full-image {
        max-width: 100vw;
        max-height: 100vh;
        border-radius: 0;
        border: none;
    }
    
    .close-btn {
        width: 40px;
        height: 40px;
        font-size: 18px;
        top: 10px;
        right: 10px;
    }
    
    .download-btn {
        padding: 8px 16px;
        font-size: 13px;
        top: 10px;
        left: 10px;
    }
    
    .nav-arrow {
        width: 45px;
        height: 45px;
        font-size: 18px;
    }
    
    .image-info {
        font-size: 11px;
        padding: 6px 12px;
        bottom: 10px;
    }
}

/* Loading animation */
.loading {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 50px;
    height: 50px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top: 3px solid #7b68ee;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    z-index: 2;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}
</style>
</head>
<body>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Full Image View - Fbclone</title>
<style>
/* Add the CSS code above here */
</style>
</head>
<body>

<button class="close-btn" onclick="window.history.back()">✕</button>
<a href="<?= htmlspecialchars($imageUrl) ?>" download class="download-btn">
    📥 Download
</a>
<div class="loading" id="loadingSpinner"></div>
<img src="<?= htmlspecialchars($imageUrl) ?>" alt="Full Image" class="full-image" 
     onload="document.getElementById('loadingSpinner').style.display='none'" 
     onerror="document.getElementById('loadingSpinner').style.display='none'" />

<script>
// Hide loading spinner when image loads
document.addEventListener('DOMContentLoaded', function() {
    const img = document.querySelector('.full-image');
    const spinner = document.getElementById('loadingSpinner');
    
    if (img.complete) {
        spinner.style.display = 'none';
    }
    
    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.history.back();
        }
    });
});
</script>

</body>
</html>

</body>
</html>
