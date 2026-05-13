<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database connection info
require_once "config.php";


// Get and sanitize input parameters
$currentUserId = $_SESSION['user_id'];
$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 50]]);
$excludeStr = filter_input(INPUT_GET, 'exclude_ids', FILTER_SANITIZE_STRING) ?? '';
$excludeIds = array_filter(array_map('intval', explode(',', $excludeStr)), fn($id) => $id > 0);
$fallback = filter_input(INPUT_GET, 'fallback', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0, 'max_range' => 1]]);

function getFriends(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT friend_id FROM friends WHERE user_id = :uid AND status='confirmed'
        UNION
        SELECT user_id FROM friends WHERE friend_id = :uid AND status='confirmed'
    ");
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

try {
    $friends = getFriends($pdo, $currentUserId);

    $friendsOfFriends = [];
    if (!empty($friends)) {
        $placeholders = implode(',', array_fill(0, count($friends), '?'));
        $stmtFoF = $pdo->prepare("
            SELECT DISTINCT friend_id FROM friends WHERE user_id IN ($placeholders) AND status='confirmed'
        ");
        $stmtFoF->execute($friends);
        $fof = $stmtFoF->fetchAll(PDO::FETCH_COLUMN);
        $friendsOfFriends = array_values(array_diff($fof, [$currentUserId], $friends));
    }

    $userIds = array_unique(array_merge([$currentUserId], $friends, $friendsOfFriends));

    if ($fallback == 1 || empty($userIds)) {
        // Fallback random posts from any public group
        $sql = "
            SELECT p.id, p.content, p.image_url, p.video_url, p.group_id, p.user_id,
                   g.cover_pic_url AS group_cover, g.name AS group_name,
                   u.username, u.profile_pic_url
            FROM posts p
            JOIN groups g ON g.id = p.group_id
            JOIN users u ON u.id = p.user_id
            WHERE g.is_private = false
        ";
        if (!empty($excludeIds)) {
            $sql .= " AND p.id NOT IN (" . implode(',', $excludeIds) . ")";
        }
        $sql .= " ORDER BY RANDOM() LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();
    } else {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmtGroups = $pdo->prepare("
            SELECT DISTINCT gm.group_id
            FROM group_members gm
            JOIN groups g ON g.id = gm.group_id
            WHERE gm.user_id IN ($placeholders)
              AND gm.status = 'approved'
              AND g.is_private = false
        ");
        $stmtGroups->execute($userIds);
        $groupIds = $stmtGroups->fetchAll(PDO::FETCH_COLUMN);

        if (empty($groupIds)) {
            // No groups found - fallback random posts
            $sql = "
                SELECT p.id, p.content, p.image_url, p.video_url, p.group_id, p.user_id,
                       g.cover_pic_url AS group_cover, g.name AS group_name,
                       u.username, u.profile_pic_url
                FROM posts p
                JOIN groups g ON g.id = p.group_id
                JOIN users u ON u.id = p.user_id
                WHERE g.is_private = false
            ";
            if (!empty($excludeIds)) {
                $sql .= " AND p.id NOT IN (" . implode(',', $excludeIds) . ")";
            }
            $sql .= " ORDER BY RANDOM() LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll();
        } else {
            $placeholdersG = implode(',', array_fill(0, count($groupIds), '?'));
            $stmtPop = $pdo->prepare("
                SELECT group_id, COUNT(*) AS member_count
                FROM group_members
                WHERE group_id IN ($placeholdersG)
                  AND status = 'approved'
                GROUP BY group_id
                ORDER BY member_count DESC
                LIMIT 1
            ");
            $stmtPop->execute($groupIds);
            $mostPopGroup = $stmtPop->fetch();
            $mostPopGroupId = $mostPopGroup['group_id'] ?? null;

            $postGroupIds = $groupIds;
            if ($mostPopGroupId !== null && !in_array($mostPopGroupId, $groupIds, true)) {
                $postGroupIds[] = $mostPopGroupId;
            }
            $placeholdersPostGroups = implode(',', array_fill(0, count($postGroupIds), '?'));

            $sql = "
                SELECT p.id, p.content, p.image_url, p.video_url, p.group_id, p.user_id,
                       g.cover_pic_url AS group_cover, g.name AS group_name,
                       u.username, u.profile_pic_url
                FROM posts p
                JOIN groups g ON p.group_id = g.id
                JOIN users u ON p.user_id = u.id
                WHERE p.group_id IN ($placeholdersPostGroups)
                  AND g.is_private = false
            ";
            if (!empty($excludeIds)) {
                $sql .= " AND p.id NOT IN (" . implode(',', $excludeIds) . ")";
            }
            $sql .= " ORDER BY RANDOM() LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            $index = 1;
            foreach ($postGroupIds as $gid) {
                $stmt->bindValue($index, $gid, PDO::PARAM_INT);
                $index++;
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll();
        }
    }

    echo json_encode(['posts' => $posts]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
}

exit;
