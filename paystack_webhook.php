<?php
// paystack_webhook.php
session_start();

$host = 'localhost';
$port = '5432';
$dbname = 'fbclone';
$user = 'postgres';
$password = 'Gi12,br12';

define('PAYSTACK_SECRET_KEY', 'sk_test_your_secret_key_here');

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die("Database connection failed");
}

// Get the input from Paystack
$input = file_get_contents("php://input");
$payload = json_decode($input, true);

// Verify it's from Paystack
if ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, PAYSTACK_SECRET_KEY)) {
    http_response_code(401);
    die("Invalid signature");
}

// Handle the event
if ($payload['event'] === 'charge.success') {
    $data = $payload['data'];
    $reference = $data['reference'];
    $amount = $data['amount'] / 100;
    $user_id = $data['metadata']['user_id'] ?? null;
    
    if ($user_id) {
        try {
            $pdo->beginTransaction();
            
            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE paystack_transactions 
                SET status = 'success', payment_method = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE reference = ?
            ");
            $stmt->execute([$data['channel'] ?? 'bank_transfer', $reference]);
            
            // Update user balance
            $stmt = $pdo->prepare("
                INSERT INTO user_balance (user_id, balance) 
                VALUES (?, ?) 
                ON CONFLICT (user_id) 
                DO UPDATE SET balance = user_balance.balance + EXCLUDED.balance
            ");
            $stmt->execute([$user_id, $amount]);
            
            $pdo->commit();
            
            http_response_code(200);
            echo "Webhook processed successfully";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo "Error processing webhook: " . $e->getMessage();
        }
    }
}
?>