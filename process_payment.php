<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['boost_data'])) {
    header('Location: boost_post.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: boost_payment.php');
    exit;
}

// Validate payment form data
$required_fields = ['card_number', 'expiry_date', 'cvv', 'card_holder'];
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        die("Please fill in all payment details.");
    }
}

// Simple payment validation
$card_number = str_replace(' ', '', $_POST['card_number']);
$expiry_date = $_POST['expiry_date'];
$cvv = $_POST['cvv'];
$card_holder = $_POST['card_holder'];

// Basic validation
if (strlen($card_number) < 13 || !is_numeric($card_number)) {
    die("Invalid card number.");
}

if (!preg_match('/^\d{2}\/\d{2}$/', $expiry_date)) {
    die("Invalid expiry date format. Use MM/YY.");
}

if (strlen($cvv) < 3 || !is_numeric($cvv)) {
    die("Invalid CVV.");
}

// Database connection
$host = 'localhost';
$port = '5432';
$dbname = 'fbclone';
$user = 'postgres';
$password = 'Gi12,br12';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get boost data from session
$boost_data = $_SESSION['boost_data'];
$user_id = $_SESSION['user_id'];

// Start transaction
$pdo->beginTransaction();

try {
    // First, let's check if the boosts table exists, if not create it
    $tableCheck = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'boosts')")->fetchColumn();
    
    if (!$tableCheck) {
        // Create boosts table if it doesn't exist
        $pdo->exec("
            CREATE TABLE boosts (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                post_ids JSONB NOT NULL,
                boost_days INTEGER NOT NULL,
                total_price DECIMAL(10,2) NOT NULL,
                status VARCHAR(20) DEFAULT 'active',
                created_at TIMESTAMP DEFAULT NOW(),
                expires_at TIMESTAMP NOT NULL
            )
        ");
    }

    // Check if posts table has is_boosted column, if not add it
    $columnCheck = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_name='posts' AND column_name='is_boosted')")->fetchColumn();
    
    if (!$columnCheck) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN is_boosted BOOLEAN DEFAULT false");
    }

    // Calculate expiry date
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$boost_data['boost_days']} days"));
    
    // Create boost record - CORRECTED VERSION
    $stmt = $pdo->prepare("
        INSERT INTO boosts (user_id, post_ids, boost_days, total_price, status, created_at, expires_at) 
        VALUES (:user_id, :post_ids, :boost_days, :total_price, 'active', NOW(), :expires_at)
    ");
    
    $post_ids_json = json_encode($boost_data['post_ids']);
    
    $stmt->execute([
        ':user_id' => $user_id,
        ':post_ids' => $post_ids_json,
        ':boost_days' => $boost_data['boost_days'],
        ':total_price' => $boost_data['total_price'],
        ':expires_at' => $expires_at
    ]);

    // Update posts to mark them as boosted
    if (!empty($boost_data['post_ids'])) {
        $placeholders = str_repeat('?,', count($boost_data['post_ids']) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE posts SET is_boosted = true WHERE id IN ($placeholders)");
        $stmt->execute($boost_data['post_ids']);
    }

    $pdo->commit();

    // Clear boost data from session
    unset($_SESSION['boost_data']);

    // Redirect to success page
    header('Location: boost_success.php');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Payment processing failed: " . $e->getMessage());
}
?>