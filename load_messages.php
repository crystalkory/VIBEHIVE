<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user1 = $_SESSION['user_id'];
$user2 = filter_input(INPUT_GET, 'user2', FILTER_VALIDATE_INT);
if (!$user2) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit;
}

$pdo = new PDO("pgsql:host=localhost;dbname=fbclone", "postgres", "Gi12,br12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("
    SELECT sender_id, receiver_id, message, created_at
    FROM messages
    WHERE (sender_id = :user1 AND receiver_id = :user2)
       OR (sender_id = :user2 AND receiver_id = :user1)
    ORDER BY created_at ASC
");
$stmt->execute(['user1' => $user1, 'user2' => $user2]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'messages' => $messages]);
