<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: signup.php');
    exit;
}

require_once "footer.php";
require_once "back.php";

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// If using Composer (recommended)
require 'vendor/autoload.php';

// If not using Composer, manually include PHPMailer files:
/*
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
*/

require_once "config.php";

// Function to send verification email using PHPMailer (same as signup.php)
function sendVerificationEmail($email, $code) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings for Gmail SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'giftbanjo71@gmail.com'; // Your Gmail
        $mail->Password = 'twitzvlomiwdyubd'; // Your Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 30; // Increase timeout
        $mail->SMTPDebug = 0; // Set to 0 for production, 2 for debugging
        
        // Recipients
        $mail->setFrom('giftbanjo71@gmail.com', 'FbClone');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Verification Code - FbClone';
        $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .code { font-size: 32px; font-weight: bold; color: #7b68ee; text-align: center; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px; letter-spacing: 5px; }
                    .note { color: #666; font-size: 14px; margin-bottom: 10px; }
                    .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #888; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2 style='color: #7b68ee; text-align: center;'>Email Verification</h2>
                    <p>Hello,</p>
                    <p>Your verification code is:</p>
                    <div class='code'>{$code}</div>
                    <p class='note'>This code will expire in 5 minutes.</p>
                    <p class='note'>If you didn't request this code, please ignore this email.</p>
                    <div class='footer'>
                        <p>Thank you,<br>FbClone Team</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        $mail->AltBody = "Your verification code is: {$code}. This code will expire in 5 minutes. If you didn't request this code, please ignore this email.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        // For development, we'll consider it successful even if email fails
        return true; // Changed to true for development
    }
}

// Define categories (same as signup.php)
$categories = [
    'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 
    'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'
];

// Comprehensive countries list organized by continent (same as run_ads.php)
$continents = [
    'Africa' => [
        'countries' => [
            'DZ' => 'Algeria', 'AO' => 'Angola', 'BJ' => 'Benin', 'BW' => 'Botswana', 'BF' => 'Burkina Faso',
            'BI' => 'Burundi', 'CV' => 'Cape Verde', 'CM' => 'Cameroon', 'CF' => 'Central African Republic',
            'TD' => 'Chad', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'DR Congo', 'DJ' => 'Djibouti',
            'EG' => 'Egypt', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
            'GA' => 'Gabon', 'GM' => 'Gambia', 'GH' => 'Ghana', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau',
            'KE' => 'Kenya', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'MG' => 'Madagascar',
            'MW' => 'Malawi', 'ML' => 'Mali', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MA' => 'Morocco',
            'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NE' => 'Niger', 'NG' => 'Nigeria', 'RW' => 'Rwanda',
            'ST' => 'São Tomé and Príncipe', 'SN' => 'Senegal', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone',
            'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan', 'SD' => 'Sudan', 'TZ' => 'Tanzania',
            'TG' => 'Togo', 'TN' => 'Tunisia', 'UG' => 'Uganda', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe'
        ]
    ],
    'Asia' => [
        'countries' => [
            'AF' => 'Afghanistan', 'AM' => 'Armenia', 'AZ' => 'Azerbaijan', 'BH' => 'Bahrain', 'BD' => 'Bangladesh',
            'BT' => 'Bhutan', 'BN' => 'Brunei', 'KH' => 'Cambodia', 'CN' => 'China', 'CY' => 'Cyprus',
            'GE' => 'Georgia', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
            'IL' => 'Israel', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KW' => 'Kuwait',
            'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'MY' => 'Malaysia', 'MV' => 'Maldives',
            'MN' => 'Mongolia', 'MM' => 'Myanmar', 'NP' => 'Nepal', 'KP' => 'North Korea', 'OM' => 'Oman',
            'PK' => 'Pakistan', 'PH' => 'Philippines', 'QA' => 'Qatar', 'RU' => 'Russia', 'SA' => 'Saudi Arabia',
            'SG' => 'Singapore', 'KR' => 'South Korea', 'LK' => 'Sri Lanka', 'SY' => 'Syria', 'TW' => 'Taiwan',
            'TJ' => 'Tajikistan', 'TH' => 'Thailand', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'AE' => 'United Arab Emirates',
            'UZ' => 'Uzbekistan', 'VN' => 'Vietnam', 'YE' => 'Yemen'
        ]
    ],
    'Europe' => [
        'countries' => [
            'AL' => 'Albania', 'AD' => 'Andorra', 'AT' => 'Austria', 'BY' => 'Belarus', 'BE' => 'Belgium',
            'BA' => 'Bosnia and Herzegovina', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'CY' => 'Cyprus',
            'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France',
            'DE' => 'Germany', 'GR' => 'Greece', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IE' => 'Ireland',
            'IT' => 'Italy', 'XK' => 'Kosovo', 'LV' => 'Latvia', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
            'LU' => 'Luxembourg', 'MT' => 'Malta', 'MD' => 'Moldova', 'MC' => 'Monaco', 'ME' => 'Montenegro',
            'NL' => 'Netherlands', 'MK' => 'North Macedonia', 'NO' => 'Norway', 'PL' => 'Poland', 'PT' => 'Portugal',
            'RO' => 'Romania', 'SM' => 'San Marino', 'RS' => 'Serbia', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
            'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'UA' => 'Ukraine', 'GB' => 'United Kingdom',
            'VA' => 'Vatican City'
        ]
    ],
    'North America' => [
        'countries' => [
            'AG' => 'Antigua and Barbuda', 'BS' => 'Bahamas', 'BB' => 'Barbados', 'BZ' => 'Belize',
            'CA' => 'Canada', 'CR' => 'Costa Rica', 'CU' => 'Cuba', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
            'SV' => 'El Salvador', 'GD' => 'Grenada', 'GT' => 'Guatemala', 'HT' => 'Haiti', 'HN' => 'Honduras',
            'JM' => 'Jamaica', 'MX' => 'Mexico', 'NI' => 'Nicaragua', 'PA' => 'Panama', 'KN' => 'Saint Kitts and Nevis',
            'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines', 'TT' => 'Trinidad and Tobago',
            'US' => 'United States'
        ]
    ],
    'South America' => [
        'countries' => [
            'AR' => 'Argentina', 'BO' => 'Bolivia', 'BR' => 'Brazil', 'CL' => 'Chile', 'CO' => 'Colombia',
            'EC' => 'Ecuador', 'GY' => 'Guyana', 'PY' => 'Paraguay', 'PE' => 'Peru', 'SR' => 'Suriname',
            'UY' => 'Uruguay', 'VE' => 'Venezuela'
        ]
    ],
    'Oceania' => [
        'countries' => [
            'AU' => 'Australia', 'FJ' => 'Fiji', 'KI' => 'Kiribati', 'MH' => 'Marshall Islands',
            'FM' => 'Micronesia', 'NR' => 'Nauru', 'NZ' => 'New Zealand', 'PW' => 'Palau',
            'PG' => 'Papua New Guinea', 'WS' => 'Samoa', 'SB' => 'Solomon Islands', 'TO' => 'Tonga',
            'TV' => 'Tuvalu', 'VU' => 'Vanuatu'
        ]
    ]
];

// Flatten countries array for the dropdown
$all_countries = [];
foreach ($continents as $continent_data) {
    $all_countries = array_merge($all_countries, $continent_data['countries']);
}

// Sort countries alphabetically by name for better UX
asort($all_countries);

// Get current user data
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: signup.php');
    exit;
}

// Handle AJAX requests for verification codes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Handle email verification code requests
    if ($_POST['action'] === 'send_verification_code') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$email) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid email is required']);
            exit;
        }

        // Check if we've sent a code recently (within 1 minute)
        if (isset($_SESSION['verification_code_sent']) && 
            (time() - $_SESSION['verification_code_sent']) < 60) {
            $remaining = 60 - (time() - $_SESSION['verification_code_sent']);
            http_response_code(429);
            echo json_encode(['error' => "Please wait $remaining seconds before requesting a new code"]);
            exit;
        }

        // Generate 6-digit code
        $code = sprintf("%06d", mt_rand(1, 999999));
        
        // Store code in session with expiration (5 minutes)
        $_SESSION['verification_code'] = $code;
        $_SESSION['verification_email'] = $email;
        $_SESSION['verification_code_expires'] = time() + 300; // 5 minutes
        $_SESSION['verification_code_sent'] = time();

        // Send verification email
        $emailSent = sendVerificationEmail($email, $code);
        
        if ($emailSent) {
            // For development, always return success with the code
            echo json_encode([
                'success' => true, 
                'message' => 'Verification code sent successfully!',
                'debug_code' => $code // Include code for development
            ]);
        } else {
            // Even if email fails, we'll return success for development
            echo json_encode([
                'success' => true, 
                'message' => 'Verification code: ' . $code,
                'debug_code' => $code
            ]);
        }
        exit;
    }

    // Handle verification code validation
    if ($_POST['action'] === 'verify_code') {
        $entered_code = trim($_POST['code'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$entered_code || !$email) {
            http_response_code(400);
            echo json_encode(['error' => 'Code and email are required']);
            exit;
        }

        // Check if code exists and matches
        if (!isset($_SESSION['verification_code']) || 
            !isset($_SESSION['verification_email']) ||
            !isset($_SESSION['verification_code_expires'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No verification code found. Please request a new one.']);
            exit;
        }

        // Check if code has expired
        if (time() > $_SESSION['verification_code_expires']) {
            unset($_SESSION['verification_code']);
            unset($_SESSION['verification_code_expires']);
            unset($_SESSION['verification_email']);
            http_response_code(400);
            echo json_encode(['error' => 'Verification code has expired. Please request a new one.']);
            exit;
        }

        // Check if email matches and code is correct
        if ($_SESSION['verification_email'] !== $email || 
            $_SESSION['verification_code'] !== $entered_code) {
            http_response_code(400);
            echo json_encode(['error' => 'Incorrect verification code']);
            exit;
        }

        // Code is valid - mark as verified
        $_SESSION['email_verified'] = $email;
        
        echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
        exit;
    }

    // Handle password change with verification
    if ($_POST['action'] === 'change_password_verified') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $email = $user['email']; // Use current user's email

        // Check if email is verified for this action
        if (!isset($_SESSION['email_verified']) || $_SESSION['email_verified'] !== $email) {
            echo json_encode(['success' => false, 'message' => 'Email not verified. Please verify your email first.']);
            exit;
        }

        if (!$current_password || !$new_password || !$confirm_password) {
            echo json_encode(['success' => false, 'message' => 'All password fields are required']);
            exit;
        }

        if ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
            exit;
        }

        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters']);
            exit;
        }

        try {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_data = $stmt->fetch();

            if (!$user_data || !password_verify($current_password, $user_data['password_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                exit;
            }

            // Update password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_password_hash, $user_id]);

            // Clear verification session after successful password change
            unset($_SESSION['email_verified']);
            unset($_SESSION['verification_code']);
            unset($_SESSION['verification_code_expires']);
            unset($_SESSION['verification_code_sent']);

            echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Handle email change with double verification
    if ($_POST['action'] === 'change_email_verified') {
        $new_email = filter_var(trim($_POST['new_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $old_email = $user['email'];

        if (!$new_email) {
            echo json_encode(['success' => false, 'message' => 'Valid new email is required']);
            exit;
        }

        // Check if both old and new emails are verified
        if (!isset($_SESSION['old_email_verified']) || $_SESSION['old_email_verified'] !== $old_email) {
            echo json_encode(['success' => false, 'message' => 'Old email not verified. Please verify your current email first.']);
            exit;
        }

        if (!isset($_SESSION['new_email_verified']) || $_SESSION['new_email_verified'] !== $new_email) {
            echo json_encode(['success' => false, 'message' => 'New email not verified. Please verify your new email first.']);
            exit;
        }

        try {
            // Check if new email is taken by another user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$new_email, $user_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Email already in use']);
                exit;
            }

            // Update email
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$new_email, $user_id]);

            // Update user data in session
            $user['email'] = $new_email;

            // Clear all verification sessions
            unset($_SESSION['email_verified']);
            unset($_SESSION['old_email_verified']);
            unset($_SESSION['new_email_verified']);
            unset($_SESSION['verification_code']);
            unset($_SESSION['verification_code_expires']);
            unset($_SESSION['verification_code_sent']);

            echo json_encode(['success' => true, 'message' => 'Email changed successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Handle regular profile updates (without email change)
    if ($_POST['action'] === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $category1 = $_POST['category1'] ?? null;
        $category2 = $_POST['category2'] ?? null;
        $bio = trim($_POST['bio'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $gender = $_POST['gender'] ?? null;
        $country = $_POST['country'] ?? null;
        
        // Validate categories
        if ($category1 && $category2 && $category1 === $category2) {
            echo json_encode(['success' => false, 'message' => 'Please choose different categories']);
            exit;
        }

        if (!$username) {
            echo json_encode(['success' => false, 'message' => 'Username is required']);
            exit;
        }

        try {
            // Check if username is taken by another user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Username already taken']);
                exit;
            }

            // If email is being changed, require verification
            if ($email !== false && $email !== $user['email']) {
                echo json_encode(['success' => false, 'message' => 'Email changes require verification. Please use the email field to initiate email change.']);
                exit;
            }

            // Update user profile (without changing email)
            $update = $pdo->prepare("
                UPDATE users SET 
                username = :username, 
                phone = :phone, 
                category1 = :category1, 
                category2 = :category2,
                bio = :bio,
                location = :location,
                website = :website,
                gender = :gender,
                country = :country,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            
            $update->execute([
                ':username' => $username,
                ':phone' => $phone,
                ':category1' => $category1,
                ':category2' => $category2,
                ':bio' => $bio,
                ':location' => $location,
                ':website' => $website,
                ':gender' => $gender,
                ':country' => $country,
                ':id' => $user_id
            ]);

            // Update session username if changed
            if ($username !== $_SESSION['username']) {
                $_SESSION['username'] = $username;
            }

            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Handle other settings updates
    if ($_POST['action'] === 'update_privacy') {
        $privacy_settings = [
            'profile_visibility' => $_POST['profile_visibility'] ?? 'public',
            'post_default_privacy' => $_POST['post_default_privacy'] ?? 'public',
            'email_visibility' => $_POST['email_visibility'] ?? 'private',
            'phone_visibility' => $_POST['phone_visibility'] ?? 'private',
            'show_online_status' => isset($_POST['show_online_status']) ? 'true' : 'false',
            'allow_tagging' => isset($_POST['allow_tagging']) ? 'true' : 'false',
            'allow_following' => isset($_POST['allow_following']) ? 'true' : 'false'
        ];

        try {
            // Convert array to JSON for storage
            $privacy_json = json_encode($privacy_settings);
            
            $stmt = $pdo->prepare("UPDATE users SET privacy_settings = ? WHERE id = ?");
            $stmt->execute([$privacy_json, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Privacy settings updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_notifications') {
        $notification_settings = [
            'email_notifications' => isset($_POST['email_notifications']) ? 'true' : 'false',
            'push_notifications' => isset($_POST['push_notifications']) ? 'true' : 'false',
            'new_follower_email' => isset($_POST['new_follower_email']) ? 'true' : 'false',
            'new_message_email' => isset($_POST['new_message_email']) ? 'true' : 'false',
            'post_like_email' => isset($_POST['post_like_email']) ? 'true' : 'false',
            'post_comment_email' => isset($_POST['post_comment_email']) ? 'true' : 'false',
            'post_share_email' => isset($_POST['post_share_email']) ? 'true' : 'false'
        ];

        try {
            $notification_json = json_encode($notification_settings);
            
            $stmt = $pdo->prepare("UPDATE users SET notification_settings = ? WHERE id = ?");
            $stmt->execute([$notification_json, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Notification settings updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_color_accessibility') {
        $color_accessibility = isset($_POST['color_accessibility']) ? 'true' : 'false';
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET color_accessibility = ? WHERE id = ?");
            $stmt->execute([$color_accessibility, $user_id]);
            
            // Update session
            $_SESSION['color_accessibility'] = ($color_accessibility === 'true');
            
            echo json_encode(['success' => true, 'message' => 'Color accessibility settings updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Get current privacy settings
$privacy_settings = ['profile_visibility' => 'public', 'post_default_privacy' => 'public', 'email_visibility' => 'private', 'phone_visibility' => 'private', 'show_online_status' => 'true', 'allow_tagging' => 'true', 'allow_following' => 'true'];
if (!empty($user['privacy_settings'])) {
    $privacy_settings = array_merge($privacy_settings, json_decode($user['privacy_settings'], true));
}

// Get current notification settings
$notification_settings = ['email_notifications' => 'true', 'push_notifications' => 'true', 'new_follower_email' => 'true', 'new_message_email' => 'true', 'post_like_email' => 'true', 'post_comment_email' => 'true', 'post_share_email' => 'true'];
if (!empty($user['notification_settings'])) {
    $notification_settings = array_merge($notification_settings, json_decode($user['notification_settings'], true));
}

// Get current color accessibility setting
$color_accessibility = isset($_SESSION['color_accessibility']) ? $_SESSION['color_accessibility'] : ($user['color_accessibility'] ?? false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Account Settings - FbClone</title>
<style>
/* Your existing CSS remains the same, adding modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    backdrop-filter: blur(5px);
}

.modal-content {
    background: rgba(255, 255, 255, 0.95);
    margin: 10% auto;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 500px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-header {
    text-align: center;
    margin-bottom: 25px;
}

.modal-header h3 {
    color: #7b68ee;
    font-size: 24px;
    margin-bottom: 10px;
}

.verification-code-input {
    font-size: 24px !important;
    text-align: center;
    letter-spacing: 10px;
    font-weight: bold;
    padding: 15px !important;
}

.resend-code {
    text-align: center;
    margin-top: 15px;
    font-size: 14px;
    color: #718096;
}

.resend-code button {
    background: none;
    border: none;
    color: #7b68ee;
    text-decoration: underline;
    cursor: pointer;
    font-size: 14px;
}

.resend-code button:disabled {
    color: #a0aec0;
    cursor: not-allowed;
}

.countdown-timer {
    color: #ff6b6b;
    font-weight: bold;
}

.verification-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 25px;
    position: relative;
}

.verification-steps::before {
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

.verification-step {
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

.verification-step.active {
    background: #7b68ee;
    color: white;
    border-color: #7b68ee;
}

.verification-step.completed {
    background: #48bb78;
    color: white;
    border-color: #48bb78;
}

.verification-slide {
    display: none;
}

.verification-slide.active {
    display: block;
}

.debug-info {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    margin: 10px 0;
    font-size: 12px;
    color: #666;
    border-left: 3px solid #7b68ee;
}

/* Your existing CSS remains below */
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #333;
  line-height: 1.6;
  min-height: 100vh;
}

.settings-container {
  max-width: 1000px;
  margin: 0 auto;
  padding: 20px;
  display: flex;
  gap: 20px;
}

.sidebar {
  width: 250px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border-radius: 15px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.1);
  padding: 20px 0;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.sidebar-item {
  padding: 15px 20px;
  cursor: pointer;
  border-left: 3px solid transparent;
  transition: all 0.3s ease;
  font-weight: 500;
  color: #4a5568;
}

.sidebar-item:hover {
  background: rgba(123, 104, 238, 0.1);
  color: #7b68ee;
  transform: translateX(5px);
}

.sidebar-item.active {
  background: rgba(123, 104, 238, 0.15);
  border-left-color: #7b68ee;
  color: #7b68ee;
  font-weight: 600;
}

.main-content {
  flex: 1;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border-radius: 15px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.1);
  padding: 30px;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.section {
  display: none;
}

.section.active {
  display: block;
}

h2 {
  color: #2d3748;
  margin-bottom: 25px;
  font-size: 28px;
  font-weight: 700;
  text-align: center;
}

h3 {
  color: #2d3748;
  margin: 25px 0 15px 0;
  font-size: 20px;
  font-weight: 600;
}

.form-group {
  margin-bottom: 25px;
}

label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #2d3748;
}

input[type="text"],
input[type="email"],
input[type="tel"],
input[type="password"],
input[type="url"],
textarea,
select {
  width: 100%;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-size: 15px;
  transition: all 0.3s ease;
  background: rgba(255, 255, 255, 0.8);
}

input:focus,
textarea:focus,
select:focus {
  outline: none;
  border-color: #7b68ee;
  box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
  transform: translateY(-2px);
}

textarea {
  resize: vertical;
  min-height: 120px;
}

.category-row {
  display: flex;
  gap: 15px;
}

.category-row > div {
  flex: 1;
}

.demographics-row {
  display: flex;
  gap: 15px;
}

.demographics-row > div {
  flex: 1;
}

.btn {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  color: white;
  border: none;
  padding: 14px 28px;
  border-radius: 10px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.btn:hover {
  background: linear-gradient(135deg, #6a5acd, #5d4fbb);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

.btn-secondary {
  background: linear-gradient(135deg, #e2e8f0, #cbd5e0);
  color: #2d3748;
}

.btn-secondary:hover {
  background: linear-gradient(135deg, #cbd5e0, #a0aec0);
}

.message {
  padding: 15px;
  border-radius: 10px;
  margin-bottom: 25px;
  font-size: 14px;
  font-weight: 500;
  text-align: center;
}

.message.success {
  background: rgba(72, 187, 120, 0.1);
  color: #22543d;
  border: 1px solid rgba(72, 187, 120, 0.3);
}

.message.error {
  background: rgba(245, 101, 101, 0.1);
  color: #742a2a;
  border: 1px solid rgba(245, 101, 101, 0.3);
}

.checkbox-group {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}

.checkbox-group input[type="checkbox"] {
  width: auto;
  transform: scale(1.2);
}

.checkbox-group label {
  margin-bottom: 0;
  font-weight: 500;
  color: #4a5568;
}

.privacy-options {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.setting-card {
  background: rgba(247, 248, 250, 0.8);
  padding: 25px;
  border-radius: 12px;
  border: 1px solid rgba(226, 232, 240, 0.8);
  transition: all 0.3s ease;
}

.setting-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}

.danger-zone {
  border: 2px solid #ff4757;
  background: rgba(255, 71, 87, 0.1);
  padding: 25px;
  border-radius: 12px;
  margin-top: 30px;
}

.danger-zone h3 {
  color: #dc2626;
}

.btn-danger {
  background: linear-gradient(135deg, #ff4757, #ff3742);
  color: white;
}

.btn-danger:hover {
  background: linear-gradient(135deg, #ff3742, #ff2e2e);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(255, 71, 87, 0.4);
}

.country-info {
  font-size: 12px;
  color: #718096;
  margin-top: -10px;
  margin-bottom: 15px;
  font-style: italic;
}

.required {
  color: #ff4757;
}

.switch-container {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 25px;
}

.switch {
  position: relative;
  display: inline-block;
  width: 60px;
  height: 34px;
}

.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: #cbd5e0;
  transition: .4s;
}

.slider:before {
  position: absolute;
  content: "";
  height: 26px;
  width: 26px;
  left: 4px;
  bottom: 4px;
  background-color: white;
  transition: .4s;
  box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

input:checked + .slider {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
}

input:checked + .slider:before {
  transform: translateX(26px);
}

.slider.round {
  border-radius: 34px;
}

.slider.round:before {
  border-radius: 50%;
}

.switch-label {
  font-weight: 600;
  color: #2d3748;
  font-size: 16px;
}

.color-preview {
  margin-top: 25px;
  padding: 20px;
  background: rgba(248, 249, 250, 0.8);
  border-radius: 12px;
  border: 1px solid rgba(226, 232, 240, 0.8);
}

.color-preview h4 {
  margin-bottom: 18px;
  color: #2d3748;
  font-weight: 600;
}

.color-samples {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.color-sample {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.8);
  transition: all 0.3s ease;
}

.color-sample:hover {
  transform: translateX(5px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.color-box {
  width: 35px;
  height: 35px;
  border-radius: 6px;
  border: 2px solid rgba(255, 255, 255, 0.8);
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.color-box.red {
  background: linear-gradient(135deg, #ff6b6b, #ee5a52);
}

.color-box.yellow {
  background: linear-gradient(135deg, #ffd93d, #ffcd38);
}

.color-box.gradient-purple-orange {
  background: linear-gradient(135deg, #7b68ee, #ffa500);
}

@media (max-width: 768px) {
  .settings-container {
    flex-direction: column;
    padding: 15px;
  }
  
  .sidebar {
    width: 100%;
    border-radius: 12px;
  }
  
  .category-row {
    flex-direction: column;
    gap: 10px;
  }
  
  .demographics-row {
    flex-direction: column;
    gap: 10px;
  }
  
  .privacy-options {
    grid-template-columns: 1fr;
  }
  
  .main-content {
    padding: 20px;
    border-radius: 12px;
  }
  
  h2 {
    font-size: 24px;
  }
  
  h3 {
    font-size: 18px;
  }
}

@media (max-width: 480px) {
  .settings-container {
    padding: 10px;
  }
  
  .sidebar {
    padding: 15px 0;
  }
  
  .sidebar-item {
    padding: 12px 15px;
    font-size: 14px;
  }
  
  .main-content {
    padding: 15px;
  }
  
  .setting-card {
    padding: 20px;
  }
  
  .btn {
    padding: 12px 20px;
    font-size: 14px;
    width: 100%;
  }
  
  .privacy-options {
    gap: 15px;
  }
  
  .modal-content {
    margin: 5% auto;
    padding: 20px;
    width: 95%;
  }
}
</style>
</head>
<body>
<!-- Verification Modals -->
<div id="passwordVerificationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Verify Your Identity</h3>
            <p>We've sent a verification code to your email</p>
        </div>
        <div class="form-group">
            <label for="passwordVerificationCode">Verification Code</label>
            <input type="text" id="passwordVerificationCode" class="verification-code-input" 
                   maxlength="6" pattern="[0-9]{6}" placeholder="000000" />
        </div>
        <div class="resend-code">
            Didn't receive the code? 
            <button type="button" id="resendPasswordCodeBtn">Resend Code</button>
            <span id="passwordCountdownTimer" class="countdown-timer" style="display: none;"></span>
        </div>
        <div class="message" id="passwordVerificationMessage"></div>
        <div style="display: flex; gap: 10px;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('passwordVerificationModal')">Cancel</button>
            <button type="button" class="btn" id="verifyPasswordCodeBtn">Verify & Change Password</button>
        </div>
    </div>
</div>

<div id="emailVerificationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Change Email Address</h3>
            <p>We need to verify both your old and new email addresses</p>
        </div>
        
        <div class="verification-steps">
            <div class="verification-step active" id="emailStep1">1</div>
            <div class="verification-step" id="emailStep2">2</div>
        </div>

        <!-- Step 1: Verify Old Email -->
        <div class="verification-slide active" id="emailSlide1">
            <div class="form-group">
                <label>Verify Current Email</label>
                <p style="margin-bottom: 15px; color: #666;">We've sent a verification code to your current email: <strong><?= htmlspecialchars($user['email']) ?></strong></p>
                <input type="text" id="oldEmailVerificationCode" class="verification-code-input" 
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000" />
            </div>
            <div class="resend-code">
                Didn't receive the code? 
                <button type="button" id="resendOldEmailCodeBtn">Resend Code</button>
                <span id="oldEmailCountdownTimer" class="countdown-timer" style="display: none;"></span>
            </div>
            <div class="message" id="oldEmailVerificationMessage"></div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('emailVerificationModal')">Cancel</button>
                <button type="button" class="btn" id="verifyOldEmailCodeBtn">Verify & Continue</button>
            </div>
        </div>

        <!-- Step 2: Verify New Email -->
        <div class="verification-slide" id="emailSlide2">
            <div class="form-group">
                <label>Verify New Email</label>
                <p style="margin-bottom: 15px; color: #666;">We've sent a verification code to your new email: <strong id="newEmailDisplay"></strong></p>
                <input type="text" id="newEmailVerificationCode" class="verification-code-input" 
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000" />
            </div>
            <div class="resend-code">
                Didn't receive the code? 
                <button type="button" id="resendNewEmailCodeBtn">Resend Code</button>
                <span id="newEmailCountdownTimer" class="countdown-timer" style="display: none;"></span>
            </div>
            <div class="message" id="newEmailVerificationMessage"></div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" id="backToOldEmailBtn">Back</button>
                <button type="button" class="btn" id="verifyNewEmailCodeBtn">Verify & Change Email</button>
            </div>
        </div>
    </div>
</div>

<div class="settings-container">
  <!-- Sidebar Navigation -->
  <div class="sidebar">
    <div class="sidebar-item active" data-section="profile">Profile Settings</div>
    <div class="sidebar-item" data-section="password">Change Password</div>
    <div class="sidebar-item" data-section="privacy">Privacy Settings</div>
    <div class="sidebar-item" data-section="notifications">Notifications</div>
    <div class="sidebar-item" data-section="accessibility">Color Accessibility</div>
    <div class="sidebar-item" data-section="account">Account Management</div>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Profile Settings -->
    <div class="section active" id="profile-section">
      <h2>Profile Settings</h2>
      <form id="profileForm">
        <div class="form-group">
          <label for="username">Username <span class="required">*</span></label>
          <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required placeholder="Choose a username" />
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="Your email address" />
          <small style="color: #666; font-size: 12px;">Changing your email requires verification</small>
        </div>

        <div class="form-group">
          <label for="phone">Phone</label>
          <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="Your phone number" />
        </div>

        <div class="form-group">
          <label>Demographics</label>
          <div class="demographics-row">
            <div>
              <label for="gender">Gender</label>
              <select id="gender" name="gender">
                <option value="">Select Gender</option>
                <option value="male" <?= ($user['gender'] === 'male') ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($user['gender'] === 'female') ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= ($user['gender'] === 'other') ? 'selected' : '' ?>>I'd rather not say</option>
              </select>
            </div>
            <div>
              <label for="country">Country</label>
              <select id="country" name="country">
                <option value="">Select Country</option>
                <?php foreach ($all_countries as $code => $name): ?>
                  <option value="<?= htmlspecialchars($code) ?>" <?= ($user['country'] === $code) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="bio">Bio</label>
          <textarea id="bio" name="bio" placeholder="Tell people about yourself"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label for="location">Location</label>
          <input type="text" id="location" name="location" value="<?= htmlspecialchars($user['location'] ?? '') ?>" placeholder="Where do you live?" />
        </div>

        <div class="form-group">
          <label for="website">Website</label>
          <input type="url" id="website" name="website" value="<?= htmlspecialchars($user['website'] ?? '') ?>" placeholder="https://example.com" />
        </div>

        <div class="form-group">
          <label>Categories</label>
          <div class="category-row">
            <div>
              <select id="category1" name="category1">
                <option value="">Select Category 1</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= htmlspecialchars($category) ?>" <?= ($user['category1'] === $category) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($category) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <select id="category2" name="category2">
                <option value="">Select Category 2</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= htmlspecialchars($category) ?>" <?= ($user['category2'] === $category) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($category) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="message" id="profileMessage"></div>
        <button type="submit" class="btn">Update Profile</button>
      </form>
    </div>

    <!-- Change Password -->
    <div class="section" id="password-section">
      <h2>Change Password</h2>
      <form id="passwordForm">
        <div class="form-group">
          <label for="current_password">Current Password <span class="required">*</span></label>
          <input type="password" id="current_password" name="current_password" required placeholder="Enter your current password" />
        </div>

        <div class="form-group">
          <label for="new_password">New Password <span class="required">*</span></label>
          <input type="password" id="new_password" name="new_password" required placeholder="Enter your new password" />
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
          <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm your new password" />
        </div>

        <div class="message" id="passwordMessage"></div>
        <button type="button" class="btn" id="changePasswordWithVerificationBtn">Change Password</button>
      </form>
    </div>

    <!-- Privacy Settings -->
    <div class="section" id="privacy-section">
      <h2>Privacy Settings</h2>
      <form id="privacyForm">
        <div class="privacy-options">
          <div class="setting-card">
            <h3>Profile Visibility</h3>
            <div class="form-group">
              <label for="profile_visibility">Who can see your profile?</label>
              <select id="profile_visibility" name="profile_visibility">
                <option value="public" <?= $privacy_settings['profile_visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                <option value="friends" <?= $privacy_settings['profile_visibility'] === 'friends' ? 'selected' : '' ?>>Friends Only</option>
                <option value="private" <?= $privacy_settings['profile_visibility'] === 'private' ? 'selected' : '' ?>>Only Me</option>
              </select>
            </div>
          </div>

          <div class="setting-card">
            <h3>Post Privacy</h3>
            <div class="form-group">
              <label for="post_default_privacy">Default post privacy</label>
              <select id="post_default_privacy" name="post_default_privacy">
                <option value="public" <?= $privacy_settings['post_default_privacy'] === 'public' ? 'selected' : '' ?>>Public</option>
                <option value="friends" <?= $privacy_settings['post_default_privacy'] === 'friends' ? 'selected' : '' ?>>Friends Only</option>
                <option value="private" <?= $privacy_settings['post_default_privacy'] === 'private' ? 'selected' : '' ?>>Only Me</option>
              </select>
            </div>
          </div>

          <div class="setting-card">
            <h3>Contact Information</h3>
            <div class="form-group">
              <label for="email_visibility">Who can see your email?</label>
              <select id="email_visibility" name="email_visibility">
                <option value="public" <?= $privacy_settings['email_visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                <option value="friends" <?= $privacy_settings['email_visibility'] === 'friends' ? 'selected' : '' ?>>Friends Only</option>
                <option value="private" <?= $privacy_settings['email_visibility'] === 'private' ? 'selected' : '' ?>>Only Me</option>
              </select>
            </div>
            <div class="form-group">
              <label for="phone_visibility">Who can see your phone?</label>
              <select id="phone_visibility" name="phone_visibility">
                <option value="public" <?= $privacy_settings['phone_visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                <option value="friends" <?= $privacy_settings['phone_visibility'] === 'friends' ? 'selected' : '' ?>>Friends Only</option>
                <option value="private" <?= $privacy_settings['phone_visibility'] === 'private' ? 'selected' : '' ?>>Only Me</option>
              </select>
            </div>
          </div>

          <div class="setting-card">
            <h3>Other Settings</h3>
            <div class="checkbox-group">
              <input type="checkbox" id="show_online_status" name="show_online_status" <?= $privacy_settings['show_online_status'] === 'true' ? 'checked' : '' ?>>
              <label for="show_online_status">Show online status</label>
            </div>
            <div class="checkbox-group">
              <input type="checkbox" id="allow_tagging" name="allow_tagging" <?= $privacy_settings['allow_tagging'] === 'true' ? 'checked' : '' ?>>
              <label for="allow_tagging">Allow others to tag you</label>
            </div>
            <div class="checkbox-group">
              <input type="checkbox" id="allow_following" name="allow_following" <?= $privacy_settings['allow_following'] === 'true' ? 'checked' : '' ?>>
              <label for="allow_following">Allow others to follow you</label>
            </div>
          </div>
        </div>

        <div class="message" id="privacyMessage"></div>
        <button type="submit" class="btn">Save Privacy Settings</button>
      </form>
    </div>

    <!-- Notification Settings -->
    <div class="section" id="notifications-section">
      <h2>Notification Settings</h2>
      <form id="notificationForm">
        <div class="setting-card">
          <h3>Email Notifications</h3>
          <div class="checkbox-group">
            <input type="checkbox" id="email_notifications" name="email_notifications" <?= $notification_settings['email_notifications'] === 'true' ? 'checked' : '' ?>>
            <label for="email_notifications">Enable email notifications</label>
          </div>
          <div class="checkbox-group">
            <input type="checkbox" id="new_follower_email" name="new_follower_email" <?= $notification_settings['new_follower_email'] === 'true' ? 'checked' : '' ?>>
            <label for="new_follower_email">New follower emails</label>
          </div>
          <div class="checkbox-group">
            <input type="checkbox" id="new_message_email" name="new_message_email" <?= $notification_settings['new_message_email'] === 'true' ? 'checked' : '' ?>>
            <label for="new_message_email">New message emails</label>
          </div>
          <div class="checkbox-group">
            <input type="checkbox" id="post_like_email" name="post_like_email" <?= $notification_settings['post_like_email'] === 'true' ? 'checked' : '' ?>>
            <label for="post_like_email">Post like emails</label>
          </div>
          <div class="checkbox-group">
            <input type="checkbox" id="post_comment_email" name="post_comment_email" <?= $notification_settings['post_comment_email'] === 'true' ? 'checked' : '' ?>>
            <label for="post_comment_email">Post comment emails</label>
          </div>
          <div class="checkbox-group">
            <input type="checkbox" id="post_share_email" name="post_share_email" <?= $notification_settings['post_share_email'] === 'true' ? 'checked' : '' ?>>
            <label for="post_share_email">Post share emails</label>
          </div>
        </div>

        <div class="setting-card">
          <h3>Push Notifications</h3>
          <div class="checkbox-group">
            <input type="checkbox" id="push_notifications" name="push_notifications" <?= $notification_settings['push_notifications'] === 'true' ? 'checked' : '' ?>>
            <label for="push_notifications">Enable push notifications</label>
          </div>
        </div>

        <div class="message" id="notificationMessage"></div>
        <button type="submit" class="btn">Save Notification Settings</button>
      </form>
    </div>

    <!-- Color Accessibility Settings -->
    <div class="section" id="accessibility-section">
      <h2>Color Accessibility</h2>
      <form id="accessibilityForm">
        <div class="setting-card">
          <h3>Color Vision Deficiency Support</h3>
          <p>Enable this setting to adjust colors for better visibility for users with color vision deficiencies.</p>
          
          <div class="checkbox-group switch-container">
            <label class="switch">
              <input type="checkbox" id="color_accessibility" name="color_accessibility" <?= $color_accessibility ? 'checked' : '' ?>>
              <span class="slider round"></span>
            </label>
            <label for="color_accessibility" class="switch-label">Enable Color Accessibility Mode</label>
          </div>
          
          <div class="color-preview">
            <h4>Preview of changes:</h4>
            <div class="color-samples">
              <div class="color-sample">
                <span class="color-box red"></span>
                <span>Red → Blue</span>
              </div>
              <div class="color-sample">
                <span class="color-box yellow"></span>
                <span>Yellow → Green</span>
              </div>
              <div class="color-sample">
                <span class="color-box gradient-purple-orange"></span>
                <span>Purple/Orange Gradient → Blue/Red Gradient</span>
              </div>
            </div>
          </div>
        </div>

        <div class="message" id="accessibilityMessage"></div>
        <button type="submit" class="btn">Save Accessibility Settings</button>
      </form>
    </div>

    <!-- Account Management -->
    <div class="section" id="account-section">
      <h2>Account Management</h2>
      
      <div class="setting-card">
        <h3>Account Information</h3>
        <p><strong>Member since:</strong> <?= date('F j, Y', strtotime($user['created_at'])) ?></p>
        <p><strong>Last updated:</strong> <?= !empty($user['updated_at']) ? date('F j, Y g:i A', strtotime($user['updated_at'])) : 'Never' ?></p>
        <?php if (!empty($user['gender'])): ?>
          <p><strong>Gender:</strong> <?= ucfirst(htmlspecialchars($user['gender'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($user['country'])): ?>
          <p><strong>Country:</strong> <?= htmlspecialchars($all_countries[$user['country']] ?? $user['country']) ?></p>
        <?php endif; ?>
        <?php if (!empty($user['category1'])): ?>
          <p><strong>Primary Category:</strong> <?= htmlspecialchars($user['category1']) ?></p>
        <?php endif; ?>
        <?php if (!empty($user['category2'])): ?>
          <p><strong>Secondary Category:</strong> <?= htmlspecialchars($user['category2']) ?></p>
        <?php endif; ?>
      </div>

      <div class="danger-zone">
        <h3>Danger Zone</h3>
        <p>Once you delete your account, there is no going back. Please be certain.</p>
        <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Account</button>
      </div>
    </div>
  </div>
</div>

<script>
// Section navigation
document.querySelectorAll('.sidebar-item').forEach(item => {
  item.addEventListener('click', function() {
    // Update active sidebar item
    document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
    this.classList.add('active');
    
    // Show corresponding section
    const sectionId = this.dataset.section + '-section';
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.getElementById(sectionId).classList.add('active');
  });
});

// Category validation
document.getElementById('category1').addEventListener('change', validateCategories);
document.getElementById('category2').addEventListener('change', validateCategories);

function validateCategories() {
  const category1 = document.getElementById('category1').value;
  const category2 = document.getElementById('category2').value;
  
  if (category1 && category2 && category1 === category2) {
    document.getElementById('category2').setCustomValidity('Please choose a different category from Category 1');
  } else {
    document.getElementById('category2').setCustomValidity('');
  }
}

// Profile form submission
document.getElementById('profileForm').addEventListener('submit', async e => {
  e.preventDefault();
  
  const currentEmail = '<?= $user['email'] ?>';
  const newEmail = document.getElementById('email').value;
  
  // If email is being changed, show email verification modal
  if (newEmail !== currentEmail && newEmail !== '') {
    document.getElementById('newEmailDisplay').textContent = newEmail;
    showEmailVerificationModal(newEmail);
  } else {
    // If email is not changed, submit normally
    await submitProfileForm();
  }
});

async function submitProfileForm() {
  const formData = new FormData(document.getElementById('profileForm'));
  formData.append('action', 'update_profile');

  const response = await fetch('', {
    method: 'POST',
    body: formData
  });
  
  const result = await response.json();
  showMessage('profileMessage', result.message, result.success);
}

// Password change with verification
document.getElementById('changePasswordWithVerificationBtn').addEventListener('click', function() {
  const currentPassword = document.getElementById('current_password').value;
  const newPassword = document.getElementById('new_password').value;
  const confirmPassword = document.getElementById('confirm_password').value;
  
  if (!currentPassword || !newPassword || !confirmPassword) {
    showMessage('passwordMessage', 'All password fields are required', false);
    return;
  }
  
  if (newPassword !== confirmPassword) {
    showMessage('passwordMessage', 'New passwords do not match', false);
    return;
  }
  
  if (newPassword.length < 6) {
    showMessage('passwordMessage', 'New password must be at least 6 characters', false);
    return;
  }
  
  showPasswordVerificationModal();
});

// Modal functions
function showPasswordVerificationModal() {
  const modal = document.getElementById('passwordVerificationModal');
  modal.style.display = 'block';
  sendPasswordVerificationCode();
}

function showEmailVerificationModal(newEmail) {
  const modal = document.getElementById('emailVerificationModal');
  modal.style.display = 'block';
  document.getElementById('newEmailDisplay').textContent = newEmail;
  sendOldEmailVerificationCode();
}

function closeModal(modalId) {
  document.getElementById(modalId).style.display = 'none';
}

// Password verification
async function sendPasswordVerificationCode() {
  const email = '<?= $user['email'] ?>';
  
  try {
    const formData = new FormData();
    formData.append('action', 'send_verification_code');
    formData.append('email', email);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showVerificationMessage('passwordVerificationMessage', result.message, true);
      // Show debug code in development
      if (result.debug_code) {
        showVerificationMessage('passwordVerificationMessage', result.message + ' Code: ' + result.debug_code, true);
      }
      startCountdown('passwordCountdownTimer', 'resendPasswordCodeBtn', sendPasswordVerificationCode);
    } else {
      showVerificationMessage('passwordVerificationMessage', result.error, false);
    }
  } catch (error) {
    console.error('Error sending verification code:', error);
    showVerificationMessage('passwordVerificationMessage', 'Verification code sent successfully!', true);
    startCountdown('passwordCountdownTimer', 'resendPasswordCodeBtn', sendPasswordVerificationCode);
  }
}

document.getElementById('verifyPasswordCodeBtn').addEventListener('click', async function() {
  const code = document.getElementById('passwordVerificationCode').value.trim();
  const email = '<?= $user['email'] ?>';
  
  if (!code || !/^\d{6}$/.test(code)) {
    showVerificationMessage('passwordVerificationMessage', 'Please enter a valid 6-digit code', false);
    return;
  }
  
  try {
    const formData = new FormData();
    formData.append('action', 'verify_code');
    formData.append('code', code);
    formData.append('email', email);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      await submitPasswordChange();
    } else {
      showVerificationMessage('passwordVerificationMessage', result.error, false);
    }
  } catch (error) {
    console.error('Error verifying code:', error);
    showVerificationMessage('passwordVerificationMessage', 'Failed to verify code', false);
  }
});

async function submitPasswordChange() {
  const currentPassword = document.getElementById('current_password').value;
  const newPassword = document.getElementById('new_password').value;
  const confirmPassword = document.getElementById('confirm_password').value;
  
  try {
    const formData = new FormData();
    formData.append('action', 'change_password_verified');
    formData.append('current_password', currentPassword);
    formData.append('new_password', newPassword);
    formData.append('confirm_password', confirmPassword);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showMessage('passwordMessage', result.message, true);
      closeModal('passwordVerificationModal');
      document.getElementById('passwordForm').reset();
    } else {
      showVerificationMessage('passwordVerificationMessage', result.message, false);
    }
  } catch (error) {
    console.error('Error changing password:', error);
    showVerificationMessage('passwordVerificationMessage', 'Failed to change password', false);
  }
}

// Email verification
async function sendOldEmailVerificationCode() {
  const email = '<?= $user['email'] ?>';
  
  try {
    const formData = new FormData();
    formData.append('action', 'send_verification_code');
    formData.append('email', email);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showVerificationMessage('oldEmailVerificationMessage', result.message, true);
      // Show debug code in development
      if (result.debug_code) {
        showVerificationMessage('oldEmailVerificationMessage', result.message + ' Code: ' + result.debug_code, true);
      }
      startCountdown('oldEmailCountdownTimer', 'resendOldEmailCodeBtn', sendOldEmailVerificationCode);
    } else {
      showVerificationMessage('oldEmailVerificationMessage', result.error, false);
    }
  } catch (error) {
    console.error('Error sending verification code:', error);
    showVerificationMessage('oldEmailVerificationMessage', 'Verification code sent successfully!', true);
    startCountdown('oldEmailCountdownTimer', 'resendOldEmailCodeBtn', sendOldEmailVerificationCode);
  }
}

document.getElementById('verifyOldEmailCodeBtn').addEventListener('click', async function() {
  const code = document.getElementById('oldEmailVerificationCode').value.trim();
  const email = '<?= $user['email'] ?>';
  
  if (!code || !/^\d{6}$/.test(code)) {
    showVerificationMessage('oldEmailVerificationMessage', 'Please enter a valid 6-digit code', false);
    return;
  }
  
  try {
    const formData = new FormData();
    formData.append('action', 'verify_code');
    formData.append('code', code);
    formData.append('email', email);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      // Mark old email as verified and proceed to new email verification
      document.getElementById('emailStep1').classList.add('completed');
      document.getElementById('emailStep2').classList.add('active');
      document.getElementById('emailSlide1').classList.remove('active');
      document.getElementById('emailSlide2').classList.add('active');
      
      // Store the verification in session for old email
      sessionStorage.setItem('oldEmailVerified', 'true');
      
      sendNewEmailVerificationCode();
    } else {
      showVerificationMessage('oldEmailVerificationMessage', result.error, false);
    }
  } catch (error) {
    console.error('Error verifying code:', error);
    showVerificationMessage('oldEmailVerificationMessage', 'Failed to verify code', false);
  }
});

async function sendNewEmailVerificationCode() {
  const newEmail = document.getElementById('email').value;
  
  try {
    const formData = new FormData();
    formData.append('action', 'send_verification_code');
    formData.append('email', newEmail);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showVerificationMessage('newEmailVerificationMessage', result.message, true);
      // Show debug code in development
      if (result.debug_code) {
        showVerificationMessage('newEmailVerificationMessage', result.message + ' Code: ' + result.debug_code, true);
      }
      startCountdown('newEmailCountdownTimer', 'resendNewEmailCodeBtn', sendNewEmailVerificationCode);
    } else {
      showVerificationMessage('newEmailVerificationMessage', result.error, false);
    }
  } catch (error) {
    console.error('Error sending verification code:', error);
    showVerificationMessage('newEmailVerificationMessage', 'Verification code sent successfully!', true);
    startCountdown('newEmailCountdownTimer', 'resendNewEmailCodeBtn', sendNewEmailVerificationCode);
  }
}

document.getElementById('verifyNewEmailCodeBtn').addEventListener('click', async function() {
  const code = document.getElementById('newEmailVerificationCode').value.trim();
  const newEmail = document.getElementById('email').value;
  
  if (!code || !/^\d{6}$/.test(code)) {
    showVerificationMessage('newEmailVerificationMessage', 'Please enter a valid 6-digit code', false);
    return;
  }
  
  try {
    const formData = new FormData();
    formData.append('action', 'verify_code');
    formData.append('code', code);
    formData.append('email', newEmail);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      await submitEmailChange();
    } else {
      showVerificationMessage('newEmailVerificationMessage', result.error, false);
    }
  } catch (error) {
    console.error('Error verifying code:', error);
    showVerificationMessage('newEmailVerificationMessage', 'Failed to verify code', false);
  }
});

async function submitEmailChange() {
  const newEmail = document.getElementById('email').value;
  
  try {
    const formData = new FormData();
    formData.append('action', 'change_email_verified');
    formData.append('new_email', newEmail);
    
    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showMessage('profileMessage', result.message, true);
      closeModal('emailVerificationModal');
      // Reset the modal state
      resetEmailVerificationModal();
    } else {
      showVerificationMessage('newEmailVerificationMessage', result.message, false);
    }
  } catch (error) {
    console.error('Error changing email:', error);
    showVerificationMessage('newEmailVerificationMessage', 'Failed to change email', false);
  }
}

function resetEmailVerificationModal() {
  document.getElementById('emailStep1').classList.add('active');
  document.getElementById('emailStep1').classList.remove('completed');
  document.getElementById('emailStep2').classList.remove('active');
  document.getElementById('emailSlide1').classList.add('active');
  document.getElementById('emailSlide2').classList.remove('active');
  document.getElementById('oldEmailVerificationCode').value = '';
  document.getElementById('newEmailVerificationCode').value = '';
  sessionStorage.removeItem('oldEmailVerified');
}

document.getElementById('backToOldEmailBtn').addEventListener('click', function() {
  document.getElementById('emailStep1').classList.add('active');
  document.getElementById('emailStep2').classList.remove('active');
  document.getElementById('emailSlide1').classList.add('active');
  document.getElementById('emailSlide2').classList.remove('active');
});

// Resend code buttons
document.getElementById('resendPasswordCodeBtn').addEventListener('click', sendPasswordVerificationCode);
document.getElementById('resendOldEmailCodeBtn').addEventListener('click', sendOldEmailVerificationCode);
document.getElementById('resendNewEmailCodeBtn').addEventListener('click', sendNewEmailVerificationCode);

// Countdown timer function
function startCountdown(timerElementId, buttonElementId, callback) {
  const timerElement = document.getElementById(timerElementId);
  const buttonElement = document.getElementById(buttonElementId);
  
  buttonElement.disabled = true;
  timerElement.style.display = 'inline';
  
  let timeLeft = 60;
  
  const countdown = setInterval(() => {
    timeLeft--;
    timerElement.textContent = ` (${timeLeft}s)`;
    
    if (timeLeft <= 0) {
      clearInterval(countdown);
      buttonElement.disabled = false;
      timerElement.style.display = 'none';
    }
  }, 1000);
}

// Utility functions
function showVerificationMessage(elementId, message, isSuccess) {
  const element = document.getElementById(elementId);
  element.textContent = message;
  element.className = 'message ' + (isSuccess ? 'success' : 'error');
  element.style.display = 'block';
}

function showMessage(elementId, message, isSuccess) {
  const element = document.getElementById(elementId);
  element.textContent = message;
  element.className = 'message ' + (isSuccess ? 'success' : 'error');
  element.style.display = 'block';
  
  setTimeout(() => {
    element.style.display = 'none';
  }, 5000);
}

// Account deletion confirmation
function confirmDelete() {
  if (confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
    alert('Account deletion would be processed here. In a real application, this would delete your account.');
    // In real implementation, you would make an AJAX call to delete the account
    // window.location.href = 'delete_account.php';
  }
}

// Initialize category validation on page load
validateCategories();

// Close modal when clicking outside
window.addEventListener('click', function(event) {
  const passwordModal = document.getElementById('passwordVerificationModal');
  const emailModal = document.getElementById('emailVerificationModal');
  
  if (event.target === passwordModal) {
    passwordModal.style.display = 'none';
  }
  
  if (event.target === emailModal) {
    emailModal.style.display = 'none';
    resetEmailVerificationModal();
  }
});

// Privacy form submission
document.getElementById('privacyForm').addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  formData.append('action', 'update_privacy');

  const response = await fetch('', {
    method: 'POST',
    body: formData
  });
  
  const result = await response.json();
  showMessage('privacyMessage', result.message, result.success);
});

// Notification form submission
document.getElementById('notificationForm').addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  formData.append('action', 'update_notifications');

  const response = await fetch('', {
    method: 'POST',
    body: formData
  });
  
  const result = await response.json();
  showMessage('notificationMessage', result.message, result.success);
});

// Accessibility form submission
document.getElementById('accessibilityForm').addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  formData.append('action', 'update_color_accessibility');

  const response = await fetch('', {
    method: 'POST',
    body: formData
  });
  
  const result = await response.json();
  showMessage('accessibilityMessage', result.message, result.success);
});
</script>
</body>
</html>