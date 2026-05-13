<?php
session_start();

require_once "config.php";


// Include PHPMailer - IMPORTANT: Use the correct path
// If using Composer (recommended), uncomment this:
require 'vendor/autoload.php';

// If NOT using Composer, use this manual include instead:
/*
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Function to send verification email using PHPMailer
function sendVerificationEmail($email, $code) {
    $mail = new PHPMailer(true);
    
    try {
        // Enable debugging for troubleshooting
        // $mail->SMTPDebug = 2; // Uncomment for debugging
        // $mail->Debugoutput = function($str, $level) {
        //     error_log("PHPMailer: $str");
        // };
        
        // Server settings for Gmail SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'giftbanjo71@gmail.com'; // YOUR GMAIL
        $mail->Password = 'twitzvlomiwdyubd'; // YOUR GMAIL APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Important settings for Gmail
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom('giftbanjo71@gmail.com', 'Vibehive');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Verification Code - Vibehive';
        
        // Simple HTML email template
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .code { font-size: 32px; font-weight: bold; color: #7b68ee; text-align: center; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px; letter-spacing: 5px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #888; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <h2 style="color: #7b68ee;">Password Reset Request</h2>
                <p>Hello,</p>
                <p>We received a request to reset your password. Please use the following verification code:</p>
                <div class="code">' . $code . '</div>
                <p>This code will expire in 5 minutes.</p>
                <p>If you did not request this password reset, please ignore this email.</p>
                <div class="footer">
                    <p>Thank you,<br>Vibehive Team</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "Your password reset verification code is: $code. This code will expire in 5 minutes.";
        
        // Try to send the email
        if ($mail->send()) {
            error_log("Email sent successfully to: " . $email);
            return true;
        } else {
            error_log("Failed to send email to: " . $email);
            return false;
        }
        
    } catch (Exception $e) {
        // Log the error for debugging
        error_log("PHPMailer Exception: " . $e->getMessage());
        error_log("PHPMailer Error Info: " . $mail->ErrorInfo);
        return false;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Step 1: Check email exists and send verification code
    if ($_POST['action'] === 'check_email') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$email) {
            echo json_encode(['success' => false, 'error' => 'Valid email is required']);
            exit;
        }

        try {
            // Check if email exists in database
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'No account found with this email address']);
                exit;
            }

            // Generate 6-digit code
            $code = sprintf("%06d", mt_rand(1, 999999));
            
            // Store code in session with expiration (5 minutes)
            $_SESSION['reset_code'] = $code;
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_code_expires'] = time() + 300; // 5 minutes
            $_SESSION['reset_user_id'] = $user['id'];
            
            // Send verification email
            $emailSent = sendVerificationEmail($email, $code);
            
            if ($emailSent) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Verification code sent to your email',
                    'username' => $user['username'],
                    'debug_info' => 'Email sent successfully'
                ]);
            } else {
                // For testing/development - show the code directly
                echo json_encode([
                    'success' => true, 
                    'message' => 'Debug mode: Email sending failed, but here is your code',
                    'debug_code' => $code,
                    'username' => $user['username'],
                    'debug_info' => 'Email failed to send, code displayed for testing'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Email error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Step 2: Verify code
    if ($_POST['action'] === 'verify_code') {
        $entered_code = trim($_POST['code'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$entered_code || !$email) {
            echo json_encode(['success' => false, 'error' => 'Code and email are required']);
            exit;
        }

        // Check if code exists and matches
        if (!isset($_SESSION['reset_code']) || 
            !isset($_SESSION['reset_email']) ||
            !isset($_SESSION['reset_code_expires'])) {
            echo json_encode(['success' => false, 'error' => 'No verification code found. Please request a new one.']);
            exit;
        }

        // Check if code has expired
        if (time() > $_SESSION['reset_code_expires']) {
            unset($_SESSION['reset_code']);
            unset($_SESSION['reset_code_expires']);
            echo json_encode(['success' => false, 'error' => 'Verification code has expired. Please request a new one.']);
            exit;
        }

        // Check if email matches and code is correct
        if ($_SESSION['reset_email'] !== $email || 
            $_SESSION['reset_code'] !== $entered_code) {
            echo json_encode(['success' => false, 'error' => 'Incorrect verification code']);
            exit;
        }

        // Code is valid - mark as verified
        $_SESSION['reset_verified'] = true;
        
        echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
        exit;
    }

    // Step 3: Reset password
    if ($_POST['action'] === 'reset_password') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$email || !$password || !$confirm_password) {
            echo json_encode(['success' => false, 'error' => 'All fields are required']);
            exit;
        }

        // Check if email is verified
        if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true ||
            !isset($_SESSION['reset_email']) || $_SESSION['reset_email'] !== $email) {
            echo json_encode(['success' => false, 'error' => 'Email not verified. Please verify your email first.']);
            exit;
        }

        // Validate passwords
        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters long']);
            exit;
        }

        if ($password !== $confirm_password) {
            echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
            exit;
        }

        try {
            // Hash the new password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Update password in database
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE email = :email");
            $stmt->execute([
                ':password_hash' => $password_hash,
                ':email' => $email
            ]);

            // Check if any rows were affected
            if ($stmt->rowCount() > 0) {
                // Clear reset session
                session_regenerate_id(true);
                unset($_SESSION['reset_code']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_code_expires']);
                unset($_SESSION['reset_verified']);
                unset($_SESSION['reset_user_id']);
                
                echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update password. User not found.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Reset Password - Vibehive</title>
<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
    padding: 20px;
    color: #333;
}

.container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 30px;
    width: 100%;
    max-width: 450px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

h2 {
    margin-bottom: 15px;
    text-align: center;
    color: #7b68ee;
    font-size: 28px;
    font-weight: 800;
}

.subtitle {
    text-align: center;
    color: #718096;
    margin-bottom: 30px;
    font-size: 16px;
    line-height: 1.5;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #7b68ee;
    font-size: 14px;
}

input[type=email],
input[type=text],
input[type=password] {
    width: 100%;
    padding: 12px 15px;
    margin-bottom: 20px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    border-radius: 10px;
    font-size: 15px;
    transition: all 0.3s ease;
    background: white;
    color: #2d3748;
}

input:focus {
    border-color: #7b68ee;
    outline: none;
    box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
}

input.error {
    border-color: #ff6b6b;
}

button {
    width: 100%;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 14px;
    font-size: 16px;
    font-weight: 600;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 10px;
}

button:hover:not(:disabled) {
    background: linear-gradient(135deg, #6a5acd, #5d4fbb);
    transform: translateY(-2px);
}

button:disabled {
    background: #cbd5e0;
    cursor: not-allowed;
    transform: none;
}

.message {
    margin-bottom: 20px;
    padding: 12px 15px;
    border-radius: 10px;
    font-weight: 600;
    text-align: center;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.error {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}

.success {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.info {
    background: linear-gradient(135deg, #4299e1, #3182ce);
    color: white;
}

.back-link {
    text-align: center;
    margin-top: 25px;
}

.back-link a {
    color: #7b68ee;
    text-decoration: none;
    font-weight: 600;
}

.back-link a:hover {
    text-decoration: underline;
}

/* Step indicator */
.step-indicator {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
    position: relative;
}

.step-indicator::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 2px;
    background: rgba(123, 104, 238, 0.2);
    transform: translateY(-50%);
    z-index: 1;
}

.step {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: white;
    border: 2px solid rgba(123, 104, 238, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    color: #7b68ee;
    position: relative;
    z-index: 2;
    transition: all 0.3s ease;
}

.step.active {
    background: #7b68ee;
    color: white;
    border-color: #7b68ee;
}

.step.completed {
    background: #48bb78;
    color: white;
    border-color: #48bb78;
}

/* Slides */
.slide {
    display: none;
    animation: fadeInSlide 0.5s ease;
}

@keyframes fadeInSlide {
    from { opacity: 0; }
    to { opacity: 1; }
}

.slide.active {
    display: block;
}

/* Verification code input */
.verification-code {
    font-size: 24px;
    text-align: center;
    letter-spacing: 10px;
    font-weight: bold;
}

/* Password strength */
.password-strength {
    margin-top: -15px;
    margin-bottom: 15px;
    font-size: 12px;
}

.password-strength.weak {
    color: #ff6b6b;
}

.password-strength.medium {
    color: #ed8936;
}

.password-strength.strong {
    color: #48bb78;
}

.resend-link {
    text-align: center;
    margin: 15px 0;
    font-size: 14px;
}

.resend-link button {
    background: none;
    border: none;
    color: #7b68ee;
    text-decoration: underline;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    margin: 0;
    width: auto;
}

.resend-link button:hover {
    color: #6a5acd;
}

.countdown {
    color: #ff6b6b;
    font-weight: bold;
}
</style>
</head>
<body>
<div class="container">
    <h2>🔐 Reset Password</h2>
    <p class="subtitle">Enter your email to receive a verification code</p>
    
    <!-- Step Indicator -->
    <div class="step-indicator">
        <div class="step active" id="step1">1</div>
        <div class="step" id="step2">2</div>
        <div class="step" id="step3">3</div>
    </div>
    
    <!-- Message Display -->
    <div id="message" class="message" style="display: none;"></div>
    
    <!-- Step 1: Email -->
    <div class="slide active" id="slide1">
        <form id="emailForm">
            <label for="email">Email Address</label>
            <input type="email" id="email" placeholder="your@email.com" required>
            <button type="submit" id="sendBtn">Send Verification Code</button>
        </form>
    </div>
    
    <!-- Step 2: Verify Code -->
    <div class="slide" id="slide2">
        <div style="text-align: center; margin-bottom: 20px;">
            <p>Code sent to: <strong id="userEmail"></strong></p>
        </div>
        <form id="codeForm">
            <label for="code">Verification Code</label>
            <input type="text" id="code" class="verification-code" placeholder="000000" maxlength="6" required>
            <div class="resend-link">
                <button type="button" id="resendBtn">Resend Code</button>
                <span id="countdown" class="countdown"></span>
            </div>
            <button type="submit" id="verifyBtn">Verify Code</button>
        </form>
    </div>
    
    <!-- Step 3: New Password -->
    <div class="slide" id="slide3">
        <form id="passwordForm">
            <label for="newPassword">New Password</label>
            <input type="password" id="newPassword" placeholder="Minimum 6 characters" required>
            <div id="passwordStrength" class="password-strength"></div>
            
            <label for="confirmPassword">Confirm Password</label>
            <input type="password" id="confirmPassword" placeholder="Re-enter your password" required>
            
            <button type="submit" id="resetBtn">Reset Password</button>
        </form>
    </div>
    
    <div class="back-link">
        <a href="signup.php">← Back to Login</a>
    </div>
</div>

<script>
let currentStep = 1;
let userEmail = '';
let canResend = false;
let countdownInterval;

// Show slide
function showSlide(step) {
    // Hide all slides
    document.querySelectorAll('.slide').forEach(slide => {
        slide.classList.remove('active');
    });
    
    // Show current slide
    document.getElementById(`slide${step}`).classList.add('active');
    
    // Update step indicator
    document.querySelectorAll('.step').forEach((stepEl, index) => {
        if (index + 1 < step) {
            stepEl.classList.remove('active');
            stepEl.classList.add('completed');
        } else if (index + 1 === step) {
            stepEl.classList.add('active');
            stepEl.classList.remove('completed');
        } else {
            stepEl.classList.remove('active', 'completed');
        }
    });
    
    currentStep = step;
}

// Show message
function showMessage(text, type = 'info') {
    const messageEl = document.getElementById('message');
    messageEl.textContent = text;
    messageEl.className = `message ${type}`;
    messageEl.style.display = 'block';
    
    // Auto-hide success messages after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            messageEl.style.display = 'none';
        }, 5000);
    }
}

// Hide message
function hideMessage() {
    document.getElementById('message').style.display = 'none';
}

// Start countdown for resend button
function startCountdown(seconds) {
    const countdownEl = document.getElementById('countdown');
    const resendBtn = document.getElementById('resendBtn');
    
    resendBtn.disabled = true;
    canResend = false;
    
    let timeLeft = seconds;
    updateCountdown(timeLeft);
    
    countdownInterval = setInterval(() => {
        timeLeft--;
        updateCountdown(timeLeft);
        
        if (timeLeft <= 0) {
            clearInterval(countdownInterval);
            resendBtn.disabled = false;
            canResend = true;
            countdownEl.textContent = '';
        }
    }, 1000);
}

// Update countdown display
function updateCountdown(seconds) {
    const countdownEl = document.getElementById('countdown');
    countdownEl.textContent = ` (${seconds}s)`;
}

// Step 1: Send verification code
document.getElementById('emailForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const email = document.getElementById('email').value.trim();
    const sendBtn = document.getElementById('sendBtn');
    
    if (!email || !email.includes('@')) {
        showMessage('Please enter a valid email address', 'error');
        return;
    }
    
    // Disable button and show loading
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';
    hideMessage();
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_email');
        formData.append('email', email);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            userEmail = email;
            document.getElementById('userEmail').textContent = email;
            
            // Show debug info if available
            if (result.debug_code) {
                showMessage(`Debug Mode: Your code is ${result.debug_code}. Use this to continue.`, 'info');
            } else {
                showMessage(result.message || 'Verification code sent!', 'success');
            }
            
            // Start countdown for resend
            startCountdown(60);
            
            // Move to step 2 after a delay
            setTimeout(() => {
                showSlide(2);
            }, 1500);
            
        } else {
            showMessage(result.error || 'Failed to send verification code', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showMessage('Network error. Please try again.', 'error');
    } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = 'Send Verification Code';
    }
});

// Step 2: Verify code
document.getElementById('codeForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const code = document.getElementById('code').value.trim();
    const verifyBtn = document.getElementById('verifyBtn');
    
    if (!code || code.length !== 6) {
        showMessage('Please enter a valid 6-digit code', 'error');
        return;
    }
    
    // Disable button and show loading
    verifyBtn.disabled = true;
    verifyBtn.textContent = 'Verifying...';
    hideMessage();
    
    try {
        const formData = new FormData();
        formData.append('action', 'verify_code');
        formData.append('code', code);
        formData.append('email', userEmail);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showMessage('Email verified successfully!', 'success');
            
            // Move to step 3 after a delay
            setTimeout(() => {
                showSlide(3);
            }, 1500);
            
        } else {
            showMessage(result.error || 'Invalid verification code', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showMessage('Network error. Please try again.', 'error');
    } finally {
        verifyBtn.disabled = false;
        verifyBtn.textContent = 'Verify Code';
    }
});

// Resend code button
document.getElementById('resendBtn').addEventListener('click', async () => {
    if (!canResend) return;
    
    const resendBtn = document.getElementById('resendBtn');
    
    // Disable button and show loading
    resendBtn.disabled = true;
    resendBtn.textContent = 'Resending...';
    hideMessage();
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_email');
        formData.append('email', userEmail);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.debug_code) {
                showMessage(`New code: ${result.debug_code}`, 'info');
            } else {
                showMessage('New verification code sent!', 'success');
            }
            
            // Restart countdown
            startCountdown(60);
            
        } else {
            showMessage(result.error || 'Failed to resend code', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showMessage('Network error. Please try again.', 'error');
    } finally {
        if (canResend) {
            resendBtn.disabled = false;
            resendBtn.textContent = 'Resend Code';
        }
    }
});

// Step 3: Reset password
document.getElementById('passwordForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const password = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const resetBtn = document.getElementById('resetBtn');
    
    // Validate passwords
    if (password.length < 6) {
        showMessage('Password must be at least 6 characters', 'error');
        return;
    }
    
    if (password !== confirmPassword) {
        showMessage('Passwords do not match', 'error');
        return;
    }
    
    // Disable button and show loading
    resetBtn.disabled = true;
    resetBtn.textContent = 'Processing...';
    hideMessage();
    
    try {
        const formData = new FormData();
        formData.append('action', 'reset_password');
        formData.append('email', userEmail);
        formData.append('password', password);
        formData.append('confirm_password', confirmPassword);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showMessage('Password reset successfully! Redirecting...', 'success');
            
            // Redirect to login page
            setTimeout(() => {
                window.location.href = 'signup.php';
            }, 2000);
            
        } else {
            showMessage(result.error || 'Failed to reset password', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showMessage('Network error. Please try again.', 'error');
    } finally {
        resetBtn.disabled = false;
        resetBtn.textContent = 'Reset Password';
    }
});

// Password strength indicator
document.getElementById('newPassword').addEventListener('input', function() {
    const password = this.value;
    const strengthEl = document.getElementById('passwordStrength');
    
    if (!password) {
        strengthEl.textContent = '';
        return;
    }
    
    let strength = 'weak';
    let message = 'Weak password';
    
    if (password.length >= 8) {
        const hasUpper = /[A-Z]/.test(password);
        const hasLower = /[a-z]/.test(password);
        const hasNumber = /\d/.test(password);
        
        const score = [hasUpper, hasLower, hasNumber].filter(Boolean).length;
        
        if (score >= 3 && password.length >= 10) {
            strength = 'strong';
            message = 'Strong password';
        } else if (score >= 2) {
            strength = 'medium';
            message = 'Medium password';
        }
    }
    
    strengthEl.textContent = message;
    strengthEl.className = `password-strength ${strength}`;
});

// Input validation
document.getElementById('code').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
});
</script>
</body>
</html>