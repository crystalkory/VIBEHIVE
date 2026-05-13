<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$pdo = new PDO("pgsql:host=localhost;dbname=fbclone", "postgres", "Gi12,br12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = $_SESSION['user_id'];

// Get pending friendship users
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.profile_pic_url
    FROM users u
    JOIN friends f ON (
        (f.user_id = :user AND f.friend_id = u.id) OR 
        (f.friend_id = :user AND f.user_id = u.id)
    )
    WHERE f.status != 'accepted'
");
$stmt->execute(['user' => $userId]);
$pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lastMessages = [];
$unreadCounts = [];

if (!empty($pendingUsers)) {
    $pendingUserIds = array_column($pendingUsers, 'id');
    $placeholders = implode(',', array_fill(0, count($pendingUserIds), '?'));

    // Last messages query
    $lastMsgStmt = $pdo->prepare("
        SELECT m.*
        FROM messages m
        INNER JOIN (
            SELECT
                LEAST(sender_id, receiver_id) AS user_one,
                GREATEST(sender_id, receiver_id) AS user_two,
                MAX(created_at) AS max_created_at
            FROM messages
            WHERE (sender_id = ? OR receiver_id = ?)
              AND (sender_id IN ($placeholders) OR receiver_id IN ($placeholders))
            GROUP BY user_one, user_two
        ) lm ON
            LEAST(m.sender_id, m.receiver_id) = lm.user_one AND
            GREATEST(m.sender_id, m.receiver_id) = lm.user_two AND
            m.created_at = lm.max_created_at
    ");
    $params = array_merge([$userId, $userId], $pendingUserIds, $pendingUserIds);
    $lastMsgStmt->execute($params);
    $lastMessagesRaw = $lastMsgStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lastMessagesRaw as $msg) {
        $otherUserId = ($msg['sender_id'] == $userId) ? $msg['receiver_id'] : $msg['sender_id'];
        $lastMessages[$otherUserId] = $msg['message'];
    }

    // Unread messages count query
    $unreadStmt = $pdo->prepare("
        SELECT sender_id, COUNT(*) AS cnt
        FROM messages
        WHERE receiver_id = ? AND read_at IS NULL AND sender_id IN ($placeholders)
        GROUP BY sender_id
    ");
    $paramsUnread = array_merge([$userId], $pendingUserIds);
    $unreadStmt->execute($paramsUnread);
    $unreadResults = $unreadStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unreadResults as $row) {
        $unreadCounts[$row['sender_id']] = $row['cnt'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Pending Friendships for Chat</title>
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

h2 {
    color: #7b68ee;
    font-size: 28px;
    font-weight: 800;
    text-align: center;
    margin-bottom: 30px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

ul {
    list-style: none;
    padding: 0;
}

li {
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(123, 104, 238, 0.3);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-radius: 15px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
}

li:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border-color: #7b68ee;
}

img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    border: 3px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
}

li:hover img {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

span {
    font-weight: 600;
    color: #2d3748;
    font-size: 16px;
}

.btn-message {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    text-decoration: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    margin-left: auto;
    position: relative;
    border: none;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
}

.btn-message:hover {
    background: linear-gradient(135deg, #6a5acd, #5d4fbb);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.badge {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    font-weight: bold;
    border-radius: 50%;
    padding: 4px 8px;
    font-size: 11px;
    user-select: none;
    min-width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 5px rgba(255, 107, 107, 0.3);
    border: 2px solid white;
    transition: all 0.3s ease;
}

.last-message {
    margin-left: 15px;
    font-style: italic;
    color: #718096;
    max-width: 250px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 14px;
    font-weight: 500;
    background: rgba(123, 104, 238, 0.1);
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

/* Empty state styling */
p {
    text-align: center;
    color: #718096;
    font-size: 16px;
    font-weight: 500;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 30px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

/* Animation for list items */
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

li {
    animation: fadeInUp 0.4s ease-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 20px auto;
    }
    
    h2 {
        font-size: 24px;
        margin-bottom: 25px;
    }
    
    li {
        padding: 15px;
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    img {
        margin-right: 0;
        width: 70px;
        height: 70px;
    }
    
    .btn-message {
        margin-left: 0;
        width: 100%;
        max-width: 200px;
    }
    
    .last-message {
        margin-left: 0;
        max-width: 100%;
        text-align: center;
    }
    
    span {
        font-size: 15px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
        margin: 15px auto;
    }
    
    h2 {
        font-size: 22px;
        margin-bottom: 20px;
    }
    
    li {
        padding: 12px;
        gap: 12px;
    }
    
    img {
        width: 60px;
        height: 60px;
    }
    
    .btn-message {
        padding: 10px 20px;
        font-size: 14px;
    }
    
    .last-message {
        font-size: 13px;
        padding: 6px 10px;
    }
    
    span {
        font-size: 14px;
    }
    
    .badge {
        font-size: 10px;
        padding: 3px 6px;
        min-width: 18px;
        height: 18px;
    }
}

/* Focus states for accessibility */
.btn-message:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state for buttons */
.btn-message:disabled {
    background: #cbd5e0;
    transform: none;
    box-shadow: none;
    cursor: not-allowed;
}

/* Message status indicators */
.message-status {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: 15px;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #48bb78;
}

.status-dot.offline {
    background: #a0aec0;
}

.status-dot.away {
    background: #ed8936;
}

/* User info container */
.user-info {
    display: flex;
    align-items: center;
    flex: 1;
}

/* Timestamp for last message */
.message-time {
    font-size: 12px;
    color: #a0aec0;
    margin-left: 10px;
    font-weight: 500;
}

/* Scrollbar styling */
ul::-webkit-scrollbar {
    width: 6px;
}

ul::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 3px;
}

ul::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 3px;
}

ul::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    li {
        border: 2px solid #7b68ee;
    }
    
    .btn-message {
        background: #7b68ee;
    }
    
    .badge {
        background: #ff6b6b;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    li,
    img,
    .btn-message {
        transition: none;
        animation: none;
    }
    
    li:hover {
        transform: none;
    }
    
    li:hover img {
        transform: none;
    }
    
    .btn-message:hover {
        transform: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    }
    
    li {
        background: rgba(45, 55, 72, 0.95);
    }
    
    span {
        color: #e2e8f0;
    }
    
    .last-message {
        color: #a0aec0;
        background: rgba(123, 104, 238, 0.2);
    }
    
    p {
        background: rgba(45, 55, 72, 0.9);
        color: #e2e8f0;
    }
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    li {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
    }
    
    .btn-message {
        display: none;
    }
    
    .badge {
        display: none;
    }
}

/* Hover effects for better UX */
li::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(123, 104, 238, 0.1), transparent);
    border-radius: 15px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

li:hover::before {
    opacity: 1;
}

/* Ensure text remains readable on hover */
li:hover span,
li:hover .last-message {
    position: relative;
    z-index: 1;
}

/* Badge animation */
@keyframes badgePulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.badge-updated {
    animation: badgePulse 0.5s ease-in-out;
}
</style>
</head>
<body>

<h2>Pending Friendships</h2>

<?php if (empty($pendingUsers)): ?>
    <p>No pending friendship requests to message.</p>
<?php else: ?>
    <ul id="pending-friends-list">
        <?php foreach ($pendingUsers as $user):
            $unreadCount = $unreadCounts[$user['id']] ?? 0;
            $lastMsg = $lastMessages[$user['id']] ?? '';
        ?>
            <li id="user-<?= htmlspecialchars($user['id']) ?>">
                <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile Picture" />
                <span><?= htmlspecialchars($user['username']) ?></span>
                <?php if ($lastMsg): ?>
                    <span class="last-message" title="<?= htmlspecialchars($lastMsg) ?>"><?= htmlspecialchars($lastMsg) ?></span>
                <?php endif; ?>
                <a href="send_direct_message.php?id=<?= htmlspecialchars($user['id']) ?>"
                   class="btn-message" 
                   data-user-id="<?= htmlspecialchars($user['id']) ?>"
                   id="message-btn-<?= htmlspecialchars($user['id']) ?>">
                    <span class="btn-text">Message</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge" id="badge-<?= htmlspecialchars($user['id']) ?>">
                            <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<script>
// Function to reload unread counts
function reloadUnreadCounts() {
    fetch('get_unread_counts.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update badges for each user
                data.counts.forEach(countInfo => {
                    const badgeElement = document.getElementById(`badge-${countInfo.user_id}`);
                    const buttonElement = document.getElementById(`message-btn-${countInfo.user_id}`);
                    const btnTextElement = buttonElement.querySelector('.btn-text');
                    
                    if (countInfo.unread_count > 0) {
                        // Create or update badge
                        if (!badgeElement) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'badge badge-updated';
                            newBadge.id = `badge-${countInfo.user_id}`;
                            newBadge.textContent = countInfo.unread_count > 99 ? '99+' : countInfo.unread_count;
                            buttonElement.appendChild(newBadge);
                        } else {
                            badgeElement.textContent = countInfo.unread_count > 99 ? '99+' : countInfo.unread_count;
                            badgeElement.classList.add('badge-updated');
                            setTimeout(() => badgeElement.classList.remove('badge-updated'), 500);
                        }
                    } else if (badgeElement) {
                        // Remove badge if count is 0
                        badgeElement.remove();
                    }
                });
                
                // Check if we need to update users with no unread messages (remove their badges)
                document.querySelectorAll('.badge').forEach(badge => {
                    const userId = badge.id.replace('badge-', '');
                    const hasCount = data.counts.some(count => count.user_id == userId && count.unread_count > 0);
                    if (!hasCount) {
                        badge.remove();
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error fetching unread counts:', error);
        });
}

// Function to mark messages as read when clicking message button
function setupMessageButtons() {
    document.querySelectorAll('.btn-message').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();

            const userId = this.dataset.userId;
            const href = this.href;
            const badge = this.querySelector('.badge');

            // Remove badge visually immediately
            if (badge) {
                badge.style.opacity = '0';
                badge.style.transform = 'scale(0)';
                setTimeout(() => badge.remove(), 300);
            }

            // Mark messages as read with AJAX
            fetch('mark_messages_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sender_id: userId })
            }).then(response => response.json())
              .finally(() => {
                  // Redirect after marking read
                  window.location.href = href;
              });
        });
    });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    setupMessageButtons();
    
    // Reload unread counts every 30 seconds
    setInterval(reloadUnreadCounts, 30000);
    
    // Also reload when the page becomes visible again
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            reloadUnreadCounts();
        }
    });
});

// Optional: Auto-reload when returning to page via browser back button
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        reloadUnreadCounts();
    }
});
</script>

</body>
</html>