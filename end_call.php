<?php
session_start();
require 'db.php';

$userId = $_SESSION['user_id'] ?? null;
$callId = $_POST['call_id'] ?? null;

if (!$userId || !$callId) {
    http_response_code(400);
    echo json_encode(['error' => 'User or call id missing']);
    exit;
}

// Verify user permission to end call (e.g., admin or call creator)

// Update call ended_at timestamp
$stmt = $pdo->prepare("UPDATE voice_calls SET ended_at = NOW() WHERE id = ? AND ended_at IS NULL");
$stmt->execute([$callId]);

if ($stmt->rowCount()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Call ending failed or already ended']);
}
