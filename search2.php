<?php
// search3.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Fetch user profile info
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$profilePicUrl = $userData['profile_pic_url'] ?: 'default_profile.png';
$username = $userData['username'];

// Get trending hashtags and searches
$trendingHashtags = [];
$trendingSearches = [];

try {
    // Get trending hashtags (most used in last 7 days)
    $hashtagStmt = $pdo->prepare("
        SELECT h.tag, COUNT(ph.post_id) as usage_count
        FROM hashtags h
        JOIN post_hashtags ph ON h.id = ph.hashtag_id
        JOIN posts p ON ph.post_id = p.id
        WHERE p.created_at > NOW() - INTERVAL '7 days'
        GROUP BY h.id, h.tag
        ORDER BY usage_count DESC
        LIMIT 10
    ");
    $hashtagStmt->execute();
    $trendingHashtags = $hashtagStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get popular search terms from post headers and content
    $searchStmt = $pdo->prepare("
        (
            SELECT post_header as term, COUNT(*) as count, 'header' as source
            FROM posts 
            WHERE post_header IS NOT NULL AND LENGTH(post_header) > 3
            AND created_at > NOW() - INTERVAL '7 days'
            GROUP BY post_header
            ORDER BY count DESC
            LIMIT 15
        )
        UNION
        (
            SELECT DISTINCT tag as term, usage_count as count, 'hashtag' as source
            FROM hashtags 
            WHERE usage_count > 0
            ORDER BY usage_count DESC
            LIMIT 15
        )
        ORDER BY count DESC
        LIMIT 20
    ");
    $searchStmt->execute();
    $trendingSearches = $searchStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Trending data error: " . $e->getMessage());
}

// AJAX handler for search suggestions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_suggestions') {
    $query = trim($_POST['query'] ?? '');
    
    if (strlen($query) < 1) {
        echo json_encode([]);
        exit;
    }
    
    $suggestions = [];
    
    try {
        // Enhanced search in hashtags with word completion
        $hashtagStmt = $pdo->prepare("
            SELECT DISTINCT tag, 'hashtag' as type, usage_count as relevance
            FROM hashtags 
            WHERE tag ILIKE ? 
            ORDER BY 
                CASE 
                    WHEN tag ILIKE ? THEN 1  -- Exact match
                    WHEN tag ILIKE ? THEN 2  -- Starts with
                    ELSE 3                   -- Contains
                END,
                usage_count DESC,
                LENGTH(tag)
            LIMIT 8
        ");
        $hashtagStmt->execute([$query . '%', $query, $query . '%']);
        $hashtagResults = $hashtagStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enhanced search in post headers with word completion
        $headerStmt = $pdo->prepare("
            SELECT DISTINCT 
                post_header as term, 
                'header' as type,
                LENGTH(post_header) as length,
                COUNT(*) as usage_count
            FROM posts 
            WHERE post_header ILIKE ? 
               AND post_header IS NOT NULL
               AND LENGTH(post_header) > 3
            GROUP BY post_header
            ORDER BY 
                CASE 
                    WHEN post_header ILIKE ? THEN 1  -- Exact match
                    WHEN post_header ILIKE ? THEN 2  -- Starts with
                    ELSE 3                           -- Contains
                END,
                usage_count DESC,
                LENGTH(post_header)
            LIMIT 8
        ");
        $headerStmt->execute(['%' . $query . '%', $query, $query . '%']);
        $headerResults = $headerStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enhanced search in usernames with word completion
        $userStmt = $pdo->prepare("
            SELECT DISTINCT 
                username as term, 
                'user' as type,
                (SELECT COUNT(*) FROM follows WHERE followed_id = users.id) as follower_count
            FROM users 
            WHERE username ILIKE ? 
            ORDER BY 
                CASE 
                    WHEN username ILIKE ? THEN 1  -- Exact match
                    WHEN username ILIKE ? THEN 2  -- Starts with
                    ELSE 3                        -- Contains
                END,
                follower_count DESC,
                LENGTH(username)
            LIMIT 8
        ");
        $userStmt->execute([$query . '%', $query, $query . '%']);
        $userResults = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine all results with priority scoring
        $allResults = [];
        
        // Add hashtag results with high priority
        foreach ($hashtagResults as $result) {
            $allResults[] = $result;
        }
        
        // Add header results
        foreach ($headerResults as $result) {
            $allResults[] = $result;
        }
        
        // Add user results
        foreach ($userResults as $result) {
            $allResults[] = $result;
        }
        
        // Remove duplicates and limit to 15 suggestions
        $uniqueSuggestions = [];
        $seen = [];
        
        foreach ($allResults as $result) {
            $key = $result['term'] ?? $result['tag'];
            if (!isset($seen[$key])) {
                $uniqueSuggestions[] = $result;
                $seen[$key] = true;
                
                if (count($uniqueSuggestions) >= 15) {
                    break;
                }
            }
        }
        
        $suggestions = $uniqueSuggestions;
        
    } catch (Exception $e) {
        error_log("Search suggestions error: " . $e->getMessage());
    }
    
    echo json_encode($suggestions);
    exit;
}

// AJAX handler for word completions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_word_completions') {
    $query = trim($_POST['query'] ?? '');
    
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }
    
    $completions = [];
    
    try {
        // Get hashtag completions
        $hashtagCompletions = $pdo->prepare("
            SELECT 
                tag as completion,
                'hashtag' as type,
                usage_count as frequency
            FROM hashtags 
            WHERE tag ILIKE ? 
              AND LENGTH(tag) > LENGTH(?)
            ORDER BY usage_count DESC, LENGTH(tag)
            LIMIT 5
        ");
        $hashtagCompletions->execute([$query . '%', $query]);
        $hashtagResults = $hashtagCompletions->fetchAll(PDO::FETCH_ASSOC);
        
        // Get username completions
        $userCompletions = $pdo->prepare("
            SELECT 
                username as completion,
                'user' as type,
                (SELECT COUNT(*) FROM follows WHERE followed_id = users.id) as frequency
            FROM users 
            WHERE username ILIKE ? 
              AND LENGTH(username) > LENGTH(?)
            ORDER BY frequency DESC, LENGTH(username)
            LIMIT 5
        ");
        $userCompletions->execute([$query . '%', $query]);
        $userResults = $userCompletions->fetchAll(PDO::FETCH_ASSOC);
        
        // Get post header completions
        $headerCompletions = $pdo->prepare("
            SELECT 
                post_header as completion,
                'header' as type,
                COUNT(*) as frequency
            FROM posts 
            WHERE post_header ILIKE ? 
              AND post_header IS NOT NULL
              AND LENGTH(post_header) > LENGTH(?)
            GROUP BY post_header
            ORDER BY frequency DESC, LENGTH(post_header)
            LIMIT 5
        ");
        $headerCompletions->execute([$query . '%', $query]);
        $headerResults = $headerCompletions->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine all completions
        $allCompletions = array_merge($hashtagResults, $userResults, $headerResults);
        
        // Sort by frequency and type priority
        usort($allCompletions, function($a, $b) {
            // Priority: hashtag > user > header
            $typePriority = [
                'hashtag' => 3,
                'user' => 2,
                'header' => 1
            ];
            
            $aPriority = $typePriority[$a['type']] ?? 0;
            $bPriority = $typePriority[$b['type']] ?? 0;
            
            if ($aPriority !== $bPriority) {
                return $bPriority - $aPriority;
            }
            
            return ($b['frequency'] ?? 0) - ($a['frequency'] ?? 0);
        });
        
        // Limit to 8 completions total
        $completions = array_slice($allCompletions, 0, 8);
        
    } catch (Exception $e) {
        error_log("Word completions error: " . $e->getMessage());
    }
    
    echo json_encode($completions);
    exit;
}

// AJAX handler for popular searches
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_popular_searches') {
    $query = trim($_POST['query'] ?? '');
    
    if (strlen($query) < 1) {
        echo json_encode([]);
        exit;
    }
    
    $popularSearches = [];
    
    try {
        // Get popular searches that start with the query
        $popularStmt = $pdo->prepare("
            (
                SELECT tag as term, 'hashtag' as type, usage_count as popularity
                FROM hashtags 
                WHERE tag ILIKE ? 
                ORDER BY usage_count DESC
                LIMIT 3
            )
            UNION
            (
                SELECT username as term, 'user' as type, 
                       (SELECT COUNT(*) FROM follows WHERE followed_id = users.id) as popularity
                FROM users 
                WHERE username ILIKE ? 
                ORDER BY popularity DESC
                LIMIT 3
            )
            UNION
            (
                SELECT post_header as term, 'header' as type, COUNT(*) as popularity
                FROM posts 
                WHERE post_header ILIKE ? 
                  AND post_header IS NOT NULL
                GROUP BY post_header
                ORDER BY popularity DESC
                LIMIT 3
            )
            ORDER BY popularity DESC
            LIMIT 8
        ");
        $popularStmt->execute([$query . '%', $query . '%', $query . '%']);
        $popularSearches = $popularStmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Popular searches error: " . $e->getMessage());
    }
    
    echo json_encode($popularSearches);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Search - Fbclone</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    line-height: 1.4;
    min-height: 100vh;
}

/* Header */
.search-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(123, 104, 238, 0.3);
    padding: 12px 16px;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 12px;
}

.back-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    padding: 10px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.back-btn:hover {
    background: rgba(123, 104, 238, 0.3);
    transform: scale(1.1);
}

.search-container {
    flex: 1;
    position: relative;
}

.search-input {
    width: 100%;
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(123, 104, 238, 0.3);
    border-radius: 25px;
    padding: 14px 20px;
    color: #fff;
    font-size: 16px;
    outline: none;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.search-input::placeholder {
    color: rgba(255, 255, 255, 0.6);
}

.search-input:focus {
    background: rgba(255, 255, 255, 0.15);
    border-color: #7b68ee;
    box-shadow: 0 4px 20px rgba(123, 104, 238, 0.3);
}

/* Search Suggestions */
.search-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    margin-top: 8px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    display: none;
    z-index: 1001;
    max-height: 500px;
    overflow-y: auto;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

.suggestion-item {
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    border-bottom: 1px solid rgba(123, 104, 238, 0.1);
    transition: all 0.3s ease;
    position: relative;
    color: #333;
}

.suggestion-item:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: translateX(5px);
}

.suggestion-item:last-child {
    border-bottom: none;
}

.suggestion-icon {
    font-size: 20px;
    width: 28px;
    text-align: center;
    flex-shrink: 0;
    color: #7b68ee;
}

.suggestion-content {
    flex: 1;
    min-width: 0;
}

.suggestion-text {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #2d3748;
}

.suggestion-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #718096;
}

.suggestion-type {
    text-transform: capitalize;
    padding: 4px 8px;
    border-radius: 12px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: #fff;
    font-weight: 600;
    font-size: 10px;
}

.suggestion-type.hashtag {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
}

.suggestion-type.user {
    background: linear-gradient(135deg, #48bb78, #38a169);
}

.suggestion-type.header {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
}

.suggestion-count {
    color: #a0aec0;
    font-weight: 500;
}

.suggestion-highlight {
    color: #7b68ee;
    font-weight: 700;
}

/* Word Completions */
.word-completions {
    padding: 12px 0;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
    background: rgba(255, 255, 255, 0.98);
}

.completion-title {
    padding: 8px 20px;
    font-size: 11px;
    color: #7b68ee;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    background: rgba(123, 104, 238, 0.05);
}

.completion-item {
    padding: 12px 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    color: #4a5568;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid rgba(123, 104, 238, 0.05);
}

.completion-item:hover {
    background: rgba(123, 104, 238, 0.1);
    color: #2d3748;
    transform: translateX(5px);
}

.completion-item:last-child {
    border-bottom: none;
}

.completion-prefix {
    color: #7b68ee;
    font-weight: 700;
}

.completion-suffix {
    flex: 1;
    font-weight: 500;
}

.completion-type {
    font-size: 10px;
    padding: 3px 8px;
    border-radius: 8px;
    background: rgba(123, 104, 238, 0.1);
    color: #7b68ee;
    font-weight: 600;
}

/* Popular Searches */
.popular-searches {
    padding: 12px 0;
    border-top: 1px solid rgba(123, 104, 238, 0.2);
    background: rgba(255, 255, 255, 0.98);
}

.popular-title {
    padding: 8px 20px;
    font-size: 11px;
    color: #7b68ee;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    background: rgba(123, 104, 238, 0.05);
}

.popular-item {
    padding: 12px 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    color: #4a5568;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(123, 104, 238, 0.05);
}

.popular-item:hover {
    background: rgba(123, 104, 238, 0.1);
    color: #2d3748;
    transform: translateX(5px);
}

.popular-item:last-child {
    border-bottom: none;
}

.popular-text {
    font-weight: 600;
    color: #2d3748;
}

.popular-stats {
    font-size: 11px;
    color: #7b68ee;
    font-weight: 600;
    background: rgba(123, 104, 238, 0.1);
    padding: 3px 8px;
    border-radius: 8px;
}

/* Main Content */
.main-content {
    margin-top: 80px;
    padding: 20px 16px;
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 20px;
    color: #fff;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

/* Trending Section */
.trending-grid {
    display: grid;
    gap: 16px;
    margin-bottom: 40px;
}

.trending-item {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    border: 2px solid rgba(123, 104, 238, 0.2);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.trending-item:hover {
    background: rgba(255, 255, 255, 0.98);
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(123, 104, 238, 0.3);
    border-color: #7b68ee;
}

.trending-number {
    font-size: 12px;
    color: #7b68ee;
    margin-bottom: 6px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.trending-text {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 6px;
    color: #2d3748;
}

.trending-count {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
}

.trending-badge {
    position: absolute;
    top: 16px;
    right: 16px;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: #fff;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Recent Searches */
.recent-searches {
    margin-bottom: 40px;
}

.recent-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    transition: all 0.3s ease;
}

.recent-item:hover {
    transform: translateX(10px);
    border-bottom-color: rgba(123, 104, 238, 0.5);
}

.recent-item:last-child {
    border-bottom: none;
}

.recent-text {
    font-size: 16px;
    font-weight: 600;
    color: #fff;
}

.recent-remove {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.recent-remove:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.1);
}

/* Discover Section */
.discover-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.discover-item {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 15px;
    padding: 24px 16px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid rgba(123, 104, 238, 0.2);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.discover-item:hover {
    background: rgba(255, 255, 255, 0.98);
    transform: translateY(-5px) scale(1.02);
    box-shadow: 0 12px 35px rgba(123, 104, 238, 0.3);
    border-color: #7b68ee;
}

.discover-icon {
    font-size: 32px;
    margin-bottom: 12px;
    filter: grayscale(0.3);
    transition: all 0.3s ease;
}

.discover-item:hover .discover-icon {
    filter: grayscale(0);
    transform: scale(1.2);
}

.discover-text {
    font-size: 14px;
    font-weight: 700;
    color: #2d3748;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: rgba(255, 255, 255, 0.8);
}

.empty-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.7;
    filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
}

.empty-text {
    font-size: 18px;
    margin-bottom: 12px;
    font-weight: 600;
}

.empty-subtext {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.6);
}

/* Loading Animation */
.loading-dots {
    display: inline-flex;
    gap: 4px;
}

.loading-dots span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #7b68ee;
    animation: bounce 1.4s infinite ease-in-out both;
}

.loading-dots span:nth-child(1) { animation-delay: -0.32s; }
.loading-dots span:nth-child(2) { animation-delay: -0.16s; }

@keyframes bounce {
    0%, 80%, 100% { 
        transform: scale(0);
        opacity: 0.5;
    }
    40% { 
        transform: scale(1);
        opacity: 1;
    }
}

/* Animation for content */
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

.trending-item, .recent-item, .discover-item {
    animation: fadeInUp 0.6s ease-out;
}

.trending-item:nth-child(1) { animation-delay: 0.1s; }
.trending-item:nth-child(2) { animation-delay: 0.2s; }
.trending-item:nth-child(3) { animation-delay: 0.3s; }
.trending-item:nth-child(4) { animation-delay: 0.4s; }
.trending-item:nth-child(5) { animation-delay: 0.5s; }

.recent-item:nth-child(1) { animation-delay: 0.2s; }
.recent-item:nth-child(2) { animation-delay: 0.3s; }
.recent-item:nth-child(3) { animation-delay: 0.4s; }
.recent-item:nth-child(4) { animation-delay: 0.5s; }

.discover-item:nth-child(1) { animation-delay: 0.3s; }
.discover-item:nth-child(2) { animation-delay: 0.4s; }
.discover-item:nth-child(3) { animation-delay: 0.5s; }
.discover-item:nth-child(4) { animation-delay: 0.6s; }

/* Responsive */
@media (max-width: 768px) {
    .discover-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .search-header {
        padding: 10px 12px;
    }
    
    .search-input {
        padding: 12px 16px;
        font-size: 15px;
    }
    
    .main-content {
        padding: 16px 12px;
        margin-top: 70px;
    }
}

@media (max-width: 480px) {
    .main-content {
        padding: 12px 10px;
    }
    
    .discover-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .trending-grid {
        gap: 12px;
    }
    
    .trending-item, .discover-item {
        padding: 16px;
    }
    
    .section-title {
        font-size: 18px;
        margin-bottom: 16px;
    }
    
    .back-btn {
        width: 36px;
        height: 36px;
        font-size: 16px;
    }
}
</style>
</head>
<body>

<!-- Header -->
<div class="search-header">
    <button class="back-btn" onclick="history.back()">←</button>
    <div class="search-container">
        <input type="text" class="search-input" placeholder="Search accounts, hashtags, and videos..." id="searchInput">
        <div class="search-suggestions" id="searchSuggestions"></div>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <?php if (empty($_GET['q'])): ?>
        
        <!-- Trending Hashtags -->
        <?php if (!empty($trendingHashtags)): ?>
            <div class="trending-section">
                <h2 class="section-title">Trending Hashtags</h2>
                <div class="trending-grid">
                    <?php foreach ($trendingHashtags as $index => $hashtag): ?>
                        <div class="trending-item" onclick="searchHashtag('<?= htmlspecialchars($hashtag['tag']) ?>')">
                            <div class="trending-number">#<?= $index + 1 ?> Trending</div>
                            <div class="trending-text">#<?= htmlspecialchars($hashtag['tag']) ?></div>
                            <div class="trending-count"><?= $hashtag['usage_count'] ?> posts</div>
                            <div class="trending-badge">HASHTAG</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Popular Searches -->
        <?php if (!empty($trendingSearches)): ?>
            <div class="recent-searches">
                <h2 class="section-title">Popular Searches</h2>
                <?php foreach ($trendingSearches as $search): ?>
                    <div class="recent-item" onclick="searchTerm('<?= htmlspecialchars($search['term']) ?>')">
                        <div class="recent-text">
                            <?= htmlspecialchars($search['term']) ?>
                            <?php if ($search['source'] === 'hashtag'): ?>
                                <span style="color: #fe2c55; margin-left: 4px;">#</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?= $search['count'] ?> posts
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Discover Categories -->
        <div class="discover-section">
            <h2 class="section-title">Discover</h2>
            <div class="discover-grid">
                <div class="discover-item" onclick="searchTerm('comedy')">
                    <div class="discover-icon">😂</div>
                    <div class="discover-text">Comedy</div>
                </div>
                <div class="discover-item" onclick="searchTerm('dance')">
                    <div class="discover-icon">💃</div>
                    <div class="discover-text">Dance</div>
                </div>
                <div class="discover-item" onclick="searchTerm('music')">
                    <div class="discover-icon">🎵</div>
                    <div class="discover-text">Music</div>
                </div>
                <div class="discover-item" onclick="searchTerm('food')">
                    <div class="discover-icon">🍔</div>
                    <div class="discover-text">Food</div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Search results will be shown here via AJAX -->
        <div id="searchResults"></div>
    <?php endif; ?>
</div>

<script>
class TikTokSearch {
    constructor() {
        this.searchInput = document.getElementById('searchInput');
        this.suggestionsContainer = document.getElementById('searchSuggestions');
        this.searchTimeout = null;
        this.isLoading = false;
        this.lastQuery = '';
        
        this.init();
    }
    
    init() {
        this.searchInput.addEventListener('input', (e) => {
            this.handleSearchInput(e.target.value);
        });
        
        this.searchInput.addEventListener('focus', () => {
            if (this.searchInput.value.length >= 1) {
                this.showSuggestions();
            }
        });
        
        document.addEventListener('click', (e) => {
            if (!this.searchInput.contains(e.target) && !this.suggestionsContainer.contains(e.target)) {
                this.hideSuggestions();
            }
        });
        
        // Handle Enter key
        this.searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.performSearch(this.searchInput.value);
            }
        });
    }
    
    handleSearchInput(query) {
        this.lastQuery = query;
        clearTimeout(this.searchTimeout);
        
        if (query.length < 1) {
            this.hideSuggestions();
            return;
        }
        
        this.searchTimeout = setTimeout(() => {
            this.fetchAllSuggestions(query);
        }, 150);
    }
    
    async fetchAllSuggestions(query) {
        if (this.isLoading) return;
        
        this.isLoading = true;
        this.showLoadingSuggestions();
        
        try {
            // Fetch regular suggestions
            const suggestions = await this.fetchSuggestions(query);
            this.displaySuggestions(suggestions, query);
            
            // Fetch word completions for longer queries
            if (query.length >= 2) {
                const completions = await this.fetchWordCompletions(query);
                this.displayWordCompletions(completions, query);
            }
            
            // Fetch popular searches
            const popularSearches = await this.fetchPopularSearches(query);
            this.displayPopularSearches(popularSearches, query);
            
        } catch (error) {
            console.error('Error fetching suggestions:', error);
            this.hideSuggestions();
        }
        
        this.isLoading = false;
    }
    
    async fetchSuggestions(query) {
        const formData = new FormData();
        formData.append('action', 'get_suggestions');
        formData.append('query', query);
        
        const response = await fetch('search3.php', {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    }
    
    async fetchWordCompletions(query) {
        const formData = new FormData();
        formData.append('action', 'get_word_completions');
        formData.append('query', query);
        
        const response = await fetch('search3.php', {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    }
    
    async fetchPopularSearches(query) {
        const formData = new FormData();
        formData.append('action', 'get_popular_searches');
        formData.append('query', query);
        
        const response = await fetch('search3.php', {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    }
    
    showLoadingSuggestions() {
        this.suggestionsContainer.innerHTML = `
            <div class="suggestion-item">
                <div class="suggestion-icon">⏳</div>
                <div class="suggestion-content">
                    <div class="suggestion-text">
                        <div class="loading-dots">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                    <div class="suggestion-meta">
                        <span class="suggestion-type">Searching</span>
                    </div>
                </div>
            </div>
        `;
        this.showSuggestions();
    }
    
    displaySuggestions(suggestions, query) {
        let html = '';
        
        if (suggestions.length === 0) {
            html = `
                <div class="suggestion-item">
                    <div class="suggestion-icon">🔍</div>
                    <div class="suggestion-content">
                        <div class="suggestion-text">Search for "${this.escapeHtml(query)}"</div>
                        <div class="suggestion-meta">
                            <span class="suggestion-type">search</span>
                        </div>
                    </div>
                </div>
            `;
        } else {
            // Display all suggestions grouped by type
            suggestions.forEach(suggestion => {
                const displayText = suggestion.term || suggestion.tag;
                const highlightedText = this.highlightQuery(displayText, query);
                const count = suggestion.usage_count || suggestion.relevance || suggestion.follower_count || suggestion.frequency;
                const type = suggestion.type;
                
                html += `
                    <div class="suggestion-item" onclick="tiktokSearch.selectSuggestion('${type}', '${this.escapeHtml(displayText)}')">
                        <div class="suggestion-icon">${this.getSuggestionIcon(type)}</div>
                        <div class="suggestion-content">
                            <div class="suggestion-text">${highlightedText}</div>
                            <div class="suggestion-meta">
                                <span class="suggestion-type ${type}">${type}</span>
                                ${count ? `<span class="suggestion-count">${count} ${this.getCountLabel(type)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
        }
        
        this.suggestionsContainer.innerHTML = html;
        this.showSuggestions();
    }
    
    displayWordCompletions(completions, query) {
        if (completions.length === 0) return;
        
        let completionsHtml = `
            <div class="word-completions">
                <div class="completion-title">Word Completions</div>
        `;
        
        completions.forEach(completion => {
            const fullText = completion.completion;
            const remainingText = fullText.substring(query.length);
            const type = completion.type;
            
            completionsHtml += `
                <div class="completion-item" onclick="tiktokSearch.selectCompletion('${this.escapeHtml(fullText)}', '${type}')">
                    <span class="completion-prefix">${this.escapeHtml(query)}</span>
                    <span class="completion-suffix">${this.escapeHtml(remainingText)}</span>
                    <span class="completion-type">${type}</span>
                </div>
            `;
        });
        
        completionsHtml += `</div>`;
        
        // Append completions to existing suggestions
        this.suggestionsContainer.innerHTML += completionsHtml;
    }
    
    displayPopularSearches(searches, query) {
        if (searches.length === 0) return;
        
        let popularHtml = `
            <div class="popular-searches">
                <div class="popular-title">Popular Searches</div>
        `;
        
        searches.forEach(search => {
            const displayText = search.term;
            const type = search.type;
            const popularity = search.popularity;
            
            popularHtml += `
                <div class="popular-item" onclick="tiktokSearch.selectSuggestion('${type}', '${this.escapeHtml(displayText)}')">
                    <span class="popular-text">${this.escapeHtml(displayText)}</span>
                    <span class="popular-stats">${popularity} ${this.getCountLabel(type)}</span>
                </div>
            `;
        });
        
        popularHtml += `</div>`;
        
        // Append popular searches to existing suggestions
        this.suggestionsContainer.innerHTML += popularHtml;
    }
    
    highlightQuery(text, query) {
        if (!query) return this.escapeHtml(text);
        
        const lowerText = text.toLowerCase();
        const lowerQuery = query.toLowerCase();
        const index = lowerText.indexOf(lowerQuery);
        
        if (index === -1) return this.escapeHtml(text);
        
        const before = text.substring(0, index);
        const match = text.substring(index, index + query.length);
        const after = text.substring(index + query.length);
        
        return `${this.escapeHtml(before)}<span class="suggestion-highlight">${this.escapeHtml(match)}</span>${this.escapeHtml(after)}`;
    }
    
    getCountLabel(type) {
        const labels = {
            'hashtag': 'posts',
            'user': 'followers',
            'header': 'posts',
            'content': 'uses'
        };
        return labels[type] || '';
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    getSuggestionIcon(type) {
        const icons = {
            'hashtag': '#️⃣',
            'header': '📝',
            'user': '👤',
            'content': '🔤'
        };
        return icons[type] || '🔍';
    }
    
    selectSuggestion(type, value) {
        let searchQuery = value;
        
        if (type === 'hashtag') {
            searchQuery = `#${value}`;
        }
        
        this.searchInput.value = searchQuery;
        this.performSearch(searchQuery);
        this.hideSuggestions();
    }
    
    selectCompletion(fullText, type) {
        let searchQuery = fullText;
        
        if (type === 'hashtag') {
            searchQuery = `#${fullText}`;
        }
        
        this.searchInput.value = searchQuery;
        this.performSearch(searchQuery);
        this.hideSuggestions();
    }
    
    performSearch(query) {
        if (!query.trim()) return;
        
        // Redirect to search results page
        window.location.href = `search_result3.php?q=${encodeURIComponent(query)}`;
    }
    
    showSuggestions() {
        this.suggestionsContainer.style.display = 'block';
    }
    
    hideSuggestions() {
        this.suggestionsContainer.style.display = 'none';
    }
}

// Global functions for onclick handlers
function searchHashtag(hashtag) {
    window.location.href = `search_result3.php?q=%23${encodeURIComponent(hashtag)}`;
}

function searchTerm(term) {
    window.location.href = `search_result3.php?q=${encodeURIComponent(term)}`;
}

// Initialize search when page loads
const tiktokSearch = new TikTokSearch();

// Focus search input on page load
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('searchInput').focus();
});
</script>

</body>
</html>