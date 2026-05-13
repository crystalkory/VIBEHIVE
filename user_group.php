<?php

if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";
$userId = $_SESSION['user_id'];

// Fetch groups the user has joined
$stmt = $pdo->prepare("
    SELECT g.*, 
    gm.status,
    (g.creator_id = :user_id) AS is_admin,
    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id AND status = 'approved') AS member_count
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = :user_id AND gm.status = 'approved'
    ORDER BY g.created_at DESC
");
$stmt->execute(['user_id' => $userId]);
$userGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Store group IDs for quick access
$groupIds = array_column($userGroups, 'id');

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get fresh unread counts for all groups (optimized single query)
    if (isset($_POST['action']) && $_POST['action'] === 'get_unread_counts') {
        $groupCounts = [];
        
        if (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            
            // Get last visited times for posts
            $stmtVisits = $pdo->prepare("
                SELECT group_id, last_visited 
                FROM group_visits 
                WHERE user_id = ? AND group_id IN ($placeholders)
            ");
            $stmtVisits->execute(array_merge([$userId], $groupIds));
            $visits = $stmtVisits->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Get last read times for messages
            $stmtMessages = $pdo->prepare("
                SELECT group_id, last_read_at 
                FROM group_members 
                WHERE user_id = ? AND group_id IN ($placeholders)
            ");
            $stmtMessages->execute(array_merge([$userId], $groupIds));
            $messageReadTimes = $stmtMessages->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Get unread posts count for all groups in one query
            $postCounts = [];
            foreach ($groupIds as $groupId) {
                $lastVisited = $visits[$groupId] ?? null;
                
                if ($lastVisited) {
                    $stmtUnreadPosts = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM posts 
                        WHERE group_id = ? 
                        AND created_at > ?
                        AND user_id != ?
                    ");
                    $stmtUnreadPosts->execute([$groupId, $lastVisited, $userId]);
                    $postCounts[$groupId] = (int)$stmtUnreadPosts->fetchColumn();
                } else {
                    $stmtUnreadPosts = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM posts 
                        WHERE group_id = ? 
                        AND user_id != ?
                    ");
                    $stmtUnreadPosts->execute([$groupId, $userId]);
                    $postCounts[$groupId] = (int)$stmtUnreadPosts->fetchColumn();
                }
            }
            
            // Get unread messages count for all groups in one query
            $messageCounts = [];
            foreach ($groupIds as $groupId) {
                $lastReadAt = $messageReadTimes[$groupId] ?? null;
                
                if ($lastReadAt) {
                    $stmtUnreadMessages = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM group_messages 
                        WHERE group_id = ? 
                        AND user_id != ? 
                        AND created_at > ?
                    ");
                    $stmtUnreadMessages->execute([$groupId, $userId, $lastReadAt]);
                    $messageCounts[$groupId] = (int)$stmtUnreadMessages->fetchColumn();
                } else {
                    $stmtUnreadMessages = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM group_messages 
                        WHERE group_id = ? 
                        AND user_id != ?
                    ");
                    $stmtUnreadMessages->execute([$groupId, $userId]);
                    $messageCounts[$groupId] = (int)$stmtUnreadMessages->fetchColumn();
                }
            }
            
            // Combine counts
            foreach ($groupIds as $groupId) {
                $postCount = $postCounts[$groupId] ?? 0;
                $messageCount = $messageCounts[$groupId] ?? 0;
                $totalCount = $postCount + $messageCount;
                
                $groupCounts[] = [
                    'group_id' => $groupId,
                    'unread_count' => $totalCount,
                    'post_count' => $postCount,
                    'message_count' => $messageCount
                ];
            }
        }
        
        echo json_encode(['success' => true, 'counts' => $groupCounts]);
        exit;
    }
    
    // Mark group as visited (for posts only)
    if (isset($_POST['action']) && $_POST['action'] === 'mark_group_visited' && isset($_POST['group_id'])) {
        $groupId = (int)$_POST['group_id'];
        
        // Check if user is a member of this group
        $stmtCheck = $pdo->prepare("
            SELECT 1 FROM group_members 
            WHERE group_id = ? AND user_id = ? AND status = 'approved'
        ");
        $stmtCheck->execute([$groupId, $userId]);
        $isMember = (bool)$stmtCheck->fetchColumn();
        
        if ($isMember) {
            // Update or insert visit record for posts
            $stmtUpsert = $pdo->prepare("
                INSERT INTO group_visits (user_id, group_id, last_visited) 
                VALUES (?, ?, NOW())
                ON CONFLICT (user_id, group_id) 
                DO UPDATE SET last_visited = NOW()
            ");
            $stmtUpsert->execute([$userId, $groupId]);
            
            // Get updated count for this group
            $stmtVisits = $pdo->prepare("SELECT last_visited FROM group_visits WHERE user_id = ? AND group_id = ?");
            $stmtVisits->execute([$userId, $groupId]);
            $lastVisited = $stmtVisits->fetchColumn();
            
            // Get post unread count after marking as visited
            $postCount = 0; // Should be 0 after marking as visited
            
            // Get message unread count (unchanged)
            $stmtMessages = $pdo->prepare("SELECT last_read_at FROM group_members WHERE user_id = ? AND group_id = ?");
            $stmtMessages->execute([$userId, $groupId]);
            $lastReadAt = $stmtMessages->fetchColumn();
            
            $messageCount = 0;
            if ($lastReadAt) {
                $stmtUnreadMessages = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM group_messages 
                    WHERE group_id = ? 
                    AND user_id != ? 
                    AND created_at > ?
                ");
                $stmtUnreadMessages->execute([$groupId, $userId, $lastReadAt]);
                $messageCount = (int)$stmtUnreadMessages->fetchColumn();
            } else {
                $stmtUnreadMessages = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM group_messages 
                    WHERE group_id = ? 
                    AND user_id != ?
                ");
                $stmtUnreadMessages->execute([$groupId, $userId]);
                $messageCount = (int)$stmtUnreadMessages->fetchColumn();
            }
            
            $totalCount = $postCount + $messageCount;
            
            echo json_encode([
                'success' => true,
                'updated_count' => [
                    'group_id' => $groupId,
                    'unread_count' => $totalCount,
                    'post_count' => $postCount,
                    'message_count' => $messageCount
                ]
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Not a member']);
            exit;
        }
    }
    
    // Check for new posts in specific group (called from group.php when new post is created)
    if (isset($_POST['action']) && $_POST['action'] === 'check_new_posts' && isset($_POST['group_id'])) {
        $groupId = (int)$_POST['group_id'];
        
        // Get last visited time for this group
        $stmtVisits = $pdo->prepare("SELECT last_visited FROM group_visits WHERE user_id = ? AND group_id = ?");
        $stmtVisits->execute([$userId, $groupId]);
        $lastVisited = $stmtVisits->fetchColumn();
        
        // Count new posts since last visit
        $newPostsCount = 0;
        if ($lastVisited) {
            $stmtNewPosts = $pdo->prepare("
                SELECT COUNT(*) 
                FROM posts 
                WHERE group_id = ? 
                AND created_at > ?
                AND user_id != ?
            ");
            $stmtNewPosts->execute([$groupId, $lastVisited, $userId]);
            $newPostsCount = (int)$stmtNewPosts->fetchColumn();
        } else {
            $stmtNewPosts = $pdo->prepare("
                SELECT COUNT(*) 
                FROM posts 
                WHERE group_id = ? 
                AND user_id != ?
            ");
            $stmtNewPosts->execute([$groupId, $userId]);
            $newPostsCount = (int)$stmtNewPosts->fetchColumn();
        }
        
        echo json_encode([
            'success' => true,
            'new_posts' => $newPostsCount,
            'group_id' => $groupId
        ]);
        exit;
    }
}

// Initial load - get unread counts for display
$initialCounts = [];
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    
    // Get last visited times
    $stmtVisits = $pdo->prepare("
        SELECT group_id, last_visited 
        FROM group_visits 
        WHERE user_id = ? AND group_id IN ($placeholders)
    ");
    $stmtVisits->execute(array_merge([$userId], $groupIds));
    $visits = $stmtVisits->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get last read times for messages
    $stmtMessages = $pdo->prepare("
        SELECT group_id, last_read_at 
        FROM group_members 
        WHERE user_id = ? AND group_id IN ($placeholders)
    ");
    $stmtMessages->execute(array_merge([$userId], $groupIds));
    $messageReadTimes = $stmtMessages->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get counts for each group
    foreach ($groupIds as $groupId) {
        $lastVisited = $visits[$groupId] ?? null;
        $lastReadAt = $messageReadTimes[$groupId] ?? null;
        
        // Get post count
        if ($lastVisited) {
            $stmtUnreadPosts = $pdo->prepare("
                SELECT COUNT(*) 
                FROM posts 
                WHERE group_id = ? 
                AND created_at > ?
                AND user_id != ?
            ");
            $stmtUnreadPosts->execute([$groupId, $lastVisited, $userId]);
            $postCount = (int)$stmtUnreadPosts->fetchColumn();
        } else {
            $stmtUnreadPosts = $pdo->prepare("
                SELECT COUNT(*) 
                FROM posts 
                WHERE group_id = ? 
                AND user_id != ?
            ");
            $stmtUnreadPosts->execute([$groupId, $userId]);
            $postCount = (int)$stmtUnreadPosts->fetchColumn();
        }
        
        // Get message count
        if ($lastReadAt) {
            $stmtUnreadMessages = $pdo->prepare("
                SELECT COUNT(*) 
                FROM group_messages 
                WHERE group_id = ? 
                AND user_id != ? 
                AND created_at > ?
            ");
            $stmtUnreadMessages->execute([$groupId, $userId, $lastReadAt]);
            $messageCount = (int)$stmtUnreadMessages->fetchColumn();
        } else {
            $stmtUnreadMessages = $pdo->prepare("
                SELECT COUNT(*) 
                FROM group_messages 
                WHERE group_id = ? 
                AND user_id != ?
            ");
            $stmtUnreadMessages->execute([$groupId, $userId]);
            $messageCount = (int)$stmtUnreadMessages->fetchColumn();
        }
        
        $initialCounts[$groupId] = [
            'total' => $postCount + $messageCount,
            'posts' => $postCount,
            'messages' => $messageCount
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Groups - Fbclone</title>
<style>
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 900px;
    margin: 20px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    color: #333;
}
h1 {
    text-align: center;
    margin-bottom: 25px;
    color: white;
    font-size: 28px;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    margin-top: 50px;
}
.group-list {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    justify-content: center;
    margin-bottom: 50px;
}
.group-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    width: 280px;
    cursor: pointer;
    transition: all 0.3s ease;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
    border: 2px solid #7b68ee;
}
.group-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
}
.group-cover {
    height: 140px;
    overflow: hidden;
    position: relative;
}
.group-cover img {
    width: 100%;
    height: 140px;
    object-fit: cover;
}
.group-profile {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    margin: -35px 20px 10px 20px;
    border: 3px solid white;
    object-fit: cover;
    background: #ccc;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.3);
    position: relative;
}
.group-info {
    padding: 0 20px 20px 20px;
    flex-grow: 1;
}
.group-name {
    font-weight: bold;
    font-size: 18px;
    margin-bottom: 5px;
    color: #7b68ee;
    font-weight: 700;
}
.group-privacy {
    font-size: 13px;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
    margin-left: 8px;
    color: white;
    vertical-align: middle;
}
.group-privacy.public {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
}
.group-privacy.private {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
}
.group-members {
    font-size: 14px;
    color: #7b68ee;
    margin-bottom: 10px;
    font-weight: 600;
}
.group-counts {
    font-size: 13px;
    color: #666;
    margin-bottom: 8px;
    line-height: 1.4;
}
.group-counts span {
    display: inline-block;
    margin-right: 10px;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
    transition: all 0.3s ease;
}
.posts-count {
    background: rgba(123, 104, 238, 0.1);
    color: #7b68ee;
}
.posts-count.new-post {
    animation: newPostPulse 2s ease-in-out;
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}
.messages-count {
    background: rgba(255, 107, 107, 0.1);
    color: #ff6b6b;
}
.admin-badge {
    display: inline-block;
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    font-weight: 600;
    padding: 2px 6px;
    font-size: 13px;
    border-radius: 4px;
    margin-left: 6px;
    box-shadow: 0 2px 8px rgba(72, 187, 120, 0.3);
}

/* UNREAD BADGE STYLES */
.unread-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    font-weight: bold;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    box-shadow: 0 3px 10px rgba(255, 107, 107, 0.3);
    border: 2px solid white;
    z-index: 10;
    animation: badgePulse 2s infinite;
    transition: all 0.3s ease;
}

.unread-badge.profile-badge {
    top: -5px;
    right: -5px;
    width: 22px;
    height: 22px;
    font-size: 11px;
}

.unread-badge.large {
    width: 28px;
    height: 28px;
    font-size: 13px;
}

.unread-badge.small {
    width: 20px;
    height: 20px;
    font-size: 10px;
}

.unread-badge.new-notification {
    animation: newNotification 0.5s ease-out;
    background: linear-gradient(135deg, #ff3860, #ff1a4b);
}

@keyframes badgePulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

@keyframes newPostPulse {
    0% { transform: scale(1); background: rgba(123, 104, 238, 0.1); }
    50% { transform: scale(1.1); background: linear-gradient(135deg, #ff6b6b, #ee5a52); }
    100% { transform: scale(1); background: linear-gradient(135deg, #ff6b6b, #ee5a52); }
}

@keyframes newNotification {
    0% { transform: scale(1); }
    25% { transform: scale(1.3); }
    50% { transform: scale(1.1); }
    75% { transform: scale(1.2); }
    100% { transform: scale(1); }
}

/* Animation for page load */
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

.group-card {
    animation: fadeInUp 0.6s ease-out;
}

/* Refresh button */
.refresh-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    margin: 10px auto;
    display: block;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.refresh-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.refresh-btn:disabled {
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

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 10px auto;
    }
    
    .group-list {
        gap: 15px;
    }
    
    .group-card {
        width: 100%;
        max-width: 320px;
    }
    
    h1 {
        font-size: 24px;
        margin-bottom: 20px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    .group-card {
        margin-bottom: 15px;
    }
    
    .group-profile {
        width: 60px;
        height: 60px;
        margin: -30px 15px 10px 15px;
    }
    
    .group-info {
        padding: 0 15px 15px 15px;
    }
    
    .group-name {
        font-size: 16px;
    }
    
    .unread-badge {
        width: 22px;
        height: 22px;
        font-size: 11px;
    }
    
    .group-counts {
        font-size: 12px;
    }
}

/* Real-time update notification */
.update-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: none;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
    animation: slideInRight 0.3s ease-out;
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
</style>
<script>
class GroupUnreadManager {
    constructor() {
        this.isRefreshing = false;
        this.lastUpdateTime = Date.now();
        this.cache = {}; // Cache for group data
        this.updateInterval = 3000; // Update every 3 seconds
        this.init();
    }
    
    init() {
        // Start auto-refresh
        this.startAutoRefresh();
        
        // Attach click handlers
        this.attachClickHandlers();
        
        // Handle visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.refreshCounts();
            }
        });
        
        // Handle page show (browser back button)
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                this.refreshCounts();
            }
        });
        
        // Listen for post creation events from other tabs/windows
        this.setupCrossTabCommunication();
    }
    
    startAutoRefresh() {
        // Refresh immediately on load
        setTimeout(() => this.refreshCounts(), 500);
        
        // Then refresh every 3 seconds
        setInterval(() => this.refreshCounts(), this.updateInterval);
    }
    
    async refreshCounts() {
        // Don't refresh if already refreshing
        if (this.isRefreshing) return;
        
        this.isRefreshing = true;
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_unread_counts'
            });
            
            if (!response.ok) throw new Error('Network response was not ok');
            
            const data = await response.json();
            
            if (data.success) {
                // Apply updates with smooth transitions
                this.applyUpdates(data.counts);
                this.lastUpdateTime = Date.now();
            }
        } catch (error) {
            console.error('Error refreshing unread counts:', error);
        } finally {
            this.isRefreshing = false;
        }
    }
    
    applyUpdates(counts) {
        counts.forEach(countInfo => {
            const groupId = countInfo.group_id;
            const totalUnreadCount = countInfo.unread_count;
            const postCount = countInfo.post_count || 0;
            const messageCount = countInfo.message_count || 0;
            
            // Get previous counts from cache
            const previous = this.cache[groupId];
            
            // Store in cache
            this.cache[groupId] = countInfo;
            
            // Find the group card
            const groupCard = document.querySelector(`.group-card[data-group-id="${groupId}"]`);
            if (!groupCard) return;
            
            // Check if post count increased (new post was created)
            if (previous && postCount > previous.post_count) {
                this.showNewPostNotification(groupCard, postCount - previous.post_count);
            }
            
            // Update the UI
            this.updateGroupCard(groupCard, {
                total: totalUnreadCount,
                posts: postCount,
                messages: messageCount
            });
        });
    }
    
    showNewPostNotification(groupCard, newPostsCount) {
        // Highlight the posts count
        const postsCountElement = groupCard.querySelector('.posts-count');
        if (postsCountElement) {
            postsCountElement.classList.add('new-post');
            setTimeout(() => {
                postsCountElement.classList.remove('new-post');
            }, 2000);
        }
        
        // Show notification badge animation
        const badge = groupCard.querySelector('.unread-badge:not(.profile-badge)');
        if (badge) {
            badge.classList.add('new-notification');
            setTimeout(() => {
                badge.classList.remove('new-notification');
            }, 500);
        }
        
        // Show update notification
        this.showNotification(`📝 ${newPostsCount} new post${newPostsCount > 1 ? 's' : ''} in ${groupCard.querySelector('.group-name').textContent}`);
    }
    
    showNotification(message) {
        // Create or update notification element
        let notification = document.getElementById('updateNotification');
        if (!notification) {
            notification = document.createElement('div');
            notification.id = 'updateNotification';
            notification.className = 'update-notification';
            document.body.appendChild(notification);
        }
        
        notification.textContent = message;
        notification.style.display = 'block';
        
        // Hide after 3 seconds
        setTimeout(() => {
            notification.style.display = 'none';
        }, 3000);
    }
    
    updateGroupCard(groupCard, counts) {
        const existingBadge = groupCard.querySelector('.unread-badge:not(.profile-badge)');
        const existingProfileBadge = groupCard.querySelector('.unread-badge.profile-badge');
        const countsElement = groupCard.querySelector('.group-counts');
        
        // Update counts display with smooth transition
        if (countsElement) {
            // Only update if values changed
            const currentPosts = parseInt(countsElement.querySelector('.posts-count')?.textContent?.match(/\d+/)?.[0] || '0');
            const currentMessages = parseInt(countsElement.querySelector('.messages-count')?.textContent?.match(/\d+/)?.[0] || '0');
            
            if (currentPosts !== counts.posts || currentMessages !== counts.messages) {
                countsElement.innerHTML = `
                    <span class="posts-count" title="New posts">📝 ${counts.posts}</span>
                    <span class="messages-count" title="New chat messages">💬 ${counts.messages}</span>
                `;
            }
        }
        
        // Update or create main badge
        if (counts.total > 0) {
            const badgeText = counts.total > 99 ? '99+' : counts.total.toString();
            
            if (existingBadge) {
                // Update existing badge if count changed
                if (existingBadge.textContent !== badgeText) {
                    existingBadge.textContent = badgeText;
                    existingBadge.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        existingBadge.style.transform = 'scale(1)';
                    }, 200);
                }
            } else {
                // Create new badge
                const newBadge = document.createElement('div');
                newBadge.className = 'unread-badge';
                newBadge.textContent = badgeText;
                groupCard.querySelector('.group-cover').appendChild(newBadge);
            }
            
            // Update or create profile badge
            if (existingProfileBadge) {
                if (existingProfileBadge.textContent !== badgeText) {
                    existingProfileBadge.textContent = badgeText;
                }
            } else {
                const profileImg = groupCard.querySelector('.group-profile');
                const profileBadge = document.createElement('div');
                profileBadge.className = 'unread-badge profile-badge';
                profileBadge.textContent = badgeText;
                profileImg.parentNode.insertBefore(profileBadge, profileImg.nextSibling);
            }
        } else {
            // Remove badges if count is 0
            [existingBadge, existingProfileBadge].forEach(badge => {
                if (badge) badge.remove();
            });
        }
    }
    
    async handleGroupClick(groupId) {
        // Clear unread posts count immediately (visual feedback)
        const groupCard = document.querySelector(`.group-card[data-group-id="${groupId}"]`);
        if (groupCard) {
            this.clearPostCounts(groupCard);
        }
        
        // Mark group as visited for posts (in background)
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=mark_group_visited&group_id=${groupId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.updated_count) {
                // Update the cache with new counts
                this.cache[groupId] = data.updated_count;
            }
        })
        .catch(console.error);
        
        // Redirect after short delay for better UX
        setTimeout(() => {
            window.location.href = `group.php?id=${groupId}`;
        }, 50);
    }
    
    clearPostCounts(groupCard) {
        // Update counts display to show only messages count
        const countsElement = groupCard.querySelector('.group-counts');
        if (countsElement) {
            const currentMessages = parseInt(countsElement.querySelector('.messages-count')?.textContent?.match(/\d+/)?.[0] || '0');
            countsElement.innerHTML = `
                <span class="posts-count" title="New posts">📝 0</span>
                <span class="messages-count" title="New chat messages">💬 ${currentMessages}</span>
            `;
        }
        
        // Update total badge if needed
        const existingBadge = groupCard.querySelector('.unread-badge:not(.profile-badge)');
        const existingProfileBadge = groupCard.querySelector('.unread-badge.profile-badge');
        const currentMessages = parseInt(countsElement?.querySelector('.messages-count')?.textContent?.match(/\d+/)?.[0] || '0');
        
        if (currentMessages > 0) {
            // Only show message count in badge
            const badgeText = currentMessages > 99 ? '99+' : currentMessages.toString();
            
            if (existingBadge) {
                existingBadge.textContent = badgeText;
            }
            if (existingProfileBadge) {
                existingProfileBadge.textContent = badgeText;
            }
        } else {
            // Remove badges if no unread items
            [existingBadge, existingProfileBadge].forEach(badge => {
                if (badge) badge.remove();
            });
        }
    }
    
    attachClickHandlers() {
        document.querySelectorAll('.group-card').forEach(card => {
            card.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const groupId = card.dataset.groupId;
                if (groupId) {
                    this.handleGroupClick(groupId);
                }
            });
        });
    }
    
    setupCrossTabCommunication() {
        // Listen for storage events (other tabs)
        window.addEventListener('storage', (event) => {
            if (event.key === 'new_group_post') {
                try {
                    const data = JSON.parse(event.newValue);
                    if (data && data.group_id) {
                        // Refresh counts when new post is created in another tab
                        this.refreshCounts();
                    }
                } catch (e) {
                    console.error('Error parsing storage event:', e);
                }
            }
        });
        
        // Listen for message events (from iframes or service workers)
        window.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'NEW_GROUP_POST') {
                this.refreshCounts();
            }
        });
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.groupManager = new GroupUnreadManager();
});

// Function to manually refresh (for refresh button)
function reloadUnreadCounts() {
    if (window.groupManager) {
        window.groupManager.refreshCounts();
    }
}
</script>
</head>
<body>

<h1 style="color: #7b68ee">Groups I Joined</h1>

<button id="refreshBtn" class="refresh-btn" >
   <a href="create_group.php" style="text-decoration: none;font-weight: bolder;color: white;"> 🔄 Create Group </a>
</button>

<div id="loadingSpinner" class="loading-spinner">
    <div class="spinner"></div>
</div>

<?php if (empty($userGroups)): ?>
    <p style="text-align:center; color: white; padding: 40px;">
        You have not joined any groups yet.
    </p>
<?php else: ?>
<div class="group-list">
    <?php foreach ($userGroups as $group): 
        $groupId = $group['id'];
        $counts = $initialCounts[$groupId] ?? ['total' => 0, 'posts' => 0, 'messages' => 0];
        $totalUnreadCount = $counts['total'];
        
        $privacyClass = strtolower($group['privacy_setting'] ?? 'public');
        if ($privacyClass !== 'private') {
            $privacyClass = 'public';
        }
        
        // Determine badge class based on total unread count
        $badgeClass = '';
        if ($totalUnreadCount > 0) {
            if ($totalUnreadCount > 50) $badgeClass = 'large';
            elseif ($totalUnreadCount < 5) $badgeClass = 'small';
        }
    ?>
        <div class="group-card" data-group-id="<?= htmlspecialchars($groupId) ?>">
            <div class="group-cover">
                <img src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_cover.jpg') ?>" alt="Group Cover" />
                <?php if ($totalUnreadCount > 0): ?>
                    <div class="unread-badge <?= $badgeClass ?>">
                        <?= $totalUnreadCount > 99 ? '99+' : $totalUnreadCount ?>
                    </div>
                <?php endif; ?>
            </div>
            <img class="group-profile" src="<?= htmlspecialchars($group['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Group Profile Picture" />
            <?php if ($totalUnreadCount > 0): ?>
                <div class="unread-badge profile-badge <?= $badgeClass ?>">
                    <?= $totalUnreadCount > 99 ? '99+' : $totalUnreadCount ?>
                </div>
            <?php endif; ?>
            <div class="group-info">
                <div class="group-name" style="color: #7b68ee;">
                    <?= htmlspecialchars($group['name']) ?>
                    <span class="group-privacy <?= $privacyClass ?>">
                        <?= ucfirst($privacyClass) ?>
                    </span>
                    <?php if ($group['is_admin']): ?>
                        <span class="admin-badge">Admin</span>
                    <?php endif; ?>
                </div>
                <div class="group-members" style="color: #7b68ee;">
                    <?= htmlspecialchars($group['member_count']) ?> member<?= $group['member_count'] != 1 ? 's' : '' ?>
                    <?php if ($totalUnreadCount > 0): ?>
                        <span style="color: #ff6b6b; font-weight: bold; margin-left: 10px;">
                            (<?= $totalUnreadCount ?> new)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="group-counts">
                    <span class="posts-count" title="New posts">📝 <?= $counts['posts'] ?></span>
                    <span class="messages-count" title="New chat messages">💬 <?= $counts['messages'] ?></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</body>
</html>