<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "header2.php";
require_once "footer.php";
require_once "config.php";

// Get user's country and preferences
$userId = $_SESSION['user_id'];
$userStmt = $pdo->prepare("SELECT country, created_at FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);
$userCountry = $userData['country'];

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

// ENHANCED USER PREFERENCE TRACKER WITH ALL REQUIRED FEATURES
class UserPreferenceTracker {
    private $pdo;
    private $userId;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
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
            
            $this->updateContentPreferencesFromPost($postId, 1.0);
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
            
            $this->updateContentPreferencesFromPost($postId, 2.0);
            return true;
        } catch (Exception $e) {
            error_log("Rewatch tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track engagement signals (like, comment, share, download)
    public function trackEngagement($postId, $authorId, $engagementType) {
        try {
            $strengths = [
                'like' => 1.2,
                'comment' => 1.8,
                'share' => 2.2,
                'download' => 1.9
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
            
            $this->updateContentPreferencesFromPost($postId, $strength * 0.8);
            return true;
        } catch (Exception $e) {
            error_log("Engagement tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track interested/not_interested signals
    public function trackInterest($postId, $authorId, $interestType) {
        try {
            $strengths = [
                'interested' => 1.5,
                'not_interested' => -2.0
            ];
            
            $strength = $strengths[$interestType] ?? 0;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + ?,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId, $interestType, $strength, $strength]);
            
            // For not_interested, check if we should block the author
            if ($interestType === 'not_interested') {
                $this->checkAndBlockAuthor($authorId);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Interest tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Track video skip (less than 5 seconds)
    public function trackVideoSkip($postId, $authorId, $watchTime = 0) {
        try {
            $skipStrength = -1.0;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_preference_signals 
                (user_id, author_id, post_id, signal_type, signal_strength, created_at) 
                VALUES (?, ?, ?, 'video_skip', ?, NOW())
                ON CONFLICT (user_id, author_id, signal_type) 
                DO UPDATE SET 
                    signal_strength = user_preference_signals.signal_strength + ?,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $authorId, $postId, $skipStrength, $skipStrength]);
            
            return true;
        } catch (Exception $e) {
            error_log("Video skip tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Update content preferences based on interaction
    private function updateContentPreferencesFromPost($postId, $strength) {
        try {
            // Get post content details
            $stmt = $this->pdo->prepare("
                SELECT post_header, content, category1, category2, category3 
                FROM posts WHERE id = ?
            ");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($post) {
                // Extract hashtags from content
                preg_match_all('/#(\w+)/', $post['content'], $hashtags);
                
                // Update hashtag preferences
                foreach ($hashtags[0] as $hashtag) {
                    $this->updateHashtagPreference($hashtag, $strength);
                }
                
                // Update post header/keyword preferences
                if (!empty($post['post_header'])) {
                    $keywords = explode(' ', $post['post_header']);
                    foreach ($keywords as $keyword) {
                        if (strlen($keyword) > 2) {
                            $this->updateKeywordPreference($keyword, $strength * 0.5);
                        }
                    }
                }
                
                // Update category preferences
                $categories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
                foreach ($categories as $category) {
                    $this->updateCategoryPreference($category, $strength);
                }
            }
        } catch (Exception $e) {
            error_log("Content preference update error: " . $e->getMessage());
        }
    }
    
    private function updateHashtagPreference($hashtag, $strength) {
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
    
    private function updateKeywordPreference($keyword, $strength) {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_keyword_preferences 
            (user_id, keyword, preference_score, created_at) 
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (user_id, keyword) 
            DO UPDATE SET 
                preference_score = user_keyword_preferences.preference_score + ?,
                updated_at = NOW()
        ");
        $stmt->execute([$this->userId, $keyword, $strength, $strength]);
    }
    
    private function updateCategoryPreference($category, $strength) {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_category_preferences 
            (user_id, category, preference_score, created_at) 
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (user_id, category) 
            DO UPDATE SET 
                preference_score = user_category_preferences.preference_score + ?,
                updated_at = NOW()
        ");
        $stmt->execute([$this->userId, $category, $strength, $strength]);
    }
    
    // Check if user has marked not_interested 5+ times for an author
    private function checkAndBlockAuthor($authorId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as not_interested_count 
                FROM user_preference_signals 
                WHERE user_id = ? AND author_id = ? AND signal_type = 'not_interested'
            ");
            $stmt->execute([$this->userId, $authorId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['not_interested_count'] >= 5) {
                // Block the author
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
    
    // Get user's content preferences
    public function getContentPreferences() {
        try {
            // Get hashtag preferences
            $hashtagStmt = $this->pdo->prepare("
                SELECT hashtag, preference_score 
                FROM user_hashtag_preferences 
                WHERE user_id = ? AND preference_score > 0
                ORDER BY preference_score DESC LIMIT 20
            ");
            $hashtagStmt->execute([$this->userId]);
            $hashtags = $hashtagStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get keyword preferences
            $keywordStmt = $this->pdo->prepare("
                SELECT keyword, preference_score 
                FROM user_keyword_preferences 
                WHERE user_id = ? AND preference_score > 0
                ORDER BY preference_score DESC LIMIT 20
            ");
            $keywordStmt->execute([$this->userId]);
            $keywords = $keywordStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get category preferences
            $categoryStmt = $this->pdo->prepare("
                SELECT category, preference_score 
                FROM user_category_preferences 
                WHERE user_id = ? 
                ORDER BY preference_score DESC
            ");
            $categoryStmt->execute([$this->userId]);
            $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'hashtags' => $hashtags,
                'keywords' => $keywords,
                'categories' => $categories
            ];
        } catch (Exception $e) {
            error_log("Content preferences error: " . $e->getMessage());
            return ['hashtags' => [], 'keywords' => [], 'categories' => []];
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
    
    // Predict user preferences based on history
    public function predictUserPreferences() {
        $preferences = $this->getContentPreferences();
        $predictedInterests = [];
        
        // Combine all preference signals
        foreach ($preferences['hashtags'] as $hashtag) {
            $predictedInterests[$hashtag['hashtag']] = $hashtag['preference_score'] * 1.2;
        }
        
        foreach ($preferences['keywords'] as $keyword) {
            $predictedInterests[$keyword['keyword']] = $keyword['preference_score'] * 1.0;
        }
        
        foreach ($preferences['categories'] as $category) {
            $predictedInterests[$category['category']] = $category['preference_score'] * 1.5;
        }
        
        arsort($predictedInterests);
        return array_slice($predictedInterests, 0, 15, true);
    }
    
    // Get user's interacted hashtags, headers, and content
    public function getUserInteractedContent() {
        try {
            // Get interacted hashtags
            $hashtagStmt = $this->pdo->prepare("
                SELECT DISTINCT LOWER(hashtag) as hashtag 
                FROM user_hashtag_preferences 
                WHERE user_id = ? AND preference_score > 0
            ");
            $hashtagStmt->execute([$this->userId]);
            $hashtags = $hashtagStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            // Get interacted keywords from post headers
            $keywordStmt = $this->pdo->prepare("
                SELECT DISTINCT LOWER(keyword) as keyword 
                FROM user_keyword_preferences 
                WHERE user_id = ? AND preference_score > 0
            ");
            $keywordStmt->execute([$this->userId]);
            $keywords = $keywordStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            return [
                'hashtags' => $hashtags,
                'keywords' => $keywords
            ];
        } catch (Exception $e) {
            error_log("User interacted content error: " . $e->getMessage());
            return ['hashtags' => [], 'keywords' => []];
        }
    }
}

// COUNTRY-BASED CONTENT PRIORITIZATION
class CountryContentPrioritizer {
    private $pdo;
    private $userCountry;
    
    public function __construct($pdo, $userCountry) {
        $this->pdo = $pdo;
        $this->userCountry = $userCountry;
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
            
            // Check if countries are in same region
            $regionScore = $this->getRegionSimilarity($this->userCountry, $authorCountry);
            
            return max(0.3, $regionScore); // Different country - lower relevance
        } catch (Exception $e) {
            error_log("Country relevance calculation error: " . $e->getMessage());
            return 0.3;
        }
    }
    
    private function getRegionSimilarity($country1, $country2) {
        $region1 = $this->findRegion($country1);
        $region2 = $this->findRegion($country2);
        
        if ($region1 === $region2) {
            return 0.8; // Same region - high relevance
        }
        
        // Check for neighboring regions or cultural similarities
        $neighboringRegions = $this->getNeighboringRegions($region1);
        if (in_array($region2, $neighboringRegions)) {
            return 0.6; // Neighboring regions - medium relevance
        }
        
        return 0.3; // Different regions - low relevance
    }
    
    private function findRegion($countryCode) {
        $regions = $this->getCountryRegions();
        return $regions[$countryCode] ?? 'Other';
    }
    
    private function getNeighboringRegions($region) {
        $neighbors = [
         'Europe' => ['Middle East', 'Asia', 'Africa'], // Added Africa
        'Asia' => ['Europe', 'Middle East', 'Oceania', 'Africa'], // Added Africa
        'Middle East' => ['Europe', 'Asia', 'Africa'],
        'Africa' => ['Middle East', 'Europe', 'Asia'], // More connections
        'North America' => ['South America', 'Asia'], // Added Asia for Pacific connection
        'South America' => ['North America', 'Africa'], // Added Africa
        'Oceania' => ['Asia', 'North America'] // Added North America
        ];
        
        return $neighbors[$region] ?? [];
    }
    
    private function getCountryRegions() {
        return [
            // Africa (54 countries)
            'DZ' => 'Africa', 'AO' => 'Africa', 'BJ' => 'Africa', 'BW' => 'Africa', 'BF' => 'Africa',
            'BI' => 'Africa', 'CV' => 'Africa', 'CM' => 'Africa', 'CF' => 'Africa', 'TD' => 'Africa',
            'KM' => 'Africa', 'CG' => 'Africa', 'CD' => 'Africa', 'DJ' => 'Africa', 'EG' => 'Africa',
            'GQ' => 'Africa', 'ER' => 'Africa', 'SZ' => 'Africa', 'ET' => 'Africa', 'GA' => 'Africa',
            'GM' => 'Africa', 'GH' => 'Africa', 'GN' => 'Africa', 'GW' => 'Africa', 'KE' => 'Africa',
            'LS' => 'Africa', 'LR' => 'Africa', 'LY' => 'Africa', 'MG' => 'Africa', 'MW' => 'Africa',
            'ML' => 'Africa', 'MR' => 'Africa', 'MU' => 'Africa', 'MA' => 'Africa', 'MZ' => 'Africa',
            'NA' => 'Africa', 'NE' => 'Africa', 'NG' => 'Africa', 'RW' => 'Africa', 'ST' => 'Africa',
            'SN' => 'Africa', 'SC' => 'Africa', 'SL' => 'Africa', 'SO' => 'Africa', 'ZA' => 'Africa',
            'SS' => 'Africa', 'SD' => 'Africa', 'TZ' => 'Africa', 'TG' => 'Africa', 'TN' => 'Africa',
            'UG' => 'Africa', 'ZM' => 'Africa', 'ZW' => 'Africa',

            // Asia (44 countries)
            'AF' => 'Asia', 'AM' => 'Asia', 'AZ' => 'Asia', 'BH' => 'Asia', 'BD' => 'Asia',
            'BT' => 'Asia', 'BN' => 'Asia', 'KH' => 'Asia', 'CN' => 'Asia', 'CY' => 'Asia',
            'GE' => 'Asia', 'IN' => 'Asia', 'ID' => 'Asia', 'IR' => 'Asia', 'IQ' => 'Asia',
            'IL' => 'Asia', 'JP' => 'Asia', 'JO' => 'Asia', 'KZ' => 'Asia', 'KW' => 'Asia',
            'KG' => 'Asia', 'LA' => 'Asia', 'LB' => 'Asia', 'MY' => 'Asia', 'MV' => 'Asia',
            'MN' => 'Asia', 'MM' => 'Asia', 'NP' => 'Asia', 'KP' => 'Asia', 'OM' => 'Asia',
            'PK' => 'Asia', 'PH' => 'Asia', 'QA' => 'Asia', 'RU' => 'Asia', 'SA' => 'Asia',
            'SG' => 'Asia', 'KR' => 'Asia', 'LK' => 'Asia', 'SY' => 'Asia', 'TW' => 'Asia',
            'TJ' => 'Asia', 'TH' => 'Asia', 'TR' => 'Asia', 'TM' => 'Asia', 'AE' => 'Asia',
            'UZ' => 'Asia', 'VN' => 'Asia', 'YE' => 'Asia',

            // Europe (45 countries)
            'AL' => 'Europe', 'AD' => 'Europe', 'AT' => 'Europe', 'BY' => 'Europe', 'BE' => 'Europe',
            'BA' => 'Europe', 'BG' => 'Europe', 'HR' => 'Europe', 'CY' => 'Europe', 'CZ' => 'Europe',
            'DK' => 'Europe', 'EE' => 'Europe', 'FI' => 'Europe', 'FR' => 'Europe', 'DE' => 'Europe',
            'GR' => 'Europe', 'HU' => 'Europe', 'IS' => 'Europe', 'IE' => 'Europe', 'IT' => 'Europe',
            'XK' => 'Europe', 'LV' => 'Europe', 'LI' => 'Europe', 'LT' => 'Europe', 'LU' => 'Europe',
            'MT' => 'Europe', 'MD' => 'Europe', 'MC' => 'Europe', 'ME' => 'Europe', 'NL' => 'Europe',
            'MK' => 'Europe', 'NO' => 'Europe', 'PL' => 'Europe', 'PT' => 'Europe', 'RO' => 'Europe',
            'SM' => 'Europe', 'RS' => 'Europe', 'SK' => 'Europe', 'SI' => 'Europe', 'ES' => 'Europe',
            'SE' => 'Europe', 'CH' => 'Europe', 'UA' => 'Europe', 'GB' => 'Europe', 'VA' => 'Europe',

            // North America (23 countries)
            'AG' => 'North America', 'BS' => 'North America', 'BB' => 'North America', 'BZ' => 'North America',
            'CA' => 'North America', 'CR' => 'North America', 'CU' => 'North America', 'DM' => 'North America',
            'DO' => 'North America', 'SV' => 'North America', 'GD' => 'North America', 'GT' => 'North America',
            'HT' => 'North America', 'HN' => 'North America', 'JM' => 'North America', 'MX' => 'North America',
            'NI' => 'North America', 'PA' => 'North America', 'KN' => 'North America', 'LC' => 'North America',
            'VC' => 'North America', 'TT' => 'North America', 'US' => 'North America',

            // South America (12 countries)
            'AR' => 'South America', 'BO' => 'South America', 'BR' => 'South America', 'CL' => 'South America',
            'CO' => 'South America', 'EC' => 'South America', 'GY' => 'South America', 'PY' => 'South America',
            'PE' => 'South America', 'SR' => 'South America', 'UY' => 'South America', 'VE' => 'South America',

            // Oceania (14 countries)
            'AU' => 'Oceania', 'FJ' => 'Oceania', 'KI' => 'Oceania', 'MH' => 'Oceania', 'FM' => 'Oceania',
            'NR' => 'Oceania', 'NZ' => 'Oceania', 'PW' => 'Oceania', 'PG' => 'Oceania', 'WS' => 'Oceania',
            'SB' => 'Oceania', 'TO' => 'Oceania', 'TV' => 'Oceania', 'VU' => 'Oceania',

            // Middle East (special regional grouping)
            'PS' => 'Middle East', 'BH' => 'Middle East', 'IQ' => 'Middle East', 'IR' => 'Middle East',
            'IL' => 'Middle East', 'JO' => 'Middle East', 'KW' => 'Middle East', 'LB' => 'Middle East',
            'OM' => 'Middle East', 'QA' => 'Middle East', 'SA' => 'Middle East', 'SY' => 'Middle East',
            'AE' => 'Middle East', 'YE' => 'Middle East'
        ];
    }
    
    // Get posts from specific countries
    public function getPostsFromCountries($countries, $limit = 20) {
        try {
            $placeholders = str_repeat('?,', count($countries) - 1) . '?';
            $stmt = $this->pdo->prepare("
                SELECT p.*, u.username, u.profile_pic_url, u.country as author_country
                FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE u.country IN ($placeholders)
                AND p.post_type = 'video'
                AND p.privacy_setting = 'public'
                ORDER BY p.created_at DESC
                LIMIT ?
            ");
            
            $params = array_merge($countries, [$limit]);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Posts from countries error: " . $e->getMessage());
            return [];
        }
    }
}

// VIRAL AND TRENDING DETECTION SYSTEM
class ViralTrendingDetector {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Check if a post is going viral
    public function isPostViral($postId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.id,
                    COUNT(DISTINCT l.user_id) as likes,
                    COUNT(DISTINCT c.id) as comments,
                    COUNT(DISTINCT s.id) as shares,
                    COUNT(DISTINCT v.user_id) as views,
                    AVG(v.completion_rate) as avg_completion,
                    COUNT(DISTINCT CASE WHEN v.watch_time >= 10 THEN v.user_id END) as quality_views,
                    EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 as hours_old,
                    COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '1 hour' THEN v.user_id END) as recent_views,
                    COUNT(DISTINCT CASE WHEN l.created_at > NOW() - INTERVAL '1 hour' THEN l.user_id END) as recent_likes,
                    COUNT(DISTINCT CASE WHEN s.created_at > NOW() - INTERVAL '1 hour' THEN s.id END) as recent_shares
                FROM posts p
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN comments c ON p.id = c.post_id
                LEFT JOIN shares s ON p.id = s.post_id
                LEFT JOIN video_views v ON p.id = v.post_id
                WHERE p.id = ?
                GROUP BY p.id, p.created_at
            ");
            $stmt->execute([$postId]);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$metrics) return false;
            
            // VIRAL CRITERIA (similar to TikTok)
            $completionRate = $metrics['avg_completion'] ?? 0;
            $engagementRate = $metrics['views'] > 0 ? 
                ($metrics['likes'] + $metrics['comments'] * 1.5 + $metrics['shares'] * 2) / $metrics['views'] : 0;
            $shareRate = $metrics['views'] > 0 ? $metrics['shares'] / $metrics['views'] : 0;
            $velocity = $metrics['recent_views'] + $metrics['recent_likes'] * 2 + $metrics['recent_shares'] * 3;
            
            // Viral conditions
            $isViral = (
                $completionRate > 0.75 &&        // High completion rate
                $engagementRate > 0.20 &&        // Strong engagement
                $shareRate > 0.08 &&            // High share rate
                $velocity > 50 &&               // High recent velocity
                $metrics['hours_old'] < 48      // Recent content
            );
            
            return $isViral;
        } catch (Exception $e) {
            error_log("Viral detection error: " . $e->getMessage());
            return false;
        }
    }
    
    // Check if a post is trending
    public function isPostTrending($postId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.id,
                    COUNT(DISTINCT l.user_id) as total_likes,
                    COUNT(DISTINCT c.id) as total_comments,
                    COUNT(DISTINCT s.id) as total_shares,
                    COUNT(DISTINCT v.user_id) as total_views,
                    COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '2 hours' THEN v.user_id END) as views_2h,
                    COUNT(DISTINCT CASE WHEN l.created_at > NOW() - INTERVAL '2 hours' THEN l.user_id END) as likes_2h,
                    COUNT(DISTINCT CASE WHEN s.created_at > NOW() - INTERVAL '2 hours' THEN s.id END) as shares_2h,
                    EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 as hours_old
                FROM posts p
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN comments c ON p.id = c.post_id
                LEFT JOIN shares s ON p.id = s.post_id
                LEFT JOIN video_views v ON p.id = v.post_id
                WHERE p.id = ?
                GROUP BY p.id, p.created_at
            ");
            $stmt->execute([$postId]);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$metrics) return false;
            
            // Calculate growth velocity
            $recentEngagement = $metrics['views_2h'] + $metrics['likes_2h'] * 2 + $metrics['shares_2h'] * 3;
            $totalEngagement = $metrics['total_views'] + $metrics['total_likes'] * 2 + $metrics['total_shares'] * 3;
            
            $velocity = $totalEngagement > 0 ? $recentEngagement / $totalEngagement : 0;
            
            // Trending conditions
            $isTrending = (
                $velocity > 0.4 &&              // High growth velocity
                $recentEngagement > 30 &&       // Significant recent engagement
                $metrics['hours_old'] < 72      // Not too old
            );
            
            return $isTrending;
        } catch (Exception $e) {
            error_log("Trending detection error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get viral boost score
    public function getViralBoostScore($postId) {
        $isViral = $this->isPostViral($postId);
        $isTrending = $this->isPostTrending($postId);
        
        if ($isViral) return 3.0;      // Maximum boost for viral content
        if ($isTrending) return 2.0;   // Strong boost for trending content
        
        return 1.0; // No boost for regular content
    }
    
    // Get trending posts by country
    public function getTrendingPostsByCountry($country, $limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.*,
                    u.username,
                    u.profile_pic_url,
                    u.country as author_country,
                    COUNT(DISTINCT l.user_id) as likes_count,
                    COUNT(DISTINCT c.id) as comments_count,
                    COUNT(DISTINCT s.id) as shares_count,
                    COUNT(DISTINCT v.user_id) as views_count,
                    COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '4 hours' THEN v.user_id END) as recent_views,
                    COUNT(DISTINCT CASE WHEN l.created_at > NOW() - INTERVAL '4 hours' THEN l.user_id END) as recent_likes,
                    (COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '4 hours' THEN v.user_id END) * 1.0 / 
                     GREATEST(COUNT(DISTINCT v.user_id), 1)) as growth_velocity
                FROM posts p
                JOIN users u ON p.user_id = u.id
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN comments c ON p.id = c.post_id
                LEFT JOIN shares s ON p.id = s.post_id
                LEFT JOIN video_views v ON p.id = v.post_id
                WHERE u.country = ?
                  AND p.created_at > NOW() - INTERVAL '7 days'
                  AND p.post_type = 'video'
                GROUP BY p.id, u.id, u.username, u.profile_pic_url, u.country
                HAVING 
                    COUNT(DISTINCT v.user_id) >= 10 AND
                    growth_velocity > 0.3 AND
                    COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '4 hours' THEN v.user_id END) >= 5
                ORDER BY growth_velocity DESC, recent_likes DESC
                LIMIT ?
            ");
            $stmt->execute([$country, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Trending posts by country error: " . $e->getMessage());
            return [];
        }
    }
    
    // Get globally viral posts (across all regions)
    public function getGlobalViralPosts($limit = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.*,
                    u.username,
                    u.profile_pic_url,
                    u.country as author_country,
                    COUNT(DISTINCT l.user_id) as likes_count,
                    COUNT(DISTINCT c.id) as comments_count,
                    COUNT(DISTINCT s.id) as shares_count,
                    COUNT(DISTINCT v.user_id) as views_count,
                    COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '2 hours' THEN v.user_id END) as recent_views,
                    COUNT(DISTINCT CASE WHEN l.created_at > NOW() - INTERVAL '2 hours' THEN l.user_id END) as recent_likes,
                    COUNT(DISTINCT CASE WHEN s.created_at > NOW() - INTERVAL '2 hours' THEN s.id END) as recent_shares,
                    AVG(v.completion_rate) as avg_completion_rate,
                    (COUNT(DISTINCT CASE WHEN v.created_at > NOW() - INTERVAL '2 hours' THEN v.user_id END) * 1.0 / 
                     GREATEST(COUNT(DISTINCT v.user_id), 1)) as growth_velocity
                FROM posts p
                JOIN users u ON p.user_id = u.id
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN comments c ON p.id = c.post_id
                LEFT JOIN shares s ON p.id = s.post_id
                LEFT JOIN video_views v ON p.id = v.post_id
                WHERE p.created_at > NOW() - INTERVAL '3 days'
                  AND p.post_type = 'video'
                  AND p.privacy_setting = 'public'
                GROUP BY p.id, u.id, u.username, u.profile_pic_url, u.country
                HAVING 
                    COUNT(DISTINCT v.user_id) >= 100 AND
                    growth_velocity > 0.5 AND
                    avg_completion_rate > 0.7 AND
                    recent_likes >= 20
                ORDER BY growth_velocity DESC, recent_shares DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Global viral posts error: " . $e->getMessage());
            return [];
        }
    }
}

// TIKTOK-STAGE DISTRIBUTION SYSTEM
class TikTokStageDistribution {
    private $pdo;
    private $viralDetector;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->viralDetector = new ViralTrendingDetector($pdo);
    }
    
    // Simulate TikTok's multi-stage distribution
    public function getDistributionStage($postId, $viewerId) {
        try {
            // Get post performance metrics
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.user_id as author_id,
                    COALESCE(COUNT(DISTINCT l.user_id), 0) as likes_count,
                    COALESCE(COUNT(DISTINCT c.id), 0) as comments_count,
                    COALESCE(COUNT(DISTINCT s.id), 0) as shares_count,
                    COALESCE(COUNT(DISTINCT v.user_id), 0) as views_count,
                    COALESCE(AVG(v.completion_rate), 0) as avg_completion_rate,
                    COALESCE(COUNT(DISTINCT CASE WHEN v.watch_time >= 5 THEN v.user_id END), 0) as quality_views,
                    EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600 as hours_since_post
                FROM posts p
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN comments c ON p.id = c.post_id
                LEFT JOIN shares s ON p.id = s.post_id
                LEFT JOIN video_views v ON p.id = v.post_id
                WHERE p.id = ?
                GROUP BY p.id, p.user_id, p.created_at
            ");
            $stmt->execute([$postId]);
            $performance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$performance) return 'initial';
            
            $completionRate = $performance['avg_completion_rate'];
            $engagementRate = $performance['views_count'] > 0 ? 
                ($performance['likes_count'] + $performance['comments_count'] + $performance['shares_count']) / $performance['views_count'] : 0;
            $qualityViewRate = $performance['views_count'] > 0 ? 
                $performance['quality_views'] / $performance['views_count'] : 0;
            
            // Check viral status first
            if ($this->viralDetector->isPostViral($postId)) {
                return 'viral';
            }
            
            // Check trending status
            if ($this->viralDetector->isPostTrending($postId)) {
                return 'trending';
            }
            
            // Determine distribution stage based on performance
            if ($performance['hours_since_post'] < 1) {
                return 'initial'; // First hour - initial testing
            } elseif ($completionRate > 0.7 && $engagementRate > 0.15 && $qualityViewRate > 0.8) {
                return 'broad'; // Excellent performance - broader distribution
            } elseif ($completionRate > 0.5 && $engagementRate > 0.08) {
                return 'niche'; // Good performance - niche targeting
            } else {
                return 'limited'; // Poor performance - limited distribution
            }
        } catch (Exception $e) {
            error_log("Distribution stage error: " . $e->getMessage());
            return 'initial';
        }
    }
    
    // Get audience for distribution stage
    public function getTargetAudience($postId, $stage, $currentUserId) {
        // This would be more complex in production, but here's a simplified version
        switch ($stage) {
            case 'viral':
                return 'all_users'; // Show to everyone
            case 'trending':
                return 'regional_trending'; // Show to regional users first
            case 'broad':
                return 'similar_interests'; // Broader but still targeted
            case 'niche':
                return 'very_specific'; // Very targeted audience
            case 'limited':
                return 'minimal'; // Only show to most likely engagers
            default: // initial
                return 'initial_test'; // Small test audience
        }
    }
}

// ENHANCED BEHAVIORAL TRACKING
class EnhancedBehaviorTracker {
    private $userId;
    private $scrollData = [];
    private $sessionData = [];
    
    public function __construct($userId) {
        $this->userId = $userId;
        $this->sessionData = [
            'session_start' => time(),
            'watch_patterns' => [],
            'engagement_intensity' => [],
            'scroll_velocity' => []
        ];
    }
    
    // Track scroll velocity
    public function trackScrollVelocity() {
        $lastScrollTime = 0;
        $lastScrollTop = 0;
        
        return "
            <script>
            let lastScrollTime = Date.now();
            let lastScrollTop = 0;
            
            window.addEventListener('scroll', () => {
                const currentTime = Date.now();
                const currentScrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const timeDiff = currentTime - lastScrollTime;
                const scrollDiff = Math.abs(currentScrollTop - lastScrollTop);
                
                if (timeDiff > 0) {
                    const velocity = scrollDiff / timeDiff;
                    
                    // Send to server
                    fetch('feed.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=track_behavior&behavior_type=scroll_velocity&velocity=' + velocity
                    });
                }
                
                lastScrollTime = currentTime;
                lastScrollTop = currentScrollTop;
            });
            </script>
        ";
    }
    
    // Track viewport attention
    public function trackViewportAttention() {
        return "
            <script>
            document.addEventListener('visibilitychange', () => {
                const isVisible = !document.hidden;
                
                fetch('feed.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=track_behavior&behavior_type=tab_switch&is_visible=' + (isVisible ? 1 : 0)
                });
            });
            </script>
        ";
    }
    
    // Track engagement intensity
    public function trackEngagementIntensity($postId) {
        return "
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const likeButtons = document.querySelectorAll('[data-post-id=\"$postId\"] .like-btn');
                
                likeButtons.forEach(btn => {
                    let pressStartTime = 0;
                    
                    btn.addEventListener('mousedown', () => {
                        pressStartTime = Date.now();
                    });
                    
                    btn.addEventListener('mouseup', () => {
                        const pressDuration = Date.now() - pressStartTime;
                        
                        if (pressDuration > 500) { // Long press
                            fetch('feed.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'action=track_behavior&behavior_type=long_press&post_id=$postId&duration=' + pressDuration
                            });
                        }
                    });
                    
                    // Double tap detection
                    let lastTap = 0;
                    btn.addEventListener('click', (e) => {
                        const currentTime = Date.now();
                        const tapLength = currentTime - lastTap;
                        
                        if (tapLength < 300 && tapLength > 0) {
                            // Double tap detected
                            fetch('feed.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'action=track_behavior&behavior_type=double_tap&post_id=$postId'
                            });
                        }
                        lastTap = currentTime;
                    });
                });
            });
            </script>
        ";
    }
}

// SESSION-BASED ADAPTATION
class SessionAdaptation {
    private $pdo;
    private $userId;
    private $sessionWatchPatterns = [];
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->loadSessionPatterns();
    }
    
    public function trackSessionBehavior($interaction) {
        // Track watch patterns in current session
        $this->sessionWatchPatterns[] = [
            'timestamp' => time(),
            'interaction' => $interaction,
            'watch_time' => $interaction['watch_time'],
            'completion_rate' => $interaction['completion_rate']
        ];
        
        // Save to database for persistence
        $this->saveSessionPatterns();
        
        // Detect session patterns (binge watching, skipping, etc.)
        $sessionPattern = $this->analyzeSessionPattern();
        
        // Adjust recommendations based on current session mood
        return $this->adjustForSessionContext($sessionPattern);
    }
    
    private function analyzeSessionPattern() {
        if (count($this->sessionWatchPatterns) < 3) {
            return 'normal';
        }
        
        $recentInteractions = array_slice($this->sessionWatchPatterns, -10);
        $avgWatchTime = array_sum(array_column($recentInteractions, 'watch_time')) / count($recentInteractions);
        $skipRate = count(array_filter($recentInteractions, function($i) { 
            return $i['watch_time'] < 5; 
        })) / count($recentInteractions);
        
        if ($skipRate > 0.7) return 'exploratory';
        if ($avgWatchTime > 30) return 'engaged';
        return 'normal';
    }
    
    private function adjustForSessionContext($sessionPattern) {
        $adjustments = [];
        
        switch ($sessionPattern) {
            case 'exploratory':
                $adjustments = [
                    'diversity_boost' => 1.5,
                    'familiarity_penalty' => 0.7,
                    'content_variety' => 'high'
                ];
                break;
            case 'engaged':
                $adjustments = [
                    'similarity_boost' => 1.3,
                    'completion_weight' => 2.0,
                    'content_variety' => 'low'
                ];
                break;
            default: // normal
                $adjustments = [
                    'diversity_boost' => 1.0,
                    'similarity_boost' => 1.0,
                    'content_variety' => 'medium'
                ];
        }
        
        return $adjustments;
    }
    
    private function loadSessionPatterns() {
        // Load from database or session
        if (isset($_SESSION['session_watch_patterns'])) {
            $this->sessionWatchPatterns = $_SESSION['session_watch_patterns'];
        }
    }
    
    private function saveSessionPatterns() {
        $_SESSION['session_watch_patterns'] = $this->sessionWatchPatterns;
        
        // Also save to database for cross-session analysis
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_session_patterns 
                (user_id, pattern_data, session_start, last_updated) 
                VALUES (?, ?, NOW(), NOW())
                ON CONFLICT (user_id) 
                DO UPDATE SET 
                    pattern_data = ?,
                    last_updated = NOW()
            ");
            $patternData = json_encode($this->sessionWatchPatterns);
            $stmt->execute([$this->userId, $patternData, $patternData]);
        } catch (Exception $e) {
            error_log("Session pattern save error: " . $e->getMessage());
        }
    }
    
    public function getSessionAdjustments() {
        $sessionPattern = $this->analyzeSessionPattern();
        return $this->adjustForSessionContext($sessionPattern);
    }
}

// ADVANCED SOCIAL FEATURES
class SocialAmplification {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function getFriendsOfFriendsContent($userId, $limit = 15) {
        try {
            // 2nd degree connections content
            $stmt = $this->pdo->prepare("
                SELECT p.*, u.username, 
                       COUNT(DISTINCT f2.follower_id) as mutual_followers
                FROM posts p
                JOIN users u ON p.user_id = u.id
                JOIN follows f1 ON f1.followed_id = u.id
                JOIN follows f2 ON f2.followed_id = u.id AND f2.follower_id IN (
                    SELECT followed_id FROM follows WHERE follower_id = ?
                )
                WHERE p.privacy_setting = 'public'
                AND p.post_type = 'video'
                GROUP BY p.id, u.id
                HAVING mutual_followers > 0
                ORDER BY mutual_followers DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Friends of friends content error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getSociallyAmplifiedPosts($userId, $limit = 20) {
        $fofContent = $this->getFriendsOfFriendsContent($userId, $limit);
        
        // Add social signals to posts
        foreach ($fofContent as &$post) {
            $post['social_amplification'] = [
                'mutual_followers' => $post['mutual_followers'],
                'social_relevance' => min($post['mutual_followers'] / 10, 1.0)
            ];
        }
        
        return $fofContent;
    }
}

// TIME/CONTEXT AWARENESS
class ContextAwareScoring {
    public function getTimeBasedBoost() {
        $hour = (int)date('H');
        $dayOfWeek = date('w'); // 0 (Sunday) to 6 (Saturday)
        
        // Boost entertainment content in evening, educational in morning
        if ($hour >= 18 || $hour <= 6) {
            return ['entertainment' => 1.3, 'comedy' => 1.4, 'dance' => 1.2];
        } else {
            return ['educational' => 1.3, 'tutorial' => 1.4, 'news' => 1.2];
        }
    }
    
    public function getSeasonalContent() {
        $month = (int)date('n');
        $seasonalThemes = [
            12 => ['christmas', 'holiday', 'winter'], // December
            1 => ['newyear', 'resolution', 'winter'], // January
            6 => ['summer', 'beach', 'travel'],       // June
            10 => ['halloween', 'autumn', 'spooky']   // October
        ];
        
        return $seasonalThemes[$month] ?? [];
    }
    
    public function getContextualBoost($postCategories) {
        $timeBoost = $this->getTimeBasedBoost();
        $seasonalThemes = $this->getSeasonalContent();
        
        $boost = 1.0;
        
        // Apply time-based boosts
        foreach ($postCategories as $category) {
            $category = strtolower($category);
            if (isset($timeBoost[$category])) {
                $boost *= $timeBoost[$category];
            }
        }
        
        // Apply seasonal boosts
        $hasSeasonalTheme = false;
        foreach ($postCategories as $category) {
            $category = strtolower($category);
            if (in_array($category, $seasonalThemes)) {
                $hasSeasonalTheme = true;
                break;
            }
        }
        
        if ($hasSeasonalTheme) {
            $boost *= 1.2;
        }
        
        return $boost;
    }
}

// ENHANCED UI/UX PERSONALIZATION
class UIPersonalization {
    private $userPreferences = [];
    
    public function __construct($userId) {
        $this->loadUIPreferences($userId);
    }
    
    private function loadUIPreferences($userId) {
        // Load from localStorage or database
        if (isset($_SESSION['ui_preferences'])) {
            $this->userPreferences = $_SESSION['ui_preferences'];
        } else {
            // Default preferences
            $this->userPreferences = [
                'preferred_volume' => 0.7,
                'auto_play' => true,
                'video_quality' => 'auto',
                'low_bandwidth_mode' => false
            ];
        }
    }
    
    public function adaptUI() {
        $adaptations = [];
        
        // Auto-play based on user data connection
        if ($this->isLowBandwidth()) {
            $adaptations['auto_play'] = false;
            $adaptations['click_to_play'] = true;
            $adaptations['video_quality'] = '480p';
        } else {
            $adaptations['auto_play'] = $this->userPreferences['auto_play'];
            $adaptations['click_to_play'] = false;
            $adaptations['video_quality'] = $this->userPreferences['video_quality'];
        }
        
        // Volume preferences
        $adaptations['default_volume'] = $this->userPreferences['preferred_volume'];
        
        return $adaptations;
    }
    
    private function isLowBandwidth() {
        // Simple bandwidth detection - in production, use Network Information API
        return isset($_SESSION['low_bandwidth']) && $_SESSION['low_bandwidth'];
    }
    
    public function getUIScript() {
        $uiAdaptations = $this->adaptUI();
        
        return "
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Apply UI adaptations
                const uiSettings = " . json_encode($uiAdaptations) . ";
                
                // Set default volume
                if (uiSettings.default_volume) {
                    document.querySelectorAll('video').forEach(video => {
                        video.volume = uiSettings.default_volume;
                    });
                }
                
                // Handle auto-play settings
                if (!uiSettings.auto_play) {
                    document.querySelectorAll('video').forEach(video => {
                        video.setAttribute('data-autoplay', 'false');
                    });
                }
                
                // Bandwidth detection
                if ('connection' in navigator) {
                    const connection = navigator.connection;
                    if (connection.saveData || connection.effectiveType === 'slow-2g' || connection.effectiveType === '2g') {
                        // Apply low bandwidth optimizations
                        document.querySelectorAll('video').forEach(video => {
                            video.preload = 'metadata';
                        });
                    }
                }
            });
            </script>
        ";
    }
}

// ENHANCED TIKTOK ALGORITHM CLASS
class TikTokAdvancedAlgorithm {
    private $pdo;
    private $userId;
    private $preferenceTracker;
    private $countryPrioritizer;
    private $distributionSystem;
    private $viralDetector;
    private $userCountry;
    private $behaviorTracker;
    private $sessionAdapter;
    private $socialAmplifier;
    private $contextScorer;
    private $uiPersonalizer;
    
    public function __construct($pdo, $userId, $userCountry) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->userCountry = $userCountry;
        $this->preferenceTracker = new UserPreferenceTracker($pdo, $userId);
        $this->countryPrioritizer = new CountryContentPrioritizer($pdo, $userCountry);
        $this->distributionSystem = new TikTokStageDistribution($pdo);
        $this->viralDetector = new ViralTrendingDetector($pdo);
        $this->behaviorTracker = new EnhancedBehaviorTracker($userId);
        $this->sessionAdapter = new SessionAdaptation($pdo, $userId);
        $this->socialAmplifier = new SocialAmplification($pdo);
        $this->contextScorer = new ContextAwareScoring();
        $this->uiPersonalizer = new UIPersonalization($userId);
    }
    
    // Calculate comprehensive score for a post
    public function calculatePostScore($post, $viewerId) {
        $authorId = $post['user_id'];
        $postId = $post['id'];
        $score = 0;
        
        // 1. CHECK BLOCKS AND BASIC FILTERS
        if ($this->preferenceTracker->isAuthorBlocked($authorId)) {
            return -1000; // Strong penalty for blocked authors
        }
        
        // 2. VIRAL/TRENDING BOOST (25% weight) - PRIORITIZE THESE
        $viralBoost = $this->viralDetector->getViralBoostScore($postId);
        $score += $viralBoost * 2.5;
        
        // 3. COUNTRY PRIORITIZATION (20% weight) - PRIORITIZE USER'S COUNTRY
        $countryRelevance = $this->countryPrioritizer->calculateCountryRelevanceScore($authorId);
        $countryScore = $countryRelevance * 2.0;
        
        // Extra boost for viral/trending content from user's country
        if ($viralBoost > 1.0 && $countryRelevance > 0.8) {
            $countryScore *= 1.5;
        }
        $score += $countryScore;
        
        // 4. USER PREFERENCE SCORING (20% weight)
        $userPreferenceScore = $this->calculateUserPreferenceScore($post);
        $score += $userPreferenceScore * 2.0;
        
        // 5. CONTENT MATCHING (15% weight) - PRIORITIZE MATCHING TAGS/HEADERS/CONTENT
        $contentMatchScore = $this->calculateContentMatchScore($post);
        $score += $contentMatchScore * 1.5;
        
        // 6. DISTRIBUTION STAGE SCORING (10% weight)
        $distributionStage = $this->distributionSystem->getDistributionStage($postId, $viewerId);
        $distributionScore = $this->getDistributionStageScore($distributionStage);
        $score += $distributionScore * 1.0;
        
        // 7. ENGAGEMENT VELOCITY (5% weight)
        $engagementVelocity = $this->calculateEngagementVelocity($postId);
        $score += $engagementVelocity * 0.5;
        
        // 8. CREATOR INFLUENCE (3% weight)
        $creatorInfluence = $this->calculateCreatorInfluence($authorId);
        $score += $creatorInfluence * 0.3;
        
        // 9. TIME DECAY (2% weight)
        $timeDecay = $this->calculateTimeDecay($post['created_at']);
        $score += $timeDecay * 0.2;
        
        // 10. SESSION-BASED ADJUSTMENTS
        $sessionAdjustments = $this->sessionAdapter->getSessionAdjustments();
        $score *= $sessionAdjustments['diversity_boost'] ?? 1.0;
        
        // 11. CONTEXT-AWARE BOOSTING
        $postCategories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
        $contextBoost = $this->contextScorer->getContextualBoost($postCategories);
        $score *= $contextBoost;
        
        return max(0, $score);
    }
    
    private function calculateUserPreferenceScore($post) {
        $authorId = $post['user_id'];
        $score = 0;
        
        // Author preference (following and interaction history)
        $authorPreference = $this->preferenceTracker->getAuthorPreferenceScore($authorId);
        $score += $authorPreference * 2.0;
        
        // Following status (strong signal but not prioritized over content)
        $isFollowing = $this->isUserFollowing($this->userId, $authorId);
        if ($isFollowing) {
            $score += 1.5;
        }
        
        return $score;
    }
    
    private function calculateContentMatchScore($post) {
        $userPreferences = $this->preferenceTracker->predictUserPreferences();
        $userInteractedContent = $this->preferenceTracker->getUserInteractedContent();
        $score = 0;
        
        // Check post header
        if (!empty($post['post_header'])) {
            $headerKeywords = explode(' ', strtolower($post['post_header']));
            foreach ($headerKeywords as $keyword) {
                if (isset($userPreferences[$keyword])) {
                    $score += $userPreferences[$keyword];
                }
                // Extra boost for exact matches with user's interacted content
                if (in_array($keyword, $userInteractedContent['keywords'])) {
                    $score += 2.0;
                }
            }
        }
        
        // Check content for hashtags and keywords
        if (!empty($post['content'])) {
            // Extract hashtags
            preg_match_all('/#(\w+)/', strtolower($post['content']), $hashtags);
            foreach ($hashtags[0] as $hashtag) {
                $cleanHashtag = strtolower($hashtag);
                if (isset($userPreferences[$cleanHashtag])) {
                    $score += $userPreferences[$cleanHashtag] * 1.2; // Hashtags are stronger signals
                }
                // Extra boost for exact hashtag matches with user's interacted content
                if (in_array($cleanHashtag, $userInteractedContent['hashtags'])) {
                    $score += 2.5;
                }
            }
            
            // Check other keywords
            $contentWords = str_word_count(strtolower($post['content']), 1);
            foreach ($contentWords as $word) {
                if (strlen($word) > 3 && isset($userPreferences[$word])) {
                    $score += $userPreferences[$word] * 0.3;
                }
            }
        }
        
        // Check categories
        $categories = array_filter([$post['category1'], $post['category2'], $post['category3']]);
        foreach ($categories as $category) {
            if (isset($userPreferences[$category])) {
                $score += $userPreferences[$category] * 1.5; // Categories are strong signals
            }
        }
        
        return $score;
    }
    
    private function getDistributionStageScore($stage) {
        $scores = [
            'viral' => 10,
            'trending' => 8,
            'broad' => 6,
            'niche' => 4,
            'initial' => 3,
            'limited' => 1
        ];
        
        return $scores[$stage] ?? 3;
    }
    
    private function calculateEngagementVelocity($postId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as engagements,
                    EXTRACT(EPOCH FROM (NOW() - MIN(created_at))) as time_span
                FROM (
                    SELECT created_at FROM likes WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                    UNION ALL
                    SELECT created_at FROM comments WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                    UNION ALL
                    SELECT created_at FROM shares WHERE post_id = ? AND created_at > NOW() - INTERVAL '1 hour'
                ) AS recent_engagements
            ");
            $stmt->execute([$postId, $postId, $postId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data['time_span'] > 0) {
                $velocity = $data['engagements'] / ($data['time_span'] / 3600); // engagements per hour
                return min($velocity / 5, 1.0); // Normalize to 0-1
            }
            return 0;
        } catch (Exception $e) {
            error_log("Engagement velocity error: " . $e->getMessage());
            return 0;
        }
    }
    
    private function calculateCreatorInfluence($creatorId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT follower_id) as follower_count,
                    COALESCE(AVG(p.avg_engagement), 0) as avg_engagement
                FROM users u
                LEFT JOIN follows f ON u.id = f.followed_id
                LEFT JOIN (
                    SELECT 
                        user_id,
                        AVG(likes_count + comments_count * 1.5 + shares_count * 2) as avg_engagement
                    FROM posts 
                    WHERE created_at > NOW() - INTERVAL '7 days'
                    GROUP BY user_id
                ) p ON u.id = p.user_id
                WHERE u.id = ?
                GROUP BY u.id
            ");
            $stmt->execute([$creatorId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $followerScore = min($result['follower_count'] / 1000, 1.0);
            $engagementScore = min($result['avg_engagement'] / 50, 1.0);
            
            return ($followerScore * 0.4 + $engagementScore * 0.6);
        } catch (Exception $e) {
            error_log("Creator influence error: " . $e->getMessage());
            return 0;
        }
    }
    
    private function calculateTimeDecay($postCreatedAt) {
        $hoursAgo = (time() - strtotime($postCreatedAt)) / 3600;
        
        // TikTok-style time decay: newer content gets boost, but very new content gets extra boost
        if ($hoursAgo < 1) return 1.0; // First hour - maximum boost
        if ($hoursAgo < 6) return 0.8; // First 6 hours - high boost
        if ($hoursAgo < 24) return 0.6; // First day - medium boost
        if ($hoursAgo < 72) return 0.3; // First 3 days - low boost
        
        return 0.1; // Older content - minimal boost
    }
    
    private function isUserFollowing($followerId, $followedId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM follows 
                WHERE follower_id = ? AND followed_id = ?
            ");
            $stmt->execute([$followerId, $followedId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Follow check error: " . $e->getMessage());
            return false;
        }
    }
    
    // Apply author display frequency rule (3 post difference)
    public function applyAuthorFrequencyRule($posts) {
        $authorLastSeen = [];
        $filteredPosts = [];
        
        foreach ($posts as $post) {
            $authorId = $post['user_id'];
            
            if (!isset($authorLastSeen[$authorId]) || 
                (count($filteredPosts) - $authorLastSeen[$authorId]) >= 3) {
                
                $filteredPosts[] = $post;
                $authorLastSeen[$authorId] = count($filteredPosts) - 1;
            }
        }
        
        return $filteredPosts;
    }
    
    // Get viral and trending posts from user's country
    public function getLocalViralTrendingPosts($limit = 10) {
        $viralDetector = new ViralTrendingDetector($this->pdo);
        return $viralDetector->getTrendingPostsByCountry($this->userCountry, $limit);
    }
    
    // Get posts from international countries with matching content
    public function getInternationalMatchingPosts($limit = 15) {
        try {
            $userInteractedContent = $this->preferenceTracker->getUserInteractedContent();
            $targetCountries = ['US', 'GB', 'CN', 'KR']; // USA, UK, China, Korea
            
            if (empty($userInteractedContent['hashtags']) && empty($userInteractedContent['keywords'])) {
                return [];
            }
            
            $conditions = [];
            $params = [];
            
            // Add hashtag conditions
            if (!empty($userInteractedContent['hashtags'])) {
                $hashtagPlaceholders = [];
                foreach ($userInteractedContent['hashtags'] as $hashtag) {
                    $hashtagPlaceholders[] = '?';
                    $params[] = '%#' . $hashtag . '%';
                }
                $conditions[] = "(p.content ILIKE ANY(ARRAY[" . implode(',', $hashtagPlaceholders) . "]))";
            }
            
            // Add keyword conditions
            if (!empty($userInteractedContent['keywords'])) {
                $keywordPlaceholders = [];
                foreach ($userInteractedContent['keywords'] as $keyword) {
                    $keywordPlaceholders[] = '?';
                    $params[] = '%' . $keyword . '%';
                }
                $conditions[] = "(p.post_header ILIKE ANY(ARRAY[" . implode(',', $keywordPlaceholders) . "]) OR p.content ILIKE ANY(ARRAY[" . implode(',', $keywordPlaceholders) . "]))";
            }
            
            $countryPlaceholders = str_repeat('?,', count($targetCountries) - 1) . '?';
            $params = array_merge($params, $targetCountries, [$limit]);
            
            $sql = "
                SELECT DISTINCT p.*, u.username, u.profile_pic_url, u.country as author_country
                FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE u.country IN ($countryPlaceholders)
                AND p.post_type = 'video'
                AND p.privacy_setting = 'public'
                AND (" . implode(' OR ', $conditions) . ")
                ORDER BY RANDOM()
                LIMIT ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("International matching posts error: " . $e->getMessage());
            return [];
        }
    }
    
    // Get random international posts for cultural diversity
    public function getRandomInternationalPosts($limit = 10) {
        $targetCountries = ['US', 'GB', 'CN', 'KR'];
        $countryPrioritizer = new CountryContentPrioritizer($this->pdo, $this->userCountry);
        return $countryPrioritizer->getPostsFromCountries($targetCountries, $limit);
    }
    
    // Get socially amplified posts (friends of friends)
    public function getSociallyAmplifiedPosts($limit = 15) {
        return $this->socialAmplifier->getSociallyAmplifiedPosts($this->userId, $limit);
    }
    
    // Get behavioral tracking scripts
    public function getBehaviorTrackingScripts() {
        return $this->behaviorTracker->trackScrollVelocity() . 
               $this->behaviorTracker->trackViewportAttention();
    }
    
    // Get UI personalization scripts
    public function getUIPersonalizationScripts() {
        return $this->uiPersonalizer->getUIScript();
    }
    
    // Track session behavior
    public function trackSessionBehavior($interaction) {
        return $this->sessionAdapter->trackSessionBehavior($interaction);
    }
}

// AJAX HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_SESSION['user_id'];
    $preferenceTracker = new UserPreferenceTracker($pdo, $userId);
    $algorithm = new TikTokAdvancedAlgorithm($pdo, $userId, $userCountry);
    
    // TRACK USER BEHAVIOR
    if (isset($_POST['action']) && $_POST['action'] === 'track_behavior' && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        $watchTime = (float)($_POST['watch_time'] ?? 0);
        $interactionType = $_POST['interaction_type'] ?? 'view';
        
        try {
            // Get author ID
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            
            // Track based on interaction type
            switch ($interactionType) {
                case 'completion':
                    $preferenceTracker->trackCompletion($postId, $authorId);
                    break;
                case 'rewatch':
                    $preferenceTracker->trackRewatch($postId, $authorId);
                    break;
                case 'video_skip':
                    if ($watchTime < 5) {
                        $preferenceTracker->trackVideoSkip($postId, $authorId, $watchTime);
                    }
                    break;
            }
            
            // Track session behavior
            $algorithm->trackSessionBehavior([
                'post_id' => $postId,
                'watch_time' => $watchTime,
                'completion_rate' => $interactionType === 'completion' ? 1.0 : ($watchTime / 30), // Assuming 30s videos
                'interaction_type' => $interactionType
            ]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Behavior tracking error: " . $e->getMessage());
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    // TRACK BEHAVIORAL DATA (scroll velocity, tab switches, etc.)
    if (isset($_POST['action']) && $_POST['action'] === 'track_behavior' && isset($_POST['behavior_type'])) {
        $behaviorType = $_POST['behavior_type'];
        
        try {
            // Store behavioral data
            $stmt = $pdo->prepare("
                INSERT INTO user_behavior_data 
                (user_id, behavior_type, behavior_value, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            
            $value = $_POST['velocity'] ?? $_POST['is_visible'] ?? $_POST['duration'] ?? 0;
            $stmt->execute([$userId, $behaviorType, $value]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Behavioral tracking error: " . $e->getMessage());
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    // TRACK ENGAGEMENT (LIKE, COMMENT, SHARE, DOWNLOAD)
    if (isset($_POST['action']) && in_array($_POST['action'], ['like', 'comment', 'share', 'download']) && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        $engagementType = $_POST['action'];
        
        try {
            // Get author ID
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            
            $preferenceTracker->trackEngagement($postId, $authorId, $engagementType);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Engagement tracking error: " . $e->getMessage());
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    // TRACK INTEREST (INTERESTED/NOT_INTERESTED)
    if (isset($_POST['action']) && in_array($_POST['action'], ['interested', 'not_interested']) && isset($_POST['post_id'])) {
        $postId = (int)$_POST['post_id'];
        $interestType = $_POST['action'];
        
        try {
            // Get author ID
            $authorStmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $authorStmt->execute([$postId]);
            $authorId = $authorStmt->fetchColumn();
            
            $preferenceTracker->trackInterest($postId, $authorId, $interestType);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Interest tracking error: " . $e->getMessage());
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

// INITIALIZE ALGORITHM
$algorithm = new TikTokAdvancedAlgorithm($pdo, $userId, $userCountry);
$preferenceTracker = new UserPreferenceTracker($pdo, $userId);
$viralDetector = new ViralTrendingDetector($pdo);

// Get user's followed accounts
$followStmt = $pdo->prepare("SELECT followed_id FROM follows WHERE follower_id = ?");
$followStmt->execute([$userId]);
$followedUserIds = $followStmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Build base query for posts - FIXED PARAMETER COUNT
$params = [$userId, $userId, $userId, $userId, $userId, $userId, $userId];
$categoryCondition = "";

if ($selectedCategory !== 'All') {
    $categoryCondition = "AND (p.category1 = ? OR p.category2 = ? OR p.category3 = ?)";
    array_push($params, $selectedCategory, $selectedCategory, $selectedCategory);
}

// Get different types of posts for diverse feed
$localViralTrendingPosts = $algorithm->getLocalViralTrendingPosts(10);
$internationalMatchingPosts = $algorithm->getInternationalMatchingPosts(10);
$randomInternationalPosts = $algorithm->getRandomInternationalPosts(8);
$globalViralPosts = $viralDetector->getGlobalViralPosts(15);
$sociallyAmplifiedPosts = $algorithm->getSociallyAmplifiedPosts(12);

// Build SQL query for regular posts - FIXED: Added missing user_id parameter
$sql = "
    SELECT DISTINCT
        p.*, 
        u.username, 
        u.profile_pic_url, 
        u.id AS author_id,
        u.country as author_country,
        -- Engagement metrics
        COALESCE(l.likes_count, 0) as likes_count,
        COALESCE(c.comments_count, 0) as comments_count,
        COALESCE(s.shares_count, 0) as shares_count,
        COALESCE(v.views_count, 0) as views_count,
        COALESCE(vw.avg_completion_rate, 0.5) as avg_completion_rate,
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
            AVG(completion_rate) as avg_completion_rate
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
    ORDER BY p.created_at DESC
    LIMIT 200
";

$basePostStmt = $pdo->prepare($sql);
$basePostStmt->execute($params);
$allPosts = $basePostStmt->fetchAll(PDO::FETCH_ASSOC);

// COMBINE ALL POST TYPES WITH PRIORITIZATION
$scoredPosts = [];

// 1. Global viral posts get highest priority (show in all regions)
foreach ($globalViralPosts as $post) {
    $baseScore = $algorithm->calculatePostScore($post, $userId);
    $finalScore = $baseScore * 2.0; // Extra high boost for global viral
    $post['tiktok_score'] = round($finalScore, 2);
    $post['is_viral_trending'] = true;
    $post['distribution_stage'] = 'viral';
    $post['post_source'] = 'global_viral';
    $scoredPosts[] = $post;
}

// 2. Local viral/trending posts
foreach ($localViralTrendingPosts as $post) {
    // Skip if already in global viral list
    $isAlreadyInList = false;
    foreach ($scoredPosts as $scoredPost) {
        if ($scoredPost['id'] == $post['id']) {
            $isAlreadyInList = true;
            break;
        }
    }
    
    if (!$isAlreadyInList) {
        $baseScore = $algorithm->calculatePostScore($post, $userId);
        $finalScore = $baseScore * 1.5; // High boost for local viral
        $post['tiktok_score'] = round($finalScore, 2);
        $post['is_viral_trending'] = true;
        $post['distribution_stage'] = $viralDetector->isPostViral($post['id']) ? 'viral' : 'trending';
        $post['post_source'] = 'local_trending';
        $scoredPosts[] = $post;
    }
}

// 3. Socially amplified posts (friends of friends)
foreach ($sociallyAmplifiedPosts as $post) {
    $isAlreadyInList = false;
    foreach ($scoredPosts as $scoredPost) {
        if ($scoredPost['id'] == $post['id']) {
            $isAlreadyInList = true;
            break;
        }
    }
    
    if (!$isAlreadyInList) {
        $baseScore = $algorithm->calculatePostScore($post, $userId);
        $finalScore = $baseScore * 1.4; // Boost for social connections
        $post['tiktok_score'] = round($finalScore, 2);
        $post['is_viral_trending'] = false;
        $post['distribution_stage'] = 'social_amplified';
        $post['post_source'] = 'social_amplified';
        $post['mutual_followers'] = $post['mutual_followers'];
        $scoredPosts[] = $post;
    }
}

// 4. International posts with matching tags/headers/content
foreach ($internationalMatchingPosts as $post) {
    $isAlreadyInList = false;
    foreach ($scoredPosts as $scoredPost) {
        if ($scoredPost['id'] == $post['id']) {
            $isAlreadyInList = true;
            break;
        }
    }
    
    if (!$isAlreadyInList) {
        $baseScore = $algorithm->calculatePostScore($post, $userId);
        $finalScore = $baseScore * 1.3; // Boost for international matching content
        $post['tiktok_score'] = round($finalScore, 2);
        $post['is_viral_trending'] = false;
        $post['distribution_stage'] = 'international_matching';
        $post['post_source'] = 'international_matching';
        $scoredPosts[] = $post;
    }
}

// 5. Regular posts from base query
foreach ($allPosts as $post) {
    $isAlreadyInList = false;
    foreach ($scoredPosts as $scoredPost) {
        if ($scoredPost['id'] == $post['id']) {
            $isAlreadyInList = true;
            break;
        }
    }
    
    if (!$isAlreadyInList) {
        $score = $algorithm->calculatePostScore($post, $userId);
        $post['tiktok_score'] = round($score, 2);
        $post['is_viral_trending'] = false;
        $post['distribution_stage'] = (new TikTokStageDistribution($pdo))->getDistributionStage($post['id'], $userId);
        $post['post_source'] = 'regular';
        $scoredPosts[] = $post;
    }
}

// 6. Random international posts for cultural diversity (lower priority)
foreach ($randomInternationalPosts as $post) {
    $isAlreadyInList = false;
    foreach ($scoredPosts as $scoredPost) {
        if ($scoredPost['id'] == $post['id']) {
            $isAlreadyInList = true;
            break;
        }
    }
    
    if (!$isAlreadyInList) {
        $baseScore = $algorithm->calculatePostScore($post, $userId);
        $finalScore = $baseScore * 0.8; // Slight penalty for random international
        $post['tiktok_score'] = round($finalScore, 2);
        $post['is_viral_trending'] = false;
        $post['distribution_stage'] = 'international_random';
        $post['post_source'] = 'international_random';
        $scoredPosts[] = $post;
    }
}

// Sort by TikTok score (highest first)
usort($scoredPosts, function($a, $b) {
    return $b['tiktok_score'] <=> $a['tiktok_score'];
});

// Apply author frequency rule (3 post difference)
$filteredPosts = $algorithm->applyAuthorFrequencyRule($scoredPosts);

// Take top posts for display
$posts = array_slice($filteredPosts, 0, 50);

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
        
    // Add viral/trending badge and source indicator
    if ($post['is_viral_trending']) {
        $post['badge'] = $post['distribution_stage'] === 'viral' ? '🔥 Viral' : '📈 Trending';
    } else {
        $post['badge'] = '';
    }
    
    // Add source indicator for international content
    if ($post['post_source'] === 'international_matching') {
        $post['source_badge'] = '🌍 Matching Content';
    } elseif ($post['post_source'] === 'international_random') {
        $post['source_badge'] = '🌎 Cultural Diversity';
    } elseif ($post['post_source'] === 'social_amplified') {
        $post['source_badge'] = '👥 ' . ($post['mutual_followers'] ?? 0) . ' Mutual Followers';
    } else {
        $post['source_badge'] = '';
    }
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
    /* Reset and base styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #000;
        color: #fff;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        overflow: hidden;
        height: 100vh;
        touch-action: pan-y;
    }

    /* Header Styles */
    .header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.9);
        backdrop-filter: blur(10px);
        z-index: 1000;
        padding: 10px 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .header h1 {
        font-size: 18px;
        font-weight: 700;
        color: #fff;
    }

    .profile-pic {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #7b68ee;
    }

    /* TikTok Reels Container - Vertical Scroll */
    .reels-scroll-container {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        overflow-y: scroll;
        overflow-x: hidden;
        scroll-snap-type: y mandatory;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
    }

    /* Hide scrollbar but keep functionality */
    .reels-scroll-container::-webkit-scrollbar {
        display: none;
    }

    .reels-scroll-container {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    /* Individual Reel - Full Screen with Snap */
    .reel {
        width: 100%;
        height: 100vh;
        scroll-snap-align: start;
        position: relative;
        background: #000;
    }

    /* Video Container - Full Screen */
    .video-container {
        position: relative;
        width: 100%;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #000;
    }

    .video-container video {
        width: 100%;
        height: 100vh;
        object-fit: contain;
        background: #000;
    }

    /* Video Controls Overlay */
    .video-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 10;
        pointer-events: none;
    }

    /* Top Bar */
    .top-bar {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        padding: 60px 15px 15px;
        background: linear-gradient(to bottom, rgba(0,0,0,0.6) 0%, transparent 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #fff;
    }

    .username {
        font-weight: 600;
        font-size: 16px;
        color: #fff;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    }

    .follow-btn {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: #fff;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        backdrop-filter: blur(10px);
        pointer-events: auto;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .follow-btn:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    .follow-btn.following {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.7);
    }

    /* Right Action Buttons */
    .action-buttons {
        position: absolute;
        right: 15px;
        bottom: 120px;
        display: flex;
        flex-direction: column;
        gap: 20px;
        align-items: center;
    }

    .action-button {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        color: #fff;
        pointer-events: auto;
        cursor: pointer;
    }

    .action-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-size: 20px;
        transition: all 0.3s ease;
    }

    .action-count {
        font-size: 12px;
        font-weight: 600;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    }

    .action-button:hover .action-icon {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.1);
    }

    .like-btn.liked .action-icon {
        background: rgba(255, 59, 92, 0.3);
        border-color: rgba(255, 59, 92, 0.5);
    }

    /* Bottom Content */
    .bottom-content {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 20px 15px 80px;
        background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, transparent 100%);
    }

    .post-header {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 10px;
        line-height: 1.4;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    }

    .post-content {
        font-size: 14px;
        line-height: 1.4;
        margin-bottom: 10px;
        opacity: 0.9;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    }

    .hashtags {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }

    .hashtag {
        color: #7b68ee;
        font-weight: 600;
        font-size: 14px;
    }

    .audio-info {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        opacity: 0.8;
    }

    .audio-icon {
        font-size: 12px;
    }

    /* Progress Bar */
    .reel-progress {
        position: absolute;
        top: 50px;
        left: 0;
        right: 0;
        height: 2px;
        background: rgba(255, 255, 255, 0.3);
        z-index: 1001;
    }

    .reel-progress-bar {
        height: 100%;
        background: #fff;
        width: 0%;
        transition: width 0.1s linear;
    }

    /* Video Controls */
    .video-controls {
        position: absolute;
        bottom: 20px;
        left: 0;
        right: 0;
        display: flex;
        justify-content: center;
        gap: 30px;
        padding: 0 20px;
    }

    .control-btn {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(10px);
        pointer-events: auto;
        cursor: pointer;
        font-size: 18px;
        transition: all 0.3s ease;
    }

    .control-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.1);
    }

    /* Loading Spinner */
    .loading-spinner {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 50px;
        height: 50px;
        border: 3px solid rgba(255, 255, 255, 0.3);
        border-top: 3px solid #fff;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        z-index: 5;
    }

    @keyframes spin {
        0% { transform: translate(-50%, -50%) rotate(0deg); }
        100% { transform: translate(-50%, -50%) rotate(360deg); }
    }

    /* Algorithm Score */
    .algorithm-score {
        position: absolute;
        top: 10px;
        left: 10px;
        background: rgba(0, 0, 0, 0.7);
        color: #fff;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        z-index: 100;
        pointer-events: none;
    }

    /* Viral/Trending Badge */
    .viral-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: linear-gradient(45deg, #ff6b6b, #ffa726);
        color: #fff;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: bold;
        z-index: 100;
        pointer-events: none;
        animation: pulse 2s infinite;
    }

    .trending-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: linear-gradient(45deg, #4ecdc4, #44a08d);
        color: #fff;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: bold;
        z-index: 100;
        pointer-events: none;
    }

    /* Source Badge */
    .source-badge {
        position: absolute;
        top: 35px;
        right: 10px;
        background: rgba(123, 104, 238, 0.8);
        color: #fff;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: bold;
        z-index: 100;
        pointer-events: none;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }

    /* No Videos State */
    .no-videos {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        color: #fff;
    }

    .no-videos h2 {
        font-size: 24px;
        margin-bottom: 10px;
        color: #7b68ee;
    }

    .no-videos p {
        font-size: 16px;
        opacity: 0.8;
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
    }

    /* Scroll Instructions */
    .scroll-instructions {
        position: fixed;
        bottom: 20px;
        left: 0;
        right: 0;
        text-align: center;
        color: rgba(255, 255, 255, 0.6);
        font-size: 12px;
        z-index: 100;
        pointer-events: none;
        transition: opacity 0.5s ease;
    }

    /* Active Video Indicator */
    .active-indicator {
        position: fixed;
        top: 60px;
        right: 20px;
        background: rgba(0, 0, 0, 0.7);
        color: #fff;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        z-index: 100;
    }

    /* Interest Buttons */
    .interest-buttons {
        position: absolute;
        left: 15px;
        bottom: 120px;
        display: flex;
        flex-direction: column;
        gap: 15px;
        align-items: center;
    }

    .interest-btn {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-size: 18px;
        cursor: pointer;
        pointer-events: auto;
        transition: all 0.3s ease;
    }

    .interest-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.1);
    }

    .interested-btn {
        background: rgba(76, 175, 80, 0.3);
        border-color: rgba(76, 175, 80, 0.5);
    }

    .not-interested-btn {
        background: rgba(244, 67, 54, 0.3);
        border-color: rgba(244, 67, 54, 0.5);
    }

    /* Download Progress */
    .download-progress {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 15px;
        border-radius: 8px;
        z-index: 10000;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .action-buttons {
            right: 10px;
            bottom: 100px;
            gap: 15px;
        }

        .action-icon {
            width: 45px;
            height: 45px;
            font-size: 18px;
        }

        .interest-buttons {
            left: 10px;
            bottom: 100px;
            gap: 12px;
        }

        .interest-btn {
            width: 40px;
            height: 40px;
            font-size: 16px;
        }

        .bottom-content {
            padding: 15px 15px 70px;
        }

        .post-header {
            font-size: 15px;
        }

        .post-content {
            font-size: 13px;
        }
    }

    @media (max-width: 480px) {
        .header {
            padding: 10px;
        }

        .header h1 {
            font-size: 16px;
        }

        .action-buttons {
            right: 8px;
            bottom: 90px;
            gap: 12px;
        }

        .action-icon {
            width: 40px;
            height: 40px;
            font-size: 16px;
        }

        .action-count {
            font-size: 11px;
        }

        .interest-buttons {
            left: 8px;
            bottom: 90px;
            gap: 10px;
        }

        .interest-btn {
            width: 38px;
            height: 38px;
            font-size: 15px;
        }

        .bottom-content {
            padding: 12px 12px 60px;
        }
    }

    /* Hide scrollbar */
    ::-webkit-scrollbar {
        display: none;
    }

    /* Pulse animation for new interactions */
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }

    .pulse {
        animation: pulse 0.3s ease-in-out;
    }
</style>
</head>
<body>

<!-- Reels Scroll Container -->
<div class="reels-scroll-container" id="reelsScrollContainer">
    <?php if (empty($posts)): ?>
        <div class="no-videos">
            <h2>No videos found</h2>
            <p>Try selecting a different category or upload your own video</p>
            <a href="make_post.php" class="upload-btn">Upload Video</a>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $index => $post): ?>
            <div class="reel" data-post-id="<?= $post['id'] ?>" data-index="<?= $index ?>">
                <!-- Algorithm Score -->
                <div class="algorithm-score">
                    ⚡ <?= $post['tiktok_score'] ?>
                </div>

                <!-- Viral/Trending Badge -->
                <?php if (!empty($post['badge'])): ?>
                    <div class="<?= $post['distribution_stage'] === 'viral' ? 'viral-badge' : 'trending-badge' ?>">
                        <?= $post['badge'] ?>
                    </div>
                <?php endif; ?>

                <!-- Source Badge -->
                <?php if (!empty($post['source_badge'])): ?>
                    <div class="source-badge">
                        <?= $post['source_badge'] ?>
                    </div>
                <?php endif; ?>

                <!-- Progress Bar -->
                <div class="reel-progress">
                    <div class="reel-progress-bar" id="progress-<?= $post['id'] ?>"></div>
                </div>

                <!-- Video Container -->
                <div class="video-container">
                    <?php if ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
                        <?php
                        $mediaArray = explode(',', $post['media_url']);
                        $firstVideo = trim($mediaArray[0]);
                        ?>
                        <video 
                            preload="metadata"
                            loop 
                            muted="false"
                            playsinline
                            data-post-id="<?= $post['id'] ?>"
                            data-video-url="<?= htmlspecialchars($firstVideo) ?>"
                            class="video-player"
                        >
                            <source src="<?= htmlspecialchars($firstVideo) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php endif; ?>
                    <div class="loading-spinner" style="display: none;"></div>
                </div>

                <!-- Video Overlay -->
                <div class="video-overlay">
                    <!-- Top Bar -->
                    <div class="top-bar">
                        <div class="user-info">
                            <img src="<?= htmlspecialchars($post['profile_pic_url'] ?: 'default_profile.png') ?>" 
                                 alt="<?= htmlspecialchars($post['username']) ?>" 
                                 class="user-avatar"
                                 onclick="window.location='profile.php?id=<?= $post['user_id'] ?>'">
                            <div class="username"><?= htmlspecialchars($post['username']) ?></div>
                            <?php if ($post['author_id'] !== $userId): ?>
                                <button class="follow-btn <?= $post['is_following'] ? 'following' : '' ?>" 
                                        data-user-id="<?= $post['author_id'] ?>">
                                    <?= $post['is_following'] ? 'Following' : 'Follow' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Left Interest Buttons -->
                    <div class="interest-buttons">
                        <div class="interest-btn interested-btn" data-post-id="<?= $post['id'] ?>" title="Interested">
                            👍
                        </div>
                        <div class="interest-btn not-interested-btn" data-post-id="<?= $post['id'] ?>" title="Not Interested">
                            👎
                        </div>
                    </div>

                    <!-- Right Action Buttons -->
                    <div class="action-buttons">
                        <div class="action-button like-btn <?= userLikedPost($pdo, $userId, $post['id']) ? 'liked' : '' ?>" 
                             data-post-id="<?= $post['id'] ?>">
                            <div class="action-icon">❤️</div>
                            <div class="action-count like-count"><?= $post['likes_count'] ?></div>
                        </div>

                        <div class="action-button comment-btn" 
                             onclick="window.location='comment.php?post_id=<?= $post['id'] ?>'">
                            <div class="action-icon">💬</div>
                            <div class="action-count"><?= $post['comments_count'] ?></div>
                        </div>

                        <div class="action-button share-btn" onclick="sharePost(<?= $post['id'] ?>)">
                            <div class="action-icon">↗️</div>
                            <div class="action-count"><?= $post['shares_count'] ?></div>
                        </div>

                        <div class="action-button download-btn" 
                             data-post-id="<?= $post['id'] ?>"
                             data-video-url="<?= htmlspecialchars($firstVideo) ?>">
                            <div class="action-icon">💾</div>
                            <div class="action-count">Download</div>
                        </div>
                    </div>

                    <!-- Bottom Content -->
                    <div class="bottom-content">
                        <?php if (!empty($post['post_header'])): ?>
                            <div class="post-header">
                                <?= htmlspecialchars($post['post_header']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="post-content">
                            <?= nl2br(htmlspecialchars($post['content'])) ?>
                        </div>

                        <?php
                        // Extract hashtags from content
                        preg_match_all('/#(\w+)/', $post['content'], $hashtags);
                        if (!empty($hashtags[0])): ?>
                            <div class="hashtags">
                                <?php foreach ($hashtags[0] as $hashtag): ?>
                                    <span class="hashtag"><?= htmlspecialchars($hashtag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($post['audio_track'])): ?>
                            <div class="audio-info">
                                <span class="audio-icon">🎵</span>
                                <span><?= htmlspecialchars($post['audio_track']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Video Controls -->
                    <div class="video-controls">
                        <button class="control-btn sound-toggle" data-muted="false">🔊</button>
                        <button class="control-btn replay-btn">🔄</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Active Video Indicator -->
<div class="active-indicator" id="activeIndicator">
    Video 1 of <?= count($posts) ?>
</div>

<!-- Scroll Instructions -->
<div class="scroll-instructions" id="scrollInstructions">
    Scroll vertically to browse videos
</div>

<!-- Download Progress -->
<div class="download-progress" id="downloadProgress">
    Downloading video...
</div>

<?= $algorithm->getBehaviorTrackingScripts() ?>
<?= $algorithm->getUIPersonalizationScripts() ?>

<script>
// TikTok-style Reels with Enhanced Algorithm Tracking
class TikTokReelsScroll {
    constructor() {
        this.container = document.getElementById('reelsScrollContainer');
        this.reels = Array.from(document.querySelectorAll('.reel'));
        this.videos = Array.from(document.querySelectorAll('.video-player'));
        this.currentIndex = 0;
        this.isMuted = false;
        this.isScrolling = false;
        this.scrollTimeout = null;
        this.playedVideos = new Set();
        this.videoWatchTimes = new Map();
        this.videoStartTimes = new Map();
        this.scrollStartTime = Date.now();
        this.lastScrollTop = 0;
        this.scrollVelocity = 0;
        
        this.init();
    }
    
    init() {
        this.initEventListeners();
        this.setupIntersectionObserver();
        this.updateActiveIndicator();
        this.hideScrollInstructions();
        this.trackScrollBehavior();
    }
    
    initEventListeners() {
        // Scroll event
        this.container.addEventListener('scroll', this.handleScroll.bind(this));
        
        // Touch events for better mobile handling
        this.container.addEventListener('touchstart', this.handleTouchStart.bind(this));
        this.container.addEventListener('touchend', this.handleTouchEnd.bind(this));
        
        // Keyboard navigation
        document.addEventListener('keydown', this.handleKeyDown.bind(this));
        
        // Video events
        this.videos.forEach(video => {
            video.addEventListener('click', this.togglePlay.bind(this));
            video.addEventListener('ended', this.handleVideoEnded.bind(this));
            video.addEventListener('timeupdate', this.handleTimeUpdate.bind(this));
            video.addEventListener('loadeddata', this.handleVideoLoaded.bind(this));
            video.addEventListener('play', this.handleVideoPlay.bind(this));
            video.addEventListener('pause', this.handleVideoPause.bind(this));
        });
        
        // Sound toggle
        document.querySelectorAll('.sound-toggle').forEach(btn => {
            btn.addEventListener('click', this.toggleSound.bind(this));
        });
        
        // Replay button
        document.querySelectorAll('.replay-btn').forEach(btn => {
            btn.addEventListener('click', this.replayVideo.bind(this));
        });
        
        // Download buttons
        document.querySelectorAll('.download-btn').forEach(btn => {
            btn.addEventListener('click', this.downloadVideo.bind(this));
        });
        
        // Like buttons
        document.querySelectorAll('.like-btn').forEach(btn => {
            btn.addEventListener('click', this.handleLike.bind(this));
        });
        
        // Follow buttons
        document.querySelectorAll('.follow-btn').forEach(btn => {
            btn.addEventListener('click', this.handleFollow.bind(this));
        });
        
        // Interest buttons
        document.querySelectorAll('.interested-btn').forEach(btn => {
            btn.addEventListener('click', this.handleInterested.bind(this));
        });
        
        document.querySelectorAll('.not-interested-btn').forEach(btn => {
            btn.addEventListener('click', this.handleNotInterested.bind(this));
        });
        
        // Enhanced engagement tracking
        this.setupEnhancedEngagementTracking();
    }
    
    setupEnhancedEngagementTracking() {
        // Track viewport attention
        document.addEventListener('visibilitychange', () => {
            this.trackBehavior('tab_switch', !document.hidden ? 1 : 0);
        });
        
        // Track engagement intensity for like buttons
        document.querySelectorAll('.like-btn').forEach(btn => {
            let pressStartTime = 0;
            
            btn.addEventListener('mousedown', () => {
                pressStartTime = Date.now();
            });
            
            btn.addEventListener('mouseup', () => {
                const duration = Date.now() - pressStartTime;
                if (duration > 500) { // Long press
                    this.trackBehavior('long_press', duration);
                }
            });
            
            // Double tap detection
            let lastTap = 0;
            btn.addEventListener('click', (e) => {
                const currentTime = Date.now();
                const tapLength = currentTime - lastTap;
                
                if (tapLength < 300 && tapLength > 0) {
                    this.trackBehavior('double_tap', 1);
                }
                lastTap = currentTime;
            });
        });
    }
    
    trackScrollBehavior() {
        let lastScrollTime = Date.now();
        let lastScrollTop = 0;
        
        this.container.addEventListener('scroll', () => {
            const currentTime = Date.now();
            const currentScrollTop = this.container.scrollTop;
            const timeDiff = currentTime - lastScrollTime;
            const scrollDiff = Math.abs(currentScrollTop - lastScrollTop);
            
            if (timeDiff > 0) {
                const velocity = scrollDiff / timeDiff;
                this.scrollVelocity = velocity;
                
                // Send scroll velocity to server periodically
                if (currentTime - this.scrollStartTime > 1000) {
                    this.trackBehavior('scroll_velocity', velocity);
                    this.scrollStartTime = currentTime;
                }
            }
            
            lastScrollTime = currentTime;
            lastScrollTop = currentScrollTop;
        });
    }
    
    trackBehavior(behaviorType, value) {
        const formData = new FormData();
        formData.append('action', 'track_behavior');
        formData.append('behavior_type', behaviorType);
        formData.append(behaviorType === 'scroll_velocity' ? 'velocity' : 'value', value);
        
        fetch('feed.php', {
            method: 'POST',
            body: formData
        }).catch(error => console.error('Behavior tracking error:', error));
    }
    
    setupIntersectionObserver() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const reel = entry.target;
                    const index = parseInt(reel.getAttribute('data-index'));
                    this.handleReelVisible(index);
                } else {
                    const reel = entry.target;
                    const index = parseInt(reel.getAttribute('data-index'));
                    this.handleReelHidden(index);
                }
            });
        }, {
            threshold: 0.8,
            root: this.container
        });
        
        this.reels.forEach(reel => {
            observer.observe(reel);
        });
    }
    
    handleReelVisible(index) {
        if (index === this.currentIndex) return;
        
        // Track completion of previous video
        if (this.currentIndex >= 0) {
            this.trackVideoCompletion(this.currentIndex);
        }
        
        // Pause previous video
        const prevVideo = this.videos[this.currentIndex];
        if (prevVideo) {
            prevVideo.pause();
            prevVideo.currentTime = 0;
        }
        
        // Update current index
        this.currentIndex = index;
        
        // Play new video
        this.playCurrentVideo();
        
        // Update UI
        this.updateActiveIndicator();
    }
    
    handleReelHidden(index) {
        if (index === this.currentIndex) {
            const video = this.videos[index];
            if (video && !video.paused) {
                video.pause();
            }
        }
    }
    
    handleScroll() {
        if (this.scrollTimeout) {
            clearTimeout(this.scrollTimeout);
        }
        
        this.scrollTimeout = setTimeout(() => {
            this.findCurrentReel();
        }, 100);
    }
    
    findCurrentReel() {
        const containerRect = this.container.getBoundingClientRect();
        const containerCenter = containerRect.top + (containerRect.height / 2);
        
        let closestReel = null;
        let closestDistance = Infinity;
        
        this.reels.forEach((reel, index) => {
            const reelRect = reel.getBoundingClientRect();
            const reelCenter = reelRect.top + (reelRect.height / 2);
            const distance = Math.abs(containerCenter - reelCenter);
            
            if (distance < closestDistance) {
                closestDistance = distance;
                closestReel = index;
            }
        });
        
        if (closestReel !== null && closestReel !== this.currentIndex) {
            this.handleReelVisible(closestReel);
        }
    }
    
    handleTouchStart(e) {
        this.touchStartY = e.touches[0].clientY;
    }
    
    handleTouchEnd(e) {
        // Let native scroll handle it
    }
    
    handleKeyDown(e) {
        switch(e.key) {
            case 'ArrowUp':
                e.preventDefault();
                this.scrollToReel(this.currentIndex - 1);
                break;
            case 'ArrowDown':
                e.preventDefault();
                this.scrollToReel(this.currentIndex + 1);
                break;
            case ' ':
                e.preventDefault();
                this.togglePlay();
                break;
            case 'm':
            case 'M':
                e.preventDefault();
                this.toggleSound();
                break;
        }
    }
    
    scrollToReel(index) {
        if (index >= 0 && index < this.reels.length) {
            const reel = this.reels[index];
            reel.scrollIntoView({ behavior: 'smooth' });
        }
    }
    
    playCurrentVideo() {
        const video = this.videos[this.currentIndex];
        if (video) {
            video.muted = this.isMuted;
            // Start tracking watch time
            this.videoStartTimes.set(video.dataset.postId, Date.now());
            video.play().catch(e => {
                console.log('Autoplay prevented:', e);
            });
        }
    }
    
    togglePlay() {
        const video = this.videos[this.currentIndex];
        if (video) {
            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        }
    }
    
    toggleSound() {
        this.isMuted = !this.isMuted;
        const video = this.videos[this.currentIndex];
        const soundBtns = document.querySelectorAll('.sound-toggle');
        
        if (video) {
            video.muted = this.isMuted;
        }
        
        soundBtns.forEach(btn => {
            btn.textContent = this.isMuted ? '🔇' : '🔊';
            btn.setAttribute('data-muted', this.isMuted);
        });
    }
    
    replayVideo() {
        const video = this.videos[this.currentIndex];
        if (video) {
            video.currentTime = 0;
            video.play();
            // Track rewatch
            this.trackUserBehavior(video.dataset.postId, 'rewatch', video.currentTime, video.duration);
        }
    }
    
    handleVideoEnded() {
        const video = this.videos[this.currentIndex];
        if (video) {
            // Track completion
            this.trackUserBehavior(video.dataset.postId, 'completion', video.duration, video.duration);
        }
        
        // Auto-advance to next video when current ends
        setTimeout(() => {
            this.scrollToReel(this.currentIndex + 1);
        }, 1000);
    }
    
    handleVideoLoaded(e) {
        const video = e.target;
        const postId = video.getAttribute('data-post-id');
        console.log(`Video ${postId} loaded successfully`);
    }
    
    handleVideoPlay(e) {
        const video = e.target;
        const postId = video.getAttribute('data-post-id');
        this.playedVideos.add(postId);
        console.log(`Video ${postId} started playing`);
    }
    
    handleVideoPause(e) {
        const video = e.target;
        const postId = video.getAttribute('data-post-id');
        console.log(`Video ${postId} paused`);
    }
    
    handleTimeUpdate(e) {
        const video = e.target;
        const postId = video.getAttribute('data-post-id');
        const progressBar = document.getElementById(`progress-${postId}`);
        
        if (progressBar && video.duration) {
            const progress = (video.currentTime / video.duration) * 100;
            progressBar.style.width = `${progress}%`;
        }
        
        // Track user behavior for videos watched more than 5 seconds
        if (video.currentTime >= 5 && !this.videoWatchTimes.has(postId)) {
            this.videoWatchTimes.set(postId, true);
            this.trackUserBehavior(postId, 'view', video.currentTime, video.duration);
        }
    }
    
    trackVideoCompletion(index) {
        const video = this.videos[index];
        if (video) {
            const postId = video.dataset.postId;
            const watchTime = video.currentTime;
            const duration = video.duration;
            
            // Track completion if video was mostly watched
            if (watchTime >= duration * 0.8) {
                this.trackUserBehavior(postId, 'completion', watchTime, duration);
            }
            
            // Track skip if watched less than 5 seconds
            if (watchTime < 5) {
                this.trackUserBehavior(postId, 'video_skip', watchTime, duration);
            }
        }
    }
    
    trackUserBehavior(postId, interactionType, currentTime, duration) {
        const formData = new FormData();
        formData.append('action', 'track_behavior');
        formData.append('post_id', postId);
        formData.append('watch_time', currentTime);
        formData.append('interaction_type', interactionType);
        
        fetch('feed.php', {
            method: 'POST',
            body: formData
        }).catch(error => console.error('Tracking error:', error));
    }
    
    handleLike(e) {
        const btn = e.currentTarget;
        const postId = btn.getAttribute('data-post-id');
        const isLiked = btn.classList.contains('liked');
        
        const formData = new FormData();
        formData.append('action', isLiked ? 'unlike' : 'like');
        formData.append('post_id', postId);
        
        fetch('feed.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const likeCount = btn.querySelector('.like-count');
                likeCount.textContent = data.likes_count;
                btn.classList.toggle('liked');
                btn.classList.add('pulse');
                
                // Track engagement
                if (!isLiked) {
                    this.trackEngagement(postId, 'like');
                }
                
                setTimeout(() => {
                    btn.classList.remove('pulse');
                }, 300);
            }
        })
        .catch(error => console.error('Like error:', error));
    }
    
    handleFollow(e) {
        const btn = e.currentTarget;
        const userId = btn.getAttribute('data-user-id');
        const isFollowing = btn.classList.contains('following');
        
        const formData = new FormData();
        formData.append('action', isFollowing ? 'unfollow' : 'follow');
        formData.append('followed_id', userId);
        
        fetch('feed.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                btn.classList.toggle('following');
                btn.textContent = btn.classList.contains('following') ? 'Following' : 'Follow';
            }
        })
        .catch(error => console.error('Follow error:', error));
    }
    
    handleInterested(e) {
        const btn = e.currentTarget;
        const postId = btn.getAttribute('data-post-id');
        
        this.trackEngagement(postId, 'interested');
        btn.classList.add('pulse');
        
        setTimeout(() => {
            btn.classList.remove('pulse');
        }, 300);
    }
    
    handleNotInterested(e) {
        const btn = e.currentTarget;
        const postId = btn.getAttribute('data-post-id');
        
        this.trackEngagement(postId, 'not_interested');
        btn.classList.add('pulse');
        
        setTimeout(() => {
            btn.classList.remove('pulse');
        }, 300);
    }
    
    trackEngagement(postId, engagementType) {
        const formData = new FormData();
        formData.append('action', engagementType);
        formData.append('post_id', postId);
        
        fetch('feed.php', {
            method: 'POST',
            body: formData
        }).catch(error => console.error('Engagement tracking error:', error));
    }
    
    downloadVideo(e) {
        const videoUrl = e.currentTarget.getAttribute('data-video-url');
        const postId = e.currentTarget.getAttribute('data-post-id');
        const progress = document.getElementById('downloadProgress');
        
        progress.style.display = 'block';
        
        fetch(videoUrl)
            .then(response => response.blob())
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'reel_video.mp4';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                progress.style.display = 'none';
                
                // Track download
                this.trackEngagement(postId, 'download');
            })
            .catch(error => {
                console.error('Download error:', error);
                progress.style.display = 'none';
                alert('Download failed. Please try again.');
            });
    }
    
    updateActiveIndicator() {
        const indicator = document.getElementById('activeIndicator');
        if (indicator) {
            indicator.textContent = `Video ${this.currentIndex + 1} of ${this.reels.length}`;
        }
    }
    
    hideScrollInstructions() {
        setTimeout(() => {
            const instructions = document.getElementById('scrollInstructions');
            if (instructions) {
                instructions.style.opacity = '0';
                setTimeout(() => {
                    instructions.style.display = 'none';
                }, 500);
            }
        }, 3000);
    }
}

// Initialize TikTok Reels when page loads
document.addEventListener('DOMContentLoaded', () => {
    new TikTokReelsScroll();
});

// Prevent context menu on videos
document.addEventListener('contextmenu', (e) => {
    if (e.target.tagName === 'VIDEO') {
        e.preventDefault();
    }
});

// Handle page visibility changes
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        // Pause all videos when tab is hidden
        document.querySelectorAll('video').forEach(video => {
            if (!video.paused) {
                video.pause();
            }
        });
    }
});

// Share function
function sharePost(postId) {
    if (navigator.share) {
        navigator.share({
            title: 'Check out this reel!',
            url: window.location.origin + '/video.php?post=' + postId
        }).then(() => {
            // Track share
            const formData = new FormData();
            formData.append('action', 'share');
            formData.append('post_id', postId);
            fetch('feed.php', { method: 'POST', body: formData });
        });
    } else {
        // Fallback: copy to clipboard
        const url = window.location.origin + '/video.php?post=' + postId;
        navigator.clipboard.writeText(url).then(() => {
            alert('Link copied to clipboard!');
            // Track share
            const formData = new FormData();
            formData.append('action', 'share');
            formData.append('post_id', postId);
            fetch('feed.php', { method: 'POST', body: formData });
        });
    }
}

// Handle browser back button
window.addEventListener('popstate', (event) => {
    window.location.reload();
});
</script>

</body>
</html>