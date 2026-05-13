<?php
// test_phpmailer.php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>PHPMailer Installation Test</h2>";

// Test 1: Check if PHPMailer files exist
echo "<h3>1. File Check:</h3>";
$required_files = [
    'PHPMailer/src/PHPMailer.php',
    'PHPMailer/src/Exception.php', 
    'PHPMailer/src/SMTP.php',
    'vendor/autoload.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "✅ $file exists<br>";
    } else {
        echo "❌ $file NOT found<br>";
    }
}

// Test 2: Try to include PHPMailer
echo "<h3>2. Include Test:</h3>";
try {
    // Method 1: Composer autoload
    if (file_exists('vendor/autoload.php')) {
        require 'vendor/autoload.php';
        echo "✅ Composer autoload loaded successfully<br>";
    } 
    // Method 2: Manual include
    else if (file_exists('PHPMailer/src/PHPMailer.php')) {
        require 'PHPMailer/src/Exception.php';
        require 'PHPMailer/src/PHPMailer.php';
        require 'PHPMailer/src/SMTP.php';
        echo "✅ Manual include successful<br>";
    } else {
        throw new Exception("PHPMailer files not found");
    }
    
    // Test 3: Check if classes are available
    echo "<h3>3. Class Availability:</h3>";
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        echo "✅ PHPMailer class found<br>";
    } else {
        echo "❌ PHPMailer class NOT found<br>";
    }
    
    if (class_exists('PHPMailer\PHPMailer\Exception')) {
        echo "✅ Exception class found<br>";
    } else {
        echo "❌ Exception class NOT found<br>";
    }
    
    if (class_exists('PHPMailer\PHPMailer\SMTP')) {
        echo "✅ SMTP class found<br>";
    } else {
        echo "❌ SMTP class NOT found<br>";
    }
    
    // Test 4: Try to create PHPMailer instance
    echo "<h3>4. Instance Test:</h3>";
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    echo "✅ PHPMailer instance created successfully<br>";
    
    // Test 5: Check version
    echo "<h3>5. Version Info:</h3>";
    echo "PHPMailer Version: " . $mail::VERSION . "<br>";
    
    echo "<h3 style='color: green;'>✓ PHPMailer is correctly installed!</h3>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>✗ Error: " . $e->getMessage() . "</h3>";
    echo "<pre>Stack trace:\n" . $e->getTraceAsString() . "</pre>";
}

// Test 6: Check PHP configuration
echo "<h3>6. PHP Configuration:</h3>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "OpenSSL: " . (extension_loaded('openssl') ? '✅ Enabled' : '❌ Disabled') . "<br>";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? '✅ Enabled' : '❌ Disabled') . "<br>";

?>