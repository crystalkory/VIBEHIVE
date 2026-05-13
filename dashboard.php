<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


// Get user's balance
$stmt = $pdo->prepare("SELECT balance FROM user_balance WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_balance = $stmt->fetch(PDO::FETCH_ASSOC);

$balance = $user_balance ? $user_balance['balance'] : 0.00;

// Get recent transactions
$stmt = $pdo->prepare("
    SELECT * FROM payment_transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ad stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_ads,
        COUNT(CASE WHEN status = 'active' AND ends_at > CURRENT_TIMESTAMP THEN 1 END) as active_ads,
        SUM(CASE WHEN status = 'active' AND ends_at > CURRENT_TIMESTAMP THEN total_price ELSE 0 END) as active_spend
    FROM ads 
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$ad_stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
       body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
    color: #333;
}

.container {
    max-width: 1000px;
    margin: 0 auto;
}

h1 {
    text-align: center;
    color: white;
    margin-bottom: 30px;
    font-size: 32px;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

.nav-links {
    text-align: center;
    margin-bottom: 30px;
}

.nav-link {
    display: inline-block;
    margin: 0 10px;
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
}

.balance-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    padding: 40px;
    border-radius: 20px;
    text-align: center;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.balance-label {
    font-size: 18px;
    margin-bottom: 15px;
    color: #666;
    font-weight: 600;
}

.balance-amount {
    font-size: 56px;
    font-weight: 800;
    margin-bottom: 25px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    text-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.add-balance-btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 25px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.add-balance-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
    background: linear-gradient(135deg, #764ba2, #667eea);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    text-align: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
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
    color: #666;
    font-size: 16px;
    font-weight: 600;
}

.transactions {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.transactions h3 {
    margin-bottom: 20px;
    color: #333;
    font-size: 24px;
    font-weight: 700;
    text-align: center;
}

.transaction-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 0;
    border-bottom: 1px solid rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.transaction-item:hover {
    background: rgba(102, 126, 234, 0.05);
    border-radius: 8px;
    padding: 18px 15px;
    margin: 0 -15px;
}

.transaction-item:last-child {
    border-bottom: none;
}

.transaction-amount {
    font-weight: 700;
    font-size: 18px;
}

.amount-positive {
    color: #48bb78;
    background: rgba(72, 187, 120, 0.1);
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 800;
}

.amount-negative {
    color: #f56565;
    background: rgba(245, 101, 101, 0.1);
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 800;
}

.transaction-date {
    color: #666;
    font-size: 14px;
    margin-top: 4px;
}

.no-transactions {
    text-align: center;
    padding: 50px;
    color: #666;
    font-size: 18px;
}

.test-balance-notice {
    background: rgba(214, 237, 218, 0.9);
    backdrop-filter: blur(10px);
    color: #155724;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    text-align: center;
    border: 1px solid rgba(195, 230, 203, 0.5);
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
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
.container > *:nth-child(5) { animation-delay: 0.5s; }

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
    }
    
    h1 {
        font-size: 28px;
        margin-bottom: 20px;
    }
    
    .nav-link {
        display: block;
        margin: 8px auto;
        max-width: 200px;
    }
    
    .balance-card {
        padding: 30px 20px;
    }
    
    .balance-amount {
        font-size: 42px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .transactions {
        padding: 20px;
    }
    
    .transaction-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .transaction-amount {
        align-self: flex-end;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    h1 {
        font-size: 24px;
    }
    
    .balance-card {
        padding: 25px 15px;
    }
    
    .balance-amount {
        font-size: 36px;
    }
    
    .add-balance-btn {
        padding: 10px 20px;
        font-size: 14px;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .stat-number {
        font-size: 24px;
    }
    
    .transactions {
        padding: 15px;
    }
    
    .transactions h3 {
        font-size: 20px;
    }
}

/* Scrollbar Styling */
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Dashboard</h1>
        
        <div class="nav-links">
            <a href="ads.php" class="nav-link">My Ads</a>
            <a href="ads_countdown.php" class="nav-link">Active Ads</a>
            <a href="run_ads.php" class="nav-link">Create New Ad</a>
        </div>
        
        <div class="balance-card">
            <div class="balance-label">Your Balance</div>
            <div class="balance-amount">$<?= number_format($balance, 2) ?></div>
            <a href="dashboard_payment.php" class="add-balance-btn">+ Add Money</a>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $ad_stats['total_ads'] ?? 0 ?></div>
                <div class="stat-label">Total Ads</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $ad_stats['active_ads'] ?? 0 ?></div>
                <div class="stat-label">Active Ads</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">$<?= number_format($ad_stats['active_spend'] ?? 0, 2) ?></div>
                <div class="stat-label">Active Spend</div>
            </div>
        </div>
        
        <div class="transactions">
            <h3>Recent Transactions</h3>
            <?php if (empty($transactions)): ?>
                <div class="no-transactions">
                    <p>No transactions yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($transactions as $transaction): ?>
                    <div class="transaction-item">
                        <div>
                            <div style="font-weight: bold;">
                                <?= ucfirst(str_replace('_', ' ', $transaction['type'])) ?>
                            </div>
                            <div class="transaction-date">
                                <?= date('M j, Y g:i A', strtotime($transaction['created_at'])) ?>
                            </div>
                        </div>
                        <div class="transaction-amount <?= $transaction['type'] === 'deposit' ? 'amount-positive' : 'amount-negative' ?>">
                            <?= $transaction['type'] === 'deposit' ? '+' : '-' ?>$<?= number_format($transaction['amount'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>