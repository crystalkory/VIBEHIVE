<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];

// Fetch user info of the logged-in user (to get username, profile pic)
$userStmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = :id");
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Construct invite link using user id or a unique code (better to use a unique token instead of raw ID)
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}";
$inviteLink = $baseUrl . "/myproject/FACEBOOK/signup.php?invite=" . urlencode($userId);

// Now fetch all users who signed up with this user's invite link
// Assuming you store inviter's user_id in signup flow by carrying ?invite=X
$signedUpStmt = $pdo->prepare("
    SELECT u.username, u.profile_pic_url, u.created_at 
    FROM users u
    WHERE u.invited_by = :inviter_id
    ORDER BY u.created_at DESC
");
$signedUpStmt->execute(['inviter_id' => $userId]);
$signedUpUsers = $signedUpStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Invite Friends | <?= htmlspecialchars($user['username'] ?? 'User') ?></title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 700px;
    margin: 20px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
    color: #333;
    min-height: 100vh;
}

h1 {
    color: #7b68ee;
    font-size: 32px;
    font-weight: 800;
    text-align: center;
    margin-bottom: 30px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

h2 {
    color: #7b68ee;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 20px;
    text-align: center;
}

.invite-link-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 30px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
}

.invite-link-container:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.invite-link-container p {
    font-size: 18px;
    font-weight: 600;
    color: #7b68ee;
    margin-bottom: 15px;
}

.invite-link {
    font-size: 16px;
    word-break: break-all;
    margin-bottom: 20px;
    user-select: all;
    background: rgba(123, 104, 238, 0.1);
    padding: 15px;
    border-radius: 10px;
    border: 2px dashed rgba(123, 104, 238, 0.3);
    color: #2d3748;
    font-weight: 500;
    transition: all 0.3s ease;
}

.invite-link:hover {
    background: rgba(123, 104, 238, 0.15);
    border-color: #7b68ee;
}

.copy-btn {
    padding: 12px 24px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 16px;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    font-family: inherit;
}

.copy-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5d4fbb);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.invited-users {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
}

.invited-users:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.invited-user {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    border: 1px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
}

.invited-user:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-color: #7b68ee;
    background: rgba(255, 255, 255, 0.9);
}

.invited-user img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    border: 3px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

.invited-user:hover img {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.invited-user-info {
    font-weight: 600;
    color: #2d3748;
    flex: 1;
}

.invited-user-date {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
    margin-top: 5px;
}

/* Empty state styling */
.invited-users p {
    text-align: center;
    color: #718096;
    font-size: 16px;
    font-weight: 500;
    padding: 40px 20px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 10px;
    border: 2px dashed rgba(123, 104, 238, 0.3);
}

/* Animation for elements */
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

.invite-link-container,
.invited-users,
.invited-user {
    animation: fadeInUp 0.4s ease-out;
}

/* Success message for copy */
.copy-success {
    position: fixed;
    top: 20px;
    right: 20px;
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    padding: 15px 20px;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    transform: translateX(150%);
    transition: transform 0.3s ease;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.copy-success.show {
    transform: translateX(0);
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
    
    h2 {
        font-size: 22px;
    }
    
    .invite-link-container,
    .invited-users {
        padding: 20px;
    }
    
    .invite-link {
        font-size: 14px;
        padding: 12px;
    }
    
    .copy-btn {
        padding: 10px 20px;
        font-size: 15px;
    }
    
    .invited-user {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .invited-user img {
        margin-right: 0;
        width: 70px;
        height: 70px;
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
    
    h2 {
        font-size: 20px;
    }
    
    .invite-link-container,
    .invited-users {
        padding: 15px;
    }
    
    .invite-link {
        font-size: 13px;
        padding: 10px;
    }
    
    .copy-btn {
        padding: 12px;
        font-size: 14px;
        width: 100%;
    }
    
    .invited-user {
        padding: 12px;
    }
    
    .invited-user img {
        width: 60px;
        height: 60px;
    }
    
    .invited-user-info {
        font-size: 14px;
    }
    
    .invited-user-date {
        font-size: 12px;
    }
}

/* Focus states for accessibility */
.copy-btn:focus,
.invite-link:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state */
.invited-users.loading {
    position: relative;
    overflow: hidden;
}

.invited-users.loading::after {
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

/* High contrast mode support */
@media (prefers-contrast: high) {
    .invite-link-container,
    .invited-users {
        border: 2px solid #7b68ee;
    }
    
    .copy-btn {
        background: #7b68ee;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .invite-link-container,
    .invited-users,
    .invited-user,
    .copy-btn,
    .invited-user img {
        transition: none;
        animation: none;
    }
    
    .invite-link-container:hover,
    .invited-users:hover {
        transform: none;
    }
    
    .invited-user:hover {
        transform: none;
    }
    
    .invited-user:hover img {
        transform: none;
    }
    
    .copy-btn:hover {
        transform: none;
    }
    
    .copy-success {
        transition: none;
    }
    
    .invited-users.loading::after {
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
    
    .invite-link-container,
    .invited-users {
        background: rgba(45, 55, 72, 0.95);
    }
    
    .invite-link {
        background: rgba(123, 104, 238, 0.2);
        color: #e2e8f0;
    }
    
    .invited-user {
        background: rgba(45, 55, 72, 0.8);
    }
    
    .invited-user-info {
        color: #e2e8f0;
    }
    
    .invited-user-date {
        color: #a0aec0;
    }
    
    .invited-users p {
        background: rgba(45, 55, 72, 0.8);
        color: #e2e8f0;
    }
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    .invite-link-container,
    .invited-users {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
    }
    
    .copy-btn {
        display: none;
    }
    
    h1 {
        background: white;
        color: #2d3748;
        box-shadow: none;
        border: 1px solid #ccc;
    }
}

/* Invite counter badge */
.invite-counter {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    margin-left: 10px;
    box-shadow: 0 2px 5px rgba(255, 107, 107, 0.3);
}

/* Share buttons container */
.share-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 15px;
    flex-wrap: wrap;
}

.share-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

.share-btn.whatsapp {
    background: linear-gradient(135deg, #25D366, #128C7E);
    color: white;
}

.share-btn.telegram {
    background: linear-gradient(135deg, #0088cc, #007ab9);
    color: white;
}

.share-btn.facebook {
    background: linear-gradient(135deg, #1877F2, #166FE5);
    color: white;
}
</style>
</head>
<body>

<h1>Invite Friends</h1>

<div class="invite-link-container">
    <p>Your unique invite link:</p>
    <div class="invite-link" id="inviteLink"><?= htmlspecialchars($inviteLink) ?></div>
    <button class="copy-btn" onclick="copyInviteLink()">Copy Link</button>
</div>

<div class="invited-users">
    <h2>Users who joined using your invite</h2>
    <?php if (count($signedUpUsers) === 0): ?>
        <p>No users have joined with your invite link yet.</p>
    <?php else: ?>
        <?php foreach ($signedUpUsers as $invitedUser): ?>
            <div class="invited-user">
                <img src="<?= htmlspecialchars($invitedUser['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" />
                <div class="invited-user-info">
                    <?= htmlspecialchars($invitedUser['username']) ?><br />
                    <span class="invited-user-date">Joined: <?= date('M j, Y', strtotime($invitedUser['created_at'])) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function copyInviteLink() {
    const inviteLink = document.getElementById('inviteLink').textContent;
    navigator.clipboard.writeText(inviteLink).then(() => {
        alert('Invite link copied to clipboard!');
    }).catch(() => {
        alert('Failed to copy invite link. Please copy manually.');
    });
}
</script>

</body>
</html>
