<?php


require_once "config.php";

$currentUserId = $_SESSION['user_id'];

// Function to get extended followed users (6 degrees)


// Function to get extended friends (5 degrees)


// Function to get followed users' friends (5 degrees)
function getFollowedUsersFriends($pdo, $userId, $maxDepth = 5) {
    // First get all users the current user follows
    $followedStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $followedStmt->execute([$userId]);
    $directFollowed = $followedStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    if (empty($directFollowed)) {
        return [];
    }
    
    $allFriendsOfFollowed = [];
    
    foreach ($directFollowed as $followedId) {
        $friendsOfFollowed = getExtendedFriends($pdo, $followedId, $maxDepth);
        $allFriendsOfFollowed = array_merge($allFriendsOfFollowed, $friendsOfFollowed);
    }
    
    return array_unique($allFriendsOfFollowed);
}





// Get extended networks with error handling
try {
    $extendedFollowedUsers = getExtendedFollowedUsers($pdo, $currentUserId, 6);
    $extendedFriends = getExtendedFriends($pdo, $currentUserId, 5);
    $followedUsersFriends = getFollowedUsersFriends($pdo, $currentUserId, 5);
    $visitedProfiles = getVisitedProfiles($pdo, $currentUserId, 30);
} catch (PDOException $e) {
    $extendedFollowedUsers = [];
    $extendedFriends = [];
    $followedUsersFriends = [];
    $visitedProfiles = [];
}

// Get users the current user is already following
$followingStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$followingStmt->execute([$currentUserId]);
$alreadyFollowing = $followingStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get current user's friends
$friendsStmt = $pdo->prepare("
    SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'
    UNION 
    SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted'
");
$friendsStmt->execute([$currentUserId, $currentUserId]);
$currentUserFriends = $friendsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get pending friend requests
$pendingStmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? AND status = 'pending'");
$pendingStmt->execute([$currentUserId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Combine all potential users from extended networks
$allPotentialUsers = array_unique(array_merge(
    $extendedFollowedUsers, 
    $extendedFriends, 
    $followedUsersFriends,
    $visitedProfiles
));

// Remove current user and users already being followed
$filteredUsers = array_diff($allPotentialUsers, [$currentUserId], $alreadyFollowing);

// Get user details for filtered users and calculate scores
$scoredUsers = [];
if (!empty($filteredUsers)) {
    $placeholders = str_repeat('?,', count($filteredUsers) - 1) . '?';
    $usersStmt = $pdo->prepare("
        SELECT u.id, u.username, u.profile_pic_url, u.category1, u.category2,
               COUNT(f.follower_id) as follower_count
        FROM users u 
        LEFT JOIN follows f ON u.id = f.followed_id 
        WHERE u.id IN ($placeholders)
        GROUP BY u.id, u.username, u.profile_pic_url, u.category1, u.category2
        LIMIT 20
    ");
    $usersStmt->execute(array_values($filteredUsers));
    $suggestedUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate scores and add network information
    foreach ($suggestedUsers as $user) {
        $userId = $user['id'];
        $relationshipScore = calculateRelationshipScore(
            $pdo, 
            $currentUserId, 
            $userId, 
            $extendedFollowedUsers, 
            $extendedFriends, 
            $followedUsersFriends,
            $visitedProfiles
        );
        
        // Engagement score based on follower count
        $engagementScore = $user['follower_count'] * 0.3;
        
        // Final score calculation
        $finalScore = (
            $relationshipScore * 0.7 +
            $engagementScore * 0.3
        );
        
        $user['final_score'] = $finalScore;
        $user['network_sources'] = [];
        
        // Add network source information
        if (in_array($userId, $extendedFollowedUsers)) {
            $user['network_sources'][] = 'Follow Network';
        }
        if (in_array($userId, $extendedFriends)) {
            $user['network_sources'][] = 'Friend Network';
        }
        if (in_array($userId, $followedUsersFriends)) {
            $user['network_sources'][] = 'Followed Friends';
        }
        if (in_array($userId, $visitedProfiles)) {
            $user['network_sources'][] = 'Visited Profiles';
        }
        
        $scoredUsers[] = $user;
    }
    
    // Sort by final score (highest first)
    usort($scoredUsers, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });
    
    $suggestedUsers = $scoredUsers;
} else {
    $suggestedUsers = [];
}

// AJAX handlers for follow functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Follow/unfollow handler
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
        $followedId = (int)$_POST['followed_id'];
        
        if ($followedId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'Cannot follow yourself']);
            exit;
        }
        
        try {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$currentUserId, $followedId]);
                
                // Create notification
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message) VALUES (?, 'follow', ?, ?)");
                $message = "started following you";
                $stmt->execute([$followedId, $currentUserId, $message]);
                
                echo json_encode(['success' => true, 'action' => 'followed']);
            } else {
                $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
                $stmt->execute([$currentUserId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'unfollowed']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>People You May Know</title>
<style>
  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }
  
  body { 
    font-family: Arial, sans-serif;
    background: #1e1e2f; 
    color: white;
    padding: 10px;
  }
  
  .horizontal-users-container {
    width: 100%;
    overflow-x: auto;
    padding: 15px 0;
    margin-bottom: 10px;
  }
  
  .horizontal-users-scroll {
    display: flex;
    gap: 15px;
    padding: 0 10px;
    min-width: min-content;
  }
  
  .user-card-small {
    background: #2c2c3d;
    border: 2px solid #7b68ee;
    border-radius: 12px;
    padding: 15px 12px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    min-width: 140px;
    max-width: 140px;
    flex-shrink: 0;
    position: relative;
  }
  
  .user-card-small:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(123, 104, 238, 0.4);
    border-color: #9370db;
  }
  
  .profile-img-container-small {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid #7b68ee;
    cursor: pointer;
    transition: all 0.3s ease;
  }
  
  .profile-img-container-small:hover {
    border-color: #9370db;
    transform: scale(1.05);
  }
  
  .profile-img-small {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  
  .user-name-small {
    font-size: 14px;
    font-weight: bold;
    color: #7b68ee;
    cursor: pointer;
    transition: color 0.3s ease;
    margin-top: 2px;
    text-align: center;
    line-height: 1.2;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  
  .user-name-small:hover {
    color: #9370db;
    text-decoration: underline;
  }
  
  .user-categories-small {
    font-size: 11px;
    color: #aaa;
    margin-bottom: 2px;
    text-align: center;
    line-height: 1.2;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  
  .user-followers-small {
    font-size: 10px;
    color: #888;
    margin-bottom: 5px;
  }
  
  .network-source-small {
    font-size: 9px;
    color: #7b68ee;
    font-weight: bold;
    margin-bottom: 8px;
    text-align: center;
    line-height: 1.2;
  }
  
  .network-source-tag {
    display: inline-block;
    background: rgba(123, 104, 238, 0.2);
    padding: 2px 6px;
    border-radius: 8px;
    margin: 1px;
    font-size: 8px;
  }
  
  .follow-btn-small {
    padding: 6px 12px;
    border: none;
    border-radius: 15px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(45deg, #ff004f, #c972ff);
    color: white;
    transition: all 0.3s ease;
    font-size: 11px;
    width: 100%;
  }
  
  .follow-btn-small:hover:not(.following) {
    background: linear-gradient(45deg, #d60040, #a85cd6);
    transform: scale(1.05);
  }
  
  .follow-btn-small.following {
    background: #6c757d;
    color: #ddd;
    cursor: default;
  }
  
  .no-users-small {
    text-align: center;
    padding: 30px 20px;
    font-size: 14px;
    color: #aaa;
    background: #2c2c3d;
    border-radius: 10px;
    border: 2px solid #7b68ee;
    width: 100%;
  }
  
  .section-title {
    font-size: 16px;
    font-weight: bold;
    color: #7b68ee;
    margin-bottom: 10px;
    padding: 0 10px;
  }
  
  .section-subtitle {
    font-size: 12px;
    color: #aaa;
    margin-bottom: 15px;
    padding: 0 10px;
  }
  
  /* Custom scrollbar for horizontal container */
  .horizontal-users-container::-webkit-scrollbar {
    height: 6px;
  }
  
  .horizontal-users-container::-webkit-scrollbar-track {
    background: #2c2c3d;
    border-radius: 3px;
  }
  
  .horizontal-users-container::-webkit-scrollbar-thumb {
    background: #7b68ee;
    border-radius: 3px;
  }
  
  .horizontal-users-container::-webkit-scrollbar-thumb:hover {
    background: #9370db;
  }
  
  /* Score indicator */
  .score-indicator-small {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(123, 104, 238, 0.3);
    padding: 1px 4px;
    border-radius: 6px;
    font-size: 8px;
    color: #ccc;
  }
  
  @media (max-width: 768px) {
    .user-card-small {
      min-width: 130px;
      max-width: 130px;
      padding: 12px 10px;
    }
    
    .profile-img-container-small {
      width: 55px;
      height: 55px;
    }
    
    .user-name-small {
      font-size: 13px;
    }
  }
  
  @media (max-width: 480px) {
    .user-card-small {
      min-width: 120px;
      max-width: 120px;
      padding: 10px 8px;
    }
    
    .profile-img-container-small {
      width: 50px;
      height: 50px;
    }
    
    .user-name-small {
      font-size: 12px;
    }
    
    .follow-btn-small {
      padding: 5px 10px;
      font-size: 10px;
    }
  }
</style>
</head>
<body>

<div class="section-title">🔍 People You May Know</div>
<p class="section-subtitle">Scroll horizontally to discover more people from your network</p>

<div class="horizontal-users-container" id="users-container">
  <div class="horizontal-users-scroll">
    <?php if (empty($suggestedUsers)): ?>
      <div class="no-users-small">
        <p>No new people to discover right now.</p>
      </div>
    <?php else: ?>
      <?php foreach ($suggestedUsers as $user): 
        $userId = $user['id'];
        
        // Build categories string
        $userCategories = [];
        if ($user['category1']) $userCategories[] = $user['category1'];
        if ($user['category2']) $userCategories[] = $user['category2'];
        $categoriesText = !empty($userCategories) ? implode(', ', $userCategories) : 'No categories';
        
        // Limit categories text length
        if (strlen($categoriesText) > 20) {
          $categoriesText = substr($categoriesText, 0, 20) . '...';
        }
      ?>
        <div class="user-card-small" data-user-id="<?= $user['id'] ?>">
          <!-- Score Indicator -->
          <div class="score-indicator-small" title="Recommendation Score: <?= round($user['final_score'], 2) ?>">
            ⭐ <?= round($user['final_score'], 1) ?>
          </div>
          
          <div class="profile-img-container-small" onclick="window.location.href='profile.php?id=<?= $user['id'] ?>'">
            <img src="<?= htmlspecialchars($user['profile_pic_url'] ?: 'default_profile.png') ?>" 
                 alt="Profile" 
                 class="profile-img-small" />
          </div>
          
          <div class="user-name-small" onclick="window.location.href='profile.php?id=<?= $user['id'] ?>'">
            <?= htmlspecialchars($user['username']) ?>
          </div>
          
          <div class="user-categories-small"><?= htmlspecialchars($categoriesText) ?></div>
          <div class="user-followers-small"><?= $user['follower_count'] ?> followers</div>
          
          <!-- Network Sources Display -->
          <div class="network-source-small">
            <?php if (!empty($user['network_sources'])): ?>
              <span class="network-source-tag"><?= htmlspecialchars($user['network_sources'][0]) ?></span>
              <?php if (count($user['network_sources']) > 1): ?>
                <span class="network-source-tag">+<?= count($user['network_sources']) - 1 ?> more</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          
          <button class="follow-btn-small" data-user-id="<?= $user['id'] ?>">
            Follow
          </button>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// Follow functionality
document.querySelectorAll('.follow-btn-small').forEach(button => {
    button.addEventListener('click', async function() {
        const btn = this;
        const userId = btn.getAttribute('data-user-id');
        
        if (btn.classList.contains('following')) return;

        const action = 'follow';
        const formData = new FormData();
        formData.append('action', action);
        formData.append('followed_id', userId);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                btn.textContent = 'Following';
                btn.classList.add('following');
                
                // Update all follow buttons for this user
                document.querySelectorAll(`.follow-btn-small[data-user-id="${userId}"]`).forEach(followBtn => {
                    followBtn.textContent = 'Following';
                    followBtn.classList.add('following');
                });
                
                showMessage('Now following ' + btn.closest('.user-card-small').querySelector('.user-name-small').textContent, 'success');
            } else {
                showMessage(data.message || 'Failed to follow user', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showMessage('Error following user', 'error');
        }
    });
});

// Message display function
function showMessage(message, type) {
    const existingMessage = document.querySelector('.message-toast');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message-toast ${type}`;
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 10px 16px;
        border-radius: 6px;
        color: white;
        font-weight: bold;
        z-index: 1000;
        transition: all 0.3s ease;
        font-size: 14px;
        ${type === 'success' ? 'background: #28a745;' : 'background: #dc3545;'}
    `;
    
    document.body.appendChild(messageDiv);
    
    setTimeout(() => {
        messageDiv.style.opacity = '0';
        messageDiv.style.transform = 'translateX(100px)';
        setTimeout(() => messageDiv.remove(), 300);
    }, 3000);
}

// Add animation for page load
document.addEventListener('DOMContentLoaded', function() {
    const userCards = document.querySelectorAll('.user-card-small');
    userCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateX(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.4s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateX(0)';
        }, index * 50);
    });
});
</script>

</body>
</html>