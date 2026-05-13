<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

// Get user code and user's email from database
$userStmt = $pdo->prepare("
    SELECT uc.user_code, u.email 
    FROM user_codes uc 
    JOIN users u ON uc.user_id = u.id 
    WHERE uc.user_id = ?
");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

$userCode = $userData['user_code'] ?? '';
$userEmail = $userData['email'] ?? '';

// Handle form submission
$error = '';
$success = '';
$amount = $_GET['amount'] ?? '';
$giftCardCode = $_GET['code'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $enteredUserCode = $_POST['user_code'] ?? '';
    $enteredGiftCardCode = $_POST['gift_card_code'] ?? '';
    
    // Validate inputs
    if (empty($email) || empty($enteredUserCode) || empty($enteredGiftCardCode)) {
        $error = 'All fields are required!';
    } elseif ($enteredUserCode !== $userCode) {
        $error = 'Invalid user code!';
    } elseif (strtolower(trim($email)) !== strtolower(trim($userEmail))) {
        $error = 'Incorrect email! Please use the email address associated with your account: ' . htmlspecialchars($userEmail);
    } else {
        // Check if gift card exists and belongs to user
        $cardStmt = $pdo->prepare("
            SELECT id, amount, is_used 
            FROM unlocked_gift_cards 
            WHERE user_id = ? AND six_digit_code = ? AND is_used = FALSE
        ");
        $cardStmt->execute([$userId, $enteredGiftCardCode]);
        $giftCard = $cardStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$giftCard) {
            $error = 'Invalid or already used gift card code!';
        } else {
            $amount = $giftCard['amount'];
            $success = "Valid gift card! Amount: $$amount";
            
            // Redirect to payment page
            header("Location: take_payment.php?amount=$amount&card_id=" . $giftCard['id'] . "&email=" . urlencode($email));
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Convert Gift Card - Tech Titans</title>
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

.conversion-form {
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

.form-group input.error {
    border-color: #e74c3c;
    background-color: #fdf2f2;
}

.email-hint {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 3px solid #7b68ee;
}

.correct-email {
    background: #d4edda !important;
    border-left: 3px solid #28a745 !important;
    color: #155724;
}

.submit-btn {
    background: linear-gradient(135deg, #7b68ee, #9370db);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
    width: 100%;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.submit-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.amount-display {
    background: #f8f9fa;
    border-radius: 15px;
    padding: 20px;
    text-align: center;
    margin: 20px 0;
    border: 2px dashed #7b68ee;
}

.amount-value {
    font-size: 32px;
    font-weight: bold;
    color: #7b68ee;
    margin: 10px 0;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
}

.success-message {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
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

@media (max-width: 480px) {
    .container {
        padding: 10px;
    }
    
    .conversion-form {
        padding: 20px;
    }
}

.email-match-indicator {
    font-size: 12px;
    margin-top: 5px;
    padding: 5px;
    border-radius: 5px;
    text-align: center;
}

.email-match {
    color: #28a745;
    background: #d4edda;
    border: 1px solid #c3e6cb;
}

.email-mismatch {
    color: #dc3545;
    background: #f8d7da;
    border: 1px solid #f5c6cb;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>💰 Convert Gift Card</h1>
        <p>Enter your details to convert gift card to cash</p>
    </div>

    <?php if ($error): ?>
        <div class="error-message">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success-message">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($amount && $giftCardCode): ?>
        <div class="amount-display">
            <div>Gift Card Amount</div>
            <div class="amount-value">$<?= number_format($amount) ?></div>
            <div style="font-size: 12px; color: #666;">Code: <?= htmlspecialchars($giftCardCode) ?></div>
        </div>
    <?php endif; ?>

    <div class="conversion-form">
        <form method="POST" id="conversionForm">
            <div class="form-group">
                <label for="email">📧 Email Address</label>
                <input type="email" id="email" name="email" required 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="Enter your email address"
                       oninput="checkEmailMatch()">
                <div class="email-hint" id="emailHint">
                    💡 Please use the email address associated with your account
                </div>
                <div id="emailMatchIndicator" class="email-match-indicator" style="display: none;"></div>
            </div>

            <div class="form-group">
                <label for="user_code">🔑 User Code (9-digit)</label>
                <input type="text" id="user_code" name="user_code" required 
                       value="<?= htmlspecialchars($_POST['user_code'] ?? $userCode) ?>"
                       placeholder="Enter your 9-digit user code" maxlength="9">
            </div>

            <div class="form-group">
                <label for="gift_card_code">🎫 Gift Card Code (6-digit)</label>
                <input type="text" id="gift_card_code" name="gift_card_code" required 
                       value="<?= htmlspecialchars($_POST['gift_card_code'] ?? $giftCardCode) ?>"
                       placeholder="Enter 6-digit gift card code" maxlength="6">
            </div>

            <button type="submit" class="submit-btn" id="submitBtn">
                ✅ Validate & Continue to Payment
            </button>
        </form>
    </div>

    <div class="navigation">
        <button class="nav-btn" onclick="window.location.href='gift_card.php'">
            🔙 Back to Gift Cards
        </button>
    </div>
</div>

<script>
// Store the correct email from PHP (this would normally come from the server)
const correctEmail = "<?= htmlspecialchars($userEmail) ?>";

function checkEmailMatch() {
    const emailInput = document.getElementById('email');
    const emailMatchIndicator = document.getElementById('emailMatchIndicator');
    const submitBtn = document.getElementById('submitBtn');
    const emailHint = document.getElementById('emailHint');
    
    const enteredEmail = emailInput.value.trim().toLowerCase();
    const correctEmailLower = correctEmail.toLowerCase();
    
    if (enteredEmail === '') {
        emailMatchIndicator.style.display = 'none';
        emailHint.style.display = 'block';
        submitBtn.disabled = false;
        emailInput.classList.remove('error');
        return;
    }
    
    if (enteredEmail === correctEmailLower) {
        emailMatchIndicator.textContent = '✅ Email matches your account';
        emailMatchIndicator.className = 'email-match-indicator email-match';
        emailMatchIndicator.style.display = 'block';
        emailHint.style.display = 'none';
        submitBtn.disabled = false;
        emailInput.classList.remove('error');
    } else {
        emailMatchIndicator.textContent = '❌ Email does not match your account';
        emailMatchIndicator.className = 'email-match-indicator email-mismatch';
        emailMatchIndicator.style.display = 'block';
        emailHint.style.display = 'none';
        submitBtn.disabled = false; // Still allow submission to show server-side error
        emailInput.classList.add('error');
    }
}

// Show the correct email when user focuses on email field
document.getElementById('email').addEventListener('focus', function() {
    const emailHint = document.getElementById('emailHint');
    emailHint.innerHTML = `💡 Please use your account email: <strong>${correctEmail}</strong>`;
    emailHint.classList.add('correct-email');
});

document.getElementById('email').addEventListener('blur', function() {
    const emailHint = document.getElementById('emailHint');
    emailHint.innerHTML = '💡 Please use the email address associated with your account';
    emailHint.classList.remove('correct-email');
});

// Auto-focus on first input
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('input');
    if (firstInput) {
        firstInput.focus();
    }
    
    // Check email match on page load if there's already a value
    if (document.getElementById('email').value) {
        checkEmailMatch();
    }
});

// Form submission validation
document.getElementById('conversionForm').addEventListener('submit', function(e) {
    const emailInput = document.getElementById('email');
    const enteredEmail = emailInput.value.trim().toLowerCase();
    const correctEmailLower = correctEmail.toLowerCase();
    
    if (enteredEmail !== correctEmailLower) {
        e.preventDefault();
        alert('Please use the correct email address associated with your account: ' + correctEmail);
        emailInput.focus();
        emailInput.classList.add('error');
    }
});
</script>
</body>
</html>