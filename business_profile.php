<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_GET['id'] ?? $_SESSION['user_id'];

try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_business = TRUE");
    $stmt->execute([$userId]);
    $business = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$business) {
        die("Business profile not found.");
    }
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title><?= htmlspecialchars($business['business_name']) ?> - Business Profile</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; }
    .cover-photo { width: 100%; height: 200px; background: #ddd url('<?= htmlspecialchars($business['cover_photo'] ?: 'default-cover.jpg') ?>') center/cover no-repeat; border-radius: 10px; }
    .profile-pic { width: 150px; height: 150px; border-radius: 50%; border: 4px solid #fff; margin-top: -75px; margin-left: 20px; object-fit: cover; background: white; }
    h1 { margin-top: 10px; }
    .info { margin: 20px; }
    .info p { margin: 5px 0; }
</style>
</head>
<body>

<div class="cover-photo"></div>
<img src="<?= htmlspecialchars($business['profile_picture'] ?: 'default-profile.png') ?>" alt="Business Profile Picture" class="profile-pic" />

<div class="info">
    <h1><?= htmlspecialchars($business['business_name']) ?></h1>
    <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($business['business_description'])) ?></p>
    <p><strong>Address:</strong> <?= htmlspecialchars($business['business_address']) ?></p>
    <p><strong>Services Offered:</strong> <?= htmlspecialchars($business['business_services']) ?></p>
</div>

</body>
</html>
