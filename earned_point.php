<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "header.php";
require_once "footer.php";
require_once "config.php";


$userId = $_SESSION['user_id'];

// Fetch user data
$userStmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

// Get total followers
$followersStmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE followed_id = ?");
$followersStmt->execute([$userId]);
$totalFollowers = $followersStmt->fetchColumn();

// Get total comments made by user
$commentsStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ?");
$commentsStmt->execute([$userId]);
$totalComments = $commentsStmt->fetchColumn();

// Get total shares of user's posts
$sharesStmt = $pdo->prepare("
    SELECT COUNT(*) FROM shared_posts sp 
    JOIN posts p ON sp.original_post_id = p.id 
    WHERE p.user_id = ?
");
$sharesStmt->execute([$userId]);
$totalShares = $sharesStmt->fetchColumn();

// Get total likes on user's posts
$likesStmt = $pdo->prepare("
    SELECT COUNT(*) FROM likes l 
    JOIN posts p ON l.post_id = p.id 
    WHERE p.user_id = ?
");
$likesStmt->execute([$userId]);
$totalLikes = $likesStmt->fetchColumn();

// Get total members in groups where user is admin
$groupMembersStmt = $pdo->prepare("
    SELECT COUNT(gm.user_id) 
    FROM group_members gm 
    JOIN groups g ON gm.group_id = g.id 
    WHERE g.creator_id = ? AND gm.status = 'approved'
");
$groupMembersStmt->execute([$userId]);
$totalGroupMembers = $groupMembersStmt->fetchColumn();

// Get total invites (users who signed up using user's invite)
$invitesStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE invited_by = ?");
$invitesStmt->execute([$userId]);
$totalInvites = $invitesStmt->fetchColumn();

// Calculate points
$pointsFromLikes = $totalLikes * 0.001;
$pointsFromComments = $totalComments * 0.0012;
$pointsFromFollowers = $totalFollowers * 0.0014;
$pointsFromGroupMembers = $totalGroupMembers * 0.001;
$pointsFromInvites = $totalInvites * 0.002;
$pointsFromshares = $totalShares * 0.0016;

$totalPoints = $pointsFromLikes + $pointsFromComments + $pointsFromFollowers + 
               $pointsFromGroupMembers + $pointsFromInvites + $pointsFromshares;

// FIX: Convert to integer for database storage
$totalPointsForDB = (int)round($totalPoints);

// Create user_points table if it doesn't exist with correct data type
$createTableStmt = $pdo->prepare("
    CREATE TABLE IF NOT EXISTS user_points (
        user_id INTEGER PRIMARY KEY REFERENCES users(id),
        total_points INTEGER DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$createTableStmt->execute();

// Save total points to database for gift_card.php - FIXED: Using integer value
$updatePointsStmt = $pdo->prepare("
    INSERT INTO user_points (user_id, total_points, updated_at) 
    VALUES (?, ?, NOW()) 
    ON CONFLICT (user_id) 
    DO UPDATE SET total_points = ?, updated_at = NOW()
");
$updatePointsStmt->execute([$userId, $totalPointsForDB, $totalPointsForDB]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Earned Points - Tech Titans</title>
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
    padding: 20px;
    color: #333;
}

.container {
    max-width: 450px;
    margin: 0 auto;
    margin-top: 50px;
}

.header {
    text-align: center;
    margin-bottom: 30px;
    color: white;
}

.header h1 {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 10px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.3);
    background: linear-gradient(135deg, #ffffff, #e2e8f0);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.header p {
    font-size: 16px;
    opacity: 0.9;
    font-weight: 500;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    text-align: center;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.stat-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.stat-card.likes { 
    background: rgba(255, 255, 255, 0.95); 
    color: #2d3748; 
}
.stat-card.comments { 
    background: rgba(255, 255, 255, 0.95); 
    color: #2d3748; 
}
.stat-card.followers { 
    background: rgba(255, 255, 255, 0.95); 
    color: #2d3748; 
}
.stat-card.shares { 
    background: rgba(255, 255, 255, 0.95); 
    color: #2d3748; 
}
.stat-card.members { 
    background: rgba(255, 255, 255, 0.95); 
    color: #2d3748; 
}
.stat-card.invites { 
    background: rgba(255, 255, 255, 0.95); 
    color: #2d3748; 
}

.stat-card.likes::before { background: linear-gradient(135deg, #f56565, #e53e3e); }
.stat-card.comments::before { background: linear-gradient(135deg, #4299e1, #3182ce); }
.stat-card.followers::before { background: linear-gradient(135deg, #48bb78, #38a169); }
.stat-card.shares::before { background: linear-gradient(135deg, #ed8936, #dd6b20); }
.stat-card.members::before { background: linear-gradient(135deg, #9f7aea, #805ad5); }
.stat-card.invites::before { background: linear-gradient(135deg, #0bc5ea, #00b5d8); }

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
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.points-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 25px;
    padding: 30px;
    text-align: center;
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
    margin-bottom: 25px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-top: 5px solid #ffd700;
    position: relative;
    overflow: hidden;
}

.points-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 140, 0, 0.1));
    z-index: 0;
}

.points-container > * {
    position: relative;
    z-index: 1;
}

.total-points {
    font-size: 56px;
    font-weight: 800;
    color: #2d3748;
    margin: 20px 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.1);
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.points-label {
    font-size: 18px;
    color: #666;
    margin-bottom: 20px;
    font-weight: 600;
}

.points-breakdown {
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 20px;
    margin-top: 20px;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    font-size: 14px;
    transition: all 0.2s ease;
}

.breakdown-item:hover {
    background: rgba(102, 126, 234, 0.05);
    border-radius: 8px;
    padding: 12px 10px;
    margin: 0 -10px;
}

.breakdown-item:last-child {
    border-bottom: none;
}

.breakdown-points {
    font-weight: 700;
    color: #667eea;
    background: rgba(102, 126, 234, 0.1);
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 13px;
}

.navigation {
    text-align: center;
    margin-top: 25px;
}

.nav-btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 15px 35px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.2);
    width: 100%;
    max-width: 300px;
    position: relative;
    overflow: hidden;
}

.nav-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.nav-btn:hover::before {
    left: 100%;
}

.nav-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(102, 126, 234, 0.6);
    background: linear-gradient(135deg, #764ba2, #667eea);
}

.nav-btn:active {
    transform: translateY(-1px);
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

.header { animation-delay: 0.1s; }
.stats-grid { animation-delay: 0.2s; }
.points-container { animation-delay: 0.3s; }
.navigation { animation-delay: 0.4s; }

.stat-card {
    animation: fadeInUp 0.6s ease-out;
}

.stat-card:nth-child(1) { animation-delay: 0.5s; }
.stat-card:nth-child(2) { animation-delay: 0.6s; }
.stat-card:nth-child(3) { animation-delay: 0.7s; }
.stat-card:nth-child(4) { animation-delay: 0.8s; }
.stat-card:nth-child(5) { animation-delay: 0.9s; }
.stat-card:nth-child(6) { animation-delay: 1.0s; }

/* Responsive Design */
@media (max-width: 480px) {
    body {
        padding: 15px;
    }
    
    .container {
        max-width: 100%;
        padding: 0 10px;
    }
    
    .header h1 {
        font-size: 28px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .stat-card {
        padding: 20px 15px;
    }
    
    .stat-number {
        font-size: 32px;
    }
    
    .points-container {
        padding: 25px 20px;
        margin-bottom: 20px;
    }
    
    .total-points {
        font-size: 48px;
        margin: 15px 0;
    }
    
    .points-breakdown {
        padding: 15px;
    }
    
    .breakdown-item {
        padding: 10px 0;
        font-size: 13px;
    }
    
    .nav-btn {
        padding: 14px 25px;
        font-size: 15px;
    }
}

@media (max-width: 360px) {
    .header h1 {
        font-size: 24px;
    }
    
    .stat-card {
        padding: 18px 12px;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .total-points {
        font-size: 42px;
    }
    
    .points-container {
        padding: 20px 15px;
    }
}

/* Custom scrollbar for points breakdown if needed */
.points-breakdown::-webkit-scrollbar {
    width: 6px;
}

.points-breakdown::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
}

.points-breakdown::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 3px;
}

.points-breakdown::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
}

/* Loading state for button */
.nav-btn:disabled {
    background: #a0aec0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.nav-btn:disabled:hover {
    transform: none;
    box-shadow: none;
}

/* Focus styles for accessibility */
.nav-btn:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

/* Text selection */
::selection {
    background: rgba(102, 126, 234, 0.3);
    color: #2d3748;
}

/* Additional decorative elements */
.stat-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    right: 0;
    width: 0;
    height: 0;
    border-style: solid;
    border-width: 0 0 20px 20px;
    border-color: transparent transparent rgba(102, 126, 234, 0.1) transparent;
    transition: all 0.3s ease;
}

.stat-card:hover::after {
    border-width: 0 0 30px 30px;
}

/* Points container glow effect */
.points-container {
    position: relative;
}

.points-container::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    background: linear-gradient(135deg, #667eea, #764ba2, #ffd700, #ff8c00);
    border-radius: 27px;
    z-index: -1;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.points-container:hover::after {
    opacity: 0.3;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎯 Earned Points</h1>
        <p>Track your engagement and earn rewards!</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card likes">
            <div class="stat-number"><?= $totalLikes ?></div>
            <div class="stat-label">Likes Received</div>
        </div>
        
        <div class="stat-card comments">
            <div class="stat-number"><?= $totalComments ?></div>
            <div class="stat-label">Comments Made</div>
        </div>
        
        <div class="stat-card followers">
            <div class="stat-number"><?= $totalFollowers ?></div>
            <div class="stat-label">Followers</div>
        </div>
        
        <div class="stat-card shares">
            <div class="stat-number"><?= $totalShares ?></div>
            <div class="stat-label">Shares</div>
        </div>
        
        <div class="stat-card members">
            <div class="stat-number"><?= $totalGroupMembers ?></div>
            <div class="stat-label">Group Members</div>
        </div>
        
        <div class="stat-card invites">
            <div class="stat-number"><?= $totalInvites ?></div>
            <div class="stat-label">Successful Invites</div>
        </div>
    </div>

    <div class="points-container">
        <div class="points-label">Total Points Earned</div>
        <div class="total-points"><?= number_format($totalPoints) ?></div>
        
        <div class="points-breakdown">
            <div class="breakdown-item">
                <span>Likes :</span>
                <span class="breakdown-points"><?= number_format($pointsFromLikes, 1) ?> pts</span>
            </div>
            <div class="breakdown-item">
                <span>Comments :</span>
                <span class="breakdown-points"><?= number_format($pointsFromComments, 1) ?> pts</span>
            </div>
            <div class="breakdown-item">
                <span>Followers :</span>
                <span class="breakdown-points"><?= number_format($pointsFromFollowers, 1) ?> pts</span>
            </div>
            <div class="breakdown-item">
                <span>Group Members :</span>
                <span class="breakdown-points"><?= number_format($pointsFromGroupMembers, 1) ?> pts</span>
            </div>
            <div class="breakdown-item">
                <span>Invites :</span>
                <span class="breakdown-points"><?= number_format($pointsFromInvites, 1) ?> pts</span>
            </div>
             <div class="breakdown-item">
                <span>Shares:</span>
                <span class="breakdown-points"><?= number_format($pointsFromshares, 1) ?> pts</span>
            </div>
        </div>
    </div>

    <div class="navigation" style="margin-bottom: 60px;">
        <button class="nav-btn" onclick="window.location.href='gift_card.php'">
            🎁 Redeem Gift Cards
        </button>
    </div>
</div>
</body>
</html>