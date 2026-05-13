<?php
session_start();
require 'db.php';

$userId = $_SESSION['user_id'] ?? null;
$callId = $_POST['call_id'] ?? null;
$status = $_POST['status'] ?? null; // 'joined' or 'left'

if (!$userId || !$callId || !in_array($status, ['joined', 'left'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

if ($status === 'joined') {
    $stmt = $pdo->prepare("INSERT INTO call_participants (call_id, user_id, joined_at) VALUES (?, ?, NOW()) ON CONFLICT (call_id, user_id) DO UPDATE SET joined_at = NOW(), left_at = NULL");
    $stmt->execute([$callId, $userId]);
} else {
    $stmt = $pdo->prepare("UPDATE call_participants SET left_at = NOW() WHERE call_id = ? AND user_id = ?");
    $stmt->execute([$callId, $userId]);
}

echo json_encode(['success' => true]);
