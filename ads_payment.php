<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['ad_data'])) {
    header('Location: run_ads.php');
    exit;
}

require_once "config.php";


// Paystack Configuration - REPLACE WITH YOUR ACTUAL KEYS
define('PAYSTACK_SECRET_KEY', '');
define('PAYSTACK_PUBLIC_KEY', '');
define('PAYSTACK_BASE_URL', '');

// ExchangeRate-API Configuration
define('EXCHANGERATE_API_KEY', '');
define('EXCHANGERATE_BASE_URL', '');

// Supported Currencies and Countries
$supported_currencies = [
    'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'min_amount' => 1],
    'NGN' => ['name' => 'Nigerian Naira', 'symbol' => '₦', 'min_amount' => 1],
    'EUR' => ['name' => 'Euro', 'symbol' => '€', 'min_amount' => 1],
    'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'min_amount' => 1],
    'GHS' => ['name' => 'Ghanaian Cedi', 'symbol' => 'GH₵', 'min_amount' => 1],
    'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R', 'min_amount' => 1],
    'KES' => ['name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'min_amount' => 1],
    'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$', 'min_amount' => 1],
    'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$', 'min_amount' => 1],
    'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'min_amount' => 1],
    'CNY' => ['name' => 'Chinese Yuan', 'symbol' => '¥', 'min_amount' => 1],
    'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'min_amount' => 1],
    'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'R$', 'min_amount' => 1],
    'MXN' => ['name' => 'Mexican Peso', 'symbol' => '$', 'min_amount' => 1],
];

$supported_countries = [
    'US' => 'United States',
    'NG' => 'Nigeria',
    'GB' => 'United Kingdom',
    'DE' => 'Germany',
    'FR' => 'France',
    'CA' => 'Canada',
    'AU' => 'Australia',
    'JP' => 'Japan',
    'CN' => 'China',
    'IN' => 'India',
    'BR' => 'Brazil',
    'MX' => 'Mexico',
    'GH' => 'Ghana',
    'KE' => 'Kenya',
    'ZA' => 'South Africa',
];

// Function to check internet connectivity
function checkInternetConnectivity() {
    $test_urls = [
        'https://api.paystack.co',
        'https://www.google.com',
        'https://api.exchangerate-api.com'
    ];
    
    foreach ($test_urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode > 0) {
            return true; // At least one URL is reachable
        }
    }
    
    return false;
}

// Check internet connectivity first
if (!checkInternetConnectivity()) {
    $internet_error = "Server cannot connect to the internet. Please check your server's network connection.";
}

// Function to get real-time exchange rates
function getExchangeRates($base_currency = 'USD') {
    $cache_file = 'exchange_rates_cache.json';
    $cache_duration = 300;
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
        $cached_data = json_decode(file_get_contents($cache_file), true);
        if (isset($cached_data['rates']) && isset($cached_data['base']) && $cached_data['base'] === $base_currency) {
            return $cached_data['rates'];
        }
    }
    
    // If no internet, use fallback rates
    if (isset($GLOBALS['internet_error'])) {
        return getFallbackRates($base_currency);
    }
    
    try {
        $url = EXCHANGERATE_API_KEY ? 
               EXCHANGERATE_BASE_URL . $base_currency . '?api_key=' . EXCHANGERATE_API_KEY :
               EXCHANGERATE_BASE_URL . $base_currency;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if (!$error && $httpcode == 200 && $response) {
            $data = json_decode($response, true);
            
            if (isset($data['rates'])) {
                file_put_contents($cache_file, json_encode([
                    'rates' => $data['rates'],
                    'base' => $base_currency,
                    'timestamp' => time()
                ]));
                
                return $data['rates'];
            }
        }
        
        return getFallbackRates($base_currency);
        
    } catch (Exception $e) {
        error_log("Exchange rate API error: " . $e->getMessage());
        return getFallbackRates($base_currency);
    }
}

function getFallbackRates($base_currency = 'USD') {
    $fallback_rates = [
        'USD' => 1,
        'NGN' => 1500,
        'EUR' => 0.85,
        'GBP' => 0.73,
        'GHS' => 12.5,
        'ZAR' => 18.7,
        'KES' => 157.8,
        'CAD' => 1.35,
        'AUD' => 1.52,
        'JPY' => 147.8,
        'CNY' => 7.18,
        'INR' => 83.2,
        'BRL' => 4.95,
        'MXN' => 17.3,
    ];
    
    if ($base_currency !== 'USD' && isset($fallback_rates[$base_currency])) {
        $base_rate = $fallback_rates[$base_currency];
        foreach ($fallback_rates as $currency => $rate) {
            $fallback_rates[$currency] = $rate / $base_rate;
        }
    }
    
    return $fallback_rates;
}

// Get current exchange rates
$exchange_rates = getExchangeRates('USD');

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Paystack API Helper Functions with improved error handling
function paystackRequest($endpoint, $method = 'GET', $data = null) {
    // Check if we already know there's no internet
    if (isset($GLOBALS['internet_error'])) {
        throw new Exception("No internet connection available. Please check server connectivity.");
    }
    
    $curl = curl_init();
    
    $url = PAYSTACK_BASE_URL . $endpoint;
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0 (compatible; PHP Paystack Client)"
        ]
    ];
    
    curl_setopt_array($curl, $options);
    
    if ($data && in_array($method, ['POST', 'PUT'])) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $errno = curl_errno($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($errno) {
        // Detailed error handling
        $error_messages = [
            CURLE_COULDNT_RESOLVE_HOST => "Could not resolve host. Please check your DNS settings or internet connection.",
            CURLE_COULDNT_CONNECT => "Could not connect to Paystack servers. Please check your firewall settings.",
            CURLE_OPERATION_TIMEOUTED => "Connection to Paystack timed out.",
            CURLE_SSL_CONNECT_ERROR => "SSL connection error. Please check your SSL/TLS configuration.",
        ];
        
        $error_msg = $error_messages[$errno] ?? "cURL Error #$errno: $error";
        throw new Exception($error_msg);
    }
    
    if (!$response) {
        throw new Exception("Empty response from Paystack API");
    }
    
    $result = json_decode($response, true);
    
    if ($httpcode !== 200) {
        $error_msg = isset($result['message']) ? $result['message'] : "HTTP Error $httpcode";
        throw new Exception("Paystack API Error: $error_msg");
    }
    
    return $result;
}

// Convert any currency to Naira
function convertToNaira($amount, $from_currency, $exchange_rates) {
    if ($from_currency === 'NGN') {
        return $amount;
    }
    
    if (isset($exchange_rates[$from_currency]) && isset($exchange_rates['NGN'])) {
        $amount_in_usd = $amount / $exchange_rates[$from_currency];
        return $amount_in_usd * $exchange_rates['NGN'];
    }
    
    return $amount * 1000;
}

// Get display amount with conversion info
function getDisplayAmount($amount, $currency, $exchange_rates) {
    global $supported_currencies;
    
    $symbol = $supported_currencies[$currency]['symbol'] ?? '$';
    
    if ($currency === 'NGN') {
        return '₦' . number_format($amount, 2);
    }
    
    $converted = convertToNaira($amount, $currency, $exchange_rates);
    return $symbol . number_format($amount, 2) . ' ' . $currency . ' ≈ ₦' . number_format($converted, 2);
}

$ad_data = $_SESSION['ad_data'];
$total_price = $ad_data['total_price'];

// Get user balance (always in NGN)
$stmt = $pdo->prepare("SELECT balance FROM user_balance WHERE user_id = ? AND currency = 'NGN'");
$stmt->execute([$_SESSION['user_id']]);
$userBalance = $stmt->fetchColumn() ?? 0;

// Handle payment methods
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';
    
    if ($payment_method === 'dashboard' && $userBalance >= $total_price) {
        // Deduct from dashboard balance
        $stmt = $pdo->prepare("UPDATE user_balance SET balance = balance - ? WHERE user_id = ? AND currency = 'NGN'");
        $stmt->execute([$total_price, $_SESSION['user_id']]);
        
        // Create payment transaction
        $stmt = $pdo->prepare("INSERT INTO payment_transactions (user_id, amount, type, status, currency) VALUES (?, ?, 'ad_payment', 'completed', 'NGN')");
        $stmt->execute([$_SESSION['user_id'], $total_price]);
        
        // Create the ad
        createAd($pdo, $ad_data, $_SESSION['user_id']);
        
        unset($_SESSION['ad_data']);
        header('Location: ads_countdown.php');
        exit;
        
    } elseif ($payment_method === 'dashboard' && $userBalance < $total_price) {
        $error = "Insufficient balance in your dashboard";
    } elseif ($payment_method === 'paystack') {
        // Process Paystack payment
        $currency = $_POST['currency'] ?? 'USD';
        $country = $_POST['country'] ?? 'US';
        $email = $_SESSION['user_email'] ?? 'user@example.com';
        
        // Convert to Naira for Paystack
        $amount_in_naira = convertToNaira($total_price, $currency, $exchange_rates);
        $amount_in_kobo = intval(round($amount_in_naira * 100));
        
        // Validate minimum amount
        $min_amount = $supported_currencies[$currency]['min_amount'] ?? 1;
        if ($total_price < $min_amount) {
            $error = "Minimum amount is " . $supported_currencies[$currency]['symbol'] . $min_amount;
        } else {
            try {
                // Test Paystack connection first
                if (isset($internet_error)) {
                    throw new Exception("No internet connection. Please check your server's network settings.");
                }
                
                // Generate unique reference
                $reference = 'AD_' . uniqid() . '_' . $_SESSION['user_id'];
                
                // Initialize Paystack transaction
                $payload = [
                    'email' => $email,
                    'amount' => $amount_in_kobo,
                    'reference' => $reference,
                    'callback_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                                     "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . 
                                     '/ads_payment.php?verify=' . $reference,
                    'currency' => 'NGN',
                    'metadata' => [
                        'user_id' => $_SESSION['user_id'],
                        'original_currency' => $currency,
                        'original_amount' => $total_price,
                        'converted_amount' => $amount_in_naira,
                        'country' => $country,
                        'ad_data' => json_encode($ad_data),
                        'transaction_type' => 'ad_payment',
                        'custom_fields' => [
                            [
                                'display_name' => "User ID",
                                'variable_name' => "user_id",
                                'value' => $_SESSION['user_id']
                            ],
                            [
                                'display_name' => "Ad Type",
                                'variable_name' => "ad_type",
                                'value' => $ad_data['ad_type']
                            ],
                            [
                                'display_name' => "Ad Duration",
                                'variable_name' => "days", 
                                'value' => $ad_data['days']
                            ]
                        ]
                    ]
                ];
                
                $response = paystackRequest('/transaction/initialize', 'POST', $payload);
                
                if ($response['status'] && isset($response['data']['authorization_url'])) {
                    // Save pending ad transaction to database
                    $stmt = $pdo->prepare("
                        INSERT INTO ad_payments (user_id, reference, amount, currency, original_currency, original_amount, country, status, ad_data) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'], 
                        $reference, 
                        $amount_in_naira,
                        'NGN',
                        $currency,
                        $total_price,
                        $country,
                        json_encode($ad_data)
                    ]);
                    
                    // Redirect to Paystack payment page
                    header('Location: ' . $response['data']['authorization_url']);
                    exit;
                } else {
                    $error = "Failed to initialize payment: " . ($response['message'] ?? 'Unknown error');
                }
                
            } catch (Exception $e) {
                $error = "Payment initialization failed: " . $e->getMessage();
                
                // Log the error for debugging
                error_log("Paystack Payment Error: " . $e->getMessage() . " - User ID: " . $_SESSION['user_id']);
                
                // If it's a connectivity error, show helpful message
                if (strpos($e->getMessage(), 'Could not resolve host') !== false || 
                    strpos($e->getMessage(), 'No internet connection') !== false) {
                    $error .= "<br><br><strong>Server Connectivity Issue Detected:</strong><br>
                               Please check:<br>
                               1. Server internet connection<br>
                               2. DNS settings<br>
                               3. Firewall rules (allow outbound connections to api.paystack.co)<br>
                               4. Contact your hosting provider";
                }
            }
        }
    }
}

// Verify Payment (callback from Paystack)
if (isset($_GET['verify'])) {
    $reference = $_GET['verify'];
    
    try {
        // Verify transaction with Paystack
        $response = paystackRequest('/transaction/verify/' . $reference);
        
        if ($response['status'] && $response['data']['status'] === 'success') {
            $data = $response['data'];
            $amount = $data['amount'] / 100;
            
            // Get original currency and amount from metadata
            $original_currency = $data['metadata']['original_currency'] ?? 'USD';
            $original_amount = $data['metadata']['original_amount'] ?? $amount;
            $ad_data_json = $data['metadata']['ad_data'] ?? '{}';
            $ad_data = json_decode($ad_data_json, true);
            
            // Update ad payment status
            $stmt = $pdo->prepare("
                UPDATE ad_payments 
                SET status = 'success', payment_method = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE reference = ?
            ");
            $stmt->execute([$data['channel'] ?? 'card', $reference]);
            
            // Create the ad if it doesn't exist
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ads WHERE payment_reference = ?");
            $stmt->execute([$reference]);
            $ad_exists = $stmt->fetchColumn();
            
            if (!$ad_exists && $ad_data) {
                createAd($pdo, $ad_data, $_SESSION['user_id'], $reference);
            }
            
            // Create success message with conversion info
            $currency_symbol = $supported_currencies[$original_currency]['symbol'] ?? '$';
            $success_msg = "Ad payment of " . $currency_symbol . 
                         number_format($original_amount, 2) . " " . $original_currency . 
                         " (≈ ₦" . number_format($amount, 2) . ") completed successfully!";
            
            $_SESSION['payment_success'] = $success_msg;
            header('Location: ads_countdown.php');
            exit;
        } else {
            $error = "Payment verification failed: " . ($response['message'] ?? 'Transaction not successful');
        }
        
    } catch (Exception $e) {
        $error = "Verification failed: " . $e->getMessage();
    }
}

function createAd($pdo, $ad_data, $user_id, $payment_reference = null) {
    $stmt = $pdo->prepare("
        INSERT INTO ads (user_id, header, description, media_path, ad_type, cta_button, url, locations, gender_target, days, price_per_day, total_price, status, starts_at, ends_at, payment_reference, currency) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + INTERVAL '1 day' * ?, ?, 'NGN')
    ");
    
    $locations_json = json_encode($ad_data['locations']);
    
    $stmt->execute([
        $user_id,
        $ad_data['header'],
        $ad_data['description'],
        $ad_data['media_path'],
        $ad_data['ad_type'],
        $ad_data['cta_button'],
        $ad_data['url'],
        $locations_json,
        $ad_data['gender'],
        $ad_data['days'],
        $ad_data['price_per_day'],
        $ad_data['total_price'],
        $ad_data['days'],
        $payment_reference
    ]);
    
    return $pdo->lastInsertId();
}

// Get recent ad payments
$stmt = $pdo->prepare("
    SELECT * FROM ad_payments 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$recent_payments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Ad Payment</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        h1 {
            text-align: center;
            color: #2d3748;
            margin-bottom: 30px;
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .price-summary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .price-summary h3 {
            margin: 0 0 15px 0;
            font-size: 20px;
            font-weight: 700;
            opacity: 0.9;
        }

        .price-amount {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .price-summary p {
            margin: 5px 0;
            opacity: 0.9;
            font-weight: 600;
        }

        .balance-section {
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
            text-align: center;
        }

        .balance-amount {
            font-size: 24px;
            font-weight: 800;
            margin: 10px 0;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .payment-methods {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .payment-method {
            flex: 1;
            padding: 25px;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
        }
        
        .payment-method.selected {
            border-color: #7b68ee;
            background: rgba(123, 104, 238, 0.1);
            box-shadow: 0 4px 15px rgba(123, 104, 238, 0.2);
        }
        
        .payment-method h3 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 18px;
        }
        
        .payment-method p {
            margin: 0;
            color: #718096;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .payment-form {
            margin-top: 25px;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        select, input[type="number"], input[type="text"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid rgba(123, 104, 238, 0.3);
            border-radius: 12px;
            font-size: 16px;
            box-sizing: border-box;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            font-family: inherit;
        }

        select:focus, input[type="number"]:focus, input[type="text"]:focus {
            outline: none;
            border-color: #7b68ee;
            box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.1);
            background: white;
        }

        .conversion-info {
            background: rgba(123, 104, 238, 0.1);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
            border: 1px solid rgba(123, 104, 238, 0.2);
            font-weight: 600;
        }

        .rate-info {
            font-size: 12px;
            opacity: 0.7;
            margin-top: 5px;
            font-weight: normal;
        }

        .btn {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            padding: 16px 30px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 700;
            width: 100%;
            margin-top: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #38a169, #2f855a);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.6);
        }

        .btn:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #718096, #4a5568);
            box-shadow: 0 4px 15px rgba(113, 128, 150, 0.4);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #4a5568, #2d3748);
            box-shadow: 0 6px 20px rgba(113, 128, 150, 0.6);
        }

        .error {
            background: rgba(245, 101, 101, 0.1);
            color: #e53e3e;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid rgba(245, 101, 101, 0.3);
            font-weight: 600;
            text-align: center;
            backdrop-filter: blur(10px);
            word-wrap: break-word;
        }

        .error.server-error {
            background: rgba(245, 101, 101, 0.2);
            border: 2px solid #e53e3e;
            color: #c53030;
        }

        .success {
            background: rgba(72, 187, 120, 0.1);
            color: #38a169;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid rgba(72, 187, 120, 0.3);
            font-weight: 600;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .info {
            background: rgba(66, 153, 225, 0.1);
            color: #3182ce;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid rgba(66, 153, 225, 0.3);
            font-weight: 600;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .ad-details {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .ad-details h3 {
            margin-top: 0;
            color: #2d3748;
            font-size: 18px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .detail-label {
            color: #718096;
            font-weight: 600;
        }

        .detail-value {
            color: #2d3748;
            font-weight: 700;
            text-align: right;
        }

        .nav-links {
            text-align: center;
            margin-bottom: 30px;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 10px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav-link:hover {
            background: linear-gradient(135deg, #6a5acd, #5a4abc);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
            text-decoration: none;
            color: white;
        }

        .hidden {
            display: none;
        }

        .connectivity-test {
            background: rgba(246, 173, 85, 0.1);
            border: 1px solid rgba(246, 173, 85, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }

        .connectivity-test h4 {
            color: #ed8936;
            margin-top: 0;
        }

        .test-btn {
            background: #ed8936;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            margin: 5px;
            font-weight: 600;
        }

        .test-btn:hover {
            background: #dd6b20;
        }

        @media (max-width: 768px) {
            .container {
                padding: 30px 25px;
            }
            
            .payment-methods {
                flex-direction: column;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .price-amount {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌍 Complete Ad Payment</h1>
        
        <div class="nav-links">
            <a href="run_ads.php" class="nav-link">← Back to Create Ad</a>
        </div>
        
        <?php if (isset($internet_error)): ?>
            <div class="error server-error">
                <strong>⚠️ SERVER CONNECTIVITY ISSUE:</strong><br>
                <?= htmlspecialchars($internet_error) ?><br><br>
                <strong>Possible Solutions:</strong><br>
                1. Check your server's internet connection<br>
                2. Verify DNS settings can resolve api.paystack.co<br>
                3. Check firewall/security group rules<br>
                4. Contact your hosting provider<br><br>
                <small>You can still use dashboard balance if you have sufficient funds.</small>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= nl2br(htmlspecialchars($error)) ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Ad Details Summary -->
        <div class="price-summary">
            <h3>Your Ad Summary</h3>
            <div class="price-amount" id="priceAmount">$<?= number_format($total_price, 2) ?></div>
            <p><?= $ad_data['days'] ?> days • <?= $ad_data['ad_type'] === 'video' ? 'Video Ad' : 'Image Ad' ?></p>
            <p>Target: <?= $ad_data['gender'] ?> • <?= count($ad_data['locations']) ?> locations</p>
        </div>

        <!-- Balance Overview -->
        <div class="balance-section">
            <h3>Your Dashboard Balance</h3>
            <div class="balance-amount">₦ <?= number_format($userBalance, 2) ?></div>
        </div>

        <!-- Information Banner -->
        <div class="info">
            💡 <strong>International Payments:</strong> All currencies are automatically converted to Naira using current exchange rates.
        </div>

        <!-- Connectivity Test (for debugging) -->
        <div class="connectivity-test">
            <h4>🛠️ Connection Status</h4>
            <p>Paystack API: 
                <?php 
                try {
                    $test_ch = curl_init('https://api.paystack.co');
                    curl_setopt_array($test_ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_NOBODY => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    curl_exec($test_ch);
                    $httpcode = curl_getinfo($test_ch, CURLINFO_HTTP_CODE);
                    curl_close($test_ch);
                    
                    if ($httpcode > 0) {
                        echo '<span style="color: #38a169; font-weight: bold;">✓ Connected</span>';
                    } else {
                        echo '<span style="color: #e53e3e; font-weight: bold;">✗ Not Connected</span>';
                    }
                } catch (Exception $e) {
                    echo '<span style="color: #e53e3e; font-weight: bold;">✗ Error</span>';
                }
                ?>
            </p>
        </div>

        <!-- Payment Method Selection -->
        <div class="payment-methods">
            <div class="payment-method <?= !isset($internet_error) ? 'selected' : '' ?>" data-method="dashboard">
                <h3>💰 Dashboard Balance</h3>
                <p>Pay from your available balance</p>
                <?php if ($userBalance < $total_price): ?>
                    <p style="color: #e53e3e; font-weight: 600; margin-top: 8px;">
                        Insufficient balance
                    </p>
                <?php endif; ?>
            </div>
            <div class="payment-method <?= isset($internet_error) ? 'disabled' : '' ?>" data-method="paystack" <?= isset($internet_error) ? 'style="opacity: 0.6; cursor: not-allowed;"' : '' ?>>
                <h3>💳 Paystack Payment</h3>
                <p>Pay with card, bank transfer, or mobile money</p>
                <p style="color: #38a169; font-weight: 600; margin-top: 8px;">
                    International support available
                </p>
                <?php if (isset($internet_error)): ?>
                    <p style="color: #e53e3e; font-weight: 600; margin-top: 8px; font-size: 12px;">
                        Currently unavailable due to server connectivity
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dashboard Payment Form -->
        <form method="POST" id="dashboardForm" class="payment-form" <?= isset($internet_error) ? '' : 'style="display: block;"' ?>>
            <input type="hidden" name="payment_method" value="dashboard">
            
            <?php if ($userBalance >= $total_price): ?>
                <div class="info">
                    ✅ Sufficient balance available. Your ad will start immediately after payment.
                </div>
                
                <button type="submit" class="btn">
                    Pay from Dashboard (₦<?= number_format($total_price, 2) ?>)
                </button>
            <?php else: ?>
                <div class="error">
                    ❌ Insufficient balance. You need ₦<?= number_format($total_price - $userBalance, 2) ?> more.
                    <?php if (!isset($internet_error)): ?>
                        Please use Paystack payment or add funds to your dashboard.
                    <?php else: ?>
                        Please add funds to your dashboard when connectivity is restored.
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn" disabled>
                    Insufficient Balance
                </button>
            <?php endif; ?>
        </form>

        <!-- Paystack Payment Form -->
        <form method="POST" id="paystackForm" class="payment-form hidden">
            <input type="hidden" name="payment_method" value="paystack">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="currency">Currency</label>
                    <select id="currency" name="currency" required>
                        <?php foreach ($supported_currencies as $code => $currency_info): ?>
                            <option value="<?= $code ?>" data-symbol="<?= $currency_info['symbol'] ?>" <?= $code === 'USD' ? 'selected' : '' ?>>
                                <?= $currency_info['name'] ?> (<?= $currency_info['symbol'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="country">Your Country</label>
                    <select id="country" name="country" required>
                        <?php foreach ($supported_countries as $code => $name): ?>
                            <option value="<?= $code ?>" <?= $code === 'US' ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="conversionDisplay" class="conversion-info">
                Enter amount to see conversion
            </div>
            
            <button type="submit" class="btn" <?= isset($internet_error) ? 'disabled' : '' ?>>
                <?= isset($internet_error) ? 'Paystack Unavailable - Check Connection' : 'Proceed to Payment' ?>
            </button>
        </form>

        <!-- Recent Payments -->
        <?php if ($recent_payments): ?>
            <div class="ad-details">
                <h3>Recent Ad Payments</h3>
                <?php foreach ($recent_payments as $payment): ?>
                    <div class="detail-row">
                        <div class="detail-label">
                            <?= date('M d, Y', strtotime($payment['created_at'])) ?>
                        </div>
                        <div class="detail-value">
                            <?php
                            $original_currency = $payment['original_currency'] ?? 'USD';
                            $original_amount = $payment['original_amount'] ?? $payment['amount'];
                            $currency_symbol = $supported_currencies[$original_currency]['symbol'] ?? '$';
                            
                            if ($original_currency !== 'NGN') {
                                echo $currency_symbol;
                                echo number_format($original_amount, 2) . ' ' . $original_currency;
                                echo ' ≈ ₦' . number_format($payment['amount'], 2);
                            } else {
                                echo '₦' . number_format($payment['amount'], 2);
                            }
                            ?>
                            <span style="margin-left: 10px; color: <?= $payment['status'] === 'success' ? '#38a169' : ($payment['status'] === 'pending' ? '#ed8936' : '#e53e3e') ?>;">
                                • <?= ucfirst($payment['status']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Payment method selection
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', function() {
                // Don't allow selection if Paystack is disabled
                if (this.classList.contains('disabled')) {
                    return;
                }
                
                document.querySelectorAll('.payment-method').forEach(m => {
                    m.classList.remove('selected');
                });
                this.classList.add('selected');
                
                const methodType = this.getAttribute('data-method');
                document.querySelectorAll('.payment-form').forEach(form => {
                    form.classList.add('hidden');
                });
                
                if (methodType === 'dashboard') {
                    document.getElementById('dashboardForm').classList.remove('hidden');
                } else {
                    document.getElementById('paystackForm').classList.remove('hidden');
                }
            });
        });
        
        // Exchange rates from PHP
        const exchangeRates = <?= json_encode($exchange_rates) ?>;
        const totalPrice = <?= $total_price ?>;
        const hasInternetError = <?= isset($internet_error) ? 'true' : 'false' ?>;
        
        // Update conversion display
        function updateConversionDisplay() {
            const currency = document.getElementById('currency')?.value || 'USD';
            const conversionDisplay = document.getElementById('conversionDisplay');
            const currencySymbol = document.querySelector(`#currency option[value="${currency}"]`)?.getAttribute('data-symbol') || '$';
            
            if (conversionDisplay) {
                if (currency === 'NGN') {
                    conversionDisplay.innerHTML = `₦${parseFloat(totalPrice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                } else {
                    // Calculate conversion using exchange rates
                    const rateToUSD = exchangeRates[currency] || 1;
                    const rateToNGN = exchangeRates['NGN'] || 1500;
                    const converted = (totalPrice / rateToUSD) * rateToNGN;
                    
                    const exchangeRate = (rateToNGN / rateToUSD).toFixed(2);
                    
                    conversionDisplay.innerHTML = 
                        `${currencySymbol}${parseFloat(totalPrice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${currency} ≈ ₦${converted.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                         <div class="rate-info">Exchange rate: 1 ${currency} = ${exchangeRate} NGN</div>`;
                }
            }
            
            // Update price display
            const priceAmount = document.getElementById('priceAmount');
            if (priceAmount) {
                if (currency === 'NGN') {
                    priceAmount.innerHTML = `₦${parseFloat(totalPrice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                } else {
                    priceAmount.innerHTML = `${currencySymbol}${parseFloat(totalPrice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                }
            }
        }
        
        // Event listeners
        document.getElementById('currency')?.addEventListener('change', updateConversionDisplay);
        
        // Initialize
        updateConversionDisplay();
        
        // Auto-select Paystack if insufficient balance and no internet error
        <?php if ($userBalance < $total_price && !isset($internet_error)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const paystackMethod = document.querySelector('.payment-method[data-method="paystack"]');
                if (paystackMethod) {
                    paystackMethod.click();
                }
            });
        <?php endif; ?>
        
        // Test connectivity button
        document.querySelector('.test-btn')?.addEventListener('click', function() {
            alert('Testing connectivity to Paystack...\nPlease wait.');
            window.location.reload();
        });
    </script>
</body>
</html>