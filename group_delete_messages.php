<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once "config.php";

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$messageIds = $input['message_ids'] ?? [];

if (empty($messageIds) || !is_array($messageIds)) {
    echo json_encode(['success' => false, 'message' => 'No valid messages selected']);
    exit;
}

try {
    // Convert all message IDs to integers for safety
    $messageIds = array_map('intval', $messageIds);
    
    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    
    // Delete messages - only allow users to delete their own messages
    $sql = "DELETE FROM group_messages WHERE id IN ($placeholders) AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    
    // Combine message IDs and user ID for parameters
    $params = array_merge($messageIds, [$_SESSION['user_id']]);
    
    $stmt->execute($params);
    $deletedCount = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'message' => "Successfully deleted $deletedCount message(s)",
        'deleted_count' => $deletedCount
    ]);
    
} catch (PDOException $e) {
    error_log("Delete group messages error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>