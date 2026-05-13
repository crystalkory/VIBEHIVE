<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "config.php";


$userId = $_SESSION['user_id'];
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($groupId <= 0) {
    die("Invalid group ID.");
}

// Check if current user is group admin
$stmt = $pdo->prepare("SELECT * FROM groups WHERE id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    die("Group not found.");
}

if ($group['creator_id'] != $userId) {
    die("Access denied. You are not the admin of this group.");
}

// Define categories
$categories = ['Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'];

// Helper function for image upload
function uploadImageFile($file, $type = 'profile')
{
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error.'];
    }
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File size must be less than 5MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimeTypes)) {
        return ['success' => false, 'error' => 'Only JPEG, PNG, and GIF images are allowed.'];
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = uniqid($type . '_', true) . '.' . $ext;
    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $destination = $uploadDir . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Failed to move uploaded file.'];
    }
    return ['success' => true, 'filename' => 'uploads/' . $safeName];
}

$message = '';
$messageType = ''; // success or error

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle group info update
    if (isset($_POST['update_group_info'])) {
        $groupName = trim($_POST['group_name'] ?? '');
        $groupDescription = trim($_POST['group_description'] ?? '');
        $category1 = $_POST['category1'] ?? null;
        $category2 = $_POST['category2'] ?? null;
        $category3 = $_POST['category3'] ?? null;
        
        // Validate categories - they can't be the same
        $selectedCategories = array_filter([$category1, $category2, $category3]);
        if (count($selectedCategories) !== count(array_unique($selectedCategories))) {
            $message = "Please choose different categories for each dropdown.";
            $messageType = 'error';
        } elseif ($groupName === '') {
            $message = "Group name is required.";
            $messageType = 'error';
        } else {
            $updateSql = "UPDATE groups SET name = :name, description = :description, category1 = :category1, category2 = :category2, category3 = :category3";
            $updateParams = [
                'name' => $groupName,
                'description' => $groupDescription,
                'category1' => $category1,
                'category2' => $category2,
                'category3' => $category3,
                'group_id' => $groupId
            ];
            
            // Handle profile picture upload
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                $result = uploadImageFile($_FILES['profile_picture'], 'profile');
                if ($result['success']) {
                    $updateSql .= ", profile_pic_url = :profile_pic";
                    $updateParams['profile_pic'] = $result['filename'];
                } else {
                    $message = "Profile picture error: " . $result['error'];
                    $messageType = 'error';
                }
            }
            
            // Handle cover picture upload
            if (isset($_FILES['cover_picture']) && $_FILES['cover_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                $result = uploadImageFile($_FILES['cover_picture'], 'cover');
                if ($result['success']) {
                    $updateSql .= ", cover_pic_url = :cover_pic";
                    $updateParams['cover_pic'] = $result['filename'];
                } else {
                    $message = "Cover picture error: " . $result['error'];
                    $messageType = 'error';
                }
            }
            
            $updateSql .= " WHERE id = :group_id AND creator_id = :creator_id";
            $updateParams['creator_id'] = $userId;
            
            if (!$message) {
                $stmt = $pdo->prepare($updateSql);
                if ($stmt->execute($updateParams)) {
                    $message = "Group information updated successfully!";
                    $messageType = 'success';
                    // Refresh group data
                    $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = :group_id");
                    $stmt->execute(['group_id' => $groupId]);
                    $group = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $message = "Failed to update group information.";
                    $messageType = 'error';
                }
            }
        }
    }
    
    // Handle admin approval toggle
    if (isset($_POST['admin_approval'])) {
        $newValue = ($_POST['admin_approval'] === 'true') ? true : false;
        $updateStmt = $pdo->prepare("UPDATE groups SET admin_approval_required = :new_val WHERE id = :group_id AND creator_id = :user_id");
        $updateStmt->execute(['new_val' => $newValue, 'group_id' => $groupId, 'user_id' => $userId]);
        echo json_encode(['success' => true, 'admin_approval' => $newValue]);
        exit;
    }
    
    // Handle remove user
    if (isset($_POST['remove_user'])) {
        $removeUserId = (int)$_POST['user_id'];
        if ($removeUserId !== $userId) { // Prevent admin from removing themselves
            $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id");
            if ($stmt->execute(['group_id' => $groupId, 'user_id' => $removeUserId])) {
                $message = "User removed from group successfully!";
                $messageType = 'success';
            } else {
                $message = "Failed to remove user from group.";
                $messageType = 'error';
            }
        } else {
            $message = "You cannot remove yourself as admin.";
            $messageType = 'error';
        }
    }
    
    // Handle make admin
    if (isset($_POST['make_admin'])) {
        $newAdminId = (int)$_POST['user_id'];
        if ($newAdminId !== $userId) {
            // Update group creator
            $stmt = $pdo->prepare("UPDATE groups SET creator_id = :new_admin_id WHERE id = :group_id AND creator_id = :current_admin_id");
            if ($stmt->execute(['new_admin_id' => $newAdminId, 'group_id' => $groupId, 'current_admin_id' => $userId])) {
                $message = "Admin privileges transferred successfully!";
                $messageType = 'success';
                // Update user ID to reflect change
                $userId = $newAdminId;
                $_SESSION['user_id'] = $newAdminId;
                // Refresh group data
                $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = :group_id");
                $stmt->execute(['group_id' => $groupId]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = "Failed to transfer admin privileges.";
                $messageType = 'error';
            }
        } else {
            $message = "You are already the admin.";
            $messageType = 'error';
        }
    }
}

$adminApproval = (bool)$group['admin_approval_required'];

// Fetch group members
$stmtMembers = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url, 
           (u.id = g.creator_id) as is_admin
    FROM group_members gm
    JOIN users u ON gm.user_id = u.id
    JOIN groups g ON gm.group_id = g.id
    WHERE gm.group_id = :group_id AND gm.status = 'approved'
    ORDER BY is_admin DESC, u.username ASC
");
$stmtMembers->execute(['group_id' => $groupId]);
$members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Group Settings - Fbclone</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 800px;
    margin: 30px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
    color: #333;
    min-height: 100vh;
}

h1 {
    text-align: center;
    margin-bottom: 30px;
    color: #7b68ee;
    font-size: 32px;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 30px;
    margin: 25px 0;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
}

.section:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
}

.section h2 {
    color: #7b68ee;
    margin-bottom: 25px;
    border-bottom: 2px solid #7b68ee;
    padding-bottom: 15px;
    font-size: 24px;
    font-weight: 700;
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
    color: #7b68ee;
    font-size: 16px;
}

.form-group input[type="text"],
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 15px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    border-radius: 10px;
    font-size: 16px;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    box-sizing: border-box;
    transition: all 0.3s ease;
    font-family: inherit;
}

.form-group input[type="text"]:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #7b68ee;
    box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

.form-group textarea {
    resize: vertical;
    min-height: 120px;
    line-height: 1.5;
}

.form-group input[type="file"] {
    width: 100%;
    padding: 12px;
    border: 2px dashed rgba(123, 104, 238, 0.5);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.8);
    color: #2d3748;
    transition: all 0.3s ease;
}

.form-group input[type="file"]:hover {
    background: rgba(123, 104, 238, 0.1);
    border-color: #7b68ee;
}

.btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
    font-family: inherit;
}

.btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5d4fbb);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

.btn-success {
    background: linear-gradient(135deg, #48bb78, #38a169);
    box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

.btn-success:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    box-shadow: 0 8px 25px rgba(72, 187, 120, 0.6);
}

.btn-danger {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
}

.btn-danger:hover {
    background: linear-gradient(135deg, #ee5a52, #e53e3e);
    box-shadow: 0 8px 25px rgba(255, 107, 107, 0.6);
}

.btn-warning {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    color: white;
    box-shadow: 0 5px 15px rgba(237, 137, 54, 0.4);
}

.btn-warning:hover {
    background: linear-gradient(135deg, #dd6b20, #c05621);
    box-shadow: 0 8px 25px rgba(237, 137, 54, 0.6);
}

/* Category row styling */
.category-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.category-row > div {
    display: flex;
    flex-direction: column;
}

/* Toggle switch */
.setting-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: rgba(123, 104, 238, 0.1);
    border-radius: 12px;
    margin: 20px 0;
    border: 1px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
}

.setting-item:hover {
    background: rgba(123, 104, 238, 0.15);
    transform: translateY(-2px);
}

.switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 30px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, #cbd5e0, #a0aec0);
    transition: .4s;
    border-radius: 15px;
    box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.2);
}

.slider:before {
    position: absolute;
    content: "";
    height: 22px;
    width: 22px;
    left: 4px;
    bottom: 4px;
    background: white;
    transition: .4s;
    border-radius: 50%;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

input:checked + .slider {
    background: linear-gradient(135deg, #48bb78, #38a169);
}

input:checked + .slider:before {
    transform: translateX(30px);
}

.status-text {
    font-weight: 600;
    font-size: 18px;
    color: #7b68ee;
}

/* Members list */
.members-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.member-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 12px;
    border: 1px solid rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.member-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.2);
    border-color: #7b68ee;
}

.member-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.member-avatar {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.member-item:hover .member-avatar {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.member-name {
    font-weight: 700;
    color: #2d3748;
    font-size: 16px;
}

.admin-badge {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 10px;
    box-shadow: 0 2px 5px rgba(72, 187, 120, 0.3);
}

.member-actions {
    display: flex;
    gap: 10px;
}

.message {
    margin: 20px 0;
    padding: 20px;
    border-radius: 12px;
    font-weight: 600;
    text-align: center;
    font-size: 16px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.success {
    color: white;
    background: linear-gradient(135deg, #48bb78, #38a169);
    border: 1px solid rgba(72, 187, 120, 0.3);
}

.error {
    color: white;
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    border: 1px solid rgba(255, 107, 107, 0.3);
}

.current-images {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 25px;
    margin-bottom: 25px;
}

.image-preview {
    text-align: center;
    padding: 20px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.image-preview:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.2);
    border-color: #7b68ee;
}

.image-preview img {
    width: 160px;
    height: 160px;
    object-fit: cover;
    border-radius: 10px;
    border: 3px solid #7b68ee;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.image-preview:hover img {
    transform: scale(1.05);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.4);
}

.image-label {
    display: block;
    margin-top: 12px;
    font-weight: 600;
    color: #7b68ee;
    font-size: 14px;
}

.current-categories {
    background: rgba(123, 104, 238, 0.1);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 1px solid rgba(123, 104, 238, 0.2);
    backdrop-filter: blur(10px);
}

.current-categories h4 {
    margin: 0 0 15px 0;
    color: #7b68ee;
    font-size: 18px;
    font-weight: 600;
}

.category-tags {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.category-tag {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 8px 16px;
    border-radius: 25px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.category-tag:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

/* Form actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 25px;
}

/* Member count badge */
.member-count {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    display: inline-block;
    margin-left: 10px;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
}

/* Animation for new elements */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.section {
    animation: fadeInUp 0.6s ease-out;
}

.member-item {
    animation: fadeInUp 0.4s ease-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 20px auto;
    }
    
    h1 {
        font-size: 28px;
        margin-bottom: 20px;
    }
    
    .section {
        padding: 20px;
        margin: 20px 0;
    }
    
    .category-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .current-images {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .image-preview img {
        width: 140px;
        height: 140px;
    }
    
    .member-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .member-actions {
        width: 100%;
        justify-content: flex-end;
    }
    
    .setting-item {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .form-group input[type="text"],
    .form-group textarea,
    .form-group select {
        padding: 12px;
        font-size: 15px;
    }
    
    .btn {
        padding: 12px 24px;
        font-size: 15px;
        width: 100%;
    }
    
    .member-actions {
        flex-direction: column;
    }
    
    .member-actions .btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
        margin: 15px auto;
    }
    
    h1 {
        font-size: 24px;
    }
    
    .section {
        padding: 15px;
    }
    
    .section h2 {
        font-size: 20px;
        margin-bottom: 20px;
    }
    
    .current-images {
        gap: 15px;
    }
    
    .image-preview img {
        width: 120px;
        height: 120px;
    }
    
    .member-avatar {
        width: 45px;
        height: 45px;
    }
    
    .member-name {
        font-size: 14px;
    }
    
    .category-tags {
        gap: 8px;
    }
    
    .category-tag {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .admin-badge {
        padding: 4px 8px;
        font-size: 10px;
    }
    
    .status-text {
        font-size: 16px;
    }
}

/* Focus states for accessibility */
button:focus,
input:focus,
textarea:focus,
select:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state for buttons */
.btn:disabled {
    background: #cbd5e0;
    transform: none;
    box-shadow: none;
    cursor: not-allowed;
}

/* Scrollbar styling */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}

/* Print styles */
@media print {
    .btn,
    .member-actions,
    .switch,
    input[type="file"] {
        display: none !important;
    }
    
    .section {
        box-shadow: none;
        border: 1px solid #ccc;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const adminToggle = document.getElementById('adminApprovalToggle');
    const statusText = document.getElementById('statusText');
    const messageBox = document.getElementById('messageBox');

    if (adminToggle) {
        adminToggle.addEventListener('change', async function() {
            const newValue = adminToggle.checked;

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'admin_approval=' + encodeURIComponent(newValue)
                });
                const data = await response.json();
                if (data.success) {
                    statusText.textContent = data.admin_approval ? 'Admin Approval Required: ON' : 'Admin Approval Required: OFF';
                    showMessage('Setting updated successfully.', 'success');
                } else {
                    showMessage('Failed to update setting.', 'error');
                }
            } catch (err) {
                showMessage('Error updating setting.', 'error');
            }
        });
    }

    // Category validation
    const category1 = document.getElementById('category1');
    const category2 = document.getElementById('category2');
    const category3 = document.getElementById('category3');
    
    function validateCategories() {
        const cat1 = category1.value;
        const cat2 = category2.value;
        const cat3 = category3.value;
        
        // Reset custom validity
        category2.setCustomValidity('');
        category3.setCustomValidity('');
        
        if (cat1 && cat2 && cat1 === cat2) {
            category2.setCustomValidity('Please choose a different category from Category 1');
        }
        if (cat1 && cat3 && cat1 === cat3) {
            category3.setCustomValidity('Please choose a different category from Category 1');
        }
        if (cat2 && cat3 && cat2 === cat3) {
            category3.setCustomValidity('Please choose a different category from Category 2');
        }
    }
    
    if (category1 && category2 && category3) {
        category1.addEventListener('change', validateCategories);
        category2.addEventListener('change', validateCategories);
        category3.addEventListener('change', validateCategories);
    }

    // Confirm actions
    document.querySelectorAll('.btn-danger, .btn-warning').forEach(button => {
        button.addEventListener('click', function(e) {
            const action = this.classList.contains('btn-danger') ? 'remove' : 'make admin';
            const userName = this.closest('.member-item').querySelector('.member-name').textContent;
            if (!confirm(`Are you sure you want to ${action} ${userName}?`)) {
                e.preventDefault();
            }
        });
    });

    function showMessage(text, type) {
        const messageBox = document.getElementById('messageBox');
        if (messageBox) {
            messageBox.textContent = text;
            messageBox.className = 'message ' + type;
            messageBox.style.display = 'block';
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                messageBox.style.display = 'none';
            }, 5000);
        }
    }

    // Image preview
    const profileInput = document.getElementById('profile_picture');
    const coverInput = document.getElementById('cover_picture');
    const profilePreview = document.getElementById('profile_preview');
    const coverPreview = document.getElementById('cover_preview');

    if (profileInput && profilePreview) {
        profileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    profilePreview.src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
    }

    if (coverInput && coverPreview) {
        coverInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    coverPreview.src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>
</head>
<body>

<h1>Group Settings - <?= htmlspecialchars($group['name']) ?></h1>

<?php if ($message): ?>
    <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div id="messageBox" style="display: none;"></div>

<!-- Group Information Section -->
<div class="section">
    <h2>Group Information</h2>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="group_name">Group Name</label>
            <input type="text" id="group_name" name="group_name" value="<?= htmlspecialchars($group['name']) ?>" required />
        </div>
        
        <div class="form-group">
            <label for="group_description">Group Description</label>
            <textarea id="group_description" name="group_description"><?= htmlspecialchars($group['description']) ?></textarea>
        </div>

        <!-- Current Categories Display -->
        <div class="current-categories">
            <h4>Current Categories</h4>
            <div class="category-tags">
                <?php 
                $currentCategories = array_filter([$group['category1'], $group['category2'], $group['category3']]);
                if (!empty($currentCategories)): 
                    foreach ($currentCategories as $category): 
                        if (!empty($category)): 
                ?>
                    <span class="category-tag"><?= htmlspecialchars($category) ?></span>
                <?php 
                        endif;
                    endforeach; 
                else: 
                ?>
                    <span style="color: #aaa;">No categories set</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Category Selection -->
        <div class="form-group">
            <label>Update Categories (Optional)</label>
            <div class="category-row">
                <div>
                    <select id="category1" name="category1">
                        <option value="">Select Category 1</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" <?= ($group['category1'] ?? '') === $category ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select id="category2" name="category2">
                        <option value="">Select Category 2</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" <?= ($group['category2'] ?? '') === $category ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select id="category3" name="category3">
                        <option value="">Select Category 3</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" <?= ($group['category3'] ?? '') === $category ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="current-images">
            <div class="image-preview">
                <img id="profile_preview" src="<?= htmlspecialchars($group['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Current Profile" />
                <span class="image-label">Current Profile Picture</span>
            </div>
            <div class="image-preview">
                <img id="cover_preview" src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_cover.jpg') ?>" alt="Current Cover" />
                <span class="image-label">Current Cover Picture</span>
            </div>
        </div>
        
        <div class="form-group">
            <label for="profile_picture">Update Profile Picture</label>
            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" />
        </div>
        
        <div class="form-group">
            <label for="cover_picture">Update Cover Picture</label>
            <input type="file" id="cover_picture" name="cover_picture" accept="image/*" />
        </div>
        
        <button type="submit" name="update_group_info" class="btn btn-success">Update Group Information</button>
    </form>
</div>

<!-- Group Settings Section -->
<div class="section">
    <h2>Group Settings</h2>
    <div class="setting-item">
        <span class="status-text" id="statusText">Admin Approval Required: <?= $adminApproval ? 'ON' : 'OFF' ?></span>
        <label class="switch">
            <input type="checkbox" id="adminApprovalToggle" <?= $adminApproval ? 'checked' : '' ?> />
            <span class="slider"></span>
        </label>
    </div>
</div>

<!-- Group Members Management Section -->
<div class="section">
    <h2>Manage Members (<?= count($members) ?> members)</h2>
    <div class="members-list">
        <?php foreach ($members as $member): ?>
            <div class="member-item">
                <div class="member-info">
                    <img src="<?= htmlspecialchars($member['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile" class="member-avatar" />
                    <span class="member-name">
                        <?= htmlspecialchars($member['username']) ?>
                        <?php if ($member['is_admin']): ?>
                            <span class="admin-badge">Admin</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="member-actions">
                    <?php if (!$member['is_admin']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?= $member['id'] ?>" />
                            <button type="submit" name="make_admin" class="btn btn-warning">Make Admin</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?= $member['id'] ?>" />
                            <button type="submit" name="remove_user" class="btn btn-danger">Remove</button>
                        </form>
                    <?php else: ?>
                        <span class="admin-badge">You</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>