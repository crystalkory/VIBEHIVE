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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update typing status
    $groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
    $isTyping = isset($_POST['is_typing']) ? (bool)$_POST['is_typing'] : false;
    
    if ($groupId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
        exit;
    }
    
    // Store typing status in database or cache (simplified implementation)
    // In a real application, you might use Redis or similar for this
    $stmt = $pdo->prepare("
        INSERT INTO group_typing (group_id, user_id, is_typing, last_updated) 
        VALUES (:group_id, :user_id, :is_typing, NOW())
        ON CONFLICT (group_id, user_id) 
        DO UPDATE SET is_typing = :is_typing, last_updated = NOW()
    ");
    
    $stmt->execute([
        'group_id' => $groupId,
        'user_id' => $userId,
        'is_typing' => $isTyping
    ]);
    
    echo json_encode(['success' => true]);
    
} else {
    // Get typing status
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
    
    if ($groupId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
        exit;
    }
    
    // Get users who are currently typing (within last 3 seconds)
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, gt.is_typing 
        FROM group_typing gt
        JOIN users u ON gt.user_id = u.id
        WHERE gt.group_id = :group_id 
        AND gt.user_id != :user_id
        AND gt.last_updated > NOW() - INTERVAL '3 seconds'
        AND gt.is_typing = true
        LIMIT 1
    ");
    
    $stmt->execute(['group_id' => $groupId, 'user_id' => $userId]);
    $typingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($typingUser) {
        echo json_encode([
            'success' => true, 
            'is_typing' => true, 
            'user_id' => $typingUser['id'],
            'username' => $typingUser['username']
        ]);
    } else {
        echo json_encode(['success' => true, 'is_typing' => false]);
    }
}
?>