<?php
session_start();
if (!isset($_SESSION['business_user_id'])) {
    header("Location: business_login.php");
    exit;
}

$userId = $_SESSION['business_user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['business_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $servicesOffered = trim($_POST['services_offered'] ?? '');

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $coverPhotoPath = '';
    $profilePhotoPath = '';

    // Upload cover photo
    if (!empty($_FILES['cover_photo']['name'])) {
        $cover = $_FILES['cover_photo'];
        if ($cover['error'] === UPLOAD_ERR_OK && in_array($cover['type'], $allowedTypes)) {
            $uploadDir = 'uploads/business/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($cover['name']));
            $coverPhotoPath = $uploadDir . $filename;
            move_uploaded_file($cover['tmp_name'], $coverPhotoPath);
        } else {
            $error = "Invalid cover photo file type.";
        }
    }

    // Upload profile photo
    if (!$error && !empty($_FILES['profile_photo']['name'])) {
        $profile = $_FILES['profile_photo'];
        if ($profile['error'] === UPLOAD_ERR_OK && in_array($profile['type'], $allowedTypes)) {
            $uploadDir = 'uploads/business/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($profile['name']));
            $profilePhotoPath = $uploadDir . $filename;
            move_uploaded_file($profile['tmp_name'], $profilePhotoPath);
        } else {
            $error = "Invalid profile photo file type.";
        }
    }

    if (!$error && $businessName) {
        try {
            $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            $stmtCheck = $pdo->prepare("SELECT id FROM business_profiles WHERE user_id = ?");
            $stmtCheck->execute([$userId]);
            $exists = $stmtCheck->fetchColumn();

            if ($exists) {
                $sql = "UPDATE business_profiles SET business_name=?, description=?, services_offered=?, updated_at=NOW()";
                $params = [$businessName, $description, $servicesOffered];
                if ($coverPhotoPath) {
                    $sql .= ", cover_photo=?";
                    $params[] = $coverPhotoPath;
                }
                if ($profilePhotoPath) {
                    $sql .= ", profile_picture=?";
                    $params[] = $profilePhotoPath;
                }
                $sql .= " WHERE user_id=?";
                $params[] = $userId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->prepare("INSERT INTO business_profiles (user_id, business_name, description, services_offered, cover_photo, profile_picture, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$userId, $businessName, $description, $servicesOffered, $coverPhotoPath, $profilePhotoPath]);
            }

            header("Location: dashboard.php");
            exit;
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } else {
        $error = "Please provide a business name.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Setup Business Profile</title>
<style>
body { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; }
label { display: block; margin-top: 15px; }
input[type=text], textarea { width: 100%; padding: 10px; margin-top: 5px; border-radius: 5px; border: 1px solid #ccc; }
textarea { resize: vertical; height: 100px; }
input[type=file] { margin-top: 5px; }
button { margin-top: 20px; padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
button:hover { background: #0056b3; }
.error { color: red; }
</style>
</head>
<body>

<h1>Setup Your Business Profile</h1>

<?php if ($error): ?>
<p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <label for="business_name">Business Name*:</label>
    <input type="text" id="business_name" name="business_name" required />

    <label for="description">Description:</label>
    <textarea id="description" name="description"></textarea>

    <label for="services_offered">Services Offered:</label>
    <input type="text" id="services_offered" name="services_offered" />

    <label for="cover_photo">Cover Photo:</label>
    <input type="file" id="cover_photo" name="cover_photo" accept="image/*" />

    <label for="profile_photo">Profile Photo:</label>
    <input type="file" id="profile_photo" name="profile_photo" accept="image/*" />

    <button type="submit">Create Business Account</button>
</form>

</body>
</html>
