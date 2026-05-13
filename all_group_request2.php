<?php
require_once "config.php";


$adminId = $_SESSION['user_id'];

// Handle accept/decline actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'], $_POST['group_id'])) {
    $userId = (int)$_POST['user_id'];
    $action = $_POST['action'];
    $groupId = (int)$_POST['group_id'];

    // Verify current user is admin of the group
    $stmtAdminCheck = $pdo->prepare("SELECT creator_id FROM groups WHERE id = :group_id");
    $stmtAdminCheck->execute(['group_id' => $groupId]);
    $creatorId = $stmtAdminCheck->fetchColumn();

    if ($creatorId != $adminId) {
        die("Access denied. Only admins can manage requests.");
    }

    if ($action === 'accept') {
        $stmtUpdate = $pdo->prepare("UPDATE group_members SET status = 'approved' WHERE group_id = :group_id AND user_id = :user_id");
        $stmtUpdate->execute(['group_id' => $groupId, 'user_id' => $userId]);
    } elseif ($action === 'decline') {
        $stmtUpdate = $pdo->prepare("UPDATE group_members SET status = 'declined' WHERE group_id = :group_id AND user_id = :user_id");
        $stmtUpdate->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }
}

// Fetch all groups where user is admin
$stmtGroups = $pdo->prepare("SELECT id, name, cover_pic_url FROM groups WHERE creator_id = :admin_id ORDER BY name ASC");
$stmtGroups->execute(['admin_id' => $adminId]);
$groups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

// For each group, fetch join requests with status (pending, approved, declined)
$groupRequests = [];
foreach ($groups as $group) {
    $stmtRequests = $pdo->prepare("
        SELECT u.id, u.username, u.profile_pic_url, gm.status
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = :group_id AND gm.status IN ('pending', 'approved', 'declined')
        ORDER BY gm.joined_at ASC
    ");
    $stmtRequests->execute(['group_id' => $group['id']]);
    $groupRequests[$group['id']] = $stmtRequests->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>All Group Join Requests - Fbclone</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 850px;
    margin: 30px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
    color: #333;
    min-height: 100vh;
}

h1 {
    text-align: center;
    margin-bottom: 35px;
    color: #7b68ee;
    font-size: 32px;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.group {
    border: 2px solid rgba(123, 104, 238, 0.3);
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 20px;
    margin-bottom: 30px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.group:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border-color: #7b68ee;
}

.group-header {
    display: flex;
    align-items: center;
    cursor: pointer;
    padding: 10px;
    border-radius: 12px;
    background: rgba(123, 104, 238, 0.1);
    transition: all 0.3s ease;
}

.group-header:hover {
    background: rgba(123, 104, 238, 0.15);
}

.group-cover {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 12px;
    margin-right: 20px;
    border: 3px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.group-header:hover .group-cover {
    transform: scale(1.05);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.group-name {
    font-weight: 700;
    font-size: 1.4em;
    flex-grow: 1;
    color: #7b68ee;
    transition: all 0.3s ease;
}

.group-header:hover .group-name {
    color: #6a5acd;
    text-shadow: 0 2px 4px rgba(123, 104, 238, 0.2);
}

.toggle-icon {
    font-size: 24px;
    user-select: none;
    color: #7b68ee;
    transition: all 0.3s ease;
    background: rgba(123, 104, 238, 0.1);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.toggle-icon:hover {
    background: rgba(123, 104, 238, 0.2);
    transform: scale(1.1);
}

.requests {
    margin-top: 20px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

.request-list {
    list-style: none;
    padding-left: 0;
}

.request-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: rgba(255, 255, 255, 0.9);
    margin-bottom: 15px;
    border-radius: 12px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
}

.request-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
    border-color: #7b68ee;
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
    color: #7b68ee;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
}

.username:hover {
    color: #6a5acd;
    text-shadow: 0 2px 4px rgba(123, 104, 238, 0.2);
}

.status-message {
    font-weight: 600;
    font-style: italic;
    color: #718096;
    background: rgba(123, 104, 238, 0.1);
    padding: 8px 16px;
    border-radius: 20px;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

.buttons {
    display: flex;
    gap: 12px;
}

button {
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    font-family: inherit;
}

button:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.accept {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.accept:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

.decline {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}

.decline:hover {
    background: linear-gradient(135deg, #ee5a52, #e53e3e);
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
}

/* Empty state styling */
p {
    text-align: center;
    color: #718096;
    font-size: 16px;
    font-weight: 500;
    padding: 20px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

/* Animation for group items */
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

.group {
    animation: fadeInUp 0.4s ease-out;
}

/* Form styling */
form {
    display: inline;
}

/* Focus states for accessibility */
button:focus,
.toggle-icon:focus,
.username:focus,
.request-item img:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state */
.group.loading {
    position: relative;
    overflow: hidden;
}

.group.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { left: -100%; }
    100% { left: 100%; }
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 20px auto;
    }
    
    h1 {
        font-size: 28px;
        margin-bottom: 25px;
        padding: 15px;
    }
    
    .group {
        padding: 15px;
        margin-bottom: 25px;
    }
    
    .group-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .group-cover {
        margin-right: 0;
        width: 100px;
        height: 100px;
    }
    
    .group-name {
        font-size: 1.3em;
    }
    
    .request-item {
        flex-direction: column;
        text-align: center;
        gap: 15px;
        padding: 20px;
    }
    
    .request-item img {
        margin-right: 0;
        width: 80px;
        height: 80px;
    }
    
    .username {
        margin-right: 0;
        font-size: 18px;
    }
    
    .buttons {
        width: 100%;
        justify-content: center;
    }
    
    button {
        padding: 12px 24px;
        font-size: 15px;
        flex: 1;
        max-width: 140px;
    }
    
    .status-message {
        width: 100%;
        text-align: center;
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
        padding: 12px;
    }
    
    .group {
        padding: 12px;
        margin-bottom: 20px;
    }
    
    .group-cover {
        width: 80px;
        height: 80px;
    }
    
    .request-item {
        padding: 15px;
        gap: 12px;
    }
    
    .request-item img {
        width: 70px;
        height: 70px;
    }
    
    .username {
        font-size: 16px;
    }
    
    .buttons {
        flex-direction: column;
        width: 100%;
        gap: 8px;
    }
    
    button {
        width: 100%;
        max-width: none;
        padding: 14px;
    }
    
    .status-message {
        font-size: 14px;
        padding: 10px;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .group {
        border: 2px solid #7b68ee;
    }
    
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
    .group,
    .request-item,
    .group-header,
    .group-cover,
    .request-item img,
    button {
        transition: none;
        animation: none;
    }
    
    .group:hover {
        transform: none;
    }
    
    .group-header:hover .group-cover {
        transform: none;
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
    
    .group.loading::after {
        animation: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    }
    
    h1 {
        background: rgba(45, 55, 72, 0.95);
        color: #7b68ee;
    }
    
    .group {
        background: rgba(45, 55, 72, 0.95);
    }
    
    .requests {
        background: rgba(45, 55, 72, 0.8);
    }
    
    .request-item {
        background: rgba(45, 55, 72, 0.9);
    }
    
    .username {
        color: #7b68ee;
    }
    
    .status-message {
        color: #a0aec0;
        background: rgba(123, 104, 238, 0.2);
    }
    
    p {
        background: rgba(45, 55, 72, 0.8);
        color: #e2e8f0;
    }
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    .group {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
    }
    
    .buttons {
        display: none;
    }
    
    h1 {
        background: white;
        color: #2d3748;
        box-shadow: none;
        border: 1px solid #ccc;
    }
}

/* Scrollbar styling */
.requests::-webkit-scrollbar {
    width: 8px;
}

.requests::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 4px;
}

.requests::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 4px;
}

.requests::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}

/* Group count badge */
.group-count {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    margin-left: 10px;
    box-shadow: 0 2px 5px rgba(123, 104, 238, 0.3);
}

/* Request count badge */
.request-count {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    padding: 4px 8px;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 600;
    margin-left: 8px;
    min-width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(255, 107, 107, 0.3);
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggles = document.querySelectorAll('.toggle-icon');
    toggles.forEach(toggle => {
        toggle.addEventListener('click', () => {
            const groupId = toggle.dataset.groupId;
            const reqDiv = document.getElementById('requests-' + groupId);
            if (reqDiv.style.display === 'none') {
                reqDiv.style.display = 'block';
                toggle.textContent = '▼';
            } else {
                reqDiv.style.display = 'none';
                toggle.textContent = '▶';
            }
        });
    });
});
</script>
</head>
<body>

<h1>All Group Join Requests</h1>

<?php if (empty($groups)): ?>
    <p style="text-align:center;">You are not an admin of any groups.</p>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <div class="group">
            <div class="group-header">
                <img src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_group_cover.png') ?>" alt="Group Cover" class="group-cover" />
                <div class="group-name"><?= htmlspecialchars($group['name']) ?></div>
                <div class="toggle-icon" data-group-id="<?= $group['id'] ?>">▼</div>
            </div>

            <div class="requests" id="requests-<?= $group['id'] ?>">
                <?php if(empty($groupRequests[$group['id']])): ?>
                    <p>No join requests.</p>
                <?php else: ?>
                <ul class="request-list">
                    <?php foreach ($groupRequests[$group['id']] as $request): ?>
                    <li class="request-item">
                        <img src="<?= htmlspecialchars($request['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" onclick="window.location.href='profile.php?id=<?= $request['id'] ?>'"/>
                        <span class="username" onclick="window.location.href='profile.php?id=<?= $request['id'] ?>'"><?= htmlspecialchars($request['username']) ?></span>

                        <?php if ($request['status'] === 'approved'): ?>
                            <div class="status-message">You accepted this request.</div>
                        <?php elseif ($request['status'] === 'declined'): ?>
                            <div class="status-message">You declined this join request.</div>
                        <?php else: /* pending */ ?>
                            <div class="buttons">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>" />
                                    <input type="hidden" name="user_id" value="<?= $request['id'] ?>" />
                                    <input type="hidden" name="action" value="accept" />
                                    <button type="submit" class="accept">Accept</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>" />
                                    <input type="hidden" name="user_id" value="<?= $request['id'] ?>" />
                                    <input type="hidden" name="action" value="decline" />
                                    <button type="submit" class="decline">Decline</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.requests').forEach(el => {
    el.style.display = 'block'; // Default visible; toggle handled above
});
</script>

</body>
</html>
