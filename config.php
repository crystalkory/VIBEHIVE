<?php
// Database config (adjust as needed)
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
?>
