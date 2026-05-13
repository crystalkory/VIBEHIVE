<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


// Get all ads for this user
$stmt = $pdo->prepare("
    SELECT *, 
           CASE 
               WHEN status = 'active' AND ends_at > CURRENT_TIMESTAMP THEN 'active'
               WHEN status = 'active' AND ends_at <= CURRENT_TIMESTAMP THEN 'completed'
               ELSE status
           END as display_status
    FROM ads 
    WHERE user_id = ? 
    ORDER BY 
        CASE 
            WHEN status = 'active' AND ends_at > CURRENT_TIMESTAMP THEN 1
            WHEN status = 'pending' THEN 2
            ELSE 3
        END,
        created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Ads</title>
    <style>
       body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
}

.container {
    max-width: 1200px;
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

.status-filters {
    text-align: center;
    margin-bottom: 30px;
}

.status-filter {
    display: inline-block;
    margin: 0 5px 10px 5px;
    padding: 8px 20px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    color: #666;
    text-decoration: none;
    border-radius: 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.status-filter:hover {
    background: rgba(255, 255, 255, 0.9);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.status-filter.active {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.ads-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 25px;
}

.ad-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.ad-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.ad-media {
    height: 180px;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #667eea;
    font-size: 18px;
    font-weight: 700;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.ad-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.ad-content {
    padding: 25px;
}

.ad-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    gap: 10px;
}

.ad-title {
    font-size: 18px;
    font-weight: 800;
    color: #2d3748;
    flex: 1;
    margin-right: 10px;
    line-height: 1.3;
}

.ad-status {
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
}

.status-active {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.status-pending {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    color: white;
}

.status-completed {
    background: linear-gradient(135deg, #a0aec0, #718096);
    color: white;
}

.status-cancelled {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    color: white;
}

.ad-details {
    font-size: 14px;
    color: #666;
    margin-bottom: 20px;
    line-height: 1.5;
}

.ad-details div {
    margin-bottom: 6px;
    padding: 4px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.ad-details div:last-of-type {
    border-bottom: none;
}

.ad-details strong {
    color: #2d3748;
    font-weight: 700;
}

.ad-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 700;
    flex: 1;
    text-align: center;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    text-decoration: none;
}

.btn-repost {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.btn-repost:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
}

.btn-view {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.btn-view:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.no-ads {
    text-align: center;
    padding: 60px 40px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    color: #666;
    grid-column: 1 / -1;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.no-ads h3 {
    color: #2d3748;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 15px;
}

.no-ads p {
    font-size: 16px;
    margin-bottom: 20px;
    opacity: 0.8;
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

.ad-card {
    animation: fadeInUp 0.6s ease-out;
}

.ad-card:nth-child(1) { animation-delay: 0.4s; }
.ad-card:nth-child(2) { animation-delay: 0.5s; }
.ad-card:nth-child(3) { animation-delay: 0.6s; }
.ad-card:nth-child(4) { animation-delay: 0.7s; }
.ad-card:nth-child(5) { animation-delay: 0.8s; }
.ad-card:nth-child(6) { animation-delay: 0.9s; }

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
    }
    
    h1 {
        font-size: 28px;
    }
    
    .ads-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .ad-content {
        padding: 20px;
    }
    
    .ad-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .ad-status {
        align-self: flex-start;
    }
    
    .ad-actions {
        flex-direction: column;
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
    
    .ads-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .ad-media {
        height: 150px;
    }
    
    .ad-content {
        padding: 15px;
    }
    
    .ad-title {
        font-size: 16px;
    }
    
    .btn {
        padding: 12px 20px;
        font-size: 14px;
    }
    
    .no-ads {
        padding: 40px 20px;
    }
    
    .no-ads h3 {
        font-size: 20px;
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

/* Loading state for buttons */
.btn:active {
    transform: scale(0.95);
    transition: transform 0.1s ease;
}

/* Status filter active state enhancement */
.status-filter.active:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

/* Card hover effects enhancement */
.ad-card:hover .ad-media {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.15), rgba(118, 75, 162, 0.15));
}

/* Text selection styling */
::selection {
    background: rgba(102, 126, 234, 0.3);
    color: #2d3748;
}
    </style>
</head>
<body>
    <div class="container">
        <h1>My Ads</h1>
        
        <div class="nav-links">
            <a href="ads_countdown.php" class="nav-link">Active Ads Countdown</a>
            <a href="run_ads.php" class="nav-link">Create New Ad</a>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
        </div>
        
        <div class="ads-grid">
            <?php if (empty($ads)): ?>
                <div class="no-ads">
                    <h3>No Ads Created</h3>
                    <p>You haven't created any ads yet.</p>
                    <a href="run_ads.php" style="color: #007bff;">Create your first ad</a>
                </div>
            <?php else: ?>
                <?php foreach ($ads as $ad): 
                    $locations = json_decode($ad['locations'], true);
                    $status_class = 'status-' . $ad['display_status'];
                ?>
                    <div class="ad-card">
                        <div class="ad-media">
                            <?php if ($ad['ad_type'] === 'video'): ?>
                                <div>🎬 Video Ad</div>
                            <?php else: ?>
                                <?php 
                                $images = explode(',', $ad['media_path']);
                                if (!empty($images[0])): 
                                ?>
                                    <img src="<?= htmlspecialchars($images[0]) ?>" alt="Ad Image">
                                <?php else: ?>
                                    <div>🖼️ Image Ad</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="ad-content">
                            <div class="ad-header">
                                <div class="ad-title"><?= htmlspecialchars($ad['header']) ?></div>
                                <div class="ad-status <?= $status_class ?>">
                                    <?= ucfirst($ad['display_status']) ?>
                                </div>
                            </div>
                            
                            <div class="ad-details">
                                <div><strong>Type:</strong> <?= ucfirst($ad['ad_type']) ?></div>
                                <div><strong>Locations:</strong> <?= count($locations) ?> countries</div>
                                <div><strong>Cost:</strong> $<?= number_format($ad['total_price'], 2) ?></div>
                                <div><strong>Created:</strong> <?= date('M j, Y', strtotime($ad['created_at'])) ?></div>
                                <?php if ($ad['display_status'] === 'active'): ?>
                                    <div><strong>Ends:</strong> <?= date('M j, Y', strtotime($ad['ends_at'])) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="ad-actions">
                                <a href="run_ads.php?repost=<?= $ad['id'] ?>" class="btn btn-repost">Repost</a>
                                <a href="ads_countdown.php" class="btn btn-view">View</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>