<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once "template_generator.php";

// Database connection
require_once "config.php";


$templateGenerator = new AITemplateGenerator($pdo);
$count = min($_GET['count'] ?? 10, 50); // Limit to 50 templates per request

// Generate new templates
$categories = ['portfolio', 'business', 'ecommerce', 'blog', 'landing'];
$generated = $templateGenerator->generateTemplateBatch($count, $categories);

echo json_encode([
    'success' => true,
    'message' => "Generated {$count} new templates",
    'generated_count' => count($generated)
]);
?>