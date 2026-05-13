<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "header.php";

require_once "config.php";


$userId = $_SESSION['user_id'];

// Get unread message count for Pending tab
$unreadMessagesCount = 0;
$stmtMessages = $pdo->prepare("
    SELECT COUNT(*) as unread_count 
    FROM messages 
    WHERE receiver_id = ? AND read_at IS NULL
");
$stmtMessages->execute([$userId]);
$result = $stmtMessages->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $unreadMessagesCount = (int)$result['unread_count'];
}

// Get unread friend requests count for Friend tab
$unreadFriendRequestsCount = 0;
$stmtFriendRequests = $pdo->prepare("
    SELECT COUNT(*) as unread_count 
    FROM friends 
    WHERE friend_id = ? AND status = 'pending' AND seen = false
");
$stmtFriendRequests->execute([$userId]);
$result = $stmtFriendRequests->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $unreadFriendRequestsCount = (int)$result['unread_count'];
}

// Get unread group posts count for Groups tab
$unreadGroupPostsCount = 0;
$unreadGroupMessagesCount = 0;
$totalGroupUnreadCount = 0;

// First, get user's groups
$stmtGroups = $pdo->prepare("
    SELECT g.id FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'
");
$stmtGroups->execute(['user_id' => $userId]);
$userGroups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

if (!empty($userGroups)) {
    // Get the last time user visited each group (for posts)
    $userLastVisits = [];
    $stmtVisits = $pdo->prepare("
        SELECT group_id, last_visited FROM group_visits 
        WHERE user_id = :user_id
    ");
$stmtVisits->execute(['user_id' => $userId]);
    $visits = $stmtVisits->fetchAll(PDO::FETCH_ASSOC);
    foreach ($visits as $visit) {
        $userLastVisits[$visit['group_id']] = $visit['last_visited'];
    }
    
    // Count unread posts across all groups
    $totalUnreadPosts = 0;
    foreach ($userGroups as $group) {
        $groupId = $group['id'];
        $lastVisited = isset($userLastVisits[$groupId]) ? $userLastVisits[$groupId] : null;
        
        if ($lastVisited) {
            $stmtUnread = $pdo->prepare("
                SELECT COUNT(*) as unread_count 
                FROM posts 
                WHERE group_id = :group_id 
                AND created_at > :last_visited
                AND user_id != :user_id
            ");
            $stmtUnread->execute([
                'group_id' => $groupId,
                'last_visited' => $lastVisited,
                'user_id' => $userId
            ]);
        } else {
            $stmtUnread = $pdo->prepare("
                SELECT COUNT(*) as unread_count 
                FROM posts 
                WHERE group_id = :group_id 
                AND user_id != :user_id
            ");
            $stmtUnread->execute([
                'group_id' => $groupId,
                'user_id' => $userId
            ]);
        }
        
        $result = $stmtUnread->fetch(PDO::FETCH_ASSOC);
        $totalUnreadPosts += $result ? (int)$result['unread_count'] : 0;
    }
    
    $unreadGroupPostsCount = $totalUnreadPosts;
    
    // ========== COUNT UNREAD GROUP MESSAGES ==========
    $totalUnreadMessages = 0;
    foreach ($userGroups as $group) {
        $groupId = $group['id'];
        
        // Get the last time user read messages in this group
        $stmtLastRead = $pdo->prepare("
            SELECT last_read_at 
            FROM group_members 
            WHERE group_id = :group_id AND user_id = :user_id
        ");
        $stmtLastRead->execute(['group_id' => $groupId, 'user_id' => $userId]);
        $lastReadAt = $stmtLastRead->fetchColumn();
        
        if ($lastReadAt) {
            // Count unread messages (messages sent by others after user's last read time)
            $stmtUnreadMessages = $pdo->prepare("
                SELECT COUNT(*) 
                FROM group_messages 
                WHERE group_id = :group_id 
                AND user_id != :user_id 
                AND created_at > :last_read_at
            ");
            $stmtUnreadMessages->execute([
                'group_id' => $groupId,
                'user_id' => $userId,
                'last_read_at' => $lastReadAt
            ]);
            $messageCount = (int)$stmtUnreadMessages->fetchColumn();
        } else {
            // If user has never read, count all messages from others
            $stmtUnreadMessages = $pdo->prepare("
                SELECT COUNT(*) 
                FROM group_messages 
                WHERE group_id = :group_id 
                AND user_id != :user_id
            ");
            $stmtUnreadMessages->execute([
                'group_id' => $groupId,
                'user_id' => $userId
            ]);
            $messageCount = (int)$stmtUnreadMessages->fetchColumn();
        }
        
        $totalUnreadMessages += $messageCount;
    }
    
    $unreadGroupMessagesCount = $totalUnreadMessages;
    $totalGroupUnreadCount = $unreadGroupPostsCount + $unreadGroupMessagesCount;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Messages</title>
</head>
<body>
<div>
    <p style="text-align: center;font-weight: bolder;font-size: 25px;">All Messages</p>
    <nav class="tabs">
        <button id="posts-btn" onclick="showTab('posts')" class="active">
            Friend
            <?php if ($unreadFriendRequestsCount > 0): ?>
                <span class="unread-badge"><?= $unreadFriendRequestsCount > 99 ? '99+' : $unreadFriendRequestsCount ?></span>
            <?php endif; ?>
        </button>
        <button id="photos-btn" onclick="showTab('photos')">
            Pending
            <?php if ($unreadMessagesCount > 0): ?>
                <span class="unread-badge"><?= $unreadMessagesCount > 99 ? '99+' : $unreadMessagesCount ?></span>
            <?php endif; ?>
        </button>
        <button id="groups-btn" onclick="showTab('groups')">
            Groups
            <?php if ($totalGroupUnreadCount > 0): ?>
                <span class="unread-badge group-badge" data-posts="<?= $unreadGroupPostsCount ?>" data-messages="<?= $unreadGroupMessagesCount ?>">
                    <?= $totalGroupUnreadCount > 99 ? '99+' : $totalGroupUnreadCount ?>
                </span>
            <?php endif; ?>
        </button>
    </nav>
</div>

<div id="posts" class="tab-panel">
    <?php require_once "Friend2.php"; ?>
</div>
<div id="photos" class="tab-panel">
    <?php require_once "direct_message.php"; ?>
</div>
<div id="groups" class="tab-panel">
    <?php require_once "user_group.php"; ?>
</div>
</body>
</html>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
}

div {
    padding: 20px;
}

p {
    text-align: center;
    font-weight: 700;
    font-size: 28px;
    color: #7b68ee;
    margin-bottom: 20px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-top: 50px;
}

nav.tabs {
    margin: 25px 0;
    border-bottom: 2px solid rgba(123, 104, 238, 0.3);
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    padding: 0 20px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
}

nav.tabs button {
    background: none;
    border: none;
    padding: 12px 24px;
    font-size: 16px;
    cursor: pointer;
    color: white;
    border-radius: 10px;
    transition: all 0.3s ease;
    font-weight: 500;
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
}

nav.tabs button:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateY(-2px);
}

nav.tabs button.active {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    font-weight: 600;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
    border-bottom: none;
}

/* UNREAD BADGE STYLES FOR TABS */
.unread-badge {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    font-weight: bold;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    box-shadow: 0 3px 10px rgba(255, 107, 107, 0.3);
    border: 2px solid white;
    animation: badgePulse 2s infinite;
}

.unread-badge.group-badge {
    position: relative;
}

.unread-badge.group-badge::after {
    content: '';
    position: absolute;
    top: -2px;
    right: -2px;
    width: 8px;
    height: 8px;
    background: linear-gradient(135deg, #48bb78, #38a169);
    border-radius: 50%;
    border: 1px solid white;
    animation: messagePulse 1.5s infinite;
}

@keyframes messagePulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.3); opacity: 0.8; }
    100% { transform: scale(1); opacity: 1; }
}

.unread-badge.large {
    width: 26px;
    height: 26px;
    font-size: 13px;
}

.unread-badge.small {
    width: 20px;
    height: 20px;
    font-size: 11px;
}

@keyframes badgePulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.tab-panel {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    min-height: 400px;
    margin: 0 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    display: none;
}

.tab-panel:first-of-type {
    display: block;
}

/* Animation for tab transitions */
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

.tab-panel {
    animation: fadeInUp 0.4s ease-out;
}

/* Refresh button for updating counts */
.refresh-counts-btn {
    position: absolute;
    top: 15px;
    right: 15px;
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.refresh-counts-btn:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
}

.refresh-counts-btn:disabled {
    background: #718096;
    cursor: not-allowed;
    transform: none;
}

/* Loading spinner */
.loading-spinner {
    display: none;
    text-align: center;
    padding: 10px;
}

.spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid #7b68ee;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Group badge tooltip */
.group-badge-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.9);
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    margin-bottom: 5px;
}

.group-badge-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: rgba(0, 0, 0, 0.9);
}

.group-badge:hover .group-badge-tooltip {
    display: block;
}

/* Badge breakdown display */
.badge-breakdown {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.badge-breakdown-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
}

.badge-breakdown-item.posts {
    color: #7b68ee;
}

.badge-breakdown-item.messages {
    color: #ff6b6b;
}

/* Real-time notification */
.realtime-notification {
    position: fixed;
    top: 80px;
    right: 20px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 10px 15px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    z-index: 10000;
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.4);
    animation: slideInRight 0.3s ease-out;
    display: none;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 10px;
    }
    
    div {
        padding: 15px;
    }
    
    p {
        font-size: 24px;
        margin-bottom: 15px;
    }
    
    nav.tabs {
        margin: 20px 0;
        padding: 12px;
        gap: 10px;
    }
    
    nav.tabs button {
        padding: 10px 18px;
        font-size: 14px;
    }
    
    .tab-panel {
        padding: 20px;
        margin: 0 10px;
        min-height: 350px;
    }
    
    .refresh-counts-btn {
        position: relative;
        top: auto;
        right: auto;
        margin: 10px auto;
        display: block;
    }
    
    .unread-badge {
        width: 20px;
        height: 20px;
        font-size: 11px;
    }
    
    .realtime-notification {
        top: 70px;
        right: 10px;
        font-size: 12px;
        padding: 8px 12px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 5px;
    }
    
    div {
        padding: 10px;
    }
    
    p {
        font-size: 20px;
        margin-bottom: 12px;
    }
    
    nav.tabs {
        margin: 15px 0;
        padding: 10px;
        gap: 8px;
        flex-direction: column;
    }
    
    nav.tabs button {
        padding: 12px;
        font-size: 14px;
        width: 100%;
        text-align: center;
        justify-content: center;
    }
    
    .tab-panel {
        padding: 15px;
        margin: 0 5px;
        min-height: 300px;
    }
    
    .unread-badge {
        width: 18px;
        height: 18px;
        font-size: 10px;
    }
}

/* Focus states for accessibility */
nav.tabs button:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state */
.tab-panel.loading {
    position: relative;
    overflow: hidden;
}

.tab-panel.loading::after {
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
    nav.tabs {
        border-bottom: 2px solid #7b68ee;
    }
    
    .tab-panel {
        border: 2px solid #7b68ee;
    }
    
    .unread-badge {
        border: 2px solid black;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .tab-panel,
    nav.tabs button,
    .unread-badge {
        transition: none;
        animation: none;
    }
    
    nav.tabs button:hover {
        transform: none;
    }
    
    .tab-panel.loading::after {
        animation: none;
    }
    
    .unread-badge.group-badge::after {
        animation: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    }
    
    .tab-panel {
        background: rgba(45, 55, 72, 0.95);
        color: #e2e8f0;
    }
    
    nav.tabs {
        background: rgba(45, 55, 72, 0.8);
        border-color: rgba(123, 104, 238, 0.5);
    }
    
    .refresh-counts-btn {
        background: linear-gradient(135deg, #2f855a, #276749);
    }
}

/* Scrollbar styling for tab panels */
.tab-panel::-webkit-scrollbar {
    width: 8px;
}

.tab-panel::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 4px;
}

.tab-panel::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 4px;
}

.tab-panel::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}

/* Ensure content within tab panels is properly styled */
.tab-panel > * {
    max-width: 100%;
}

/* Header and footer integration */
header + div {
    margin-top: 80px;
}

/* Smooth transitions for better UX */
.tab-panel {
    transition: opacity 0.3s ease;
}

/* Active state enhancements */
nav.tabs button.active {
    position: relative;
    overflow: hidden;
}

nav.tabs button.active::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent);
    border-radius: 8px;
}

/* Badge update animation */
.badge-updated {
    animation: badgeUpdate 0.5s ease-in-out;
}

@keyframes badgeUpdate {
    0% { transform: scale(1); }
    50% { transform: scale(1.3); }
    100% { transform: scale(1); }
}

/* Tab count wrapper */
.tab-count-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Auto-refresh notification */
.auto-refresh-notice {
    text-align: center;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
    margin-top: 5px;
    font-style: italic;
}
</style>
<script>
// Global variables for counts
let currentUnreadCounts = {
    friend: <?= $unreadFriendRequestsCount ?>,
    pending: <?= $unreadMessagesCount ?>,
    groups: <?= $totalGroupUnreadCount ?>,
    groupPosts: <?= $unreadGroupPostsCount ?>,
    groupMessages: <?= $unreadGroupMessagesCount ?>
};

function showTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(el => el.style.display = 'none');
    document.getElementById(tab).style.display = 'block';
    document.querySelectorAll('nav.tabs button').forEach(btn => btn.classList.remove('active'));
    document.getElementById(tab + '-btn').classList.add('active');
    
    // Mark as seen when switching to certain tabs
    if (tab === 'posts' && currentUnreadCounts.friend > 0) {
        markFriendRequestsAsSeen();
    } else if (tab === 'photos' && currentUnreadCounts.pending > 0) {
        // Don't mark messages as read here - let direct_message.php handle it
    } else if (tab === 'groups' && currentUnreadCounts.groups > 0) {
        // Groups will be marked as visited when user clicks on individual groups
    }
    
    // Store current active tab
    localStorage.setItem('activeTab', tab);
}

// Function to reload all unread counts
async function reloadAllCounts() {
    const refreshBtn = document.getElementById('refreshCountsBtn');
    const loadingSpinner = document.getElementById('loadingSpinner');
    
    if (refreshBtn) refreshBtn.disabled = true;
    if (loadingSpinner) loadingSpinner.style.display = 'block';
    
    try {
        const response = await fetch('get_all_unread_counts.php');
        const data = await response.json();
        
        if (data.success) {
            // Update Friend tab count
            updateTabCount('posts-btn', data.friend_requests || 0, 'Friend', 'friend');
            currentUnreadCounts.friend = data.friend_requests || 0;
            
            // Update Pending tab count
            updateTabCount('photos-btn', data.messages || 0, 'Pending', 'pending');
            currentUnreadCounts.pending = data.messages || 0;
            
            // Update Groups tab count with detailed info
            const groupPosts = data.group_posts || 0;
            const groupMessages = data.group_messages || 0;
            const totalGroups = groupPosts + groupMessages;
            
            updateTabCount('groups-btn', totalGroups, 'Groups', 'groups', {
                posts: groupPosts,
                messages: groupMessages
            });
            
            currentUnreadCounts.groups = totalGroups;
            currentUnreadCounts.groupPosts = groupPosts;
            currentUnreadCounts.groupMessages = groupMessages;
            
            // Show real-time notification if there are new messages
            if (groupMessages > 0 && data.new_messages) {
                showRealtimeNotification(`💬 ${groupMessages} new group message${groupMessages > 1 ? 's' : ''}`);
            }
            
            // Show notification
            showNotification('Counts updated successfully!', 'success');
        } else {
            showNotification('Failed to update counts', 'error');
        }
    } catch (error) {
        console.error('Error reloading counts:', error);
        showNotification('Network error', 'error');
    } finally {
        if (refreshBtn) refreshBtn.disabled = false;
        if (loadingSpinner) loadingSpinner.style.display = 'none';
    }
}

// Function to update a specific tab's badge
function updateTabCount(buttonId, count, tabName, tabType, details = {}) {
    const button = document.getElementById(buttonId);
    if (!button) return;
    
    // Find or create the badge
    let badge = button.querySelector('.unread-badge');
    
    if (count > 0) {
        const badgeText = count > 99 ? '99+' : count.toString();
        
        if (badge) {
            // Update existing badge
            badge.textContent = badgeText;
            badge.classList.add('badge-updated');
            setTimeout(() => badge.classList.remove('badge-updated'), 500);
            
            // Update badge size class
            badge.className = 'unread-badge';
            if (tabType === 'groups') {
                badge.classList.add('group-badge');
                badge.setAttribute('data-posts', details.posts || 0);
                badge.setAttribute('data-messages', details.messages || 0);
                
                // Add tooltip for group badge
                if (!badge.querySelector('.group-badge-tooltip')) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'group-badge-tooltip';
                    tooltip.innerHTML = `
                        <div class="badge-breakdown">
                            <div class="badge-breakdown-item posts">
                                📝 ${details.posts || 0} new posts
                            </div>
                            <div class="badge-breakdown-item messages">
                                💬 ${details.messages || 0} new messages
                            </div>
                        </div>
                    `;
                    badge.appendChild(tooltip);
                }
            }
            
            if (count > 50) badge.classList.add('large');
            else if (count < 5) badge.classList.add('small');
        } else {
            // Create new badge
            badge = document.createElement('span');
            badge.className = 'unread-badge';
            
            if (tabType === 'groups') {
                badge.classList.add('group-badge');
                badge.setAttribute('data-posts', details.posts || 0);
                badge.setAttribute('data-messages', details.messages || 0);
                
                // Add tooltip for group badge
                const tooltip = document.createElement('div');
                tooltip.className = 'group-badge-tooltip';
                tooltip.innerHTML = `
                    <div class="badge-breakdown">
                        <div class="badge-breakdown-item posts">
                            📝 ${details.posts || 0} new posts
                        </div>
                        <div class="badge-breakdown-item messages">
                            💬 ${details.messages || 0} new messages
                        </div>
                    </div>
                `;
                badge.appendChild(tooltip);
            }
            
            if (count > 50) badge.classList.add('large');
            else if (count < 5) badge.classList.add('small');
            
            badge.textContent = badgeText;
            button.appendChild(badge);
        }
        
        // Update button text to include count
        const baseText = tabName;
        const currentText = button.textContent.replace(/\(.*?\)/, '').trim();
        if (!currentText.includes(baseText)) {
            button.textContent = baseText;
            button.appendChild(badge);
        }
    } else if (badge) {
        // Remove badge if count is 0
        badge.remove();
        
        // Remove count from button text
        const baseText = tabName;
        button.textContent = baseText;
    }
}

// Function to mark friend requests as seen
async function markFriendRequestsAsSeen() {
    try {
        await fetch('mark_friend_requests_seen.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        });
        
        // Update local count immediately for better UX
        currentUnreadCounts.friend = 0;
        updateTabCount('posts-btn', 0, 'Friend', 'friend');
        
    } catch (error) {
        console.error('Error marking friend requests as seen:', error);
    }
}

// Function to show notification
function showNotification(message, type) {
    // Remove existing notification
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create new notification
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        background: ${type === 'success' ? '#48bb78' : '#f56565'};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10000;
        animation: slideIn 0.3s ease-out;
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Function to show real-time notification for new group messages
function showRealtimeNotification(message) {
    // Remove existing real-time notification
    const existingNotification = document.querySelector('.realtime-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create new notification
    const notification = document.createElement('div');
    notification.className = 'realtime-notification';
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Animation for notifications
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Auto-refresh counts every 30 seconds for real-time updates
function initAutoRefresh() {
    // Initial refresh after 5 seconds
    setTimeout(reloadAllCounts, 5000);
    
    // Then refresh every 30 seconds
    setInterval(reloadAllCounts, 30000); // 30 seconds
    
    // Also refresh when page becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            reloadAllCounts();
        }
    });
    
    // Refresh when returning via browser back button
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            reloadAllCounts();
        }
    });
    
    // Listen for storage events (new posts from other tabs)
    window.addEventListener('storage', (event) => {
        if (event.key === 'new_group_post' || event.key === 'new_group_message') {
            reloadAllCounts();
        }
    });
    
    // Listen for messages from iframes (user_group.php)
    window.addEventListener('message', (event) => {
        if (event.data && event.data.type === 'NEW_GROUP_UPDATE') {
            reloadAllCounts();
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Restore active tab if saved
    const savedTab = localStorage.getItem('activeTab');
    if (savedTab && ['posts', 'photos', 'groups'].includes(savedTab)) {
        showTab(savedTab);
    } else {
        showTab('posts');
    }
    
    // Initialize auto-refresh
    initAutoRefresh();
    
    // Add refresh button to the page
    const tabsContainer = document.querySelector('nav.tabs');
    if (tabsContainer) {
        const refreshBtn = document.createElement('button');
        refreshBtn.id = 'refreshCountsBtn';
        refreshBtn.className = 'refresh-counts-btn';
        refreshBtn.innerHTML = '🔄 Refresh Counts';
        refreshBtn.onclick = reloadAllCounts;
        
        // Insert after tabs
        tabsContainer.parentNode.insertBefore(refreshBtn, tabsContainer.nextSibling);
        
        // Add loading spinner
        const loadingSpinner = document.createElement('div');
        loadingSpinner.id = 'loadingSpinner';
        loadingSpinner.className = 'loading-spinner';
        loadingSpinner.innerHTML = '<div class="spinner"></div>';
        refreshBtn.parentNode.insertBefore(loadingSpinner, refreshBtn.nextSibling);
        
        // Add auto-refresh notice
        const notice = document.createElement('div');
        notice.className = 'auto-refresh-notice';
        notice.textContent = 'Counts auto-refresh every 30 seconds';
        loadingSpinner.parentNode.insertBefore(notice, loadingSpinner.nextSibling);
    }
    
    // Check for new group messages on page load
    if (currentUnreadCounts.groupMessages > 0) {
        showRealtimeNotification(`💬 ${currentUnreadCounts.groupMessages} new group message${currentUnreadCounts.groupMessages > 1 ? 's' : ''}`);
    }
});
</script>