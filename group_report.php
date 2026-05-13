<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

// Check if user is admin of the group
if ($groupId > 0) {
    $stmt = $pdo->prepare("SELECT id, name, creator_id FROM groups WHERE id = :group_id");
    $stmt->execute(['group_id' => $groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        die("Group not found.");
    }
    
    if ($group['creator_id'] != $userId) {
        die("Access denied. You must be the group admin to view reported posts.");
    }
} else {
    die("Invalid group ID.");
}

// Fetch reported posts for this group
$stmt = $pdo->prepare("
    SELECT pr.*, p.*, u.username, u.profile_pic_url, 
           COUNT(pr2.id) as report_count
    FROM post_reports pr
    JOIN posts p ON pr.post_id = p.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN post_reports pr2 ON p.id = pr2.post_id
    WHERE p.group_id = :group_id
    GROUP BY pr.id, p.id, u.id
    ORDER BY pr.created_at DESC
");
$stmt->execute(['group_id' => $groupId]);
$reportedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete_post' && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        
        // Verify the post belongs to this group
        $checkStmt = $pdo->prepare("SELECT 1 FROM posts WHERE id = ? AND group_id = ?");
        $checkStmt->execute([$postId, $groupId]);
        
        if ($checkStmt->fetchColumn()) {
            // Delete the post and associated reports
            $pdo->beginTransaction();
            try {
                // Delete post reports first
                $deleteReportsStmt = $pdo->prepare("DELETE FROM post_reports WHERE post_id = ?");
                $deleteReportsStmt->execute([$postId]);
                
                // Delete post likes
                $deleteLikesStmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ?");
                $deleteLikesStmt->execute([$postId]);
                
                // Delete post comments
                $deleteCommentsStmt = $pdo->prepare("DELETE FROM comments WHERE post_id = ?");
                $deleteCommentsStmt->execute([$postId]);
                
                // Delete the post
                $deletePostStmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
                $deletePostStmt->execute([$postId]);
                
                $pdo->commit();
                
                // Refresh the page
                header("Location: group_report.php?group_id=" . $groupId . "&deleted=1");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error deleting post: " . $e->getMessage();
            }
        } else {
            $error = "Post not found or doesn't belong to this group.";
        }
    }
    
    // Handle keep post action (remove reports but keep post)
    if (isset($_POST['action']) && $_POST['action'] === 'keep_post' && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        
        // Verify the post belongs to this group
        $checkStmt = $pdo->prepare("SELECT 1 FROM posts WHERE id = ? AND group_id = ?");
        $checkStmt->execute([$postId, $groupId]);
        
        if ($checkStmt->fetchColumn()) {
            try {
                // Delete all reports for this post
                $deleteReportsStmt = $pdo->prepare("DELETE FROM post_reports WHERE post_id = ?");
                $deleteReportsStmt->execute([$postId]);
                
                // Refresh the page
                header("Location: group_report.php?group_id=" . $groupId . "&kept=1");
                exit;
            } catch (Exception $e) {
                $error = "Error keeping post: " . $e->getMessage();
            }
        } else {
            $error = "Post not found or doesn't belong to this group.";
        }
    }
}

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Reported Posts - <?= htmlspecialchars($group['name']) ?></title>
<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; 
    max-width: 900px; 
    margin: 20px auto; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
    padding: 20px;
}

.container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border: 2px solid rgba(123, 104, 238, 0.3);
}

h1 {
    color: #7b68ee;
    margin-bottom: 20px;
    text-align: center;
}

.back-btn {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 20px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.back-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
}

.reported-post {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    border-left: 4px solid #ff6b6b;
}

.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.post-header img {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #7b68ee;
    margin-right: 12px;
}

.post-header-info {
    flex-grow: 1;
}

.username {
    font-weight: bold;
    color: #2d3748;
}

.timestamp {
    font-size: 12px;
    color: #718096;
}

.report-info {
    background: #fff5f5;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.report-reason {
    font-style: italic;
    color: #e53e3e;
    margin-bottom: 8px;
}

.report-count {
    font-size: 12px;
    color: #718096;
}

.report-date {
    font-size: 12px;
    color: #718096;
    margin-top: 5px;
}

.post-content {
    margin-bottom: 15px;
    line-height: 1.5;
}

.post-actions {
    display: flex;
    gap: 10px;
}

.delete-btn {
    background: linear-gradient(135deg, #e53e3e, #c53030);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.delete-btn:hover {
    background: linear-gradient(135deg, #c53030, #9b2c2c);
    transform: translateY(-2px);
}

.keep-btn {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.keep-btn:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
}

.no-reports {
    text-align: center;
    padding: 40px;
    color: #718096;
    font-style: italic;
}

.success-message {
    background: #48bb78;
    color: white;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    text-align: center;
}

.error-message {
    background: #e53e3e;
    color: white;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    text-align: center;
}

.loading {
    text-align: center;
    padding: 20px;
    color: #718096;
}
</style>
</head>
<body>
<div class="container">
    <a href="group.php?id=<?= $groupId ?>" class="back-btn">← Back to Group</a>
    
    <h1>Reported Posts - <?= htmlspecialchars($group['name']) ?></h1>
    
    <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
        <div class="success-message">
            Post deleted successfully.
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['kept']) && $_GET['kept'] == 1): ?>
        <div class="success-message">
            Post kept successfully. All reports have been removed.
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error-message">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <?php if (empty($reportedPosts)): ?>
        <div class="no-reports">
            <h3>No reported posts</h3>
            <p>There are no reported posts in this group.</p>
        </div>
    <?php else: ?>
        <?php foreach ($reportedPosts as $report): ?>
            <div class="reported-post" id="post-<?= $report['post_id'] ?>">
                <div class="post-header">
                    <img src="<?= htmlspecialchars($report['profile_pic_url'] ?: 'default_profile.png') ?>" alt="User Profile" />
                    <div class="post-header-info">
                        <div class="username"><?= htmlspecialchars($report['username']) ?></div>
                        <div class="timestamp">Posted on: <?= date('M j, Y H:i', strtotime($report['created_at'])) ?></div>
                    </div>
                </div>
                
                <div class="report-info">
                    <div class="report-reason">
                        <strong>Report Reason:</strong> "<?= htmlspecialchars($report['report_reason']) ?>"
                    </div>
                    <div class="report-count">
                        Total reports for this post: <?= $report['report_count'] ?>
                    </div>
                    <div class="report-date">
                        Reported on: <?= date('M j, Y H:i', strtotime($report['created_at'])) ?>
                    </div>
                </div>
                
                <?php if (!empty($report['post_header'])): ?>
                    <div class="post-content" style="font-weight: bold; font-size: 16px; margin-bottom: 10px;">
                        <?= htmlspecialchars($report['post_header']) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($report['content'])): ?>
                    <div class="post-content">
                        <?= nl2br(htmlspecialchars($report['content'])) ?>
                    </div>
                <?php endif; ?>
                
                <div class="post-actions">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?= $report['post_id'] ?>">
                        <button type="submit" class="delete-btn" onclick="return confirm('Are you sure you want to delete this post? This action cannot be undone.')">
                            Delete Post
                        </button>
                    </form>
                    
                    <form method="POST" class="keep-form" style="display: inline;">
                        <input type="hidden" name="action" value="keep_post">
                        <input type="hidden" name="post_id" value="<?= $report['post_id'] ?>">
                        <button type="submit" class="keep-btn" onclick="return confirm('Are you sure you want to keep this post? All reports for this post will be removed.')">
                            Keep Post
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// AJAX handling for keep post action (optional enhancement)
document.addEventListener('DOMContentLoaded', function() {
    const keepForms = document.querySelectorAll('.keep-form');
    
    keepForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const postElement = this.closest('.reported-post');
            const postId = formData.get('post_id');
            
            // Show loading state
            const keepBtn = this.querySelector('.keep-btn');
            const originalText = keepBtn.textContent;
            keepBtn.textContent = 'Processing...';
            keepBtn.disabled = true;
            
            fetch('group_report.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .then(data => {
                // If we get here, it means the response wasn't a redirect
                // Remove the post element from the DOM
                postElement.remove();
                
                // Check if there are any posts left
                if (document.querySelectorAll('.reported-post').length === 0) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                keepBtn.textContent = originalText;
                keepBtn.disabled = false;
                alert('Error keeping post. Please try again.');
            });
        });
    });
});
</script>
</body>
</html>