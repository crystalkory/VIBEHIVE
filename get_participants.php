<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

require_once "config.php";


$callId = isset($_GET['call_id']) ? (int)$_GET['call_id'] : 0;

if ($callId <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

// Get participants
$stmt = $pdo->prepare("
    SELECT vp.user_id, u.username, u.profile_pic_url, vp.is_muted, vp.joined_at,
           CASE WHEN u.id = g.creator_id THEN 'Admin' ELSE 'Member' END as role
    FROM voice_call_participants vp
    JOIN users u ON vp.user_id = u.id
    JOIN voice_calls vc ON vp.call_id = vc.id
    JOIN groups g ON vc.group_id = g.id
    WHERE vp.call_id = :call_id
    ORDER BY vp.joined_at ASC
");
$stmt->execute(['call_id' => $callId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($participants);