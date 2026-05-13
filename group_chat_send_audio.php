<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['user_id'];

$host = 'localhost'; $port = '5432'; $dbname = 'fbclone'; $dbUser = 'postgres'; $dbPass = 'Gi12,br12';
try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed: ' . $e->getMessage()]);
    exit;
}

// Validate input
$groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$replyTo = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

if ($groupId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
    exit;
}

// Check if user is approved member
$stmt = $pdo->prepare("SELECT status FROM group_members WHERE user_id=:user_id AND group_id=:group_id");
$stmt->execute(['user_id' => $userId, 'group_id' => $groupId]);
$status = $stmt->fetchColumn();

if ($status !== 'approved') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Handle audio upload
if (!isset($_FILES['audio'])) {
    echo json_encode(['success' => false, 'message' => 'No audio file provided']);
    exit;
}

$audioFile = $_FILES['audio'];
$uploadDir = 'uploads/group_audio/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$fileName = time() . '_audio_' . $userId . '.webm';
$filePath = $uploadDir . $fileName;

if (move_uploaded_file($audioFile['tmp_name'], $filePath)) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO group_messages (group_id, user_id, message, reply_to, attachment_url, attachment_name, attachment_size) 
            VALUES (:group_id, :user_id, :message, :reply_to, :attachment_url, :attachment_name, :attachment_size)
            RETURNING id
        ");
        
        $stmt->execute([
            'group_id' => $groupId,
            'user_id' => $userId,
            'message' => '[Audio message]',
            'reply_to' => $replyTo,
            'attachment_url' => $filePath,
            'attachment_name' => 'audio-message.webm',
            'attachment_size' => $audioFile['size']
        ]);
        
        $messageId = $stmt->fetchColumn();
        
        // Update last activity timestamp for the group
        $stmt = $pdo->prepare("UPDATE groups SET last_activity = NOW() WHERE id = :group_id");
        $stmt->execute(['group_id' => $groupId]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message_id' => $messageId]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        // Delete the uploaded file if DB operation failed
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to send audio message: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload audio file']);
}
?>