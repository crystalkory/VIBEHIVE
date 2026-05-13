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

// Get the JSON input
$input = json_decode(file_get_contents('php://input'), true);
$messageIds = isset($input['message_ids']) ? $input['message_ids'] : [];
$groupId = isset($input['group_id']) ? (int)$input['group_id'] : 0;

if ($groupId <= 0 || empty($messageIds)) {
    echo json_encode(['success' => true, 'read_messages' => []]);
    exit;
}

// Check which messages have been read by other group members
try {
    // Convert message IDs to a comma-separated string for the SQL query
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    
    $stmt = $pdo->prepare("
        SELECT id 
        FROM group_messages 
        WHERE id IN ($placeholders) 
        AND group_id = ? 
        AND read_at IS NOT NULL
    ");
    
    $params = array_merge($messageIds, [$groupId]);
    $stmt->execute($params);
    
    $readMessages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(['success' => true, 'read_messages' => $readMessages]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to check read receipts: ' . $e->getMessage()]);
}
?>