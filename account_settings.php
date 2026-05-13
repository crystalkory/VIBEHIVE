<?php
session_start();
require_once 'vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once "header.php";
require_once "footer.php";
require_once "config.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: signup.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user = null;

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: signup.php');
    exit;
}

// Define categories
$categories = [
    'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 
    'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'
];

// Comprehensive countries list organized by continent
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
asort($all_countries);

// Function to send verification email using PHPMailer
function sendVerificationEmail($email, $code, $type = 'old') {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings for Gmail SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'giftbanjo71@gmail.com'; // REPLACE WITH YOUR GMAIL
        $mail->Password = 'twitzvlomiwdyubd'; // REPLACE WITH YOUR GMAIL APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('giftbanjo71@gmail.com', 'Vibehive');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        
        if ($type === 'old') {
            $mail->Subject = 'Verify Your Current Email - Account Update';
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
                        <h2 style='color: #7b68ee; text-align: center;'>Email Verification Required</h2>
                        <p>Hello,</p>
                        <p>You are attempting to change your account email address. To verify that this is you, please use the following verification code:</p>
                        <div class='code'>{$code}</div>
                        <p class='note'>This code will expire in 5 minutes.</p>
                        <p class='note'>If you didn't request this change, please secure your account immediately.</p>
                        <div class='footer'>
                            <p>Thank you,<br>Vibehive Security Team</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
            $mail->AltBody = "Email change verification code: {$code}. This code will expire in 5 minutes. If you didn't request this change, please secure your account.";
        } else {
            $mail->Subject = 'Verify Your New Email - Account Update';
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
                        <h2 style='color: #7b68ee; text-align: center;'>New Email Verification</h2>
                        <p>Hello,</p>
                        <p>You are adding this email address to your Vibehive account. To complete the verification, please use the following code:</p>
                        <div class='code'>{$code}</div>
                        <p class='note'>This code will expire in 5 minutes.</p>
                        <p class='note'>If you didn't request this change, please ignore this email.</p>
                        <div class='footer'>
                            <p>Thank you,<br>Vibehive</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
            $mail->AltBody = "New email verification code: {$code}. This code will expire in 5 minutes. If you didn't request this change, please ignore this email.";
        }
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $category1 = $_POST['category1'] ?? null;
        $category2 = $_POST['category2'] ?? null;
        $country = $_POST['country'] ?? null;
        $gender = $_POST['gender'] ?? null;
        
        // Check if email is being changed
        $new_email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $old_email_verified = isset($_SESSION['old_email_verified']) && $_SESSION['old_email_verified'] === $user['email'];
        $new_email_verified = isset($_SESSION['new_email_verified']) && $_SESSION['new_email_verified'] === $new_email;
        
        // If email is being changed, verify both old and new emails
        if ($new_email && $new_email !== $user['email']) {
            if (!$old_email_verified) {
                http_response_code(400);
                echo json_encode(['error' => 'Please verify your current email first']);
                exit;
            }
            
            if (!$new_email_verified) {
                http_response_code(400);
                echo json_encode(['error' => 'Please verify your new email first']);
                exit;
            }
            
            // Check if new email is already in use
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = :email AND id != :id");
            $stmt->execute([':email' => $new_email, ':id' => $user_id]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'New email is already in use by another account']);
                exit;
            }
        }
        
        // Validate categories
        if ($category1 && $category2 && $category1 === $category2) {
            http_response_code(400);
            echo json_encode(['error' => 'Please choose different categories']);
            exit;
        }

        
        try {
            // Update user record
            $update = $pdo->prepare("
                UPDATE users 
                SET username = :username, 
                    phone = :phone, 
                    category1 = :category1, 
                    category2 = :category2, 
                    country = :country, 
                    gender = :gender
                    " . ($new_email && $new_email !== $user['email'] ? ", email = :email" : "") . "
                WHERE id = :id
            ");
            
            $params = [
                ':username' => $username,
                ':phone' => $phone ?: null,
                ':category1' => $category1 ?: null,
                ':category2' => $category2 ?: null,
                ':country' => $country,
                ':gender' => $gender,
                ':id' => $user_id
            ];
            
            if ($new_email && $new_email !== $user['email']) {
                $params[':email'] = $new_email;
            }
            
            $update->execute($params);
            
            // Update session username if changed
            if ($username !== $_SESSION['username']) {
                $_SESSION['username'] = $username;
            }
            
            // Clear verification sessions
            unset($_SESSION['old_email_verified']);
            unset($_SESSION['new_email_verified']);
            unset($_SESSION['old_verification_code']);
            unset($_SESSION['new_verification_code']);
            
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'send_old_email_code') {
        $email = $user['email']; // Use current email from database
        
        if (!$email) {
            http_response_code(400);
            echo json_encode(['error' => 'No email found for your account']);
            exit;
        }
        
        // Check if we've sent a code recently (within 1 minute)
        if (isset($_SESSION['old_verification_code_sent']) && 
            (time() - $_SESSION['old_verification_code_sent']) < 60) {
            $remaining = 60 - (time() - $_SESSION['old_verification_code_sent']);
            http_response_code(429);
            echo json_encode(['error' => "Please wait $remaining seconds before requesting a new code"]);
            exit;
        }
        
        // Generate 6-digit code
        $code = sprintf("%06d", mt_rand(1, 999999));
        
        // Store code in session with expiration (5 minutes)
        $_SESSION['old_verification_code'] = $code;
        $_SESSION['old_verification_email'] = $email;
        $_SESSION['old_verification_code_expires'] = time() + 300; // 5 minutes
        $_SESSION['old_verification_code_sent'] = time();
        
        // Send verification email
        $emailSent = sendVerificationEmail($email, $code, 'old');
        
        if ($emailSent) {
            echo json_encode([
                'success' => true, 
                'message' => 'Verification code sent to your current email'
            ]);
        } else {
            // For development/testing, return the code even if email fails
            error_log("Old email verification code for $email: $code");
            echo json_encode([
                'success' => true, 
                'debug_code' => $code
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'send_new_email_code') {
        $new_email = filter_var(trim($_POST['new_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$new_email) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid new email is required']);
            exit;
        }
        
        // Check if new email is same as current
        if ($new_email === $user['email']) {
            http_response_code(400);
            echo json_encode(['error' => 'New email is the same as current email']);
            exit;
        }
        
        // Check if email is already in use
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = :email AND id != :id");
        $stmt->execute([':email' => $new_email, ':id' => $user_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'This email is already in use by another account']);
            exit;
        }
        
        // Check if we've sent a code recently (within 1 minute)
        if (isset($_SESSION['new_verification_code_sent']) && 
            (time() - $_SESSION['new_verification_code_sent']) < 60) {
            $remaining = 60 - (time() - $_SESSION['new_verification_code_sent']);
            http_response_code(429);
            echo json_encode(['error' => "Please wait $remaining seconds before requesting a new code"]);
            exit;
        }
        
        // Generate 6-digit code
        $code = sprintf("%06d", mt_rand(1, 999999));
        
        // Store code in session with expiration (5 minutes)
        $_SESSION['new_verification_code'] = $code;
        $_SESSION['new_verification_email'] = $new_email;
        $_SESSION['new_verification_code_expires'] = time() + 300; // 5 minutes
        $_SESSION['new_verification_code_sent'] = time();
        
        // Send verification email
        $emailSent = sendVerificationEmail($new_email, $code, 'new');
        
        if ($emailSent) {
            echo json_encode([
                'success' => true, 
                'message' => 'Verification code sent to your new email'
            ]);
        } else {
            // For development/testing, return the code even if email fails
            error_log("New email verification code for $new_email: $code");
            echo json_encode([
                'success' => true, 
                'debug_code' => $code
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'verify_old_email_code') {
        $entered_code = trim($_POST['code'] ?? '');
        
        if (!$entered_code) {
            http_response_code(400);
            echo json_encode(['error' => 'Verification code is required']);
            exit;
        }
        
        // Check if code exists and matches
        if (!isset($_SESSION['old_verification_code']) || 
            !isset($_SESSION['old_verification_code_expires'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No verification code found. Please request a new one.']);
            exit;
        }
        
        // Check if code has expired
        if (time() > $_SESSION['old_verification_code_expires']) {
            unset($_SESSION['old_verification_code']);
            unset($_SESSION['old_verification_code_expires']);
            http_response_code(400);
            echo json_encode(['error' => 'Verification code has expired. Please request a new one.']);
            exit;
        }
        
        // Check if code is correct
        if ($_SESSION['old_verification_code'] !== $entered_code) {
            http_response_code(400);
            echo json_encode(['error' => 'Incorrect verification code']);
            exit;
        }
        
        // Code is valid - mark old email as verified in session
        $_SESSION['old_email_verified'] = $user['email'];
        
        echo json_encode(['success' => true, 'message' => 'Current email verified successfully']);
        exit;
    }
    
    if ($_POST['action'] === 'verify_new_email_code') {
        $entered_code = trim($_POST['code'] ?? '');
        $new_email = filter_var(trim($_POST['new_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$entered_code || !$new_email) {
            http_response_code(400);
            echo json_encode(['error' => 'Code and new email are required']);
            exit;
        }
        
        // Check if code exists and matches
        if (!isset($_SESSION['new_verification_code']) || 
            !isset($_SESSION['new_verification_email']) ||
            !isset($_SESSION['new_verification_code_expires'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No verification code found. Please request a new one.']);
            exit;
        }
        
        // Check if code has expired
        if (time() > $_SESSION['new_verification_code_expires']) {
            unset($_SESSION['new_verification_code']);
            unset($_SESSION['new_verification_code_expires']);
            http_response_code(400);
            echo json_encode(['error' => 'Verification code has expired. Please request a new one.']);
            exit;
        }
        
        // Check if email matches and code is correct
        if ($_SESSION['new_verification_email'] !== $new_email || 
            $_SESSION['new_verification_code'] !== $entered_code) {
            http_response_code(400);
            echo json_encode(['error' => 'Incorrect verification code']);
            exit;
        }
        
        // Code is valid - mark new email as verified in session
        $_SESSION['new_email_verified'] = $new_email;
        
        echo json_encode(['success' => true, 'message' => 'New email verified successfully']);
        exit;
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $target_dir = "uploads/profile_pictures/";
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $target_file = $target_dir . basename($_FILES["profile_picture"]["name"]);
    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    
    // Check if image file is an actual image
    $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
    if ($check === false) {
        die("File is not an image.");
    }
    
    // Check file size (5MB max)
    if ($_FILES["profile_picture"]["size"] > 5000000) {
        die("Sorry, your file is too large.");
    }
    
    // Allow certain file formats
    if (!in_array($imageFileType, ["jpg", "jpeg", "png", "gif"])) {
        die("Sorry, only JPG, JPEG, PNG & GIF files are allowed.");
    }
    
    // Generate unique filename
    $new_filename = $user_id . '_' . time() . '.' . $imageFileType;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
        // Update database with new profile picture path
        $stmt = $pdo->prepare("UPDATE users SET profile_picture = :profile_picture WHERE id = :id");
        $stmt->execute([
            ':profile_picture' => $target_file,
            ':id' => $user_id
        ]);
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=profile_updated");
        exit;
    } else {
        die("Sorry, there was an error uploading your file.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Account Settings</title>
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
    align-items: flex-start;
    padding: 30px 15px;
    min-height: 100vh;
    margin: 0;
    color: #333;
}

.container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 30px;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border-radius: 15px;
    margin: auto;
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.container:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
}

h2 {
    margin-bottom: 25px;
    text-align: center;
    color: #7b68ee;
    font-size: 28px;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.profile-picture-section {
    text-align: center;
    margin-bottom: 30px;
}

.profile-picture {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 5px solid #7b68ee;
    margin-bottom: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.upload-btn {
    background: #7b68ee;
    color: white;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
    display: inline-block;
    transition: all 0.3s ease;
}

.upload-btn:hover {
    background: #6a5acd;
    transform: translateY(-2px);
}

.upload-form {
    display: none;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #7b68ee;
    font-size: 14px;
}

input[type=text],
input[type=email],
input[type=tel],
input[type=password],
select {
    width: 100%;
    padding: 15px;
    margin-bottom: 20px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    border-radius: 10px;
    font-size: 15px;
    box-sizing: border-box;
    transition: all 0.3s ease;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    font-family: inherit;
}

input:focus, select:focus {
    border-color: #7b68ee;
    outline: none;
    box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

button {
    width: 100%;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: #fff;
    padding: 16px;
    font-size: 16px;
    font-weight: 600;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
    font-family: inherit;
    margin-top: 10px;
}

button:hover {
    background: linear-gradient(135deg, #6a5acd, #5d4fbb);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

.category-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}

.category-row > div {
    display: flex;
    flex-direction: column;
}

.message {
    margin-bottom: 20px;
    font-size: 14px;
    padding: 15px;
    border-radius: 10px;
    font-weight: 600;
    text-align: center;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.error {
    color: white;
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    border: 1px solid rgba(255, 107, 107, 0.3);
}

.success {
    color: white;
    background: linear-gradient(135deg, #48bb78, #38a169);
    border: 1px solid rgba(72, 187, 120, 0.3);
}

.required {
    color: #ff6b6b;
    font-weight: bold;
}

.optional {
    color: #718096;
    font-size: 12px;
    font-weight: normal;
}

.email-verification-badge {
    display: inline-block;
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 4px;
    margin-left: 8px;
    font-weight: 600;
}

.email-verified {
    background: #48bb78;
    color: white;
}

.email-not-verified {
    background: #ed8936;
    color: white;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
}

.modal-content {
    background: white;
    margin: 10% auto;
    padding: 30px;
    border-radius: 15px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    position: relative;
}

.close {
    position: absolute;
    right: 20px;
    top: 15px;
    font-size: 28px;
    font-weight: bold;
    color: #aaa;
    cursor: pointer;
}

.close:hover {
    color: #000;
}

.modal-title {
    color: #7b68ee;
    margin-bottom: 20px;
    font-size: 24px;
}

.verification-step {
    margin-bottom: 25px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 10px;
    border-left: 4px solid #7b68ee;
}

.verification-step h4 {
    color: #7b68ee;
    margin-bottom: 15px;
}

.verification-code-input {
    font-size: 24px !important;
    text-align: center;
    letter-spacing: 10px;
    font-weight: bold;
    margin-bottom: 15px !important;
}

.resend-code {
    text-align: center;
    margin-top: 15px;
}

.resend-code button {
    background: none;
    border: none;
    color: #7b68ee;
    text-decoration: underline;
    cursor: pointer;
    font-size: 14px;
    width: auto;
    padding: 0;
    margin: 0;
    box-shadow: none;
}

.resend-code button:hover {
    background: none;
    transform: none;
    box-shadow: none;
    color: #6a5acd;
}

.resend-code button:disabled {
    color: #a0aec0;
    cursor: not-allowed;
}

.countdown-timer {
    color: #ff6b6b;
    font-weight: bold;
}

.verification-status {
    display: flex;
    align-items: center;
    margin-top: 10px;
    font-size: 14px;
}

.status-icon {
    width: 20px;
    height: 20px;
    margin-right: 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.status-pending {
    background: #ed8936;
    color: white;
}

.status-verified {
    background: #48bb78;
    color: white;
}

.status-icon i {
    font-style: normal;
}

@media (max-width: 768px) {
    .container {
        padding: 25px;
        margin: 10px;
    }
    
    .category-row {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .modal-content {
        width: 95%;
        margin: 5% auto;
    }
}
</style>
</head>
<body>
<div class="container">
    <h2>Account Settings</h2>
    
    <div class="message" id="message"></div>
    
    <!-- Profile Picture Section -->
    <div class="profile-picture-section">
        <img src="<?= htmlspecialchars($user['profile_picture'] ?? 'default-profile.png') ?>" 
             alt="Profile Picture" 
             class="profile-picture"
             onerror="this.src='default-profile.png'">
        <form class="upload-form" action="" method="post" enctype="multipart/form-data">
            <input type="file" name="profile_picture" id="profile_picture" accept="image/*" required>
            <button type="submit">Upload</button>
        </form>
        <div class="upload-btn" onclick="document.getElementById('profile_picture').click();">
            Change Profile Picture
        </div>
    </div>
    
    <!-- Account Settings Form -->
    <form id="settingsForm">
        <label for="username">Username <span class="required">*</span></label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
        
        <label for="email">Email <span class="required">*</span>
            <?php if (isset($_SESSION['old_email_verified']) && $_SESSION['old_email_verified'] === $user['email']): ?>
                <span class="email-verification-badge email-verified">✓ Current email verified</span>
            <?php endif; ?>
        </label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        <small style="color: #718096; display: block; margin-top: -15px; margin-bottom: 20px;">
            Changing email requires verification of both old and new emails.
        </small>
        
        <label for="phone">Phone <span class="optional">(Optional)</span></label>
        <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
        
        <button type="submit">Update Profile</button>
    </form>
    
    <div style="text-align: center; margin-top: 20px;">
        <a href="video.php" style="color: #7b68ee; text-decoration: none; font-weight: 600;">← Back to Videos</a>
    </div>
</div>

<!-- Email Verification Modal -->
<div id="emailVerificationModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3 class="modal-title">Email Change Verification</h3>
        
        <div id="modalMessage" class="message" style="display: none;"></div>
        
        <!-- Step 1: Verify Current Email -->
        <div class="verification-step" id="step1">
            <h4>Step 1: Verify Current Email</h4>
            <p>We've sent a verification code to your current email: <strong><?= htmlspecialchars($user['email']) ?></strong></p>
            
            <label for="oldEmailCode">Enter Verification Code</label>
            <input type="text" id="oldEmailCode" name="oldEmailCode" maxlength="6" pattern="[0-9]{6}" 
                   placeholder="000000" class="verification-code-input">
            <div class="validation-error" id="oldEmailCodeError" style="display: none;"></div>
            
            <div class="resend-code">
                Didn't receive the code? 
                <button type="button" id="resendOldCodeBtn">Resend Code</button>
                <span id="oldCodeCountdown" class="countdown-timer" style="display: none;"></span>
            </div>
            
            <div class="verification-status">
                <div class="status-icon status-pending" id="oldEmailStatusIcon">?</div>
                <span id="oldEmailStatusText">Pending verification</span>
            </div>
            
            <button type="button" id="verifyOldEmailBtn" style="margin-top: 15px;">Verify Current Email</button>
        </div>
        
        <!-- Step 2: Verify New Email -->
        <div class="verification-step" id="step2" style="display: none;">
            <h4>Step 2: Verify New Email</h4>
            <p>Enter the verification code sent to your new email: <strong id="newEmailDisplay"></strong></p>
            
            <label for="newEmailCode">Enter Verification Code</label>
            <input type="text" id="newEmailCode" name="newEmailCode" maxlength="6" pattern="[0-9]{6}" 
                   placeholder="000000" class="verification-code-input">
            <div class="validation-error" id="newEmailCodeError" style="display: none;"></div>
            
            <div class="resend-code">
                Didn't receive the code? 
                <button type="button" id="resendNewCodeBtn">Resend Code</button>
                <span id="newCodeCountdown" class="countdown-timer" style="display: none;"></span>
            </div>
            
            <div class="verification-status">
                <div class="status-icon status-pending" id="newEmailStatusIcon">?</div>
                <span id="newEmailStatusText">Pending verification</span>
            </div>
            
            <button type="button" id="verifyNewEmailBtn" style="margin-top: 15px;">Verify New Email</button>
        </div>
        
        <!-- Step 3: Complete -->
        <div id="step3" style="display: none; text-align: center; padding: 30px;">
            <div style="font-size: 48px; color: #48bb78; margin-bottom: 20px;">✓</div>
            <h4 style="color: #48bb78;">Email Verification Complete!</h4>
            <p>Both emails have been verified. You can now save your profile changes.</p>
            <button type="button" id="closeModalBtn" style="margin-top: 20px;">Continue</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const settingsForm = document.getElementById('settingsForm');
    const emailInput = document.getElementById('email');
    const messageDiv = document.getElementById('message');
    const modal = document.getElementById('emailVerificationModal');
    const closeBtn = document.querySelector('.close');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const resendOldCodeBtn = document.getElementById('resendOldCodeBtn');
    const resendNewCodeBtn = document.getElementById('resendNewCodeBtn');
    const verifyOldEmailBtn = document.getElementById('verifyOldEmailBtn');
    const verifyNewEmailBtn = document.getElementById('verifyNewEmailBtn');
    const oldEmailCodeInput = document.getElementById('oldEmailCode');
    const newEmailCodeInput = document.getElementById('newEmailCode');
    const oldCodeCountdown = document.getElementById('oldCodeCountdown');
    const newCodeCountdown = document.getElementById('newCodeCountdown');
    const oldEmailStatusIcon = document.getElementById('oldEmailStatusIcon');
    const oldEmailStatusText = document.getElementById('oldEmailStatusText');
    const newEmailStatusIcon = document.getElementById('newEmailStatusIcon');
    const newEmailStatusText = document.getElementById('newEmailStatusText');
    const modalMessage = document.getElementById('modalMessage');
    
    let oldEmailVerified = false;
    let newEmailVerified = false;
    let newEmailValue = '';
    let oldEmailTimer = null;
    let newEmailTimer = null;
    
    // Original email for comparison
    const originalEmail = "<?= htmlspecialchars($user['email']) ?>";
    
    // Check if emails are already verified in session
    <?php if (isset($_SESSION['old_email_verified'])): ?>
        oldEmailVerified = true;
        updateOldEmailStatus(true);
    <?php endif; ?>
    
    <?php if (isset($_SESSION['new_email_verified'])): ?>
        newEmailVerified = true;
        updateNewEmailStatus(true);
    <?php endif; ?>
    
    // Profile picture upload
    document.getElementById('profile_picture').addEventListener('change', function() {
        if (this.files && this.files[0]) {
            this.closest('form').submit();
        }
    });
    
    // Settings form submission
    settingsForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'update_profile');
        
        // Check if email is being changed
        const currentEmail = emailInput.value;
        if (currentEmail !== originalEmail) {
            // Show verification modal if email is being changed
            newEmailValue = currentEmail;
            document.getElementById('newEmailDisplay').textContent = currentEmail;
            
            // Reset modal state
            resetModal();
            showStep(1);
            modal.style.display = 'block';
            
            // Start verification process
            sendOldEmailVerificationCode();
            return;
        }
        
        // If email is not being changed, submit normally
        submitProfileUpdate(formData);
    });
    
    // Modal close handlers
    closeBtn.onclick = function() {
        modal.style.display = 'none';
    }
    
    closeModalBtn.onclick = function() {
        modal.style.display = 'none';
        // Submit the form after verification
        const formData = new FormData(settingsForm);
        formData.append('action', 'update_profile');
        submitProfileUpdate(formData);
    }
    
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
    
    // Verification functions
    function resetModal() {
        document.getElementById('step1').style.display = 'block';
        document.getElementById('step2').style.display = 'none';
        document.getElementById('step3').style.display = 'none';
        modalMessage.style.display = 'none';
        oldEmailCodeInput.value = '';
        newEmailCodeInput.value = '';
        oldEmailCodeInput.classList.remove('input-highlight');
        newEmailCodeInput.classList.remove('input-highlight');
    }
    
    function showStep(stepNumber) {
        document.getElementById('step1').style.display = stepNumber === 1 ? 'block' : 'none';
        document.getElementById('step2').style.display = stepNumber === 2 ? 'block' : 'none';
        document.getElementById('step3').style.display = stepNumber === 3 ? 'block' : 'none';
    }
    
    function updateOldEmailStatus(verified) {
        if (verified) {
            oldEmailStatusIcon.className = 'status-icon status-verified';
            oldEmailStatusIcon.innerHTML = '✓';
            oldEmailStatusText.textContent = 'Current email verified';
            oldEmailVerified = true;
        } else {
            oldEmailStatusIcon.className = 'status-icon status-pending';
            oldEmailStatusIcon.innerHTML = '?';
            oldEmailStatusText.textContent = 'Pending verification';
            oldEmailVerified = false;
        }
    }
    
    function updateNewEmailStatus(verified) {
        if (verified) {
            newEmailStatusIcon.className = 'status-icon status-verified';
            newEmailStatusIcon.innerHTML = '✓';
            newEmailStatusText.textContent = 'New email verified';
            newEmailVerified = true;
        } else {
            newEmailStatusIcon.className = 'status-icon status-pending';
            newEmailStatusIcon.innerHTML = '?';
            newEmailStatusText.textContent = 'Pending verification';
            newEmailVerified = false;
        }
    }
    
    // Send verification code to old email
    async function sendOldEmailVerificationCode() {
        try {
            const formData = new FormData();
            formData.append('action', 'send_old_email_code');
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showModalMessage('Verification code sent to your current email', false);
                
                // Disable resend button for 60 seconds
                resendOldCodeBtn.disabled = true;
                oldCodeCountdown.style.display = 'inline';
                
                let timeLeft = 60;
                updateOldCountdownTimer(timeLeft);
                
                oldEmailTimer = setInterval(() => {
                    timeLeft--;
                    updateOldCountdownTimer(timeLeft);
                    
                    if (timeLeft <= 0) {
                        clearInterval(oldEmailTimer);
                        resendOldCodeBtn.disabled = false;
                        oldCodeCountdown.style.display = 'none';
                    }
                }, 1000);
            } else {
                showModalMessage(result.error || 'Failed to send verification code', true);
            }
        } catch (error) {
            console.error('Error sending verification code:', error);
            showModalMessage('Failed to send verification code', true);
        }
    }
    
    // Send verification code to new email
    async function sendNewEmailVerificationCode() {
        try {
            const formData = new FormData();
            formData.append('action', 'send_new_email_code');
            formData.append('new_email', newEmailValue);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showModalMessage('Verification code sent to your new email', false);
                
                // Disable resend button for 60 seconds
                resendNewCodeBtn.disabled = true;
                newCodeCountdown.style.display = 'inline';
                
                let timeLeft = 60;
                updateNewCountdownTimer(timeLeft);
                
                newEmailTimer = setInterval(() => {
                    timeLeft--;
                    updateNewCountdownTimer(timeLeft);
                    
                    if (timeLeft <= 0) {
                        clearInterval(newEmailTimer);
                        resendNewCodeBtn.disabled = false;
                        newCodeCountdown.style.display = 'none';
                    }
                }, 1000);
            } else {
                showModalMessage(result.error || 'Failed to send verification code', true);
            }
        } catch (error) {
            console.error('Error sending verification code:', error);
            showModalMessage('Failed to send verification code', true);
        }
    }
    
    function updateOldCountdownTimer(seconds) {
        oldCodeCountdown.textContent = ` (${seconds}s)`;
    }
    
    function updateNewCountdownTimer(seconds) {
        newCodeCountdown.textContent = ` (${seconds}s)`;
    }
    
    // Verify old email code
    verifyOldEmailBtn.addEventListener('click', async function() {
        const code = oldEmailCodeInput.value.trim();
        
        if (!code || !/^\d{6}$/.test(code)) {
            showModalMessage('Please enter a valid 6-digit code', true);
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'verify_old_email_code');
            formData.append('code', code);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                updateOldEmailStatus(true);
                showModalMessage('Current email verified successfully!', false);
                oldEmailCodeInput.classList.add('input-highlight');
                
                // Move to step 2 after a short delay
                setTimeout(() => {
                    showStep(2);
                    sendNewEmailVerificationCode();
                }, 1500);
            } else {
                showModalMessage(result.error || 'Incorrect verification code', true);
                oldEmailCodeInput.classList.remove('input-highlight');
            }
        } catch (error) {
            console.error('Error verifying code:', error);
            showModalMessage('Failed to verify code', true);
        }
    });
    
    // Verify new email code
    verifyNewEmailBtn.addEventListener('click', async function() {
        const code = newEmailCodeInput.value.trim();
        
        if (!code || !/^\d{6}$/.test(code)) {
            showModalMessage('Please enter a valid 6-digit code', true);
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'verify_new_email_code');
            formData.append('code', code);
            formData.append('new_email', newEmailValue);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                updateNewEmailStatus(true);
                showModalMessage('New email verified successfully!', false);
                newEmailCodeInput.classList.add('input-highlight');
                
                // Move to step 3 after a short delay
                setTimeout(() => {
                    showStep(3);
                }, 1500);
            } else {
                showModalMessage(result.error || 'Incorrect verification code', true);
                newEmailCodeInput.classList.remove('input-highlight');
            }
        } catch (error) {
            console.error('Error verifying code:', error);
            showModalMessage('Failed to verify code', true);
        }
    });
    
    // Resend code buttons
    resendOldCodeBtn.addEventListener('click', sendOldEmailVerificationCode);
    resendNewCodeBtn.addEventListener('click', sendNewEmailVerificationCode);
    
    function showModalMessage(msg, isError) {
        modalMessage.textContent = msg;
        modalMessage.className = 'message ' + (isError ? 'error' : 'success');
        modalMessage.style.display = 'block';
        
        // Clear message after 5 seconds
        setTimeout(() => {
            modalMessage.style.display = 'none';
        }, 5000);
    }
    
    async function submitProfileUpdate(formData) {
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showMessage('Profile updated successfully!', false);
                // Refresh the page after 2 seconds to show updated data
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                showMessage(result.error || 'Failed to update profile', true);
            }
        } catch (error) {
            console.error('Error updating profile:', error);
            showMessage('Failed to update profile', true);
        }
    }
    
    function showMessage(msg, isError) {
        messageDiv.textContent = msg;
        messageDiv.className = 'message ' + (isError ? 'error' : 'success');
    }
    
    // Email input focus event to warn about verification
    emailInput.addEventListener('focus', function() {
        if (this.value !== originalEmail) {
            // Show a warning tooltip or note
            this.setAttribute('title', 'Changing email requires verification');
        }
    });
    
    // Email input change event
    emailInput.addEventListener('input', function() {
        const emailWarning = document.getElementById('emailWarning');
        if (!emailWarning && this.value !== originalEmail) {
            const warning = document.createElement('div');
            warning.id = 'emailWarning';
            warning.style.cssText = 'color: #ed8936; font-size: 12px; margin-top: -15px; margin-bottom: 10px;';
            warning.textContent = 'Changing email will require verification of both old and new emails.';
            this.parentNode.insertBefore(warning, this.nextSibling);
        } else if (emailWarning && this.value === originalEmail) {
            emailWarning.remove();
        }
    });
});
</script>
</body>
</html>