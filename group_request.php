<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit;
}

require_once "config.php";

$adminId = $_SESSION['user_id'];

if (!isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
    die("Invalid group ID.");
}
$groupId = (int)$_GET['group_id'];

// Confirm current user is group admin
$stmt = $pdo->prepare("SELECT creator_id FROM groups WHERE id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$creatorId = $stmt->fetchColumn();

if ($creatorId != $adminId) {
    die("Access denied. Only group admin can view requests.");
}

// Process accept or decline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $userId = (int)$_POST['user_id'];
    $action = $_POST['action'];

    if ($action === 'accept') {
        $stmtUpdate = $pdo->prepare("UPDATE group_members SET status = 'approved' WHERE group_id = :group_id AND user_id = :user_id");
        $stmtUpdate->execute(['group_id' => $groupId, 'user_id' => $userId]);
    } elseif ($action === 'decline') {
        $stmtDelete = $pdo->prepare("DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id AND status = 'pending'");
        $stmtDelete->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }
}

// Fetch pending join requests with user info
$stmtPending = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url
    FROM group_members gm
    JOIN users u ON gm.user_id = u.id
    WHERE gm.group_id = :group_id AND gm.status = 'pending'
    ORDER BY gm.joined_at ASC
");
$stmtPending->execute(['group_id' => $groupId]);
$pendingRequests = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Group Join Requests - Fbclone</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px;
    margin: 30px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
    color: #333;
    min-height: 100vh;
}

h1 {
    margin-bottom: 30px;
    text-align: center;
    margin-top: 20px;
    color: #7b68ee;
    font-size: 32px;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.request-list {
    list-style: none;
    padding: 0;
}

.request-item {
    display: flex;
    align-items: center;
    padding: 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    margin-bottom: 15px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
}

.request-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border-color: rgba(123, 104, 238, 0.3);
}

.request-item img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    cursor: pointer;
    border: 3px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.request-item:hover img {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.username {
    font-weight: 700;
    margin-right: auto;
    cursor: pointer;
    color: #7b68ee;
    font-size: 18px;
    transition: all 0.3s ease;
}

.username:hover {
    color: #6a5acd;
    text-shadow: 0 2px 4px rgba(123, 104, 238, 0.2);
}

.buttons {
    display: flex;
    gap: 12px;
}

button {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    font-family: inherit;
}

.accept {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.accept:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

.decline {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}

.decline:hover {
    background: linear-gradient(135deg, #ee5a52, #e53e3e);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
}

/* Empty state styling */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #7b68ee;
    font-size: 18px;
    font-weight: 600;
}

.empty-state p {
    margin: 0;
    font-size: 16px;
    color: #718096;
    margin-top: 10px;
    font-weight: 500;
}

/* Form styling */
form {
    display: inline;
}

/* Animation for new items */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.request-item {
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
        margin-top: 10px;
        margin-bottom: 25px;
    }
    
    .request-item {
        padding: 15px;
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .request-item img {
        margin-right: 0;
        width: 70px;
        height: 70px;
    }
    
    .username {
        margin-right: 0;
        font-size: 16px;
    }
    
    .buttons {
        width: 100%;
        justify-content: center;
    }
    
    button {
        padding: 10px 20px;
        font-size: 13px;
        flex: 1;
        max-width: 120px;
    }
    
    .empty-state {
        padding: 40px 15px;
        font-size: 16px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
        margin: 15px auto;
    }
    
    h1 {
        font-size: 24px;
        margin-bottom: 20px;
    }
    
    .request-item {
        padding: 12px;
        gap: 12px;
    }
    
    .request-item img {
        width: 60px;
        height: 60px;
    }
    
    .username {
        font-size: 15px;
    }
    
    .buttons {
        flex-direction: column;
        gap: 8px;
        width: 100%;
    }
    
    button {
        padding: 12px;
        font-size: 14px;
        max-width: none;
    }
    
    .empty-state {
        padding: 30px 10px;
        font-size: 15px;
    }
    
    .empty-state p {
        font-size: 14px;
    }
}

/* Focus states for accessibility */
button:focus,
.request-item img:focus,
.username:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state for buttons */
button:disabled {
    background: #cbd5e0;
    transform: none;
    box-shadow: none;
    cursor: not-allowed;
}

/* Success and error message styling */
.message {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 600;
    text-align: center;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
}

.message.success {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.message.error {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}

/* Header with back button */
.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(123, 104, 238, 0.3);
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #718096, #4a5568);
    color: white;
    border: none;
    border-radius: 20px;
    padding: 10px 20px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
    text-decoration: none;
    font-weight: 600;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

.back-btn:hover {
    background: linear-gradient(135deg, #4a5568, #2d3748);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
}

/* Request count badge */
.request-count {
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
    body {
        background: white;
        color: black;
    }
    
    .request-item {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
    }
    
    button {
        display: none;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .request-item {
        border: 2px solid #7b68ee;
    }
    
    .accept {
        background: #28a745;
    }
    
    .decline {
        background: #dc3545;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .request-item,
    .request-item img,
    button,
    .username {
        transition: none;
        animation: none;
    }
    
    .request-item:hover {
        transform: none;
    }
    
    .request-item:hover img {
        transform: none;
    }
    
    button:hover {
        transform: none;
    }
}
</style>
</head>
<body>

<h1 style="margin-top: 50px;">Pending Group Join Requests</h1>

<?php if (empty($pendingRequests)): ?>
    <p style="text-align:center;">No pending join requests.</p>
<?php else: ?>
<ul class="request-list">
    <?php foreach ($pendingRequests as $request): ?>
    <li class="request-item">
        <img src="<?= htmlspecialchars($request['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" onclick="window.location.href='profile.php?id=<?= $request['id'] ?>'" />
        <span class="username" onclick="window.location.href='profile.php?id=<?= $request['id'] ?>'"><?= htmlspecialchars($request['username']) ?></span>
        <div class="buttons">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="user_id" value="<?= $request['id'] ?>" />
                <input type="hidden" name="action" value="accept" />
                <button type="submit" class="accept">Accept</button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="user_id" value="<?= $request['id'] ?>" />
                <input type="hidden" name="action" value="decline" />
                <button type="submit" class="decline">Decline</button>
            </form>
        </div>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

</body>
</html>
