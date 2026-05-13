<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$userId = $_SESSION['user_id'];

$newStatus = isset($_POST['status']) && $_POST['status'] === 'true' ? true : false;

try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=face2', 'postgres', 'Gi12,br12', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET is_profile_locked = ? WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);
    echo json_encode(['success' => true, 'message' => 'Profile lock status updated.', 'status' => $newStatus]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to update profile lock: ' . $e->getMessage()]);
}
