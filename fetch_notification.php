<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$markAsSeen = $input['mark_as_seen'] ?? false;

try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    if ($markAsSeen) {
        $update = $pdo->prepare("UPDATE notifications SET is_seen = TRUE WHERE user_id = ? AND is_seen = FALSE");
        $update->execute([$userId]);
    }

    $stmt = $pdo->prepare("SELECT id, message, is_seen, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_seen = FALSE");
    $stmtCount->execute([$userId]);
    $unseenCount = (int)$stmtCount->fetchColumn();

    echo json_encode([
        'notifications' => $notifications,
        'unseen_count' => $unseenCount,
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
