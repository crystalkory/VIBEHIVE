<?php
// db.php - Database connection file for PostgreSQL

$host = "localhost";     // Database host
$port = "5432";          // Default PostgreSQL port
$dbname = "socialapp"; // Your database name
$user = "postgres";      // Your PostgreSQL username
$password = "Gi12,br12"; // Your PostgreSQL password

try {
    // Create a new PDO instance
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);

    // Set error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Optional: set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Handle error if connection fails
    die("Database connection failed: " . $e->getMessage());
}
?>
