<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$type = $_POST['type'] ?? ''; // 'profile' or 'cover'

if (!in_array($type, ['profile', 'cover'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid picture type.']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image uploaded or upload error.']);
    exit;
}

$uploadedFile = $_FILES['image'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($uploadedFile['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported image type.']);
    exit;
}

$uploadDir = 'uploads/profiles/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$uniqueName = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($uploadedFile['name']));
$destination = $uploadDir . $uniqueName;

if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
    exit;
}

try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=face2', 'postgres', 'Gi12,br12', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$field = ($type === 'profile') ? 'profile_picture' : 'cover_photo';

try {
    $stmt = $pdo->prepare("UPDATE users SET $field = ? WHERE id = ?");
    $stmt->execute([$destination, $userId]);
    echo json_encode(['success' => true, 'message' => ucfirst($type) . ' picture updated.', 'url' => $destination]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to update database.']);
}
