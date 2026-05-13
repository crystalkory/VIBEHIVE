<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$count = 0;

try {
    // Count pending group join requests where user is admin
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.id
        WHERE g.creator_id = ? AND gm.status = 'pending'
    ");
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // Silently fail
}

echo json_encode([
    'success' => true,
    'count' => $count,
    'timestamp' => time()
]);
?>