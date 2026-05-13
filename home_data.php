<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once "config.php";


$currentUserId = $_SESSION['user_id'];
$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT, ['options' => ['default'=>0, 'min_range'=>0]]);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range'=>1, 'max_range'=>50]]);
$excludeIdsStr = filter_input(INPUT_GET, 'exclude_ids', FILTER_SANITIZE_STRING) ?? '';
$excludeIds = array_filter(array_map('intval', explode(',', $excludeIdsStr)), fn($id) => $id > 0);

// Recursive function to retrieve friends up to 4 levels
function getFriendsRecursive(PDO $pdo, array $userIds, int $depth = 1, int $maxDepth = 4, array &$acc = []) {
    if ($depth > $maxDepth || empty($userIds)) {
        return $acc;
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("
        SELECT friend_id FROM friends WHERE user_id IN ($placeholders) AND status = 'accepted'
        UNION
        SELECT user_id FROM friends WHERE friend_id IN ($placeholders) AND status = 'accepted'
    ");
    $params = array_merge($userIds, $userIds);
    $stmt->execute($params);
    $friends = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $newFriends = array_diff($friends, $acc);
    $acc = array_unique(array_merge($acc, $newFriends));
    if (empty($newFriends)) {
        return $acc;
    }
    return getFriendsRecursive($pdo, $newFriends, $depth + 1, $maxDepth, $acc);
}

try {
    // Get friends up to 4 levels
    $friendsLevel4 = getFriendsRecursive($pdo, [$currentUserId]);

    // Get users followed by current user
    $stmtFollows = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
    $stmtFollows->execute([$currentUserId]);
    $followedIds = $stmtFollows->fetchAll(PDO::FETCH_COLUMN);

    // Compose list of user IDs to get posts from (excluding current user if desired)
    $userIds = array_unique(array_merge($friendsLevel4, $followedIds));

    if (empty($userIds)) {
        echo json_encode(['posts' => []]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $excludePlaceholders = '';
    if ($excludeIds) {
        $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
    }

    // Query posts without group posts (group_id NULL or zero)
    $sql = "
        SELECT p.id, p.content, p.media_url, p.post_type, p.user_id,
               u.username, u.profile_pic_url
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.user_id IN ($placeholders)
          AND (p.group_id IS NULL OR p.group_id = 0)
    ";

    if ($excludePlaceholders) {
        $sql .= " AND p.id NOT IN ($excludePlaceholders)";
    }

    $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    $idx = 1;
    foreach ($userIds as $uid) {
        $stmt->bindValue($idx++, $uid, PDO::PARAM_INT);
    }
    if ($excludeIds) {
        foreach ($excludeIds as $eid) {
            $stmt->bindValue($idx++, $eid, PDO::PARAM_INT);
        }
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $posts = $stmt->fetchAll();

    echo json_encode(['posts' => $posts]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
}

exit;
