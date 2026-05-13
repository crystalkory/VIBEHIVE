<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "template_generator.php";

require_once "config.php";

$templateGenerator = new AITemplateGenerator($pdo);

// Check if we need to generate new templates
$stmt = $pdo->query("SELECT COUNT(*) as count FROM website_templates");
$templateCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Auto-generate templates if database is empty
if ($templateCount == 0) {
    $templateGenerator->generateTemplateBatch(50); // Generate 50 templates initially
}

// Get filter parameters
$category = $_GET['category'] ?? 'all';
$sort = $_GET['sort'] ?? 'newest';
$search = $_GET['search'] ?? '';

// Build query
$sql = "SELECT * FROM website_templates WHERE 1=1";
$params = [];

if ($category !== 'all') {
    $sql .= " AND category = ?";
    $params[] = $category;
}

if (!empty($search)) {
    $sql .= " AND (name ILIKE ? OR description ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Add sorting
switch ($sort) {
    case 'popular':
        $sql .= " ORDER BY usage_count DESC";
        break;
    case 'name':
        $sql .= " ORDER BY name ASC";
        break;
    default: // newest
        $sql .= " ORDER BY created_at DESC";
}

$sql .= " LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [
    'all' => 'All Templates',
    'portfolio' => 'Portfolio',
    'business' => 'Business', 
    'ecommerce' => 'E-commerce',
    'blog' => 'Blog',
    'landing' => 'Landing Pages'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI-Generated Website Templates</title>
    <style>
        .controls {
            padding: 20px;
            background: #2d2d2d;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-box {
            flex: 1;
            min-width: 200px;
            padding: 10px;
            border: none;
            border-radius: 5px;
        }
        
        .category-filter, .sort-filter {
            padding: 10px;
            border: none;
            border-radius: 5px;
            background: white;
        }
        
        .generate-more-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .template-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .template-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }
        
        .template-preview {
            height: 180px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            position: relative;
            overflow: hidden;
        }
        
        .template-preview::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 40%, rgba(255,255,255,0.1) 50%, transparent 60%);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .template-info {
            padding: 15px;
        }
        
        .template-name {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 1.1rem;
        }
        
        .template-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 10px;
            line-height: 1.4;
        }
        
        .template-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        
        .template-category {
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .template-stats {
            color: #888;
            font-size: 0.8rem;
        }
        
        .use-template-btn {
            width: 100%;
            padding: 10px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.3s;
        }
        
        .use-template-btn:hover {
            background: #5a67d8;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="controls">
        <input type="text" class="search-box" placeholder="Search templates..." 
               value="<?= htmlspecialchars($search) ?>" onkeyup="debounceSearch()">
        
        <select class="category-filter" onchange="filterTemplates()">
            <?php foreach ($categories as $key => $name): ?>
                <option value="<?= $key ?>" <?= $category === $key ? 'selected' : '' ?>>
                    <?= $name ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select class="sort-filter" onchange="filterTemplates()">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
            <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most Popular</option>
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
        </select>
        
        <button class="generate-more-btn" onclick="generateMoreTemplates()">
            🔄 Generate More Templates
        </button>
    </div>
    
    <div class="template-grid" id="templateGrid">
        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <h2>No templates found</h2>
                <p>Try adjusting your search or generate new templates</p>
                <button class="generate-more-btn" onclick="generateMoreTemplates()">
                    Generate Templates
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($templates as $template): ?>
                <div class="template-card" onclick="useTemplate(<?= $template['id'] ?>)">
                    <div class="template-preview" style="background: linear-gradient(135deg, <?= json_decode($template['colors'], true)['primary'] ?? '#667eea' ?>, <?= json_decode($template['colors'], true)['secondary'] ?? '#764ba2' ?>)">
                        <?= htmlspecialchars($template['name']) ?>
                    </div>
                    <div class="template-info">
                        <div class="template-name"><?= htmlspecialchars($template['name']) ?></div>
                        <div class="template-description"><?= htmlspecialchars($template['description']) ?></div>
                        <div class="template-meta">
                            <span class="template-category"><?= htmlspecialchars($template['category']) ?></span>
                            <span class="template-stats"><?= $template['usage_count'] ?> uses</span>
                        </div>
                        <button class="use-template-btn" onclick="event.stopPropagation(); useTemplate(<?= $template['id'] ?>)">
                            Use This Template
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        let searchTimeout;
        
        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(filterTemplates, 500);
        }
        
        function filterTemplates() {
            const search = document.querySelector('.search-box').value;
            const category = document.querySelector('.category-filter').value;
            const sort = document.querySelector('.sort-filter').value;
            
            const url = new URL(window.location);
            url.searchParams.set('search', search);
            url.searchParams.set('category', category);
            url.searchParams.set('sort', sort);
            
            window.location.href = url.toString();
        }
        
        function useTemplate(templateId) {
            window.location.href = `template_builder.php?template_id=${templateId}`;
        }
        
        function generateMoreTemplates() {
            document.getElementById('templateGrid').innerHTML = `
                <div class="loading">
                    <h3>🎨 Generating new templates...</h3>
                    <p>Creating unique website designs for you</p>
                </div>
            `;
            
            fetch('generate_templates.php?count=12')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error generating templates: ' + data.message);
                        window.location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    window.location.reload();
                });
        }
        
        // Infinite scroll for more templates
        let isLoading = false;
        window.addEventListener('scroll', () => {
            if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 500 && !isLoading) {
                loadMoreTemplates();
            }
        });
        
        function loadMoreTemplates() {
            // Implementation for loading more templates via AJAX
        }
    </script>
</body>
</html>