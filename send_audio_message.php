<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$friendId = $_POST['friend_id'] ?? null;
$replyTo = $_POST['reply_to'] ?? null;

if (!$friendId) {
    echo json_encode(['success' => false, 'message' => 'Friend ID required']);
    exit;
}

try {
    // Check if audio file was uploaded
    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No audio file uploaded or upload error']);
        exit;
    }

    $audioFile = $_FILES['audio'];
    
    // Create uploads directory if it doesn't exist
    $uploadDir = 'uploads/audio/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $fileExtension = 'webm'; // Default for recorded audio
    $fileName = uniqid() . '_audio.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (move_uploaded_file($audioFile['tmp_name'], $filePath)) {
        // Insert message into database
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, message, attachment_url, attachment_name, attachment_size, reply_to_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $messageText = "🎤 Audio message";
        $stmt->execute([
            $currentUserId,
            $friendId,
            $messageText,
            $filePath,
            $fileName,
            $audioFile['size'],
            $replyTo ?: null
        ]);
        
        $messageId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true, 
            'message_id' => $messageId,
            'message' => 'Audio message sent successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload audio file']);
    }
    
} catch (Exception $e) {
    error_log("Audio message error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>