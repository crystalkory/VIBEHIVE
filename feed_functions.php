<?php
// ==========================================================
// FEED FETCHER: Unified algorithm with shuffle & ranking
// ==========================================================

function getPersonalizedFeed($pdo, $userId, $profileViewedId = null, $limit = 30) {
    $posts = [];

    // 1. Posts from friends & followed users (last 3 days)
    $friendPosts = fetchPosts($pdo, $userId, "NOW() - INTERVAL '3 days'", ['friends', 'follows'], $limit);

    // 2. Posts from followed users' network (friends of follows) - last 3 days
    $networkPosts = fetchNetworkPosts($pdo, $userId, $limit);

    // 3. Random posts from others (last 7 days)
    $randomPosts = fetchRandomPosts($pdo, "NOW() - INTERVAL '7 days'", $limit / 3);

    // 4. Popular posts (last 6 days)
    $popularPosts = fetchPopularPosts($pdo, "NOW() - INTERVAL '6 days'", $limit / 2);

    // Merge everything
    $posts = array_merge($friendPosts, $networkPosts, $randomPosts, $popularPosts);

    // 5. If viewing a profile, boost that user’s posts
    if ($profileViewedId && $profileViewedId != $userId) {
        $profilePosts = fetchUserPosts($pdo, $profileViewedId, $limit / 2);
        $posts = array_merge($posts, $profilePosts);
    }

    // 6. Engagement boost – reorder to push posts from users the current user engages with
    $posts = boostEngagedUsers($pdo, $userId, $posts);

    // 7. Shuffle while avoiding duplicates close together
    $posts = shuffleWithSpacing($posts);

    // 8. Limit final feed
    return array_slice($posts, 0, $limit);
}

// ==========================================================
// HELPERS
// ==========================================================

// Fetch posts by category
function fetchPosts($pdo, $userId, $since, $type = ['friends'], $limit = 20) {
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.id AS user_id
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE p.created_at >= $since
        AND (p.privacy_setting = 'public' OR p.privacy_setting = 'friends')
    ";

    if (in_array('friends', $type)) {
        $query .= " AND p.user_id IN (SELECT friend_id FROM friends WHERE user_id = :uid AND status='accepted')";
    }
    if (in_array('follows', $type)) {
        $query .= " OR p.user_id IN (SELECT followed_id FROM follows WHERE follower_id = :uid)";
    }

    $query .= " ORDER BY p.created_at DESC LIMIT :lim";

    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch friends-of-follows posts
function fetchNetworkPosts($pdo, $userId, $limit = 10) {
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.id AS user_id
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.user_id IN (
            SELECT f2.followed_id
            FROM follows f1
            JOIN follows f2 ON f1.followed_id = f2.follower_id
            WHERE f1.follower_id = :uid
        )
        AND p.created_at >= NOW() - INTERVAL '3 days'
        ORDER BY RANDOM()
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch random posts
function fetchRandomPosts($pdo, $since, $limit = 10) {
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.id AS user_id
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE p.created_at >= $since
        AND p.privacy_setting = 'public'
        ORDER BY RANDOM()
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch popular posts
function fetchPopularPosts($pdo, $since, $limit = 10) {
    $query = "
        SELECT p.*, u.username, u.profile_pic_url, u.id AS user_id,
               (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS likes_count,
               (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comments_count
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE p.created_at >= $since
        AND (p.privacy_setting='public' OR p.privacy_setting='friends')
        ORDER BY (likes_count + comments_count*2) DESC
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch specific user posts
function fetchUserPosts($pdo, $userId, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.profile_pic_url, u.id AS user_id
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE u.id = :uid
        ORDER BY p.created_at DESC
        LIMIT :lim
    ");
    $stmt->execute(['uid' => $userId, 'lim' => $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Boost posts of users the current user engages with
function boostEngagedUsers($pdo, $userId, $posts) {
    $stmt = $pdo->prepare("
        SELECT p.user_id, COUNT(*) AS engagement_count
        FROM posts p
        LEFT JOIN likes l ON l.post_id = p.id AND l.user_id = :uid
        LEFT JOIN comments c ON c.post_id = p.id AND c.user_id = :uid
        GROUP BY p.user_id
        ORDER BY engagement_count DESC
        LIMIT 10
    ");
    $stmt->execute(['uid' => $userId]);
    $engagedUsers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Sort posts: engaged users first
    usort($posts, function($a, $b) use ($engagedUsers) {
        $aBoost = $engagedUsers[$a['user_id']] ?? 0;
        $bBoost = $engagedUsers[$b['user_id']] ?? 0;
        return $bBoost <=> $aBoost;
    });

    return $posts;
}

// Shuffle posts with spacing (avoid two same-user posts consecutively)
function shuffleWithSpacing($posts) {
    $shuffled = [];
    $lastUser = null;

    foreach ($posts as $post) {
        if ($lastUser === $post['user_id']) {
            // push to end to avoid duplicates close together
            array_push($posts, $post);
            continue;
        }
        $shuffled[] = $post;
        $lastUser = $post['user_id'];
    }
    return $shuffled;
}
