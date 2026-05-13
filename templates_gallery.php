<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "templates_manager.php";

// Database connection (same as before)
require_once "config.php";

$templateManager = new WebsiteTemplateManager($pdo);
$category = $_GET['category'] ?? null;
$templates = $templateManager->getTemplates($category);

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
    <title>Website Templates</title>
    <style>
        .categories {
            display: flex;
            gap: 10px;
            padding: 20px;
            background: #2d2d2d;
            flex-wrap: wrap;
        }
        
        .category-btn {
            padding: 10px 20px;
            background: #444;
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .category-btn.active {
            background: #667eea;
        }
        
        .template-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .template-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            cursor: pointer;
        }
        
        .template-card:hover {
            transform: translateY(-5px);
        }
        
        .template-thumbnail {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .template-info {
            padding: 15px;
        }
        
        .template-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .template-category {
            background: #667eea;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            display: inline-block;
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
        }
    </style>
</head>
<body>
    <div class="categories">
        <?php foreach ($categories as $key => $name): ?>
            <button class="category-btn <?= ($category === $key || (!$category && $key === 'all')) ? 'active' : '' ?>" 
                    onclick="filterTemplates('<?= $key ?>')">
                <?= $name ?>
            </button>
        <?php endforeach; ?>
    </div>
    
    <div class="template-gallery">
        <?php foreach ($templates as $template): ?>
            <div class="template-card">
                <div class="template-thumbnail">
                    <?php if ($template['thumbnail_url']): ?>
                        <img src="<?= htmlspecialchars($template['thumbnail_url']) ?>" alt="<?= htmlspecialchars($template['name']) ?>" class="template-thumbnail">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-weight: bold;">
                            <?= htmlspecialchars($template['name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="template-info">
                    <div class="template-name"><?= htmlspecialchars($template['name']) ?></div>
                    <div class="template-description"><?= htmlspecialchars($template['description']) ?></div>
                    <span class="template-category"><?= htmlspecialchars($template['category']) ?></span>
                    
                    <?php if ($template['is_premium']): ?>
                        <div style="color: #ff6b6b; font-weight: bold; margin-top: 5px;">
                            Premium - $<?= $template['price'] ?>
                        </div>
                    <?php else: ?>
                        <div style="color: #51cf66; font-weight: bold; margin-top: 5px;">
                            Free
                        </div>
                    <?php endif; ?>
                    
                    <button class="use-template-btn" onclick="useTemplate(<?= $template['id'] ?>)">
                        Use Template
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        function filterTemplates(category) {
            window.location.href = `templates_gallery.php?category=${category}`;
        }
        
        function useTemplate(templateId) {
            window.location.href = `template_builder.php?template_id=${templateId}`;
        }
    </script>
</body>
</html>