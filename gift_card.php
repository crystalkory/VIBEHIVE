<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "back.php";
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
    11 => ['amount' => 5500, 'points_required' => 6000],
    12 => ['amount' => 6000, 'points_required' => 6500],
    13 => ['amount' => 6500, 'points_required' => 7000],
    14 => ['amount' => 7000, 'points_required' => 7500],
    15 => ['amount' => 7500, 'points_required' => 8000],
    16 => ['amount' => 8000, 'points_required' => 8500],
    17 => ['amount' => 8500, 'points_required' => 9000],
    18 => ['amount' => 9000, 'points_required' => 9500],
    19 => ['amount' => 9500, 'points_required' => 10000],
    20 => ['amount' => 10000, 'points_required' => 10500]
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
// Handle scratch action
if (isset($_POST['scratch_card'])) {
    $cardId = $_POST['card_id'];
    
    // Check if this is a request to unlock a NEW card (starts with "new_")
    if (strpos($cardId, 'new_') === 0) {
        $level = (int) str_replace('new_', '', $cardId);
        
        // Generate unique gift card code and 6-digit code
        $giftCardCode = 'TT' . strtoupper(bin2hex(random_bytes(4))) . $userId;
        $sixDigitCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        
        // Unlock gift card for the specified level
        $unlockStmt = $pdo->prepare("
            INSERT INTO unlocked_gift_cards (user_id, gift_card_level, amount, code, six_digit_code, is_scratched) 
            VALUES (?, ?, ?, ?, ?, TRUE)
        ");
        $unlockStmt->execute([
            $userId, 
            $level, 
            $giftCardLevels[$level]['amount'], 
            $giftCardCode, 
            $sixDigitCode
        ]);
        
        // Get the ID of the newly created card
        $newCardId = $pdo->lastInsertId();
        
        // Update next unlock date (30 days from now)
        $newUnlockDate = date('Y-m-d H:i:s', strtotime('+30 days'));
        $updateStmt = $pdo->prepare("
            UPDATE user_gift_card_progress 
            SET last_unlock_date = NOW(), next_unlock_date = ? 
            WHERE user_id = ?
        ");
        $updateStmt->execute([$newUnlockDate, $userId]);
        
    } else {
        // This is scratching an existing card
        $cardId = (int) $cardId; // Convert to integer for safety
        $scratchStmt = $pdo->prepare("UPDATE unlocked_gift_cards SET is_scratched = TRUE WHERE id = ? AND user_id = ?");
        $scratchStmt->execute([$cardId, $userId]);
    }
    
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

.points-display {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 25px;
    padding: 25px;
    text-align: center;
    margin-bottom: 25px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-top: 5px solid #ffd700;
    position: relative;
    overflow: hidden;
}

.points-display::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 140, 0, 0.1));
    z-index: 0;
}

.points-display > * {
    position: relative;
    z-index: 1;
}

.total-points {
    font-size: 48px;
    font-weight: 800;
    color: #2d3748;
    margin: 15px 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.1);
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.user-code-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    text-align: center;
}

.user-code-display {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin: 15px 0;
    padding: 15px;
    background: rgba(102, 126, 234, 0.1);
    border-radius: 15px;
    border: 2px dashed rgba(102, 126, 234, 0.3);
}

.user-code {
    font-size: 24px;
    font-weight: 800;
    color: #667eea;
    font-family: 'Courier New', monospace;
    letter-spacing: 3px;
    text-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
}

.copy-btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 20px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.copy-btn:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.unlocked-summary {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    text-align: center;
}

.summary-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin: 20px 0;
}

.stat {
    text-align: center;
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 15px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.stat:hover {
    background: rgba(255, 255, 255, 0.95);
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.stat-number {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 5px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.stat-label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.countdown-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    text-align: center;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-left: 5px solid #e53e3e;
}

.countdown-title {
    font-size: 16px;
    color: #666;
    margin-bottom: 15px;
    font-weight: 600;
}

.countdown-timer {
    font-size: 32px;
    font-weight: 800;
    color: #e53e3e;
    font-family: 'Courier New', monospace;
    text-shadow: 0 2px 4px rgba(229, 62, 62, 0.2);
    background: rgba(229, 62, 62, 0.1);
    padding: 15px;
    border-radius: 15px;
    border: 2px solid rgba(229, 62, 62, 0.2);
}

.gift-cards-container {
    overflow-x: auto;
    white-space: nowrap;
    padding: 15px 0;
    margin-bottom: 25px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

.gift-cards-container::-webkit-scrollbar {
    display: none;
}

.gift-cards-scroll {
    display: inline-flex;
    gap: 20px;
    padding: 10px 5px;
}

.gift-card {
    width: 300px;
    height: 220px;
    border-radius: 25px;
    padding: 25px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 15px 40px rgba(0,0,0,0.25);
    transition: all 0.4s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.gift-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.05));
    z-index: 1;
}

.gift-card > * {
    position: relative;
    z-index: 2;
}

.gift-card.locked {
    filter: grayscale(1) brightness(0.7);
    opacity: 0.8;
    transform: scale(0.95);
}

.gift-card.unlocked {
    transform: scale(1.02);
    box-shadow: 0 20px 50px rgba(0,0,0,0.35);
    animation: cardGlow 2s ease-in-out infinite alternate;
}

@keyframes cardGlow {
    from {
        box-shadow: 0 15px 40px rgba(0,0,0,0.25);
    }
    to {
        box-shadow: 0 20px 50px rgba(255,255,255,0.3);
    }
}

/* Enhanced gradient backgrounds for each tier */
.gift-card-tier-1 { 
    background: linear-gradient(135deg, #ff6b6b, #ee5a24, #ff8e53);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-2 { 
    background: linear-gradient(135deg, #ffd93d, #ff9a3d, #ffb347);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-3 { 
    background: linear-gradient(135deg, #6bcf7f, #4cd964, #78e08f);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-4 { 
    background: linear-gradient(135deg, #48cae4, #0096c7, #00b4d8);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-5 { 
    background: linear-gradient(135deg, #a29bfe, #6c5ce7, #8e7cff);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-6 { 
    background: linear-gradient(135deg, #fd79a8, #e84393, #ff6b9d);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-7 { 
    background: linear-gradient(135deg, #fdcb6e, #f39c12, #fdcb6e);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-8 { 
    background: linear-gradient(135deg, #00cec9, #00b894, #00d4b8);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-9 { 
    background: linear-gradient(135deg, #e17055, #d63031, #e84343);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-10 { 
    background: linear-gradient(135deg, #0984e3, #74b9ff, #0984e3);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}
.gift-card-tier-11 { 
    background: linear-gradient(135deg, #8e44ad, #9b59b6, #a569bd);
    background-size: 200% 200%;
    animation: gradientShift 3s ease infinite;
}

@keyframes gradientShift {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.card-logo {
    font-weight: 800;
    font-size: 16px;
    color: white;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    background: rgba(0,0,0,0.2);
    padding: 6px 12px;
    border-radius: 12px;
}

.card-amount {
    font-size: 36px;
    font-weight: 800;
    color: white;
    text-shadow: 0 3px 6px rgba(0,0,0,0.4);
    margin: 15px 0;
    text-align: center;
    letter-spacing: 1px;
}

.card-code {
    text-align: center;
    margin: 15px 0;
}

.six-digit-code {
    font-size: 20px;
    font-weight: 800;
    color: white;
    font-family: 'Courier New', monospace;
    letter-spacing: 4px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    background: rgba(0,0,0,0.3);
    padding: 10px 15px;
    border-radius: 12px;
    display: inline-block;
    min-width: 180px;
}

.blurred {
    filter: blur(12px);
    user-select: none;
    cursor: pointer;
    background: rgba(0,0,0,0.4);
}

.clear {
    filter: none;
    user-select: all;
    animation: codeReveal 0.5s ease-out;
}

@keyframes codeReveal {
    from {
        opacity: 0;
        transform: scale(0.8);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}

.card-level {
    font-size: 13px;
    color: rgba(255,255,255,0.9);
    background: rgba(0,0,0,0.3);
    padding: 6px 12px;
    border-radius: 15px;
    font-weight: 600;
}

.scratch-btn {
    background: rgba(255,255,255,0.95);
    color: #2d3748;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    border: 1px solid rgba(255,255,255,0.3);
}

.scratch-btn:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}

.lock-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 48px;
    color: rgba(255,255,255,0.9);
    text-shadow: 0 3px 6px rgba(0,0,0,0.4);
    z-index: 3;
}

.progress-info {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 25px;
    margin-top: 20px;
    text-align: center;
    font-size: 14px;
    color: #666;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    line-height: 1.6;
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
    margin: 8px;
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

.level-0-message {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    text-align: center;
    margin: 25px 0;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-left: 5px solid #e53e3e;
}

.level-0-message h3 {
    color: #e53e3e;
    margin-bottom: 15px;
    font-size: 20px;
    font-weight: 700;
}

.convert-btn {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.convert-btn:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
}

.used-badge {
    background: linear-gradient(135deg, #e53e3e, #c53030);
    color: white;
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(229, 62, 62, 0.3);
}

/* Enhanced scratch area */
.scratch-area {
    position: relative;
    cursor: pointer;
    background: linear-gradient(45deg, #ffd700, #ffed4e, #ffd700);
    border-radius: 15px;
    padding: 15px;
    margin: 10px 0;
    text-align: center;
    transition: all 0.3s ease;
    border: 2px dashed rgba(255, 215, 0, 0.5);
    animation: scratchPulse 2s ease-in-out infinite;
}

@keyframes scratchPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.scratch-area:hover {
    background: linear-gradient(45deg, #ffed4e, #fff9c4, #ffed4e);
    transform: scale(1.05);
}

.scratch-instruction {
    font-size: 12px;
    color: #666;
    margin-top: 8px;
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

.header { animation-delay: 0.1s; }
.points-display { animation-delay: 0.2s; }
.user-code-section { animation-delay: 0.3s; }
.unlocked-summary { animation-delay: 0.4s; }
.countdown-container { animation-delay: 0.5s; }
.gift-cards-container { animation-delay: 0.6s; }
.progress-info { animation-delay: 0.7s; }
.navigation { animation-delay: 0.8s; }

.gift-card {
    animation: fadeInUp 0.8s ease-out;
}

/* Responsive Design */
@media (max-width: 480px) {
    body {
        padding: 15px;
    }
    
    .container {
        max-width: 100%;
        padding: 0 10px;
    }
    
    .gift-card {
        width: 280px;
        height: 200px;
        padding: 20px;
    }
    
    .total-points {
        font-size: 42px;
    }
    
    .summary-stats {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .stat {
        padding: 12px;
    }
    
    .stat-number {
        font-size: 24px;
    }
    
    .countdown-timer {
        font-size: 28px;
        padding: 12px;
    }
    
    .card-amount {
        font-size: 32px;
    }
    
    .six-digit-code {
        font-size: 18px;
        min-width: 160px;
        padding: 8px 12px;
    }
}

@media (max-width: 360px) {
    .header h1 {
        font-size: 28px;
    }
    
    .gift-card {
        width: 260px;
        height: 190px;
        padding: 18px;
    }
    
    .total-points {
        font-size: 36px;
    }
    
    .user-code {
        font-size: 20px;
    }
}

/* Focus styles for accessibility */
.nav-btn:focus, .copy-btn:focus, .scratch-btn:focus, .convert-btn:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

/* Text selection */
::selection {
    background: rgba(102, 126, 234, 0.3);
    color: #2d3748;
}

/* Card hover effects */
.gift-card:hover {
    transform: translateY(-5px) scale(1.02);
}

.gift-card.locked:hover {
    transform: scale(0.98);
    filter: grayscale(0.8) brightness(0.8);
}

/* Sparkle effect for unlocked cards */
.gift-card.unlocked::after {
    content: '✨';
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 20px;
    animation: sparkle 2s ease-in-out infinite;
}

@keyframes sparkle {
    0%, 100% { opacity: 0; transform: scale(0.8); }
    50% { opacity: 1; transform: scale(1.2); }
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
                            <div class="six-digit-code <?= $card['is_scratched'] ? 'clear' : 'blurred' ?>">
                                <?= $card['is_scratched'] ? $card['six_digit_code'] : '######' ?>
                            </div>
                            <?php if (!$card['is_scratched']): ?>
                                <div class="scratch-area" onclick="showScratchButton(<?= $card['id'] ?>)">
                                    🎯 Click to Reveal Code
                                    <div class="scratch-instruction">Scratch to see your gift card code</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-footer">
                            <div>
                                <?php if ($card['is_used']): ?>
                                    <span class="used-badge">USED</span>
                                <?php else: ?>
                                    <?php if ($card['is_scratched']): ?>
                                        <button class="convert-btn" onclick="convertGiftCard(<?= $card['amount'] ?>, '<?= $card['six_digit_code'] ?>')">
                                            💰 Convert
                                        </button>
                                    <?php else: ?>
                                        <!-- Scratch button will appear here via JavaScript -->
                                        <div id="scratch-button-<?= $card['id'] ?>" style="display: none;">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="scratch_card" value="1">
                                                <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                                <button type="submit" class="scratch-btn">🎯 Scratch Now</button>
                                            </form>
                                        </div>
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
                                    <?php if ($totalUnlockedCards > 0): ?>
                                        ✅ Available
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="scratch_card" value="1">
                                            <input type="hidden" name="card_id" value="new_<?= $level ?>">
                                            <button type="submit" class="scratch-btn">🎯 Unlock & Scratch</button>
                                        </form>
                                    <?php endif; ?>
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

// Show scratch button when user clicks on scratch area
function showScratchButton(cardId) {
    const scratchButton = document.getElementById(`scratch-button-${cardId}`);
    if (scratchButton) {
        scratchButton.style.display = 'block';
        // Scroll to make the button visible
        scratchButton.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
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