<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];
$amount = $_GET['amount'] ?? 0;
$cardId = $_GET['card_id'] ?? 0;
$email = $_GET['email'] ?? '';

if (!$amount || !$cardId) {
    header('Location: gift_card.php');
    exit;
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bankName = $_POST['bank_name'] ?? '';
    $accountNumber = $_POST['account_number'] ?? '';
    $accountName = $_POST['account_name'] ?? '';
    
    if (empty($bankName) || empty($accountNumber) || empty($accountName)) {
        $error = 'All bank details are required!';
    } else {
        // Mark gift card as used
        $updateStmt = $pdo->prepare("UPDATE unlocked_gift_cards SET is_used = TRUE WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$cardId, $userId]);
        
        // Record payment transaction (you might want to create a payments table)
        $paymentStmt = $pdo->prepare("
            INSERT INTO payment_transactions (user_id, amount, bank_name, account_number, account_name, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'completed', NOW())
        ");
        $paymentStmt->execute([$userId, $amount, $bankName, $accountNumber, $accountName]);
        
        // Create payment_transactions table if not exists
        $createPaymentTable = $pdo->prepare("
            CREATE TABLE IF NOT EXISTS payment_transactions (
                id SERIAL PRIMARY KEY,
                user_id INTEGER REFERENCES users(id),
                amount DECIMAL(10,2),
                bank_name VARCHAR(100),
                account_number VARCHAR(50),
                account_name VARCHAR(100),
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $createPaymentTable->execute();
        
        header('Location: gift_card.php?payment=success');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Take Payment - Tech Titans</title>
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

.amount-display {
    background: white;
    border-radius: 20px;
    padding: 25px;
    text-align: center;
    margin-bottom: 20px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    border: 3px solid #ffd700;
}

.amount-label {
    font-size: 16px;
    color: #666;
    margin-bottom: 10px;
}

.amount-value {
    font-size: 48px;
    font-weight: bold;
    color: #7b68ee;
    margin: 10px 0;
}

.payment-form {
    background: white;
    border-radius: 20px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    color: #555;
}

.form-group input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #ddd;
    border-radius: 10px;
    font-size: 16px;
    transition: border-color 0.3s ease;
}

.form-group input:focus {
    outline: none;
    border-color: #7b68ee;
}

.submit-btn {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    width: 100%;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
}

.navigation {
    text-align: center;
    margin-top: 20px;
}

.nav-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    margin: 5px;
}

.nav-btn:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

.payment-info {
    background: #e7f3ff;
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 20px;
    border-left: 4px solid #007bff;
}

.payment-info h3 {
    color: #007bff;
    margin-bottom: 10px;
}

.payment-info ul {
    list-style: none;
    padding-left: 0;
}

.payment-info li {
    padding: 5px 0;
    color: #555;
}

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .payment-form {
        padding: 20px;
    }
    
    .amount-value {
        font-size: 36px;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>💳 Take Payment</h1>
        <p>Complete your gift card conversion</p>
    </div>

    <div class="amount-display">
        <div class="amount-label">Payment Amount</div>
        <div class="amount-value">$<?= number_format($amount) ?></div>
        <div style="font-size: 14px; color: #666; margin-top: 10px;">
            📧 <?= htmlspecialchars($email) ?>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="error-message">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="payment-info">
        <h3>💰 Payment Information</h3>
        <ul>
            <li>✅ Amount will be sent to your bank account</li>
            <li>✅ Processing time: 1-3 business days</li>
            <li>✅ Secure and encrypted transaction</li>
        </ul>
    </div>

    <div class="payment-form">
        <form method="POST">
            <div class="form-group">
                <label for="bank_name">🏦 Bank Name</label>
                <input type="text" id="bank_name" name="bank_name" required 
                       value="<?= htmlspecialchars($_POST['bank_name'] ?? '') ?>"
                       placeholder="Enter your bank name">
            </div>

            <div class="form-group">
                <label for="account_number">🔢 Account Number</label>
                <input type="text" id="account_number" name="account_number" required 
                       value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>"
                       placeholder="Enter your account number">
            </div>

            <div class="form-group">
                <label for="account_name">👤 Account Name</label>
                <input type="text" id="account_name" name="account_name" required 
                       value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>"
                       placeholder="Enter account holder name">
            </div>

            <button type="submit" class="submit-btn">
                ✅ Complete Payment
            </button>
        </form>
    </div>

    <div class="navigation">
        <button class="nav-btn" onclick="window.location.href='conversion.php'">
            🔙 Back to Conversion
        </button>
        <button class="nav-btn" onclick="window.location.href='gift_card.php'">
            🎫 Back to Gift Cards
        </button>
    </div>
</div>

<script>
// Auto-focus on first input
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('input');
    if (firstInput) {
        firstInput.focus();
    }
});

// Confirm before submission
document.querySelector('form').addEventListener('submit', function(e) {
    if (!confirm('Are you sure you want to complete this payment? This action cannot be undone.')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>