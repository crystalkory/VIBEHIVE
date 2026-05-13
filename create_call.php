<?php
session_start();
require 'db.php';

$userId = $_SESSION['user_id'] ?? null;
$groupId = $_POST['group_id'] ?? null;

if (!$userId || !$groupId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Insert new call record (example)
$stmt = $pdo->prepare("INSERT INTO voice_calls (group_id, creator_id, started_at) VALUES (?, ?, NOW()) RETURNING id");
$stmt->execute([$groupId, $userId]);
$callId = $stmt->fetchColumn();

echo json_encode(['call_id' => $callId]);
