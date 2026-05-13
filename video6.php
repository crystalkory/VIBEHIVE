 <?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";
// Get user's country from database
$userId = $_SESSION['user_id'];
$userStmt = $pdo->prepare("SELECT country FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userCountry = $userStmt->fetchColumn();

// Define available categories
$categories = [
    'All' => 'All Videos',
    'Entertainment' => 'Entertainment',
    'Dance' => 'Dance',
    'Lip-Sync' => 'Lip-Sync',
    'Comedy' => 'Comedy',
    'Music' => 'Music',
    'Beauty and Fashion' => 'Beauty and Fashion',
    'Food and Cooking' => 'Food and Cooking',
    'DIY and Crafting' => 'DIY and Crafting',
    'Gaming' => 'Gaming'
];

// Get selected category from URL or default to 'All'
$selectedCategory = $_GET['category'] ?? 'All';
if (!array_key_exists($selectedCategory, $categories)) {
    $selectedCategory = 'All';
}

// MODIFIED: Get user's viewed videos - only count videos watched for at least 5 seconds
$viewedVideosStmt = $pdo->prepare("
    SELECT DISTINCT post_id 
    FROM user_behavior_tracking 
    WHERE user_id = ? 
    AND interaction_type IN ('view', 'progress', 'completion')
    AND watch_time >= 5  -- Only count videos watched for at least 5 seconds
");
$viewedVideosStmt->execute([$userId]);
$viewedVideoIds = $viewedVideosStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// NEW: Enhanced User Preference Tracking with Hashtag Support
class UserPreferenceTracker {
    private $pdo;
    private $userId;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    // Track download as strong positive signal
    public function trackDownload($postId, $authorId) {
        try {
            // Strong positive signal for the author
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, 'download', 2.0, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + 2.0,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId]);
            
            // Also track category preference
            $this->updateCategoryPreferenceFromPost($postId, 1.5);
            
            // Track hashtags from post
            $this->updateHashtagPreferences($postId, 1.5);
            
            return true;
        } catch (Exception $e) {
            error_log("Download tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track video completion as strong positive signal
    public function trackCompletion($postId, $authorId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, 'completion', 1.5, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + 1.5,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId]);
            
            $this->updateCategoryPreferenceFromPost($postId, 1.0);
            $this->updateHashtagPreferences($postId, 1.0);
            
            return true;
        } catch (Exception $e) {
            error_log("Completion tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track rewatch as very strong positive signal
    public function trackRewatch($postId, $authorId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, 'rewatch', 2.5, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + 2.5,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId]);
            
            $this->updateCategoryPreferenceFromPost($postId, 2.0);
            $this->updateHashtagPreferences($postId, 2.0);
            
            return true;
        } catch (Exception $e) {
            error_log("Rewatch tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track consecutive completions for same author
    public function trackConsecutiveCompletion($authorId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, 'consecutive_watch', 3.0, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + 1.0,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId]);
            
            return true;
        } catch (Exception $e) {
            error_log("Consecutive completion tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track negative signals
    public function trackNegativeSignal($postId, $authorId, $signalType) {
        try {
            $strength = -2.0; // Strong negative signal
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + ?,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId, $signalType, $strength, $strength]);
            
            // Check if user should be blocked after 3 negative signals
            $this->checkAuthorBlock($authorId);
            
            return true;
        } catch (Exception $e) {
            error_log("Negative signal tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track quick scroll-away (less than 2 seconds)
    public function trackQuickScrollAway($postId, $authorId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, 'quick_skip', -1.5, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength - 1.5,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId]);
            
            return true;
        } catch (Exception $e) {
            error_log("Quick scroll tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track engagement signals (like, comment, share)
    public function trackEngagement($postId, $authorId, $engagementType) {
        try {
            $strengths = [
                'like' => 1.2,
                'comment' => 1.8,
                'share' => 2.2
            ];
            
            $strength = $strengths[$engagementType] ?? 1.0;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + ?,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId, $engagementType, $strength, $strength]);
            
            $this->updateCategoryPreferenceFromPost($postId, $strength * 0.8);
            $this->updateHashtagPreferences($postId, $strength * 0.8);
            
            return true;
        } catch (Exception $e) {
            error_log("Engagement tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Update category preferences based on interaction
    private function updateCategoryPreferenceFromPost($postId, $strength) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_category_preferences 
                (user_id, category, preference_score, created_at) 
                SELECT ?, category1, ?, NOW() FROM posts WHERE id = ?
                UNION ALL
                SELECT ?, category2, ?, NOW() FROM posts WHERE id = ?
                UNION ALL
                SELECT ?, category3, ?, NOW() FROM posts WHERE id = ?
                ON CONFLICT (user_id, category) 
                DO UPDATE SET 
                    preference_score = user_category_preferences.preference_score + ?,
                    updated_at = NOW()
            ");
            $stmt->execute([
                $this->userId, $strength, $postId,
                $this->userId, $strength, $postId,
                $this->userId, $strength, $postId,
                $strength
            ]);
        } catch (Exception $e) {
            error_log("Category preference update error: " . $e->getMessage());
        }
    }
    
    // NEW: Update hashtag preferences based on interaction
    private function updateHashtagPreferences($postId, $strength) {
        try {
            // Extract hashtags from post content and header
            $stmt = $this->pdo->prepare("
                SELECT content, post_header FROM posts WHERE id = ?
            ");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($post) {
                $text = $post['content'] . ' ' . ($post['post_header'] ?? '');
                preg_match_all('/#(\w+)/', $text, $matches);
                $hashtags = array_unique($matches[1]);
                
                foreach ($hashtags as $hashtag) {
                    $hashtag = strtolower($hashtag);
                    $stmt = $this->pdo->prepare("
                        INSERT INTO user_hashtag_preferences 
                        (user_id, hashtag, preference_score, created_at) 
                        VALUES (?, ?, ?, NOW())
                        ON CONFLICT (user_id, hashtag) 
                        DO UPDATE SET 
                            preference_score = user_hashtag_preferences.preference_score + ?,
                            updated_at = NOW()
                    ");
                    $stmt->execute([$this->userId, $hashtag, $strength, $strength]);
                }
            }
        } catch (Exception $e) {
            error_log("Hashtag preference update error: " . $e->getMessage());
        }
    }
    
    // Check if author should be blocked after 3 negative signals
    private function checkAuthorBlock($authorId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as negative_count 
                FROM user_preference_signals 
                WHERE user_id = ? AND author_id = ? AND signal_strength < 0
            ");
            $stmt->execute([$this->userId, $authorId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['negative_count'] >= 3) {
                // Block this author (except for trending posts)
                $blockStmt = $this->pdo->prepare("
                    INSERT INTO user_blocked_authors 
                    (user_id, author_id, created_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT DO NOTHING
                ");
                $blockStmt->execute([$this->userId, $authorId]);
            }
        } catch (Exception $e) {
            error_log("Author block check error: " . $e->getMessage());
        }
    }
    
    // Get user's preference score for an author
    public function getAuthorPreferenceScore($authorId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT SUM(signal_strength) as total_score 
                FROM user_preference_signals 
                WHERE user_id = ? AND author_id = ?
            ");
            $stmt->execute([$this->userId, $authorId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['total_score'] ?? 0;
        } catch (Exception $e) {
            error_log("Author preference score error: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get user's category preference scores
    public function getCategoryPreferences() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT category, preference_score 
                FROM user_category_preferences 
                WHERE user_id = ? 
                ORDER BY preference_score DESC
            ");
            $stmt->execute([$this->userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Category preferences error: " . $e->getMessage());
            return [];
        }
    }
    
    // NEW: Get user's hashtag preference scores
    public function getHashtagPreferences() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT hashtag, preference_score 
                FROM user_hashtag_preferences 
                WHERE user_id = ? 
                ORDER BY preference_score DESC
                LIMIT 20
            ");
            $stmt->execute([$this->userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Hashtag preferences error: " . $e->getMessage());
            return [];
        }
    }
    
    // Check if author is blocked
    public function isAuthorBlocked($authorId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM user_blocked_authors 
                WHERE user_id = ? AND author_id = ?
            ");
            $stmt->execute([$this->userId, $authorId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Author block check error: " . $e->getMessage());
            return false;
        }
    }
}

// NEW: Country-based Content Prioritization
class CountryContentPrioritizer {
    private $pdo;
    private $userCountry;
    
    public function __construct($pdo, $userCountry) {
        $this->pdo = $pdo;
        $this->userCountry = $userCountry;
    }
    
    // Get trending posts from user's country
    public function getTrendingPostsFromCountry($limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.*,
                    u.username,
                    u.profile_pic_url,
                    u.country,
                    COALESCE(l.likes_count, 0) as likes_count,
                    COALESCE(c.comments_count, 0) as comments_count,
                    COALESCE(s.shares_count, 0) as shares_count,
                    COALESCE(v.views_count, 0) as views_count,
                    (COALESCE(l.likes_count, 0) + COALESCE(c.comments_count, 0) * 2 + COALESCE(s.shares_count, 0) * 3) as engagement_score
                FROM posts p
                JOIN users u ON p.user_id = u.id
                LEFT JOIN (
                    SELECT post_id, COUNT(*) as likes_count 
                    FROM likes 
                    GROUP BY post_id
                ) l ON p.id = l.post_id
                LEFT JOIN (
                    SELECT post_id, COUNT(*) as comments_count 
                    FROM comments 
                    GROUP BY post_id
                ) c ON p.id = c.post_id
                LEFT JOIN (
                    SELECT original_post_id, COUNT(*) as shares_count 
                    FROM shared_posts 
                    GROUP BY original_post_id
                ) s ON p.id = s.original_post_id
                LEFT JOIN (
                    SELECT post_id, COUNT(*) as views_count 
                    FROM video_views 
                    GROUP BY post_id
                ) v ON p.id = v.post_id
                WHERE u.country = ?
                AND p.created_at > NOW() - INTERVAL '24 hours'
                AND p.post_type = 'video'
                ORDER BY engagement_score DESC
                LIMIT ?
            ");
            $stmt->execute([$this->userCountry, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Country trending posts error: " . $e->getMessage());
            return [];
        }
    }
    
    // Calculate country relevance score for a post
    public function calculateCountryRelevanceScore($postAuthorId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT country FROM users WHERE id = ?
            ");
            $stmt->execute([$postAuthorId]);
            $authorCountry = $stmt->fetchColumn();
            
            if ($authorCountry === $this->userCountry) {
                return 1.0; // Same country - high relevance
            }
            
            // Check if countries are in same region (you can expand this logic)
            $regions = $this->getCountryRegions();
            $userRegion = $regions[$this->userCountry] ?? null;
            $authorRegion = $regions[$authorCountry] ?? null;
            
            if ($userRegion && $authorRegion && $userRegion === $authorRegion) {
                return 0.7; // Same region - medium relevance
            }
            
            return 0.3; // Different country/region - lower relevance
        } catch (Exception $e) {
            error_log("Country relevance calculation error: " . $e->getMessage());
            return 0.3;
        }
    }
    
    // Simple region mapping (you can expand this)
    private function getCountryRegions() {
        return [
            'US' => 'North America',
            'CA' => 'North America',
            'GB' => 'Europe',
            'FR' => 'Europe',
            'DE' => 'Europe',
            'IT' => 'Europe',
            'ES' => 'Europe',
            'BR' => 'South America',
            'MX' => 'North America',
            'JP' => 'Asia',
            'KR' => 'Asia',
            'CN' => 'Asia',
            'IN' => 'Asia',
            'AU' => 'Oceania',
            'NG' => 'Africa',
            'ZA' => 'Africa',
            'EG' => 'Africa',
            'KE' => 'Africa',
            'GH' => 'Africa'
        ];
    }
}

// NEW: Hashtag-based Content Analysis
class HashtagAnalyzer {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Extract hashtags from text
    public function extractHashtags($text) {
        preg_match_all('/#(\w+)/', $text, $matches);
        return array_unique($matches[1]);
    }
    
    // Calculate hashtag relevance score for user
    public function calculateHashtagRelevanceScore($postId, $userHashtagPreferences) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT content, post_header FROM posts WHERE id = ?
            ");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$post) return 0;
            
            $text = $post['content'] . ' ' . ($post['post_header'] ?? '');
            $postHashtags = $this->extractHashtags($text);
            
            if (empty($postHashtags)) return 0;
            
            $totalScore = 0;
            $matchedTags = 0;
            
            // Create hashtag preference map for quick lookup
            $preferenceMap = [];
            foreach ($userHashtagPreferences as $pref) {
                $preferenceMap[$pref['hashtag']] = $pref['preference_score'];
            }
            
            foreach ($postHashtags as $hashtag) {
                $hashtag = strtolower($hashtag);
                if (isset($preferenceMap[$hashtag])) {
                    $totalScore += $preferenceMap[$hashtag];
                    $matchedTags++;
                }
            }
            
            if ($matchedTags > 0) {
                return ($totalScore / $matchedTags) * min($matchedTags / 3, 1.0);
            }
            
            return 0;
        } catch (Exception $e) {
            error_log("Hashtag relevance calculation error: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get trending hashtags from user's country
    public function getTrendingHashtagsByCountry($country, $limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                WITH post_hashtags AS (
                    SELECT 
                        UNNEST(ARRAY(
                            SELECT LOWER(REGEXP_MATCHES(content || ' ' || COALESCE(post_header, ''), '#(\\w+)', 'g'))[1]
                        )) as hashtag,
                        p.id as post_id
                    FROM posts p
                    JOIN users u ON p.user_id = u.id
                    WHERE u.country = ?
                    AND p.created_at > NOW() - INTERVAL '24 hours'
                ),
                hashtag_stats AS (
                    SELECT 
                        hashtag,
                        COUNT(*) as usage_count,
                        COUNT(DISTINCT ph.post_id) as post_count
                    FROM post_hashtags ph
                    GROUP BY hashtag
                )
                SELECT 
                    hashtag,
                    usage_count,
                    post_count,
                    usage_count::float / post_count as engagement_per_post
                FROM hashtag_stats
                WHERE usage_count >= 3
                ORDER BY usage_count DESC, engagement_per_post DESC
                LIMIT ?
            ");
            $stmt->execute([$country, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Trending hashtags error: " . $e->getMessage());
            return [];
        }
    }
}

// Content Analysis Service (Simulated AI)
class ContentAnalysisService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Simulate computer vision analysis
    public function analyzeVideoContent($videoUrl, $caption) {
        $dominantColors = $this->extractDominantColors($videoUrl);
        $motionPattern = $this->analyzeMotionPattern($videoUrl);
        $sceneComplexity = $this->calculateSceneComplexity($videoUrl);
        $textInVideo = $this->extractTextFromVideo($videoUrl);
        
        return [
            'dominant_colors' => $dominantColors,
            'motion_intensity' => $motionPattern,
            'scene_complexity' => $sceneComplexity,
            'detected_text' => $textInVideo,
            'content_mood' => $this->analyzeMood($caption, $textInVideo),
            'aesthetic_score' => $this->calculateAestheticScore($dominantColors, $motionPattern, $sceneComplexity)
        ];
    }
    
    // Simulate audio analysis
    public function analyzeAudioContent($videoUrl) {
        return [
            'music_presence' => rand(0, 100) / 100,
            'speech_presence' => rand(0, 100) / 100,
            'background_noise' => rand(0, 30) / 100,
            'audio_quality' => rand(70, 100) / 100,
            'beat_strength' => rand(0, 100) / 100
        ];
    }
    
    // Simulate text sentiment analysis
    public function analyzeTextSentiment($text) {
        $positiveWords = ['love', 'amazing', 'great', 'awesome', 'beautiful', 'perfect', 'happy', 'good', 'nice', 'excellent'];
        $negativeWords = ['hate', 'terrible', 'awful', 'bad', 'horrible', 'dislike', 'sad', 'angry', 'worst', 'boring'];
        
        $text = strtolower($text);
        $positiveCount = 0;
        $negativeCount = 0;
        
        foreach ($positiveWords as $word) {
            $positiveCount += substr_count($text, $word);
        }
        
        foreach ($negativeWords as $word) {
            $negativeCount += substr_count($text, $word);
        }
        
        $total = $positiveCount + $negativeCount;
        if ($total === 0) return 0.5;
        
        return $positiveCount / $total;
    }
    
    private function extractDominantColors($videoUrl) {
        return ['#' . substr(md5($videoUrl), 0, 6), '#' . substr(md5($videoUrl), 6, 6)];
    }
    
    private function analyzeMotionPattern($videoUrl) {
        return rand(10, 90) / 100;
    }
    
    private function calculateSceneComplexity($videoUrl) {
        return rand(20, 80) / 100;
    }
    
    private function extractTextFromVideo($videoUrl) {
        $possibleText = ['funny', 'dance', 'challenge', 'tutorial', 'comedy', 'music', 'life', 'love', 'fashion', 'food'];
        shuffle($possibleText);
        return array_slice($possibleText, 0, 3);
    }
    
    private function analyzeMood($caption, $detectedText) {
        $allText = $caption . ' ' . implode(' ', $detectedText);
        return $this->analyzeTextSentiment($allText);
    }
    
    private function calculateAestheticScore($colors, $motion, $complexity) {
        return ($motion * 0.3 + $complexity * 0.4 + (count($colors) / 10) * 0.3);
    }
}

// Real-time Trend Detection Engine
class TrendDetectionEngine {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function detectEmergingTrends($timeWindow = '6 hours') {
        $stmt = $this->pdo->prepare("
            SELECT 
                p.category1,
                p.category2,
                p.category3,
                COUNT(*) as post_count,
                AVG(l.likes_count) as avg_likes,
                AVG(c.comments_count) as avg_comments,
                AVG(s.shares_count) as avg_shares,
                COUNT(DISTINCT p.user_id) as unique_creators,
                EXTRACT(EPOCH FROM (NOW() - MIN(p.created_at))) / 3600 as hours_since_first
            FROM posts p
            LEFT JOIN (
                SELECT post_id, COUNT(*) as likes_count 
                FROM likes 
                GROUP BY post_id
            ) l ON p.id = l.post_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) as comments_count 
                FROM comments 
                GROUP BY post_id
            ) c ON p.id = c.post_id
            LEFT JOIN (
                SELECT original_post_id, COUNT(*) as shares_count 
                FROM shared_posts 
                GROUP BY original_post_id
            ) s ON p.id = s.original_post_id
            WHERE p.created_at > NOW() - INTERVAL ?
            GROUP BY p.category1, p.category2, p.category3
            HAVING COUNT(*) >= 3
            ORDER BY (AVG(l.likes_count) + AVG(c.comments_count) * 2 + AVG(s.shares_count) * 3) / GREATEST(hours_since_first, 1) DESC
            LIMIT 10
        ");
        $stmt->execute([$timeWindow]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
   public function calculateTrendMomentum($category) {
    $stmt = $this->pdo->prepare("
        SELECT 
            COUNT(*) as current_posts,
            COALESCE(AVG(like_counts.likes_count), 0) as current_engagement,
            (
                SELECT COUNT(*) 
                FROM posts p2 
                WHERE (p2.category1 = ? OR p2.category2 = ? OR p2.category3 = ?)
                AND p2.created_at BETWEEN NOW() - INTERVAL '12 hours' AND NOW() - INTERVAL '6 hours'
            ) as previous_posts,
            (
                SELECT COALESCE(AVG(l2.likes_count), 0)
                FROM posts p2
                LEFT JOIN (
                    SELECT post_id, COUNT(*) as likes_count 
                    FROM likes 
                    GROUP BY post_id
                ) l2 ON p2.id = l2.post_id
                WHERE (p2.category1 = ? OR p2.category2 = ? OR p2.category3 = ?)
                AND p2.created_at BETWEEN NOW() - INTERVAL '12 hours' AND NOW() - INTERVAL '6 hours'
            ) as previous_engagement
        FROM posts p
        LEFT JOIN (
            SELECT post_id, COUNT(*) as likes_count 
            FROM likes 
            GROUP BY post_id
        ) like_counts ON p.id = like_counts.post_id
        WHERE (p.category1 = ? OR p.category2 = ? OR p.category3 = ?)
        AND p.created_at > NOW() - INTERVAL '6 hours'
    ");
    $stmt->execute(array_fill(0, 9, $category));
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data['previous_posts'] > 0) {
        $growthRate = ($data['current_posts'] - $data['previous_posts']) / $data['previous_posts'];
        $engagementGrowth = ($data['current_engagement'] - $data['previous_engagement']) / max($data['previous_engagement'], 1);
        return ($growthRate + $engagementGrowth) / 2;
    }
    
    return 0;
}
}

// Advanced User Behavior Model
class AdvancedUserModel {
    private $pdo;
    private $userId;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    public function getUserBehaviorPattern() {
        $stmt = $this->pdo->prepare("
            SELECT 
                EXTRACT(HOUR FROM ubt.created_at) as hour_of_day,
                EXTRACT(DOW FROM ubt.created_at) as day_of_week,
                AVG(ubt.watch_time) as avg_watch_time,
                AVG(ubt.completion_rate) as avg_completion,
                AVG(ubt.scroll_velocity) as avg_scroll_speed,
                p.category1,
                p.category2,
                p.category3,
                COUNT(*) as interaction_count,
                CASE 
                    WHEN ubt.completion_rate > 0.8 THEN 'deep'
                    WHEN ubt.completion_rate > 0.4 THEN 'medium'
                    ELSE 'shallow'
                END as engagement_depth,
                us.duration as session_duration,
                us.videos_watched as session_videos
            FROM user_behavior_tracking ubt
            LEFT JOIN posts p ON ubt.post_id = p.id
            LEFT JOIN user_sessions us ON ubt.user_id = us.user_id 
                AND DATE(ubt.created_at) = DATE(us.created_at)
            WHERE ubt.user_id = ? 
            AND ubt.created_at > NOW() - INTERVAL '30 days'
            GROUP BY 
                EXTRACT(HOUR FROM ubt.created_at), 
                EXTRACT(DOW FROM ubt.created_at),
                p.category1, p.category2, p.category3,
                engagement_depth,
                us.duration, us.videos_watched
            ORDER BY interaction_count DESC
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function calculateUserAttentionSpan() {
        $stmt = $this->pdo->prepare("
            SELECT 
                AVG(watch_time) as avg_watch_time,
                STDDEV(watch_time) as watch_time_stddev,
                AVG(completion_rate) as avg_completion,
                COUNT(*) as total_views
            FROM user_behavior_tracking 
            WHERE user_id = ? 
            AND created_at > NOW() - INTERVAL '7 days'
        ");
        $stmt->execute([$this->userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data['total_views'] > 0) {
            $consistency = 1 - min($data['watch_time_stddev'] / max($data['avg_watch_time'], 1), 1);
            return ($data['avg_completion'] * 0.6 + $consistency * 0.4);
        }
        
        return 0.5;
    }
    
    public function getMicroInteractionPatterns() {
        $stmt = $this->pdo->prepare("
            SELECT 
                interaction_type,
                COUNT(*) as count,
                AVG(completion_rate) as avg_completion,
                AVG(watch_time) as avg_watch_time,
                AVG(scroll_velocity) as avg_scroll_speed
            FROM user_behavior_tracking 
            WHERE user_id = ? 
            AND created_at > NOW() - INTERVAL '7 days'
            GROUP BY interaction_type
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Enhanced TikTok Algorithm Class with NEW features including country and hashtag prioritization
class TikTokAdvancedAlgorithm {
    private $pdo;
    private $userId;
    private $contentAnalyzer;
    private $trendEngine;
    private $userModel;
    private $preferenceTracker;
    private $countryPrioritizer;
    private $hashtagAnalyzer;
    private $userCountry;
    
public function getCountryRelevanceScore($authorId) {
    if ($this->countryPrioritizer) {
        return $this->countryPrioritizer->calculateCountryRelevanceScore($authorId);
    }
    return 0.5; // Default score
}

public function getHashtagRelevanceScore($postId, $userHashtagPreferences) {
    if ($this->hashtagAnalyzer) {
        return $this->hashtagAnalyzer->calculateHashtagRelevanceScore($postId, $userHashtagPreferences);
    }
    return 0; // Default score
}

    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->contentAnalyzer = new ContentAnalysisService($pdo);
        $this->trendEngine = new TrendDetectionEngine($pdo);
        $this->userModel = new AdvancedUserModel($pdo, $userId);
        $this->preferenceTracker = new UserPreferenceTracker($pdo, $userId);
        
        // Get user's country
        $stmt = $pdo->prepare("SELECT country FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $this->userCountry = $stmt->fetchColumn();
        
        $this->countryPrioritizer = new CountryContentPrioritizer($pdo, $this->userCountry);
        $this->hashtagAnalyzer = new HashtagAnalyzer($pdo);
    }
    
    // NEW: Enhanced preference-based scoring with country and hashtag prioritization
    public function calculateUserPreferenceScore($post) {
        $authorId = $post['user_id'];
        $score = 0;
        
        // Check if author is blocked (except for trending posts)
        if ($this->preferenceTracker->isAuthorBlocked($authorId)) {
            // Only show if it's trending (high engagement velocity)
            $trendingVelocity = $this->calculateTrendingVelocity($post['id']);
            if ($trendingVelocity < 0.7) {
                return -10; // Strong penalty for blocked authors
            }
        }
        
        
        // Get author preference score
        $authorPreference = $this->preferenceTracker->getAuthorPreferenceScore($authorId);
        $score += $authorPreference * 3; // Strong weight for author preference
        
        // Category preferences
        $categoryPreferences = $this->preferenceTracker->getCategoryPreferences();
        $preferenceMap = [];
        foreach ($categoryPreferences as $pref) {
            $preferenceMap[$pref['category']] = $pref['preference_score'];
        }
        
        $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
        foreach ($postCategories as $category) {
            if (isset($preferenceMap[$category])) {
                $score += $preferenceMap[$category] * 2;
            }
        }
        
        // NEW: Country relevance scoring
        $countryRelevance = $this->countryPrioritizer->calculateCountryRelevanceScore($authorId);
        $score += $countryRelevance * 4; // Strong weight for country relevance
        
        // NEW: Hashtag relevance scoring
        $hashtagPreferences = $this->preferenceTracker->getHashtagPreferences();
        $hashtagRelevance = $this->hashtagAnalyzer->calculateHashtagRelevanceScore($post['id'], $hashtagPreferences);
        $score += $hashtagRelevance * 3; // Medium weight for hashtag relevance
        
        return $score;
    }
    
    // NEW: Get country-based trending posts
    public function getCountryTrendingPosts($limit = 10) {
        return $this->countryPrioritizer->getTrendingPostsFromCountry($limit);
    }
    
    // NEW: Get trending hashtags from user's country
    public function getCountryTrendingHashtags($limit = 10) {
        return $this->hashtagAnalyzer->getTrendingHashtagsByCountry($this->userCountry, $limit);
    }
    
    // Multi-modal content scoring
    public function calculateMultiModalScore($post) {
        $scores = [];
        
        // Content analysis score
        if (!empty($post['media_url'])) {
            $videoUrl = explode(',', $post['media_url'])[0];
            $contentAnalysis = $this->contentAnalyzer->analyzeVideoContent($videoUrl, $post['content']);
            $audioAnalysis = $this->contentAnalyzer->analyzeAudioContent($videoUrl);
            
            $scores['visual_quality'] = $contentAnalysis['aesthetic_score'];
            $scores['audio_quality'] = $audioAnalysis['audio_quality'];
            $scores['content_mood'] = $contentAnalysis['content_mood'];
            $scores['production_value'] = (
                $contentAnalysis['aesthetic_score'] * 0.4 +
                $audioAnalysis['audio_quality'] * 0.3 +
                (1 - $audioAnalysis['background_noise']) * 0.3
            );
        }
        
        // Text sentiment score
        $scores['sentiment'] = $this->contentAnalyzer->analyzeTextSentiment($post['content']);
        
        // Engagement pattern score
        $scores['engagement_velocity'] = $this->calculateEngagementVelocity($post['id']);
        
        return $scores;
    }
    
    // Advanced trend detection
    public function calculateTrendScore($post) {
        $categories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
        $trendScore = 0;
        
        foreach ($categories as $category) {
            $momentum = $this->trendEngine->calculateTrendMomentum($category);
            $trendScore += $momentum;
        }
        
        // Normalize trend score
        return min(max($trendScore, 0), 1);
    }
    
    // User attention modeling
    public function calculateAttentionMatch($post, $userAttentionSpan) {
        $postIdealWatchTime = $this->estimateIdealWatchTime($post);
        $userPreferredWatchTime = $userAttentionSpan * 60; // Convert to seconds
        
        $timeMatch = 1 - abs($postIdealWatchTime - $userPreferredWatchTime) / max($postIdealWatchTime, $userPreferredWatchTime, 1);
        return max($timeMatch, 0);
    }
    
    // Sound/Effect virality prediction
    public function predictSoundVirality($post) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT p2.id) as usage_count,
                COALESCE(AVG(like_counts.likes_count), 0) as avg_likes,
                COALESCE(AVG(comment_counts.comments_count), 0) as avg_comments
            FROM posts p2
            LEFT JOIN (
                SELECT post_id, COUNT(*) as likes_count 
                FROM likes 
                GROUP BY post_id
            ) like_counts ON p2.id = like_counts.post_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) as comments_count 
                FROM comments 
                GROUP BY post_id
            ) comment_counts ON p2.id = comment_counts.post_id
            WHERE p2.audio_track = ? 
            AND p2.created_at > NOW() - INTERVAL '7 days'
            AND p2.id != ?
        ");
        $stmt->execute([$post['audio_track'] ?? '', $post['id']]);
        $soundData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($soundData['usage_count'] > 0) {
            $adoptionRate = min($soundData['usage_count'] / 100, 1);
            $engagementRate = ($soundData['avg_likes'] + $soundData['avg_comments']) / 50;
            return ($adoptionRate * 0.6 + $engagementRate * 0.4);
        }
        
        return 0.1; // Default for new sounds
    }
    
    // Advanced cold start for new content
    public function enhancedColdStartScoring($post, $userBehavior) {
        $scores = [];
        
        // Creator similarity
        $scores['creator_similarity'] = $this->calculateCreatorSimilarity($post['user_id']);
        
        // Content-based matching
        $scores['content_match'] = $this->calculateContentSimilarity($post, $userBehavior);
        
        // Early engagement velocity
        $scores['early_velocity'] = $this->calculateEarlyEngagementVelocity($post['id']);
        
        // Production quality
        $scores['production_quality'] = ($post['video_quality_score'] + $post['audio_quality_score']) / 2;
        
        return array_sum($scores) / count($scores);
    }
    
    // A/B Testing framework
    public function getABTestVariation($userId, $testName) {
        $hash = md5($userId . $testName);
        return hexdec(substr($hash, 0, 8)) % 100;
    }
    
    // Real-time engagement velocity
    private function calculateEngagementVelocity($postId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as interactions,
                EXTRACT(EPOCH FROM (NOW() - MIN(created_at))) as time_span
            FROM (
                SELECT created_at FROM likes WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                UNION ALL
                SELECT created_at FROM comments WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                UNION ALL
                SELECT created_at FROM shares WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                UNION ALL
                SELECT created_at FROM video_views WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
            ) AS engagements
        ");
        $stmt->execute([$postId, $postId, $postId, $postId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data['time_span'] > 0) {
            return $data['interactions'] / ($data['time_span'] / 3600);
        }
        return 0;
    }
    
    private function estimateIdealWatchTime($post) {
        $completionRate = $post['avg_completion_rate'] ?? 0.5;
        $averageWatchTime = 30; // Default 30 seconds
        
        if ($completionRate > 0.8) {
            return 45; // High completion suggests engaging content
        } elseif ($completionRate < 0.3) {
            return 15; // Low completion suggests shorter attention span
        }
        
        return $averageWatchTime;
    }
    
    private function calculateCreatorSimilarity($creatorId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT f1.follower_id) as overlapping_followers
            FROM follows f1
            JOIN follows f2 ON f1.follower_id = f2.follower_id
            WHERE f1.followed_id = ? AND f2.followed_id = ?
        ");
        $stmt->execute([$creatorId, $this->userId]);
        $overlap = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return min($overlap['overlapping_followers'] / 100, 1);
    }
    
    private function calculateContentSimilarity($post, $userBehavior) {
        $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
        $userPreferredCategories = $this->getUserPreferredCategories();
        
        $similarity = 0;
        foreach ($postCategories as $category) {
            if (in_array($category, $userPreferredCategories)) {
                $similarity += 1;
            }
        }
        
        return $similarity / max(count($postCategories), 1);
    }
    
    private function calculateEarlyEngagementVelocity($postId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as early_engagements
            FROM (
                SELECT 1 FROM likes WHERE post_id = ? AND created_at <= NOW() - INTERVAL '1 hour'
                UNION ALL
                SELECT 1 FROM comments WHERE post_id = ? AND created_at <= NOW() - INTERVAL '1 hour'
            ) AS early_engagement
        ");
        $stmt->execute([$postId, $postId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return min($data['early_engagements'] / 10, 1);
    }
    
    private function getUserPreferredCategories() {
        $stmt = $this->pdo->prepare("
            SELECT 
                p.category1, p.category2, p.category3,
                COUNT(*) as interaction_count
            FROM user_behavior_tracking ubt
            JOIN posts p ON ubt.post_id = p.id
            WHERE ubt.user_id = ? 
            AND ubt.created_at > NOW() - INTERVAL '30 days'
            GROUP BY p.category1, p.category2, p.category3
            ORDER BY interaction_count DESC
            LIMIT 10
        ");
        $stmt->execute([$this->userId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $preferred = [];
        foreach ($categories as $cat) {
            if (!empty($cat['category1'])) $preferred[] = $cat['category1'];
            if (!empty($cat['category2'])) $preferred[] = $cat['category2'];
            if (!empty($cat['category3'])) $preferred[] = $cat['category3'];
        }
        
        return array_unique($preferred);
    }
    
    // Existing methods from original class
    public function calculateTrendingVelocity($postId, $timeWindow = '1 hour') {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as interactions,
                EXTRACT(EPOCH FROM (NOW() - MIN(created_at))) as time_span
            FROM (
                SELECT created_at FROM likes WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                UNION ALL
                SELECT created_at FROM comments WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                UNION ALL
                SELECT created_at FROM shares WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
            ) AS engagements
        ");
        $stmt->execute([$postId, $postId, $postId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data['time_span'] > 0) {
            $velocity = $data['interactions'] / ($data['time_span'] / 3600);
            return min($velocity / 10, 1.0);
        }
        return 0;
    }
    
    public function predictVirality($post, $earlyMetrics) {
        $completionRate = $earlyMetrics['avg_completion_rate'] ?? 0;
        $shareRatio = $earlyMetrics['shares_count'] / max($earlyMetrics['views_count'], 1);
        $commentRatio = $earlyMetrics['comments_count'] / max($earlyMetrics['views_count'], 1);
        
        $viralityScore = (
            $completionRate * 0.4 +
            $shareRatio * 100 * 0.4 +
            $commentRatio * 50 * 0.2
        );
        
        return min($viralityScore, 1.0);
    }
    
    public function calculateSocialDistance($viewerId, $creatorId) {
        if ($viewerId == $creatorId) return 1.0;
        
        $stmt = $this->pdo->prepare("
            WITH RECURSIVE social_graph AS (
                SELECT follower_id, followed_id, 1 as distance
                FROM follows 
                WHERE follower_id = ? AND followed_id = ?
                
                UNION
                
                SELECT f1.user_id as follower_id, f2.friend_id as followed_id, 2 as distance
                FROM friends f1
                JOIN friends f2 ON f1.friend_id = f2.user_id
                WHERE f1.user_id = ? AND f2.friend_id = ? AND f1.status = 'accepted' AND f2.status = 'accepted'
                
                UNION
                
                SELECT f1.follower_id, f2.followed_id, 2 as distance
                FROM follows f1
                JOIN follows f2 ON f1.followed_id = f2.follower_id
                WHERE f1.follower_id = ? AND f2.followed_id = ?
            )
            SELECT MIN(distance) as min_distance FROM social_graph
        ");
        $stmt->execute([$viewerId, $creatorId, $viewerId, $creatorId, $viewerId, $creatorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['min_distance']) {
            return 1.0 / $result['min_distance'];
        }
        return 0.1;
    }
    
    public function getNetworkEngagement($postId, $viewerId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT ubt.user_id) as network_engagements
            FROM user_behavior_tracking ubt
            JOIN follows f ON ubt.user_id = f.followed_id
            WHERE ubt.post_id = ? AND f.follower_id = ?
            AND ubt.created_at > NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute([$postId, $viewerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return min($result['network_engagements'] / 10, 1.0);
    }
    
    public function calculateCreatorInfluence($creatorId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT follower_id) as follower_count,
                AVG(l.likes_count) as avg_likes,
                AVG(c.comments_count) as avg_comments,
                AVG(v.views_count) as avg_views
            FROM users u
            LEFT JOIN follows f ON u.id = f.followed_id
            LEFT JOIN posts p ON u.id = p.user_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) as likes_count 
                FROM likes GROUP BY post_id
            ) l ON p.id = l.post_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) as comments_count 
                FROM comments GROUP BY post_id
            ) c ON p.id = c.post_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) as views_count 
                FROM video_views GROUP BY post_id
            ) v ON p.id = v.post_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$creatorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $followerScore = min($result['follower_count'] / 1000, 1.0);
        $engagementScore = min(($result['avg_likes'] + $result['avg_comments']) / 50, 1.0);
        
        return ($followerScore * 0.6 + $engagementScore * 0.4);
    }
    
    public function getUserEmbeddingVector($userId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COALESCE(AVG(CASE WHEN p.category1 = 'Entertainment' THEN 1 ELSE 0 END), 0) as cat_entertainment,
                COALESCE(AVG(CASE WHEN p.category1 = 'Music' THEN 1 ELSE 0 END), 0) as cat_music,
                COALESCE(AVG(CASE WHEN p.category1 = 'Comedy' THEN 1 ELSE 0 END), 0) as cat_comedy,
                COALESCE(AVG(CASE WHEN p.category1 = 'Gaming' THEN 1 ELSE 0 END), 0) as cat_gaming,
                AVG(ubt.completion_rate) as avg_completion,
                AVG(ubt.watch_time) as avg_watch_time,
                EXTRACT(HOUR FROM NOW()) as current_hour_pref
            FROM user_behavior_tracking ubt
            LEFT JOIN posts p ON ubt.post_id = p.id
            WHERE ubt.user_id = ? 
            AND ubt.created_at > NOW() - INTERVAL '30 days'
            GROUP BY ubt.user_id
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? array_values($result) : array_fill(0, 7, 0.5);
    }
    
    public function getContentEmbeddingVector($post) {
        $categories = ['Entertainment', 'Music', 'Comedy', 'Gaming'];
        $categoryVector = [];
        
        foreach ($categories as $category) {
            $categoryVector[] = in_array($category, [$post['category1'], $post['category2'], $post['category3']]) ? 1 : 0;
        }
        
        $categoryVector[] = min($post['likes_count'] / 100, 1.0);
        $categoryVector[] = min($post['comments_count'] / 50, 1.0);
        $categoryVector[] = $post['avg_completion_rate'];
        
        return $categoryVector;
    }
    
    public function cosineSimilarity($vectorA, $vectorB) {
        if (count($vectorA) !== count($vectorB)) return 0;
        
        $dotProduct = 0;
        $magnitudeA = 0;
        $magnitudeB = 0;
        
        for ($i = 0; $i < count($vectorA); $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
            $magnitudeA += $vectorA[$i] * $vectorA[$i];
            $magnitudeB += $vectorB[$i] * $vectorB[$i];
        }
        
        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);
        
        if ($magnitudeA == 0 || $magnitudeB == 0) return 0;
        
        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
    
    public function coldStartScoring($post, $userData) {
        $contentQuality = ($post['video_quality_score'] + $post['audio_quality_score']) / 2;
        $popularity = min(($post['likes_count'] + $post['comments_count']) / 50, 1.0);
        $completionRate = $post['avg_completion_rate'];
        
        return ($contentQuality * 0.4 + $popularity * 0.4 + $completionRate * 0.2);
    }
    
    public function contentBasedScoring($post, $creatorHistory) {
        $creatorPerformance = $this->calculateCreatorInfluence($post['user_id']);
        $contentQuality = ($post['video_quality_score'] + $post['audio_quality_score']) / 2;
        
        return ($creatorPerformance * 0.6 + $contentQuality * 0.4);
    }
    
    public function calculateMultiObjectiveScore($post, $userEngagement, $objectives) {
        $scores = [
            'user_retention' => $userEngagement,
            'time_spent' => $post['avg_completion_rate'] * $post['rewatch_rate'],
            'content_diversity' => $this->calculateDiversityScore($post),
            'creator_satisfaction' => $this->calculateCreatorSatisfactionScore($post),
            'engagement_quality' => ($post['comments_count'] + $post['shares_count']) / max($post['views_count'], 1)
        ];
        
        $totalScore = 0;
        foreach ($objectives as $objective => $weight) {
            $totalScore += $scores[$objective] * $weight;
        }
        
        return $totalScore;
    }
    
    private function calculateDiversityScore($post) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as similar_posts 
            FROM posts 
            WHERE category1 = ? OR category2 = ? OR category3 = ?
            AND created_at > NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute([$post['category1'], $post['category2'], $post['category3']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return max(0, 1 - ($result['similar_posts'] / 100));
    }
    
    private function calculateCreatorSatisfactionScore($post) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT f.follower_id) as follower_count,
                COALESCE(AVG(post_stats.avg_likes), 0) as avg_likes,
                COALESCE(AVG(post_stats.avg_comments), 0) as avg_comments,
                COUNT(p.id) as post_count
            FROM users u
            LEFT JOIN follows f ON u.id = f.followed_id
            LEFT JOIN posts p ON u.id = p.user_id
            LEFT JOIN (
                SELECT 
                    p.user_id,
                    COUNT(l.id) as avg_likes,
                    COUNT(c.id) as avg_comments
                FROM posts p
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN comments c ON p.id = c.post_id
                WHERE p.user_id = ? AND p.created_at > NOW() - INTERVAL '7 days'
                GROUP BY p.user_id, p.id
            ) post_stats ON u.id = post_stats.user_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$post['user_id'], $post['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $followerScore = min($result['follower_count'] / 1000, 1.0);
        $engagementScore = min(($result['avg_likes'] + $result['avg_comments']) / 50, 1.0);
        
        return ($followerScore * 0.6 + $engagementScore * 0.4);
    }
    
    public function calculateNegativeSignals($postId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(CASE WHEN interaction_type = 'skip' THEN 1 END) as skip_count,
                COUNT(CASE WHEN interaction_type = 'hide' THEN 1 END) as hide_count,
                AVG(CASE WHEN interaction_type = 'view' THEN completion_rate END) as avg_completion,
                COUNT(*) as total_views
            FROM user_behavior_tracking 
            WHERE post_id = ? AND created_at > NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute([$postId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $skipRate = $result['total_views'] > 0 ? $result['skip_count'] / $result['total_views'] : 0;
        $hideRate = $result['total_views'] > 0 ? $result['hide_count'] / $result['total_views'] : 0;
        $completionRate = $result['avg_completion'] ?? 0;
        
        $negativeScore = (
            $skipRate * 0.4 +
            $hideRate * 0.5 +
            (1 - $completionRate) * 0.1
        );
        
        return min($negativeScore, 1.0);
    }
}

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // FOLLOW/UNFOLLOW HANDLER
    if (isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow']) && isset($_POST['followed_id'])) {
        $followerId = $_SESSION['user_id'];
        $followedId = (int)$_POST['followed_id'];
        if ($followerId && $followedId && $followerId !== $followedId) {
            if ($_POST['action'] === 'follow') {
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                $stmt->execute([$followerId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'followed']);
                exit;
            } else {
                $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
                $stmt->execute([$followerId, $followedId]);
                echo json_encode(['success' => true, 'action' => 'unfollowed']);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid operation']);
        exit;
    }

    // LIKE/UNLIKE HANDLER
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike']) && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid post id']);
            exit;
        }
        if ($_POST['action'] === 'like') {
            $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (:post_id, :user_id) ON CONFLICT DO NOTHING");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
            
            // Track engagement signal
            $preferenceTracker = new UserPreferenceTracker($pdo, $userId);
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            $preferenceTracker->trackEngagement($postId, $authorId, 'like');
        } else {
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = :post_id AND user_id = :user_id");
            $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id = :post_id");
        $stmt->execute(['post_id' => $postId]);
        $likesCount = (int)$stmt->fetchColumn();
        echo json_encode(['success' => true, 'likes_count' => $likesCount]);
        exit;
    }
    
    // DOWNLOAD TRACKING HANDLER - NEW
    if (isset($_POST['action']) && $_POST['action'] === 'track_download' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        
        try {
            $preferenceTracker = new UserPreferenceTracker($pdo, $userId);
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            
            $success = $preferenceTracker->trackDownload($postId, $authorId);
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            error_log("Download tracking error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Download tracking failed']);
        }
        exit;
    }
    
    // USER BEHAVIOR TRACKING HANDLER - MODIFIED: Track actual watch time
    if (isset($_POST['action']) && $_POST['action'] === 'track_behavior' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        $watchTime = (float)($_POST['watch_time'] ?? 0);
        $completionRate = (float)($_POST['completion_rate'] ?? 0);
        $scrollVelocity = (float)($_POST['scroll_velocity'] ?? 0);
        $interactionType = $_POST['interaction_type'] ?? 'view';
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_behavior_tracking 
                (user_id, post_id, watch_time, completion_rate, scroll_velocity, interaction_type, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $postId, $watchTime, $completionRate, $scrollVelocity, $interactionType]);
            
            // Track preference signals based on interaction type
            $preferenceTracker = new UserPreferenceTracker($pdo, $userId);
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            
            if ($interactionType === 'completion') {
                $preferenceTracker->trackCompletion($postId, $authorId);
            } elseif ($interactionType === 'rewatch') {
                $preferenceTracker->trackRewatch($postId, $authorId);
            } elseif ($watchTime < 2 && $interactionType === 'view') {
                $preferenceTracker->trackQuickScrollAway($postId, $authorId);
            }
            
            // Only update session if video was watched for at least 5 seconds
            if ($watchTime >= 5) {
                $stmt = $pdo->prepare("
                    UPDATE user_sessions 
                    SET duration = duration + 10, 
                        last_activity = NOW(),
                        videos_watched = videos_watched + 1
                    WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE
                ");
                $stmt->execute([$userId]);
                
                if ($stmt->rowCount() === 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_sessions (user_id, duration, videos_watched, created_at, last_activity)
                        VALUES (?, 10, 1, NOW(), NOW())
                    ");
                    $stmt->execute([$userId]);
                }
            }
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Behavior tracking error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Tracking failed']);
        }
        exit;
    }
    
    // NEGATIVE FEEDBACK HANDLER
    if (isset($_POST['action']) && $_POST['action'] === 'negative_feedback' && isset($_POST['post_id'])) {
        $userId = $_SESSION['user_id'];
        $postId = (int)$_POST['post_id'];
        $feedbackType = $_POST['feedback_type'] ?? 'skip';
        
        try {
            $preferenceTracker = new UserPreferenceTracker($pdo, $userId);
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            
            $preferenceTracker->trackNegativeSignal($postId, $authorId, $feedbackType);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Negative feedback error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Feedback failed']);
        }
        exit;
    }
}

// Fetch user's data and initialize enhanced algorithm
$userId = $_SESSION['user_id'];
$algorithm = new TikTokAdvancedAlgorithm($pdo, $userId);
$preferenceTracker = new UserPreferenceTracker($pdo, $userId);

// NEW: Get country-based trending posts
$countryTrendingPosts = $algorithm->getCountryTrendingPosts(10);

// NEW: Get trending hashtags from user's country
$countryTrendingHashtags = $algorithm->getCountryTrendingHashtags(10);

// NEW: Get user's hashtag preferences
$userHashtagPreferences = $preferenceTracker->getHashtagPreferences();

// Get emerging trends
try {
    $trendEngine = new TrendDetectionEngine($pdo);
    $emergingTrends = $trendEngine->detectEmergingTrends();
} catch (Exception $e) {
    $emergingTrends = [];
    error_log("Trend detection error: " . $e->getMessage());
}

// Get advanced user behavior patterns
try {
    $userModel = new AdvancedUserModel($pdo, $userId);
    $userBehaviorPatterns = $userModel->getUserBehaviorPattern();
    $userAttentionSpan = $userModel->calculateUserAttentionSpan();
    $microInteractions = $userModel->getMicroInteractionPatterns();
} catch (Exception $e) {
    $userBehaviorPatterns = [];
    $userAttentionSpan = 0.5;
    $microInteractions = [];
    error_log("User modeling error: " . $e->getMessage());
}

// Get user's watched videos for completion rate tracking
try {
    $watchedVideosStmt = $pdo->prepare("
        SELECT post_id, watch_duration, completed, rewatches
        FROM video_views 
        WHERE user_id = ? AND watched_at > NOW() - INTERVAL '30 days'
    ");
    $watchedVideosStmt->execute([$userId]);
    $userWatchHistory = $watchedVideosStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userWatchHistory = [];
}

// Get user's liked categories
try {
    $likedCategoriesStmt = $pdo->prepare("
        SELECT DISTINCT p.category1, p.category2, p.category3
        FROM likes l
        JOIN posts p ON l.post_id = p.id
        WHERE l.user_id = ? AND p.post_type = 'video'
        AND l.created_at > NOW() - INTERVAL '30 days'
    ");
    $likedCategoriesStmt->execute([$userId]);
    $userLikedCategories = $likedCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userLikedCategories = [];
}

// Get user's recent behavior patterns
try {
    $behaviorStmt = $pdo->prepare("
        SELECT 
            post_id,
            AVG(watch_time) as avg_watch_time,
            AVG(completion_rate) as avg_completion_rate,
            AVG(scroll_velocity) as avg_scroll_velocity,
            COUNT(*) as interaction_count
        FROM user_behavior_tracking 
        WHERE user_id = ? AND created_at > NOW() - INTERVAL '7 days'
        GROUP BY post_id
    ");
    $behaviorStmt->execute([$userId]);
    $userBehavior = $behaviorStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userBehavior = [];
}

// Get user's session patterns
try {
    $sessionStmt = $pdo->prepare("
        SELECT 
            EXTRACT(HOUR FROM created_at) as hour_of_day,
            EXTRACT(DOW FROM created_at) as day_of_week,
            AVG(duration) as avg_duration,
            AVG(videos_watched) as avg_videos_watched
        FROM user_sessions 
        WHERE user_id = ? AND created_at > NOW() - INTERVAL '30 days'
        GROUP BY EXTRACT(HOUR FROM created_at), EXTRACT(DOW FROM created_at)
    ");
    $sessionStmt->execute([$userId]);
    $userSessionPatterns = $sessionStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userSessionPatterns = [];
}

// Check if user is new (less than 10 interactions)
$isNewUser = count($userBehavior) < 10;

// Extract preferred categories from liked videos
$preferredCategories = [];
foreach ($userLikedCategories as $row) {
    if (!empty($row['category1'])) $preferredCategories[] = $row['category1'];
    if (!empty($row['category2'])) $preferredCategories[] = $row['category2'];
    if (!empty($row['category3'])) $preferredCategories[] = $row['category3'];
}
$preferredCategories = array_count_values($preferredCategories);
arsort($preferredCategories);

// Calculate user engagement patterns
$userEngagementScore = 0;
$totalInteractions = 0;
foreach ($userBehavior as $behavior) {
    $userEngagementScore += $behavior['avg_completion_rate'] * 0.5;
    $userEngagementScore += (1 - min($behavior['avg_scroll_velocity'] / 100, 1)) * 0.3;
    $userEngagementScore += min($behavior['interaction_count'] / 10, 1) * 0.2;
    $totalInteractions += $behavior['interaction_count'];
}

// Normalize engagement score
if (count($userBehavior) > 0) {
    $userEngagementScore = $userEngagementScore / count($userBehavior);
}

// Get user's followed accounts for follow buttons
$stmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followedUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Build the SQL query using only positional parameters
$params = [$userId, $userId, $userId, $userId, $userId, $userId, $userId];
$categoryCondition = "";

if ($selectedCategory !== 'All') {
    $categoryCondition = "AND (p.category1 = ? OR p.category2 = ? OR p.category3 = ?)";
    array_push($params, $selectedCategory, $selectedCategory, $selectedCategory);
}

// MODIFIED: Add condition to exclude viewed videos (only those watched for at least 5 seconds)
$excludeViewedCondition = "";
if (!empty($viewedVideoIds)) {
    $placeholders = implode(',', array_fill(0, count($viewedVideoIds), '?'));
    $excludeViewedCondition = "AND p.id NOT IN ($placeholders)";
    $params = array_merge($params, $viewedVideoIds);
}

// Get all potential videos first with engagement metrics
$sql = "
    SELECT 
        p.*, 
        u.username, 
        u.profile_pic_url, 
        u.is_business_account, 
        u.id AS author_id,
        -- Engagement metrics
        COALESCE(l.likes_count, 0) as likes_count,
        COALESCE(c.comments_count, 0) as comments_count,
        COALESCE(s.shares_count, 0) as shares_count,
        COALESCE(v.views_count, 0) as views_count,
        COALESCE(vw.avg_completion_rate, 0) as avg_completion_rate,
        COALESCE(vw.rewatch_rate, 0) as rewatch_rate,
        -- User relationship signals
        CASE WHEN f.follower_id IS NOT NULL THEN 1 ELSE 0 END as is_following_author,
        CASE WHEN ul.user_id IS NOT NULL THEN 1 ELSE 0 END as user_liked_post,
        CASE WHEN p.user_id = ? THEN 1 ELSE 0 END as is_own_post,
        -- Categories for scoring
        p.category1, p.category2, p.category3,
        -- Time factors
        EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 as hours_ago,
        -- Content quality signals
        COALESCE(p.video_quality_score, 0.5) as video_quality_score,
        COALESCE(p.audio_quality_score, 0.5) as audio_quality_score,
        COALESCE(p.engagement_trend, 0) as engagement_trend,
          -- Add post_header here
        p.post_header

    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN group_members gm ON p.group_id = gm.group_id AND gm.user_id = ? AND gm.status = 'approved'
    
    -- Engagement metrics subqueries
    LEFT JOIN (
        SELECT post_id, COUNT(*) as likes_count 
        FROM likes 
        GROUP BY post_id
    ) l ON p.id = l.post_id
    
    LEFT JOIN (
        SELECT post_id, COUNT(*) as comments_count 
        FROM comments 
        GROUP BY post_id
    ) c ON p.id = c.post_id
    
    LEFT JOIN (
        SELECT original_post_id, COUNT(*) as shares_count 
        FROM shared_posts 
        GROUP BY original_post_id
    ) s ON p.id = s.original_post_id
    
    LEFT JOIN (
        SELECT post_id, COUNT(*) as views_count 
        FROM video_views 
        GROUP BY post_id
    ) v ON p.id = v.post_id
    
    -- Video performance metrics
    LEFT JOIN (
        SELECT 
            post_id,
            AVG(completion_rate) as avg_completion_rate,
            AVG(rewatches) as rewatch_rate
        FROM video_views 
        WHERE watched_at > NOW() - INTERVAL '7 days'
        GROUP BY post_id
    ) vw ON p.id = vw.post_id
    
    -- User relationship subqueries
    LEFT JOIN follows f ON (f.follower_id = ? AND f.followed_id = p.user_id)
    LEFT JOIN likes ul ON (ul.post_id = p.id AND ul.user_id = ?)
    
    WHERE p.post_type = 'video'
      AND (
        (p.privacy_setting = 'public')
        OR (p.privacy_setting = 'friends' AND EXISTS (
              SELECT 1 FROM friends f WHERE 
                ((f.user_id = ? AND f.friend_id = p.user_id) 
                 OR (f.friend_id = ? AND f.user_id = p.user_id)) 
                 AND f.status = 'accepted'))
        OR (p.privacy_setting = 'private' AND p.user_id = ?)
        OR (p.privacy_setting = 'groups-only' AND gm.user_id IS NOT NULL)
      )
      $categoryCondition
      $excludeViewedCondition
";

$basePostStmt = $pdo->prepare($sql);
$basePostStmt->execute($params);
$allPosts = $basePostStmt->fetchAll(PDO::FETCH_ASSOC);

// ENHANCED TIKTOK-STYLE ALGORITHMIC SCORING WITH COUNTRY AND HASHTAG PRIORITIZATION
$scoredPosts = [];
$userEmbedding = $algorithm->getUserEmbeddingVector($userId);

// Multi-objective optimization weights - UPDATED with country and hashtag weights
$objectives = [
    'user_retention' => 0.25,
    'time_spent' => 0.20,
    'content_diversity' => 0.15,
    'creator_satisfaction' => 0.15,
    'engagement_quality' => 0.15,
    'country_relevance' => 0.10  // NEW: Country relevance weight
];

// Initialize tiktok_score for all posts before scoring
foreach ($allPosts as &$post) {
    $post['tiktok_score'] = 0;
    $post['score_breakdown'] = [];
}
unset($post);

foreach ($allPosts as $post) {
    $score = 0;
    
    
    // NEW: Enhanced user preference scoring with country and hashtag prioritization
    $userPreferenceScore = $algorithm->calculateUserPreferenceScore($post);
    // Calculate country relevance score separately for breakdown
$countryRelevanceScore = $algorithm->getCountryRelevanceScore($post['user_id']);

// Calculate hashtag relevance score separately for breakdown
$hashtagRelevanceScore = $algorithm->getHashtagRelevanceScore($post['id'], $userHashtagPreferences);
    
    // Enhanced scoring with AI features
    $multiModalScores = $algorithm->calculateMultiModalScore($post);
    $trendScore = $algorithm->calculateTrendScore($post);
    $attentionMatch = $algorithm->calculateAttentionMatch($post, $userAttentionSpan);
    $soundVirality = $algorithm->predictSoundVirality($post);
    
    // Check if content is new (less than 100 views)
    $isNewContent = $post['views_count'] < 100;
    
    // 1. ENHANCED USER PREFERENCE SCORING (25% weight) - UPDATED
    $preferenceScore = $userPreferenceScore * 25 / 100;
    $score += $preferenceScore;
    
    // 2. ENHANCED REAL-TIME ENGAGEMENT METRICS (20% weight) - REDUCED
    $engagementScore = (
        ($post['likes_count'] * 0.8) +        
        ($post['comments_count'] * 1.5) +     
        ($post['shares_count'] * 2.0) +       
        ($post['views_count'] * 0.3) +
        ($post['avg_completion_rate'] * 3.0) +
        ($post['rewatch_rate'] * 2.5) +
        ($multiModalScores['production_value'] * 2.0) +
        ($trendScore * 1.5) +
        ($attentionMatch * 2.0) +
        ($soundVirality * 1.0)
    ) * 20 / 100;
    
    // Add trending and virality scores safely
    try {
        $engagementScore += ($algorithm->calculateTrendingVelocity($post['id']) * 2.0);
        $engagementScore += ($algorithm->predictVirality($post, [
            'avg_completion_rate' => $post['avg_completion_rate'],
            'shares_count' => $post['shares_count'],
            'comments_count' => $post['comments_count'],
            'views_count' => $post['views_count']
        ]) * 1.5);
    } catch (Exception $e) {
        error_log("Engagement scoring error: " . $e->getMessage());
    }
    
    $score += $engagementScore;
    
    // 3. ENHANCED USER PERSONALIZATION (20% weight)
    $personalizationScore = 0;
    
    $socialProximity = $algorithm->calculateSocialDistance($userId, $post['user_id']);
    $personalizationScore += $socialProximity * 6;
    
    // Network engagement
    $networkEngagement = $algorithm->getNetworkEngagement($post['id'], $userId);
    $personalizationScore += $networkEngagement * 4;
    
    // Previous interaction
    if ($post['user_liked_post']) {
        $personalizationScore += 5;
    }
    
    // Advanced user-content matching
    $contentEmbedding = $algorithm->getContentEmbeddingVector($post);
    $affinityScore = $algorithm->cosineSimilarity($userEmbedding, $contentEmbedding);
    $personalizationScore += $affinityScore * 8;
    
    // Category relevance with behavior weighting
    $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
    $categoryRelevance = 0;
    foreach ($postCategories as $category) {
        if (isset($preferredCategories[$category])) {
            $weight = min($preferredCategories[$category] * 2, 6);
            $categoryRelevance += $weight;
        }
    }
    $personalizationScore += $categoryRelevance;
    
    // Boost for current category selection
    if ($selectedCategory !== 'All' && in_array($selectedCategory, $postCategories)) {
        $personalizationScore += 3;
    }
    
    $score += $personalizationScore;
    
    // 4. ENHANCED CONTENT QUALITY & CREATOR INFLUENCE (15% weight)
    $qualityScore = (
        ($post['video_quality_score'] * 4) +
        ($post['audio_quality_score'] * 3) +
        (min($post['engagement_trend'] * 2, 3)) +
        ($algorithm->calculateCreatorInfluence($post['user_id']) * 5) +
        ($multiModalScores['visual_quality'] * 2) +
        ($multiModalScores['audio_quality'] * 2) +
        ($multiModalScores['sentiment'] * 1)
    );
    $score += $qualityScore;
    
    // 5. COUNTRY AND REGION PRIORITIZATION (10% weight) - NEW
    $countryScore = $countryRelevanceScore * 10;
    $score += $countryScore;
    
    // 6. HASHTAG RELEVANCE SCORING (5% weight) - NEW
    $hashtagScore = $hashtagRelevanceScore * 5;
    $score += $hashtagScore;
    
    // 7. TIME DECAY WITH USER PATTERNS (5% weight) - REDUCED
    $currentHour = (int)date('G');
    $currentDay = (int)date('w');
    
    // Check if this matches user's typical viewing patterns
    $timePatternBoost = 0;
    foreach ($userSessionPatterns as $pattern) {
        if ($pattern['hour_of_day'] == $currentHour && $pattern['day_of_week'] == $currentDay) {
            $timePatternBoost = min($pattern['avg_videos_watched'] / 10, 2);
            break;
        }
    }
    
    $timeDecay = max(0, (72 - min($post['hours_ago'], 72)) / 72);
    $score += ($timeDecay * 4) + $timePatternBoost; // Reduced from 8 to 4
    
    // Apply user engagement pattern modifier
    $userEngagementModifier = 0.8 + ($userEngagementScore * 0.4); // 0.8 to 1.2 range
    $score *= $userEngagementModifier;
    
    // Ensure score is within reasonable bounds
    $score = max(0, min($score, 100));
    
    // Store the calculated score
    $post['tiktok_score'] = round($score, 2);
    $post['score_breakdown'] = [
        'preference' => round($preferenceScore, 2),
        'engagement' => round($engagementScore, 2),
        'personalization' => round($personalizationScore, 2),
        'quality' => round($qualityScore, 2),
        'country' => round($countryScore, 2), // NEW
        'hashtag' => round($hashtagScore, 2), // NEW
        'recency' => round(($timeDecay * 4) + $timePatternBoost, 2), // Updated
        'user_modifier' => round($userEngagementModifier, 2)
    ];
    
    // Store advanced metrics for display - ADD COUNTRY AND HASHTAG METRICS
    $post['country_relevance'] = round($countryRelevanceScore, 2);
    $post['hashtag_relevance'] = round($hashtagRelevanceScore, 2);
    $post['trending_velocity'] = round($algorithm->calculateTrendingVelocity($post['id']), 2);
    $post['virality_probability'] = round($algorithm->predictVirality($post, [
        'avg_completion_rate' => $post['avg_completion_rate'],
        'shares_count' => $post['shares_count'],
        'comments_count' => $post['comments_count'],
        'views_count' => $post['views_count']
    ]), 2);
    $post['social_proximity'] = round($algorithm->calculateSocialDistance($userId, $post['user_id']), 2);
    $post['creator_influence'] = round($algorithm->calculateCreatorInfluence($post['user_id']), 2);
    $post['affinity_score'] = round($affinityScore, 2);
    $post['attention_match'] = round($attentionMatch, 2);
    $post['sound_virality'] = round($soundVirality, 2);
    $post['trend_score'] = round($trendScore, 2);
    $post['user_preference_score'] = round($userPreferenceScore, 2);
    
    $scoredPosts[] = $post;
}

// Sort by TikTok score (highest first)
usort($scoredPosts, function($a, $b) {
    return $b['tiktok_score'] <=> $a['tiktok_score'];
});

// NEW: Boost country trending posts to top positions
if (!empty($countryTrendingPosts)) {
    $countryTrendingIds = array_column($countryTrendingPosts, 'id');
    $boostedPosts = [];
    $regularPosts = [];
    
    foreach ($scoredPosts as $post) {
        if (in_array($post['id'], $countryTrendingIds)) {
            // Boost score for country trending posts
            $post['tiktok_score'] = min($post['tiktok_score'] * 1.3, 100);
            $post['is_country_trending'] = true;
            $boostedPosts[] = $post;
        } else {
            $regularPosts[] = $post;
        }
    }
    
    // Re-sort with boosted posts
    usort($boostedPosts, function($a, $b) {
        return $b['tiktok_score'] <=> $a['tiktok_score'];
    });
    
    $scoredPosts = array_merge($boostedPosts, $regularPosts);
}

// NEW: Implement user post spacing - ensure no two consecutive posts from same user
$finalPosts = [];
$lastUserIds = []; // Track last user IDs to avoid repetition

foreach ($scoredPosts as $post) {
    $currentUserId = $post['user_id'];
    
    // Check if this user was in the last two posts
    if (in_array($currentUserId, $lastUserIds)) {
        // Skip this post for now, we'll add it later
        continue;
    }
    
    // Add the post to final list
    $finalPosts[] = $post;
    
    // Update the last user IDs (keep only last 2)
    array_unshift($lastUserIds, $currentUserId);
    if (count($lastUserIds) > 2) {
        array_pop($lastUserIds);
    }
}

// Add any remaining posts that were skipped due to user repetition
foreach ($scoredPosts as $post) {
    if (!in_array($post, $finalPosts)) {
        $finalPosts[] = $post;
    }
}

// A/B Testing: Occasionally shuffle top results for testing
if (rand(1, 10) === 1) {
    $topPosts = array_slice($finalPosts, 0, 5);
    shuffle($topPosts);
    $remainingPosts = array_slice($finalPosts, 5);
    $finalPosts = array_merge($topPosts, $remainingPosts);
}

// Take top posts for display
$posts = array_slice($finalPosts, 0, 50);

// Prepare posts for display
foreach ($posts as &$post) {
    $post['is_following'] = in_array($post['author_id'], $followedUserIds);
    
    // Get post categories for display
    $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
    $post['display_categories'] = !empty($postCategories) ? implode(', ', $postCategories) : 'Uncategorized';
    
    // Calculate engagement rate for display
    $post['engagement_rate'] = $post['views_count'] > 0 
        ? round(($post['likes_count'] + $post['comments_count'] + $post['shares_count']) / $post['views_count'] * 100, 1)
        : 0;
        
    // Calculate completion rate for display
    $post['completion_display'] = $post['avg_completion_rate'] > 0 
        ? round($post['avg_completion_rate'] * 100, 1) . '%'
        : 'N/A';
        
}

unset($post);

// Helper function to get comment count
function getCommentCount($pdo, $postId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $postId]);
    return (int)$stmt->fetchColumn();
}

// Check if current user liked a post
function userLikedPost($pdo, $userId, $postId) {
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$profilePicUrl = $stmt->fetchColumn() ?: 'default_profile.png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fbclone Reels - <?= htmlspecialchars($categories[$selectedCategory]) ?></title>
<style>
    /* Post Header Display */
.post-header-display {
    font-size: 20px;
    font-weight: 700;
    color: #ffffff;
    margin: 10px 0 15px 0;
    line-height: 1.3;
    padding: 0 5px;
    word-wrap: break-word;
    text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.post-header-display.has-content {
    border-left: 3px solid #7b68ee;
    padding-left: 12px;
    background: rgba(123, 104, 238, 0.05);
    border-radius: 0 8px 8px 0;
}

.post-header-hashtag {
    color: #7b68ee;
    font-weight: 600;
}

/* Enhanced content area when header is present */
.reel-content.with-header {
    margin-top: 8px;
    font-size: 15px;
    color: #e0e0e0;
    line-height: 1.4;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .post-header-display {
        font-size: 18px;
        margin: 8px 0 12px 0;
    }
    
    .reel-content.with-header {
        font-size: 14px;
    }
}
/* Enhanced Algorithm Indicators */
.algorithm-dashboard {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px;
    border-radius: 12px;
    margin: 15px 0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.algorithm-stats {
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 15px;
}

.stat-item {
    text-align: center;
    flex: 1;
    min-width: 120px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 12px;
    opacity: 0.9;
}

/* Enhanced TikTok Score Display */
.tiktok-score {
    position: absolute;
    top: 10px;
    left: 10px;
    background: linear-gradient(45deg, #FF0050, #00F2EA);
    color: white;
    padding: 6px 10px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: bold;
    z-index: 100;
    cursor: help;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

.score-breakdown {
    display: none;
    position: absolute;
    top: 35px;
    left: 10px;
    background: rgba(0, 0, 0, 0.95);
    color: white;
    padding: 15px;
    border-radius: 10px;
    font-size: 12px;
    z-index: 1000;
    min-width: 300px;
    border: 1px solid #333;
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    backdrop-filter: blur(10px);
}

/* Advanced Metrics Indicators */
.advanced-metrics {
    display: flex;
    gap: 8px;
    margin: 8px 0;
    flex-wrap: wrap;
}

.metric-indicator {
    background: rgba(255, 107, 107, 0.1);
    border: 1px solid #ff6b6b;
    border-radius: 10px;
    padding: 3px 8px;
    font-size: 9px;
    color: #ff6b6b;
}

.trending-indicator {
    background: rgba(255, 193, 7, 0.1);
    border-color: #ffc107;
    color: #ffc107;
}

.virality-indicator {
    background: rgba(76, 175, 80, 0.1);
    border-color: #4CAF50;
    color: #4CAF50;
}

.influence-indicator {
    background: rgba(156, 39, 176, 0.1);
    border-color: #9c27b0;
    color: #9c27b0;
}

.attention-indicator {
    background: rgba(33, 150, 243, 0.1);
    border-color: #2196F3;
    color: #2196F3;
}

.sound-indicator {
    background: rgba(233, 30, 99, 0.1);
    border-color: #E91E63;
    color: #E91E63;
}

.preference-indicator {
    background: rgba(255, 152, 0, 0.1);
    border-color: #FF9800;
    color: #FF9800;
}

/* NEW: Country and Hashtag Indicators */
.country-indicator {
    background: rgba(123, 104, 238, 0.1);
    border-color: #7b68ee;
    color: #7b68ee;
}

.hashtag-indicator {
    background: rgba(255, 105, 180, 0.1);
    border-color: #FF69B4;
    color: #FF69B4;
}

.trending-country-indicator {
    background: rgba(255, 215, 0, 0.1);
    border-color: #FFD700;
    color: #FFD700;
}

/* Behavior Tracking Indicators */
.behavior-indicators {
    display: flex;
    gap: 10px;
    margin: 8px 0;
    flex-wrap: wrap;
}

.behavior-indicator {
    background: rgba(123, 104, 238, 0.1);
    border: 1px solid #7b68ee;
    border-radius: 12px;
    padding: 4px 8px;
    font-size: 10px;
    color: #7b68ee;
}

.completion-rate {
    background: rgba(0, 242, 234, 0.1);
    border-color: #00F2EA;
    color: #00F2EA;
}

.rewatch-rate {
    background: rgba(255, 0, 80, 0.1);
    border-color: #FF0050;
    color: #FF0050;
}

/* Real-time Learning Indicator */
.learning-indicator {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #4CAF50;
    color: white;
    padding: 10px 15px;
    border-radius: 20px;
    font-size: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    display: none;
    z-index: 10000;
}

/* Enhanced Video Controls */
.enhanced-video-controls {
    position: absolute;
    bottom: 15px;
    right: 15px;
    z-index: 10;
    display: flex;
    gap: 10px;
    background: rgba(0, 0, 0, 0.7);
    padding: 8px;
    border-radius: 20px;
    backdrop-filter: blur(10px);
}

/* Progress bar for video watching */
.watch-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: rgba(255, 255, 255, 0.3);
}

.watch-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #FF0050, #00F2EA);
    width: 0%;
    transition: width 0.1s linear;
}

/* Negative Feedback Buttons */
.negative-feedback {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

.negative-btn {
    background: rgba(255, 0, 0, 0.1);
    border: 1px solid #ff4444;
    color: #ff4444;
    padding: 4px 8px;
    border-radius: 8px;
    font-size: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.negative-btn:hover {
    background: rgba(255, 0, 0, 0.2);
}

/* Viewed Videos Link */
.viewed-videos-link {
    text-align: center;
    margin: 20px 0;
}

.viewed-videos-btn {
    background: linear-gradient(45deg, #ff6b6b, #ee5a24);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 25px;
    font-weight: bold;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    font-size: 16px;
}

.viewed-videos-btn:hover {
    background: linear-gradient(45deg, #ff5252, #e74c3c);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.3);
}

/* Responsive improvements */
@media (max-width: 768px) {
    .algorithm-stats {
        flex-direction: column;
        gap: 10px;
    }
    
    .stat-item {
        min-width: auto;
    }
    
    .score-breakdown {
        left: 5px;
        right: 5px;
        min-width: auto;
    }
    
    .advanced-metrics {
        flex-direction: column;
    }
}

/* Keep all previous CSS styles */
header {
    background: #1e1e2f;
    color: #fff;
    padding: 10px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}
header h1 {
    margin: 0;
    font-size: 22px;
    flex-grow: 1;
}
header nav a {
    color: #fff;
    background: #0053ba;
    border: none;
    padding: 8px 14px;
    border-radius: 4px;
    text-decoration: none;
    margin: 5px 3px;
    display: inline-block;
    cursor: pointer;
}

/* Category Filter Styles */
.category-filter {
    background: #2c2c3d;
    padding: 20px;
    margin: 20px 0;
    border-radius: 12px;
    border: 2px solid #7b68ee;
}

.category-filter h3 {
    margin: 0 0 15px 0;
    color: #7b68ee;
    font-size: 18px;
    text-align: center;
}

.category-select-container {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
}

.category-select {
    padding: 12px 20px;
    border: 2px solid #7b68ee;
    border-radius: 25px;
    background: #1e1e2f;
    color: white;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 200px;
}

.category-select:focus {
    outline: none;
    border-color: #9370db;
    box-shadow: 0 0 10px rgba(123, 104, 238, 0.5);
}

.category-select:hover {
    background: #2c2c3d;
}

.filter-btn {
    background: linear-gradient(45deg, #7b68ee, #9370db);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 12px 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-btn:hover {
    background: linear-gradient(45deg, #6a5acd, #7b68ee);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.current-category {
    text-align: center;
    margin-top: 15px;
    font-size: 14px;
    color: #ccc;
}

.current-category .category-name {
    color: #7b68ee;
    font-weight: bold;
    background: rgba(123, 104, 238, 0.1);
    padding: 4px 12px;
    border-radius: 15px;
    margin-left: 8px;
}

.reels-container {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    background-color: #1e1e2f;
}
.reel {
    background: #2c2c3d;
    border-radius: 8px;
    box-shadow: 0 2px 6px #ccc;
    padding: 15px;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    position: relative;
}
.reel-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}
.reel-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
}
.reel-header .username {
    margin-left: 10px;
    font-weight: bold;
    cursor: pointer;
    color: #007bff;
    flex-grow: 1;
}
.reel-content {
    white-space: pre-wrap;
    max-height: 4.5em;
    overflow: hidden;
    position: relative;
    transition: max-height 0.3s ease;
    margin-bottom: 10px;
}
.reel-content.expanded {
    max-height: none;
}
.show-more-btn {
    background: none;
    border: none;
    color: #007bff;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    margin: 0 0 8px 0;
    user-select: none;
}

/* Video reel styling */
.video-reel-container {
  position: relative;
  width: 100%;
  margin-bottom: 10px;
  overflow: hidden;
  border-radius: 10px;
}
.video-reel-scroller {
  display: flex;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.video-reel-scroller::-webkit-scrollbar {
  display: none;
}
.video-reel-item {
  position: relative;
  flex: 0 0 auto;
  width: 100%;
  scroll-snap-align: start;
}
.video-reel-item video {
  width: 100%;
  height: auto;
  max-height: 600px;
  object-fit: contain;
  background: #000;
  border-radius: 10px;
  cursor: pointer;
}
.video-controls {
  position: absolute;
  bottom: 15px;
  right: 15px;
  z-index: 10;
  display: flex;
  gap: 10px;
}
.sound-toggle, .download-btn {
  background: rgba(0, 0, 0, 0.5);
  color: white;
  border: none;
  border-radius: 50%;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 18px;
}
.download-btn {
  background: rgba(0, 0, 0, 0.7);
}
.video-count-indicator {
  position: absolute;
  top: 15px;
  right: 15px;
  background: rgba(0, 0, 0, 0.5);
  color: white;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
}
.video-pagination {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 10px;
}
.video-pagination-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ccc;
  cursor: pointer;
  transition: background 0.3s ease;
}
.video-pagination-dot.active {
  background: #0095f6;
}

.actions {
    display: flex;
    gap: 20px;
    font-size: 14px;
    align-items: center;
}
.actions span, .actions button {
    cursor: pointer;
    color: #007bff;
    user-select: none;
}
.liked {
    font-weight: bold;
    color: #d9534f;
}

.follow-btn {
  margin-left: 10px;
  padding: 6px 14px;
  border: none;
  border-radius: 20px;
  cursor: pointer;
  font-weight: bold;
  background: linear-gradient(45deg, #ff004f, #c972ff);
  color: white;
  user-select: none;
  transition: all 0.3s ease;
}
.follow-btn.following {
  background: #888;
  box-shadow: inset 0 1px 5px #555;
  color: #ddd;
}
.follow-btn:hover:not(.following) {
  background-color: #d60040;
}

/* Category badges */
.category-badges {
    margin: 8px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.category-badge {
    background: rgba(123, 104, 238, 0.2);
    color: #7b68ee;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    border: 1px solid #7b68ee;
}

.engagement-rate {
    font-size: 11px;
    color: #888;
    margin-left: 8px;
}

/* Download progress indicator */
.download-progress {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(0, 0, 0, 0.8);
  color: white;
  padding: 15px 20px;
  border-radius: 8px;
  z-index: 10000;
  display: none;
}

.no-videos {
    text-align: center;
    padding: 40px;
    background: #2c2c3d;
    border-radius: 12px;
    margin: 20px 0;
}

.no-videos h3 {
    color: #7b68ee;
    margin-bottom: 15px;
}

.no-videos p {
    color: #ccc;
    margin-bottom: 20px;
}

.upload-btn {
    background: linear-gradient(45deg, #7b68ee, #9370db);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 25px;
    font-weight: bold;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.upload-btn:hover {
    background: linear-gradient(45deg, #6a5acd, #7b68ee);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

@media (max-width: 600px) {
  .video-reel-item video {
    max-height: 500px;
  }
  
  .category-select-container {
    flex-direction: column;
  }
  
  .category-select {
    min-width: 100%;
  }
  
  .filter-btn {
    width: 100%;
  }
  
  .score-breakdown {
    left: 5px;
    right: 5px;
    min-width: auto;
  }
}
</style>
</head>
<body>

<div style="text-align: center; padding: 15px; background: #1e1e2f; border-bottom: 1px solid #ccc;margin-top: 50px;">
    <a href="profile.php?id=<?= $currentUserId ?>" title="My Profile" style="display: inline-block;">
        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="My Profile Picture" 
             style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #007bff;" />
    </a>
    <h2 style="color: #7b68ee;">Video Reels</h2>
    <p style="color: #ccc; font-size: 12px;">Advanced TikTok-style algorithm with real-time learning</p>
</div>

<!-- Enhanced Algorithm Dashboard -->
<div class="algorithm-dashboard">
    <div class="algorithm-stats">
        <div class="stat-item">
            <div class="stat-value"><?= count($posts) ?></div>
            <div class="stat-label">New Videos</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= count($viewedVideoIds) ?></div>
            <div class="stat-label">Viewed Videos</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= round($userEngagementScore * 100, 1) ?>%</div>
            <div class="stat-label">Your Engagement</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= $totalInteractions ?></div>
            <div class="stat-label">Recent Interactions</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= $isNewUser ? 'New User' : 'Established' ?></div>
            <div class="stat-label">User Type</div>
        </div>
    </div>
</div>

<!-- Viewed Videos Link -->
<div class="viewed-videos-link">
    <a href="viewed_videos.php" class="viewed-videos-btn">
        📺 View Your Watched Videos (<?= count($viewedVideoIds) ?>)
    </a>
</div>

<!-- Real-time Learning Indicator -->
<div class="learning-indicator" id="learningIndicator">
    🧠 Learning from your interactions...
</div>

<!-- Category Filter Section -->
<div class="category-filter">
    <h3>🎯 Filter Videos by Category</h3>
    <form method="GET" action="video.php" class="category-select-container">
        <select name="category" class="category-select" onchange="this.form.submit()">
            <?php foreach ($categories as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>" <?= $selectedCategory === $value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="filter-btn">Apply Filter</button>
    </form>
    <div class="current-category">
        Currently viewing: <span class="category-name"><?= htmlspecialchars($categories[$selectedCategory]) ?></span>
        <?php if ($selectedCategory !== 'All'): ?>
            <span style="color: #888; margin-left: 10px;">(<?= count($posts) ?> new videos found)</span>
        <?php endif; ?>
    </div>
</div>

<!-- NEW: Country Trending Section -->
<?php if (!empty($countryTrendingHashtags)): ?>
<div class="algorithm-dashboard" style="background: linear-gradient(135deg, #7b68ee 0%, #9370db 100%);">
    <h3 style="margin: 0 0 15px 0; text-align: center;">📍 Trending in <?= $userCountry ?></h3>
    <div class="algorithm-stats">
        <?php foreach (array_slice($countryTrendingHashtags, 0, 5) as $hashtag): ?>
            <div class="stat-item">
                <div class="stat-value">#<?= htmlspecialchars($hashtag['hashtag']) ?></div>
                <div class="stat-label"><?= $hashtag['usage_count'] ?> uses</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Emerging Trends Section -->
<?php if (!empty($emergingTrends)): ?>
<div class="algorithm-dashboard" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);">
    <h3 style="margin: 0 0 15px 0; text-align: center;">🔥 Emerging Trends</h3>
    <div class="algorithm-stats">
        <?php foreach (array_slice($emergingTrends, 0, 4) as $trend): ?>
            <div class="stat-item">
                <div class="stat-value"><?= $trend['post_count'] ?></div>
                <div class="stat-label"><?= $trend['category1'] ?: 'Trending' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="reels-container" id="reelsContainer">
    <?php if (empty($posts)): ?>
        <div class="no-videos">
            <h3>No new videos found</h3>
            <p>
                <?php if ($selectedCategory === 'All'): ?>
                    You've watched all available videos! Check out your <a href="viewed_videos.php" style="color: #7b68ee;">watched videos</a> or upload new content.
                <?php else: ?>
                    No new videos found in the <strong><?= htmlspecialchars($categories[$selectedCategory]) ?></strong> category.
                    <br>You may have already watched all videos in this category.
                    <?php if ($selectedCategory !== 'All'): ?>
                        <br>Try selecting a different category or check your <a href="viewed_videos.php" style="color: #7b68ee;">watched videos</a>.
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <a href="make_post.php" class="upload-btn">📹 Upload Video</a>
            <a href="viewed_videos.php" class="viewed-videos-btn" style="margin-left: 10px;">📺 View Watched Videos</a>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="reel" data-post-id="<?= $post['id'] ?>">
                <!-- Enhanced TikTok Algorithm Score -->
                <div class="tiktok-score" title="Advanced Algorithm Score - Higher is better">
                    ⚡ <?= $post['tiktok_score'] ?>
                </div>
                <div class="score-breakdown">
                    <div class="score-item">
                        <span class="score-label">User Preference:</span>
                        <span class="score-value"><?= $post['score_breakdown']['preference'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['preference'] / 25) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Engagement:</span>
                        <span class="score-value"><?= $post['score_breakdown']['engagement'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['engagement'] / 20) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Personalization:</span>
                        <span class="score-value"><?= $post['score_breakdown']['personalization'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['personalization'] / 20) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Quality & Influence:</span>
                        <span class="score-value"><?= $post['score_breakdown']['quality'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['quality'] / 15) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Country Relevance:</span>
                        <span class="score-value"><?= $post['score_breakdown']['country'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['country'] / 10) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Hashtag Relevance:</span>
                        <span class="score-value"><?= $post['score_breakdown']['hashtag'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['hashtag'] / 5) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Recency & Patterns:</span>
                        <span class="score-value"><?= $post['score_breakdown']['recency'] ?></span>
                    </div>
                    <div class="score-bar"><div class="score-fill" style="width: <?= ($post['score_breakdown']['recency'] / 5) * 100 ?>%"></div></div>
                    
                    <div class="score-item">
                        <span class="score-label">Your Engagement Modifier:</span>
                        <span class="score-value">x<?= $post['score_breakdown']['user_modifier'] ?></span>
                    </div>
                </div>
                
                <!-- Advanced Metrics Indicators -->
                <div class="advanced-metrics">
                    <div class="metric-indicator preference-indicator">
                        Preference: <?= $post['user_preference_score'] ?>
                    </div>
                    <div class="metric-indicator trending-indicator">
                        Trend: <?= $post['trending_velocity'] ?>
                    </div>
                    <div class="metric-indicator virality-indicator">
                        Virality: <?= $post['virality_probability'] ?>
                    </div>
                    <div class="metric-indicator">
                        Proximity: <?= $post['social_proximity'] ?>
                    </div>
                    <div class="metric-indicator influence-indicator">
                        Influence: <?= $post['creator_influence'] ?>
                    </div>
                    <div class="metric-indicator">
                        Affinity: <?= $post['affinity_score'] ?>
                    </div>
                    <div class="metric-indicator attention-indicator">
                        Attention: <?= $post['attention_match'] ?>
                    </div>
                    <div class="metric-indicator sound-indicator">
                        Sound: <?= $post['sound_virality'] ?>
                    </div>
                    <div class="metric-indicator trending-indicator">
                        Trend Score: <?= $post['trend_score'] ?>
                    </div>
                    <!-- NEW: Country and Hashtag Indicators -->
                    <div class="metric-indicator country-indicator">
                        Country: <?= $post['country_relevance'] ?>
                    </div>
                    <div class="metric-indicator hashtag-indicator">
                        Hashtags: <?= $post['hashtag_relevance'] ?>
                    </div>
                    <?php if (isset($post['is_country_trending']) && $post['is_country_trending']): ?>
                        <div class="metric-indicator trending-country-indicator">
                            🔥 Local Trending
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Behavior Tracking Indicators -->
                <div class="behavior-indicators">
                    <div class="behavior-indicator completion-rate">
                        Completion: <?= $post['completion_display'] ?>
                    </div>
                    <?php if ($post['rewatch_rate'] > 0): ?>
                        <div class="behavior-indicator rewatch-rate">
                            Rewatch: <?= round($post['rewatch_rate'] * 100, 1) ?>%
                        </div>
                    <?php endif; ?>
                    <?php if ($post['engagement_trend'] > 0): ?>
                        <div class="behavior-indicator" style="background: rgba(76, 175, 80, 0.1); border-color: #4CAF50; color: #4CAF50;">
                            Trend: +<?= round($post['engagement_trend'] * 100, 1) ?>%
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="reel-header">
                    <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>"
                         alt="Profile" onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'" />
                    <div class="username"
                         onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                        <?= htmlspecialchars($post['username']) ?>
                        <span class="engagement-rate">(<?= $post['engagement_rate'] ?>% engagement)</span>
                    </div>
                               <!-- Post Header Display -->
                <?php if (!empty($post['post_header'])): ?>
                    <div class="post-header-display" id="post-header-<?= $post['id'] ?>">
                        <?= 
                            preg_replace(
                                '/#(\w+)/',
                                '<span class="post-header-hashtag">#$1</span>',
                                htmlspecialchars($post['post_header'])
                            ) 
                        ?>
                    </div>
                <?php endif; ?>

                    
                    <?php if ($post['author_id'] !== $userId): ?>
                        <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" data-user-id="<?= $post['author_id'] ?>">
                            <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Category Badges -->
                <?php if (!empty($post['category1']) || !empty($post['category2']) || !empty($post['category3'])): ?>
                    <div class="category-badges">
                        <?php if (!empty($post['category1'])): ?>
                            <span class="category-badge"><?= htmlspecialchars($post['category1']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($post['category2'])): ?>
                            <span class="category-badge"><?= htmlspecialchars($post['category2']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($post['category3'])): ?>
                            <span class="category-badge"><?= htmlspecialchars($post['category3']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="reel-content" id="reel-content-<?= $post['id'] ?>">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
                <?php if (mb_strlen(strip_tags($post['content'])) > 100): ?>
                    <button class="show-more-btn" data-post-id="<?= $post['id'] ?>">Show More</button>
                <?php endif; ?>

                <!-- Video display with enhanced tracking -->
                <?php if ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
                    <?php
                    $mediaArray = explode(',', $post['media_url']);
                    $videoCount = count($mediaArray);
                    ?>
                    <div class="video-reel-container" data-post-id="<?= $post['id'] ?>">
                        <div class="video-reel-scroller">
                            <?php foreach ($mediaArray as $index => $video): 
                                $video = trim($video);
                            ?>
                                <div class="video-reel-item" data-video-index="<?= $index ?>">
                                    <video <?= $index === 0 ? 'autoplay loop' : 'preload="none"' ?> 
                                           data-video-id="<?= $post['id'] ?>" 
                                           data-video-index="<?= $index ?>">
                                        <source src="<?= htmlspecialchars($video) ?>" type="video/mp4" />
                                        Your browser does not support the video tag.
                                    </video>
                                    <!-- Watch progress bar -->
                                    <div class="watch-progress">
                                        <div class="watch-progress-fill" id="progress-<?= $post['id'] ?>-<?= $index ?>"></div>
                                    </div>
                                    <div class="enhanced-video-controls">
                                        <button class="sound-toggle" data-muted="false">🔊</button>
                                        <button class="download-btn" data-video-url="<?= htmlspecialchars($video) ?>" title="Download Video">💾</button>
                                    </div>
                                    <?php if ($videoCount > 1): ?>
                                        <div class="video-count-indicator"><?= ($index + 1) . '/' . $videoCount ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($videoCount > 1): ?>
                            <div class="video-pagination">
                                <?php for ($i = 0; $i < $videoCount; $i++): ?>
                                    <div class="video-pagination-dot <?= $i === 0 ? 'active' : '' ?>" data-video-index="<?= $i ?>"></div>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="actions">
                    <span class="like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                      Like (<span class="like-count"><?= $post['likes_count'] ?></span>)
                    </span>
                    <span class="comment-btn" onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                      Comment (<?= $post['comments_count'] ?>)
                    </span>
                    <span class="share-btn" onclick="alert('Share is coming soon!')">Share (<?= $post['shares_count'] ?>)</span>
                </div>
                
                <!-- Negative Feedback Section -->
                <div class="negative-feedback">
                    <button class="negative-btn" data-post-id="<?= $post['id'] ?>" data-feedback-type="skip">
                        👎 Not Interested
                    </button>
                    <button class="negative-btn" data-post-id="<?= $post['id'] ?>" data-feedback-type="hide">
                        🚫 Hide
                    </button>
                    <button class="negative-btn" data-post-id="<?= $post['id'] ?>" data-feedback-type="report">
                        ⚠️ Report
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Download progress indicator -->
<div class="download-progress" id="downloadProgress">
    Downloading video...
</div>

<script>
// Enhanced User Behavior Tracking with Advanced Features
class AdvancedBehaviorTracker {
    constructor() {
        this.watchData = new Map();
        this.scrollData = new Map();
        this.lastScrollTime = Date.now();
        this.userEmbedding = [];
        this.initAdvancedTracking();
    }
    
    initAdvancedTracking() {
        this.initScrollTracking();
        this.initNegativeFeedback();
        this.initABTesting();
        this.initDownloadTracking();
    }
    
    initScrollTracking() {
        let lastScrollY = window.scrollY;
        let lastScrollTime = Date.now();
        
        window.addEventListener('scroll', () => {
            const currentTime = Date.now();
            const timeDiff = (currentTime - lastScrollTime) / 1000;
            const scrollDiff = Math.abs(window.scrollY - lastScrollY);
            
            if (timeDiff > 0) {
                const scrollVelocity = scrollDiff / timeDiff;
                this.trackScrollVelocity(scrollVelocity);
                
                // Track scroll past videos
                document.querySelectorAll('.reel').forEach(reel => {
                    const rect = reel.getBoundingClientRect();
                    if (rect.top < window.innerHeight && rect.bottom > 0) {
                        const postId = reel.getAttribute('data-post-id');
                        this.trackScrollPast(postId);
                    }
                });
            }
            
            lastScrollY = window.scrollY;
            lastScrollTime = currentTime;
        });
    }
    
    initDownloadTracking() {
        document.querySelectorAll('.download-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const videoUrl = e.target.getAttribute('data-video-url');
                const postId = e.target.closest('.reel').getAttribute('data-post-id');
                this.trackDownload(postId, videoUrl);
            });
        });
    }
    
    trackDownload(postId, videoUrl) {
        const formData = new FormData();
        formData.append('action', 'track_download');
        formData.append('post_id', postId);
        
        fetch('video.php', {
            method: 'POST',
            body: formData
        }).then(response => response.json())
          .then(data => {
              if (data.success) {
                  this.showLearningIndicator('💾 Download preference recorded');
              }
          })
          .catch(error => console.error('Download tracking error:', error));
    }
    
    trackScrollPast(postId) {
        // Only track if not already viewed
        if (!this.watchData.has(postId)) {
            this.sendBehaviorData(postId, 0.1, 'scroll_past', 0.1);
        }
    }
    
    initNegativeFeedback() {
        document.querySelectorAll('.negative-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const postId = e.target.getAttribute('data-post-id');
                const feedbackType = e.target.getAttribute('data-feedback-type');
                this.sendNegativeFeedback(postId, feedbackType);
            });
        });
    }
    
    initABTesting() {
        // Multi-armed bandit style exploration
        if (Math.random() < 0.15) {
            const reels = Array.from(document.querySelectorAll('.reel'));
            const lowScoringPosts = reels.filter(reel => {
                const score = parseFloat(reel.querySelector('.tiktok-score').textContent.replace('⚡', ''));
                return score < 50;
            });
            
            if (lowScoringPosts.length > 0) {
                const randomPost = lowScoringPosts[Math.floor(Math.random() * lowScoringPosts.length)];
                document.getElementById('reelsContainer').prepend(randomPost);
                this.showLearningIndicator('🔍 Exploring new content for you...');
            }
        }
    }
    
    trackScrollVelocity(velocity) {
        const sessionData = this.getSessionData();
        sessionData.scrollVelocities.push(velocity);
        
        if (sessionData.scrollVelocities.length > 10) {
            sessionData.scrollVelocities.shift();
        }
        
        this.saveSessionData(sessionData);
    }
    
    trackVideoWatch(postId, videoIndex, currentTime, duration, eventType) {
        if (!this.watchData.has(postId)) {
            this.watchData.set(postId, {
                startTime: Date.now(),
                watchSessions: [],
                totalWatchTime: 0,
                completed: false,
                rewatchCount: 0
            });
        }
        
        const postData = this.watchData.get(postId);
        
        if (eventType === 'play') {
            postData.currentSession = {
                start: Date.now(),
                pauses: []
            };
        } else if (eventType === 'pause') {
            if (postData.currentSession) {
                postData.currentSession.pauses.push({
                    time: currentTime,
                    timestamp: Date.now()
                });
            }
        } else if (eventType === 'ended') {
            postData.completed = true;
            postData.completionTime = Date.now();
            this.sendBehaviorData(postId, 1.0, 'completion', duration);
        } else if (eventType === 'timeupdate') {
            const progress = currentTime / duration;
            
            // Track rewatch
            if (currentTime < 2 && postData.totalWatchTime > duration * 0.8) {
                postData.rewatchCount++;
                if (postData.rewatchCount === 1) {
                    this.sendBehaviorData(postId, 1.0, 'rewatch', duration);
                }
            }
            
            // MODIFIED: Only count as viewed if watched for at least 5 seconds
            if (currentTime >= 5) {
                // Send progress update every 5 seconds of watch time
                if (Math.floor(currentTime) % 5 === 0) {
                    this.sendBehaviorData(postId, progress, 'progress', currentTime);
                }
            }
            
            // Track quick scroll away (less than 2 seconds)
            if (currentTime < 2 && eventType === 'timeupdate') {
                const timeSinceStart = Date.now() - postData.startTime;
                if (timeSinceStart < 2000) {
                    this.sendBehaviorData(postId, progress, 'quick_skip', currentTime);
                }
            }
        }
    }
    
    sendBehaviorData(postId, completionRate, interactionType, watchTime = 0) {
        const sessionData = this.getSessionData();
        const avgScrollVelocity = sessionData.scrollVelocities.length > 0 
            ? sessionData.scrollVelocities.reduce((a, b) => a + b, 0) / sessionData.scrollVelocities.length
            : 0;
        
        const formData = new FormData();
        formData.append('action', 'track_behavior');
        formData.append('post_id', postId);
        formData.append('watch_time', watchTime);
        formData.append('completion_rate', completionRate);
        formData.append('scroll_velocity', avgScrollVelocity);
        formData.append('interaction_type', interactionType);
        
        fetch('video.php', {
            method: 'POST',
            body: formData
        }).then(response => response.json())
          .then(data => {
              if (data.success && interactionType === 'completion') {
                  this.showLearningIndicator('🎯 Learned from your viewing pattern');
              }
          })
          .catch(error => console.error('Tracking error:', error));
    }
    
    sendNegativeFeedback(postId, feedbackType) {
        const formData = new FormData();
        formData.append('action', 'negative_feedback');
        formData.append('post_id', postId);
        formData.append('feedback_type', feedbackType);
        
        fetch('video.php', {
            method: 'POST',
            body: formData
        }).then(response => response.json())
          .then(data => {
              if (data.success) {
                  this.showLearningIndicator('📝 Thanks for your feedback');
                  document.querySelector(`.reel[data-post-id="${postId}"]`).style.opacity = '0.5';
              }
          })
          .catch(error => console.error('Feedback error:', error));
    }
    
    showLearningIndicator(message = '🧠 Learning from your interactions...') {
        const indicator = document.getElementById('learningIndicator');
        indicator.textContent = message;
        indicator.style.display = 'block';
        setTimeout(() => {
            indicator.style.display = 'none';
        }, 3000);
    }
    
    getSessionData() {
        const sessionId = 'user_session_' + <?= $userId ?>;
        let data = sessionStorage.getItem(sessionId);
        if (!data) {
            data = {
                scrollVelocities: [],
                videoCompletions: [],
                negativeFeedback: [],
                sessionStart: Date.now()
            };
            this.saveSessionData(data);
        } else {
            data = JSON.parse(data);
        }
        return data;
    }
    
    saveSessionData(data) {
        const sessionId = 'user_session_' + <?= $userId ?>;
        sessionStorage.setItem(sessionId, JSON.stringify(data));
    }
}

// Enhanced Video Tracking
function initEnhancedVideoTracking() {
    document.querySelectorAll('video').forEach(video => {
        const postId = video.getAttribute('data-video-id');
        const videoIndex = video.getAttribute('data-video-index');
        
        video.addEventListener('timeupdate', function() {
            const progress = (this.currentTime / this.duration) * 100;
            const progressBar = document.getElementById(`progress-${postId}-${videoIndex}`);
            if (progressBar) {
                progressBar.style.width = progress + '%';
            }
            
            advancedBehaviorTracker.trackVideoWatch(
                postId, 
                videoIndex, 
                this.currentTime, 
                this.duration, 
                'timeupdate'
            );
        });
        
        video.addEventListener('play', function() {
            advancedBehaviorTracker.trackVideoWatch(
                postId, 
                videoIndex, 
                this.currentTime, 
                this.duration, 
                'play'
            );
        });
        
        video.addEventListener('pause', function() {
            advancedBehaviorTracker.trackVideoWatch(
                postId, 
                videoIndex, 
                this.currentTime, 
                this.duration, 
                'pause'
            );
        });
        
        video.addEventListener('ended', function() {
            advancedBehaviorTracker.trackVideoWatch(
                postId, 
                videoIndex, 
                this.currentTime, 
                this.duration, 
                'ended'
            );
        });
    });
}

// Enhanced scroll-based video pausing
function initScrollBasedVideoControl() {
    const videoObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const video = entry.target;
            if (!entry.isIntersecting) {
                if (!video.paused) {
                    video.pause();
                }
            } else {
                const container = video.closest('.video-reel-container');
                const scroller = container.querySelector('.video-reel-scroller');
                const scrollPos = scroller.scrollLeft;
                const containerWidth = scroller.offsetWidth;
                const currentIndex = Math.round(scrollPos / containerWidth);
                const videoIndex = parseInt(video.getAttribute('data-video-index'));
                
                if (currentIndex === videoIndex && video.paused) {
                    video.play().catch(e => console.log('Autoplay prevented:', e));
                }
            }
        });
    }, { threshold: 0.5 });
    
    document.querySelectorAll('video').forEach(video => {
        videoObserver.observe(video);
    });
}

// Initialize advanced behavior tracker
const advancedBehaviorTracker = new AdvancedBehaviorTracker();

// Enhanced initialization
document.addEventListener('DOMContentLoaded', function() {
    initEnhancedVideoTracking();
    initScrollBasedVideoControl();
    
    // Initialize all videos to be unmuted on page load
    const allVideos = document.querySelectorAll('video');
    allVideos.forEach(video => {
        video.muted = false;
    });
    
    // Score breakdown tooltip
    document.querySelectorAll('.tiktok-score').forEach(score => {
        score.addEventListener('mouseenter', function() {
            const breakdown = this.nextElementSibling;
            if (breakdown && breakdown.classList.contains('score-breakdown')) {
                breakdown.style.display = 'block';
            }
        });
        
        score.addEventListener('mouseleave', function() {
            const breakdown = this.nextElementSibling;
            if (breakdown && breakdown.classList.contains('score-breakdown')) {
                breakdown.style.display = 'none';
            }
        });
    });
    
    // Follow button functionality
    document.querySelectorAll('.follow-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            const isFollowing = this.classList.contains('following');
            const action = isFollowing ? 'unfollow' : 'follow';
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('followed_id', userId);
            
            fetch('video.php', {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      if (action === 'follow') {
                          this.classList.add('following');
                          this.textContent = 'Following';
                      } else {
                          this.classList.remove('following');
                          this.textContent = 'Follow';
                      }
                  }
              })
              .catch(error => console.error('Follow error:', error));
        });
    });
    
    // Like button functionality
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.getAttribute('data-post-id');
            const isLiked = this.classList.contains('liked');
            const action = isLiked ? 'unlike' : 'like';
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('post_id', postId);
            
            fetch('video.php', {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      const likeCount = this.querySelector('.like-count');
                      if (action === 'like') {
                          this.classList.add('liked');
                          likeCount.textContent = data.likes_count;
                      } else {
                          this.classList.remove('liked');
                          likeCount.textContent = data.likes_count;
                      }
                  }
              })
              .catch(error => console.error('Like error:', error));
        });
    });
    
    // Show more functionality
    document.querySelectorAll('.show-more-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.getAttribute('data-post-id');
            const content = document.getElementById(`reel-content-${postId}`);
            if (content) {
                content.classList.toggle('expanded');
                this.textContent = content.classList.contains('expanded') ? 'Show Less' : 'Show More';
            }
        });
    });
    
    // Sound toggle functionality
    document.querySelectorAll('.sound-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const video = this.closest('.video-reel-item').querySelector('video');
            const isMuted = video.muted;
            video.muted = !isMuted;
            this.textContent = video.muted ? '🔇' : '🔊';
            this.setAttribute('data-muted', video.muted);
        });
    });
    
    // Download button functionality
    document.querySelectorAll('.download-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const videoUrl = this.getAttribute('data-video-url');
            const progress = document.getElementById('downloadProgress');
            progress.style.display = 'block';
            
            fetch(videoUrl)
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = 'video.mp4';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    progress.style.display = 'none';
                })
                .catch(error => {
                    console.error('Download error:', error);
                    progress.style.display = 'none';
                    alert('Download failed. Please try again.');
                });
        });
    });
});

// Helper function to format post header with hashtag styling
function formatPostHeader($header) {
    // Convert hashtags to styled spans
    $formatted = preg_replace(
        '/#(\w+)/',
        '<span class="post-header-hashtag">#$1</span>',
        htmlspecialchars($header)
    );
    return $formatted;
}

</script>

</body>
</html>