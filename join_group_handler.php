<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!isset($_POST['action'], $_POST['group_id']) || $_POST['action'] !== 'join_group') {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$groupId = (int)$_POST['group_id'];
$userId = $_SESSION['user_id'];

$host = 'localhost';
$port = '5432';
$dbname = 'fbclone';
$user = 'postgres';
$password = 'Gi12,br12';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if user already member or pending
$stmtCheck = $pdo->prepare("SELECT status FROM group_members WHERE group_id = :group_id AND user_id = :user_id");
$stmtCheck->execute(['group_id' => $groupId, 'user_id' => $userId]);
$currentStatus = $stmtCheck->fetchColumn();

if ($currentStatus === 'approved') {
    echo json_encode(['success' => true, 'pending' => false, 'message' => 'Already a member']);
    exit;
}
if ($currentStatus === 'pending') {
    echo json_encode(['success' => true, 'pending' => true, 'message' => 'Join request is pending admin approval']);
    exit;
}

// Check if admin approval is required for this group
$stmtGroup = $pdo->prepare("SELECT admin_approval_required FROM groups WHERE id = :group_id");
$stmtGroup->execute(['group_id' => $groupId]);
$adminApprovalRequired = (bool)$stmtGroup->fetchColumn();

try {
    if ($adminApprovalRequired) {
        // Create pending join request
        $stmtInsert = $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, joined_at) VALUES (:group_id, :user_id, 'pending', NOW())");
        $stmtInsert->execute(['group_id' => $groupId, 'user_id' => $userId]);
        echo json_encode(['success' => true, 'pending' => true, 'message' => 'Join request is pending admin approval']);
    } else {
        // Directly approve user member
        $stmtInsert = $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, joined_at) VALUES (:group_id, :user_id, 'approved', NOW())");
        $stmtInsert->execute(['group_id' => $groupId, 'user_id' => $userId]);
        echo json_encode(['success' => true, 'pending' => false, 'message' => 'Successfully joined the group']);
    }
} catch (PDOException $e) {
    // Possibly duplicate entry or DB error
    echo json_encode(['success' => false, 'message' => 'Failed to process join request']);
}
