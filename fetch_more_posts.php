<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];

// Same recursive function to get friend network as in post.php
function getExtendedFriendIds($pdo, $userId, $maxDepth = 5) {
    $visited = [$userId];
    $currentLevel = [$userId];

    for ($depth = 0; $depth < $maxDepth; $depth++) {
        if (empty($currentLevel)) break;

        $placeholders = implode(',', array_fill(0, count($currentLevel), '?'));
        $params = array_merge($currentLevel, $currentLevel);

        $stmt1 = $pdo->prepare("
            SELECT DISTINCT friend_id FROM friends WHERE status='accepted' AND friend_id IN ($placeholders)
            UNION
            SELECT DISTINCT friend_id FROM friends WHERE status='accepted' AND friend_id IN ($placeholders)
        ");
        $stmt1->execute($params);
        $friends1 = $stmt1->fetchAll(PDO::FETCH_COLUMN);

        $stmt2 = $pdo->prepare("
            SELECT DISTINCT followed_id FROM follows WHERE follower_id IN ($placeholders)
            UNION
            SELECT DISTINCT follower_id FROM follows WHERE followed_id IN ($placeholders)
        ");
        $stmt2->execute($params);
        $friends2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);

        $newFriends = array_unique(array_merge($friends1, $friends2));
        $newFriendsFiltered = array_diff($newFriends, $visited);

        if (empty($newFriendsFiltered)) break;

        $visited = array_merge($visited, $newFriendsFiltered);
        $currentLevel = array_values($newFriendsFiltered);
    }

    return array_values(array_diff($visited, [$userId]));
}

$extendedFriendIds = getExtendedFriendIds($pdo, $userId);

$groupIdsStmt = $pdo->prepare("SELECT group_id FROM group_members WHERE user_id = ? AND status = 'approved'");
$groupIdsStmt->execute([$userId]);
$groupIds = $groupIdsStmt->fetchAll(PDO::FETCH_COLUMN);

$sqlParts = [];
$params = [];

if (!empty($extendedFriendIds)) {
    $placeholdersFriends = implode(',', array_fill(0, count($extendedFriendIds), '?'));
    $sqlParts[] = "p.user_id IN ($placeholdersFriends)";
    $params = array_merge($params, $extendedFriendIds);
}

if (!empty($groupIds)) {
    $placeholdersGroups = implode(',', array_fill(0, count($groupIds), '?'));
    $sqlParts[] = "p.group_id IN ($placeholdersGroups)";
    $params = array_merge($params, $groupIds);
}

$topUsersSql = "
    SELECT u.id FROM users u
    LEFT JOIN follows f ON u.id = f.followed_id
    GROUP BY u.id
    ORDER BY COUNT(f.follower_id) DESC
    LIMIT 10
";
$topUsersStmt = $pdo->query($topUsersSql);
$topUserIds = $topUsersStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($topUserIds)) {
    $placeholdersTopUsers = implode(',', array_fill(0, count($topUserIds), '?'));
    $sqlParts[] = "p.user_id IN ($placeholdersTopUsers)";
    $params = array_merge($params, $topUserIds);
}

$whereClause = empty($sqlParts) ? "p.privacy_setting = 'public'" : '(' . implode(' OR ', $sqlParts) . ')';

$limit = 15;

$sql = "
    SELECT p.*, u.username, u.profile_pic_url, u.is_business_account, u.id AS author_id
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE $whereClause
    ORDER BY RANDOM()
    LIMIT $limit
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output just the HTML for posts to be injected by JS
foreach ($posts as $post): ?>
    <div class="post" data-post-id="<?= $post['id'] ?>">
        <div class="post-header">
            <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
            <div class="username" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'"><?= htmlspecialchars($post['username']) ?></div>
        </div>
        <div class="post-content" id="post-content-<?= $post['id'] ?>">
            <?= nl2br(htmlspecialchars($post['content'])) ?>
        </div>
        <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
            <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
