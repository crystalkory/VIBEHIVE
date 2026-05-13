<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['group_id'])) {
    header('Location: join_groups.php');
    exit;
}

try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=fbclone", "postgres", "Gi12,br12", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

$userId = $_SESSION['user_id'];
$groupId = (int)$_POST['group_id'];

// Check if user is already a member or has pending request
$stmt = $pdo->prepare("SELECT status FROM group_members WHERE user_id = :user_id AND group_id = :group_id");
$stmt->execute(['user_id' => $userId, 'group_id' => $groupId]);
$existingStatus = $stmt->fetchColumn();
if ($existingStatus) {
    // Already member or pending - do nothing or redirect
    header("Location: join_groups.php");
    exit;
}

// Get group details
$stmt = $pdo->prepare("SELECT admin_approval_required, created_by FROM groups WHERE id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    // Group not found
    header("Location: join_groups.php");
    exit;
}

$status = $group['admin_approval_required'] ? 'pending' : 'approved';

// Insert group member entry
$stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, is_admin, status, joined_at) VALUES (:group_id, :user_id, false, :status, NOW())");
$stmt->execute([
    'group_id' => $groupId,
    'user_id' => $userId,
    'status' => $status
]);

// Retrieve current user info for notifications
$stmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Notify group admins
$adminsStmt = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = :group_id AND is_admin = true");
$adminsStmt->execute(['group_id' => $groupId]);
$admins = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($admins as $adminId) {
    if ($status === 'pending') {
        $text = "{$user['username']} has requested to join your group.";
    } else {
        $text = "{$user['username']} has joined your group.";
    }
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, notification_text, created_at, is_read) VALUES (:user_id, :text, NOW(), false)");
    $notifStmt->execute(['user_id' => $adminId, 'text' => $text]);
}

header("Location: join_groups.php");
exit;
