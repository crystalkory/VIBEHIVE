<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

// Get user's total points
$pointsStmt = $pdo->prepare("SELECT total_points FROM user_points WHERE user_id = ?");
$pointsStmt->execute([$userId]);
$totalPoints = $pointsStmt->fetchColumn() ?? 0;

// Create tables if not exists
$createTable1 = $pdo->prepare("
    CREATE TABLE IF NOT EXISTS unlocked_gift_cards (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id),
        gift_card_level INTEGER,
        amount INTEGER,
        unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        code VARCHAR(20) UNIQUE,
        six_digit_code VARCHAR(6) UNIQUE,
        is_used BOOLEAN DEFAULT FALSE,
        is_scratched BOOLEAN DEFAULT FALSE
    )
");
$createTable1->execute();

$createTable2 = $pdo->prepare("
    CREATE TABLE IF NOT EXISTS user_gift_card_progress (
        user_id INTEGER PRIMARY KEY REFERENCES users(id),
        current_level INTEGER DEFAULT 0,
        last_unlock_date TIMESTAMP,
        next_unlock_date TIMESTAMP
    )
");
$createTable2->execute();

// Create user_codes table if not exists
$createTable3 = $pdo->prepare("
    CREATE TABLE IF NOT EXISTS user_codes (
        user_id INTEGER PRIMARY KEY REFERENCES users(id),
        user_code VARCHAR(9) UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$createTable3->execute();

// Generate or get user's unique 9-digit code
$userCodeStmt = $pdo->prepare("SELECT user_code FROM user_codes WHERE user_id = ?");
$userCodeStmt->execute([$userId]);
$userCode = $userCodeStmt->fetchColumn();

if (!$userCode) {
    // Generate unique 9-digit user code
    $userCode = str_pad(mt_rand(1, 999999999), 9, '0', STR_PAD_LEFT);
    $insertCodeStmt = $pdo->prepare("INSERT INTO user_codes (user_id, user_code) VALUES (?, ?)");
    $insertCodeStmt->execute([$userId, $userCode]);
}

// Gift card levels configuration
$giftCardLevels = [
    1 => ['amount' => 500, 'points_required' => 1000],
    2 => ['amount' => 1000, 'points_required' => 1500],
    3 => ['amount' => 1500, 'points_required' => 2000],
    4 => ['amount' => 2000, 'points_required' => 2500],
    5 => ['amount' => 2500, 'points_required' => 3000],
    6 => ['amount' => 3000, 'points_required' => 3500],
    7 => ['amount' => 3500, 'points_required' => 4000],
    8 => ['amount' => 4000, 'points_required' => 4500],
    9 => ['amount' => 4500, 'points_required' => 5000],
    10 => ['amount' => 5000, 'points_required' => 5500],
    11 => ['amount' => 5500, 'points_required' => 6000]
];

// Determine current level based on points
$currentLevel = 0;
foreach ($giftCardLevels as $level => $card) {
    if ($totalPoints >= $card['points_required']) {
        $currentLevel = $level;
    } else {
        break;
    }
}

// Get user's unlocked gift cards with details
$unlockedStmt = $pdo->prepare("
    SELECT id, gift_card_level, amount, six_digit_code, is_used, is_scratched
    FROM unlocked_gift_cards 
    WHERE user_id = ? AND is_used = FALSE
    ORDER BY unlocked_at DESC
");
$unlockedStmt->execute([$userId]);
$unlockedCards = $unlockedStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total unlocked value
$totalUnlockedValue = 0;
$totalUnlockedCards = 0;
foreach ($unlockedCards as $card) {
    $totalUnlockedValue += $card['amount'];
    $totalUnlockedCards++;
}

// Get user progress
$progressStmt = $pdo->prepare("SELECT * FROM user_gift_card_progress WHERE user_id = ?");
$progressStmt->execute([$userId]);
$userProgress = $progressStmt->fetch(PDO::FETCH_ASSOC);

if (!$userProgress) {
    // Initialize user progress
    $initStmt = $pdo->prepare("
        INSERT INTO user_gift_card_progress (user_id, current_level, next_unlock_date) 
        VALUES (?, ?, NOW() + INTERVAL '30 days')
    ");
    $initStmt->execute([$userId, $currentLevel]);
    $userProgress = ['current_level' => $currentLevel, 'next_unlock_date' => date('Y-m-d H:i:s', strtotime('+30 days'))];
}

// Check if it's time to unlock a gift card
$now = new DateTime();
$nextUnlockDate = new DateTime($userProgress['next_unlock_date']);

if ($now >= $nextUnlockDate && $currentLevel > 0) {
    // Generate unique gift card code and 6-digit code
    $giftCardCode = 'TT' . strtoupper(bin2hex(random_bytes(4))) . $userId;
    $sixDigitCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    
    // Unlock gift card for current level
    $unlockStmt = $pdo->prepare("
        INSERT INTO unlocked_gift_cards (user_id, gift_card_level, amount, code, six_digit_code) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $unlockStmt->execute([$userId, $currentLevel, $giftCardLevels[$currentLevel]['amount'], $giftCardCode, $sixDigitCode]);
    
    // Update next unlock date (30 days from now)
    $newUnlockDate = date('Y-m-d H:i:s', strtotime('+30 days'));
    $updateStmt = $pdo->prepare("
        UPDATE user_gift_card_progress 
        SET last_unlock_date = NOW(), next_unlock_date = ? 
        WHERE user_id = ?
    ");
    $updateStmt->execute([$newUnlockDate, $userId]);
    
    // Refresh page to show new gift card
    header("Location: gift_card.php");
    exit;
}

// Handle scratch action
if (isset($_POST['scratch_card'])) {
    $cardId = $_POST['card_id'];
    $scratchStmt = $pdo->prepare("UPDATE unlocked_gift_cards SET is_scratched = TRUE WHERE id = ? AND user_id = ?");
    $scratchStmt->execute([$cardId, $userId]);
    header("Location: gift_card.php");
    exit;
}

// Calculate countdown
$countdownDate = new DateTime($userProgress['next_unlock_date']);
$timeLeft = $now->diff($countdownDate);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Gift Cards - Tech Titans</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Arial', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
    color: #333;
}

.container {
    max-width: 400px;
    margin: 0 auto;
}

.header {
    text-align: center;
    margin-bottom: 25px;
    color: white;
}

.header h1 {
    font-size: 28px;
    margin-bottom: 10px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.points-display {
    background: white;
    border-radius: 20px;
    padding: 20px;
    text-align: center;
    margin-bottom: 20px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    border: 3px solid #ffd700;
}

.total-points {
    font-size: 42px;
    font-weight: bold;
    color: #7b68ee;
    margin: 10px 0;
}

.user-code-section {
    background: rgba(255,255,255,0.95);
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    text-align: center;
}

.user-code-display {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin: 10px 0;
}

.user-code {
    font-size: 20px;
    font-weight: bold;
    color: #7b68ee;
    font-family: 'Courier New', monospace;
    letter-spacing: 2px;
}

.copy-btn {
    background: #7b68ee;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 20px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
}

.copy-btn:hover {
    background: #6a5acd;
    transform: translateY(-1px);
}

.unlocked-summary {
    background: rgba(255,255,255,0.95);
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    text-align: center;
}

.summary-stats {
    display: flex;
    justify-content: space-around;
    margin: 15px 0;
}

.stat {
    text-align: center;
}

.stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #7b68ee;
}

.stat-label {
    font-size: 12px;
    color: #666;
}

.countdown-container {
    background: rgba(255,255,255,0.95);
    border-radius: 15px;
    padding: 15px;
    text-align: center;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.countdown-title {
    font-size: 16px;
    color: #666;
    margin-bottom: 10px;
}

.countdown-timer {
    font-size: 24px;
    font-weight: bold;
    color: #e74c3c;
    font-family: 'Courier New', monospace;
}

.gift-cards-container {
    overflow-x: auto;
    white-space: nowrap;
    padding: 10px 0;
    margin-bottom: 20px;
    -webkit-overflow-scrolling: touch;
}

.gift-cards-scroll {
    display: inline-flex;
    gap: 15px;
    padding: 10px 5px;
}

.gift-card {
    width: 280px;
    height: 200px;
    border-radius: 20px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.gift-card.locked {
    filter: grayscale(1);
    opacity: 0.7;
}

.gift-card.unlocked {
    transform: scale(1.05);
    box-shadow: 0 12px 35px rgba(0,0,0,0.4);
}

.gift-card-tier-1 { background: linear-gradient(135deg, #ff6b6b, #ee5a24); }
.gift-card-tier-2 { background: linear-gradient(135deg, #ffd93d, #ff9a3d); }
.gift-card-tier-3 { background: linear-gradient(135deg, #6bcf7f, #4cd964); }
.gift-card-tier-4 { background: linear-gradient(135deg, #48cae4, #0096c7); }
.gift-card-tier-5 { background: linear-gradient(135deg, #a29bfe, #6c5ce7); }
.gift-card-tier-6 { background: linear-gradient(135deg, #fd79a8, #e84393); }
.gift-card-tier-7 { background: linear-gradient(135deg, #fdcb6e, #f39c12); }
.gift-card-tier-8 { background: linear-gradient(135deg, #00cec9, #00b894); }
.gift-card-tier-9 { background: linear-gradient(135deg, #e17055, #d63031); }
.gift-card-tier-10 { background: linear-gradient(135deg, #0984e3, #74b9ff); }
.gift-card-tier-11 { background: linear-gradient(135deg, #8e44ad, #9b59b6); }

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.card-logo {
    font-weight: bold;
    font-size: 14px;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.card-amount {
    font-size: 32px;
    font-weight: bold;
    color: white;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    margin: 10px 0;
}

.card-code {
    text-align: center;
    margin: 10px 0;
}

.six-digit-code {
    font-size: 18px;
    font-weight: bold;
    color: white;
    font-family: 'Courier New', monospace;
    letter-spacing: 3px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.blurred {
    filter: blur(8px);
    user-select: none;
    cursor: pointer;
}

.clear {
    filter: none;
    user-select: all;
}

.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}

.card-level {
    font-size: 12px;
    color: rgba(255,255,255,0.9);
    background: rgba(0,0,0,0.2);
    padding: 4px 8px;
    border-radius: 10px;
}

.scratch-btn {
    background: rgba(255,255,255,0.9);
    color: #333;
    border: none;
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
}

.scratch-btn:hover {
    background: white;
    transform: translateY(-1px);
}

.lock-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 40px;
    color: rgba(255,255,255,0.8);
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.progress-info {
    background: white;
    border-radius: 15px;
    padding: 15px;
    margin-top: 15px;
    text-align: center;
    font-size: 14px;
    color: #666;
}

.navigation {
    text-align: center;
    margin-top: 20px;
}

.nav-btn {
    background: linear-gradient(135deg, #7b68ee, #9370db);
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    margin: 5px;
}

.nav-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.level-0-message {
    background: white;
    border-radius: 15px;
    padding: 20px;
    text-align: center;
    margin: 20px 0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.level-0-message h3 {
    color: #e74c3c;
    margin-bottom: 10px;
}

.convert-btn {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 5px;
}

.convert-btn:hover {
    background: #218838;
    transform: translateY(-1px);
}

.used-badge {
    background: #dc3545;
    color: white;
    padding: 4px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: bold;
}

/* Scrollbar styling */
.gift-cards-container::-webkit-scrollbar {
    height: 6px;
}

.gift-cards-container::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
}

.gift-cards-container::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 3px;
}

.gift-cards-container::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .gift-card {
        width: 260px;
        height: 190px;
    }
    
    .total-points {
        font-size: 36px;
    }
    
    .summary-stats {
        flex-direction: column;
        gap: 10px;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎁 Gift Cards</h1>
        <p>Unlock rewards with your points!</p>
    </div>

    <div class="points-display">
        <div style="font-size: 16px; color: #666;">Your Total Points</div>
        <div class="total-points"><?= number_format($totalPoints) ?></div>
    </div>

    <div class="user-code-section">
        <h3>🔑 Your Unique Code</h3>
        <div class="user-code-display">
            <span class="user-code"><?= $userCode ?></span>
            <button class="copy-btn" onclick="copyUserCode()">📋 Copy</button>
        </div>
        <p style="font-size: 12px; color: #666; margin-top: 5px;">Use this code when converting gift cards</p>
    </div>

    <div class="unlocked-summary">
        <h3>📦 Your Unlocked Gift Cards</h3>
        <div class="summary-stats">
            <div class="stat">
                <div class="stat-number"><?= $totalUnlockedCards ?></div>
                <div class="stat-label">Total Cards</div>
            </div>
            <div class="stat">
                <div class="stat-number">$<?= number_format($totalUnlockedValue) ?></div>
                <div class="stat-label">Total Value</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?= $currentLevel ?></div>
                <div class="stat-label">Current Level</div>
            </div>
        </div>
    </div>

    <div class="countdown-container">
        <div class="countdown-title">Next Gift Card Unlock</div>
        <div class="countdown-timer" id="countdown">00:00:00</div>
    </div>

    <?php if ($currentLevel === 0): ?>
        <div class="level-0-message">
            <h3>🚫 Level 0</h3>
            <p>You need at least <strong>1,000 points</strong> to start unlocking gift cards.</p>
            <p>Keep engaging with the platform to earn more points!</p>
            <button class="nav-btn" onclick="window.location.href='earned_point.php'" style="margin-top: 15px;">
                📊 Earn More Points
            </button>
        </div>
    <?php endif; ?>

    <div class="gift-cards-container">
        <div class="gift-cards-scroll" id="giftCardsScroll">
            <?php if ($totalUnlockedCards > 0): ?>
                <?php foreach ($unlockedCards as $card): ?>
                    <div class="gift-card gift-card-tier-<?= $card['gift_card_level'] ?> unlocked">
                        <div class="card-header">
                            <div class="card-logo">Tech Titans</div>
                            <div class="card-level">Level <?= $card['gift_card_level'] ?></div>
                        </div>
                        
                        <div class="card-amount">$<?= number_format($card['amount']) ?></div>
                        
                        <div class="card-code">
                            <div class="six-digit-code <?= $card['is_scratched'] ? 'clear' : 'blurred' ?>" 
                                 onclick="<?= !$card['is_scratched'] ? 'scratchCard(' . $card['id'] . ')' : '' ?>">
                                <?= $card['six_digit_code'] ?>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <div>
                                <?php if ($card['is_used']): ?>
                                    <span class="used-badge">USED</span>
                                <?php else: ?>
                                    <?php if (!$card['is_scratched']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="scratch_card" value="1">
                                            <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                            <button type="submit" class="scratch-btn">🎯 Scratch</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="convert-btn" onclick="convertGiftCard(<?= $card['amount'] ?>, '<?= $card['six_digit_code'] ?>')">
                                            💰 Convert
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($giftCardLevels as $level => $card): ?>
                    <div class="gift-card gift-card-tier-<?= $level ?> <?= $level <= $currentLevel ? 'unlocked' : 'locked' ?>" 
                         data-level="<?= $level ?>">
                        
                        <?php if ($level > $currentLevel): ?>
                            <div class="lock-icon">🔒</div>
                        <?php endif; ?>
                        
                        <div class="card-header">
                            <div class="card-logo">Tech Titans</div>
                            <div class="card-level">Level <?= $level ?></div>
                        </div>
                        
                        <div class="card-amount">$<?= number_format($card['amount']) ?></div>
                        
                        <div class="card-footer">
                            <div class="card-status">
                                <?php if ($level <= $currentLevel): ?>
                                    ✅ Available
                                <?php else: ?>
                                    🔒 <?= number_format($card['points_required']) ?> pts
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($currentLevel > 0): ?>
    <div class="progress-info">
        <p>🎯 <strong>Current Level:</strong> <?= $currentLevel ?> ($<?= number_format($giftCardLevels[$currentLevel]['amount']) ?> cards)</p>
        <p>📈 <strong>Next Level:</strong> 
            <?php if ($currentLevel < count($giftCardLevels)): ?>
                <?= number_format($giftCardLevels[$currentLevel + 1]['points_required']) ?> points for $<?= number_format($giftCardLevels[$currentLevel + 1]['amount']) ?> cards
            <?php else: ?>
                🏆 Maximum Level Reached!
            <?php endif; ?>
        </p>
        <p>💡 You'll automatically unlock a <strong>$<?= number_format($giftCardLevels[$currentLevel]['amount']) ?> gift card</strong> every countdown cycle!</p>
    </div>
    <?php endif; ?>

    <div class="navigation">
        <button class="nav-btn" onclick="window.location.href='earned_point.php'">
            📊 View Points Breakdown
        </button>
        <?php if ($totalUnlockedCards > 0): ?>
        <button class="nav-btn" onclick="window.location.href='conversion.php'">
            💰 Convert Gift Cards
        </button>
        <?php endif; ?>
    </div>
</div>

<script>
// Countdown timer
function updateCountdown() {
    const countdownElement = document.getElementById('countdown');
    const now = new Date().getTime();
    const countdownDate = new Date("<?= $userProgress['next_unlock_date'] ?>").getTime();
    const distance = countdownDate - now;

    if (distance < 0) {
        countdownElement.textContent = "00:00:00";
        // Page will refresh automatically due to PHP logic
        return;
    }

    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

    const daysStr = days.toString().padStart(2, '0');
    const hoursStr = hours.toString().padStart(2, '0');
    const minutesStr = minutes.toString().padStart(2, '0');
    const secondsStr = seconds.toString().padStart(2, '0');

    countdownElement.textContent = `${daysStr}:${hoursStr}:${minutesStr}:${secondsStr}`;
}

// Initialize countdown
updateCountdown();
setInterval(updateCountdown, 1000);

// Copy user code
function copyUserCode() {
    const userCode = "<?= $userCode ?>";
    navigator.clipboard.writeText(userCode).then(() => {
        alert('User code copied to clipboard!');
    }).catch(() => {
        alert('Failed to copy user code. Please copy manually.');
    });
}

// Convert gift card
function convertGiftCard(amount, code) {
    if (confirm(`Convert this $${amount} gift card?\n\nYou will be redirected to the conversion page.`)) {
        window.location.href = `conversion.php?amount=${amount}&code=${code}`;
    }
}

// Auto-scroll to current level gift card
document.addEventListener('DOMContentLoaded', function() {
    const currentLevel = <?= $currentLevel ?>;
    if (currentLevel > 0) {
        const giftCards = document.getElementById('giftCardsScroll');
        const currentCard = document.querySelector(`.gift-card[data-level="${currentLevel}"]`);
        
        if (currentCard) {
            setTimeout(() => {
                currentCard.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            }, 500);
        }
    }
});

// Show notification if user just leveled up
<?php if (isset($_GET['levelup'])): ?>
setTimeout(() => {
    alert('🎉 Congratulations! You\'ve reached Level <?= $currentLevel ?>! You can now unlock $<?= number_format($giftCardLevels[$currentLevel]['amount']) ?> gift cards.');
}, 1000);
<?php endif; ?>
</script>
</body>
</html>