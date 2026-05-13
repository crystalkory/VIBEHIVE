<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once "config.php";


// Get the message IDs from the form data
$messageIdsJson = $_POST['message_ids'] ?? '[]';
$messageIds = json_decode($messageIdsJson, true);

if (empty($messageIds) || !is_array($messageIds)) {
    echo json_encode(['success' => false, 'message' => 'No valid messages selected']);
    exit;
}

try {
    // Convert all message IDs to integers for safety
    $messageIds = array_map('intval', $messageIds);
    
    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    
    // Prepare and execute the delete query
    // Only delete messages sent by the current user
    $sql = "DELETE FROM messages WHERE id IN ($placeholders) AND sender_id = ?";
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
    error_log("Delete messages error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>