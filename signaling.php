<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $callId = $input['call_id'] ?? 0;
    $toUserId = $input['to_user_id'] ?? 0;
    $signalType = $input['type'] ?? '';
    $signalData = $input['data'] ?? '';
    
    // Verify user is in the call
    $stmt = $pdo->prepare("SELECT 1 FROM voice_call_participants WHERE call_id = :call_id AND user_id = :user_id");
    $stmt->execute(['call_id' => $callId, 'user_id' => $userId]);
    
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Not in call']);
        exit;
    }
    
    // Store signal in database
    $stmt = $pdo->prepare("INSERT INTO webrtc_signaling (call_id, from_user_id, to_user_id, signal_type, signal_data) VALUES (:call_id, :from_user, :to_user, :type, :data)");
    $stmt->execute([
        'call_id' => $callId,
        'from_user' => $userId,
        'to_user' => $toUserId,
        'type' => $signalType,
        'data' => json_encode($signalData)
    ]);
    
    echo json_encode(['success' => true]);
    exit;
}

// GET request - retrieve signals for user
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $callId = $_GET['call_id'] ?? 0;
    $lastId = $_GET['last_id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT id, from_user_id, signal_type, signal_data, created_at 
        FROM webrtc_signaling 
        WHERE call_id = :call_id AND to_user_id = :user_id AND id > :last_id 
        ORDER BY id ASC
    ");
    $stmt->execute(['call_id' => $callId, 'user_id' => $userId, 'last_id' => $lastId]);
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['signals' => $signals]);
    exit;
}