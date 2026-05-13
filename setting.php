<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

// Verify user is admin of the group
$stmt = $pdo->prepare("SELECT creator_id FROM groups WHERE id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group || $group['creator_id'] != $userId) {
    die("Access denied. Only group admin can access settings.");
}

// Get current settings
$stmtSettings = $pdo->prepare("SELECT only_admin_can_call FROM group_settings WHERE group_id = :group_id");
$stmtSettings->execute(['group_id' => $groupId]);
$settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
$onlyAdminCanCall = $settings ? $settings['only_admin_can_call'] : false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $onlyAdminCanCall = isset($_POST['only_admin_can_call']) ? true : false;
    
    if ($settings) {
        $stmtUpdate = $pdo->prepare("UPDATE group_settings SET only_admin_can_call = :value, updated_at = NOW() WHERE group_id = :group_id");
        $stmtUpdate->execute(['value' => $onlyAdminCanCall, 'group_id' => $groupId]);
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO group_settings (group_id, only_admin_can_call) VALUES (:group_id, :value)");
        $stmtInsert->execute(['group_id' => $groupId, 'value' => $onlyAdminCanCall]);
    }
    
    header("Location: setting.php?group_id=$groupId&success=1");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Call Settings</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .switch { position: relative; display: inline-block; width: 60px; height: 34px; margin-right: 15px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #007bff; }
        input:checked + .slider:before { transform: translateX(26px); }
        .setting-item { display: flex; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .setting-label { flex: 1; font-weight: bold; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .back-btn { background: #6c757d; margin-right: 10px; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Voice Call Settings</h1>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="success">Settings updated successfully!</div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="setting-item">
                <div class="setting-label">
                    Only admin can start voice and video calls
                    <div style="font-size: 14px; color: #666; font-weight: normal;">
                        When enabled, only group admins can initiate voice calls. Members can only join existing calls.
                    </div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="only_admin_can_call" <?= $onlyAdminCanCall ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>
            </div>
            
            <div style="margin-top: 30px;">
                <button type="button" class="btn back-btn" onclick="window.location.href='group.php?id=<?= $groupId ?>'">Back to Group</button>
                <button type="submit" class="btn">Save Settings</button>
            </div>
        </form>
    </div>
</body>
</html>