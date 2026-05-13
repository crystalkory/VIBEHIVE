<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
<div style="margin-top: 60px;">
    <p style="text-align: center;font-weight: bolder;font-size: 25px;color: #7b68ee;">All Notifications</p>
    <nav class="tabs">
    <button id="posts-btn" onclick="showTab('posts')" class="active">Request</button>
    <button id="photos-btn" onclick="showTab('photos')">group</button>
    <button id="photos-btn" onclick="showTab('message')">message</button>
    <button id="videos-btn" onclick="showTab('videos')">invite</button>
</div>

<div id="posts" class="tab-panel">
    <?php
        require_once "friend_requests_ui.php";
    ?>
</div>
<div id="photos" class="tab-panel" style="display:none;">
    <?php
        require_once "all_group_request.php";
    ?>
</div>
<div id="message" class="tab-panel">
    <?php
        require_once "friend_requests_ui.php";
    ?>
</div>
<div id="videos" class="tab-panel" style="display:none;">
    <?php
        require_once "invite.php";
    ?>
</div>
</div>
</body>
</html>
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
    min-height: 100vh;
}

div {
    padding: 20px;
    margin-top: 60px;
}

p {
    text-align: center;
    font-weight: 800;
    font-size: 28px;
    color: #7b68ee;
    margin-bottom: 25px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

nav.tabs {
    margin: 25px 0;
    border-bottom: 2px solid rgba(123, 104, 238, 0.3);
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    padding: 0 20px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    justify-content: center;
}

nav.tabs button {
    background: none;
    border: none;
    padding: 12px 24px;
    font-size: 16px;
    cursor: pointer;
    color: #7b68ee;
    border-radius: 10px;
    transition: all 0.3s ease;
    font-weight: 600;
    position: relative;
    overflow: hidden;
}

nav.tabs button:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.2);
}

nav.tabs button.active {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    font-weight: 700;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
    border-bottom: none;
    transform: translateY(-2px);
}

nav.tabs button.active::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), transparent);
    border-radius: 8px;
}

.tab-panel {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    min-height: 400px;
    margin: 0 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    display: none;
}

.tab-panel:first-of-type {
    display: block;
}

/* Animation for tab transitions */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.tab-panel {
    animation: fadeInUp 0.4s ease-out;
}

/* Header and footer spacing */
header + div {
    margin-top: 80px;
}

/* Tab indicator animation */
@keyframes tabSlide {
    from {
        transform: scaleX(0);
    }
    to {
        transform: scaleX(1);
    }
}

nav.tabs button.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 10%;
    right: 10%;
    height: 3px;
    background: white;
    border-radius: 2px;
    animation: tabSlide 0.3s ease-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 10px;
    }
    
    div {
        padding: 15px;
        margin-top: 50px;
    }
    
    p {
        font-size: 24px;
        margin-bottom: 20px;
    }
    
    nav.tabs {
        margin: 20px 0;
        padding: 12px;
        gap: 10px;
    }
    
    nav.tabs button {
        padding: 10px 18px;
        font-size: 14px;
        flex: 1;
        min-width: 120px;
        text-align: center;
    }
    
    .tab-panel {
        padding: 20px;
        margin: 0 10px;
        min-height: 350px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 5px;
    }
    
    div {
        padding: 10px;
        margin-top: 40px;
    }
    
    p {
        font-size: 22px;
        margin-bottom: 15px;
    }
    
    nav.tabs {
        margin: 15px 0;
        padding: 10px;
        gap: 8px;
        flex-direction: column;
    }
    
    nav.tabs button {
        padding: 12px;
        font-size: 14px;
        width: 100%;
        text-align: center;
    }
    
    .tab-panel {
        padding: 15px;
        margin: 0 5px;
        min-height: 300px;
    }
    
    nav.tabs button.active::after {
        left: 20%;
        right: 20%;
    }
}

/* Focus states for accessibility */
nav.tabs button:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state for tab panels */
.tab-panel.loading {
    position: relative;
    overflow: hidden;
}

.tab-panel.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { left: -100%; }
    100% { left: 100%; }
}

/* Tab counter badges */
.tab-badge {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: bold;
    margin-left: 6px;
    min-width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(255, 107, 107, 0.3);
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    nav.tabs {
        border-bottom: 2px solid #7b68ee;
    }
    
    .tab-panel {
        border: 2px solid #7b68ee;
    }
    
    nav.tabs button.active {
        background: #7b68ee;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .tab-panel,
    nav.tabs button {
        transition: none;
        animation: none;
    }
    
    nav.tabs button:hover {
        transform: none;
    }
    
    nav.tabs button.active {
        transform: none;
    }
    
    nav.tabs button.active::after {
        animation: none;
    }
    
    .tab-panel.loading::after {
        animation: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    }
    
    .tab-panel {
        background: rgba(45, 55, 72, 0.95);
        color: #e2e8f0;
    }
    
    nav.tabs {
        background: rgba(45, 55, 72, 0.8);
        border-color: rgba(123, 104, 238, 0.5);
    }
    
    p {
        color: #7b68ee;
    }
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    .tab-panel {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
        display: block !important;
    }
    
    nav.tabs {
        display: none;
    }
    
    p {
        color: #2d3748;
    }
}

/* Scrollbar styling for tab panels */
.tab-panel::-webkit-scrollbar {
    width: 8px;
}

.tab-panel::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 4px;
}

.tab-panel::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 4px;
}

.tab-panel::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}

/* Ensure content within tab panels is properly styled */
.tab-panel > * {
    max-width: 100%;
}

/* Tab hover effects */
nav.tabs button::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(123, 104, 238, 0.1), transparent);
    border-radius: 8px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

nav.tabs button:hover::before {
    opacity: 1;
}

/* Active tab glow effect */
nav.tabs button.active {
    position: relative;
    z-index: 1;
}

nav.tabs button.active::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd, #7b68ee);
    border-radius: 12px;
    z-index: -1;
    opacity: 0.5;
    filter: blur(4px);
}

/* Tab container responsive adjustments */
@media (max-width: 1024px) {
    nav.tabs {
        justify-content: flex-start;
        overflow-x: auto;
        padding-bottom: 5px;
    }
    
    nav.tabs::-webkit-scrollbar {
        height: 6px;
    }
    
    nav.tabs::-webkit-scrollbar-track {
        background: rgba(123, 104, 238, 0.1);
        border-radius: 3px;
    }
    
    nav.tabs::-webkit-scrollbar-thumb {
        background: rgba(123, 104, 238, 0.3);
        border-radius: 3px;
    }
}

/* Tab transition smoothness */
.tab-panel {
    transition: opacity 0.3s ease, transform 0.3s ease;
}

/* Empty state styling for tab panels */
.tab-panel:empty::before {
    content: 'No content available';
    display: flex;
    align-items: center;
    justify-content: center;
    height: 200px;
    color: #718096;
    font-style: italic;
    font-size: 16px;
    font-weight: 500;
}

</style>
<script>
    function showTab(tab) {
  document.querySelectorAll('.tab-panel').forEach(el => el.style.display = 'none');
  document.getElementById(tab).style.display = 'block';
  document.querySelectorAll('nav.tabs button').forEach(btn => btn.classList.remove('active'));
  document.getElementById(tab + '-btn').classList.add('active');
}
document.addEventListener('DOMContentLoaded', () => showTab('posts'));

</script>