<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$senderId = intval($data['sender_id'] ?? 0);
$receiverId = $_SESSION['user_id'];

if ($senderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid sender ID']);
    exit;
}

try {
    $pdo = new PDO("pgsql:host=localhost;dbname=fbclone", "postgres", "Gi12,br12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        UPDATE messages 
        SET read_at = NOW()
        WHERE sender_id = :sender_id AND receiver_id = :receiver_id AND read_at IS NULL
    ");
    $success = $stmt->execute([
        ':sender_id' => $senderId,
        ':receiver_id' => $receiverId,
    ]);

    echo json_encode(['success' => $success]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
