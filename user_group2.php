<?php

require_once "config.php";
$userId = $_SESSION['user_id'];

// Fetch groups the user has joined, including group's info and if user is admin (creator)
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
}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.group-card').forEach(card => {
        card.addEventListener('click', () => {
            const groupId = card.dataset.groupId;
            window.location.href = `group.php?id=${groupId}`;
        });
    });
});
</script>
</head>
<body>

<h1 style="color: #7b68ee">Group I Joined </h1>

<?php if (empty($userGroups)): ?>
    <p style="text-align:center;">You have not joined any groups yet.</p>
<?php else: ?>
<div class="group-list">
    <?php foreach ($userGroups as $group): ?>
        <?php
        $privacyClass = strtolower($group['privacy_setting'] ?? 'public'); // example field name
        if ($privacyClass !== 'private') {
            $privacyClass = 'public';
        }
        ?>
        <div class="group-card" data-group-id="<?= htmlspecialchars($group['id']) ?>">
            <div class="group-cover">
                <img src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_cover.jpg') ?>" alt="Group Cover" />
            </div>
            <img class="group-profile" src="<?= htmlspecialchars($group['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Group Profile Picture" />
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
                <div class="group-members" style="color: #7b68ee;"><?= htmlspecialchars($group['member_count']) ?> member<?= $group['member_count'] != 1 ? 's' : '' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</body>
</html>
