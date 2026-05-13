<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['business_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $servicesOffered = trim($_POST['services_offered'] ?? '');

    if ($businessName === '') {
        $error = "Business name is required.";
    } else {
        $coverPicPath = '';
        $profilePicPath = '';
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

        // Handle Cover Picture Upload
        if (!empty($_FILES['cover_picture']['name'])) {
            $coverPic = $_FILES['cover_picture'];
            if ($coverPic['error'] === UPLOAD_ERR_OK && in_array($coverPic['type'], $allowedTypes)) {
                $uploadDir = 'uploads/business/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $coverPicName = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($coverPic['name']));
                $coverPicPath = $uploadDir . $coverPicName;
                if (!move_uploaded_file($coverPic['tmp_name'], $coverPicPath)) {
                    $error = "Failed to upload cover picture.";
                }
            } else {
                $error = "Invalid cover picture file type.";
            }
        }

        // Handle Profile Picture Upload
        if (!$error && !empty($_FILES['profile_picture']['name'])) {
            $profilePic = $_FILES['profile_picture'];
            if ($profilePic['error'] === UPLOAD_ERR_OK && in_array($profilePic['type'], $allowedTypes)) {
                $uploadDir = 'uploads/business/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $profilePicName = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($profilePic['name']));
                $profilePicPath = $uploadDir . $profilePicName;
                if (!move_uploaded_file($profilePic['tmp_name'], $profilePicPath)) {
                    $error = "Failed to upload profile picture.";
                }
            } else {
                $error = "Invalid profile picture file type.";
            }
        }

        if (!$error) {
            try {
                $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Check if business_profile already exists for user to update or insert
                $stmtCheck = $pdo->prepare("SELECT id FROM business_profiles WHERE user_id = ?");
                $stmtCheck->execute([$userId]);
                $existingBusiness = $stmtCheck->fetchColumn();

                if ($existingBusiness) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE business_profiles SET 
                        business_name = ?, description = ?, address = ?, services_offered = ?, 
                        cover_photo = COALESCE(NULLIF(?, ''), cover_photo),
                        profile_picture = COALESCE(NULLIF(?, ''), profile_picture),
                        updated_at = NOW()
                        WHERE user_id = ?");
                    $stmt->execute([$businessName, $description, $address, $servicesOffered, $coverPicPath, $profilePicPath, $userId]);
                } else {
                    // Insert new business profile
                    $stmt = $pdo->prepare("INSERT INTO business_profiles 
                        (user_id, business_name, description, address, services_offered, cover_photo, profile_picture, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$userId, $businessName, $description, $address, $servicesOffered, $coverPicPath, $profilePicPath]);
                }

                header("Location: switch_account.php");
                exit;
            } catch (Exception $e) {
                $error = "Failed to create business profile: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Create Business Profile</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; }
    label { display: block; margin-top: 15px; }
    input[type=text], textarea { width: 100%; padding: 10px; margin-top: 5px; border-radius: 5px; border: 1px solid #ccc; }
    textarea { resize: vertical; height: 100px; }
    input[type=file] { margin-top: 5px; }
    button { margin-top: 20px; padding: 12px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
    button:hover { background: #0056b3; }
    .error { color: red; }
</style>
</head>
<body>

<h1>Create Business Profile</h1>
<?php if ($error): ?>
<p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <label for="business_name">Business Name*</label>
    <input type="text" name="business_name" id="business_name" required />

    <label for="description">Description</label>
    <textarea name="description" id="description"></textarea>

    <label for="address">Address</label>
    <input type="text" name="address" id="address" />

    <label for="services_offered">Services Offered</label>
    <input type="text" name="services_offered" id="services_offered" />

    <label for="cover_picture">Cover Picture</label>
    <input type="file" name="cover_picture" id="cover_picture" accept="image/*" />

    <label for="profile_picture">Profile Picture</label>
    <input type="file" name="profile_picture" id="profile_picture" accept="image/*" />

    <button type="submit">Create Business Profile</button>
</form>

</body>
</html>
