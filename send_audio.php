<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$friendId = filter_input(INPUT_POST, 'friend_id', FILTER_VALIDATE_INT);
$replyTo = filter_input(INPUT_POST, 'reply_to', FILTER_VALIDATE_INT);

if (!$friendId) {
    echo json_encode(['success' => false, 'message' => 'Friend ID required']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No audio file uploaded']);
    exit;
}

// Handle audio file upload
$uploadDir = 'uploads/audio_messages/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$fileName = uniqid() . '_' . time() . '.webm';
$filePath = $uploadDir . $fileName;
$fileSize = $_FILES['audio']['size'];

if (move_uploaded_file($_FILES['audio']['tmp_name'], $filePath)) {
    // Save message to database
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, attachment_url, attachment_name, attachment_size, reply_to_id, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $currentUserId,
        $friendId,
        $filePath,
        'Audio message',
        $fileSize,
        $replyTo ?: null
    ]);
    
    $messageId = $stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'message_id' => $messageId]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload audio']);
}