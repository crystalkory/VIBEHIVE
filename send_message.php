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
$message = trim($_POST['message'] ?? '');
$replyTo = filter_input(INPUT_POST, 'reply_to', FILTER_VALIDATE_INT);

if (!$friendId) {
    echo json_encode(['success' => false, 'message' => 'Friend ID required']);
    exit;
}
if ($message === '' && empty($_FILES['attachments']['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'Empty message and no files']);
    exit;
}

// Validate that the reply_to message exists and belongs to this conversation if provided
if ($replyTo) {
    $checkStmt = $pdo->prepare("
        SELECT id FROM messages 
        WHERE id = ? 
        AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
    ");
    $checkStmt->execute([$replyTo, $currentUserId, $friendId, $friendId, $currentUserId]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Invalid reply message']);
        exit;
    }
}

// Handle file uploads
$attachments = [];
if (!empty($_FILES['attachments']['name'][0])) {
    $uploadDir = 'uploads/chat_attachments/';
    
    // Create upload directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Process each uploaded file
    foreach ($_FILES['attachments']['name'] as $index => $name) {
        if ($_FILES['attachments']['error'][$index] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['attachments']['tmp_name'][$index];
            $fileSize = $_FILES['attachments']['size'][$index];
            $fileType = $_FILES['attachments']['type'][$index];
            
            // Generate unique filename
            $fileExt = pathinfo($name, PATHINFO_EXTENSION);
            $fileName = uniqid() . '_' . time() . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            
            // Move uploaded file
            if (move_uploaded_file($fileTmp, $filePath)) {
                $attachments[] = [
                    'name' => $name,
                    'path' => $filePath,
                    'size' => $fileSize,
                    'type' => $fileType
                ];
            }
        }
    }
}

// Save message to DB with optional reply_to and attachments
try {
    $pdo->beginTransaction();
    
    if (!empty($attachments)) {
        // Save each attachment as a separate message
        foreach ($attachments as $attachment) {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message, reply_to_id, attachment_url, attachment_name, attachment_size, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $currentUserId, 
                $friendId, 
                $message, // You might want to adjust this - maybe empty for pure file messages
                $replyTo,
                $attachment['path'],
                $attachment['name'],
                $attachment['size']
            ]);
        }
        
        // Also save the text message separately if it's not empty
        if (!empty($message)) {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message, reply_to_id, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$currentUserId, $friendId, $message, $replyTo]);
        }
    } else {
        // Save text-only message
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, message, reply_to_id, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$currentUserId, $friendId, $message, $replyTo]);
    }
    
    $pdo->commit();
    
    // Optionally notify WebSocket server here if architecture allows
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    // Clean up uploaded files if transaction failed
    foreach ($attachments as $attachment) {
        if (file_exists($attachment['path'])) {
            unlink($attachment['path']);
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Failed to send message: ' . $e->getMessage()]);
}