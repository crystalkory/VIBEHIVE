<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Get active ads for this user
$stmt = $pdo->prepare("
    SELECT *, 
           EXTRACT(EPOCH FROM (ends_at - CURRENT_TIMESTAMP)) as seconds_remaining,
           EXTRACT(DAYS FROM (ends_at - CURRENT_TIMESTAMP)) as days_remaining
    FROM ads 
    WHERE user_id = ? AND status = 'active' AND ends_at > CURRENT_TIMESTAMP
    ORDER BY ends_at ASC
");
$stmt->execute([$_SESSION['user_id']]);
$active_ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get like count for an ad
function getAdLikeCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_likes WHERE ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

// Function to get comment count for an ad
function getAdCommentCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_comments WHERE ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

// Function to get share count for an ad
function getAdShareCount($pdo, $adId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_ads WHERE original_ad_id = ?");
    $stmt->execute([$adId]);
    return (int)$stmt->fetchColumn();
}

// Get engagement counts for all ads
$ads_with_engagement = [];
foreach ($active_ads as $ad) {
    $ad['like_count'] = getAdLikeCount($pdo, $ad['id']);
    $ad['comment_count'] = getAdCommentCount($pdo, $ad['id']);
    $ad['share_count'] = getAdShareCount($pdo, $ad['id']);
    $ads_with_engagement[] = $ad;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Ads Countdown</title>
    <style>
       body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
}

.container {
    max-width: 900px;
    margin: 0 auto;
}

h1 {
    text-align: center;
    color: white;
    margin-bottom: 30px;
    font-size: 36px;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.ad-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    padding: 25px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    margin-bottom: 25px;
    border-left: 5px solid #667eea;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.ad-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.ad-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.ad-title {
    font-size: 20px;
    font-weight: 800;
    color: #2d3748;
    flex: 1;
    min-width: 200px;
}

.countdown {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: 700;
    font-size: 14px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
    min-width: 140px;
    text-align: center;
}

.countdown.urgent {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    animation: pulse 2s infinite;
    box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
}

@keyframes pulse {
    0% { 
        transform: scale(1); 
        box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
    }
    50% { 
        transform: scale(1.05); 
        box-shadow: 0 6px 20px rgba(245, 101, 101, 0.6);
    }
    100% { 
        transform: scale(1); 
        box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
    }
}

.ad-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.detail-item {
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.detail-item:hover {
    background: rgba(255, 255, 255, 0.9);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.detail-label {
    font-size: 12px;
    color: #666;
    margin-bottom: 5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-weight: 700;
    color: #2d3748;
    font-size: 16px;
}

.engagement-stats {
    display: flex;
    gap: 20px;
    margin: 20px 0;
    padding: 20px;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    border-radius: 15px;
    border: 1px solid rgba(102, 126, 234, 0.2);
    backdrop-filter: blur(10px);
}

.engagement-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    padding: 10px;
    border-radius: 10px;
    transition: all 0.2s ease;
}

.engagement-item:hover {
    background: rgba(255, 255, 255, 0.5);
    transform: translateY(-2px);
}

.engagement-count {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 5px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.engagement-label {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.engagement-actions {
    display: flex;
    gap: 12px;
    margin-top: 15px;
}

.engagement-btn {
    flex: 1;
    padding: 12px 20px;
    border: none;
    border-radius: 12px;
    color: white;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
    text-align: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.engagement-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    text-decoration: none;
    color: white;
}

.engagement-btn.comments {
    background: linear-gradient(135deg, #48bb78, #38a169);
}

.engagement-btn.comments:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
}

.engagement-btn.likes {
    background: linear-gradient(135deg, #f56565, #e53e3e);
}

.engagement-btn.likes:hover {
    background: linear-gradient(135deg, #e53e3e, #c53030);
    box-shadow: 0 6px 20px rgba(245, 101, 101, 0.4);
}

.engagement-btn.shares {
    background: linear-gradient(135deg, #9f7aea, #805ad5);
}

.engagement-btn.shares:hover {
    background: linear-gradient(135deg, #805ad5, #6b46c1);
    box-shadow: 0 6px 20px rgba(159, 122, 234, 0.4);
}

.no-ads {
    text-align: center;
    padding: 60px 40px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    color: #666;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.no-ads h3 {
    color: #2d3748;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 15px;
}

.no-ads a {
    color: #667eea;
    text-decoration: none;
    font-weight: 700;
    font-size: 16px;
    transition: all 0.3s ease;
}

.no-ads a:hover {
    color: #764ba2;
    text-decoration: underline;
}

.nav-links {
    text-align: center;
    margin-bottom: 40px;
}

.nav-link {
    display: inline-block;
    margin: 0 8px 10px 8px;
    padding: 12px 24px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #667eea;
    text-decoration: none;
    border-radius: 25px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.nav-link:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    color: #764ba2;
    text-decoration: none;
}

.ad-description {
    margin: 20px 0;
    padding: 20px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    border-left: 4px solid #667eea;
    transition: all 0.2s ease;
}

.ad-description:hover {
    background: rgba(255, 255, 255, 0.9);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.description-text {
    color: #555;
    line-height: 1.6;
    font-size: 15px;
}

.performance-badge {
    display: inline-block;
    padding: 6px 12px;
    color: white;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    margin-left: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.performance-badge.high {
    background: linear-gradient(135deg, #48bb78, #38a169);
}

.performance-badge.medium {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
}

.performance-badge.low {
    background: linear-gradient(135deg, #f56565, #e53e3e);
}

.stats-summary {
    text-align: center;
    margin-bottom: 40px;
    padding: 30px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.stats-summary h3 {
    color: #2d3748;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.stat-item {
    padding: 20px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    text-align: center;
    border: 1px solid rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.stat-item:hover {
    background: rgba(255, 255, 255, 0.9);
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.stat-number {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 8px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.stat-label {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
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

.container > *:nth-child(1) { animation-delay: 0.1s; }
.container > *:nth-child(2) { animation-delay: 0.2s; }
.container > *:nth-child(3) { animation-delay: 0.3s; }
.container > *:nth-child(4) { animation-delay: 0.4s; }

.ad-card {
    animation: fadeInUp 0.6s ease-out;
}

.ad-card:nth-child(1) { animation-delay: 0.5s; }
.ad-card:nth-child(2) { animation-delay: 0.6s; }
.ad-card:nth-child(3) { animation-delay: 0.7s; }
.ad-card:nth-child(4) { animation-delay: 0.8s; }

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
    }
    
    h1 {
        font-size: 28px;
    }
    
    .ad-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .ad-title {
        min-width: auto;
    }
    
    .countdown {
        align-self: flex-start;
    }
    
    .engagement-stats {
        flex-direction: column;
        gap: 15px;
    }
    
    .engagement-actions {
        flex-direction: column;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .nav-link {
        display: block;
        margin: 8px auto;
        max-width: 200px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    h1 {
        font-size: 24px;
    }
    
    .ad-card {
        padding: 20px;
    }
    
    .ad-details {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .engagement-count {
        font-size: 24px;
    }
    
    .countdown {
        font-size: 12px;
        padding: 8px 16px;
    }
}

/* Custom scrollbar */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
}

/* Loading animation for engagement buttons */
.engagement-btn:active {
    transform: scale(0.95);
    transition: transform 0.1s ease;
}
    </style>
</head>
<body>
    <div class="container">
        <h1>Active Ads Countdown</h1>
        
        <div class="nav-links">
            <a href="ads.php" class="nav-link">All Ads</a>
            <a href="run_ads.php" class="nav-link">Create New Ad</a>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="home.php" class="nav-link">Back to Feed</a>
        </div>

        <?php if (empty($ads_with_engagement)): ?>
            <div class="no-ads">
                <h3>No Active Ads</h3>
                <p>You don't have any active ads running at the moment.</p>
                <a href="run_ads.php" style="color: #007bff; text-decoration: none; font-weight: bold;">Create your first ad</a>
            </div>
        <?php else: ?>
            <!-- Stats Summary -->
            <div class="stats-summary">
                <h3>Overall Performance Summary</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?= count($ads_with_engagement) ?></div>
                        <div class="stat-label">Active Ads</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?= array_sum(array_column($ads_with_engagement, 'like_count')) ?>
                        </div>
                        <div class="stat-label">Total Likes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?= array_sum(array_column($ads_with_engagement, 'comment_count')) ?>
                        </div>
                        <div class="stat-label">Total Comments</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?= array_sum(array_column($ads_with_engagement, 'share_count')) ?>
                        </div>
                        <div class="stat-label">Total Shares</div>
                    </div>
                </div>
            </div>

            <?php foreach ($ads_with_engagement as $ad): 
                $days_remaining = floor($ad['seconds_remaining'] / 86400);
                $hours_remaining = floor(($ad['seconds_remaining'] % 86400) / 3600);
                $minutes_remaining = floor(($ad['seconds_remaining'] % 3600) / 60);
                $locations = json_decode($ad['locations'], true);
                
                // Calculate total engagement
                $total_engagement = $ad['like_count'] + $ad['comment_count'] + $ad['share_count'];
                
                // Determine performance badge
                $performance_badge = '';
                if ($total_engagement > 50) {
                    $performance_badge = 'high';
                } elseif ($total_engagement > 10) {
                    $performance_badge = 'medium';
                } else {
                    $performance_badge = 'low';
                }
            ?>
                <div class="ad-card">
                    <div class="ad-header">
                        <div class="ad-title">
                            <?= htmlspecialchars($ad['header']) ?>
                            <?php if ($total_engagement > 0): ?>
                                <span class="performance-badge <?= $performance_badge ?>">
                                    <?= $total_engagement ?> Engagements
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="countdown <?= $days_remaining < 1 ? 'urgent' : '' ?>" 
                             id="countdown-<?= $ad['id'] ?>">
                            <?php if ($days_remaining > 0): ?>
                                <?= $days_remaining ?>d <?= $hours_remaining ?>h left
                            <?php else: ?>
                                <?= $hours_remaining ?>h <?= $minutes_remaining ?>m left
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="ad-details">
                        <div class="detail-item">
                            <div class="detail-label">Type</div>
                            <div class="detail-value"><?= ucfirst($ad['ad_type']) ?> Ad</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Target Locations</div>
                            <div class="detail-value"><?= count($locations) ?> countries</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Target Gender</div>
                            <div class="detail-value"><?= $ad['gender_target'] ? ucfirst($ad['gender_target']) : 'All' ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Total Cost</div>
                            <div class="detail-value">$<?= number_format($ad['total_price'], 2) ?></div>
                        </div>
                    </div>
                    
                    <div class="ad-description">
                        <div class="detail-label">Description</div>
                        <div class="description-text"><?= htmlspecialchars($ad['description']) ?></div>
                    </div>

                    <!-- Engagement Statistics -->
                    <div class="engagement-stats">
                        <div class="engagement-item">
                            <div class="engagement-count"><?= $ad['like_count'] ?></div>
                            <div class="engagement-label">Likes</div>
                        </div>
                        <div class="engagement-item">
                            <div class="engagement-count"><?= $ad['comment_count'] ?></div>
                            <div class="engagement-label">Comments</div>
                        </div>
                        <div class="engagement-item">
                            <div class="engagement-count"><?= $ad['share_count'] ?></div>
                            <div class="engagement-label">Shares</div>
                        </div>
                    </div>

                    <!-- Engagement Actions -->
                    <div class="engagement-actions">
                        <a href="ads_comment.php?ad_id=<?= $ad['id'] ?>" 
                           class="engagement-btn comments">
                            💬 View Comments
                        </a>
                        <a href="ads_likes.php?ad_id=<?= $ad['id'] ?>" 
                           class="engagement-btn likes">
                            👍 View Likes
                        </a>
                        <button class="engagement-btn shares" onclick="viewAdShares(<?= $ad['id'] ?>)">
                            🔄 View Shares
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // Update countdown every minute
        setInterval(() => {
            location.reload();
        }, 60000);

        // Function to view ad shares
        function viewAdShares(adId) {
            alert('Share analytics for ad #' + adId + ' would be displayed here.\nThis feature shows who shared your ad and when.');
            // In a real implementation, this would open a modal or navigate to a shares page
        }

        // Add smooth scrolling for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // Add click animation to engagement buttons
            const buttons = document.querySelectorAll('.engagement-btn');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.href) { // Only for buttons that aren't links
                        e.preventDefault();
                        this.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            this.style.transform = 'scale(1)';
                        }, 150);
                    }
                });
            });
        });

        // Real-time countdown update (client-side)
        function updateCountdowns() {
            const countdowns = document.querySelectorAll('.countdown');
            countdowns.forEach(countdown => {
                const text = countdown.textContent.trim();
                if (text.includes('d')) {
                    // Handle day-based countdown
                    const [days, hours] = text.split('d');
                    const daysNum = parseInt(days);
                    const hoursNum = parseInt(hours);
                    
                    if (daysNum === 0 && hoursNum <= 1) {
                        countdown.classList.add('urgent');
                    }
                } else if (text.includes('h')) {
                    // Handle hour-based countdown (already urgent)
                    countdown.classList.add('urgent');
                }
            });
        }

        // Initialize countdown styling
        document.addEventListener('DOMContentLoaded', updateCountdowns);
    </script>
</body>
</html>