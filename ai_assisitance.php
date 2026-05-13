<?php
// editor_video.php - PROFESSIONAL VIDEO EDITOR (CAPCUT + INSHOT CLONE)
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";



class AIAssistant {
    private $pdo;
    private $userId;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    // Main AI processing function
    public function processMessage($message, $context = []) {
        $message = trim($message);
        
        // Analyze message intent
        $intent = $this->analyzeIntent($message);
        
        // Get user context
        $userContext = $this->getUserContext();
        
        // Process based on intent
        switch($intent) {
            case 'content_recommendation':
                return $this->handleContentRecommendation($message, $userContext);
                
            case 'profile_help':
                return $this->handleProfileHelp($message, $userContext);
                
            case 'trending_info':
                return $this->handleTrendingInfo($message, $userContext);
                
            case 'technical_support':
                return $this->handleTechnicalSupport($message);
                
            case 'general_chat':
                return $this->handleGeneralChat($message);
                
            default:
                return $this->handleDefaultResponse($message);
        }
    }
    
    private function analyzeIntent($message) {
        $message = strtolower($message);
        
        $patterns = [
            'content_recommendation' => [
                '/recommend|suggest|what should i watch|what.*video|show me.*video/i',
                '/similar to|like.*video|more.*like this/i',
                '/what.*trending|popular.*videos|trending.*now/i'
            ],
            'profile_help' => [
                '/profile|bio|picture|avatar|how to change/i',
                '/followers|following|how to get followers/i',
                '/privacy|settings|private account/i'
            ],
            'trending_info' => [
                '/trending|popular|viral|hot.*right now/i',
                '/what.*everyone.*watching|most viewed/i'
            ],
            'technical_support' => [
                '/bug|error|not working|problem|issue/i',
                '/upload|post|video.*not.*working/i',
                '/login|signup|password|account problem/i'
            ]
        ];
        
        foreach ($patterns as $intent => $regexPatterns) {
            foreach ($regexPatterns as $pattern) {
                if (preg_match($pattern, $message)) {
                    return $intent;
                }
            }
        }
        
        return 'general_chat';
    }
    
    private function getUserContext() {
        try {
            // Get user's recent activity
            $stmt = $this->pdo->prepare("
                SELECT 
                    u.username,
                    u.country,
                    COUNT(DISTINCT p.id) as post_count,
                    COUNT(DISTINCT f.follower_id) as followers_count,
                    COUNT(DISTINCT l.id) as total_likes,
                    p_recent.category1 as recent_category
                FROM users u
                LEFT JOIN posts p ON u.id = p.user_id
                LEFT JOIN follows f ON u.id = f.followed_id
                LEFT JOIN likes l ON p.id = l.post_id
                LEFT JOIN posts p_recent ON u.id = p_recent.user_id 
                    AND p_recent.created_at = (
                        SELECT MAX(created_at) FROM posts WHERE user_id = u.id
                    )
                WHERE u.id = ?
                GROUP BY u.id, u.username, u.country, p_recent.category1
            ");
            $stmt->execute([$this->userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent viewed categories
            $catStmt = $this->pdo->prepare("
                SELECT p.category1, COUNT(*) as view_count
                FROM user_behavior_tracking ubt
                JOIN posts p ON ubt.post_id = p.id
                WHERE ubt.user_id = ? AND ubt.created_at > NOW() - INTERVAL '7 days'
                GROUP BY p.category1
                ORDER BY view_count DESC
                LIMIT 5
            ");
            $catStmt->execute([$this->userId]);
            $topCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'user_data' => $userData,
                'top_categories' => $topCategories,
                'is_new_user' => $this->isNewUser()
            ];
            
        } catch (Exception $e) {
            error_log("User context error: " . $e->getMessage());
            return [];
        }
    }
    
    private function handleContentRecommendation($message, $context) {
        // Analyze message for specific content requests
        $categories = $this->extractCategories($message);
        $mood = $this->detectMood($message);
        
        // Get personalized recommendations
        $recommendations = $this->getPersonalizedRecommendations($categories, $mood, $context);
        
        $response = "🤖 **AI Assistant:**\n\n";
        
        if (!empty($recommendations)) {
            $response .= "Based on your interests, I recommend these:\n\n";
            
            foreach ($recommendations as $rec) {
                $response .= "• **{$rec['title']}** - {$rec['reason']}\n";
            }
            
            $response .= "\n💡 *Tip:* " . $this->getContentTip($context);
        } else {
            $response .= "I'd love to help you find great content! Try being more specific, like:\n";
            $response .= "• \"Recommend funny videos\"\n";
            $response .= "• \"Show me trending dance videos\"\n";
            $response .= "• \"Suggest videos similar to what I've liked\"\n";
        }
        
        return $response;
    }
    
    private function getPersonalizedRecommendations($categories, $mood, $context) {
        try {
            $placeholders = str_repeat('?,', count($categories) - 1) . '?';
            
            $sql = "
                SELECT 
                    p.id,
                    p.post_header as title,
                    p.category1,
                    p.category2,
                    p.category3,
                    u.username,
                    p.likes_count,
                    p.views_count,
                    CASE 
                        WHEN p.category1 IN ($placeholders) THEN 'Matches your interests'
                        WHEN p.likes_count > 100 THEN 'Popular in community'
                        WHEN p.user_id IN (
                            SELECT followed_id FROM follows WHERE follower_id = ?
                        ) THEN 'From creators you follow'
                        ELSE 'Trending now'
                    END as reason
                FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.post_type = 'video'
                AND p.privacy_setting = 'public'
                AND p.id NOT IN (
                    SELECT post_id FROM user_behavior_tracking 
                    WHERE user_id = ? AND watch_time > 5
                )
                ORDER BY 
                    CASE 
                        WHEN p.category1 IN ($placeholders) THEN 1
                        WHEN p.likes_count > 100 THEN 2
                        ELSE 3
                    END,
                    p.likes_count DESC,
                    p.created_at DESC
                LIMIT 5
            ";
            
            $params = array_merge(
                $categories, 
                [$this->userId],
                [$this->userId],
                $categories
            );
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recommendations = [];
            foreach ($posts as $post) {
                $recommendations[] = [
                    'id' => $post['id'],
                    'title' => $post['title'] ?: 'Untitled Video',
                    'reason' => $post['reason'],
                    'username' => $post['username'],
                    'likes' => $post['likes_count']
                ];
            }
            
            return $recommendations;
            
        } catch (Exception $e) {
            error_log("Recommendation error: " . $e->getMessage());
            return [];
        }
    }
    
    private function handleTrendingInfo($message, $context) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.category1,
                    COUNT(*) as post_count,
                    AVG(p.likes_count) as avg_likes,
                    AVG(p.comments_count) as avg_comments
                FROM posts p
                WHERE p.created_at > NOW() - INTERVAL '24 hours'
                AND p.post_type = 'video'
                GROUP BY p.category1
                HAVING COUNT(*) >= 3
                ORDER BY (AVG(p.likes_count) + AVG(p.comments_count)) DESC
                LIMIT 5
            ");
            $stmt->execute();
            $trendingCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = "🔥 **Trending Right Now:**\n\n";
            
            if (!empty($trendingCategories)) {
                foreach ($trendingCategories as $index => $category) {
                    $emoji = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'][$index] ?? '🔸';
                    $response .= "$emoji **{$category['category1']}** - ";
                    $response .= round($category['avg_likes']) . " avg likes\n";
                }
                
                $response .= "\n💫 *Pro Tip:* Posts in trending categories get 3x more views!";
            } else {
                $response .= "No strong trends detected yet. Check back later!";
            }
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Trending info error: " . $e->getMessage());
            return "I'm having trouble fetching trends right now. Please try again later!";
        }
    }
    
    private function handleProfileHelp($message, $context) {
        $tips = [
            "**Profile Picture Tips:**\n• Use a clear, well-lit photo\n• Show your face clearly\n• Square images work best\n• File size should be under 2MB",
            
            "**Bio Best Practices:**\n• Keep it short and engaging\n• Include your interests\n• Add relevant hashtags\n• Update regularly",
            
            "**Growing Followers:**\n• Post consistently\n• Engage with other users\n• Use relevant hashtags\n• Collaborate with others\n• Share quality content"
        ];
        
        $response = "📱 **Profile Optimization Tips:**\n\n";
        $response .= $tips[array_rand($tips)];
        $response .= "\n\n🔧 *Need specific help? Ask me about: profile picture, bio, followers, or privacy settings!*";
        
        return $response;
    }
    
    private function handleTechnicalSupport($message) {
        $commonSolutions = [
            "**Video Upload Issues:**\n• Check file format (MP4 works best)\n• Ensure file size < 100MB\n• Stable internet connection required\n• Try refreshing the page",
            
            "**Login Problems:**\n• Reset your password\n• Clear browser cache\n• Try different browser\n• Check internet connection",
            
            "**Video Playback:**\n• Check internet speed\n• Update your browser\n• Clear browser cache\n• Try different device"
        ];
        
        $response = "🔧 **Technical Support:**\n\n";
        $response .= $commonSolutions[array_rand($commonSolutions)];
        $response .= "\n\n📞 *If problems persist, contact our support team with details about the issue.*";
        
        return $response;
    }
    
    private function handleGeneralChat($message) {
        $responses = [
            "Hello! I'm your AI assistant 🤖 How can I help you with the platform today?",
            "Hi there! I can help you find videos, optimize your profile, or answer questions about the platform!",
            "Hey! Looking for video recommendations, trending content, or need help with something?",
            "Greetings! I'm here to help you discover amazing content and make the most of our platform! 💫"
        ];
        
        $followUps = [
            "\n\nTry asking me:\n• \"Recommend funny videos\"\n• \"What's trending?\"\n• \"How to get more followers?\"",
            "\n\nI can help with:\n• Content recommendations\n• Profile optimization\n• Technical issues\n• Platform guidance",
            "\n\nNeed ideas? Ask me about:\n• Video suggestions\n• Trending categories\n• Profile tips\n• Engagement strategies"
        ];
        
        return $responses[array_rand($responses)] . $followUps[array_rand($followUps)];
    }
    
    private function handleDefaultResponse($message) {
        return "I'm not sure I understand. I can help you with:\n\n" .
               "🎬 **Content Discovery** - Find videos you'll love\n" .
               "📊 **Trending Info** - What's popular right now\n" .
               "👤 **Profile Help** - Optimize your presence\n" .
               "🔧 **Technical Support** - Fix issues and problems\n\n" .
               "Try rephrasing your question or ask about one of these topics!";
    }
    
    private function extractCategories($message) {
        $categories = [
            'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music',
            'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'
        ];
        
        $found = [];
        foreach ($categories as $category) {
            if (stripos($message, $category) !== false) {
                $found[] = $category;
            }
        }
        
        return empty($found) ? ['Entertainment', 'Comedy', 'Music'] : $found;
    }
    
    private function detectMood($message) {
        $positiveWords = ['funny', 'happy', 'exciting', 'amazing', 'great', 'good'];
        $negativeWords = ['sad', 'boring', 'tired', 'angry', 'frustrated'];
        
        $message = strtolower($message);
        
        $positiveCount = 0;
        $negativeCount = 0;
        
        foreach ($positiveWords as $word) {
            $positiveCount += substr_count($message, $word);
        }
        
        foreach ($negativeWords as $word) {
            $negativeCount += substr_count($message, $word);
        }
        
        if ($positiveCount > $negativeCount) return 'positive';
        if ($negativeCount > $positiveCount) return 'negative';
        return 'neutral';
    }
    
    private function getContentTip($context) {
        $tips = [
            "Engage with videos you like - it helps me learn your preferences better!",
            "Try exploring different categories to discover new content you might enjoy!",
            "Consistent posting can help grow your audience and engagement!",
            "Use relevant hashtags to make your content more discoverable!",
            "Interacting with other creators can help build your community!"
        ];
        
        return $tips[array_rand($tips)];
    }
    
    private function isNewUser() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as post_count 
            FROM posts 
            WHERE user_id = ? AND created_at > NOW() - INTERVAL '7 days'
        ");
        $stmt->execute([$this->userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['post_count'] < 3;
    }
}

// AJAX Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please log in first']);
        exit;
    }
    
    $message = trim($_POST['message'] ?? '');
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
        exit;
    }
    
    try {
        $aiAssistant = new AIAssistant($pdo, $_SESSION['user_id']);
        $response = $aiAssistant->processMessage($message);
        
        // Store conversation in database
        $stmt = $pdo->prepare("
            INSERT INTO ai_conversations (user_id, user_message, ai_response, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $message, $response]);
        
        echo json_encode([
            'success' => true,
            'response' => $response,
            'message_id' => $pdo->lastInsertId()
        ]);
        
    } catch (Exception $e) {
        error_log("AI Assistant error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Sorry, I encountered an error. Please try again.'
        ]);
    }
    exit;
}
?>