<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";
$currentUserId = $_SESSION['user_id'];

// Handle search query filtering groups by name
$searchFilter = trim($_GET['q'] ?? '');
$params = ['currentUser' => $currentUserId];
$sqlWhere = "WHERE gm.user_id = :currentUser AND (gm.status = 'approved' OR gm.is_admin = TRUE)";

if ($searchFilter !== '') {
    $sqlWhere .= " AND LOWER(g.name) LIKE :search";
    $params['search'] = '%' . strtolower($searchFilter) . '%';
}

// Fetch groups with member counts
$sql = "
    SELECT g.id, g.name, g.profile_pic_url, g.cover_pic_url,
           gm.status, gm.is_admin,
           COALESCE(member_counts.count, 0) AS member_count
    FROM groups g
    INNER JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN (
        SELECT group_id, COUNT(*) AS count
        FROM group_members
        WHERE status = 'approved'
        GROUP BY group_id
    ) member_counts ON g.id = member_counts.group_id
    $sqlWhere
    ORDER BY g.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>My Groups</title>
<style>
    body {
        font-family: Arial, sans-serif;
        max-width: 700px;
        margin: 40px auto;
        background: #f9f9f9;
        padding: 10px;
    }
    h2 {
        text-align: center;
        margin-bottom: 20px;
    }
    #search-container {
        max-width: 400px;
        margin: auto;
        margin-bottom: 30px;
        text-align: center;
    }
    #search-input {
        width: 100%;
        padding: 10px;
        font-size: 16px;
        border: 1px solid #ccc;
        border-radius: 6px;
        box-sizing: border-box;
    }
    .group-card {
        display: flex;
        gap: 20px;
        align-items: center;
        padding: 15px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 6px #ccc;
        margin-bottom: 20px;
    }
    .group-cover {
        width: 120px;
        height: 70px;
        object-fit: cover;
        border-radius: 6px;
    }
    .group-profile-pic {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #007bff;
    }
    .group-details {
        flex-grow: 1;
    }
    .group-name {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 6px;
    }
    .member-count {
        font-size: 14px;
        color: #555;
        margin-bottom: 6px;
    }
    .admin-badge {
        background-color: #007bff;
        color: white;
        font-size: 12px;
        font-weight: bold;
        padding: 2px 8px;
        border-radius: 12px;
    }
    button.visit-btn {
        background-color: #007bff;
        color: white;
        border: none;
        padding: 8px 14px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
        white-space: nowrap;
    }
    button.visit-btn:hover {
        background-color: #0056b3;
    }
</style>
</head>
<body>

<h2>My Groups</h2>

<div id="search-container">
    <input type="text" id="search-input" placeholder="Search your groups..." />
</div>

<div id="groups-list">
    <?php if (count($groups) === 0): ?>
        <p style="text-align:center; font-style: italic;">You haven't joined any groups yet.</p>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <div class="group-card">
                <img 
                    src="<?= htmlspecialchars($group['cover_pic_url'] ?: 'default_cover.png') ?>" 
                    alt="Group Cover" 
                    class="group-cover" 
                />
                <img 
                    src="<?= htmlspecialchars($group['profile_pic_url'] ?: 'default_profile.png') ?>" 
                    alt="Group Profile" 
                    class="group-profile-pic" 
                />
                <div class="group-details">
                    <div class="group-name">
                        <?= htmlspecialchars($group['name']) ?>
                        <?php if ($group['is_admin']): ?>
                            <span class="admin-badge">Admin</span>
                        <?php endif; ?>
                    </div>
                    <div class="member-count">
                        Members: <?= htmlspecialchars($group['member_count']) ?>
                    </div>
                </div>
                <button class="visit-btn" onclick="location.href='group.php?id=<?= $group['id'] ?>'">Visit Group</button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


<script>
    const searchInput = document.getElementById('search-input');
    const groupsList = document.getElementById('groups-list');

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim();

        // Use XMLHttpRequest or fetch to reload page with filter
        // Simpler approach: reload with query param
        clearTimeout(window.searchTimeout);
        window.searchTimeout = setTimeout(() => {
            const url = new URL(window.location);
            if (query.length > 0) {
                url.searchParams.set('q', query);
            } else {
                url.searchParams.delete('q');
            }
            window.location.href = url.toString();
        }, 400);
    });
</script>

</body>
</html>
