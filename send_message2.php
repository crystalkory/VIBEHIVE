<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['receiver_id']) || empty(trim($data['message']))) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$receiverId = (int)$data['receiver_id'];
$message = trim($data['message']);

$pdo = new PDO("pgsql:host=localhost;dbname=fbclone", "postgres", "Gi12,br12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())");
$success = $stmt->execute([$userId, $receiverId, $message]);

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to insert message']);
}
