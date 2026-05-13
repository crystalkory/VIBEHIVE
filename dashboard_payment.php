<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
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


// Supported Currencies and Countries (expanded list)
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

// Function to get real-time exchange rates
function getExchangeRates($base_currency = 'USD') {
    $cache_file = 'exchange_rates_cache.json';
    $cache_duration = 300; // 1 hour cache
    
    // Check cache first
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
        $cached_data = json_decode(file_get_contents($cache_file), true);
        if (isset($cached_data['rates']) && isset($cached_data['base']) && $cached_data['base'] === $base_currency) {
            return $cached_data['rates'];
        }
    }
    
    try {
        // Using ExchangeRate-API (free tier available)
        $url = EXCHANGERATE_BASE_URL . $base_currency;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!$error && $response) {
            $data = json_decode($response, true);
            
            if (isset($data['rates'])) {
                // Cache the rates
                file_put_contents($cache_file, json_encode([
                    'rates' => $data['rates'],
                    'base' => $base_currency,
                    'timestamp' => time()
                ]));
                
                return $data['rates'];
            }
        }
        
        // Fallback to static rates if API fails
        return getFallbackRates($base_currency);
        
    } catch (Exception $e) {
        error_log("Exchange rate API error: " . $e->getMessage());
        return getFallbackRates($base_currency);
    }
}

// Fallback rates in case API fails
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
    
    // Convert all rates to the requested base currency
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

// Paystack API Helper Functions
function paystackRequest($endpoint, $method = 'GET', $data = null) {
    $curl = curl_init();
    
    $url = PAYSTACK_BASE_URL . $endpoint;
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
            "Content-Type: application/json"
        ]
    ]);
    
    if ($data && in_array($method, ['POST', 'PUT'])) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    return json_decode($response, true);
}

// Convert any currency to Naira using real exchange rates
function convertToNaira($amount, $from_currency, $exchange_rates) {
    if ($from_currency === 'NGN') {
        return $amount;
    }
    
    if (isset($exchange_rates[$from_currency]) && isset($exchange_rates['NGN'])) {
        // Convert from source currency to USD first, then to NGN
        $amount_in_usd = $amount / $exchange_rates[$from_currency];
        return $amount_in_usd * $exchange_rates['NGN'];
    }
    
    // Default conversion if currency not in rates
    return $amount * 1000; // Safe default
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

// Initialize Paystack Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize_payment'])) {
    $amount = floatval($_POST['amount'] ?? 0);
    $currency = $_POST['currency'] ?? 'USD'; // Default to USD
    $email = $_SESSION['user_email'] ?? 'user@example.com';
    $country = $_POST['country'] ?? 'US'; // Default to US
    
    // Convert to Naira for Paystack (Paystack only accepts NGN for Nigerian merchants)
    $amount_in_naira = convertToNaira($amount, $currency, $exchange_rates);
    $amount_in_kobo = intval(round($amount_in_naira * 100)); // Convert to kobo for Paystack
    
    $min_amount = $supported_currencies[$currency]['min_amount'] ?? 1;
    
    if ($amount < $min_amount) {
        $error = "Minimum amount is " . $supported_currencies[$currency]['symbol'] . $min_amount;
    } else {
        try {
            // Generate unique reference
            $reference = 'PSK_' . uniqid() . '_' . $_SESSION['user_id'];
            
            // Initialize Paystack transaction - ALWAYS USE NGN FOR PAYSTACK
            $payload = [
                'email' => $email,
                'amount' => $amount_in_kobo,
                'reference' => $reference,
                'callback_url' => 'http://yourdomain.com/dashboard_payment.php?verify=' . $reference,
                'currency' => 'NGN', // Force NGN for Paystack
                'metadata' => [
                    'user_id' => $_SESSION['user_id'],
                    'original_currency' => $currency,
                    'original_amount' => $amount,
                    'converted_amount' => $amount_in_naira,
                    'country' => $country,
                    'exchange_rate' => isset($exchange_rates[$currency]) ? $exchange_rates[$currency] : null,
                    'custom_fields' => [
                        [
                            'display_name' => "User ID",
                            'variable_name' => "user_id",
                            'value' => $_SESSION['user_id']
                        ],
                        [
                            'display_name' => "Original Currency",
                            'variable_name' => "original_currency",
                            'value' => $currency
                        ],
                        [
                            'display_name' => "Original Amount",
                            'variable_name' => "original_amount", 
                            'value' => $amount
                        ]
                    ]
                ]
            ];
            
            $response = paystackRequest('/transaction/initialize', 'POST', $payload);
            
            if ($response['status'] && isset($response['data']['authorization_url'])) {
                // Save transaction to database with original currency info
                $stmt = $pdo->prepare("
                    INSERT INTO paystack_transactions (user_id, reference, amount, currency, original_currency, original_amount, country, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $_SESSION['user_id'], 
                    $reference, 
                    $amount_in_naira, // Store converted amount in NGN
                    'NGN', // Store as NGN in database
                    $currency, // Store original currency
                    $amount, // Store original amount
                    $country
                ]);
                
                // Redirect to Paystack payment page
                header('Location: ' . $response['data']['authorization_url']);
                exit;
            } else {
                $error = "Failed to initialize payment: " . ($response['message'] ?? 'Unknown error');
            }
            
        } catch (Exception $e) {
            $error = "Payment initialization failed: " . $e->getMessage();
        }
    }
}

// Create Virtual Account for User (Nigeria only for now)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_virtual_account'])) {
    try {
        $email = $_SESSION['user_email'] ?? 'user@example.com';
        $user_id = $_SESSION['user_id'];
        $country = $_POST['country'] ?? 'NG';
        $currency = $_POST['currency'] ?? 'NGN';
        
        // For now, only support Nigerian virtual accounts
        if ($country !== 'NG') {
            $error = "Virtual accounts are currently only available for Nigerian banks. Please use direct payment for international transactions.";
        } else {
            // Check if user already has an active virtual account
            $stmt = $pdo->prepare("SELECT * FROM user_virtual_accounts WHERE user_id = ? AND is_active = true");
            $stmt->execute([$user_id]);
            $existingAccount = $stmt->fetch();
            
            if ($existingAccount) {
                $virtualAccount = $existingAccount;
            } else {
                // Create dedicated virtual account (Nigeria only)
                $response = paystackRequest('/dedicated_account', 'POST', [
                    'customer' => $email,
                    'preferred_bank' => 'wema-bank',
                ]);
                
                if ($response['status'] && isset($response['data']['account_number'])) {
                    $data = $response['data'];
                    
                    // Save virtual account details
                    $stmt = $pdo->prepare("
                        INSERT INTO user_virtual_accounts (user_id, account_number, bank_name, account_name, currency, country, reference) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $reference = 'VA_' . uniqid() . '_' . $user_id;
                    $stmt->execute([
                        $user_id, 
                        $data['account_number'], 
                        $data['bank']['name'], 
                        $data['account_name'],
                        'NGN', // Virtual accounts are always in NGN
                        'NG',  // Virtual accounts are always Nigeria
                        $reference
                    ]);
                    
                    $virtualAccount = [
                        'account_number' => $data['account_number'],
                        'bank_name' => $data['bank']['name'],
                        'account_name' => $data['account_name'],
                        'currency' => 'NGN',
                        'country' => 'NG'
                    ];
                    
                    $success = "Virtual account created successfully!";
                } else {
                    $error = "Failed to create virtual account: " . ($response['message'] ?? 'Unknown error');
                }
            }
        }
        
    } catch (Exception $e) {
        $error = "Virtual account creation failed: " . $e->getMessage();
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
            $amount = $data['amount'] / 100; // Convert back to Naira
            
            // Get original currency and amount from metadata
            $original_currency = $data['metadata']['original_currency'] ?? 'USD';
            $original_amount = $data['metadata']['original_amount'] ?? $amount;
            
            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE paystack_transactions 
                SET status = 'success', payment_method = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE reference = ?
            ");
            $stmt->execute([$data['channel'] ?? 'card', $reference]);
            
            // Update user balance - always in NGN
            $stmt = $pdo->prepare("
                INSERT INTO user_balance (user_id, balance, currency) 
                VALUES (?, ?, 'NGN') 
                ON CONFLICT (user_id, currency) 
                DO UPDATE SET balance = user_balance.balance + EXCLUDED.balance
            ");
            $stmt->execute([$_SESSION['user_id'], $amount]);
            
            // Create success message with conversion info
            $currency_symbol = $supported_currencies[$original_currency]['symbol'] ?? '$';
            $success_msg = "Payment of " . $currency_symbol . 
                         number_format($original_amount, 2) . " " . $original_currency . 
                         " (≈ ₦" . number_format($amount, 2) . ") completed successfully!";
            
            $_SESSION['payment_success'] = $success_msg;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = "Payment verification failed: " . ($response['message'] ?? 'Transaction not successful');
        }
        
    } catch (Exception $e) {
        $error = "Verification failed: " . $e->getMessage();
    }
}

// Get user's virtual account if exists
$stmt = $pdo->prepare("SELECT * FROM user_virtual_accounts WHERE user_id = ? AND is_active = true");
$stmt->execute([$_SESSION['user_id']]);
$virtualAccount = $stmt->fetch();

// Get recent transactions
$stmt = $pdo->prepare("
    SELECT * FROM paystack_transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$transactions = $stmt->fetchAll();

// Get user balance (always in NGN)
$stmt = $pdo->prepare("SELECT balance FROM user_balance WHERE user_id = ? AND currency = 'NGN'");
$stmt->execute([$_SESSION['user_id']]);
$userBalance = $stmt->fetchColumn() ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Money to Dashboard - International Support</title>
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

        .form-group {
            margin-bottom: 25px;
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

        .amount-presets {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }

        .amount-preset {
            padding: 16px 12px;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(123, 104, 238, 0.2);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: #2d3748;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .amount-preset:hover {
            border-color: rgba(123, 104, 238, 0.5);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(123, 104, 238, 0.2);
        }

        .amount-preset.selected {
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            border-color: #7b68ee;
            color: white;
            box-shadow: 0 4px 15px rgba(123, 104, 238, 0.4);
            transform: translateY(-2px);
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

        .btn:hover {
            background: linear-gradient(135deg, #38a169, #2f855a);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.6);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #718096, #4a5568);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #4a5568, #2d3748);
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

        .payment-methods {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .payment-method {
            flex: 1;
            padding: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .payment-method.selected {
            border-color: #7b68ee;
            background: rgba(123, 104, 238, 0.05);
        }
        
        .payment-method h3 {
            margin: 0 0 10px 0;
            color: #2d3748;
        }
        
        .payment-method p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }
        
        .virtual-account-info {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .virtual-account-info h3 {
            margin: 0 0 15px 0;
            text-align: center;
        }
        
        .account-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .account-detail {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        
        .account-detail .label {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        
        .account-detail .value {
            font-size: 16px;
            font-weight: 700;
        }
        
        .copy-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            margin-top: 8px;
        }
        
        .copy-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .transactions-list {
            margin-top: 30px;
        }
        
        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .transaction-status.success {
            color: #48bb78;
            font-weight: 600;
        }
        
        .transaction-status.pending {
            color: #ed8936;
            font-weight: 600;
        }
        
        .transaction-status.failed {
            color: #e53e3e;
            font-weight: 600;
        }
        
        .hidden {
            display: none;
        }

        .balance-section {
            background: linear-gradient(135deg, #7b68ee, #6a5acd);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(123, 104, 238, 0.3);
        }

        .balance-amount {
            font-size: 24px;
            font-weight: 800;
            text-align: center;
            margin: 0;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .conversion-info {
            background: rgba(123, 104, 238, 0.1);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
            border: 1px solid rgba(123, 104, 238, 0.2);
        }

        .rate-info {
            font-size: 12px;
            opacity: 0.7;
            margin-top: 5px;
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
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌍 Add Money - International</h1>
        
        <div class="nav-links">
            <a href="dashboard.php" class="nav-link">← Back to Dashboard</a>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Balance Overview -->
        <div class="balance-section">
            <h3>Your Balance</h3>
            <div class="balance-amount">₦ <?= number_format($userBalance, 2) ?></div>
            <p style="text-align: center; margin: 10px 0 0 0; opacity: 0.9; font-size: 14px;">
                All currencies are converted to Naira using live exchange rates
            </p>
        </div>

        <!-- Information Banner -->
        <div class="info">
            💡 <strong>International Payments:</strong> All currencies are automatically converted to Naira using current exchange rates. USD is the default currency.
        </div>
        
        <!-- Payment Methods Selection -->
        <div class="payment-methods">
            <div class="payment-method selected" data-method="direct">
                <h3>💳 Direct Payment</h3>
                <p>Pay with card, bank transfer, or mobile money</p>
            </div>
            <div class="payment-method" data-method="virtual">
                <h3>🏦 Virtual Account</h3>
                <p>Get a dedicated Nigerian account number</p>
            </div>
        </div>
        
        <!-- Direct Payment Form -->
        <form method="POST" id="directPaymentForm" class="payment-form">
            <input type="hidden" name="initialize_payment" value="1">
            
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
            
            <div class="form-group">
                <label for="amount">Amount to Add</label>
                <input type="number" id="amount" name="amount" min="1" step="0.01" required placeholder="Enter amount">
                <div id="conversionDisplay" class="conversion-info">
                    Enter amount to see conversion
                </div>
            </div>
            
            <div class="amount-presets">
                <div class="amount-preset" data-amount="10">10</div>
                <div class="amount-preset" data-amount="50">50</div>
                <div class="amount-preset" data-amount="100">100</div>
                <div class="amount-preset" data-amount="250">250</div>
                <div class="amount-preset" data-amount="500">500</div>
                <div class="amount-preset" data-amount="1000">1000</div>
            </div>
            
            <button type="submit" class="btn">Proceed to Payment</button>
        </form>
        
        <!-- Virtual Account Section -->
        <div id="virtualAccountSection" class="payment-form hidden">
            <?php if ($virtualAccount): ?>
                <div class="virtual-account-info">
                    <h3>Your Dedicated Nigerian Account</h3>
                    <div class="account-details">
                        <div class="account-detail">
                            <div class="label">Account Number</div>
                            <div class="value"><?= htmlspecialchars($virtualAccount['account_number']) ?></div>
                            <button class="copy-btn" data-text="<?= htmlspecialchars($virtualAccount['account_number']) ?>">Copy</button>
                        </div>
                        <div class="account-detail">
                            <div class="label">Bank Name</div>
                            <div class="value"><?= htmlspecialchars($virtualAccount['bank_name']) ?></div>
                        </div>
                        <div class="account-detail">
                            <div class="label">Account Name</div>
                            <div class="value"><?= htmlspecialchars($virtualAccount['account_name']) ?></div>
                            <button class="copy-btn" data-text="<?= htmlspecialchars($virtualAccount['account_name']) ?>">Copy</button>
                        </div>
                    </div>
                    <p style="text-align: center; margin: 0; opacity: 0.9; font-size: 14px;">
                        Transfer any amount in Naira to this account and it will be credited automatically within minutes.
                    </p>
                </div>
            <?php else: ?>
                <div class="info">
                    <h3>Nigerian Virtual Account</h3>
                    <p>Create a unique Nigerian bank account number where you can transfer money in Naira. Any transfer to this account will be automatically credited to your balance.</p>
                    <p><strong>Note:</strong> Virtual accounts are currently only available for Nigerian banks.</p>
                    
                    <form method="POST">
                        <input type="hidden" name="create_virtual_account" value="1">
                        <input type="hidden" name="currency" value="NGN">
                        <input type="hidden" name="country" value="NG">
                        <button type="submit" class="btn">Create Nigerian Virtual Account</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Transactions -->
        <?php if ($transactions): ?>
            <div class="transactions-list">
                <h3>Recent Transactions</h3>
                <?php foreach ($transactions as $transaction): ?>
                    <div class="transaction-item">
                        <div>
                            <div>Ref: <?= htmlspecialchars($transaction['reference']) ?></div>
                            <div>
                                <?php
                                $original_currency = $transaction['original_currency'] ?? 'USD';
                                $original_amount = $transaction['original_amount'] ?? $transaction['amount'];
                                $currency_symbol = $supported_currencies[$original_currency]['symbol'] ?? '$';
                                
                                if ($original_currency !== 'NGN') {
                                    echo $currency_symbol;
                                    echo number_format($original_amount, 2) . ' ' . $original_currency;
                                    echo ' ≈ ₦' . number_format($transaction['amount'], 2);
                                } else {
                                    echo '₦' . number_format($transaction['amount'], 2);
                                }
                                ?>
                            </div>
                        </div>
                        <div class="transaction-status <?= $transaction['status'] ?>">
                            <?= ucfirst($transaction['status']) ?>
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
                document.querySelectorAll('.payment-method').forEach(m => {
                    m.classList.remove('selected');
                });
                this.classList.add('selected');
                
                const methodType = this.getAttribute('data-method');
                document.querySelectorAll('.payment-form').forEach(form => {
                    form.classList.add('hidden');
                });
                
                if (methodType === 'direct') {
                    document.getElementById('directPaymentForm').classList.remove('hidden');
                } else {
                    document.getElementById('virtualAccountSection').classList.remove('hidden');
                }
            });
        });
        
        // Amount preset selection
        document.querySelectorAll('.amount-preset').forEach(preset => {
            preset.addEventListener('click', function() {
                document.querySelectorAll('.amount-preset').forEach(p => {
                    p.classList.remove('selected');
                });
                this.classList.add('selected');
                document.getElementById('amount').value = this.getAttribute('data-amount');
                updateConversionDisplay();
            });
        });
        
        // Copy to clipboard functionality
        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const text = this.getAttribute('data-text');
                navigator.clipboard.writeText(text).then(() => {
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = originalText;
                    }, 2000);
                });
            });
        });
        
        // Exchange rates from PHP (passed via data attribute)
        const exchangeRates = <?= json_encode($exchange_rates) ?>;
        
        // Update conversion display
        function updateConversionDisplay() {
            const amount = document.getElementById('amount').value;
            const currency = document.getElementById('currency').value;
            const conversionDisplay = document.getElementById('conversionDisplay');
            const currencySymbol = document.querySelector(`#currency option[value="${currency}"]`).getAttribute('data-symbol');
            
            if (amount && amount > 0) {
                if (currency === 'NGN') {
                    conversionDisplay.innerHTML = `₦${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                } else {
                    // Calculate conversion using exchange rates
                    const rateToUSD = exchangeRates[currency] || 1;
                    const rateToNGN = exchangeRates['NGN'] || 1500;
                    const converted = (amount / rateToUSD) * rateToNGN;
                    
                    const exchangeRate = (rateToNGN / rateToUSD).toFixed(2);
                    
                    conversionDisplay.innerHTML = 
                        `${currencySymbol}${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${currency} ≈ ₦${converted.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                         <div class="rate-info">Exchange rate: 1 ${currency} = ${exchangeRate} NGN</div>`;
                }
            } else {
                conversionDisplay.innerHTML = 'Enter amount to see conversion';
            }
        }
        
        // Event listeners
        document.getElementById('currency')?.addEventListener('change', updateConversionDisplay);
        document.getElementById('amount')?.addEventListener('input', updateConversionDisplay);
        
        // Initialize
        updateConversionDisplay();
        
        // Form validation
        document.getElementById('directPaymentForm').addEventListener('submit', function(e) {
            const amount = document.getElementById('amount').value;
            if (amount < 1) {
                e.preventDefault();
                alert('Please enter a valid amount (minimum 1)');
                return;
            }
        });
    </script>
</body>
</html>