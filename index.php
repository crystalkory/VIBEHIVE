<?php
session_start();

// Database config (adjust as needed)
require_once "config.php";


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

// Define categories
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

// Function to send verification email using PHPMailer
function sendVerificationEmail($email, $code) {
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
        $mail->Subject = 'Your Verification Code';
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
                        <p>Thank you,<br>Vibehive</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        $mail->AltBody = "Your verification code is: {$code}. This code will expire in 5 minutes. If you didn't request this code, please ignore this email.";
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Handle AJAX requests for signup and login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'signup') {
        $username = trim($_POST['username'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $category1 = $_POST['category1'] ?? null;
        $category2 = $_POST['category2'] ?? null;
        $country = $_POST['country'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $invited_by = isset($_POST['invited_by']) ? (int)$_POST['invited_by'] : null;
        
        if ($invited_by === 0) {
            $invited_by = null;
        }

        // Check if email is verified
        if (!isset($_SESSION['email_verified']) || $_SESSION['email_verified'] !== $email) {
            http_response_code(400);
            echo json_encode(['error' => 'Email not verified. Please verify your email first.']);
            exit;
        }

        // Validate categories - they can't be the same if both are provided
        if ($category1 && $category2 && $category1 === $category2) {
            http_response_code(400);
            echo json_encode(['error' => 'Please choose different categories']);
            exit;
        }

        // Required fields validation
        if (!$username || !$email || !$password || !$gender || !$country) {
            http_response_code(400);
            echo json_encode(['error' => 'Username, email, password, gender, and country are required']);
            exit;
        }

        try {
            // Check uniqueness of email
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already in use']);
                exit;
            }

            // Check uniqueness of phone if provided
            if ($phone) {
                $stmt = $pdo->prepare("SELECT 1 FROM users WHERE phone = :phone");
                $stmt->execute([':phone' => $phone]);
                if ($stmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Phone number already in use']);
                    exit;
                }
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Insert user record with inviter info, categories, country, and gender
            $insert = $pdo->prepare("
                INSERT INTO users (username, email, phone, password_hash, invited_by, category1, category2, country, gender)
                VALUES (:username, :email, :phone, :password_hash, :invited_by, :category1, :category2, :country, :gender)
            ");
            $insert->execute([
                ':username' => $username,
                ':email' => $email,
                ':phone' => $phone ?: null,
                ':password_hash' => $password_hash,
                ':invited_by' => $invited_by,
                ':category1' => $category1 ?: null,
                ':category2' => $category2 ?: null,
                ':country' => $country,
                ':gender' => $gender
            ]);

            $user_id = $pdo->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;

            // Clear verification session after successful signup
            unset($_SESSION['email_verified']);
            unset($_SESSION['verification_code']);
            unset($_SESSION['verification_code_expires']);
            unset($_SESSION['verification_code_sent']);

            echo json_encode(['success' => true, 'user_id' => $user_id]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'login') {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$login || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Login and password are required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE email = :login OR phone = :login LIMIT 1");
            $stmt->execute([':login' => $login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                echo json_encode(['success' => true]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid credentials']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

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
            echo json_encode([
                'success' => true, 
                'message' => 'Verification code sent to your email'
            ]);
        } else {
            // For development/testing, return the code even if email fails
            error_log("Verification code for $email: $code");
            echo json_encode([
                'success' => true, 
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

        // Code is valid - mark email as verified in session
        $_SESSION['email_verified'] = $email;
        
        echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
        exit;
    }
}

// Get invite user ID from query param if present
$invited_by = isset($_GET['invite']) ? (int)$_GET['invite'] : null;

// Get user's country from IP address for auto-detection
function getCountryFromIP() {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // For localhost/testing, use a fallback IP or default country
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return 'US'; // Default to United States for local development
    }
    
    // Use ipapi.co service (free tier available)
    $url = "http://ipapi.co/{$ip}/country_code/";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $country_code = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($country_code) && strlen($country_code) === 2) {
        return $country_code;
    }
    
    return 'US'; // Fallback to United States
}

$detected_country = getCountryFromIP();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Signup / Login</title>
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
    max-width: 450px;
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

input::placeholder {
    color: #a0aec0;
    font-weight: 500;
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

.form-switch {
    text-align: center;
    margin-top: 25px;
    color: #718096;
    font-size: 14px;
    font-weight: 500;
}

.form-switch a {
    color: #7b68ee;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    padding: 5px 10px;
    border-radius: 6px;
}

.form-switch a:hover {
    color: #6a5acd;
    background: rgba(123, 104, 238, 0.1);
    text-decoration: none;
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

.country-info {
    font-size: 12px;
    color: #718096;
    margin-top: -15px;
    margin-bottom: 20px;
    font-style: italic;
    font-weight: 500;
    text-align: center;
    background: rgba(123, 104, 238, 0.1);
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid rgba(123, 104, 238, 0.2);
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

/* Form animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

form {
    animation: fadeInUp 0.6s ease-out;
}

/* Focus states for accessibility */
button:focus,
input:focus,
select:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state for buttons */
button:disabled {
    background: #cbd5e0;
    transform: none;
    box-shadow: none;
    cursor: not-allowed;
}

/* Password strength indicator */
.password-strength {
    margin-top: -15px;
    margin-bottom: 15px;
    font-size: 12px;
    font-weight: 500;
}

.strength-weak {
    color: #ff6b6b;
}

.strength-medium {
    color: #ed8936;
}

.strength-strong {
    color: #48bb78;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 20px 10px;
    }
    
    .container {
        padding: 25px;
        margin: 10px;
    }
    
    h2 {
        font-size: 24px;
        margin-bottom: 20px;
    }
    
    input[type=text],
    input[type=email],
    input[type=tel],
    input[type=password],
    select {
        padding: 12px;
        margin-bottom: 15px;
        font-size: 14px;
    }
    
    button {
        padding: 14px;
        font-size: 15px;
    }
    
    .category-row {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .form-switch {
        margin-top: 20px;
        font-size: 13px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 15px 5px;
    }
    
    .container {
        padding: 20px;
    }
    
    h2 {
        font-size: 22px;
        margin-bottom: 15px;
    }
    
    input[type=text],
    input[type=email],
    input[type=tel],
    input[type=password],
    select {
        padding: 10px;
        margin-bottom: 12px;
        font-size: 13px;
    }
    
    button {
        padding: 12px;
        font-size: 14px;
    }
    
    .message {
        padding: 12px;
        font-size: 13px;
    }
    
    .country-info {
        font-size: 11px;
        padding: 6px 10px;
    }
    
    .form-switch {
        margin-top: 15px;
        font-size: 12px;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .container {
        border: 2px solid #7b68ee;
    }
    
    input, select {
        border: 2px solid #7b68ee;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .container,
    input,
    select,
    button,
    form {
        transition: none;
        animation: none;
    }
    
    .container:hover {
        transform: none;
    }
    
    input:focus, select:focus {
        transform: none;
    }
    
    button:hover {
        transform: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    }
    
    .container {
        background: rgba(45, 55, 72, 0.95);
        color: #e2e8f0;
    }
    
    input[type=text],
    input[type=email],
    input[type=tel],
    input[type=password],
    select {
        background: rgba(74, 85, 104, 0.9);
        color: #e2e8f0;
        border-color: rgba(123, 104, 238, 0.5);
    }
    
    input::placeholder {
        color: #a0aec0;
    }
    
    label {
        color: #7b68ee;
    }
    
    .form-switch {
        color: #a0aec0;
    }
    
    .country-info {
        background: rgba(123, 104, 238, 0.2);
        color: #cbd5e0;
    }
}

/* Password visibility toggle */
.password-container {
    position: relative;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #718096;
    cursor: pointer;
    width: auto;
    padding: 0;
    margin: 0;
    box-shadow: none;
}

.toggle-password:hover {
    background: none;
    transform: translateY(-50%);
    color: #7b68ee;
}

/* Form validation styles */
input:invalid:not(:focus):not(:placeholder-shown) {
    border-color: #ff6b6b;
    background: rgba(255, 107, 107, 0.05);
}

input:valid:not(:focus):not(:placeholder-shown) {
    border-color: #48bb78;
    background: rgba(72, 187, 120, 0.05);
}

/* Custom select styling */
select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'><path fill='%237b68ee' d='M2 0L0 2h4zm0 5L0 3h4z'/></svg>");
    background-repeat: no-repeat;
    background-position: right 15px center;
    background-size: 12px;
    padding-right: 40px;
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    .container {
        box-shadow: none;
        border: 1px solid #ccc;
        background: white;
    }
    
    button {
        display: none;
    }
    
    .form-switch {
        display: none;
    }
}

/* Slide-based form styles */
.slide-container {
    position: relative;
    overflow: hidden;
    width: 100%;
}

.slide {
    display: none;
    animation: fadeIn 0.5s ease-out;
}

.slide.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.progress-indicator {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
    position: relative;
}

.progress-indicator::before {
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

.progress-step {
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

.progress-step.active {
    background: #7b68ee;
    color: white;
    border-color: #7b68ee;
}

.progress-step.completed {
    background: #48bb78;
    color: white;
    border-color: #48bb78;
}

.slide-navigation {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
}

.slide-navigation button {
    width: 48%;
}

.slide-navigation button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.slide-title {
    text-align: center;
    margin-bottom: 20px;
    font-size: 18px;
    color: #7b68ee;
    font-weight: 600;
}

.validation-error {
    color: #ff6b6b;
    font-size: 12px;
    margin-top: -15px;
    margin-bottom: 15px;
    display: none;
}

.field-required {
    border-left: 3px solid #ff6b6b;
    padding-left: 10px;
}

.field-optional {
    border-left: 3px solid #48bb78;
    padding-left: 10px;
}

.next-btn {
    position: relative;
}

.next-btn:after {
    content: '→';
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
}

.prev-btn:before {
    content: '←';
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
}

.input-highlight {
    border-color: #7b68ee !important;
    box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2) !important;
}

.optional-note {
    font-size: 12px;
    color: #718096;
    margin-top: -10px;
    margin-bottom: 15px;
    font-style: italic;
}

/* Verification code specific styles */
.verification-code-container {
    text-align: center;
}

.verification-code-input {
    font-size: 24px !important;
    text-align: center;
    letter-spacing: 10px;
    font-weight: bold;
}

.resend-code {
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

.verification-info {
    background: rgba(123, 104, 238, 0.1);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

.verification-info p {
    margin-bottom: 10px;
    font-size: 14px;
}

.verification-info strong {
    color: #7b68ee;
}
</style>
</head>
<body>
<div class="container">

  <!-- Signup Form -->
  <form id="signupForm" style="display: block;">
    <h2>Create Account</h2>
    <div class="message" id="signupMsg"></div>

    <div class="progress-indicator">
        <div class="progress-step active" id="step1">1</div>
        <div class="progress-step" id="step2">2</div>
        <div class="progress-step" id="step3">3</div>
        <div class="progress-step" id="step4">4</div>
        <div class="progress-step" id="step5">5</div>
        <div class="progress-step" id="step6">6</div>
        <div class="progress-step" id="step7">7</div>
        <div class="progress-step" id="step8">8</div>
        <div class="progress-step" id="step9">9</div>
    </div>

    <div class="slide-container">
        <!-- Username Slide -->
        <div class="slide active" id="slide1">
            <div class="slide-title">Choose Your Username</div>
            <div class="field-required">
                <label for="username">Username <span class="required">*</span></label>
                <input type="text" id="username" name="username" required placeholder="Enter your username" />
            </div>
            <div class="validation-error" id="usernameError">Username is required and must be at least 3 characters</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" disabled style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next1" style="position: relative;" disabled>Continue</button>
            </div>
        </div>

        <!-- Email Slide -->
        <div class="slide" id="slide2">
            <div class="slide-title">Add Your Email</div>
            <div class="field-required">
                <label for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email" required placeholder="Enter your email address" />
            </div>
            <div class="validation-error" id="emailError">Please enter a valid email address</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev2" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next2" style="position: relative;" disabled>Continue</button>
            </div>
        </div>

        <!-- Phone Slide -->
        <div class="slide" id="slide3">
            <div class="slide-title">Add Your Phone Number</div>
            <div class="field-optional">
                <label for="phone">Phone <span class="optional">(Optional)</span></label>
                <input type="tel" id="phone" name="phone" placeholder="Enter your phone number (optional)" />
            </div>
            <div class="optional-note">You can skip this if you prefer not to provide a phone number</div>
            <div class="validation-error" id="phoneError">Please enter a valid phone number</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev3" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next3" style="position: relative;">Continue</button>
            </div>
        </div>

        <!-- Password Slide -->
        <div class="slide" id="slide4">
            <div class="slide-title">Create a Password</div>
            <div class="field-required">
                <label for="password">Password <span class="required">*</span></label>
                <input type="password" id="password" name="password" required placeholder="Create a strong password" />
            </div>
            <div class="validation-error" id="passwordError">Password must be at least 6 characters long</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev4" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next4" style="position: relative;" disabled>Continue</button>
            </div>
        </div>

        <!-- Gender Slide -->
        <div class="slide" id="slide5">
            <div class="slide-title">Select Your Gender</div>
            <div class="field-required">
                <label for="gender">Gender <span class="required">*</span></label>
                <select id="gender" name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">I'd rather not say</option>
                </select>
            </div>
            <div class="validation-error" id="genderError">Please select your gender</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev5" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next5" style="position: relative;" disabled>Continue</button>
            </div>
        </div>

        <!-- Country Slide -->
        <div class="slide" id="slide6">
            <div class="slide-title">Select Your Country</div>
            <div class="field-required">
                <label for="country">Country <span class="required">*</span></label>
                <select id="country" name="country" required>
                    <option value="">Select Country</option>
                    <?php foreach ($all_countries as $code => $name): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $code === $detected_country ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="country-info" id="countryInfo">
                We've detected your location. You can change this if needed.
            </div>
            <div class="validation-error" id="countryError">Please select your country</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev6" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next6" style="position: relative;" disabled>Continue</button>
            </div>
        </div>

        <!-- Categories Slide -->
        <div class="slide" id="slide7">
            <div class="slide-title">Select Your Interests</div>
            <div class="field-optional">
                <label>Categories <span class="optional">(Optional)</span></label>
                <div class="category-row">
                    <div>
                        <select id="category1" name="category1">
                            <option value="">Select Category 1</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <select id="category2" name="category2">
                            <option value="">Select Category 2</option>
                           
                        </select>
                    </div>
                </div>
            </div>
            <div class="optional-note">You can skip this or choose one or two categories of interest</div>
            <div class="validation-error" id="categoryError">Please choose different categories if selecting both</div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev7" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next7" style="position: relative;">Continue</button>
            </div>
        </div>

        <!-- Email Verification Slide -->
        <div class="slide" id="slide8">
            <div class="slide-title">Verify Your Email</div>
            <div class="verification-info">
                <p>A 6-digit verification code has been sent to:</p>
                <p><strong id="verificationEmailDisplay"></strong></p>
                <p>Please check your email and enter the code below.</p>
                <p><em>Note: The code will expire in 5 minutes.</em></p>
            </div>
            <div class="field-required">
                <label for="verificationCode">Verification Code <span class="required">*</span></label>
                <input type="text" id="verificationCode" name="verification_code" required 
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       class="verification-code-input" />
            </div>
            <div class="validation-error" id="verificationCodeError">Please enter the 6-digit code</div>
            <div class="resend-code">
                Didn't receive the code? 
                <button type="button" id="resendCodeBtn">Resend Code</button>
                <span id="countdownTimer" class="countdown-timer" style="display: none;"></span>
            </div>
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev8" style="position: relative;">Previous</button>
                <button type="button" class="next-btn" id="next8" style="position: relative;" disabled>Continue</button>
            </div>
        </div>

        <!-- Review and Submit Slide -->
        <div class="slide" id="slide9">
            <div class="slide-title">Review Your Information</div>
            <div id="reviewInfo" style="margin-bottom: 20px; background: rgba(123, 104, 238, 0.1); padding: 15px; border-radius: 10px;">
                <!-- Review information will be populated here -->
            </div>
            <input type="hidden" id="invited_by" name="invited_by" value="<?= htmlspecialchars($invited_by) ?>" />
            <div class="slide-navigation">
                <button type="button" class="prev-btn" id="prev9" style="position: relative;">Previous</button>
                <button type="submit" id="submitForm">Create Account</button>
            </div>
        </div>
    </div>

    <p class="form-switch">Already have an account? <a id="toLogin">Login here</a></p>
  </form>

  <!-- Login Form -->
  <form id="loginForm" style="display: none;">
    <h2>Login</h2>
    <div class="message" id="loginMsg"></div>

    <label for="login">Email or Phone <span class="required">*</span></label>
    <input type="text" id="login" name="login" required placeholder="Enter your email or phone" />

    <label for="loginPassword">Password <span class="required">*</span></label>
    <input type="password" id="loginPassword" name="password" required placeholder="Enter your password" />

    <button type="submit">Login</button>

    <p class="form-switch">Don't have an account? <a id="toSignup">Sign up here</a></p>
    <a href="forgot_password.php">Forgot Password</a>
  </form>
</div>

<script>
  document.getElementById('toLogin').addEventListener('click', () => {
    document.getElementById('signupForm').style.display = 'none';
    document.getElementById('loginForm').style.display = 'block';
    clearMessages();
  });

  document.getElementById('toSignup').addEventListener('click', () => {
    document.getElementById('loginForm').style.display = 'none';
    document.getElementById('signupForm').style.display = 'block';
    clearMessages();
  });

  function clearMessages() {
    document.getElementById('signupMsg').textContent = '';
    document.getElementById('loginMsg').textContent = '';
  }

  // Slide-based form functionality
  let currentSlide = 1;
  const totalSlides = 9;
  let resendTimer = null;

  // Initialize the form
  function initSlideForm() {
    updateProgressIndicator();
    updateReviewInfo();
    
    // Add input event listeners for real-time validation
    document.getElementById('username').addEventListener('input', function() {
        validateUsername();
        updateContinueButton(1);
    });
    
    document.getElementById('email').addEventListener('input', function() {
        validateEmail();
        updateContinueButton(2);
    });
    
    document.getElementById('phone').addEventListener('input', function() {
        validatePhone();
        updateContinueButton(3);
    });
    
    document.getElementById('password').addEventListener('input', function() {
        validatePassword();
        updateContinueButton(4);
    });
    
    document.getElementById('gender').addEventListener('change', function() {
        validateGender();
        updateContinueButton(5);
    });
    
    document.getElementById('country').addEventListener('change', function() {
        validateCountry();
        updateContinueButton(6);
    });
    
    document.getElementById('category1').addEventListener('change', function() {
        validateCategories();
        updateContinueButton(7);
    });
    
    document.getElementById('category2').addEventListener('change', function() {
        validateCategories();
        updateContinueButton(7);
    });
    
    document.getElementById('verificationCode').addEventListener('input', function() {
        validateVerificationCode();
        updateContinueButton(8);
    });
    
    // Resend code button
    document.getElementById('resendCodeBtn').addEventListener('click', function() {
        sendVerificationCode();
    });
    
    // Initial button state
    updateContinueButton(1);
  }

  // Update progress indicator
  function updateProgressIndicator() {
    for (let i = 1; i <= totalSlides; i++) {
      const step = document.getElementById(`step${i}`);
      if (i < currentSlide) {
        step.className = 'progress-step completed';
      } else if (i === currentSlide) {
        step.className = 'progress-step active';
      } else {
        step.className = 'progress-step';
      }
    }
  }

  // Show a specific slide
  function showSlide(slideNumber) {
    // Hide all slides
    for (let i = 1; i <= totalSlides; i++) {
      document.getElementById(`slide${i}`).classList.remove('active');
    }
    
    // Show the requested slide
    document.getElementById(`slide${slideNumber}`).classList.add('active');
    currentSlide = slideNumber;
    updateProgressIndicator();
    
    // Special handling for verification slide
    if (currentSlide === 8) {
      const email = document.getElementById('email').value;
      document.getElementById('verificationEmailDisplay').textContent = email;
      sendVerificationCode();
    }
    
    // Update review info if we're on the last slide
    if (currentSlide === totalSlides) {
      updateReviewInfo();
    }
    
    // Update continue button state for the current slide
    updateContinueButton(slideNumber);
  }

  // Update continue button state based on validation
  function updateContinueButton(slideNumber) {
    const nextButton = document.getElementById(`next${slideNumber}`);
    if (!nextButton) return;
    
    let isValid = false;
    
    switch(slideNumber) {
      case 1:
        isValid = validateUsername();
        break;
      case 2:
        isValid = validateEmail();
        break;
      case 3:
        // Phone is optional, so always valid
        isValid = true;
        break;
      case 4:
        isValid = validatePassword();
        break;
      case 5:
        isValid = validateGender();
        break;
      case 6:
        isValid = validateCountry();
        break;
      case 7:
        // Categories are optional, so always valid
        isValid = true;
        break;
      case 8:
        isValid = validateVerificationCode();
        break;
    }
    
    nextButton.disabled = !isValid;
  }

  // Update review information
  function updateReviewInfo() {
    const reviewInfo = document.getElementById('reviewInfo');
    const username = document.getElementById('username').value || 'Not provided';
    const email = document.getElementById('email').value || 'Not provided';
    const phone = document.getElementById('phone').value || 'Not provided';
    const gender = document.getElementById('gender').options[document.getElementById('gender').selectedIndex].text || 'Not provided';
    const country = document.getElementById('country').options[document.getElementById('country').selectedIndex].text || 'Not provided';
    const category1 = document.getElementById('category1').value || 'Not provided';
    const category2 = document.getElementById('category2').value || 'Not provided';
    
    reviewInfo.innerHTML = `
      <p><strong>Username:</strong> ${username}</p>
      <p><strong>Email:</strong> ${email} ✅</p>
      <p><strong>Phone:</strong> ${phone}</p>
      <p><strong>Gender:</strong> ${gender}</p>
      <p><strong>Country:</strong> ${country}</p>
      <p><strong>Category 1:</strong> ${category1}</p>
      <p><strong>Category 2:</strong> ${category2}</p>
    `;
  }

  // Send verification code
  async function sendVerificationCode() {
    const email = document.getElementById('email').value;
    const resendBtn = document.getElementById('resendCodeBtn');
    const countdownTimer = document.getElementById('countdownTimer');
    
    if (!email) {
      showVerificationError('Email is required');
      return;
    }
    
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
        showVerificationSuccess(result.message || 'Verification code sent successfully');
        
        // Disable resend button for 60 seconds
        resendBtn.disabled = true;
        countdownTimer.style.display = 'inline';
        
        let timeLeft = 60;
        updateCountdownTimer(timeLeft);
        
        resendTimer = setInterval(() => {
          timeLeft--;
          updateCountdownTimer(timeLeft);
          
          if (timeLeft <= 0) {
            clearInterval(resendTimer);
            resendBtn.disabled = false;
            countdownTimer.style.display = 'none';
          }
        }, 1000);
      } else {
        showVerificationError(result.error || 'Failed to send verification code');
      }
    } catch (error) {
      console.error('Error sending verification code:', error);
      showVerificationError('Failed to send verification code');
    }
  }

  function updateCountdownTimer(seconds) {
    const countdownTimer = document.getElementById('countdownTimer');
    countdownTimer.textContent = ` (${seconds}s)`;
  }

  function showVerificationError(message) {
    const errorElement = document.getElementById('verificationCodeError');
    errorElement.textContent = message;
    errorElement.style.display = 'block';
    errorElement.style.color = '#ff6b6b';
  }

  function showVerificationSuccess(message) {
    const errorElement = document.getElementById('verificationCodeError');
    errorElement.textContent = message;
    errorElement.style.display = 'block';
    errorElement.style.color = '#48bb78';
    
    // Clear success message after 3 seconds
    setTimeout(() => {
      errorElement.style.display = 'none';
    }, 3000);
  }

  // Validation functions
  function validateUsername() {
    const username = document.getElementById('username').value.trim();
    const error = document.getElementById('usernameError');
    const input = document.getElementById('username');
    
    if (!username) {
      error.textContent = 'Username is required';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    if (username.length < 3) {
      error.textContent = 'Username must be at least 3 characters long';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  function validateEmail() {
    const email = document.getElementById('email').value.trim();
    const error = document.getElementById('emailError');
    const input = document.getElementById('email');
    
    if (!email) {
      error.textContent = 'Email is required';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      error.textContent = 'Please enter a valid email address';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  function validatePhone() {
    const phone = document.getElementById('phone').value.trim();
    const error = document.getElementById('phoneError');
    const input = document.getElementById('phone');
    
    // Phone is optional, so if empty, it's valid
    if (!phone) {
      error.style.display = 'none';
      input.classList.remove('input-highlight');
      return true;
    }
    
    // Basic phone validation - at least 10 digits if provided
    const phoneDigits = phone.replace(/\D/g, '');
    if (phoneDigits.length < 10) {
      error.textContent = 'Please enter a valid phone number (at least 10 digits)';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  function validatePassword() {
    const password = document.getElementById('password').value;
    const error = document.getElementById('passwordError');
    const input = document.getElementById('password');
    
    if (!password) {
      error.textContent = 'Password is required';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    if (password.length < 6) {
      error.textContent = 'Password must be at least 6 characters long';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  function validateGender() {
    const gender = document.getElementById('gender').value;
    const error = document.getElementById('genderError');
    const input = document.getElementById('gender');
    
    if (!gender) {
      error.textContent = 'Please select your gender';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  function validateCountry() {
    const country = document.getElementById('country').value;
    const error = document.getElementById('countryError');
    const input = document.getElementById('country');
    
    if (!country) {
      error.textContent = 'Please select your country';
      error.style.display = 'block';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  function validateCategories() {
    const category1 = document.getElementById('category1').value;
    const category2 = document.getElementById('category2').value;
    const error = document.getElementById('categoryError');
    
    // Categories are optional, so if both are empty, it's valid
    if (!category1 && !category2) {
      error.style.display = 'none';
      document.getElementById('category1').classList.remove('input-highlight');
      document.getElementById('category2').classList.remove('input-highlight');
      return true;
    }
    
    // If both are selected, they must be different
    if (category1 && category2 && category1 === category2) {
      error.textContent = 'Please choose different categories';
      error.style.display = 'block';
      document.getElementById('category1').classList.remove('input-highlight');
      document.getElementById('category2').classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    if (category1) document.getElementById('category1').classList.add('input-highlight');
    if (category2) document.getElementById('category2').classList.add('input-highlight');
    return true;
  }

  function validateVerificationCode() {
    const code = document.getElementById('verificationCode').value.trim();
    const error = document.getElementById('verificationCodeError');
    const input = document.getElementById('verificationCode');
    
    if (!code) {
      error.textContent = 'Verification code is required';
      error.style.display = 'block';
      error.style.color = '#ff6b6b';
      input.classList.remove('input-highlight');
      return false;
    }
    
    if (!/^\d{6}$/.test(code)) {
      error.textContent = 'Please enter a valid 6-digit code';
      error.style.display = 'block';
      error.style.color = '#ff6b6b';
      input.classList.remove('input-highlight');
      return false;
    }
    
    error.style.display = 'none';
    input.classList.add('input-highlight');
    return true;
  }

  // Navigation event listeners
  document.getElementById('next1').addEventListener('click', () => {
    if (validateUsername()) {
      showSlide(2);
    }
  });

  document.getElementById('next2').addEventListener('click', () => {
    if (validateEmail()) {
      showSlide(3);
    }
  });

  document.getElementById('next3').addEventListener('click', () => {
    // Phone is optional, so always proceed
    showSlide(4);
  });

  document.getElementById('next4').addEventListener('click', () => {
    if (validatePassword()) {
      showSlide(5);
    }
  });

  document.getElementById('next5').addEventListener('click', () => {
    if (validateGender()) {
      showSlide(6);
    }
  });

  document.getElementById('next6').addEventListener('click', () => {
    if (validateCountry()) {
      showSlide(7);
    }
  });

  document.getElementById('next7').addEventListener('click', () => {
    // Categories are optional, but validate in case both are selected
    if (validateCategories()) {
      showSlide(8);
    }
  });

  document.getElementById('next8').addEventListener('click', async () => {
    if (validateVerificationCode()) {
      const code = document.getElementById('verificationCode').value.trim();
      const email = document.getElementById('email').value;
      
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
          showVerificationSuccess('Email verified successfully!');
          setTimeout(() => {
            showSlide(9);
          }, 1000);
        } else {
          showVerificationError(result.error || 'Incorrect verification code');
        }
      } catch (error) {
        console.error('Error verifying code:', error);
        showVerificationError('Failed to verify code');
      }
    }
  });

  // Previous buttons
  document.getElementById('prev2').addEventListener('click', () => showSlide(1));
  document.getElementById('prev3').addEventListener('click', () => showSlide(2));
  document.getElementById('prev4').addEventListener('click', () => showSlide(3));
  document.getElementById('prev5').addEventListener('click', () => showSlide(4));
  document.getElementById('prev6').addEventListener('click', () => showSlide(5));
  document.getElementById('prev7').addEventListener('click', () => showSlide(6));
  document.getElementById('prev8').addEventListener('click', () => showSlide(7));
  document.getElementById('prev9').addEventListener('click', () => showSlide(8));

  // Enhanced country detection using browser's geolocation API as fallback
  function detectCountryWithGeolocation() {
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        function(position) {
          document.getElementById('countryInfo').textContent = 'Location detected via GPS. You can change the country if needed.';
        },
        function(error) {
          console.log('Geolocation failed, using IP-based detection');
        }
      );
    }
  }

  // Try enhanced detection when page loads
  document.addEventListener('DOMContentLoaded', function() {
    detectCountryWithGeolocation();
    initSlideForm();
  });

  document.getElementById('signupForm').addEventListener('submit', async e => {
    e.preventDefault();
    clearMessages();

    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'signup');

    // Validate all required fields before submission
    if (!validateUsername() || !validateEmail() || !validatePassword() || 
        !validateGender() || !validateCountry() || !validateCategories()) {
      // If validation fails, show the first slide with an error
      showSlide(1);
      signupMsg('Please fix the errors in the form before submitting', true);
      return;
    }

    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    const result = await response.json();

    if (result.success) {
      signupMsg('Signup successful! Redirecting...', false);
      setTimeout(() => window.location.href = 'video.php', 1500);
    } else {
      signupMsg(result.error || 'Signup failed', true);
    }
  });

  function signupMsg(msg, isError) {
    const el = document.getElementById('signupMsg');
    el.textContent = msg;
    el.className = 'message ' + (isError ? 'error' : 'success');
  }

  document.getElementById('loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    clearMessages();

    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'login');

    if (!formData.get('login')) {
      loginMsg('Email or Phone is required', true);
      return;
    }
    if (!formData.get('password')) {
      loginMsg('Password is required', true);
      return;
    }

    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    const result = await response.json();

    if (result.success) {
      loginMsg('Login successful! Redirecting...', false);
      setTimeout(() => window.location.href = 'video.php', 1500);
    } else {
      loginMsg(result.error || 'Login failed', true);
    }
  });

  function loginMsg(msg, isError) {
    const el = document.getElementById('loginMsg');
    el.textContent = msg;
    el.className = 'message ' + (isError ? 'error' : 'success');
  }
</script>
</body>
</html>